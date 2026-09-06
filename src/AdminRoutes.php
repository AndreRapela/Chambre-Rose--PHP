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
                self::queryString($request, 'role')
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
                $active ? 'VIP access activated' : 'VIP access changed',
                $active
                    ? 'Your VIP access is now active. Private conversations are available.'
                    : 'Your VIP access is no longer active.',
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
                'Account role updated',
                'Your account role is now ' . (string) $updated['role'] . '.',
                '/espace-prive',
                null
            );

            return ApiResponder::json($updated);
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
            $approved ? 'Account approved' : 'Account review completed',
            $approved
                ? 'Your professional account was approved and is now visible.'
                : 'Your professional account was not approved. Open your account for details.',
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
}
