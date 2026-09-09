ALTER TABLE professional_profiles ADD COLUMN location_city VARCHAR(80) NULL AFTER location;
ALTER TABLE professional_profiles ADD COLUMN location_region VARCHAR(100) NULL AFTER location_city;
ALTER TABLE professional_profiles ADD COLUMN location_country VARCHAR(80) NULL AFTER location_region;
CREATE INDEX idx_profiles_location_city_country ON professional_profiles (location_city, location_country);
CREATE INDEX idx_profiles_location_region_country ON professional_profiles (location_region, location_country);
UPDATE professional_profiles p
JOIN users u ON u.id = p.user_id
SET p.location_city = COALESCE(NULLIF(p.location_city, ''), NULLIF(TRIM(SUBSTRING_INDEX(p.location, ',', 1)), ''), NULLIF(u.city, '')),
    p.location_country = COALESCE(NULLIF(p.location_country, ''), CASE WHEN p.location LIKE '%,%' THEN NULLIF(TRIM(SUBSTRING_INDEX(p.location, ',', -1)), '') ELSE NULL END, NULLIF(u.country, ''))
WHERE p.location_city IS NULL OR p.location_country IS NULL;
