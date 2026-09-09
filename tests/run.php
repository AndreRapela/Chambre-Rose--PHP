<?php

declare(strict_types=1);

putenv('JWT_SECRET=test-secret-with-more-than-thirty-two-bytes-123456');
putenv('JWT_EXPIRATION_MINUTES=5');
putenv('APP_ENV=production');

require dirname(__DIR__) . '/bootstrap.php';

use ChambreRose\ApiException;
use ChambreRose\AdminUserService;
use ChambreRose\App;
use ChambreRose\AuthSessionCookie;
use ChambreRose\HttpByteRange;
use ChambreRose\Jwt;
use ChambreRose\LocationNormalizer;
use ChambreRose\MultipartParser;
use ChambreRose\NotificationRepository;
use ChambreRose\NotificationOutboxStore;
use ChambreRose\NotificationOutboxRepository;
use ChambreRose\NotificationDeliverySchedule;
use ChambreRose\NotificationMessageCatalog;
use ChambreRose\NotificationRetentionService;
use ChambreRose\PushNotificationSender;
use ChambreRose\PushNotificationWorker;
use ChambreRose\PushDeviceCookie;
use ChambreRose\ProductImageRepository;
use ChambreRose\ProfessionalProfileRepository;
use ChambreRose\ProfileMediaRepository;
use ChambreRose\RealtimeEventRepository;
use ChambreRose\RealtimeRoutes;
use ChambreRose\ResponsiveImageProcessor;
use ChambreRose\ResponsiveImageService;
use ChambreRose\ResponsiveImageVariantRepository;
use ChambreRose\Request;
use ChambreRose\Response;
use ChambreRose\UploadedFile;
use ChambreRose\UserRepository;
use ChambreRose\UserNotificationService;
use ChambreRose\Validator;

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
            'target_url' => '/mensagens/1',
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
$token = $jwt->generate('admin@example.com', 'ADMIN');
$claims = $jwt->verify($token);
$assert($claims['sub'] === 'admin@example.com', 'JWT must preserve the subject.');
$assert($claims['role'] === 'ADMIN', 'JWT must preserve the role.');
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
    $adminDatabase = new PDO('sqlite::memory:');
    $adminDatabase->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $adminDatabase->exec(<<<'SQL'
        CREATE TABLE users (
          id INTEGER PRIMARY KEY, email TEXT NOT NULL, password_hash TEXT NOT NULL,
          first_name TEXT NOT NULL, last_name TEXT NOT NULL, phone TEXT NOT NULL,
          address TEXT NOT NULL, city TEXT NOT NULL, country TEXT NOT NULL, postal_code TEXT NOT NULL,
          role TEXT NOT NULL, approval_status TEXT NOT NULL, approval_reason TEXT NULL,
          review_deadline TEXT NULL, approved_at TEXT NULL, locale TEXT NOT NULL,
          vip_active INTEGER NOT NULL, vip_since TEXT NULL, vip_until TEXT NULL,
          created_at TEXT NOT NULL, updated_at TEXT NOT NULL
        );
        CREATE TABLE professional_profiles (
          user_id INTEGER PRIMARY KEY, profile_type TEXT NOT NULL, display_name TEXT NOT NULL,
          business_name TEXT NULL, segment TEXT NULL, location TEXT NULL,
          location_city TEXT NULL, location_region TEXT NULL, location_country TEXT NULL
        );
        SQL);
    $adminUserInsert = $adminDatabase->prepare(
        'INSERT INTO users (id,email,password_hash,first_name,last_name,phone,address,city,country,'
        . 'postal_code,role,approval_status,approval_reason,review_deadline,approved_at,locale,'
        . 'vip_active,vip_since,vip_until,created_at,updated_at) VALUES '
        . '(:id,:email,:password_hash,:first_name,:last_name,:phone,:address,:city,:country,'
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
    $adminService->deleteUser(2, 1);
    $assert($adminUsers->find(2) === null, 'Administrators must be able to remove another account.');
    try {
        $adminService->deleteUser(1, 1);
        $assert(false, 'An administrator must not be able to remove their own account.');
    } catch (ApiException $exception) {
        $assert($exception->status === 403, 'Self-deletion from administrator controls must be rejected.');
    }

    $notificationDatabase = new PDO('sqlite::memory:');
    $notificationDatabase->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $notificationDatabase->exec(
        'CREATE TABLE push_subscriptions ('
        . 'id INTEGER PRIMARY KEY,user_id INTEGER NOT NULL,endpoint_hash TEXT NOT NULL,endpoint TEXT NOT NULL)'
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
    (new NotificationRepository($notificationDatabase))->deleteSubscriptionByHash(
        7,
        hash('sha256', 'https://push.example.test/current-device')
    );
    $remainingDevice = $notificationDatabase->query('SELECT endpoint FROM push_subscriptions')->fetchColumn();
    $assert(
        $remainingDevice === 'https://push.example.test/mobile-device',
        'Revoking the current Push device must preserve the account subscription on another device.'
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
        '/espace-prive/perfil',
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
        '/favoritos',
        'preferences:suppressed'
    );
    $assert(
        ($suppressed['suppressed'] ?? false) === true
        && (int) $preferenceDatabase->query('SELECT COUNT(*) FROM account_notifications')->fetchColumn() === 2,
        'Direct-messages-only mode must suppress every other notification category.'
    );

    $notificationPreferences['onlyDirectMessages'] = false;
    $notificationPreferences['dailyDigest'] = false;
    $notificationPreferences['browserNotifications'] = false;
    $preferenceRepository->savePreferences(91, $notificationPreferences);
    $notificationService->notify(
        91,
        UserNotificationService::DIRECT_MESSAGE,
        'MESSAGE_RECEIVED',
        '/mensagens/10',
        'preferences:in-app',
        ['senderName' => 'Camille']
    );
    $inAppFeed = $preferenceRepository->feed(91);
    $assert(
        count($inAppFeed['items']) === 2
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
        '/mensagens/11',
        'preferences:browser',
        ['senderName' => 'Morgan']
    );
    $assert(
        count($preferenceRepository->feed(91)['items']) === 2
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
imagedestroy($wideSource);
$assert(is_string($widePng), 'Responsive image fixture must be encoded.');
$responsiveVariants = $imageProcessor->generate($widePng);
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
    ResponsiveImageService::srcSet('/api/profiles/9/media/2', [640, 320])
        === '/api/profiles/9/media/2/320.webp 320w, /api/profiles/9/media/2/640.webp 640w',
    'Responsive image metadata must expose only available, ordered width descriptors.'
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
    try {
        $responsiveProductImages->responsive(44, 'MAIN', 960);
        $assert(false, 'A responsive endpoint must not enlarge an 800-pixel source to 960 pixels.');
    } catch (ApiException $exception) {
        $assert(
            $exception->status === 404
            && (int) $responsiveImageDatabase->query('SELECT COUNT(*) FROM responsive_image_variants')->fetchColumn() === 2,
            'Unavailable oversized variants must return 404 without creating an upscaled file.'
        );
    }

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
        "INSERT INTO profile_media (id,user_id,media_type,file_name,content_type,size_bytes,media_data,position,created_at) VALUES"
        . " (1,7,'PHOTO','later.jpg','image/jpeg',1,X'01',5,'2026-09-08 00:00:00'),"
        . " (2,7,'PHOTO','cover.jpg','image/jpeg',1,X'02',1,'2026-09-08 00:00:00'),"
        . " (4,8,'PHOTO','other.jpg','image/jpeg',1,X'03',0,'2026-09-08 00:00:00')"
    );
    $mediaDatabase->exec(
        'INSERT INTO responsive_image_variants (profile_media_id,width) VALUES (1,320),(2,320),(2,640),(4,320)'
    );
    $profileMedia = new ProfileMediaRepository($mediaDatabase);
    $covers = $profileMedia->firstPhotosForUsers([7, 8, 99], true);
    $assert(
        count($covers[7]) === 1
        && (int) $covers[7][0]['id'] === 2
        && ($covers[7][0]['srcSet'] ?? '') === '/api/profiles/7/media/2/320.webp 320w, /api/profiles/7/media/2/640.webp 640w'
        && !array_key_exists('fileName', $covers[7][0])
        && count($covers[8]) === 1
        && $covers[99] === [],
        'Listing cards must receive only each profile\'s first public photo and its available variants.'
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
$assert(
    Request::resolveClientIp('172.20.0.3', '203.0.113.20, 172.20.0.2', '172.16.0.0/12') === '203.0.113.20',
    'Trusted reverse proxies must preserve the original client IP for rate limiting.'
);
$assert(
    Request::resolveClientIp('198.51.100.9', '203.0.113.20', '172.16.0.0/12') === '198.51.100.9',
    'Untrusted clients must not be able to spoof a forwarded IP address.'
);

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

try {
    (new App())->handle($oversized);
    $assert(false, 'Oversized requests must fail before database boot.');
} catch (ApiException $exception) {
    $assert($exception->status === 413, 'Oversized requests must return 413.');
}

fwrite(STDOUT, "OK - {$tests} assertions\n");
