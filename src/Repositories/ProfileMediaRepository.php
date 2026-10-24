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
    public function listFor(
        int $userId,
        bool $public = false,
        ?string $type = null,
        ?int $limit = null,
        int $offset = 0
    ): array
    {
        if ($type !== null && !in_array($type, ['PHOTO', 'VIDEO'], true)) {
            throw new \InvalidArgumentException('Unsupported profile media type.');
        }
        $whereType = $type === null ? '' : ' AND media_type=:type';
        $pagination = $limit === null ? '' : ' LIMIT :limit OFFSET :offset';
        $statement = $this->pdo->prepare(
            'SELECT id, user_id, media_type, file_name, content_type, size_bytes, position, created_at'
            . ' FROM profile_media WHERE user_id=:id' . $whereType
            . ' ORDER BY media_type, position, id' . $pagination
        );
        $statement->bindValue(':id', $userId, PDO::PARAM_INT);
        if ($type !== null) {
            $statement->bindValue(':type', $type);
        }
        if ($limit !== null) {
            $statement->bindValue(':limit', max(1, min(50, $limit)), PDO::PARAM_INT);
            $statement->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
        }
        $statement->execute();

        return $this->mapRows($statement->fetchAll(), $public);
    }

    public function countAll(int $userId): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM profile_media WHERE user_id=:id');
        $statement->execute(['id' => $userId]);

        return (int) $statement->fetchColumn();
    }

    /** @return array{PHOTO:int,VIDEO:int} */
    public function countTypes(int $userId): array
    {
        $counts = ['PHOTO' => 0, 'VIDEO' => 0];
        $statement = $this->pdo->prepare(
            'SELECT media_type,COUNT(*) AS media_count FROM profile_media WHERE user_id=:id GROUP BY media_type'
        );
        $statement->execute(['id' => $userId]);
        foreach ($statement->fetchAll() as $row) {
            if (isset($counts[$row['media_type']])) {
                $counts[$row['media_type']] = (int) $row['media_count'];
            }
        }

        return $counts;
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

    /** @return array<string, mixed>|null */
    public function profilePhotoForUser(int $userId, bool $public = false): ?array
    {
        $photos = $this->firstPhotosForUsers([$userId], $public);
        $photo = $photos[$userId][0] ?? null;
        if (!is_array($photo)) {
            return null;
        }

        $mediaUrl = (string) ($photo['url'] ?? '');
        $profileBaseUrl = '/api/profiles/' . $userId . '/profile-photo';
        $profileUrl = $profileBaseUrl . '?v=media-v3-' . (int) $photo['id'];
        $photo['url'] = $profileUrl;
        if ($mediaUrl !== '' && isset($photo['srcSet']) && is_string($photo['srcSet'])) {
            preg_match_all('/\/(320|640|960|1280)\.webp(?:\?[^ ]*)?\s+\1w/', $photo['srcSet'], $matches);
            $widths = array_values(array_unique(array_map('intval', $matches[1])));
            $photo['srcSet'] = implode(', ', array_map(
                static fn (int $width): string => $profileBaseUrl . '/' . $width . '.webp?v=media-v3-' . (int) $photo['id'] . ' ' . $width . 'w',
                $widths
            ));
        }

        return $photo;
    }

    public function profilePhotoIdForUser(int $userId): ?int
    {
        $statement = $this->pdo->prepare(
            "SELECT id FROM profile_media WHERE user_id=:id AND media_type='PHOTO' ORDER BY position,id LIMIT 1"
        );
        $statement->execute(['id' => $userId]);
        $mediaId = $statement->fetchColumn();

        return $mediaId === false ? null : (int) $mediaId;
    }

    public function publicProfilePhotoIdForUser(int $userId): ?int
    {
        $statement = $this->pdo->prepare(
            "SELECT media.id FROM profile_media media"
            . " JOIN professional_profiles profile ON profile.user_id=media.user_id"
            . " JOIN users user_account ON user_account.id=media.user_id"
            . " WHERE media.user_id=:id AND media.media_type='PHOTO'"
            . " AND user_account.approval_status='APPROVED'"
            . " AND user_account.role IN ('ESCORT','STORE')"
            . ' ORDER BY media.position,media.id LIMIT 1'
        );
        $statement->execute(['id' => $userId]);
        $mediaId = $statement->fetchColumn();

        return $mediaId === false ? null : (int) $mediaId;
    }

    public function countType(int $userId, string $type): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM profile_media WHERE user_id=:id AND media_type=:type');
        $statement->execute(['id' => $userId, 'type' => $type]);

        return (int) $statement->fetchColumn();
    }

    /**
     * Counts one media type for several profiles in a single query.
     *
     * @param list<int> $userIds
     * @return array<int, int>
     */
    public function countTypeForUsers(array $userIds, string $type): array
    {
        $userIds = array_values(array_unique(array_filter(
            array_map(static fn (int $userId): int => $userId, $userIds),
            static fn (int $userId): bool => $userId > 0
        )));
        if ($userIds === []) {
            return [];
        }

        $counts = array_fill_keys($userIds, 0);
        $placeholders = array_map(
            static fn (int $index): string => ':count_user_id_' . $index,
            array_keys($userIds)
        );
        $statement = $this->pdo->prepare(
            'SELECT user_id, COUNT(*) AS media_count FROM profile_media'
            . ' WHERE media_type=:media_type AND user_id IN (' . implode(', ', $placeholders) . ')'
            . ' GROUP BY user_id'
        );
        $statement->bindValue(':media_type', $type);
        foreach ($userIds as $index => $userId) {
            $statement->bindValue(':count_user_id_' . $index, $userId, PDO::PARAM_INT);
        }
        $statement->execute();

        foreach ($statement->fetchAll() as $row) {
            $counts[(int) $row['user_id']] = (int) $row['media_count'];
        }

        return $counts;
    }

    public function isFirstPhoto(int $userId, int $mediaId): bool
    {
        $statement = $this->pdo->prepare(
            "SELECT id FROM profile_media WHERE user_id=:id AND media_type='PHOTO' ORDER BY position,id LIMIT 1"
        );
        $statement->execute(['id' => $userId]);

        return (int) $statement->fetchColumn() === $mediaId;
    }

    public function promotePhoto(int $userId, int $mediaId): void
    {
        $media = $this->metadata($userId, $mediaId);
        if ($media === null || $media['type'] !== 'PHOTO') {
            throw new ApiException(404, 'Profile photo not found.');
        }
        $statement = $this->pdo->prepare(
            "UPDATE profile_media SET position=CASE WHEN id=:media_id THEN 0 ELSE position+1 END"
            . " WHERE user_id=:user_id AND media_type='PHOTO'"
        );
        $statement->execute(['media_id' => $mediaId, 'user_id' => $userId]);
        if (!$this->isFirstPhoto($userId, $mediaId)) throw new ApiException(500, 'Unable to select profile photo.');
    }

    /** @return array<string, mixed> */
    public function insertWithinLimit(
        int $userId,
        string $type,
        string $name,
        string $contentType,
        string $bytes,
        int $position,
        ?int $limit
    ): array {
        $this->pdo->beginTransaction();

        try {
            $lock = $this->pdo->prepare('SELECT id FROM users WHERE id=:id FOR UPDATE');
            $lock->execute(['id' => $userId]);
            if ($lock->fetchColumn() === false) {
                throw new ApiException(404, 'User not found.');
            }
            if ($limit !== null && $this->countType($userId, $type) >= $limit) {
                throw new ApiException(
                    409,
                    $type === 'PHOTO'
                        ? sprintf('A profile can contain at most %d photos.', $limit)
                        : sprintf('A profile can contain at most %d videos.', $limit)
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
        $url = '/api/profiles/' . (int) $row['user_id'] . '/media/' . (int) $row['id'] . '?v=media-v3';
        $media = ['id' => (int)$row['id'],'userId' => (int)$row['user_id'],'type' => (string)$row['media_type'],
            'fileName' => (string)$row['file_name'],'contentType' => (string)$row['content_type'],'size' => (int)$row['size_bytes'],
            'position' => (int)$row['position'],'url' => $url,
            'createdAt' => (string)$row['created_at']];
        if ($media['type'] === 'PHOTO') {
            $availableResponsiveWidths = array_values(array_filter(
                $responsiveWidths,
                static fn (int $width): bool => in_array($width, ResponsiveImageProcessor::WIDTHS, true)
            ));
            // Older photos predate stored WebP variants. Advertise the standard
            // widths so the image endpoint can generate/cache one on first use
            // instead of making the browser download the original upload.
            $widthsForSrcSet = $responsiveWidths === [] ? null : $availableResponsiveWidths;
            $srcSet = ResponsiveImageService::srcSet($url, $widthsForSrcSet);
            if ($srcSet !== '') {
                $media['srcSet'] = $srcSet;
            }
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
