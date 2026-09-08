<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require dirname(__DIR__) . '/bootstrap.php';

use ChambreRose\Config;
use ChambreRose\Database;
use ChambreRose\DatabaseMigrator;
use ChambreRose\NotificationOutboxRepository;
use ChambreRose\NotificationRepository;
use ChambreRose\NotificationRetentionService;
use ChambreRose\PushNotificationService;
use ChambreRose\PushNotificationWorker;
use ChambreRose\RealtimeEventRepository;

$options = getopt('', ['once', 'watch', 'batch:', 'sleep:', 'lock-timeout:']);
$watch = isset($options['watch']) && !isset($options['once']);
$batchSize = max(1, min(100, (int) ($options['batch'] ?? Config::int('PUSH_OUTBOX_BATCH_SIZE', 20))));
$sleepSeconds = max(1, (int) ($options['sleep'] ?? Config::int('PUSH_OUTBOX_POLL_SECONDS', 2)));
$lockTimeout = max(30, (int) ($options['lock-timeout'] ?? Config::int('PUSH_OUTBOX_LOCK_SECONDS', 120)));
$workerId = substr((gethostname() ?: 'worker') . ':' . getmypid() . ':' . bin2hex(random_bytes(4)), 0, 190);

try {
    $pdo = Database::connection();
    if (Config::bool('APP_AUTO_MIGRATE', true)) {
        (new DatabaseMigrator($pdo))->migrate();
    }
    $notifications = new NotificationRepository($pdo);
    $worker = new PushNotificationWorker(
        new NotificationOutboxRepository($pdo),
        new PushNotificationService($notifications),
        Config::int('PUSH_OUTBOX_RETRY_BASE_SECONDS', 15),
        Config::int('PUSH_OUTBOX_RETRY_MAX_SECONDS', 3600)
    );
    $realtimeEvents = new RealtimeEventRepository($pdo);
    $notificationRetention = new NotificationRetentionService($pdo);
    $readRetentionDays = max(1, Config::int('NOTIFICATION_READ_RETENTION_DAYS', 90));
    $unreadRetentionDays = max(
        $readRetentionDays,
        Config::int('NOTIFICATION_UNREAD_RETENTION_DAYS', 365)
    );
    $notificationPurgeBatch = max(
        1,
        min(5000, Config::int('NOTIFICATION_RETENTION_BATCH_SIZE', 500))
    );
    $notificationPurgeInterval = max(
        300,
        Config::int('NOTIFICATION_RETENTION_INTERVAL_SECONDS', 3600)
    );
    $nextRealtimePurgeAt = 0;
    $nextNotificationPurgeAt = 0;

    do {
        $expiredRealtimeEvents = 0;
        if (time() >= $nextRealtimePurgeAt) {
            $expiredRealtimeEvents = $realtimeEvents->purgeExpired(
                Config::int('REALTIME_EVENT_RETENTION_DAYS', 7)
            );
            $nextRealtimePurgeAt = time() + 3600;
        }
        $expiredNotifications = 0;
        if (time() >= $nextNotificationPurgeAt) {
            $expiredNotifications = $notificationRetention->purgeBatch(
                $readRetentionDays,
                $unreadRetentionDays,
                $notificationPurgeBatch
            );
            $nextNotificationPurgeAt = $expiredNotifications >= $notificationPurgeBatch
                ? time()
                : time() + $notificationPurgeInterval;
        }
        $summary = $worker->processBatch($batchSize, $workerId, $lockTimeout);
        if (
            !$watch
            || $summary['claimed'] > 0
            || $expiredRealtimeEvents > 0
            || $expiredNotifications > 0
        ) {
            fwrite(STDOUT, json_encode(
                [
                    'worker' => $workerId,
                    'timestamp' => gmdate('c'),
                    'expiredRealtimeEvents' => $expiredRealtimeEvents,
                    'expiredNotifications' => $expiredNotifications,
                ] + $summary,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
            ) . PHP_EOL);
        }
        if ($watch && $summary['claimed'] === 0) {
            sleep($sleepSeconds);
        }
    } while ($watch);

    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, '[Push worker] ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
