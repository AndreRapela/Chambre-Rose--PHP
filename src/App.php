<?php

declare(strict_types=1);

namespace ChambreRose;

use PDO;

final class App
{
    private PDO $pdo;
    private ProductRepository $products;
    private UserRepository $users;
    private Jwt $jwt;
    private EstablishmentPhotoStorage $establishmentPhotos;
    private Mailer $mailer;
    private bool $booted = false;

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
        $this->products = new ProductRepository($this->pdo);
        $this->users = new UserRepository($this->pdo);
        $this->jwt = new Jwt();
        $this->establishmentPhotos = new EstablishmentPhotoStorage();
        $this->mailer = new Mailer();
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
        $this->boot();
        return $this->dispatch($request)->withHeaders(self::commonHeaders($request));
    }

    /** @return array<string, string> */
    public static function commonHeaders(Request $request): array
    {
        $headers = ['X-Content-Type-Options'=>'nosniff','X-Frame-Options'=>'DENY',
            'X-Request-ID'=>$request->requestId,'Referrer-Policy'=>'no-referrer',
            'Content-Security-Policy'=>"default-src 'self'; frame-ancestors 'none'",
            'Permissions-Policy'=>'camera=(), microphone=(), geolocation=()'];
        $origin = $request->header('origin');
        $allowed = array_values(array_filter(array_map('trim',
            explode(',', Config::get('APP_CORS_ALLOWED_ORIGINS', '') ?? ''))));
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
        $auth = new AuthService($this->users, $this->jwt, $this->establishmentPhotos, $this->mailer);
        $products = new ProductService($this->pdo, $this->products);

        if ($method === 'GET' && $path === '/api/health') {
            return Response::json(['status'=>'UP','timestamp'=>gmdate('Y-m-d\TH:i:s\Z')], 200, self::noStore());
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
            return Response::json($auth->register($form['fields'], $form['files']['establishmentPhoto'] ?? null),
                201, self::noStore());
        }
        if ($method === 'POST' && $path === '/api/auth/forgot-password') {
            $this->requireJson($request);
            $service = new AccountRecoveryService($this->users, new PasswordResetRepository($this->pdo), $this->mailer);
            return Response::json($service->forgotPassword($request->json()), 200, self::noStore());
        }
        if ($method === 'POST' && $path === '/api/auth/reset-password') {
            $this->requireJson($request);
            $service = new AccountRecoveryService($this->users, new PasswordResetRepository($this->pdo), $this->mailer);
            return Response::json($service->resetPassword($request->json()), 200, self::noStore());
        }
        if ($method === 'POST' && $path === '/api/newsletter/subscribe') {
            $this->requireJson($request);
            $service = new NewsletterService(new NewsletterRepository($this->pdo), $this->mailer);
            return Response::json($service->subscribe($request->json()), 200, self::noStore());
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
        if ($method === 'GET' && preg_match('#^/api/users/(\d+)/establishment-photo$#', $path, $match)) {
            return $this->establishmentPhoto($request, (int)$match[1]);
        }

        if ($method === 'GET' && $path === '/api/products') {
            return Response::json($this->products->all(), 200, self::noStore());
        }
        if ($method === 'GET' && $path === '/api/products/categories') {
            return Response::json($this->products->categories(), 200, self::noStore());
        }
        if ($method === 'GET' && preg_match('#^/api/products/(?:category|categorie)/([^/]+)$#', $path, $match)) {
            return Response::json($this->products->byCategory(strtolower(trim(rawurldecode($match[1])))),
                200, self::noStore());
        }
        if ($method === 'GET' && preg_match('#^/api/products/(\d+)/images/([^/]+)$#', $path, $match)) {
            return $this->productImage($request, (int)$match[1], rawurldecode($match[2]));
        }
        if ($method === 'GET' && preg_match('#^/api/products/(\d+)$#', $path, $match)) {
            $product = $this->products->find((int)$match[1]);
            if ($product === null) {
                throw new ApiException(404, 'Product not found.');
            }
            return Response::json($product, 200, self::noStore());
        }
        if ($method === 'POST' && $path === '/api/products') {
            $this->requireAdmin($request);
            if ($request->contentType() === 'application/json') {
                return Response::json($products->createJson($request->json()), 201, self::noStore());
            }
            $this->requireMultipart($request);
            $form = $request->multipart();
            return Response::json($products->createForm($form['fields'], $form['files']), 201, self::noStore());
        }
        if ($method === 'PUT' && preg_match('#^/api/products/(\d+)$#', $path, $match)) {
            $this->requireAdmin($request);
            $id = (int)$match[1];
            if ($request->contentType() === 'application/json') {
                return Response::json($products->updateJson($id, $request->json()), 200, self::noStore());
            }
            $this->requireMultipart($request);
            $form = $request->multipart();
            return Response::json($products->updateForm($id, $form['fields'], $form['files']), 200, self::noStore());
        }
        if ($method === 'POST' && preg_match('#^/api/products/(\d+)/purchases$#', $path, $match)) {
            $this->requireAdmin($request);
            return Response::json($this->products->registerPurchase((int)$match[1]), 200, self::noStore());
        }
        if ($method === 'DELETE' && preg_match('#^/api/products/(\d+)$#', $path, $match)) {
            $this->requireAdmin($request);
            $this->products->delete((int)$match[1]);
            return new Response(204, '', self::noStore());
        }

        if ($method === 'GET' && $path === '/api/admin/users') {
            $this->requireAdmin($request);
            $service = new AdminUserService($this->users, $this->mailer);
            return Response::json($service->list(self::queryString($request, 'email'),
                self::queryString($request, 'name'), self::queryString($request, 'sort') ?? 'newest'),
                200, self::noStore());
        }
        if ($method === 'PATCH' && preg_match('#^/api/admin/users/(\d+)/vip$#', $path, $match)) {
            $this->requireAdmin($request);
            $this->requireJson($request);
            return Response::json((new AdminUserService($this->users, $this->mailer))->updateVip((int)$match[1],
                Validator::vipActive($request->json())), 200, self::noStore());
        }
        if ($method === 'PATCH' && preg_match('#^/api/admin/users/(\d+)/status$#', $path, $match)) {
            $this->requireAdmin($request);
            $this->requireJson($request);
            return Response::json((new AdminUserService($this->users, $this->mailer))->updateAccountStatus(
                (int)$match[1], Validator::accountStatus($request->json())), 200, self::noStore());
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
        return $this->jwt->verify(trim($match[1]));
    }

    private function requireAdmin(Request $request): array
    {
        $identity = $this->authenticate($request);
        if ($identity['role'] !== 'ADMIN') {
            throw new ApiException(403, 'Administrator access is required.');
        }
        return $identity;
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

    private function productImage(Request $request, int $id, string $role): Response
    {
        $role = strtolower(trim($role));
        if (!in_array($role, ['main', 'secondary'], true)) {
            throw new ApiException(404, 'Product image not found.');
        }
        $metadata = $this->products->imageMetadata($id, $role);
        if ($metadata === null) {
            throw new ApiException(404, 'Product image not found.');
        }
        $etag = self::etag(implode('|', [(string)$id,$role,$metadata['fileName'],$metadata['contentType'],
            (string)$metadata['size'],$metadata['updatedAt']]));
        $cache = 'public, max-age=3600, stale-while-revalidate=86400';
        if (self::etagMatches($request, $etag)) {
            return new Response(304, '', ['ETag'=>$etag,'Cache-Control'=>$cache]);
        }
        $data = $this->products->imageData($id, $role);
        if ($data === null) {
            throw new ApiException(404, 'Product image not found.');
        }
        $fileName = str_replace(['"',"\r","\n"], '', $metadata['fileName']);
        return new Response(200, $data, ['Content-Type'=>$metadata['contentType'],'Content-Length'=>(string)strlen($data),
            'Content-Disposition'=>'inline; filename="'.$fileName.'"','Cache-Control'=>$cache,'ETag'=>$etag]);
    }

    private function brandLogo(Request $request): Response
    {
        $path = dirname(__DIR__) . '/resources/brand/brand-logo.png';
        $bytes = is_file($path) ? file_get_contents($path) : false;
        if ($bytes === false) {
            throw new ApiException(404, 'Brand logo not found.');
        }
        $etag = self::etag($bytes);
        $cache = 'public, max-age=2592000';
        if (self::etagMatches($request, $etag)) {
            return new Response(304, '', ['ETag'=>$etag,'Cache-Control'=>$cache]);
        }
        return new Response(200, $bytes, ['Content-Type'=>'image/png','Content-Length'=>(string)strlen($bytes),
            'Cache-Control'=>$cache,'ETag'=>$etag]);
    }

    private function establishmentPhoto(Request $request, int $userId): Response
    {
        $photo = $this->users->establishmentPhoto($userId);
        if ($photo === null || !$this->establishmentPhotos->exists($photo['storageKey'])) {
            throw new ApiException(404, 'Establishment photo not found.');
        }
        $etag = self::etag($userId.'|'.$photo['storageKey'].'|'.$photo['size']);
        $cache = 'public, max-age=86400';
        if (self::etagMatches($request, $etag)) {
            return new Response(304, '', ['ETag'=>$etag,'Cache-Control'=>$cache]);
        }
        $bytes = $this->establishmentPhotos->read($photo['storageKey']);
        if ($bytes === null) {
            throw new ApiException(404, 'Establishment photo not found.');
        }
        $fileName = str_replace(['"',"\r","\n"], '', $photo['fileName']);
        return new Response(200, $bytes, ['Content-Type'=>$photo['contentType'],'Content-Length'=>(string)strlen($bytes),
            'Content-Disposition'=>'inline; filename="'.$fileName.'"','Cache-Control'=>$cache,'ETag'=>$etag]);
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
        return ['Cache-Control'=>'no-store'];
    }

    private static function queryString(Request $request, string $name): ?string
    {
        $value = $request->query[$name] ?? null;
        return is_scalar($value) ? (string)$value : null;
    }
}
