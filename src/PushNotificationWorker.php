<?php

declare(strict_types=1);

namespace ChambreRose;

use Throwable;

final class PushNotificationWorker
{
    public function __construct(
        private readonly NotificationOutboxStore $outbox,
        private readonly PushNotificationSender $push,
        private readonly int $retryBaseSeconds = 15,
        private readonly int $retryMaxSeconds = 3600
    ) {
    }

    /** @return array{claimed: int, delivered: int, skipped: int, retried: int, failed: int} */
    public function processBatch(int $limit, string $workerId, int $lockTimeoutSeconds): array
    {
        $summary = ['claimed' => 0, 'delivered' => 0, 'skipped' => 0, 'retried' => 0, 'failed' => 0];
        $jobs = $this->outbox->claim($limit, $workerId, $lockTimeoutSeconds);
        $summary['claimed'] = count($jobs);

        foreach ($jobs as $job) {
            $id = (int) $job['id'];
            try {
                $result = $this->push->send((int) $job['user_id'], self::notification($job));
                if ($result === PushNotificationSender::SKIPPED) {
                    $this->outbox->markSkipped($id, 'Delivery was disabled or no active push subscription was available.');
                    $summary['skipped']++;
                    continue;
                }
                $this->outbox->markDelivered($id);
                $summary['delivered']++;
            } catch (Throwable $exception) {
                $attempts = (int) $job['attempts'];
                $status = $this->outbox->recordFailure(
                    $id,
                    $attempts,
                    (int) $job['max_attempts'],
                    $exception->getMessage(),
                    self::retryDelaySeconds($attempts, $this->retryBaseSeconds, $this->retryMaxSeconds)
                );
                $summary[$status === 'FAILED' ? 'failed' : 'retried']++;
                error_log(sprintf(
                    '[Chambre Rose Push] outbox=%d attempt=%d status=%s error=%s',
                    $id,
                    $attempts,
                    $status,
                    $exception->getMessage()
                ));
            }
        }

        return $summary;
    }

    public static function retryDelaySeconds(int $attempt, int $baseSeconds, int $maximumSeconds): int
    {
        $baseSeconds = max(1, $baseSeconds);
        $maximumSeconds = max($baseSeconds, $maximumSeconds);
        $exponent = min(16, max(0, $attempt - 1));

        return min($maximumSeconds, $baseSeconds * (2 ** $exponent));
    }

    /**
     * @param array<string, mixed> $job
     * @return array<string, mixed>
     */
    private static function notification(array $job): array
    {
        return [
            'id' => (int) $job['notification_id'],
            'category' => (string) $job['category'],
            'eventType' => (string) $job['event_type'],
            'title' => (string) $job['title'],
            'body' => (string) $job['body'],
            'params' => self::messageParameters($job['message_params'] ?? null),
            'recipientLocale' => (string) ($job['recipient_locale'] ?? 'fr'),
            'targetUrl' => (string) $job['target_url'],
            'createdAt' => (string) $job['created_at'],
        ];
    }

    /** @return array<string, scalar|null> */
    private static function messageParameters(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;
        if (!is_array($decoded)) {
            return [];
        }

        $parameters = [];
        foreach ($decoded as $name => $parameter) {
            if (is_string($name) && (is_scalar($parameter) || $parameter === null)) {
                $parameters[$name] = $parameter;
            }
        }

        return $parameters;
    }
}
