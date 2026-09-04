<?php

declare(strict_types=1);

namespace ChambreRose;

final class AdminRoutes implements RouteHandler
{
    public function __construct(
        private readonly AdminUserService $service,
        private readonly ApiRequestGuard $guard
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

            return ApiResponder::json(
                $this->service->updateVip((int) $match[1], Validator::vipActive($request->json()))
            );
        }
        if ($method === 'PATCH' && preg_match('#^/api/admin/users/(\d+)/approval$#', $path, $match)) {
            return $this->updateApproval($request, (int) $match[1]);
        }
        if ($method === 'PATCH' && preg_match('#^/api/admin/users/(\d+)/role$#', $path, $match)) {
            $this->guard->requireAdmin($request);
            $this->guard->requireJson($request);

            return ApiResponder::json(
                $this->service->updateRole((int) $match[1], (string) ($request->json()['role'] ?? ''))
            );
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

        return ApiResponder::json($this->service->updateApproval($userId, $status, $reason));
    }

    private static function queryString(Request $request, string $name): ?string
    {
        $value = $request->query[$name] ?? null;

        return is_scalar($value) ? (string) $value : null;
    }
}
