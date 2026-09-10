<?php

declare(strict_types=1);

namespace ChambreRose;

use PDO;

final class NotificationRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string, bool|string> */
    public function preferences(int $userId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT direct_messages,account_updates,marketplace_updates,security_updates,'
            . 'browser_notifications,in_app_notifications,only_direct_messages,daily_digest,daily_digest_time,'
            . 'quiet_hours_enabled,quiet_hours_start,quiet_hours_end,timezone '
            . 'FROM notification_preferences WHERE user_id=:user_id'
        );
        $statement->execute(['user_id' => $userId]);
        $row = $statement->fetch();

        return [
            'directMessages' => !is_array($row) || self::bool($row['direct_messages']),
            // These categories are required for account and security emails.
            'accountUpdates' => true,
            'marketplaceUpdates' => !is_array($row) || self::bool($row['marketplace_updates']),
            'securityUpdates' => true,
            'browserNotifications' => !is_array($row) || self::bool($row['browser_notifications']),
            'inAppNotifications' => !is_array($row) || self::bool($row['in_app_notifications']),
            'onlyDirectMessages' => is_array($row) && self::bool($row['only_direct_messages']),
            'dailyDigest' => is_array($row) && self::bool($row['daily_digest']),
            'dailyDigestTime' => is_array($row) ? (string) $row['daily_digest_time'] : '09:00',
            'quietHoursEnabled' => is_array($row) && self::bool($row['quiet_hours_enabled']),
            'quietHoursStart' => is_array($row) ? (string) $row['quiet_hours_start'] : '22:00',
            'quietHoursEnd' => is_array($row) ? (string) $row['quiet_hours_end'] : '08:00',
            'timezone' => is_array($row) ? (string) $row['timezone'] : 'UTC',
        ];
    }

    /**
     * @param array{
     *   directMessages: bool, accountUpdates: bool, marketplaceUpdates: bool, securityUpdates: bool,
     *   browserNotifications: bool, inAppNotifications: bool, onlyDirectMessages: bool, dailyDigest: bool,
     *   dailyDigestTime: string, quietHoursEnabled: bool, quietHoursStart: string, quietHoursEnd: string,
     *   timezone: string
     * } $preferences
     * @return array<string, bool|string>
     */
    public function savePreferences(int $userId, array $preferences): array
    {
        $isMySql = $this->isMySql();
        $sql = $isMySql
            ? <<<'SQL'
                INSERT INTO notification_preferences (
                  user_id,direct_messages,account_updates,marketplace_updates,security_updates,
                  browser_notifications,in_app_notifications,only_direct_messages,daily_digest,daily_digest_time,
                  quiet_hours_enabled,quiet_hours_start,quiet_hours_end,timezone,updated_at
                ) VALUES (
                  :user_id,:direct_messages,:account_updates,:marketplace_updates,:security_updates,
                  :browser_notifications,:in_app_notifications,:only_direct_messages,:daily_digest,:daily_digest_time,
                  :quiet_hours_enabled,:quiet_hours_start,:quiet_hours_end,:timezone,CURRENT_TIMESTAMP(3)
                ) ON DUPLICATE KEY UPDATE
                  direct_messages=VALUES(direct_messages),account_updates=VALUES(account_updates),
                  marketplace_updates=VALUES(marketplace_updates),security_updates=VALUES(security_updates),
                  browser_notifications=VALUES(browser_notifications),in_app_notifications=VALUES(in_app_notifications),
                  only_direct_messages=VALUES(only_direct_messages),daily_digest=VALUES(daily_digest),
                  daily_digest_time=VALUES(daily_digest_time),quiet_hours_enabled=VALUES(quiet_hours_enabled),
                  quiet_hours_start=VALUES(quiet_hours_start),quiet_hours_end=VALUES(quiet_hours_end),timezone=VALUES(timezone),
                  updated_at=CURRENT_TIMESTAMP(3)
                SQL
            : <<<'SQL'
                INSERT INTO notification_preferences (
                  user_id,direct_messages,account_updates,marketplace_updates,security_updates,
                  browser_notifications,in_app_notifications,only_direct_messages,daily_digest,daily_digest_time,
                  quiet_hours_enabled,quiet_hours_start,quiet_hours_end,timezone,updated_at
                ) VALUES (
                  :user_id,:direct_messages,:account_updates,:marketplace_updates,:security_updates,
                  :browser_notifications,:in_app_notifications,:only_direct_messages,:daily_digest,:daily_digest_time,
                  :quiet_hours_enabled,:quiet_hours_start,:quiet_hours_end,:timezone,CURRENT_TIMESTAMP
                ) ON CONFLICT (user_id) DO UPDATE SET
                  direct_messages=EXCLUDED.direct_messages,account_updates=EXCLUDED.account_updates,
                  marketplace_updates=EXCLUDED.marketplace_updates,security_updates=EXCLUDED.security_updates,
                  browser_notifications=EXCLUDED.browser_notifications,in_app_notifications=EXCLUDED.in_app_notifications,
                  only_direct_messages=EXCLUDED.only_direct_messages,daily_digest=EXCLUDED.daily_digest,
                  daily_digest_time=EXCLUDED.daily_digest_time,quiet_hours_enabled=EXCLUDED.quiet_hours_enabled,
                  quiet_hours_start=EXCLUDED.quiet_hours_start,quiet_hours_end=EXCLUDED.quiet_hours_end,timezone=EXCLUDED.timezone,
                  updated_at=CURRENT_TIMESTAMP
                SQL;
        $statement = $this->pdo->prepare($sql);
        $statement->execute([
            'user_id' => $userId,
            'direct_messages' => $this->databaseBool($preferences['directMessages']),
            'account_updates' => 1,
            'marketplace_updates' => $this->databaseBool($preferences['marketplaceUpdates']),
            'security_updates' => 1,
            'browser_notifications' => $this->databaseBool($preferences['browserNotifications']),
            'in_app_notifications' => $this->databaseBool($preferences['inAppNotifications']),
            'only_direct_messages' => $this->databaseBool($preferences['onlyDirectMessages']),
            'daily_digest' => $this->databaseBool($preferences['dailyDigest']),
            'daily_digest_time' => $preferences['dailyDigestTime'],
            'quiet_hours_enabled' => $this->databaseBool($preferences['quietHoursEnabled']),
            'quiet_hours_start' => $preferences['quietHoursStart'],
            'quiet_hours_end' => $preferences['quietHoursEnd'],
            'timezone' => $preferences['timezone'],
        ]);

        return $this->preferences($userId);
    }

    /**
     * @param array<string, scalar|null> $messageParameters
     * @return array<string, mixed>
     */
    public function create(
        int $userId,
        string $category,
        string $eventType,
        string $targetUrl,
        ?string $dedupeKey = null,
        bool $visibleInApp = true,
        array $messageParameters = []
    ): array {
        $params = [
            'user_id' => $userId,
            'category' => $category,
            'event_type' => $eventType,
            'message_params' => json_encode($messageParameters, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'target_url' => $targetUrl,
            'dedupe_key' => $dedupeKey,
            'visible_in_app' => $this->databaseBool($visibleInApp),
        ];
        if ($this->isMySql()) {
            $statement = $this->pdo->prepare(<<<'SQL'
                INSERT INTO account_notifications (
                  user_id,category,event_type,title,body,message_params,target_url,dedupe_key,visible_in_app,created_at
                ) VALUES (
                  :user_id,:category,:event_type,NULL,NULL,:message_params,:target_url,:dedupe_key,:visible_in_app,CURRENT_TIMESTAMP(3)
                ) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)
                SQL);
            $statement->execute($params);
            $id = (int) $this->pdo->lastInsertId();
        } else {
            $statement = $this->pdo->prepare(<<<'SQL'
                INSERT INTO account_notifications (
                  user_id,category,event_type,title,body,message_params,target_url,dedupe_key,visible_in_app,created_at
                ) VALUES (
                  :user_id,:category,:event_type,NULL,NULL,:message_params,:target_url,:dedupe_key,:visible_in_app,CURRENT_TIMESTAMP
                ) ON CONFLICT (user_id,dedupe_key) DO UPDATE SET dedupe_key=EXCLUDED.dedupe_key
                RETURNING id
                SQL);
            $statement->execute($params);
            $id = (int) $statement->fetchColumn();
        }

        return $this->find($id, $userId)
            ?? throw new ApiException(500, 'Unable to create account notification.');
    }

    /** @return array{items: list<array<string, mixed>>, unreadCount: int, unreadByCategory: array<string, int>} */
    public function feed(int $userId, int $afterId = 0, int $limit = 30): array
    {
        $limit = min(100, max(1, $limit));
        $sql = 'SELECT id,category,event_type,title,body,message_params,target_url,read_at,created_at '
            . 'FROM account_notifications WHERE user_id=:user_id AND visible_in_app';
        if ($afterId > 0) {
            $sql .= ' AND id>:after_id ORDER BY id ASC';
        } else {
            $sql .= ' ORDER BY id DESC';
        }
        $sql .= ' LIMIT :limit';
        $statement = $this->pdo->prepare($sql);
        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        if ($afterId > 0) {
            $statement->bindValue(':after_id', $afterId, PDO::PARAM_INT);
        }
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        $unread = $this->pdo->prepare(
            'SELECT COUNT(*) FROM account_notifications WHERE user_id=:user_id AND visible_in_app AND read_at IS NULL'
        );
        $unread->execute(['user_id' => $userId]);
        $categoryStatement = $this->pdo->prepare(
            'SELECT category,COUNT(*) AS total FROM account_notifications '
            . 'WHERE user_id=:user_id AND visible_in_app AND read_at IS NULL GROUP BY category'
        );
        $categoryStatement->execute(['user_id' => $userId]);
        $byCategory = [];
        foreach ($categoryStatement->fetchAll() as $row) {
            $byCategory[(string) $row['category']] = (int) $row['total'];
        }

        return [
            'items' => array_map([self::class, 'mapNotification'], $statement->fetchAll()),
            'unreadCount' => (int) $unread->fetchColumn(),
            'unreadByCategory' => $byCategory,
        ];
    }

    public function markRead(int $userId, ?int $notificationId = null): void
    {
        $sql = 'UPDATE account_notifications SET read_at=COALESCE(read_at,CURRENT_TIMESTAMP) '
            . 'WHERE user_id=:user_id AND visible_in_app';
        $params = ['user_id' => $userId];
        if ($notificationId !== null) {
            $sql .= ' AND id=:notification_id';
            $params['notification_id'] = $notificationId;
        }
        $this->pdo->prepare($sql)->execute($params);
    }

    public function markReadForTarget(int $userId, string $category, string $targetUrl): void
    {
        $this->pdo->prepare(
            'UPDATE account_notifications SET read_at=COALESCE(read_at,CURRENT_TIMESTAMP) '
            . 'WHERE user_id=:user_id AND visible_in_app AND category=:category AND target_url=:target_url'
        )->execute([
            'user_id' => $userId,
            'category' => $category,
            'target_url' => $targetUrl,
        ]);
    }

    public function saveSubscription(
        int $userId,
        string $endpoint,
        string $publicKey,
        string $authToken,
        string $contentEncoding,
        ?string $userAgent
    ): void {
        $params = [
            'user_id' => $userId,
            'endpoint_hash' => hash('sha256', $endpoint),
            'endpoint' => $endpoint,
            'public_key' => $publicKey,
            'auth_token' => $authToken,
            'content_encoding' => $contentEncoding,
            'user_agent' => $userAgent,
        ];
        $sql = $this->isMySql()
            ? <<<'SQL'
                INSERT INTO push_subscriptions (
                  user_id,endpoint_hash,endpoint,public_key,auth_token,content_encoding,user_agent,created_at,updated_at
                ) VALUES (
                  :user_id,:endpoint_hash,:endpoint,:public_key,:auth_token,:content_encoding,:user_agent,CURRENT_TIMESTAMP(3),CURRENT_TIMESTAMP(3)
                ) ON DUPLICATE KEY UPDATE
                  user_id=VALUES(user_id),endpoint=VALUES(endpoint),public_key=VALUES(public_key),
                  auth_token=VALUES(auth_token),content_encoding=VALUES(content_encoding),
                  user_agent=VALUES(user_agent),failure_count=0,updated_at=CURRENT_TIMESTAMP(3)
                SQL
            : <<<'SQL'
                INSERT INTO push_subscriptions (
                  user_id,endpoint_hash,endpoint,public_key,auth_token,content_encoding,user_agent,created_at,updated_at
                ) VALUES (
                  :user_id,:endpoint_hash,:endpoint,:public_key,:auth_token,:content_encoding,:user_agent,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP
                ) ON CONFLICT (endpoint_hash) DO UPDATE SET
                  user_id=EXCLUDED.user_id,endpoint=EXCLUDED.endpoint,public_key=EXCLUDED.public_key,
                  auth_token=EXCLUDED.auth_token,content_encoding=EXCLUDED.content_encoding,
                  user_agent=EXCLUDED.user_agent,failure_count=0,updated_at=CURRENT_TIMESTAMP
                SQL;
        $this->pdo->prepare($sql)->execute($params);
    }

    public function deleteSubscription(int $userId, string $endpoint): void
    {
        $this->deleteSubscriptionByHash($userId, hash('sha256', $endpoint));
    }

    public function deleteSubscriptionByHash(int $userId, string $endpointHash): void
    {
        $this->pdo->prepare(
            'DELETE FROM push_subscriptions WHERE user_id=:user_id AND endpoint_hash=:endpoint_hash'
        )->execute(['user_id' => $userId, 'endpoint_hash' => $endpointHash]);
    }

    public function browserDeliveryAllowed(int $userId, string $category, string $eventType): bool
    {
        $preferences = $this->preferences($userId);
        if ($preferences['browserNotifications'] !== true) {
            return false;
        }
        if ($preferences['onlyDirectMessages'] === true
            && !in_array($category, [UserNotificationService::DIRECT_MESSAGE, UserNotificationService::ACCOUNT, UserNotificationService::SECURITY], true)) {
            return false;
        }
        if ($eventType === UserNotificationService::DAILY_DIGEST) {
            return $preferences['dailyDigest'] === true
                && ($preferences['accountUpdates'] === true || $preferences['marketplaceUpdates'] === true);
        }

        $categoryEnabled = match ($category) {
            UserNotificationService::DIRECT_MESSAGE => $preferences['directMessages'] === true,
            UserNotificationService::MARKETPLACE => $preferences['marketplaceUpdates'] === true,
            UserNotificationService::SECURITY => $preferences['securityUpdates'] === true,
            default => $preferences['accountUpdates'] === true,
        };
        if (!$categoryEnabled) {
            return false;
        }

        return $preferences['dailyDigest'] !== true
            || !in_array($category, [UserNotificationService::ACCOUNT, UserNotificationService::MARKETPLACE], true);
    }

    /** @return list<array<string, mixed>> */
    public function subscriptions(int $userId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id,endpoint,public_key,auth_token,content_encoding FROM push_subscriptions '
            . 'WHERE user_id=:user_id AND failure_count<5 ORDER BY id'
        );
        $statement->execute(['user_id' => $userId]);

        return $statement->fetchAll();
    }

    public function recordSubscriptionResult(int $id, bool $success, bool $expired = false): void
    {
        if ($expired) {
            $this->pdo->prepare('DELETE FROM push_subscriptions WHERE id=:id')->execute(['id' => $id]);
            return;
        }
        $sql = $success
            ? 'UPDATE push_subscriptions SET failure_count=0,last_success_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=:id'
            : 'UPDATE push_subscriptions SET failure_count=failure_count+1,last_failure_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=:id';
        $this->pdo->prepare($sql)->execute(['id' => $id]);
    }

    /** @return array<string, mixed>|null */
    private function find(int $id, int $userId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id,category,event_type,title,body,message_params,target_url,read_at,created_at '
            . 'FROM account_notifications WHERE id=:id AND user_id=:user_id'
        );
        $statement->execute(['id' => $id, 'user_id' => $userId]);
        $row = $statement->fetch();

        return is_array($row) ? self::mapNotification($row) : null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function mapNotification(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'category' => (string) $row['category'],
            'eventType' => (string) $row['event_type'],
            'title' => (string) ($row['title'] ?? ''),
            'body' => (string) ($row['body'] ?? ''),
            'params' => self::messageParameters($row['message_params'] ?? null),
            'targetUrl' => (string) $row['target_url'],
            'readAt' => $row['read_at'] === null ? null : (string) $row['read_at'],
            'createdAt' => (string) $row['created_at'],
        ];
    }

    private static function bool(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 't', 'true'], true);
    }

    /** @return array<string, scalar|null> */
    private static function messageParameters(mixed $value): array
    {
        if (is_array($value)) {
            $decoded = $value;
        } elseif (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
        } else {
            return [];
        }
        if (!is_array($decoded)) {
            return [];
        }

        $parameters = [];
        foreach ($decoded as $name => $parameter) {
            if (is_string($name) && (is_scalar($parameter) || $parameter === null)) {
                $parameters[$name] = $parameter;
            }
        }

        return $parameters;
    }

    private function isMySql(): bool
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    }

    private function databaseBool(bool $value): int|string
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql'
            ? ($value ? 'true' : 'false')
            : (int) $value;
    }
}
