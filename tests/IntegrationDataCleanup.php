<?php

declare(strict_types=1);

namespace ChambreRose\Tests;

use PDO;
use Throwable;

final class IntegrationDataCleanup
{
    private const EMAIL_PREFIXES = [
        'visitor',
        'profile',
        'weak-password',
        'future-profile',
        'unsafe-website',
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $runId
    ) {
    }

    public function profileName(): string
    {
        return 'Profile Integration ' . $this->runId;
    }

    public function productName(): string
    {
        return 'Integration Product ' . $this->runId;
    }

    public function productDescription(): string
    {
        return 'Integration product for run ' . $this->runId . '.';
    }

    public function storeName(): string
    {
        return 'Integration Store ' . $this->runId;
    }

    public function email(string $prefix): string
    {
        if (!in_array($prefix, self::EMAIL_PREFIXES, true)) {
            throw new \InvalidArgumentException('Unknown integration email prefix.');
        }

        return $prefix . '-' . $this->runId . '@example.com';
    }

    public function cleanup(): void
    {
        $emails = array_map(fn (string $prefix): string => $this->email($prefix), self::EMAIL_PREFIXES);
        $this->deleteFixtures(
            $emails,
            'name = :product_name AND description = :product_description AND store_name = :store_name',
            [
                'product_name' => $this->productName(),
                'product_description' => $this->productDescription(),
                'store_name' => $this->storeName(),
            ]
        );
    }

    public function remainingCount(): int
    {
        $emails = array_map(fn (string $prefix): string => $this->email($prefix), self::EMAIL_PREFIXES);
        $emailPlaceholders = $this->placeholders('remaining_email_', $emails);
        $params = $this->placeholderValues('remaining_email_', $emails) + [
            'product_name' => $this->productName(),
            'product_description' => $this->productDescription(),
            'store_name' => $this->storeName(),
        ];
        $statement = $this->pdo->prepare(
            'SELECT ('
            . 'SELECT COUNT(*) FROM users WHERE email IN (' . implode(', ', $emailPlaceholders) . ')'
            . ') + ('
            . 'SELECT COUNT(*) FROM products WHERE name = :product_name '
            . 'AND description = :product_description AND store_name = :store_name'
            . ')'
        );
        $statement->execute($params);

        return (int) $statement->fetchColumn();
    }

    public static function cleanupLegacyFixtures(PDO $pdo): void
    {
        (new self($pdo, 'legacy'))->deleteFixtures(
            [],
            'name = :product_name AND description = :product_description AND store_name = :store_name',
            [
                'product_name' => 'Integration Product',
                'product_description' => 'Integration product for purchase-count validation.',
                'store_name' => 'Integration Store',
            ],
            true
        );
    }

    /**
     * @param list<string> $emails
     * @param array<string, string> $productParams
     */
    private function deleteFixtures(
        array $emails,
        string $productWhere,
        array $productParams,
        bool $includeLegacyUsers = false
    ): void {
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $userWhere = $this->userWhere($emails, $includeLegacyUsers);
            $userParams = $this->placeholderValues('fixture_email_', $emails);

            $this->execute(
                'DELETE FROM marketplace_orders WHERE product_id IN '
                . '(SELECT id FROM products WHERE ' . $productWhere . ')',
                $productParams
            );
            if ($userWhere !== '') {
                $this->execute(
                    'DELETE FROM marketplace_orders WHERE buyer_user_id IN '
                    . '(SELECT id FROM users WHERE ' . $userWhere . ')',
                    $userParams
                );
                $this->execute(
                    'DELETE FROM marketplace_orders WHERE profile_user_id IN '
                    . '(SELECT id FROM users WHERE ' . $userWhere . ')',
                    $userParams
                );
                $this->execute(
                    'DELETE FROM profile_reviews WHERE profile_user_id IN '
                    . '(SELECT id FROM users WHERE ' . $userWhere . ')',
                    $userParams
                );
                $this->execute(
                    'DELETE FROM profile_reviews WHERE reviewer_user_id IN '
                    . '(SELECT id FROM users WHERE ' . $userWhere . ')',
                    $userParams
                );
            }
            $this->execute('DELETE FROM products WHERE ' . $productWhere, $productParams);
            if ($emails !== []) {
                $this->execute('DELETE FROM email_outbox WHERE recipient IN ('
                    . implode(', ', $this->placeholders('fixture_email_', $emails)) . ')', $userParams);
            }
            if ($includeLegacyUsers) {
                $this->execute(
                    "DELETE FROM email_outbox WHERE recipient LIKE 'visitor-%@example.com' "
                    . "OR recipient LIKE 'profile-%@example.com'"
                );
            }
            if ($userWhere !== '') {
                $this->execute('DELETE FROM users WHERE ' . $userWhere, $userParams);
            }

            if ($ownsTransaction) {
                $this->pdo->commit();
            }
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    /** @param list<string> $emails */
    private function userWhere(array $emails, bool $includeLegacyUsers): string
    {
        $conditions = [];
        if ($emails !== []) {
            $conditions[] = 'email IN (' . implode(', ', $this->placeholders('fixture_email_', $emails)) . ')';
        }
        if ($includeLegacyUsers) {
            $conditions[] = "(last_name = 'Integration' AND ("
                . "(first_name = 'Visitor' AND email LIKE 'visitor-%@example.com') OR "
                . "(first_name = 'Profile' AND email LIKE 'profile-%@example.com')"
                . '))';
        }

        return implode(' OR ', $conditions);
    }

    /** @param list<string> $values @return list<string> */
    private function placeholders(string $prefix, array $values): array
    {
        return array_map(static fn (int $index): string => ':' . $prefix . $index, array_keys($values));
    }

    /** @param list<string> $values @return array<string, string> */
    private function placeholderValues(string $prefix, array $values): array
    {
        $params = [];
        foreach ($values as $index => $value) {
            $params[$prefix . $index] = $value;
        }

        return $params;
    }

    /** @param array<string, string> $params */
    private function execute(string $sql, array $params = []): void
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
    }
}
