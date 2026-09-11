<?php

declare(strict_types=1);

namespace ChambreRose;

use RuntimeException;
use Throwable;

final class NativePushNotificationService
{
    /** @var array{token: string, expires: int}|null */
    private ?array $googleAccessToken = null;

    public function __construct(private readonly NotificationRepository $notifications)
    {
    }

    public function isConfigured(): bool
    {
        return $this->androidConfigured() || $this->iosConfigured();
    }

    /** @param array<string, mixed> $notification */
    public function send(int $userId, array $notification): string
    {
        if (!$this->notifications->browserDeliveryAllowed(
            $userId,
            (string) ($notification['category'] ?? ''),
            (string) ($notification['eventType'] ?? '')
        )) {
            return PushNotificationSender::SKIPPED;
        }

        $devices = $this->notifications->nativeDevices($userId);
        if ($devices === []) {
            return PushNotificationSender::SKIPPED;
        }

        $delivered = 0;
        $errors = [];
        foreach ($devices as $device) {
            $id = (int) $device['id'];
            try {
                $result = match ((string) $device['platform']) {
                    'android' => $this->sendAndroid((string) $device['device_token'], $notification, (string) $device['locale']),
                    'ios' => $this->sendIos((string) $device['device_token'], $notification, (string) $device['locale']),
                    default => ['success' => false, 'expired' => true],
                };
                $this->notifications->recordNativeDeviceResult($id, $result['success'], $result['expired']);
                if ($result['success']) $delivered++;
            } catch (Throwable $exception) {
                $this->notifications->recordNativeDeviceResult($id, false);
                $errors[] = $exception->getMessage();
            }
        }

        if ($delivered === 0 && $errors !== []) {
            throw new RuntimeException(implode(' | ', array_unique($errors)));
        }

        return $delivered > 0 ? PushNotificationSender::DELIVERED : PushNotificationSender::SKIPPED;
    }

    /**
     * @param array<string, mixed> $notification
     * @return array{success: bool, expired: bool}
     */
    private function sendAndroid(string $deviceToken, array $notification, string $locale): array
    {
        if (!$this->androidConfigured()) throw new RuntimeException('Android push is not configured.');
        $projectId = trim(Config::get('FCM_PROJECT_ID', '') ?? '');
        $message = $this->localizedMessage($notification, $locale);
        $payload = [
            'message' => [
                'token' => $deviceToken,
                'notification' => ['title' => $message['title'], 'body' => $message['body']],
                'data' => [
                    'targetUrl' => (string) ($notification['targetUrl'] ?? '/espace-prive/notificacoes'),
                    'notificationId' => (string) ((int) ($notification['id'] ?? 0)),
                    'category' => (string) ($notification['category'] ?? 'ACCOUNT'),
                ],
                'android' => [
                    'priority' => ($notification['category'] ?? '') === UserNotificationService::DIRECT_MESSAGE ? 'HIGH' : 'NORMAL',
                    'notification' => ['sound' => 'default', 'channel_id' => 'chambre_rose_updates'],
                ],
            ],
        ];
        $response = $this->http(
            'https://fcm.googleapis.com/v1/projects/' . rawurlencode($projectId) . '/messages:send',
            ['Authorization: Bearer ' . $this->googleAccessToken(), 'Content-Type: application/json'],
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
        $expired = in_array($response['status'], [404, 410], true)
            || str_contains($response['body'], 'UNREGISTERED');
        if (!($response['status'] >= 200 && $response['status'] < 300) && !$expired) {
            throw new RuntimeException('Firebase push failed with HTTP ' . $response['status'] . '.');
        }

        return ['success' => $response['status'] >= 200 && $response['status'] < 300, 'expired' => $expired];
    }

    /**
     * @param array<string, mixed> $notification
     * @return array{success: bool, expired: bool}
     */
    private function sendIos(string $deviceToken, array $notification, string $locale): array
    {
        if (!$this->iosConfigured()) throw new RuntimeException('iOS push is not configured.');
        $bundleId = trim(Config::get('APNS_BUNDLE_ID', '') ?? '');
        $message = $this->localizedMessage($notification, $locale);
        $payload = [
            'aps' => [
                'alert' => ['title' => $message['title'], 'body' => $message['body']],
                'sound' => 'default',
                'badge' => 1,
            ],
            'targetUrl' => (string) ($notification['targetUrl'] ?? '/espace-prive/notificacoes'),
            'notificationId' => (int) ($notification['id'] ?? 0),
        ];
        $production = Config::bool('APNS_PRODUCTION', true);
        $response = $this->http(
            'https://' . ($production ? 'api.push.apple.com' : 'api.sandbox.push.apple.com')
                . '/3/device/' . rawurlencode($deviceToken),
            [
                'authorization: bearer ' . $this->apnsJwt(),
                'apns-topic: ' . $bundleId,
                'apns-push-type: alert',
                'apns-priority: 10',
                'content-type: application/json',
            ],
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            true
        );
        if ($response['status'] !== 200 && !in_array($response['status'], [404, 410], true)) {
            throw new RuntimeException('Apple push failed with HTTP ' . $response['status'] . '.');
        }

        return [
            'success' => $response['status'] === 200,
            'expired' => in_array($response['status'], [404, 410], true),
        ];
    }

    /**
     * @param array<string, mixed> $notification
     * @return array{title: string, body: string}
     */
    private function localizedMessage(array $notification, string $locale): array
    {
        $parameters = $notification['params'] ?? $notification['message_params'] ?? [];
        if (is_string($parameters)) $parameters = json_decode($parameters, true);

        return NotificationMessageCatalog::render(
            (string) ($notification['eventType'] ?? $notification['event_type'] ?? ''),
            is_array($parameters) ? $parameters : [],
            str_starts_with(strtolower($locale), 'fr') ? 'fr' : 'en',
            (string) ($notification['title'] ?? 'Chambre Rose'),
            (string) ($notification['body'] ?? '')
        );
    }

    private function googleAccessToken(): string
    {
        if ($this->googleAccessToken !== null && $this->googleAccessToken['expires'] > time() + 60) {
            return $this->googleAccessToken['token'];
        }
        $credentials = $this->googleCredentials();
        $now = time();
        $header = self::base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $claims = self::base64Url(json_encode([
            'iss' => $credentials['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ], JSON_THROW_ON_ERROR));
        $signingInput = $header . '.' . $claims;
        if (!openssl_sign($signingInput, $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Unable to sign the Firebase access token.');
        }
        $assertion = $signingInput . '.' . self::base64Url($signature);
        $response = $this->http(
            'https://oauth2.googleapis.com/token',
            ['Content-Type: application/x-www-form-urlencoded'],
            http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            ])
        );
        $decoded = json_decode($response['body'], true);
        if ($response['status'] !== 200 || !is_array($decoded) || !is_string($decoded['access_token'] ?? null)) {
            throw new RuntimeException('Firebase rejected the service account credentials.');
        }
        $this->googleAccessToken = [
            'token' => $decoded['access_token'],
            'expires' => $now + max(300, (int) ($decoded['expires_in'] ?? 3600)),
        ];

        return $this->googleAccessToken['token'];
    }

    /** @return array{client_email: string, private_key: string} */
    private function googleCredentials(): array
    {
        $raw = trim(Config::get('FCM_SERVICE_ACCOUNT_JSON', '') ?? '');
        $path = trim(Config::get('FCM_SERVICE_ACCOUNT_FILE', '') ?? '');
        if ($raw === '' && $path !== '' && is_file($path)) $raw = (string) file_get_contents($path);
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !is_string($decoded['client_email'] ?? null) || !is_string($decoded['private_key'] ?? null)) {
            throw new RuntimeException('Firebase service account credentials are invalid.');
        }

        return ['client_email' => $decoded['client_email'], 'private_key' => $decoded['private_key']];
    }

    private function apnsJwt(): string
    {
        $keyId = trim(Config::get('APNS_KEY_ID', '') ?? '');
        $teamId = trim(Config::get('APNS_TEAM_ID', '') ?? '');
        $privateKey = trim(Config::get('APNS_PRIVATE_KEY', '') ?? '');
        $path = trim(Config::get('APNS_PRIVATE_KEY_FILE', '') ?? '');
        if ($privateKey === '' && $path !== '' && is_file($path)) $privateKey = (string) file_get_contents($path);
        $header = self::base64Url(json_encode(['alg' => 'ES256', 'kid' => $keyId], JSON_THROW_ON_ERROR));
        $claims = self::base64Url(json_encode(['iss' => $teamId, 'iat' => time()], JSON_THROW_ON_ERROR));
        $signingInput = $header . '.' . $claims;
        if (!openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Unable to sign the Apple push token.');
        }

        return $signingInput . '.' . self::base64Url(self::derToJose($signature, 64));
    }

    private function androidConfigured(): bool
    {
        return trim(Config::get('FCM_PROJECT_ID', '') ?? '') !== ''
            && (trim(Config::get('FCM_SERVICE_ACCOUNT_JSON', '') ?? '') !== ''
                || trim(Config::get('FCM_SERVICE_ACCOUNT_FILE', '') ?? '') !== '');
    }

    private function iosConfigured(): bool
    {
        return trim(Config::get('APNS_KEY_ID', '') ?? '') !== ''
            && trim(Config::get('APNS_TEAM_ID', '') ?? '') !== ''
            && trim(Config::get('APNS_BUNDLE_ID', '') ?? '') !== ''
            && (trim(Config::get('APNS_PRIVATE_KEY', '') ?? '') !== ''
                || trim(Config::get('APNS_PRIVATE_KEY_FILE', '') ?? '') !== '');
    }

    /**
     * @param list<string> $headers
     * @return array{status: int, body: string}
     */
    private function http(string $url, array $headers, string $body, bool $http2 = false): array
    {
        if (!function_exists('curl_init')) throw new RuntimeException('The cURL extension is required for native push.');
        $handle = curl_init($url);
        if ($handle === false) throw new RuntimeException('Unable to initialize the push request.');
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
        ]);
        if ($http2 && defined('CURL_HTTP_VERSION_2_0')) curl_setopt($handle, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2_0);
        $responseBody = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        if ($responseBody === false) throw new RuntimeException('Push provider connection failed: ' . $error);

        return ['status' => $status, 'body' => (string) $responseBody];
    }

    private static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function derToJose(string $der, int $length): string
    {
        $offset = 1;
        self::readDerLength($der, $offset);
        if (ord($der[$offset++]) !== 0x02) throw new RuntimeException('Invalid Apple signature.');
        $rLength = self::readDerLength($der, $offset);
        $r = substr($der, $offset, $rLength);
        $offset += $rLength;
        if (ord($der[$offset++]) !== 0x02) throw new RuntimeException('Invalid Apple signature.');
        $sLength = self::readDerLength($der, $offset);
        $s = substr($der, $offset, $sLength);
        $partLength = intdiv($length, 2);
        $r = str_pad(ltrim($r, "\0"), $partLength, "\0", STR_PAD_LEFT);
        $s = str_pad(ltrim($s, "\0"), $partLength, "\0", STR_PAD_LEFT);

        return substr($r, -$partLength) . substr($s, -$partLength);
    }

    private static function readDerLength(string $der, int &$offset): int
    {
        $length = ord($der[$offset++]);
        if (($length & 0x80) === 0) return $length;
        $bytes = $length & 0x7f;
        $length = 0;
        for ($index = 0; $index < $bytes; $index++) $length = ($length << 8) | ord($der[$offset++]);

        return $length;
    }
}
