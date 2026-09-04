ALTER TABLE professional_profiles ADD COLUMN IF NOT EXISTS contact_email VARCHAR(160) NULL;
ALTER TABLE professional_profiles ADD COLUMN IF NOT EXISTS response_time VARCHAR(40) NULL;

ALTER TABLE profile_reviews ADD COLUMN IF NOT EXISTS reviewer_user_id BIGINT NULL REFERENCES users(id) ON DELETE SET NULL;
ALTER TABLE profile_reviews ADD COLUMN IF NOT EXISTS rating SMALLINT NOT NULL DEFAULT 5;

DO $$
BEGIN
  ALTER TABLE profile_reviews ADD CONSTRAINT chk_profile_reviews_rating CHECK (rating BETWEEN 1 AND 5);
EXCEPTION
  WHEN duplicate_object THEN NULL;
END $$;

CREATE UNIQUE INDEX IF NOT EXISTS uk_profile_reviews_profile_reviewer
  ON profile_reviews (profile_user_id, reviewer_user_id)
  WHERE reviewer_user_id IS NOT NULL;
