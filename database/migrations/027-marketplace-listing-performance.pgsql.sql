CREATE INDEX IF NOT EXISTS idx_profiles_marketplace_type_updated
  ON professional_profiles (profile_type, updated_at DESC, user_id DESC);

CREATE INDEX IF NOT EXISTS idx_users_marketplace_visibility
  ON users (approval_status, role, vip_active DESC, id DESC);
