<?php

declare(strict_types=1);

namespace ChambreRose;

use PDO;

final class App
{
    private PDO $pdo;
    private UserRepository $users;
    private Jwt $jwt;
    private ProfessionalProfileRepository $profiles;
    private ProfileMediaRepository $profileMedia;
    private FavoritesRepository $favorites;
    private MessagingRepository $messaging;
    private PasswordResetRepository $passwordResets;
    private MailService $mail;
    private MarketplaceService $marketplace;
    private ProductRepository $products;
    private ProductImageRepository $productImages;
    private ProductService $productService;
    private bool $booted = false;

    public function __construct()
    {
    }

    private function boot(): void
    {
        if ($this->booted) {
            return;
        }
        $this->pdo = Database::connection();
        if (Config::bool('APP_AUTO_MIGRATE', true)) {
            $migrated = (new DatabaseMigrator($this->pdo))->migrate();
            if ($migrated) {
                (new Seeder($this->pdo))->run();
            }
        }
        $this->users = new UserRepository($this->pdo);
        $this->jwt = new Jwt();
        $this->profiles = new ProfessionalProfileRepository($this->pdo);
        $this->profileMedia = new ProfileMediaRepository($this->pdo);
        $this->favorites = new FavoritesRepository($this->pdo);
        $this->messaging = new MessagingRepository($this->pdo);
        $this->passwordResets = new PasswordResetRepository($this->pdo);
        $this->mail = new MailService($this->pdo);
        $this->marketplace = new MarketplaceService($this->users, $this->profiles, $this->profileMedia);
        $this->products = new ProductRepository($this->pdo);
        $this->productImages = new ProductImageRepository($this->pdo);
        $this->productService = new ProductService($this->products, $this->productImages, $this->users);
        $this->booted = true;
    }

    public function handle(Request $request): Response
    {
        if ($request->method === 'OPTIONS') {
            return (new Response(204))->withHeaders(self::commonHeaders($request));
        }
        if ($request->method === 'GET' && $request->path === '/api/brand/logo') {
            return $this->brandLogo($request)->withHeaders(self::commonHeaders($request));
        }

        $contentLength = (int) ($request->header('content-length') ?? 0);
        if ($contentLength > 30 * 1024 * 1024) {
            throw new ApiException(413, 'Request body cannot exceed 30MB.');
        }

        $this->boot();
        $response = $this->dispatch($request);

        return $response->withHeaders(self::commonHeaders($request));
    }

    /** @return array<string, string> */
    public static function commonHeaders(Request $request): array
    {
        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'X-Request-ID' => $request->requestId,
            'Referrer-Policy' => 'no-referrer',
            'Content-Security-Policy' => "default-src 'self'; frame-ancestors 'none'",
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
        ];

        $origin = $request->header('origin');
        $allowed = array_values(array_filter(array_map(
            'trim',
            explode(',', Config::get('APP_CORS_ALLOWED_ORIGINS', '') ?? '')
        )));
        if ($allowed !== []) {
            $headers['Vary'] = 'Origin';
        }
        if ($origin !== null && in_array($origin, $allowed, true)) {
            $headers['Access-Control-Allow-Origin'] = $origin;
            $headers['Access-Control-Allow-Credentials'] = 'true';
        }
        $headers['Access-Control-Allow-Methods'] = 'GET, POST, PUT, PATCH, DELETE, OPTIONS';
        $headers['Access-Control-Allow-Headers'] = 'Authorization, Content-Type, Accept, Origin, X-Requested-With';
        $headers['Access-Control-Expose-Headers'] = 'Cache-Control, Content-Language, Content-Type';
        $headers['Access-Control-Max-Age'] = '3600';

        return $headers;
    }

    private function dispatch(Request $request): Response
    {
        $method = $request->method;
        $path = $request->path;
        $auth = new AuthService(
            $this->users,
            $this->jwt,
            $this->profiles,
            $this->marketplace,
            $this->passwordResets,
            $this->mail
        );

        if ($method === 'GET' && $path === '/api/health') {
            return Response::json(['status' => 'UP', 'timestamp' => gmdate('Y-m-d\TH:i:s\Z')], 200, self::noStore());
        }
        if ($method === 'POST' && $path === '/api/auth/login') {
            $this->requireJson($request);

            return Response::json($auth->login($request->json()), 200, self::noStore());
        }
        if ($method === 'POST' && $path === '/api/auth/register') {
            if ($request->contentType() === 'application/json') {
                return Response::json($auth->register($request->json()), 201, self::noStore());
            }
            $this->requireMultipart($request);
            $form = $request->multipart();

            return Response::json(
                $auth->register($form['fields'], $form['files']['establishmentPhoto'] ?? null),
                201,
                self::noStore()
            );
        }
        if ($method === 'POST' && $path === '/api/auth/forgot-password') {
            $this->requireJson($request);

            return Response::json($auth->forgotPassword($request->json()), 202, self::noStore());
        }
        if ($method === 'POST' && $path === '/api/auth/reset-password') {
            $this->requireJson($request);

            return Response::json($auth->resetPassword($request->json()), 200, self::noStore());
        }
        if ($method === 'GET' && $path === '/api/auth/me') {
            $identity = $this->authenticate($request);

            return Response::json($auth->profile($identity['sub']), 200, self::noStore());
        }
        if ($method === 'PUT' && $path === '/api/auth/me') {
            $identity = $this->authenticate($request);
            $this->requireJson($request);

            return Response::json($auth->updateProfile($identity['sub'], $request->json()), 200, self::noStore());
        }

        if ($method === 'GET' && $path === '/api/listings') {
            return Response::json($this->marketplace->listings($request->query), 200, self::noStore());
        }
        if ($method === 'GET' && preg_match('#^/api/listings/(\d+)$#', $path, $match)) {
            return Response::json($this->marketplace->publicProfile((int) $match[1]), 200, self::noStore());
        }
        if ($method === 'GET' && preg_match('#^/api/listings/(\d+)/contact$#', $path, $match)) {
            $user = $this->currentUser($request);

            return Response::json(
                $this->marketplace->contactDetails((int) $match[1], $user),
                200,
                self::noStore()
            );
        }
        if ($method === 'POST' && preg_match('#^/api/listings/(\d+)/reviews$#', $path, $match)) {
            $user = $this->currentUser($request);
            $this->requireJson($request);

            return Response::json(
                $this->marketplace->submitReview((int) $match[1], $user, $request->json()),
                201,
                self::noStore()
            );
        }
        if ($method === 'POST' && preg_match('#^/api/listings/(\d+)/purchases$#', $path, $match)) {
            $user = $this->currentUser($request);
            if (!$user['vipActive']) {
                throw new ApiException(403, 'VIP membership is required to select a companion.', ['vipRequired' => 'true']);
            }
            $profile = $this->marketplace->publicProfile((int) $match[1]);
            if ($profile['type'] !== 'ESCORT') {
                throw new ApiException(400, 'Only companion profiles can receive this purchase type.');
            }
            $this->requireJson($request);
            $body = $request->json();
            $amount = isset($body['amount']) && is_numeric($body['amount']) ? max(0, (float) $body['amount']) : null;
            $this->products->registerProfilePurchase((int) $match[1], (int) $user['id'], $amount);

            return Response::json($this->marketplace->publicProfile((int) $match[1]), 201, self::noStore());
        }

        if ($method === 'GET' && $path === '/api/products') {
            return Response::json($this->productService->search($request->query), 200, self::noStore());
        }
        if ($method === 'GET' && preg_match('#^/api/products/(\d+)$#', $path, $match)) {
            return Response::json($this->productService->get((int) $match[1]), 200, self::noStore());
        }
        if ($method === 'GET' && preg_match('#^/api/products/(\d+)/images/(main|secondary)$#', $path, $match)) {
            return $this->productImage($request, (int) $match[1], strtoupper($match[2]));
        }
        if ($method === 'POST' && preg_match('#^/api/products/(\d+)/purchases$#', $path, $match)) {
            $user = $this->currentUser($request);

            return Response::json($this->products->registerPurchase((int) $match[1], (int) $user['id']), 201, self::noStore());
        }
        if (($method === 'POST' && $path === '/api/products') || ($method === 'PUT' && preg_match('#^/api/products/(\d+)$#', $path, $match))) {
            $user = $this->currentUser($request);
            $id = $method === 'PUT' ? (int) $match[1] : null;
            if ($request->contentType() === 'multipart/form-data') {
                $form = $request->multipart();
                $product = $this->productService->save(
                    $id,
                    (int) $user['id'],
                    (string) $user['role'],
                    $form['fields'],
                    $form['files']['mainImage'] ?? null,
                    $form['files']['secondaryImage'] ?? null
                );
            } else {
                $this->requireJson($request);
                $product = $this->productService->save($id, (int) $user['id'], (string) $user['role'], $request->json(), null, null);
            }

            return Response::json($product, $method === 'POST' ? 201 : 200, self::noStore());
        }
        if ($method === 'DELETE' && preg_match('#^/api/products/(\d+)$#', $path, $match)) {
            $user = $this->currentUser($request);
            $product = $this->products->find((int) $match[1], false) ?? throw new ApiException(404, 'Product not found.');
            if ($user['role'] !== 'ADMIN' && ($user['role'] !== 'STORE' || (int) ($product['storeUserId'] ?? 0) !== (int) $user['id'])) {
                throw new ApiException(403, 'Only the product store or an administrator may remove it.');
            }
            $this->products->setActive((int) $match[1], false);

            return new Response(204, '', self::noStore());
        }
        if (($method === 'POST' || $method === 'PUT') && $path === '/api/listings') {
            $user = $this->currentUser($request);
            $this->requireJson($request);

            return Response::json($this->marketplace->saveProfile((int) $user['id'], $request->json()), $method === 'POST' ? 201 : 200, self::noStore());
        }
        if ($method === 'PUT' && preg_match('#^/api/listings/(\d+)$#', $path, $match)) {
            $user = $this->currentUser($request);
            $target = (int) $match[1];
            if ((int) $user['id'] !== $target && $user['role'] !== 'ADMIN') {
                throw new ApiException(403, 'You may edit only your own listing.');
            }
            $this->requireJson($request);

            return Response::json($this->marketplace->saveProfile($target, $request->json()), 200, self::noStore());
        }
        if ($method === 'GET' && $path === '/api/profiles/me') {
            $user = $this->currentUser($request);

            return Response::json($this->marketplace->ownProfile((int) $user['id']), 200, self::noStore());
        }
        if ($method === 'GET' && preg_match('#^/api/profiles/(\d+)$#', $path, $match)) {
            $this->requireAdmin($request);
            $profile = $this->profiles->findByUser((int)$match[1]) ?? throw new ApiException(404, 'Professional profile not found.');
            $profile['media'] = $this->profileMedia->listFor((int)$match[1]);

            return Response::json($profile, 200, self::noStore());
        }
        if ($method === 'PUT' && $path === '/api/profiles/me') {
            $user = $this->currentUser($request);
            $this->requireJson($request);

            return Response::json($this->marketplace->saveProfile((int) $user['id'], $request->json()), 200, self::noStore());
        }
        if ($method === 'PUT' && preg_match('#^/api/profiles/(\d+)$#', $path, $match)) {
            $this->requireAdmin($request);
            $this->requireJson($request);

            return Response::json($this->marketplace->saveProfile((int) $match[1], $request->json()), 200, self::noStore());
        }
        if ($method === 'POST' && $path === '/api/profiles/me/media') {
            $user = $this->currentUser($request);
            $this->requireMultipart($request);
            $form = $request->multipart();

            return Response::json($this->marketplace->upload((int) $user['id'], $form['files']['media'] ?? new UploadedFile('', '', 0, null, null, UPLOAD_ERR_NO_FILE), (int) ($form['fields']['position'] ?? 0)), 201, self::noStore());
        }
        if ($method === 'GET' && preg_match('#^/api/profiles/(\d+)/media/(\d+)$#', $path, $match)) {
            return $this->profileMedia($request, (int) $match[1], (int) $match[2]);
        }
        if ($method === 'DELETE' && preg_match('#^/api/profiles/(\d+)/media/(\d+)$#', $path, $match)) {
            $user = $this->currentUser($request);
            $target = (int)$match[1];
            if ((int)$user['id'] !== $target && $user['role'] !== 'ADMIN') {
                throw new ApiException(403, 'You may delete only your own media.');
            }
            $this->profileMedia->delete($target, (int)$match[2]);

            return new Response(204, '', self::noStore());
        }
        if ($method === 'DELETE' && preg_match('#^/api/profiles/me/media/(\d+)$#', $path, $match)) {
            $user = $this->currentUser($request);
            $this->profileMedia->delete((int)$user['id'], (int)$match[1]);

            return new Response(204, '', self::noStore());
        }

        if ($method === 'GET' && $path === '/api/favorites') {
            $user = $this->currentUser($request);
            $items = [];
            foreach ($this->favorites->ids((int)$user['id']) as $id) {
                try {
                    $items[] = $this->marketplace->publicProfile($id);
                } catch (ApiException) {
                }
            }

            return Response::json(['items' => $items], 200, self::noStore());
        }
        if ($method === 'POST' && preg_match('#^/api/favorites/(\d+)$#', $path, $match)) {
            $user = $this->currentUser($request);
            $target = (int)$match[1];
            $this->marketplace->publicProfile($target);
            $this->favorites->add((int)$user['id'], $target);

            return Response::json(['favorited' => true,'profileId' => $target], 201, self::noStore());
        }
        if ($method === 'DELETE' && preg_match('#^/api/favorites/(\d+)$#', $path, $match)) {
            $user = $this->currentUser($request);
            $target = (int)$match[1];
            $this->favorites->remove((int)$user['id'], $target);

            return new Response(204, '', self::noStore());
        }

        if ($method === 'GET' && $path === '/api/conversations') {
            $user = $this->currentUser($request);

            return Response::json(['items' => $this->messaging->list((int)$user['id'])], 200, self::noStore());
        }
        if ($method === 'POST' && $path === '/api/conversations') {
            $user = $this->currentUser($request);
            $this->requireJson($request);
            $body = $request->json();
            $recipient = (int)($body['recipientId'] ?? 0);
            $recipientUser = $recipient > 0 ? $this->users->find($recipient) : null;
            if ($recipientUser === null || $recipientUser['approvalStatus'] !== 'APPROVED') {
                throw new ApiException(404, 'Recipient not found.');
            }

            return Response::json(
                $this->messaging->conversation((int)$user['id'], $recipient),
                201,
                self::noStore()
            );
        }
        if ($method === 'GET' && preg_match('#^/api/conversations/(\d+)/messages$#', $path, $match)) {
            $user = $this->currentUser($request);

            return Response::json(
                ['items' => $this->messaging->messages((int) $match[1], (int) $user['id'])],
                200,
                self::noStore()
            );
        }
        if ($method === 'POST' && preg_match('#^/api/conversations/(\d+)/messages$#', $path, $match)) {
            $user = $this->currentUser($request);
            $this->requireJson($request);
            $body = $request->json();

            return Response::json(
                $this->messaging->send((int) $match[1], (int) $user['id'], (string) ($body['body'] ?? '')),
                201,
                self::noStore()
            );
        }
        if ($method === 'PATCH' && preg_match('#^/api/conversations/(\d+)/read$#', $path, $match)) {
            $user = $this->currentUser($request);
            $this->messaging->read((int) $match[1], (int) $user['id']);

            return new Response(204, '', self::noStore());
        }
        if ($method === 'PATCH' && preg_match('#^/api/conversations/(\d+)/archive$#', $path, $match)) {
            $user = $this->currentUser($request);
            $this->requireJson($request);
            $body = $request->json();
            if (!isset($body['archived']) || !is_bool($body['archived'])) {
                throw new ApiException(400, 'archived must be boolean.');
            }
            $this->messaging->archive((int) $match[1], (int) $user['id'], $body['archived']);

            return new Response(204, '', self::noStore());
        }
        if ($method === 'DELETE' && preg_match('#^/api/conversations/(\d+)$#', $path, $match)) {
            $user = $this->currentUser($request);
            $this->messaging->deleteForUser((int)$match[1], (int)$user['id']);

            return new Response(204, '', self::noStore());
        }
        if ($method === 'POST' && preg_match('#^/api/users/(\d+)/block$#', $path, $match)) {
            $user = $this->currentUser($request);
            $target = (int) $match[1];
            if ($this->users->find($target) === null) {
                throw new ApiException(404, 'User not found.');
            }
            $this->messaging->block((int) $user['id'], $target);

            return new Response(204, '', self::noStore());
        }
        if ($method === 'DELETE' && preg_match('#^/api/users/(\d+)/block$#', $path, $match)) {
            $user = $this->currentUser($request);
            $this->messaging->unblock((int)$user['id'], (int)$match[1]);

            return new Response(204, '', self::noStore());
        }
        if ($method === 'POST' && preg_match('#^/api/users/(\d+)/reports$#', $path, $match)) {
            $user = $this->currentUser($request);
            $this->requireJson($request);
            $body = $request->json();
            $target = (int) $match[1];
            if ($this->users->find($target) === null) {
                throw new ApiException(404, 'User not found.');
            }

            return Response::json($this->messaging->report((int)$user['id'], $target, (string)($body['reason'] ?? ''), isset($body['details']) ? (string)$body['details'] : null), 201, self::noStore());
        }

        if ($method === 'GET' && $path === '/api/admin/users') {
            $this->requireAdmin($request);
            $service = new AdminUserService($this->users, $this->mail, $this->profiles);

            return Response::json($service->list(
                self::queryString($request, 'email'),
                self::queryString($request, 'name'),
                self::queryString($request, 'sort') ?? 'newest',
                self::queryString($request, 'approvalStatus'),
                self::queryString($request, 'role')
            ), 200, self::noStore());
        }
        if ($method === 'PATCH' && preg_match('#^/api/admin/users/(\d+)/vip$#', $path, $match)) {
            $this->requireAdmin($request);
            $this->requireJson($request);
            $service = new AdminUserService($this->users, $this->mail, $this->profiles);

            return Response::json(
                $service->updateVip((int) $match[1], Validator::vipActive($request->json())),
                200,
                self::noStore()
            );
        }
        if ($method === 'PATCH' && preg_match('#^/api/admin/users/(\d+)/approval$#', $path, $match)) {
            $this->requireAdmin($request);
            $this->requireJson($request);
            $body = $request->json();
            $status = strtoupper(trim((string)($body['status'] ?? '')));
            $reason = isset($body['reason']) ? trim((string)$body['reason']) : null;
            if ($reason !== null && strlen($reason) > 500) {
                throw new ApiException(400, 'Approval reason cannot exceed 500 characters.');
            }

            return Response::json((new AdminUserService($this->users, $this->mail, $this->profiles))->updateApproval((int)$match[1], $status, $reason), 200, self::noStore());
        }
        if ($method === 'PATCH' && preg_match('#^/api/admin/users/(\d+)/role$#', $path, $match)) {
            $this->requireAdmin($request);
            $this->requireJson($request);
            $body = $request->json();

            return Response::json(
                (new AdminUserService($this->users, $this->mail, $this->profiles))
                    ->updateRole((int)$match[1], (string)($body['role'] ?? '')),
                200,
                self::noStore()
            );
        }

        throw new ApiException(404, 'Endpoint not found.');
    }

    /** @return array{sub: string, role: string, iat?: int, exp: int} */
    private function authenticate(Request $request): array
    {
        $authorization = $request->header('authorization');
        if ($authorization === null || !preg_match('/^Bearer\s+(.+)$/i', $authorization, $match)) {
            throw new ApiException(401, 'Authentication is required.');
        }
        $identity = $this->jwt->verify(trim($match[1]));
        $user = $this->users->findByEmail(strtolower($identity['sub']));
        if ($user === null) {
            throw new ApiException(401, 'Authentication account no longer exists.');
        }
        if ($user['approvalStatus'] !== 'APPROVED') {
            throw new ApiException(403, 'This account is not approved.', ['approvalStatus' => $user['approvalStatus']]);
        }
        $identity['role'] = $user['role'];

        return $identity;
    }

    private function requireAdmin(Request $request): array
    {
        $identity = $this->authenticate($request);
        if ($identity['role'] !== 'ADMIN') {
            throw new ApiException(403, 'Administrator access is required.');
        }

        return $identity;
    }

    /** @return array<string,mixed> */
    private function currentUser(Request $request): array
    {
        $identity = $this->authenticate($request);
        $user = $this->users->findByEmail(strtolower($identity['sub']));
        if ($user === null) {
            throw new ApiException(401, 'Authentication account no longer exists.');
        }

        return $user;
    }

    private function requireJson(Request $request): void
    {
        if ($request->contentType() !== 'application/json') {
            throw new ApiException(415, 'Content-Type must be application/json.');
        }
    }

    private function requireMultipart(Request $request): void
    {
        if ($request->contentType() !== 'multipart/form-data') {
            throw new ApiException(415, 'Content-Type must be multipart/form-data.');
        }
    }

    private function brandLogo(Request $request): Response
    {
        $path = dirname(__DIR__) . '/resources/brand/brand-logo.png';
        $bytes = is_file($path) ? file_get_contents($path) : false;
        if ($bytes === false) {
            throw new ApiException(404, 'Brand logo not found.');
        }
        $etag = self::etag($bytes);
        $cacheControl = 'public, max-age=2592000';
        if (self::etagMatches($request, $etag)) {
            return new Response(304, '', ['ETag' => $etag, 'Cache-Control' => $cacheControl]);
        }

        return new Response(200, $bytes, [
            'Content-Type' => 'image/png',
            'Content-Length' => (string) strlen($bytes),
            'Cache-Control' => $cacheControl,
            'ETag' => $etag,
        ]);
    }

    private function profileMedia(Request $request, int $userId, int $mediaId): Response
    {
        try {
            $this->marketplace->publicProfile($userId);
        } catch (ApiException $exception) {
            if ($exception->status !== 404) {
                throw $exception;
            }
            $viewer = $this->optionalCurrentUser($request);
            if ($viewer === null) {
                throw new ApiException(404, 'Media not found.');
            }
            if ((int)$viewer['id'] !== $userId && $viewer['role'] !== 'ADMIN') {
                throw new ApiException(404, 'Media not found.');
            }
        }
        $meta = $this->profileMedia->metadata($userId, $mediaId);
        if ($meta === null) {
            throw new ApiException(404, 'Media not found.');
        }
        $etag = self::etag($userId . '|' . $mediaId . '|' . $meta['size'] . '|' . $meta['createdAt']);
        $cache = 'private, no-store';
        if (self::etagMatches($request, $etag)) {
            return new Response(304, '', ['ETag' => $etag,'Cache-Control' => $cache]);
        }
        $bytes = $this->profileMedia->data($userId, $mediaId);
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
        $file = "profile-{$kind}-{$mediaId}.{$extension}";

        return new Response(200, $bytes, [
            'Content-Type' => $meta['contentType'],
            'Content-Length' => (string) strlen($bytes),
            'Content-Disposition' => 'inline; filename="' . $file . '"',
            'Cache-Control' => $cache,
            'ETag' => $etag,
        ]);
    }

    private function productImage(Request $request, int $productId, string $role): Response
    {
        $this->productService->get($productId);
        $image = $this->productImages->get($productId, $role);
        if ($image === null) {
            throw new ApiException(404, 'Product image not found.');
        }
        $etag = self::etag($productId . '|' . $role . '|' . $image['size_bytes'] . '|' . $image['updated_at']);
        $cache = 'public, max-age=3600, must-revalidate';
        if (self::etagMatches($request, $etag)) {
            return new Response(304, '', ['ETag' => $etag, 'Cache-Control' => $cache]);
        }

        return new Response(200, (string) $image['image_data'], [
            'Content-Type' => (string) $image['content_type'],
            'Content-Length' => (string) $image['size_bytes'],
            'Content-Disposition' => 'inline',
            'Cache-Control' => $cache,
            'ETag' => $etag,
        ]);
    }

    /** @return array<string,mixed>|null */
    private function optionalCurrentUser(Request $request): ?array
    {
        if ($request->header('authorization') === null) {
            return null;
        }

        try {
            return $this->currentUser($request);
        } catch (ApiException) {
            return null;
        }
    }

    private static function etag(string $value): string
    {
        return '"' . hash('sha256', $value) . '"';
    }

    private static function etagMatches(Request $request, string $etag): bool
    {
        $header = $request->header('if-none-match');
        if ($header === null) {
            return false;
        }
        foreach (explode(',', $header) as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '*' || preg_replace('/^W\//i', '', $candidate) === $etag) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, string> */
    private static function noStore(): array
    {
        return ['Cache-Control' => 'no-store'];
    }

    private static function queryString(Request $request, string $name): ?string
    {
        $value = $request->query[$name] ?? null;

        return is_scalar($value) ? (string) $value : null;
    }
}
