<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
require dirname(__DIR__) . '/bootstrap.php';

use ChambreRose\Database;
use ChambreRose\ProfileImageCacheWarmer;

$apply = false;
$batchSize = 25;
$userId = null;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--help') {
        fwrite(STDOUT, "Usage: php bin/warm-profile-images.php [--apply] [--batch-size=25] [--user=ID]\nDefault: dry-run. Only responsive caches are changed; original photos are preserved.\n");
        exit(0);
    }
    if ($argument === '--apply') $apply = true;
    elseif (preg_match('/^--batch-size=(\d+)$/', $argument, $match)) $batchSize = (int) $match[1];
    elseif (preg_match('/^--user=(\d+)$/', $argument, $match)) $userId = (int) $match[1];
    else {
        fwrite(STDERR, "Unknown option: {$argument}\n");
        exit(64);
    }
}
try {
    $report = (new ProfileImageCacheWarmer(Database::connection()))->run($apply, $batchSize, $userId);
    fwrite(STDOUT, json_encode(['mode' => $apply ? 'apply' : 'dry-run'] + $report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
    exit($report['failures'] === [] ? 0 : 2);
} catch (Throwable $exception) {
    fwrite(STDERR, "Photo cache preparation failed: {$exception->getMessage()}\n");
    exit(1);
}
