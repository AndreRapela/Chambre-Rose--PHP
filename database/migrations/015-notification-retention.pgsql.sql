CREATE INDEX IF NOT EXISTS idx_account_notifications_retention
  ON account_notifications (created_at, id);

CREATE INDEX IF NOT EXISTS idx_account_notifications_unread_category
  ON account_notifications (user_id, category)
  WHERE read_at IS NULL;

CREATE INDEX IF NOT EXISTS idx_account_notifications_target
  ON account_notifications (user_id, category, target_url);

CREATE INDEX IF NOT EXISTS idx_push_subscriptions_active
  ON push_subscriptions (user_id, failure_count, id);
