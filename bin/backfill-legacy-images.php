<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use ChambreRose\Database;
use ChambreRose\LegacyImageBackfill;
use ChambreRose\ResponsiveImageProcessor;

$arguments = array_slice($argv, 1);
if (in_array('--help', $arguments, true)) {
    fwrite(STDOUT, "Usage: php bin/backfill-legacy-images.php [--batch-size=25] [--apply]\n");
    fwrite(STDOUT, "Default mode validates legacy JPEG/PNG rows without writing. --apply updates the configured database.\n");
    exit(0);
}

$apply = in_array('--apply', $arguments, true);
$batchSize = 25;
foreach ($arguments as $argument) {
    if (preg_match('/^--batch-size=(\d+)$/', $argument, $match) === 1) {
        $batchSize = (int) $match[1];
        continue;
    }
    if (!in_array($argument, ['--apply'], true)) {
        fwrite(STDERR, "Unknown option: {$argument}\n");
        exit(64);
    }
}

try {
    $report = (new LegacyImageBackfill(Database::connection(), new ResponsiveImageProcessor()))
        ->run($apply, $batchSize);
    fwrite(STDOUT, strtoupper($report['mode']) . " legacy image backfill\n");
    if (!$apply) {
        fwrite(STDOUT, "Dry-run only: no database rows were changed.\n");
    }
    foreach ($report['tables'] as $table => $stats) {
        fwrite(STDOUT, sprintf(
            "%s: scanned=%d candidate=%d updated=%d derivatives=%d bytes=%d -> %d skipped=%d failures=%d\n",
            $table,
            $stats['scanned'],
            $stats['candidates'],
            $stats['updated'],
            $stats['derivatives'],
            $stats['originalBytes'],
            $stats['optimizedBytes'],
            $stats['skipped'],
            count($stats['failures'])
        ));
        foreach ($stats['failures'] as $failure) {
            fwrite(STDERR, "  {$failure}\n");
        }
    }
    $hasFailures = array_sum(array_map(
        static fn (array $stats): int => count($stats['failures']),
        $report['tables']
    )) > 0;
    exit($hasFailures ? 2 : 0);
} catch (Throwable $exception) {
    fwrite(STDERR, "Legacy image backfill failed: {$exception->getMessage()}\n");
    exit(1);
}
