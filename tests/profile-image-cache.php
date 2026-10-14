<?php

declare(strict_types=1);

use ChambreRose\ProfileImageCacheWarmer;
use ChambreRose\ResponsiveImageService;
use ChambreRose\ResponsiveImageProcessor;
use ChambreRose\ResponsiveImageVariantRepository;
use ChambreRose\Seeder;

/** @param Closure(bool,string):void $assert */
function runProfileImageCacheChecks(Closure $assert): void
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('CREATE TABLE profile_media (id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,media_type TEXT,file_name TEXT,content_type TEXT,size_bytes INTEGER,media_data BLOB,position INTEGER,created_at TEXT)');
    $pdo->exec('CREATE TABLE responsive_image_variants (profile_media_id INTEGER,width INTEGER,height INTEGER,content_type TEXT,size_bytes INTEGER,image_data BLOB,source_hash TEXT,created_at TEXT,updated_at TEXT,UNIQUE(profile_media_id,width))');
    $picture = imagecreatetruecolor(640, 800);
    imagefill($picture, 0, 0, imagecolorallocate($picture, 150, 40, 75));
    ob_start();
    imagepng($picture);
    $bytes = (string) ob_get_clean();
    unset($picture);
    $insert = $pdo->prepare("INSERT INTO profile_media (id,user_id,media_type,file_name,content_type,size_bytes,media_data,position,created_at) VALUES (:id,7,:type,'original.png',:mime,:size,:bytes,0,'2026-09-01')");
    $insert->execute(['id' => 1, 'type' => 'PHOTO', 'mime' => 'image/png', 'size' => strlen($bytes), 'bytes' => $bytes]);
    $insert->execute(['id' => 2, 'type' => 'VIDEO', 'mime' => 'video/mp4', 'size' => 5, 'bytes' => 'video']);
    $before = $pdo->query('SELECT * FROM profile_media ORDER BY id')->fetchAll();
    $warmer = new ProfileImageCacheWarmer($pdo);
    $dry = $warmer->run(false, 1, 7);
    $assert($dry['scanned'] === 1 && $dry['prepared'] === 1 && $dry['derivatives'] === 3 && $dry['failures'] === [], 'Photo cache dry-run validates only photos and respects batch/profile scope.');
    $assert((int) $pdo->query('SELECT COUNT(*) FROM responsive_image_variants')->fetchColumn() === 0, 'Photo cache dry-run does not write derivatives.');
    $applied = $warmer->run(true, 1);
    $assert($applied['prepared'] === 1 && $applied['derivatives'] === 3 && $applied['failures'] === [], 'Cache warming prepares public thumbnails and the VIP preview together.');
    $assert($pdo->query('SELECT * FROM profile_media ORDER BY id')->fetchAll() === $before, 'Cache warming preserves all photo/video bytes, filenames, IDs, order and timestamps.');
    $widths = $pdo->query('SELECT width FROM responsive_image_variants ORDER BY width')->fetchAll(PDO::FETCH_COLUMN);
    $assert(array_map('intval', $widths) === [160,320,640], 'Cache warming never enlarges a source beyond its natural width.');
    $assert($pdo->query('SELECT source_hash FROM responsive_image_variants LIMIT 1')->fetchColumn() === ResponsiveImageService::sourceHash($bytes), 'Derivatives use the original source fingerprint, not the temporary sanitized file.');
    $again = $warmer->run(true);
    $assert($again['alreadyCached'] === 1 && $again['prepared'] === 0, 'A repeated cache warm is idempotent.');
    $pdo->exec('DELETE FROM responsive_image_variants WHERE width=640');
    $assert($warmer->run(true)['prepared'] === 1, 'A missing gallery size is repaired even when a card thumbnail is cached.');
    $pdo->exec("UPDATE responsive_image_variants SET source_hash='outdated'");
    $assert($warmer->run(true)['prepared'] === 1, 'Outdated fingerprints are regenerated before browser requests.');
    $pdo->exec("INSERT INTO profile_media VALUES (3,8,'PHOTO','broken.jpg','image/jpeg',6,'broken',0,'2026-09-01')");
    $assert($warmer->run(false, 25, 7)['failures'] === [], 'A selected profile does not scan unrelated corrupt photos.');
    $broken = $warmer->run(true);
    $assert(count($broken['failures']) === 1 && $broken['alreadyCached'] === 1 && !$pdo->inTransaction(), 'A corrupt photo is reported without rolling back unrelated successful cache entries or leaving a transaction open.');
    $seedPhotos = new ReflectionMethod(Seeder::class, 'seedProfilePhotos');
    $seedPhotos->invoke(new Seeder($pdo), 9, ['companion-man.jpg', 'companion-trans.jpg']);
    $seeded = $pdo->query('SELECT id,file_name,content_type,media_data FROM profile_media WHERE user_id=9 ORDER BY position')->fetchAll();
    $assert(count($seeded) === 2 && $seeded[0]['file_name'] === 'companion-man.webp' && $seeded[1]['file_name'] === 'companion-trans.webp', 'Male and trans seed portraits use their preserved individual images.');
    foreach ($seeded as $photo) {
        $query = $pdo->prepare('SELECT width FROM responsive_image_variants WHERE profile_media_id=:id ORDER BY width');
        $query->execute(['id' => $photo['id']]);
        $photoWidths = array_map('intval', $query->fetchAll(PDO::FETCH_COLUMN));
        $assert($photo['content_type'] === 'image/webp' && in_array(160, $photoWidths, true) && in_array(320, $photoWidths, true), 'Seeded portrait is sanitized and its public/VIP images are ready before publication.');
    }
    $pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY,email TEXT,password_hash TEXT DEFAULT '',first_name TEXT DEFAULT '',last_name TEXT DEFAULT '',phone TEXT DEFAULT '',address TEXT DEFAULT '',city TEXT DEFAULT '',region TEXT DEFAULT '',country TEXT DEFAULT '',postal_code TEXT DEFAULT '',role TEXT DEFAULT 'ESCORT',approval_status TEXT DEFAULT 'APPROVED',approval_reason TEXT,review_deadline TEXT,approved_at TEXT,locale TEXT DEFAULT 'en',vip_active INTEGER DEFAULT 0,vip_since TEXT,vip_until TEXT,created_at TEXT,updated_at TEXT)");
    $pdo->exec('CREATE TABLE professional_profiles (user_id INTEGER PRIMARY KEY,updated_at TEXT)');
    $pdo->exec('CREATE TABLE chambre_rose_seed_history (seed_key TEXT PRIMARY KEY)');
    $pdo->exec("INSERT INTO users (id,email) VALUES (10,'demo-companion-man@chambre-rose.invalid'),(11,'demo-companion-trans@chambre-rose.invalid')");
    $pdo->exec('INSERT INTO professional_profiles (user_id) VALUES (10),(11)');
    $default = file_get_contents(dirname(__DIR__) . '/resources/seed-images/carousel-basic-black.jpeg');
    $oldPhoto = $pdo->prepare("INSERT INTO profile_media (user_id,media_type,file_name,content_type,size_bytes,media_data,position,created_at) VALUES (:user,'PHOTO',:name,'image/jpeg',:size,:bytes,0,'2026-09-01')");
    $oldPhoto->execute(['user' => 10, 'name' => 'carousel-basic-black.jpeg', 'size' => strlen((string) $default), 'bytes' => $default]);
    $oldId = (int) $pdo->lastInsertId();
    $oldPhoto->execute(['user' => 11, 'name' => 'carousel-pink-lace-tie.jpeg', 'size' => strlen($bytes), 'bytes' => $bytes]);
    $customId = (int) $pdo->lastInsertId();
    $oldPhoto->closeCursor();
    (new Seeder($pdo))->repairDefaultPortraits();
    $assert((int) $pdo->query('SELECT COUNT(*) FROM profile_media WHERE user_id=10')->fetchColumn() === 2
        && $pdo->query('SELECT file_name FROM profile_media WHERE user_id=10 ORDER BY position,id LIMIT 1')->fetchColumn() === 'companion-man.webp', 'Default portrait repair adds the correct cover without deleting the old photo.');
    $saved = $pdo->query('SELECT media_data,created_at FROM profile_media WHERE id=' . $oldId)->fetch();
    $assert($saved['media_data'] === $default && $saved['created_at'] === '2026-09-01', 'Default portrait repair preserves the original bytes, IDs and timestamps.');
    $assert((int) $pdo->query('SELECT COUNT(*) FROM profile_media WHERE user_id=11')->fetchColumn() === 1
        && $pdo->query('SELECT media_data FROM profile_media WHERE id=' . $customId)->fetchColumn() === $bytes, 'Default portrait repair protects customized photos even with a default filename.');
    (new Seeder($pdo))->repairDefaultPortraits();
    $assert((int) $pdo->query('SELECT COUNT(*) FROM profile_media WHERE user_id=10')->fetchColumn() === 2, 'Repeated portrait repair never adds duplicate covers.');
    $tiny = imagecreatetruecolor(1, 1);
    ob_start();
    imagepng($tiny);
    $tinyBytes = (string) ob_get_clean();
    unset($tiny);
    $tinyInsert = $pdo->prepare("INSERT INTO profile_media (user_id,media_type,file_name,content_type,size_bytes,media_data,position,created_at) VALUES (12,'PHOTO','tiny.png','image/png',:size,:bytes,0,'2026-09-01')");
    $tinyInsert->execute(['size' => strlen($tinyBytes), 'bytes' => $tinyBytes]);
    $tinyId = (int) $pdo->lastInsertId();
    $tinyInsert->closeCursor();
    $assert($warmer->run(true, 25, 12)['prepared'] === 1 && $warmer->run(true, 25, 12)['alreadyCached'] === 1, 'Tiny photo cache warming is idempotent without enlarging the private preview.');
    $tinyImages = new ResponsiveImageService(new ResponsiveImageProcessor(), new ResponsiveImageVariantRepository($pdo));
    $tinyPreview = $tinyImages->cachedVipPreview('PROFILE', $tinyId);
    $assert(($tinyPreview['width'] ?? 0) === 1, 'VIP preview lookup reuses an image smaller than the reserved maximum width.');
    $assert($tinyImages->vipPreview('PROFILE', $tinyId, $tinyBytes)['bytes'] === $tinyPreview['bytes'], 'Tiny VIP previews are reused instead of regenerated on every visit.');
    // The original exceeds the current pixel limit; no decoder may allocate it.
    $oversizedBytes = substr_replace($bytes, pack('N', 9000) . pack('N', 9000), 16, 8);
    $largeInsert = $pdo->prepare("INSERT INTO profile_media (user_id,media_type,file_name,content_type,size_bytes,media_data,position,created_at) VALUES (:user,'PHOTO','legacy.png','image/png',:size,:bytes,0,'2026-09-01')");
    $largeInsert->execute(['user' => 13, 'size' => strlen($oversizedBytes), 'bytes' => $oversizedBytes]);
    $largeId = (int) $pdo->lastInsertId();
    $largeInsert->closeCursor();
    $tinyImages->storePrepared('PROFILE', $largeId, $oversizedBytes, $tinyImages->prepare($bytes));
    $legacy = $warmer->run(true, 25, 13);
    $assert($legacy['failures'] === [] && $legacy['prepared'] === 1 && $warmer->run(true, 25, 13)['alreadyCached'] === 1,
        'Oversized legacy photos use a matching validated public derivative without loosening upload limits.');
    $assert($pdo->query('SELECT media_data FROM profile_media WHERE id=' . $largeId)->fetchColumn() === $oversizedBytes,
        'Preparing oversized legacy previews preserves the full original bytes.');
    $oldFingerprint = $pdo->prepare('UPDATE responsive_image_variants SET source_hash=:hash WHERE profile_media_id=:id');
    $oldFingerprint->execute(['hash' => hash('sha256', $oversizedBytes), 'id' => $largeId]);
    $pdo->exec('DELETE FROM responsive_image_variants WHERE width=160 AND profile_media_id=' . $largeId);
    $assert($warmer->run(true, 25, 13)['failures'] === []
        && $pdo->query('SELECT source_hash FROM responsive_image_variants WHERE profile_media_id=' . $largeId . ' LIMIT 1')->fetchColumn() === ResponsiveImageService::sourceHash($oversizedBytes),
        'Exact unversioned legacy source fingerprints are accepted and migrated while preparing the missing preview.');
    $largeInsert->execute(['user' => 14, 'size' => strlen($oversizedBytes), 'bytes' => $oversizedBytes]);
    $largeInsert->closeCursor();
    $assert(count($warmer->run(true, 25, 14)['failures']) === 1,
        'An oversized photo without a trusted public derivative is rejected instead of decoded without bounds.');
}
