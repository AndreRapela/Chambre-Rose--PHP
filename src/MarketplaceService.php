<?php

declare(strict_types=1);

namespace ChambreRose;

use DateTimeImmutable;

final class MarketplaceService
{
    private const IMAGE_MAX = 8 * 1024 * 1024;
    private const VIDEO_MAX = 25 * 1024 * 1024;

    public function __construct(
        private readonly UserRepository $users,
        private readonly ProfessionalProfileRepository $profiles,
        private readonly ProfileMediaRepository $media,
        private readonly ResponsiveImageService $responsiveImages
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function saveProfile(int $userId, array $input): array
    {
        $user = $this->users->find($userId) ?? throw new ApiException(404, 'User not found.');
        if (!in_array($user['role'], ['ESCORT','STORE'], true)) {
            throw new ApiException(403, 'A professional account is required.');
        }
        $existing = $this->profiles->findByUser($userId) ?? [];
        $data = $this->validateProfile($user['role'], array_replace($existing, $input), $user);

        return $this->withMedia($this->profiles->upsert($userId, $user['role'], $data));
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $user
     */
    public function validateProfileInput(string $type, array $input, array $user): void
    {
        $this->validateProfile($type, $input, $user);
    }

    /** @return array<string, mixed> */
    public function ownProfile(int $userId): array
    {
        $profile = $this->profiles->findByUser($userId) ?? throw new ApiException(404, 'Professional profile not found.');

        return $this->withMedia($profile);
    }

    /** @return array<string, mixed> */
    public function publicProfile(int $userId): array
    {
        $profile = $this->profiles->findByUser($userId, true) ?? throw new ApiException(404, 'Listing not found.');
        $profile['reviews'] = $this->profiles->reviews($userId);

        return $this->withMedia($profile, true);
    }

    /** @return array<string, mixed> */
    public function visitPublicProfile(int $userId): array
    {
        $profile = $this->publicProfile($userId);
        $this->profiles->incrementViews($userId);
        $profile['viewsCount'] = (int) ($profile['viewsCount'] ?? 0) + 1;

        return $profile;
    }

    /**
     * @param list<int> $userIds
     * @return list<array<string, mixed>>
     */
    public function publicListingsByUserIds(array $userIds): array
    {
        $userIds = array_values(array_unique(array_filter(
            $userIds,
            static fn (int $userId): bool => $userId > 0
        )));
        if ($userIds === []) {
            return [];
        }

        $profilesByUser = [];
        foreach ($this->profiles->findPublicListingsByUserIds($userIds) as $profile) {
            $profilesByUser[(int) $profile['userId']] = $profile;
        }
        $mediaByUser = $this->media->firstPhotosForUsers($userIds, true);
        $result = [];
        foreach ($userIds as $userId) {
            if (!isset($profilesByUser[$userId])) {
                continue;
            }
            $profile = $profilesByUser[$userId];
            $profile['media'] = $mediaByUser[$userId] ?? [];
            $result[] = $profile;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $requester
     * @return array<string, mixed>
     */
    public function contactDetails(int $profileUserId, array $requester): array
    {
        $profile = $this->profiles->findByUser($profileUserId)
            ?? throw new ApiException(404, 'Listing not found.');
        if (($profile['approvalStatus'] ?? '') !== 'APPROVED') {
            throw new ApiException(404, 'Listing not found.');
        }
        if ($profile['type'] === 'ESCORT'
            && ($requester['role'] ?? '') === 'VISITOR'
            && ($requester['vipActive'] ?? false) !== true
        ) {
            throw new ApiException(403, 'VIP membership is required to access companion contact details.', ['vipRequired' => 'true']);
        }

        return [
            'email' => $profile['contactEmail'] ?? null,
            'responseTime' => $profile['responseTime'] ?? null,
            'canReview' => $this->profiles->canReview($profileUserId, (int) $requester['id']),
        ];
    }

    /**
     * @param array<string, mixed> $reviewer
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function submitReview(int $profileUserId, array $reviewer, array $input): array
    {
        $profile = $this->profiles->findByUser($profileUserId, true)
            ?? throw new ApiException(404, 'Listing not found.');
        if ($profile['type'] !== 'ESCORT') {
            throw new ApiException(400, 'Only companion profiles can be reviewed here.');
        }
        $rating = filter_var($input['rating'] ?? null, FILTER_VALIDATE_INT);
        $body = trim((string) ($input['body'] ?? ''));
        $errors = [];
        if ($rating === false || $rating < 1 || $rating > 5) {
            $errors['rating'] = 'must be an integer between 1 and 5';
        }
        if (self::len($body) < 10 || self::len($body) > 500) {
            $errors['body'] = 'must contain between 10 and 500 characters';
        }
        if ($errors !== []) {
            throw new ApiException(400, 'Invalid review data.', $errors);
        }
        $reviewerName = trim((string) ($reviewer['firstName'] ?? '') . ' ' . (string) ($reviewer['lastName'] ?? ''));
        if ($reviewerName === '') {
            $reviewerName = 'Member';
        }

        return $this->profiles->addReview(
            $profileUserId,
            (int) $reviewer['id'],
            $reviewerName,
            (int) $rating,
            $body
        );
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function listings(array $filters): array
    {
        $result = $this->profiles->search($filters);
        $userIds = array_map(
            static fn (array $profile): int => (int) $profile['userId'],
            $result['items']
        );
        $mediaByUser = $this->media->firstPhotosForUsers($userIds, true);
        $result['items'] = array_map(
            static function (array $profile) use ($mediaByUser): array {
                $profile['media'] = $mediaByUser[(int) $profile['userId']] ?? [];

                return $profile;
            },
            $result['items']
        );

        return $result;
    }

    /** @return array<string, mixed> */
    public function upload(int $userId, UploadedFile $file, int $position = 0): array
    {
        $this->ownProfile($userId);
        if ($file->isEmpty()) {
            throw new ApiException(400, 'A media file is required.', ['media' => 'is required']);
        }
        $mime = $file->detectedContentType();
        $images = ['image/jpeg','image/png','image/webp'];
        $videos = ['video/mp4','video/webm'];
        if (in_array($mime, $images, true)) {
            $type = 'PHOTO';
            $max = self::IMAGE_MAX;
            $limit = 15;
        } elseif (in_array($mime, $videos, true)) {
            $type = 'VIDEO';
            $max = self::VIDEO_MAX;
            $limit = 3;
        } else {
            throw new ApiException(400, 'Media must be JPG, PNG, WebP, MP4 or WebM.');
        }
        $size = $file->actualSize();
        if ($size > $max) {
            throw new ApiException(413, $type === 'PHOTO' ? 'A photo cannot exceed 8 MB.' : 'A video cannot exceed 25 MB.');
        }
        $name = trim(preg_replace('/[\x00-\x1F\x7F"]/', '', basename(str_replace('\\', '/', $file->name))) ?? '') ?: strtolower($type);

        $bytes = $file->bytes();
        $prepared = $type === 'PHOTO' ? $this->responsiveImages->prepare($bytes) : [];
        $media = $this->media->insertWithinLimit(
            $userId,
            $type,
            $name,
            $mime,
            $bytes,
            max(0, min(32767, $position)),
            $limit
        );
        if ($type === 'PHOTO') {
            try {
                $this->responsiveImages->storePrepared('PROFILE', (int) $media['id'], $bytes, $prepared);
            } catch (\Throwable $exception) {
                $this->media->delete($userId, (int) $media['id']);
                throw $exception;
            }
        }

        return $type === 'PHOTO'
            ? ($this->media->metadata($userId, (int) $media['id']) ?? $media)
            : $media;
    }

    /** @return array{width: int, height: int, contentType: string, size: int, bytes: string, sourceHash: string, updatedAt: string} */
    public function responsivePhoto(int $userId, int $mediaId, int $width): array
    {
        $meta = $this->media->metadata($userId, $mediaId);
        if ($meta === null || $meta['type'] !== 'PHOTO') {
            throw new ApiException(404, 'Profile photo not found.');
        }
        $cached = $this->responsiveImages->cachedVariant('PROFILE', $mediaId, $width);
        if ($cached !== null) {
            return $cached;
        }
        $bytes = $this->media->data($userId, $mediaId);
        if ($bytes === null) {
            throw new ApiException(404, 'Profile photo not found.');
        }

        return $this->responsiveImages->variant('PROFILE', $mediaId, $bytes, $width);
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private function validateProfile(string $type, array $input, array $user): array
    {
        $aliases = ['description' => 'bio','serviceArea' => 'location','businessSegment' => 'segment'];
        foreach ($aliases as $alias => $target) {
            if (!array_key_exists($target, $input) && array_key_exists($alias, $input)) {
                $input[$target] = $input[$alias];
            }
        }
        $errors = [];
        if ($type === 'ESCORT' && trim((string) ($user['address'] ?? '')) === '') {
            $errors['address'] = 'is required for companion accounts';
        }
        $display = trim((string)($input['displayName'] ?? ''));
        if ($display === '' || self::len($display) > 120) {
            $errors['displayName'] = 'must contain between 1 and 120 characters';
        }
        $data = ['displayName' => $display];
        foreach (['gender' => 40,'location' => 260,'locationCity' => 80,'locationRegion' => 100,'locationCountry' => 80,'bio' => 3000,'hair' => 60,'eyes' => 60,'origin' => 80,'availability' => 500,'website' => 300,'businessName' => 160,'legalName' => 160,'segment' => 100,'businessAddress' => 200,'businessHours' => 500,'contactEmail' => 160,'responseTime' => 40] as $field => $max) {
            $value = in_array($field, ['location', 'locationCity', 'locationRegion', 'locationCountry'], true)
                ? LocationNormalizer::display($input[$field] ?? '')
                : (isset($input[$field]) ? trim((string)$input[$field]) : '');
            if (self::len($value) > $max) {
                $errors[$field] = "cannot exceed {$max} characters";
            }
            $data[$field] = $value === '' ? null : $value;
        }
        if ($data['website'] !== null) {
            $scheme = strtolower((string) parse_url($data['website'], PHP_URL_SCHEME));
            if (filter_var($data['website'], FILTER_VALIDATE_URL) === false
                || !in_array($scheme, ['http', 'https'], true)
            ) {
                $errors['website'] = 'must be a valid HTTP or HTTPS URL';
            }
        }
        if ($data['contactEmail'] !== null && filter_var($data['contactEmail'], FILTER_VALIDATE_EMAIL) === false) {
            $errors['contactEmail'] = 'must be a valid email address';
        }
        $allowedResponseTimes = ['LESS_THAN_HOUR','FEW_HOURS','WITHIN_DAY','MORE_THAN_DAY','VARIES'];
        if ($data['responseTime'] !== null && !in_array($data['responseTime'], $allowedResponseTimes, true)) {
            $errors['responseTime'] = 'must be one of the supported response time options';
        }
        foreach (['languages','services','interests','contactOptions'] as $field) {
            $value = $input[$field] ?? [];
            if (is_string($value)) {
                $decoded = json_decode($value, true);
                $value = is_array($decoded) ? $decoded : array_filter(array_map('trim', explode(',', $value)));
            }
            if (!is_array($value) || count($value) > 30) {
                $errors[$field] = 'must be a list with at most 30 values';
            }
            $items = is_array($value) ? $value : [];
            $items = array_map(static fn ($item) => trim((string) $item), $items);
            $items = array_filter($items, static fn (string $item) => $item !== '' && self::len($item) <= 80);
            $data[$field] = array_values(array_unique($items));
        }
        foreach (['priceFrom','priceTo','priceHour','priceNight','priceWeekend'] as $field) {
            $value = $input[$field] ?? null;
            if ($value !== null && $value !== '' && (!is_numeric($value) || (float)$value < 0 || (float)$value > 99999999.99)) {
                $errors[$field] = 'must be a valid non-negative amount';
            }
            $data[$field] = $value === null || $value === '' ? null : (float)$value;
        }
        if (!array_key_exists('priceHour', $input) && $data['priceFrom'] !== null) {
            $data['priceHour'] = $data['priceFrom'];
        }
        if ($data['priceFrom'] !== null && $data['priceTo'] !== null && $data['priceFrom'] > $data['priceTo']) {
            $errors['priceTo'] = 'must be greater than or equal to priceFrom';
        }
        if ($type === 'ESCORT') {
            $birth = trim((string)($input['birthDate'] ?? ''));

            try {
                $date = $birth === '' ? null : new DateTimeImmutable($birth);
                $today = new DateTimeImmutable('today');
                if ($date === null
                    || $date->format('Y-m-d') !== $birth
                    || $date > $today
                    || $date->diff($today)->y < 18
                ) {
                    $errors['birthDate'] = 'must be a valid date for an adult (18+)';
                }
            } catch (\Throwable) {
                $errors['birthDate'] = 'must be a valid date for an adult (18+)';
            }
            $data['birthDate'] = $birth === '' ? null : $birth;
            $height = $input['heightCm'] ?? null;
            if ($height !== null && $height !== '' && (filter_var($height, FILTER_VALIDATE_INT) === false || (int)$height < 100 || (int)$height > 250)) {
                $errors['heightCm'] = 'must be between 100 and 250';
            }
            $data['heightCm'] = $height === null || $height === '' ? null : (int)$height;
            foreach (['weightKg' => [35, 250], 'bustCm' => [40, 200], 'waistCm' => [40, 200], 'hipsCm' => [40, 220]] as $field => [$minimum, $maximum]) {
                $value = $input[$field] ?? null;
                if ($value !== null && $value !== '' && (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < $minimum || (int) $value > $maximum)) {
                    $errors[$field] = "must be between {$minimum} and {$maximum}";
                }
                $data[$field] = $value === null || $value === '' ? null : (int) $value;
            }
            if ($data['gender'] === null) {
                $errors['gender'] = 'is required';
            }
        } else {
            $data['birthDate'] = null;
            $data['heightCm'] = null;
            $data['gender'] = null;
            $data['hair'] = null;
            $data['eyes'] = null;
            $data['weightKg'] = null;
            $data['bustCm'] = null;
            $data['waistCm'] = null;
            $data['hipsCm'] = null;
            $data['origin'] = null;
            if ($data['businessName'] === null) {
                $data['businessName'] = $display;
            }
            if ($data['segment'] === null) {
                $errors['segment'] = 'is required';
            }
        }
        $legacyLocation = array_values(array_filter(array_map(
            static fn (string $part): string => trim($part),
            explode(',', (string) ($data['location'] ?? ''))
        )));
        $accountCity = trim((string) ($user['city'] ?? ''));
        $accountCountry = trim((string) ($user['country'] ?? ''));
        $data['locationCity'] ??= $legacyLocation[0] ?? ($accountCity !== '' ? $accountCity : null);
        $data['locationCountry'] ??= count($legacyLocation) > 1
            ? $legacyLocation[array_key_last($legacyLocation)]
            : ($accountCountry !== '' ? $accountCountry : null);
        if ($data['locationCity'] === null) {
            $errors['locationCity'] = 'is required';
        }
        if ($data['locationCountry'] === null) {
            $errors['locationCountry'] = 'is required';
        }
        $data['location'] = implode(', ', array_filter([
            $data['locationCity'],
            $data['locationRegion'],
            $data['locationCountry'],
        ]));
        if ($errors !== []) {
            throw new ApiException(400, 'Invalid professional profile data.', $errors);
        }

        return $data;
    }
    /**
     * @param array<string, mixed> $profile
     * @return array<string, mixed>
     */
    private function withMedia(array $profile, bool $public = false): array
    {
        $profile['media'] = $this->media->listFor((int) $profile['userId'], $public);

        return $profile;
    }
    private static function len(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }
}
