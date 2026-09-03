ALTER TABLE professional_profiles ADD COLUMN weight_kg SMALLINT UNSIGNED NULL;
ALTER TABLE professional_profiles ADD COLUMN bust_cm SMALLINT UNSIGNED NULL;
ALTER TABLE professional_profiles ADD COLUMN waist_cm SMALLINT UNSIGNED NULL;
ALTER TABLE professional_profiles ADD COLUMN hips_cm SMALLINT UNSIGNED NULL;
ALTER TABLE professional_profiles ADD COLUMN origin VARCHAR(80) NULL;
ALTER TABLE professional_profiles ADD COLUMN interests TEXT NULL;
ALTER TABLE professional_profiles ADD COLUMN contact_options TEXT NULL;
ALTER TABLE professional_profiles ADD COLUMN purchase_count INT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE professional_profiles ADD COLUMN views_count INT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE professional_profiles ADD COLUMN verified BOOLEAN NOT NULL DEFAULT FALSE;

CREATE TABLE IF NOT EXISTS products (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  store_user_id BIGINT UNSIGNED NULL,
  name VARCHAR(120) NOT NULL,
  category VARCHAR(60) NOT NULL,
  price DECIMAL(10,2) NOT NULL,
  original_price DECIMAL(10,2) NULL,
  image_url VARCHAR(500) NOT NULL,
  secondary_image_url VARCHAR(500) NULL,
  tag VARCHAR(40) NULL,
  sale_label VARCHAR(40) NULL,
  reviews INT NOT NULL DEFAULT 0,
  purchase_count INT NOT NULL DEFAULT 0,
  likes INT NOT NULL DEFAULT 0,
  description TEXT NULL,
  store_name VARCHAR(120) NULL,
  store_address VARCHAR(160) NULL,
  store_city VARCHAR(80) NULL,
  store_segment VARCHAR(80) NULL,
  store_hours VARCHAR(120) NULL,
  product_type VARCHAR(80) NULL,
  material VARCHAR(160) NULL,
  available_sizes VARCHAR(120) NULL,
  color_options VARCHAR(120) NULL,
  stock_status VARCHAR(80) NULL,
  shipping_note VARCHAR(160) NULL,
  care_instructions VARCHAR(160) NULL,
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY idx_products_category (category, is_active),
  KEY idx_products_store (store_user_id, is_active),
  CONSTRAINT fk_products_store FOREIGN KEY (store_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE products ADD COLUMN store_user_id BIGINT UNSIGNED NULL;
ALTER TABLE products ADD COLUMN is_active BOOLEAN NOT NULL DEFAULT TRUE;
ALTER TABLE products ADD CONSTRAINT fk_products_store FOREIGN KEY (store_user_id) REFERENCES users(id) ON DELETE SET NULL;
CREATE INDEX idx_products_store ON products (store_user_id, is_active);

CREATE TABLE IF NOT EXISTS product_images (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id BIGINT UNSIGNED NOT NULL,
  role VARCHAR(20) NOT NULL,
  file_name VARCHAR(255) NOT NULL,
  content_type VARCHAR(120) NOT NULL,
  size_bytes BIGINT UNSIGNED NOT NULL,
  image_data LONGBLOB NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uk_product_images_product_role (product_id, role),
  CONSTRAINT fk_product_images_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS marketplace_orders (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  buyer_user_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NULL,
  profile_user_id BIGINT UNSIGNED NULL,
  order_type VARCHAR(20) NOT NULL,
  amount DECIMAL(10,2) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'COMPLETED',
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY idx_orders_buyer (buyer_user_id, created_at),
  KEY idx_orders_product (product_id, status),
  KEY idx_orders_profile (profile_user_id, status),
  CONSTRAINT fk_orders_buyer FOREIGN KEY (buyer_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_orders_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL,
  CONSTRAINT fk_orders_profile FOREIGN KEY (profile_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS profile_reviews (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  profile_user_id BIGINT UNSIGNED NOT NULL,
  reviewer_name VARCHAR(80) NOT NULL,
  body VARCHAR(500) NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY idx_profile_reviews_profile (profile_user_id, created_at),
  CONSTRAINT fk_profile_reviews_profile FOREIGN KEY (profile_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
