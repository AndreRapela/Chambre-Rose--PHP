<?php

declare(strict_types=1);

namespace ChambreRose;

use GdImage;

final class ResponsiveImageProcessor
{
    /** @var list<int> */
    public const WIDTHS = [320, 640, 960, 1280];

    private const MAX_PIXELS = 24_000_000;
    private const MAX_ASPECT_RATIO = 5;
    private const WEBP_QUALITY = 82;

    /**
     * @param list<int>|null $widths
     * @return list<array{width: int, height: int, contentType: string, size: int, bytes: string}>
     */
    public function generate(string $sourceBytes, ?array $widths = null): array
    {
        if (!function_exists('imagecreatefromstring') || !function_exists('imagewebp')) {
            throw new ApiException(503, 'Responsive image processing is unavailable.');
        }

        $dimensions = @getimagesizefromstring($sourceBytes);
        if (!is_array($dimensions)) {
            throw new ApiException(400, 'The uploaded image is invalid or corrupted.');
        }
        $sourceWidth = (int) $dimensions[0];
        $sourceHeight = (int) $dimensions[1];
        if ($sourceWidth < 1 || $sourceHeight < 1 || $sourceWidth * $sourceHeight > self::MAX_PIXELS) {
            throw new ApiException(413, 'The image dimensions are too large.');
        }
        $aspectRatio = max($sourceWidth / $sourceHeight, $sourceHeight / $sourceWidth);
        if ($aspectRatio > self::MAX_ASPECT_RATIO) {
            throw new ApiException(413, 'The image aspect ratio is too large for responsive processing.');
        }

        $source = @imagecreatefromstring($sourceBytes);
        if (!$source instanceof GdImage) {
            throw new ApiException(400, 'The uploaded image could not be decoded.');
        }

        $requestedWidths = array_values(array_unique($widths ?? self::WIDTHS));
        $targetWidths = array_values(array_filter(
            $requestedWidths,
            static fn (int $width): bool => in_array($width, self::WIDTHS, true)
        ));
        if ($targetWidths === []) {
            unset($source);
            throw new ApiException(400, 'Unsupported responsive image width.');
        }
        $targetWidths = array_values(array_filter(
            $targetWidths,
            static fn (int $width): bool => $width <= $sourceWidth
        ));
        if ($targetWidths === []) {
            unset($source);

            return [];
        }

        $variants = [];
        try {
            foreach ($targetWidths as $targetWidth) {
                $targetHeight = max(1, (int) round($sourceHeight * ($targetWidth / $sourceWidth)));
                if ($targetHeight > 65_535 || $targetWidth * $targetHeight > self::MAX_PIXELS) {
                    throw new ApiException(413, 'The image aspect ratio is too large for responsive processing.');
                }
                $target = imagecreatetruecolor($targetWidth, $targetHeight);
                if (!$target instanceof GdImage) {
                    throw new ApiException(503, 'Unable to allocate the responsive image.');
                }

                try {
                    imagealphablending($target, false);
                    imagesavealpha($target, true);
                    $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);
                    imagefilledrectangle($target, 0, 0, $targetWidth, $targetHeight, $transparent);
                    imagecopyresampled(
                        $target,
                        $source,
                        0,
                        0,
                        0,
                        0,
                        $targetWidth,
                        $targetHeight,
                        $sourceWidth,
                        $sourceHeight
                    );

                    ob_start();
                    try {
                        $encoded = imagewebp($target, null, self::WEBP_QUALITY);
                        $bytes = ob_get_contents();
                    } finally {
                        ob_end_clean();
                    }
                    if (!$encoded || !is_string($bytes) || $bytes === '') {
                        throw new ApiException(503, 'Unable to encode the responsive image.');
                    }
                    $variants[] = [
                        'width' => $targetWidth,
                        'height' => $targetHeight,
                        'contentType' => 'image/webp',
                        'size' => strlen($bytes),
                        'bytes' => $bytes,
                    ];
                } finally {
                    unset($target);
                }
            }
        } finally {
            unset($source);
        }

        return $variants;
    }
}
