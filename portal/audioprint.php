<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/audioprint.php';

require_product_access('audioprint');
set_current_product('audioprint');

$user = current_user();
$canAdministerAudioprint = can_administer_product('audioprint');
$currentRole = (string) (($user['primary_role_name'] ?? $user['primary_role'] ?? 'user'));
$message = null;
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? null;

    if (!verify_csrf(is_string($token) ? $token : null)) {
        $message = 'La sesion del formulario no es valida. Recarga la pagina e intentalo de nuevo.';
        $messageType = 'error';
    } else {
        $action = (string) ($_POST['action'] ?? 'upload');

        if ($action === 'delete_job') {
            $jobId = (int) ($_POST['job_id'] ?? 0);
            $job = get_audio_job_by_id($jobId);

            if ($job === null) {
                $message = 'El registro seleccionado no existe.';
                $messageType = 'error';
            } elseif (!$canAdministerAudioprint && (int) $job['user_id'] !== (int) $user['id']) {
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
            if (!is_array($upload)) {
                $message = 'Debes adjuntar un archivo de audio.';
                $messageType = 'error';
            } else {
                $result = handle_audioprint_upload((int) $user['id'], $upload);
                if (($result['ok'] ?? false) === true) {
                    $message = 'Audio procesado correctamente. Ya puedes revisar el analisis en tu historial.';
                    $messageType = 'success';
                } else {
                    $message = (string) ($result['message'] ?? 'No fue posible procesar el audio.');
                    $messageType = 'error';
                }
            }
        }
    }
}

$jobs = list_audio_jobs_for_user((int) $user['id']);
$adminJobs = $canAdministerAudioprint ? list_recent_audio_jobs(50) : [];
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
        $message = 'El analisis solicitado no existe.';
        $messageType = 'error';
    } elseif (!$canAdministerAudioprint && (int) $candidateJob['user_id'] !== (int) $user['id']) {
        $message = 'No tienes permisos para consultar este analisis.';
        $messageType = 'error';
    } else {
        $selectedAnalysisJob = audioprint_enrich_job_record($candidateJob);
        $selectedAnalysis = audioprint_load_analysis_for_job($selectedAnalysisJob);

        if (!is_array($selectedAnalysis)) {
            $message = 'Este audio todavia no tiene un analisis disponible.';
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

function audioprint_build_insights(array $analysis): array
{
    $insights = [];
    $silenceRatio = audioprint_analysis_number_any($analysis, [
        ['analysis_engine', 'quality', 'silence_ratio'],
        ['temporal_analysis', 'silence_ratio'],
    ]);
    $clippingRatio = audioprint_analysis_number_any($analysis, [
        ['analysis_engine', 'quality', 'clipping_ratio'],
        ['temporal_analysis', 'clipping_ratio'],
    ]);
    $flatness = audioprint_analysis_number_any($analysis, [
        ['analysis_engine', 'spectral_summary', 'spectral_flatness_mean'],
        ['spectral_analysis', 'flatness', 'mean'],
    ]);
    $dominantFrequency = audioprint_analysis_number_any($analysis, [
        ['analysis_engine', 'spectral_summary', 'dominant_frequency'],
        ['spectral_analysis', 'dominant_frequency_hz'],
    ]);
    $stabilityIndex = audioprint_analysis_number($analysis, ['analysis_engine', 'temporal_summary', 'stability_index']);

    if ($silenceRatio !== null) {
        $insights[] = $silenceRatio >= 0.45
            ? 'El audio contiene bastante silencio o baja energia. Este indicador es util para detectar inactividad o degradacion de captura.'
            : 'La senal mantiene actividad util durante buena parte del tiempo, lo que favorece comparaciones historicas mas consistentes.';
    }

    if ($clippingRatio !== null) {
        $insights[] = $clippingRatio > 0
            ? 'Se observan muestras cercanas al maximo digital, asi que puede haber clipping y cierta distorsion en el analisis.'
            : 'No aparecen signos relevantes de clipping, por lo que la captura parece estable desde el punto de vista dinamico.';
    }

    if ($flatness !== null) {
        $insights[] = $flatness > 0.2
            ? 'La textura espectral es relativamente ancha o ruidosa. Merece la pena vigilar cambios bruscos de flatness a lo largo del tiempo.'
            : 'La energia esta concentrada en bandas concretas, lo que apunta a un patron mas tonal o mecanico.';
    }

    if ($dominantFrequency !== null) {
        $insights[] = 'La frecuencia dominante ronda los ' . round($dominantFrequency, 1) . ' Hz y puede usarse como referencia base para detectar drift o anomalias.';
    }

    if ($stabilityIndex !== null) {
        $insights[] = 'El indice de estabilidad temporal es ' . round($stabilityIndex, 3) . ', calculado desde frames internos de 5 segundos y agregado al audio completo.';
    }

    return $insights;
}

function audioprint_metric_label(string $segment): string
{
    $labels = [
        'analysis_engine' => 'Analysis engine',
        'input_audio' => 'Audio de entrada',
        'global_features' => 'Features globales',
        'basic_features' => 'Features basicas',
        'temporal_summary' => 'Resumen temporal',
        'spectral_summary' => 'Resumen espectral',
        'cepstral_summary' => 'Resumen cepstral',
        'time_frequency_summary' => 'Tiempo-frecuencia',
        'dashboard_ready' => 'Dashboard',
        'quality' => 'Calidad',
        'framing' => 'Framing',
        'plots' => 'Graficos',
        'audio_metadata' => 'Metadatos de audio',
        'temporal_analysis' => 'Analisis temporal',
        'spectral_analysis' => 'Analisis espectral',
        'autocorrelation_analysis' => 'Autocorrelacion',
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
        'analysis_engine.quality' => 'Calidad de senal',
        'analysis_engine.input_audio' => 'Audio y framing',
        'analysis_engine.framing' => 'Audio y framing',
        'analysis_engine.global_features.basic_features' => 'Basicas',
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

    $preferredOrder = ['Calidad de senal', 'Audio y framing', 'Basicas', 'Temporal', 'Espectral', 'Cepstral', 'Tiempo-frecuencia', 'Dashboard', 'General'];
    uksort($groups, static function (string $left, string $right) use ($preferredOrder): int {
        $leftIndex = array_search($left, $preferredOrder, true);
        $rightIndex = array_search($right, $preferredOrder, true);
        $leftIndex = $leftIndex === false ? PHP_INT_MAX : $leftIndex;
        $rightIndex = $rightIndex === false ? PHP_INT_MAX : $rightIndex;
        return $leftIndex <=> $rightIndex ?: strcmp($left, $right);
    });

    return $groups;
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
    fputcsv($output, ['categoria', 'metrica', 'valor', 'unidad', 'lectura', 'ruta_json']);
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

$selectedInsights = is_array($selectedAnalysis) ? audioprint_build_insights($selectedAnalysis) : [];
$selectedMetricGroups = is_array($selectedAnalysis) ? audioprint_group_metric_rows(audioprint_build_metric_rows($selectedAnalysis)) : [];

if (
    $selectedAnalysisJob !== null
    && $selectedMetricGroups !== []
    && (string) ($_GET['download'] ?? '') === 'metrics_csv'
) {
    $csvName = 'audioprint_metrics_' . (int) $selectedAnalysisJob['id'] . '.csv';
    audioprint_output_metrics_csv($selectedMetricGroups, $csvName);
}

$csrfToken = csrf_token();
render_app_header('Audioprint | Mi espacio');
?>
<section class="page-stack">
  <section class="hero">
    <div class="portal-hero">
      <div>
        <span class="role-badge">Audioprint</span>
        <h1>Sube tu audio y guarda cada analisis con trazabilidad.</h1>
        <p class="lead">Este espacio reune tu flujo completo: subida del archivo, generacion del analisis temporal y espectral, y acceso posterior a tu historial. Tu rol actual en el producto es <strong><?= htmlspecialchars($currentRole, ENT_QUOTES, 'UTF-8') ?></strong>.</p>
      </div>
      <div class="stats-grid">
        <article class="stat-card">
          <strong><?= count($jobs) ?></strong>
          <span>Audios registrados</span>
        </article>
        <article class="stat-card">
          <strong><?= $completedJobs ?></strong>
          <span>Analisis listos</span>
        </article>
      </div>
    </div>
  </section>

  <?php if ($message !== null): ?>
    <div class="message <?= $messageType === 'error' ? 'is-error' : 'is-success' ?>">
      <strong><?= $messageType === 'error' ? 'Revision necesaria' : 'Proceso completado' ?></strong>
      <span><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></span>
    </div>
  <?php endif; ?>

  <section class="panel-grid">
    <article class="card">
      <span class="section-tag">Nuevo audio</span>
      <h2>Generar analisis</h2>
      <p>Sube un archivo de audio y Audioprint lo enviara a la API para devolverte una visual principal, metricas temporales y espectrales, y un analisis reutilizable.</p>

      <form method="post" action="/portal/audioprint.php" class="form-block" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="action" value="upload">
        <div>
          <label for="audio_file">Archivo de audio</label>
          <input id="audio_file" name="audio_file" type="file" accept=".wav,.mp3,.flac,.ogg,.m4a,audio/*" required>
        </div>
        <button class="button" type="submit">Subir y generar</button>
      </form>

      <div class="helper">
        <strong>Que ocurre al subir</strong>
        <span>El sistema guarda el audio, llama a la API, conserva la imagen principal y el JSON completo del analisis, y deja ambos artefactos enlazados a tu historial dentro del producto.</span>
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
      <h2>Evolucion de tus metricas clave</h2>
      <p>Estos graficos usan la fecha y hora de cada subida en el eje X y las metricas principales en el eje Y. Te sirven para detectar desplazamientos de comportamiento a medida que acumulas audios en Audioprint.</p>

      <div class="audioprint-trend-grid">
        <?php foreach ($availableTrendSeries as $series): ?>
          <?php
            $latestPoint = end($series['points']);
            $latestValue = is_array($latestPoint) ? (float) ($latestPoint['y'] ?? 0) : 0.0;
            $latestValueText = rtrim(rtrim(number_format($latestValue, 3, '.', ''), '0'), '.');
          ?>
          <article class="detail-card">
            <strong><?= htmlspecialchars((string) ($series['label'] ?? 'Metrica'), ENT_QUOTES, 'UTF-8') ?></strong>
            <p><?= htmlspecialchars((string) ($series['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
            <div class="audioprint-trend-meta">
              <span><?= count($series['points']) ?> mediciones</span>
              <span>Ultimo valor: <?= htmlspecialchars($latestValueText, ENT_QUOTES, 'UTF-8') ?><?= !empty($series['unit']) ? ' ' . htmlspecialchars((string) $series['unit'], ENT_QUOTES, 'UTF-8') : '' ?></span>
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
    ?>
    <article class="card" id="analysis-detail">
      <span class="section-tag">Analisis</span>
      <h2>Detalle del audio seleccionado</h2>
      <p>Este bloque se abre bajo demanda desde el historial. Cada audio conserva su visual principal, autocorrelacion y metricas descargables.</p>

      <div class="audioprint-analysis-grid">
        <div class="stack">
          <article class="detail-card audioprint-spotlight">
            <strong><?= htmlspecialchars((string) ($primaryPlot['title'] ?? 'Visual principal'), ENT_QUOTES, 'UTF-8') ?></strong>
            <p><?= htmlspecialchars((string) ($primaryPlot['description'] ?? 'Resumen visual del ultimo analisis.'), ENT_QUOTES, 'UTF-8') ?></p>
            <?php if (is_array($primaryPlot) && !empty($primaryPlot['image_base64'])): ?>
              <img class="audioprint-image" src="data:image/png;base64,<?= htmlspecialchars((string) $primaryPlot['image_base64'], ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars((string) ($primaryPlot['title'] ?? 'Visual principal'), ENT_QUOTES, 'UTF-8') ?>">
            <?php elseif (!empty($selectedAnalysisJob['scalogram_url'])): ?>
              <img class="audioprint-image" src="<?= htmlspecialchars((string) $selectedAnalysisJob['scalogram_url'], ENT_QUOTES, 'UTF-8') ?>" alt="Visual principal del analisis">
            <?php endif; ?>
            <div class="table-actions">
              <a class="button-secondary" href="<?= htmlspecialchars((string) $selectedAnalysisJob['audio_url'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noreferrer">Abrir audio</a>
              <?php if (!empty($selectedAnalysisJob['scalogram_url'])): ?>
                <a class="button" href="<?= htmlspecialchars((string) $selectedAnalysisJob['scalogram_url'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noreferrer">Abrir imagen</a>
              <?php endif; ?>
              <?php if ($selectedMetricGroups !== []): ?>
                <a class="button-secondary" href="/portal/audioprint.php?analysis_id=<?= (int) $selectedAnalysisJob['id'] ?>&download=metrics_csv">Descargar métricas CSV</a>
              <?php endif; ?>
              <a class="button-secondary" href="/portal/audioprint.php">Cerrar analisis</a>
            </div>
          </article>
        </div>

        <div class="stack">
          <div class="audioprint-summary-grid">
            <article class="feature-card">
              <strong>Frecuencia efectiva</strong>
              <p><?= htmlspecialchars(audioprint_analysis_value_any($selectedAnalysis, [
                  ['analysis_engine', 'input_audio', 'internal_sample_rate'],
                  ['audio_metadata', 'sample_rate'],
              ]), ENT_QUOTES, 'UTF-8') ?> Hz</p>
            </article>
            <article class="feature-card">
              <strong>Duracion</strong>
              <p><?= htmlspecialchars(audioprint_analysis_value_any($selectedAnalysis, [
                  ['analysis_engine', 'input_audio', 'duration_seconds'],
                  ['audio_metadata', 'duration_seconds'],
              ]), ENT_QUOTES, 'UTF-8') ?> s</p>
            </article>
            <article class="feature-card">
              <strong>Frecuencia dominante</strong>
              <p><?= htmlspecialchars(audioprint_analysis_value_any($selectedAnalysis, [
                  ['analysis_engine', 'spectral_summary', 'dominant_frequency'],
                  ['spectral_analysis', 'dominant_frequency_hz'],
              ]), ENT_QUOTES, 'UTF-8') ?> Hz</p>
            </article>
            <article class="feature-card">
              <strong>Rango dinamico</strong>
              <p><?= htmlspecialchars(audioprint_analysis_value_any($selectedAnalysis, [
                  ['analysis_engine', 'global_features', 'basic_features', 'dynamic_range_db'],
                  ['temporal_analysis', 'dynamic_range_db'],
              ]), ENT_QUOTES, 'UTF-8') ?> dB</p>
            </article>
            <article class="feature-card">
              <strong>Silencio</strong>
              <p><?= htmlspecialchars(audioprint_analysis_value_any($selectedAnalysis, [
                  ['analysis_engine', 'quality', 'silence_ratio'],
                  ['temporal_analysis', 'silence_ratio'],
              ]), ENT_QUOTES, 'UTF-8') ?></p>
            </article>
            <article class="feature-card">
              <strong>Flatness</strong>
              <p><?= htmlspecialchars(audioprint_analysis_value_any($selectedAnalysis, [
                  ['analysis_engine', 'spectral_summary', 'spectral_flatness_mean'],
                  ['spectral_analysis', 'flatness', 'mean'],
              ]), ENT_QUOTES, 'UTF-8') ?></p>
            </article>
            <article class="feature-card">
              <strong>Estabilidad temporal</strong>
              <p><?= htmlspecialchars(audioprint_analysis_value_any($selectedAnalysis, [
                  ['analysis_engine', 'temporal_summary', 'stability_index'],
              ]), ENT_QUOTES, 'UTF-8') ?></p>
            </article>
            <article class="feature-card">
              <strong>MFCC 1 medio</strong>
              <p><?= htmlspecialchars(audioprint_analysis_value_any($selectedAnalysis, [
                  ['analysis_engine', 'dashboard_ready', 'summary', 'mfcc_1_mean'],
              ]), ENT_QUOTES, 'UTF-8') ?></p>
            </article>
            <article class="feature-card">
              <strong>Energia low/mid/high</strong>
              <p>
                <?= htmlspecialchars(audioprint_analysis_value($selectedAnalysis, ['analysis_engine', 'spectral_summary', 'energy_bands', 'low']), ENT_QUOTES, 'UTF-8') ?>
                /
                <?= htmlspecialchars(audioprint_analysis_value($selectedAnalysis, ['analysis_engine', 'spectral_summary', 'energy_bands', 'mid']), ENT_QUOTES, 'UTF-8') ?>
                /
                <?= htmlspecialchars(audioprint_analysis_value($selectedAnalysis, ['analysis_engine', 'spectral_summary', 'energy_bands', 'high']), ENT_QUOTES, 'UTF-8') ?>
              </p>
            </article>
            <article class="feature-card">
              <strong>Tiempo-frecuencia</strong>
              <p><?= htmlspecialchars(audioprint_analysis_value($selectedAnalysis, ['analysis_engine', 'time_frequency_summary', 'status']), ENT_QUOTES, 'UTF-8') ?></p>
            </article>
            <article class="feature-card">
              <strong>Distancia entre picos</strong>
              <p><?= htmlspecialchars(audioprint_analysis_value($selectedAnalysis, ['autocorrelation_analysis', 'peak_distance_seconds']), ENT_QUOTES, 'UTF-8') ?> s</p>
            </article>
            <article class="feature-card">
              <strong>Picos autocorrelacion</strong>
              <p><?= htmlspecialchars(audioprint_analysis_value($selectedAnalysis, ['autocorrelation_analysis', 'peak_count']), ENT_QUOTES, 'UTF-8') ?></p>
            </article>
          </div>

          <?php if (is_array($autocorrelationPlot) && !empty($autocorrelationPlot['image_base64'])): ?>
            <article class="detail-card">
              <strong><?= htmlspecialchars((string) ($autocorrelationPlot['title'] ?? 'Autocorrelation'), ENT_QUOTES, 'UTF-8') ?></strong>
              <p><?= htmlspecialchars((string) ($autocorrelationPlot['description'] ?? 'Grafica de autocorrelacion del audio seleccionado.'), ENT_QUOTES, 'UTF-8') ?></p>
              <div class="audioprint-peak-grid">
                <span>Primer pico: <?= htmlspecialchars(audioprint_analysis_value($selectedAnalysis, ['autocorrelation_analysis', 'strongest_peak_lag_seconds']), ENT_QUOTES, 'UTF-8') ?> s</span>
                <span>Segundo pico: <?= htmlspecialchars(audioprint_analysis_value($selectedAnalysis, ['autocorrelation_analysis', 'second_peak_lag_seconds']), ENT_QUOTES, 'UTF-8') ?> s</span>
                <span>Distancia: <?= htmlspecialchars(audioprint_analysis_value($selectedAnalysis, ['autocorrelation_analysis', 'peak_distance_seconds']), ENT_QUOTES, 'UTF-8') ?> s</span>
                <span>Muestras: <?= htmlspecialchars(audioprint_analysis_value($selectedAnalysis, ['autocorrelation_analysis', 'peak_distance_samples']), ENT_QUOTES, 'UTF-8') ?></span>
              </div>
              <img class="audioprint-image" src="data:image/png;base64,<?= htmlspecialchars((string) $autocorrelationPlot['image_base64'], ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars((string) ($autocorrelationPlot['title'] ?? 'Autocorrelation'), ENT_QUOTES, 'UTF-8') ?>">
            </article>
          <?php endif; ?>
        </div>
      </div>
    </article>
  <?php endif; ?>

  <article class="card">
    <span class="section-tag">Historial</span>
    <h2>Tus audios y resultados</h2>
    <p>Todo lo que subes queda registrado con fecha, estado, enlace al audio, visual principal y JSON del analisis cuando el proceso ha finalizado. En esta tabla solo trabajas sobre tus propios audios.</p>

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
                <strong><?= htmlspecialchars($job['original_filename'], ENT_QUOTES, 'UTF-8') ?></strong>
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
                    <a class="button-secondary" href="/portal/audioprint.php?analysis_id=<?= (int) $job['id'] ?>#analysis-detail">Analisis</a>
                  <?php endif; ?>
                  <?php if (empty($job['scalogram_url']) && empty($job['analysis_available'])): ?>
                    <span class="muted">Pendiente</span>
                  <?php endif; ?>
                  <form method="post" action="/portal/audioprint.php" class="inline-form" onsubmit="return confirm('¿Estas seguro de que deseas eliminar este audio y su analisis asociado?');">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="delete_job">
                    <input type="hidden" name="job_id" value="<?= (int) $job['id'] ?>">
                    <button class="button-secondary" type="submit">Eliminar</button>
                  </form>
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
      <span class="section-tag">Administracion de producto</span>
      <h2>Historial global de Audioprint</h2>
      <p>Como admin de Audioprint puedes supervisar la operacion del producto y eliminar registros de cualquier usuario sin entrar al panel global. El admin global mantiene aparte la gestion transversal del sistema.</p>

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
                <td><?= htmlspecialchars((string) $job['original_filename'], ENT_QUOTES, 'UTF-8') ?></td>
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
                      <a class="button-secondary" href="/portal/audioprint.php?analysis_id=<?= (int) $job['id'] ?>#analysis-detail">Analisis</a>
                    <?php endif; ?>
                    <form method="post" action="/portal/audioprint.php" class="inline-form" onsubmit="return confirm('¿Estas seguro de que deseas eliminar este audio y su analisis asociado?');">
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
