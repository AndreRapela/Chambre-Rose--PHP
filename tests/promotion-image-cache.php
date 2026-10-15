<?php

declare(strict_types=1);

use ChambreRose\ApiException;
use ChambreRose\ApiRequestGuard;
use ChambreRose\AuthSessionCookie;
use ChambreRose\Jwt;
use ChambreRose\PromotionRepository;
use ChambreRose\PromotionRoutes;
use ChambreRose\PromotionService;
use ChambreRose\Request;
use ChambreRose\ResponsiveImageProcessor;
use ChambreRose\UploadedFile;
use ChambreRose\UserRepository;

final class PromotionImageTestPdo extends PDO
{
    /** @var list<string> */
    public array $queries = [];

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->queries[] = $query;
        return parent::prepare($query, $options);
    }

    public function payloadSelects(): int
    {
        return count(array_filter($this->queries, static fn (string $sql): bool =>
            preg_match('/^\s*SELECT\s+[^;]*\bimage_data\b[^;]*\bFROM\s+site_promotions\b/i', $sql) === 1
        ));
    }
}

/** @param callable(bool, string): void $assert */
function runPromotionImageCacheChecks(callable $assert): void
{
    $pdo = new PromotionImageTestPdo('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec(<<<'SQL'
        CREATE TABLE site_promotions (
          slot INTEGER PRIMARY KEY,label TEXT,title TEXT,subtitle TEXT,link_url TEXT,
          link_text TEXT,icon TEXT,image_path TEXT,image_file_name TEXT,image_content_type TEXT,
          image_size_bytes INTEGER,image_data BLOB,image_sanitized_hash TEXT,
          updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        INSERT INTO site_promotions (slot,label,title,subtitle,link_url,link_text,icon) VALUES
          (1,'Gift','Gift selection','A seasonal collection.','/catalogue','Explore','gift'),
          (2,'Delivery','Private delivery','Boutique care.','/catalogue/lojas','Contact','truck');
        SQL);
    $repository = new PromotionRepository($pdo);
    $service = new PromotionService($repository);
    $jwt = new Jwt();
    $routes = new PromotionRoutes($service, new ApiRequestGuard(new UserRepository($pdo), $jwt, new AuthSessionCookie($jwt)));
    $data = ['label' => 'Gift', 'title' => 'Gift selection', 'subtitle' => 'A seasonal collection.',
        'linkUrl' => '/catalogue', 'linkText' => 'Explore', 'icon' => 'gift'];
    $source = (string) file_get_contents(dirname(__DIR__) . '/resources/brand/brand-logo.png');
    $saved = $service->update(1, $data, new UploadedFile('gift.png', 'image/png', strlen($source), null, $source));
    $stored = $pdo->query('SELECT image_data,image_sanitized_hash FROM site_promotions WHERE slot=1')->fetch();
    $assert($stored['image_sanitized_hash'] === hash('sha256', $stored['image_data']),
        'A promotion upload must fingerprint the sanitized bytes written to storage.');

    $path = '/api/promotions/1/image';
    $query = [];
    parse_str((string) parse_url($saved['imageUrl'], PHP_URL_QUERY), $query);
    $pdo->queries = [];
    $initial = $routes->handle(new Request('GET', $path, [], $query));
    $assert($initial?->status === 200 && $initial->body === $stored['image_data'],
        'A sanitized promotion must be served byte-for-byte without a second lossy WebP conversion.');
    $assert($pdo->payloadSelects() === 1 && count($pdo->queries) === 1,
        'An ordinary promotion GET must read its payload once without an extra metadata round-trip.');
    $assert(($initial->headers['Content-Length'] ?? '') === (string) strlen($initial->body)
        && ($initial->headers['Content-Type'] ?? '') === 'image/webp',
        'Promotion response headers must describe the actual sanitized representation.');
    $assert(str_contains($initial->headers['Cache-Control'] ?? '', 'immutable'),
        'A matching promotion version may retain its long-lived cache.');
    $etag = $initial->headers['ETag'];
    foreach ([$etag, 'W/' . $etag, substr($etag, 0, -1) . '-gzip"', '*'] as $validator) {
        $pdo->queries = [];
        $cached = $routes->handle(new Request('GET', $path, ['if-none-match' => $validator], $query));
        $assert($cached?->status === 304 && $cached->body === '' && $cached->headers['ETag'] === $etag,
            'Promotion cache validators must retain an empty, consistent 304 response.');
        $assert($pdo->payloadSelects() === 0,
            'A matching promotion validator must not transfer or process the image BLOB.');
    }
    $pdo->queries = [];
    $head = $routes->handle(new Request('HEAD', $path, [], $query));
    $assert($head?->status === 200 && $head->body === '' && $head->headers === $initial->headers,
        'Promotion HEAD must retain GET metadata without a response body.');
    $assert($pdo->payloadSelects() === 0, 'Promotion HEAD must not read or convert image bytes.');
    $pdo->queries = [];
    $changed = $routes->handle(new Request('GET', $path, ['if-none-match' => '"outdated"'], $query));
    $assert($changed?->status === 200 && $changed->body === $initial->body && $pdo->payloadSelects() === 1,
        'An unmatched promotion validator must read the current payload exactly once.');

    $warnings = [];
    set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
        $warnings[] = $message;
        return true;
    });
    try {
        foreach ([[], ['v' => ''], ['v' => 'outdated'], ['v' => ['nested' => ['value']]]] as $version) {
            $response = $routes->handle(new Request('GET', $path, [], $version));
            $assert($response?->status === 200 && !str_contains($response->headers['Cache-Control'] ?? '', 'immutable'),
                'Missing, stale or malformed versions must not make a mutable promotion URL immutable.');
        }
    } finally {
        restore_error_handler();
    }
    $assert($warnings === [], 'Array-valued promotion versions must not cause conversion warnings.');

    $pdo->exec("UPDATE site_promotions SET updated_at='2000-01-01 00:00:00.000' WHERE slot=1");
    $beforeTextChange = $repository->find(1);
    $afterTextChange = $service->update(1, array_replace($data, ['title' => 'Updated text']), null);
    $assert($pdo->query('SELECT image_sanitized_hash FROM site_promotions WHERE slot=1')->fetchColumn() === $stored['image_sanitized_hash'],
        'Editing promotion text must preserve the existing sanitized image fingerprint.');
    $assert($beforeTextChange['imageUrl'] === $afterTextChange['imageUrl']
        && str_contains($afterTextChange['imageUrl'], (string) $stored['image_sanitized_hash']),
        'Editing promotion text must not change its image URL or trigger an unnecessary image download.');

    $processor = new ResponsiveImageProcessor();
    $bySize = [];
    $pair = null;
    for ($index = 0; $index < 32; $index++) {
        $pixel = imagecreatetruecolor(16, 16);
        imagefill($pixel, 0, 0, imagecolorallocate($pixel, $index * 7, ($index * 41) % 256, ($index * 67) % 256));
        ob_start();
        imagepng($pixel);
        $png = (string) ob_get_clean();
        imagedestroy($pixel);
        $encoded = $processor->sanitize($png);
        $size = strlen($encoded);
        if (isset($bySize[$size]) && $bySize[$size]['encoded'] !== $encoded) {
            $pair = [$bySize[$size]['source'], $png];
            break;
        }
        $bySize[$size] = ['source' => $png, 'encoded' => $encoded];
    }
    $assert($pair !== null, 'Cache invalidation fixtures must contain different images with identical encoded lengths.');
    if ($pair !== null) {
        $oldImage = null;
        foreach ($pair as $png) {
            $service->update(1, $data, new UploadedFile('color.png', 'image/png', strlen($png), null, $png));
            $pdo->exec("UPDATE site_promotions SET updated_at='2026-10-15 00:00:00.000' WHERE slot=1");
            $headers = $oldImage === null ? [] : ['if-none-match' => $oldImage->headers['ETag']];
            $current = $routes->handle(new Request('GET', $path, $headers, []));
            if ($oldImage !== null) {
                $assert(strlen($current->body) === strlen($oldImage->body) && $current->body !== $oldImage->body,
                    'The image replacement regression must retain the same byte length and modification timestamp.');
                $assert($current->status === 200 && $current->headers['ETag'] !== $oldImage->headers['ETag'],
                    'A different image must invalidate its ETag even when size and modification time are unchanged.');
            }
            $oldImage = $current;
        }
    }
    foreach (['image/png' => $source, 'image/webp' => $stored['image_data']] as $type => $legacy) {
        $legacy .= 'PRIVATE-LEGACY-METADATA';
        $pdo->prepare('UPDATE site_promotions SET image_data=:bytes,image_content_type=:type,image_size_bytes=:size,image_sanitized_hash=NULL WHERE slot=1')
            ->execute(['bytes' => $legacy, 'type' => $type, 'size' => strlen($legacy)]);
        $safe = $routes->handle(new Request('GET', $path, [], []));
        $assert($safe?->status === 200 && $safe->headers['Content-Type'] === 'image/webp'
            && !str_contains($safe->body, 'PRIVATE-LEGACY-METADATA') && $safe->body !== $legacy,
            'Unmarked legacy PNG and WebP images must still be sanitized before being served.');
        $assert($pdo->query('SELECT image_data FROM site_promotions WHERE slot=1')->fetchColumn() === $legacy,
            'Reading a legacy promotion must not rewrite or remove its original bytes.');
    }
    try {
        $routes->handle(new Request('GET', '/api/promotions/2/image', ['if-none-match' => '*'], []));
        $assert(false, 'A missing promotion image must not receive a false 304.');
    } catch (ApiException $exception) {
        $assert($exception->status === 404, 'Missing promotion images must retain their 404 response.');
    }
}
