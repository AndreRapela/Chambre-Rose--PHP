CREATE TABLE IF NOT EXISTS native_push_devices (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  device_token TEXT NOT NULL,
  platform VARCHAR(16) NOT NULL,
  locale VARCHAR(16) NOT NULL DEFAULT 'en',
  app_version VARCHAR(32) NULL,
  failure_count INT NOT NULL DEFAULT 0,
  last_success_at DATETIME(3) NULL,
  last_failure_at DATETIME(3) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uk_native_push_token (token_hash),
  KEY idx_native_push_user (user_id, failure_count, id),
  CONSTRAINT fk_native_push_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
