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
                'Cache-Control' => $this->guard->hasAuthenticationCredentials($request)
                    ? 'private, no-store'
                    : 'public, max-age=30, stale-while-revalidate=300',
                'Vary' => 'Cookie, Authorization',
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
        if ($request->method === 'HEAD' || $request->header('if-none-match') !== null) {
            $metadata = $this->service->imageMetadata($slot);
            if ($metadata !== null) {
                $headers = $this->imageHeaders($request, $slot, $metadata);
                if (ApiResponder::etagMatches($request, $headers['ETag'])) {
                    return new Response(304, '', $headers);
                }
                if ($request->method === 'HEAD') return new Response(200, '', $headers);
            }
        }
        $image = $this->service->image($slot);
        $headers = $this->imageHeaders($request, $slot, $image);
        if (ApiResponder::etagMatches($request, $headers['ETag'])) {
            return new Response(304, '', $headers);
        }

        return new Response(200, $request->method === 'HEAD' ? '' : $image['bytes'], $headers);
    }

    /**
     * @param array{contentType:string,size:int,sourceHash:string,updatedAt:string} $image
     * @return array<string,string>
     */
    private function imageHeaders(Request $request, int $slot, array $image): array
    {
        $version = $request->query['v'] ?? null;
        $versioned = is_string($version) && $version !== ''
            && ($version === $image['sourceHash'] || $version === $image['updatedAt']);

        return [
            'Content-Type' => $image['contentType'],
            'Content-Length' => (string) $image['size'],
            'Cache-Control' => $versioned
                ? 'public, max-age=31536000, immutable'
                : 'public, max-age=3600, stale-while-revalidate=86400',
            'ETag' => ApiResponder::etag($slot . '|' . $image['sourceHash'] . '|promotion'),
        ];
    }
}
