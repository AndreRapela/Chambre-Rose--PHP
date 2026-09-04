<?php

declare(strict_types=1);

namespace ChambreRose;

final class AuthRoutes implements RouteHandler
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly ApiRequestGuard $guard
    ) {
    }

    public function handle(Request $request): ?Response
    {
        $method = $request->method;
        $path = $request->path;

        if ($method === 'POST' && $path === '/api/auth/login') {
            $this->guard->requireJson($request);

            return ApiResponder::json($this->auth->login($request->json()));
        }
        if ($method === 'POST' && $path === '/api/auth/register') {
            if ($request->contentType() === 'application/json') {
                return ApiResponder::json($this->auth->register($request->json()), 201);
            }
            $this->guard->requireMultipart($request);
            $form = $request->multipart();

            return ApiResponder::json(
                $this->auth->register($form['fields'], $form['files']['establishmentPhoto'] ?? null),
                201
            );
        }
        if ($method === 'POST' && $path === '/api/auth/forgot-password') {
            $this->guard->requireJson($request);

            return ApiResponder::json($this->auth->forgotPassword($request->json()), 202);
        }
        if ($method === 'POST' && $path === '/api/auth/reset-password') {
            $this->guard->requireJson($request);

            return ApiResponder::json($this->auth->resetPassword($request->json()));
        }
        if ($method === 'GET' && $path === '/api/auth/me') {
            $identity = $this->guard->authenticate($request);

            return ApiResponder::json($this->auth->profile($identity['sub']));
        }
        if ($method === 'PUT' && $path === '/api/auth/me') {
            $identity = $this->guard->authenticate($request);
            $this->guard->requireJson($request);

            return ApiResponder::json($this->auth->updateProfile($identity['sub'], $request->json()));
        }

        return null;
    }
}
