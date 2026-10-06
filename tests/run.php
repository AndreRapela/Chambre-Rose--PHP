<?php

declare(strict_types=1);

putenv('JWT_SECRET=test-secret-with-more-than-thirty-two-bytes-123456');
putenv('JWT_EXPIRATION_MINUTES=5');
putenv('APP_ENV=production');

require dirname(__DIR__) . '/bootstrap.php';

use ChambreRose\ApiException;
use ChambreRose\ApiResponder;
use ChambreRose\ApiRequestGuard;
use ChambreRose\AdminModerationService;
use ChambreRose\AdminUserService;
use ChambreRose\App;
use ChambreRose\AuthSessionCookie;
use ChambreRose\CompanyVerificationRepository;
use ChambreRose\CompanyVerificationService;
use ChambreRose\HttpByteRange;
use ChambreRose\IdentityVerificationRepository;
use ChambreRose\IdentityVerificationService;
use ChambreRose\Jwt;
use ChambreRose\LegacyImageBackfill;
use ChambreRose\LocationNormalizer;
use ChambreRose\MarketplaceService;
use ChambreRose\MultipartParser;
use ChambreRose\NotificationRepository;
use ChambreRose\NotificationOutboxStore;
use ChambreRose\NotificationOutboxRepository;
use ChambreRose\NotificationDeliverySchedule;
use ChambreRose\NotificationMessageCatalog;
use ChambreRose\NotificationRetentionService;
use ChambreRose\PasswordResetRepository;
use ChambreRose\PushNotificationSender;
use ChambreRose\PushNotificationWorker;
use ChambreRose\PushDeviceCookie;
use ChambreRose\ProductImageRepository;
use ChambreRose\ProductRepository;
use ChambreRose\ProductService;
use ChambreRose\PrivateIdentityFileCipher;
use ChambreRose\ProfessionalProfileRepository;
use ChambreRose\ProfileMediaRepository;
use ChambreRose\PromotionRepository;
use ChambreRose\PromotionService;
use ChambreRose\RealtimeEventRepository;
use ChambreRose\RealtimeRoutes;
use ChambreRose\ResponsiveImageProcessor;
use ChambreRose\ResponsiveImageService;
use ChambreRose\ResponsiveImageVariantRepository;
use ChambreRose\Request;
use ChambreRose\Response;
use ChambreRose\SeoRoutes;
use ChambreRose\SeoSitemapService;
use ChambreRose\UploadedFile;
use ChambreRose\UserRepository;
use ChambreRose\UserExclusionRepository;
use ChambreRose\UserNotificationService;
use ChambreRose\Validator;
use ChambreRose\SearchPagination;
use ChambreRose\Seeder;

final class MemoryNotificationOutbox implements NotificationOutboxStore
{
    /** @var array<string, mixed> */
    private array $job;
    public string $status = 'PENDING';
    public int $recordedDelay = 0;

    public function __construct(int $maxAttempts = 2)
    {
        $this->job = [
            'id' => 1,
            'notification_id' => 10,
            'user_id' => 20,
            'attempts' => 0,
            'max_attempts' => $maxAttempts,
            'category' => 'DIRECT_MESSAGE',
            'event_type' => 'MESSAGE_RECEIVED',
            'title' => 'New message',
            'body' => 'You received a message.',
            'target_url' => '/conta/mensagens/1',
            'created_at' => '2026-09-06 12:00:00',
        ];
    }

    public function transaction(callable $operation): mixed
    {
        return $operation();
    }

    public function enqueue(int $notificationId, int $userId, int $maxAttempts, ?string $availableAt = null): void
    {
    }

    public function claim(int $limit, string $workerId, int $lockTimeoutSeconds): array
    {
        if (!in_array($this->status, ['PENDING', 'RETRY'], true)) {
            return [];
        }
        $this->status = 'PROCESSING';
        $this->job['attempts'] = (int) $this->job['attempts'] + 1;

        return [$this->job];
    }

    public function markDelivered(int $id): void
    {
        $this->status = 'DELIVERED';
    }

    public function markSkipped(int $id, string $reason): void
    {
        $this->status = 'SKIPPED';
    }

    public function recordFailure(
        int $id,
        int $attempts,
        int $maxAttempts,
        string $error,
        int $delaySeconds
    ): string {
        $this->recordedDelay = $delaySeconds;
        $this->status = $attempts >= $maxAttempts ? 'FAILED' : 'RETRY';

        return $this->status;
    }
}

final class ControlledPushSender implements PushNotificationSender
{
    public int $failuresRemaining = 1;

    public function publicKey(): ?string
    {
        return null;
    }

    public function send(int $userId, array $notification): string
    {
        if ($this->failuresRemaining > 0) {
            $this->failuresRemaining--;
            throw new RuntimeException('Temporary provider failure.');
        }

        return self::DELIVERED;
    }
}

$tests = 0;

$assert = static function (bool $condition, string $message) use (&$tests): void {
    $tests++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$assert(
    LocationNormalizer::key('  Île-de-France  ', 100) === 'ile de france'
    && LocationNormalizer::key('São   Paulo', 80) === 'sao paulo',
    'Location matching must ignore accents, punctuation and repeated whitespace.'
);
$assert(
    LocationNormalizer::countryKey('Belgique') === 'belgium'
    && in_array('brasil', LocationNormalizer::countryKeys('Brazil'), true),
    'Common translated country names must share one ranking key.'
);

if (in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    $seoDatabase = new PDO('sqlite::memory:');
    $seoDatabase->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $seoDatabase->exec(
        'CREATE TABLE users ('
        . 'id INTEGER PRIMARY KEY,role TEXT NOT NULL,approval_status TEXT NOT NULL,updated_at TEXT NOT NULL)'
    );
    $seoDatabase->exec(
        'CREATE TABLE professional_profiles ('
        . 'user_id INTEGER PRIMARY KEY,updated_at TEXT NOT NULL)'
    );
    $seoDatabase->exec(
        'CREATE TABLE products ('
        . 'id INTEGER PRIMARY KEY,is_active INTEGER NOT NULL,updated_at TEXT NOT NULL)'
    );
    $seoDatabase->exec(
        "INSERT INTO users (id,role,approval_status,updated_at) VALUES
         (4,'ESCORT','APPROVED','2026-08-03 12:00:00'),
         (5,'STORE','PENDING','2026-08-04 12:00:00'),
         (6,'VISITOR','APPROVED','2026-08-05 12:00:00')"
    );
    $seoDatabase->exec(
        "INSERT INTO professional_profiles (user_id,updated_at) VALUES
         (4,'2026-08-02 12:00:00'),(5,'2026-08-04 12:00:00'),(6,'2026-08-05 12:00:00')"
    );
    $seoDatabase->exec(
        "INSERT INTO products (id,is_active,updated_at) VALUES
         (21,1,'2026-08-06 12:00:00'),(22,0,'2026-08-07 12:00:00')"
    );

    $sitemap = new SeoSitemapService($seoDatabase, 'https://WWW.Chambre-Rose.com/');
    $sitemapXml = $sitemap->xml();
    $assert(
        str_contains($sitemapXml, '<loc>https://www.chambre-rose.com/catalogue/perfil/4</loc>')
        && str_contains($sitemapXml, '<lastmod>2026-08-03</lastmod>')
        && str_contains($sitemapXml, '<loc>https://www.chambre-rose.com/catalogue/produto/21</loc>')
        && !str_contains($sitemapXml, '/perfil/5')
        && !str_contains($sitemapXml, '/perfil/6')
        && !str_contains($sitemapXml, '/produto/22'),
        'The dynamic sitemap must expose only approved professional profiles and active products.'
    );
    $sitemapRequest = new Request('GET', '/api/seo/sitemap.xml', [], []);
    $sitemapResponse = (new SeoRoutes($sitemap))->handle($sitemapRequest);
    $assert(
        $sitemapResponse?->status === 200
        && ($sitemapResponse->headers['Content-Type'] ?? '') === 'application/xml; charset=utf-8'
        && isset($sitemapResponse->headers['ETag'])
        && ($sitemapResponse->headers['Cache-Control'] ?? '') === 'public, max-age=3600, must-revalidate',
        'The dynamic sitemap endpoint must be cacheable XML with a strong validator.'
    );
    $cachedSitemap = (new SeoRoutes($sitemap))->handle(new Request(
        'GET',
        '/api/seo/sitemap.xml',
        ['if-none-match' => (string) $sitemapResponse?->headers['ETag']],
        []
    ));
    $assert($cachedSitemap?->status === 304, 'An unchanged dynamic sitemap must support conditional requests.');
}

$memoryOutbox = new MemoryNotificationOutbox();
$controlledPush = new ControlledPushSender();
$pushWorker = new PushNotificationWorker($memoryOutbox, $controlledPush, 15, 3600);
$firstPushRun = $pushWorker->processBatch(10, 'test-worker', 120);
$assert(
    $firstPushRun['retried'] === 1
    && $memoryOutbox->status === 'RETRY'
    && $memoryOutbox->recordedDelay === 15,
    'Temporary Push failures must remain queued with exponential retry metadata.'
);
$secondPushRun = $pushWorker->processBatch(10, 'test-worker', 120);
$assert(
    $secondPushRun['delivered'] === 1 && $memoryOutbox->status === 'DELIVERED',
    'A queued Push notification must be marked delivered after a successful retry.'
);
$terminalOutbox = new MemoryNotificationOutbox(1);
$terminalRun = (new PushNotificationWorker($terminalOutbox, new ControlledPushSender(), 15, 3600))
    ->processBatch(10, 'test-worker', 120);
$assert(
    $terminalRun['failed'] === 1 && $terminalOutbox->status === 'FAILED',
    'A Push notification must retain a terminal failure after reaching its attempt limit.'
);
$assert(
    PushNotificationWorker::retryDelaySeconds(8, 15, 3600) === 1920
    && PushNotificationWorker::retryDelaySeconds(20, 15, 3600) === 3600,
    'Push retry delays must grow exponentially and respect the configured cap.'
);
$quietEnd = NotificationDeliverySchedule::afterQuietHours(
    true,
    '22:00',
    '08:00',
    'Europe/Brussels',
    new DateTimeImmutable('2026-03-28 22:30:00', new DateTimeZone('UTC'))
);
$assert(
    $quietEnd === '2026-03-29 06:00:00.000000',
    'Quiet hours must respect overnight windows and daylight-saving time.'
);
$assert(
    NotificationDeliverySchedule::afterQuietHours(
        true,
        '13:00',
        '15:00',
        'UTC',
        new DateTimeImmutable('2026-09-07 14:00:00', new DateTimeZone('UTC'))
    ) === '2026-09-07 15:00:00.000000',
    'Quiet hours must support same-day windows.'
);
$assert(
    NotificationDeliverySchedule::afterQuietHours(
        true,
        '22:00',
        '08:00',
        'UTC',
        new DateTimeImmutable('2026-09-07 12:00:00', new DateTimeZone('UTC'))
    ) === null,
    'Browser delivery must remain immediate outside quiet hours.'
);
$assert(
    NotificationDeliverySchedule::nextDailyDigest(
        '09:00',
        'UTC',
        new DateTimeImmutable('2026-09-07 10:00:00', new DateTimeZone('UTC'))
    ) === '2026-09-08 09:00:00.000000',
    'Daily summaries must be scheduled for the next selected local time.'
);
$frenchNotification = NotificationMessageCatalog::render(
    'MESSAGE_RECEIVED',
    ['senderName' => 'Alex'],
    'fr'
);
$assert(
    $frenchNotification['title'] === 'Nouveau message privé'
    && $frenchNotification['body'] === 'Vous avez reçu un message privé de Alex.',
    'Notification templates must be localized at presentation time with their stored parameters.'
);
$localizedRole = NotificationMessageCatalog::render('ROLE_CHANGED', ['role' => 'STORE'], 'pt');
$assert(
    $localizedRole['body'] === 'A função da sua conta agora é loja.',
    'Notification parameters with domain values must also be localized.'
);

$jwt = new Jwt();
$tokenPasswordHash = 'test-password-hash-v1';
$token = $jwt->generate('admin@example.com', 'ADMIN', $tokenPasswordHash);
$claims = $jwt->verify($token);
$assert($claims['sub'] === 'admin@example.com', 'JWT must preserve the subject.');
$assert($claims['role'] === 'ADMIN', 'JWT must preserve the role.');
$assert(
    $jwt->matchesPasswordHash($claims['pwd'], $tokenPasswordHash)
    && !$jwt->matchesPasswordHash($claims['pwd'], 'test-password-hash-v2'),
    'JWT credentials must become invalid when the account password hash changes.'
);

if (in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    $resetDatabase = new PDO('sqlite::memory:');
    $resetDatabase->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $resetDatabase->exec(<<<'SQL'
        CREATE TABLE password_reset_tokens (
          id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,token_hash TEXT NOT NULL,
          verification_code_hash TEXT NULL,verification_attempts INTEGER NOT NULL DEFAULT 0,
          verified_at TEXT NULL,used_at TEXT NULL,expires_at TEXT NOT NULL,created_at TEXT NOT NULL
        )
        SQL);
    $resetRepository = new PasswordResetRepository($resetDatabase);
    $resetToken = $resetRepository->issue(41);
    try {
        $resetRepository->consume($resetToken, static function (): void {
            throw new RuntimeException('Simulated password update failure.');
        });
        $assert(false, 'A failed password update must abort reset-token consumption.');
    } catch (RuntimeException) {
        $assert(true, 'A failed password update must roll back reset-token consumption.');
    }
    $assert(
        $resetRepository->consume($resetToken) === 41
        && $resetRepository->consume($resetToken) === null,
        'A reset token must remain available after rollback and become single-use after commit.'
    );
}

if (function_exists('openssl_encrypt') && in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    $identityDatabase = new PDO('sqlite::memory:');
    $identityDatabase->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $identityDatabase->exec(<<<'SQL'
        CREATE TABLE user_identity_verifications (
          user_id INTEGER PRIMARY KEY,document_type TEXT NOT NULL,document_name TEXT NOT NULL,
          document_content_type TEXT NOT NULL,document_data BLOB NOT NULL,selfie_name TEXT NOT NULL,
          selfie_content_type TEXT NOT NULL,selfie_data BLOB NOT NULL,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
        SQL);
    $identityRepository = new IdentityVerificationRepository($identityDatabase);
    $identityService = new IdentityVerificationService(
        $identityRepository,
        new PrivateIdentityFileCipher('identity-test-secret-with-more-than-thirty-two-bytes')
    );
    $identityBytes = (string) file_get_contents(dirname(__DIR__) . '/resources/brand/brand-logo.png');
    $identityFile = new UploadedFile('identity.png', 'image/png', strlen($identityBytes), null, $identityBytes);
    $identitySelfie = new UploadedFile('selfie.png', 'image/png', strlen($identityBytes), null, $identityBytes);
    $identityRegistration = $identityService->validateRegistration(
        'VISITOR',
        'PASSPORT',
        $identityFile,
        $identitySelfie
    );
    $assert(is_array($identityRegistration), 'Visitor registration must require an identity verification payload.');
    $identityService->store(44, $identityRegistration);
    $storedIdentity = $identityRepository->find(44);
    $restoredIdentity = $identityService->file(44, 'DOCUMENT');
    $identitySummary = $identityService->summariesByUserIds([44]);
    $assert(
        is_array($storedIdentity)
        && $storedIdentity['document_data'] !== $identityBytes
        && $restoredIdentity['bytes'] === $identityBytes
        && ($identitySummary[44]['documentType'] ?? null) === 'PASSPORT'
        && $identityService->validateRegistration('STORE', null, null, null) === null,
        'Private identity files must be encrypted at rest, decryptable for admin use and omitted for stores.'
    );
    try {
        $identityService->validateRegistration('ESCORT', 'IDENTITY_CARD', null, null);
        $assert(false, 'Companion registration must reject missing identity images.');
    } catch (ApiException $exception) {
        $assert(
            $exception->status === 400
            && isset($exception->fields['identityDocument'], $exception->fields['identitySelfie']),
            'Missing private identity images must return field-level validation errors.'
        );
    }

    $identityDatabase->exec(<<<'SQL'
        CREATE TABLE user_company_verifications (
          user_id INTEGER PRIMARY KEY,company_number TEXT NOT NULL,registration_name TEXT NOT NULL,
          registration_content_type TEXT NOT NULL,registration_data BLOB NOT NULL,
          created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
        SQL);
    $companyRepository = new CompanyVerificationRepository($identityDatabase);
    $companyService = new CompanyVerificationService(
        $companyRepository,
        new PrivateIdentityFileCipher('identity-test-secret-with-more-than-thirty-two-bytes')
    );
    $companyFile = new UploadedFile('company.png', 'image/png', strlen($identityBytes), null, $identityBytes);
    $companyRegistration = $companyService->validateRegistration('STORE', 'BE 0123.456.789', $companyFile);
    $assert(is_array($companyRegistration), 'Store registration must require a company verification payload.');
    $companyService->store(45, $companyRegistration);
    $storedCompany = $companyRepository->find(45);
    $restoredCompany = $companyService->file(45);
    $companySummary = $companyService->summariesByUserIds([45]);
    $assert(
        is_array($storedCompany)
        && $storedCompany['registration_data'] !== $identityBytes
        && $restoredCompany['bytes'] === $identityBytes
        && ($companySummary[45]['companyNumber'] ?? null) === 'BE 0123.456.789'
        && $companyService->validateRegistration('VISITOR', null, null) === null,
        'Private company registration must be encrypted at rest, decryptable for admin use and required only for stores.'
    );
    try {
        $companyService->validateRegistration('STORE', '', null);
        $assert(false, 'Store registration must reject missing company verification.');
    } catch (ApiException $exception) {
        $assert(
            $exception->status === 400
            && isset($exception->fields['companyNumber'], $exception->fields['companyRegistration']),
            'Missing company verification must return field-level validation errors.'
        );
    }
}
$sessionCookie = new AuthSessionCookie($jwt);
$cookieHeader = $sessionCookie->issue($token);
$assert(
    str_contains($cookieHeader, 'HttpOnly')
    && str_contains($cookieHeader, 'SameSite=Strict')
    && str_contains($cookieHeader, 'Secure')
    && str_contains($cookieHeader, 'Path=/api'),
    'Authentication cookies must use the production security attributes.'
);
$cookieRequest = new Request('GET', '/api/auth/me', [
    'cookie' => 'preference=fr; chambre_rose_session=' . rawurlencode($token),
], []);
$assert($sessionCookie->token($cookieRequest) === $token, 'Authentication cookies must be read without exposing them in JSON.');
$assert(str_contains($sessionCookie->clear(), 'Max-Age=0'), 'Signing out must expire the authentication cookie.');
$pushDeviceCookie = new PushDeviceCookie();
$pushEndpoint = 'https://push.example.test/current-device';
$pushDeviceHeader = $pushDeviceCookie->issue($pushEndpoint);
preg_match('/^([^;]+)/', $pushDeviceHeader, $pushDeviceMatch);
$pushDevicePair = (string) ($pushDeviceMatch[1] ?? '');
$pushDeviceRequest = new Request('POST', '/api/auth/logout', ['cookie' => $pushDevicePair], []);
$assert(
    $pushDeviceCookie->endpointHash($pushDeviceRequest) === hash('sha256', $pushEndpoint)
    && str_contains($pushDeviceHeader, 'HttpOnly')
    && str_contains($pushDeviceHeader, 'SameSite=Strict')
    && str_contains($pushDeviceHeader, 'Secure'),
    'Push device cookies must identify only the current browser and use secure attributes.'
);
[$pushDeviceName, $pushDeviceValue] = array_pad(explode('=', $pushDevicePair, 2), 2, '');
$lastCharacter = substr($pushDeviceValue, -1);
$tamperedValue = substr($pushDeviceValue, 0, -1) . ($lastCharacter === 'a' ? 'b' : 'a');
$tamperedDeviceRequest = new Request('POST', '/api/auth/logout', [
    'cookie' => $pushDeviceName . '=' . $tamperedValue,
], []);
$assert(
    $pushDeviceCookie->endpointHash($tamperedDeviceRequest) === null,
    'A tampered Push device cookie must not revoke a subscription.'
);
if (in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    $promotionDatabase = new PDO('sqlite::memory:');
    $promotionDatabase->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $promotionDatabase->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $promotionDatabase->exec(<<<'SQL'
        CREATE TABLE site_promotions (
          slot INTEGER PRIMARY KEY,label TEXT NOT NULL,title TEXT NOT NULL,subtitle TEXT NOT NULL,
          link_url TEXT NOT NULL,link_text TEXT NOT NULL,icon TEXT NOT NULL,image_path TEXT NULL,
          image_file_name TEXT NULL,image_content_type TEXT NULL,image_size_bytes INTEGER NULL,
          image_data BLOB NULL,updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        INSERT INTO site_promotions (slot,label,title,subtitle,link_url,link_text,icon,image_path) VALUES
          (1,'Gift edit','Gift-ready lingerie edits','Curated selection.','/catalogue','Explore gifts','gift','/assets/gift.jpg'),
          (2,'Private delivery','Boutique care','Discreet delivery.','/catalogue/lojas','Contact boutique','truck','/assets/delivery.jpg');
        SQL);
    $promotionService = new PromotionService(new PromotionRepository($promotionDatabase));
    $assert(
        count($promotionService->all()) === 2
        && $promotionService->all()[0]['imageUrl'] === '/assets/gift.jpg',
        'Public promotions must expose both configured slots with their initial images.'
    );
    $promotionImageBytes = (string) file_get_contents(dirname(__DIR__) . '/resources/brand/brand-logo.png');
    $updatedPromotion = $promotionService->update(1, [
        'label' => 'Seasonal edit',
        'title' => 'Autumn selection',
        'subtitle' => 'A refined seasonal collection.',
        'linkUrl' => '/catalogue?category=lingerie',
        'linkText' => 'Discover now',
        'icon' => 'star',
    ], new UploadedFile('season.png', 'image/png', strlen($promotionImageBytes), null, $promotionImageBytes));
    $storedPromotionImage = $promotionService->image(1);
    $assert(
        $updatedPromotion['title'] === 'Autumn selection'
        && $updatedPromotion['icon'] === 'star'
        && str_starts_with((string) $updatedPromotion['imageUrl'], '/api/promotions/1/image?v=')
        && $storedPromotionImage['contentType'] === 'image/webp'
        && str_starts_with($storedPromotionImage['bytes'], 'RIFF')
        && substr($storedPromotionImage['bytes'], 8, 4) === 'WEBP'
        && $storedPromotionImage['bytes'] !== $promotionImageBytes,
        'Promotion uploads and public responses must use metadata-free WebP while administrators can edit every field.'
    );
    try {
        $promotionService->update(2, [
            'label' => 'Unsafe', 'title' => 'Unsafe link', 'subtitle' => 'Invalid destination.',
            'linkUrl' => 'javascript:alert(1)', 'linkText' => 'Open', 'icon' => 'truck',
        ], null);
        $assert(false, 'Unsafe promotion links must be rejected.');
    } catch (ApiException $exception) {
        $assert($exception->status === 400, 'Unsafe promotion links must return a validation error.');
    }

    $seedDatabase = new PDO('sqlite::memory:');
    $seedDatabase->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $seedDatabase->exec(<<<'SQL'
        CREATE TABLE users (
          id INTEGER PRIMARY KEY, email TEXT NOT NULL, password_hash TEXT NOT NULL,
          first_name TEXT NOT NULL, last_name TEXT NOT NULL, phone TEXT NOT NULL,
          address TEXT NOT NULL, city TEXT NOT NULL, region TEXT NOT NULL, country TEXT NOT NULL, postal_code TEXT NOT NULL,
          role TEXT NOT NULL, approval_status TEXT NOT NULL, approval_reason TEXT NULL,
          review_deadline TEXT NULL, approved_at TEXT NULL, locale TEXT NOT NULL,
          vip_active INTEGER NOT NULL, vip_since TEXT NULL, vip_until TEXT NULL,
          created_at TEXT NOT NULL, updated_at TEXT NOT NULL
        );
        CREATE TABLE chambre_rose_seed_history (
          seed_key TEXT PRIMARY KEY, applied_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        INSERT INTO chambre_rose_seed_history (seed_key) VALUES
          ('mvp-marketplace-content-v1'), ('mvp-profile-diversity-v2');
        INSERT INTO users (
          id,email,password_hash,first_name,last_name,phone,address,city,region,country,postal_code,
          role,approval_status,approval_reason,review_deadline,approved_at,locale,vip_active,vip_since,vip_until,
          created_at,updated_at
        ) VALUES
          (1,'demo-companion-man@chambre-rose.invalid','old-hash','Noah','','','','Brussels','','Belgium','',
           'ESCORT','PENDING',NULL,NULL,NULL,'fr',0,NULL,NULL,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP),
          (2,'demo-companion-trans@chambre-rose.invalid','old-hash','Alexia','','','','Antwerp','','Belgium','',
           'ESCORT','PENDING',NULL,NULL,NULL,'fr',1,NULL,NULL,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP);
        SQL);
    putenv('SEED_MVP_CONTENT=false');
    putenv('SEED_DEMO_USERS=false');
    (new Seeder($seedDatabase))->run();
    $demoAccounts = $seedDatabase->query(
        "SELECT email,password_hash,approval_status FROM users WHERE email LIKE 'demo-companion-%' ORDER BY id"
    )->fetchAll(PDO::FETCH_ASSOC);
    $firstDemoHash = (string) $demoAccounts[0]['password_hash'];
    $assert(
        password_verify('ChambreRose2026!Man', $firstDemoHash)
        && password_verify('ChambreRose2026!Trans', (string) $demoAccounts[1]['password_hash']),
        'Male and trans demo companions must receive the credentials advertised by the login page.'
    );
    $assert(
        array_column($demoAccounts, 'approval_status') === ['APPROVED', 'APPROVED'],
        'Demo companion quick-login accounts must be approved.'
    );
    (new Seeder($seedDatabase))->run();
    $assert(
        $seedDatabase->query("SELECT password_hash FROM users WHERE id=1")->fetchColumn() === $firstDemoHash
        && (int) $seedDatabase->query(
            "SELECT COUNT(*) FROM chambre_rose_seed_history WHERE seed_key='mvp-profile-demo-login-v3'"
        )->fetchColumn() === 1,
        'The demo companion credential seed must be idempotent.'
    );

    $adminDatabase = new PDO('sqlite::memory:');
    $adminDatabase->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $adminDatabase->exec(<<<'SQL'
        CREATE TABLE users (
          id INTEGER PRIMARY KEY, email TEXT NOT NULL, password_hash TEXT NOT NULL,
          first_name TEXT NOT NULL, last_name TEXT NOT NULL, phone TEXT NOT NULL,
          address TEXT NOT NULL, city TEXT NOT NULL, region TEXT NOT NULL, country TEXT NOT NULL, postal_code TEXT NOT NULL,
          role TEXT NOT NULL, approval_status TEXT NOT NULL, approval_reason TEXT NULL,
          review_deadline TEXT NULL, approved_at TEXT NULL, locale TEXT NOT NULL,
          vip_active INTEGER NOT NULL, vip_since TEXT NULL, vip_until TEXT NULL,
          created_at TEXT NOT NULL, updated_at TEXT NOT NULL
        );
        CREATE TABLE professional_profiles (
          user_id INTEGER PRIMARY KEY, profile_type TEXT NOT NULL, display_name TEXT NOT NULL,
          business_name TEXT NULL, segment TEXT NULL, location TEXT NULL,
          location_city TEXT NULL, location_region TEXT NULL, location_country TEXT NULL,
          verified INTEGER NOT NULL DEFAULT 0
        );
        CREATE TABLE user_reports (
          id INTEGER PRIMARY KEY, reporter_id INTEGER NOT NULL, reported_id INTEGER NOT NULL,
          reason TEXT NOT NULL, details TEXT NULL, status TEXT NOT NULL, created_at TEXT NOT NULL
        );
        SQL);
    $adminUserInsert = $adminDatabase->prepare(
        'INSERT INTO users (id,email,password_hash,first_name,last_name,phone,address,city,region,country,'
        . 'postal_code,role,approval_status,approval_reason,review_deadline,approved_at,locale,'
        . 'vip_active,vip_since,vip_until,created_at,updated_at) VALUES '
        . '(:id,:email,:password_hash,:first_name,:last_name,:phone,:address,:city,:region,:country,'
        . ':postal_code,:role,:approval_status,NULL,NULL,NULL,:locale,0,NULL,NULL,:created_at,:updated_at)'
    );
    $adminProfileInsert = $adminDatabase->prepare(
        'INSERT INTO professional_profiles (user_id,profile_type,display_name,business_name,segment,'
        . 'location,location_city,location_region,location_country) VALUES '
        . '(:user_id,:profile_type,:display_name,NULL,:segment,NULL,:city,:region,:country)'
    );
    for ($index = 1; $index <= 30; $index++) {
        $createdAt = sprintf('2026-09-%02d 10:00:00', (($index - 1) % 28) + 1);
        $adminUserInsert->execute([
            'id' => $index,
            'email' => "professional-{$index}@example.test",
            'password_hash' => 'hash',
            'first_name' => 'Rose',
            'last_name' => (string) $index,
            'phone' => '12345678',
            'address' => '',
            'city' => 'Brussels',
            'region' => 'Brussels-Capital',
            'country' => 'Belgium',
            'postal_code' => '',
            'role' => 'ESCORT',
            'approval_status' => 'PENDING',
            'locale' => 'en',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
        $adminProfileInsert->execute([
            'user_id' => $index,
            'profile_type' => 'ESCORT',
            'display_name' => "Profile {$index}",
            'segment' => 'Massage',
            'city' => 'Brussels',
            'region' => 'Brussels-Capital',
            'country' => 'Belgium',
        ]);
    }
    $adminDatabase->exec(
        "INSERT INTO user_reports (id,reporter_id,reported_id,reason,details,status,created_at) VALUES "
        . "(1,1,2,'FAKE_PROFILE','Evidence supplied','OPEN','2026-09-11 10:00:00'),"
        . "(2,3,4,'HARASSMENT',NULL,'RESOLVED','2026-09-10 10:00:00')"
    );
    $moderation = new AdminModerationService($adminDatabase);
    $openReports = $moderation->list('open', 1, 25);
    $reviewingReport = $moderation->updateStatus(1, 'reviewing');
    $assert(
        $openReports['total'] === 1
        && $openReports['items'][0]['reported']['id'] === 2
        && $reviewingReport['status'] === 'REVIEWING',
        'Administrator moderation must filter reports and persist workflow status changes.'
    );
    try {
        $moderation->updateStatus(1, 'invalid');
        $assert(false, 'Unknown moderation states must be rejected.');
    } catch (ApiException $exception) {
        $assert($exception->status === 400, 'Unknown moderation states must return a validation error.');
    }
    $adminUsers = new UserRepository($adminDatabase);
    $adminService = new AdminUserService(
        $adminUsers,
        null,
        new ProfessionalProfileRepository($adminDatabase)
    );
    $adminPage = $adminService->list(null, null, 'newest', 'PENDING', null, 2, 10);
    $assert(
        $adminPage['page'] === 2
        && $adminPage['pageSize'] === 10
        && $adminPage['total'] === 30
        && $adminPage['totalPages'] === 3
        && count($adminPage['items']) === 10
        && isset($adminPage['items'][0]['professionalProfile']['displayName']),
        'Administrator accounts must use bounded pagination and one lightweight profile summary batch.'
    );
    $adminDatabase->exec("UPDATE users SET approval_status='APPROVED',vip_active=1 WHERE id=2");
    $publicMediaAccess = (new ProfessionalProfileRepository($adminDatabase))->findPublicMediaAccess(2);
    $publicGallery = (new ProfessionalProfileRepository($adminDatabase))->findPublicGallery(2);
    $assert(
        $publicMediaAccess === ['userId' => 2, 'type' => 'ESCORT', 'vipActive' => true],
        'Public media authorization must use the lightweight profile access projection.'
    );
    $assert(
        ($publicGallery['userId'] ?? null) === 2
        && ($publicGallery['displayName'] ?? null) === 'Profile 2'
        && ($publicGallery['type'] ?? null) === 'ESCORT'
        && ($publicGallery['vipActive'] ?? null) === true,
        'The gallery must use a lightweight public header without hydrating a complete profile.'
    );
    $adminDatabase->exec(<<<'SQL'
        CREATE TABLE profile_media (
          id INTEGER PRIMARY KEY,user_id INTEGER NOT NULL,media_type TEXT NOT NULL,file_name TEXT NOT NULL,
          content_type TEXT NOT NULL,size_bytes INTEGER NOT NULL,media_data BLOB NOT NULL,
          position INTEGER NOT NULL,created_at TEXT NOT NULL
        );
        CREATE TABLE responsive_image_variants (profile_media_id INTEGER,width INTEGER NOT NULL);
        SQL);
    $galleryMediaInsert = $adminDatabase->prepare(
        'INSERT INTO profile_media '
        . '(id,user_id,media_type,file_name,content_type,size_bytes,media_data,position,created_at) '
        . "VALUES (:id,2,:type,'fixture',:mime,1,X'01',:position,CURRENT_TIMESTAMP)"
    );
    for ($index = 1; $index <= 67; $index++) {
        $isPhoto = $index <= 65;
        $galleryMediaInsert->execute([
            'id' => $index,
            'type' => $isPhoto ? 'PHOTO' : 'VIDEO',
            'mime' => $isPhoto ? 'image/webp' : 'video/webm',
            'position' => $index,
        ]);
    }
    $galleryService = new MarketplaceService(
        $adminUsers,
        new ProfessionalProfileRepository($adminDatabase),
        new ProfileMediaRepository($adminDatabase),
        new ResponsiveImageService(new ResponsiveImageProcessor(), new ResponsiveImageVariantRepository($adminDatabase))
    );
    $galleryOwner = ['id' => 2, 'role' => 'ESCORT', 'vipActive' => true];
    $firstGalleryPage = $galleryService->publicGallery(2, $galleryOwner);
    $lastGalleryPage = $galleryService->publicGallery(2, $galleryOwner, 60, 30);
    $assert(
        array_column($firstGalleryPage['media'], 'id') === range(1, 30)
        && $firstGalleryPage['mediaTotal'] === 67
        && $firstGalleryPage['mediaPhotoCount'] === 65
        && $firstGalleryPage['mediaVideoCount'] === 2
        && $firstGalleryPage['mediaNextOffset'] === 30
        && $firstGalleryPage['mediaHasMore'] === true
        && isset($firstGalleryPage['profileImageUrl']),
        'The default gallery request must begin at the first item and return a full bounded page.'
    );
    $assert(
        array_column($lastGalleryPage['media'], 'id') === range(61, 67)
        && $lastGalleryPage['mediaNextOffset'] === null
        && $lastGalleryPage['mediaHasMore'] === false,
        'Gallery offsets must advance independently of the page size without repeating an earlier page.'
    );
    $lockedGalleryPage = $galleryService->publicGallery(2, null, 60, 30);
    $assert(
        array_column($lockedGalleryPage['media'], 'id') === range(61, 65)
        && $lockedGalleryPage['mediaLocked'] === true
        && $lockedGalleryPage['mediaTotal'] === 65
        && $lockedGalleryPage['mediaVideoCount'] === 0
        && $lockedGalleryPage['mediaNextOffset'] === null
        && str_contains($lockedGalleryPage['media'][0]['url'], '/vip-preview.webp')
        && $lockedGalleryPage['media'][0]['locked'] === true,
        'VIP gallery pagination must return only locked photo previews and never expose video entries.'
    );
    $boundedGalleryPage = $galleryService->publicGallery(2, $galleryOwner, -5, 100);
    $singleGalleryPage = $galleryService->publicGallery(2, $galleryOwner, 0, 0);
    $assert(
        array_column($boundedGalleryPage['media'], 'id') === range(1, 50)
        && $boundedGalleryPage['mediaNextOffset'] === 50
        && array_column($singleGalleryPage['media'], 'id') === [1]
        && $singleGalleryPage['mediaNextOffset'] === 1,
        'Invalid gallery bounds must clamp the limit and offset without swapping their meanings.'
    );
    $adminDatabase->exec("UPDATE users SET approval_status='PENDING',vip_active=0 WHERE id=2");
    $adminDatabase->exec("UPDATE users SET approval_status='APPROVED' WHERE id=1");
    $adminDatabase->exec("UPDATE users SET role='ADMIN' WHERE id=1");
    $approvedAdministrators = $adminUsers->approvedAdministrators();
    $assert(
        count($approvedAdministrators) === 1
        && $approvedAdministrators[0]['email'] === 'professional-1@example.test',
        'Only approved administrator accounts must receive new-registration alerts.'
    );
    $sessionToken = $jwt->generate('professional-1@example.test', 'ESCORT', 'hash');
    $sessionGuard = new ApiRequestGuard($adminUsers, $jwt, new AuthSessionCookie($jwt));
    $sessionIdentity = $sessionGuard->authenticate(new Request('GET', '/api/auth/me', [
        'authorization' => 'Bearer ' . $sessionToken,
    ], []));
    $assert(
        $sessionIdentity['sub'] === 'professional-1@example.test',
        'A session bound to the current password hash must authenticate.'
    );
    $sessionCookie = 'chambre_rose_session=' . rawurlencode($sessionToken);
    try {
        $sessionGuard->authenticate(new Request('POST', '/api/profiles/me/media', [
            'cookie' => $sessionCookie,
        ], []));
        $assert(false, 'A cookie-authenticated mutation without the anti-CSRF header must be rejected.');
    } catch (ApiException $exception) {
        $assert($exception->status === 403, 'A forged cookie mutation must return 403.');
    }
    $cookieIdentity = $sessionGuard->authenticate(new Request('POST', '/api/profiles/me/media', [
        'cookie' => $sessionCookie,
        'x-requested-with' => 'XMLHttpRequest',
    ], []));
    $assert(
        $cookieIdentity['sub'] === 'professional-1@example.test',
        'A cookie mutation carrying the anti-CSRF header must authenticate.'
    );
    $adminDatabase->exec("UPDATE users SET password_hash='changed-hash' WHERE id=1");
    try {
        $sessionGuard->authenticate(new Request('GET', '/api/auth/me', [
            'authorization' => 'Bearer ' . $sessionToken,
        ], []));
        $assert(false, 'A password change must revoke previously issued sessions.');
    } catch (ApiException $exception) {
        $assert($exception->status === 401, 'A revoked session must return 401.');
    }
    $adminService->deleteUser(2, 1);
    $assert($adminUsers->find(2) === null, 'Administrators must be able to remove another account.');
    try {
        $adminService->deleteUser(1, 1);
        $assert(false, 'An administrator must not be able to remove their own account.');
    } catch (ApiException $exception) {
        $assert($exception->status === 403, 'Self-deletion from administrator controls must be rejected.');
    }

    $productDatabase = new PDO('sqlite::memory:');
    $productDatabase->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $productDatabase->exec(<<<'SQL'
        CREATE TABLE products (
          id INTEGER PRIMARY KEY AUTOINCREMENT,store_user_id INTEGER NULL,name TEXT NOT NULL,
          category TEXT NOT NULL,price REAL NOT NULL,original_price REAL NULL,image_url TEXT NOT NULL,
          secondary_image_url TEXT NULL,tag TEXT NULL,sale_label TEXT NULL,reviews INTEGER NOT NULL,
          purchase_count INTEGER NOT NULL,likes INTEGER NOT NULL,description TEXT NULL,store_name TEXT NULL,
          store_address TEXT NULL,store_city TEXT NULL,store_segment TEXT NULL,store_hours TEXT NULL,
          product_type TEXT NULL,material TEXT NULL,available_sizes TEXT NULL,color_options TEXT NULL,
          stock_status TEXT NULL,shipping_note TEXT NULL,care_instructions TEXT NULL,is_active INTEGER NOT NULL,
          created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE product_reviews (
          id INTEGER PRIMARY KEY,product_id INTEGER NOT NULL,reviewer_name TEXT NOT NULL,
          body TEXT NOT NULL,created_at TEXT NOT NULL
        );
        CREATE TABLE product_images (
          id INTEGER PRIMARY KEY,product_id INTEGER NOT NULL,role TEXT NOT NULL,content_type TEXT NOT NULL,
          size_bytes INTEGER NOT NULL,image_data BLOB NOT NULL,updated_at TEXT NOT NULL
        );
        CREATE TABLE responsive_image_variants (
          id INTEGER PRIMARY KEY,product_image_id INTEGER NULL,width INTEGER NOT NULL
        );
        CREATE TABLE professional_profiles (
          user_id INTEGER PRIMARY KEY,purchase_count INTEGER NOT NULL DEFAULT 0,updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE marketplace_orders (
          id INTEGER PRIMARY KEY AUTOINCREMENT,buyer_user_id INTEGER NOT NULL,profile_user_id INTEGER NULL,
          order_type TEXT NOT NULL,amount REAL NULL,status TEXT NOT NULL,created_at TEXT NOT NULL
        );
        SQL);
    $productRepository = new ProductRepository($productDatabase);
    $productService = new ProductService(
        $productRepository,
        new ProductImageRepository($productDatabase),
        new UserRepository($productDatabase)
    );
    $createdProduct = $productService->save(null, 999, 'ADMIN', [
        'name' => 'Protected metrics',
        'category' => 'wellness',
        'price' => 49.90,
        'imageUrl' => '/api/products/1/images/main',
        'reviews' => 900,
        'purchaseCount' => 800,
        'likes' => 700,
    ], null, null);
    $assert(
        $createdProduct['reviews'] === 0
        && $createdProduct['purchaseCount'] === 0
        && $createdProduct['likes'] === 0,
        'New products must ignore client-supplied engagement metrics.'
    );
    $productId = (int) $createdProduct['id'];
    $productDatabase->exec(
        "INSERT INTO product_images (id,product_id,role,content_type,size_bytes,image_data,updated_at)"
        . " VALUES (51,{$productId},'MAIN','image/jpeg',4,X'01020304',CURRENT_TIMESTAMP)"
    );
    $legacyResponsiveProduct = $productRepository->find($productId);
    $assert(
        ($legacyResponsiveProduct['imageSrcSet'] ?? null) === implode(', ', [
            "/api/products/{$productId}/images/main/320.webp 320w",
            "/api/products/{$productId}/images/main/640.webp 640w",
            "/api/products/{$productId}/images/main/960.webp 960w",
            "/api/products/{$productId}/images/main/1280.webp 1280w",
        ]),
        'Legacy product uploads must request responsive WebP variants instead of downloading the original BLOB on catalogue cards.'
    );
    $productDatabase->exec(
        "UPDATE products SET reviews=3,purchase_count=4,likes=5 WHERE id={$productId}"
    );
    $updatedProduct = $productService->save($productId, 999, 'ADMIN', [
        'name' => 'Protected metrics updated',
        'reviews' => 90,
        'purchaseCount' => 80,
        'likes' => 70,
    ], null, null);
    $assert(
        $updatedProduct['reviews'] === 3
        && $updatedProduct['purchaseCount'] === 4
        && $updatedProduct['likes'] === 5,
        'Product edits must preserve server-owned engagement metrics.'
    );
    try {
        $productRepository->registerProfileSelection(7, 7);
        $assert(false, 'A member must not be able to select their own profile.');
    } catch (ApiException $exception) {
        $assert($exception->status === 403, 'Self-selection must return 403.');
    }
    $productDatabase->exec('INSERT INTO professional_profiles (user_id,purchase_count) VALUES (8,0)');
    $assert($productRepository->registerProfileSelection(8, 7), 'A member may confirm a companion selection once.');
    $assert(!$productRepository->registerProfileSelection(8, 7), 'Repeating the same profile selection must be idempotent.');
    $selectionOrder = $productDatabase->query("SELECT amount,order_type FROM marketplace_orders WHERE buyer_user_id=7")->fetch(PDO::FETCH_ASSOC);
    $selectionCount = (int) $productDatabase->query('SELECT purchase_count FROM professional_profiles WHERE user_id=8')->fetchColumn();
    $assert(
        is_array($selectionOrder) && $selectionOrder['amount'] === null && $selectionOrder['order_type'] === 'PROFILE'
        && $selectionCount === 1,
        'Profile ranking must ignore client-provided prices and count a buyer/profile pair only once.'
    );

    $notificationDatabase = new PDO('sqlite::memory:');
    $notificationDatabase->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $notificationDatabase->exec(
        'CREATE TABLE push_subscriptions ('
        . 'id INTEGER PRIMARY KEY,user_id INTEGER NOT NULL,endpoint_hash TEXT NOT NULL,endpoint TEXT NOT NULL)'
    );
    $notificationDatabase->exec(
        'CREATE TABLE native_push_devices ('
        . 'id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,token_hash TEXT NOT NULL UNIQUE,'
        . 'device_token TEXT NOT NULL,platform TEXT NOT NULL,locale TEXT NOT NULL,app_version TEXT NULL,'
        . 'failure_count INTEGER NOT NULL DEFAULT 0,last_success_at TEXT NULL,last_failure_at TEXT NULL,'
        . 'created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)'
    );
    $insertSubscription = $notificationDatabase->prepare(
        'INSERT INTO push_subscriptions (id,user_id,endpoint_hash,endpoint) VALUES (:id,:user_id,:hash,:endpoint)'
    );
    foreach (['current-device', 'mobile-device'] as $index => $device) {
        $endpoint = 'https://push.example.test/' . $device;
        $insertSubscription->execute([
            'id' => $index + 1,
            'user_id' => 7,
            'hash' => hash('sha256', $endpoint),
            'endpoint' => $endpoint,
        ]);
    }
    $deviceRepository = new NotificationRepository($notificationDatabase);
    $deviceRepository->deleteSubscriptionByHash(
        7,
        hash('sha256', 'https://push.example.test/current-device')
    );
    $remainingDevice = $notificationDatabase->query('SELECT endpoint FROM push_subscriptions')->fetchColumn();
    $assert(
        $remainingDevice === 'https://push.example.test/mobile-device',
        'Revoking the current Push device must preserve the account subscription on another device.'
    );
    $nativeToken = str_repeat('native-token-', 4);
    $deviceRepository->saveNativeDevice(7, $nativeToken, 'android', 'en', '1.0.0');
    $deviceRepository->saveNativeDevice(8, $nativeToken, 'ios', 'fr-BE', '1.0.1');
    $nativeDevices = $deviceRepository->nativeDevices(8);
    $nativeDeviceId = (int) $nativeDevices[0]['id'];
    $assert(
        count($nativeDevices) === 1
        && $nativeDevices[0]['platform'] === 'ios'
        && $nativeDevices[0]['locale'] === 'fr-BE',
        'A native token must be rebound to the current account and retain its platform metadata.'
    );
    $deviceRepository->recordNativeDeviceResult($nativeDeviceId, false);
    $deviceRepository->recordNativeDeviceResult($nativeDeviceId, true);
    $failureCount = $notificationDatabase
        ->query('SELECT failure_count FROM native_push_devices WHERE id=' . $nativeDeviceId)
        ->fetchColumn();
    $assert((int) $failureCount === 0, 'A successful native delivery must clear prior failure counts.');
    $deviceRepository->recordNativeDeviceResult($nativeDeviceId, false, true);
    $assert(
        $deviceRepository->nativeDevices(8) === [],
        'Expired native device tokens must be removed instead of retried indefinitely.'
    );

    $retentionDatabase = new PDO('sqlite::memory:');
    $retentionDatabase->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $retentionDatabase->exec('PRAGMA foreign_keys=ON');
    $retentionDatabase->exec(
        'CREATE TABLE account_notifications ('
        . 'id INTEGER PRIMARY KEY,read_at TEXT NULL,created_at TEXT NOT NULL)'
    );
    $retentionDatabase->exec(
        'CREATE TABLE push_notification_outbox ('
        . 'id INTEGER PRIMARY KEY,notification_id INTEGER NOT NULL,status TEXT NOT NULL,'
        . 'FOREIGN KEY (notification_id) REFERENCES account_notifications(id) ON DELETE CASCADE)'
    );
    $retentionInsert = $retentionDatabase->prepare(
        'INSERT INTO account_notifications (id,read_at,created_at) VALUES (:id,:read_at,:created_at)'
    );
    $retentionDates = [
        1 => ['read_at' => '-100 days', 'created_at' => '-120 days'],
        2 => ['read_at' => null, 'created_at' => '-400 days'],
        3 => ['read_at' => '-5 days', 'created_at' => '-10 days'],
        4 => ['read_at' => null, 'created_at' => '-120 days'],
        5 => ['read_at' => '-100 days', 'created_at' => '-120 days'],
        6 => ['read_at' => '-100 days', 'created_at' => '-120 days'],
        7 => ['read_at' => '-100 days', 'created_at' => '-120 days'],
        8 => ['read_at' => '-100 days', 'created_at' => '-120 days'],
        9 => ['read_at' => '-100 days', 'created_at' => '-120 days'],
    ];
    foreach ($retentionDates as $id => $dates) {
        $retentionInsert->execute([
            'id' => $id,
            'read_at' => $dates['read_at'] === null ? null : gmdate('Y-m-d H:i:s', strtotime($dates['read_at'])),
            'created_at' => gmdate('Y-m-d H:i:s', strtotime($dates['created_at'])),
        ]);
    }
    $retentionDatabase->exec(
        "INSERT INTO push_notification_outbox (id,notification_id,status) VALUES "
        . "(1,5,'PENDING'),(2,6,'DELIVERED'),(3,7,'RETRY'),(4,8,'PROCESSING'),(5,9,'FAILED')"
    );
    $retention = new NotificationRetentionService($retentionDatabase);
    $firstRetentionBatch = $retention->purgeBatch(90, 365, 2);
    $secondRetentionBatch = $retention->purgeBatch(90, 365, 2);
    $remainingNotifications = $retentionDatabase
        ->query('SELECT id FROM account_notifications ORDER BY id')
        ->fetchAll(PDO::FETCH_COLUMN);
    $assert($firstRetentionBatch === 2, 'Notification retention must respect its configured batch size.');
    $assert($secondRetentionBatch === 2, 'Notification retention must continue draining eligible history.');
    $assert(
        $remainingNotifications === [3, 4, 5, 7, 8],
        'Retention must keep recent, unread-within-policy, and actively queued notifications.'
    );
    $assert(
        (int) $retentionDatabase->query('SELECT COUNT(*) FROM push_notification_outbox')->fetchColumn() === 3,
        'Deleting notification history must cascade terminal outbox entries while preserving pending delivery.'
    );

    $preferenceDatabase = new PDO('sqlite::memory:');
    $preferenceDatabase->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $preferenceDatabase->exec(<<<'SQL'
        CREATE TABLE notification_preferences (
          user_id INTEGER PRIMARY KEY,direct_messages INTEGER NOT NULL,account_updates INTEGER NOT NULL,
          marketplace_updates INTEGER NOT NULL,security_updates INTEGER NOT NULL,browser_notifications INTEGER NOT NULL,
          in_app_notifications INTEGER NOT NULL,only_direct_messages INTEGER NOT NULL,daily_digest INTEGER NOT NULL,
          daily_digest_time TEXT NOT NULL,quiet_hours_enabled INTEGER NOT NULL,quiet_hours_start TEXT NOT NULL,
          quiet_hours_end TEXT NOT NULL,timezone TEXT NOT NULL,updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE account_notifications (
          id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,category TEXT NOT NULL,event_type TEXT NOT NULL,
          title TEXT NULL,body TEXT NULL,message_params TEXT NULL,target_url TEXT NOT NULL,dedupe_key TEXT NULL,
          visible_in_app INTEGER NOT NULL DEFAULT 1,read_at TEXT NULL,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
          UNIQUE(user_id,dedupe_key)
        );
        CREATE TABLE push_notification_outbox (
          id INTEGER PRIMARY KEY AUTOINCREMENT,notification_id INTEGER NOT NULL UNIQUE,user_id INTEGER NOT NULL,
          status TEXT NOT NULL DEFAULT 'PENDING',attempts INTEGER NOT NULL DEFAULT 0,max_attempts INTEGER NOT NULL,
          available_at TEXT NOT NULL,locked_at TEXT NULL,locked_by TEXT NULL,last_error TEXT NULL,delivered_at TEXT NULL,
          failed_at TEXT NULL,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE realtime_events (
          id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,event_type TEXT NOT NULL,
          resource_id INTEGER NULL,payload TEXT NOT NULL,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        SQL);
    $preferenceRepository = new NotificationRepository($preferenceDatabase);
    $notificationPreferences = [
        'directMessages' => true,
        'accountUpdates' => true,
        'marketplaceUpdates' => true,
        'securityUpdates' => true,
        'browserNotifications' => true,
        'inAppNotifications' => true,
        'onlyDirectMessages' => false,
        'dailyDigest' => true,
        'dailyDigestTime' => gmdate('H:i', time() + 3600),
        'quietHoursEnabled' => false,
        'quietHoursStart' => '22:00',
        'quietHoursEnd' => '08:00',
        'timezone' => 'UTC',
    ];
    $preferenceRepository->savePreferences(91, $notificationPreferences);
    $notificationService = new UserNotificationService(
        $preferenceRepository,
        new NotificationOutboxRepository($preferenceDatabase),
        new ControlledPushSender(),
        new RealtimeEventRepository($preferenceDatabase)
    );
    $notificationService->notify(
        91,
        UserNotificationService::ACCOUNT,
        'PROFILE_UPDATED',
        '/conta/perfil',
        'preferences:account'
    );
    $digestFeed = $preferenceRepository->feed(91);
    $assert(
        count($digestFeed['items']) === 1
        && (int) $preferenceDatabase->query('SELECT COUNT(*) FROM account_notifications')->fetchColumn() === 2
        && (int) $preferenceDatabase->query('SELECT COUNT(*) FROM push_notification_outbox')->fetchColumn() === 1,
        'Daily summary mode must keep the in-app event but enqueue only one hidden browser digest.'
    );

    $notificationPreferences['onlyDirectMessages'] = true;
    $preferenceRepository->savePreferences(91, $notificationPreferences);
    $suppressed = $notificationService->notify(
        91,
        UserNotificationService::MARKETPLACE,
        'PROFILE_FAVORITED',
        '/conta/favoritos',
        'preferences:suppressed'
    );
    $assert(
        ($suppressed['suppressed'] ?? false) === true
        && (int) $preferenceDatabase->query('SELECT COUNT(*) FROM account_notifications')->fetchColumn() === 2,
        'Direct-messages-only mode must suppress optional marketplace notifications.'
    );

    $requiredNotification = $notificationService->notify(
        91,
        UserNotificationService::ACCOUNT,
        'PROFILE_UPDATED',
        '/conta/perfil',
        'preferences:required'
    );
    $assert(
        ($requiredNotification['suppressed'] ?? false) !== true
        && (int) $preferenceDatabase->query('SELECT COUNT(*) FROM account_notifications')->fetchColumn() === 3,
        'Direct-messages-only mode must keep mandatory account notifications active.'
    );

    $notificationPreferences['onlyDirectMessages'] = false;
    $notificationPreferences['dailyDigest'] = false;
    $notificationPreferences['browserNotifications'] = false;
    $preferenceRepository->savePreferences(91, $notificationPreferences);
    $notificationService->notify(
        91,
        UserNotificationService::DIRECT_MESSAGE,
        'MESSAGE_RECEIVED',
        '/conta/mensagens/10',
        'preferences:in-app',
        ['senderName' => 'Camille']
    );
    $inAppFeed = $preferenceRepository->feed(91);
    $assert(
        count($inAppFeed['items']) === 3
        && ($inAppFeed['items'][0]['params']['senderName'] ?? null) === 'Camille'
        && (int) $preferenceDatabase->query('SELECT COUNT(*) FROM push_notification_outbox')->fetchColumn() === 1,
        'In-app-only mode must expose structured message parameters without enqueueing browser Push.'
    );

    $notificationPreferences['browserNotifications'] = true;
    $notificationPreferences['inAppNotifications'] = false;
    $preferenceRepository->savePreferences(91, $notificationPreferences);
    $notificationService->notify(
        91,
        UserNotificationService::DIRECT_MESSAGE,
        'MESSAGE_RECEIVED',
        '/conta/mensagens/11',
        'preferences:browser',
        ['senderName' => 'Morgan']
    );
    $assert(
        count($preferenceRepository->feed(91)['items']) === 3
        && (int) $preferenceDatabase->query('SELECT COUNT(*) FROM push_notification_outbox')->fetchColumn() === 2,
        'Browser-only mode must enqueue Push without exposing the event in the in-app feed.'
    );

    $productImageDatabase = new PDO('sqlite::memory:');
    $productImageDatabase->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $productImageDatabase->exec(
        'CREATE TABLE product_images ('
        . 'id INTEGER PRIMARY KEY,product_id INTEGER NOT NULL,role TEXT NOT NULL,'
        . 'content_type TEXT NOT NULL,size_bytes INTEGER NOT NULL,image_data BLOB NOT NULL,updated_at TEXT NOT NULL)'
    );
    $productImageDatabase->exec(
        "INSERT INTO product_images (id,product_id,role,content_type,size_bytes,image_data,updated_at) "
        . "VALUES (1,1,'MAIN','image/svg+xml',6,'<svg/>','2026-09-07 00:00:00')"
    );
    $legacyImage = (new ProductImageRepository($productImageDatabase))->get(1, 'MAIN');
    $assert(
        ($legacyImage['content_type'] ?? null) === 'image/jpeg'
        && (int) ($legacyImage['size_bytes'] ?? 0) > 10_000
        && str_starts_with((string) ($legacyImage['image_data'] ?? ''), "\xFF\xD8\xFF"),
        'Legacy SVG product placeholders must be served as real JPEG catalog photos.'
    );
}

$tampered = substr($token, 0, -1) . (str_ends_with($token, 'a') ? 'b' : 'a');

try {
    $jwt->verify($tampered);
    $assert(false, 'A tampered JWT must fail.');
} catch (ApiException $exception) {
    $assert($exception->status === 401, 'A tampered JWT must return 401.');
}

$login = Validator::login(['email' => ' Test@Example.com ', 'password' => '123456']);
$assert(Validator::registrationEmail(['email' => ' Test@Example.com ']) === 'test@example.com', 'Email preflight must trim and normalize the address.');
try {
    Validator::registrationEmail(['email' => 'bad@example']);
    $assert(false, 'Email preflight must reject an incomplete domain.');
} catch (ApiException $exception) {
    $assert($exception->status === 400 && isset($exception->fields['email']), 'Email preflight must identify the malformed field.');
}
$assert($login['email'] === 'Test@Example.com', 'Login validator must trim email.');
$invalidEmails = ['person@example', 'person @example.com', 'person..name@example.com', 'person@example..com'];
foreach ($invalidEmails as $invalidEmail) {
    try {
        Validator::login(['email' => $invalidEmail, 'password' => '123456']);
        $assert(false, "Malformed email {$invalidEmail} must be rejected.");
    } catch (ApiException $exception) {
        $assert(isset($exception->fields['email']), "Malformed email {$invalidEmail} must report the email field.");
    }
}
$assert(Validator::accountType([]) === 'VISITOR', 'Visitor must be the default account type.');
$assert(Validator::accountType(['accountType' => 'user']) === 'VISITOR', 'Legacy USER must map to VISITOR.');
$assert(Validator::accountType(['accountType' => 'escort']) === 'ESCORT', 'Escort account type must be supported.');
$assert(Validator::locale(['locale' => 'pt-BR']) === 'pt', 'Locales must be normalized.');

try {
    Validator::accountType(['accountType' => 'ROOT']);
    $assert(false, 'Unknown account types must fail.');
} catch (ApiException $exception) {
    $assert($exception->status === 400 && isset($exception->fields['accountType']), 'Account type errors must be explicit.');
}

try {
    Validator::register([]);
    $assert(false, 'Missing registration fields must fail.');
} catch (ApiException $exception) {
    $assert($exception->status === 400 && isset($exception->fields['email']), 'Registration must report field errors.');
}

try {
    Validator::register([
        'firstName' => 'Ana', 'lastName' => 'Silva', 'email' => 'ana@example.com',
        'phone' => '+33 6 12 34 56 78', 'password' => 'Strong9!pass',
        'city' => 'Paris', 'country' => 'France',
    ], true, 'ESCORT');
    $assert(false, 'Companion registration without an exact private address must fail.');
} catch (ApiException $exception) {
    $assert(isset($exception->fields['address']), 'Companion address validation must report the address field.');
}

$privateLocation = Validator::location([
    'address' => '  Rue de Rivoli  33 ', 'city' => ' Paris ', 'region' => ' Île-de-France ',
    'country' => ' France ', 'postalCode' => ' 75001 ',
], 'ESCORT');
$assert(
    $privateLocation['address'] === 'Rue de Rivoli 33'
    && $privateLocation['city'] === 'Paris'
    && $privateLocation['region'] === 'Île-de-France',
    'Private locations must be normalized before they are stored.'
);

try {
    Validator::register([
        'firstName' => 'Ana', 'lastName' => 'Silva', 'email' => 'ana@example.com',
        'phone' => '12345', 'password' => 'Strong9!pass',
    ]);
    $assert(false, 'A short phone number must fail registration.');
} catch (ApiException $exception) {
    $assert(
        ($exception->fields['phone'] ?? null) === 'Enter a phone number with 8 to 15 digits.',
        'Registration must explain the required phone digit count.'
    );
}

try {
    Validator::register([
        'firstName' => 'Ana', 'lastName' => 'Silva', 'email' => 'ana@example.com',
        'phone' => 'call-me-now', 'password' => 'Strong9!pass',
    ]);
    $assert(false, 'Letters must not be accepted as a phone number.');
} catch (ApiException $exception) {
    $assert(
        ($exception->fields['phone'] ?? null) === 'Use only numbers and common phone symbols.',
        'Registration must explain an unsupported phone format.'
    );
}

$visitor = Validator::register([
    'firstName' => 'Ana', 'lastName' => 'Silva', 'email' => 'ana@example.com',
    'phone' => '12345678', 'password' => 'Strong9!pass',
]);
$assert($visitor['address'] === '' && $visitor['city'] === '', 'Visitor address fields must remain optional.');

$boundary = 'test-boundary';
$body = "--{$boundary}\r\n"
    . "Content-Disposition: form-data; name=\"name\"\r\n\r\nProduct\r\n"
    . "--{$boundary}\r\n"
    . "Content-Disposition: form-data; name=\"mainImage\"; filename=\"image.png\"\r\n"
    . "Content-Type: image/png\r\n\r\nPNGDATA\r\n"
    . "--{$boundary}--\r\n";
$multipart = MultipartParser::parse($body, "multipart/form-data; boundary={$boundary}");
$assert($multipart['fields']['name'] === 'Product', 'Multipart parser must read fields.');
$assert($multipart['files']['mainImage']->bytes() === 'PNGDATA', 'Multipart parser must preserve file bytes.');

$png = base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
    true
);
$assert(is_string($png), 'PNG fixture must be valid base64.');
$image = new UploadedFile('pixel.png', 'text/plain', strlen($png), null, $png);
$assert($image->detectedContentType() === 'image/png', 'Uploaded image type must come from its bytes.');
$assert($image->actualSize() === strlen($png), 'Uploaded image size must come from its bytes.');

$imageProcessor = new ResponsiveImageProcessor();
$tinyVariants = $imageProcessor->generate($png);
$wideSource = imagecreatetruecolor(800, 400);
$assert($wideSource instanceof GdImage, 'Responsive image fixture must be allocated.');
imagefilledrectangle($wideSource, 0, 0, 799, 399, imagecolorallocate($wideSource, 157, 32, 73));
ob_start();
imagepng($wideSource);
$widePng = ob_get_clean();
unset($wideSource);
$assert(is_string($widePng), 'Responsive image fixture must be encoded.');
$sanitizedPixel = $imageProcessor->sanitize($png);
$sanitizedPixelInfo = getimagesizefromstring($sanitizedPixel);
$assert(
    is_array($sanitizedPixelInfo)
    && strtolower((string) ($sanitizedPixelInfo['mime'] ?? '')) === 'image/webp'
    && str_starts_with($sanitizedPixel, 'RIFF')
    && strpos($sanitizedPixel, 'EXIF') === false,
    'Sanitized originals must be metadata-free WebP images, including very small uploads.'
);
$orientedSource = imagecreatetruecolor(20, 10);
$assert($orientedSource instanceof GdImage, 'EXIF orientation fixture must be allocated.');
imagefilledrectangle($orientedSource, 0, 0, 19, 9, imagecolorallocate($orientedSource, 220, 20, 60));
ob_start();
imagejpeg($orientedSource, null, 90);
$orientedJpeg = ob_get_clean();
unset($orientedSource);
$assert(is_string($orientedJpeg), 'EXIF orientation fixture must be encoded as JPEG.');
$exifTiff = 'II' . pack('v', 42) . pack('V', 8) . pack('v', 1)
    . pack('v', 0x0112) . pack('v', 3) . pack('V', 1) . pack('v', 6) . pack('v', 0) . pack('V', 0);
$exifPayload = "Exif\0\0" . $exifTiff;
$orientedJpegWithExif = substr($orientedJpeg, 0, 2)
    . "\xFF\xE1" . pack('n', strlen($exifPayload) + 2) . $exifPayload
    . substr($orientedJpeg, 2);
$orientedWebp = $imageProcessor->sanitize($orientedJpegWithExif);
$orientedWebpInfo = getimagesizefromstring($orientedWebp);
$assert(
    is_array($orientedWebpInfo)
    && $orientedWebpInfo[0] === 10
    && $orientedWebpInfo[1] === 20
    && strpos($orientedWebp, 'EXIF') === false,
    'JPEG orientation must be applied before EXIF is removed from sanitized originals.'
);
$responsiveVariants = $imageProcessor->generate($widePng);
$blurredPreview = $imageProcessor->blurredPreview($widePng);
$assert(
    $tinyVariants === []
    && array_column($responsiveVariants, 'width') === [320, 640]
    && array_reduce(
        $responsiveVariants,
        static fn (bool $valid, array $variant): bool => $valid
            && $variant['contentType'] === 'image/webp'
            && str_starts_with($variant['bytes'], 'RIFF')
            && substr($variant['bytes'], 8, 4) === 'WEBP',
        true
    ),
    'Responsive photos must create valid WebP variants without enlarging their source.'
);
$assert(
    $blurredPreview['width'] === 160
    && $blurredPreview['height'] === 80
    && $blurredPreview['contentType'] === 'image/webp'
    && $blurredPreview['size'] === strlen($blurredPreview['bytes'])
    && str_starts_with($blurredPreview['bytes'], 'RIFF')
    && substr($blurredPreview['bytes'], 8, 4) === 'WEBP'
    && $blurredPreview['bytes'] !== $widePng,
    'VIP previews must be reduced, blurred WebP representations instead of original photo bytes.'
);
$assert(
    ResponsiveImageService::srcSet('/api/profiles/9/media/2', [640, 320])
        === '/api/profiles/9/media/2/320.webp 320w, /api/profiles/9/media/2/640.webp 640w',
    'Responsive image metadata must expose only available, ordered width descriptors.'
);
$assert(
    ResponsiveImageService::srcSet('/api/profiles/9/media/2')
        === '/api/profiles/9/media/2/320.webp 320w, /api/profiles/9/media/2/640.webp 640w, /api/profiles/9/media/2/960.webp 960w, /api/profiles/9/media/2/1280.webp 1280w',
    'Legacy photos without stored variants must still advertise lazy-generated responsive candidates.'
);
$assert(
    ResponsiveImageService::srcSet('/api/profiles/9/media/2?v=media-v3', [320, 640])
        === '/api/profiles/9/media/2/320.webp?v=media-v3 320w, /api/profiles/9/media/2/640.webp?v=media-v3 640w',
    'Versioned responsive URLs must preserve cache-busting query strings after the image variant path.'
);

$closedRange = HttpByteRange::parse('bytes=2-5', 10);
$assert(
    $closedRange?->start === 2 && $closedRange->end === 5 && $closedRange->length() === 4,
    'Closed HTTP byte ranges must preserve their inclusive boundaries.'
);
$openRange = HttpByteRange::parse('bytes=7-', 10);
$assert(
    $openRange?->start === 7 && $openRange->end === 9,
    'Open HTTP byte ranges must extend to the final representation byte.'
);
$suffixRange = HttpByteRange::parse('bytes=-3', 10);
$assert(
    $suffixRange?->start === 7 && $suffixRange->end === 9,
    'Suffix HTTP byte ranges must select bytes from the end of the representation.'
);
$clampedRange = HttpByteRange::parse('bytes=8-99', 10);
$assert(
    $clampedRange?->start === 8 && $clampedRange->end === 9,
    'HTTP byte range ends beyond the representation must be clamped.'
);
$assert(HttpByteRange::parse(null, 10) === null, 'Requests without Range must select the full representation.');
try {
    HttpByteRange::parse('bytes=10-', 10);
    $assert(false, 'Unsatisfiable HTTP byte ranges must be rejected.');
} catch (ApiException $exception) {
    $assert(
        $exception->status === 416
        && ($exception->headers['Accept-Ranges'] ?? null) === 'bytes'
        && ($exception->headers['Content-Range'] ?? null) === 'bytes */10',
        'Unsatisfiable HTTP byte ranges must return the representation size with status 416.'
    );
}
try {
    HttpByteRange::parse('bytes=0-1,4-5', 10);
    $assert(false, 'Multiple ranges must be rejected until multipart responses are supported.');
} catch (ApiException $exception) {
    $assert($exception->status === 416, 'Unsupported multiple HTTP ranges must return 416.');
}

if (in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    $responsiveImageDatabase = new PDO('sqlite::memory:');
    $responsiveImageDatabase->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $responsiveImageDatabase->exec(
        'CREATE TABLE product_images ('
        . 'id INTEGER PRIMARY KEY AUTOINCREMENT,product_id INTEGER NOT NULL,role TEXT NOT NULL,'
        . 'file_name TEXT NOT NULL,content_type TEXT NOT NULL,size_bytes INTEGER NOT NULL,image_data BLOB NOT NULL,'
        . 'created_at TEXT NOT NULL,updated_at TEXT NOT NULL,UNIQUE(product_id,role))'
    );
    $responsiveImageDatabase->exec(
        'CREATE TABLE responsive_image_variants ('
        . 'id INTEGER PRIMARY KEY AUTOINCREMENT,profile_media_id INTEGER,product_image_id INTEGER,'
        . 'width INTEGER NOT NULL,height INTEGER NOT NULL,content_type TEXT NOT NULL,size_bytes INTEGER NOT NULL,'
        . 'image_data BLOB NOT NULL,source_hash TEXT NOT NULL,created_at TEXT NOT NULL,updated_at TEXT NOT NULL,'
        . 'UNIQUE(profile_media_id,width),UNIQUE(product_image_id,width))'
    );
    $responsiveImageService = new ResponsiveImageService(
        $imageProcessor,
        new ResponsiveImageVariantRepository($responsiveImageDatabase)
    );
    $responsiveProductImages = new ProductImageRepository($responsiveImageDatabase, $responsiveImageService);
    $responsiveImage = new UploadedFile('wide.png', 'image/png', strlen($widePng), null, $widePng);
    $responsiveProductImages->put(44, 'MAIN', $responsiveImage);
    $storedProductVariant = $responsiveProductImages->responsive(44, 'MAIN', 320);
    $assert(
        $storedProductVariant['width'] === 320
        && $storedProductVariant['contentType'] === 'image/webp'
        && str_starts_with($storedProductVariant['bytes'], 'RIFF')
        && (int) $responsiveImageDatabase->query('SELECT COUNT(*) FROM responsive_image_variants')->fetchColumn() === 2,
        'Product uploads must atomically persist only non-upscaled responsive WebP variants.'
    );
    $profileVariants = $imageProcessor->generate($widePng);
    $profileVariants[] = $responsiveImageService->prepareVipPreview($widePng);
    $responsiveImageService->storePrepared('PROFILE', 77, $widePng, $profileVariants);
    $storedVipPreview = $responsiveImageService->cachedVipPreview('PROFILE', 77);
    $assert(
        ($storedVipPreview['width'] ?? null) === ResponsiveImageService::VIP_PREVIEW_WIDTH
        && ($storedVipPreview['contentType'] ?? null) === 'image/webp'
        && str_starts_with((string) ($storedVipPreview['bytes'] ?? ''), 'RIFF'),
        'VIP blur previews must be persisted as small WebP variants instead of recalculated for each visit.'
    );
    $legacyLargeRequest = $responsiveImageService->variant('PROFILE', 78, $widePng, 1280, true);
    $legacyVariantCount = (int) $responsiveImageDatabase->query(
        'SELECT COUNT(*) FROM responsive_image_variants WHERE profile_media_id=78'
    )->fetchColumn();
    $reusedLegacyLargeRequest = $responsiveImageService->variant('PROFILE', 78, $widePng, 1280, true);
    $assert(
        $legacyLargeRequest['width'] === 640
        && $legacyLargeRequest['contentType'] === 'image/webp'
        && $reusedLegacyLargeRequest['bytes'] === $legacyLargeRequest['bytes']
        && $legacyVariantCount === 2
        && (int) $responsiveImageDatabase->query(
            'SELECT COUNT(*) FROM responsive_image_variants WHERE profile_media_id=78'
        )->fetchColumn() === $legacyVariantCount,
        'Legacy profile images must generate and reuse the largest available non-upscaled WebP instead of failing oversized candidates.'
    );
    $tinyProfileFallback = $responsiveImageService->variant('PROFILE', 79, $png, 320, true);
    $assert(
        $tinyProfileFallback['width'] === 1
        && $tinyProfileFallback['contentType'] === 'image/webp'
        && str_starts_with($tinyProfileFallback['bytes'], 'RIFF')
        && $tinyProfileFallback['bytes'] !== $png,
        'Very small legacy photos must be sanitized as WebP when no responsive width can be generated.'
    );
    try {
        $responsiveProductImages->responsive(44, 'MAIN', 960);
        $assert(false, 'A responsive endpoint must not enlarge an 800-pixel source to 960 pixels.');
    } catch (ApiException $exception) {
        $assert(
            $exception->status === 404
            && (int) $responsiveImageDatabase->query(
                'SELECT COUNT(*) FROM responsive_image_variants WHERE product_image_id IS NOT NULL'
            )->fetchColumn() === 2,
            'Unavailable oversized variants must return 404 without creating an upscaled file.'
        );
    }

    $legacyBackfillDatabase = new PDO('sqlite::memory:');
    $legacyBackfillDatabase->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $legacyBackfillDatabase->exec(
        'CREATE TABLE profile_media ('
        . 'id INTEGER PRIMARY KEY,media_type TEXT NOT NULL,content_type TEXT,size_bytes INTEGER NOT NULL,'
        . 'media_data BLOB NOT NULL)'
    );
    $legacyBackfillDatabase->exec(
        'CREATE TABLE product_images ('
        . 'id INTEGER PRIMARY KEY,content_type TEXT,size_bytes INTEGER NOT NULL,image_data BLOB NOT NULL,'
        . 'updated_at TEXT NOT NULL)'
    );
    $legacyBackfillDatabase->exec(
        'CREATE TABLE site_promotions ('
        . 'slot INTEGER PRIMARY KEY,image_content_type TEXT,image_size_bytes INTEGER,image_data BLOB,'
        . 'updated_at TEXT NOT NULL)'
    );
    $legacyBackfillDatabase->exec(
        'CREATE TABLE responsive_image_variants ('
        . 'id INTEGER PRIMARY KEY AUTOINCREMENT,profile_media_id INTEGER,product_image_id INTEGER,'
        . 'width INTEGER NOT NULL,height INTEGER NOT NULL,content_type TEXT NOT NULL,size_bytes INTEGER NOT NULL,'
        . 'image_data BLOB NOT NULL,source_hash TEXT NOT NULL,created_at TEXT NOT NULL,updated_at TEXT NOT NULL,'
        . 'UNIQUE(profile_media_id,width),UNIQUE(product_image_id,width))'
    );
    $legacyWebp = $imageProcessor->sanitize($widePng);
    $legacyBackfillDatabase->prepare(
        'INSERT INTO profile_media (id,media_type,content_type,size_bytes,media_data) VALUES (1,\'PHOTO\',\'image/png\',:size,:data)'
    )->execute(['size' => strlen($widePng), 'data' => $widePng]);
    $legacyBackfillDatabase->prepare(
        'INSERT INTO profile_media (id,media_type,content_type,size_bytes,media_data) VALUES (2,\'VIDEO\',\'video/webm\',:size,:data)'
    )->execute(['size' => 8, 'data' => 'video001']);
    $legacyBackfillDatabase->prepare(
        'INSERT INTO profile_media (id,media_type,content_type,size_bytes,media_data) VALUES (3,\'PHOTO\',\'image/webp\',:size,:data)'
    )->execute(['size' => strlen($legacyWebp), 'data' => $legacyWebp]);
    $legacyBackfillDatabase->prepare(
        'INSERT INTO profile_media (id,media_type,content_type,size_bytes,media_data) VALUES (4,\'PHOTO\',\'image/svg+xml\',:size,:data)'
    )->execute(['size' => 11, 'data' => '<svg></svg>']);
    $legacyBackfillDatabase->prepare(
        'INSERT INTO product_images (id,content_type,size_bytes,image_data,updated_at) VALUES (1,\'image/png\',:size,:data,\'2026-09-01\')'
    )->execute(['size' => strlen($widePng), 'data' => $widePng]);
    $legacyBackfillDatabase->prepare(
        'INSERT INTO product_images (id,content_type,size_bytes,image_data,updated_at) VALUES (2,\'image/webp\',:size,:data,\'2026-09-01\')'
    )->execute(['size' => strlen($legacyWebp), 'data' => $legacyWebp]);
    $legacyBackfillDatabase->prepare(
        'INSERT INTO site_promotions (slot,image_content_type,image_size_bytes,image_data,updated_at) VALUES (1,\'image/png\',:size,:data,\'2026-09-01\')'
    )->execute(['size' => strlen($widePng), 'data' => $widePng]);
    $legacyBackfillDatabase->exec(
        "INSERT INTO site_promotions (slot,image_content_type,image_size_bytes,image_data,updated_at) VALUES (2,NULL,NULL,NULL,'2026-09-01')"
    );
    $legacyBackfill = new LegacyImageBackfill($legacyBackfillDatabase, $imageProcessor);
    $legacyDryRun = $legacyBackfill->run(false, 1);
    $assert(
        $legacyDryRun['mode'] === 'dry-run'
        && $legacyDryRun['tables']['profile_media']['candidates'] === 1
        && $legacyDryRun['tables']['product_images']['candidates'] === 1
        && $legacyDryRun['tables']['site_promotions']['candidates'] === 1
        && $legacyDryRun['tables']['profile_media']['derivatives'] === 3
        && $legacyDryRun['tables']['product_images']['derivatives'] === 2
        && (string) $legacyBackfillDatabase->query('SELECT content_type FROM profile_media WHERE id=1')->fetchColumn() === 'image/png'
        && (int) $legacyBackfillDatabase->query('SELECT COUNT(*) FROM responsive_image_variants')->fetchColumn() === 0,
        'Legacy image backfill dry-run must validate and estimate eligible photos without changing blobs or derivatives.'
    );
    $legacyApplied = $legacyBackfill->run(true, 1);
    $assert(
        $legacyApplied['tables']['profile_media']['updated'] === 1
        && $legacyApplied['tables']['product_images']['updated'] === 1
        && $legacyApplied['tables']['site_promotions']['updated'] === 1
        && (string) $legacyBackfillDatabase->query('SELECT content_type FROM profile_media WHERE id=1')->fetchColumn() === 'image/webp'
        && (string) $legacyBackfillDatabase->query('SELECT content_type FROM product_images WHERE id=1')->fetchColumn() === 'image/webp'
        && (string) $legacyBackfillDatabase->query('SELECT image_content_type FROM site_promotions WHERE slot=1')->fetchColumn() === 'image/webp'
        && (string) $legacyBackfillDatabase->query('SELECT media_type FROM profile_media WHERE id=2')->fetchColumn() === 'VIDEO'
        && (string) $legacyBackfillDatabase->query('SELECT content_type FROM profile_media WHERE id=4')->fetchColumn() === 'image/svg+xml'
        && (int) $legacyBackfillDatabase->query('SELECT COUNT(*) FROM responsive_image_variants WHERE profile_media_id=1')->fetchColumn() === 3
        && (int) $legacyBackfillDatabase->query('SELECT COUNT(*) FROM responsive_image_variants WHERE product_image_id=1')->fetchColumn() === 2,
        'Legacy image backfill must convert eligible blobs once, preserve videos, and warm responsive and VIP preview variants.'
    );
    $legacyRepeated = $legacyBackfill->run(true, 1);
    $assert(
        $legacyRepeated['tables']['profile_media']['candidates'] === 0
        && $legacyRepeated['tables']['product_images']['candidates'] === 0
        && $legacyRepeated['tables']['site_promotions']['candidates'] === 0,
        'Legacy image backfill must be idempotent after successful conversion.'
    );

    $mediaDatabase = new PDO('sqlite::memory:');
    $mediaDatabase->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $mediaDatabase->exec(
        'CREATE TABLE profile_media ('
        . 'id INTEGER PRIMARY KEY,user_id INTEGER NOT NULL,media_type TEXT NOT NULL,file_name TEXT NOT NULL,'
        . 'content_type TEXT NOT NULL,size_bytes INTEGER NOT NULL,media_data BLOB NOT NULL,'
        . 'position INTEGER NOT NULL,created_at TEXT NOT NULL)'
    );
    $mediaDatabase->exec(
        'CREATE TABLE responsive_image_variants ('
        . 'profile_media_id INTEGER,width INTEGER NOT NULL)'
    );
    $mediaDatabase->exec(
        'CREATE TABLE users ('
        . 'id INTEGER PRIMARY KEY,role TEXT NOT NULL,approval_status TEXT NOT NULL)'
    );
    $mediaDatabase->exec(
        'CREATE TABLE professional_profiles ('
        . 'user_id INTEGER PRIMARY KEY,profile_type TEXT NOT NULL)'
    );
    $mediaDatabase->exec("INSERT INTO users (id,role,approval_status) VALUES (7,'ESCORT','APPROVED'),(8,'ESCORT','PENDING'),(9,'ESCORT','APPROVED')");
    $mediaDatabase->exec("INSERT INTO professional_profiles (user_id,profile_type) VALUES (7,'ESCORT'),(8,'ESCORT'),(9,'ESCORT')");
    $mediaDatabase->exec(
        "INSERT INTO profile_media (id,user_id,media_type,file_name,content_type,size_bytes,media_data,position,created_at) VALUES"
        . " (1,7,'PHOTO','later.jpg','image/jpeg',1,X'01',5,'2026-09-08 00:00:00'),"
        . " (2,7,'PHOTO','cover.jpg','image/jpeg',1,X'02',1,'2026-09-08 00:00:00'),"
        . " (4,8,'PHOTO','other.jpg','image/jpeg',1,X'03',0,'2026-09-08 00:00:00'),"
        . " (5,9,'PHOTO','legacy.jpg','image/jpeg',1,X'04',0,'2026-09-08 00:00:00')"
    );
    $mediaDatabase->exec(
        'INSERT INTO responsive_image_variants (profile_media_id,width) VALUES (1,320),(2,320),(2,640),(4,320)'
    );
    $profileMedia = new ProfileMediaRepository($mediaDatabase);
    $covers = $profileMedia->firstPhotosForUsers([7, 8, 9, 99], true);
    $photoCounts = $profileMedia->countTypeForUsers([7, 8, 9, 99], 'PHOTO');
    $profilePhoto = $profileMedia->profilePhotoForUser(7, true);
    $galleryPage = $profileMedia->listFor(7, true, null, 1, 1);
    $assert(
        count($covers[7]) === 1
        && (int) $covers[7][0]['id'] === 2
        && ($covers[7][0]['url'] ?? '') === '/api/profiles/7/media/2?v=media-v3'
        && ($covers[7][0]['srcSet'] ?? '') === '/api/profiles/7/media/2/320.webp?v=media-v3 320w, /api/profiles/7/media/2/640.webp?v=media-v3 640w'
        && !array_key_exists('fileName', $covers[7][0])
        && count($covers[8]) === 1
        && ($covers[9][0]['srcSet'] ?? '') === '/api/profiles/9/media/5/320.webp?v=media-v3 320w, /api/profiles/9/media/5/640.webp?v=media-v3 640w, /api/profiles/9/media/5/960.webp?v=media-v3 960w, /api/profiles/9/media/5/1280.webp?v=media-v3 1280w'
        && $covers[99] === [],
        'Listing cards must receive each profile\'s first public photo and lazy responsive candidates for legacy media.'
    );
    $assert(
        $photoCounts === [7 => 2, 8 => 1, 9 => 1, 99 => 0]
        && $profileMedia->countTypeForUsers([], 'PHOTO') === [],
        'Profile media counts must be loaded for the full result set in one batch.'
    );
    $assert(
        (int) ($profilePhoto['id'] ?? 0) === 2
        && ($profilePhoto['url'] ?? '') === '/api/profiles/7/profile-photo?v=media-v3-2'
        && ($profilePhoto['srcSet'] ?? '') === '/api/profiles/7/profile-photo/320.webp?v=media-v3-2 320w, /api/profiles/7/profile-photo/640.webp?v=media-v3-2 640w'
        && $profileMedia->profilePhotoIdForUser(7) === 2
        && $profileMedia->publicProfilePhotoIdForUser(7) === 2
        && $profileMedia->publicProfilePhotoIdForUser(8) === null
        && ($profileMedia->profilePhotoForUser(9, true)['srcSet'] ?? '') === '/api/profiles/9/profile-photo/320.webp?v=media-v3-5 320w, /api/profiles/9/profile-photo/640.webp?v=media-v3-5 640w, /api/profiles/9/profile-photo/960.webp?v=media-v3-5 960w, /api/profiles/9/profile-photo/1280.webp?v=media-v3-5 1280w'
        && $profileMedia->profilePhotoIdForUser(99) === null
        && $profileMedia->profilePhotoForUser(99, true) === null,
        'A public profile photo must use independent paths and expose its ordered identifier without hydrating media metadata.'
    );
    $assert(
        $profileMedia->countAll(7) === 2
        && count($galleryPage) === 1
        && (int) ($galleryPage[0]['id'] ?? 0) === 1,
        'Profile gallery queries must return bounded pages and a separate total count.'
    );
    $assert(
        $profileMedia->isFirstPhoto(7, 2)
        && !$profileMedia->isFirstPhoto(7, 1)
        && !$profileMedia->isFirstPhoto(99, 2),
        'Only the first ordered photo must be recognized as the public profile photo.'
    );
    $profileMedia->promotePhoto(7, 1);
    $assert(
        $profileMedia->isFirstPhoto(7, 1)
        && !$profileMedia->isFirstPhoto(7, 2)
        && (int) ($profileMedia->profilePhotoForUser(7, true)['id'] ?? 0) === 1,
        'Choosing a new profile photo must promote it without removing the previous gallery photo.'
    );
    $videoFixture = '0123456789abcdefghijklmnopqrstuvwxyz';
    $insertMedia = $mediaDatabase->prepare(
        'INSERT INTO profile_media '
        . '(id,user_id,media_type,file_name,content_type,size_bytes,media_data,position,created_at) '
        . "VALUES (3,7,'VIDEO','fixture.webm','video/webm',:size,:data,0,'2026-09-08 00:00:00')"
    );
    $insertMedia->bindValue(':size', strlen($videoFixture), PDO::PARAM_INT);
    $insertMedia->bindValue(':data', $videoFixture, PDO::PARAM_LOB);
    $insertMedia->execute();
    $mediaCounts = $profileMedia->countTypes(7);
    $assert(
        $mediaCounts === ['PHOTO' => 2, 'VIDEO' => 1]
        && $profileMedia->countAll(7) === 3,
        'Profile totals must count each media type in one bounded aggregate query.'
    );
    $mediaChunks = iterator_to_array(
        $profileMedia->chunks(7, 3, 2, 7, 3),
        false
    );
    $assert(
        $mediaChunks === ['234', '567', '8'] && implode('', $mediaChunks) === '2345678',
        'Ranged media reads must fetch only the requested BLOB section in bounded chunks.'
    );
}

$failedUpload = new UploadedFile('large.png', 'image/png', 0, null, null, UPLOAD_ERR_INI_SIZE);
$assert(!$failedUpload->isEmpty(), 'A rejected upload must not be mistaken for an empty file.');

try {
    $failedUpload->bytes();
    $assert(false, 'A server-rejected upload must fail.');
} catch (ApiException $exception) {
    $assert($exception->status === 413, 'An oversized server-rejected upload must return 413.');
}

$request = new Request('GET', '/api/test', [], [], '0123456789abcdef');
$assert($request->requestId === '0123456789abcdef', 'Request IDs must remain stable during a request.');
$headers = App::commonHeaders($request);
$assert(($headers['X-Request-ID'] ?? null) === $request->requestId, 'Responses must expose the request ID.');
$assert(
    str_contains($headers['Access-Control-Allow-Headers'] ?? '', 'Last-Event-ID'),
    'CORS must allow the SSE reconnection cursor header.'
);
$assert(
    str_contains($headers['Access-Control-Allow-Headers'] ?? '', 'Range')
    && str_contains($headers['Access-Control-Expose-Headers'] ?? '', 'Content-Range'),
    'CORS must allow byte-range requests and expose ranged response metadata.'
);
$etag = ApiResponder::etag('compressed representation');
$assert(
    ApiResponder::etagMatches(new Request('GET', '/api/test', ['if-none-match' => $etag], []), $etag)
    && ApiResponder::etagMatches(new Request(
        'GET',
        '/api/test',
        ['if-none-match' => substr($etag, 0, -1) . '-gzip"'],
        []
    ), $etag),
    'Conditional requests must accept original and Apache-compressed ETag variants.'
);
$assert(
    ($headers['X-Robots-Tag'] ?? '') === 'noindex, nofollow, nosnippet'
    && !isset(App::commonHeaders(new Request('GET', '/api/seo/sitemap.xml', [], []))['X-Robots-Tag'])
    && !isset(App::commonHeaders(new Request('GET', '/api/products/4/images/main/320.webp', [], []))['X-Robots-Tag']),
    'API documents must be excluded from search while sitemaps and public media remain indexable.'
);
$assert(
    Request::resolveClientIp('172.20.0.3', '203.0.113.20, 172.20.0.2', '172.16.0.0/12') === '203.0.113.20',
    'Trusted reverse proxies must preserve the original client IP for rate limiting.'
);
$assert(
    Request::resolveClientIp('198.51.100.9', '203.0.113.20', '172.16.0.0/12') === '198.51.100.9',
    'Untrusted clients must not be able to spoof a forwarded IP address.'
);
if (in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    $exclusionDatabase = new PDO('sqlite::memory:');
    $exclusionDatabase->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $exclusionDatabase->exec('CREATE TABLE user_exclusions (owner_id INTEGER NOT NULL, excluded_user_id INTEGER NOT NULL)');
    $exclusionDatabase->exec('INSERT INTO user_exclusions (owner_id,excluded_user_id) VALUES (1,3),(4,1)');
    $exclusions = new UserExclusionRepository($exclusionDatabase);
    $excludedUserIds = $exclusions->excludedUserIds(1, [2, 3, 4, 4, 0, -1]);
    sort($excludedUserIds);
    $assert(
        $excludedUserIds === [3, 4] && !$exclusions->existsBetween(1, 2),
        'Batch exclusion lookup must preserve both directions while deduplicating and ignoring invalid IDs.'
    );
    $exclusionDatabase->exec('INSERT INTO user_exclusions (owner_id,excluded_user_id) VALUES (1,402),(403,1)');
    $largeBatchExclusions = $exclusions->excludedUserIds(1, range(2, 405));
    sort($largeBatchExclusions);
    $assert(
        $largeBatchExclusions === [3, 4, 402, 403],
        'Batch exclusion lookup must safely split large candidate lists without missing either exclusion direction.'
    );
    $assert(
        $exclusions->excludedUserIds(1, []) === [] && $exclusions->excludedUserIds(1, [1]) === [],
        'Batch exclusion lookup must skip empty lists and the viewer’s own ID.'
    );
    $imageDatabase = new PDO('sqlite::memory:');
    $imageDatabase->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $imageDatabase->exec('CREATE TABLE profile_media (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, media_type TEXT NOT NULL)');
    $imageDatabase->exec('CREATE TABLE responsive_image_variants (profile_media_id INTEGER NOT NULL, width INTEGER NOT NULL, height INTEGER NOT NULL, content_type TEXT NOT NULL, size_bytes INTEGER NOT NULL, image_data BLOB NOT NULL, source_hash TEXT NOT NULL, updated_at TEXT NOT NULL)');
    $imageDatabase->exec("INSERT INTO profile_media (id,user_id,media_type) VALUES (10,2,'PHOTO'),(11,3,'PHOTO'),(12,2,'VIDEO')");
    $imageDatabase->exec("INSERT INTO responsive_image_variants (profile_media_id,width,height,content_type,size_bytes,image_data,source_hash,updated_at) VALUES (10,640,480,'image/webp',4,'test','hash-a','2026-01-01'),(10,1280,960,'image/webp',8,'large','hash-a','2026-01-01'),(11,640,480,'image/webp',4,'other','hash-b','2026-01-01'),(12,640,480,'image/webp',4,'video','hash-c','2026-01-01')");
    $imageVariants = new ResponsiveImageVariantRepository($imageDatabase);
    $cachedProfileImage = $imageVariants->findProfilePhotoForUser(2, 10, 960, 320);
    $assert(
        ($cachedProfileImage['bytes'] ?? null) === 'test'
            && $cachedProfileImage['width'] === 640
            && $imageVariants->findProfilePhotoForUser(2, 11, 960, 320) === null
            && $imageVariants->findProfilePhotoForUser(2, 12, 960, 320) === null,
        'A cached gallery photo must be selected in one owner-checked query without returning another profile’s media or videos.'
    );
}

$error = Response::error(400, 'Invalid request data.', '/api/test');
$decoded = json_decode($error->body, true, 16, JSON_THROW_ON_ERROR);
$assert($decoded['status'] === 400 && $decoded['fields'] === [], 'Error response must match the API contract.');
$assert(($error->headers['Cache-Control'] ?? null) === 'no-store', 'Error responses must not be cached.');
$encodedEvent = RealtimeRoutes::encode([
    'id' => 12,
    'type' => 'MESSAGE_CREATED',
    'resourceId' => 4,
    'payload' => ['messageId' => 21],
    'createdAt' => '2026-09-06 20:00:00',
]);
$assert(
    str_starts_with($encodedEvent, "id: 12\ndata: ")
    && str_ends_with($encodedEvent, "\n\n")
    && str_contains($encodedEvent, '"messageId":21'),
    'Realtime events must use a resumable, standards-compliant SSE frame.'
);

$oversized = new Request('POST', '/api/auth/register', ['content-length' => (string) (31 * 1024 * 1024)], []);

$mailReflection = new ReflectionClass(ChambreRose\MailService::class);
$mailPreview = $mailReflection->newInstanceWithoutConstructor();
$renderMail = $mailReflection->getMethod('render');
foreach (['en', 'fr'] as $mailLocale) {
    foreach (['account_approved', 'account_rejected'] as $mailTemplate) {
        [$subject, $text, $html] = $renderMail->invoke($mailPreview, $mailTemplate, $mailLocale, ['name' => 'Rose']);
        $assert(str_contains($html, '/api/brand/logo?format=png'), 'Decision emails must use the transparent, email-compatible brand PNG.');
        $assert(str_contains($html, 'font-size:13px') && str_contains($html, 'padding:10px 16px'), 'Decision email buttons must be compact.');
        $assert($subject !== '' && $text !== '' && str_contains($html, 'lang="' . $mailLocale . '"'), 'Both decision emails must have localized text and HTML.');
    }
    [$adminSubject, $adminText, $adminHtml] = $renderMail->invoke(
        $mailPreview,
        'admin_account_pending',
        $mailLocale,
        [
            'name' => 'Andre',
            'applicantName' => 'New Member',
            'applicantEmail' => 'member@example.com',
            'accountType' => $mailLocale === 'fr' ? 'client' : 'client',
            'url' => 'https://www.chambre-rose.com/conta/usuarios?email=member%40example.com',
        ]
    );
    $assert(
        $adminSubject !== ''
        && str_contains($adminText, 'New Member')
        && str_contains($adminText, 'member@example.com')
        && str_contains($adminHtml, 'member%40example.com'),
        'Administrator registration alerts must identify the applicant and link directly to account review.'
    );
}
$pngLogo = (new ReflectionMethod(App::class, 'brandLogo'))->invoke(new App(), new Request('GET', '/api/brand/logo', [], ['format' => 'png']));
$assert($pngLogo->status === 200 && $pngLogo->headers['Content-Type'] === 'image/png', 'Email brand endpoint must support PNG without changing existing WebP requests.');

try {
    (new App())->handle($oversized);
    $assert(false, 'Oversized requests must fail before database boot.');
} catch (ApiException $exception) {
    $assert($exception->status === 413, 'Oversized requests must return 413.');
}

$assert(SearchPagination::resolve(9, 1, 8) === ['page' => 1, 'pageSize' => 8, 'totalPages' => 2, 'offset' => 0], 'Nine results must have two pages of eight.');
$assert(SearchPagination::resolve(9, 2, 8)['offset'] === 8, 'The second page must start at the ninth result.');
$assert(SearchPagination::resolve(9, PHP_INT_MAX, 8)['page'] === 2, 'Out-of-range pages must resolve to the last real page without offset overflow.');
$assert(SearchPagination::resolve(0, 8, 8) === ['page' => 1, 'pageSize' => 8, 'totalPages' => 0, 'offset' => 0], 'Empty searches must resolve to page one.');
$assert(SearchPagination::resolve(20, -2, 500, 48)['pageSize'] === 48, 'Search page quantities must stay bounded.');
$assert(SearchPagination::resolve(20, null, null)['pageSize'] === 20, 'Existing API default quantities must remain compatible.');
$addressFeature = ['properties' => ['street' => 'Rue de Rivoli', 'housenumber' => '33', 'city' => 'Paris', 'state' => 'Île-de-France', 'country' => 'France', 'postcode' => '75001']];
$addresses = \ChambreRose\AddressSearchRoutes::suggestions([$addressFeature, $addressFeature, ['properties' => ['name' => 'Unknown']]]);
$assert(count($addresses) === 1, 'Address suggestions deduplicate and reject missing locality');
$assert($addresses[0] === ['address' => 'Rue de Rivoli, 33', 'city' => 'Paris', 'region' => 'Île-de-France', 'country' => 'France', 'postalCode' => '75001'], 'Address suggestion preserves street and locality separately');
fwrite(STDOUT, "OK - {$tests} assertions\n");
