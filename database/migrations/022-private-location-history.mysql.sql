ALTER TABLE users ADD COLUMN region VARCHAR(100) NOT NULL DEFAULT '' AFTER city;

CREATE TABLE IF NOT EXISTS user_location_history (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  address VARCHAR(160) NOT NULL,
  city VARCHAR(80) NOT NULL,
  region VARCHAR(100) NOT NULL,
  country VARCHAR(80) NOT NULL,
  postal_code VARCHAR(20) NOT NULL,
  source VARCHAR(30) NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY idx_user_location_history_user_created (user_id, created_at),
  CONSTRAINT fk_user_location_history_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
