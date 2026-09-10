<?php

declare(strict_types=1);

namespace ChambreRose;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class UserNotificationService
{
    public const DIRECT_MESSAGE = 'DIRECT_MESSAGE';
    public const ACCOUNT = 'ACCOUNT';
    public const MARKETPLACE = 'MARKETPLACE';
    public const SECURITY = 'SECURITY';
    public const DAILY_DIGEST = 'DAILY_DIGEST';

    public function __construct(
        private readonly NotificationRepository $notifications,
        private readonly NotificationOutboxStore $outbox,
        private readonly PushNotificationSender $push,
        private readonly RealtimeEventRepository $realtimeEvents
    ) {
    }

    /**
     * @param array<string, scalar|null> $messageParameters
     * @return array<string, mixed>
     */
    public function notify(
        int $userId,
        string $category,
        string $eventType,
        string $targetUrl,
        ?string $dedupeKey = null,
        array $messageParameters = []
    ): array {
        if (!NotificationMessageCatalog::supports($eventType)) {
            throw new InvalidArgumentException('Unsupported notification event type: ' . $eventType);
        }
        $preferences = $this->notifications->preferences($userId);
        $categoryPreference = match ($category) {
            self::DIRECT_MESSAGE => 'directMessages',
            self::MARKETPLACE => 'marketplaceUpdates',
            self::SECURITY => 'securityUpdates',
            default => 'accountUpdates',
        };
        $requiredCategory = in_array($category, [self::ACCOUNT, self::SECURITY], true);
        $categoryEnabled = $requiredCategory || $preferences[$categoryPreference] === true;
        $onlyDirectMessages = $preferences['onlyDirectMessages'] === true;
        $allowed = $categoryEnabled && (!$onlyDirectMessages || in_array($category, [self::DIRECT_MESSAGE, self::ACCOUNT, self::SECURITY], true));
        $inAppEnabled = $allowed && $preferences['inAppNotifications'] === true;
        $browserEnabled = $allowed && $preferences['browserNotifications'] === true;
        if (!$inAppEnabled && !$browserEnabled) {
            return ['suppressed' => true];
        }
        $dailyDigest = $browserEnabled
            && $preferences['dailyDigest'] === true
            && in_array($category, [self::ACCOUNT, self::MARKETPLACE], true);

        return $this->outbox->transaction(function () use (
            $userId,
            $category,
            $eventType,
            $targetUrl,
            $dedupeKey,
            $messageParameters,
            $preferences,
            $inAppEnabled,
            $browserEnabled,
            $dailyDigest
        ): array {
            $notification = null;
            if ($inAppEnabled || !$dailyDigest) {
                $notification = $this->notifications->create(
                    $userId,
                    $category,
                    $eventType,
                    $targetUrl,
                    $dedupeKey,
                    $inAppEnabled,
                    $messageParameters
                );
            }
            if ($browserEnabled && $dailyDigest) {
                $digestAt = NotificationDeliverySchedule::nextDailyDigest(
                    (string) $preferences['dailyDigestTime'],
                    (string) $preferences['timezone']
                );
                $quietHoursEnd = NotificationDeliverySchedule::afterQuietHours(
                    $preferences['quietHoursEnabled'] === true,
                    (string) $preferences['quietHoursStart'],
                    (string) $preferences['quietHoursEnd'],
                    (string) $preferences['timezone'],
                    new DateTimeImmutable($digestAt, new DateTimeZone('UTC'))
                );
                $digestAt = $quietHoursEnd ?? $digestAt;
                $digestNotification = $this->notifications->create(
                    $userId,
                    self::ACCOUNT,
                    self::DAILY_DIGEST,
                    '/espace-prive/notificacoes',
                    'daily-digest:' . substr(hash('sha256', $digestAt), 0, 32),
                    false,
                    []
                );
                $this->outbox->enqueue(
                    (int) $digestNotification['id'],
                    $userId,
                    Config::int('PUSH_OUTBOX_MAX_ATTEMPTS', 8),
                    $digestAt
                );
                $notification ??= $digestNotification;
            } elseif ($browserEnabled && $notification !== null) {
                $this->outbox->enqueue(
                    (int) $notification['id'],
                    $userId,
                    Config::int('PUSH_OUTBOX_MAX_ATTEMPTS', 8),
                    NotificationDeliverySchedule::afterQuietHours(
                        $preferences['quietHoursEnabled'] === true,
                        (string) $preferences['quietHoursStart'],
                        (string) $preferences['quietHoursEnd'],
                        (string) $preferences['timezone']
                    )
                );
            }
            if ($inAppEnabled && $notification !== null) {
                $this->realtimeEvents->publish(
                    $userId,
                    RealtimeEventType::NOTIFICATION_CREATED,
                    (int) $notification['id'],
                    [
                        'category' => $category,
                        'eventType' => $eventType,
                        'targetUrl' => $targetUrl,
                    ]
                );
            }

            return $notification ?? ['suppressed' => true];
        });
    }

    public function publicKey(): ?string
    {
        return $this->push->publicKey();
    }

    public function markConversationRead(int $userId, int $conversationId): void
    {
        $this->notifications->markReadForTarget(
            $userId,
            self::DIRECT_MESSAGE,
            '/mensagens/' . $conversationId
        );
    }

    public function revokeDevice(int $userId, string $endpointHash): void
    {
        $this->notifications->deleteSubscriptionByHash($userId, $endpointHash);
    }
}
