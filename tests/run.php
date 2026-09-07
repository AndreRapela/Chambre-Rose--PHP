<?php

declare(strict_types=1);

putenv('JWT_SECRET=test-secret-with-more-than-thirty-two-bytes-123456');
putenv('JWT_EXPIRATION_MINUTES=5');
putenv('APP_ENV=production');

require dirname(__DIR__) . '/bootstrap.php';

use ChambreRose\ApiException;
use ChambreRose\App;
use ChambreRose\AuthSessionCookie;
use ChambreRose\Jwt;
use ChambreRose\MultipartParser;
use ChambreRose\NotificationRepository;
use ChambreRose\NotificationOutboxStore;
use ChambreRose\PushNotificationSender;
use ChambreRose\PushNotificationWorker;
use ChambreRose\PushDeviceCookie;
use ChambreRose\RealtimeRoutes;
use ChambreRose\Request;
use ChambreRose\Response;
use ChambreRose\UploadedFile;
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

    public function enqueue(int $notificationId, int $userId, int $maxAttempts): void
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
