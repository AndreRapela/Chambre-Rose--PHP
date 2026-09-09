<?php

declare(strict_types=1);

namespace ChambreRose;

final class App
{
    private ApiRouter $router;
    private bool $booted = false;

    private function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $pdo = Database::connection();
        if (Config::bool('APP_AUTO_MIGRATE', true)) {
            (new DatabaseMigrator($pdo))->migrate();
            (new Seeder($pdo))->run();
        }

        $users = new UserRepository($pdo);
        $jwt = new Jwt();
        $profiles = new ProfessionalProfileRepository($pdo);
        $responsiveImages = new ResponsiveImageService(
            new ResponsiveImageProcessor(),
            new ResponsiveImageVariantRepository($pdo)
        );
        $profileMedia = new ProfileMediaRepository($pdo);
        $favorites = new FavoritesRepository($pdo);
        $messaging = new MessagingRepository($pdo);
        $notificationRepository = new NotificationRepository($pdo);
        $notificationOutbox = new NotificationOutboxRepository($pdo);
        $realtimeEvents = new RealtimeEventRepository($pdo);
        $pushNotifications = new PushNotificationService($notificationRepository);
        $userNotifications = new UserNotificationService(
            $notificationRepository,
            $notificationOutbox,
            $pushNotifications,
            $realtimeEvents
        );
        $passwordResets = new PasswordResetRepository($pdo);
        $mail = new MailService($pdo);
        $marketplace = new MarketplaceService($users, $profiles, $profileMedia, $responsiveImages);
        $products = new ProductRepository($pdo);
        $productImages = new ProductImageRepository($pdo, $responsiveImages);
        $productService = new ProductService($products, $productImages, $users);
        $sessionCookie = new AuthSessionCookie($jwt);
        $pushDeviceCookie = new PushDeviceCookie();
        $rateLimiter = new AuthRateLimiter($pdo);
        $guard = new ApiRequestGuard($users, $jwt, $sessionCookie);
        $auth = new AuthService($users, $jwt, $marketplace, $passwordResets, $mail, $userNotifications);

        $this->router = new ApiRouter([
            new AuthRoutes($auth, $guard, $sessionCookie, $pushDeviceCookie, $rateLimiter, $userNotifications),
            new ListingRoutes($marketplace, $profiles, $profileMedia, $favorites, $products, $guard, $userNotifications),
            new ProductRoutes($productService, $products, $productImages, $guard, $userNotifications),
            new MessagingRoutes($messaging, $users, $guard, $userNotifications, $realtimeEvents),
            new NotificationRoutes($notificationRepository, $userNotifications, $guard, $pushDeviceCookie),
            new AdminRoutes(new AdminUserService($users, $mail, $profiles), $guard, $userNotifications),
            new RealtimeRoutes($realtimeEvents, $guard),
        ]);
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

        return $this->router->dispatch($request)->withHeaders(self::commonHeaders($request));
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
        $headers['Access-Control-Allow-Methods'] = 'GET, HEAD, POST, PUT, PATCH, DELETE, OPTIONS';
        $headers['Access-Control-Allow-Headers'] = 'Authorization, Content-Type, Accept, Origin, X-Requested-With, Last-Event-ID, Range, If-Range';
        $headers['Access-Control-Expose-Headers'] = 'Accept-Ranges, Cache-Control, Content-Language, Content-Length, Content-Range, Content-Type, ETag, Retry-After';
        $headers['Access-Control-Max-Age'] = '3600';

        return $headers;
    }

    private function brandLogo(Request $request): Response
    {
        $path = dirname(__DIR__) . '/resources/brand/brand-logo-84.webp';
        $bytes = is_file($path) ? file_get_contents($path) : false;
        if ($bytes === false) {
            throw new ApiException(404, 'Brand logo not found.');
        }
        $etag = ApiResponder::etag($bytes);
        $cacheControl = 'public, max-age=2592000';
        if (ApiResponder::etagMatches($request, $etag)) {
            return new Response(304, '', ['ETag' => $etag, 'Cache-Control' => $cacheControl]);
        }

        return new Response(200, $bytes, [
            'Content-Type' => 'image/webp',
            'Content-Length' => (string) strlen($bytes),
            'Cache-Control' => $cacheControl,
            'ETag' => $etag,
        ]);
    }
}
