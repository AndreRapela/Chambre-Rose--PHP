<?php

declare(strict_types=1);

namespace ChambreRose;

use PDO;

final class UserExclusionRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function existsBetween(int $one, int $two): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM user_exclusions '
            . 'WHERE (owner_id=:one_a AND excluded_user_id=:two_a) '
            . 'OR (owner_id=:two_b AND excluded_user_id=:one_b) LIMIT 1'
        );
        $statement->execute([
            'one_a' => $one,
            'two_a' => $two,
            'two_b' => $two,
            'one_b' => $one,
        ]);

        return (bool) $statement->fetchColumn();
    }

    public function exclude(int $ownerId, int $excludedUserId): void
    {
        if ($ownerId === $excludedUserId) {
            throw new ApiException(400, 'You cannot remove yourself.');
        }
        $sql = $this->isMySql()
            ? 'INSERT IGNORE INTO user_exclusions (owner_id,excluded_user_id,created_at) '
                . 'VALUES (:owner_id,:excluded_user_id,CURRENT_TIMESTAMP(3))'
            : 'INSERT INTO user_exclusions (owner_id,excluded_user_id,created_at) '
                . 'VALUES (:owner_id,:excluded_user_id,CURRENT_TIMESTAMP) ON CONFLICT DO NOTHING';
        $this->pdo->prepare($sql)->execute([
            'owner_id' => $ownerId,
            'excluded_user_id' => $excludedUserId,
        ]);
    }

    private function isMySql(): bool
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    }
}
