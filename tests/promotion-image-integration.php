<?php

declare(strict_types=1);

/**
 * @param callable(bool,string):void $assert
 * @param Closure(string,array,array,?string,string):array $multipart
 * @param Closure(string,string,array):array $binary
 */
function runPromotionHttpChecks(callable $assert, Closure $multipart, Closure $binary, string $adminCookie, PDO $pdo, string $fixture): void
{
    $original = $pdo->query('SELECT * FROM site_promotions WHERE slot=1')->fetch(PDO::FETCH_ASSOC);
    if (!is_array($original)) throw new RuntimeException('The promotion integration fixture is missing.');
    if (is_resource($original['image_data'])) $original['image_data'] = stream_get_contents($original['image_data']);
    $source = (string) file_get_contents($fixture);
    $data = ['label' => 'Image check', 'title' => 'Promotion integration', 'subtitle' => 'Temporary integration fixture.',
        'linkUrl' => '/catalogue', 'linkText' => 'Explore', 'icon' => 'gift'];
    try {
        [$publicStatus, , $publicHeaders] = $binary('GET', '/api/promotions', []);
        $vary = array_map('strtolower', array_map('trim', explode(',', $publicHeaders['vary'] ?? '')));
        $assert($publicStatus === 200 && ($publicHeaders['cache-control'] ?? '') === 'public, max-age=30, stale-while-revalidate=300'
            && in_array('cookie', $vary, true) && in_array('authorization', $vary, true),
            'Public promotion metadata must retain a cache varied by browser authentication.');
        [$status, $promotion] = $multipart('/api/admin/promotions/1', $data, [
            'image' => ['name' => 'integration.png', 'contentType' => 'image/png', 'bytes' => $source],
        ], $adminCookie, 'PUT');
        $stored = $pdo->query('SELECT image_data,image_sanitized_hash FROM site_promotions WHERE slot=1')->fetch(PDO::FETCH_ASSOC);
        $assert($status === 200 && is_array($stored)
            && $stored['image_sanitized_hash'] === hash('sha256', $stored['image_data']),
            'The admin promotion endpoint must store sanitized image bytes and their fingerprint together.');
        [$listStatus, $listBytes, $listHeaders] = $binary('GET', '/api/promotions', ['Cookie: ' . $adminCookie]);
        $items = json_decode($listBytes, true, 512, JSON_THROW_ON_ERROR)['items'] ?? [];
        $assert($listStatus === 200 && ($items[0]['title'] ?? '') === $data['title']
            && ($listHeaders['cache-control'] ?? '') === 'private, no-store',
            'An authenticated promotion read after saving must return the current record without caching it.');
        $url = (string) ($promotion['imageUrl'] ?? '');
        [$getStatus, $bytes, $headers] = $binary('GET', $url, []);
        $assert($getStatus === 200 && $bytes === $stored['image_data'] && ($headers['content-type'] ?? '') === 'image/webp'
            && ($headers['content-length'] ?? '') === (string) strlen($bytes),
            'Public promotion images must match the stored WebP bytes and response length.');
        [$headStatus, $headBytes, $headHeaders] = $binary('HEAD', $url, []);
        $assert($headStatus === 200 && $headBytes === '' && ($headHeaders['etag'] ?? '') === ($headers['etag'] ?? '')
            && ($headHeaders['content-length'] ?? '') === (string) strlen($bytes),
            'Promotion HEAD must preserve the representation metadata without sending the image.');
        [$cachedStatus, $cachedBytes, $cachedHeaders] = $binary('GET', $url, ['If-None-Match: W/' . $headers['etag']]);
        $assert($cachedStatus === 304 && $cachedBytes === '' && ($cachedHeaders['etag'] ?? '') === $headers['etag'],
            'Promotion conditional GET must return an empty 304 for a matching weak ETag.');
        foreach (['v%5B%5D=invalid', 'v=outdated'] as $version) {
            [$invalidStatus, $invalidBytes, $invalidHeaders] = $binary('GET', '/api/promotions/1/image?' . $version, []);
            $assert($invalidStatus === 200 && $invalidBytes === $bytes && !str_contains($invalidHeaders['cache-control'] ?? '', 'immutable'),
                'Malformed and stale promotion versions must not corrupt the image or receive an immutable cache.');
        }
    } finally {
        $assignments = [];
        foreach (array_keys($original) as $column) {
            if (!preg_match('/^[a-z_]+$/D', $column)) throw new RuntimeException('Unexpected promotion column.');
            $assignments[] = $column . '=:' . $column;
        }
        $restore = $pdo->prepare('UPDATE site_promotions SET ' . implode(',', $assignments) . ' WHERE slot=1');
        foreach ($original as $column => $value) {
            $type = $value === null ? PDO::PARAM_NULL : ($column === 'image_data' ? PDO::PARAM_LOB : PDO::PARAM_STR);
            $restore->bindValue(':' . $column, $value, $type);
        }
        $restore->execute();
        $restored = $pdo->query('SELECT * FROM site_promotions WHERE slot=1')->fetch(PDO::FETCH_ASSOC);
        if (is_resource($restored['image_data'])) $restored['image_data'] = stream_get_contents($restored['image_data']);
        $assert($restored === $original, 'Promotion integration checks must restore every original field and image byte.');
    }
}
