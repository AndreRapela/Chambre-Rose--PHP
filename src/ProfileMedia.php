<?php

declare(strict_types=1);

namespace ChambreRose;

use PDO;

final class ProfileMediaRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array<string, mixed>> */
    public function listFor(int $userId, bool $public = false): array
    {
        $statement = $this->pdo->prepare('SELECT id, user_id, media_type, file_name, content_type, size_bytes, position, created_at FROM profile_media WHERE user_id=:id ORDER BY media_type, position, id');
        $statement->execute(['id' => $userId]);

        return $this->mapRows($statement->fetchAll(), $public);
    }

    /**
     * Returns only the first photo required by each listing card.
     *
     * @param list<int> $userIds
     * @return array<int, list<array<string, mixed>>>
     */
    public function firstPhotosForUsers(array $userIds, bool $public = false): array
    {
        $userIds = array_values(array_unique(array_filter(
            array_map(static fn (int $userId): int => $userId, $userIds),
            static fn (int $userId): bool => $userId > 0
        )));
        if ($userIds === []) {
            return [];
        }

        $mediaByUser = array_fill_keys($userIds, []);
        $placeholders = array_map(
            static fn (int $index): string => ':cover_user_id_' . $index,
            array_keys($userIds)
        );
        $statement = $this->pdo->prepare(
            'SELECT id, user_id, media_type, file_name, content_type, size_bytes, position, created_at FROM ('
            . ' SELECT id, user_id, media_type, file_name, content_type, size_bytes, position, created_at,'
            . ' ROW_NUMBER() OVER (PARTITION BY user_id ORDER BY position, id) AS media_rank'
            . ' FROM profile_media WHERE media_type=\'PHOTO\' AND user_id IN (' . implode(', ', $placeholders) . ')'
            . ') ranked_media WHERE media_rank=1 ORDER BY user_id'
        );
        foreach ($userIds as $index => $userId) {
            $statement->bindValue(':cover_user_id_' . $index, $userId, PDO::PARAM_INT);
        }
        $statement->execute();

        foreach ($this->mapRows($statement->fetchAll(), $public) as $media) {
            $mediaByUser[(int) $media['userId']][] = $media;
        }

        return $mediaByUser;
    }

    public function countType(int $userId, string $type): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM profile_media WHERE user_id=:id AND media_type=:type');
        $statement->execute(['id' => $userId, 'type' => $type]);

        return (int) $statement->fetchColumn();
    }

    /** @return array<string, mixed> */
    public function insertWithinLimit(
        int $userId,
        string $type,
        string $name,
        string $contentType,
        string $bytes,
        int $position,
        int $limit
    ): array {
        $this->pdo->beginTransaction();

        try {
            $lock = $this->pdo->prepare('SELECT id FROM users WHERE id=:id FOR UPDATE');
            $lock->execute(['id' => $userId]);
            if ($lock->fetchColumn() === false) {
                throw new ApiException(404, 'User not found.');
            }
            if ($this->countType($userId, $type) >= $limit) {
                throw new ApiException(
                    409,
                    $type === 'PHOTO'
                        ? 'A profile can contain at most 15 photos.'
                        : 'A profile can contain at most 3 videos.'
                );
            }
            $media = $this->insert($userId, $type, $name, $contentType, $bytes, $position);
            $this->pdo->commit();

            return $media;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    private function insert(int $userId, string $type, string $name, string $contentType, string $bytes, int $position): array
    {
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $sql = 'INSERT INTO profile_media (user_id,media_type,file_name,content_type,size_bytes,media_data,position,created_at) VALUES (:uid,:type,:name,:content,:size,:data,:position,CURRENT_TIMESTAMP(3))';
            $statement = $this->pdo->prepare($sql);
            $statement->bindValue(':uid', $userId, PDO::PARAM_INT);
            $statement->bindValue(':type', $type);
            $statement->bindValue(':name', $name);
            $statement->bindValue(':content', $contentType);
            $statement->bindValue(':size', strlen($bytes), PDO::PARAM_INT);
            $statement->bindValue(':data', $bytes, PDO::PARAM_LOB);
            $statement->bindValue(':position', $position, PDO::PARAM_INT);
            $statement->execute();
            $id = (int) $this->pdo->lastInsertId();
        } else {
            $statement = $this->pdo->prepare('INSERT INTO profile_media (user_id,media_type,file_name,content_type,size_bytes,media_data,position,created_at) VALUES (:uid,:type,:name,:content,:size,decode(:data,\'base64\'),:position,CURRENT_TIMESTAMP) RETURNING id');
            $statement->execute(['uid' => $userId,'type' => $type,'name' => $name,'content' => $contentType,'size' => strlen($bytes),'data' => base64_encode($bytes),'position' => $position]);
            $id = (int) $statement->fetchColumn();
        }

        return $this->metadata($userId, $id) ?? throw new ApiException(500, 'Unable to store media.');
    }

    /** @return array<string, mixed>|null */
    public function metadata(int $userId, int $mediaId): ?array
    {
        $statement = $this->pdo->prepare('SELECT id,user_id,media_type,file_name,content_type,size_bytes,position,created_at FROM profile_media WHERE user_id=:uid AND id=:id');
        $statement->execute(['uid' => $userId,'id' => $mediaId]);
        $row = $statement->fetch();

        return is_array($row) ? $this->mapRows([$row], false)[0] : null;
    }

    public function data(int $userId, int $mediaId): ?string
    {
        $statement = $this->pdo->prepare('SELECT media_data FROM profile_media WHERE user_id=:uid AND id=:id');
        $statement->execute(['uid' => $userId,'id' => $mediaId]);
        return self::lobToString($statement->fetchColumn());
    }

    /** @return \Generator<int, string, void, void> */
    public function chunks(
        int $userId,
        int $mediaId,
        int $start,
        int $length,
        int $chunkSize = 1_048_576
    ): \Generator {
        if ($start < 0 || $length <= 0 || $chunkSize <= 0) {
            throw new \InvalidArgumentException('Media byte ranges must contain positive lengths and offsets.');
        }

        $chunkSize = min($chunkSize, 1_048_576);
        $offset = $start;
        $remaining = $length;
        while ($remaining > 0) {
            $requested = min($chunkSize, $remaining);
            $bytes = $this->dataRange($userId, $mediaId, $offset, $requested);
            if ($bytes === null || $bytes === '') {
                return;
            }

            yield $bytes;
            $read = strlen($bytes);
            $offset += $read;
            $remaining -= $read;
            if ($read < $requested) {
                return;
            }
        }
    }

    public function delete(int $userId, int $mediaId): void
    {
        $statement = $this->pdo->prepare('DELETE FROM profile_media WHERE user_id=:uid AND id=:id');
        $statement->execute(['uid' => $userId,'id' => $mediaId]);
        if ($statement->rowCount() === 0) {
            throw new ApiException(404, 'Media not found.');
        }
    }

    /**
     * @param array<string, mixed> $row
     * @param list<int> $responsiveWidths
     * @return array<string, mixed>
     */
    private static function map(array $row, bool $public = false, array $responsiveWidths = []): array
    {
        $url = '/api/profiles/' . (int) $row['user_id'] . '/media/' . (int) $row['id'];
        $media = ['id' => (int)$row['id'],'userId' => (int)$row['user_id'],'type' => (string)$row['media_type'],
            'fileName' => (string)$row['file_name'],'contentType' => (string)$row['content_type'],'size' => (int)$row['size_bytes'],
            'position' => (int)$row['position'],'url' => $url,
            'createdAt' => (string)$row['created_at']];
        if ($media['type'] === 'PHOTO' && $responsiveWidths !== []) {
            $media['srcSet'] = ResponsiveImageService::srcSet($url, $responsiveWidths);
        }
        if ($public) {
            unset($media['fileName']);
        }

        return $media;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function mapRows(array $rows, bool $public): array
    {
        $photoIds = array_values(array_map(
            static fn (array $row): int => (int) $row['id'],
            array_filter($rows, static fn (array $row): bool => $row['media_type'] === 'PHOTO')
        ));
        $responsiveWidths = $this->responsiveWidths($photoIds);

        return array_map(
            static fn (array $row): array => self::map(
                $row,
                $public,
                $responsiveWidths[(int) $row['id']] ?? []
            ),
            $rows
        );
    }

    /**
     * @param list<int> $mediaIds
     * @return array<int, list<int>>
     */
    private function responsiveWidths(array $mediaIds): array
    {
        if ($mediaIds === []) {
            return [];
        }

        $placeholders = array_map(
            static fn (int $index): string => ':responsive_media_id_' . $index,
            array_keys($mediaIds)
        );
        $statement = $this->pdo->prepare(
            'SELECT profile_media_id, width FROM responsive_image_variants'
            . ' WHERE profile_media_id IN (' . implode(', ', $placeholders) . ')'
            . ' ORDER BY profile_media_id, width'
        );
        foreach ($mediaIds as $index => $mediaId) {
            $statement->bindValue(':responsive_media_id_' . $index, $mediaId, PDO::PARAM_INT);
        }
        $statement->execute();

        $widths = [];
        foreach ($statement->fetchAll() as $row) {
            $widths[(int) $row['profile_media_id']][] = (int) $row['width'];
        }

        return $widths;
    }

    private static function lobToString(mixed $value): ?string
    {
        if (is_resource($value)) {
            $value = stream_get_contents($value);
        }

        return is_string($value) ? $value : null;
    }

    private function dataRange(int $userId, int $mediaId, int $offset, int $length): ?string
    {
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $sql = match ($driver) {
            'mysql' => 'SELECT SUBSTRING(media_data, :position, :length) FROM profile_media WHERE user_id=:uid AND id=:id',
            'pgsql' => 'SELECT SUBSTRING(media_data FROM :position FOR :length) FROM profile_media WHERE user_id=:uid AND id=:id',
            'sqlite' => 'SELECT SUBSTR(media_data, :position, :length) FROM profile_media WHERE user_id=:uid AND id=:id',
            default => throw new \RuntimeException('Unsupported database driver for ranged media streaming.'),
        };
        $statement = $this->pdo->prepare($sql);
        $statement->bindValue(':position', $offset + 1, PDO::PARAM_INT);
        $statement->bindValue(':length', $length, PDO::PARAM_INT);
        $statement->bindValue(':uid', $userId, PDO::PARAM_INT);
        $statement->bindValue(':id', $mediaId, PDO::PARAM_INT);
        $statement->execute();

        return self::lobToString($statement->fetchColumn());
    }
}
