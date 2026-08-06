<?php

declare(strict_types=1);

namespace ChambreRose;

use JsonException;

final class Jwt
{
    private readonly string $secret;
    private readonly int $expirationMinutes;

    public function __construct()
    {
        $this->secret = Config::get('JWT_SECRET', '') ?? '';
        if (strlen($this->secret) < 32) {
            throw new \RuntimeException('JWT_SECRET must be at least 32 bytes.');
        }
        $this->expirationMinutes = max(1, Config::int('JWT_EXPIRATION_MINUTES', 180));
    }

    public function generate(string $email, string $role): string
    {
        $now = time();
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $payload = ['sub' => $email, 'role' => strtoupper($role), 'iat' => $now,
            'exp' => $now + ($this->expirationMinutes * 60)];
        $unsigned = self::encodeJson($header) . '.' . self::encodeJson($payload);
        return $unsigned . '.' . self::base64UrlEncode(hash_hmac('sha256', $unsigned, $this->secret, true));
    }

    /** @return array{sub: string, role: string, iat?: int, exp: int} */
    public function verify(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new ApiException(401, 'Invalid or expired authentication token.');
        }
        try {
            $header = json_decode(self::base64UrlDecode($parts[0]), true, 16, JSON_THROW_ON_ERROR);
            $payload = json_decode(self::base64UrlDecode($parts[1]), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ApiException(401, 'Invalid or expired authentication token.');
        }
        if (!is_array($header) || ($header['alg'] ?? null) !== 'HS256' || !is_array($payload)) {
            throw new ApiException(401, 'Invalid or expired authentication token.');
        }
        $expected = hash_hmac('sha256', $parts[0] . '.' . $parts[1], $this->secret, true);
        if (!hash_equals($expected, self::base64UrlDecode($parts[2]))) {
            throw new ApiException(401, 'Invalid or expired authentication token.');
        }
        $subject = $payload['sub'] ?? null;
        $role = $payload['role'] ?? null;
        $expiration = $payload['exp'] ?? null;
        if (!is_string($subject) || $subject === '' || !is_string($role) || !is_numeric($expiration)
            || (int) $expiration <= time()) {
            throw new ApiException(401, 'Invalid or expired authentication token.');
        }
        return ['sub' => $subject, 'role' => strtoupper($role),
            'iat' => isset($payload['iat']) ? (int) $payload['iat'] : 0, 'exp' => (int) $expiration];
    }

    /** @param array<string, mixed> $value */
    private static function encodeJson(array $value): string
    {
        return self::base64UrlEncode(json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): string
    {
        if ($value === '' || preg_match('/[^A-Za-z0-9_-]/', $value)) {
            throw new ApiException(401, 'Invalid or expired authentication token.');
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false) {
            throw new ApiException(401, 'Invalid or expired authentication token.');
        }
        return $decoded;
    }
}

