ALTER TABLE notification_preferences
  ADD COLUMN browser_notifications TINYINT(1) NOT NULL DEFAULT 1;

ALTER TABLE notification_preferences
  ADD COLUMN in_app_notifications TINYINT(1) NOT NULL DEFAULT 1;

ALTER TABLE notification_preferences
  ADD COLUMN only_direct_messages TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE notification_preferences
  ADD COLUMN daily_digest TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE notification_preferences
  ADD COLUMN daily_digest_time CHAR(5) NOT NULL DEFAULT '09:00';

ALTER TABLE notification_preferences
  ADD COLUMN quiet_hours_enabled TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE notification_preferences
  ADD COLUMN quiet_hours_start CHAR(5) NOT NULL DEFAULT '22:00';

ALTER TABLE notification_preferences
  ADD COLUMN quiet_hours_end CHAR(5) NOT NULL DEFAULT '08:00';

ALTER TABLE notification_preferences
  ADD COLUMN timezone VARCHAR(64) NOT NULL DEFAULT 'UTC';

ALTER TABLE account_notifications
  ADD COLUMN visible_in_app TINYINT(1) NOT NULL DEFAULT 1;

CREATE INDEX idx_account_notifications_visible_feed
  ON account_notifications (user_id, visible_in_app, id);

CREATE INDEX idx_account_notifications_visible_unread
  ON account_notifications (user_id, visible_in_app, read_at, category);
