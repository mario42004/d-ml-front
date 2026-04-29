from __future__ import annotations

from pathlib import Path

import pymysql


LEGACY_SUFFIX = "legacy_20260413"


def load_env(path: Path) -> dict[str, str]:
    env: dict[str, str] = {}
    for raw_line in path.read_text().splitlines():
        line = raw_line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        env[key] = value
    return env


def table_exists(cur, schema: str, table_name: str) -> bool:
    cur.execute(
        """
        SELECT COUNT(*) AS c
        FROM information_schema.tables
        WHERE table_schema = %s AND table_name = %s
        """,
        (schema, table_name),
    )
    return cur.fetchone()["c"] > 0


def execute(cur, sql: str) -> None:
    cur.execute(sql)


def execute_sql_file(cur, path: Path) -> None:
    for statement in path.read_text().split(";"):
        statement = statement.strip()
        if statement:
            cur.execute(statement)


def main() -> None:
    project_root = Path(__file__).resolve().parents[1]
    env = load_env(project_root / ".env")
    schema = env["DB_NAME"]

    connection = pymysql.connect(
        host=env["DB_HOST"],
        port=int(env["DB_PORT"]),
        user=env["DB_USER"],
        password=env["DB_PASSWORD"],
        database=schema,
        charset=env.get("DB_CHARSET", "utf8mb4"),
        autocommit=False,
        cursorclass=pymysql.cursors.DictCursor,
    )

    with connection:
        with connection.cursor() as cur:
            legacy_roles_exists = table_exists(cur, schema, "roles")
            new_products_exists = table_exists(cur, schema, "products")
            legacy_user_roles_exists = table_exists(cur, schema, "user_roles")

            if legacy_roles_exists and not new_products_exists:
                if not table_exists(cur, schema, f"roles_{LEGACY_SUFFIX}"):
                    execute(cur, f"RENAME TABLE roles TO roles_{LEGACY_SUFFIX}")
                if legacy_user_roles_exists and not table_exists(cur, schema, f"user_roles_{LEGACY_SUFFIX}"):
                    execute(cur, f"RENAME TABLE user_roles TO user_roles_{LEGACY_SUFFIX}")

            execute(
                cur,
                """
                CREATE TABLE IF NOT EXISTS products (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                """,
            )

            execute(
                cur,
                """
                CREATE TABLE IF NOT EXISTS roles (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                """,
            )

            execute(
                cur,
                """
                CREATE TABLE IF NOT EXISTS user_product_roles (
                  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                  user_id BIGINT UNSIGNED NOT NULL,
                  product_id BIGINT UNSIGNED NOT NULL,
                  role_id BIGINT UNSIGNED NOT NULL,
                  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                  PRIMARY KEY (id),
                  UNIQUE KEY uq_user_product_roles_user_product (user_id, product_id),
                  KEY idx_user_product_roles_role_id (role_id),
                  CONSTRAINT fk_user_product_roles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                  CONSTRAINT fk_user_product_roles_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
                  CONSTRAINT fk_user_product_roles_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                """,
            )

            execute(
                cur,
                """
                CREATE TABLE IF NOT EXISTS audio_jobs (
                  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                  user_id BIGINT UNSIGNED NOT NULL,
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
                  KEY idx_audio_jobs_product_id (product_id),
                  KEY idx_audio_jobs_status (status),
                  CONSTRAINT fk_audio_jobs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                  CONSTRAINT fk_audio_jobs_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                """,
            )

            cur.execute("SHOW COLUMNS FROM audio_jobs LIKE 'audio_description'")
            if cur.fetchone() is None:
                execute(
                    cur,
                    "ALTER TABLE audio_jobs ADD COLUMN audio_description VARCHAR(50) NOT NULL DEFAULT '' AFTER original_filename",
                )

            execute(
                cur,
                """
                CREATE TABLE IF NOT EXISTS audio_job_metrics (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                """,
            )

            execute(
                cur,
                """
                CREATE TABLE IF NOT EXISTS audio_job_feature_snapshots (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                """,
            )

            execute_sql_file(cur, project_root / "db" / "migrations" / "005_analysis_platform.sql")

            execute(
                cur,
                """
                CREATE TABLE IF NOT EXISTS smart_tales_child_profiles (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                """,
            )

            execute(
                cur,
                """
                CREATE TABLE IF NOT EXISTS smart_tales_voice_profiles (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                """,
            )

            execute(
                cur,
                """
                CREATE TABLE IF NOT EXISTS smart_tales_story_requests (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                """,
            )

            execute(
                cur,
                """
                INSERT INTO products (code, name, description, is_public, is_active, sort_order)
                VALUES
                  ('audioprint', 'Audioprint', 'Solucion para subir audios y generar su analisis.', 1, 1, 10),
                  ('qvoice', 'Qvoice', 'Solucion orientada al seguimiento de la voz humana en entornos laborales.', 1, 1, 20),
                  ('smart_tales', 'Smart Tales', 'Cuentos personalizados con voces familiares, perfiles infantiles e historial narrativo.', 1, 1, 30)
                ON DUPLICATE KEY UPDATE
                  name = VALUES(name),
                  description = VALUES(description),
                  is_public = VALUES(is_public),
                  is_active = VALUES(is_active),
                  sort_order = VALUES(sort_order)
                """,
            )

            execute(
                cur,
                """
                INSERT INTO roles (product_id, code, name, description)
                SELECT p.id, seed.code, seed.name, seed.description
                FROM products p
                JOIN (
                  SELECT 'audioprint' AS product_code, 'admin' AS code, 'Admin' AS name, 'Gestion de la solucion, usuarios e historial.' AS description
                  UNION ALL
                  SELECT 'audioprint', 'user', 'User', 'Uso normal de la solución y gestión de sus propios audios.'
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
                  description = VALUES(description)
                """,
            )

            cur.execute("SELECT id FROM products WHERE code = 'audioprint' LIMIT 1")
            audioprint_product_id = cur.fetchone()["id"]

            cur.execute(
                """
                SELECT r.id
                FROM roles r
                INNER JOIN products p ON p.id = r.product_id
                WHERE p.code = 'audioprint' AND r.code = 'user'
                LIMIT 1
                """
            )
            audioprint_user_role_id = cur.fetchone()["id"]

            cur.execute(
                """
                SELECT r.id
                FROM roles r
                INNER JOIN products p ON p.id = r.product_id
                WHERE p.code = 'audioprint' AND r.code = 'admin'
                LIMIT 1
                """
            )
            audioprint_admin_role_id = cur.fetchone()["id"]

            execute(cur, "DELETE FROM user_product_roles")

            legacy_roles_table = f"roles_{LEGACY_SUFFIX}"
            legacy_user_roles_table = f"user_roles_{LEGACY_SUFFIX}"

            if table_exists(cur, schema, legacy_roles_table) and table_exists(cur, schema, legacy_user_roles_table):
                execute(
                    cur,
                    f"""
                    INSERT INTO user_product_roles (user_id, product_id, role_id)
                    SELECT
                      u.id,
                      {audioprint_product_id},
                      CASE
                        WHEN EXISTS (
                          SELECT 1
                          FROM {legacy_user_roles_table} lur
                          INNER JOIN {legacy_roles_table} lr ON lr.id = lur.role_id
                          WHERE lur.user_id = u.id AND lr.code = 'admin'
                        ) THEN {audioprint_admin_role_id}
                        ELSE {audioprint_user_role_id}
                      END AS role_id
                    FROM users u
                    """
                )
            else:
                execute(
                    cur,
                    f"""
                    INSERT INTO user_product_roles (user_id, product_id, role_id)
                    SELECT u.id, {audioprint_product_id}, {audioprint_user_role_id}
                    FROM users u
                    """
                )

        connection.commit()

    print("Migration completed successfully.")


if __name__ == "__main__":
    main()
