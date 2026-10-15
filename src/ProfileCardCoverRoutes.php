<?php

declare(strict_types=1);

namespace ChambreRose;

final class ProfileCardCoverRoutes implements RouteHandler
{
    public function __construct(
        private readonly ProfileCardCoverRepository $covers,
        private readonly ProfileCardCoverService $service,
        private readonly MarketplaceService $marketplace,
        private readonly ApiRequestGuard $guard
    ) {
    }

    public function handle(Request $request): ?Response
    {
        if ($request->path === '/api/profiles/me/card-cover'
            && in_array($request->method, ['POST', 'DELETE'], true)
        ) {
            $user = $this->guard->currentUser($request);
            if (!in_array($user['role'], ['ESCORT', 'STORE'], true)) {
                throw new ApiException(403, 'A professional account is required.');
            }
            $userId = (int) $user['id'];
            if ($request->method === 'DELETE') {
                $this->covers->delete($userId);
                return ApiResponder::json($this->covers->forUser($userId));
            }
            $this->guard->requireMultipart($request);
            $form = $request->multipart();
            $file = $form['files']['image'] ?? throw new ApiException(400, 'Choose a card cover.');

            return ApiResponder::json($this->service->upload($userId, $file), 201);
        }
        if (in_array($request->method, ['GET', 'HEAD'], true)
            && preg_match('#^/api/profiles/(\d+)/card-cover/(\d{1,4})\.webp$#', $request->path, $match)
        ) {
            return $this->image($request, (int) $match[1], (int) $match[2]);
        }

        return null;
    }

    private function image(Request $request, int $userId, int $width): Response
    {
        $viewer = $this->guard->optionalCurrentUser($request);
        $access = $this->marketplace->publicMediaAccess($userId, $viewer);
        $isPublic = $access !== null;
        if (!$isPublic && ($viewer === null
            || ((int) $viewer['id'] !== $userId && $viewer['role'] !== 'ADMIN'))
        ) {
            throw new ApiException(404, 'Card cover not found.');
        }
        $meta = $this->covers->metadata($userId, $width)
            ?? throw new ApiException(404, 'Card cover not found.');
        if (isset($request->query['v']) && $request->query['v'] !== $meta['sourceHash']) {
            throw new ApiException(404, 'Card cover not found.');
        }
        $etag = ApiResponder::etag($userId . '|' . $width . '|' . $meta['sourceHash'] . '|card-cover');
        $headers = [
            'Content-Type' => 'image/webp',
            'Cache-Control' => $isPublic && $viewer === null ? 'public, max-age=300, stale-while-revalidate=300' : 'private, no-store',
            'Vary' => 'Cookie, Authorization',
            'ETag' => $etag,
        ];
        if (ApiResponder::etagMatches($request, $etag)) {
            return new Response(304, '', $headers);
        }
        if ($request->method === 'HEAD') {
            return new Response(200, '', $headers);
        }
        $bytes = $this->covers->bytes($userId, $width, $meta['sourceHash']);
        $headers['Content-Length'] = (string) strlen($bytes);

        return new Response(200, $bytes, $headers);
    }
}
