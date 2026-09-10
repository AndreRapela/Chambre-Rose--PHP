ALTER TABLE password_reset_tokens
  ADD COLUMN IF NOT EXISTS verification_code_hash CHAR(64),
  ADD COLUMN IF NOT EXISTS verification_attempts SMALLINT NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS verified_at TIMESTAMPTZ;
