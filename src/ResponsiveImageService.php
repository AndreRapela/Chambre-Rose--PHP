<?php

declare(strict_types=1);

namespace ChambreRose;

final class ResponsiveImageService
{
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

    /**
     * @param list<array{width: int, height: int, contentType: string, size: int, bytes: string}> $prepared
     */
    public function storePrepared(
        string $ownerType,
        int $ownerId,
        string $sourceBytes,
        array $prepared
    ): void {
        $this->variants->replace($ownerType, $ownerId, hash('sha256', $sourceBytes), $prepared);
    }

    /** @return array{width: int, height: int, contentType: string, size: int, bytes: string, sourceHash: string, updatedAt: string} */
    public function variant(string $ownerType, int $ownerId, string $sourceBytes, int $width): array
    {
        self::assertSupportedWidth($width);
        $sourceHash = hash('sha256', $sourceBytes);
        $cached = $this->variants->find($ownerType, $ownerId, $width, $sourceHash);
        if ($cached !== null) {
            return $cached;
        }

        $generated = $this->processor->generate($sourceBytes, [$width]);
        if ($generated === []) {
            throw new ApiException(404, 'Responsive image size is larger than the source image.');
        }
        $this->variants->save($ownerType, $ownerId, $sourceHash, $generated);

        return $this->variants->find($ownerType, $ownerId, $width, $sourceHash)
            ?? throw new ApiException(503, 'Unable to cache the responsive image.');
    }

    /** @return array{width: int, height: int, contentType: string, size: int, bytes: string, sourceHash: string, updatedAt: string}|null */
    public function cachedVariant(string $ownerType, int $ownerId, int $width): ?array
    {
        self::assertSupportedWidth($width);

        return $this->variants->find($ownerType, $ownerId, $width);
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

        return implode(', ', array_map(
            static fn (int $width): string => $baseUrl . '/' . $width . '.webp ' . $width . 'w',
            $availableWidths
        ));
    }

    private static function assertSupportedWidth(int $width): void
    {
        if (!in_array($width, ResponsiveImageProcessor::WIDTHS, true)) {
            throw new ApiException(404, 'Responsive image size not found.');
        }
    }
}
