ALTER TABLE users
  ADD COLUMN account_status VARCHAR(20) NOT NULL DEFAULT 'APPROVED' AFTER role;

ALTER TABLE users
  MODIFY account_status VARCHAR(20) NOT NULL DEFAULT 'PENDING';

CREATE INDEX idx_users_account_status_created_at
  ON users (account_status, created_at);
