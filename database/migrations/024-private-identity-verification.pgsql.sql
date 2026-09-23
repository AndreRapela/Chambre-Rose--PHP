CREATE TABLE IF NOT EXISTS user_identity_verifications (
  user_id BIGINT PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
  document_type VARCHAR(20) NOT NULL,
  document_name VARCHAR(255) NOT NULL,
  document_content_type VARCHAR(80) NOT NULL,
  document_data BYTEA NOT NULL,
  selfie_name VARCHAR(255) NOT NULL,
  selfie_content_type VARCHAR(80) NOT NULL,
  selfie_data BYTEA NOT NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_identity_verifications_created_at
  ON user_identity_verifications (created_at);
