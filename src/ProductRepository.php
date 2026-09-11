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

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
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
        $orderParams = [];
        $nearCity = LocationNormalizer::key($filters['nearCity'] ?? '', 80);
        if ($nearCity !== '') {
            $order = 'CASE WHEN ' . $this->normalizedLocationSql("COALESCE(store_city,'')")
                . ' = :near_city_order THEN 0 ELSE 1 END ASC, ' . $order;
            $orderParams['near_city_order'] = $nearCity;
        }
        $whereSql = implode(' AND ', $where);
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM products WHERE ' . $whereSql);
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $query = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM products WHERE ' . $whereSql . " ORDER BY {$order} LIMIT :limit OFFSET :offset");
        foreach ($params + $orderParams as $key => $value) {
            $query->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $query->bindValue(':limit', $pageSize, PDO::PARAM_INT);
        $query->bindValue(':offset', ($page - 1) * $pageSize, PDO::PARAM_INT);
        $query->execute();

        $items = array_map(self::map(...), $query->fetchAll());

        return [
            'items' => $this->withResponsiveSrcSets($items),
            'page' => $page,
            'pageSize' => $pageSize,
            'total' => $total,
            'totalPages' => $total === 0 ? 0 : (int) ceil($total / $pageSize),
        ];
    }

    /** @return array<string, mixed>|null */
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

        $product = $this->withResponsiveSrcSets([self::map($row)])[0];
        $product['reviewItems'] = $this->reviews($id);

        return $product;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
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

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
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

    /** @return array<string, mixed> */
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

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function map(array $row): array
    {
        $imageUrl = (string) $row['image_url'];
        $secondaryImageUrl = $row['secondary_image_url'] === null ? null : (string) $row['secondary_image_url'];

        return [
            'id' => (int) $row['id'], 'storeUserId' => $row['store_user_id'] === null ? null : (int) $row['store_user_id'],
            'name' => (string) $row['name'], 'category' => (string) $row['category'],
            'price' => (float) $row['price'], 'originalPrice' => $row['original_price'] === null ? null : (float) $row['original_price'],
            'imageUrl' => $imageUrl, 'secondaryImageUrl' => $secondaryImageUrl,
            'imageSrcSet' => null,
            'secondaryImageSrcSet' => null,
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

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
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

    private function normalizedLocationSql(string $expression): string
    {
        $sql = "LOWER(TRIM({$expression}))";
        foreach ([
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
            'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ñ' => 'n',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'ÿ' => 'y',
            'æ' => 'ae', 'œ' => 'oe', 'ß' => 'ss', 'ł' => 'l',
            '-' => ' ', '_' => ' ', '.' => ' ', ',' => ' ', "'" => '', '’' => '',
        ] as $from => $to) {
            $from = str_replace("'", "''", $from);
            $to = str_replace("'", "''", $to);
            $sql = "REPLACE({$sql},'{$from}','{$to}')";
        }

        return "TRIM(REPLACE(REPLACE({$sql},'  ',' '),'  ',' '))";
    }

    private static function bool(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 't', 'true'], true);
    }

    private static function time(mixed $value): ?string
    {
        return $value === null ? null : (new DateTimeImmutable((string) $value))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    }

    /**
     * @param list<array<string, mixed>> $products
     * @return list<array<string, mixed>>
     */
    private function withResponsiveSrcSets(array $products): array
    {
        if ($products === []) {
            return [];
        }

        $productIds = array_values(array_unique(array_map(
            static fn (array $product): int => (int) $product['id'],
            $products
        )));
        $placeholders = array_map(
            static fn (int $index): string => ':responsive_product_id_' . $index,
            array_keys($productIds)
        );
        $statement = $this->pdo->prepare(
            'SELECT product_images.product_id, product_images.role, responsive_image_variants.width'
            . ' FROM product_images INNER JOIN responsive_image_variants'
            . ' ON responsive_image_variants.product_image_id=product_images.id'
            . ' WHERE product_images.product_id IN (' . implode(', ', $placeholders) . ')'
            . ' ORDER BY product_images.product_id, product_images.role, responsive_image_variants.width'
        );
        foreach ($productIds as $index => $productId) {
            $statement->bindValue(':responsive_product_id_' . $index, $productId, PDO::PARAM_INT);
        }
        $statement->execute();

        $widths = [];
        foreach ($statement->fetchAll() as $row) {
            $widths[(int) $row['product_id']][(string) $row['role']][] = (int) $row['width'];
        }

        return array_map(static function (array $product) use ($widths): array {
            $productId = (int) $product['id'];
            $mainWidths = $widths[$productId]['MAIN'] ?? [];
            $secondaryWidths = $widths[$productId]['SECONDARY'] ?? [];
            $product['imageSrcSet'] = $mainWidths === []
                ? null
                : ResponsiveImageService::srcSet((string) $product['imageUrl'], $mainWidths);
            $product['secondaryImageSrcSet'] = $secondaryWidths === [] || $product['secondaryImageUrl'] === null
                ? null
                : ResponsiveImageService::srcSet((string) $product['secondaryImageUrl'], $secondaryWidths);

            return $product;
        }, $products);
    }
}
