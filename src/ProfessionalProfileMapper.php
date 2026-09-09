<?php

declare(strict_types=1);

namespace ChambreRose;

use DateTimeImmutable;
use DateTimeZone;

final class ProfessionalProfileMapper
{
    /**
     * @param array<string, mixed> $profile
     * @return array<string, mixed>
     */
    public static function parameters(int $userId, string $type, array $profile): array
    {
        return [
            'user_id' => $userId, 'profile_type' => $type,
            'display_name' => $profile['displayName'], 'birth_date' => $profile['birthDate'] ?? null,
            'gender' => $profile['gender'] ?? null, 'location' => $profile['location'] ?? null,
            'location_city' => $profile['locationCity'] ?? null,
            'location_region' => $profile['locationRegion'] ?? null,
            'location_country' => $profile['locationCountry'] ?? null,
            'bio' => $profile['bio'] ?? null, 'languages' => self::encodeList($profile['languages'] ?? []),
            'height_cm' => $profile['heightCm'] ?? null, 'hair' => $profile['hair'] ?? null,
            'eyes' => $profile['eyes'] ?? null, 'services' => self::encodeList($profile['services'] ?? []),
            'availability' => $profile['availability'] ?? null, 'website' => $profile['website'] ?? null,
            'price_from' => $profile['priceFrom'] ?? null, 'price_to' => $profile['priceTo'] ?? null,
            'price_hour' => $profile['priceHour'] ?? ($profile['priceFrom'] ?? null),
            'price_night' => $profile['priceNight'] ?? null,
            'price_weekend' => $profile['priceWeekend'] ?? null,
            'business_name' => $profile['businessName'] ?? null, 'legal_name' => $profile['legalName'] ?? null,
            'segment' => $profile['segment'] ?? null, 'business_address' => $profile['businessAddress'] ?? null,
            'business_hours' => $profile['businessHours'] ?? null,
            'weight_kg' => $profile['weightKg'] ?? null, 'bust_cm' => $profile['bustCm'] ?? null,
            'waist_cm' => $profile['waistCm'] ?? null, 'hips_cm' => $profile['hipsCm'] ?? null,
            'origin' => $profile['origin'] ?? null, 'interests' => self::encodeList($profile['interests'] ?? []),
            'contact_options' => self::encodeList($profile['contactOptions'] ?? []),
            'contact_email' => $profile['contactEmail'] ?? null,
            'response_time' => $profile['responseTime'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function map(array $row): array
    {
        $birthDate = $row['birth_date'] === null ? null : substr((string) $row['birth_date'], 0, 10);
        $age = $birthDate === null ? null : (new DateTimeImmutable($birthDate))->diff(new DateTimeImmutable('today'))->y;
        $id = (int) $row['user_id'];

        return [
            'id' => $id, 'userId' => $id, 'ownerId' => $id, 'type' => (string) $row['profile_type'],
            'displayName' => (string) $row['display_name'],
            'city' => (string) ($row['location_city'] ?: $row['account_city']),
            'province' => $row['location_region'],
            'country' => (string) ($row['location_country'] ?: $row['account_country']),
            'locationCity' => (string) ($row['location_city'] ?: $row['account_city']),
            'locationRegion' => $row['location_region'],
            'locationCountry' => (string) ($row['location_country'] ?: $row['account_country']),
            'location' => $row['location'], 'bio' => $row['bio'], 'birthDate' => $birthDate, 'age' => $age,
            'gender' => $row['gender'], 'languages' => self::decodeList($row['languages']),
            'heightCm' => $row['height_cm'] === null ? null : (int) $row['height_cm'],
            'weightKg' => $row['weight_kg'] === null ? null : (int) $row['weight_kg'],
            'bustCm' => $row['bust_cm'] === null ? null : (int) $row['bust_cm'],
            'waistCm' => $row['waist_cm'] === null ? null : (int) $row['waist_cm'],
            'hipsCm' => $row['hips_cm'] === null ? null : (int) $row['hips_cm'],
            'hair' => $row['hair'], 'eyes' => $row['eyes'], 'services' => self::decodeList($row['services']),
            'origin' => $row['origin'], 'interests' => self::decodeList($row['interests']),
            'contactOptions' => self::decodeList($row['contact_options']),
            'contactEmail' => $row['contact_email'], 'responseTime' => $row['response_time'],
            'availability' => $row['availability'], 'website' => $row['website'],
            'priceFrom' => $row['price_from'] === null ? null : (float) $row['price_from'],
            'priceTo' => $row['price_to'] === null ? null : (float) $row['price_to'],
            'priceHour' => $row['price_hour'] === null ? null : (float) $row['price_hour'],
            'priceNight' => $row['price_night'] === null ? null : (float) $row['price_night'],
            'priceWeekend' => $row['price_weekend'] === null ? null : (float) $row['price_weekend'],
            'businessName' => $row['business_name'], 'legalName' => $row['legal_name'],
            'segment' => $row['segment'], 'businessAddress' => $row['business_address'],
            'businessHours' => $row['business_hours'], 'vipActive' => self::bool($row['vip_active']),
            'purchaseCount' => max(0, (int) $row['purchase_count']),
            'starCount' => max(0, (int) $row['purchase_count']),
            'viewsCount' => max(0, (int) $row['views_count']),
            'reviewCount' => max(0, (int) $row['review_count']),
            'averageRating' => $row['average_rating'] === null ? null : round((float) $row['average_rating'], 1),
            'verified' => self::bool($row['verified']),
            'approvalStatus' => (string) $row['approval_status'],
            'createdAt' => self::time($row['created_at']), 'updatedAt' => self::time($row['updated_at']),
        ];
    }

    private static function encodeList(mixed $value): string
    {
        return json_encode(is_array($value) ? array_values($value) : [], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** @return list<string> */
    private static function decodeList(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : [];

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }

    /**
     * @param array<string, mixed> $profile
     * @return array<string, mixed>
     */
    public static function publicView(array $profile): array
    {
        $profile['hasContactEmail'] = isset($profile['contactEmail']) && $profile['contactEmail'] !== '';
        unset(
            $profile['birthDate'],
            $profile['legalName'],
            $profile['businessAddress'],
            $profile['contactEmail'],
            $profile['priceFrom'],
            $profile['priceTo']
        );

        return $profile;
    }

    /**
     * List responses deliberately exclude prices. Prices are available only from
     * the profile detail endpoint and are revealed there after explicit action.
     *
     * @param array<string, mixed> $profile
     * @return array<string, mixed>
     */
    public static function listingView(array $profile): array
    {
        $profile = self::publicView($profile);
        unset(
            $profile['priceFrom'],
            $profile['priceTo'],
            $profile['priceHour'],
            $profile['priceNight'],
            $profile['priceWeekend']
        );

        return $profile;
    }

    private static function bool(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 't', 'true'], true);
    }

    private static function time(mixed $value): ?string
    {
        return $value === null
            ? null
            : (new DateTimeImmutable((string) $value))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s.v\\Z');
    }
}
