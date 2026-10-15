<?php

declare(strict_types=1);

use ChambreRose\ProductRepository;

/** @param callable(bool, string): void $assert */
function runProductResponsiveUrlChecks(callable $assert): void
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE product_images (id INTEGER PRIMARY KEY, product_id INTEGER, role TEXT, image_data BLOB);
        CREATE TABLE responsive_image_variants (product_image_id INTEGER, width INTEGER);
        INSERT INTO product_images VALUES (1,7,'MAIN',X'01020304'),(2,7,'SECONDARY',X'05060708');
        INSERT INTO responsive_image_variants VALUES (1,320),(1,640),(2,320),(2,640)");
    $repository = new ProductRepository($pdo);
    $hydrate = new ReflectionMethod(ProductRepository::class, 'withResponsiveSrcSets');
    $product = ['id' => 7, 'imageUrl' => '/assets/carousel-pink-ring-thong.jpeg', 'secondaryImageUrl' => 'https://example.test/photo.jpg'];
    $result = $hydrate->invoke($repository, [$product])[0];
    $assert($result['imageSrcSet'] === null && $result['secondaryImageSrcSet'] === null,
        'Stored product images must not invent responsive URLs for a selected bundled or external photo.');
    $assert($result['imageUrl'] === $product['imageUrl'] && $result['secondaryImageUrl'] === $product['secondaryImageUrl'],
        'Responsive metadata must preserve the selected image URLs.');

    $product['imageUrl'] = '/api/products/7/images/main?v=current';
    $product['secondaryImageUrl'] = '/api/products/7/images/secondary?v=second';
    $result = $hydrate->invoke($repository, [$product])[0];
    $assert($result['imageSrcSet'] === '/api/products/7/images/main/320.webp?v=current 320w, /api/products/7/images/main/640.webp?v=current 640w',
        'Uploaded main images must retain their own responsive widths and version query.');
    $assert($result['secondaryImageSrcSet'] === '/api/products/7/images/secondary/320.webp?v=second 320w, /api/products/7/images/secondary/640.webp?v=second 640w',
        'Uploaded secondary images must retain their own responsive widths and version query.');
    foreach (['/api/products/8/images/main', '/api/products/7/images/secondary', 'https://example.test/api/products/7/images/main'] as $url) {
        $result = $hydrate->invoke($repository, [array_replace($product, ['imageUrl' => $url])])[0];
        $assert($result['imageSrcSet'] === null, 'Responsive widths must belong to the selected image, product and origin.');
    }
    $pdo->exec('DELETE FROM responsive_image_variants');
    $result = $hydrate->invoke($repository, [$product])[0];
    $assert(str_contains($result['imageSrcSet'] ?? '', '/main/1280.webp?v=current 1280w'),
        'Legacy uploaded photos without cached derivatives must retain the supported responsive route.');
    $assert((int) $pdo->query('SELECT COUNT(*) FROM product_images')->fetchColumn() === 2
        && $pdo->query('SELECT hex(image_data) FROM product_images WHERE id=1')->fetchColumn() === '01020304',
        'Responsive metadata must not delete or replace stored originals.');
}
