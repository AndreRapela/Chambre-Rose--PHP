<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use ChambreRose\ApiException;
use ChambreRose\ApiRequestGuard;
use ChambreRose\AuthSessionCookie;
use ChambreRose\Config;
use ChambreRose\Database;
use ChambreRose\Jwt;
use ChambreRose\MarketplaceService;
use ChambreRose\ProfessionalProfileRepository;
use ChambreRose\ProfileCardCoverRepository;
use ChambreRose\ProfileCardCoverRoutes;
use ChambreRose\ProfileCardCoverService;
use ChambreRose\ProfileMediaRepository;
use ChambreRose\Request;
use ChambreRose\ResponsiveImageProcessor;
use ChambreRose\ResponsiveImageService;
use ChambreRose\ResponsiveImageVariantRepository;
use ChambreRose\UploadedFile;
use ChambreRose\UserRepository;
use ChambreRose\UserExclusionRepository;

if (Config::get('APP_ENV') !== 'development') {
    throw new RuntimeException('Card cover integration checks require a development database.');
}

$pdo = Database::connection();
$users = new UserRepository($pdo);
$profiles = new ProfessionalProfileRepository($pdo);
$media = new ProfileMediaRepository($pdo);
$covers = new ProfileCardCoverRepository($pdo);
$images = new ResponsiveImageService(new ResponsiveImageProcessor(), new ResponsiveImageVariantRepository($pdo));
$marketplace = new MarketplaceService($users, $profiles, $media, $images, new UserExclusionRepository($pdo), $covers);
$service = new ProfileCardCoverService($covers, new ResponsiveImageProcessor());
$jwt = new Jwt();
$guard = new ApiRequestGuard($users, $jwt, new AuthSessionCookie($jwt));
$routes = new ProfileCardCoverRoutes($covers, $service, $marketplace, $guard);
$created = [];
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$rejects = static function (callable $call, int $status) use ($assert): void {
    try {
        $call();
    } catch (ApiException $exception) {
        $assert($exception->status === $status, 'Expected HTTP ' . $status . ', got ' . $exception->status);
        return;
    }
    throw new RuntimeException('Expected rejection with HTTP ' . $status);
};
$create = static function (string $role, string $gender = '') use ($users, $profiles, &$created): array {
    $email = 'card-cover-qa-' . bin2hex(random_bytes(12)) . '@example.invalid';
    $user = $users->create([
        'email' => $email, 'firstName' => 'Cover', 'lastName' => 'QA', 'phone' => '+32470123456',
        'address' => 'Test street', 'city' => 'Brussels', 'country' => 'Belgium', 'postalCode' => '1000',
    ], password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT), $role);
    $created[(int) $user['id']] = $email;
    if (in_array($role, ['ESCORT', 'STORE'], true)) {
        $profiles->upsert((int) $user['id'], $role, ['displayName' => 'Cover QA', 'gender' => $gender]);
    }
    return $user;
};
$request = static function (string $method, string $path, ?array $user = null, array $query = [], ?UploadedFile $file = null) use ($jwt): Request {
    $headers = [];
    if ($user !== null) $headers['authorization'] = 'Bearer ' . $jwt->generate($user['email'], $user['role'], $user['passwordHash']);
    if ($file !== null) $headers['content-type'] = 'multipart/form-data; boundary=cover';
    $request = new Request($method, $path, $headers, $query);
    if ($file !== null) (new ReflectionProperty(Request::class, 'parsedMultipart'))->setValue($request, ['fields' => [], 'files' => ['image' => $file]]);
    return $request;
};
$makePhoto = static function (int $width, int $height, int $red): UploadedFile {
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, imagecolorallocate($image, $red, 30, 80));
    ob_start();
    imagepng($image);
    $bytes = ob_get_clean();
    unset($image);
    return new UploadedFile('cover.png', 'image/png', strlen($bytes), null, $bytes);
};

try {
    $visitor = $create('VISITOR');
    $photo = $makePhoto(960, 768, 200);
    $rejects(fn () => $routes->handle($request('POST', '/api/profiles/me/card-cover', null, [], $photo)), 401);
    $rejects(fn () => $routes->handle($request('POST', '/api/profiles/me/card-cover', $visitor, [], $photo)), 403);
    foreach ([['ESCORT', 'WOMAN'], ['ESCORT', 'MAN'], ['ESCORT', 'TRANS'], ['STORE', '']] as [$role, $gender]) {
        $owner = $create($role, $gender);
        $userId = (int) $owner['id'];
        $galleryPhoto = $marketplace->upload($userId, $makePhoto(640, 800, 30), 0, true);
        $before = $marketplace->ownProfile($userId);
        $response = $routes->handle($request('POST', '/api/profiles/me/card-cover', $owner, [], $photo));
        $assert($response?->status === 201, 'Professional cover upload succeeds.');
        $cover = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        $after = $marketplace->ownProfile($userId);
        $assert($after['media'] === $before['media'], 'Cover upload leaves gallery inventory unchanged.');
        $assert($after['profileImageUrl'] === $before['profileImageUrl'], 'Cover upload leaves avatar unchanged.');
        $assert($after['cardCoverUrl'] === $cover['cardCoverUrl'], 'Private profile returns the saved cover.');
        $assert(count(explode(', ', $cover['cardCoverSrcSet'])) === 3, 'Cover has precomputed 320/640/960 variants.');
        $public = $marketplace->publicListingsByUserIds([$userId]);
        $assert($public[0]['cardCoverUrl'] === $cover['cardCoverUrl'], 'Catalogue returns the independent cover.');
        $url = parse_url($cover['cardCoverUrl']);
        parse_str($url['query'], $query);
        $imageResponse = $routes->handle($request('GET', $url['path'], null, $query));
        $assert($imageResponse?->status === 200 && strlen($imageResponse->body) > 0, 'Cover is publicly readable.');
        $dimensions = getimagesizefromstring($imageResponse->body);
        $assert($dimensions[0] === 960 && $dimensions[1] === 768, 'Rectangular framing is retained.');
        $revalidate = new Request('GET', $url['path'], ['if-none-match' => $imageResponse->headers['ETag']], $query);
        $assert($routes->handle($revalidate)?->status === 304, 'Cover supports conditional GET without reprocessing.');
        $assert($routes->handle($request('HEAD', $url['path'], null, $query))?->body === '', 'HEAD does not read image bytes.');
        $rejects(fn () => $routes->handle($request('GET', $url['path'], null, ['v' => 'stale'])), 404);
        $bad = new UploadedFile('cover.svg', 'image/png', 18, null, '<svg>not raster</svg>');
        $rejects(fn () => $routes->handle($request('POST', '/api/profiles/me/card-cover', $owner, [], $bad)), 400);
        $assert($covers->forUser($userId)['cardCoverUrl'] === $cover['cardCoverUrl'], 'Failed upload preserves the previous cover.');
        $users->setApproval($userId, 'REJECTED', 'QA only');
        $rejects(fn () => $routes->handle($request('GET', $url['path'], null, $query)), 404);
        $users->setApproval($userId, 'APPROVED', null);
        $removedWithGallery = $routes->handle($request('DELETE', '/api/profiles/me/card-cover', $owner));
        $assert($removedWithGallery?->status === 200 && $media->listFor($userId) === $before['media'], 'Removing a cover keeps the gallery photos.');
        $assert($marketplace->ownProfile($userId)['profileImageUrl'] === $before['profileImageUrl'], 'Removing a cover keeps the avatar.');
        $service->upload($userId, $photo);
        $newAvatar = $marketplace->upload($userId, $makePhoto(640, 800, 80), 0, true);
        $assert($covers->forUser($userId)['cardCoverUrl'] === $cover['cardCoverUrl'], 'Changing the avatar does not synchronize the independent cover.');
        $media->delete($userId, $newAvatar['id']);
        $media->delete($userId, $galleryPhoto['id']);
        $assert($covers->forUser($userId)['cardCoverUrl'] === $cover['cardCoverUrl'], 'Deleting a gallery source does not delete the independent cover.');
        $replacement = $service->upload($userId, $makePhoto(100, 80, 50));
        $assert($replacement['cardCoverUrl'] !== $cover['cardCoverUrl'], 'Small photos receive a separate valid cover version.');
        $removed = $routes->handle($request('DELETE', '/api/profiles/me/card-cover', $owner));
        $assert($removed?->status === 200 && $covers->forUser($userId)['cardCoverUrl'] === null, 'Only the cover is removed.');
        $assert($media->listFor($userId) === [], 'Removing the cover does not recreate gallery media.');
    }
    $rejects(fn () => $service->upload((int) $visitor['id'], new UploadedFile('large.jpg', 'image/jpeg', 9 * 1024 * 1024, null, str_repeat('x', 9 * 1024 * 1024))), 413);
} finally {
    foreach ($created as $userId => $email) {
        $delete = $pdo->prepare('DELETE FROM users WHERE id=:id AND email=:email');
        $delete->execute(['id' => $userId, 'email' => $email]);
    }
}
$assert($covers->forUsers(array_keys($created)) === [], 'Deleting temporary users cascades only their cover variants.');
echo 'OK - ' . $assertions . " independent cover assertions; temporary accounts removed\n";
