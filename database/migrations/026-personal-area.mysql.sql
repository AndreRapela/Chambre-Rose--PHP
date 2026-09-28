CREATE TABLE IF NOT EXISTS user_exclusions (
  owner_id BIGINT UNSIGNED NOT NULL,
  excluded_user_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (owner_id, excluded_user_id),
  KEY idx_user_exclusions_target (excluded_user_id, owner_id),
  CONSTRAINT fk_user_exclusions_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_user_exclusions_target FOREIGN KEY (excluded_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT chk_user_exclusions_distinct CHECK (owner_id <> excluded_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS escort_appointments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  escort_user_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(160) NOT NULL,
  client_name VARCHAR(120) NOT NULL,
  starts_at DATETIME(3) NOT NULL,
  ends_at DATETIME(3) NOT NULL,
  location VARCHAR(200) NULL,
  notes VARCHAR(2000) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'SCHEDULED',
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY idx_escort_appointments_owner_start (escort_user_id, starts_at, id),
  CONSTRAINT fk_escort_appointments_owner FOREIGN KEY (escort_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT chk_escort_appointments_dates CHECK (ends_at > starts_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
