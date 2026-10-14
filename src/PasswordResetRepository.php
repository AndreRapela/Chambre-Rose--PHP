<?php

declare(strict_types=1);

namespace ChambreRose;

use PDO;

final class PasswordResetRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{challenge: string, code: string} */
    public function issueCode(int $userId): array
    {
        $challenge = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $statement = $this->pdo->prepare(
            'UPDATE password_reset_tokens SET used_at=CURRENT_TIMESTAMP WHERE user_id=:id AND used_at IS NULL'
        );
        $statement->execute(['id' => $userId]);

        $expires = gmdate('Y-m-d H:i:s', time() + 3600);
        $statement = $this->pdo->prepare(
            'INSERT INTO password_reset_tokens '
            . '(user_id,token_hash,verification_code_hash,verification_attempts,expires_at,created_at) '
            . 'VALUES (:uid,:hash,:code_hash,0,:expires,CURRENT_TIMESTAMP)'
        );
        $statement->execute([
            'uid' => $userId,
            'hash' => hash('sha256', $challenge),
            'code_hash' => hash('sha256', $code),
            'expires' => $expires,
        ]);

        return ['challenge' => $challenge, 'code' => $code];
    }

    public function anonymousChallenge(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public function issue(int $userId): string
    {
        $raw = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $hash = hash('sha256', $raw);
        $statement = $this->pdo->prepare(
            'UPDATE password_reset_tokens SET used_at=CURRENT_TIMESTAMP WHERE user_id=:id AND used_at IS NULL'
        );
        $statement->execute(['id' => $userId]);

        $expires = gmdate('Y-m-d H:i:s', time() + 3600);
        $statement = $this->pdo->prepare(
            'INSERT INTO password_reset_tokens '
            . '(user_id,token_hash,expires_at,created_at) '
            . 'VALUES (:uid,:hash,:expires,CURRENT_TIMESTAMP)'
        );
        $statement->execute([
            'uid' => $userId,
            'hash' => $hash,
            'expires' => $expires,
        ]);

        return $raw;
    }

    public function verifyCode(string $raw, string $code): bool
    {
        if ($raw === '' || !preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        $this->pdo->beginTransaction();

        try {
            $sql = 'SELECT id,verification_code_hash,verification_attempts,verified_at '
                . 'FROM password_reset_tokens '
                . 'WHERE token_hash=:hash AND used_at IS NULL AND expires_at>CURRENT_TIMESTAMP';
            if ($this->isLockable()) {
                $sql .= ' FOR UPDATE';
            }
            $statement = $this->pdo->prepare($sql);
            $statement->execute(['hash' => hash('sha256', $raw)]);
            $row = $statement->fetch();
            if (!is_array($row) || !is_string($row['verification_code_hash'] ?? null)) {
                $this->pdo->rollBack();

                return false;
            }
            if ($row['verified_at'] !== null) {
                $this->pdo->commit();

                // Keep retries idempotent for the same code, but never allow
                // an arbitrary code after the token has already been verified.
                return hash_equals($row['verification_code_hash'], hash('sha256', $code));
            }

            $attempts = (int) ($row['verification_attempts'] ?? 0);
            if ($attempts >= 5) {
                $this->pdo->rollBack();
                throw new ApiException(429, 'Too many verification attempts. Request a new code.');
            }

            $valid = hash_equals($row['verification_code_hash'], hash('sha256', $code));
            $statement = $this->pdo->prepare(
                'UPDATE password_reset_tokens SET verification_attempts=:attempts'
                . ($valid ? ',verified_at=CURRENT_TIMESTAMP' : '')
                . ' WHERE id=:id'
            );
            $statement->execute(['id' => $row['id'], 'attempts' => $attempts + 1]);
            $this->pdo->commit();

            return $valid;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    /** @param null|callable(int): void $onConsume */
    public function consume(string $raw, ?callable $onConsume = null): ?int
    {
        $hash = hash('sha256', $raw);
        $this->pdo->beginTransaction();

        try {
            $sql = 'SELECT id,user_id FROM password_reset_tokens WHERE token_hash=:hash '
                . 'AND used_at IS NULL AND expires_at>CURRENT_TIMESTAMP '
                . 'AND (verification_code_hash IS NULL OR verified_at IS NOT NULL)';
            if ($this->isLockable()) {
                $sql .= ' FOR UPDATE';
            }
            $s = $this->pdo->prepare($sql);
            $s->execute(['hash' => $hash]);
            $r = $s->fetch();
            if (!is_array($r)) {
                $this->pdo->rollBack();

                return null;
            }
            $statement = $this->pdo->prepare(
                'UPDATE password_reset_tokens SET used_at=CURRENT_TIMESTAMP WHERE id=:id'
            );
            $statement->execute(['id' => $r['id']]);
            $userId = (int) $r['user_id'];
            if ($onConsume !== null) {
                $onConsume($userId);
            }
            $this->pdo->commit();

            return $userId;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    private function isLockable(): bool
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite';
    }
}
