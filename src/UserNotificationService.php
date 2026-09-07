<?php

declare(strict_types=1);

namespace ChambreRose;

final class UserNotificationService
{
    public const DIRECT_MESSAGE = 'DIRECT_MESSAGE';
    public const ACCOUNT = 'ACCOUNT';
    public const MARKETPLACE = 'MARKETPLACE';
    public const SECURITY = 'SECURITY';

    public function __construct(
        private readonly NotificationRepository $notifications,
        private readonly NotificationOutboxStore $outbox,
        private readonly PushNotificationSender $push,
        private readonly RealtimeEventRepository $realtimeEvents
    ) {
    }

    /** @return array<string, mixed> */
    public function notify(
        int $userId,
        string $category,
        string $eventType,
        string $title,
        string $body,
        string $targetUrl,
        ?string $dedupeKey = null
    ): array {
        $preferences = $this->notifications->preferences($userId);
        $preference = match ($category) {
            self::DIRECT_MESSAGE => 'directMessages',
            self::MARKETPLACE => 'marketplaceUpdates',
            self::SECURITY => 'securityUpdates',
            default => 'accountUpdates',
        };
        $pushEnabled = $preferences[$preference] ?? true;

        return $this->outbox->transaction(function () use (
            $userId,
            $category,
            $eventType,
            $title,
            $body,
            $targetUrl,
            $dedupeKey,
            $pushEnabled
        ): array {
            $notification = $this->notifications->create(
                $userId,
                $category,
                $eventType,
                $title,
                $body,
                $targetUrl,
                $dedupeKey
            );
            if ($pushEnabled) {
                $this->outbox->enqueue(
                    (int) $notification['id'],
                    $userId,
                    Config::int('PUSH_OUTBOX_MAX_ATTEMPTS', 8)
                );
            }
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

            return $notification;
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
