CREATE TABLE products (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code VARCHAR(80) NOT NULL,
  name VARCHAR(120) NOT NULL,
  description VARCHAR(255) NULL,
  is_public TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 100,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_products_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  first_name VARCHAR(100) NOT NULL,
  last_name VARCHAR(100) NOT NULL,
  email VARCHAR(190) NOT NULL,
  organization_id BIGINT UNSIGNED NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  is_system_admin TINYINT(1) NOT NULL DEFAULT 0,
  last_login_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_organization (organization_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE organizations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(160) NOT NULL,
  slug VARCHAR(100) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_organizations_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO organizations (name, slug, is_active)
VALUES ('Genérica', 'generica', 1)
ON DUPLICATE KEY UPDATE name = VALUES(name), is_active = VALUES(is_active);

CREATE TABLE roles (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id BIGINT UNSIGNED NOT NULL,
  code VARCHAR(50) NOT NULL,
  name VARCHAR(100) NOT NULL,
  description VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_roles_product_code (product_id, code),
  CONSTRAINT fk_roles_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_product_roles (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  organization_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  role_id BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_user_org_product_roles (user_id, organization_id, product_id),
  KEY idx_user_product_roles_org (organization_id),
  KEY idx_user_product_roles_role_id (role_id),
  CONSTRAINT fk_user_product_roles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_user_product_roles_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_user_product_roles_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  CONSTRAINT fk_user_product_roles_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE product_coin_wallets (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  balance INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_product_coin_wallets_user_product (user_id, product_id),
  KEY idx_product_coin_wallets_product (product_id),
  CONSTRAINT fk_product_coin_wallets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_product_coin_wallets_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE product_coin_ledger (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  amount INT NOT NULL,
  balance_after INT NOT NULL,
  movement_type VARCHAR(40) NOT NULL,
  source_type VARCHAR(80) NULL,
  source_id BIGINT UNSIGNED NULL,
  description VARCHAR(255) NOT NULL DEFAULT '',
  metadata_json JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_product_coin_ledger_user_product_time (user_id, product_id, created_at),
  KEY idx_product_coin_ledger_actor_time (actor_user_id, created_at),
  KEY idx_product_coin_ledger_source (source_type, source_id),
  CONSTRAINT fk_product_coin_ledger_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_product_coin_ledger_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  CONSTRAINT fk_product_coin_ledger_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE auth_sessions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  refresh_token_hash VARCHAR(255) NOT NULL,
  user_agent VARCHAR(255) NULL,
  ip_address VARCHAR(64) NULL,
  expires_at TIMESTAMP NOT NULL,
  revoked_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_auth_sessions_user_id (user_id),
  CONSTRAINT fk_auth_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE analysis_engines (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id BIGINT UNSIGNED NOT NULL,
  code VARCHAR(100) NOT NULL,
  name VARCHAR(160) NOT NULL,
  description VARCHAR(255) NULL,
  endpoint_url VARCHAR(255) NULL,
  output_contract_version VARCHAR(40) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_analysis_engines_product_code (product_id, code),
  CONSTRAINT fk_analysis_engines_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE analysis_jobs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  analysis_engine_id BIGINT UNSIGNED NULL,
  source_job_type VARCHAR(80) NULL,
  source_job_id BIGINT UNSIGNED NULL,
  input_title VARCHAR(190) NOT NULL DEFAULT '',
  input_description VARCHAR(255) NOT NULL DEFAULT '',
  input_filename VARCHAR(255) NULL,
  input_mime_type VARCHAR(120) NULL,
  input_size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
  status VARCHAR(40) NOT NULL DEFAULT 'pending',
  request_payload_json JSON NULL,
  response_payload_json JSON NULL,
  error_message TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  processed_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_analysis_jobs_source (source_job_type, source_job_id),
  KEY idx_analysis_jobs_user_time (user_id, created_at),
  KEY idx_analysis_jobs_product_time (product_id, created_at),
  KEY idx_analysis_jobs_engine_time (analysis_engine_id, created_at),
  KEY idx_analysis_jobs_status (status),
  CONSTRAINT fk_analysis_jobs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_analysis_jobs_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  CONSTRAINT fk_analysis_jobs_engine FOREIGN KEY (analysis_engine_id) REFERENCES analysis_engines(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE analysis_metrics (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  analysis_job_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  metric_group_key VARCHAR(80) NOT NULL,
  metric_group_label VARCHAR(160) NOT NULL,
  metric_key VARCHAR(120) NOT NULL,
  metric_label VARCHAR(190) NOT NULL,
  metric_value_text TEXT NULL,
  metric_value_number DOUBLE NULL,
  unit VARCHAR(40) NOT NULL DEFAULT '',
  source_path VARCHAR(255) NOT NULL DEFAULT '',
  description TEXT NULL,
  captured_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_analysis_metrics_job_key (analysis_job_id, metric_key),
  KEY idx_analysis_metrics_user_time (user_id, captured_at),
  KEY idx_analysis_metrics_product_key_time (product_id, metric_key, captured_at),
  CONSTRAINT fk_analysis_metrics_job FOREIGN KEY (analysis_job_id) REFERENCES analysis_jobs(id) ON DELETE CASCADE,
  CONSTRAINT fk_analysis_metrics_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_analysis_metrics_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE analysis_feature_snapshots (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  analysis_job_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  features_json JSON NOT NULL,
  numeric_features_json JSON NULL,
  feature_labels_json JSON NULL,
  feature_units_json JSON NULL,
  captured_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_analysis_feature_snapshots_job (analysis_job_id),
  KEY idx_analysis_feature_snapshots_user_time (user_id, captured_at),
  KEY idx_analysis_feature_snapshots_product_time (product_id, captured_at),
  CONSTRAINT fk_analysis_feature_snapshots_job FOREIGN KEY (analysis_job_id) REFERENCES analysis_jobs(id) ON DELETE CASCADE,
  CONSTRAINT fk_analysis_feature_snapshots_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_analysis_feature_snapshots_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE analysis_artifacts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  analysis_job_id BIGINT UNSIGNED NOT NULL,
  artifact_type VARCHAR(80) NOT NULL,
  title VARCHAR(190) NOT NULL DEFAULT '',
  media_type VARCHAR(120) NULL,
  storage_path VARCHAR(255) NULL,
  public_url VARCHAR(255) NULL,
  metadata_json JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_analysis_artifacts_job_type (analysis_job_id, artifact_type),
  CONSTRAINT fk_analysis_artifacts_job FOREIGN KEY (analysis_job_id) REFERENCES analysis_jobs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audio_jobs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  organization_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  original_filename VARCHAR(255) NOT NULL,
  audio_description VARCHAR(50) NOT NULL DEFAULT '',
  mime_type VARCHAR(120) NULL,
  audio_size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
  audio_path VARCHAR(255) NOT NULL,
  audio_url VARCHAR(255) NOT NULL,
  scalogram_path VARCHAR(255) NULL,
  scalogram_url VARCHAR(255) NULL,
  status VARCHAR(40) NOT NULL DEFAULT 'pending',
  error_message TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  processed_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_audio_jobs_user_id (user_id),
  KEY idx_audio_jobs_org_time (organization_id, created_at),
  KEY idx_audio_jobs_product_id (product_id),
  KEY idx_audio_jobs_status (status),
  CONSTRAINT fk_audio_jobs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_audio_jobs_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_audio_jobs_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audio_job_metrics (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  audio_job_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  metric_group_key VARCHAR(80) NOT NULL,
  metric_group_label VARCHAR(160) NOT NULL,
  metric_key VARCHAR(120) NOT NULL,
  metric_label VARCHAR(190) NOT NULL,
  metric_value_text TEXT NULL,
  metric_value_number DOUBLE NULL,
  unit VARCHAR(40) NOT NULL DEFAULT '',
  source_path VARCHAR(255) NOT NULL DEFAULT '',
  description TEXT NULL,
  captured_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_audio_job_metrics_job_key (audio_job_id, metric_key),
  KEY idx_audio_job_metrics_user_time (user_id, captured_at),
  KEY idx_audio_job_metrics_user_key_time (user_id, metric_key, captured_at),
  KEY idx_audio_job_metrics_product_key_time (product_id, metric_key, captured_at),
  CONSTRAINT fk_audio_job_metrics_job FOREIGN KEY (audio_job_id) REFERENCES audio_jobs(id) ON DELETE CASCADE,
  CONSTRAINT fk_audio_job_metrics_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_audio_job_metrics_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audio_job_feature_snapshots (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  audio_job_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  features_json JSON NOT NULL,
  numeric_features_json JSON NULL,
  feature_labels_json JSON NULL,
  feature_units_json JSON NULL,
  captured_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_audio_job_feature_snapshots_job (audio_job_id),
  KEY idx_audio_job_feature_snapshots_user_time (user_id, captured_at),
  KEY idx_audio_job_feature_snapshots_product_time (product_id, captured_at),
  CONSTRAINT fk_audio_job_feature_snapshots_job FOREIGN KEY (audio_job_id) REFERENCES audio_jobs(id) ON DELETE CASCADE,
  CONSTRAINT fk_audio_job_feature_snapshots_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_audio_job_feature_snapshots_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE smart_tales_child_profiles (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  display_name VARCHAR(120) NOT NULL,
  age_years TINYINT UNSIGNED NULL,
  language_code VARCHAR(10) NOT NULL DEFAULT 'es',
  preferences_json JSON NULL,
  bedtime_context TEXT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_smart_tales_child_profiles_user_id (user_id),
  CONSTRAINT fk_smart_tales_child_profiles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE smart_tales_voice_profiles (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  child_profile_id BIGINT UNSIGNED NULL,
  label VARCHAR(120) NOT NULL,
  source_relation VARCHAR(80) NULL,
  provider VARCHAR(80) NOT NULL,
  provider_voice_id VARCHAR(190) NULL,
  consent_status VARCHAR(40) NOT NULL DEFAULT 'pending',
  sample_audio_path VARCHAR(255) NULL,
  sample_audio_url VARCHAR(255) NULL,
  sample_mime_type VARCHAR(120) NULL,
  sample_size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
  status VARCHAR(40) NOT NULL DEFAULT 'pending',
  metadata_json JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_smart_tales_voice_profiles_user_id (user_id),
  KEY idx_smart_tales_voice_profiles_child_id (child_profile_id),
  KEY idx_smart_tales_voice_profiles_provider_voice_id (provider_voice_id),
  CONSTRAINT fk_smart_tales_voice_profiles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_smart_tales_voice_profiles_child FOREIGN KEY (child_profile_id) REFERENCES smart_tales_child_profiles(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE smart_tales_story_requests (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  child_profile_id BIGINT UNSIGNED NOT NULL,
  voice_profile_id BIGINT UNSIGNED NULL,
  theme VARCHAR(120) NOT NULL,
  tone VARCHAR(80) NOT NULL DEFAULT 'calm',
  target_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 5,
  language_code VARCHAR(10) NOT NULL DEFAULT 'es',
  prompt_context TEXT NULL,
  generation_status VARCHAR(40) NOT NULL DEFAULT 'pending',
  llm_provider VARCHAR(80) NULL,
  tts_provider VARCHAR(80) NULL,
  story_title VARCHAR(190) NULL,
  story_text MEDIUMTEXT NULL,
  audio_path VARCHAR(255) NULL,
  audio_url VARCHAR(255) NULL,
  cover_image_url VARCHAR(255) NULL,
  provider_request_id VARCHAR(190) NULL,
  error_message TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  completed_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_smart_tales_story_requests_user_id (user_id),
  KEY idx_smart_tales_story_requests_child_id (child_profile_id),
  KEY idx_smart_tales_story_requests_voice_id (voice_profile_id),
  KEY idx_smart_tales_story_requests_status (generation_status),
  CONSTRAINT fk_smart_tales_story_requests_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_smart_tales_story_requests_child FOREIGN KEY (child_profile_id) REFERENCES smart_tales_child_profiles(id) ON DELETE CASCADE,
  CONSTRAINT fk_smart_tales_story_requests_voice FOREIGN KEY (voice_profile_id) REFERENCES smart_tales_voice_profiles(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audiometer_tests (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  organization_id BIGINT UNSIGNED NOT NULL,
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
  KEY idx_audiometer_tests_org_time (organization_id, created_at),
  KEY idx_audiometer_tests_product_time (product_id, created_at),
  CONSTRAINT fk_audiometer_tests_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_audiometer_tests_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_audiometer_tests_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO products (code, name, description, is_public, is_active, sort_order)
VALUES
  ('audioprint', 'Audioprint', 'Solucion para subir audios y generar su analisis.', 1, 1, 10),
  ('audiometer', 'Audiometer', 'Screening auditivo orientativo con tonos puros, audiograma relativo e historial de pruebas.', 1, 1, 15),
  ('qvoice', 'Qvoice', 'Solucion orientada al seguimiento de la voz humana en entornos laborales.', 1, 1, 20),
  ('smart_tales', 'Smart Tales', 'Cuentos personalizados con voces familiares, perfiles infantiles e historial narrativo.', 1, 1, 30)
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  description = VALUES(description),
  is_public = VALUES(is_public),
  is_active = VALUES(is_active),
  sort_order = VALUES(sort_order);

INSERT INTO roles (product_id, code, name, description)
SELECT p.id, seed.code, seed.name, seed.description
FROM products p
JOIN (
  SELECT 'audioprint' AS product_code, 'admin' AS code, 'Admin' AS name, 'Gestion del producto, usuarios e historial.' AS description
  UNION ALL
  SELECT 'audioprint', 'user', 'User', 'Uso normal del producto y gestión de sus propios audios.'
  UNION ALL
  SELECT 'audiometer', 'admin', 'Admin', 'Gestion del producto, usuarios e historial.'
  UNION ALL
  SELECT 'audiometer', 'user', 'User', 'Uso normal del screening auditivo y gestión de sus propias pruebas.'
  UNION ALL
  SELECT 'qvoice', 'admin', 'Admin', 'Gestion de accesos y configuracion inicial de la solucion.'
  UNION ALL
  SELECT 'qvoice', 'user', 'User', 'Acceso al espacio funcional de Qvoice y a sus futuros modulos.'
  UNION ALL
  SELECT 'smart_tales', 'admin', 'Admin', 'Gestion de perfiles infantiles, voces, configuracion y supervision del producto.'
  UNION ALL
  SELECT 'smart_tales', 'user', 'User', 'Uso normal del producto para crear cuentos, perfiles y voces autorizadas.'
) AS seed
  ON seed.product_code = p.code
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  description = VALUES(description);
