<?php

declare(strict_types=1);

namespace ChambreRose;

use PDO;

final class ProfessionalProfileSearch
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $columns
    ) {
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
        $city = LocationNormalizer::key($filters['city'] ?? '', 80);
        if ($city !== '') {
            $where[] = '(' . $this->normalizedLocationSql("COALESCE(p.location_city,u.city,'')") . ' LIKE :city_name'
                . ' OR ' . $this->normalizedLocationSql("COALESCE(p.location_region,'')") . ' LIKE :city_region'
                . ' OR ' . $this->normalizedLocationSql("COALESCE(p.location,'')") . ' LIKE :city_location)';
            $params['city_name'] = '%' . $city . '%';
            $params['city_region'] = '%' . $city . '%';
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

        [$proximityOrder, $proximityParams] = $this->proximityOrder($filters);
        $contentOrder = strtolower((string) ($filters['sort'] ?? '')) === 'newest'
            ? 'p.created_at DESC, p.user_id DESC'
            : 'u.vip_active DESC, p.updated_at DESC, p.user_id DESC';
        $order = $proximityOrder === '' ? $contentOrder : $proximityOrder . ', ' . $contentOrder;
        $sql = 'SELECT ' . $this->columns . ' FROM professional_profiles p JOIN users u ON u.id=p.user_id WHERE '
            . $whereSql . ' ORDER BY ' . $order . ' LIMIT :limit OFFSET :offset';
        $statement = $this->pdo->prepare($sql);
        foreach ($params + $proximityParams as $key => $value) {
            $statement->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $statement->bindValue(':limit', $pageSize, PDO::PARAM_INT);
        $statement->bindValue(':offset', ($page - 1) * $pageSize, PDO::PARAM_INT);
        $statement->execute();

        return [
            'items' => array_map(
                static fn (array $row): array => ProfessionalProfileMapper::listingView(ProfessionalProfileMapper::map($row)),
                $statement->fetchAll()
            ),
            'page' => $page,
            'pageSize' => $pageSize,
            'total' => $total,
            'totalPages' => $total === 0 ? 0 : (int) ceil($total / $pageSize),
        ];
    }

    /**
     * Location is selected by the visitor, never inferred from live GPS. The
     * ranking keeps every result available while placing the same city first,
     * followed by the same state/region and then the same country.
     *
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<string, string>}
     */
    private function proximityOrder(array $filters): array
    {
        $city = LocationNormalizer::key($filters['nearCity'] ?? '', 80);
        $region = LocationNormalizer::key($filters['nearRegion'] ?? '', 100);
        $countryKeys = LocationNormalizer::countryKeys($filters['nearCountry'] ?? '');
        $profileCity = "COALESCE(NULLIF(p.location_city,''),NULLIF(u.city,''),'')";
        $profileCountry = "COALESCE(NULLIF(p.location_country,''),NULLIF(u.country,''),'')";
        $cases = [];
        $params = [];
        $rank = 0;

        if ($city !== '') {
            $condition = $this->normalizedLocationSql($profileCity) . ' = :near_city_order';
            $params['near_city_order'] = $city;
            if ($countryKeys !== []) {
                $condition .= ' AND ' . $this->locationInCondition(
                    $profileCountry,
                    $countryKeys,
                    'near_city_country_order',
                    $params
                );
            }
            $cases[] = "WHEN {$condition} THEN {$rank}";
            $rank++;
        }
        if ($region !== '') {
            $condition = $this->normalizedLocationSql("COALESCE(p.location_region,'')") . ' = :near_region_order';
            $params['near_region_order'] = $region;
            if ($countryKeys !== []) {
                $condition .= ' AND ' . $this->locationInCondition(
                    $profileCountry,
                    $countryKeys,
                    'near_region_country_order',
                    $params
                );
            }
            $cases[] = "WHEN {$condition} THEN {$rank}";
            $rank++;
        }
        if ($countryKeys !== []) {
            $condition = $this->locationInCondition($profileCountry, $countryKeys, 'near_country_order', $params);
            $cases[] = "WHEN {$condition} THEN {$rank}";
            $rank++;
        }

        return $cases === []
            ? ['', []]
            : ['CASE ' . implode(' ', $cases) . " ELSE {$rank} END ASC", $params];
    }

    /**
     * @param list<string> $values
     * @param array<string, string> $params
     */
    private function locationInCondition(string $expression, array $values, string $prefix, array &$params): string
    {
        $placeholders = [];
        foreach ($values as $index => $value) {
            $key = $prefix . '_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $value;
        }

        return $this->normalizedLocationSql($expression) . ' IN (' . implode(', ', $placeholders) . ')';
    }

    private function normalizedLocationSql(string $expression): string
    {
        $sql = "LOWER(TRIM({$expression}))";
        foreach ([
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
            'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ñ' => 'n',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'ÿ' => 'y',
            'æ' => 'ae', 'œ' => 'oe', 'ß' => 'ss', 'ł' => 'l',
            '-' => ' ', '_' => ' ', '.' => ' ', ',' => ' ', "'" => '', '’' => '',
        ] as $from => $to) {
            $from = str_replace("'", "''", $from);
            $to = str_replace("'", "''", $to);
            $sql = "REPLACE({$sql},'{$from}','{$to}')";
        }

        return "TRIM(REPLACE(REPLACE({$sql},'  ',' '),'  ',' '))";
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

    private function isMySql(): bool
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    }
}
