<?php

declare(strict_types=1);

namespace ChambreRose;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class IdentityVerificationRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array{name:string,contentType:string,data:string} $document
     * @param array{name:string,contentType:string,data:string} $selfie
     */
    public function create(int $userId, string $documentType, array $document, array $selfie): void
    {
        $statement = $this->pdo->prepare(<<<'SQL'
            INSERT INTO user_identity_verifications (
              user_id,document_type,document_name,document_content_type,document_data,
              selfie_name,selfie_content_type,selfie_data,created_at
            ) VALUES (
              :user_id,:document_type,:document_name,:document_content_type,:document_data,
              :selfie_name,:selfie_content_type,:selfie_data,CURRENT_TIMESTAMP
            )
            SQL);
        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $statement->bindValue(':document_type', $documentType);
        $statement->bindValue(':document_name', $document['name']);
        $statement->bindValue(':document_content_type', $document['contentType']);
        $statement->bindValue(':document_data', $document['data'], PDO::PARAM_LOB);
        $statement->bindValue(':selfie_name', $selfie['name']);
        $statement->bindValue(':selfie_content_type', $selfie['contentType']);
        $statement->bindValue(':selfie_data', $selfie['data'], PDO::PARAM_LOB);
        $statement->execute();
    }

    /** @return array<string,mixed>|null */
    public function find(int $userId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT user_id,document_type,document_name,document_content_type,document_data,'
            . 'selfie_name,selfie_content_type,selfie_data,created_at '
            . 'FROM user_identity_verifications WHERE user_id=:id'
        );
        $statement->execute(['id' => $userId]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @param list<int> $userIds
     * @return array<int,array{documentType:string,submittedAt:string}>
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
            $key = 'identity_user_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $id;
        }
        $statement = $this->pdo->prepare(
            'SELECT user_id,document_type,created_at FROM user_identity_verifications '
            . 'WHERE user_id IN (' . implode(',', $placeholders) . ')'
        );
        $statement->execute($params);
        $summaries = [];
        foreach ($statement->fetchAll() as $row) {
            $summaries[(int) $row['user_id']] = [
                'documentType' => (string) $row['document_type'],
                'submittedAt' => (new DateTimeImmutable((string) $row['created_at']))
                    ->setTimezone(new DateTimeZone('UTC'))
                    ->format('Y-m-d\TH:i:s.v\Z'),
            ];
        }

        return $summaries;
    }
}
