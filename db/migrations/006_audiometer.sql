INSERT INTO products (code, name, description, is_public, is_active, sort_order)
VALUES ('audiometer', 'Audiometer', 'Screening auditivo orientativo con tonos puros, audiograma relativo e historial de pruebas.', 1, 1, 15)
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
  SELECT 'admin' AS code, 'Admin' AS name, 'Gestion del producto, usuarios e historial.' AS description
  UNION ALL
  SELECT 'user', 'User', 'Uso normal del screening auditivo y gestion de sus propias pruebas.'
) role_seed
WHERE p.code = 'audiometer'
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  description = VALUES(description);

CREATE TABLE IF NOT EXISTS audiometer_tests (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  test_title VARCHAR(190) NOT NULL DEFAULT '',
  device_label VARCHAR(190) NOT NULL DEFAULT '',
  headphone_label VARCHAR(190) NOT NULL DEFAULT '',
  volume_label VARCHAR(120) NOT NULL DEFAULT '',
  environment_label VARCHAR(190) NOT NULL DEFAULT '',
  calibration_note TEXT NULL,
  result_payload_json JSON NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audiometer_tests_user_time (user_id, created_at),
  KEY idx_audiometer_tests_product_time (product_id, created_at),
  CONSTRAINT fk_audiometer_tests_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_audiometer_tests_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
