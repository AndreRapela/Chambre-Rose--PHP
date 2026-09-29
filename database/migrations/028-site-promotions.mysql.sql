CREATE TABLE IF NOT EXISTS site_promotions (
  slot TINYINT UNSIGNED NOT NULL,
  label VARCHAR(80) NOT NULL,
  title VARCHAR(160) NOT NULL,
  subtitle VARCHAR(400) NOT NULL,
  link_url VARCHAR(500) NOT NULL,
  link_text VARCHAR(100) NOT NULL,
  icon VARCHAR(40) NOT NULL,
  image_path VARCHAR(500) NULL,
  image_file_name VARCHAR(255) NULL,
  image_content_type VARCHAR(80) NULL,
  image_size_bytes INT UNSIGNED NULL,
  image_data LONGBLOB NULL,
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (slot),
  CONSTRAINT chk_site_promotions_slot CHECK (slot IN (1, 2))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO site_promotions (
  slot,label,title,subtitle,link_url,link_text,icon,image_path,updated_at
) VALUES
  (1,'Gift edit','Gift-ready lingerie edits','Curated sets with discreet packaging and refined finishing.','/catalogue?category=lingerie','Explore gifts','gift','/assets/carousel-pink-lace-tie.jpeg',CURRENT_TIMESTAMP(3)),
  (2,'Private delivery','Private delivery, boutique care','Soft service details inspired by premium catalog shopping.','/catalogue/lojas','Contact boutique','truck','/assets/carousel-pink-ring-thong.jpeg',CURRENT_TIMESTAMP(3))
ON DUPLICATE KEY UPDATE slot=VALUES(slot);
