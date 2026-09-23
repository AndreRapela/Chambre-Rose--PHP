CREATE TABLE IF NOT EXISTS user_identity_verifications (
  user_id BIGINT UNSIGNED NOT NULL,
  document_type VARCHAR(20) NOT NULL,
  document_name VARCHAR(255) NOT NULL,
  document_content_type VARCHAR(80) NOT NULL,
  document_data LONGBLOB NOT NULL,
  selfie_name VARCHAR(255) NOT NULL,
  selfie_content_type VARCHAR(80) NOT NULL,
  selfie_data LONGBLOB NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (user_id),
  KEY idx_identity_verifications_created_at (created_at),
  CONSTRAINT fk_identity_verification_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
