<?php

declare(strict_types=1);

namespace ChambreRose;

final class NotificationRoutes implements RouteHandler
{
    public function __construct(
        private readonly NotificationRepository $notifications,
        private readonly UserNotificationService $service,
        private readonly ApiRequestGuard $guard
    ) {
    }

    public function handle(Request $request): ?Response
    {
        $method = $request->method;
        $path = $request->path;

        if ($method === 'GET' && $path === '/api/notifications/config') {
            $this->guard->currentUser($request);
            $publicKey = $this->service->publicKey();

            return ApiResponder::json(['enabled' => $publicKey !== null, 'publicKey' => $publicKey]);
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
        $preferences = [];
        foreach (['directMessages', 'accountUpdates', 'marketplaceUpdates', 'securityUpdates'] as $field) {
            if (!array_key_exists($field, $input) || !is_bool($input[$field])) {
                throw new ApiException(400, 'All notification preferences must be boolean.', [
                    $field => 'must be boolean',
                ]);
            }
            $preferences[$field] = $input[$field];
        }

        /** @var array{directMessages: bool, accountUpdates: bool, marketplaceUpdates: bool, securityUpdates: bool} $preferences */
        return ApiResponder::json($this->notifications->savePreferences((int) $user['id'], $preferences));
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

        return ApiResponder::json(['subscribed' => true], 201);
    }

    private static function queryInt(Request $request, string $name, int $fallback): int
    {
        $value = $request->query[$name] ?? null;

        return is_scalar($value) && filter_var($value, FILTER_VALIDATE_INT) !== false
            ? max(0, (int) $value)
            : $fallback;
    }
}
