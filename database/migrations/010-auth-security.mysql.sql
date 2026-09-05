CREATE TABLE auth_rate_limits (
  bucket_hash CHAR(64) NOT NULL,
  scope VARCHAR(40) NOT NULL,
  attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  window_started_at DATETIME(3) NOT NULL,
  blocked_until DATETIME(3) NULL,
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (bucket_hash),
  KEY idx_auth_rate_limits_cleanup (updated_at, blocked_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
