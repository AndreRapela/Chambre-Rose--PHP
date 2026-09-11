CREATE TABLE IF NOT EXISTS native_push_devices (
  id BIGSERIAL PRIMARY KEY,
  user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  token_hash CHAR(64) NOT NULL UNIQUE,
  device_token TEXT NOT NULL,
  platform VARCHAR(16) NOT NULL,
  locale VARCHAR(16) NOT NULL DEFAULT 'en',
  app_version VARCHAR(32),
  failure_count INTEGER NOT NULL DEFAULT 0,
  last_success_at TIMESTAMP(3),
  last_failure_at TIMESTAMP(3),
  created_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_native_push_user
  ON native_push_devices (user_id, failure_count, id);
