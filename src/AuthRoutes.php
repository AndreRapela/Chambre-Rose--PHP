<?php

declare(strict_types=1);

namespace ChambreRose;

final class AuthRoutes implements RouteHandler
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly ApiRequestGuard $guard,
        private readonly AuthSessionCookie $sessionCookie,
        private readonly AuthRateLimiter $rateLimiter,
        private readonly UserNotificationService $notifications
    ) {
    }

    public function handle(Request $request): ?Response
    {
        $method = $request->method;
        $path = $request->path;

        if ($method === 'POST' && $path === '/api/auth/login') {
            $this->guard->requireJson($request);
            $input = $request->json();
            $email = strtolower(trim((string) ($input['email'] ?? '')));
            $this->rateLimiter->assertLoginAllowed($email, $request->clientIp);

            try {
                $response = $this->auth->login($input);
            } catch (ApiException $exception) {
                if ($exception->status === 401) {
                    $this->rateLimiter->registerLoginFailure($email, $request->clientIp);
                }

                throw $exception;
            }

            $this->rateLimiter->clearLoginFailures($email);

            return $this->authenticatedResponse($response);
        }
        if ($method === 'POST' && $path === '/api/auth/register') {
            if ($request->contentType() === 'application/json') {
                return $this->authenticatedResponse($this->auth->register($request->json()), 201, true);
            }
            $this->guard->requireMultipart($request);
            $form = $request->multipart();

            return $this->authenticatedResponse(
                $this->auth->register($form['fields'], $form['files']['establishmentPhoto'] ?? null),
                201,
                true
            );
        }
        if ($method === 'POST' && $path === '/api/auth/forgot-password') {
            $this->guard->requireJson($request);
            $input = $request->json();
            $email = strtolower(trim((string) ($input['email'] ?? '')));
            $this->rateLimiter->consumeRecoveryRequest($email, $request->clientIp);

            return ApiResponder::json($this->auth->forgotPassword($input), 202);
        }
        if ($method === 'POST' && $path === '/api/auth/reset-password') {
            $this->guard->requireJson($request);
            $input = $request->json();
            $token = is_string($input['token'] ?? null) ? trim($input['token']) : '';
            $this->rateLimiter->consumePasswordResetAttempt($token, $request->clientIp);
            $response = $this->auth->resetPassword($input);
            $this->rateLimiter->clearPasswordResetAttempts($token);

            return ApiResponder::json($response);
        }
        if ($method === 'POST' && $path === '/api/auth/logout') {
            $user = $this->guard->optionalCurrentUser($request);
            if ($user !== null) {
                $this->notifications->revokeDevices((int) $user['id']);
            }

            return ApiResponder::empty()->withHeaders(['Set-Cookie' => $this->sessionCookie->clear()]);
        }
        if ($method === 'GET' && $path === '/api/auth/me') {
            $identity = $this->guard->authenticate($request);

            return ApiResponder::json($this->auth->profile($identity['sub']));
        }
        if ($method === 'PUT' && $path === '/api/auth/me') {
            $identity = $this->guard->authenticate($request);
            $this->guard->requireJson($request);

            return $this->authenticatedResponse($this->auth->updateProfile($identity['sub'], $request->json()));
        }

        return null;
    }

    /** @param array<string,mixed> $payload */
    private function authenticatedResponse(array $payload, int $status = 200, bool $clearWithoutToken = false): Response
    {
        $token = is_string($payload['token'] ?? null) ? $payload['token'] : null;
        unset($payload['token']);
        $response = ApiResponder::json($payload, $status);

        if ($token !== null) {
            return $response->withHeaders(['Set-Cookie' => $this->sessionCookie->issue($token)]);
        }

        return $clearWithoutToken
            ? $response->withHeaders(['Set-Cookie' => $this->sessionCookie->clear()])
            : $response;
    }
}
