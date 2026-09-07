CREATE TABLE IF NOT EXISTS push_notification_outbox (
  id BIGSERIAL PRIMARY KEY,
  notification_id BIGINT NOT NULL UNIQUE REFERENCES account_notifications(id) ON DELETE CASCADE,
  user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  status VARCHAR(16) NOT NULL DEFAULT 'PENDING',
  attempts INTEGER NOT NULL DEFAULT 0,
  max_attempts INTEGER NOT NULL DEFAULT 8,
  available_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
  locked_at TIMESTAMPTZ NULL,
  locked_by VARCHAR(190) NULL,
  last_error VARCHAR(1000) NULL,
  delivered_at TIMESTAMPTZ NULL,
  failed_at TIMESTAMPTZ NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT chk_push_notification_outbox_status CHECK (status IN ('PENDING', 'PROCESSING', 'RETRY', 'DELIVERED', 'SKIPPED', 'FAILED')),
  CONSTRAINT chk_push_notification_outbox_attempts CHECK (attempts >= 0 AND max_attempts > 0)
);
CREATE INDEX IF NOT EXISTS idx_push_notification_outbox_dispatch ON push_notification_outbox (status, available_at, id);
CREATE INDEX IF NOT EXISTS idx_push_notification_outbox_user ON push_notification_outbox (user_id, created_at DESC);
