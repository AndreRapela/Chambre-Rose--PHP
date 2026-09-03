<?php

declare(strict_types=1);

$base = rtrim(getenv('TEST_API_URL') ?: 'http://localhost:8080', '/');
$adminEmail = getenv('TEST_ADMIN_EMAIL') ?: 'admin@admin.com';
$adminPassword = getenv('TEST_ADMIN_PASSWORD') ?: '';
$fixture = dirname(__DIR__) . '/resources/brand/brand-logo.png';
$suffix = (string)time();
$assertions = 0;

$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$request = static function (string $method, string $path, ?array $body = null, ?string $token = null) use ($base): array {
    $headers = ['Accept: application/json'];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
    }
    if ($token !== null) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    $context = stream_context_create(['http' => [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'content' => $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR),
        'ignore_errors' => true,
    ]]);
    $raw = file_get_contents($base . $path, false, $context);
    $statusLine = $http_response_header[0] ?? '';
    preg_match('/\s(\d{3})\s/', $statusLine, $match);

    return [(int)($match[1] ?? 0), is_string($raw) && $raw !== '' ? json_decode($raw, true) : null];
};

[$visitorStatus, $visitor] = $request('POST', '/api/auth/register', [
    'firstName' => 'Visitor', 'lastName' => 'Integration',
    'email' => "visitor-{$suffix}@example.com", 'phone' => '12345678',
    'password' => 'Integration9!pass', 'accountType' => 'VISITOR',
]);
$assert($visitorStatus === 201 && isset($visitor['token']), 'Visitor registration must return a token.');

[$weakPasswordStatus, $weakPasswordBody] = $request('POST', '/api/auth/register', [
    'firstName' => 'Weak', 'lastName' => 'Password',
    'email' => "weak-password-{$suffix}@example.com", 'phone' => '12345678',
    'password' => 'password', 'accountType' => 'VISITOR',
]);
$assert(
    $weakPasswordStatus === 400 && isset($weakPasswordBody['fields']['password']),
    'Registration must reject a password that does not meet every visible strength rule.'
);

$profile = json_encode([
    'displayName' => 'Profile Integration', 'birthDate' => '1995-05-12', 'gender' => 'woman',
    'location' => 'Brussels', 'bio' => 'Integration profile', 'languages' => ['fr'],
    'services' => ['massage'],
], JSON_THROW_ON_ERROR);
$boundary = 'integration-' . bin2hex(random_bytes(12));
$fields = [
    'firstName' => 'Profile', 'lastName' => 'Integration',
    'email' => "profile-{$suffix}@example.com", 'phone' => '12345678',
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
$assert($professionalStatus === 201 && ($professional['approvalStatus'] ?? null) === 'PENDING', 'Professional registration must be pending.');

$futureProfile = json_encode([
    'displayName' => 'Invalid Future Profile',
    'birthDate' => '2099-05-12',
    'gender' => 'woman',
    'location' => 'Brussels',
], JSON_THROW_ON_ERROR);
$futureBoundary = 'future-profile-' . bin2hex(random_bytes(12));
$futureFields = $fields;
$futureFields['email'] = "future-profile-{$suffix}@example.com";
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
$unsafeFields['email'] = "unsafe-website-{$suffix}@example.com";
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
    'email' => "profile-{$suffix}@example.com", 'password' => 'Integration9!pass',
]);
$assert($pendingStatus === 403, 'Pending professional login must be blocked.');

if ($adminPassword !== '') {
    [$adminStatus, $admin] = $request('POST', '/api/auth/login', ['email' => $adminEmail, 'password' => $adminPassword]);
    $assert($adminStatus === 200, 'Admin login must work.');
    [$productCreateStatus, $testProduct] = $request('POST', '/api/products', [
        'name' => 'Integration Product',
        'category' => 'wellness',
        'price' => 29.9,
        'imageUrl' => '/assets/carousel-pink-lace-tie.jpeg',
        'description' => 'Integration product for purchase-count validation.',
        'storeName' => 'Integration Store',
        'active' => true,
    ], $admin['token']);
    $productId = (int) ($testProduct['id'] ?? 0);
    [$productPurchaseStatus, $purchasedProduct] = $request(
        'POST',
        "/api/products/{$productId}/purchases",
        [],
        $visitor['token']
    );
    $assert(
        $productCreateStatus === 201
        && $productId > 0
        && $productPurchaseStatus === 201
        && (int) ($purchasedProduct['starCount'] ?? -1) === 1
        && !array_key_exists('rating', $purchasedProduct),
        'A non-VIP visitor must be able to buy a store product and add exactly one star count.'
    );
    [$usersStatus, $users] = $request('GET', '/api/admin/users', null, $admin['token']);
    $match = array_values(array_filter($users, static fn (array $user): bool => $user['email'] === "profile-{$suffix}@example.com"));
    $assert($usersStatus === 200 && count($match) === 1, 'Admin must see the pending account.');
    $id = (int)$match[0]['id'];
    [$adminProfileStatus, $adminProfile] = $request('GET', "/api/profiles/{$id}", null, $admin['token']);
    $mediaId = (int)($adminProfile['media'][0]['id'] ?? 0);
    $assert(
        $adminProfileStatus === 200
        && $mediaId > 0
        && ($adminProfile['birthDate'] ?? null) === '1995-05-12',
        'Admin must see complete profile data and stored media while reviewing a pending account.'
    );
    [$privateMediaStatus] = $request('GET', "/api/profiles/{$id}/media/{$mediaId}");
    $assert($privateMediaStatus === 404, 'Pending profile media must not be public.');
    [$approvalStatus] = $request('PATCH', "/api/admin/users/{$id}/approval", ['status' => 'APPROVED'], $admin['token']);
    $assert($approvalStatus === 200, 'Admin approval must work.');
    [$listingStatus, $listing] = $request('GET', "/api/listings/{$id}");
    $assert(
        $listingStatus === 200
        && count($listing['media'] ?? []) === 1
        && !array_key_exists('birthDate', $listing)
        && !array_key_exists('legalName', $listing)
        && !array_key_exists('businessAddress', $listing)
        && !array_key_exists('fileName', $listing['media'][0] ?? []),
        'Approved listings must expose media and withhold private professional fields.'
    );
    [$listingsStatus, $listings] = $request(
        'GET',
        '/api/listings?pageSize=50&q=' . rawurlencode('Profile Integration')
    );
    $listedProfile = array_values(array_filter(
        $listings['items'] ?? [],
        static fn (array $item): bool => (int) ($item['id'] ?? 0) === $id
    ));
    $assert(
        $listingsStatus === 200
        && count($listedProfile) === 1
        && (int) ($listedProfile[0]['media'][0]['id'] ?? 0) === $mediaId
        && !array_key_exists('fileName', $listedProfile[0]['media'][0] ?? []),
        'The listings collection must include batched public media without original file names.'
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
        $admin['token']
    );
    [$storeProfileStatus, $storeProfile] = $request('GET', "/api/profiles/{$id}", null, $admin['token']);
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
        $admin['token']
    );
    [$reapprovalStatus] = $request(
        'PATCH',
        "/api/admin/users/{$id}/approval",
        ['status' => 'APPROVED'],
        $admin['token']
    );
    $assert(
        $escortRoleStatus === 200 && $reapprovalStatus === 200,
        'The integration professional must return to an approved escort account.'
    );

    [$professionalLoginStatus, $professionalLogin] = $request('POST', '/api/auth/login', [
        'email' => "profile-{$suffix}@example.com", 'password' => 'Integration9!pass',
    ]);
    $assert($professionalLoginStatus === 200, 'Approved professional login must work.');
    [$lockedSelectionStatus, $lockedSelection] = $request(
        'POST',
        "/api/listings/{$id}/purchases",
        ['amount' => null],
        $visitor['token']
    );
    $assert(
        $lockedSelectionStatus === 403 && isset($lockedSelection['fields']['vipRequired']),
        'A visitor without VIP access must not confirm a companion selection.'
    );
    [$lockedConversationStatus, $lockedConversation] = $request(
        'POST',
        '/api/conversations',
        ['recipientId' => $id],
        $visitor['token']
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
        $admin['token']
    );
    $assert($visitorId > 0 && $vipStatus === 200, 'An administrator must be able to activate visitor VIP access.');
    $starsBeforeSelection = (int) ($listing['starCount'] ?? 0);
    [$selectionStatus, $selectedProfile] = $request(
        'POST',
        "/api/listings/{$id}/purchases",
        ['amount' => null],
        $visitor['token']
    );
    $assert(
        $selectionStatus === 201
        && (int) ($selectedProfile['starCount'] ?? -1) === $starsBeforeSelection + 1
        && !array_key_exists('rating', $selectedProfile),
        'A completed VIP selection must add one star count without exposing a score.'
    );
    [$conversationStatus, $conversation] = $request(
        'POST',
        '/api/conversations',
        ['recipientId' => $id],
        $visitor['token']
    );
    $conversationId = (int)($conversation['id'] ?? 0);
    $assert($conversationStatus === 201 && $conversationId > 0, 'A VIP visitor must be able to contact an approved companion.');
    [$rejectedStatus] = $request(
        'PATCH',
        "/api/admin/users/{$id}/approval",
        ['status' => 'REJECTED', 'reason' => 'Integration moderation check'],
        $admin['token']
    );
    [$rejectedMessageStatus] = $request(
        'POST',
        "/api/conversations/{$conversationId}/messages",
        ['body' => 'This message must not be accepted.'],
        $visitor['token']
    );
    $assert(
        $rejectedStatus === 200 && $rejectedMessageStatus === 403,
        'An existing conversation must not accept messages to a rejected account.'
    );
    [$approvedAgainStatus] = $request(
        'PATCH',
        "/api/admin/users/{$id}/approval",
        ['status' => 'APPROVED'],
        $admin['token']
    );
    $assert($approvedAgainStatus === 200, 'The moderated integration account must be restorable.');
    [$deleteStatus] = $request('DELETE', "/api/conversations/{$conversationId}", null, $visitor['token']);
    [$deletedAccessStatus] = $request(
        'GET',
        "/api/conversations/{$conversationId}/messages",
        null,
        $visitor['token']
    );
    $assert($deleteStatus === 204 && $deletedAccessStatus === 404, 'A conversation removed from an inbox must not remain accessible by ID.');
    [$participantAccessStatus] = $request(
        'GET',
        "/api/conversations/{$conversationId}/messages",
        null,
        $professionalLogin['token']
    );
    $assert($participantAccessStatus === 200, 'Deleting one inbox copy must preserve the other participant copy.');
}

fwrite(STDOUT, "OK - {$assertions} integration assertions\n");
