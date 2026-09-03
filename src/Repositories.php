<?php

declare(strict_types=1);

namespace ChambreRose;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class UserRepository
{
    private const SELECT_COLUMNS = <<<'SQL'
        users.id, email, password_hash, first_name, last_name, phone, address, city, country,
        postal_code, role, approval_status, approval_reason, review_deadline, approved_at, locale,
        vip_active, vip_since, vip_until, created_at, updated_at
        SQL;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function normalizeLegacyRoles(): void
    {
        $this->pdo->exec("UPDATE users SET role = 'VISITOR', updated_at = CURRENT_TIMESTAMP WHERE role = 'USER'");
    }

    public function findByEmail(string $email): ?array
    {
        $statement = $this->pdo->prepare('SELECT ' . self::SELECT_COLUMNS . ' FROM users WHERE email = :email');
        $statement->execute(['email' => $email]);
        $row = $statement->fetch();

        return is_array($row) ? self::mapUser($row) : null;
    }

    public function find(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT ' . self::SELECT_COLUMNS . ' FROM users WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return is_array($row) ? self::mapUser($row) : null;
    }

    public function emailExists(string $email, ?int $exceptId = null): bool
    {
        $sql = 'SELECT 1 FROM users WHERE email = :email';
        $params = ['email' => $email];
        if ($exceptId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $exceptId;
        }
        $statement = $this->pdo->prepare($sql . ' LIMIT 1');
        $statement->execute($params);

        return (bool) $statement->fetchColumn();
    }

    /** @param array<string, string> $user */
    public function create(
        array $user,
        string $passwordHash,
        string $role = 'VISITOR',
        string $approvalStatus = 'APPROVED',
        string $locale = 'fr'
    ): array {
        $role = strtoupper($role) === 'USER' ? 'VISITOR' : strtoupper($role);
        $approvalStatus = strtoupper($approvalStatus);
        $sql = <<<'SQL'
            INSERT INTO users (
              email, password_hash, first_name, last_name, phone, address, city, country,
              postal_code, role, approval_status, review_deadline, approved_at, locale,
              vip_active, created_at, updated_at
            ) VALUES (
              :email, :password_hash, :first_name, :last_name, :phone, :address, :city,
              :country, :postal_code, :role, :approval_status, :review_deadline, :approved_at,
              :locale, FALSE, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
            )
            SQL;
        if (!$this->isMySql()) {
            $sql .= ' RETURNING id';
        }
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $statement = $this->pdo->prepare($sql);
            $statement->execute([
                'email' => strtolower(trim($user['email'])),
                'password_hash' => $passwordHash,
                'first_name' => trim($user['firstName']),
                'last_name' => trim($user['lastName']),
                'phone' => trim($user['phone']),
                'address' => trim($user['address']),
                'city' => trim($user['city']),
                'country' => trim($user['country']),
                'postal_code' => trim($user['postalCode']),
                'role' => $role,
                'approval_status' => $approvalStatus,
                'review_deadline' => $approvalStatus === 'PENDING'
                    ? gmdate('Y-m-d H:i:s', time() + 86400)
                    : null,
                'approved_at' => $approvalStatus === 'APPROVED' ? gmdate('Y-m-d H:i:s') : null,
                'locale' => in_array(strtolower($locale), ['fr', 'en', 'pt'], true) ? strtolower($locale) : 'fr',
            ]);
            $id = $this->isMySql() ? (int) $this->pdo->lastInsertId() : (int) $statement->fetchColumn();

            $created = $this->find($id) ?? throw new ApiException(500, 'Unable to create user.');
            if ($ownsTransaction) {
                $this->pdo->commit();
            }

            return $created;
        } catch (\Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    public function delete(int $id): void
    {
        $statement = $this->pdo->prepare('DELETE FROM users WHERE id = :id');
        $statement->execute(['id' => $id]);
    }

    /** @param array<string, string> $user */
    public function updateProfile(int $id, array $user): array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
            UPDATE users SET email = :email, first_name = :first_name, last_name = :last_name,
              phone = :phone, address = :address, city = :city, country = :country,
              postal_code = :postal_code, updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
            SQL);
        $statement->execute([
            'id' => $id,
            'email' => strtolower(trim($user['email'])),
            'first_name' => trim($user['firstName']),
            'last_name' => trim($user['lastName']),
            'phone' => trim($user['phone']),
            'address' => trim($user['address']),
            'city' => trim($user['city']),
            'country' => trim($user['country']),
            'postal_code' => trim($user['postalCode']),
        ]);

        return $this->find($id) ?? throw new ApiException(404, 'User profile not found.');
    }

    /** @return list<array<string, mixed>> */
    public function list(
        ?string $email,
        ?string $name,
        string $sort,
        ?string $approvalStatus = null,
        ?string $role = null
    ): array {
        $where = [];
        $params = [];
        if ($email !== null) {
            $where[] = 'LOWER(email) LIKE LOWER(:email)';
            $params['email'] = '%' . $email . '%';
        }
        if ($name !== null) {
            $where[] = "LOWER(concat(first_name, ' ', last_name)) LIKE LOWER(:name)";
            $params['name'] = '%' . $name . '%';
        }
        if ($approvalStatus !== null) {
            $where[] = 'approval_status = :approval_status';
            $params['approval_status'] = $approvalStatus;
        }
        if ($role !== null) {
            $where[] = 'role = :role';
            $params['role'] = $role;
        }
        $direction = strtolower($sort) === 'oldest' ? 'ASC' : 'DESC';
        $sql = 'SELECT ' . self::SELECT_COLUMNS . ' FROM users';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY created_at ' . $direction . ', id ' . $direction;
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return array_map([self::class, 'mapUser'], $statement->fetchAll());
    }

    public function countAdmins(): int
    {
        return (int)$this->pdo->query("SELECT COUNT(*) FROM users WHERE role = 'ADMIN'")->fetchColumn();
    }

    public function setVip(int $id, bool $active): array
    {
        $sql = $active
            ? 'UPDATE users SET vip_active = TRUE, vip_since = COALESCE(vip_since, CURRENT_TIMESTAMP), vip_until = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
            : 'UPDATE users SET vip_active = FALSE, vip_until = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = :id';
        $statement = $this->pdo->prepare($sql);
        $statement->execute(['id' => $id]);
        if ($statement->rowCount() === 0) {
            throw new ApiException(404, 'User not found.');
        }

        return $this->find($id) ?? throw new ApiException(404, 'User not found.');
    }

    public function setApproval(int $id, string $status, ?string $reason): array
    {
        $status = strtoupper($status);
        if (!in_array($status, ['APPROVED', 'REJECTED'], true)) {
            throw new ApiException(400, 'Approval status must be APPROVED or REJECTED.');
        }
        $statement = $this->pdo->prepare(<<<'SQL'
            UPDATE users SET approval_status = :status, approval_reason = :reason,
              approved_at = CASE WHEN :approval_check = 'APPROVED' THEN CURRENT_TIMESTAMP ELSE NULL END,
              updated_at = CURRENT_TIMESTAMP
            WHERE id = :id AND role IN ('ESCORT', 'STORE')
            SQL);
        $statement->execute([
            'id' => $id,
            'status' => $status,
            'approval_check' => $status,
            'reason' => $reason,
        ]);
        if ($statement->rowCount() === 0) {
            $existing = $this->find($id);
            if ($existing === null) {
                throw new ApiException(404, 'User not found.');
            }
            if (!in_array($existing['role'], ['ESCORT', 'STORE'], true)) {
                throw new ApiException(409, 'Only professional accounts require approval.');
            }
        }

        return $this->find($id) ?? throw new ApiException(404, 'User not found.');
    }

    public function setRole(int $id, string $role): array
    {
        $role = strtoupper($role);
        if ($role === 'USER') {
            $role = 'VISITOR';
        }
        if (!in_array($role, ['ADMIN', 'VISITOR', 'ESCORT', 'STORE'], true)) {
            throw new ApiException(400, 'Role must be ADMIN, VISITOR, ESCORT or STORE.');
        }
        $existing = $this->find($id) ?? throw new ApiException(404, 'User not found.');
        if ($existing['role'] === 'ADMIN' && $role !== 'ADMIN' && $this->countAdmins() <= 1) {
            throw new ApiException(409, 'The last administrator cannot be demoted.');
        }
        $approval = in_array($role, ['ADMIN', 'VISITOR'], true)
            ? 'APPROVED'
            : ($existing['role'] === $role ? $existing['approvalStatus'] : 'PENDING');
        $reviewDeadline = $approval === 'PENDING' ? gmdate('Y-m-d H:i:s', time() + 86400) : null;
        $statement = $this->pdo->prepare(<<<'SQL'
            UPDATE users SET role = :role, approval_status = :approval,
              review_deadline = :review_deadline,
              updated_at = CURRENT_TIMESTAMP WHERE id = :id
            SQL);
        $statement->execute([
            'id' => $id,
            'role' => $role,
            'approval' => $approval,
            'review_deadline' => $reviewDeadline,
        ]);

        return $this->find($id) ?? throw new ApiException(404, 'User not found.');
    }

    public function updatePassword(int $id, string $passwordHash): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE users SET password_hash = :hash, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
        );
        $statement->execute(['id' => $id, 'hash' => $passwordHash]);
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private static function mapUser(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'email' => (string) $row['email'],
            'passwordHash' => (string) $row['password_hash'],
            'firstName' => (string) $row['first_name'],
            'lastName' => (string) $row['last_name'],
            'phone' => (string) $row['phone'],
            'address' => (string) $row['address'],
            'city' => (string) $row['city'],
            'country' => (string) $row['country'],
            'postalCode' => (string) $row['postal_code'],
            'role' => (string) $row['role'],
            'approvalStatus' => (string) $row['approval_status'],
            'approvalReason' => $row['approval_reason'],
            'reviewDeadline' => self::time($row['review_deadline']),
            'approvedAt' => self::time($row['approved_at']),
            'locale' => (string) $row['locale'],
            'vipActive' => self::toBool($row['vip_active']),
            'vipSince' => self::time($row['vip_since']),
            'vipUntil' => self::time($row['vip_until']),
            'createdAt' => self::time($row['created_at']),
            'updatedAt' => self::time($row['updated_at']),
        ];
    }

    private static function toBool(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 't', 'true'], true);
    }

    private static function time(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (new DateTimeImmutable((string) $value))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.v\Z');
    }

    private function isMySql(): bool
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    }
}
