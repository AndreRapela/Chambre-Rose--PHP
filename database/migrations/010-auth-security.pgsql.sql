CREATE TABLE IF NOT EXISTS auth_rate_limits (
  bucket_hash CHAR(64) PRIMARY KEY,
  scope VARCHAR(40) NOT NULL,
  attempts SMALLINT NOT NULL DEFAULT 0,
  window_started_at TIMESTAMPTZ NOT NULL,
  blocked_until TIMESTAMPTZ,
  updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_auth_rate_limits_cleanup
ON auth_rate_limits (updated_at, blocked_until);
