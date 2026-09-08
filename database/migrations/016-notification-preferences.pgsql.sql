ALTER TABLE notification_preferences
  ADD COLUMN IF NOT EXISTS browser_notifications BOOLEAN NOT NULL DEFAULT TRUE;

ALTER TABLE notification_preferences
  ADD COLUMN IF NOT EXISTS in_app_notifications BOOLEAN NOT NULL DEFAULT TRUE;

ALTER TABLE notification_preferences
  ADD COLUMN IF NOT EXISTS only_direct_messages BOOLEAN NOT NULL DEFAULT FALSE;

ALTER TABLE notification_preferences
  ADD COLUMN IF NOT EXISTS daily_digest BOOLEAN NOT NULL DEFAULT FALSE;

ALTER TABLE notification_preferences
  ADD COLUMN IF NOT EXISTS daily_digest_time CHAR(5) NOT NULL DEFAULT '09:00';

ALTER TABLE notification_preferences
  ADD COLUMN IF NOT EXISTS quiet_hours_enabled BOOLEAN NOT NULL DEFAULT FALSE;

ALTER TABLE notification_preferences
  ADD COLUMN IF NOT EXISTS quiet_hours_start CHAR(5) NOT NULL DEFAULT '22:00';

ALTER TABLE notification_preferences
  ADD COLUMN IF NOT EXISTS quiet_hours_end CHAR(5) NOT NULL DEFAULT '08:00';

ALTER TABLE notification_preferences
  ADD COLUMN IF NOT EXISTS timezone VARCHAR(64) NOT NULL DEFAULT 'UTC';

ALTER TABLE account_notifications
  ADD COLUMN IF NOT EXISTS visible_in_app BOOLEAN NOT NULL DEFAULT TRUE;

CREATE INDEX IF NOT EXISTS idx_account_notifications_visible_feed
  ON account_notifications (user_id, visible_in_app, id DESC);

CREATE INDEX IF NOT EXISTS idx_account_notifications_visible_unread
  ON account_notifications (user_id, read_at, category)
  WHERE visible_in_app=TRUE;
