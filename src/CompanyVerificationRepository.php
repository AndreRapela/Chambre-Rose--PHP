<?php

declare(strict_types=1);

namespace ChambreRose;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class CompanyVerificationRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param array{name:string,contentType:string,data:string} $registration */
    public function create(int $userId, string $companyNumber, array $registration): void
    {
        $statement = $this->pdo->prepare(<<<'SQL'
            INSERT INTO user_company_verifications (
              user_id,company_number,registration_name,registration_content_type,registration_data,created_at
            ) VALUES (
              :user_id,:company_number,:registration_name,:registration_content_type,:registration_data,CURRENT_TIMESTAMP
            )
            SQL);
        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $statement->bindValue(':company_number', $companyNumber);
        $statement->bindValue(':registration_name', $registration['name']);
        $statement->bindValue(':registration_content_type', $registration['contentType']);
        $statement->bindValue(':registration_data', $registration['data'], PDO::PARAM_LOB);
        $statement->execute();
    }

    /** @return array<string,mixed>|null */
    public function find(int $userId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT user_id,company_number,registration_name,registration_content_type,registration_data,created_at '
            . 'FROM user_company_verifications WHERE user_id=:id'
        );
        $statement->execute(['id' => $userId]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @param list<int> $userIds
     * @return array<int,array{companyNumber:string,submittedAt:string}>
     */
    public function summariesByUserIds(array $userIds): array
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn (int $id): bool => $id > 0)));
        if ($userIds === []) {
            return [];
        }
        $placeholders = [];
        $params = [];
        foreach ($userIds as $index => $id) {
            $key = 'company_user_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $id;
        }
        $statement = $this->pdo->prepare(
            'SELECT user_id,company_number,created_at FROM user_company_verifications '
            . 'WHERE user_id IN (' . implode(',', $placeholders) . ')'
        );
        $statement->execute($params);
        $summaries = [];
        foreach ($statement->fetchAll() as $row) {
            $summaries[(int) $row['user_id']] = [
                'companyNumber' => (string) $row['company_number'],
                'submittedAt' => (new DateTimeImmutable((string) $row['created_at']))
                    ->setTimezone(new DateTimeZone('UTC'))
                    ->format('Y-m-d\TH:i:s.v\Z'),
            ];
        }

        return $summaries;
    }
}
