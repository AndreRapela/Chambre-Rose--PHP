<?php

declare(strict_types=1);

namespace ChambreRose;

use PDO;

final class ProductImageRepository
{
    /** @var list<string> */
    private const LEGACY_PLACEHOLDER_FALLBACKS = [
        'carousel-pink-ring-thong.jpeg',
        'carousel-basic-black.jpeg',
        'carousel-pink-lace-tie.jpeg',
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly ?ResponsiveImageService $responsiveImages = null
    ) {
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
        $bytes = $file->bytes();
        $prepared = $this->responsiveImages?->prepare($bytes) ?? [];
        $name = trim(preg_replace('/[\x00-\x1F\x7F"]/', '', basename(str_replace('\\', '/', $file->name))) ?? '') ?: 'product-image';
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $sql = $driver === 'mysql'
            ? 'INSERT INTO product_images (product_id,role,file_name,content_type,size_bytes,image_data,created_at,updated_at) VALUES (:product,:role,:name,:type,:size,:data,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE file_name=VALUES(file_name),content_type=VALUES(content_type),size_bytes=VALUES(size_bytes),image_data=VALUES(image_data),updated_at=CURRENT_TIMESTAMP'
            : 'INSERT INTO product_images (product_id,role,file_name,content_type,size_bytes,image_data,created_at,updated_at) VALUES (:product,:role,:name,:type,:size,:data,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP) ON CONFLICT (product_id,role) DO UPDATE SET file_name=EXCLUDED.file_name,content_type=EXCLUDED.content_type,size_bytes=EXCLUDED.size_bytes,image_data=EXCLUDED.image_data,updated_at=CURRENT_TIMESTAMP';
        $startedTransaction = !$this->pdo->inTransaction();
        if ($startedTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $statement = $this->pdo->prepare($sql);
            $statement->bindValue(':product', $productId, PDO::PARAM_INT);
            $statement->bindValue(':role', $role);
            $statement->bindValue(':name', $name);
            $statement->bindValue(':type', $mime);
            $statement->bindValue(':size', strlen($bytes), PDO::PARAM_INT);
            $statement->bindValue(':data', $bytes, PDO::PARAM_LOB);
            $statement->execute();
            if ($this->responsiveImages !== null) {
                $stored = $this->get($productId, $role)
                    ?? throw new ApiException(503, 'Unable to store the product image.');
                $this->responsiveImages->storePrepared('PRODUCT', (int) $stored['id'], $bytes, $prepared);
            }
            if ($startedTransaction) {
                $this->pdo->commit();
            }
        } catch (\Throwable $exception) {
            if ($startedTransaction) {
                $this->rollBackIfActive();
            }
            throw $exception;
        }
    }

    private function rollBackIfActive(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    /** @return array<string,mixed>|null */
    public function get(int $productId, string $role): ?array
    {
        $statement = $this->pdo->prepare('SELECT id,content_type,size_bytes,image_data,updated_at FROM product_images WHERE product_id=:product AND role=:role');
        $statement->execute(['product' => $productId, 'role' => $role]);
        $row = $statement->fetch();

        if (!is_array($row)) {
            return null;
        }
        if (is_resource($row['image_data'] ?? null)) {
            $row['image_data'] = stream_get_contents($row['image_data']);
        }
        if (!is_string($row['image_data'] ?? null)) {
            return null;
        }

        return strtolower((string) ($row['content_type'] ?? '')) === 'image/svg+xml'
            ? $this->replaceLegacyPlaceholder($row, $productId, $role)
            : $row;
    }

    /** @return array{id: int, content_type: string, size_bytes: int, updated_at: string}|null */
    private function metadata(int $productId, string $role): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id,content_type,size_bytes,updated_at FROM product_images WHERE product_id=:product AND role=:role'
        );
        $statement->execute(['product' => $productId, 'role' => $role]);
        $row = $statement->fetch();

        return is_array($row) ? [
            'id' => (int) $row['id'],
            'content_type' => (string) $row['content_type'],
            'size_bytes' => (int) $row['size_bytes'],
            'updated_at' => (string) $row['updated_at'],
        ] : null;
    }

    /** @return array{width: int, height: int, contentType: string, size: int, bytes: string, sourceHash: string, updatedAt: string} */
    public function responsive(int $productId, string $role, int $width): array
    {
        if ($this->responsiveImages === null) {
            throw new ApiException(503, 'Responsive image processing is unavailable.');
        }
        $metadata = $this->metadata($productId, $role);
        if ($metadata === null) {
            throw new ApiException(404, 'Product image not found.');
        }
        $cached = $this->responsiveImages->cachedVariant('PRODUCT', $metadata['id'], $width);
        if ($cached !== null) {
            return $cached;
        }
        $image = $this->get($productId, $role);
        if ($image === null) {
            throw new ApiException(404, 'Product image not found.');
        }

        return $this->responsiveImages->variant(
            'PRODUCT',
            (int) $image['id'],
            (string) $image['image_data'],
            $width
        );
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function replaceLegacyPlaceholder(array $row, int $productId, string $role): array
    {
        $offset = $role === 'SECONDARY' ? 1 : 0;
        $index = (max(1, $productId) - 1 + $offset) % count(self::LEGACY_PLACEHOLDER_FALLBACKS);
        $path = dirname(__DIR__) . '/resources/seed-images/' . self::LEGACY_PLACEHOLDER_FALLBACKS[$index];
        $bytes = is_file($path) ? file_get_contents($path) : false;
        if ($bytes === false) {
            return $row;
        }

        return [
            ...$row,
            'content_type' => 'image/jpeg',
            'size_bytes' => strlen($bytes),
            'image_data' => $bytes,
            'updated_at' => (string) (filemtime($path) ?: $row['updated_at']),
        ];
    }

}
