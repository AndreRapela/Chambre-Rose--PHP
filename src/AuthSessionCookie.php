<?php

declare(strict_types=1);

namespace ChambreRose;

final class AuthSessionCookie
{
    private const NAME = 'chambre_rose_session';

    public function __construct(private readonly Jwt $jwt)
    {
    }

    public function token(Request $request): ?string
    {
        $token = $request->cookie(self::NAME);

        return is_string($token) && $token !== '' ? $token : null;
    }

    public function hasToken(Request $request): bool
    {
        return $this->token($request) !== null;
    }

    public function issue(string $token): string
    {
        $maxAge = $this->jwt->expirationSeconds();

        return $this->header(rawurlencode($token), $maxAge, time() + $maxAge);
    }

    public function clear(): string
    {
        return $this->header('', 0, 1);
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
