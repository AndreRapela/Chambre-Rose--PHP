CREATE TABLE IF NOT EXISTS profile_card_covers (
  user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  width SMALLINT NOT NULL,
  height SMALLINT NOT NULL,
  image_data BYTEA NOT NULL,
  source_hash CHAR(64) NOT NULL,
  PRIMARY KEY (user_id, width)
);
