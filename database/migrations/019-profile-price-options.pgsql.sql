ALTER TABLE professional_profiles ADD COLUMN IF NOT EXISTS price_hour DECIMAL(10,2);
ALTER TABLE professional_profiles ADD COLUMN IF NOT EXISTS price_night DECIMAL(10,2);
ALTER TABLE professional_profiles ADD COLUMN IF NOT EXISTS price_weekend DECIMAL(10,2);
UPDATE professional_profiles SET price_hour = price_from WHERE price_hour IS NULL AND price_from IS NOT NULL;
