ALTER TABLE password_reset_tokens ADD COLUMN verification_code_hash CHAR(64) NULL;
ALTER TABLE password_reset_tokens ADD COLUMN verification_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE password_reset_tokens ADD COLUMN verified_at DATETIME(3) NULL;
