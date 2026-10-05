<?php

declare(strict_types=1);

namespace ChambreRose;

final class PromotionService
{
    private const IMAGE_MAX = 8 * 1024 * 1024;
    /** @var list<string> */
    private const ICONS = ['gift', 'truck', 'heart', 'star', 'diamond', 'shopping-bag', 'bullhorn'];

    public function __construct(
        private readonly PromotionRepository $promotions,
        private readonly ?ResponsiveImageService $responsiveImages = null
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return $this->promotions->all();
    }

    /** @return array{contentType:string,size:int,bytes:string,updatedAt:string} */
    public function image(int $slot): array
    {
        self::assertSlot($slot);

        $image = $this->promotions->image($slot)
            ?? throw new ApiException(404, 'Promotion image not found.');
        $image['bytes'] = $this->sanitizeImage($image['bytes']);
        $image['contentType'] = 'image/webp';
        $image['size'] = strlen($image['bytes']);

        return $image;
    }

    /** @param array<string, string> $input
     *  @return array<string, mixed>
     */
    public function update(int $slot, array $input, ?UploadedFile $file): array
    {
        self::assertSlot($slot);
        $limits = ['label' => 80, 'title' => 160, 'subtitle' => 400, 'linkText' => 100];
        $data = [];
        $errors = [];
        foreach ($limits as $field => $maximum) {
            $value = trim((string) ($input[$field] ?? ''));
            if ($value === '' || self::length($value) > $maximum) {
                $errors[$field] = 'is required and cannot exceed ' . $maximum . ' characters';
            }
            $data[$field] = $value;
        }
        $linkUrl = trim((string) ($input['linkUrl'] ?? ''));
        if (!self::validLink($linkUrl)) {
            $errors['linkUrl'] = 'must be an internal path or a valid HTTP/HTTPS URL';
        }
        $data['linkUrl'] = $linkUrl;
        $icon = strtolower(trim((string) ($input['icon'] ?? '')));
        if (!in_array($icon, self::ICONS, true)) {
            $errors['icon'] = 'must be one of the available promotion icons';
        }
        $data['icon'] = $icon;
        if ($errors !== []) {
            throw new ApiException(400, 'Invalid promotion data.', $errors);
        }

        $image = null;
        if ($file !== null && !$file->isEmpty()) {
            $contentType = $file->detectedContentType();
            if (!in_array($contentType, ['image/jpeg', 'image/png', 'image/webp'], true)) {
                throw new ApiException(400, 'Promotion image must be JPG, PNG or WebP.', [
                    'image' => 'must be JPG, PNG or WebP',
                ]);
            }
            $bytes = $file->bytes();
            if (strlen($bytes) > self::IMAGE_MAX) {
                throw new ApiException(413, 'A promotion image cannot exceed 8 MB.');
            }
            $bytes = $this->sanitizeImage($bytes);
            if (strlen($bytes) > self::IMAGE_MAX) {
                throw new ApiException(413, 'A processed promotion image cannot exceed 8 MB.');
            }
            $dimensions = @getimagesizefromstring($bytes);
            if (!is_array($dimensions) || (int) $dimensions[0] < 1 || (int) $dimensions[1] < 1) {
                throw new ApiException(400, 'The promotion image is invalid or corrupted.', [
                    'image' => 'is invalid or corrupted',
                ]);
            }
            $name = trim(preg_replace('/[\x00-\x1F\x7F"]/', '', basename(str_replace('\\', '/', $file->name))) ?? '')
                ?: 'promotion-image';
            $image = ['name' => $name, 'contentType' => 'image/webp', 'bytes' => $bytes];
        }

        /** @var array{label:string,title:string,subtitle:string,linkUrl:string,linkText:string,icon:string} $data */
        return $this->promotions->update($slot, $data, $image);
    }

    private static function assertSlot(int $slot): void
    {
        if (!in_array($slot, [1, 2], true)) {
            throw new ApiException(404, 'Promotion not found.');
        }
    }

    private static function validLink(string $value): bool
    {
        if ($value === '' || self::length($value) > 500) {
            return false;
        }
        if (str_starts_with($value, '/') && !str_starts_with($value, '//')) {
            return true;
        }
        if (filter_var($value, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        return in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true);
    }

    private static function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }

    private function sanitizeImage(string $bytes): string
    {
        return $this->responsiveImages?->sanitize($bytes)
            ?? (new ResponsiveImageProcessor())->sanitize($bytes);
    }
}
