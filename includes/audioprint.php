<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/analysis.php';

function audioprint_api_url(): string
{
    $newUrl = env_value('AUDIOPRINT_AUDIOANALISYS_API_URL');
    if (is_string($newUrl) && trim($newUrl) !== '') {
        return trim($newUrl);
    }

    return (string) env_value('AUDIOPRINT_SCALOGRAM_API_URL', 'http://127.0.0.1:8001/audioanalisys');
}

function audioprint_max_upload_bytes(): int
{
    return ((int) env_value('AUDIOPRINT_MAX_UPLOAD_MB', '25')) * 1024 * 1024;
}

function audioprint_timeout_seconds(): int
{
    $configured = (int) env_value('AUDIOPRINT_UPLOAD_TIMEOUT_SECONDS', '120');
    if ($configured <= 0) {
        return 120;
    }

    return min($configured, 300);
}

function audioprint_storage_dir(string $kind): string
{
    return dirname(__DIR__) . '/storage/' . $kind . '/audioprint';
}

function audioprint_public_url(string $kind, string $filename): string
{
    return '/storage/' . $kind . '/audioprint/' . $filename;
}

function ensure_directory(string $path): void
{
    if (!is_dir($path)) {
        mkdir($path, 0775, true);
    }
}

function audioprint_product(): ?array
{
    return find_product_by_code('audioprint');
}

function ensure_audio_jobs_description_column(): void
{
    $stmt = db()->query("SHOW COLUMNS FROM audio_jobs LIKE 'audio_description'");
    if ($stmt !== false && $stmt->fetch() !== false) {
        return;
    }

    db()->exec("ALTER TABLE audio_jobs ADD COLUMN audio_description VARCHAR(50) NOT NULL DEFAULT '' AFTER original_filename");
}

function audioprint_normalize_description(string $description): string
{
    $description = trim(preg_replace('/\s+/', ' ', $description) ?? '');
    if (function_exists('mb_substr')) {
        return mb_substr($description, 0, 50, 'UTF-8');
    }

    return substr($description, 0, 50);
}

function audioprint_text_length(string $value): int
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($value, 'UTF-8');
    }

    return strlen($value);
}

function create_audio_job_record(int $userId, int $productId, string $originalFilename, string $audioDescription, string $mimeType, int $sizeBytes, string $audioPath, string $audioUrl): int
{
    ensure_audio_jobs_description_column();

    $stmt = db()->prepare(
        'INSERT INTO audio_jobs (user_id, product_id, original_filename, audio_description, mime_type, audio_size_bytes, audio_path, audio_url, status) VALUES (:user_id, :product_id, :original_filename, :audio_description, :mime_type, :audio_size_bytes, :audio_path, :audio_url, :status)'
    );
    $stmt->execute([
        'user_id' => $userId,
        'product_id' => $productId,
        'original_filename' => $originalFilename,
        'audio_description' => $audioDescription,
        'mime_type' => $mimeType,
        'audio_size_bytes' => $sizeBytes,
        'audio_path' => $audioPath,
        'audio_url' => $audioUrl,
        'status' => 'processing',
    ]);

    return (int) db()->lastInsertId();
}

function finalize_audio_job(int $jobId, string $status, ?string $scalogramPath = null, ?string $scalogramUrl = null, ?string $errorMessage = null): void
{
    $stmt = db()->prepare(
        'UPDATE audio_jobs SET status = :status, scalogram_path = :scalogram_path, scalogram_url = :scalogram_url, error_message = :error_message, processed_at = NOW(), updated_at = NOW() WHERE id = :id'
    );
    $stmt->execute([
        'status' => $status,
        'scalogram_path' => $scalogramPath,
        'scalogram_url' => $scalogramUrl,
        'error_message' => $errorMessage,
        'id' => $jobId,
    ]);
}

function ensure_audio_job_metrics_table(): void
{
    db()->exec(
        <<<'SQL'
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
        SQL
    );
}

function ensure_audio_job_feature_snapshots_table(): void
{
    db()->exec(
        <<<'SQL'
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
        SQL
    );
}

function list_audio_jobs_for_user(int $userId): array
{
    ensure_audio_jobs_description_column();

    $stmt = db()->prepare(
        'SELECT id, original_filename, audio_description, mime_type, audio_size_bytes, audio_url, scalogram_path, scalogram_url, status, error_message, created_at, processed_at FROM audio_jobs WHERE user_id = :user_id ORDER BY created_at DESC, id DESC'
    );
    $stmt->execute(['user_id' => $userId]);
    return $stmt->fetchAll();
}

function list_recent_audio_jobs(int $limit = 20): array
{
    ensure_audio_jobs_description_column();

    $sql = <<<'SQL'
        SELECT
            j.id,
            j.original_filename,
            j.audio_description,
            j.status,
            j.error_message,
            j.audio_url,
            j.scalogram_path,
            j.scalogram_url,
            j.created_at,
            j.processed_at,
            u.first_name,
            u.last_name,
            u.email
        FROM audio_jobs j
        INNER JOIN users u ON u.id = j.user_id
        ORDER BY j.created_at DESC, j.id DESC
        LIMIT :limit
    SQL;

    $stmt = db()->prepare($sql);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function get_audio_job_by_id(int $jobId): ?array
{
    ensure_audio_jobs_description_column();

    $stmt = db()->prepare(
        'SELECT id, user_id, original_filename, audio_description, audio_path, audio_url, scalogram_path, scalogram_url, status FROM audio_jobs WHERE id = :id LIMIT 1'
    );
    $stmt->execute(['id' => $jobId]);
    $job = $stmt->fetch();

    return $job ?: null;
}

function audioprint_metric_numeric_value(mixed $value): ?float
{
    if (is_bool($value)) {
        return $value ? 1.0 : 0.0;
    }

    return is_numeric($value) ? (float) $value : null;
}

function audioprint_canonical_metric_rows_from_analysis(array $analysis): array
{
    $metricas = $analysis['metricas'] ?? $analysis['metrics'] ?? null;
    if (!is_array($metricas)) {
        return [];
    }

    $groups = $metricas['grupos'] ?? $metricas['groups'] ?? null;
    if (!is_array($groups)) {
        return [];
    }

    $rows = [];
    foreach ($groups as $group) {
        if (!is_array($group)) {
            continue;
        }

        $groupKey = trim((string) ($group['clave'] ?? $group['key'] ?? 'general'));
        $groupLabel = trim((string) ($group['etiqueta'] ?? $group['label'] ?? $groupKey));
        $metrics = $group['metricas'] ?? $group['metrics'] ?? null;
        if (!is_array($metrics)) {
            continue;
        }

        foreach ($metrics as $metric) {
            if (!is_array($metric)) {
                continue;
            }

            $metricKey = trim((string) ($metric['clave'] ?? $metric['key'] ?? ''));
            if ($metricKey === '' || !array_key_exists('valor', $metric) && !array_key_exists('value', $metric)) {
                continue;
            }

            $value = $metric['valor'] ?? $metric['value'];
            $rows[] = [
                'metric_group_key' => $groupKey !== '' ? $groupKey : 'general',
                'metric_group_label' => $groupLabel !== '' ? $groupLabel : 'General',
                'metric_key' => $metricKey,
                'metric_label' => (string) ($metric['etiqueta'] ?? $metric['label'] ?? $metricKey),
                'metric_value_text' => audioprint_format_metric_value($value),
                'metric_value_number' => audioprint_metric_numeric_value($value),
                'unit' => (string) ($metric['unidad'] ?? $metric['unit'] ?? ''),
                'source_path' => (string) ($metric['fuente'] ?? $metric['source'] ?? $metricKey),
                'description' => (string) ($metric['descripcion'] ?? $metric['description'] ?? ''),
            ];
        }
    }

    return $rows;
}

function audioprint_feature_snapshot_from_metric_rows(array $rows): array
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

function persist_audioprint_analysis_platform_record(
    int $analysisJobId,
    int $userId,
    int $productId,
    int $audioJobId,
    array $analysis,
    array $metricRows,
    string $audioPath,
    string $audioUrl,
    string $audioMimeType,
    string $scalogramPath,
    string $scalogramUrl,
    string $analysisPath,
    string $analysisUrl
): void {
    persist_analysis_metrics($analysisJobId, $userId, $productId, $metricRows);

    persist_analysis_artifact(
        $analysisJobId,
        'input_audio',
        'Audio original',
        $audioMimeType,
        $audioPath,
        $audioUrl,
        ['audio_job_id' => $audioJobId]
    );
    persist_analysis_artifact(
        $analysisJobId,
        'primary_image',
        (string) (($analysis['plots']['dashboard']['title'] ?? null) ?: 'Dashboard'),
        'image/png',
        $scalogramPath,
        $scalogramUrl,
        ['source' => 'image_base64']
    );
    persist_analysis_artifact(
        $analysisJobId,
        'response_json',
        'Respuesta JSON de análisis',
        'application/json',
        $analysisPath,
        $analysisUrl,
        ['audio_job_id' => $audioJobId]
    );
}

function persist_audio_job_metrics(int $jobId, int $userId, int $productId, array $analysis): void
{
    $rows = audioprint_canonical_metric_rows_from_analysis($analysis);
    ensure_audio_job_metrics_table();
    ensure_audio_job_feature_snapshots_table();

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $delete = $pdo->prepare('DELETE FROM audio_job_metrics WHERE audio_job_id = :audio_job_id');
        $delete->execute(['audio_job_id' => $jobId]);

        if ($rows !== []) {
            $insert = $pdo->prepare(
                'INSERT INTO audio_job_metrics (
                    audio_job_id,
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
                    :audio_job_id,
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
                    'audio_job_id' => $jobId,
                    'user_id' => $userId,
                    'product_id' => $productId,
                    'metric_group_key' => $row['metric_group_key'],
                    'metric_group_label' => $row['metric_group_label'],
                    'metric_key' => $row['metric_key'],
                    'metric_label' => $row['metric_label'],
                    'metric_value_text' => $row['metric_value_text'],
                    'metric_value_number' => $row['metric_value_number'],
                    'unit' => $row['unit'],
                    'source_path' => $row['source_path'],
                    'description' => $row['description'],
                ]);
            }
        }

        $snapshot = audioprint_feature_snapshot_from_metric_rows($rows);
        $featuresJson = json_encode($snapshot['features'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $numericFeaturesJson = json_encode($snapshot['numeric_features'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $labelsJson = json_encode($snapshot['labels'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $unitsJson = json_encode($snapshot['units'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($featuresJson) || !is_string($numericFeaturesJson) || !is_string($labelsJson) || !is_string($unitsJson)) {
            throw new RuntimeException('No fue posible serializar las features del audio.');
        }

        $snapshotInsert = $pdo->prepare(
            'INSERT INTO audio_job_feature_snapshots (
                audio_job_id,
                user_id,
                product_id,
                features_json,
                numeric_features_json,
                feature_labels_json,
                feature_units_json,
                captured_at
            ) VALUES (
                :audio_job_id,
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
            'audio_job_id' => $jobId,
            'user_id' => $userId,
            'product_id' => $productId,
            'features_json' => $featuresJson,
            'numeric_features_json' => $numericFeaturesJson,
            'feature_labels_json' => $labelsJson,
            'feature_units_json' => $unitsJson,
        ]);

        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }
}

function list_audio_job_metrics(int $jobId): array
{
    ensure_audio_job_metrics_table();

    $stmt = db()->prepare(
        'SELECT metric_group_key, metric_group_label, metric_key, metric_label, metric_value_text, metric_value_number, unit, source_path, description, captured_at
         FROM audio_job_metrics
         WHERE audio_job_id = :audio_job_id
         ORDER BY id ASC'
    );
    $stmt->execute(['audio_job_id' => $jobId]);
    return $stmt->fetchAll();
}

function list_audio_job_metric_values(array $jobIds, array $metricKeys): array
{
    $jobIds = array_values(array_unique(array_filter(array_map('intval', $jobIds))));
    $metricKeys = array_values(array_unique(array_filter(array_map('strval', $metricKeys))));
    if ($jobIds === [] || $metricKeys === []) {
        return [];
    }

    ensure_audio_job_metrics_table();

    $jobPlaceholders = implode(',', array_fill(0, count($jobIds), '?'));
    $metricPlaceholders = implode(',', array_fill(0, count($metricKeys), '?'));
    $stmt = db()->prepare(
        "SELECT audio_job_id, metric_key, metric_value_number, metric_value_text, unit, captured_at
         FROM audio_job_metrics
         WHERE audio_job_id IN ($jobPlaceholders)
           AND metric_key IN ($metricPlaceholders)"
    );
    $stmt->execute([...$jobIds, ...$metricKeys]);

    $values = [];
    foreach ($stmt->fetchAll() as $row) {
        $jobId = (int) $row['audio_job_id'];
        $key = (string) $row['metric_key'];
        $values[$jobId][$key] = $row;
    }

    return $values;
}

function list_audio_job_metric_export_rows(?int $userId = null): array
{
    ensure_audio_job_metrics_table();
    ensure_audio_jobs_description_column();

    $where = '';
    $params = [];
    if ($userId !== null) {
        $where = 'WHERE m.user_id = :user_id';
        $params['user_id'] = $userId;
    }

    $stmt = db()->prepare(
        <<<SQL
        SELECT
            u.id AS user_id,
            u.email AS user_email,
            u.first_name,
            u.last_name,
            j.id AS audio_job_id,
            j.original_filename,
            j.audio_description,
            j.mime_type,
            j.audio_size_bytes,
            j.status AS job_status,
            j.created_at AS job_created_at,
            j.processed_at AS job_processed_at,
            m.metric_group_key,
            m.metric_group_label,
            m.metric_key,
            m.metric_label,
            m.metric_value_text,
            m.metric_value_number,
            m.unit,
            m.source_path,
            m.description,
            m.captured_at
        FROM audio_job_metrics m
        INNER JOIN audio_jobs j ON j.id = m.audio_job_id
        INNER JOIN users u ON u.id = m.user_id
        $where
        ORDER BY
            u.email ASC,
            COALESCE(j.processed_at, j.created_at) ASC,
            j.id ASC,
            m.id ASC
        SQL
    );
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function audioprint_decode_json_object(mixed $value): array
{
    if (is_array($value)) {
        return $value;
    }

    if (!is_string($value) || trim($value) === '') {
        return [];
    }

    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : [];
}

function list_audio_job_feature_export_rows(?int $userId = null): array
{
    ensure_audio_job_feature_snapshots_table();
    ensure_audio_jobs_description_column();

    $where = '';
    $params = [];
    if ($userId !== null) {
        $where = 'WHERE s.user_id = :user_id';
        $params['user_id'] = $userId;
    }

    $stmt = db()->prepare(
        <<<SQL
        SELECT
            u.id AS user_id,
            u.email AS user_email,
            u.first_name,
            u.last_name,
            j.id AS audio_job_id,
            j.original_filename,
            j.audio_description,
            j.mime_type,
            j.audio_size_bytes,
            j.status AS job_status,
            j.created_at AS job_created_at,
            j.processed_at AS job_processed_at,
            s.features_json,
            s.feature_labels_json,
            s.feature_units_json,
            s.captured_at
        FROM audio_job_feature_snapshots s
        INNER JOIN audio_jobs j ON j.id = s.audio_job_id
        INNER JOIN users u ON u.id = s.user_id
        $where
        ORDER BY
            COALESCE(j.processed_at, j.created_at) ASC,
            j.id ASC
        SQL
    );
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function audioprint_feature_export_columns(array $rows): array
{
    $columns = [];
    foreach ($rows as $row) {
        foreach (audioprint_decode_json_object($row['features_json'] ?? null) as $key => $_value) {
            $key = (string) $key;
            if ($key !== '' && !in_array($key, $columns, true)) {
                $columns[] = $key;
            }
        }
    }

    sort($columns, SORT_NATURAL);
    return $columns;
}

function audioprint_metric_groups_from_persisted_rows(array $rows): array
{
    $groups = [];
    foreach ($rows as $row) {
        $category = (string) ($row['metric_group_label'] ?? 'General');
        $groups[$category][] = [
            'category' => $category,
            'metric' => (string) ($row['metric_label'] ?? $row['metric_key'] ?? 'Métrica'),
            'path' => (string) ($row['source_path'] ?? $row['metric_key'] ?? ''),
            'value' => (string) ($row['metric_value_text'] ?? ''),
            'unit' => (string) ($row['unit'] ?? ''),
            'status_label' => (string) ($row['description'] ?? 'Métrica persistida'),
            'status_class' => 'is-neutral',
        ];
    }

    return $groups;
}

function delete_audio_job_record(int $jobId): array
{
    $job = get_audio_job_by_id($jobId);
    if ($job === null) {
        return ['ok' => false, 'message' => 'El registro ya no existe o fue eliminado.'];
    }

    $audioPath = (string) ($job['audio_path'] ?? '');
    $scalogramPath = (string) ($job['scalogram_path'] ?? '');
    $analysisPath = audioprint_analysis_path_from_scalogram_path($scalogramPath);

    ensure_analysis_platform_tables();
    $deleteAnalysisJob = db()->prepare('DELETE FROM analysis_jobs WHERE source_job_type = :source_job_type AND source_job_id = :source_job_id');
    $deleteAnalysisJob->execute([
        'source_job_type' => 'audio_jobs',
        'source_job_id' => $jobId,
    ]);

    $stmt = db()->prepare('DELETE FROM audio_jobs WHERE id = :id');
    $stmt->execute(['id' => $jobId]);

    foreach ([$audioPath, $scalogramPath, $analysisPath] as $path) {
        if (is_string($path) && $path !== '' && is_file($path)) {
            @unlink($path);
        }
    }

    return ['ok' => true];
}

function audioprint_analysis_path_from_scalogram_path(?string $scalogramPath): ?string
{
    if (!is_string($scalogramPath) || $scalogramPath === '') {
        return null;
    }

    return preg_replace('/\.png$/i', '.json', $scalogramPath) ?: null;
}

function audioprint_analysis_url_from_scalogram_url(?string $scalogramUrl): ?string
{
    if (!is_string($scalogramUrl) || $scalogramUrl === '') {
        return null;
    }

    return preg_replace('/\.png$/i', '.json', $scalogramUrl) ?: null;
}

function audioprint_enrich_job_record(array $job): array
{
    $analysisPath = audioprint_analysis_path_from_scalogram_path((string) ($job['scalogram_path'] ?? ''));
    $analysisUrl = audioprint_analysis_url_from_scalogram_url((string) ($job['scalogram_url'] ?? ''));
    $job['analysis_path'] = $analysisPath;
    $job['analysis_url'] = $analysisUrl;
    $job['analysis_available'] = $analysisPath !== null && is_file($analysisPath);

    return $job;
}

function audioprint_load_analysis_for_job(array $job): ?array
{
    $analysisPath = $job['analysis_path'] ?? audioprint_analysis_path_from_scalogram_path((string) ($job['scalogram_path'] ?? ''));
    if (!is_string($analysisPath) || $analysisPath === '' || !is_file($analysisPath)) {
        return null;
    }

    $contents = file_get_contents($analysisPath);
    if (!is_string($contents) || $contents === '') {
        return null;
    }

    $decoded = json_decode($contents, true);
    return is_array($decoded) ? $decoded : null;
}

function audioprint_analysis_metric(array $analysis, array $path): ?float
{
    if (($path[0] ?? null) === 'metricas' && isset($path[1])) {
        foreach (audioprint_canonical_metric_rows_from_analysis($analysis) as $row) {
            if (($row['metric_key'] ?? '') === (string) $path[1]) {
                return is_numeric($row['metric_value_number']) ? (float) $row['metric_value_number'] : null;
            }
        }

        return null;
    }

    $value = $analysis;
    foreach ($path as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return null;
        }
        $value = $value[$segment];
    }

    return is_numeric($value) ? (float) $value : null;
}

function audioprint_analysis_metric_any(array $analysis, array $paths): ?float
{
    foreach ($paths as $path) {
        if (!is_array($path)) {
            continue;
        }

        $metric = audioprint_analysis_metric($analysis, $path);
        if ($metric !== null) {
            return $metric;
        }
    }

    return null;
}

function audioprint_job_datetime_label(array $job): string
{
    $raw = (string) ($job['processed_at'] ?? $job['created_at'] ?? '');
    if ($raw === '') {
        return '';
    }

    try {
        $date = new DateTimeImmutable($raw);
        return $date->format('d/m H:i');
    } catch (Throwable $exception) {
        return $raw;
    }
}

function audioprint_job_datetime_sort_value(array $job): int
{
    $raw = (string) ($job['processed_at'] ?? $job['created_at'] ?? '');
    if ($raw === '') {
        return 0;
    }

    try {
        return (new DateTimeImmutable($raw))->getTimestamp();
    } catch (Throwable $exception) {
        return 0;
    }
}

function audioprint_trend_definitions(): array
{
    return [
        'dominant_frequency_hz' => [
            'label' => 'Frecuencia dominante',
            'unit' => 'Hz',
            'axis_label' => 'frecuencia en Hz',
            'description' => 'Frecuencia con mayor presencia en cada audio.',
            'color' => '#ffc74d',
            'paths' => [
                ['metricas', 'dominant_frequency_hz'],
                ['analysis_engine', 'spectral_summary', 'dominant_frequency'],
                ['spectral_analysis', 'dominant_frequency_hz'],
            ],
        ],
        'dynamic_range_db' => [
            'label' => 'Rango dinámico',
            'unit' => 'dB',
            'axis_label' => 'diferencia en dB',
            'description' => 'Diferencia entre zonas de menor y mayor nivel.',
            'color' => '#f26a21',
            'paths' => [
                ['metricas', 'dynamic_range_db'],
                ['analysis_engine', 'global_features', 'basic_features', 'dynamic_range_db'],
                ['temporal_analysis', 'dynamic_range_db'],
            ],
        ],
        'silence_sample_ratio' => [
            'label' => 'Silencio',
            'unit' => 'ratio',
            'axis_label' => 'proporción 0-1',
            'description' => 'Proporción del audio detectada como silencio.',
            'color' => '#46c797',
            'paths' => [
                ['metricas', 'silence_sample_ratio'],
                ['analysis_engine', 'quality', 'silence_ratio'],
                ['temporal_analysis', 'silence_ratio'],
            ],
        ],
        'stability_index' => [
            'label' => 'Estabilidad',
            'unit' => 'valor',
            'axis_label' => 'cambio entre capturas',
            'description' => 'Compara qué tan parecida es la señal entre análisis. Úsala para ver cambios, no como diagnóstico.',
            'color' => '#7cc7ff',
            'paths' => [
                ['metricas', 'stability_index'],
                ['analysis_engine', 'temporal_summary', 'stability_index'],
            ],
        ],
    ];
}

function audioprint_build_trend_series(array $jobs): array
{
    $definitions = audioprint_trend_definitions();
    $series = [];
    $jobIds = array_map(static fn (array $job): int => (int) ($job['id'] ?? 0), $jobs);
    $persistedMetrics = list_audio_job_metric_values($jobIds, array_keys($definitions));

    foreach ($definitions as $key => $definition) {
        $series[$key] = [
            'key' => $key,
            'label' => $definition['label'],
            'unit' => $definition['unit'],
            'axis_label' => $definition['axis_label'],
            'description' => $definition['description'],
            'color' => $definition['color'],
            'points' => [],
        ];
    }

    foreach ($jobs as $job) {
        if (($job['analysis_available'] ?? false) !== true) {
            continue;
        }

        $analysis = audioprint_load_analysis_for_job($job);
        if (!is_array($analysis)) {
            continue;
        }

        $sortValue = audioprint_job_datetime_sort_value($job);
        $label = audioprint_job_datetime_label($job);
        $jobId = (int) ($job['id'] ?? 0);
        foreach ($definitions as $key => $definition) {
            $metric = null;
            $persistedMetric = $persistedMetrics[$jobId][$key] ?? null;
            if (is_array($persistedMetric) && is_numeric($persistedMetric['metric_value_number'] ?? null)) {
                $metric = (float) $persistedMetric['metric_value_number'];
            }

            if ($metric === null) {
                $paths = is_array($definition['paths'] ?? null) ? $definition['paths'] : [];
                $metric = audioprint_analysis_metric_any($analysis, $paths);
            }

            if ($metric === null) {
                continue;
            }

            $series[$key]['points'][] = [
                'x' => $sortValue,
                'x_label' => $label,
                'y' => $metric,
                'job_id' => $jobId,
            ];
        }
    }

    foreach ($series as $key => $definition) {
        usort(
            $series[$key]['points'],
            static fn (array $left, array $right): int => ($left['x'] ?? 0) <=> ($right['x'] ?? 0)
        );
    }

    return $series;
}

function audioprint_render_trend_chart(array $series): string
{
    $points = $series['points'] ?? [];
    if (!is_array($points) || $points === []) {
        return '';
    }

    $width = 560;
    $height = 220;
    $paddingLeft = 62;
    $paddingRight = 18;
    $paddingTop = 18;
    $paddingBottom = 34;
    $plotWidth = $width - $paddingLeft - $paddingRight;
    $plotHeight = $height - $paddingTop - $paddingBottom;

    $values = array_map(static fn (array $point): float => (float) ($point['y'] ?? 0), $points);
    $minValue = min($values);
    $maxValue = max($values);
    if (abs($maxValue - $minValue) < 0.000001) {
        $minValue -= 1.0;
        $maxValue += 1.0;
    }

    $count = count($points);
    $coordinates = [];
    foreach ($points as $index => $point) {
        $x = $paddingLeft + ($count === 1 ? $plotWidth / 2 : ($plotWidth * $index / ($count - 1)));
        $normalized = (((float) $point['y']) - $minValue) / ($maxValue - $minValue);
        $y = $paddingTop + $plotHeight - ($normalized * $plotHeight);
        $coordinates[] = ['x' => $x, 'y' => $y, 'label' => (string) ($point['x_label'] ?? '')];
    }

    $path = '';
    foreach ($coordinates as $index => $point) {
        $path .= ($index === 0 ? 'M' : ' L') . round($point['x'], 2) . ' ' . round($point['y'], 2);
    }

    $gridLines = 4;
    $gridMarkup = '';
    $yLabelsMarkup = '';
    for ($step = 0; $step <= $gridLines; $step++) {
        $y = $paddingTop + ($plotHeight * $step / $gridLines);
        $value = $maxValue - (($maxValue - $minValue) * $step / $gridLines);
        $valueText = rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
        $gridMarkup .= '<line x1="' . $paddingLeft . '" y1="' . round($y, 2) . '" x2="' . ($width - $paddingRight) . '" y2="' . round($y, 2) . '" stroke="rgba(255,255,255,0.08)" stroke-width="1" />';
        $yLabelsMarkup .= '<text x="' . ($paddingLeft - 8) . '" y="' . round($y + 4, 2) . '" text-anchor="end" fill="#bbaea0" font-size="11">' . htmlspecialchars($valueText, ENT_QUOTES, 'UTF-8') . '</text>';
    }

    $circlesMarkup = '';
    foreach ($coordinates as $index => $point) {
        $circlesMarkup .= '<circle cx="' . round($point['x'], 2) . '" cy="' . round($point['y'], 2) . '" r="4" fill="' . htmlspecialchars((string) ($series['color'] ?? '#ffc74d'), ENT_QUOTES, 'UTF-8') . '" />';
        if ($count <= 8 || $index === 0 || $index === $count - 1) {
            $circlesMarkup .= '<text x="' . round($point['x'], 2) . '" y="' . ($height - 10) . '" text-anchor="middle" fill="#bbaea0" font-size="11">' . htmlspecialchars($point['label'], ENT_QUOTES, 'UTF-8') . '</text>';
        }
    }

    return
        '<svg viewBox="0 0 ' . $width . ' ' . $height . '" class="audioprint-trend-svg" role="img" aria-label="' . htmlspecialchars((string) ($series['label'] ?? 'Tendencia'), ENT_QUOTES, 'UTF-8') . '">' .
        '<rect x="0" y="0" width="' . $width . '" height="' . $height . '" rx="18" fill="rgba(255,255,255,0.02)" />' .
        $gridMarkup .
        $yLabelsMarkup .
        '<line x1="' . $paddingLeft . '" y1="' . $paddingTop . '" x2="' . $paddingLeft . '" y2="' . ($height - $paddingBottom) . '" stroke="rgba(255,255,255,0.12)" stroke-width="1" />' .
        '<path d="' . trim($path) . '" fill="none" stroke="' . htmlspecialchars((string) ($series['color'] ?? '#ffc74d'), ENT_QUOTES, 'UTF-8') . '" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" />' .
        $circlesMarkup .
        '</svg>';
}

function audioprint_call_scalogram_api(string $audioPath, string $mimeType, string $originalFilename, string $audioDescription): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'message' => 'La extensión cURL no está disponible en PHP.'];
    }

    $ch = curl_init(audioprint_api_url());
    if ($ch === false) {
        return ['ok' => false, 'message' => 'No fue posible inicializar la conexión con la API.'];
    }

    $payload = [
        'audio_file' => new CURLFile($audioPath, $mimeType, $originalFilename),
        'output' => 'json',
        'visualization' => 'dashboard',
        'audio_description' => $audioDescription,
    ];

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => audioprint_timeout_seconds(),
        CURLOPT_HTTPHEADER => ['Expect:'],
    ]);

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        $message = $error !== '' ? $error : 'La API no devolvió respuesta.';

        if (stripos($message, 'timed out') !== false) {
            $message = 'La generación del análisis tardó demasiado y el servidor canceló la espera. Prueba con un audio más corto.';
        }

        return ['ok' => false, 'message' => $message];
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        $decodedError = json_decode((string) $response, true);
        $detail = is_array($decodedError) ? (string) ($decodedError['detail'] ?? '') : '';
        $message = $detail !== '' ? $detail : 'La API devolvió un error HTTP ' . $httpCode . '.';
        return ['ok' => false, 'message' => $message];
    }

    $decoded = json_decode((string) $response, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'message' => 'La API devolvió una respuesta JSON no válida.'];
    }

    if (!isset($decoded['image_base64']) || !is_string($decoded['image_base64'])) {
        return ['ok' => false, 'message' => 'La API no devolvió la imagen principal del análisis.'];
    }

    return ['ok' => true, 'payload' => $decoded];
}

function handle_audioprint_upload(int $userId, array $file, string $audioDescription): array
{
    $product = audioprint_product();
    if ($product === null) {
        return ['ok' => false, 'message' => 'Audioprint no está configurado en la base de datos.'];
    }

    $audioDescription = audioprint_normalize_description($audioDescription);
    if ($audioDescription === '') {
        return ['ok' => false, 'message' => 'Debes escribir una descripción corta del audio.'];
    }
    if (audioprint_text_length($audioDescription) > 50) {
        return ['ok' => false, 'message' => 'La descripción del audio no puede superar 50 caracteres.'];
    }

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'message' => 'No fue posible recibir el archivo de audio.'];
    }

    $tmpPath = (string) ($file['tmp_name'] ?? '');
    $originalFilename = trim((string) ($file['name'] ?? 'audio'));
    $sizeBytes = (int) ($file['size'] ?? 0);
    $mimeType = trim((string) ($file['type'] ?? 'application/octet-stream'));

    if ($sizeBytes <= 0) {
        return ['ok' => false, 'message' => 'El archivo de audio está vacío.'];
    }

    if ($sizeBytes > audioprint_max_upload_bytes()) {
        return ['ok' => false, 'message' => 'El archivo supera el tamaño máximo permitido.'];
    }

    $uploadsDir = audioprint_storage_dir('uploads');
    $resultsDir = audioprint_storage_dir('results');
    ensure_directory($uploadsDir);
    ensure_directory($resultsDir);

    $extension = strtolower((string) pathinfo($originalFilename, PATHINFO_EXTENSION));
    $safeExtension = $extension !== '' ? preg_replace('/[^a-z0-9]/', '', $extension) : 'bin';
    $baseName = 'audio_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6));
    $audioFilename = $baseName . '.' . ($safeExtension !== '' ? $safeExtension : 'bin');
    $audioPath = $uploadsDir . '/' . $audioFilename;
    $audioUrl = audioprint_public_url('uploads', $audioFilename);

    if (!move_uploaded_file($tmpPath, $audioPath)) {
        return ['ok' => false, 'message' => 'No fue posible guardar el audio subido.'];
    }

    $productId = (int) $product['id'];
    $jobId = create_audio_job_record($userId, $productId, $originalFilename, $audioDescription, $mimeType, $sizeBytes, $audioPath, $audioUrl);
    $analysisEngineId = ensure_analysis_engine(
        $productId,
        'audioprint_wavelet',
        'Audioprint Wavelet',
        'API de análisis de audio con dashboard, autocorrelación y métricas canónicas.',
        audioprint_api_url(),
        'metricas.v1'
    );
    $analysisJobId = create_analysis_job([
        'user_id' => $userId,
        'product_id' => $productId,
        'analysis_engine_id' => $analysisEngineId,
        'source_job_type' => 'audio_jobs',
        'source_job_id' => $jobId,
        'input_title' => $audioDescription,
        'input_description' => $audioDescription,
        'input_filename' => $originalFilename,
        'input_mime_type' => $mimeType,
        'input_size_bytes' => $sizeBytes,
        'status' => 'processing',
        'request_payload' => [
            'output' => 'json',
            'visualization' => 'dashboard',
            'audio_description' => $audioDescription,
        ],
    ]);

    $apiResult = audioprint_call_scalogram_api($audioPath, $mimeType, $originalFilename, $audioDescription);
    if (($apiResult['ok'] ?? false) !== true) {
        $errorMessage = (string) ($apiResult['message'] ?? 'La API devolvió un error.');
        finalize_audio_job($jobId, 'failed', null, null, $errorMessage);
        finalize_analysis_job($analysisJobId, 'failed', null, $errorMessage);
        return ['ok' => false, 'message' => $errorMessage];
    }

    $analysisPayload = $apiResult['payload'] ?? null;
    if (!is_array($analysisPayload)) {
        finalize_audio_job($jobId, 'failed', null, null, 'La API no devolvió un análisis interpretable.');
        finalize_analysis_job($analysisJobId, 'failed', null, 'La API no devolvió un análisis interpretable.');
        return ['ok' => false, 'message' => 'La API no devolvió un análisis interpretable.'];
    }

    $scalogramFilename = $baseName . '.png';
    $scalogramPath = $resultsDir . '/' . $scalogramFilename;
    $scalogramUrl = audioprint_public_url('results', $scalogramFilename);
    $analysisFilename = $baseName . '.json';
    $analysisPath = $resultsDir . '/' . $analysisFilename;

    $primaryImageBase64 = (string) ($analysisPayload['image_base64'] ?? '');
    $primaryImageBytes = base64_decode($primaryImageBase64, true);
    if (!is_string($primaryImageBytes) || $primaryImageBytes === '') {
        finalize_audio_job($jobId, 'failed', null, null, 'La API devolvió una imagen principal no válida.');
        finalize_analysis_job($analysisJobId, 'failed', $analysisPayload, 'La API devolvió una imagen principal no válida.');
        return ['ok' => false, 'message' => 'La API devolvió una imagen principal no válida.'];
    }

    if (file_put_contents($scalogramPath, $primaryImageBytes) === false) {
        finalize_audio_job($jobId, 'failed', null, null, 'No fue posible guardar la imagen principal del análisis.');
        finalize_analysis_job($analysisJobId, 'failed', $analysisPayload, 'No fue posible guardar la imagen principal del análisis.');
        return ['ok' => false, 'message' => 'No fue posible guardar la imagen principal del análisis.'];
    }

    $analysisJson = json_encode($analysisPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($analysisJson) || file_put_contents($analysisPath, $analysisJson) === false) {
        @unlink($scalogramPath);
        finalize_audio_job($jobId, 'failed', null, null, 'No fue posible guardar el análisis generado.');
        finalize_analysis_job($analysisJobId, 'failed', $analysisPayload, 'No fue posible guardar el análisis generado.');
        return ['ok' => false, 'message' => 'No fue posible guardar el análisis generado.'];
    }

    try {
        $metricRows = audioprint_canonical_metric_rows_from_analysis($analysisPayload);
        persist_audio_job_metrics($jobId, $userId, $productId, $analysisPayload);
        persist_audioprint_analysis_platform_record(
            $analysisJobId,
            $userId,
            $productId,
            $jobId,
            $analysisPayload,
            $metricRows,
            $audioPath,
            $audioUrl,
            $mimeType,
            $scalogramPath,
            $scalogramUrl,
            $analysisPath,
            audioprint_analysis_url_from_scalogram_url($scalogramUrl) ?? ''
        );
    } catch (Throwable $exception) {
        finalize_audio_job($jobId, 'failed', $scalogramPath, $scalogramUrl, 'No fue posible persistir las métricas del análisis.');
        finalize_analysis_job($analysisJobId, 'failed', $analysisPayload, 'No fue posible persistir las métricas del análisis.');
        return ['ok' => false, 'message' => 'El análisis se generó, pero no fue posible persistir sus métricas.'];
    }

    finalize_audio_job($jobId, 'completed', $scalogramPath, $scalogramUrl, null);
    finalize_analysis_job($analysisJobId, 'completed', $analysisPayload, null);

    return ['ok' => true, 'job_id' => $jobId, 'analysis_job_id' => $analysisJobId];
}
