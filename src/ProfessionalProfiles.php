<?php

declare(strict_types=1);

namespace ChambreRose;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class ProfessionalProfileRepository
{
    private const COLUMNS = <<<'SQL'
        p.user_id, p.profile_type, p.display_name, p.birth_date, p.gender, p.location, p.bio,
        p.languages, p.height_cm, p.hair, p.eyes, p.services, p.availability, p.website,
        p.price_from, p.price_to, p.business_name, p.legal_name, p.segment,
        p.business_address, p.business_hours, p.weight_kg, p.bust_cm, p.waist_cm, p.hips_cm,
        p.origin, p.interests, p.contact_options, p.contact_email, p.response_time,
        p.purchase_count, p.views_count, p.verified,
        (SELECT COUNT(*) FROM profile_reviews r WHERE r.profile_user_id=p.user_id) AS review_count,
        (SELECT AVG(r.rating) FROM profile_reviews r WHERE r.profile_user_id=p.user_id) AS average_rating,
        p.created_at, p.updated_at,
        u.city, u.role, u.vip_active, u.approval_status
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
        $params = $this->params($userId, $type, $profile);
        if ($this->isMySql()) {
            $sql = <<<'SQL'
                INSERT INTO professional_profiles (
                  user_id, profile_type, display_name, birth_date, gender, location, bio, languages,
                  height_cm, hair, eyes, services, availability, website, price_from, price_to,
                  business_name, legal_name, segment, business_address, business_hours,
                  weight_kg, bust_cm, waist_cm, hips_cm, origin, interests, contact_options,
                  contact_email, response_time,
                  created_at, updated_at
                ) VALUES (
                  :user_id, :profile_type, :display_name, :birth_date, :gender, :location, :bio, :languages,
                  :height_cm, :hair, :eyes, :services, :availability, :website, :price_from, :price_to,
                  :business_name, :legal_name, :segment, :business_address, :business_hours,
                  :weight_kg, :bust_cm, :waist_cm, :hips_cm, :origin, :interests, :contact_options,
                  :contact_email, :response_time,
                  CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3)
                ) ON DUPLICATE KEY UPDATE
                  profile_type=VALUES(profile_type), display_name=VALUES(display_name),
                  birth_date=VALUES(birth_date), gender=VALUES(gender),
                  location=VALUES(location), bio=VALUES(bio), languages=VALUES(languages),
                  height_cm=VALUES(height_cm), hair=VALUES(hair), eyes=VALUES(eyes), services=VALUES(services),
                  availability=VALUES(availability), website=VALUES(website), price_from=VALUES(price_from),
                  price_to=VALUES(price_to), business_name=VALUES(business_name), legal_name=VALUES(legal_name),
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
                  user_id, profile_type, display_name, birth_date, gender, location, bio, languages,
                  height_cm, hair, eyes, services, availability, website, price_from, price_to,
                  business_name, legal_name, segment, business_address, business_hours,
                  weight_kg, bust_cm, waist_cm, hips_cm, origin, interests, contact_options,
                  contact_email, response_time,
                  created_at, updated_at
                ) VALUES (
                  :user_id, :profile_type, :display_name, :birth_date, :gender, :location, :bio, :languages,
                  :height_cm, :hair, :eyes, :services, :availability, :website, :price_from, :price_to,
                  :business_name, :legal_name, :segment, :business_address, :business_hours,
                  :weight_kg, :bust_cm, :waist_cm, :hips_cm, :origin, :interests, :contact_options,
                  :contact_email, :response_time,
                  CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                ) ON CONFLICT (user_id) DO UPDATE SET
                  profile_type=EXCLUDED.profile_type, display_name=EXCLUDED.display_name,
                  birth_date=EXCLUDED.birth_date, gender=EXCLUDED.gender,
                  location=EXCLUDED.location, bio=EXCLUDED.bio, languages=EXCLUDED.languages,
                  height_cm=EXCLUDED.height_cm, hair=EXCLUDED.hair, eyes=EXCLUDED.eyes,
                  services=EXCLUDED.services, availability=EXCLUDED.availability, website=EXCLUDED.website,
                  price_from=EXCLUDED.price_from, price_to=EXCLUDED.price_to,
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

        $profile = self::map($row);

        return $publicOnly ? self::publicView($profile) : $profile;
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
        if (empty($filters['city']) && !empty($filters['province'])) {
            $filters['city'] = $filters['province'];
        }
        $where = ["u.approval_status='APPROVED'", "u.role IN ('ESCORT','STORE')"];
        $params = [];
        foreach (['type' => 'p.profile_type', 'gender' => 'p.gender'] as $key => $column) {
            $value = self::limitedText($filters[$key] ?? '', 40);
            if ($value !== '') {
                $where[] = "LOWER({$column}) = LOWER(:{$key})";
                $params[$key] = $value;
            }
        }
        $city = self::limitedText($filters['city'] ?? '', 80);
        if ($city !== '') {
            $where[] = "(LOWER(u.city) LIKE LOWER(:city_name) OR LOWER(COALESCE(p.location,'')) LIKE LOWER(:city_location))";
            $params['city_name'] = '%' . $city . '%';
            $params['city_location'] = '%' . $city . '%';
        }
        $q = self::limitedText($filters['q'] ?? '', 120);
        if ($q !== '') {
            $where[] = '(LOWER(p.display_name) LIKE LOWER(:q_name) OR LOWER(COALESCE(p.bio,\'\')) LIKE LOWER(:q_bio) OR LOWER(COALESCE(p.segment,\'\')) LIKE LOWER(:q_segment))';
            $params['q_name'] = '%' . $q . '%';
            $params['q_bio'] = '%' . $q . '%';
            $params['q_segment'] = '%' . $q . '%';
        }
        $servicesInput = $filters['services'] ?? '';
        $services = is_array($servicesInput)
            ? $servicesInput
            : explode(',', (string) $servicesInput);
        $services = array_map(
            static fn ($value): string => self::limitedText($value, 80),
            array_slice($services, 0, 10)
        );
        $services = array_values(array_unique(array_filter(
            $services,
            static fn (string $service): bool => $service !== ''
        )));
        foreach ($services as $index => $service) {
            $key = 'service' . $index;
            $where[] = "LOWER(COALESCE(p.services,'')) LIKE LOWER(:{$key})";
            $params[$key] = '%"' . $service . '"%';
        }
        foreach (['minPrice' => ['p.price_to', '>='], 'maxPrice' => ['p.price_from', '<=']] as $key => [$column, $operator]) {
            if (isset($filters[$key]) && is_numeric($filters[$key])) {
                $where[] = "COALESCE({$column}, p.price_from, p.price_to) {$operator} :{$key}";
                $params[$key] = (float) $filters[$key];
            }
        }
        $ageExpression = $this->isMySql()
            ? 'TIMESTAMPDIFF(YEAR, p.birth_date, CURRENT_DATE)'
            : "DATE_PART('year', AGE(CURRENT_DATE, p.birth_date))";
        foreach (['minAge' => '>=', 'maxAge' => '<='] as $key => $operator) {
            if (isset($filters[$key]) && filter_var($filters[$key], FILTER_VALIDATE_INT) !== false) {
                $where[] = "p.birth_date IS NOT NULL AND {$ageExpression} {$operator} :{$key}";
                $params[$key] = (int) $filters[$key];
            }
        }
        foreach (['minHeight' => '>=', 'maxHeight' => '<='] as $key => $operator) {
            if (isset($filters[$key]) && filter_var($filters[$key], FILTER_VALIDATE_INT) !== false) {
                $where[] = "p.height_cm IS NOT NULL AND p.height_cm {$operator} :{$key}";
                $params[$key] = max(100, min(250, (int) $filters[$key]));
            }
        }
        if (filter_var($filters['verified'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $where[] = 'p.verified = TRUE';
        }

        $page = max(1, (int) ($filters['page'] ?? 1));
        $pageSize = min(50, max(1, (int) ($filters['pageSize'] ?? 20)));
        $whereSql = implode(' AND ', $where);
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM professional_profiles p JOIN users u ON u.id=p.user_id WHERE ' . $whereSql);
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $sql = 'SELECT ' . self::COLUMNS . ' FROM professional_profiles p JOIN users u ON u.id=p.user_id WHERE '
            . $whereSql . ' ORDER BY u.vip_active DESC, p.updated_at DESC, p.user_id DESC LIMIT :limit OFFSET :offset';
        $statement = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $statement->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $statement->bindValue(':limit', $pageSize, PDO::PARAM_INT);
        $statement->bindValue(':offset', ($page - 1) * $pageSize, PDO::PARAM_INT);
        $statement->execute();

        return [
            'items' => array_map(
                static fn (array $row): array => self::publicView(self::map($row)),
                $statement->fetchAll()
            ),
            'page' => $page,
            'pageSize' => $pageSize,
            'total' => $total,
            'totalPages' => $total === 0 ? 0 : (int) ceil($total / $pageSize),
        ];
    }

    /**
     * @param array<string, mixed> $profile
     * @return array<string, mixed>
     */
    private function params(int $userId, string $type, array $profile): array
    {
        return [
            'user_id' => $userId, 'profile_type' => $type,
            'display_name' => $profile['displayName'], 'birth_date' => $profile['birthDate'] ?? null,
            'gender' => $profile['gender'] ?? null, 'location' => $profile['location'] ?? null,
            'bio' => $profile['bio'] ?? null, 'languages' => self::encodeList($profile['languages'] ?? []),
            'height_cm' => $profile['heightCm'] ?? null, 'hair' => $profile['hair'] ?? null,
            'eyes' => $profile['eyes'] ?? null, 'services' => self::encodeList($profile['services'] ?? []),
            'availability' => $profile['availability'] ?? null, 'website' => $profile['website'] ?? null,
            'price_from' => $profile['priceFrom'] ?? null, 'price_to' => $profile['priceTo'] ?? null,
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
    private static function map(array $row): array
    {
        $birthDate = $row['birth_date'] === null ? null : substr((string) $row['birth_date'], 0, 10);
        $age = $birthDate === null ? null : (new DateTimeImmutable($birthDate))->diff(new DateTimeImmutable('today'))->y;
        $id = (int) $row['user_id'];

        return [
            'id' => $id, 'userId' => $id, 'ownerId' => $id, 'type' => (string) $row['profile_type'],
            'displayName' => (string) $row['display_name'], 'city' => (string) $row['city'],
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
    private static function publicView(array $profile): array
    {
        $profile['hasContactEmail'] = isset($profile['contactEmail']) && $profile['contactEmail'] !== '';
        unset($profile['birthDate'], $profile['legalName'], $profile['businessAddress'], $profile['contactEmail']);

        return $profile;
    }

    private static function bool(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 't', 'true'], true);
    }

    private static function limitedText(mixed $value, int $maxLength): string
    {
        if (!is_scalar($value)) {
            return '';
        }
        $value = trim((string) $value);

        return function_exists('mb_substr')
            ? mb_substr($value, 0, $maxLength, 'UTF-8')
            : substr($value, 0, $maxLength);
    }
    private static function time(mixed $value): ?string
    {
        return $value === null ? null : (new DateTimeImmutable((string) $value))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    }
    private function isMySql(): bool
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    }
}
