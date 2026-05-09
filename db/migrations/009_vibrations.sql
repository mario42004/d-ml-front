INSERT INTO products (code, name, description, is_public, is_active, sort_order)
VALUES ('vibrations', 'Vibrations', 'Análisis de acelerómetro y giroscopio para seguimiento de vibraciones y cambios anómalos.', 1, 1, 12)
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  description = VALUES(description),
  is_public = VALUES(is_public),
  is_active = VALUES(is_active),
  sort_order = VALUES(sort_order);

INSERT INTO roles (product_id, code, name, description)
SELECT p.id, role_seed.code, role_seed.name, role_seed.description
FROM products p
INNER JOIN (
  SELECT 'admin' AS code, 'Admin' AS name, 'Gestión del producto, usuarios e historial.' AS description
  UNION ALL
  SELECT 'user', 'User', 'Carga de archivos DATS y revisión de sus propios análisis.'
) role_seed
WHERE p.code = 'vibrations'
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  description = VALUES(description);

CREATE TABLE IF NOT EXISTS vibration_phenomena (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  organization_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(190) NOT NULL,
  external_id VARCHAR(120) NOT NULL DEFAULT '',
  description VARCHAR(255) NOT NULL DEFAULT '',
  baseline_job_id BIGINT UNSIGNED NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_vibration_phenomena_org_user_external (organization_id, user_id, external_id),
  KEY idx_vibration_phenomena_user_time (user_id, created_at),
  KEY idx_vibration_phenomena_org_product (organization_id, product_id),
  CONSTRAINT fk_vibration_phenomena_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_vibration_phenomena_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_vibration_phenomena_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vibration_jobs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  organization_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  phenomenon_id BIGINT UNSIGNED NULL,
  original_filename VARCHAR(255) NOT NULL,
  phenomenon_label VARCHAR(190) NOT NULL DEFAULT '',
  external_id VARCHAR(120) NOT NULL DEFAULT '',
  baseline_scope VARCHAR(240) NOT NULL DEFAULT '',
  is_baseline TINYINT(1) NOT NULL DEFAULT 0,
  baseline_job_id BIGINT UNSIGNED NULL,
  baseline_distance_score DOUBLE NULL,
  baseline_summary_json JSON NULL,
  mime_type VARCHAR(120) NULL,
  dat_size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
  dat_path VARCHAR(255) NOT NULL,
  dat_url VARCHAR(255) NOT NULL,
  analysis_path VARCHAR(255) NULL,
  analysis_url VARCHAR(255) NULL,
  window_ms INT NOT NULL DEFAULT 500,
  status VARCHAR(40) NOT NULL DEFAULT 'pending',
  error_message TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  processed_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_vibration_jobs_user_time (user_id, created_at),
  KEY idx_vibration_jobs_phenomenon_time (phenomenon_id, created_at),
  KEY idx_vibration_jobs_org_time (organization_id, created_at),
  KEY idx_vibration_jobs_baseline_scope (organization_id, baseline_scope, is_baseline),
  KEY idx_vibration_jobs_product_time (product_id, created_at),
  KEY idx_vibration_jobs_status (status),
  CONSTRAINT fk_vibration_jobs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_vibration_jobs_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_vibration_jobs_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  CONSTRAINT fk_vibration_jobs_phenomenon FOREIGN KEY (phenomenon_id) REFERENCES vibration_phenomena(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
