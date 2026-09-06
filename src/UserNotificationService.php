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
        private readonly PushNotificationService $push
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
        $notification = $this->notifications->create(
            $userId,
            $category,
            $eventType,
            $title,
            $body,
            $targetUrl,
            $dedupeKey
        );
        $preferences = $this->notifications->preferences($userId);
        $preference = match ($category) {
            self::DIRECT_MESSAGE => 'directMessages',
            self::MARKETPLACE => 'marketplaceUpdates',
            self::SECURITY => 'securityUpdates',
            default => 'accountUpdates',
        };
        if ($preferences[$preference] ?? true) {
            register_shutdown_function(function () use ($userId, $notification): void {
                $this->push->send($userId, $notification);
            });
        }

        return $notification;
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

    public function revokeDevices(int $userId): void
    {
        $this->notifications->deleteSubscriptions($userId);
    }
}
