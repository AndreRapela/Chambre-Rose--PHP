<?php

declare(strict_types=1);

namespace ChambreRose;

use Throwable;

final class CompositePushNotificationSender implements PushNotificationSender
{
    public function __construct(
        private readonly PushNotificationService $web,
        private readonly NativePushNotificationService $native
    ) {
    }

    public function publicKey(): ?string
    {
        return $this->web->publicKey();
    }

    /** @param array<string, mixed> $notification */
    public function send(int $userId, array $notification): string
    {
        $delivered = false;
        $errors = [];
        foreach ([$this->web, $this->native] as $sender) {
            try {
                $delivered = $sender->send($userId, $notification) === self::DELIVERED || $delivered;
            } catch (Throwable $exception) {
                $errors[] = $exception;
            }
        }
        if (!$delivered && $errors !== []) throw $errors[0];

        return $delivered ? self::DELIVERED : self::SKIPPED;
    }
}
