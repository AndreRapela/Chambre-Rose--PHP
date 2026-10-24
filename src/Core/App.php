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
        $sessionCookie = new AuthSessionCookie($jwt);
        $guard = new ApiRequestGuard($users, $jwt, $sessionCookie);

        $profiles = null;
        $getProfiles = static function () use ($pdo, &$profiles): ProfessionalProfileRepository {
            return $profiles ??= new ProfessionalProfileRepository($pdo);
        };

        $responsiveImages = null;
        $getResponsiveImages = static function () use ($pdo, &$responsiveImages): ResponsiveImageService {
            return $responsiveImages ??= new ResponsiveImageService(
                new ResponsiveImageProcessor(),
                new ResponsiveImageVariantRepository($pdo)
            );
        };

        $profileMedia = null;
        $getProfileMedia = static function () use ($pdo, &$profileMedia): ProfileMediaRepository {
            return $profileMedia ??= new ProfileMediaRepository($pdo);
        };

        $exclusions = null;
        $getExclusions = static function () use ($pdo, &$exclusions): UserExclusionRepository {
            return $exclusions ??= new UserExclusionRepository($pdo);
        };

        $messaging = null;
        $getMessaging = static function () use ($pdo, $getExclusions, &$messaging): MessagingRepository {
            return $messaging ??= new MessagingRepository($pdo, $getExclusions());
        };

        $notificationRepository = null;
        $getNotificationRepository = static function () use ($pdo, &$notificationRepository): NotificationRepository {
            return $notificationRepository ??= new NotificationRepository($pdo);
        };

        $realtimeEvents = null;
        $getRealtimeEvents = static function () use ($pdo, &$realtimeEvents): RealtimeEventRepository {
            return $realtimeEvents ??= new RealtimeEventRepository($pdo);
        };

        $nativePushNotifications = null;
        $getNativePush = static function () use ($getNotificationRepository, &$nativePushNotifications): NativePushNotificationService {
            return $nativePushNotifications ??= new NativePushNotificationService($getNotificationRepository());
        };

        $userNotifications = null;
        $getUserNotifications = static function () use ($pdo, $getNotificationRepository, $getNativePush, $getRealtimeEvents, &$userNotifications): UserNotificationService {
            if ($userNotifications === null) {
                $repo = $getNotificationRepository();
                $push = new CompositePushNotificationSender(
                    new PushNotificationService($repo),
                    $getNativePush()
                );
                $userNotifications = new UserNotificationService(
                    $repo,
                    new NotificationOutboxRepository($pdo),
                    $push,
                    $getRealtimeEvents()
                );
            }

            return $userNotifications;
        };

        $cardCovers = null;
        $getCardCovers = static function () use ($pdo, &$cardCovers): ProfileCardCoverRepository {
            return $cardCovers ??= new ProfileCardCoverRepository($pdo);
        };

        $marketplace = null;
        $getMarketplace = static function () use ($users, $getProfiles, $getProfileMedia, $getResponsiveImages, $getExclusions, $getCardCovers, &$marketplace): MarketplaceService {
            return $marketplace ??= new MarketplaceService(
                $users,
                $getProfiles(),
                $getProfileMedia(),
                $getResponsiveImages(),
                $getExclusions(),
                $getCardCovers()
            );
        };

        $products = null;
        $getProducts = static function () use ($pdo, &$products): ProductRepository {
            return $products ??= new ProductRepository($pdo);
        };

        $rateLimiter = null;
        $getRateLimiter = static function () use ($pdo, &$rateLimiter): AuthRateLimiter {
            return $rateLimiter ??= new AuthRateLimiter($pdo);
        };

        $identityVerification = null;
        $getIdentityVerification = static function () use ($pdo, &$identityVerification): IdentityVerificationService {
            return $identityVerification ??= new IdentityVerificationService(
                new IdentityVerificationRepository($pdo),
                new PrivateIdentityFileCipher()
            );
        };

        $companyVerification = null;
        $getCompanyVerification = static function () use ($pdo, &$companyVerification): CompanyVerificationService {
            return $companyVerification ??= new CompanyVerificationService(
                new CompanyVerificationRepository($pdo),
                new PrivateIdentityFileCipher()
            );
        };

        $mail = null;
        $getMail = static function () use ($pdo, &$mail): MailService {
            return $mail ??= new MailService($pdo);
        };

        $this->router = new ApiRouter([
            [['/api/openapi.json', '/api/docs', '/api/swagger'], static fn (): RouteHandler => new OpenApiRoutes()],
            [['/api/locations'], static fn (): RouteHandler => new AddressSearchRoutes($guard, $getRateLimiter())],
            [['/api/seo'], static fn (): RouteHandler => new SeoRoutes(new SeoSitemapService($pdo))],
            [['/api/promotions', '/api/admin/promotions'], static fn (): RouteHandler => new PromotionRoutes(
                new PromotionService(new PromotionRepository($pdo), $getResponsiveImages()),
                $guard
            )],
            [['/api/auth'], static fn (): RouteHandler => new AuthRoutes(
                new AuthService(
                    $users,
                    $jwt,
                    $getMarketplace(),
                    new PasswordResetRepository($pdo),
                    $getMail(),
                    $getUserNotifications(),
                    $getIdentityVerification(),
                    $getCompanyVerification()
                ),
                $guard,
                $sessionCookie,
                new PushDeviceCookie(),
                $getRateLimiter(),
                $getUserNotifications()
            )],
            [['/api/listings', '/api/profiles', '/api/favorites'], static fn (): RouteHandler => new ListingRoutes(
                $getMarketplace(),
                $getProfiles(),
                $getProfileMedia(),
                new FavoritesRepository($pdo),
                $getProducts(),
                $guard,
                $getUserNotifications()
            )],
            [['/api/profiles'], static fn (): RouteHandler => new ProfileCardCoverRoutes(
                $getCardCovers(),
                new ProfileCardCoverService($getCardCovers(), new ResponsiveImageProcessor()),
                $getMarketplace(),
                $guard
            )],
            [['/api/products'], static function () use ($pdo, $users, $guard, $getProducts, $getResponsiveImages, $getUserNotifications): RouteHandler {
                $productRepo = $getProducts();
                $productImages = new ProductImageRepository($pdo, $getResponsiveImages());

                return new ProductRoutes(
                    new ProductService($productRepo, $productImages, $users),
                    $productRepo,
                    $productImages,
                    $guard,
                    $getUserNotifications()
                );
            }],
            [['/api/conversations', '/api/users'], static fn (): RouteHandler => new MessagingRoutes(
                $getMessaging(),
                $users,
                $guard,
                $getUserNotifications(),
                $getRealtimeEvents()
            )],
            [['/api/support'], static fn (): RouteHandler => new SupportRoutes(
                $pdo,
                $getMessaging(),
                $guard,
                $getUserNotifications(),
                $getRealtimeEvents()
            )],
            [['/api/calendar'], static fn (): RouteHandler => new CalendarRoutes(
                new AppointmentRepository($pdo),
                $guard
            )],
            [['/api/notifications', '/api/notification-preferences', '/api/push-subscriptions', '/api/native-push-devices'], static fn (): RouteHandler => new NotificationRoutes(
                $getNotificationRepository(),
                $getUserNotifications(),
                $guard,
                new PushDeviceCookie(),
                $getNativePush()
            )],
            [['/api/admin/users'], static fn (): RouteHandler => new AdminRoutes(
                new AdminUserService(
                    $users,
                    $getMail(),
                    $getProfiles(),
                    $getIdentityVerification(),
                    $getCompanyVerification()
                ),
                $guard,
                $getUserNotifications()
            )],
            [['/api/admin/reports'], static fn (): RouteHandler => new AdminModerationRoutes(
                new AdminModerationService($pdo),
                $guard
            )],
            [['/api/events'], static fn (): RouteHandler => new RealtimeRoutes(
                $getRealtimeEvents(),
                $guard
            )],
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
        if ($request->method === 'GET' && in_array($request->path, ['/api/openapi.json', '/api/docs/openapi.json', '/api/docs', '/api/swagger'], true)) {
            $openApi = (new OpenApiRoutes())->handle($request);
            if ($openApi !== null) {
                return $openApi->withHeaders(self::commonHeaders($request));
            }
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
        $csp = in_array($request->path, ['/api/docs', '/api/swagger'], true)
            ? "default-src 'self' https://unpkg.com; script-src 'self' 'unsafe-inline' https://unpkg.com; style-src 'self' 'unsafe-inline' https://unpkg.com; img-src 'self' data: https:; connect-src 'self' http://localhost:8088 http://localhost:8089 http://localhost:4200; frame-ancestors 'none'"
            : "default-src 'self'; frame-ancestors 'none'";

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'X-Request-ID' => $request->requestId,
            'Referrer-Policy' => 'no-referrer',
            'Content-Security-Policy' => $csp,
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
        ];
        if (self::shouldPreventIndexing($request->path)) {
            $headers['X-Robots-Tag'] = 'noindex, nofollow, nosnippet';
        }

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

    private static function shouldPreventIndexing(string $path): bool
    {
        if (!str_starts_with($path, '/api/')) {
            return false;
        }
        if (in_array($path, ['/api/brand/logo', '/api/seo/sitemap.xml'], true)) {
            return false;
        }

        return preg_match('#^/api/(?:profiles/\d+/media/\d+(?:/\d+\.webp)?|products/\d+/images/(?:main|secondary)(?:/\d+\.webp)?)$#', $path) !== 1;
    }

    private function brandLogo(Request $request): Response
    {
        $png = ($request->query['format'] ?? '') === 'png';
        $path = dirname(__DIR__, 2) . '/resources/brand/' . ($png ? 'brand-logo.png' : 'brand-logo-84.webp');
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
            'Content-Type' => $png ? 'image/png' : 'image/webp',
            'Content-Length' => (string) strlen($bytes),
            'Cache-Control' => $cacheControl,
            'ETag' => $etag,
        ]);
    }
}
