CREATE INDEX idx_account_notifications_retention
  ON account_notifications (created_at, id);

CREATE INDEX idx_account_notifications_unread_category
  ON account_notifications (user_id, read_at, category);

CREATE INDEX idx_account_notifications_target
  ON account_notifications (user_id, category, target_url(190));

CREATE INDEX idx_push_subscriptions_active
  ON push_subscriptions (user_id, failure_count, id);
