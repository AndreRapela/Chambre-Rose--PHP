ALTER TABLE professional_profiles ADD COLUMN contact_email VARCHAR(160) NULL;
ALTER TABLE professional_profiles ADD COLUMN response_time VARCHAR(40) NULL;

ALTER TABLE profile_reviews ADD COLUMN reviewer_user_id BIGINT UNSIGNED NULL;
ALTER TABLE profile_reviews ADD COLUMN rating TINYINT UNSIGNED NOT NULL DEFAULT 5;
ALTER TABLE profile_reviews ADD CONSTRAINT fk_profile_reviews_reviewer FOREIGN KEY (reviewer_user_id) REFERENCES users(id) ON DELETE SET NULL;
CREATE UNIQUE INDEX uk_profile_reviews_profile_reviewer ON profile_reviews (profile_user_id, reviewer_user_id);
