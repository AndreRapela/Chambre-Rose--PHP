<?php

declare(strict_types=1);

namespace ChambreRose;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class NotificationRetentionService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function purgeBatch(int $readRetentionDays, int $unreadRetentionDays, int $batchSize): int
    {
        $readRetentionDays = max(1, $readRetentionDays);
        $unreadRetentionDays = max($readRetentionDays, $unreadRetentionDays);
        $batchSize = min(5000, max(1, $batchSize));

        $statement = $this->pdo->prepare(<<<'SQL'
            DELETE FROM account_notifications
            WHERE id IN (
              SELECT candidate.id
              FROM (
                SELECT notification.id
                FROM account_notifications notification
                WHERE notification.created_at<:read_before
                  AND (notification.read_at IS NOT NULL OR notification.created_at<:unread_before)
                  AND NOT EXISTS (
                    SELECT 1
                    FROM push_notification_outbox outbox
                    WHERE outbox.notification_id=notification.id
                      AND outbox.status IN ('PENDING','PROCESSING','RETRY')
                  )
                ORDER BY notification.created_at ASC,notification.id ASC
                LIMIT :batch_size
              ) candidate
            )
            SQL);
        $statement->bindValue(':read_before', self::before($readRetentionDays));
        $statement->bindValue(':unread_before', self::before($unreadRetentionDays));
        $statement->bindValue(':batch_size', $batchSize, PDO::PARAM_INT);
        $statement->execute();

        return $statement->rowCount();
    }

    private static function before(int $days): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('-' . $days . ' days')
            ->format('Y-m-d H:i:s.u');
    }
}
