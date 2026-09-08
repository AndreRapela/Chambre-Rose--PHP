<?php

declare(strict_types=1);

namespace ChambreRose;

final class ListingRoutes implements RouteHandler
{
    public function __construct(
        private readonly MarketplaceService $marketplace,
        private readonly ProfessionalProfileRepository $profiles,
        private readonly ProfileMediaRepository $media,
        private readonly FavoritesRepository $favorites,
        private readonly ProductRepository $products,
        private readonly ApiRequestGuard $guard,
        private readonly UserNotificationService $notifications
    ) {
    }

    public function handle(Request $request): ?Response
    {
        $method = $request->method;
        $path = $request->path;

        if ($method === 'GET' && $path === '/api/listings') {
            return ApiResponder::json($this->marketplace->listings($request->query));
        }
        if ($method === 'GET' && preg_match('#^/api/listings/(\d+)$#', $path, $match)) {
            return ApiResponder::json($this->marketplace->publicProfile((int) $match[1]));
        }
        if ($method === 'GET' && preg_match('#^/api/listings/(\d+)/contact$#', $path, $match)) {
            return ApiResponder::json(
                $this->marketplace->contactDetails((int) $match[1], $this->guard->currentUser($request))
            );
        }
        if ($method === 'POST' && preg_match('#^/api/listings/(\d+)/reviews$#', $path, $match)) {
            $user = $this->guard->currentUser($request);
            $this->guard->requireJson($request);
            $target = (int) $match[1];
            $review = $this->marketplace->submitReview($target, $user, $request->json());
            $this->notifications->notify(
                $target,
                UserNotificationService::MARKETPLACE,
                'PROFILE_REVIEWED',
                '/catalogue/perfil/' . $target,
                'profile-review:' . (int) ($review['id'] ?? 0)
            );

            return ApiResponder::json($review, 201);
        }
        if ($method === 'POST' && preg_match('#^/api/listings/(\d+)/purchases$#', $path, $match)) {
            return $this->registerSelection($request, (int) $match[1]);
        }
        if (($method === 'POST' || $method === 'PUT') && $path === '/api/listings') {
            $user = $this->guard->currentUser($request);
            $this->guard->requireJson($request);

            return ApiResponder::json(
                $this->marketplace->saveProfile((int) $user['id'], $request->json()),
                $method === 'POST' ? 201 : 200
            );
        }
        if ($method === 'PUT' && preg_match('#^/api/listings/(\d+)$#', $path, $match)) {
            return $this->updateListing($request, (int) $match[1]);
        }
        if ($method === 'GET' && $path === '/api/profiles/me') {
            $user = $this->guard->currentUser($request);

            return ApiResponder::json($this->marketplace->ownProfile((int) $user['id']));
        }
        if ($method === 'GET' && preg_match('#^/api/profiles/(\d+)$#', $path, $match)) {
            return $this->adminProfile($request, (int) $match[1]);
        }
        if ($method === 'PUT' && $path === '/api/profiles/me') {
            $user = $this->guard->currentUser($request);
            $this->guard->requireJson($request);

            return ApiResponder::json($this->marketplace->saveProfile((int) $user['id'], $request->json()));
        }
        if ($method === 'PUT' && preg_match('#^/api/profiles/(\d+)$#', $path, $match)) {
            $this->guard->requireAdmin($request);
            $this->guard->requireJson($request);

            return ApiResponder::json($this->marketplace->saveProfile((int) $match[1], $request->json()));
        }
        if ($method === 'POST' && $path === '/api/profiles/me/media') {
            return $this->upload($request);
        }
        if ($method === 'GET' && preg_match('#^/api/profiles/(\d+)/media/(\d+)/(320|640|960|1280)\.webp$#', $path, $match)) {
            return $this->profileMediaVariant($request, (int) $match[1], (int) $match[2], (int) $match[3]);
        }
        if (($method === 'GET' || $method === 'HEAD')
            && preg_match('#^/api/profiles/(\d+)/media/(\d+)$#', $path, $match)
        ) {
            return $this->profileMedia($request, (int) $match[1], (int) $match[2]);
        }
        if ($method === 'DELETE' && preg_match('#^/api/profiles/(\d+)/media/(\d+)$#', $path, $match)) {
            return $this->deleteMedia($request, (int) $match[1], (int) $match[2]);
        }
        if ($method === 'DELETE' && preg_match('#^/api/profiles/me/media/(\d+)$#', $path, $match)) {
            $user = $this->guard->currentUser($request);
            $this->media->delete((int) $user['id'], (int) $match[1]);

            return ApiResponder::empty();
        }
        $favoriteRoute = ($method === 'GET' && $path === '/api/favorites')
            || (($method === 'POST' || $method === 'DELETE')
                && preg_match('#^/api/favorites/\d+$#', $path));
        if ($favoriteRoute) {
            return $this->favorites($request);
        }

        return null;
    }

    private function registerSelection(Request $request, int $profileId): Response
    {
        $user = $this->guard->currentUser($request);
        if (!$user['vipActive']) {
            throw new ApiException(403, 'VIP membership is required to select a companion.', ['vipRequired' => 'true']);
        }
        $profile = $this->marketplace->publicProfile($profileId);
        if ($profile['type'] !== 'ESCORT') {
            throw new ApiException(400, 'Only companion profiles can receive this purchase type.');
        }
        $this->guard->requireJson($request);
        $body = $request->json();
        $amount = isset($body['amount']) && is_numeric($body['amount'])
            ? max(0, (float) $body['amount'])
            : null;
        $this->products->registerProfilePurchase($profileId, (int) $user['id'], $amount);
        $buyerName = trim((string) ($user['firstName'] ?? '') . ' ' . (string) ($user['lastName'] ?? ''));
        $this->notifications->notify(
            $profileId,
            UserNotificationService::MARKETPLACE,
            'PROFILE_SELECTED',
            '/catalogue/perfil/' . $profileId,
            null,
            ['memberName' => $buyerName]
        );

        return ApiResponder::json($this->marketplace->publicProfile($profileId), 201);
    }

    private function updateListing(Request $request, int $target): Response
    {
        $user = $this->guard->currentUser($request);
        if ((int) $user['id'] !== $target && $user['role'] !== 'ADMIN') {
            throw new ApiException(403, 'You may edit only your own listing.');
        }
        $this->guard->requireJson($request);

        return ApiResponder::json($this->marketplace->saveProfile($target, $request->json()));
    }

    private function adminProfile(Request $request, int $userId): Response
    {
        $this->guard->requireAdmin($request);
        $profile = $this->profiles->findByUser($userId)
            ?? throw new ApiException(404, 'Professional profile not found.');
        $profile['media'] = $this->media->listFor($userId);

        return ApiResponder::json($profile);
    }

    private function upload(Request $request): Response
    {
        $user = $this->guard->currentUser($request);
        $this->guard->requireMultipart($request);
        $form = $request->multipart();
        $file = $form['files']['media']
            ?? new UploadedFile('', '', 0, null, null, UPLOAD_ERR_NO_FILE);

        return ApiResponder::json(
            $this->marketplace->upload((int) $user['id'], $file, (int) ($form['fields']['position'] ?? 0)),
            201
        );
    }

    private function deleteMedia(Request $request, int $target, int $mediaId): Response
    {
        $user = $this->guard->currentUser($request);
        if ((int) $user['id'] !== $target && $user['role'] !== 'ADMIN') {
            throw new ApiException(403, 'You may delete only your own media.');
        }
        $this->media->delete($target, $mediaId);

        return ApiResponder::empty();
    }

    private function favorites(Request $request): ?Response
    {
        $method = $request->method;
        $path = $request->path;
        $user = $this->guard->currentUser($request);

        if ($method === 'GET' && $path === '/api/favorites') {
            $items = [];
            foreach ($this->favorites->ids((int) $user['id']) as $id) {
                try {
                    $items[] = $this->marketplace->publicProfile($id);
                } catch (ApiException) {
                }
            }

            return ApiResponder::json(['items' => $items]);
        }
        if ($method === 'POST' && preg_match('#^/api/favorites/(\d+)$#', $path, $match)) {
            $target = (int) $match[1];
            $this->marketplace->publicProfile($target);
            $this->favorites->add((int) $user['id'], $target);
            $this->notifications->notify(
                $target,
                UserNotificationService::MARKETPLACE,
                'PROFILE_FAVORITED',
                '/catalogue/perfil/' . $target,
                'favorite:' . (int) $user['id'] . ':' . $target
            );

            return ApiResponder::json(['favorited' => true, 'profileId' => $target], 201);
        }
        if ($method === 'DELETE' && preg_match('#^/api/favorites/(\d+)$#', $path, $match)) {
            $this->favorites->remove((int) $user['id'], (int) $match[1]);

            return ApiResponder::empty();
        }

        return null;
    }

    private function profileMedia(Request $request, int $userId, int $mediaId): Response
    {
        $this->authorizeProfileMedia($request, $userId);
        $meta = $this->media->metadata($userId, $mediaId);
        if ($meta === null) {
            throw new ApiException(404, 'Media not found.');
        }
        $etag = ApiResponder::etag($userId . '|' . $mediaId . '|' . $meta['size'] . '|' . $meta['createdAt']);
        $cache = 'private, no-store';
        $extension = match ($meta['contentType']) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'video/mp4' => 'mp4',
            'video/webm' => 'webm',
            default => 'bin',
        };
        $kind = $meta['type'] === 'PHOTO' ? 'photo' : 'video';
        $headers = [
            'Content-Type' => (string) $meta['contentType'],
            'Content-Disposition' => 'inline; filename="profile-' . $kind . '-' . $mediaId . '.' . $extension . '"',
            'Cache-Control' => $cache,
            'ETag' => $etag,
        ];
        $isVideo = $meta['type'] === 'VIDEO';
        if ($isVideo) {
            $headers['Accept-Ranges'] = 'bytes';
            $headers['X-Accel-Buffering'] = 'no';
        }

        if (ApiResponder::etagMatches($request, $etag)) {
            return new Response(304, '', $headers);
        }

        if ($isVideo) {
            return $this->videoResponse($request, $userId, $mediaId, (int) $meta['size'], $etag, $headers);
        }

        if ($request->method === 'HEAD') {
            return new Response(200, '', ['Content-Length' => (string) $meta['size']] + $headers);
        }

        $bytes = $this->media->data($userId, $mediaId);
        if ($bytes === null) {
            throw new ApiException(404, 'Media not found.');
        }

        return new Response(200, $bytes, [
            'Content-Length' => (string) strlen($bytes),
        ] + $headers);
    }

    /** @param array<string, string> $headers */
    private function videoResponse(
        Request $request,
        int $userId,
        int $mediaId,
        int $size,
        string $etag,
        array $headers
    ): Response {
        $rangeHeader = $request->header('range');
        $ifRange = trim($request->header('if-range') ?? '');
        if ($ifRange !== '' && !hash_equals($etag, $ifRange)) {
            $rangeHeader = null;
        }

        $range = HttpByteRange::parse($rangeHeader, $size);
        if ($range === null) {
            $start = 0;
            $end = $size - 1;
            $length = $size;
            $status = 200;
        } else {
            $start = $range->start;
            $end = $range->end;
            $length = $range->length();
            $status = 206;
        }
        $headers['Content-Length'] = (string) $length;
        if ($range !== null) {
            $headers['Content-Range'] = 'bytes ' . $start . '-' . $end . '/' . $size;
        }

        if ($request->method === 'HEAD') {
            return new Response($status, '', $headers);
        }

        $chunks = $this->media->chunks($userId, $mediaId, $start, $length);
        $chunks->rewind();
        if (!$chunks->valid()) {
            throw new ApiException(404, 'Media not found.');
        }

        return Response::stream(static function () use ($chunks): void {
            while ($chunks->valid()) {
                echo $chunks->current();
                flush();
                $chunks->next();
            }
        }, $headers, $status);
    }

    private function profileMediaVariant(Request $request, int $userId, int $mediaId, int $width): Response
    {
        $isPublic = $this->authorizeProfileMedia($request, $userId);
        $image = $this->marketplace->responsivePhoto($userId, $mediaId, $width);
        $etag = ApiResponder::etag($userId . '|' . $mediaId . '|' . $image['sourceHash'] . '|' . $width);
        $cache = $isPublic ? 'public, max-age=86400, must-revalidate' : 'private, no-store';
        if (ApiResponder::etagMatches($request, $etag)) {
            return new Response(304, '', ['ETag' => $etag, 'Cache-Control' => $cache]);
        }

        return new Response(200, $image['bytes'], [
            'Content-Type' => $image['contentType'],
            'Content-Length' => (string) $image['size'],
            'Content-Disposition' => 'inline; filename="profile-photo-' . $mediaId . '-' . $width . '.webp"',
            'Cache-Control' => $cache,
            'ETag' => $etag,
        ]);
    }

    private function authorizeProfileMedia(Request $request, int $userId): bool
    {
        if ($this->profiles->findByUser($userId, true) !== null) {
            return true;
        }

        $viewer = $this->guard->optionalCurrentUser($request);
        if ($viewer === null || ((int) $viewer['id'] !== $userId && $viewer['role'] !== 'ADMIN')) {
            throw new ApiException(404, 'Media not found.');
        }

        return false;
    }
}
