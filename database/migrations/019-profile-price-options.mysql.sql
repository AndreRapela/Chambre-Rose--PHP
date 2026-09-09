ALTER TABLE professional_profiles ADD COLUMN price_hour DECIMAL(10,2) NULL AFTER price_to;
ALTER TABLE professional_profiles ADD COLUMN price_night DECIMAL(10,2) NULL AFTER price_hour;
ALTER TABLE professional_profiles ADD COLUMN price_weekend DECIMAL(10,2) NULL AFTER price_night;
UPDATE professional_profiles SET price_hour = price_from WHERE price_hour IS NULL AND price_from IS NOT NULL;
