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
                'New profile review',
                'A member left a new review on your profile.',
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
        if ($method === 'GET' && preg_match('#^/api/profiles/(\d+)/media/(\d+)$#', $path, $match)) {
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
            'New profile selection',
            ($buyerName === '' ? 'A member' : $buyerName) . ' selected an option from your profile.',
            '/catalogue/perfil/' . $profileId,
            null
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
                'New favorite',
                'A member added your profile to favorites.',
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
        try {
            $this->marketplace->publicProfile($userId);
        } catch (ApiException $exception) {
            if ($exception->status !== 404) {
                throw $exception;
            }
            $viewer = $this->guard->optionalCurrentUser($request);
            if ($viewer === null || ((int) $viewer['id'] !== $userId && $viewer['role'] !== 'ADMIN')) {
                throw new ApiException(404, 'Media not found.');
            }
        }
        $meta = $this->media->metadata($userId, $mediaId);
        if ($meta === null) {
            throw new ApiException(404, 'Media not found.');
        }
        $etag = ApiResponder::etag($userId . '|' . $mediaId . '|' . $meta['size'] . '|' . $meta['createdAt']);
        $cache = 'private, no-store';
        if (ApiResponder::etagMatches($request, $etag)) {
            return new Response(304, '', ['ETag' => $etag, 'Cache-Control' => $cache]);
        }
        $bytes = $this->media->data($userId, $mediaId);
        if ($bytes === null) {
            throw new ApiException(404, 'Media not found.');
        }
        $extension = match ($meta['contentType']) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'video/mp4' => 'mp4',
            'video/webm' => 'webm',
            default => 'bin',
        };
        $kind = $meta['type'] === 'PHOTO' ? 'photo' : 'video';

        return new Response(200, $bytes, [
            'Content-Type' => $meta['contentType'],
            'Content-Length' => (string) strlen($bytes),
            'Content-Disposition' => 'inline; filename="profile-' . $kind . '-' . $mediaId . '.' . $extension . '"',
            'Cache-Control' => $cache,
            'ETag' => $etag,
        ]);
    }
}
