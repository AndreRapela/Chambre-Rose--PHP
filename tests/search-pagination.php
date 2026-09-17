<?php

declare(strict_types=1);

// Read-only checks: no users, products, messages or emails are created.
$base = rtrim(getenv('TEST_API_URL') ?: 'http://localhost:8080', '/');
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$get = static function (string $path, array $query) use ($base): array {
    $context = stream_context_create(['http' => ['header' => "Accept: application/json\r\nCache-Control: no-cache", 'timeout' => 30]]);
    $raw = file_get_contents($base . '/api/' . $path . '?' . http_build_query($query), false, $context);
    return json_decode($raw === false ? '' : $raw, true, 512, JSON_THROW_ON_ERROR);
};

foreach (['products' => ['sort' => 'name'], 'companions' => ['type' => 'ESCORT', 'sort' => 'newest'], 'stores' => ['type' => 'STORE', 'sort' => 'newest']] as $kind => $filters) {
    $path = $kind === 'products' ? 'products' : 'listings';
    $filters['pageSize'] = 2;
    $first = $get($path, $filters + ['page' => 1]);
    $assert($first['page'] === 1 && $first['pageSize'] === 2, "$kind: request page and quantity must be respected.");
    $assert($first['totalPages'] === (int) ceil($first['total'] / 2), "$kind: total pages must describe every matching result.");
    $ids = [];
    $last = $first;
    for ($page = 1; $page <= max(1, $first['totalPages']); $page++) {
        $last = $page === 1 ? $first : $get($path, $filters + ['page' => $page]);
        $assert($last['page'] === $page && count($last['items']) <= 2, "$kind: each page must contain the bounded requested slice.");
        foreach ($last['items'] as $item) $ids[] = $item['id'];
    }
    $assert(count($ids) === $first['total'] && count(array_unique($ids)) === count($ids), "$kind: no results may be skipped or repeated across pages.");
    $overflow = $get($path, $filters + ['page' => PHP_INT_MAX]);
    $assert($overflow['page'] === max(1, $first['totalPages']) && array_column($overflow['items'], 'id') === array_column($last['items'], 'id'), "$kind: invalid high pages must return the last valid page.");
    $negative = $get($path, $filters + ['page' => -4]);
    $assert($negative['page'] === 1, "$kind: negative pages must return page one.");
    $empty = $get($path, $filters + ['page' => 8, 'q' => 'pagination-no-match-' . bin2hex(random_bytes(8))]);
    $assert($empty['total'] === 0 && $empty['page'] === 1 && $empty['items'] === [], "$kind: an empty filtered search must not retain a stale page.");
}
fwrite(STDOUT, "OK - {$assertions} read-only search pagination assertions\n");
