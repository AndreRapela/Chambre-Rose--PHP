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

    /**
     * @param list<int> $candidateIds
     * @return list<int>
     */
    public function excludedUserIds(int $viewerId, array $candidateIds): array
    {
        $candidateIds = array_values(array_unique(array_filter(
            array_map('intval', $candidateIds),
            static fn (int $id): bool => $id > 0 && $id !== $viewerId
        )));
        if ($viewerId <= 0 || $candidateIds === []) {
            return [];
        }

        $excluded = [];
        foreach (array_chunk($candidateIds, 400) as $candidateChunk) {
            $outbound = [];
            $inbound = [];
            $parameters = ['owner_id' => $viewerId, 'excluded_user_id' => $viewerId];
            foreach ($candidateChunk as $index => $candidateId) {
                $outboundName = 'outbound_id_' . $index;
                $inboundName = 'inbound_id_' . $index;
                $outbound[] = ':' . $outboundName;
                $inbound[] = ':' . $inboundName;
                $parameters[$outboundName] = $candidateId;
                $parameters[$inboundName] = $candidateId;
            }

            $statement = $this->pdo->prepare(
                'SELECT excluded_user_id AS user_id FROM user_exclusions'
                . ' WHERE owner_id=:owner_id AND excluded_user_id IN (' . implode(',', $outbound) . ')'
                . ' UNION SELECT owner_id AS user_id FROM user_exclusions'
                . ' WHERE excluded_user_id=:excluded_user_id AND owner_id IN (' . implode(',', $inbound) . ')'
            );
            $statement->execute($parameters);
            foreach ($statement->fetchAll() as $row) {
                $excluded[(int) $row['user_id']] = true;
            }
        }

        return array_map('intval', array_keys($excluded));
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
