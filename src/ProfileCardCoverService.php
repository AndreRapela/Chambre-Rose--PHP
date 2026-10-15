<?php

declare(strict_types=1);

namespace ChambreRose;

final class ProfileCardCoverService
{
    public function __construct(
        private readonly ProfileCardCoverRepository $covers,
        private readonly ResponsiveImageProcessor $processor
    ) {
    }

    /** @return array{cardCoverUrl: ?string, cardCoverSrcSet: ?string} */
    public function upload(int $userId, UploadedFile $file): array
    {
        $bytes = $file->bytes();
        if ($bytes === '') {
            throw new ApiException(400, 'Choose a card cover.');
        }
        if (strlen($bytes) > 8 * 1024 * 1024) {
            throw new ApiException(413, 'A card cover cannot exceed 8 MB.');
        }
        if (!in_array($file->detectedContentType(), ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new ApiException(400, 'Card cover must be JPG, PNG or WebP.');
        }
        $sanitized = $this->processor->sanitize($bytes);
        $variants = $this->processor->generate($sanitized);
        if ($variants === []) {
            $dimensions = getimagesizefromstring($sanitized);
            if ($dimensions === false) {
                throw new ApiException(400, 'The uploaded image is invalid or corrupted.');
            }
            $variants[] = [
                'width' => $dimensions[0], 'height' => $dimensions[1],
                'bytes' => $sanitized, 'contentType' => 'image/webp', 'size' => strlen($sanitized),
            ];
        }
        $this->covers->replace($userId, hash('sha256', $sanitized), $variants);

        return $this->covers->forUser($userId);
    }
}
