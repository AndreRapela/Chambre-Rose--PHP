ALTER TABLE users ADD COLUMN approval_status VARCHAR(20) NOT NULL DEFAULT 'APPROVED';
ALTER TABLE users ADD COLUMN approval_reason VARCHAR(500) NULL;
ALTER TABLE users ADD COLUMN review_deadline DATETIME(3) NULL;
ALTER TABLE users ADD COLUMN approved_at DATETIME(3) NULL;
ALTER TABLE users ADD COLUMN locale VARCHAR(5) NOT NULL DEFAULT 'fr';

UPDATE users SET role = 'VISITOR' WHERE role = 'USER';
UPDATE users SET approval_status = 'APPROVED', approved_at = COALESCE(approved_at, created_at)
WHERE role IN ('ADMIN', 'VISITOR');

CREATE INDEX idx_users_approval_status ON users (approval_status, created_at);

CREATE TABLE professional_profiles (
  user_id BIGINT UNSIGNED NOT NULL,
  profile_type VARCHAR(20) NOT NULL,
  display_name VARCHAR(120) NOT NULL,
  birth_date DATE NULL,
  gender VARCHAR(40) NULL,
  location VARCHAR(160) NULL,
  bio TEXT NULL,
  languages TEXT NULL,
  height_cm SMALLINT UNSIGNED NULL,
  hair VARCHAR(60) NULL,
  eyes VARCHAR(60) NULL,
  services TEXT NULL,
  availability VARCHAR(500) NULL,
  website VARCHAR(300) NULL,
  price_from DECIMAL(10,2) NULL,
  price_to DECIMAL(10,2) NULL,
  business_name VARCHAR(160) NULL,
  legal_name VARCHAR(160) NULL,
  segment VARCHAR(100) NULL,
  business_address VARCHAR(200) NULL,
  business_hours VARCHAR(500) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (user_id),
  KEY idx_professional_profiles_type (profile_type, display_name),
  CONSTRAINT fk_professional_profiles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE profile_media (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  media_type VARCHAR(10) NOT NULL,
  file_name VARCHAR(255) NOT NULL,
  content_type VARCHAR(120) NOT NULL,
  size_bytes BIGINT UNSIGNED NOT NULL,
  media_data LONGBLOB NOT NULL,
  position SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY idx_profile_media_user (user_id, media_type, position, id),
  CONSTRAINT fk_profile_media_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE favorites (
  user_id BIGINT UNSIGNED NOT NULL,
  profile_user_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (user_id, profile_user_id),
  CONSTRAINT fk_favorites_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_favorites_profile FOREIGN KEY (profile_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE conversations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  participant_one_id BIGINT UNSIGNED NOT NULL,
  participant_two_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uk_conversation_pair (participant_one_id, participant_two_id),
  KEY idx_conversations_updated (updated_at),
  CONSTRAINT fk_conversations_one FOREIGN KEY (participant_one_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_conversations_two FOREIGN KEY (participant_two_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE conversation_members (
  conversation_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  archived_at DATETIME(3) NULL,
  last_read_at DATETIME(3) NULL,
  PRIMARY KEY (conversation_id, user_id),
  KEY idx_conversation_members_user (user_id, archived_at),
  CONSTRAINT fk_conversation_members_conversation FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
  CONSTRAINT fk_conversation_members_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE messages (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  conversation_id BIGINT UNSIGNED NOT NULL,
  sender_id BIGINT UNSIGNED NOT NULL,
  body TEXT NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY idx_messages_conversation (conversation_id, id),
  CONSTRAINT fk_messages_conversation FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
  CONSTRAINT fk_messages_sender FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE blocked_users (
  blocker_id BIGINT UNSIGNED NOT NULL,
  blocked_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (blocker_id, blocked_id),
  CONSTRAINT fk_blocked_users_blocker FOREIGN KEY (blocker_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_blocked_users_blocked FOREIGN KEY (blocked_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_reports (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  reporter_id BIGINT UNSIGNED NOT NULL,
  reported_id BIGINT UNSIGNED NOT NULL,
  reason VARCHAR(80) NOT NULL,
  details VARCHAR(1000) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'OPEN',
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY idx_user_reports_status (status, created_at),
  CONSTRAINT fk_user_reports_reporter FOREIGN KEY (reporter_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_user_reports_reported FOREIGN KEY (reported_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE password_reset_tokens (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME(3) NOT NULL,
  used_at DATETIME(3) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uk_password_reset_token_hash (token_hash),
  KEY idx_password_reset_user (user_id, created_at),
  CONSTRAINT fk_password_reset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE email_outbox (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  recipient VARCHAR(160) NOT NULL,
  template VARCHAR(60) NOT NULL,
  locale VARCHAR(5) NOT NULL,
  subject VARCHAR(255) NOT NULL,
  body TEXT NOT NULL,
  delivery_status VARCHAR(20) NOT NULL DEFAULT 'PENDING',
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  sent_at DATETIME(3) NULL,
  PRIMARY KEY (id),
  KEY idx_email_outbox_status (delivery_status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
