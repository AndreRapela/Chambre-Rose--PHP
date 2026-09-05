<?php

declare(strict_types=1);

namespace ChambreRose;

final class ApiRequestGuard
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly Jwt $jwt,
        private readonly AuthSessionCookie $sessionCookie
    ) {
    }

    /** @return array{sub: string, role: string, iat?: int, exp: int} */
    public function authenticate(Request $request): array
    {
        $token = $this->bearerToken($request) ?? $this->sessionCookie->token($request);
        if ($token === null) {
            throw new ApiException(401, 'Authentication is required.');
        }
        $identity = $this->jwt->verify($token);
        $user = $this->users->findByEmail(strtolower($identity['sub']));
        if ($user === null) {
            throw new ApiException(401, 'Authentication account no longer exists.');
        }
        if ($user['approvalStatus'] !== 'APPROVED') {
            throw new ApiException(403, 'This account is not approved.', ['approvalStatus' => $user['approvalStatus']]);
        }
        $identity['role'] = $user['role'];

        return $identity;
    }

    /** @return array{sub: string, role: string, iat?: int, exp: int} */
    public function requireAdmin(Request $request): array
    {
        $identity = $this->authenticate($request);
        if ($identity['role'] !== 'ADMIN') {
            throw new ApiException(403, 'Administrator access is required.');
        }

        return $identity;
    }

    /** @return array<string,mixed> */
    public function currentUser(Request $request): array
    {
        $identity = $this->authenticate($request);
        $user = $this->users->findByEmail(strtolower($identity['sub']));
        if ($user === null) {
            throw new ApiException(401, 'Authentication account no longer exists.');
        }

        return $user;
    }

    /** @return array<string,mixed>|null */
    public function optionalCurrentUser(Request $request): ?array
    {
        if ($this->bearerToken($request) === null && !$this->sessionCookie->hasToken($request)) {
            return null;
        }

        try {
            return $this->currentUser($request);
        } catch (ApiException) {
            return null;
        }
    }

    public function requireJson(Request $request): void
    {
        if ($request->contentType() !== 'application/json') {
            throw new ApiException(415, 'Content-Type must be application/json.');
        }
    }

    public function requireMultipart(Request $request): void
    {
        if ($request->contentType() !== 'multipart/form-data') {
            throw new ApiException(415, 'Content-Type must be multipart/form-data.');
        }
    }

    private function bearerToken(Request $request): ?string
    {
        $authorization = $request->header('authorization');
        if ($authorization === null || !preg_match('/^Bearer\s+(.+)$/i', $authorization, $match)) {
            return null;
        }

        $token = trim($match[1]);

        return $token !== '' ? $token : null;
    }
}
