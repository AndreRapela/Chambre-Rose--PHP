CREATE TABLE IF NOT EXISTS notification_preferences (
  user_id BIGINT UNSIGNED NOT NULL,
  direct_messages TINYINT(1) NOT NULL DEFAULT 1,
  account_updates TINYINT(1) NOT NULL DEFAULT 1,
  marketplace_updates TINYINT(1) NOT NULL DEFAULT 1,
  security_updates TINYINT(1) NOT NULL DEFAULT 1,
  updated_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (user_id),
  CONSTRAINT fk_notification_preferences_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS push_subscriptions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  endpoint_hash CHAR(64) NOT NULL,
  endpoint TEXT NOT NULL,
  public_key VARCHAR(255) NOT NULL,
  auth_token VARCHAR(255) NOT NULL,
  content_encoding VARCHAR(32) NOT NULL DEFAULT 'aes128gcm',
  user_agent VARCHAR(255) NULL,
  failure_count INT UNSIGNED NOT NULL DEFAULT 0,
  last_success_at TIMESTAMP(3) NULL,
  last_failure_at TIMESTAMP(3) NULL,
  created_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uk_push_subscriptions_endpoint (endpoint_hash),
  KEY idx_push_subscriptions_user (user_id, updated_at),
  CONSTRAINT fk_push_subscriptions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS account_notifications (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  category VARCHAR(32) NOT NULL,
  event_type VARCHAR(64) NOT NULL,
  title VARCHAR(160) NOT NULL,
  body VARCHAR(500) NOT NULL,
  target_url VARCHAR(500) NOT NULL,
  dedupe_key VARCHAR(190) NULL,
  read_at TIMESTAMP(3) NULL,
  created_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uk_account_notifications_dedupe (user_id, dedupe_key),
  KEY idx_account_notifications_user (user_id, id),
  KEY idx_account_notifications_unread (user_id, read_at, id),
  CONSTRAINT fk_account_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
