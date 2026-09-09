ALTER TABLE professional_profiles ADD COLUMN IF NOT EXISTS location_city VARCHAR(80);
ALTER TABLE professional_profiles ADD COLUMN IF NOT EXISTS location_region VARCHAR(100);
ALTER TABLE professional_profiles ADD COLUMN IF NOT EXISTS location_country VARCHAR(80);
CREATE INDEX IF NOT EXISTS idx_profiles_location_city_country ON professional_profiles (location_city, location_country);
CREATE INDEX IF NOT EXISTS idx_profiles_location_region_country ON professional_profiles (location_region, location_country);
UPDATE professional_profiles p
SET location_city = COALESCE(NULLIF(p.location_city, ''), NULLIF(TRIM(SPLIT_PART(p.location, ',', 1)), ''), NULLIF(u.city, '')),
    location_country = COALESCE(NULLIF(p.location_country, ''), CASE WHEN POSITION(',' IN p.location) > 0 THEN NULLIF(TRIM(REGEXP_REPLACE(p.location, '^.*,', '')), '') ELSE NULL END, NULLIF(u.country, ''))
FROM users u
WHERE u.id = p.user_id AND (p.location_city IS NULL OR p.location_country IS NULL);
