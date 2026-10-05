<?php

declare(strict_types=1);

namespace ChambreRose;

final class ResponsiveImageService
{
    public const VIP_PREVIEW_WIDTH = 160;
    private const PROCESSING_VERSION = 'webp-sanitized-v2';

    public function __construct(
        private readonly ResponsiveImageProcessor $processor,
        private readonly ResponsiveImageVariantRepository $variants
    ) {
    }

    /** @return list<array{width: int, height: int, contentType: string, size: int, bytes: string}> */
    public function prepare(string $sourceBytes): array
    {
        return $this->processor->generate($sourceBytes);
    }

    public function sanitize(string $sourceBytes): string
    {
        return $this->processor->sanitize($sourceBytes);
    }

    /**
     * @param list<array{width: int, height: int, contentType: string, size: int, bytes: string}> $prepared
     */
    public function storePrepared(
        string $ownerType,
        int $ownerId,
        string $sourceBytes,
        array $prepared
    ): void {
        $this->variants->replace($ownerType, $ownerId, self::sourceHash($sourceBytes), $prepared);
    }

    /** @return array{width: int, height: int, contentType: string, size: int, bytes: string, sourceHash: string, updatedAt: string} */
    public function variant(
        string $ownerType,
        int $ownerId,
        string $sourceBytes,
        int $width,
        bool $allowSmaller = false
    ): array
    {
        self::assertSupportedWidth($width);
        $sourceHash = self::sourceHash($sourceBytes);
        $cached = $this->variants->find($ownerType, $ownerId, $width, $sourceHash);
        if ($cached !== null) {
            return $cached;
        }

        if ($allowSmaller) {
            $largestCached = $this->variants->findLargestAtOrBelow(
                $ownerType,
                $ownerId,
                $width,
                min(ResponsiveImageProcessor::WIDTHS)
            );
            if ($largestCached !== null) {
                return $largestCached;
            }
        }

        $sanitizedBytes = $this->processor->sanitize($sourceBytes);
        $generated = $this->processor->generate($sanitizedBytes, [$width]);
        if ($generated === []) {
            if (!$allowSmaller) {
                throw new ApiException(404, 'Responsive image size is larger than the source image.');
            }

            // Legacy uploads have no derivatives yet. Generate all supported
            // widths once and serve the largest non-upscaled candidate when
            // the browser asks for a width larger than the source can provide.
            $generated = $this->processor->generate($sanitizedBytes);
            $available = array_values(array_filter(
                $generated,
                static fn (array $variant): bool => $variant['width'] <= $width
            ));
            if ($available === []) {
                return $this->originalImageFallback($sourceBytes, $sourceHash);
            }
            $this->variants->save($ownerType, $ownerId, $sourceHash, $generated);
            $actualWidth = max(array_column($available, 'width'));

            return $this->variants->find($ownerType, $ownerId, $actualWidth, $sourceHash)
                ?? throw new ApiException(503, 'Unable to cache the responsive image.');
        }
        $this->variants->save($ownerType, $ownerId, $sourceHash, $generated);

        return $this->variants->find($ownerType, $ownerId, $width, $sourceHash)
            ?? throw new ApiException(503, 'Unable to cache the responsive image.');
    }

    /** @return array{width: int, height: int, contentType: string, size: int, bytes: string, sourceHash: string, updatedAt: string}|null */
    public function cachedVipPreview(string $ownerType, int $ownerId): ?array
    {
        return $this->variants->find($ownerType, $ownerId, self::VIP_PREVIEW_WIDTH);
    }

    /** @return array{width: int, height: int, contentType: string, size: int, bytes: string} */
    public function prepareVipPreview(string $sourceBytes): array
    {
        return $this->processor->blurredPreview($sourceBytes);
    }

    /** @return array{width: int, height: int, contentType: string, size: int, bytes: string, sourceHash: string, updatedAt: string} */
    public function vipPreview(string $ownerType, int $ownerId, string $sourceBytes): array
    {
        $sourceHash = self::sourceHash($sourceBytes);
        $cached = $this->variants->find($ownerType, $ownerId, self::VIP_PREVIEW_WIDTH, $sourceHash);
        if ($cached !== null) {
            return $cached;
        }

        $preview = $this->prepareVipPreview($sourceBytes);
        $this->variants->save($ownerType, $ownerId, $sourceHash, [$preview]);

        return $this->variants->find($ownerType, $ownerId, self::VIP_PREVIEW_WIDTH, $sourceHash)
            ?? throw new ApiException(503, 'Unable to cache the VIP photo preview.');
    }

    /** @return array{width: int, height: int, contentType: string, size: int, bytes: string, sourceHash: string, updatedAt: string}|null */
    public function cachedVariant(string $ownerType, int $ownerId, int $width): ?array
    {
        self::assertSupportedWidth($width);

        return $this->variants->find($ownerType, $ownerId, $width);
    }

    /** @return array{width: int, height: int, contentType: string, size: int, bytes: string, sourceHash: string, updatedAt: string}|null */
    public function cachedVariantAtOrBelow(string $ownerType, int $ownerId, int $width): ?array
    {
        self::assertSupportedWidth($width);

        return $this->variants->findLargestAtOrBelow(
            $ownerType,
            $ownerId,
            $width,
            min(ResponsiveImageProcessor::WIDTHS)
        );
    }

    /** @return array{width: int, height: int, contentType: string, size: int, bytes: string, sourceHash: string} */
    public function blurredPreview(string $sourceBytes): array
    {
        return $this->processor->blurredPreview($sourceBytes) + [
            'sourceHash' => self::sourceHash($sourceBytes),
        ];
    }

    /** @param list<int>|null $widths */
    public static function srcSet(string $baseUrl, ?array $widths = null): string
    {
        $availableWidths = array_values(array_unique($widths ?? ResponsiveImageProcessor::WIDTHS));
        $availableWidths = array_values(array_filter(
            $availableWidths,
            static fn (int $width): bool => in_array($width, ResponsiveImageProcessor::WIDTHS, true)
        ));
        sort($availableWidths, SORT_NUMERIC);

        [$path, $query] = array_pad(explode('?', $baseUrl, 2), 2, '');
        $suffix = $query === '' ? '' : '?' . $query;

        return implode(', ', array_map(
            static fn (int $width): string => $path . '/' . $width . '.webp' . $suffix . ' ' . $width . 'w',
            $availableWidths
        ));
    }

    private static function assertSupportedWidth(int $width): void
    {
        if (!in_array($width, ResponsiveImageProcessor::WIDTHS, true)) {
            throw new ApiException(404, 'Responsive image size not found.');
        }
    }

    private static function sourceHash(string $sourceBytes): string
    {
        return hash('sha256', self::PROCESSING_VERSION . "\0" . $sourceBytes);
    }

    /** @return array{width: int, height: int, contentType: string, size: int, bytes: string, sourceHash: string, updatedAt: string} */
    private function originalImageFallback(string $sourceBytes, string $sourceHash): array
    {
        $sanitizedBytes = $this->processor->sanitize($sourceBytes);
        $dimensions = @getimagesizefromstring($sanitizedBytes);
        if (!is_array($dimensions) || strtolower((string) $dimensions['mime']) !== 'image/webp') {
            throw new ApiException(404, 'Responsive image size is larger than the source image.');
        }

        return [
            'width' => (int) $dimensions[0],
            'height' => (int) $dimensions[1],
            'contentType' => 'image/webp',
            'size' => strlen($sanitizedBytes),
            'bytes' => $sanitizedBytes,
            'sourceHash' => $sourceHash,
            'updatedAt' => '',
        ];
    }
}
