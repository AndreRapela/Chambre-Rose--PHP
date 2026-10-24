<?php

declare(strict_types=1);

namespace ChambreRose;

use PDO;

/**
 * Converts legacy JPEG/PNG blobs to the same sanitized WebP representation
 * used for new uploads and warms their responsive variants.
 *
 * This is deliberately an explicit CLI operation: callers must opt in to
 * writes, while the default mode only estimates the work and validates files.
 */
final class LegacyImageBackfill
{
    private const TARGETS = [
        'profile_media' => [
            'key' => 'id',
            'contentType' => 'content_type',
            'size' => 'size_bytes',
            'data' => 'media_data',
            'filter' => "media_type='PHOTO'",
            'owner' => 'PROFILE',
        ],
        'product_images' => [
            'key' => 'id',
            'contentType' => 'content_type',
            'size' => 'size_bytes',
            'data' => 'image_data',
            'filter' => '1=1',
            'owner' => 'PRODUCT',
        ],
        'site_promotions' => [
            'key' => 'slot',
            'contentType' => 'image_content_type',
            'size' => 'image_size_bytes',
            'data' => 'image_data',
            'filter' => 'image_data IS NOT NULL',
            'owner' => null,
        ],
    ];

    private readonly ResponsiveImageService $responsiveImages;

    public function __construct(
        private readonly PDO $pdo,
        private readonly ResponsiveImageProcessor $processor = new ResponsiveImageProcessor()
    ) {
        $this->responsiveImages = new ResponsiveImageService(
            $processor,
            new ResponsiveImageVariantRepository($pdo)
        );
    }

    /**
     * @return array{
     *   mode: string,
     *   tables: array<string, array{
     *     scanned: int,
     *     candidates: int,
     *     updated: int,
     *     skipped: int,
     *     derivatives: int,
     *     originalBytes: int,
     *     optimizedBytes: int,
     *     failures: list<string>
     *   }>
     * }
     */
    public function run(bool $apply = false, int $batchSize = 25): array
    {
        if ($batchSize < 1 || $batchSize > 100) {
            throw new \InvalidArgumentException('Batch size must be between 1 and 100.');
        }

        $report = ['mode' => $apply ? 'apply' : 'dry-run', 'tables' => []];
        foreach (self::TARGETS as $table => $target) {
            $report['tables'][$table] = $this->migrateTable($table, $target, $apply, $batchSize);
        }

        return $report;
    }

    /**
     * @param array{key:string,contentType:string,size:string,data:string,filter:string,owner:?string} $target
     * @return array{
     *   scanned: int,
     *   candidates: int,
     *   updated: int,
     *   skipped: int,
     *   derivatives: int,
     *   originalBytes: int,
     *   optimizedBytes: int,
     *   failures: list<string>
     * }
     */
    private function migrateTable(string $table, array $target, bool $apply, int $batchSize): array
    {
        $stats = [
            'scanned' => 0,
            'candidates' => 0,
            'updated' => 0,
            'skipped' => 0,
            'derivatives' => 0,
            'originalBytes' => 0,
            'optimizedBytes' => 0,
            'failures' => [],
        ];
        $lastId = 0;
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        do {
            $selectIds = $this->pdo->prepare(
                "SELECT {$target['key']} AS row_id FROM {$table}"
                . " WHERE {$target['key']}>:last_id AND {$target['filter']}"
                . " AND {$target['data']} IS NOT NULL"
                . " AND ({$target['contentType']} IS NULL OR LOWER({$target['contentType']})<>'image/webp')"
                . " AND LOWER(COALESCE({$target['contentType']},''))<>'image/svg+xml'"
                . " ORDER BY {$target['key']} LIMIT :batch_size"
            );
            $selectIds->bindValue(':last_id', $lastId, PDO::PARAM_INT);
            $selectIds->bindValue(':batch_size', $batchSize, PDO::PARAM_INT);
            $selectIds->execute();
            $ids = array_map('intval', $selectIds->fetchAll(PDO::FETCH_COLUMN));

            foreach ($ids as $id) {
                $lastId = max($lastId, $id);
                $stats['scanned']++;
                $stats['candidates']++;
                try {
                    $result = $this->processRow($table, $target, $id, $apply, $driver);
                    if ($result === null) {
                        $stats['skipped']++;
                        continue;
                    }
                    $stats['updated'] += $apply ? 1 : 0;
                    $stats['derivatives'] += $result['derivatives'];
                    $stats['originalBytes'] += $result['originalBytes'];
                    $stats['optimizedBytes'] += $result['optimizedBytes'];
                } catch (ApiException $exception) {
                    $stats['failures'][] = $table . '#' . $id . ': ' . $exception->getMessage();
                    if ($apply && $this->pdo->inTransaction()) {
                        $this->pdo->rollBack();
                    }
                } catch (\Throwable $exception) {
                    if ($apply && $this->pdo->inTransaction()) {
                        $this->pdo->rollBack();
                    }
                    throw $exception;
                }
            }
        } while (count($ids) === $batchSize);

        return $stats;
    }

    /**
     * @param array{key:string,contentType:string,size:string,data:string,filter:string,owner:?string} $target
     * @return array{derivatives:int,originalBytes:int,optimizedBytes:int}|null
     */
    private function processRow(string $table, array $target, int $id, bool $apply, string $driver): ?array
    {
        if ($apply) {
            $this->pdo->beginTransaction();
        }

        try {
            $forUpdate = $apply && in_array($driver, ['mysql', 'pgsql'], true) ? ' FOR UPDATE' : '';
            $select = $this->pdo->prepare(
                "SELECT {$target['contentType']} AS content_type,{$target['data']} AS image_data"
                . " FROM {$table} WHERE {$target['key']}=:row_id" . $forUpdate
            );
            $select->execute(['row_id' => $id]);
            $row = $select->fetch();
            if (!is_array($row) || strtolower((string) ($row['content_type'] ?? '')) === 'image/webp') {
                if ($apply) {
                    $this->pdo->commit();
                }
                return null;
            }

            $original = self::lobToString($row['image_data'] ?? null);
            if ($original === null || $original === '') {
                throw new ApiException(400, 'image data is empty or unreadable');
            }
            $optimized = $this->processor->sanitize($original);
            $prepared = $target['owner'] === null ? [] : $this->processor->generate($optimized);
            if ($target['owner'] === 'PROFILE') {
                $prepared[] = $this->processor->blurredPreview($optimized);
            }

            if ($apply) {
                $timestamp = $driver === 'mysql' ? 'CURRENT_TIMESTAMP(3)' : 'CURRENT_TIMESTAMP';
                $dataExpression = $driver === 'pgsql' ? "decode(:image_data,'base64')" : ':image_data';
                $update = $this->pdo->prepare(
                    "UPDATE {$table} SET {$target['contentType']}='image/webp',"
                    . "{$target['size']}=:image_size,{$target['data']}={$dataExpression}"
                    . ($table === 'profile_media' ? '' : ",updated_at={$timestamp}")
                    . " WHERE {$target['key']}=:row_id"
                );
                $update->bindValue(':image_size', strlen($optimized), PDO::PARAM_INT);
                $update->bindValue(
                    ':image_data',
                    $driver === 'pgsql' ? base64_encode($optimized) : $optimized,
                    $driver === 'pgsql' ? PDO::PARAM_STR : PDO::PARAM_LOB
                );
                $update->bindValue(':row_id', $id, PDO::PARAM_INT);
                $update->execute();

                if ($target['owner'] !== null) {
                    $this->responsiveImages->storePrepared($target['owner'], $id, $optimized, $prepared);
                }
                $this->pdo->commit();
            }

            return [
                'derivatives' => count($prepared),
                'originalBytes' => strlen($original),
                'optimizedBytes' => strlen($optimized),
            ];
        } catch (\Throwable $exception) {
            if ($apply && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private static function lobToString(mixed $value): ?string
    {
        if (is_resource($value)) {
            $value = stream_get_contents($value);
        }

        return is_string($value) ? $value : null;
    }
}
