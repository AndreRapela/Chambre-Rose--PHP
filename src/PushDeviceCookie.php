<?php

declare(strict_types=1);

namespace ChambreRose;

use RuntimeException;

final class PushDeviceCookie
{
    private const NAME = 'chambre_rose_push_device';
    private const MAX_AGE = 31536000;

    public function issue(string $endpoint): string
    {
        $endpointHash = hash('sha256', $endpoint);
        $value = $endpointHash . '.' . $this->signature($endpointHash);

        return $this->header($value, self::MAX_AGE, time() + self::MAX_AGE);
    }

    public function endpointHash(Request $request): ?string
    {
        $value = $request->cookie(self::NAME);
        if ($value === null || preg_match('/^([a-f0-9]{64})\.([a-f0-9]{64})$/', $value, $match) !== 1) {
            return null;
        }

        return hash_equals($this->signature($match[1]), $match[2]) ? $match[1] : null;
    }

    public function clear(): string
    {
        return $this->header('', 0, 1);
    }

    private function signature(string $endpointHash): string
    {
        $secret = Config::first(['PUSH_DEVICE_COOKIE_SECRET', 'JWT_SECRET']);
        if ($secret === null || strlen($secret) < 32) {
            throw new RuntimeException('PUSH_DEVICE_COOKIE_SECRET or JWT_SECRET must contain at least 32 characters.');
        }

        return hash_hmac('sha256', 'push-device:' . $endpointHash, $secret);
    }

    private function header(string $value, int $maxAge, int $expiresAt): string
    {
        $parts = [
            self::NAME . '=' . $value,
            'Path=/api',
            'Max-Age=' . $maxAge,
            'Expires=' . gmdate('D, d M Y H:i:s', $expiresAt) . ' GMT',
            'HttpOnly',
            'SameSite=Strict',
        ];
        $secureByDefault = strtolower(Config::get('APP_ENV', 'production') ?? 'production') === 'production';
        if (Config::bool('AUTH_COOKIE_SECURE', $secureByDefault)) {
            $parts[] = 'Secure';
        }

        return implode('; ', $parts);
    }
}
