CREATE INDEX idx_profiles_marketplace_type_updated
  ON professional_profiles (profile_type, updated_at, user_id);

CREATE INDEX idx_users_marketplace_visibility
  ON users (approval_status, role, vip_active, id);
