CREATE TABLE IF NOT EXISTS user_company_verifications (
  user_id BIGINT PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
  company_number VARCHAR(80) NOT NULL,
  registration_name VARCHAR(255) NOT NULL,
  registration_content_type VARCHAR(80) NOT NULL,
  registration_data BYTEA NOT NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_company_verifications_created_at
  ON user_company_verifications (created_at);
