<?php

declare(strict_types=1);

namespace ChambreRose;

use DateTimeZone;

final class NotificationRoutes implements RouteHandler
{
    public function __construct(
        private readonly NotificationRepository $notifications,
        private readonly UserNotificationService $service,
        private readonly ApiRequestGuard $guard,
        private readonly PushDeviceCookie $pushDeviceCookie,
        private readonly NativePushNotificationService $nativePush
    ) {
    }

    public function handle(Request $request): ?Response
    {
        $method = $request->method;
        $path = $request->path;

        if ($method === 'GET' && $path === '/api/notifications/config') {
            $this->guard->currentUser($request);
            $publicKey = $this->service->publicKey();

            return ApiResponder::json([
                'enabled' => $publicKey !== null,
                'publicKey' => $publicKey,
                'nativeEnabled' => $this->nativePush->isConfigured(),
            ]);
        }
        if ($method === 'GET' && $path === '/api/notifications') {
            $user = $this->guard->currentUser($request);

            return ApiResponder::json($this->notifications->feed(
                (int) $user['id'],
                self::queryInt($request, 'after', 0),
                self::queryInt($request, 'limit', 30)
            ));
        }
        if ($method === 'GET' && $path === '/api/notification-preferences') {
            $user = $this->guard->currentUser($request);

            return ApiResponder::json($this->notifications->preferences((int) $user['id']));
        }
        if ($method === 'PUT' && $path === '/api/notification-preferences') {
            return $this->savePreferences($request);
        }
        if ($method === 'POST' && $path === '/api/push-subscriptions') {
            return $this->subscribe($request);
        }
        if ($method === 'DELETE' && $path === '/api/push-subscriptions') {
            $user = $this->guard->currentUser($request);
            $this->guard->requireJson($request);
            $endpoint = trim((string) ($request->json()['endpoint'] ?? ''));
            if ($endpoint !== '') {
                $this->notifications->deleteSubscription((int) $user['id'], $endpoint);
            }

            return ApiResponder::empty()->withHeaders(['Set-Cookie' => $this->pushDeviceCookie->clear()]);
        }
        if ($method === 'POST' && $path === '/api/native-push-devices') {
            return $this->saveNativeDevice($request);
        }
        if ($method === 'DELETE' && $path === '/api/native-push-devices') {
            $user = $this->guard->currentUser($request);
            $this->guard->requireJson($request);
            $token = trim((string) ($request->json()['token'] ?? ''));
            if ($token !== '') $this->notifications->deleteNativeDevice((int) $user['id'], $token);

            return ApiResponder::empty();
        }
        if ($method === 'PATCH' && $path === '/api/notifications/read') {
            $user = $this->guard->currentUser($request);
            $this->notifications->markRead((int) $user['id']);

            return ApiResponder::empty();
        }
        if ($method === 'PATCH' && preg_match('#^/api/notifications/(\d+)/read$#', $path, $match)) {
            $user = $this->guard->currentUser($request);
            $this->notifications->markRead((int) $user['id'], (int) $match[1]);

            return ApiResponder::empty();
        }

        return null;
    }

    private function savePreferences(Request $request): Response
    {
        $user = $this->guard->currentUser($request);
        $this->guard->requireJson($request);
        $input = $request->json();
        $current = $this->notifications->preferences((int) $user['id']);
        $preferences = [
            'directMessages' => self::booleanPreference($input, $current, 'directMessages'),
            // Account and security emails are mandatory and cannot be opted out of.
            'accountUpdates' => true,
            'marketplaceUpdates' => self::booleanPreference($input, $current, 'marketplaceUpdates'),
            'securityUpdates' => true,
            'browserNotifications' => self::booleanPreference($input, $current, 'browserNotifications'),
            'inAppNotifications' => self::booleanPreference($input, $current, 'inAppNotifications'),
            'onlyDirectMessages' => self::booleanPreference($input, $current, 'onlyDirectMessages'),
            'dailyDigest' => self::booleanPreference($input, $current, 'dailyDigest'),
            'dailyDigestTime' => self::timePreference($input, $current, 'dailyDigestTime'),
            'quietHoursEnabled' => self::booleanPreference($input, $current, 'quietHoursEnabled'),
            'quietHoursStart' => self::timePreference($input, $current, 'quietHoursStart'),
            'quietHoursEnd' => self::timePreference($input, $current, 'quietHoursEnd'),
            'timezone' => self::timezonePreference($input, $current),
        ];

        return ApiResponder::json($this->notifications->savePreferences((int) $user['id'], $preferences));
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, bool|string> $current
     */
    private static function booleanPreference(array $input, array $current, string $field): bool
    {
        if (!array_key_exists($field, $input)) {
            return $current[$field] === true;
        }
        if (!is_bool($input[$field])) {
            throw new ApiException(400, 'Invalid notification preference.', [$field => 'must be boolean']);
        }

        return $input[$field];
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, bool|string> $current
     */
    private static function timePreference(array $input, array $current, string $field): string
    {
        $value = array_key_exists($field, $input) ? $input[$field] : $current[$field];
        if (!is_string($value) || preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value) !== 1) {
            throw new ApiException(400, 'Invalid notification preference.', [$field => 'must use HH:MM']);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, bool|string> $current
     */
    private static function timezonePreference(array $input, array $current): string
    {
        $value = array_key_exists('timezone', $input) ? $input['timezone'] : $current['timezone'];
        $identifiers = DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC);
        if (!is_string($value) || strlen($value) > 64 || !in_array($value, $identifiers, true)) {
            throw new ApiException(400, 'Invalid notification preference.', [
                'timezone' => 'must be a valid IANA time zone',
            ]);
        }

        return $value;
    }

    private function subscribe(Request $request): Response
    {
        $user = $this->guard->currentUser($request);
        $this->guard->requireJson($request);
        $input = $request->json();
        $keys = is_array($input['keys'] ?? null) ? $input['keys'] : [];
        $endpoint = trim((string) ($input['endpoint'] ?? ''));
        $publicKey = trim((string) ($keys['p256dh'] ?? ''));
        $authToken = trim((string) ($keys['auth'] ?? ''));
        $contentEncoding = trim((string) ($input['contentEncoding'] ?? 'aes128gcm'));
        if (strlen($endpoint) > 2048 || !str_starts_with($endpoint, 'https://') || $publicKey === '' || $authToken === '') {
            throw new ApiException(400, 'Invalid push subscription.');
        }
        if (!in_array($contentEncoding, ['aes128gcm', 'aesgcm'], true)) {
            $contentEncoding = 'aes128gcm';
        }
        $userAgent = $request->header('user-agent');
        $this->notifications->saveSubscription(
            (int) $user['id'],
            $endpoint,
            $publicKey,
            $authToken,
            $contentEncoding,
            $userAgent === null ? null : substr($userAgent, 0, 255)
        );

        return ApiResponder::json(['subscribed' => true], 201)
            ->withHeaders(['Set-Cookie' => $this->pushDeviceCookie->issue($endpoint)]);
    }

    private function saveNativeDevice(Request $request): Response
    {
        $user = $this->guard->currentUser($request);
        $this->guard->requireJson($request);
        $input = $request->json();
        $token = trim((string) ($input['token'] ?? ''));
        $platform = strtolower(trim((string) ($input['platform'] ?? '')));
        $locale = strtolower(trim((string) ($input['locale'] ?? 'en')));
        $appVersion = trim((string) ($input['appVersion'] ?? ''));
        if (strlen($token) < 20 || strlen($token) > 4096 || !in_array($platform, ['android', 'ios'], true)) {
            throw new ApiException(400, 'Invalid native push device.');
        }
        if (preg_match('/^[a-z]{2}(?:[-_][a-z]{2})?$/i', $locale) !== 1) $locale = 'en';
        $this->notifications->saveNativeDevice(
            (int) $user['id'],
            $token,
            $platform,
            substr($locale, 0, 16),
            $appVersion === '' ? null : substr($appVersion, 0, 32)
        );

        return ApiResponder::json(['registered' => true], 201);
    }

    private static function queryInt(Request $request, string $name, int $fallback): int
    {
        $value = $request->query[$name] ?? null;

        return is_scalar($value) && filter_var($value, FILTER_VALIDATE_INT) !== false
            ? max(0, (int) $value)
            : $fallback;
    }
}
