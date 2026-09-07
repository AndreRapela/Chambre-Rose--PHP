CREATE TABLE IF NOT EXISTS realtime_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  event_type VARCHAR(64) NOT NULL,
  resource_id BIGINT UNSIGNED NULL,
  payload TEXT NOT NULL,
  created_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY idx_realtime_events_user_cursor (user_id, id),
  KEY idx_realtime_events_created (created_at),
  CONSTRAINT fk_realtime_events_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
