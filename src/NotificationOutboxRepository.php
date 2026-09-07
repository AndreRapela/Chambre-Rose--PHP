<?php

declare(strict_types=1);

namespace ChambreRose;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

final class NotificationOutboxRepository implements NotificationOutboxStore
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
                    // Preserve the original operation/commit failure.
                }
            }

            throw $exception;
        }
    }

    public function enqueue(int $notificationId, int $userId, int $maxAttempts): void
    {
        $params = [
            'notification_id' => $notificationId,
            'user_id' => $userId,
            'max_attempts' => max(1, $maxAttempts),
        ];
        $sql = $this->isMySql()
            ? <<<'SQL'
                INSERT INTO push_notification_outbox (
                  notification_id,user_id,status,attempts,max_attempts,available_at,created_at,updated_at
                ) VALUES (
                  :notification_id,:user_id,'PENDING',0,:max_attempts,CURRENT_TIMESTAMP(3),CURRENT_TIMESTAMP(3),CURRENT_TIMESTAMP(3)
                ) ON DUPLICATE KEY UPDATE notification_id=VALUES(notification_id)
                SQL
            : <<<'SQL'
                INSERT INTO push_notification_outbox (
                  notification_id,user_id,status,attempts,max_attempts,available_at,created_at,updated_at
                ) VALUES (
                  :notification_id,:user_id,'PENDING',0,:max_attempts,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP
                ) ON CONFLICT (notification_id) DO NOTHING
                SQL;
        $this->pdo->prepare($sql)->execute($params);
    }

    /** @return list<array<string, mixed>> */
    public function claim(int $limit, string $workerId, int $lockTimeoutSeconds): array
    {
        $limit = min(100, max(1, $limit));
        $staleBefore = self::timestamp(-max(30, $lockTimeoutSeconds));

        return $this->transaction(function () use ($limit, $workerId, $staleBefore): array {
            $this->recoverAbandonedJobs($staleBefore);

            $lockClause = $this->isMySql()
                ? ' FOR UPDATE SKIP LOCKED'
                : ' FOR UPDATE OF outbox SKIP LOCKED';
            $statement = $this->pdo->prepare(
                'SELECT outbox.id,outbox.notification_id,outbox.user_id,outbox.attempts,outbox.max_attempts,'
                . 'notification.category,notification.event_type,notification.title,notification.body,'
                . 'notification.target_url,notification.created_at '
                . 'FROM push_notification_outbox outbox '
                . 'INNER JOIN account_notifications notification ON notification.id=outbox.notification_id '
                . "WHERE outbox.status IN ('PENDING','RETRY') "
                . 'AND outbox.available_at<=CURRENT_TIMESTAMP AND outbox.attempts<outbox.max_attempts '
                . 'ORDER BY outbox.id ASC LIMIT :limit' . $lockClause
            );
            $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
            $statement->execute();
            /** @var list<array<string, mixed>> $jobs */
            $jobs = $statement->fetchAll();
            if ($jobs === []) {
                return [];
            }

            $claim = $this->pdo->prepare(
                "UPDATE push_notification_outbox SET status='PROCESSING',attempts=attempts+1,"
                . 'locked_at=CURRENT_TIMESTAMP,locked_by=:worker_id,updated_at=CURRENT_TIMESTAMP '
                . "WHERE id=:id AND status IN ('PENDING','RETRY')"
            );
            foreach ($jobs as &$job) {
                $claim->execute(['id' => (int) $job['id'], 'worker_id' => $workerId]);
                $job['attempts'] = (int) $job['attempts'] + 1;
                $job['max_attempts'] = (int) $job['max_attempts'];
                $job['id'] = (int) $job['id'];
                $job['notification_id'] = (int) $job['notification_id'];
                $job['user_id'] = (int) $job['user_id'];
            }
            unset($job);

            return $jobs;
        });
    }

    public function markDelivered(int $id): void
    {
        $this->pdo->prepare(
            "UPDATE push_notification_outbox SET status='DELIVERED',delivered_at=CURRENT_TIMESTAMP,"
            . 'locked_at=NULL,locked_by=NULL,last_error=NULL,updated_at=CURRENT_TIMESTAMP '
            . "WHERE id=:id AND status='PROCESSING'"
        )->execute(['id' => $id]);
    }

    public function markSkipped(int $id, string $reason): void
    {
        $this->pdo->prepare(
            "UPDATE push_notification_outbox SET status='SKIPPED',locked_at=NULL,locked_by=NULL,"
            . 'last_error=:reason,updated_at=CURRENT_TIMESTAMP '
            . "WHERE id=:id AND status='PROCESSING'"
        )->execute(['id' => $id, 'reason' => self::error($reason)]);
    }

    public function recordFailure(
        int $id,
        int $attempts,
        int $maxAttempts,
        string $error,
        int $delaySeconds
    ): string {
        $failed = $attempts >= $maxAttempts;
        $status = $failed ? 'FAILED' : 'RETRY';
        $availableAt = self::timestamp(max(1, $delaySeconds));
        $statement = $this->pdo->prepare(
            'UPDATE push_notification_outbox SET status=:status,available_at=:available_at,'
            . 'locked_at=NULL,locked_by=NULL,last_error=:last_error,'
            . 'failed_at=' . ($failed ? 'CURRENT_TIMESTAMP' : 'NULL') . ',updated_at=CURRENT_TIMESTAMP '
            . "WHERE id=:id AND status='PROCESSING'"
        );
        $statement->execute([
            'id' => $id,
            'status' => $status,
            'available_at' => $availableAt,
            'last_error' => self::error($error),
        ]);

        return $status;
    }

    private function recoverAbandonedJobs(string $staleBefore): void
    {
        $this->pdo->prepare(
            "UPDATE push_notification_outbox SET status=CASE WHEN attempts>=max_attempts THEN 'FAILED' ELSE 'RETRY' END,"
            . 'available_at=CURRENT_TIMESTAMP,locked_at=NULL,locked_by=NULL,last_error=:lease_error,'
            . 'failed_at=CASE WHEN attempts>=max_attempts THEN CURRENT_TIMESTAMP ELSE failed_at END,'
            . 'updated_at=CURRENT_TIMESTAMP '
            . "WHERE status='PROCESSING' AND locked_at<:stale_before"
        )->execute([
            'lease_error' => 'Worker lease expired before delivery completed.',
            'stale_before' => $staleBefore,
        ]);
    }

    private function isMySql(): bool
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    }

    private static function timestamp(int $offsetSeconds): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify(($offsetSeconds >= 0 ? '+' : '') . $offsetSeconds . ' seconds')
            ->format('Y-m-d H:i:s.u');
    }

    private static function error(string $error): string
    {
        $normalized = trim(preg_replace('/\s+/', ' ', $error) ?? $error);
        $normalized = $normalized === '' ? 'Unknown push delivery failure.' : $normalized;
        if (preg_match('/^.{0,1000}/us', $normalized, $match) === 1) {
            return $match[0];
        }

        return substr($normalized, 0, 1000);
    }
}
