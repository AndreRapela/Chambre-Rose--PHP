<?php

declare(strict_types=1);

namespace ChambreRose;

use PDO;

final class MessagingRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string, mixed> */
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
        return $this->summaries($userId);
    }

    /** @return array<string, mixed> */
    public function get(int $id, int $userId): array
    {
        $items = $this->summaries($userId, $id);
        if ($items === []) {
            throw new ApiException(404, 'Conversation not found.');
        }

        return $items[0];
    }

    /** @return array{items: list<array<string,mixed>>, hasMore: bool, peerReadAt: string|null} */
    public function messages(
        int $id,
        int $userId,
        ?int $afterId = null,
        ?int $beforeId = null,
        int $limit = 50
    ): array
    {
        $conversation = $this->get($id, $userId);
        if ($afterId !== null && $beforeId !== null) {
            throw new ApiException(400, 'Use either after or before, not both.');
        }
        $limit = min(100, max(1, $limit));
        $sql = <<<'SQL'
            SELECT m.id,m.conversation_id,m.sender_id,m.body,m.created_at,
              CASE WHEN other_member.last_read_at>=m.created_at THEN other_member.last_read_at ELSE NULL END AS read_at
            FROM messages m
            LEFT JOIN conversation_members other_member
              ON other_member.conversation_id=m.conversation_id AND other_member.user_id<>:user_id
            WHERE m.conversation_id=:conversation_id
            SQL;
        $descending = $afterId === null;
        $params = ['user_id' => $userId, 'conversation_id' => $id];
        if ($afterId !== null) {
            $sql .= ' AND m.id>:after_id ORDER BY m.id ASC';
            $params['after_id'] = $afterId;
        } else {
            if ($beforeId !== null) {
                $sql .= ' AND m.id<:before_id';
                $params['before_id'] = $beforeId;
            }
            $sql .= ' ORDER BY m.id DESC';
        }
        $sql .= ' LIMIT :limit';
        $statement = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $statement->bindValue(':' . $key, $value, PDO::PARAM_INT);
        }
        $statement->bindValue(':limit', $limit + 1, PDO::PARAM_INT);
        $statement->execute();
        $rows = $statement->fetchAll();
        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            array_pop($rows);
        }
        if ($descending) {
            $rows = array_reverse($rows);
        }

        return [
            'items' => array_map([self::class, 'message'], $rows),
            'hasMore' => $hasMore,
            'peerReadAt' => $conversation['peerReadAt'],
        ];
    }
    /** @return array<string, mixed> */
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
    /** @return array{id: int, status: string} */
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

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function message(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'conversationId' => (int) $row['conversation_id'],
            'senderId' => (int) $row['sender_id'],
            'body' => (string) $row['body'],
            'createdAt' => (string) $row['created_at'],
            'readAt' => ($row['read_at'] ?? null) === null ? null : (string) $row['read_at'],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function summaries(int $userId, ?int $conversationId = null): array
    {
        $sql = <<<'SQL'
            SELECT c.id,c.updated_at,cm.archived_at,
              other_user.id AS other_id,other_user.role AS other_role,
              other_user.approval_status AS other_approval_status,other_member.last_read_at AS peer_read_at,
              COALESCE(profile.display_name,CONCAT(other_user.first_name,' ',other_user.last_name)) AS display_name,
              blocked.blocked_id AS blocked_id,
              (SELECT media.id FROM profile_media media
                WHERE media.user_id=other_user.id AND media.media_type='PHOTO'
                ORDER BY media.position,media.id LIMIT 1) AS avatar_id,
              last_message.id AS last_id,last_message.sender_id AS last_sender_id,
              last_message.body AS last_body,last_message.created_at AS last_created_at,
              (SELECT COUNT(*) FROM messages unread
                WHERE unread.conversation_id=c.id AND unread.sender_id<>:unread_user_id
                  AND (cm.last_read_at IS NULL OR unread.created_at>cm.last_read_at)) AS unread_count
            FROM conversations c
            JOIN conversation_members cm ON cm.conversation_id=c.id AND cm.user_id=:member_user_id
            JOIN users other_user ON other_user.id=CASE
              WHEN c.participant_one_id=:participant_user_id THEN c.participant_two_id
              ELSE c.participant_one_id END
            LEFT JOIN conversation_members other_member
              ON other_member.conversation_id=c.id AND other_member.user_id=other_user.id
            LEFT JOIN professional_profiles profile ON profile.user_id=other_user.id
            LEFT JOIN blocked_users blocked ON blocked.blocker_id=:blocker_user_id AND blocked.blocked_id=other_user.id
            LEFT JOIN messages last_message ON last_message.id=(
              SELECT MAX(latest.id) FROM messages latest WHERE latest.conversation_id=c.id
            )
            SQL;
        $params = [
            'unread_user_id' => $userId,
            'member_user_id' => $userId,
            'participant_user_id' => $userId,
            'blocker_user_id' => $userId,
        ];
        if ($conversationId !== null) {
            $sql .= ' WHERE c.id=:conversation_id';
            $params['conversation_id'] = $conversationId;
        }
        $sql .= ' ORDER BY c.updated_at DESC,c.id DESC';
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return array_map(static function (array $row): array {
            $avatarUrl = $row['avatar_id'] === null
                ? null
                : '/api/profiles/' . (int) $row['other_id'] . '/media/' . (int) $row['avatar_id'];
            $lastMessage = $row['last_id'] === null ? null : self::message([
                'id' => $row['last_id'],
                'conversation_id' => $row['id'],
                'sender_id' => $row['last_sender_id'],
                'body' => $row['last_body'],
                'created_at' => $row['last_created_at'],
                'read_at' => null,
            ]);

            return [
                'id' => (int) $row['id'],
                'otherUser' => [
                    'id' => (int) $row['other_id'],
                    'displayName' => trim((string) $row['display_name']),
                    'role' => (string) $row['other_role'],
                    'approvalStatus' => (string) $row['other_approval_status'],
                    'blocked' => $row['blocked_id'] !== null,
                    'avatarUrl' => $avatarUrl,
                    'profileImageUrl' => $avatarUrl,
                ],
                'archived' => $row['archived_at'] !== null,
                'unreadCount' => (int) $row['unread_count'],
                'lastMessage' => $lastMessage,
                'peerReadAt' => $row['peer_read_at'] === null ? null : (string) $row['peer_read_at'],
                'updatedAt' => (string) $row['updated_at'],
            ];
        }, $statement->fetchAll());
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
