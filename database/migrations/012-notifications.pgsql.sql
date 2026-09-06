CREATE TABLE IF NOT EXISTS notification_preferences (
  user_id BIGINT PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
  direct_messages BOOLEAN NOT NULL DEFAULT TRUE,
  account_updates BOOLEAN NOT NULL DEFAULT TRUE,
  marketplace_updates BOOLEAN NOT NULL DEFAULT TRUE,
  security_updates BOOLEAN NOT NULL DEFAULT TRUE,
  updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS push_subscriptions (
  id BIGSERIAL PRIMARY KEY,
  user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  endpoint_hash CHAR(64) NOT NULL UNIQUE,
  endpoint TEXT NOT NULL,
  public_key VARCHAR(255) NOT NULL,
  auth_token VARCHAR(255) NOT NULL,
  content_encoding VARCHAR(32) NOT NULL DEFAULT 'aes128gcm',
  user_agent VARCHAR(255) NULL,
  failure_count INTEGER NOT NULL DEFAULT 0,
  last_success_at TIMESTAMPTZ NULL,
  last_failure_at TIMESTAMPTZ NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_push_subscriptions_user ON push_subscriptions (user_id, updated_at DESC);

CREATE TABLE IF NOT EXISTS account_notifications (
  id BIGSERIAL PRIMARY KEY,
  user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  category VARCHAR(32) NOT NULL,
  event_type VARCHAR(64) NOT NULL,
  title VARCHAR(160) NOT NULL,
  body VARCHAR(500) NOT NULL,
  target_url VARCHAR(500) NOT NULL,
  dedupe_key VARCHAR(190) NULL,
  read_at TIMESTAMPTZ NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (user_id, dedupe_key)
);
CREATE INDEX IF NOT EXISTS idx_account_notifications_user ON account_notifications (user_id, id DESC);
CREATE INDEX IF NOT EXISTS idx_account_notifications_unread ON account_notifications (user_id, read_at, id DESC);
