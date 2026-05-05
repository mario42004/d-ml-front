<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/audioprint.php';

require_product_access('audioprint');
set_current_product('audioprint');

$user = current_user();
$canAdministerAudioprint = can_administer_product('audioprint');
$currentOrganizationId = current_organization_id();
$currentRole = (string) (($user['primary_role_name'] ?? $user['primary_role'] ?? 'user'));
$audioprintCoins = product_coin_balance((int) $user['id'], 'audioprint');
$message = null;
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? null;

    if (!verify_csrf(is_string($token) ? $token : null)) {
        $message = 'La sesión del formulario no es válida. Recarga la página e inténtalo de nuevo.';
        $messageType = 'error';
    } else {
        $action = (string) ($_POST['action'] ?? 'upload');

        if ($action === 'delete_job') {
            $jobId = (int) ($_POST['job_id'] ?? 0);
            $job = get_audio_job_by_id($jobId);

            if ($job === null) {
                $message = 'El registro seleccionado no existe.';
                $messageType = 'error';
            } elseif (!is_system_admin() && (int) ($job['organization_id'] ?? 0) !== $currentOrganizationId) {
                $message = 'No tienes permisos para eliminar este registro.';
                $messageType = 'error';
            } elseif (!$canAdministerAudioprint) {
                $message = 'No tienes permisos para eliminar este registro.';
                $messageType = 'error';
            } else {
                $result = delete_audio_job_record($jobId);
                if (($result['ok'] ?? false) === true) {
                    $message = 'Registro eliminado correctamente.';
                    $messageType = 'success';
                } else {
                    $message = (string) ($result['message'] ?? 'No fue posible eliminar el registro.');
                    $messageType = 'error';
                }
            }
        } else {
            $upload = $_FILES['audio_file'] ?? null;
            $audioDescription = is_string($_POST['audio_description'] ?? null) ? (string) $_POST['audio_description'] : '';
            if (!is_array($upload)) {
                $message = 'Debes adjuntar un archivo de audio.';
                $messageType = 'error';
            } else {
                $result = handle_audioprint_upload((int) $user['id'], $upload, $audioDescription);
                if (($result['ok'] ?? false) === true) {
                    $message = 'Audio procesado correctamente. Ya puedes revisar el análisis en tu historial.';
                    $messageType = 'success';
                } else {
                    $message = (string) ($result['message'] ?? 'No fue posible procesar el audio.');
                    $messageType = 'error';
                }
            }
        }
    }
}

$jobs = list_audio_jobs_for_user((int) $user['id'], $currentOrganizationId);
$audioprintCoins = product_coin_balance((int) $user['id'], 'audioprint');
$adminJobs = $canAdministerAudioprint ? list_recent_audio_jobs(50, $currentOrganizationId) : [];
$completedJobs = 0;
foreach ($jobs as $index => $job) {
    $jobs[$index] = audioprint_enrich_job_record($job);
    if (($jobs[$index]['status'] ?? '') === 'completed') {
        $completedJobs++;
    }
}

$trendSeries = audioprint_build_trend_series($jobs);

foreach ($adminJobs as $index => $job) {
    $adminJobs[$index] = audioprint_enrich_job_record($job);
}

$selectedAnalysisJob = null;
$selectedAnalysis = null;
$selectedAnalysisId = (int) ($_GET['analysis_id'] ?? 0);
if ($selectedAnalysisId > 0) {
    $candidateJob = get_audio_job_by_id($selectedAnalysisId);
    if ($candidateJob === null) {
        $message = 'El análisis solicitado no existe.';
        $messageType = 'error';
    } elseif (!is_system_admin() && (int) ($candidateJob['organization_id'] ?? 0) !== $currentOrganizationId) {
        $message = 'No tienes permisos para consultar este análisis.';
        $messageType = 'error';
    } elseif (!$canAdministerAudioprint && (int) $candidateJob['user_id'] !== (int) $user['id']) {
        $message = 'No tienes permisos para consultar este análisis.';
        $messageType = 'error';
    } else {
        $selectedAnalysisJob = audioprint_enrich_job_record($candidateJob);
        $selectedAnalysis = audioprint_load_analysis_for_job($selectedAnalysisJob);

        if (!is_array($selectedAnalysis)) {
            $message = 'Este audio todavía no tiene un análisis disponible.';
            $messageType = 'error';
            $selectedAnalysisJob = null;
        }
    }
}

function audioprint_analysis_value(array $source, array $path, string $fallback = 'n/d'): string
{
    $value = $source;
    foreach ($path as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return $fallback;
        }
        $value = $value[$segment];
    }

    if (is_float($value) || is_int($value)) {
        return (string) round((float) $value, 3);
    }

    return is_scalar($value) ? (string) $value : $fallback;
}

function audioprint_analysis_number(array $source, array $path): ?float
{
    $value = $source;
    foreach ($path as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return null;
        }
        $value = $value[$segment];
    }

    return is_numeric($value) ? (float) $value : null;
}

function audioprint_analysis_value_any(array $source, array $paths, string $fallback = 'n/d'): string
{
    foreach ($paths as $path) {
        if (!is_array($path)) {
            continue;
        }

        $value = audioprint_analysis_value($source, $path, "\0");
        if ($value !== "\0") {
            return $value;
        }
    }

    return $fallback;
}

function audioprint_analysis_number_any(array $source, array $paths): ?float
{
    foreach ($paths as $path) {
        if (!is_array($path)) {
            continue;
        }

        $value = audioprint_analysis_number($source, $path);
        if ($value !== null) {
            return $value;
        }
    }

    return null;
}

function audioprint_metricas_flatten(array $analysis): array
{
    $metricas = $analysis['metricas'] ?? $analysis['metrics'] ?? null;
    if (!is_array($metricas)) {
        return [];
    }

    $groups = $metricas['grupos'] ?? $metricas['groups'] ?? null;
    if (!is_array($groups)) {
        return [];
    }

    $flat = [];
    foreach ($groups as $group) {
        if (!is_array($group)) {
            continue;
        }

        $rows = $group['metricas'] ?? $group['metrics'] ?? null;
        if (!is_array($rows)) {
            continue;
        }

        foreach ($rows as $metric) {
            if (!is_array($metric)) {
                continue;
            }
            $key = (string) ($metric['clave'] ?? $metric['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $flat[$key] = $metric;
        }
    }

    return $flat;
}

function audioprint_metricas_value(array $analysis, string $key, string $fallback = 'n/d'): string
{
    $metric = audioprint_metricas_flatten($analysis)[$key] ?? null;
    if (!is_array($metric) || !array_key_exists('valor', $metric) && !array_key_exists('value', $metric)) {
        return $fallback;
    }

    return audioprint_format_metric_value($metric['valor'] ?? $metric['value']);
}

function audioprint_metricas_number(array $analysis, string $key): ?float
{
    $metric = audioprint_metricas_flatten($analysis)[$key] ?? null;
    if (!is_array($metric) || !array_key_exists('valor', $metric) && !array_key_exists('value', $metric)) {
        return null;
    }

    $value = $metric['valor'] ?? $metric['value'];
    return is_numeric($value) ? (float) $value : null;
}

function audioprint_metric_card(array $analysis, string $key, string $label, string $help, string $unit = '', string $fallback = 'n/d'): string
{
    $value = audioprint_metricas_value($analysis, $key, $fallback);
    return audioprint_metric_card_value($value, $label, $help, $unit);
}

function audioprint_metric_card_value(string $value, string $label, string $help, string $unit = ''): string
{
    $unitText = $unit !== '' && $value !== 'n/d'
        ? ' <span class="metric-unit">' . htmlspecialchars($unit, ENT_QUOTES, 'UTF-8') . '</span>'
        : '';

    return
        '<article class="feature-card">' .
        '<strong>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</strong>' .
        '<p class="metric-value">' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . $unitText . '</p>' .
        '<small>' . htmlspecialchars($help, ENT_QUOTES, 'UTF-8') . '</small>' .
        '</article>';
}

function audioprint_job_title(array $job): string
{
    $description = trim((string) ($job['audio_description'] ?? ''));
    if ($description !== '') {
        return $description;
    }

    return (string) ($job['original_filename'] ?? 'Audio sin descripción');
}

function audioprint_build_insights(array $analysis): array
{
    $insights = [];
    $silenceRatio = audioprint_metricas_number($analysis, 'silence_sample_ratio') ?? audioprint_analysis_number_any($analysis, [
        ['analysis_engine', 'quality', 'silence_ratio'],
        ['temporal_analysis', 'silence_ratio'],
    ]);
    $clippingRatio = audioprint_metricas_number($analysis, 'clipping_ratio') ?? audioprint_analysis_number_any($analysis, [
        ['analysis_engine', 'quality', 'clipping_ratio'],
        ['temporal_analysis', 'clipping_ratio'],
    ]);
    $flatness = audioprint_metricas_number($analysis, 'spectral_flatness_mean') ?? audioprint_analysis_number_any($analysis, [
        ['analysis_engine', 'spectral_summary', 'spectral_flatness_mean'],
        ['spectral_analysis', 'flatness', 'mean'],
    ]);
    $dominantFrequency = audioprint_metricas_number($analysis, 'dominant_frequency_hz') ?? audioprint_analysis_number_any($analysis, [
        ['analysis_engine', 'spectral_summary', 'dominant_frequency'],
        ['spectral_analysis', 'dominant_frequency_hz'],
    ]);
    $stabilityIndex = audioprint_metricas_number($analysis, 'stability_index') ?? audioprint_analysis_number($analysis, ['analysis_engine', 'temporal_summary', 'stability_index']);

    if ($silenceRatio !== null) {
        $insights[] = $silenceRatio >= 0.45
            ? 'El audio contiene bastante silencio o baja energía. Este indicador es útil para detectar inactividad o degradación de captura.'
            : 'La señal mantiene actividad útil durante buena parte del tiempo, lo que favorece comparaciones históricas más consistentes.';
    }

    if ($clippingRatio !== null) {
        $insights[] = $clippingRatio > 0
            ? 'Se observan muestras cercanas al máximo digital, así que puede haber clipping y cierta distorsión en el análisis.'
            : 'No aparecen signos relevantes de clipping, por lo que la captura parece estable desde el punto de vista dinámico.';
    }

    if ($flatness !== null) {
        $insights[] = $flatness > 0.2
            ? 'La textura espectral es relativamente ancha o ruidosa. Merece la pena vigilar cambios bruscos de flatness a lo largo del tiempo.'
            : 'La energía está concentrada en bandas concretas, lo que apunta a un patrón más tonal o mecánico.';
    }

    if ($dominantFrequency !== null) {
        $insights[] = 'La frecuencia dominante ronda los ' . round($dominantFrequency, 1) . ' Hz y puede usarse como referencia base para detectar drift o anomalias.';
    }

    if ($stabilityIndex !== null) {
        $insights[] = 'El índice de estabilidad temporal es ' . round($stabilityIndex, 3) . ', calculado desde frames internos de 5 segundos y agregado al audio completo.';
    }

    return $insights;
}

function audioprint_metric_label(string $segment): string
{
    $labels = [
        'analysis_engine' => 'Analysis engine',
        'input_audio' => 'Audio de entrada',
        'global_features' => 'Features globales',
        'basic_features' => 'Features básicas',
        'temporal_summary' => 'Resumen temporal',
        'spectral_summary' => 'Resumen espectral',
        'cepstral_summary' => 'Resumen cepstral',
        'time_frequency_summary' => 'Tiempo-frecuencia',
        'dashboard_ready' => 'Dashboard',
        'quality' => 'Calidad',
        'framing' => 'Framing',
        'plots' => 'Graficos',
        'audio_metadata' => 'Metadatos de audio',
        'temporal_analysis' => 'Análisis temporal',
        'spectral_analysis' => 'Análisis espectral',
        'autocorrelation_analysis' => 'Autocorrelación',
    ];

    if (isset($labels[$segment])) {
        return $labels[$segment];
    }

    return ucfirst(str_replace('_', ' ', $segment));
}

function audioprint_metric_category(array $path): string
{
    $joined = implode('.', $path);
    $rules = [
        'analysis_engine.quality' => 'Calidad de señal',
        'analysis_engine.input_audio' => 'Audio y framing',
        'analysis_engine.framing' => 'Audio y framing',
        'analysis_engine.global_features.basic_features' => 'Básicas',
        'analysis_engine.temporal_summary' => 'Temporal',
        'analysis_engine.spectral_summary' => 'Espectral',
        'analysis_engine.cepstral_summary' => 'Cepstral',
        'analysis_engine.time_frequency_summary' => 'Tiempo-frecuencia',
        'analysis_engine.dashboard_ready' => 'Dashboard',
        'audio_metadata' => 'Audio y framing',
        'temporal_analysis' => 'Temporal',
        'spectral_analysis' => 'Espectral',
        'autocorrelation_analysis' => 'Temporal',
    ];

    foreach ($rules as $prefix => $category) {
        if (str_starts_with($joined, $prefix)) {
            return $category;
        }
    }

    return 'General';
}

function audioprint_metric_unit(array $path, mixed $value): string
{
    $name = strtolower((string) end($path));
    if (str_contains($name, 'sample_rate') || str_contains($name, 'frequency') || str_ends_with($name, '_hz') || str_contains($name, 'centroid') || str_contains($name, 'rolloff')) {
        return 'Hz';
    }
    if (str_contains($name, 'duration') || str_contains($name, 'seconds') || str_ends_with($name, '_s')) {
        return 's';
    }
    if (str_contains($name, '_db') || str_contains($name, 'decibel')) {
        return 'dB';
    }
    if (str_contains($name, 'bytes') || str_contains($name, 'size')) {
        return 'bytes';
    }
    if (str_contains($name, 'ratio') || str_contains($name, 'rate') || str_contains($name, 'flatness') || str_contains($name, 'stability')) {
        return 'ratio';
    }
    if (is_bool($value)) {
        return 'bool';
    }

    return '';
}

function audioprint_metric_status(array $path, mixed $value): array
{
    $name = strtolower((string) end($path));
    $numericValue = is_numeric($value) ? (float) $value : null;

    if ($numericValue !== null && str_contains($name, 'clipping')) {
        if ($numericValue >= 0.01) {
            return ['label' => 'Alerta', 'class' => 'is-warning'];
        }
        if ($numericValue > 0) {
            return ['label' => 'Revisar', 'class' => 'is-review'];
        }
        return ['label' => 'OK', 'class' => 'is-ok'];
    }

    if ($numericValue !== null && str_contains($name, 'silence_ratio')) {
        if ($numericValue >= 0.45) {
            return ['label' => 'Revisar', 'class' => 'is-review'];
        }
        return ['label' => 'OK', 'class' => 'is-ok'];
    }

    if ($numericValue !== null && str_contains($name, 'stability')) {
        if ($numericValue < 0.35) {
            return ['label' => 'Variable', 'class' => 'is-review'];
        }
        return ['label' => 'Estable', 'class' => 'is-ok'];
    }

    if (is_string($value) && in_array(strtolower($value), ['completed', 'ok', 'available', 'enabled'], true)) {
        return ['label' => 'OK', 'class' => 'is-ok'];
    }

    return ['label' => 'Dato', 'class' => 'is-neutral'];
}

function audioprint_format_metric_value(mixed $value): string
{
    if (is_bool($value)) {
        return $value ? 'Si' : 'No';
    }
    if (is_int($value)) {
        return (string) $value;
    }
    if (is_float($value) || is_numeric($value)) {
        $number = (float) $value;
        if (abs($number) >= 1000) {
            return number_format($number, 2, '.', ',');
        }
        return rtrim(rtrim(number_format($number, 5, '.', ''), '0'), '.');
    }

    return is_scalar($value) ? (string) $value : '';
}

function audioprint_should_skip_metric(array $path, mixed $value): bool
{
    $skipSegments = ['image_base64', 'plots', 'raw', 'frame_features'];
    foreach ($path as $segment) {
        if (in_array((string) $segment, $skipSegments, true)) {
            return true;
        }
    }

    return is_string($value) && strlen($value) > 240;
}

function audioprint_build_metric_rows(array $source, array $path = []): array
{
    $rows = [];
    foreach ($source as $key => $value) {
        $currentPath = [...$path, (string) $key];

        if (is_array($value)) {
            $rows = [...$rows, ...audioprint_build_metric_rows($value, $currentPath)];
            continue;
        }

        if (audioprint_should_skip_metric($currentPath, $value)) {
            continue;
        }

        $status = audioprint_metric_status($currentPath, $value);
        $labelSegments = array_slice($currentPath, -2);
        $rows[] = [
            'category' => audioprint_metric_category($currentPath),
            'metric' => implode(' / ', array_map('audioprint_metric_label', $labelSegments)),
            'path' => implode('.', $currentPath),
            'value' => audioprint_format_metric_value($value),
            'unit' => audioprint_metric_unit($currentPath, $value),
            'status_label' => $status['label'],
            'status_class' => $status['class'],
        ];
    }

    return $rows;
}

function audioprint_group_metric_rows(array $rows): array
{
    $groups = [];
    foreach ($rows as $row) {
        $groups[$row['category']][] = $row;
    }

    $preferredOrder = ['Calidad de señal', 'Audio y framing', 'Básicas', 'Temporal', 'Espectral', 'Cepstral', 'Tiempo-frecuencia', 'Dashboard', 'General'];
    uksort($groups, static function (string $left, string $right) use ($preferredOrder): int {
        $leftIndex = array_search($left, $preferredOrder, true);
        $rightIndex = array_search($right, $preferredOrder, true);
        $leftIndex = $leftIndex === false ? PHP_INT_MAX : $leftIndex;
        $rightIndex = $rightIndex === false ? PHP_INT_MAX : $rightIndex;
        return $leftIndex <=> $rightIndex ?: strcmp($left, $right);
    });

    return $groups;
}

function audioprint_canonical_metric_groups(array $analysis): array
{
    $metrics = $analysis['metricas'] ?? $analysis['metrics'] ?? null;
    if (!is_array($metrics)) {
        return [];
    }

    $metricGroups = $metrics['grupos'] ?? $metrics['groups'] ?? null;
    if (!is_array($metricGroups)) {
        return [];
    }

    $groups = [];
    foreach ($metricGroups as $group) {
        if (!is_array($group)) {
            continue;
        }

        $rows = $group['metricas'] ?? $group['metrics'] ?? null;
        if (!is_array($rows)) {
            continue;
        }

        $category = (string) ($group['etiqueta'] ?? $group['label'] ?? $group['clave'] ?? $group['key'] ?? 'General');
        foreach ($rows as $metric) {
            if (!is_array($metric) || !array_key_exists('valor', $metric) && !array_key_exists('value', $metric)) {
                continue;
            }

            $value = $metric['valor'] ?? $metric['value'];
            $groups[$category][] = [
                'category' => $category,
                'metric' => (string) ($metric['etiqueta'] ?? $metric['label'] ?? $metric['clave'] ?? $metric['key'] ?? 'Métrica'),
                'path' => (string) ($metric['fuente'] ?? $metric['source'] ?? $metric['clave'] ?? $metric['key'] ?? ''),
                'value' => audioprint_format_metric_value($value),
                'unit' => (string) ($metric['unidad'] ?? $metric['unit'] ?? ''),
                'status_label' => (string) ($metric['descripcion'] ?? $metric['description'] ?? 'Métrica canónica'),
                'status_class' => 'is-neutral',
            ];
        }
    }

    return $groups;
}

function audioprint_metric_groups_for_csv(array $analysis): array
{
    $canonicalGroups = audioprint_canonical_metric_groups($analysis);
    if ($canonicalGroups !== []) {
        return $canonicalGroups;
    }

    return audioprint_group_metric_rows(audioprint_build_metric_rows($analysis));
}

function audioprint_output_metrics_csv(array $groups, string $filename): never
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    $output = fopen('php://output', 'wb');
    if ($output === false) {
        exit;
    }

    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, ['categoría', 'métrica', 'valor', 'unidad', 'descripción', 'fuente']);
    foreach ($groups as $category => $rows) {
        foreach ($rows as $row) {
            fputcsv($output, [
                $category,
                $row['metric'],
                $row['value'],
                $row['unit'],
                $row['status_label'],
                $row['path'],
            ]);
        }
    }

    fclose($output);
    exit;
}

function audioprint_output_metric_table_csv(array $rows, string $filename): never
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    $output = fopen('php://output', 'wb');
    if ($output === false) {
        exit;
    }

    fwrite($output, "\xEF\xBB\xBF");
    $baseColumns = [
        'analysis_id',
        'audio_job_id',
        'descripcion_audio',
        'archivo_audio',
        'audio_creado_en',
        'audio_procesado_en',
        'captured_at',
        'estado_audio',
        'mime_type',
        'audio_size_bytes',
        'user_id',
        'user_email',
        'usuario',
    ];
    $featureColumns = audioprint_feature_export_columns($rows);
    fputcsv($output, [...$baseColumns, ...$featureColumns]);

    foreach ($rows as $row) {
        $features = audioprint_decode_json_object($row['features_json'] ?? null);
        $csvRow = [
            $row['audio_job_id'] ?? '',
            $row['audio_job_id'] ?? '',
            $row['audio_description'] ?? '',
            $row['original_filename'] ?? '',
            $row['job_created_at'] ?? '',
            $row['job_processed_at'] ?? '',
            $row['captured_at'] ?? '',
            $row['job_status'] ?? '',
            $row['mime_type'] ?? '',
            $row['audio_size_bytes'] ?? '',
            $row['user_id'] ?? '',
            $row['user_email'] ?? '',
            trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? '')),
        ];

        foreach ($featureColumns as $featureKey) {
            $csvRow[] = $features[$featureKey] ?? '';
        }

        fputcsv($output, $csvRow);
    }

    fclose($output);
    exit;
}

$selectedInsights = is_array($selectedAnalysis) ? audioprint_build_insights($selectedAnalysis) : [];
$selectedPersistedMetricRows = $selectedAnalysisJob !== null ? list_audio_job_metrics((int) $selectedAnalysisJob['id']) : [];
$selectedMetricGroups = $selectedPersistedMetricRows !== []
    ? audioprint_metric_groups_from_persisted_rows($selectedPersistedMetricRows)
    : (is_array($selectedAnalysis) ? audioprint_metric_groups_for_csv($selectedAnalysis) : []);

if (
    $selectedAnalysisJob !== null
    && $selectedMetricGroups !== []
    && (string) ($_GET['download'] ?? '') === 'metrics_csv'
) {
    $csvName = 'audioprint_metrics_' . (int) $selectedAnalysisJob['id'] . '.csv';
    audioprint_output_metrics_csv($selectedMetricGroups, $csvName);
}

if ((string) ($_GET['download'] ?? '') === 'metrics_table_csv') {
    $exportAll = $canAdministerAudioprint;
    $metricRows = list_audio_job_feature_export_rows($exportAll ? null : (int) $user['id']);
    $csvName = $exportAll
        ? 'audioprint_metricas_todos_los_usuarios.csv'
        : 'audioprint_metricas_usuario_' . (int) $user['id'] . '.csv';
    audioprint_output_metric_table_csv($metricRows, $csvName);
}

$csrfToken = csrf_token();
render_app_header('Audioprint | Mi espacio');
?>
<section class="page-stack">
  <section class="hero">
    <div class="portal-hero">
      <div>
        <span class="role-badge">Audioprint</span>
        <h1>Sube tu audio y guarda cada análisis con trazabilidad.</h1>
        <p class="lead">Este espacio reúne tu flujo completo: subida del archivo, generación del análisis temporal y espectral, y acceso posterior a tu historial. Tu rol actual en el producto es <strong><?= htmlspecialchars($currentRole, ENT_QUOTES, 'UTF-8') ?></strong>.</p>
      </div>
      <div class="stats-grid">
        <article class="stat-card">
          <strong><?= count($jobs) ?></strong>
          <span>Audios registrados</span>
        </article>
        <article class="stat-card">
          <strong><?= $completedJobs ?></strong>
          <span>Análisis listos</span>
        </article>
      </div>
      <div class="table-actions">
        <a class="button" href="/portal/audioprint.php?download=metrics_table_csv">
          <?= $canAdministerAudioprint ? 'Descargar features de todos los usuarios' : 'Descargar features de todos mis audios' ?>
        </a>
      </div>
    </div>
  </section>

  <?php if ($message !== null): ?>
    <div class="message <?= $messageType === 'error' ? 'is-error' : 'is-success' ?>">
      <strong><?= $messageType === 'error' ? 'Revisión necesaria' : 'Proceso completado' ?></strong>
      <span><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></span>
    </div>
  <?php endif; ?>

  <section class="panel-grid">
    <article class="card">
      <span class="section-tag">Nuevo audio</span>
      <h2>Generar análisis</h2>
      <p>Sube un archivo de audio y Audioprint lo enviará a la API para devolverte una visual principal, métricas temporales y espectrales, y un análisis reutilizable.</p>
      <div class="coin-balance-strip">
        <strong><?= (int) $audioprintCoins ?></strong>
        <span>coins disponibles para Audioprint</span>
      </div>

      <form method="post" action="/portal/audioprint.php" class="form-block" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="action" value="upload">
        <div>
          <label for="audio_file">Archivo de audio</label>
          <input id="audio_file" name="audio_file" type="file" accept=".wav,.mp3,.flac,.ogg,.m4a,audio/*" <?= $audioprintCoins > 0 ? '' : 'disabled' ?> required>
        </div>
        <div>
          <label for="audio_description">Descripción del audio</label>
          <input id="audio_description" name="audio_description" type="text" maxlength="50" <?= $audioprintCoins > 0 ? '' : 'disabled' ?> required placeholder="Ej. Motor bomba turno mañana">
          <small class="field-help">Máximo 50 caracteres. Se mostrará en el dashboard y la autocorrelación.</small>
        </div>
        <button class="button" type="submit" <?= $audioprintCoins > 0 ? '' : 'disabled' ?>>Subir y generar</button>
      </form>

      <div class="helper">
        <strong>Qué ocurre al subir</strong>
        <span><?= $audioprintCoins > 0 ? 'Cada procesamiento completado consume 1 coin de Audioprint.' : 'No tienes coins disponibles para Audioprint. Solicita una recarga al superadmin.' ?></span>
      </div>
    </article>
  </section>

  <?php
    $availableTrendSeries = array_filter(
        $trendSeries,
        static fn (array $series): bool => count($series['points'] ?? []) >= 2
    );
  ?>
  <?php if ($availableTrendSeries !== []): ?>
    <article class="card">
      <span class="section-tag">Tendencias</span>
      <h2>Evolución de tus métricas clave</h2>
      <p>Cada punto representa un audio procesado. El eje X es la fecha del análisis y el eje Y muestra el valor de la métrica indicada.</p>

      <div class="audioprint-trend-grid">
        <?php foreach ($availableTrendSeries as $series): ?>
          <?php
            $latestPoint = end($series['points']);
            $latestValue = is_array($latestPoint) ? (float) ($latestPoint['y'] ?? 0) : 0.0;
            $latestValueText = rtrim(rtrim(number_format($latestValue, 3, '.', ''), '0'), '.');
          ?>
          <article class="detail-card">
            <strong><?= htmlspecialchars((string) ($series['label'] ?? 'Métrica'), ENT_QUOTES, 'UTF-8') ?></strong>
            <p><?= htmlspecialchars((string) ($series['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
            <p class="chart-help">Cada punto es un audio. X: fecha/hora. Y: <?= htmlspecialchars((string) ($series['axis_label'] ?? $series['unit'] ?? 'valor'), ENT_QUOTES, 'UTF-8') ?>.</p>
            <div class="audioprint-trend-meta">
              <span><?= count($series['points']) ?> mediciones</span>
              <span>Último valor: <?= htmlspecialchars($latestValueText, ENT_QUOTES, 'UTF-8') ?><?= !empty($series['unit']) ? ' ' . htmlspecialchars((string) $series['unit'], ENT_QUOTES, 'UTF-8') : '' ?></span>
            </div>
            <?= audioprint_render_trend_chart($series) ?>
          </article>
        <?php endforeach; ?>
      </div>
    </article>
  <?php endif; ?>

  <?php if ($selectedAnalysisJob !== null && $selectedAnalysis !== null): ?>
    <?php
      $primaryKey = (string) ($selectedAnalysis['primary_visualization'] ?? 'dashboard');
      $plots = is_array($selectedAnalysis['plots'] ?? null) ? $selectedAnalysis['plots'] : [];
      $primaryPlot = is_array($plots[$primaryKey] ?? null) ? $plots[$primaryKey] : null;
      $autocorrelationPlot = is_array($plots['autocorrelation'] ?? null) ? $plots['autocorrelation'] : null;
      $selectedAudioTitle = audioprint_job_title($selectedAnalysisJob);
    ?>
    <article class="card" id="analysis-detail">
      <span class="section-tag">Análisis</span>
      <h2><?= htmlspecialchars($selectedAudioTitle, ENT_QUOTES, 'UTF-8') ?></h2>
      <p>Este bloque se abre bajo demanda desde el historial. Cada audio conserva su visual principal, autocorrelación y métricas descargables.</p>

      <div class="audioprint-analysis-grid">
        <div class="stack">
          <article class="detail-card audioprint-spotlight">
            <strong>Dashboard: <?= htmlspecialchars($selectedAudioTitle, ENT_QUOTES, 'UTF-8') ?></strong>
            <div class="table-meta"><?= htmlspecialchars((string) ($selectedAnalysisJob['original_filename'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
            <p><?= htmlspecialchars((string) ($primaryPlot['description'] ?? 'Resumen visual del último análisis.'), ENT_QUOTES, 'UTF-8') ?></p>
            <?php if (is_array($primaryPlot) && !empty($primaryPlot['image_base64'])): ?>
              <img class="audioprint-image" src="data:image/png;base64,<?= htmlspecialchars((string) $primaryPlot['image_base64'], ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars((string) ($primaryPlot['title'] ?? 'Visual principal'), ENT_QUOTES, 'UTF-8') ?>">
            <?php elseif (!empty($selectedAnalysisJob['scalogram_url'])): ?>
              <img class="audioprint-image" src="<?= htmlspecialchars((string) $selectedAnalysisJob['scalogram_url'], ENT_QUOTES, 'UTF-8') ?>" alt="Visual principal del análisis">
            <?php endif; ?>
            <div class="table-actions">
              <a class="button-secondary" href="<?= htmlspecialchars((string) $selectedAnalysisJob['audio_url'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noreferrer">Abrir audio</a>
              <?php if (!empty($selectedAnalysisJob['scalogram_url'])): ?>
                <a class="button" href="<?= htmlspecialchars((string) $selectedAnalysisJob['scalogram_url'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noreferrer">Abrir imagen</a>
              <?php endif; ?>
              <?php if ($selectedMetricGroups !== []): ?>
                <a class="button-secondary" href="/portal/audioprint.php?analysis_id=<?= (int) $selectedAnalysisJob['id'] ?>&download=metrics_csv">Descargar métricas CSV</a>
              <?php endif; ?>
              <a class="button-secondary" href="/portal/audioprint.php">Cerrar análisis</a>
            </div>
          </article>

          <?php if (is_array($autocorrelationPlot) && !empty($autocorrelationPlot['image_base64'])): ?>
            <article class="detail-card">
              <strong>Autocorrelación: <?= htmlspecialchars($selectedAudioTitle, ENT_QUOTES, 'UTF-8') ?></strong>
              <div class="table-meta"><?= htmlspecialchars((string) ($selectedAnalysisJob['original_filename'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
              <p><?= htmlspecialchars((string) ($autocorrelationPlot['description'] ?? 'Gráfica de autocorrelación del audio seleccionado.'), ENT_QUOTES, 'UTF-8') ?></p>
              <div class="audioprint-peak-grid">
                <span>Primer pico: <?= htmlspecialchars(audioprint_metricas_value($selectedAnalysis, 'strongest_peak_lag_seconds', audioprint_analysis_value($selectedAnalysis, ['autocorrelation_analysis', 'strongest_peak_lag_seconds'])), ENT_QUOTES, 'UTF-8') ?> s</span>
                <span>Segundo pico: <?= htmlspecialchars(audioprint_metricas_value($selectedAnalysis, 'second_peak_lag_seconds', audioprint_analysis_value($selectedAnalysis, ['autocorrelation_analysis', 'second_peak_lag_seconds'])), ENT_QUOTES, 'UTF-8') ?> s</span>
                <span>Distancia: <?= htmlspecialchars(audioprint_metricas_value($selectedAnalysis, 'peak_distance_seconds', audioprint_analysis_value($selectedAnalysis, ['autocorrelation_analysis', 'peak_distance_seconds'])), ENT_QUOTES, 'UTF-8') ?> s</span>
                <span>Muestras: <?= htmlspecialchars(audioprint_metricas_value($selectedAnalysis, 'peak_distance_samples', audioprint_analysis_value($selectedAnalysis, ['autocorrelation_analysis', 'peak_distance_samples'])), ENT_QUOTES, 'UTF-8') ?></span>
              </div>
              <img class="audioprint-image" src="data:image/png;base64,<?= htmlspecialchars((string) $autocorrelationPlot['image_base64'], ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars((string) ($autocorrelationPlot['title'] ?? 'Autocorrelation'), ENT_QUOTES, 'UTF-8') ?>">
            </article>
          <?php endif; ?>
        </div>

        <div class="stack">
          <div class="audioprint-summary-grid">
            <?= audioprint_metric_card($selectedAnalysis, 'snr_estimate', 'SNR estimado', 'SNR estimado.', 'dB') ?>
            <?= audioprint_metric_card($selectedAnalysis, 'active_ratio', 'Actividad', 'Proporción del audio con señal activa.', 'ratio') ?>
            <?= audioprint_metric_card_value(
                audioprint_metricas_value($selectedAnalysis, 'dominant_frequency_hz', audioprint_analysis_value_any($selectedAnalysis, [
                    ['analysis_engine', 'spectral_summary', 'dominant_frequency'],
                    ['spectral_analysis', 'dominant_frequency_hz'],
                ])),
                'Frecuencia dominante',
                'Frecuencia con mayor presencia.',
                'Hz'
            ) ?>
            <?= audioprint_metric_card_value(
                audioprint_metricas_value($selectedAnalysis, 'dynamic_range_db', audioprint_analysis_value_any($selectedAnalysis, [
                    ['analysis_engine', 'global_features', 'basic_features', 'dynamic_range_db'],
                    ['temporal_analysis', 'dynamic_range_db'],
                ])),
                'Rango dinámico',
                'Diferencia entre niveles bajos y altos.',
                'dB'
            ) ?>
            <?= audioprint_metric_card($selectedAnalysis, 'silence_sample_ratio', 'Silencio', 'Proporción detectada como silencio.', 'ratio') ?>
            <?= audioprint_metric_card($selectedAnalysis, 'spectral_flatness_mean', 'Flatness espectral', 'Qué tan plano es el espectro.', 'índice') ?>
            <?= audioprint_metric_card($selectedAnalysis, 'stability_index', 'Estabilidad temporal', 'Sirve para comparar cambios entre capturas.', 'valor') ?>
            <?= audioprint_metric_card_value(
                audioprint_metricas_value($selectedAnalysis, 'mfcc_0_mean', audioprint_analysis_value_any($selectedAnalysis, [
                    ['analysis_engine', 'dashboard_ready', 'summary', 'mfcc_1_mean'],
                ])),
                'MFCC 0 medio',
                'Resumen cepstral para comparar timbre.',
                'coef.'
            ) ?>
            <?= audioprint_metric_card_value(
                audioprint_metricas_value($selectedAnalysis, 'low_band_energy_ratio', audioprint_analysis_value($selectedAnalysis, ['analysis_engine', 'spectral_summary', 'energy_bands', 'low'])) .
                    ' / ' .
                    audioprint_metricas_value($selectedAnalysis, 'mid_band_energy_ratio', audioprint_analysis_value($selectedAnalysis, ['analysis_engine', 'spectral_summary', 'energy_bands', 'mid'])) .
                    ' / ' .
                    audioprint_metricas_value($selectedAnalysis, 'high_band_energy_ratio', audioprint_analysis_value($selectedAnalysis, ['analysis_engine', 'spectral_summary', 'energy_bands', 'high'])),
                'Energía baja/media/alta',
                'Reparto de energía por bandas.',
                'ratio'
            ) ?>
            <?= audioprint_metric_card($selectedAnalysis, 'time_frequency_status', 'Tiempo-frecuencia', 'Estado del módulo, no valor físico.') ?>
            <?= audioprint_metric_card_value(
                audioprint_metricas_value($selectedAnalysis, 'peak_distance_seconds', audioprint_analysis_value($selectedAnalysis, ['autocorrelation_analysis', 'peak_distance_seconds'])),
                'Distancia entre picos',
                'Separación temporal entre picos.',
                's'
            ) ?>
            <?= audioprint_metric_card_value(
                audioprint_metricas_value($selectedAnalysis, 'autocorrelation_peak_count', audioprint_analysis_value($selectedAnalysis, ['autocorrelation_analysis', 'peak_count'])),
                'Picos autocorrelación',
                'Cantidad de picos detectados.'
            ) ?>
          </div>
        </div>
      </div>
    </article>
  <?php endif; ?>

  <article class="card">
    <span class="section-tag">Historial</span>
    <h2>Tus audios y resultados</h2>
    <p>Todo lo que subes queda registrado con fecha, estado, enlace al audio, visual principal y JSON del análisis cuando el proceso ha finalizado. En esta tabla solo trabajas sobre tus propios audios.</p>
    <div class="table-actions">
      <a class="button-secondary" href="/portal/audioprint.php?download=metrics_table_csv">
        <?= $canAdministerAudioprint ? 'Descargar features de todos los usuarios' : 'Descargar features de todos mis audios' ?>
      </a>
    </div>

    <div class="table-shell">
      <table class="users-table">
        <thead>
          <tr>
            <th>Audio</th>
            <th>Estado</th>
            <th>Fecha</th>
            <th>Audio</th>
            <th>Resultado</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($jobs as $job): ?>
            <tr>
              <td>
                <strong><?= htmlspecialchars(audioprint_job_title($job), ENT_QUOTES, 'UTF-8') ?></strong>
                <?php if (!empty($job['audio_description'])): ?>
                  <div class="table-meta"><?= htmlspecialchars((string) $job['original_filename'], ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
                <div class="table-meta"><?= htmlspecialchars((string) ($job['mime_type'] ?? 'audio'), ENT_QUOTES, 'UTF-8') ?></div>
              </td>
              <td>
                <span class="status-pill <?= ($job['status'] ?? '') === 'completed' ? 'is-active' : 'is-inactive' ?>">
                  <?= htmlspecialchars((string) $job['status'], ENT_QUOTES, 'UTF-8') ?>
                </span>
                <?php if (!empty($job['error_message'])): ?>
                  <div class="table-meta"><?= htmlspecialchars((string) $job['error_message'], ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
              </td>
              <td><?= htmlspecialchars((string) $job['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
              <td>
                <a class="button-secondary" href="<?= htmlspecialchars((string) $job['audio_url'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noreferrer">Abrir audio</a>
              </td>
              <td>
                <div class="table-actions">
                  <?php if (!empty($job['scalogram_url'])): ?>
                    <a class="button" href="<?= htmlspecialchars((string) $job['scalogram_url'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noreferrer">Ver imagen</a>
                  <?php endif; ?>
                  <?php if (!empty($job['analysis_available']) && !empty($job['analysis_url'])): ?>
                    <a class="button-secondary" href="/portal/audioprint.php?analysis_id=<?= (int) $job['id'] ?>#analysis-detail">Análisis</a>
                  <?php endif; ?>
                  <?php if (empty($job['scalogram_url']) && empty($job['analysis_available'])): ?>
                    <span class="muted">Pendiente</span>
                  <?php endif; ?>
                  <?php if ($canAdministerAudioprint): ?>
                    <form method="post" action="/portal/audioprint.php" class="inline-form" onsubmit="return confirm('¿Estás seguro de que deseas eliminar este audio y su análisis asociado?');">
                      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                      <input type="hidden" name="action" value="delete_job">
                      <input type="hidden" name="job_id" value="<?= (int) $job['id'] ?>">
                      <button class="button-secondary" type="submit">Eliminar</button>
                    </form>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </article>

  <?php if ($canAdministerAudioprint): ?>
    <article class="card">
      <span class="section-tag">Administración de producto</span>
      <h2>Historial global de Audioprint</h2>
      <p>Como admin de Audioprint puedes supervisar la operación del producto y eliminar registros de cualquier usuario sin entrar al panel global. El admin global mantiene aparte la gestión transversal del sistema.</p>

      <div class="table-shell">
        <table class="users-table">
          <thead>
            <tr>
              <th>Usuario</th>
              <th>Audio</th>
              <th>Estado</th>
              <th>Creado</th>
              <th>Acciones</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($adminJobs as $job): ?>
              <tr>
                <td>
                  <strong><?= htmlspecialchars(trim($job['first_name'] . ' ' . $job['last_name']), ENT_QUOTES, 'UTF-8') ?></strong>
                  <div class="table-meta"><?= htmlspecialchars((string) $job['email'], ENT_QUOTES, 'UTF-8') ?></div>
                </td>
                <td>
                  <strong><?= htmlspecialchars(audioprint_job_title($job), ENT_QUOTES, 'UTF-8') ?></strong>
                  <?php if (!empty($job['audio_description'])): ?>
                    <div class="table-meta"><?= htmlspecialchars((string) $job['original_filename'], ENT_QUOTES, 'UTF-8') ?></div>
                  <?php endif; ?>
                </td>
                <td>
                  <span class="status-pill <?= ($job['status'] ?? '') === 'completed' ? 'is-active' : 'is-inactive' ?>">
                    <?= htmlspecialchars((string) $job['status'], ENT_QUOTES, 'UTF-8') ?>
                  </span>
                </td>
                <td><?= htmlspecialchars((string) $job['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                <td>
                  <div class="table-actions">
                    <?php if (!empty($job['scalogram_url'])): ?>
                      <a class="button-secondary" href="<?= htmlspecialchars((string) $job['scalogram_url'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noreferrer">Ver imagen</a>
                    <?php endif; ?>
                    <?php if (!empty($job['analysis_available']) && !empty($job['analysis_url'])): ?>
                      <a class="button-secondary" href="/portal/audioprint.php?analysis_id=<?= (int) $job['id'] ?>#analysis-detail">Análisis</a>
                    <?php endif; ?>
                    <form method="post" action="/portal/audioprint.php" class="inline-form" onsubmit="return confirm('¿Estás seguro de que deseas eliminar este audio y su análisis asociado?');">
                      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                      <input type="hidden" name="action" value="delete_job">
                      <input type="hidden" name="job_id" value="<?= (int) $job['id'] ?>">
                      <button class="button-secondary" type="submit">Eliminar registro</button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </article>
  <?php endif; ?>
</section>
<?php render_app_footer(); ?>
