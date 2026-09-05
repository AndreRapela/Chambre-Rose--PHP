<?php

declare(strict_types=1);

namespace ChambreRose;

use PDO;
use PDOException;

final class AuthRateLimiter
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function assertLoginAllowed(string $email, string $ip): void
    {
        $this->assertAllowed($this->loginAccountPolicy(), $email);
        $this->assertAllowed($this->loginIpPolicy(), $ip);
    }

    public function registerLoginFailure(string $email, string $ip): void
    {
        $rateLimit = null;
        foreach ([[$this->loginAccountPolicy(), $email], [$this->loginIpPolicy(), $ip]] as [$policy, $identifier]) {
            try {
                $this->hit($policy, $identifier, true);
            } catch (ApiException $exception) {
                if ($exception->status !== 429) {
                    throw $exception;
                }
                $rateLimit ??= $exception;
            }
        }

        if ($rateLimit !== null) {
            throw $rateLimit;
        }
    }

    public function clearLoginFailures(string $email): void
    {
        $this->clear($this->loginAccountPolicy()['scope'], $email);
    }

    public function consumeRecoveryRequest(string $email, string $ip): void
    {
        $this->consume([
            [$this->recoveryAccountPolicy(), $email],
            [$this->recoveryIpPolicy(), $ip],
        ]);
    }

    public function consumePasswordResetAttempt(string $token, string $ip): void
    {
        $this->consume([
            [$this->resetTokenPolicy(), $token],
            [$this->resetIpPolicy(), $ip],
        ]);
    }

    public function clearPasswordResetAttempts(string $token): void
    {
        $this->clear($this->resetTokenPolicy()['scope'], $token);
    }

    /** @param list<array{0: array{scope:string,max:int,window:int,block:int},1:string}> $buckets */
    private function consume(array $buckets): void
    {
        foreach ($buckets as [$policy, $identifier]) {
            $this->assertAllowed($policy, $identifier);
        }
        foreach ($buckets as [$policy, $identifier]) {
            $this->hit($policy, $identifier, false);
        }
    }

    /** @param array{scope:string,max:int,window:int,block:int} $policy */
    private function assertAllowed(array $policy, string $identifier): void
    {
        $statement = $this->pdo->prepare(
            'SELECT attempts, window_started_at, blocked_until FROM auth_rate_limits WHERE bucket_hash = :hash'
        );
        $statement->execute(['hash' => $this->hash($policy['scope'], $identifier)]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return;
        }

        $retryAfter = $this->retryAfter($row, $policy, time());
        if ($retryAfter > 0) {
            throw $this->rateLimited($retryAfter);
        }
    }

    /** @param array{scope:string,max:int,window:int,block:int} $policy */
    private function hit(array $policy, string $identifier, bool $blockAtLimit, bool $canRetry = true): void
    {
        $this->cleanupOccasionally();
        $hash = $this->hash($policy['scope'], $identifier);
        $now = time();
        $nowSql = gmdate('Y-m-d H:i:s', $now);
        $this->pdo->beginTransaction();

        try {
            $statement = $this->pdo->prepare(
                'SELECT attempts, window_started_at, blocked_until '
                . 'FROM auth_rate_limits WHERE bucket_hash = :hash FOR UPDATE'
            );
            $statement->execute(['hash' => $hash]);
            $row = $statement->fetch();

            if (is_array($row)) {
                $retryAfter = $this->retryAfter($row, $policy, $now);
                if ($retryAfter > 0) {
                    $this->pdo->rollBack();
                    throw $this->rateLimited($retryAfter);
                }
            }

            $windowExpired = !is_array($row)
                || $this->timestamp($row['window_started_at'] ?? null) + $policy['window'] <= $now;
            $attempts = $windowExpired ? 1 : ((int) $row['attempts'] + 1);
            $windowStartedAt = $windowExpired ? $nowSql : (string) $row['window_started_at'];
            $blockedUntil = $blockAtLimit && $attempts >= $policy['max']
                ? gmdate('Y-m-d H:i:s', $now + $policy['block'])
                : null;

            if (is_array($row)) {
                $statement = $this->pdo->prepare(
                    'UPDATE auth_rate_limits SET attempts=:attempts, window_started_at=:window, '
                    . 'blocked_until=:blocked, updated_at=:updated WHERE bucket_hash=:hash'
                );
            } else {
                $statement = $this->pdo->prepare(
                    'INSERT INTO auth_rate_limits '
                    . '(bucket_hash, scope, attempts, window_started_at, blocked_until, updated_at) '
                    . 'VALUES (:hash, :scope, :attempts, :window, :blocked, :updated)'
                );
            }
            $params = [
                'hash' => $hash,
                'attempts' => $attempts,
                'window' => $windowStartedAt,
                'blocked' => $blockedUntil,
                'updated' => $nowSql,
            ];
            if (!is_array($row)) {
                $params['scope'] = $policy['scope'];
            }
            $statement->execute($params);
            $this->pdo->commit();

            if ($blockedUntil !== null) {
                throw $this->rateLimited($policy['block']);
            }
        } catch (PDOException $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($canRetry && in_array($exception->getCode(), ['23000', '23505'], true)) {
                $this->hit($policy, $identifier, $blockAtLimit, false);

                return;
            }

            throw $exception;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    private function clear(string $scope, string $identifier): void
    {
        $statement = $this->pdo->prepare('DELETE FROM auth_rate_limits WHERE bucket_hash = :hash');
        $statement->execute(['hash' => $this->hash($scope, $identifier)]);
    }

    /**
     * @param array<string, mixed> $row
     * @param array{scope: string, max: int, window: int, block: int} $policy
     */
    private function retryAfter(array $row, array $policy, int $now): int
    {
        $blockedUntil = $this->timestamp($row['blocked_until'] ?? null);
        if ($blockedUntil > $now) {
            return $blockedUntil - $now;
        }

        $windowStartedAt = $this->timestamp($row['window_started_at'] ?? null);
        if ((int) ($row['attempts'] ?? 0) >= $policy['max'] && $windowStartedAt + $policy['window'] > $now) {
            return ($windowStartedAt + $policy['window']) - $now;
        }

        return 0;
    }

    private function timestamp(mixed $value): int
    {
        if (!is_string($value) || $value === '') {
            return 0;
        }

        return strtotime($value . (str_contains($value, '+') ? '' : ' UTC')) ?: 0;
    }

    private function hash(string $scope, string $identifier): string
    {
        $secret = Config::get('RATE_LIMIT_SECRET', Config::get('JWT_SECRET', '')) ?? '';
        if (strlen($secret) < 32) {
            throw new \RuntimeException('RATE_LIMIT_SECRET or JWT_SECRET must be at least 32 bytes.');
        }

        return hash_hmac('sha256', $scope . "\0" . strtolower(trim($identifier)), $secret);
    }

    private function cleanupOccasionally(): void
    {
        if (random_int(1, 100) !== 1) {
            return;
        }

        $now = time();
        $statement = $this->pdo->prepare(
            'DELETE FROM auth_rate_limits WHERE updated_at < :cutoff '
            . 'AND (blocked_until IS NULL OR blocked_until < :now)'
        );
        $statement->execute([
            'cutoff' => gmdate('Y-m-d H:i:s', $now - 172800),
            'now' => gmdate('Y-m-d H:i:s', $now),
        ]);
    }

    private function rateLimited(int $retryAfter): ApiException
    {
        $retryAfter = max(1, $retryAfter);

        return new ApiException(
            429,
            'Too many attempts. Please try again later.',
            ['retryAfterSeconds' => (string) $retryAfter],
            ['Retry-After' => (string) $retryAfter]
        );
    }

    /** @return array{scope:string,max:int,window:int,block:int} */
    private function loginAccountPolicy(): array
    {
        return $this->policy('login-account', 'AUTH_LOGIN_ACCOUNT_ATTEMPTS', 5, 900, 900);
    }

    /** @return array{scope:string,max:int,window:int,block:int} */
    private function loginIpPolicy(): array
    {
        return $this->policy('login-ip', 'AUTH_LOGIN_IP_ATTEMPTS', 30, 900, 900);
    }

    /** @return array{scope:string,max:int,window:int,block:int} */
    private function recoveryAccountPolicy(): array
    {
        return $this->policy('recovery-account', 'AUTH_RECOVERY_ACCOUNT_ATTEMPTS', 3, 3600, 3600);
    }

    /** @return array{scope:string,max:int,window:int,block:int} */
    private function recoveryIpPolicy(): array
    {
        return $this->policy('recovery-ip', 'AUTH_RECOVERY_IP_ATTEMPTS', 20, 3600, 3600);
    }

    /** @return array{scope:string,max:int,window:int,block:int} */
    private function resetTokenPolicy(): array
    {
        return $this->policy('reset-token', 'AUTH_RESET_TOKEN_ATTEMPTS', 5, 900, 900);
    }

    /** @return array{scope:string,max:int,window:int,block:int} */
    private function resetIpPolicy(): array
    {
        return $this->policy('reset-ip', 'AUTH_RESET_IP_ATTEMPTS', 20, 900, 900);
    }

    /** @return array{scope:string,max:int,window:int,block:int} */
    private function policy(string $scope, string $attemptsEnv, int $attempts, int $window, int $block): array
    {
        return [
            'scope' => $scope,
            'max' => max(1, Config::int($attemptsEnv, $attempts)),
            'window' => $window,
            'block' => $block,
        ];
    }
}
