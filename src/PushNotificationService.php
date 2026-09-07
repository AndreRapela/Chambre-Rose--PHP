<?php

declare(strict_types=1);

namespace ChambreRose;

use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Throwable;

final class PushNotificationService implements PushNotificationSender
{
    public function __construct(private readonly NotificationRepository $notifications)
    {
    }

    public function publicKey(): ?string
    {
        $key = trim(Config::get('VAPID_PUBLIC_KEY', '') ?? '');

        return $key === '' ? null : $key;
    }

    /** @param array<string, mixed> $notification */
    public function send(int $userId, array $notification): string
    {
        $subscriptions = $this->notifications->subscriptions($userId);
        if ($subscriptions === []) {
            return self::SKIPPED;
        }

        $publicKey = $this->publicKey();
        $privateKey = trim(Config::get('VAPID_PRIVATE_KEY', '') ?? '');
        $subject = trim(Config::get('VAPID_SUBJECT', '') ?? '');
        if ($publicKey === null || $privateKey === '' || $subject === '' || !class_exists(WebPush::class)) {
            throw new RuntimeException('Web Push is not configured on this server.');
        }

        try {
            $logger = new class extends AbstractLogger {
                /** @param array<string, mixed> $context */
                public function log($level, string|\Stringable $message, array $context = []): void
                {
                    error_log('[Chambre Rose Push] ' . strtoupper((string) $level) . ': ' . (string) $message);
                }
            };
            $webPush = new WebPush([
                'VAPID' => [
                    'subject' => $subject,
                    'publicKey' => $publicKey,
                    'privateKey' => $privateKey,
                ],
            ], [
                'TTL' => 86400,
                'urgency' => ($notification['category'] ?? '') === UserNotificationService::DIRECT_MESSAGE ? 'high' : 'normal',
                'batchSize' => 20,
                'requestConcurrency' => 5,
            ], 4, ['connect_timeout' => 2], $logger);
            $notificationTopic = substr(hash(
                'sha256',
                (string) ($notification['eventType'] ?? 'account')
                    . '|' . (string) ($notification['targetUrl'] ?? '')
            ), 0, 32);
            $payload = json_encode([
                'notification' => [
                    'title' => (string) ($notification['title'] ?? 'Chambre Rose'),
                    'body' => (string) ($notification['body'] ?? ''),
                    'icon' => '/assets/pwa-icon-192.png',
                    'badge' => '/assets/pwa-icon-192.png',
                    'tag' => 'chambre-rose-' . substr($notificationTopic, 0, 24),
                    'renotify' => true,
                    'data' => [
                        'notificationId' => (int) ($notification['id'] ?? 0),
                        'onActionClick' => [
                            'default' => [
                                'operation' => 'navigateLastFocusedOrOpen',
                                'url' => (string) ($notification['targetUrl'] ?? '/espace-prive/notificacoes'),
                            ],
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            $subscriptionIds = [];
            $deliveryErrors = [];
            foreach ($subscriptions as $stored) {
                try {
                    $subscription = Subscription::create([
                        'endpoint' => (string) $stored['endpoint'],
                        'publicKey' => (string) $stored['public_key'],
                        'authToken' => (string) $stored['auth_token'],
                        'contentEncoding' => (string) $stored['content_encoding'],
                    ]);
                    $subscriptionIds[hash('sha256', (string) $stored['endpoint'])] = (int) $stored['id'];
                    $webPush->queueNotification($subscription, $payload, ['topic' => $notificationTopic]);
                } catch (Throwable $exception) {
                    $this->notifications->recordSubscriptionResult((int) $stored['id'], false);
                    $deliveryErrors[] = $exception->getMessage();
                }
            }
            $delivered = 0;
            foreach ($webPush->flush() as $report) {
                $subscriptionId = $subscriptionIds[hash('sha256', $report->getEndpoint())] ?? null;
                if ($subscriptionId === null) {
                    continue;
                }
                $success = $report->isSuccess();
                $subscriptionExpired = $report->isSubscriptionExpired();
                $this->notifications->recordSubscriptionResult(
                    $subscriptionId,
                    $success,
                    $subscriptionExpired
                );
                if ($success) {
                    $delivered++;
                } elseif (!$subscriptionExpired) {
                    $reason = method_exists($report, 'getReason') ? trim((string) $report->getReason()) : '';
                    $deliveryErrors[] = $reason === '' ? 'Push provider rejected the delivery.' : $reason;
                }
            }

            if ($deliveryErrors !== []) {
                throw new RuntimeException(implode(' | ', array_unique($deliveryErrors)));
            }

            return $delivered > 0 ? self::DELIVERED : self::SKIPPED;
        } catch (Throwable $exception) {
            error_log('[Chambre Rose Push] Dispatch attempt failed: ' . $exception->getMessage());
            throw $exception;
        }
    }
}
