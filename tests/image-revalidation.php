<?php

declare(strict_types=1);

use ChambreRose\ApiException;
use ChambreRose\ApiRequestGuard;
use ChambreRose\AuthSessionCookie;
use ChambreRose\Jwt;
use ChambreRose\ListingRoutes;
use ChambreRose\MarketplaceService;
use ChambreRose\ProfessionalProfileRepository;
use ChambreRose\ProfileMediaRepository;
use ChambreRose\Request;
use ChambreRose\ResponsiveImageProcessor;
use ChambreRose\ResponsiveImageService;
use ChambreRose\ResponsiveImageVariantRepository;
use ChambreRose\UserRepository;

final class ImageRevalidationTestPdo extends PDO
{
    /** @var list<string> */
    public array $preparedQueries = [];

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->preparedQueries[] = $query;
        return parent::prepare($query, $options);
    }

    public function payloadSelects(): int
    {
        return count(array_filter($this->preparedQueries, static fn (string $sql): bool =>
            preg_match('/^\s*SELECT\b/i', $sql) === 1
            && preg_match('/\b(?:image_data|media_data)\b/i', $sql) === 1
        ));
    }
}

/** @param Closure(bool, string): void $assert */
function runImageRevalidationChecks(Closure $assert): void
{
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        throw new RuntimeException('Image revalidation tests require PDO SQLite.');
    }
    $pdo = new ImageRevalidationTestPdo('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, role TEXT, approval_status TEXT, vip_active INTEGER)');
    $pdo->exec('CREATE TABLE professional_profiles (user_id INTEGER PRIMARY KEY, profile_type TEXT)');
    $pdo->exec('CREATE TABLE profile_media (id INTEGER PRIMARY KEY, user_id INTEGER, media_type TEXT, position INTEGER, content_type TEXT, size_bytes INTEGER, created_at TEXT, media_data BLOB, file_name TEXT DEFAULT \'fixture.webp\')');
    $pdo->exec('CREATE TABLE responsive_image_variants (profile_media_id INTEGER, width INTEGER, height INTEGER, content_type TEXT, size_bytes INTEGER, image_data BLOB, source_hash TEXT, updated_at TEXT, created_at TEXT DEFAULT \'2026-10-10\', UNIQUE(profile_media_id,width))');
    $pdo->exec("INSERT INTO users VALUES (7,'ESCORT','APPROVED',0),(8,'ESCORT','APPROVED',0)");
    $pdo->exec("INSERT INTO professional_profiles VALUES (7,'ESCORT'),(8,'ESCORT')");
    $pdo->exec("INSERT INTO profile_media (id,user_id,media_type,position,content_type,size_bytes,created_at,media_data) VALUES (101,7,'PHOTO',0,'image/webp',65536,'2026-10-10','original'),(102,8,'PHOTO',0,'image/webp',65536,'2026-10-10','other'),(103,7,'VIDEO',1,'video/mp4',4,'2026-10-10','video')");
    $bytes = str_repeat('x', 65536);
    $insert = $pdo->prepare('INSERT INTO responsive_image_variants (profile_media_id,width,height,content_type,size_bytes,image_data,source_hash,updated_at) VALUES (:id,320,240,:type,:size,:bytes,:hash,:date)');
    foreach ([101, 102, 103] as $id) {
        $insert->execute(['id' => $id, 'type' => 'image/webp', 'size' => strlen($bytes), 'bytes' => $bytes, 'hash' => 'fixture-source-hash', 'date' => '2026-10-10']);
    }

    $media = new ProfileMediaRepository($pdo);
    $profiles = new ProfessionalProfileRepository($pdo);
    $users = new UserRepository($pdo);
    $images = new ResponsiveImageService(new ResponsiveImageProcessor(), new ResponsiveImageVariantRepository($pdo));
    $marketplace = new MarketplaceService($users, $profiles, $media, $images);
    $jwt = new Jwt();
    $guard = new ApiRequestGuard($users, $jwt, new AuthSessionCookie($jwt));
    // Only the dependencies used by these real GET handlers are initialized.
    $routes = (new ReflectionClass(ListingRoutes::class))->newInstanceWithoutConstructor();
    foreach (['media' => $media, 'marketplace' => $marketplace, 'guard' => $guard] as $name => $dependency) {
        (new ReflectionProperty(ListingRoutes::class, $name))->setValue($routes, $dependency);
    }

    $etags = [];
    foreach (['/api/profiles/7/profile-photo/640.webp', '/api/profiles/7/media/101/640.webp'] as $path) {
        $pdo->preparedQueries = [];
        $initial = $routes->handle(new Request('GET', $path, [], []));
        $assert($initial?->status === 200 && $initial->body === $bytes && $initial->headers['Content-Length'] === '65536', "{$path}: ordinary GET keeps the cached smaller variant and response contract.");
        $assert($pdo->payloadSelects() === 1, "{$path}: an ordinary GET selects the image payload once.");
        $assert(count($pdo->preparedQueries) === 2, "{$path}: an ordinary GET adds no metadata round-trip to the existing authorization and payload queries.");
        $etag = $initial->headers['ETag'];
        $etags[$path] = $etag;
        foreach ([$etag, 'W/' . $etag, substr($etag, 0, -1) . '-gzip"', '*'] as $validator) {
            $pdo->preparedQueries = [];
            $cached = $routes->handle(new Request('GET', $path, ['if-none-match' => $validator], []));
            $assert($cached?->status === 304 && $cached->body === '' && $cached->headers['ETag'] === $etag && $cached->headers['Cache-Control'] === $initial->headers['Cache-Control'], "{$path}: matching validators preserve the empty 304 response and cache headers.");
            $assert($pdo->payloadSelects() === 0, "{$path}: a matching conditional GET must not select image BLOBs.");
        }
        $pdo->preparedQueries = [];
        $changed = $routes->handle(new Request('GET', $path, ['if-none-match' => '"older-image"'], []));
        $assert($changed?->status === 200 && $changed->body === $bytes && $changed->headers['ETag'] === $etag && $pdo->payloadSelects() === 1, "{$path}: a nonmatching validator still selects the payload exactly once.");
    }

    $pdo->exec("UPDATE responsive_image_variants SET source_hash='new-source-hash' WHERE profile_media_id=101");
    foreach (['/api/profiles/7/profile-photo/640.webp', '/api/profiles/7/media/101/640.webp'] as $path) {
        $current = $routes->handle(new Request('GET', $path, [], []));
        $pdo->preparedQueries = [];
        $stale = $routes->handle(new Request('GET', $path, ['if-none-match' => $etags[$path]], []));
        $assert($stale?->status === 200 && $stale->body === $bytes && $stale->headers['ETag'] === $current->headers['ETag'] && $current->headers['ETag'] !== $etags[$path] && $pdo->payloadSelects() === 1, 'A changed source hash invalidates the older ETag instead of returning a false 304.');
    }

    // A VIP blur preview is not a substitute for a full photo derivative.
    $pdo->exec("UPDATE responsive_image_variants SET width=160 WHERE profile_media_id=101");
    $assert($marketplace->cachedPhotoSourceHash(7, 101, 640) === null, 'Metadata revalidation excludes low-resolution VIP preview rows.');
    $pdo->exec("UPDATE responsive_image_variants SET width=320 WHERE profile_media_id=101");

    if (!extension_loaded('gd')) throw new RuntimeException('Cold image revalidation tests require GD.');
    $original = imagecreatetruecolor(640, 480);
    ob_start();
    imagepng($original);
    $originalBytes = (string) ob_get_clean();
    imagedestroy($original);
    $pdo->prepare('UPDATE profile_media SET media_data=:bytes,size_bytes=:size,content_type=:type WHERE id=101')
        ->execute(['bytes' => $originalBytes, 'size' => strlen($originalBytes), 'type' => 'image/png']);
    foreach (['/api/profiles/7/profile-photo/640.webp', '/api/profiles/7/media/101/640.webp'] as $path) {
        $pdo->exec('DELETE FROM responsive_image_variants WHERE profile_media_id=101');
        $cold = $routes->handle(new Request('GET', $path, ['if-none-match' => $etags[$path]], []));
        $dimensions = getimagesizefromstring($cold->body);
        $assert($cold?->status === 200 && $cold->headers['Content-Type'] === 'image/webp' && ($dimensions[0] ?? 0) === 640, 'A missing derivative still falls back to generation from the original, not a false 304.');
        $pdo->preparedQueries = [];
        $warm = $routes->handle(new Request('GET', $path, ['if-none-match' => $cold->headers['ETag']], []));
        $assert($warm?->status === 304 && $warm->body === '' && $pdo->payloadSelects() === 0, 'A generated derivative is subsequently revalidated without a payload SELECT.');
    }

    foreach ([102, 103, 999] as $mediaId) {
        $pdo->preparedQueries = [];
        try {
            $routes->handle(new Request('GET', "/api/profiles/7/media/{$mediaId}/640.webp", ['if-none-match' => '*'], []));
            $assert(false, 'Foreign media, video and missing media must never receive a successful 304.');
        } catch (ApiException $exception) {
            // A prepared owner-filtered SELECT with zero rows is not proof of
            // a BLOB transfer; the relevant security assertion is the 404.
            $assert($exception->status === 404, 'Foreign media, video and missing media remain 404, including wildcard validators.');
        }
    }
    $pdo->exec('UPDATE users SET vip_active=1 WHERE id=7');
    $pdo->preparedQueries = [];
    try {
        $routes->handle(new Request('GET', '/api/profiles/7/media/101/640.webp', ['if-none-match' => '*'], []));
        $assert(false, 'A validator must not bypass the VIP gallery access check.');
    } catch (ApiException $exception) {
        $assert($exception->status === 403 && $pdo->payloadSelects() === 0, 'VIP gallery authorization runs before conditional cache reads.');
    }
    $pdo->exec("UPDATE users SET vip_active=0,approval_status='PENDING' WHERE id=7");
    foreach (['/api/profiles/7/profile-photo/640.webp', '/api/profiles/7/media/101/640.webp'] as $path) {
        $pdo->preparedQueries = [];
        try {
            $routes->handle(new Request('GET', $path, ['if-none-match' => '*'], []));
            $assert(false, 'A conditional request must not expose a profile whose approval was revoked.');
        } catch (ApiException $exception) {
            $assert($exception->status === 404 && $pdo->payloadSelects() === 0, 'Revoked profile access stays 404 before any image BLOB query.');
        }
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    putenv('JWT_SECRET=image-test-secret-with-more-than-thirty-two-bytes');
    putenv('APP_ENV=production');
    require dirname(__DIR__) . '/bootstrap.php';
    $count = 0;
    $failures = [];
    runImageRevalidationChecks(static function (bool $condition, string $message) use (&$count, &$failures): void {
        $count++;
        if (!$condition) $failures[] = $message;
    });
    foreach ($failures as $failure) fwrite(STDERR, "FAIL - {$failure}\n");
    fwrite(STDOUT, count($failures) === 0 ? "OK - {$count} image assertions\n" : count($failures) . " failures in {$count} image assertions\n");
    exit($failures === [] ? 0 : 1);
}
