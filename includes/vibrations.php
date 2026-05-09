<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/analysis.php';

function vibrations_api_url(): string
{
    return (string) env_value('VIBRATIONS_API_URL', 'http://127.0.0.1:8002/datsanalysis');
}

function vibrations_max_upload_bytes(): int
{
    return ((int) env_value('VIBRATIONS_MAX_UPLOAD_MB', '25')) * 1024 * 1024;
}

function vibrations_timeout_seconds(): int
{
    $configured = (int) env_value('VIBRATIONS_UPLOAD_TIMEOUT_SECONDS', '120');
    return $configured > 0 ? min($configured, 300) : 120;
}

function vibrations_storage_dir(string $kind): string
{
    return dirname(__DIR__) . '/storage/' . $kind . '/vibrations';
}

function vibrations_public_url(string $kind, string $filename): string
{
    return '/storage/' . $kind . '/vibrations/' . $filename;
}

function vibrations_ensure_directory(string $path): void
{
    if (!is_dir($path)) {
        mkdir($path, 0775, true);
    }
}

function vibrations_trim(string $value, int $maxLength): string
{
    $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $maxLength, 'UTF-8');
    }

    return substr($value, 0, $maxLength);
}

function vibrations_product(): ?array
{
    return find_product_by_code('vibrations');
}

function ensure_vibrations_schema(): void
{
    ensure_organization_schema();

    db()->exec(
        <<<'SQL'
        INSERT INTO products (code, name, description, is_public, is_active, sort_order)
        VALUES ('vibrations', 'Vibrations', 'Análisis de acelerómetro y giroscopio para seguimiento de vibraciones y cambios anómalos.', 1, 1, 12)
        ON DUPLICATE KEY UPDATE
          name = VALUES(name),
          description = VALUES(description),
          is_public = VALUES(is_public),
          is_active = VALUES(is_active),
          sort_order = VALUES(sort_order)
        SQL
    );

    db()->exec(
        <<<'SQL'
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
          description = VALUES(description)
        SQL
    );

    db()->exec(
        <<<'SQL'
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL
    );

    db()->exec(
        <<<'SQL'
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL
    );

    vibrations_ensure_column('vibration_jobs', 'phenomenon_id', 'ALTER TABLE vibration_jobs ADD COLUMN phenomenon_id BIGINT UNSIGNED NULL AFTER product_id');
    vibrations_ensure_column('vibration_jobs', 'baseline_scope', "ALTER TABLE vibration_jobs ADD COLUMN baseline_scope VARCHAR(240) NOT NULL DEFAULT '' AFTER external_id");
    vibrations_ensure_column('vibration_jobs', 'is_baseline', "ALTER TABLE vibration_jobs ADD COLUMN is_baseline TINYINT(1) NOT NULL DEFAULT 0 AFTER baseline_scope");
    vibrations_ensure_column('vibration_jobs', 'baseline_job_id', 'ALTER TABLE vibration_jobs ADD COLUMN baseline_job_id BIGINT UNSIGNED NULL AFTER is_baseline');
    vibrations_ensure_column('vibration_jobs', 'baseline_distance_score', 'ALTER TABLE vibration_jobs ADD COLUMN baseline_distance_score DOUBLE NULL AFTER baseline_job_id');
    vibrations_ensure_column('vibration_jobs', 'baseline_summary_json', 'ALTER TABLE vibration_jobs ADD COLUMN baseline_summary_json JSON NULL AFTER baseline_distance_score');

    try {
        db()->exec('ALTER TABLE vibration_jobs ADD KEY idx_vibration_jobs_phenomenon_time (phenomenon_id, created_at)');
    } catch (Throwable) {
    }

    try {
        db()->exec('ALTER TABLE vibration_jobs ADD KEY idx_vibration_jobs_baseline_scope (organization_id, baseline_scope, is_baseline)');
    } catch (Throwable) {
    }
}

function vibrations_ensure_column(string $table, string $column, string $alterSql): void
{
    $stmt = db()->query("SHOW COLUMNS FROM {$table} LIKE " . db()->quote($column));
    if ($stmt !== false && $stmt->fetch() === false) {
        db()->exec($alterSql);
    }
}

function vibrations_baseline_scope(string $phenomenonLabel, string $externalId, string $originalFilename): string
{
    $candidate = $externalId !== '' ? 'external:' . $externalId : ($phenomenonLabel !== '' ? 'phenomenon:' . $phenomenonLabel : 'file:' . $originalFilename);
    $candidate = strtolower(trim(preg_replace('/[^a-zA-Z0-9:_-]+/', '-', $candidate) ?? '', '-'));
    return substr($candidate !== '' ? $candidate : 'default', 0, 240);
}

function vibrations_external_key(string $externalId, string $name): string
{
    $candidate = $externalId !== '' ? $externalId : $name;
    $candidate = strtolower(trim(preg_replace('/[^a-zA-Z0-9:_-]+/', '-', $candidate) ?? '', '-'));
    return substr($candidate !== '' ? $candidate : 'phenomenon', 0, 120);
}

function create_vibration_phenomenon(int $userId, string $name, string $externalId = '', string $description = ''): array
{
    $product = vibrations_product();
    if ($product === null) {
        return ['ok' => false, 'message' => 'Vibrations no está configurado en la base de datos.'];
    }

    $organizationId = current_organization_id();
    if ($organizationId <= 0) {
        return ['ok' => false, 'message' => 'No hay una organización activa para crear el fenómeno.'];
    }

    $name = vibrations_trim($name, 190);
    if ($name === '') {
        return ['ok' => false, 'message' => 'Debes indicar el nombre del fenómeno observado.'];
    }

    $externalKey = vibrations_external_key(vibrations_trim($externalId, 120), $name);
    $description = vibrations_trim($description, 255);

    $stmt = db()->prepare(
        'INSERT INTO vibration_phenomena (user_id, organization_id, product_id, name, external_id, description, is_active)
         VALUES (:user_id, :organization_id, :product_id, :name, :external_id, :description, 1)
         ON DUPLICATE KEY UPDATE
           name = VALUES(name),
           description = VALUES(description),
           is_active = 1,
           updated_at = NOW()'
    );
    $stmt->execute([
        'user_id' => $userId,
        'organization_id' => $organizationId,
        'product_id' => (int) $product['id'],
        'name' => $name,
        'external_id' => $externalKey,
        'description' => $description,
    ]);

    $select = db()->prepare('SELECT id FROM vibration_phenomena WHERE organization_id = :organization_id AND user_id = :user_id AND external_id = :external_id LIMIT 1');
    $select->execute([
        'organization_id' => $organizationId,
        'user_id' => $userId,
        'external_id' => $externalKey,
    ]);

    return ['ok' => true, 'phenomenon_id' => (int) $select->fetchColumn()];
}

function get_vibration_phenomenon_by_id(int $phenomenonId): ?array
{
    ensure_vibrations_schema();

    $stmt = db()->prepare('SELECT * FROM vibration_phenomena WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $phenomenonId]);
    $row = $stmt->fetch();

    return $row ?: null;
}

function list_vibration_phenomena_for_user(int $userId, ?int $organizationId = null, bool $includeAll = false): array
{
    ensure_vibrations_schema();
    $organizationId = $organizationId ?? current_organization_id();
    $where = $includeAll ? 'organization_id = :organization_id' : 'organization_id = :organization_id AND user_id = :user_id';

    $stmt = db()->prepare(
        "SELECT * FROM vibration_phenomena
         WHERE {$where}
           AND is_active = 1
         ORDER BY name ASC, id DESC"
    );
    $stmt->bindValue('organization_id', $organizationId, PDO::PARAM_INT);
    if (!$includeAll) {
        $stmt->bindValue('user_id', $userId, PDO::PARAM_INT);
    }
    $stmt->execute();
    return $stmt->fetchAll();
}

function create_vibration_job_record(int $userId, int $productId, ?int $phenomenonId, string $originalFilename, string $phenomenonLabel, string $externalId, string $baselineScope, string $mimeType, int $sizeBytes, string $datPath, string $datUrl, int $windowMs): int
{
    ensure_vibrations_schema();
    $organizationId = current_organization_id();
    if ($organizationId <= 0) {
        throw new RuntimeException('No hay una organización activa para crear el análisis.');
    }

    $stmt = db()->prepare(
        'INSERT INTO vibration_jobs (user_id, organization_id, product_id, phenomenon_id, original_filename, phenomenon_label, external_id, baseline_scope, mime_type, dat_size_bytes, dat_path, dat_url, window_ms, status) VALUES (:user_id, :organization_id, :product_id, :phenomenon_id, :original_filename, :phenomenon_label, :external_id, :baseline_scope, :mime_type, :dat_size_bytes, :dat_path, :dat_url, :window_ms, :status)'
    );
    $stmt->execute([
        'user_id' => $userId,
        'organization_id' => $organizationId,
        'product_id' => $productId,
        'phenomenon_id' => $phenomenonId,
        'original_filename' => $originalFilename,
        'phenomenon_label' => $phenomenonLabel,
        'external_id' => $externalId,
        'baseline_scope' => $baselineScope,
        'mime_type' => $mimeType,
        'dat_size_bytes' => $sizeBytes,
        'dat_path' => $datPath,
        'dat_url' => $datUrl,
        'window_ms' => $windowMs,
        'status' => 'processing',
    ]);

    return (int) db()->lastInsertId();
}

function finalize_vibration_job(int $jobId, string $status, ?string $analysisPath = null, ?string $analysisUrl = null, ?string $errorMessage = null, ?int $baselineJobId = null, ?float $baselineDistanceScore = null, ?array $baselineSummary = null): void
{
    $stmt = db()->prepare(
        'UPDATE vibration_jobs SET status = :status, analysis_path = :analysis_path, analysis_url = :analysis_url, error_message = :error_message, baseline_job_id = :baseline_job_id, baseline_distance_score = :baseline_distance_score, baseline_summary_json = :baseline_summary_json, processed_at = NOW(), updated_at = NOW() WHERE id = :id'
    );
    $stmt->execute([
        'status' => $status,
        'analysis_path' => $analysisPath,
        'analysis_url' => $analysisUrl,
        'error_message' => $errorMessage,
        'baseline_job_id' => $baselineJobId,
        'baseline_distance_score' => $baselineDistanceScore,
        'baseline_summary_json' => $baselineSummary === null ? null : json_encode($baselineSummary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'id' => $jobId,
    ]);
}

function delete_vibration_job_record(int $jobId): array
{
    $job = get_vibration_job_by_id($jobId);
    if ($job === null) {
        return ['ok' => false, 'message' => 'Registro no encontrado.'];
    }

    foreach (['dat_path', 'analysis_path'] as $pathKey) {
        $path = (string) ($job[$pathKey] ?? '');
        if ($path !== '' && is_file($path)) {
            @unlink($path);
        }
    }

    $stmt = db()->prepare('DELETE FROM vibration_jobs WHERE id = :id');
    $stmt->execute(['id' => $jobId]);

    return ['ok' => true];
}

function vibrations_call_api(string $datPath, string $mimeType, string $originalFilename, int $windowMs): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'message' => 'La extensión cURL no está disponible en PHP.'];
    }

    $ch = curl_init(vibrations_api_url());
    if ($ch === false) {
        return ['ok' => false, 'message' => 'No fue posible inicializar la conexión con la API.'];
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'dat_file' => new CURLFile($datPath, $mimeType, $originalFilename),
            'window_ms' => (string) $windowMs,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => vibrations_timeout_seconds(),
        CURLOPT_HTTPHEADER => ['Expect:'],
    ]);

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return ['ok' => false, 'message' => $error !== '' ? $error : 'La API no devolvió respuesta.'];
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        $decodedError = json_decode((string) $response, true);
        $detail = is_array($decodedError) ? (string) ($decodedError['detail'] ?? '') : '';
        return ['ok' => false, 'message' => $detail !== '' ? $detail : 'La API devolvió un error HTTP ' . $httpCode . '.'];
    }

    $decoded = json_decode((string) $response, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'message' => 'La API devolvió una respuesta JSON no válida.'];
    }

    return ['ok' => true, 'payload' => $decoded];
}

function vibrations_metric_numeric_value(mixed $value): ?float
{
    if (is_int($value) || is_float($value)) {
        return (float) $value;
    }

    if (is_string($value) && is_numeric($value)) {
        return (float) $value;
    }

    return null;
}

function vibrations_metric_rows_from_analysis(array $analysis): array
{
    $metricas = $analysis['metricas'] ?? null;
    if (!is_array($metricas)) {
        return [];
    }

    $rows = [];
    foreach (($metricas['grupos'] ?? []) as $group) {
        if (!is_array($group)) {
            continue;
        }

        foreach (($group['metricas'] ?? []) as $metric) {
            if (!is_array($metric)) {
                continue;
            }

            $value = $metric['valor'] ?? null;
            $metricKey = (string) ($metric['clave'] ?? '');
            $rows[] = [
                'metric_group_key' => (string) ($group['clave'] ?? 'general'),
                'metric_group_label' => (string) ($group['etiqueta'] ?? 'General'),
                'metric_key' => $metricKey,
                'metric_label' => (string) ($metric['etiqueta'] ?? ($metric['clave'] ?? '')),
                'metric_value_text' => is_scalar($value) || $value === null ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'metric_value_number' => vibrations_metric_numeric_value($value),
                'unit' => (string) ($metric['unidad'] ?? ''),
                'source_path' => (string) ($metric['fuente'] ?? ''),
                'description' => (string) ($metric['descripcion'] ?? ''),
            ];
        }
    }

    return array_values(array_filter($rows, static fn(array $row): bool => $row['metric_key'] !== ''));
}

function vibrations_numeric_metrics_from_analysis(array $analysis): array
{
    $metrics = [];
    foreach (vibrations_metric_rows_from_analysis($analysis) as $row) {
        if (is_numeric($row['metric_value_number'] ?? null)) {
            $metrics[(string) $row['metric_key']] = (float) $row['metric_value_number'];
        }
    }

    return $metrics;
}

function find_vibration_baseline(int $organizationId, string $baselineScope): ?array
{
    ensure_vibrations_schema();
    if ($organizationId <= 0 || $baselineScope === '') {
        return null;
    }

    $stmt = db()->prepare(
        "SELECT * FROM vibration_jobs
         WHERE organization_id = :organization_id
           AND baseline_scope = :baseline_scope
           AND is_baseline = 1
           AND status = 'completed'
         ORDER BY processed_at DESC, id DESC
         LIMIT 1"
    );
    $stmt->execute([
        'organization_id' => $organizationId,
        'baseline_scope' => $baselineScope,
    ]);
    $row = $stmt->fetch();

    return $row ?: null;
}

function set_vibration_baseline(int $jobId): array
{
    $job = get_vibration_job_by_id($jobId);
    if ($job === null) {
        return ['ok' => false, 'message' => 'Registro no encontrado.'];
    }

    if ((string) ($job['status'] ?? '') !== 'completed') {
        return ['ok' => false, 'message' => 'Solo un análisis completado puede ser baseline.'];
    }

    $organizationId = (int) ($job['organization_id'] ?? 0);
    $phenomenonId = (int) ($job['phenomenon_id'] ?? 0);
    $baselineScope = (string) ($job['baseline_scope'] ?? '');
    if ($organizationId <= 0 || ($phenomenonId <= 0 && $baselineScope === '')) {
        return ['ok' => false, 'message' => 'El registro no tiene scope válido para baseline.'];
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        if ($phenomenonId > 0) {
            $clear = $pdo->prepare('UPDATE vibration_jobs SET is_baseline = 0 WHERE organization_id = :organization_id AND phenomenon_id = :phenomenon_id');
            $clear->execute(['organization_id' => $organizationId, 'phenomenon_id' => $phenomenonId]);

            $phenomenonUpdate = $pdo->prepare('UPDATE vibration_phenomena SET baseline_job_id = :job_id, updated_at = NOW() WHERE id = :phenomenon_id');
            $phenomenonUpdate->execute(['job_id' => $jobId, 'phenomenon_id' => $phenomenonId]);
        } else {
            $clear = $pdo->prepare('UPDATE vibration_jobs SET is_baseline = 0 WHERE organization_id = :organization_id AND baseline_scope = :baseline_scope');
            $clear->execute(['organization_id' => $organizationId, 'baseline_scope' => $baselineScope]);
        }

        $mark = $pdo->prepare('UPDATE vibration_jobs SET is_baseline = 1, baseline_job_id = NULL, baseline_distance_score = NULL, baseline_summary_json = NULL, updated_at = NOW() WHERE id = :id');
        $mark->execute(['id' => $jobId]);

        $pdo->commit();
        return ['ok' => true];
    } catch (Throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'message' => 'No fue posible marcar el baseline.'];
    }
}

function vibrations_compare_to_baseline(array $analysis, array $baselineJob): ?array
{
    $baselineAnalysis = vibrations_load_analysis_for_job($baselineJob);
    if (!is_array($baselineAnalysis)) {
        return null;
    }

    $currentMetrics = vibrations_numeric_metrics_from_analysis($analysis);
    $baselineMetrics = vibrations_numeric_metrics_from_analysis($baselineAnalysis);
    $sharedKeys = array_values(array_intersect(array_keys($currentMetrics), array_keys($baselineMetrics)));
    $sharedKeys = array_values(array_filter($sharedKeys, static fn(string $key): bool => !str_ends_with($key, '_sample_count')));

    if ($sharedKeys === []) {
        return null;
    }

    $differences = [];
    $squared = [];
    foreach ($sharedKeys as $key) {
        $current = $currentMetrics[$key];
        $baseline = $baselineMetrics[$key];
        $absoluteDelta = $current - $baseline;
        $denominator = max(abs($baseline), 1.0);
        $relativeDelta = $absoluteDelta / $denominator;
        $squared[] = $relativeDelta * $relativeDelta;
        $differences[] = [
            'metric_key' => $key,
            'baseline' => $baseline,
            'current' => $current,
            'absolute_delta' => $absoluteDelta,
            'relative_delta' => $relativeDelta,
            'relative_delta_percent' => $relativeDelta * 100.0,
        ];
    }

    usort(
        $differences,
        static fn(array $left, array $right): int => abs((float) $right['relative_delta']) <=> abs((float) $left['relative_delta'])
    );

    $distanceScore = sqrt(array_sum($squared) / count($squared)) * 100.0;

    return [
        'baseline_job_id' => (int) $baselineJob['id'],
        'baseline_created_at' => (string) ($baselineJob['created_at'] ?? ''),
        'compared_metric_count' => count($sharedKeys),
        'distance_score' => round($distanceScore, 6),
        'severity' => vibrations_baseline_severity($distanceScore),
        'top_differences' => array_slice($differences, 0, 8),
    ];
}

function vibrations_baseline_severity(float $distanceScore): string
{
    if ($distanceScore >= 75.0) {
        return 'critical';
    }

    if ($distanceScore >= 35.0) {
        return 'high';
    }

    if ($distanceScore >= 15.0) {
        return 'medium';
    }

    return 'normal';
}

function handle_vibrations_upload(int $userId, array $file, array $form): array
{
    $product = vibrations_product();
    if ($product === null) {
        return ['ok' => false, 'message' => 'Vibrations no está configurado en la base de datos.'];
    }

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'message' => 'Debes adjuntar un archivo .dat válido.'];
    }

    $tmpPath = (string) ($file['tmp_name'] ?? '');
    $originalFilename = trim((string) ($file['name'] ?? 'capture.dat'));
    $sizeBytes = (int) ($file['size'] ?? 0);
    $mimeType = trim((string) ($file['type'] ?? 'text/plain'));
    $extension = strtolower((string) pathinfo($originalFilename, PATHINFO_EXTENSION));
    $windowMs = (int) ($form['window_ms'] ?? 500);
    $windowMs = max(100, min(5000, $windowMs));
    $phenomenonId = (int) ($form['phenomenon_id'] ?? 0);
    $phenomenon = $phenomenonId > 0 ? get_vibration_phenomenon_by_id($phenomenonId) : null;

    if ($phenomenon === null) {
        $createPhenomenon = create_vibration_phenomenon(
            $userId,
            (string) ($form['phenomenon_label'] ?? ''),
            (string) ($form['external_id'] ?? ''),
            (string) ($form['phenomenon_description'] ?? '')
        );
        if (($createPhenomenon['ok'] ?? false) !== true) {
            return ['ok' => false, 'message' => (string) ($createPhenomenon['message'] ?? 'No fue posible crear el fenómeno observado.')];
        }
        $phenomenonId = (int) ($createPhenomenon['phenomenon_id'] ?? 0);
        $phenomenon = get_vibration_phenomenon_by_id($phenomenonId);
    }

    if ($phenomenon === null || (int) ($phenomenon['organization_id'] ?? 0) !== current_organization_id()) {
        return ['ok' => false, 'message' => 'El fenómeno observado no es válido para esta organización.'];
    }

    if (!can_administer_product('vibrations') && (int) ($phenomenon['user_id'] ?? 0) !== $userId) {
        return ['ok' => false, 'message' => 'No tienes acceso a este fenómeno observado.'];
    }

    $phenomenonLabel = vibrations_trim((string) ($phenomenon['name'] ?? ''), 190);
    $externalId = vibrations_trim((string) ($phenomenon['external_id'] ?? ''), 120);
    $baselineScope = 'phenomenon:' . $phenomenonId;

    if ($sizeBytes <= 0) {
        return ['ok' => false, 'message' => 'El archivo .dat está vacío.'];
    }

    if ($sizeBytes > vibrations_max_upload_bytes()) {
        return ['ok' => false, 'message' => 'El archivo supera el tamaño máximo permitido.'];
    }

    if ($extension !== 'dat') {
        return ['ok' => false, 'message' => 'El archivo debe tener extensión .dat.'];
    }

    $uploadsDir = vibrations_storage_dir('uploads');
    $resultsDir = vibrations_storage_dir('results');
    vibrations_ensure_directory($uploadsDir);
    vibrations_ensure_directory($resultsDir);

    $baseName = 'dats_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6));
    $datFilename = $baseName . '.dat';
    $datPath = $uploadsDir . '/' . $datFilename;
    $datUrl = vibrations_public_url('uploads', $datFilename);

    if (!move_uploaded_file($tmpPath, $datPath)) {
        return ['ok' => false, 'message' => 'No fue posible guardar el archivo subido.'];
    }

    $productId = (int) $product['id'];
    $jobId = create_vibration_job_record($userId, $productId, $phenomenonId, $originalFilename, $phenomenonLabel, $externalId, $baselineScope, $mimeType, $sizeBytes, $datPath, $datUrl, $windowMs);
    $coinCharge = consume_product_coin($userId, 'vibrations', 'vibration_jobs', $jobId, 'Procesamiento de archivo DATS en Vibrations');
    if (($coinCharge['ok'] ?? false) !== true) {
        delete_vibration_job_record($jobId);
        return ['ok' => false, 'message' => (string) ($coinCharge['message'] ?? 'No tienes coins disponibles para Vibrations.')];
    }

    $analysisEngineId = ensure_analysis_engine(
        $productId,
        'vibrations_dats',
        'Vibrations DATS',
        'API de análisis de acelerómetro y giroscopio con ventanas de observación.',
        vibrations_api_url(),
        'metricas.v1'
    );
    $analysisJobId = create_analysis_job([
        'user_id' => $userId,
        'product_id' => $productId,
        'analysis_engine_id' => $analysisEngineId,
        'source_job_type' => 'vibration_jobs',
        'source_job_id' => $jobId,
        'input_title' => $phenomenonLabel !== '' ? $phenomenonLabel : $originalFilename,
        'input_description' => $externalId,
        'input_filename' => $originalFilename,
        'input_mime_type' => $mimeType,
        'input_size_bytes' => $sizeBytes,
        'status' => 'processing',
        'request_payload' => ['window_ms' => $windowMs],
    ]);

    $apiResult = vibrations_call_api($datPath, $mimeType, $originalFilename, $windowMs);
    if (($apiResult['ok'] ?? false) !== true) {
        $errorMessage = (string) ($apiResult['message'] ?? 'La API devolvió un error.');
        finalize_vibration_job($jobId, 'failed', null, null, $errorMessage);
        finalize_analysis_job($analysisJobId, 'failed', null, $errorMessage);
        refund_product_coin($userId, 'vibrations', 'vibration_jobs', $jobId, 'Reembolso por fallo del procesamiento Vibrations');
        return ['ok' => false, 'message' => $errorMessage];
    }

    $analysisPayload = $apiResult['payload'] ?? null;
    if (!is_array($analysisPayload)) {
        finalize_vibration_job($jobId, 'failed', null, null, 'La API no devolvió un análisis interpretable.');
        finalize_analysis_job($analysisJobId, 'failed', null, 'La API no devolvió un análisis interpretable.');
        refund_product_coin($userId, 'vibrations', 'vibration_jobs', $jobId, 'Reembolso por respuesta no interpretable de Vibrations');
        return ['ok' => false, 'message' => 'La API no devolvió un análisis interpretable.'];
    }

    $analysisFilename = $baseName . '.json';
    $analysisPath = $resultsDir . '/' . $analysisFilename;
    $analysisUrl = vibrations_public_url('results', $analysisFilename);
    $analysisJson = json_encode($analysisPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($analysisJson) || file_put_contents($analysisPath, $analysisJson) === false) {
        finalize_vibration_job($jobId, 'failed', null, null, 'No fue posible guardar el análisis generado.');
        finalize_analysis_job($analysisJobId, 'failed', $analysisPayload, 'No fue posible guardar el análisis generado.');
        refund_product_coin($userId, 'vibrations', 'vibration_jobs', $jobId, 'Reembolso por fallo guardando análisis Vibrations');
        return ['ok' => false, 'message' => 'No fue posible guardar el análisis generado.'];
    }

    $baseline = null;
    if ((int) ($phenomenon['baseline_job_id'] ?? 0) > 0) {
        $baseline = get_vibration_job_by_id((int) $phenomenon['baseline_job_id']);
    }
    if ($baseline === null) {
        $baseline = find_vibration_baseline(current_organization_id(), $baselineScope);
    }
    $baselineSummary = $baseline === null ? null : vibrations_compare_to_baseline($analysisPayload, $baseline);
    $baselineJobId = $baselineSummary === null ? null : (int) $baselineSummary['baseline_job_id'];
    $baselineDistanceScore = $baselineSummary === null ? null : (float) $baselineSummary['distance_score'];

    try {
        persist_analysis_metrics($analysisJobId, $userId, $productId, vibrations_metric_rows_from_analysis($analysisPayload));
    } catch (Throwable) {
        finalize_vibration_job($jobId, 'failed', $analysisPath, $analysisUrl, 'No fue posible persistir las métricas del análisis.');
        finalize_analysis_job($analysisJobId, 'failed', $analysisPayload, 'No fue posible persistir las métricas del análisis.');
        refund_product_coin($userId, 'vibrations', 'vibration_jobs', $jobId, 'Reembolso por fallo persistiendo métricas Vibrations');
        return ['ok' => false, 'message' => 'El análisis se generó, pero no fue posible persistir sus métricas.'];
    }

    persist_analysis_artifact($analysisJobId, 'input_dats', $originalFilename, $mimeType, $datPath, $datUrl, ['window_ms' => $windowMs]);
    persist_analysis_artifact($analysisJobId, 'analysis_json', 'Análisis Vibrations', 'application/json', $analysisPath, $analysisUrl, ['schema' => 'vibrations.v1']);
    finalize_vibration_job($jobId, 'completed', $analysisPath, $analysisUrl, null, $baselineJobId, $baselineDistanceScore, $baselineSummary);
    finalize_analysis_job($analysisJobId, 'completed', $analysisPayload, null);

    return ['ok' => true, 'job_id' => $jobId, 'analysis_job_id' => $analysisJobId];
}

function list_vibration_jobs_for_user(int $userId, ?int $organizationId = null): array
{
    ensure_vibrations_schema();
    $organizationId = $organizationId ?? current_organization_id();

    $stmt = db()->prepare(
        'SELECT id, user_id, organization_id, product_id, phenomenon_id, original_filename, phenomenon_label, external_id, baseline_scope, is_baseline, baseline_job_id, baseline_distance_score, baseline_summary_json, dat_size_bytes, dat_url, analysis_path, analysis_url, window_ms, status, error_message, created_at, processed_at FROM vibration_jobs WHERE user_id = :user_id AND organization_id = :organization_id ORDER BY created_at DESC, id DESC'
    );
    $stmt->execute(['user_id' => $userId, 'organization_id' => $organizationId]);
    return $stmt->fetchAll();
}

function list_vibration_jobs_by_phenomenon(int $phenomenonId, int $limit = 5): array
{
    ensure_vibrations_schema();
    $limit = max(1, min($limit, 100));

    $stmt = db()->prepare(
        "SELECT id, user_id, organization_id, product_id, phenomenon_id, original_filename, phenomenon_label, external_id, baseline_scope, is_baseline, baseline_job_id, baseline_distance_score, baseline_summary_json, dat_size_bytes, dat_url, analysis_path, analysis_url, window_ms, status, error_message, created_at, processed_at
         FROM vibration_jobs
         WHERE phenomenon_id = :phenomenon_id
         ORDER BY created_at DESC, id DESC
         LIMIT {$limit}"
    );
    $stmt->execute(['phenomenon_id' => $phenomenonId]);
    return $stmt->fetchAll();
}

function list_recent_vibration_jobs(int $limit = 50, ?int $organizationId = null): array
{
    ensure_vibrations_schema();
    $limit = max(1, min($limit, 200));
    $organizationId = $organizationId ?? current_organization_id();
    $where = is_system_admin() ? '1 = 1' : 'vj.organization_id = :organization_id';

    $sql = <<<SQL
        SELECT
            vj.id,
            vj.user_id,
            vj.organization_id,
            vj.phenomenon_id,
            vj.original_filename,
            vj.phenomenon_label,
            vj.external_id,
            vj.baseline_scope,
            vj.is_baseline,
            vj.baseline_job_id,
            vj.baseline_distance_score,
            vj.baseline_summary_json,
            vj.dat_size_bytes,
            vj.analysis_path,
            vj.analysis_url,
            vj.window_ms,
            vj.status,
            vj.error_message,
            vj.created_at,
            vj.processed_at,
            u.email,
            CONCAT(u.first_name, ' ', u.last_name) AS user_name
        FROM vibration_jobs vj
        INNER JOIN users u ON u.id = vj.user_id
        WHERE $where
        ORDER BY vj.created_at DESC, vj.id DESC
        LIMIT $limit
    SQL;

    $stmt = db()->prepare($sql);
    if (!is_system_admin()) {
        $stmt->bindValue('organization_id', $organizationId, PDO::PARAM_INT);
    }
    $stmt->execute();
    return $stmt->fetchAll();
}

function get_vibration_job_by_id(int $jobId): ?array
{
    ensure_vibrations_schema();

    $sql = <<<'SQL'
        SELECT
            vj.*,
            u.email,
            CONCAT(u.first_name, ' ', u.last_name) AS user_name
        FROM vibration_jobs vj
        INNER JOIN users u ON u.id = vj.user_id
        WHERE vj.id = :id
        LIMIT 1
    SQL;

    $stmt = db()->prepare($sql);
    $stmt->execute(['id' => $jobId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function vibrations_load_analysis_for_job(array $job): ?array
{
    $path = (string) ($job['analysis_path'] ?? '');
    if ($path === '' || !is_file($path)) {
        return null;
    }

    $raw = file_get_contents($path);
    if (!is_string($raw)) {
        return null;
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

function vibrations_analysis_stat(array $analysis, string $sensor, string $path): mixed
{
    $value = $analysis['sensor_summaries'][$sensor] ?? null;
    foreach (explode('.', $path) as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return null;
        }
        $value = $value[$segment];
    }

    return $value;
}

function vibrations_format_value(mixed $value, string $unit = ''): string
{
    if ($value === null || $value === '') {
        return 'n/d';
    }

    $text = is_numeric($value) ? rtrim(rtrim(number_format((float) $value, 3, '.', ''), '0'), '.') : (string) $value;
    return trim($text . ($unit !== '' ? ' ' . $unit : ''));
}
