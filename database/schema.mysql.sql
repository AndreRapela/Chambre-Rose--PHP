CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email VARCHAR(160) NOT NULL,
  password_hash VARCHAR(120) NOT NULL,
  first_name VARCHAR(80) NOT NULL,
  last_name VARCHAR(80) NOT NULL,
  phone VARCHAR(40) NOT NULL,
  address VARCHAR(160) NOT NULL,
  city VARCHAR(80) NOT NULL,
  country VARCHAR(80) NOT NULL,
  postal_code VARCHAR(20) NOT NULL,
  role VARCHAR(20) NOT NULL,
  vip_active BOOLEAN NOT NULL DEFAULT FALSE,
  vip_since TIMESTAMP(3) NULL,
  vip_until TIMESTAMP(3) NULL,
  created_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uk_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS products (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  category VARCHAR(60) NOT NULL,
  price DECIMAL(10,2) NOT NULL,
  original_price DECIMAL(10,2) NULL,
  image_url VARCHAR(500) NOT NULL,
  secondary_image_url VARCHAR(500) NULL,
  tag VARCHAR(40) NULL,
  sale_label VARCHAR(40) NULL,
  rating INT NULL,
  reviews INT NULL,
  purchase_count INT NULL DEFAULT 0,
  description TEXT NULL,
  store_name VARCHAR(120) NULL,
  store_address VARCHAR(160) NULL,
  store_city VARCHAR(80) NULL,
  store_segment VARCHAR(80) NULL,
  store_hours VARCHAR(80) NULL,
  product_type VARCHAR(80) NULL,
  material VARCHAR(160) NULL,
  available_sizes VARCHAR(120) NULL,
  color_options VARCHAR(120) NULL,
  stock_status VARCHAR(80) NULL,
  shipping_note VARCHAR(160) NULL,
  care_instructions VARCHAR(160) NULL,
  created_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY idx_products_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS product_images (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id BIGINT UNSIGNED NOT NULL,
  role VARCHAR(20) NOT NULL,
  file_name VARCHAR(255) NOT NULL,
  content_type VARCHAR(120) NOT NULL,
  size_bytes BIGINT NOT NULL,
  image_data LONGBLOB NOT NULL,
  created_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uk_product_images_product_role (product_id, role),
  CONSTRAINT fk_product_images_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_establishment_photos (
  user_id BIGINT UNSIGNED NOT NULL,
  file_name VARCHAR(255) NOT NULL,
  content_type VARCHAR(120) NOT NULL,
  size_bytes BIGINT NOT NULL,
  storage_key VARCHAR(255) NOT NULL,
  created_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (user_id),
  UNIQUE KEY uk_user_establishment_photos_storage_key (storage_key),
  CONSTRAINT fk_user_establishment_photos_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

