<?php

declare(strict_types=1);

namespace ChambreRose;

use PDO;

final class SupportRoutes implements RouteHandler
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly MessagingRepository $messaging,
        private readonly ApiRequestGuard $guard,
        private readonly UserNotificationService $notifications,
        private readonly RealtimeEventRepository $realtimeEvents
    ) {
    }

    public function handle(Request $request): ?Response
    {
        if ($request->method !== 'POST' || $request->path !== '/api/support/messages') {
            return null;
        }

        $user = $this->guard->currentUser($request);
        $this->guard->requireJson($request);
        $input = $request->json();
        $subject = trim((string) ($input['subject'] ?? ''));
        $body = trim((string) ($input['body'] ?? ''));
        $category = strtoupper(trim((string) ($input['category'] ?? 'ACCOUNT')));
        $errors = [];
        if ($subject === '' || self::length($subject) > 120) {
            $errors['subject'] = 'must contain between 1 and 120 characters';
        }
        if ($body === '' || self::length($body) > 3500) {
            $errors['body'] = 'must contain between 1 and 3500 characters';
        }
        if (!in_array($category, ['ACCOUNT', 'PROFILE', 'MESSAGES', 'VIP', 'SAFETY', 'OTHER'], true)) {
            $errors['category'] = 'contains an unsupported value';
        }
        if ($errors !== []) {
            throw new ApiException(400, 'Invalid support message.', $errors);
        }

        $userId = (int) $user['id'];
        $adminId = $this->supportAdmin($userId);
        $messageBody = '[Support / ' . $category . '] ' . $subject . "\n\n" . $body;
        $result = $this->realtimeEvents->transaction(function () use (
            $userId,
            $adminId,
            $user,
            $messageBody
        ): array {
            $conversation = $this->messaging->conversation($userId, $adminId);
            $conversationId = (int) $conversation['id'];
            $this->messaging->restoreConversationMember($conversationId, $adminId);
            $message = $this->messaging->send($conversationId, $userId, $messageBody);
            $senderName = trim((string) ($user['firstName'] ?? '') . ' ' . (string) ($user['lastName'] ?? ''));
            $this->notifications->notify(
                $adminId,
                UserNotificationService::DIRECT_MESSAGE,
                'MESSAGE_RECEIVED',
                '/conta/mensagens/' . $conversationId,
                'message:' . (int) $message['id'],
                ['senderName' => $senderName]
            );
            $this->realtimeEvents->publishForUsers(
                [$userId, $adminId],
                RealtimeEventType::MESSAGE_CREATED,
                $conversationId,
                ['messageId' => (int) $message['id']]
            );

            return ['conversationId' => $conversationId, 'messageId' => (int) $message['id']];
        });

        return ApiResponder::json($result, 201);
    }

    private function supportAdmin(int $userId): int
    {
        $statement = $this->pdo->prepare(
            "SELECT support_user.id FROM users support_user "
            . "WHERE support_user.role='ADMIN' AND support_user.approval_status='APPROVED' "
            . 'AND support_user.id<>:requester_id '
            . 'AND NOT EXISTS (SELECT 1 FROM user_exclusions exclusion WHERE '
            . '(exclusion.owner_id=:owner_id AND exclusion.excluded_user_id=support_user.id) OR '
            . '(exclusion.owner_id=support_user.id AND exclusion.excluded_user_id=:excluded_id)) '
            . 'AND NOT EXISTS (SELECT 1 FROM blocked_users blocked WHERE '
            . '(blocked.blocker_id=:blocker_id AND blocked.blocked_id=support_user.id) OR '
            . '(blocked.blocker_id=support_user.id AND blocked.blocked_id=:blocked_id)) '
            . 'ORDER BY support_user.id ASC LIMIT 1'
        );
        $statement->execute([
            'requester_id' => $userId,
            'owner_id' => $userId,
            'excluded_id' => $userId,
            'blocker_id' => $userId,
            'blocked_id' => $userId,
        ]);
        $adminId = (int) $statement->fetchColumn();
        if ($adminId <= 0) {
            throw new ApiException(503, 'Support is temporarily unavailable.');
        }

        return $adminId;
    }

    private static function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }
}
