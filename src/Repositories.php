<?php

declare(strict_types=1);

namespace ChambreRose;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class ProductRepository
{
    private const SELECT_COLUMNS = <<<'SQL'
        id, name, category, price, original_price, image_url, secondary_image_url,
        tag, sale_label, rating, reviews, purchase_count, description, store_name,
        store_address, store_city, store_segment, store_hours, product_type, material,
        available_sizes, color_options, stock_status, shipping_note, care_instructions
        SQL;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return array_map([self::class, 'mapProduct'],
            $this->pdo->query('SELECT ' . self::SELECT_COLUMNS . ' FROM products ORDER BY id ASC')->fetchAll());
    }

    public function find(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT ' . self::SELECT_COLUMNS . ' FROM products WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();
        return is_array($row) ? self::mapProduct($row) : null;
    }

    public function findForUpdate(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT ' . self::SELECT_COLUMNS . ' FROM products WHERE id = :id FOR UPDATE');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();
        return is_array($row) ? self::mapProduct($row) : null;
    }

    public function findByName(string $name): ?array
    {
        $statement = $this->pdo->prepare('SELECT ' . self::SELECT_COLUMNS .
            ' FROM products WHERE lower(name) = lower(:name) LIMIT 1');
        $statement->execute(['name' => $name]);
        $row = $statement->fetch();
        return is_array($row) ? self::mapProduct($row) : null;
    }

    /** @return list<array<string, mixed>> */
    public function byCategory(string $category): array
    {
        $comparison = $this->isMySql() ? 'category = :category' : 'lower(category) = lower(:category)';
        $statement = $this->pdo->prepare('SELECT ' . self::SELECT_COLUMNS .
            ' FROM products WHERE ' . $comparison . ' ORDER BY id ASC');
        $statement->execute(['category' => $category]);
        return array_map([self::class, 'mapProduct'], $statement->fetchAll());
    }

    /** @return list<string> */
    public function categories(): array
    {
        return $this->pdo->query('SELECT DISTINCT category FROM products ORDER BY category')->fetchAll(PDO::FETCH_COLUMN);
    }

    public function count(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
    }

    /** @param array<string, mixed> $product */
    public function create(array $product): array
    {
        $sql = <<<'SQL'
            INSERT INTO products (
              name, category, price, original_price, image_url, secondary_image_url,
              tag, sale_label, rating, reviews, purchase_count, description, store_name,
              store_address, store_city, store_segment, store_hours, product_type, material,
              available_sizes, color_options, stock_status, shipping_note, care_instructions,
              created_at, updated_at
            ) VALUES (
              :name, :category, :price, :original_price, :image_url, :secondary_image_url,
              :tag, :sale_label, :rating, :reviews, :purchase_count, :description, :store_name,
              :store_address, :store_city, :store_segment, :store_hours, :product_type, :material,
              :available_sizes, :color_options, :stock_status, :shipping_note, :care_instructions,
              CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
            )
            SQL;
        if (!$this->isMySql()) {
            $sql .= ' RETURNING id';
        }
        $statement = $this->pdo->prepare($sql);
        $statement->execute(self::databaseParams($product));
        $id = $this->isMySql() ? (int) $this->pdo->lastInsertId() : (int) $statement->fetchColumn();
        return $this->required($id);
    }

    /** @param array<string, mixed> $product */
    public function update(int $id, array $product): array
    {
        $params = self::databaseParams($product);
        $params['id'] = $id;
        $statement = $this->pdo->prepare(<<<'SQL'
            UPDATE products SET
              name=:name, category=:category, price=:price, original_price=:original_price,
              image_url=:image_url, secondary_image_url=:secondary_image_url, tag=:tag,
              sale_label=:sale_label, rating=:rating, reviews=:reviews, purchase_count=:purchase_count,
              description=:description, store_name=:store_name, store_address=:store_address,
              store_city=:store_city, store_segment=:store_segment, store_hours=:store_hours,
              product_type=:product_type, material=:material, available_sizes=:available_sizes,
              color_options=:color_options, stock_status=:stock_status, shipping_note=:shipping_note,
              care_instructions=:care_instructions, updated_at=CURRENT_TIMESTAMP
            WHERE id=:id
            SQL);
        $statement->execute($params);
        if ($statement->rowCount() === 0 && $this->find($id) === null) {
            throw new ApiException(404, 'Product not found.');
        }
        return $this->required($id);
    }

    public function delete(int $id): void
    {
        $statement = $this->pdo->prepare('DELETE FROM products WHERE id = :id');
        $statement->execute(['id' => $id]);
        if ($statement->rowCount() === 0) {
            throw new ApiException(404, 'Product not found.');
        }
    }

    public function registerPurchase(int $id): array
    {
        $statement = $this->pdo->prepare('UPDATE products SET purchase_count=COALESCE(purchase_count,0)+1, '
            . 'updated_at=CURRENT_TIMESTAMP WHERE id=:id');
        $statement->execute(['id' => $id]);
        if ($statement->rowCount() === 0) {
            throw new ApiException(404, 'Product not found.');
        }
        return $this->required($id);
    }

    /** @return array{fileName: string, contentType: string, size: int, updatedAt: string}|null */
    public function imageMetadata(int $productId, string $role): ?array
    {
        $statement = $this->pdo->prepare('SELECT file_name,content_type,size_bytes,updated_at FROM product_images '
            . 'WHERE product_id=:product_id AND role=:role');
        $statement->execute(['product_id' => $productId, 'role' => strtoupper($role)]);
        $row = $statement->fetch();
        return is_array($row) ? ['fileName' => (string) $row['file_name'],
            'contentType' => (string) $row['content_type'], 'size' => (int) $row['size_bytes'],
            'updatedAt' => (string) $row['updated_at']] : null;
    }

    public function imageData(int $productId, string $role): ?string
    {
        $statement = $this->pdo->prepare('SELECT image_data FROM product_images WHERE product_id=:product_id AND role=:role');
        $statement->execute(['product_id' => $productId, 'role' => strtoupper($role)]);
        $data = $statement->fetchColumn();
        if ($data === false) {
            return null;
        }
        if (is_resource($data)) {
            $data = stream_get_contents($data);
        }
        return is_string($data) ? $data : null;
    }

    public function hasImage(int $productId, string $role): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM product_images WHERE product_id=:product_id AND role=:role');
        $statement->execute(['product_id' => $productId, 'role' => strtoupper($role)]);
        return (bool) $statement->fetchColumn();
    }

    public function upsertImage(int $productId, string $role, string $fileName, string $contentType, string $bytes): void
    {
        if ($this->isMySql()) {
            $statement = $this->pdo->prepare(<<<'SQL'
                INSERT INTO product_images (product_id,role,file_name,content_type,size_bytes,image_data,created_at,updated_at)
                VALUES (:product_id,:role,:file_name,:content_type,:size_bytes,:image_data,CURRENT_TIMESTAMP(3),CURRENT_TIMESTAMP(3))
                ON DUPLICATE KEY UPDATE file_name=VALUES(file_name),content_type=VALUES(content_type),
                  size_bytes=VALUES(size_bytes),image_data=VALUES(image_data),updated_at=CURRENT_TIMESTAMP(3)
                SQL);
            $statement->bindValue(':product_id', $productId, PDO::PARAM_INT);
            $statement->bindValue(':role', strtoupper($role));
            $statement->bindValue(':file_name', $fileName);
            $statement->bindValue(':content_type', $contentType);
            $statement->bindValue(':size_bytes', strlen($bytes), PDO::PARAM_INT);
            $statement->bindValue(':image_data', $bytes, PDO::PARAM_LOB);
            $statement->execute();
            return;
        }
        $statement = $this->pdo->prepare(<<<'SQL'
            INSERT INTO product_images (product_id,role,file_name,content_type,size_bytes,image_data,created_at,updated_at)
            VALUES (:product_id,:role,:file_name,:content_type,:size_bytes,decode(:image_base64,'base64'),CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)
            ON CONFLICT (product_id,role) DO UPDATE SET file_name=EXCLUDED.file_name,
              content_type=EXCLUDED.content_type,size_bytes=EXCLUDED.size_bytes,image_data=EXCLUDED.image_data,
              updated_at=CURRENT_TIMESTAMP
            SQL);
        $statement->execute(['product_id' => $productId, 'role' => strtoupper($role), 'file_name' => $fileName,
            'content_type' => $contentType, 'size_bytes' => strlen($bytes), 'image_base64' => base64_encode($bytes)]);
    }

    private function isMySql(): bool
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    }

    private function required(int $id): array
    {
        return $this->find($id) ?? throw new ApiException(404, 'Product not found.');
    }

    /** @param array<string, mixed> $product @return array<string, mixed> */
    private static function databaseParams(array $product): array
    {
        $mapping = ['name'=>'name','category'=>'category','price'=>'price','original_price'=>'originalPrice',
            'image_url'=>'imageUrl','secondary_image_url'=>'secondaryImageUrl','tag'=>'tag','sale_label'=>'saleLabel',
            'rating'=>'rating','reviews'=>'reviews','purchase_count'=>'purchaseCount','description'=>'description',
            'store_name'=>'storeName','store_address'=>'storeAddress','store_city'=>'storeCity',
            'store_segment'=>'storeSegment','store_hours'=>'storeHours','product_type'=>'productType',
            'material'=>'material','available_sizes'=>'availableSizes','color_options'=>'colorOptions',
            'stock_status'=>'stockStatus','shipping_note'=>'shippingNote','care_instructions'=>'careInstructions'];
        $params = [];
        foreach ($mapping as $database => $api) {
            $params[$database] = $product[$api] ?? null;
        }
        return $params;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private static function mapProduct(array $row): array
    {
        return ['id'=>(int)$row['id'],'name'=>(string)$row['name'],'category'=>(string)$row['category'],
            'price'=>(float)$row['price'],'originalPrice'=>$row['original_price']===null?null:(float)$row['original_price'],
            'imageUrl'=>(string)$row['image_url'],'secondaryImageUrl'=>$row['secondary_image_url'],'tag'=>$row['tag'],
            'saleLabel'=>$row['sale_label'],'rating'=>$row['rating']===null?null:(int)$row['rating'],
            'reviews'=>$row['reviews']===null?null:(int)$row['reviews'],
            'purchaseCount'=>$row['purchase_count']===null?null:(int)$row['purchase_count'],
            'description'=>$row['description'],'storeName'=>$row['store_name'],'storeAddress'=>$row['store_address'],
            'storeCity'=>$row['store_city'],'storeSegment'=>$row['store_segment'],'storeHours'=>$row['store_hours'],
            'productType'=>$row['product_type'],'material'=>$row['material'],'availableSizes'=>$row['available_sizes'],
            'colorOptions'=>$row['color_options'],'stockStatus'=>$row['stock_status'],'shippingNote'=>$row['shipping_note'],
            'careInstructions'=>$row['care_instructions']];
    }
}

final class UserRepository
{
    private const SELECT_COLUMNS = <<<'SQL'
        users.id, email, password_hash, first_name, last_name, phone, address, city, country,
        postal_code, role, account_status, vip_active, vip_since, vip_until, created_at, updated_at,
        EXISTS (SELECT 1 FROM user_establishment_photos WHERE user_establishment_photos.user_id=users.id)
          AS has_establishment_photo
        SQL;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function findByEmail(string $email): ?array
    {
        $statement = $this->pdo->prepare('SELECT ' . self::SELECT_COLUMNS . ' FROM users WHERE email=:email');
        $statement->execute(['email' => strtolower(trim($email))]);
        $row = $statement->fetch();
        return is_array($row) ? self::mapUser($row) : null;
    }

    public function find(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT ' . self::SELECT_COLUMNS . ' FROM users WHERE id=:id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();
        return is_array($row) ? self::mapUser($row) : null;
    }

    public function emailExists(string $email, ?int $exceptId = null): bool
    {
        $sql = 'SELECT 1 FROM users WHERE email=:email';
        $params = ['email' => strtolower(trim($email))];
        if ($exceptId !== null) {
            $sql .= ' AND id<>:id';
            $params['id'] = $exceptId;
        }
        $statement = $this->pdo->prepare($sql . ' LIMIT 1');
        $statement->execute($params);
        return (bool) $statement->fetchColumn();
    }

    /** @param array<string, string> $user */
    public function create(array $user, string $passwordHash, string $role = 'USER'): array
    {
        $sql = <<<'SQL'
            INSERT INTO users (email,password_hash,first_name,last_name,phone,address,city,country,postal_code,
              role,account_status,vip_active,created_at,updated_at)
            VALUES (:email,:password_hash,:first_name,:last_name,:phone,:address,:city,:country,:postal_code,
              :role,:account_status,FALSE,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)
            SQL;
        if (!$this->isMySql()) {
            $sql .= ' RETURNING id';
        }
        $statement = $this->pdo->prepare($sql);
        $statement->execute(['email'=>strtolower(trim($user['email'])),'password_hash'=>$passwordHash,
            'first_name'=>trim($user['firstName']),'last_name'=>trim($user['lastName']),
            'phone'=>trim($user['phone']),'address'=>trim($user['address']),'city'=>trim($user['city']),
            'country'=>trim($user['country']),'postal_code'=>trim($user['postalCode']),'role'=>strtoupper($role),
            'account_status'=>strtoupper($role) === 'ADMIN' ? 'APPROVED' : 'PENDING']);
        $id = $this->isMySql() ? (int) $this->pdo->lastInsertId() : (int) $statement->fetchColumn();
        return $this->find($id) ?? throw new ApiException(500, 'Unable to create user.');
    }

    /** @return array{fileName: string, contentType: string, size: int, storageKey: string}|null */
    public function establishmentPhoto(int $userId): ?array
    {
        $statement = $this->pdo->prepare('SELECT file_name,content_type,size_bytes,storage_key '
            . 'FROM user_establishment_photos WHERE user_id=:id');
        $statement->execute(['id' => $userId]);
        $row = $statement->fetch();
        return is_array($row) ? ['fileName'=>(string)$row['file_name'],'contentType'=>(string)$row['content_type'],
            'size'=>(int)$row['size_bytes'],'storageKey'=>(string)$row['storage_key']] : null;
    }

    public function saveEstablishmentPhoto(int $userId, string $storageKey, string $fileName,
        string $contentType, int $size): void
    {
        $normalized = basename(str_replace('\\', '/', $fileName));
        $fileName = trim(preg_replace('/[\x00-\x1F\x7F"]/', '', $normalized) ?? '') ?: 'establishment.webp';
        $values = [$userId, $fileName, $contentType, $size, $storageKey];
        if ($this->isMySql()) {
            $statement = $this->pdo->prepare(<<<'SQL'
                INSERT INTO user_establishment_photos (user_id,file_name,content_type,size_bytes,storage_key,created_at,updated_at)
                VALUES (?,?,?,?,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)
                ON DUPLICATE KEY UPDATE file_name=?,content_type=?,size_bytes=?,storage_key=?,updated_at=CURRENT_TIMESTAMP
                SQL);
            $statement->execute(array_merge($values, array_slice($values, 1)));
            return;
        }
        $statement = $this->pdo->prepare(<<<'SQL'
            INSERT INTO user_establishment_photos (user_id,file_name,content_type,size_bytes,storage_key,created_at,updated_at)
            VALUES (?,?,?,?,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)
            ON CONFLICT (user_id) DO UPDATE SET file_name=EXCLUDED.file_name,content_type=EXCLUDED.content_type,
              size_bytes=EXCLUDED.size_bytes,storage_key=EXCLUDED.storage_key,updated_at=CURRENT_TIMESTAMP
            SQL);
        $statement->execute($values);
    }

    public function delete(int $id): void
    {
        $statement = $this->pdo->prepare('DELETE FROM users WHERE id=:id');
        $statement->execute(['id' => $id]);
    }

    /** @param array<string, string> $user */
    public function updateProfile(int $id, array $user): array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
            UPDATE users SET email=:email,first_name=:first_name,last_name=:last_name,phone=:phone,
              address=:address,city=:city,country=:country,postal_code=:postal_code,updated_at=CURRENT_TIMESTAMP
            WHERE id=:id
            SQL);
        $statement->execute(['id'=>$id,'email'=>strtolower(trim($user['email'])),
            'first_name'=>trim($user['firstName']),'last_name'=>trim($user['lastName']),'phone'=>trim($user['phone']),
            'address'=>trim($user['address']),'city'=>trim($user['city']),'country'=>trim($user['country']),
            'postal_code'=>trim($user['postalCode'])]);
        return $this->find($id) ?? throw new ApiException(404, 'User profile not found.');
    }

    public function updatePassword(int $id, string $passwordHash): void
    {
        $statement = $this->pdo->prepare('UPDATE users SET password_hash=:password_hash,updated_at=CURRENT_TIMESTAMP WHERE id=:id');
        $statement->execute(['id' => $id, 'password_hash' => $passwordHash]);
        if ($statement->rowCount() === 0 && $this->find($id) === null) {
            throw new ApiException(404, 'User not found.');
        }
    }

    /** @return list<array<string, mixed>> */
    public function list(?string $email, ?string $name, string $sort): array
    {
        $where = [];
        $params = [];
        if ($email !== null) {
            $where[] = 'LOWER(email) LIKE LOWER(:email)';
            $params['email'] = '%' . $email . '%';
        }
        if ($name !== null) {
            $where[] = "LOWER(concat(first_name,' ',last_name)) LIKE LOWER(:name)";
            $params['name'] = '%' . $name . '%';
        }
        $direction = strtolower($sort) === 'oldest' ? 'ASC' : 'DESC';
        $sql = 'SELECT ' . self::SELECT_COLUMNS . ' FROM users';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY created_at ' . $direction . ',id ' . $direction;
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return array_map([self::class, 'mapUser'], $statement->fetchAll());
    }

    public function setVip(int $id, bool $active): array
    {
        $sql = $active
            ? 'UPDATE users SET vip_active=TRUE,vip_since=COALESCE(vip_since,CURRENT_TIMESTAMP),vip_until=NULL,updated_at=CURRENT_TIMESTAMP WHERE id=:id'
            : 'UPDATE users SET vip_active=FALSE,vip_until=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=:id';
        $statement = $this->pdo->prepare($sql);
        $statement->execute(['id' => $id]);
        if ($statement->rowCount() === 0) {
            throw new ApiException(404, 'User not found.');
        }
        return $this->find($id) ?? throw new ApiException(404, 'User not found.');
    }

    public function setAccountStatus(int $id, string $status): array
    {
        $statement = $this->pdo->prepare('UPDATE users SET account_status=:status,updated_at=CURRENT_TIMESTAMP WHERE id=:id');
        $statement->execute(['id' => $id, 'status' => strtoupper($status)]);
        if ($statement->rowCount() === 0 && $this->find($id) === null) {
            throw new ApiException(404, 'User not found.');
        }
        return $this->find($id) ?? throw new ApiException(404, 'User not found.');
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private static function mapUser(array $row): array
    {
        return ['id'=>(int)$row['id'],'email'=>(string)$row['email'],'passwordHash'=>(string)$row['password_hash'],
            'firstName'=>(string)$row['first_name'],'lastName'=>(string)$row['last_name'],'phone'=>(string)$row['phone'],
            'address'=>(string)$row['address'],'city'=>(string)$row['city'],'country'=>(string)$row['country'],
            'postalCode'=>(string)$row['postal_code'],'role'=>(string)$row['role'],
            'accountStatus'=>(string)$row['account_status'],
            'vipActive'=>self::toBool($row['vip_active']),'vipSince'=>self::time($row['vip_since']),
            'vipUntil'=>self::time($row['vip_until']),'createdAt'=>self::time($row['created_at']),
            'updatedAt'=>self::time($row['updated_at']),
            'establishmentPhotoUrl'=>self::toBool($row['has_establishment_photo'] ?? false)
                ? '/api/users/' . (int)$row['id'] . '/establishment-photo' : null];
    }

    private static function toBool(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 't', 'true'], true);
    }

    private static function time(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        return (new DateTimeImmutable((string)$value))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    }

    private function isMySql(): bool
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    }
}
