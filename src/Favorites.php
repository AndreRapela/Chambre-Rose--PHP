<?php

declare(strict_types=1);

namespace ChambreRose;

use PDO;

final class FavoritesRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }
    public function add(int $userId, int $profileId): void
    {
        if ($userId === $profileId) {
            throw new ApiException(400, 'You cannot favorite your own profile.');
        }
        $sql = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
            ? 'INSERT IGNORE INTO favorites (user_id,profile_user_id,created_at) VALUES (:uid,:pid,CURRENT_TIMESTAMP(3))'
            : 'INSERT INTO favorites (user_id,profile_user_id,created_at) VALUES (:uid,:pid,CURRENT_TIMESTAMP) ON CONFLICT DO NOTHING';
        $this->pdo->prepare($sql)->execute(['uid' => $userId,'pid' => $profileId]);
    }
    public function remove(int $userId, int $profileId): void
    {
        $this->pdo->prepare('DELETE FROM favorites WHERE user_id=:uid AND profile_user_id=:pid')->execute(['uid' => $userId,'pid' => $profileId]);
    }
    /** @return list<int> */
    public function ids(int $userId): array
    {
        $s = $this->pdo->prepare('SELECT profile_user_id FROM favorites WHERE user_id=:id ORDER BY created_at DESC');
        $s->execute(['id' => $userId]);

        return array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
    }
}
