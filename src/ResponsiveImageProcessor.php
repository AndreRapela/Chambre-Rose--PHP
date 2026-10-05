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

    /**
     * Re-encodes the canonical upload as WebP, removing EXIF and other embedded
     * metadata before the original is persisted or served publicly.
     */
    public function sanitize(string $sourceBytes): string
    {
        if (!function_exists('imagecreatefromstring') || !function_exists('imagewebp')) {
            throw new ApiException(503, 'Image sanitization is unavailable.');
        }

        $dimensions = @getimagesizefromstring($sourceBytes);
        if (!is_array($dimensions)) {
            throw new ApiException(400, 'The uploaded image is invalid or corrupted.');
        }
        $sourceWidth = (int) $dimensions[0];
        $sourceHeight = (int) $dimensions[1];
        self::assertSupportedDimensions($sourceWidth, $sourceHeight);

        $source = @imagecreatefromstring($sourceBytes);
        if (!$source instanceof GdImage) {
            throw new ApiException(400, 'The uploaded image could not be decoded.');
        }

        try {
            if (strtolower((string) $dimensions['mime']) === 'image/jpeg') {
                $source = self::applyExifOrientation($source, self::jpegExifOrientation($sourceBytes));
            }
            imagealphablending($source, false);
            imagesavealpha($source, true);

            ob_start();
            try {
                $encoded = imagewebp($source, null, 82);
                $bytes = ob_get_contents();
            } finally {
                ob_end_clean();
            }
            if (!$encoded || !is_string($bytes) || $bytes === '') {
                throw new ApiException(503, 'Unable to sanitize the uploaded image.');
            }

            return $bytes;
        } finally {
            unset($source);
        }
    }

    /** @return array{width: int, height: int, contentType: string, size: int, bytes: string} */
    public function blurredPreview(string $sourceBytes): array
    {
        if (!function_exists('imagecreatefromstring')
            || !function_exists('imagefilter')
            || !function_exists('imagewebp')
        ) {
            throw new ApiException(503, 'VIP image preview processing is unavailable.');
        }

        $sourceBytes = $this->sanitize($sourceBytes);

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
            throw new ApiException(413, 'The image aspect ratio is too large for preview processing.');
        }

        $source = @imagecreatefromstring($sourceBytes);
        if (!$source instanceof GdImage) {
            throw new ApiException(400, 'The uploaded image could not be decoded.');
        }

        $targetWidth = min(160, $sourceWidth);
        $targetHeight = max(1, (int) round($sourceHeight * ($targetWidth / $sourceWidth)));
        $target = imagecreatetruecolor($targetWidth, $targetHeight);
        if (!$target instanceof GdImage) {
            unset($source);
            throw new ApiException(503, 'Unable to allocate the VIP image preview.');
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
            for ($pass = 0; $pass < 5; $pass++) {
                imagefilter($target, IMG_FILTER_GAUSSIAN_BLUR);
            }
            imagefilter($target, IMG_FILTER_COLORIZE, 28, 18, 22, 18);

            ob_start();
            try {
                $encoded = imagewebp($target, null, 52);
                $bytes = ob_get_contents();
            } finally {
                ob_end_clean();
            }
            if (!$encoded || !is_string($bytes) || $bytes === '') {
                throw new ApiException(503, 'Unable to encode the VIP image preview.');
            }

            return [
                'width' => $targetWidth,
                'height' => $targetHeight,
                'contentType' => 'image/webp',
                'size' => strlen($bytes),
                'bytes' => $bytes,
            ];
        } finally {
            unset($target, $source);
        }
    }

    private static function assertSupportedDimensions(int $width, int $height): void
    {
        if ($width < 1 || $height < 1 || $width * $height > self::MAX_PIXELS) {
            throw new ApiException(413, 'The image dimensions are too large.');
        }
        if (max($width / $height, $height / $width) > self::MAX_ASPECT_RATIO) {
            throw new ApiException(413, 'The image aspect ratio is too large for responsive processing.');
        }
    }

    private static function applyExifOrientation(GdImage $image, int $orientation): GdImage
    {
        $rotation = match ($orientation) {
            3, 4 => 180,
            5, 6 => 270,
            7, 8 => 90,
            default => 0,
        };
        if ($rotation !== 0) {
            $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
            $rotated = @imagerotate($image, $rotation, $transparent);
            if ($rotated instanceof GdImage) {
                unset($image);
                $image = $rotated;
                imagealphablending($image, false);
                imagesavealpha($image, true);
            }
        }

        $flip = match ($orientation) {
            2, 5, 7 => IMG_FLIP_HORIZONTAL,
            4 => IMG_FLIP_VERTICAL,
            default => null,
        };
        if ($flip !== null) {
            imageflip($image, $flip);
        }

        return $image;
    }

    private static function jpegExifOrientation(string $bytes): int
    {
        if (!str_starts_with($bytes, "\xFF\xD8")) {
            return 1;
        }

        $length = strlen($bytes);
        for ($offset = 2; $offset + 4 <= $length;) {
            if (ord($bytes[$offset]) !== 0xFF) {
                break;
            }
            while ($offset < $length && ord($bytes[$offset]) === 0xFF) {
                $offset++;
            }
            if ($offset >= $length) {
                break;
            }

            $marker = ord($bytes[$offset++]);
            if ($marker === 0xDA || $marker === 0xD9) {
                break;
            }
            if ($marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD7)) {
                continue;
            }
            if ($offset + 2 > $length) {
                break;
            }

            $segmentLength = unpack('n', substr($bytes, $offset, 2))[1] ?? 0;
            if ($segmentLength < 2 || $offset + $segmentLength > $length) {
                break;
            }
            if ($marker === 0xE1) {
                $orientation = self::orientationFromExifSegment(
                    substr($bytes, $offset + 2, $segmentLength - 2)
                );
                if ($orientation !== null) {
                    return $orientation;
                }
            }
            $offset += $segmentLength;
        }

        return 1;
    }

    private static function orientationFromExifSegment(string $segment): ?int
    {
        if (!str_starts_with($segment, "Exif\0\0")) {
            return null;
        }
        $tiffStart = 6;
        $byteOrder = substr($segment, $tiffStart, 2);
        if (!in_array($byteOrder, ['II', 'MM'], true)) {
            return null;
        }
        $littleEndian = $byteOrder === 'II';
        if (self::readTiffUInt16($segment, $tiffStart + 2, $littleEndian) !== 42) {
            return null;
        }
        $ifdOffset = self::readTiffUInt32($segment, $tiffStart + 4, $littleEndian);
        if ($ifdOffset === null) {
            return null;
        }
        $ifd = $tiffStart + $ifdOffset;
        $entryCount = self::readTiffUInt16($segment, $ifd, $littleEndian);
        if ($entryCount === null) {
            return null;
        }

        for ($index = 0; $index < $entryCount; $index++) {
            $entry = $ifd + 2 + ($index * 12);
            $tag = self::readTiffUInt16($segment, $entry, $littleEndian);
            if ($tag !== 0x0112) {
                continue;
            }
            $type = self::readTiffUInt16($segment, $entry + 2, $littleEndian);
            $count = self::readTiffUInt32($segment, $entry + 4, $littleEndian);
            if ($type !== 3 || $count === null || $count < 1) {
                return null;
            }
            $valueOffset = $entry + 8;
            if ($count !== 1) {
                $relativeValueOffset = self::readTiffUInt32($segment, $entry + 8, $littleEndian);
                if ($relativeValueOffset === null || $relativeValueOffset > strlen($segment) - $tiffStart) {
                    return null;
                }
                $valueOffset = $tiffStart + $relativeValueOffset;
            }
            $orientation = self::readTiffUInt16($segment, $valueOffset, $littleEndian);

            return $orientation !== null && $orientation >= 1 && $orientation <= 8
                ? $orientation
                : null;
        }

        return null;
    }

    private static function readTiffUInt16(string $bytes, int $offset, bool $littleEndian): ?int
    {
        if ($offset < 0 || $offset + 2 > strlen($bytes)) {
            return null;
        }

        return unpack($littleEndian ? 'v' : 'n', substr($bytes, $offset, 2))[1] ?? null;
    }

    private static function readTiffUInt32(string $bytes, int $offset, bool $littleEndian): ?int
    {
        if ($offset < 0 || $offset + 4 > strlen($bytes)) {
            return null;
        }

        return unpack($littleEndian ? 'V' : 'N', substr($bytes, $offset, 4))[1] ?? null;
    }
}
