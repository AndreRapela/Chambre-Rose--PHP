<?php

declare(strict_types=1);

namespace ChambreRose;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class ProfessionalProfileRepository
{
    private const COLUMNS = <<<'SQL'
        p.user_id, p.profile_type, p.display_name, p.birth_date, p.gender, p.location,
        p.location_city, p.location_region, p.location_country, p.bio,
        p.languages, p.height_cm, p.hair, p.eyes, p.services, p.availability, p.website,
        p.price_from, p.price_to, p.price_hour, p.price_night, p.price_weekend,
        p.business_name, p.legal_name, p.segment,
        p.business_address, p.business_hours, p.weight_kg, p.bust_cm, p.waist_cm, p.hips_cm,
        p.origin, p.interests, p.contact_options, p.contact_email, p.response_time,
        p.purchase_count, p.views_count, p.verified,
        (SELECT COUNT(*) FROM profile_reviews r WHERE r.profile_user_id=p.user_id) AS review_count,
        (SELECT AVG(r.rating) FROM profile_reviews r WHERE r.profile_user_id=p.user_id) AS average_rating,
        p.created_at, p.updated_at,
        u.city AS account_city, u.country AS account_country,
        u.role, u.vip_active, u.approval_status
        SQL;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array<string, mixed> $profile
     * @return array<string, mixed>
     */
    public function upsert(int $userId, string $type, array $profile): array
    {
        $params = ProfessionalProfileMapper::parameters($userId, $type, $profile);
        if ($this->isMySql()) {
            $sql = <<<'SQL'
                INSERT INTO professional_profiles (
                  user_id, profile_type, display_name, birth_date, gender, location, location_city, location_region, location_country, bio, languages,
                  height_cm, hair, eyes, services, availability, website, price_from, price_to, price_hour, price_night, price_weekend,
                  business_name, legal_name, segment, business_address, business_hours,
                  weight_kg, bust_cm, waist_cm, hips_cm, origin, interests, contact_options,
                  contact_email, response_time,
                  created_at, updated_at
                ) VALUES (
                  :user_id, :profile_type, :display_name, :birth_date, :gender, :location, :location_city, :location_region, :location_country, :bio, :languages,
                  :height_cm, :hair, :eyes, :services, :availability, :website, :price_from, :price_to, :price_hour, :price_night, :price_weekend,
                  :business_name, :legal_name, :segment, :business_address, :business_hours,
                  :weight_kg, :bust_cm, :waist_cm, :hips_cm, :origin, :interests, :contact_options,
                  :contact_email, :response_time,
                  CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3)
                ) ON DUPLICATE KEY UPDATE
                  profile_type=VALUES(profile_type), display_name=VALUES(display_name),
                  birth_date=VALUES(birth_date), gender=VALUES(gender),
                  location=VALUES(location), location_city=VALUES(location_city), location_region=VALUES(location_region), location_country=VALUES(location_country), bio=VALUES(bio), languages=VALUES(languages),
                  height_cm=VALUES(height_cm), hair=VALUES(hair), eyes=VALUES(eyes), services=VALUES(services),
                  availability=VALUES(availability), website=VALUES(website), price_from=VALUES(price_from),
                  price_to=VALUES(price_to), price_hour=VALUES(price_hour), price_night=VALUES(price_night), price_weekend=VALUES(price_weekend), business_name=VALUES(business_name), legal_name=VALUES(legal_name),
                  segment=VALUES(segment), business_address=VALUES(business_address),
                  business_hours=VALUES(business_hours), weight_kg=VALUES(weight_kg),
                  bust_cm=VALUES(bust_cm), waist_cm=VALUES(waist_cm), hips_cm=VALUES(hips_cm),
                  origin=VALUES(origin), interests=VALUES(interests), contact_options=VALUES(contact_options),
                  contact_email=VALUES(contact_email), response_time=VALUES(response_time),
                  updated_at=CURRENT_TIMESTAMP(3)
                SQL;
        } else {
            $sql = <<<'SQL'
                INSERT INTO professional_profiles (
                  user_id, profile_type, display_name, birth_date, gender, location, location_city, location_region, location_country, bio, languages,
                  height_cm, hair, eyes, services, availability, website, price_from, price_to, price_hour, price_night, price_weekend,
                  business_name, legal_name, segment, business_address, business_hours,
                  weight_kg, bust_cm, waist_cm, hips_cm, origin, interests, contact_options,
                  contact_email, response_time,
                  created_at, updated_at
                ) VALUES (
                  :user_id, :profile_type, :display_name, :birth_date, :gender, :location, :location_city, :location_region, :location_country, :bio, :languages,
                  :height_cm, :hair, :eyes, :services, :availability, :website, :price_from, :price_to, :price_hour, :price_night, :price_weekend,
                  :business_name, :legal_name, :segment, :business_address, :business_hours,
                  :weight_kg, :bust_cm, :waist_cm, :hips_cm, :origin, :interests, :contact_options,
                  :contact_email, :response_time,
                  CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                ) ON CONFLICT (user_id) DO UPDATE SET
                  profile_type=EXCLUDED.profile_type, display_name=EXCLUDED.display_name,
                  birth_date=EXCLUDED.birth_date, gender=EXCLUDED.gender,
                  location=EXCLUDED.location, location_city=EXCLUDED.location_city, location_region=EXCLUDED.location_region, location_country=EXCLUDED.location_country, bio=EXCLUDED.bio, languages=EXCLUDED.languages,
                  height_cm=EXCLUDED.height_cm, hair=EXCLUDED.hair, eyes=EXCLUDED.eyes,
                  services=EXCLUDED.services, availability=EXCLUDED.availability, website=EXCLUDED.website,
                  price_from=EXCLUDED.price_from, price_to=EXCLUDED.price_to, price_hour=EXCLUDED.price_hour, price_night=EXCLUDED.price_night, price_weekend=EXCLUDED.price_weekend,
                  business_name=EXCLUDED.business_name, legal_name=EXCLUDED.legal_name,
                  segment=EXCLUDED.segment, business_address=EXCLUDED.business_address,
                  business_hours=EXCLUDED.business_hours, weight_kg=EXCLUDED.weight_kg,
                  bust_cm=EXCLUDED.bust_cm, waist_cm=EXCLUDED.waist_cm, hips_cm=EXCLUDED.hips_cm,
                  origin=EXCLUDED.origin, interests=EXCLUDED.interests,
                  contact_options=EXCLUDED.contact_options, contact_email=EXCLUDED.contact_email,
                  response_time=EXCLUDED.response_time, updated_at=CURRENT_TIMESTAMP
                SQL;
        }
        $this->pdo->prepare($sql)->execute($params);

        return $this->findByUser($userId) ?? throw new ApiException(500, 'Unable to save professional profile.');
    }

    /** @return array<string, mixed>|null */
    public function findByUser(int $userId, bool $publicOnly = false): ?array
    {
        $sql = 'SELECT ' . self::COLUMNS . ' FROM professional_profiles p JOIN users u ON u.id=p.user_id WHERE p.user_id=:id';
        if ($publicOnly) {
            $sql .= " AND u.approval_status='APPROVED' AND u.role IN ('ESCORT','STORE')";
        }
        $statement = $this->pdo->prepare($sql);
        $statement->execute(['id' => $userId]);
        $row = $statement->fetch();

        if (!is_array($row)) {
            return null;
        }

        $profile = ProfessionalProfileMapper::map($row);

        return $publicOnly ? ProfessionalProfileMapper::publicView($profile) : $profile;
    }

    /**
     * @param list<int> $userIds
     * @return list<array<string, mixed>>
     */
    public function findPublicListingsByUserIds(array $userIds): array
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn (int $id): bool => $id > 0)));
        if ($userIds === []) {
            return [];
        }
        [$placeholders, $params] = self::idParameters($userIds, 'public_profile');
        $statement = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM professional_profiles p JOIN users u ON u.id=p.user_id'
            . ' WHERE p.user_id IN (' . implode(',', $placeholders) . ") AND u.approval_status='APPROVED'"
            . " AND u.role IN ('ESCORT','STORE')"
        );
        $statement->execute($params);

        return array_map(
            static fn (array $row): array => ProfessionalProfileMapper::listingView(ProfessionalProfileMapper::map($row)),
            $statement->fetchAll()
        );
    }

    /**
     * @param list<int> $userIds
     * @return array<int, array<string, mixed>>
     */
    public function findAdminSummariesByUserIds(array $userIds): array
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn (int $id): bool => $id > 0)));
        if ($userIds === []) {
            return [];
        }
        [$placeholders, $params] = self::idParameters($userIds, 'admin_profile');
        $statement = $this->pdo->prepare(
            'SELECT user_id,profile_type,display_name,business_name,segment,location,location_city,location_region,location_country'
            . ' FROM professional_profiles WHERE user_id IN (' . implode(',', $placeholders) . ')'
        );
        $statement->execute($params);
        $summaries = [];
        foreach ($statement->fetchAll() as $row) {
            $userId = (int) $row['user_id'];
            $summaries[$userId] = [
                'type' => (string) $row['profile_type'],
                'displayName' => (string) $row['display_name'],
                'businessName' => self::nullableText($row['business_name'] ?? null),
                'segment' => self::nullableText($row['segment'] ?? null),
                'location' => self::nullableText($row['location'] ?? null),
                'locationCity' => self::nullableText($row['location_city'] ?? null),
                'locationRegion' => self::nullableText($row['location_region'] ?? null),
                'locationCountry' => self::nullableText($row['location_country'] ?? null),
            ];
        }

        return $summaries;
    }

    public function synchronizeType(int $userId, string $type): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE professional_profiles SET profile_type=:type, updated_at=CURRENT_TIMESTAMP WHERE user_id=:id'
        );
        $statement->execute(['id' => $userId, 'type' => $type]);
    }

    public function incrementViews(int $userId): void
    {
        $this->pdo->prepare('UPDATE professional_profiles SET views_count=views_count+1 WHERE user_id=:id')
            ->execute(['id' => $userId]);
    }

    /** @return list<array<string,mixed>> */
    public function reviews(int $userId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id,reviewer_name,rating,body,created_at FROM profile_reviews WHERE profile_user_id=:id ORDER BY created_at DESC,id DESC LIMIT 12'
        );
        $statement->execute(['id' => $userId]);

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'reviewerName' => (string) $row['reviewer_name'],
            'rating' => max(1, min(5, (int) $row['rating'])),
            'body' => (string) $row['body'],
            'createdAt' => self::time($row['created_at']),
        ], $statement->fetchAll());
    }

    public function canReview(int $profileUserId, int $reviewerUserId): bool
    {
        if ($profileUserId === $reviewerUserId) {
            return false;
        }
        $statement = $this->pdo->prepare(
            "SELECT 1 FROM marketplace_orders o
             WHERE o.profile_user_id=:profile AND o.buyer_user_id=:reviewer
               AND o.order_type='PROFILE' AND o.status='COMPLETED'
               AND NOT EXISTS (
                 SELECT 1 FROM profile_reviews r
                 WHERE r.profile_user_id=o.profile_user_id AND r.reviewer_user_id=o.buyer_user_id
               ) LIMIT 1"
        );
        $statement->execute(['profile' => $profileUserId, 'reviewer' => $reviewerUserId]);

        return $statement->fetchColumn() !== false;
    }

    /** @return array<string,mixed> */
    public function addReview(int $profileUserId, int $reviewerUserId, string $reviewerName, int $rating, string $body): array
    {
        if (!$this->canReview($profileUserId, $reviewerUserId)) {
            throw new ApiException(403, 'A completed selection is required before reviewing this profile.');
        }
        $sql = 'INSERT INTO profile_reviews (profile_user_id,reviewer_user_id,reviewer_name,rating,body,created_at)
                VALUES (:profile,:reviewer,:name,:rating,:body,CURRENT_TIMESTAMP)';
        if (!$this->isMySql()) {
            $sql .= ' RETURNING id';
        }
        $statement = $this->pdo->prepare($sql);
        $statement->execute([
            'profile' => $profileUserId,
            'reviewer' => $reviewerUserId,
            'name' => $reviewerName,
            'rating' => $rating,
            'body' => $body,
        ]);
        $reviewId = $this->isMySql() ? (int) $this->pdo->lastInsertId() : (int) $statement->fetchColumn();

        return [
            'id' => $reviewId,
            'reviewerName' => $reviewerName,
            'rating' => $rating,
            'body' => $body,
            'createdAt' => gmdate('Y-m-d\TH:i:s.000\Z'),
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function search(array $filters): array
    {
        return (new ProfessionalProfileSearch($this->pdo, self::COLUMNS))->search($filters);
    }

    /**
     * @param list<int> $ids
     * @return array{0: list<string>, 1: array<string, int>}
     */
    private static function idParameters(array $ids, string $prefix): array
    {
        $placeholders = [];
        $params = [];
        foreach ($ids as $index => $id) {
            $key = $prefix . '_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $id;
        }

        return [$placeholders, $params];
    }

    private static function nullableText(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private static function time(mixed $value): ?string
    {
        return $value === null
            ? null
            : (new DateTimeImmutable((string) $value))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s.v\\Z');
    }

    private function isMySql(): bool
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    }
}
