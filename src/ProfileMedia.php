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

        return array_map(
            static fn (array $row): array => self::map($row, $public),
            $statement->fetchAll()
        );
    }

    /**
     * @param list<int> $userIds
     * @return array<int, list<array<string, mixed>>>
     */
    public function listForUsers(array $userIds, bool $public = false): array
    {
        $userIds = array_values(array_unique(array_filter(
            array_map(static fn (int $userId): int => $userId, $userIds),
            static fn (int $userId): bool => $userId > 0
        )));
        if ($userIds === []) {
            return [];
        }

        $mediaByUser = array_fill_keys($userIds, []);
        $placeholders = [];
        foreach ($userIds as $index => $userId) {
            $placeholder = ':user_id_' . $index;
            $placeholders[] = $placeholder;
        }

        $statement = $this->pdo->prepare(
            'SELECT id, user_id, media_type, file_name, content_type, size_bytes, position, created_at'
            . ' FROM profile_media WHERE user_id IN (' . implode(', ', $placeholders) . ')'
            . ' ORDER BY user_id, media_type, position, id'
        );
        foreach ($userIds as $index => $userId) {
            $statement->bindValue(':user_id_' . $index, $userId, PDO::PARAM_INT);
        }
        $statement->execute();

        foreach ($statement->fetchAll() as $row) {
            $userId = (int) $row['user_id'];
            $mediaByUser[$userId][] = self::map($row, $public);
        }

        return $mediaByUser;
    }

    public function countType(int $userId, string $type): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM profile_media WHERE user_id=:id AND media_type=:type');
        $statement->execute(['id' => $userId, 'type' => $type]);

        return (int) $statement->fetchColumn();
    }

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

    public function metadata(int $userId, int $mediaId): ?array
    {
        $statement = $this->pdo->prepare('SELECT id,user_id,media_type,file_name,content_type,size_bytes,position,created_at FROM profile_media WHERE user_id=:uid AND id=:id');
        $statement->execute(['uid' => $userId,'id' => $mediaId]);
        $row = $statement->fetch();

        return is_array($row) ? self::map($row) : null;
    }

    public function data(int $userId, int $mediaId): ?string
    {
        $statement = $this->pdo->prepare('SELECT media_data FROM profile_media WHERE user_id=:uid AND id=:id');
        $statement->execute(['uid' => $userId,'id' => $mediaId]);
        $value = $statement->fetchColumn();
        if ($value === false) {
            return null;
        } if (is_resource($value)) {
            $value = stream_get_contents($value);
        }

        return is_string($value) ? $value : null;
    }

    public function delete(int $userId, int $mediaId): void
    {
        $statement = $this->pdo->prepare('DELETE FROM profile_media WHERE user_id=:uid AND id=:id');
        $statement->execute(['uid' => $userId,'id' => $mediaId]);
        if ($statement->rowCount() === 0) {
            throw new ApiException(404, 'Media not found.');
        }
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function map(array $row, bool $public = false): array
    {
        $media = ['id' => (int)$row['id'],'userId' => (int)$row['user_id'],'type' => (string)$row['media_type'],
            'fileName' => (string)$row['file_name'],'contentType' => (string)$row['content_type'],'size' => (int)$row['size_bytes'],
            'position' => (int)$row['position'],'url' => '/api/profiles/' . (int)$row['user_id'] . '/media/' . (int)$row['id'],
            'createdAt' => (string)$row['created_at']];
        if ($public) {
            unset($media['fileName']);
        }

        return $media;
    }
}
