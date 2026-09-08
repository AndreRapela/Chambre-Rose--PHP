<?php

declare(strict_types=1);

namespace ChambreRose;

use PDO;

final class ResponsiveImageVariantRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{width: int, height: int, contentType: string, size: int, bytes: string, sourceHash: string, updatedAt: string}|null */
    public function find(string $ownerType, int $ownerId, int $width, ?string $sourceHash = null): ?array
    {
        $ownerColumn = self::ownerColumn($ownerType);
        $hashCondition = $sourceHash === null ? '' : ' AND source_hash=:source_hash';
        $statement = $this->pdo->prepare(
            "SELECT width,height,content_type,size_bytes,image_data,source_hash,updated_at
             FROM responsive_image_variants
             WHERE {$ownerColumn}=:owner AND width=:width{$hashCondition}"
        );
        $parameters = ['owner' => $ownerId, 'width' => $width];
        if ($sourceHash !== null) {
            $parameters['source_hash'] = $sourceHash;
        }
        $statement->execute($parameters);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }
        $bytes = self::lobToString($row['image_data'] ?? null);
        if ($bytes === null) {
            return null;
        }

        return [
            'width' => (int) $row['width'],
            'height' => (int) $row['height'],
            'contentType' => (string) $row['content_type'],
            'size' => (int) $row['size_bytes'],
            'bytes' => $bytes,
            'sourceHash' => (string) $row['source_hash'],
            'updatedAt' => (string) $row['updated_at'],
        ];
    }

    /**
     * @param list<array{width: int, height: int, contentType: string, size: int, bytes: string}> $variants
     */
    public function replace(string $ownerType, int $ownerId, string $sourceHash, array $variants): void
    {
        $ownerColumn = self::ownerColumn($ownerType);
        $startedTransaction = !$this->pdo->inTransaction();
        if ($startedTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $delete = $this->pdo->prepare("DELETE FROM responsive_image_variants WHERE {$ownerColumn}=:owner");
            $delete->execute(['owner' => $ownerId]);
            $this->save($ownerType, $ownerId, $sourceHash, $variants);
            if ($startedTransaction) {
                $this->pdo->commit();
            }
        } catch (\Throwable $exception) {
            if ($startedTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * @param list<array{width: int, height: int, contentType: string, size: int, bytes: string}> $variants
     */
    public function save(string $ownerType, int $ownerId, string $sourceHash, array $variants): void
    {
        $ownerColumn = self::ownerColumn($ownerType);
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        foreach ($variants as $variant) {
            if ($driver === 'mysql') {
                $sql = "INSERT INTO responsive_image_variants
                    ({$ownerColumn},width,height,content_type,size_bytes,image_data,source_hash,created_at,updated_at)
                    VALUES (:owner,:width,:height,:content_type,:size,:data,:source_hash,CURRENT_TIMESTAMP(3),CURRENT_TIMESTAMP(3))
                    ON DUPLICATE KEY UPDATE height=VALUES(height),content_type=VALUES(content_type),size_bytes=VALUES(size_bytes),
                    image_data=VALUES(image_data),source_hash=VALUES(source_hash),updated_at=CURRENT_TIMESTAMP(3)";
                $statement = $this->pdo->prepare($sql);
                $statement->bindValue(':owner', $ownerId, PDO::PARAM_INT);
                $statement->bindValue(':width', $variant['width'], PDO::PARAM_INT);
                $statement->bindValue(':height', $variant['height'], PDO::PARAM_INT);
                $statement->bindValue(':content_type', $variant['contentType']);
                $statement->bindValue(':size', $variant['size'], PDO::PARAM_INT);
                $statement->bindValue(':data', $variant['bytes'], PDO::PARAM_LOB);
                $statement->bindValue(':source_hash', $sourceHash);
                $statement->execute();
                continue;
            }

            if ($driver === 'sqlite') {
                $sql = "INSERT INTO responsive_image_variants
                    ({$ownerColumn},width,height,content_type,size_bytes,image_data,source_hash,created_at,updated_at)
                    VALUES (:owner,:width,:height,:content_type,:size,:data,:source_hash,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)
                    ON CONFLICT ({$ownerColumn},width) DO UPDATE SET height=excluded.height,content_type=excluded.content_type,
                    size_bytes=excluded.size_bytes,image_data=excluded.image_data,source_hash=excluded.source_hash,updated_at=CURRENT_TIMESTAMP";
                $statement = $this->pdo->prepare($sql);
                $statement->bindValue(':owner', $ownerId, PDO::PARAM_INT);
                $statement->bindValue(':width', $variant['width'], PDO::PARAM_INT);
                $statement->bindValue(':height', $variant['height'], PDO::PARAM_INT);
                $statement->bindValue(':content_type', $variant['contentType']);
                $statement->bindValue(':size', $variant['size'], PDO::PARAM_INT);
                $statement->bindValue(':data', $variant['bytes'], PDO::PARAM_LOB);
                $statement->bindValue(':source_hash', $sourceHash);
                $statement->execute();
                continue;
            }

            $sql = "INSERT INTO responsive_image_variants
                ({$ownerColumn},width,height,content_type,size_bytes,image_data,source_hash,created_at,updated_at)
                VALUES (:owner,:width,:height,:content_type,:size,decode(:data,'base64'),:source_hash,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)
                ON CONFLICT ({$ownerColumn},width) DO UPDATE SET height=EXCLUDED.height,content_type=EXCLUDED.content_type,
                size_bytes=EXCLUDED.size_bytes,image_data=EXCLUDED.image_data,source_hash=EXCLUDED.source_hash,updated_at=CURRENT_TIMESTAMP";
            $this->pdo->prepare($sql)->execute([
                'owner' => $ownerId,
                'width' => $variant['width'],
                'height' => $variant['height'],
                'content_type' => $variant['contentType'],
                'size' => $variant['size'],
                'data' => base64_encode($variant['bytes']),
                'source_hash' => $sourceHash,
            ]);
        }
    }

    private static function ownerColumn(string $ownerType): string
    {
        return match ($ownerType) {
            'PROFILE' => 'profile_media_id',
            'PRODUCT' => 'product_image_id',
            default => throw new \InvalidArgumentException('Unsupported responsive image owner.'),
        };
    }

    private static function lobToString(mixed $value): ?string
    {
        if (is_resource($value)) {
            $value = stream_get_contents($value);
        }

        return is_string($value) ? $value : null;
    }
}
