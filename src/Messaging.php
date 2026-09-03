<?php

declare(strict_types=1);

namespace ChambreRose;

use PDO;

final class MessagingRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function conversation(int $userId, int $otherId): array
    {
        if ($userId === $otherId) {
            throw new ApiException(400, 'A conversation requires another user.');
        }
        if ($this->isBlocked($userId, $otherId)) {
            throw new ApiException(403, 'Messaging is unavailable between these users.');
        }
        $this->assertCanMessage($userId, $otherId);
        [$one, $two] = $userId < $otherId ? [$userId, $otherId] : [$otherId, $userId];
        if ($this->isMySql()) {
            $statement = $this->pdo->prepare(<<<'SQL'
                INSERT INTO conversations (
                  participant_one_id, participant_two_id, created_at, updated_at
                ) VALUES (:one, :two, CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3))
                ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)
                SQL);
            $statement->execute(['one' => $one, 'two' => $two]);
            $id = (int) $this->pdo->lastInsertId();
        } else {
            $statement = $this->pdo->prepare(<<<'SQL'
                INSERT INTO conversations (
                  participant_one_id, participant_two_id, created_at, updated_at
                ) VALUES (:one, :two, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                ON CONFLICT (participant_one_id, participant_two_id)
                DO UPDATE SET updated_at=conversations.updated_at
                RETURNING id
                SQL);
            $statement->execute(['one' => $one, 'two' => $two]);
            $id = (int) $statement->fetchColumn();
        }

        $memberCount = $this->conversationMemberCount($id);
        $this->addConversationMember($id, $userId);
        if ($memberCount === 0) {
            $this->addConversationMember($id, $otherId);
        }

        return $this->get($id, $userId);
    }
    /** @return list<array<string,mixed>> */
    public function list(int $userId): array
    {
        $s = $this->pdo->prepare('SELECT c.id FROM conversations c JOIN conversation_members cm ON cm.conversation_id=c.id WHERE cm.user_id=:uid ORDER BY c.updated_at DESC');
        $s->execute(['uid' => $userId]);

        return array_map(fn ($id) => $this->get((int) $id, $userId), $s->fetchAll(PDO::FETCH_COLUMN));
    }

    public function get(int $id, int $userId): array
    {
        $s = $this->pdo->prepare('SELECT c.*,cm.user_id AS member_user_id,cm.archived_at,cm.last_read_at FROM conversations c LEFT JOIN conversation_members cm ON cm.conversation_id=c.id AND cm.user_id=:uid WHERE c.id=:id');
        $s->execute(['uid' => $userId,'id' => $id]);
        $r = $s->fetch();
        if (!is_array($r)
            || $r['member_user_id'] === null
            || ((int)$r['participant_one_id'] !== $userId && (int)$r['participant_two_id'] !== $userId)
        ) {
            throw new ApiException(404, 'Conversation not found.');
        }
        $other = (int)$r['participant_one_id'] === $userId ? (int)$r['participant_two_id'] : (int)$r['participant_one_id'];
        $u = $this->pdo->prepare("SELECT u.id,u.first_name,u.last_name,u.role,u.approval_status,COALESCE(p.display_name,CONCAT(u.first_name,' ',u.last_name)) display_name FROM users u LEFT JOIN professional_profiles p ON p.user_id=u.id WHERE u.id=:id");
        $u->execute(['id' => $other]);
        $otherUser = $u->fetch();
        $last = $this->pdo->prepare('SELECT id,conversation_id,sender_id,body,created_at FROM messages WHERE conversation_id=:id ORDER BY id DESC LIMIT 1');
        $last->execute(['id' => $id]);
        $lastRow = $last->fetch();
        $unread = $this->pdo->prepare('SELECT COUNT(*) FROM messages WHERE conversation_id=:id AND sender_id<>:uid AND (:read_null IS NULL OR created_at>:read_after)');
        $unread->execute([
            'id' => $id,
            'uid' => $userId,
            'read_null' => $r['last_read_at'],
            'read_after' => $r['last_read_at'],
        ]);
        $blockedByMe = false;
        $avatarUrl = null;
        if ($otherUser) {
            $b = $this->pdo->prepare('SELECT 1 FROM blocked_users WHERE blocker_id=:uid AND blocked_id=:oid');
            $b->execute(['uid' => $userId,'oid' => $other]);
            $blockedByMe = (bool)$b->fetchColumn();
            $avatar = $this->pdo->prepare("SELECT id FROM profile_media WHERE user_id=:uid AND media_type='PHOTO' ORDER BY position,id LIMIT 1");
            $avatar->execute(['uid' => $other]);
            $avatarId = $avatar->fetchColumn();
            if ($avatarId !== false) {
                $avatarUrl = '/api/profiles/' . $other . '/media/' . (int)$avatarId;
            }
        }

        return [
            'id' => $id,
            'otherUser' => $otherUser ? [
                'id' => (int) $otherUser['id'],
                'displayName' => (string) $otherUser['display_name'],
                'role' => (string) $otherUser['role'],
                'approvalStatus' => (string) $otherUser['approval_status'],
                'blocked' => $blockedByMe,
                'avatarUrl' => $avatarUrl,
                'profileImageUrl' => $avatarUrl,
            ] : null,
            'archived' => $r['archived_at'] !== null,
            'unreadCount' => (int) $unread->fetchColumn(),
            'lastMessage' => is_array($lastRow) ? self::message($lastRow) : null,
            'updatedAt' => (string) $r['updated_at'],
        ];
    }

    /** @return list<array<string,mixed>> */
    public function messages(int $id, int $userId): array
    {
        $this->get($id, $userId);
        $s = $this->pdo->prepare('SELECT id,conversation_id,sender_id,body,created_at FROM (SELECT * FROM messages WHERE conversation_id=:id ORDER BY id DESC LIMIT 200) recent ORDER BY id ASC');
        $s->execute(['id' => $id]);

        return array_map([self::class,'message'], $s->fetchAll());
    }
    public function send(int $id, int $userId, string $body): array
    {
        $conversation = $this->get($id, $userId);
        $other = (int)$conversation['otherUser']['id'];
        if (($conversation['otherUser']['approvalStatus'] ?? null) !== 'APPROVED') {
            throw new ApiException(403, 'Messaging is unavailable while an account is not approved.');
        }
        $this->assertCanMessage($userId, $other);
        if ($this->isBlocked($userId, $other)) {
            throw new ApiException(403, 'Messaging is unavailable between these users.');
        }
        $body = trim($body);
        if ($body === '' || self::length($body) > 4000) {
            throw new ApiException(400, 'Message body must contain between 1 and 4000 characters.');
        }
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $s = $this->pdo->prepare('INSERT INTO messages (conversation_id,sender_id,body,created_at) VALUES (:cid,:uid,:body,CURRENT_TIMESTAMP(3))');
            $s->execute(['cid' => $id,'uid' => $userId,'body' => $body]);
            $mid = (int)$this->pdo->lastInsertId();
        } else {
            $s = $this->pdo->prepare('INSERT INTO messages (conversation_id,sender_id,body,created_at) VALUES (:cid,:uid,:body,CURRENT_TIMESTAMP) RETURNING id');
            $s->execute(['cid' => $id,'uid' => $userId,'body' => $body]);
            $mid = (int)$s->fetchColumn();
        }
        $this->pdo->prepare('UPDATE conversations SET updated_at=CURRENT_TIMESTAMP WHERE id=:id')->execute(['id' => $id]);
        $this->pdo->prepare('UPDATE conversation_members SET archived_at=NULL WHERE conversation_id=:id')->execute(['id' => $id]);
        $s = $this->pdo->prepare('SELECT id,conversation_id,sender_id,body,created_at FROM messages WHERE id=:id');
        $s->execute(['id' => $mid]);

        return self::message($s->fetch());
    }
    public function read(int $id, int $userId): void
    {
        $this->get($id, $userId);
        $statement = $this->pdo->prepare(
            'UPDATE conversation_members SET last_read_at=CURRENT_TIMESTAMP '
            . 'WHERE conversation_id=:cid AND user_id=:uid'
        );
        $statement->execute(['cid' => $id, 'uid' => $userId]);
    }
    public function archive(int $id, int $userId, bool $archived): void
    {
        $this->get($id, $userId);
        $sql = $archived ? 'UPDATE conversation_members SET archived_at=CURRENT_TIMESTAMP WHERE conversation_id=:cid AND user_id=:uid' : 'UPDATE conversation_members SET archived_at=NULL WHERE conversation_id=:cid AND user_id=:uid';
        $this->pdo->prepare($sql)->execute(['cid' => $id,'uid' => $userId]);
    }
    public function deleteForUser(int $id, int $userId): void
    {
        $this->get($id, $userId);
        $this->pdo->prepare('DELETE FROM conversation_members WHERE conversation_id=:cid AND user_id=:uid')->execute(['cid' => $id,'uid' => $userId]);
        $s = $this->pdo->prepare('SELECT COUNT(*) FROM conversation_members WHERE conversation_id=:cid');
        $s->execute(['cid' => $id]);
        if ((int)$s->fetchColumn() === 0) {
            $this->pdo->prepare('DELETE FROM conversations WHERE id=:cid')->execute(['cid' => $id]);
        }
    }
    public function block(int $userId, int $otherId): void
    {
        if ($userId === $otherId) {
            throw new ApiException(400, 'You cannot block yourself.');
        }
        $sql = $this->isMySql()
            ? 'INSERT IGNORE INTO blocked_users (blocker_id,blocked_id,created_at) '
                . 'VALUES (:uid,:oid,CURRENT_TIMESTAMP(3))'
            : 'INSERT INTO blocked_users (blocker_id,blocked_id,created_at) '
                . 'VALUES (:uid,:oid,CURRENT_TIMESTAMP) ON CONFLICT DO NOTHING';
        $this->pdo->prepare($sql)->execute(['uid' => $userId, 'oid' => $otherId]);
    }
    public function unblock(int $userId, int $otherId): void
    {
        $this->pdo->prepare('DELETE FROM blocked_users WHERE blocker_id=:uid AND blocked_id=:oid')->execute(['uid' => $userId,'oid' => $otherId]);
    }
    public function report(int $userId, int $otherId, string $reason, ?string $details): array
    {
        if ($userId === $otherId) {
            throw new ApiException(400, 'You cannot report yourself.');
        }
        $reason = trim($reason);
        $details = $details === null ? null : trim($details);
        if ($reason === '' || self::length($reason) > 80 || ($details !== null && self::length($details) > 1000)) {
            throw new ApiException(400, 'Invalid report data.');
        }
        if ($this->isMySql()) {
            $s = $this->pdo->prepare("INSERT INTO user_reports (reporter_id,reported_id,reason,details,status,created_at) VALUES (:uid,:oid,:reason,:details,'OPEN',CURRENT_TIMESTAMP(3))");
            $s->execute(['uid' => $userId,'oid' => $otherId,'reason' => $reason,'details' => $details]);
            $id = (int)$this->pdo->lastInsertId();
        } else {
            $s = $this->pdo->prepare("INSERT INTO user_reports (reporter_id,reported_id,reason,details,status,created_at) VALUES (:uid,:oid,:reason,:details,'OPEN',CURRENT_TIMESTAMP) RETURNING id");
            $s->execute(['uid' => $userId,'oid' => $otherId,'reason' => $reason,'details' => $details]);
            $id = (int)$s->fetchColumn();
        }

        return ['id' => $id, 'status' => 'OPEN'];
    }
    private function isBlocked(int $one, int $two): bool
    {
        $s = $this->pdo->prepare('SELECT 1 FROM blocked_users WHERE (blocker_id=:one_a AND blocked_id=:two_a) OR (blocker_id=:two_b AND blocked_id=:one_b) LIMIT 1');
        $s->execute(['one_a' => $one,'two_a' => $two,'two_b' => $two,'one_b' => $one]);

        return(bool)$s->fetchColumn();
    }

    private function assertCanMessage(int $one, int $two): void
    {
        $statement = $this->pdo->prepare(
            'SELECT id,role,approval_status,vip_active FROM users WHERE id IN (:one,:two)'
        );
        $statement->execute(['one' => $one, 'two' => $two]);
        $users = [];
        foreach ($statement->fetchAll() as $row) {
            $users[(int) $row['id']] = $row;
        }
        if (count($users) !== 2 || array_filter($users, static fn (array $user): bool => $user['approval_status'] !== 'APPROVED') !== []) {
            throw new ApiException(403, 'Messaging is unavailable while an account is not approved.');
        }
        $escort = null;
        $visitor = null;
        foreach ($users as $user) {
            $escort = $user['role'] === 'ESCORT' ? $user : $escort;
            $visitor = $user['role'] === 'VISITOR' ? $user : $visitor;
        }
        if ($escort !== null && $visitor !== null && !in_array($visitor['vip_active'], [true, 1, '1', 't', 'true'], true)) {
            throw new ApiException(403, 'VIP membership is required to contact a companion.', ['vipRequired' => 'true']);
        }
    }
    private function conversationMemberCount(int $conversationId): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM conversation_members WHERE conversation_id=:conversation_id'
        );
        $statement->execute(['conversation_id' => $conversationId]);

        return (int) $statement->fetchColumn();
    }

    private function addConversationMember(int $conversationId, int $userId): void
    {
        $sql = $this->isMySql()
            ? 'INSERT IGNORE INTO conversation_members (conversation_id,user_id) VALUES (:cid,:uid)'
            : 'INSERT INTO conversation_members (conversation_id,user_id) '
                . 'VALUES (:cid,:uid) ON CONFLICT DO NOTHING';
        $statement = $this->pdo->prepare($sql);
        $statement->execute(['cid' => $conversationId, 'uid' => $userId]);
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function message(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'conversationId' => (int) $row['conversation_id'],
            'senderId' => (int) $row['sender_id'],
            'body' => (string) $row['body'],
            'createdAt' => (string) $row['created_at'],
        ];
    }

    private static function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }

    private function isMySql(): bool
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    }
}
