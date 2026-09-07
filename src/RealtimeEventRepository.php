<?php

declare(strict_types=1);

namespace ChambreRose;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use PDO;
use Throwable;

final class RealtimeEventRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function transaction(callable $operation): mixed
    {
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $result = $operation();
            if ($ownsTransaction) {
                $this->pdo->commit();
            }

            return $result;
        } catch (Throwable $exception) {
            if ($ownsTransaction) {
                try {
                    $this->pdo->rollBack();
                } catch (Throwable) {
                    // Preserve the original failure.
                }
            }

            throw $exception;
        }
    }

    /** @param array<string, mixed> $payload */
    public function publish(int $userId, string $eventType, ?int $resourceId = null, array $payload = []): int
    {
        try {
            $encodedPayload = json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
        } catch (JsonException) {
            throw new ApiException(500, 'Unable to record a realtime event.');
        }

        $params = [
            'user_id' => $userId,
            'event_type' => $eventType,
            'resource_id' => $resourceId,
            'payload' => $encodedPayload,
        ];
        if ($this->isMySql()) {
            $statement = $this->pdo->prepare(
                'INSERT INTO realtime_events (user_id,event_type,resource_id,payload,created_at) '
                . 'VALUES (:user_id,:event_type,:resource_id,:payload,CURRENT_TIMESTAMP(3))'
            );
            $statement->execute($params);

            return (int) $this->pdo->lastInsertId();
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO realtime_events (user_id,event_type,resource_id,payload,created_at) '
            . 'VALUES (:user_id,:event_type,:resource_id,:payload,CURRENT_TIMESTAMP) RETURNING id'
        );
        $statement->execute($params);

        return (int) $statement->fetchColumn();
    }

    /**
     * @param list<int> $userIds
     * @param array<string, mixed> $payload
     */
    public function publishForUsers(array $userIds, string $eventType, ?int $resourceId = null, array $payload = []): void
    {
        $uniqueUserIds = array_values(array_unique(array_filter($userIds, static fn (int $id): bool => $id > 0)));
        foreach ($uniqueUserIds as $userId) {
            $this->publish($userId, $eventType, $resourceId, $payload);
        }
    }

    /** @return list<array{id: int, type: string, resourceId: int|null, payload: array<string, mixed>, createdAt: string}> */
    public function after(int $userId, int $cursor, int $limit = 50): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id,event_type,resource_id,payload,created_at FROM realtime_events '
            . 'WHERE user_id=:user_id AND id>:cursor ORDER BY id ASC LIMIT :limit'
        );
        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $statement->bindValue(':cursor', max(0, $cursor), PDO::PARAM_INT);
        $statement->bindValue(':limit', min(100, max(1, $limit)), PDO::PARAM_INT);
        $statement->execute();

        $events = [];
        foreach ($statement->fetchAll() as $row) {
            try {
                $payload = json_decode((string) $row['payload'], true, 32, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $payload = [];
            }
            $events[] = [
                'id' => (int) $row['id'],
                'type' => (string) $row['event_type'],
                'resourceId' => $row['resource_id'] === null ? null : (int) $row['resource_id'],
                'payload' => is_array($payload) ? $payload : [],
                'createdAt' => (string) $row['created_at'],
            ];
        }

        return $events;
    }

    public function latestId(int $userId): int
    {
        $statement = $this->pdo->prepare('SELECT COALESCE(MAX(id),0) FROM realtime_events WHERE user_id=:user_id');
        $statement->execute(['user_id' => $userId]);

        return (int) $statement->fetchColumn();
    }

    public function purgeExpired(int $retentionDays): int
    {
        $cutoff = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('-' . max(1, $retentionDays) . ' days')
            ->format('Y-m-d H:i:s.u');
        $statement = $this->pdo->prepare('DELETE FROM realtime_events WHERE created_at<:cutoff');
        $statement->execute(['cutoff' => $cutoff]);

        return $statement->rowCount();
    }

    private function isMySql(): bool
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    }
}
