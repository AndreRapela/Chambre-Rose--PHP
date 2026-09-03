<?php

declare(strict_types=1);

namespace ChambreRose;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class ProductRepository
{
    private const COLUMNS = <<<'SQL'
        id, store_user_id, name, category, price, original_price, image_url, secondary_image_url,
        tag, sale_label, reviews, purchase_count, likes, description, store_name,
        store_address, store_city, store_segment, store_hours, product_type, material,
        available_sizes, color_options, stock_status, shipping_note, care_instructions,
        is_active, created_at, updated_at
        SQL;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param array<string,mixed> $filters @return array<string,mixed> */
    public function search(array $filters): array
    {
        $where = ['is_active = TRUE'];
        $params = [];
        $q = self::text($filters['q'] ?? null, 120);
        if ($q !== null) {
            $where[] = "(LOWER(name) LIKE LOWER(:q_name) OR LOWER(COALESCE(description,'')) LIKE LOWER(:q_description) OR LOWER(COALESCE(store_name,'')) LIKE LOWER(:q_store))";
            $params += ['q_name' => "%{$q}%", 'q_description' => "%{$q}%", 'q_store' => "%{$q}%"];
        }
        $category = self::text($filters['category'] ?? null, 60);
        if ($category !== null) {
            $where[] = 'LOWER(category) = LOWER(:category)';
            $params['category'] = $category;
        }
        $city = self::text($filters['city'] ?? null, 80);
        if ($city !== null) {
            $where[] = "LOWER(COALESCE(store_city,'')) LIKE LOWER(:city)";
            $params['city'] = "%{$city}%";
        }
        if (isset($filters['storeId']) && filter_var($filters['storeId'], FILTER_VALIDATE_INT) !== false) {
            $where[] = 'store_user_id = :store_id';
            $params['store_id'] = (int) $filters['storeId'];
        }
        foreach (['minPrice' => '>=', 'maxPrice' => '<='] as $key => $operator) {
            if (isset($filters[$key]) && is_numeric($filters[$key])) {
                $where[] = "price {$operator} :{$key}";
                $params[$key] = max(0, (float) $filters[$key]);
            }
        }

        $page = max(1, (int) ($filters['page'] ?? 1));
        $pageSize = min(48, max(1, (int) ($filters['pageSize'] ?? 20)));
        $sort = (string) ($filters['sort'] ?? 'popular');
        $order = match ($sort) {
            'newest' => 'created_at DESC, id DESC',
            'priceAsc' => 'price ASC, id DESC',
            'priceDesc' => 'price DESC, id DESC',
            'name' => 'name ASC, id ASC',
            default => 'purchase_count DESC, updated_at DESC, id DESC',
        };
        $whereSql = implode(' AND ', $where);
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM products WHERE ' . $whereSql);
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $query = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM products WHERE ' . $whereSql . " ORDER BY {$order} LIMIT :limit OFFSET :offset");
        foreach ($params as $key => $value) {
            $query->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $query->bindValue(':limit', $pageSize, PDO::PARAM_INT);
        $query->bindValue(':offset', ($page - 1) * $pageSize, PDO::PARAM_INT);
        $query->execute();

        return [
            'items' => array_map(self::map(...), $query->fetchAll()),
            'page' => $page,
            'pageSize' => $pageSize,
            'total' => $total,
            'totalPages' => $total === 0 ? 0 : (int) ceil($total / $pageSize),
        ];
    }

    public function find(int $id, bool $publicOnly = true): ?array
    {
        $sql = 'SELECT ' . self::COLUMNS . ' FROM products WHERE id=:id';
        if ($publicOnly) {
            $sql .= ' AND is_active=TRUE';
        }
        $statement = $this->pdo->prepare($sql);
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        if (!is_array($row)) {
            return null;
        }

        $product = self::map($row);
        $product['reviewItems'] = $this->reviews($id);

        return $product;
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): array
    {
        $columns = array_keys(self::databaseValues($data));
        $values = self::databaseValues($data);
        $sql = 'INSERT INTO products (' . implode(',', $columns) . ',created_at,updated_at) VALUES (:'
            . implode(',:', $columns) . ',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)';
        $this->pdo->prepare($sql)->execute($values);

        return $this->find((int) $this->pdo->lastInsertId(), false)
            ?? throw new ApiException(500, 'Unable to create product.');
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data): array
    {
        $values = self::databaseValues($data);
        $set = array_map(static fn (string $column): string => "{$column}=:{$column}", array_keys($values));
        $values['id'] = $id;
        $this->pdo->prepare('UPDATE products SET ' . implode(',', $set) . ',updated_at=CURRENT_TIMESTAMP WHERE id=:id')->execute($values);

        return $this->find($id, false) ?? throw new ApiException(404, 'Product not found.');
    }

    public function setActive(int $id, bool $active): void
    {
        $statement = $this->pdo->prepare('UPDATE products SET is_active=:active,updated_at=CURRENT_TIMESTAMP WHERE id=:id');
        $statement->execute(['id' => $id, 'active' => $active]);
        if ($statement->rowCount() === 0 && $this->find($id, false) === null) {
            throw new ApiException(404, 'Product not found.');
        }
    }

    public function registerPurchase(int $id, int $buyerUserId): array
    {
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare('SELECT id,store_user_id,price,is_active FROM products WHERE id=:id FOR UPDATE');
            $statement->execute(['id' => $id]);
            $product = $statement->fetch();
            if (!is_array($product) || !self::bool($product['is_active'])) {
                throw new ApiException(404, 'Product not found.');
            }
            $this->pdo->prepare(
                "INSERT INTO marketplace_orders (buyer_user_id,product_id,order_type,amount,status,created_at) VALUES (:buyer,:product,'PRODUCT',:amount,'COMPLETED',CURRENT_TIMESTAMP)"
            )->execute(['buyer' => $buyerUserId, 'product' => $id, 'amount' => $product['price']]);
            $this->pdo->prepare('UPDATE products SET purchase_count=purchase_count+1,updated_at=CURRENT_TIMESTAMP WHERE id=:id')
                ->execute(['id' => $id]);
            if ($product['store_user_id'] !== null) {
                $this->pdo->prepare('UPDATE professional_profiles SET purchase_count=purchase_count+1,updated_at=CURRENT_TIMESTAMP WHERE user_id=:id')
                    ->execute(['id' => (int) $product['store_user_id']]);
            }
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        return $this->find($id) ?? throw new ApiException(404, 'Product not found.');
    }

    public function registerProfilePurchase(int $profileUserId, int $buyerUserId, ?float $amount): void
    {
        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare('SELECT user_id FROM professional_profiles WHERE user_id=:id FOR UPDATE');
            $lock->execute(['id' => $profileUserId]);
            if ($lock->fetchColumn() === false) {
                throw new ApiException(404, 'Profile not found.');
            }
            $this->pdo->prepare(
                "INSERT INTO marketplace_orders (buyer_user_id,profile_user_id,order_type,amount,status,created_at) VALUES (:buyer,:profile,'PROFILE',:amount,'COMPLETED',CURRENT_TIMESTAMP)"
            )->execute(['buyer' => $buyerUserId, 'profile' => $profileUserId, 'amount' => $amount]);
            $this->pdo->prepare('UPDATE professional_profiles SET purchase_count=purchase_count+1,updated_at=CURRENT_TIMESTAMP WHERE user_id=:id')
                ->execute(['id' => $profileUserId]);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @return list<array<string,mixed>> */
    private function reviews(int $productId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id,reviewer_name,body,created_at FROM product_reviews WHERE product_id=:product ORDER BY created_at DESC,id DESC LIMIT 6'
        );
        $statement->execute(['product' => $productId]);

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'reviewerName' => (string) $row['reviewer_name'],
            'body' => (string) $row['body'],
            'createdAt' => self::time($row['created_at']),
        ], $statement->fetchAll());
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function map(array $row): array
    {
        return [
            'id' => (int) $row['id'], 'storeUserId' => $row['store_user_id'] === null ? null : (int) $row['store_user_id'],
            'name' => (string) $row['name'], 'category' => (string) $row['category'],
            'price' => (float) $row['price'], 'originalPrice' => $row['original_price'] === null ? null : (float) $row['original_price'],
            'imageUrl' => (string) $row['image_url'], 'secondaryImageUrl' => $row['secondary_image_url'],
            'tag' => $row['tag'], 'saleLabel' => $row['sale_label'], 'starCount' => max(0, (int) $row['purchase_count']),
            'reviews' => max(0, (int) $row['reviews']), 'purchaseCount' => max(0, (int) $row['purchase_count']),
            'likes' => max(0, (int) $row['likes']), 'description' => $row['description'],
            'storeName' => $row['store_name'], 'storeAddress' => $row['store_address'], 'storeCity' => $row['store_city'],
            'storeSegment' => $row['store_segment'], 'storeHours' => $row['store_hours'], 'productType' => $row['product_type'],
            'material' => $row['material'], 'availableSizes' => $row['available_sizes'], 'colorOptions' => $row['color_options'],
            'stockStatus' => $row['stock_status'], 'shippingNote' => $row['shipping_note'], 'careInstructions' => $row['care_instructions'],
            'active' => self::bool($row['is_active']), 'createdAt' => self::time($row['created_at']), 'updatedAt' => self::time($row['updated_at']),
        ];
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private static function databaseValues(array $data): array
    {
        $map = [
            'store_user_id' => 'storeUserId', 'name' => 'name', 'category' => 'category', 'price' => 'price',
            'original_price' => 'originalPrice', 'image_url' => 'imageUrl', 'secondary_image_url' => 'secondaryImageUrl',
            'tag' => 'tag', 'sale_label' => 'saleLabel', 'reviews' => 'reviews',
            'purchase_count' => 'purchaseCount', 'likes' => 'likes', 'description' => 'description',
            'store_name' => 'storeName', 'store_address' => 'storeAddress', 'store_city' => 'storeCity',
            'store_segment' => 'storeSegment', 'store_hours' => 'storeHours', 'product_type' => 'productType',
            'material' => 'material', 'available_sizes' => 'availableSizes', 'color_options' => 'colorOptions',
            'stock_status' => 'stockStatus', 'shipping_note' => 'shippingNote', 'care_instructions' => 'careInstructions',
            'is_active' => 'active',
        ];
        $values = [];
        foreach ($map as $column => $field) {
            $values[$column] = $data[$field] ?? null;
        }

        return $values;
    }

    private static function text(mixed $value, int $max): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        return function_exists('mb_substr') ? mb_substr($value, 0, $max, 'UTF-8') : substr($value, 0, $max);
    }

    private static function bool(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 't', 'true'], true);
    }

    private static function time(mixed $value): ?string
    {
        return $value === null ? null : (new DateTimeImmutable((string) $value))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    }
}

final class ProductImageRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function put(int $productId, string $role, UploadedFile $file): void
    {
        $mime = $file->detectedContentType();
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new ApiException(400, 'Product image must be JPG, PNG or WebP.');
        }
        if ($file->actualSize() > 8 * 1024 * 1024) {
            throw new ApiException(413, 'A product image cannot exceed 8 MB.');
        }
        $name = trim(preg_replace('/[\x00-\x1F\x7F"]/', '', basename(str_replace('\\', '/', $file->name))) ?? '') ?: 'product-image';
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $sql = $driver === 'mysql'
            ? 'INSERT INTO product_images (product_id,role,file_name,content_type,size_bytes,image_data,created_at,updated_at) VALUES (:product,:role,:name,:type,:size,:data,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE file_name=VALUES(file_name),content_type=VALUES(content_type),size_bytes=VALUES(size_bytes),image_data=VALUES(image_data),updated_at=CURRENT_TIMESTAMP'
            : 'INSERT INTO product_images (product_id,role,file_name,content_type,size_bytes,image_data,created_at,updated_at) VALUES (:product,:role,:name,:type,:size,:data,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP) ON CONFLICT (product_id,role) DO UPDATE SET file_name=EXCLUDED.file_name,content_type=EXCLUDED.content_type,size_bytes=EXCLUDED.size_bytes,image_data=EXCLUDED.image_data,updated_at=CURRENT_TIMESTAMP';
        $this->pdo->prepare($sql)->execute([
            'product' => $productId, 'role' => $role, 'name' => $name, 'type' => $mime,
            'size' => $file->actualSize(), 'data' => $file->bytes(),
        ]);
    }

    /** @return array<string,mixed>|null */
    public function get(int $productId, string $role): ?array
    {
        $statement = $this->pdo->prepare('SELECT id,content_type,size_bytes,image_data,updated_at FROM product_images WHERE product_id=:product AND role=:role');
        $statement->execute(['product' => $productId, 'role' => $role]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }
}

final class ProductService
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly ProductImageRepository $images,
        private readonly UserRepository $users
    ) {
    }

    /** @param array<string,mixed> $filters */
    public function search(array $filters): array
    {
        return $this->products->search($filters);
    }

    public function get(int $id): array
    {
        return $this->products->find($id) ?? throw new ApiException(404, 'Product not found.');
    }

    /** @param array<string,mixed> $input */
    public function save(?int $id, int $actorId, string $actorRole, array $input, ?UploadedFile $main, ?UploadedFile $secondary): array
    {
        $existing = $id === null ? null : $this->products->find($id, false);
        if ($id !== null && $existing === null) {
            throw new ApiException(404, 'Product not found.');
        }
        if ($actorRole !== 'ADMIN') {
            if ($actorRole !== 'STORE' || ($existing !== null && (int) ($existing['storeUserId'] ?? 0) !== $actorId)) {
                throw new ApiException(403, 'Only the product store or an administrator may edit it.');
            }
        }
        $store = $actorRole === 'STORE' ? $this->users->find($actorId) : null;
        $data = $this->validate(array_replace($existing ?? [], $input));
        if ($actorRole === 'STORE') {
            $data['storeUserId'] = $actorId;
            $data['storeName'] = $data['storeName'] ?: trim(($store['firstName'] ?? '') . ' ' . ($store['lastName'] ?? ''));
            $data['storeCity'] = $data['storeCity'] ?: ($store['city'] ?? null);
        }
        if ($id === null && ($main === null || $main->isEmpty()) && trim((string) $data['imageUrl']) === '') {
            throw new ApiException(400, 'A main product image is required.', ['mainImage' => 'is required']);
        }
        $product = $id === null ? $this->products->create($data) : $this->products->update($id, $data);
        foreach (['MAIN' => $main, 'SECONDARY' => $secondary] as $role => $file) {
            if ($file !== null && !$file->isEmpty()) {
                $this->images->put((int) $product['id'], $role, $file);
                $field = $role === 'MAIN' ? 'imageUrl' : 'secondaryImageUrl';
                $data[$field] = '/api/products/' . $product['id'] . '/images/' . strtolower($role);
            }
        }
        if (($data['imageUrl'] ?? '') !== $product['imageUrl'] || ($data['secondaryImageUrl'] ?? null) !== $product['secondaryImageUrl']) {
            $product = $this->products->update((int) $product['id'], $data);
        }

        return $product;
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function validate(array $input): array
    {
        $errors = [];
        $limits = [
            'name' => 120, 'category' => 60, 'imageUrl' => 500, 'secondaryImageUrl' => 500,
            'tag' => 40, 'saleLabel' => 40, 'description' => 3000, 'storeName' => 120,
            'storeAddress' => 160, 'storeCity' => 80, 'storeSegment' => 80, 'storeHours' => 120,
            'productType' => 80, 'material' => 160, 'availableSizes' => 120, 'colorOptions' => 120,
            'stockStatus' => 80, 'shippingNote' => 160, 'careInstructions' => 160,
        ];
        $data = [];
        foreach ($limits as $field => $max) {
            $value = trim((string) ($input[$field] ?? ''));
            if (($field === 'name' || $field === 'category') && $value === '') {
                $errors[$field] = 'is required';
            } elseif (self::length($value) > $max) {
                $errors[$field] = "cannot exceed {$max} characters";
            }
            $data[$field] = $value === '' && !in_array($field, ['name', 'category', 'imageUrl'], true) ? null : $value;
        }
        foreach (['price', 'originalPrice'] as $field) {
            $value = $input[$field] ?? null;
            if ($field === 'price' && ($value === null || $value === '')) {
                $errors[$field] = 'is required';
            } elseif ($value !== null && $value !== '' && (!is_numeric($value) || (float) $value < 0 || (float) $value > 99999999.99)) {
                $errors[$field] = 'must be a valid non-negative amount';
            }
            $data[$field] = $value === null || $value === '' ? null : (float) $value;
        }
        $data += [
            'storeUserId' => isset($input['storeUserId']) ? (int) $input['storeUserId'] : null,
            'reviews' => max(0, (int) ($input['reviews'] ?? 0)),
            'purchaseCount' => max(0, (int) ($input['purchaseCount'] ?? 0)),
            'likes' => max(0, (int) ($input['likes'] ?? 0)),
            'active' => !array_key_exists('active', $input) || filter_var($input['active'], FILTER_VALIDATE_BOOL),
        ];
        if ($errors !== []) {
            throw new ApiException(400, 'Invalid product data.', $errors);
        }

        return $data;
    }

    private static function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }
}
