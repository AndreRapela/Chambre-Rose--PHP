CREATE TABLE IF NOT EXISTS user_company_verifications (
  user_id BIGINT UNSIGNED NOT NULL,
  company_number VARCHAR(80) NOT NULL,
  registration_name VARCHAR(255) NOT NULL,
  registration_content_type VARCHAR(80) NOT NULL,
  registration_data LONGBLOB NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (user_id),
  KEY idx_company_verifications_created_at (created_at),
  CONSTRAINT fk_company_verification_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
