CREATE TABLE IF NOT EXISTS site_promotions (
  slot SMALLINT PRIMARY KEY,
  label VARCHAR(80) NOT NULL,
  title VARCHAR(160) NOT NULL,
  subtitle VARCHAR(400) NOT NULL,
  link_url VARCHAR(500) NOT NULL,
  link_text VARCHAR(100) NOT NULL,
  icon VARCHAR(40) NOT NULL,
  image_path VARCHAR(500),
  image_file_name VARCHAR(255),
  image_content_type VARCHAR(80),
  image_size_bytes INTEGER,
  image_data BYTEA,
  updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CHECK (slot IN (1, 2))
);

INSERT INTO site_promotions (
  slot,label,title,subtitle,link_url,link_text,icon,image_path,updated_at
) VALUES
  (1,'Gift edit','Gift-ready lingerie edits','Curated sets with discreet packaging and refined finishing.','/catalogue?category=lingerie','Explore gifts','gift','/assets/carousel-pink-lace-tie.jpeg',CURRENT_TIMESTAMP),
  (2,'Private delivery','Private delivery, boutique care','Soft service details inspired by premium catalog shopping.','/catalogue/lojas','Contact boutique','truck','/assets/carousel-pink-ring-thong.jpeg',CURRENT_TIMESTAMP)
ON CONFLICT (slot) DO NOTHING;
