CREATE TABLE IF NOT EXISTS profile_card_covers (
  user_id BIGINT UNSIGNED NOT NULL,
  width SMALLINT UNSIGNED NOT NULL,
  height SMALLINT UNSIGNED NOT NULL,
  image_data LONGBLOB NOT NULL,
  source_hash CHAR(64) NOT NULL,
  PRIMARY KEY (user_id, width),
  CONSTRAINT fk_card_cover_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
