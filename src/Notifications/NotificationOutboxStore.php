<?php

declare(strict_types=1);

namespace ChambreRose;

interface NotificationOutboxStore
{
    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public function transaction(callable $operation): mixed;

    public function enqueue(int $notificationId, int $userId, int $maxAttempts, ?string $availableAt = null): void;

    /** @return list<array<string, mixed>> */
    public function claim(int $limit, string $workerId, int $lockTimeoutSeconds): array;

    public function markDelivered(int $id): void;

    public function markSkipped(int $id, string $reason): void;

    /** @return 'RETRY'|'FAILED' */
    public function recordFailure(int $id, int $attempts, int $maxAttempts, string $error, int $delaySeconds): string;
}
