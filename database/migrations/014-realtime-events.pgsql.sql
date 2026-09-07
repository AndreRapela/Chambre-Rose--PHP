CREATE TABLE IF NOT EXISTS realtime_events (
  id BIGSERIAL PRIMARY KEY,
  user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  event_type VARCHAR(64) NOT NULL,
  resource_id BIGINT NULL,
  payload TEXT NOT NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_realtime_events_user_cursor ON realtime_events (user_id, id);
CREATE INDEX IF NOT EXISTS idx_realtime_events_created ON realtime_events (created_at);
