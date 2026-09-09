<?php

declare(strict_types=1);

namespace ChambreRose;

final class AdminRoutes implements RouteHandler
{
    public function __construct(
        private readonly AdminUserService $service,
        private readonly ApiRequestGuard $guard,
        private readonly UserNotificationService $notifications
    ) {
    }

    public function handle(Request $request): ?Response
    {
        $method = $request->method;
        $path = $request->path;

        if ($method === 'GET' && $path === '/api/admin/users') {
            $this->guard->requireAdmin($request);

            return ApiResponder::json($this->service->list(
                self::queryString($request, 'email'),
                self::queryString($request, 'name'),
                self::queryString($request, 'sort') ?? 'newest',
                self::queryString($request, 'approvalStatus'),
                self::queryString($request, 'role'),
                self::queryInt($request, 'page', 1),
                self::queryInt($request, 'pageSize', 25)
            ));
        }
        if ($method === 'PATCH' && preg_match('#^/api/admin/users/(\d+)/vip$#', $path, $match)) {
            $this->guard->requireAdmin($request);
            $this->guard->requireJson($request);
            $userId = (int) $match[1];
            $active = Validator::vipActive($request->json());
            $updated = $this->service->updateVip($userId, $active);
            $this->notifications->notify(
                $userId,
                UserNotificationService::ACCOUNT,
                $active ? 'VIP_ACTIVATED' : 'VIP_DEACTIVATED',
                '/espace-prive',
                null
            );

            return ApiResponder::json($updated);
        }
        if ($method === 'PATCH' && preg_match('#^/api/admin/users/(\d+)/approval$#', $path, $match)) {
            return $this->updateApproval($request, (int) $match[1]);
        }
        if ($method === 'PATCH' && preg_match('#^/api/admin/users/(\d+)/role$#', $path, $match)) {
            $this->guard->requireAdmin($request);
            $this->guard->requireJson($request);
            $userId = (int) $match[1];
            $updated = $this->service->updateRole($userId, (string) ($request->json()['role'] ?? ''));
            $this->notifications->notify(
                $userId,
                UserNotificationService::ACCOUNT,
                'ROLE_CHANGED',
                '/espace-prive',
                null,
                ['role' => (string) $updated['role']]
            );

            return ApiResponder::json($updated);
        }
        if ($method === 'DELETE' && preg_match('#^/api/admin/users/(\d+)$#', $path, $match)) {
            $admin = $this->guard->currentUser($request);
            if (($admin['role'] ?? '') !== 'ADMIN') {
                throw new ApiException(403, 'Administrator access is required.');
            }
            $this->service->deleteUser((int) $match[1], (int) $admin['id']);

            return ApiResponder::empty();
        }

        return null;
    }

    private function updateApproval(Request $request, int $userId): Response
    {
        $this->guard->requireAdmin($request);
        $this->guard->requireJson($request);
        $body = $request->json();
        $status = strtoupper(trim((string) ($body['status'] ?? '')));
        $reason = isset($body['reason']) ? trim((string) $body['reason']) : null;
        if ($reason !== null && strlen($reason) > 500) {
            throw new ApiException(400, 'Approval reason cannot exceed 500 characters.');
        }

        $updated = $this->service->updateApproval($userId, $status, $reason);
        $approved = $status === 'APPROVED';
        $this->notifications->notify(
            $userId,
            UserNotificationService::ACCOUNT,
            $approved ? 'ACCOUNT_APPROVED' : 'ACCOUNT_REJECTED',
            '/espace-prive',
            null
        );

        return ApiResponder::json($updated);
    }

    private static function queryString(Request $request, string $name): ?string
    {
        $value = $request->query[$name] ?? null;

        return is_scalar($value) ? (string) $value : null;
    }

    private static function queryInt(Request $request, string $name, int $fallback): int
    {
        $value = $request->query[$name] ?? null;

        return is_scalar($value) && filter_var($value, FILTER_VALIDATE_INT) !== false
            ? max(1, (int) $value)
            : $fallback;
    }
}
