<?php

declare(strict_types=1);

namespace ChambreRose;

final class PromotionRoutes implements RouteHandler
{
    public function __construct(
        private readonly PromotionService $service,
        private readonly ApiRequestGuard $guard
    ) {
    }

    public function handle(Request $request): ?Response
    {
        if ($request->method === 'GET' && $request->path === '/api/promotions') {
            return Response::json(['items' => $this->service->all()], 200, [
                'Cache-Control' => 'public, max-age=30, stale-while-revalidate=300',
            ]);
        }
        if (($request->method === 'GET' || $request->method === 'HEAD')
            && preg_match('#^/api/promotions/([12])/image$#', $request->path, $match)
        ) {
            return $this->image($request, (int) $match[1]);
        }
        if ($request->method === 'PUT'
            && preg_match('#^/api/admin/promotions/([12])$#', $request->path, $match)
        ) {
            $this->guard->requireAdmin($request);
            $this->guard->requireMultipart($request);
            $form = $request->multipart();

            return ApiResponder::json($this->service->update(
                (int) $match[1],
                $form['fields'],
                $form['files']['image'] ?? null
            ));
        }

        return null;
    }

    private function image(Request $request, int $slot): Response
    {
        $image = $this->service->image($slot);
        $etag = ApiResponder::etag($slot . '|' . $image['size'] . '|' . $image['updatedAt']);
        $cache = isset($request->query['v']) && trim((string) $request->query['v']) !== ''
            ? 'public, max-age=31536000, immutable'
            : 'public, max-age=3600, stale-while-revalidate=86400';
        $headers = [
            'Content-Type' => $image['contentType'],
            'Content-Length' => (string) $image['size'],
            'Cache-Control' => $cache,
            'ETag' => $etag,
        ];
        if (ApiResponder::etagMatches($request, $etag)) {
            return new Response(304, '', $headers);
        }

        return new Response(200, $request->method === 'HEAD' ? '' : $image['bytes'], $headers);
    }
}
