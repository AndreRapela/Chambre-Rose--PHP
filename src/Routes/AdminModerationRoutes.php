<?php

declare(strict_types=1);

namespace ChambreRose;

final class AdminModerationRoutes implements RouteHandler
{
    public function __construct(
        private readonly AdminModerationService $service,
        private readonly ApiRequestGuard $guard
    ) {
    }

    public function handle(Request $request): ?Response
    {
        if ($request->method === 'GET' && $request->path === '/api/admin/reports') {
            $this->guard->requireAdmin($request);

            return ApiResponder::json($this->service->list(
                is_scalar($request->query['status'] ?? null) ? (string) $request->query['status'] : null,
                self::queryInt($request, 'page', 1),
                self::queryInt($request, 'pageSize', 25)
            ));
        }
        if ($request->method === 'PATCH' && preg_match('#^/api/admin/reports/(\d+)$#', $request->path, $match)) {
            $this->guard->requireAdmin($request);
            $this->guard->requireJson($request);

            return ApiResponder::json($this->service->updateStatus(
                (int) $match[1],
                (string) ($request->json()['status'] ?? '')
            ));
        }

        return null;
    }

    private static function queryInt(Request $request, string $name, int $fallback): int
    {
        $value = $request->query[$name] ?? null;

        return is_scalar($value) && filter_var($value, FILTER_VALIDATE_INT) !== false
            ? max(1, (int) $value)
            : $fallback;
    }
}
