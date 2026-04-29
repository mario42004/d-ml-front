<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function ensure_analysis_platform_tables(): void
{
    db()->exec(
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS analysis_engines (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL
    );

    db()->exec(
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS analysis_jobs (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL
    );

    db()->exec(
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS analysis_metrics (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL
    );

    db()->exec(
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS analysis_feature_snapshots (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL
    );

    db()->exec(
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS analysis_artifacts (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL
    );
}

function analysis_json_or_null(mixed $value): ?string
{
    if ($value === null) {
        return null;
    }

    $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) {
        throw new RuntimeException('No fue posible serializar el JSON del análisis.');
    }

    return $json;
}

function ensure_analysis_engine(
    int $productId,
    string $code,
    string $name,
    ?string $description = null,
    ?string $endpointUrl = null,
    ?string $contractVersion = null
): int {
    ensure_analysis_platform_tables();

    $stmt = db()->prepare(
        'INSERT INTO analysis_engines (product_id, code, name, description, endpoint_url, output_contract_version, is_active)
         VALUES (:product_id, :code, :name, :description, :endpoint_url, :output_contract_version, 1)
         ON DUPLICATE KEY UPDATE
           name = VALUES(name),
           description = VALUES(description),
           endpoint_url = VALUES(endpoint_url),
           output_contract_version = VALUES(output_contract_version),
           is_active = 1,
           updated_at = NOW()'
    );
    $stmt->execute([
        'product_id' => $productId,
        'code' => $code,
        'name' => $name,
        'description' => $description,
        'endpoint_url' => $endpointUrl,
        'output_contract_version' => $contractVersion,
    ]);

    $select = db()->prepare('SELECT id FROM analysis_engines WHERE product_id = :product_id AND code = :code LIMIT 1');
    $select->execute(['product_id' => $productId, 'code' => $code]);
    $row = $select->fetch();
    if (!$row) {
        throw new RuntimeException('No fue posible registrar el motor de análisis.');
    }

    return (int) $row['id'];
}

function create_analysis_job(array $data): int
{
    ensure_analysis_platform_tables();

    $stmt = db()->prepare(
        'INSERT INTO analysis_jobs (
            user_id,
            product_id,
            analysis_engine_id,
            source_job_type,
            source_job_id,
            input_title,
            input_description,
            input_filename,
            input_mime_type,
            input_size_bytes,
            status,
            request_payload_json
        ) VALUES (
            :user_id,
            :product_id,
            :analysis_engine_id,
            :source_job_type,
            :source_job_id,
            :input_title,
            :input_description,
            :input_filename,
            :input_mime_type,
            :input_size_bytes,
            :status,
            :request_payload_json
        )
        ON DUPLICATE KEY UPDATE
            analysis_engine_id = VALUES(analysis_engine_id),
            input_title = VALUES(input_title),
            input_description = VALUES(input_description),
            input_filename = VALUES(input_filename),
            input_mime_type = VALUES(input_mime_type),
            input_size_bytes = VALUES(input_size_bytes),
            status = VALUES(status),
            request_payload_json = VALUES(request_payload_json),
            updated_at = NOW()'
    );
    $stmt->execute([
        'user_id' => (int) $data['user_id'],
        'product_id' => (int) $data['product_id'],
        'analysis_engine_id' => $data['analysis_engine_id'] ?? null,
        'source_job_type' => (string) ($data['source_job_type'] ?? ''),
        'source_job_id' => (int) ($data['source_job_id'] ?? 0),
        'input_title' => (string) ($data['input_title'] ?? ''),
        'input_description' => (string) ($data['input_description'] ?? ''),
        'input_filename' => (string) ($data['input_filename'] ?? ''),
        'input_mime_type' => (string) ($data['input_mime_type'] ?? ''),
        'input_size_bytes' => (int) ($data['input_size_bytes'] ?? 0),
        'status' => (string) ($data['status'] ?? 'processing'),
        'request_payload_json' => analysis_json_or_null($data['request_payload'] ?? null),
    ]);

    $select = db()->prepare('SELECT id FROM analysis_jobs WHERE source_job_type = :source_job_type AND source_job_id = :source_job_id LIMIT 1');
    $select->execute([
        'source_job_type' => (string) ($data['source_job_type'] ?? ''),
        'source_job_id' => (int) ($data['source_job_id'] ?? 0),
    ]);
    $row = $select->fetch();
    if (!$row) {
        throw new RuntimeException('No fue posible registrar el job de análisis.');
    }

    return (int) $row['id'];
}

function finalize_analysis_job(int $analysisJobId, string $status, ?array $responsePayload = null, ?string $errorMessage = null): void
{
    ensure_analysis_platform_tables();

    $stmt = db()->prepare(
        'UPDATE analysis_jobs
         SET status = :status,
             response_payload_json = :response_payload_json,
             error_message = :error_message,
             processed_at = NOW(),
             updated_at = NOW()
         WHERE id = :id'
    );
    $stmt->execute([
        'status' => $status,
        'response_payload_json' => analysis_json_or_null($responsePayload),
        'error_message' => $errorMessage,
        'id' => $analysisJobId,
    ]);
}

function persist_analysis_artifact(
    int $analysisJobId,
    string $artifactType,
    string $title,
    ?string $mediaType,
    ?string $storagePath,
    ?string $publicUrl,
    ?array $metadata = null
): void {
    ensure_analysis_platform_tables();

    $stmt = db()->prepare(
        'INSERT INTO analysis_artifacts (analysis_job_id, artifact_type, title, media_type, storage_path, public_url, metadata_json)
         VALUES (:analysis_job_id, :artifact_type, :title, :media_type, :storage_path, :public_url, :metadata_json)'
    );
    $stmt->execute([
        'analysis_job_id' => $analysisJobId,
        'artifact_type' => $artifactType,
        'title' => $title,
        'media_type' => $mediaType,
        'storage_path' => $storagePath,
        'public_url' => $publicUrl,
        'metadata_json' => analysis_json_or_null($metadata),
    ]);
}

function analysis_feature_snapshot_from_metric_rows(array $rows): array
{
    $features = [];
    $numericFeatures = [];
    $labels = [];
    $units = [];

    foreach ($rows as $row) {
        $key = (string) ($row['metric_key'] ?? '');
        if ($key === '') {
            continue;
        }

        $features[$key] = (string) ($row['metric_value_text'] ?? '');
        if (is_numeric($row['metric_value_number'] ?? null)) {
            $numericFeatures[$key] = (float) $row['metric_value_number'];
        }

        $labels[$key] = (string) ($row['metric_label'] ?? $key);
        $units[$key] = (string) ($row['unit'] ?? '');
    }

    return [
        'features' => $features,
        'numeric_features' => $numericFeatures,
        'labels' => $labels,
        'units' => $units,
    ];
}

function persist_analysis_metrics(int $analysisJobId, int $userId, int $productId, array $rows): void
{
    ensure_analysis_platform_tables();

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $delete = $pdo->prepare('DELETE FROM analysis_metrics WHERE analysis_job_id = :analysis_job_id');
        $delete->execute(['analysis_job_id' => $analysisJobId]);

        if ($rows !== []) {
            $insert = $pdo->prepare(
                'INSERT INTO analysis_metrics (
                    analysis_job_id,
                    user_id,
                    product_id,
                    metric_group_key,
                    metric_group_label,
                    metric_key,
                    metric_label,
                    metric_value_text,
                    metric_value_number,
                    unit,
                    source_path,
                    description,
                    captured_at
                ) VALUES (
                    :analysis_job_id,
                    :user_id,
                    :product_id,
                    :metric_group_key,
                    :metric_group_label,
                    :metric_key,
                    :metric_label,
                    :metric_value_text,
                    :metric_value_number,
                    :unit,
                    :source_path,
                    :description,
                    NOW()
                )'
            );

            foreach ($rows as $row) {
                $insert->execute([
                    'analysis_job_id' => $analysisJobId,
                    'user_id' => $userId,
                    'product_id' => $productId,
                    'metric_group_key' => (string) ($row['metric_group_key'] ?? 'general'),
                    'metric_group_label' => (string) ($row['metric_group_label'] ?? 'General'),
                    'metric_key' => (string) ($row['metric_key'] ?? ''),
                    'metric_label' => (string) ($row['metric_label'] ?? ($row['metric_key'] ?? '')),
                    'metric_value_text' => $row['metric_value_text'] ?? null,
                    'metric_value_number' => $row['metric_value_number'] ?? null,
                    'unit' => (string) ($row['unit'] ?? ''),
                    'source_path' => (string) ($row['source_path'] ?? ''),
                    'description' => (string) ($row['description'] ?? ''),
                ]);
            }
        }

        $snapshot = analysis_feature_snapshot_from_metric_rows($rows);
        $snapshotInsert = $pdo->prepare(
            'INSERT INTO analysis_feature_snapshots (
                analysis_job_id,
                user_id,
                product_id,
                features_json,
                numeric_features_json,
                feature_labels_json,
                feature_units_json,
                captured_at
            ) VALUES (
                :analysis_job_id,
                :user_id,
                :product_id,
                :features_json,
                :numeric_features_json,
                :feature_labels_json,
                :feature_units_json,
                NOW()
            )
            ON DUPLICATE KEY UPDATE
                user_id = VALUES(user_id),
                product_id = VALUES(product_id),
                features_json = VALUES(features_json),
                numeric_features_json = VALUES(numeric_features_json),
                feature_labels_json = VALUES(feature_labels_json),
                feature_units_json = VALUES(feature_units_json),
                captured_at = VALUES(captured_at),
                updated_at = NOW()'
        );
        $snapshotInsert->execute([
            'analysis_job_id' => $analysisJobId,
            'user_id' => $userId,
            'product_id' => $productId,
            'features_json' => analysis_json_or_null($snapshot['features']),
            'numeric_features_json' => analysis_json_or_null($snapshot['numeric_features']),
            'feature_labels_json' => analysis_json_or_null($snapshot['labels']),
            'feature_units_json' => analysis_json_or_null($snapshot['units']),
        ]);

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function list_analysis_jobs_for_viewer(int $viewerUserId, bool $canViewAll = false, int $limit = 100): array
{
    ensure_analysis_platform_tables();

    $limit = max(1, min($limit, 500));
    $where = $canViewAll ? '' : 'WHERE aj.user_id = :viewer_user_id';
    $sql = <<<SQL
        SELECT
            aj.id,
            aj.user_id,
            aj.product_id,
            aj.input_title,
            aj.input_description,
            aj.input_filename,
            aj.input_mime_type,
            aj.input_size_bytes,
            aj.status,
            aj.error_message,
            aj.created_at,
            aj.processed_at,
            p.code AS product_code,
            p.name AS product_name,
            ae.code AS engine_code,
            ae.name AS engine_name,
            u.email AS user_email,
            CONCAT(u.first_name, ' ', u.last_name) AS user_name,
            COUNT(DISTINCT am.id) AS metric_count,
            COUNT(DISTINCT aa.id) AS artifact_count
        FROM analysis_jobs aj
        INNER JOIN products p ON p.id = aj.product_id
        INNER JOIN users u ON u.id = aj.user_id
        LEFT JOIN analysis_engines ae ON ae.id = aj.analysis_engine_id
        LEFT JOIN analysis_metrics am ON am.analysis_job_id = aj.id
        LEFT JOIN analysis_artifacts aa ON aa.analysis_job_id = aj.id
        $where
        GROUP BY aj.id
        ORDER BY aj.created_at DESC, aj.id DESC
        LIMIT $limit
    SQL;

    $stmt = db()->prepare($sql);
    if (!$canViewAll) {
        $stmt->bindValue('viewer_user_id', $viewerUserId, PDO::PARAM_INT);
    }
    $stmt->execute();
    return $stmt->fetchAll();
}

function get_analysis_job_for_viewer(int $analysisJobId, int $viewerUserId, bool $canViewAll = false): ?array
{
    ensure_analysis_platform_tables();

    $where = $canViewAll ? 'aj.id = :id' : 'aj.id = :id AND aj.user_id = :viewer_user_id';
    $sql = <<<SQL
        SELECT
            aj.*,
            p.code AS product_code,
            p.name AS product_name,
            ae.code AS engine_code,
            ae.name AS engine_name,
            u.email AS user_email,
            CONCAT(u.first_name, ' ', u.last_name) AS user_name
        FROM analysis_jobs aj
        INNER JOIN products p ON p.id = aj.product_id
        INNER JOIN users u ON u.id = aj.user_id
        LEFT JOIN analysis_engines ae ON ae.id = aj.analysis_engine_id
        WHERE $where
        LIMIT 1
    SQL;

    $stmt = db()->prepare($sql);
    $stmt->bindValue('id', $analysisJobId, PDO::PARAM_INT);
    if (!$canViewAll) {
        $stmt->bindValue('viewer_user_id', $viewerUserId, PDO::PARAM_INT);
    }
    $stmt->execute();
    $row = $stmt->fetch();
    return $row ?: null;
}

function list_analysis_metrics(int $analysisJobId): array
{
    ensure_analysis_platform_tables();

    $stmt = db()->prepare(
        'SELECT metric_group_key, metric_group_label, metric_key, metric_label, metric_value_text, metric_value_number, unit, source_path, description, captured_at
         FROM analysis_metrics
         WHERE analysis_job_id = :analysis_job_id
         ORDER BY id ASC'
    );
    $stmt->execute(['analysis_job_id' => $analysisJobId]);
    return $stmt->fetchAll();
}

function list_analysis_artifacts(int $analysisJobId): array
{
    ensure_analysis_platform_tables();

    $stmt = db()->prepare(
        'SELECT artifact_type, title, media_type, storage_path, public_url, metadata_json, created_at
         FROM analysis_artifacts
         WHERE analysis_job_id = :analysis_job_id
         ORDER BY id ASC'
    );
    $stmt->execute(['analysis_job_id' => $analysisJobId]);
    return $stmt->fetchAll();
}
