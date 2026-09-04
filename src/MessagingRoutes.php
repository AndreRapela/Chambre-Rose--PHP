<?php

declare(strict_types=1);

namespace ChambreRose;

final class MessagingRoutes implements RouteHandler
{
    public function __construct(
        private readonly MessagingRepository $messaging,
        private readonly UserRepository $users,
        private readonly ApiRequestGuard $guard
    ) {
    }

    public function handle(Request $request): ?Response
    {
        $method = $request->method;
        $path = $request->path;

        if ($method === 'GET' && $path === '/api/conversations') {
            $user = $this->guard->currentUser($request);

            return ApiResponder::json(['items' => $this->messaging->list((int) $user['id'])]);
        }
        if ($method === 'POST' && $path === '/api/conversations') {
            return $this->startConversation($request);
        }
        if ($method === 'GET' && preg_match('#^/api/conversations/(\d+)/messages$#', $path, $match)) {
            $user = $this->guard->currentUser($request);

            return ApiResponder::json([
                'items' => $this->messaging->messages((int) $match[1], (int) $user['id']),
            ]);
        }
        if ($method === 'POST' && preg_match('#^/api/conversations/(\d+)/messages$#', $path, $match)) {
            $user = $this->guard->currentUser($request);
            $this->guard->requireJson($request);
            $body = $request->json();

            return ApiResponder::json(
                $this->messaging->send((int) $match[1], (int) $user['id'], (string) ($body['body'] ?? '')),
                201
            );
        }
        if ($method === 'PATCH' && preg_match('#^/api/conversations/(\d+)/read$#', $path, $match)) {
            $user = $this->guard->currentUser($request);
            $this->messaging->read((int) $match[1], (int) $user['id']);

            return ApiResponder::empty();
        }
        if ($method === 'PATCH' && preg_match('#^/api/conversations/(\d+)/archive$#', $path, $match)) {
            return $this->archive($request, (int) $match[1]);
        }
        if ($method === 'DELETE' && preg_match('#^/api/conversations/(\d+)$#', $path, $match)) {
            $user = $this->guard->currentUser($request);
            $this->messaging->deleteForUser((int) $match[1], (int) $user['id']);

            return ApiResponder::empty();
        }
        if ($method === 'POST' && preg_match('#^/api/users/(\d+)/block$#', $path, $match)) {
            return $this->block($request, (int) $match[1]);
        }
        if ($method === 'DELETE' && preg_match('#^/api/users/(\d+)/block$#', $path, $match)) {
            $user = $this->guard->currentUser($request);
            $this->messaging->unblock((int) $user['id'], (int) $match[1]);

            return ApiResponder::empty();
        }
        if ($method === 'POST' && preg_match('#^/api/users/(\d+)/reports$#', $path, $match)) {
            return $this->report($request, (int) $match[1]);
        }

        return null;
    }

    private function startConversation(Request $request): Response
    {
        $user = $this->guard->currentUser($request);
        $this->guard->requireJson($request);
        $recipient = (int) ($request->json()['recipientId'] ?? 0);
        $recipientUser = $recipient > 0 ? $this->users->find($recipient) : null;
        if ($recipientUser === null || $recipientUser['approvalStatus'] !== 'APPROVED') {
            throw new ApiException(404, 'Recipient not found.');
        }

        return ApiResponder::json(
            $this->messaging->conversation((int) $user['id'], $recipient),
            201
        );
    }

    private function archive(Request $request, int $conversationId): Response
    {
        $user = $this->guard->currentUser($request);
        $this->guard->requireJson($request);
        $body = $request->json();
        if (!isset($body['archived']) || !is_bool($body['archived'])) {
            throw new ApiException(400, 'archived must be boolean.');
        }
        $this->messaging->archive($conversationId, (int) $user['id'], $body['archived']);

        return ApiResponder::empty();
    }

    private function block(Request $request, int $target): Response
    {
        $user = $this->guard->currentUser($request);
        if ($this->users->find($target) === null) {
            throw new ApiException(404, 'User not found.');
        }
        $this->messaging->block((int) $user['id'], $target);

        return ApiResponder::empty();
    }

    private function report(Request $request, int $target): Response
    {
        $user = $this->guard->currentUser($request);
        $this->guard->requireJson($request);
        $body = $request->json();
        if ($this->users->find($target) === null) {
            throw new ApiException(404, 'User not found.');
        }

        return ApiResponder::json($this->messaging->report(
            (int) $user['id'],
            $target,
            (string) ($body['reason'] ?? ''),
            isset($body['details']) ? (string) $body['details'] : null
        ), 201);
    }
}
