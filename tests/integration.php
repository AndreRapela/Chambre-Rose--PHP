<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/IntegrationDataCleanup.php';

use ChambreRose\ApiException;
use ChambreRose\AuthRateLimiter;
use ChambreRose\Config;
use ChambreRose\Database;
use ChambreRose\Jwt;
use ChambreRose\Tests\IntegrationDataCleanup;

$base = rtrim(getenv('TEST_API_URL') ?: 'http://localhost:8080', '/');
$adminEmail = getenv('TEST_ADMIN_EMAIL') ?: getenv('SEED_ADMIN_EMAIL') ?: 'admin@admin.com';
$adminPassword = getenv('TEST_ADMIN_PASSWORD') ?: getenv('SEED_ADMIN_PASSWORD') ?: '';
$fixture = dirname(__DIR__) . '/resources/brand/brand-logo.png';
$runId = gmdate('YmdHis') . '-' . bin2hex(random_bytes(6));
$cleanup = new IntegrationDataCleanup(Database::connection(), $runId);
$profileName = $cleanup->profileName();
$assertions = 0;
$cleanupComplete = false;

$cleanupFixtures = static function () use ($cleanup, &$cleanupComplete): void {
    if ($cleanupComplete) {
        return;
    }

    $cleanup->cleanup();
    if ($cleanup->remainingCount() !== 0) {
        throw new RuntimeException('Integration fixtures remained in the database after cleanup.');
    }
    $cleanupComplete = true;
};

register_shutdown_function(static function () use ($cleanupFixtures): void {
    try {
        $cleanupFixtures();
    } catch (Throwable $exception) {
        fwrite(STDERR, "Integration cleanup failed: {$exception->getMessage()}\n");
        if (error_get_last() === null) {
            exit(1);
        }
    }
});

$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$request = static function (
    string $method,
    string $path,
    ?array $body = null,
    ?string $sessionCookie = null,
    array $extraHeaders = []
) use ($base): array {
    $headers = ['Accept: application/json'];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
    }
    if ($sessionCookie !== null) {
        $headers[] = 'Cookie: ' . $sessionCookie;
    }
    array_push($headers, ...$extraHeaders);
    $context = stream_context_create(['http' => [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'content' => $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR),
        'ignore_errors' => true,
    ]]);
    $raw = file_get_contents($base . $path, false, $context);
    $statusLine = $http_response_header[0] ?? '';
    preg_match('/\s(\d{3})\s/', $statusLine, $match);
    $responseCookie = null;
    $responseHeaders = [];
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('/^Set-Cookie:\s*([^;]+)/i', $header, $cookieMatch)) {
            $responseCookie = $cookieMatch[1];
        }
        if (str_contains($header, ':')) {
            [$headerName, $headerValue] = explode(':', $header, 2);
            $responseHeaders[strtolower(trim($headerName))] = trim($headerValue);
        }
    }

    $decoded = is_string($raw) && $raw !== ''
        ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR)
        : null;

    return [
        (int)($match[1] ?? 0),
        $decoded,
        $responseCookie,
        $responseHeaders,
    ];
};

$binaryRequest = static function (string $method, string $path, array $headers = []) use ($base): array {
    $context = stream_context_create(['http' => [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'ignore_errors' => true,
    ]]);
    $raw = file_get_contents($base . $path, false, $context);
    $responseHeaders = $http_response_header ?? [];
    preg_match('/\s(\d{3})\s/', $responseHeaders[0] ?? '', $statusMatch);
    $headerMap = [];
    foreach ($responseHeaders as $header) {
        if (str_contains($header, ':')) {
            [$headerName, $headerValue] = explode(':', $header, 2);
            $headerMap[strtolower(trim($headerName))] = trim($headerValue);
        }
    }

    return [(int) ($statusMatch[1] ?? 0), is_string($raw) ? $raw : '', $headerMap];
};

putenv('AUTH_LOGIN_ACCOUNT_ATTEMPTS=3');
putenv('AUTH_LOGIN_IP_ATTEMPTS=50');
putenv('AUTH_RECOVERY_ACCOUNT_ATTEMPTS=2');
putenv('AUTH_RECOVERY_IP_ATTEMPTS=50');
putenv('AUTH_RESET_TOKEN_ATTEMPTS=2');
putenv('AUTH_RESET_IP_ATTEMPTS=50');
$rateLimiter = new AuthRateLimiter(Database::connection());
$rateEmail = 'rate-' . $runId . '@example.invalid';
$rateIp = '198.51.100.42';
$rateToken = 'integration-reset-token-' . $runId;
$rateSecret = Config::get('RATE_LIMIT_SECRET', Config::get('JWT_SECRET', '')) ?? '';
$rateIdentifiers = [
    ['login-account', $rateEmail],
    ['login-ip', $rateIp],
    ['recovery-account', $rateEmail],
    ['recovery-ip', $rateIp],
    ['reset-token', $rateToken],
    ['reset-ip', $rateIp],
];
$rateHashes = array_map(
    static fn (array $item): string => hash_hmac('sha256', $item[0] . "\0" . strtolower(trim($item[1])), $rateSecret),
    $rateIdentifiers
);

try {
    $loginLimited = false;
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        try {
            $rateLimiter->registerLoginFailure($rateEmail, $rateIp);
        } catch (ApiException $exception) {
            $loginLimited = $attempt === 3
                && $exception->status === 429
                && isset($exception->headers['Retry-After']);
        }
    }
    $assert($loginLimited, 'Login failures must be rate limited with Retry-After metadata.');

    $rateLimiter->consumeRecoveryRequest($rateEmail, $rateIp);
    $rateLimiter->consumeRecoveryRequest($rateEmail, $rateIp);
    try {
        $rateLimiter->consumeRecoveryRequest($rateEmail, $rateIp);
        $assert(false, 'Password recovery must reject requests above the configured limit.');
    } catch (ApiException $exception) {
        $assert($exception->status === 429, 'Password recovery must return 429 after its limit.');
    }

    $rateLimiter->consumePasswordResetAttempt($rateToken, $rateIp);
    $rateLimiter->consumePasswordResetAttempt($rateToken, $rateIp);
    try {
        $rateLimiter->consumePasswordResetAttempt($rateToken, $rateIp);
        $assert(false, 'Password reset must reject attempts above the configured limit.');
    } catch (ApiException $exception) {
        $assert($exception->status === 429, 'Password reset must return 429 after its limit.');
    }
} finally {
    $placeholders = implode(', ', array_fill(0, count($rateHashes), '?'));
    $statement = Database::connection()->prepare('DELETE FROM auth_rate_limits WHERE bucket_hash IN (' . $placeholders . ')');
    $statement->execute($rateHashes);
}

$httpRateEmail = 'http-rate-' . $runId . '@example.invalid';
$httpRateIp = '198.51.100.77';
$httpRateHashes = array_map(
    static fn (array $item): string => hash_hmac('sha256', $item[0] . "\0" . strtolower(trim($item[1])), $rateSecret),
    [['login-account', $httpRateEmail], ['login-ip', $httpRateIp]]
);
try {
    $statuses = [];
    $lastHeaders = [];
    for ($attempt = 1; $attempt <= 5; $attempt++) {
        [$status, , , $lastHeaders] = $request(
            'POST',
            '/api/auth/login',
            ['email' => $httpRateEmail, 'password' => 'Incorrect9!pass'],
            null,
            ['X-Forwarded-For: ' . $httpRateIp]
        );
        $statuses[] = $status;
    }
    $assert(
        $statuses === [401, 401, 401, 401, 429]
        && isset($lastHeaders['retry-after']),
        'The HTTP login endpoint must enforce its limit through a trusted proxy and expose Retry-After.'
    );
} finally {
    $placeholders = implode(', ', array_fill(0, count($httpRateHashes), '?'));
    $statement = Database::connection()->prepare('DELETE FROM auth_rate_limits WHERE bucket_hash IN (' . $placeholders . ')');
    $statement->execute($httpRateHashes);
}

[$visitorStatus, $visitor, $visitorCookie] = $request('POST', '/api/auth/register', [
    'firstName' => 'Visitor', 'lastName' => 'Integration',
    'email' => $cleanup->email('visitor'), 'phone' => '12345678',
    'password' => 'Integration9!pass', 'accountType' => 'VISITOR',
]);
$assert(
    $visitorStatus === 201
    && is_string($visitorCookie)
    && str_starts_with($visitorCookie, 'chambre_rose_session=')
    && !array_key_exists('token', $visitor),
    'Visitor registration must create an HttpOnly server session without exposing the JWT.'
);

[$forgotStatus, $forgotBody] = $request('POST', '/api/auth/forgot-password', [
    'email' => $cleanup->email('visitor'),
    'locale' => 'en',
]);
$resetEmail = Database::connection()->prepare(
    'SELECT delivery_status, body FROM email_outbox WHERE recipient = :recipient AND template = :template ORDER BY id DESC LIMIT 1'
);
$resetEmail->execute(['recipient' => $cleanup->email('visitor'), 'template' => 'password_reset']);
$resetEmailRow = $resetEmail->fetch();
$resetCode = null;
if (is_array($resetEmailRow)) {
    preg_match('/\n(\d{6})\n/', (string) ($resetEmailRow['body'] ?? ''), $codeMatch);
    $resetCode = $codeMatch[1] ?? null;
}
$resetChallenge = (string) ($forgotBody['challenge'] ?? '');
$assert(
    $forgotStatus === 202
    && strlen($resetChallenge) >= 32
    && is_array($resetEmailRow)
    && in_array($resetEmailRow['delivery_status'] ?? null, ['SENT', 'LOGGED'], true)
    && is_string($resetCode)
    && preg_match('/^\d{6}$/', $resetCode) === 1,
    'Password recovery must send a six-digit verification code and return a reset challenge.'
);
[$wrongCodeStatus] = $request('POST', '/api/auth/verify-reset-code', [
    'challenge' => $resetChallenge,
    'code' => $resetCode === '000000' ? '999999' : '000000',
]);
$assert($wrongCodeStatus === 400, 'An incorrect password recovery code must be rejected.');
[$verifyCodeStatus] = $request('POST', '/api/auth/verify-reset-code', [
    'challenge' => $resetChallenge,
    'code' => $resetCode,
]);
$assert($verifyCodeStatus === 200, 'The correct password recovery code must unlock password reset.');
[$resetPasswordStatus] = $request('POST', '/api/auth/reset-password', [
    'token' => $resetChallenge,
    'password' => 'Reset9!pass',
]);
$assert($resetPasswordStatus === 200, 'A verified password recovery challenge must allow a new password.');

[$weakPasswordStatus, $weakPasswordBody] = $request('POST', '/api/auth/register', [
    'firstName' => 'Weak', 'lastName' => 'Password',
    'email' => $cleanup->email('weak-password'), 'phone' => '12345678',
    'password' => 'password', 'accountType' => 'VISITOR',
]);
$assert(
    $weakPasswordStatus === 400 && isset($weakPasswordBody['fields']['password']),
    'Registration must reject a password that does not meet every visible strength rule.'
);

$profile = json_encode([
    'displayName' => $profileName, 'birthDate' => '1995-05-12', 'gender' => 'woman',
    'locationCity' => 'Saint-Gilles', 'locationRegion' => 'Brussels-Capital', 'locationCountry' => 'Belgium',
    'bio' => 'Integration profile', 'languages' => ['fr'],
    'services' => ['massage'], 'contactEmail' => "contact-{$runId}@example.com",
    'responseTime' => 'FEW_HOURS', 'priceHour' => 50, 'priceNight' => 250,
], JSON_THROW_ON_ERROR);
$boundary = 'integration-' . bin2hex(random_bytes(12));
$fields = [
    'firstName' => 'Profile', 'lastName' => 'Integration',
    'email' => $cleanup->email('profile'), 'phone' => '12345678',
    'password' => 'Integration9!pass', 'accountType' => 'ESCORT', 'locale' => 'pt', 'profile' => $profile,
];
$parts = [];
foreach ($fields as $name => $value) {
    $parts[] = "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$name}\"\r\n\r\n{$value}\r\n";
}
$parts[] = "--{$boundary}\r\nContent-Disposition: form-data; name=\"establishmentPhoto\"; filename=\"profile.png\"\r\nContent-Type: image/png\r\n\r\n" . file_get_contents($fixture) . "\r\n";
$parts[] = "--{$boundary}--\r\n";
$multipart = implode('', $parts);
$context = stream_context_create(['http' => [
    'method' => 'POST',
    'header' => "Content-Type: multipart/form-data; boundary={$boundary}\r\nContent-Length: " . strlen($multipart),
    'content' => $multipart,
    'ignore_errors' => true,
]]);
$professionalRaw = file_get_contents($base . '/api/auth/register', false, $context);
preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $statusMatch);
$professionalStatus = (int)($statusMatch[1] ?? 0);
$professional = json_decode((string)$professionalRaw, true);
$assert(
    $professionalStatus === 201
    && ($professional['approvalStatus'] ?? null) === 'PENDING'
    && ($professional['pendingApproval'] ?? false) === true
    && str_contains((string) ($professional['message'] ?? ''), '24'),
    'Companion registration must succeed with one public location, remain pending and explain the 24-hour review.'
);
$professionalEmail = Database::connection()->prepare(
    'SELECT delivery_status, body FROM email_outbox WHERE recipient = :recipient AND template = :template ORDER BY id DESC LIMIT 1'
);
$professionalEmail->execute(['recipient' => $cleanup->email('profile'), 'template' => 'account_created']);
$professionalEmailRow = $professionalEmail->fetch();
$assert(
    is_array($professionalEmailRow)
    && in_array($professionalEmailRow['delivery_status'] ?? null, ['SENT', 'LOGGED'], true)
    && str_contains((string) ($professionalEmailRow['body'] ?? ''), '24'),
    'Professional registration must queue a confirmation email containing the review deadline.'
);
$pendingToken = (new Jwt())->generate($cleanup->email('profile'), 'ESCORT');
[$pendingResourceStatus, $pendingResourceBody] = $request(
    'GET',
    '/api/auth/me',
    null,
    null,
    ['Authorization: Bearer ' . $pendingToken]
);
$assert(
    $pendingResourceStatus === 403
    && ($pendingResourceBody['fields']['approvalStatus'] ?? null) === 'PENDING',
    'Pending professional accounts must be denied protected resources even when they present a valid token.'
);

$storeProfile = json_encode([
    'displayName' => 'Store Integration ' . $runId,
    'segment' => 'Intimate wellness',
    'locationCity' => 'Brussels',
    'locationRegion' => 'Brussels-Capital',
    'locationCountry' => 'Belgium',
    'businessHours' => 'MON,TUE,WED,THU,FRI|10:00|19:00',
], JSON_THROW_ON_ERROR);
$storeBoundary = 'store-profile-' . bin2hex(random_bytes(12));
$storeFields = [
    'firstName' => 'Store', 'lastName' => 'Integration',
    'email' => $cleanup->email('store-profile'), 'phone' => '12345678',
    'password' => 'Integration9!pass', 'accountType' => 'STORE', 'locale' => 'fr',
    'profile' => $storeProfile,
];
$storeParts = [];
foreach ($storeFields as $name => $value) {
    $storeParts[] = "--{$storeBoundary}\r\nContent-Disposition: form-data; name=\"{$name}\"\r\n\r\n{$value}\r\n";
}
$storeParts[] = "--{$storeBoundary}\r\nContent-Disposition: form-data; name=\"establishmentPhoto\"; filename=\"store.png\"\r\nContent-Type: image/png\r\n\r\n" . file_get_contents($fixture) . "\r\n";
$storeParts[] = "--{$storeBoundary}--\r\n";
$storeMultipart = implode('', $storeParts);
$storeContext = stream_context_create(['http' => [
    'method' => 'POST',
    'header' => "Content-Type: multipart/form-data; boundary={$storeBoundary}\r\nContent-Length: " . strlen($storeMultipart),
    'content' => $storeMultipart,
    'ignore_errors' => true,
]]);
$storeRaw = file_get_contents($base . '/api/auth/register', false, $storeContext);
preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $storeStatusMatch);
$storeBody = json_decode((string) $storeRaw, true);
$assert(
    (int) ($storeStatusMatch[1] ?? 0) === 201 && ($storeBody['approvalStatus'] ?? null) === 'PENDING',
    'Store registration must succeed without a duplicate account address or unnecessary business fields.'
);

$futureProfile = json_encode([
    'displayName' => 'Invalid Future Profile',
    'birthDate' => '2099-05-12',
    'gender' => 'woman',
    'location' => 'Brussels',
], JSON_THROW_ON_ERROR);
$futureBoundary = 'future-profile-' . bin2hex(random_bytes(12));
$futureFields = $fields;
$futureFields['email'] = $cleanup->email('future-profile');
$futureFields['profile'] = $futureProfile;
$futureParts = [];
foreach ($futureFields as $name => $value) {
    $futureParts[] = "--{$futureBoundary}\r\nContent-Disposition: form-data; name=\"{$name}\"\r\n\r\n{$value}\r\n";
}
$futureParts[] = "--{$futureBoundary}\r\nContent-Disposition: form-data; name=\"establishmentPhoto\"; filename=\"profile.png\"\r\nContent-Type: image/png\r\n\r\n" . file_get_contents($fixture) . "\r\n";
$futureParts[] = "--{$futureBoundary}--\r\n";
$futureMultipart = implode('', $futureParts);
$futureContext = stream_context_create(['http' => [
    'method' => 'POST',
    'header' => "Content-Type: multipart/form-data; boundary={$futureBoundary}\r\nContent-Length: " . strlen($futureMultipart),
    'content' => $futureMultipart,
    'ignore_errors' => true,
]]);
$futureRaw = file_get_contents($base . '/api/auth/register', false, $futureContext);
preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $futureStatusMatch);
$futureBody = json_decode((string) $futureRaw, true);
$assert(
    (int) ($futureStatusMatch[1] ?? 0) === 400 && isset($futureBody['fields']['birthDate']),
    'A future birth date must never satisfy the adult requirement.'
);

$unsafeWebsiteProfile = json_encode([
    'displayName' => 'Unsafe Website Profile',
    'birthDate' => '1995-05-12',
    'gender' => 'woman',
    'location' => 'Brussels',
    'website' => 'javascript://example.com/%0Aalert(1)',
], JSON_THROW_ON_ERROR);
$unsafeBoundary = 'unsafe-website-' . bin2hex(random_bytes(12));
$unsafeFields = $fields;
$unsafeFields['email'] = $cleanup->email('unsafe-website');
$unsafeFields['profile'] = $unsafeWebsiteProfile;
$unsafeParts = [];
foreach ($unsafeFields as $name => $value) {
    $unsafeParts[] = "--{$unsafeBoundary}\r\nContent-Disposition: form-data; name=\"{$name}\"\r\n\r\n{$value}\r\n";
}
$unsafeParts[] = "--{$unsafeBoundary}\r\nContent-Disposition: form-data; name=\"establishmentPhoto\"; filename=\"profile.png\"\r\nContent-Type: image/png\r\n\r\n" . file_get_contents($fixture) . "\r\n";
$unsafeParts[] = "--{$unsafeBoundary}--\r\n";
$unsafeMultipart = implode('', $unsafeParts);
$unsafeContext = stream_context_create(['http' => [
    'method' => 'POST',
    'header' => "Content-Type: multipart/form-data; boundary={$unsafeBoundary}\r\nContent-Length: " . strlen($unsafeMultipart),
    'content' => $unsafeMultipart,
    'ignore_errors' => true,
]]);
$unsafeRaw = file_get_contents($base . '/api/auth/register', false, $unsafeContext);
preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $unsafeStatusMatch);
$unsafeBody = json_decode((string) $unsafeRaw, true);
$assert(
    (int) ($unsafeStatusMatch[1] ?? 0) === 400 && isset($unsafeBody['fields']['website']),
    'Professional websites must be restricted to HTTP or HTTPS.'
);

[$pendingStatus] = $request('POST', '/api/auth/login', [
    'email' => $cleanup->email('profile'), 'password' => 'Integration9!pass',
]);
$assert($pendingStatus === 403, 'Pending professional login must be blocked.');

if ($adminPassword !== '') {
    [$adminStatus, $admin, $adminCookie] = $request('POST', '/api/auth/login', ['email' => $adminEmail, 'password' => $adminPassword]);
    $assert($adminStatus === 200 && is_string($adminCookie), 'Admin login must create a cookie-backed session.');
    [$productCreateStatus, $testProduct] = $request('POST', '/api/products', [
        'name' => $cleanup->productName(),
        'category' => 'wellness',
        'price' => 29.9,
        'imageUrl' => '/assets/carousel-pink-lace-tie.jpeg',
        'description' => $cleanup->productDescription(),
        'storeName' => $cleanup->storeName(),
        'active' => true,
    ], $adminCookie);
    $productId = (int) ($testProduct['id'] ?? 0);
    $productBoundary = 'responsive-product-' . bin2hex(random_bytes(12));
    $productFields = [
        'name' => $cleanup->productName(),
        'category' => 'wellness',
        'price' => '29.9',
        'description' => $cleanup->productDescription(),
        'storeName' => $cleanup->storeName(),
        'active' => 'true',
    ];
    $productParts = [];
    foreach ($productFields as $name => $value) {
        $productParts[] = "--{$productBoundary}\r\nContent-Disposition: form-data; name=\"{$name}\"\r\n\r\n{$value}\r\n";
    }
    $productParts[] = "--{$productBoundary}\r\nContent-Disposition: form-data; name=\"mainImage\"; filename=\"product.png\"\r\nContent-Type: image/png\r\n\r\n" . file_get_contents($fixture) . "\r\n";
    $productParts[] = "--{$productBoundary}--\r\n";
    $productMultipart = implode('', $productParts);
    $productContext = stream_context_create(['http' => [
        'method' => 'PUT',
        'header' => "Content-Type: multipart/form-data; boundary={$productBoundary}\r\n"
            . 'Content-Length: ' . strlen($productMultipart) . "\r\n"
            . 'Cookie: ' . $adminCookie,
        'content' => $productMultipart,
        'ignore_errors' => true,
    ]]);
    $productUpdateRaw = file_get_contents($base . "/api/products/{$productId}", false, $productContext);
    preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $productUpdateStatusMatch);
    $responsiveProduct = json_decode((string) $productUpdateRaw, true);
    $productVariant = file_get_contents(
        $base . "/api/products/{$productId}/images/main/320.webp",
        false,
        stream_context_create(['http' => ['method' => 'GET', 'ignore_errors' => true]])
    );
    preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $productVariantStatusMatch);
    $productVariantDimensions = is_string($productVariant) ? getimagesizefromstring($productVariant) : false;
    $assert(
        (int) ($productUpdateStatusMatch[1] ?? 0) === 200
        && ($responsiveProduct['imageUrl'] ?? '') === "/api/products/{$productId}/images/main"
        && ($responsiveProduct['imageSrcSet'] ?? '') === "/api/products/{$productId}/images/main/320.webp 320w"
        && (int) ($productVariantStatusMatch[1] ?? 0) === 200
        && is_string($productVariant)
        && str_starts_with($productVariant, 'RIFF')
        && substr($productVariant, 8, 4) === 'WEBP'
        && is_array($productVariantDimensions)
        && (int) $productVariantDimensions[0] === 320,
        'Uploaded product photos must expose and serve responsive WebP variants.'
    );
    [$productPurchaseStatus, $purchasedProduct] = $request(
        'POST',
        "/api/products/{$productId}/purchases",
        [],
        $visitorCookie
    );
    $assert(
        $productCreateStatus === 201
        && $productId > 0
        && $productPurchaseStatus === 201
        && (int) ($purchasedProduct['starCount'] ?? -1) === 1
        && !array_key_exists('rating', $purchasedProduct),
        'A non-VIP visitor must be able to buy a store product and add exactly one star count.'
    );
    [$usersStatus, $users] = $request(
        'GET',
        '/api/admin/users?email=' . rawurlencode($cleanup->email('profile')) . '&role=ESCORT&page=1&pageSize=10',
        null,
        $adminCookie
    );
    $match = is_array($users['items'] ?? null) ? $users['items'] : [];
    $assert($usersStatus === 200, 'Admin account pagination must return HTTP 200.');
    $assert(
        count($match) === 1 && (int) ($users['total'] ?? 0) === 1,
        'Admin account filters must report only the matching registration'
        . ' (items=' . count($match) . ', total=' . (int) ($users['total'] ?? 0) . ').'
    );
    $assert(
        (int) ($users['page'] ?? 0) === 1 && (int) ($users['pageSize'] ?? 0) === 10,
        'Admin account pagination must preserve the requested page and page size.'
    );
    $assert(
        isset($match[0]['professionalProfile']['displayName']),
        'Admin account pages must include a lightweight professional summary.'
    );
    $id = (int)$match[0]['id'];
    [$adminProfileStatus, $adminProfile] = $request('GET', "/api/profiles/{$id}", null, $adminCookie);
    $mediaId = (int)($adminProfile['media'][0]['id'] ?? 0);
    $assert(
        $adminProfileStatus === 200
        && $mediaId > 0
        && str_contains((string) ($adminProfile['media'][0]['srcSet'] ?? ''), "/api/profiles/{$id}/media/{$mediaId}/320.webp 320w")
        && ($adminProfile['birthDate'] ?? null) === '1995-05-12',
        'Admin must see complete profile data and responsive media while reviewing a pending account.'
    );
    [$privateMediaStatus] = $request('GET', "/api/profiles/{$id}/media/{$mediaId}");
    [$privateVariantStatus] = $request('GET', "/api/profiles/{$id}/media/{$mediaId}/320.webp");
    $assert(
        $privateMediaStatus === 404 && $privateVariantStatus === 404,
        'Pending profile media and its responsive variants must not be public.'
    );
    [$approvalStatus, $approvalBody] = $request('PATCH', "/api/admin/users/{$id}/approval", ['status' => 'APPROVED'], $adminCookie);
    $assert(
        $approvalStatus === 200 && in_array($approvalBody['approvalEmailStatus'] ?? null, ['SENT', 'LOGGED'], true),
        'Admin approval must work and trigger the approval email.'
    );
    $approvalEmail = Database::connection()->prepare(
        'SELECT delivery_status, body FROM email_outbox WHERE recipient = :recipient AND template = :template ORDER BY id DESC LIMIT 1'
    );
    $approvalEmail->execute(['recipient' => $cleanup->email('profile'), 'template' => 'account_approved']);
    $approvalEmailRow = $approvalEmail->fetch();
    $assert(
        is_array($approvalEmailRow)
        && in_array($approvalEmailRow['delivery_status'] ?? null, ['SENT', 'LOGGED'], true)
        && str_contains((string) ($approvalEmailRow['body'] ?? ''), '/auth/login'),
        'Approved accounts must receive an email with a sign-in link.'
    );
    [$listingStatus, $listing] = $request('GET', "/api/listings/{$id}");
    $assert(
        $listingStatus === 200
        && count($listing['media'] ?? []) === 1
        && !array_key_exists('birthDate', $listing)
        && !array_key_exists('legalName', $listing)
        && !array_key_exists('businessAddress', $listing)
        && !array_key_exists('contactEmail', $listing)
        && ($listing['city'] ?? null) === 'Saint-Gilles'
        && ($listing['province'] ?? null) === 'Brussels-Capital'
        && ($listing['country'] ?? null) === 'Belgium'
        && !array_key_exists('priceFrom', $listing)
        && !array_key_exists('priceTo', $listing)
        && (float) ($listing['priceHour'] ?? 0) === 50.0
        && (float) ($listing['priceNight'] ?? 0) === 250.0
        && ($listing['priceWeekend'] ?? null) === null
        && ($listing['hasContactEmail'] ?? false) === true
        && ($listing['media'][0]['srcSet'] ?? '') === "/api/profiles/{$id}/media/{$mediaId}/320.webp 320w"
        && !array_key_exists('fileName', $listing['media'][0] ?? []),
        'Approved listings must expose responsive media and contact availability while withholding private fields.'
    );
    $viewsStatement = Database::connection()->prepare(
        'SELECT views_count FROM professional_profiles WHERE user_id=:id'
    );
    $viewsStatement->execute(['id' => $id]);
    $viewsAfterDetail = (int) $viewsStatement->fetchColumn();
    $assert(
        (int) ($listing['viewsCount'] ?? 0) === 1 && $viewsAfterDetail === 1,
        'Opening a public profile detail must record exactly one real visit.'
    );
    $additionalPhoto = (string) file_get_contents($fixture);
    $database = Database::connection();
    $driver = (string) $database->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'mysql') {
        $insertAdditionalPhoto = $database->prepare(
            'INSERT INTO profile_media '
            . '(user_id,media_type,file_name,content_type,size_bytes,media_data,position,created_at) '
            . "VALUES (:user,'PHOTO','secondary-integration.png','image/png',:size,:data,10,CURRENT_TIMESTAMP(3))"
        );
        $insertAdditionalPhoto->bindValue(':user', $id, PDO::PARAM_INT);
        $insertAdditionalPhoto->bindValue(':size', strlen($additionalPhoto), PDO::PARAM_INT);
        $insertAdditionalPhoto->bindValue(':data', $additionalPhoto, PDO::PARAM_LOB);
        $insertAdditionalPhoto->execute();
    } else {
        $database->prepare(
            'INSERT INTO profile_media '
            . '(user_id,media_type,file_name,content_type,size_bytes,media_data,position,created_at) '
            . "VALUES (:user,'PHOTO','secondary-integration.png','image/png',:size,decode(:data,'base64'),10,CURRENT_TIMESTAMP)"
        )->execute([
            'user' => $id,
            'size' => strlen($additionalPhoto),
            'data' => base64_encode($additionalPhoto),
        ]);
    }
    [$listingsStatus, $listings] = $request(
        'GET',
        '/api/listings?pageSize=50&q=' . rawurlencode($profileName)
    );
    $listedProfile = array_values(array_filter(
        $listings['items'] ?? [],
        static fn (array $item): bool => (int) ($item['id'] ?? 0) === $id
    ));
    $assert(
        $listingsStatus === 200
        && count($listedProfile) === 1
        && count($listedProfile[0]['media'] ?? []) === 1
        && (int) ($listedProfile[0]['media'][0]['id'] ?? 0) === $mediaId
        && !array_key_exists('priceFrom', $listedProfile[0])
        && !array_key_exists('priceTo', $listedProfile[0])
        && !array_key_exists('priceHour', $listedProfile[0])
        && !array_key_exists('priceNight', $listedProfile[0])
        && !array_key_exists('priceWeekend', $listedProfile[0])
        && !array_key_exists('fileName', $listedProfile[0]['media'][0] ?? []),
        'The listings collection must include public media while withholding names of files and every price.'
    );
    [$nearbyStatus, $nearbyListings] = $request(
        'GET',
        '/api/listings?type=ESCORT&pageSize=50&nearCity=saint%20gilles&nearRegion=brussels%20capital&nearCountry=Belgique'
    );
    $assert(
        $nearbyStatus === 200
        && (int) ($nearbyListings['items'][0]['id'] ?? 0) === $id,
        'Location ranking must ignore accents, punctuation, casing and common translated country names.'
    );
    [$favoriteAddStatus] = $request('POST', "/api/favorites/{$id}", [], $visitorCookie);
    [$favoritesStatus, $favoriteProfiles] = $request('GET', '/api/favorites', null, $visitorCookie);
    [$favoriteRemoveStatus] = $request('DELETE', "/api/favorites/{$id}", null, $visitorCookie);
    $favoriteProfile = array_values(array_filter(
        $favoriteProfiles['items'] ?? [],
        static fn (array $item): bool => (int) ($item['id'] ?? 0) === $id
    ));
    $viewsStatement->execute(['id' => $id]);
    $viewsAfterFavoriteActivity = (int) $viewsStatement->fetchColumn();
    $assert(
        $favoriteAddStatus === 201
        && $favoritesStatus === 200
        && $favoriteRemoveStatus === 204
        && count($favoriteProfile) === 1
        && count($favoriteProfile[0]['media'] ?? []) === 1
        && (int) ($favoriteProfile[0]['media'][0]['id'] ?? 0) === $mediaId
        && !array_key_exists('priceHour', $favoriteProfile[0])
        && !array_key_exists('priceNight', $favoriteProfile[0])
        && !array_key_exists('priceWeekend', $favoriteProfile[0])
        && $viewsAfterFavoriteActivity === $viewsAfterDetail,
        'Favorites must load public cards in bulk without exposing prices or recording artificial visits.'
    );
    $mediaContext = stream_context_create(['http' => [
        'method' => 'GET',
        'ignore_errors' => true,
    ]]);
    $approvedMedia = file_get_contents(
        $base . "/api/profiles/{$id}/media/{$mediaId}",
        false,
        $mediaContext
    );
    $approvedMediaHeaders = $http_response_header ?? [];
    preg_match('/\s(\d{3})\s/', $approvedMediaHeaders[0] ?? '', $approvedMediaStatusMatch);
    $cacheControlHeader = null;
    $contentDispositionHeader = null;
    foreach ($approvedMediaHeaders as $header) {
        if (stripos($header, 'Cache-Control:') === 0) {
            $cacheControlHeader = strtolower(trim(substr($header, strlen('Cache-Control:'))));
        }
        if (stripos($header, 'Content-Disposition:') === 0) {
            $contentDispositionHeader = strtolower(trim(substr($header, strlen('Content-Disposition:'))));
        }
    }
    $assert(
        (int) ($approvedMediaStatusMatch[1] ?? 0) === 200
        && is_string($approvedMedia)
        && $approvedMedia !== ''
        && $cacheControlHeader === 'private, no-store'
        && is_string($contentDispositionHeader)
        && str_contains($contentDispositionHeader, "profile-photo-{$mediaId}.png")
        && !str_contains($contentDispositionHeader, 'profile.png'),
        'Profile media must avoid caches and original file names.'
    );

    $variantContext = stream_context_create(['http' => [
        'method' => 'GET',
        'header' => 'Accept: image/webp',
        'ignore_errors' => true,
    ]]);
    $responsiveMedia = file_get_contents(
        $base . "/api/profiles/{$id}/media/{$mediaId}/320.webp",
        false,
        $variantContext
    );
    $responsiveHeaders = $http_response_header ?? [];
    preg_match('/\s(\d{3})\s/', $responsiveHeaders[0] ?? '', $responsiveStatusMatch);
    $responsiveHeaderMap = [];
    foreach ($responsiveHeaders as $header) {
        if (str_contains($header, ':')) {
            [$headerName, $headerValue] = explode(':', $header, 2);
            $responsiveHeaderMap[strtolower(trim($headerName))] = strtolower(trim($headerValue));
        }
    }
    $responsiveDimensions = is_string($responsiveMedia) ? getimagesizefromstring($responsiveMedia) : false;
    $storedVariantStatement = Database::connection()->prepare(
        'SELECT COUNT(*) FROM responsive_image_variants WHERE profile_media_id=:media'
    );
    $storedVariantStatement->execute(['media' => $mediaId]);
    $assert(
        (int) ($responsiveStatusMatch[1] ?? 0) === 200
        && is_string($responsiveMedia)
        && str_starts_with($responsiveMedia, 'RIFF')
        && substr($responsiveMedia, 8, 4) === 'WEBP'
        && is_array($responsiveDimensions)
        && (int) $responsiveDimensions[0] === 320
        && ($responsiveHeaderMap['content-type'] ?? '') === 'image/webp'
        && ($responsiveHeaderMap['cache-control'] ?? '') === 'public, max-age=86400, must-revalidate'
        && (int) $storedVariantStatement->fetchColumn() === 1,
        'Approved profile photos must serve only cached WebP variants that do not upscale the source.'
    );

    $videoFixture = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $database = Database::connection();
    $driver = (string) $database->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'mysql') {
        $insertVideo = $database->prepare(
            'INSERT INTO profile_media '
            . '(user_id,media_type,file_name,content_type,size_bytes,media_data,position,created_at) '
            . "VALUES (:user,'VIDEO','integration.webm','video/webm',:size,:data,1,CURRENT_TIMESTAMP(3))"
        );
        $insertVideo->bindValue(':user', $id, PDO::PARAM_INT);
        $insertVideo->bindValue(':size', strlen($videoFixture), PDO::PARAM_INT);
        $insertVideo->bindValue(':data', $videoFixture, PDO::PARAM_LOB);
        $insertVideo->execute();
        $videoId = (int) $database->lastInsertId();
    } else {
        $insertVideo = $database->prepare(
            'INSERT INTO profile_media '
            . '(user_id,media_type,file_name,content_type,size_bytes,media_data,position,created_at) '
            . "VALUES (:user,'VIDEO','integration.webm','video/webm',:size,decode(:data,'base64'),1,CURRENT_TIMESTAMP) "
            . 'RETURNING id'
        );
        $insertVideo->execute([
            'user' => $id,
            'size' => strlen($videoFixture),
            'data' => base64_encode($videoFixture),
        ]);
        $videoId = (int) $insertVideo->fetchColumn();
    }

    [$rangeStatus, $rangeBody, $rangeHeaders] = $binaryRequest(
        'GET',
        "/api/profiles/{$id}/media/{$videoId}",
        ['Range: bytes=5-12']
    );
    $assert(
        $videoId > 0
        && $rangeStatus === 206
        && $rangeBody === substr($videoFixture, 5, 8)
        && ($rangeHeaders['accept-ranges'] ?? null) === 'bytes'
        && ($rangeHeaders['content-range'] ?? null) === 'bytes 5-12/' . strlen($videoFixture)
        && ($rangeHeaders['content-length'] ?? null) === '8',
        'Video requests must return only the requested bytes with complete 206 metadata.'
    );
    [$headStatus, $headBody, $headHeaders] = $binaryRequest(
        'HEAD',
        "/api/profiles/{$id}/media/{$videoId}",
        ['Range: bytes=-4']
    );
    $expectedSuffixContentRange = 'bytes ' . (strlen($videoFixture) - 4) . '-'
        . (strlen($videoFixture) - 1) . '/' . strlen($videoFixture);
    $assert(
        $headStatus === 206
        && $headBody === ''
        && ($headHeaders['content-range'] ?? null) === $expectedSuffixContentRange
        && ($headHeaders['content-length'] ?? null) === '4',
        'HEAD video requests must expose suffix range metadata without reading a response body.'
    );
    [$ifRangeStatus, $ifRangeBody, $ifRangeHeaders] = $binaryRequest(
        'GET',
        "/api/profiles/{$id}/media/{$videoId}",
        ['Range: bytes=0-3', 'If-Range: "outdated-representation"']
    );
    $assert(
        $ifRangeStatus === 200
        && $ifRangeBody === $videoFixture
        && !isset($ifRangeHeaders['content-range']),
        'A stale If-Range validator must safely fall back to the complete video representation.'
    );
    [$invalidRangeStatus, , $invalidRangeHeaders] = $binaryRequest(
        'GET',
        "/api/profiles/{$id}/media/{$videoId}",
        ['Range: bytes=' . strlen($videoFixture) . '-']
    );
    $assert(
        $invalidRangeStatus === 416
        && ($invalidRangeHeaders['accept-ranges'] ?? null) === 'bytes'
        && ($invalidRangeHeaders['content-range'] ?? null) === 'bytes */' . strlen($videoFixture),
        'Unsatisfiable video ranges must return 416 and advertise the current representation size.'
    );

    $manyServices = implode(',', array_fill(0, 15, str_repeat('s', 90)));
    [$boundedFiltersStatus] = $request(
        'GET',
        '/api/listings?q=' . rawurlencode(str_repeat('q', 180))
            . '&city=' . rawurlencode(str_repeat('c', 120))
            . '&services=' . rawurlencode($manyServices)
    );
    $assert($boundedFiltersStatus === 200, 'Oversized public filters must be normalized safely.');

    [$storeRoleStatus] = $request(
        'PATCH',
        "/api/admin/users/{$id}/role",
        ['role' => 'STORE'],
        $adminCookie
    );
    [$storeProfileStatus, $storeProfile] = $request('GET', "/api/profiles/{$id}", null, $adminCookie);
    $assert(
        $storeRoleStatus === 200
        && $storeProfileStatus === 200
        && ($storeProfile['type'] ?? null) === 'STORE',
        'Changing a professional role must keep the persisted profile type synchronized.'
    );
    [$escortRoleStatus] = $request(
        'PATCH',
        "/api/admin/users/{$id}/role",
        ['role' => 'ESCORT'],
        $adminCookie
    );
    [$reapprovalStatus] = $request(
        'PATCH',
        "/api/admin/users/{$id}/approval",
        ['status' => 'APPROVED'],
        $adminCookie
    );
    $assert(
        $escortRoleStatus === 200 && $reapprovalStatus === 200,
        'The integration professional must return to an approved escort account.'
    );

    [$professionalLoginStatus, $professionalLogin, $professionalCookie] = $request('POST', '/api/auth/login', [
        'email' => $cleanup->email('profile'), 'password' => 'Integration9!pass',
    ]);
    $assert($professionalLoginStatus === 200 && is_string($professionalCookie), 'Approved professional login must work.');
    [$optionalRegionStatus, $optionalRegionProfile] = $request(
        'PUT',
        '/api/profiles/me',
        ['locationRegion' => ''],
        $professionalCookie
    );
    $assert(
        $optionalRegionStatus === 200
        && ($optionalRegionProfile['locationRegion'] ?? null) === null
        && ($optionalRegionProfile['location'] ?? null) === 'Saint-Gilles, Belgium',
        'Professional profiles must accept city and country when a state or region does not apply.'
    );
    [$localeStatus, $localizedProfile] = $request(
        'PATCH',
        '/api/auth/locale',
        ['locale' => 'fr'],
        $professionalCookie
    );
    $assert(
        $localeStatus === 200 && ($localizedProfile['locale'] ?? null) === 'fr',
        'Changing the interface language must persist the recipient locale for future Push delivery.'
    );
    [$lockedSelectionStatus, $lockedSelection] = $request(
        'POST',
        "/api/listings/{$id}/purchases",
        ['amount' => null],
        $visitorCookie
    );
    $assert(
        $lockedSelectionStatus === 403 && isset($lockedSelection['fields']['vipRequired']),
        'A visitor without VIP access must not confirm a companion selection.'
    );
    [$lockedContactStatus, $lockedContact] = $request(
        'GET',
        "/api/listings/{$id}/contact",
        null,
        $visitorCookie
    );
    $assert(
        $lockedContactStatus === 403 && isset($lockedContact['fields']['vipRequired']),
        'A visitor without VIP access must not reveal a companion contact email.'
    );
    [$earlyReviewStatus] = $request(
        'POST',
        "/api/listings/{$id}/reviews",
        ['rating' => 5, 'body' => 'This review must wait for a completed selection.'],
        $visitorCookie
    );
    $assert($earlyReviewStatus === 403, 'A visitor must not review a companion before a completed selection.');
    [$lockedConversationStatus, $lockedConversation] = $request(
        'POST',
        '/api/conversations',
        ['recipientId' => $id],
        $visitorCookie
    );
    $assert(
        $lockedConversationStatus === 403 && isset($lockedConversation['fields']['vipRequired']),
        'A visitor without VIP access must not be able to contact a companion.'
    );
    $visitorId = (int) ($visitor['profile']['id'] ?? 0);
    [$vipStatus] = $request(
        'PATCH',
        "/api/admin/users/{$visitorId}/vip",
        ['vipActive' => true],
        $adminCookie
    );
    $assert($visitorId > 0 && $vipStatus === 200, 'An administrator must be able to activate visitor VIP access.');
    $starsBeforeSelection = (int) ($listing['starCount'] ?? 0);
    [$selectionStatus, $selectedProfile] = $request(
        'POST',
        "/api/listings/{$id}/purchases",
        ['amount' => null],
        $visitorCookie
    );
    $viewsStatement->execute(['id' => $id]);
    $viewsAfterSelection = (int) $viewsStatement->fetchColumn();
    $assert(
        $selectionStatus === 201
        && (int) ($selectedProfile['starCount'] ?? -1) === $starsBeforeSelection + 1
        && !array_key_exists('rating', $selectedProfile),
        'A completed VIP selection must add one star count without exposing a score.'
    );
    $assert(
        $viewsAfterSelection === $viewsAfterDetail,
        'Validating and registering a selection must not create profile visits.'
    );
    [$contactStatus, $contact] = $request('GET', "/api/listings/{$id}/contact", null, $visitorCookie);
    $assert(
        $contactStatus === 200
        && ($contact['email'] ?? null) === "contact-{$runId}@example.com"
        && ($contact['responseTime'] ?? null) === 'FEW_HOURS'
        && ($contact['canReview'] ?? false) === true,
        'A VIP visitor must receive protected contact details and review eligibility.'
    );
    [$reviewStatus, $review] = $request(
        'POST',
        "/api/listings/{$id}/reviews",
        ['rating' => 4, 'body' => 'A respectful and verified integration experience.'],
        $visitorCookie
    );
    [$duplicateReviewStatus] = $request(
        'POST',
        "/api/listings/{$id}/reviews",
        ['rating' => 5, 'body' => 'This duplicate review must not be accepted.'],
        $visitorCookie
    );
    [$reviewedListingStatus, $reviewedListing] = $request('GET', "/api/listings/{$id}");
    $assert(
        $reviewStatus === 201
        && (int) ($review['rating'] ?? 0) === 4
        && $duplicateReviewStatus === 403
        && $reviewedListingStatus === 200
        && (float) ($reviewedListing['averageRating'] ?? 0) > 0
        && count($reviewedListing['reviews'] ?? []) > 0
        && (int) ($reviewedListing['viewsCount'] ?? 0) === $viewsAfterDetail + 1,
        'A completed selection must allow one rated comment and update the public average.'
    );
    [$conversationStatus, $conversation] = $request(
        'POST',
        '/api/conversations',
        ['recipientId' => $id],
        $visitorCookie
    );
    $conversationId = (int)($conversation['id'] ?? 0);
    $assert($conversationStatus === 201 && $conversationId > 0, 'A VIP visitor must be able to contact an approved companion.');
    [$preferenceStatus, $defaultPreferences] = $request('GET', '/api/notification-preferences', null, $professionalCookie);
    [$savedPreferenceStatus, $savedPreferences] = $request('PUT', '/api/notification-preferences', [
        'directMessages' => true,
        'accountUpdates' => false,
        'marketplaceUpdates' => false,
        'securityUpdates' => false,
        'browserNotifications' => true,
        'inAppNotifications' => true,
        'onlyDirectMessages' => false,
        'dailyDigest' => false,
        'dailyDigestTime' => '09:00',
        'quietHoursEnabled' => true,
        'quietHoursStart' => '22:00',
        'quietHoursEnd' => '08:00',
        'timezone' => 'Europe/Brussels',
    ], $professionalCookie);
    $assert(
        $preferenceStatus === 200
        && ($defaultPreferences['directMessages'] ?? false) === true
        && $savedPreferenceStatus === 200
        && ($savedPreferences['accountUpdates'] ?? false) === true
        && ($savedPreferences['marketplaceUpdates'] ?? true) === false
        && ($savedPreferences['securityUpdates'] ?? false) === true
        && ($savedPreferences['quietHoursEnabled'] ?? false) === true
        && ($savedPreferences['timezone'] ?? '') === 'Europe/Brussels',
        'Notification preferences must load with safe defaults and persist per account.'
    );
    $fakePushEndpoint = 'https://push.example.invalid/' . rawurlencode($runId);
    [$subscribeStatus] = $request('POST', '/api/push-subscriptions', [
        'endpoint' => $fakePushEndpoint,
        'keys' => ['p256dh' => 'integration-public-key', 'auth' => 'integration-auth-token'],
        'contentEncoding' => 'aes128gcm',
    ], $professionalCookie);
    [$messageStatus, $sentMessage] = $request(
        'POST',
        "/api/conversations/{$conversationId}/messages",
        ['body' => 'Fast incremental integration message.'],
        $visitorCookie
    );
    $messageId = (int) ($sentMessage['id'] ?? 0);
    [$messagePageStatus, $messagePage] = $request(
        'GET',
        "/api/conversations/{$conversationId}/messages?before=" . ($messageId + 1) . '&limit=25',
        null,
        $professionalCookie
    );
    [$incrementalStatus, $incrementalPage] = $request(
        'GET',
        "/api/conversations/{$conversationId}/messages?after={$messageId}&limit=25",
        null,
        $professionalCookie
    );
    [$notificationStatus, $notificationFeed] = $request('GET', '/api/notifications', null, $professionalCookie);
    $messageNotification = array_values(array_filter(
        $notificationFeed['items'] ?? [],
        static fn (array $item): bool => ($item['eventType'] ?? null) === 'MESSAGE_RECEIVED'
    ))[0] ?? null;
    $outboxStatement = Database::connection()->prepare(
        'SELECT outbox.status FROM push_notification_outbox outbox '
        . 'INNER JOIN account_notifications notification ON notification.id=outbox.notification_id '
        . 'WHERE notification.user_id=:user_id AND notification.dedupe_key=:dedupe_key'
    );
    $outboxStatement->execute(['user_id' => $id, 'dedupe_key' => 'message:' . $messageId]);
    $outboxStatus = $outboxStatement->fetchColumn();
    $realtimeStatement = Database::connection()->prepare(
        'SELECT user_id FROM realtime_events '
        . 'WHERE event_type=:event_type AND resource_id=:conversation_id ORDER BY user_id'
    );
    $realtimeStatement->execute([
        'event_type' => 'MESSAGE_CREATED',
        'conversation_id' => $conversationId,
    ]);
    $realtimeRecipients = array_map('intval', $realtimeStatement->fetchAll(PDO::FETCH_COLUMN));
    [$markConversationReadStatus] = $request(
        'PATCH',
        "/api/conversations/{$conversationId}/read",
        [],
        $professionalCookie
    );
    [$readNotificationStatus, $readNotificationFeed] = $request(
        'GET',
        '/api/notifications',
        null,
        $professionalCookie
    );
    [$unsubscribeStatus] = $request(
        'DELETE',
        '/api/push-subscriptions',
        ['endpoint' => $fakePushEndpoint],
        $professionalCookie
    );
    $assert(
        $subscribeStatus === 201
        && $messageStatus === 201
        && $messageId > 0
        && $messagePageStatus === 200
        && count($messagePage['items'] ?? []) === 1
        && array_key_exists('peerReadAt', $messagePage ?? [])
        && $incrementalStatus === 200
        && count($incrementalPage['items'] ?? []) === 0
        && $notificationStatus === 200
        && ($notificationFeed['unreadByCategory']['DIRECT_MESSAGE'] ?? 0) >= 1
        && ($messageNotification['params']['senderName'] ?? '') !== ''
        && is_string($outboxStatus)
        && in_array($outboxStatus, ['PENDING', 'PROCESSING', 'RETRY', 'DELIVERED', 'FAILED'], true)
        && in_array($visitorId, $realtimeRecipients, true)
        && in_array($id, $realtimeRecipients, true)
        && $markConversationReadStatus === 204
        && $readNotificationStatus === 200
        && ($readNotificationFeed['unreadByCategory']['DIRECT_MESSAGE'] ?? 0) === 0
        && $unsubscribeStatus === 204,
        'Messages must use incremental pages and notify both participants through persistent realtime events.'
    );
    [$rejectedStatus] = $request(
        'PATCH',
        "/api/admin/users/{$id}/approval",
        ['status' => 'REJECTED', 'reason' => 'Integration moderation check'],
        $adminCookie
    );
    [$rejectedMessageStatus] = $request(
        'POST',
        "/api/conversations/{$conversationId}/messages",
        ['body' => 'This message must not be accepted.'],
        $visitorCookie
    );
    $assert(
        $rejectedStatus === 200 && $rejectedMessageStatus === 403,
        'An existing conversation must not accept messages to a rejected account.'
    );
    [$approvedAgainStatus] = $request(
        'PATCH',
        "/api/admin/users/{$id}/approval",
        ['status' => 'APPROVED'],
        $adminCookie
    );
    $assert($approvedAgainStatus === 200, 'The moderated integration account must be restorable.');
    [$deleteStatus] = $request('DELETE', "/api/conversations/{$conversationId}", null, $visitorCookie);
    [$deletedAccessStatus] = $request(
        'GET',
        "/api/conversations/{$conversationId}/messages",
        null,
        $visitorCookie
    );
    $assert($deleteStatus === 204 && $deletedAccessStatus === 404, 'A conversation removed from an inbox must not remain accessible by ID.');
    [$participantAccessStatus] = $request(
        'GET',
        "/api/conversations/{$conversationId}/messages",
        null,
        $professionalCookie
    );
    $assert($participantAccessStatus === 200, 'Deleting one inbox copy must preserve the other participant copy.');
    $currentDeviceEndpoint = 'https://push.example.invalid/current-' . rawurlencode($runId);
    $otherDeviceEndpoint = 'https://push.example.invalid/other-' . rawurlencode($runId);
    [$currentDeviceStatus, , $currentDeviceCookie] = $request('POST', '/api/push-subscriptions', [
        'endpoint' => $currentDeviceEndpoint,
        'keys' => ['p256dh' => 'current-device-key', 'auth' => 'current-device-auth'],
        'contentEncoding' => 'aes128gcm',
    ], $professionalCookie);
    [$otherDeviceStatus] = $request('POST', '/api/push-subscriptions', [
        'endpoint' => $otherDeviceEndpoint,
        'keys' => ['p256dh' => 'other-device-key', 'auth' => 'other-device-auth'],
        'contentEncoding' => 'aes128gcm',
    ], $professionalCookie);
    [$logoutStatus] = $request(
        'POST',
        '/api/auth/logout',
        [],
        $professionalCookie . '; ' . $currentDeviceCookie
    );
    $remainingPushStatement = Database::connection()->prepare(
        'SELECT endpoint FROM push_subscriptions WHERE user_id=:user_id ORDER BY endpoint'
    );
    $remainingPushStatement->execute(['user_id' => $id]);
    $remainingPushEndpoints = $remainingPushStatement->fetchAll(PDO::FETCH_COLUMN);
    $assert(
        $currentDeviceStatus === 201
        && is_string($currentDeviceCookie)
        && str_starts_with($currentDeviceCookie, 'chambre_rose_push_device=')
        && $otherDeviceStatus === 201
        && $logoutStatus === 204
        && !in_array($currentDeviceEndpoint, $remainingPushEndpoints, true)
        && in_array($otherDeviceEndpoint, $remainingPushEndpoints, true),
        'Logout must revoke only the current browser Push subscription and preserve other devices.'
    );
}

$cleanupFixtures();
fwrite(STDOUT, "OK - {$assertions} integration assertions; database cleanup verified\n");
