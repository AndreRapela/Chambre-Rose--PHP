<?php

declare(strict_types=1);

namespace ChambreRose;

final class MessagingRoutes implements RouteHandler
{
    public function __construct(
        private readonly MessagingRepository $messaging,
        private readonly UserRepository $users,
        private readonly ApiRequestGuard $guard,
        private readonly UserNotificationService $notifications,
        private readonly RealtimeEventRepository $realtimeEvents
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

            return ApiResponder::json($this->messaging->messages(
                (int) $match[1],
                (int) $user['id'],
                self::queryId($request, 'after'),
                self::queryId($request, 'before'),
                self::queryLimit($request)
            ));
        }
        if ($method === 'POST' && preg_match('#^/api/conversations/(\d+)/messages$#', $path, $match)) {
            $user = $this->guard->currentUser($request);
            $this->guard->requireJson($request);
            $body = $request->json();
            $conversationId = (int) $match[1];
            $userId = (int) $user['id'];
            $message = $this->realtimeEvents->transaction(function () use (
                $conversationId,
                $userId,
                $user,
                $body
            ): array {
                $conversation = $this->messaging->get($conversationId, $userId);
                $recipientId = (int) $conversation['otherUser']['id'];
                $message = $this->messaging->send($conversationId, $userId, (string) ($body['body'] ?? ''));
                $senderName = trim((string) ($user['firstName'] ?? '') . ' ' . (string) ($user['lastName'] ?? ''));
                $this->notifications->notify(
                    $recipientId,
                    UserNotificationService::DIRECT_MESSAGE,
                    'MESSAGE_RECEIVED',
                    '/mensagens/' . $conversationId,
                    'message:' . (int) $message['id'],
                    ['senderName' => $senderName]
                );
                $this->realtimeEvents->publishForUsers(
                    [$userId, $recipientId],
                    RealtimeEventType::MESSAGE_CREATED,
                    $conversationId,
                    ['messageId' => (int) $message['id']]
                );

                return $message;
            });

            return ApiResponder::json($message, 201);
        }
        if ($method === 'PATCH' && preg_match('#^/api/conversations/(\d+)/read$#', $path, $match)) {
            $user = $this->guard->currentUser($request);
            $conversationId = (int) $match[1];
            $userId = (int) $user['id'];
            $this->realtimeEvents->transaction(function () use ($conversationId, $userId): void {
                $conversation = $this->messaging->get($conversationId, $userId);
                $this->messaging->read($conversationId, $userId);
                $this->notifications->markConversationRead($userId, $conversationId);
                $this->realtimeEvents->publishForUsers(
                    [$userId, (int) $conversation['otherUser']['id']],
                    RealtimeEventType::CONVERSATION_READ,
                    $conversationId
                );
            });

            return ApiResponder::empty();
        }
        if ($method === 'PATCH' && preg_match('#^/api/conversations/(\d+)/archive$#', $path, $match)) {
            return $this->archive($request, (int) $match[1]);
        }
        if ($method === 'DELETE' && preg_match('#^/api/conversations/(\d+)$#', $path, $match)) {
            $user = $this->guard->currentUser($request);
            $conversationId = (int) $match[1];
            $userId = (int) $user['id'];
            $this->realtimeEvents->transaction(function () use ($conversationId, $userId): void {
                $this->messaging->deleteForUser($conversationId, $userId);
                $this->realtimeEvents->publish(
                    $userId,
                    RealtimeEventType::INBOX_UPDATED,
                    $conversationId
                );
            });

            return ApiResponder::empty();
        }
        if ($method === 'POST' && preg_match('#^/api/users/(\d+)/block$#', $path, $match)) {
            return $this->block($request, (int) $match[1]);
        }
        if ($method === 'DELETE' && preg_match('#^/api/users/(\d+)/block$#', $path, $match)) {
            $user = $this->guard->currentUser($request);
            $userId = (int) $user['id'];
            $targetId = (int) $match[1];
            $this->realtimeEvents->transaction(function () use ($userId, $targetId): void {
                $this->messaging->unblock($userId, $targetId);
                $this->realtimeEvents->publishForUsers(
                    [$userId, $targetId],
                    RealtimeEventType::INBOX_UPDATED
                );
            });

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

        $userId = (int) $user['id'];
        $conversation = $this->realtimeEvents->transaction(function () use ($userId, $recipient): array {
            $conversation = $this->messaging->conversation($userId, $recipient);
            $this->realtimeEvents->publishForUsers(
                [$userId, $recipient],
                RealtimeEventType::INBOX_UPDATED,
                (int) $conversation['id']
            );

            return $conversation;
        });

        return ApiResponder::json($conversation, 201);
    }

    private function archive(Request $request, int $conversationId): Response
    {
        $user = $this->guard->currentUser($request);
        $this->guard->requireJson($request);
        $body = $request->json();
        if (!isset($body['archived']) || !is_bool($body['archived'])) {
            throw new ApiException(400, 'archived must be boolean.');
        }
        $userId = (int) $user['id'];
        $this->realtimeEvents->transaction(function () use ($conversationId, $userId, $body): void {
            $this->messaging->archive($conversationId, $userId, $body['archived']);
            $this->realtimeEvents->publish(
                $userId,
                RealtimeEventType::INBOX_UPDATED,
                $conversationId
            );
        });

        return ApiResponder::empty();
    }

    private function block(Request $request, int $target): Response
    {
        $user = $this->guard->currentUser($request);
        if ($this->users->find($target) === null) {
            throw new ApiException(404, 'User not found.');
        }
        $userId = (int) $user['id'];
        $this->realtimeEvents->transaction(function () use ($userId, $target): void {
            $this->messaging->block($userId, $target);
            $this->realtimeEvents->publishForUsers(
                [$userId, $target],
                RealtimeEventType::INBOX_UPDATED
            );
        });

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

    private static function queryId(Request $request, string $name): ?int
    {
        $value = $request->query[$name] ?? null;

        return is_scalar($value) && filter_var($value, FILTER_VALIDATE_INT) !== false && (int) $value > 0
            ? (int) $value
            : null;
    }

    private static function queryLimit(Request $request): int
    {
        $value = $request->query['limit'] ?? null;

        return is_scalar($value) && filter_var($value, FILTER_VALIDATE_INT) !== false
            ? min(100, max(1, (int) $value))
            : 50;
    }
}
