<?php

declare(strict_types=1);

namespace ChambreRose;

interface PushNotificationSender
{
    public const DELIVERED = 'DELIVERED';
    public const SKIPPED = 'SKIPPED';

    public function publicKey(): ?string;

    /**
     * @param array<string, mixed> $notification
     * @return self::DELIVERED|self::SKIPPED
     */
    public function send(int $userId, array $notification): string;
}
