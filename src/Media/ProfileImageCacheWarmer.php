<?php

declare(strict_types=1);

namespace ChambreRose;

use PDO;

/** Prepares legacy photo derivatives without changing profiles or originals. */
final class ProfileImageCacheWarmer
{
    private readonly ResponsiveImageService $images;

    public function __construct(private readonly PDO $pdo)
    {
        $this->images = new ResponsiveImageService(new ResponsiveImageProcessor(), new ResponsiveImageVariantRepository($pdo));
    }

    /** @return array{scanned:int,prepared:int,alreadyCached:int,derivatives:int,failures:list<string>} */
    public function run(bool $apply = false, int $batchSize = 25, ?int $userId = null): array
    {
        if ($batchSize < 1 || $batchSize > 100 || ($userId !== null && $userId < 1)) {
            throw new \InvalidArgumentException('Batch size must be 1–100 and the profile ID must be positive.');
        }
        $report = ['scanned' => 0, 'prepared' => 0, 'alreadyCached' => 0, 'derivatives' => 0, 'failures' => []];
        $lastId = 0;
        do {
            $query = $this->pdo->prepare("SELECT id FROM profile_media WHERE media_type='PHOTO' AND id>:last"
                . ($userId === null ? '' : ' AND user_id=:user') . ' ORDER BY id LIMIT ' . $batchSize);
            $parameters = ['last' => $lastId];
            if ($userId !== null) $parameters['user'] = $userId;
            $query->execute($parameters);
            $ids = $query->fetchAll(PDO::FETCH_COLUMN);
            foreach ($ids as $id) {
                $lastId = (int) $id;
                $report['scanned']++;
                $ownsTransaction = $apply && !$this->pdo->inTransaction();
                if ($ownsTransaction) $this->pdo->beginTransaction();
                try {
                    $lock = $apply && in_array($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME), ['mysql', 'pgsql'], true)
                        ? ' FOR UPDATE' : '';
                    $photo = $this->pdo->prepare("SELECT media_data FROM profile_media WHERE id=:id AND media_type='PHOTO'" . $lock);
                    $photo->execute(['id' => $lastId]);
                    $row = $photo->fetch(PDO::FETCH_ASSOC);
                    $bytes = is_array($row) ? ($row['media_data'] ?? null) : null;
                    if (is_resource($bytes)) $bytes = stream_get_contents($bytes);
                    $photo->closeCursor();
                    if (!is_string($bytes) || $bytes === '') throw new \RuntimeException('Photo has no image data.');
                    $cache = $this->pdo->prepare('SELECT width,source_hash FROM responsive_image_variants WHERE profile_media_id=:id');
                    $cache->execute(['id' => $lastId]);
                    $hash = ResponsiveImageService::sourceHash($bytes);
                    $legacyHash = hash('sha256', $bytes);
                    $cached = [];
                    $cacheHashes = [];
                    foreach ($cache->fetchAll() as $variant) {
                        // Earlier imports used the raw SHA-256 before versioned keys.
                        if (in_array($variant['source_hash'], [$hash, $legacyHash], true)) {
                            $cached[] = (int) $variant['width'];
                            $cacheHashes[(int) $variant['width']] = (string) $variant['source_hash'];
                        }
                    }
                    $processingBytes = $bytes;
                    try {
                        $dimensions = ResponsiveImageProcessor::displayDimensions($bytes);
                    } catch (ApiException $exception) {
                        // Legacy originals can exceed today's upload limits. Use only
                        // a previously validated public derivative of this exact source.
                        $publicWidths = array_values(array_intersect($cached, ResponsiveImageProcessor::WIDTHS));
                        if ($exception->status !== 413 || $publicWidths === []) throw $exception;
                        $largestWidth = max($publicWidths);
                        $largest = (new ResponsiveImageVariantRepository($this->pdo))
                            ->find('PROFILE', $lastId, $largestWidth, $cacheHashes[$largestWidth]);
                        if ($largest === null) throw $exception;
                        $processingBytes = $largest['bytes'];
                        $dimensions = ResponsiveImageProcessor::displayDimensions($processingBytes);
                    }
                    $required = array_values(array_filter(ResponsiveImageProcessor::WIDTHS,
                        static fn (int $width): bool => $width <= $dimensions['width']));
                    $required[] = min(ResponsiveImageService::VIP_PREVIEW_WIDTH, $dimensions['width']);
                    if (array_diff($required, $cached) === []) {
                        $report['alreadyCached']++;
                    } else {
                        $variants = $this->images->prepare($processingBytes);
                        $variants[] = $this->images->prepareVipPreview($processingBytes);
                        if ($apply) $this->images->storePrepared('PROFILE', $lastId, $bytes, $variants);
                        $report['prepared']++;
                        $report['derivatives'] += count($variants);
                    }
                    if ($ownsTransaction) $this->pdo->commit();
                } catch (\Throwable $exception) {
                    if ($ownsTransaction) $this->rollbackIfActive();
                    $report['failures'][] = "Photo {$lastId}: {$exception->getMessage()}";
                }
            }
        } while (count($ids) === $batchSize);

        return $report;
    }

    private function rollbackIfActive(): void
    {
        if ($this->pdo->inTransaction()) $this->pdo->rollBack();
    }
}
