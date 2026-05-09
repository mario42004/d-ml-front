<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/vibrations.php';

ensure_vibrations_schema();
require_product_access('vibrations');
set_current_product('vibrations');

$user = current_user();
$membership = current_membership('vibrations');
$currentOrganizationId = current_organization_id();
$canAdministerVibrations = can_administer_product('vibrations');
$vibrationsCoins = product_coin_balance((int) $user['id'], 'vibrations');
$phenomena = list_vibration_phenomena_for_user((int) $user['id'], $currentOrganizationId, $canAdministerVibrations);
$message = null;
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? null;

    if (!verify_csrf(is_string($token) ? $token : null)) {
        $message = 'La sesión del formulario no es válida. Recarga la página e inténtalo de nuevo.';
        $messageType = 'error';
    } else {
        $action = (string) ($_POST['action'] ?? 'upload');

        if ($action === 'create_phenomenon') {
            $result = create_vibration_phenomenon(
                (int) $user['id'],
                (string) ($_POST['phenomenon_label'] ?? ''),
                (string) ($_POST['external_id'] ?? ''),
                (string) ($_POST['phenomenon_description'] ?? '')
            );
            $message = ($result['ok'] ?? false) ? 'Fenómeno creado correctamente. Ya puedes estudiarlo.' : (string) ($result['message'] ?? 'No fue posible crear el fenómeno.');
            $messageType = ($result['ok'] ?? false) ? 'success' : 'error';
            if (($result['ok'] ?? false) && (int) ($result['phenomenon_id'] ?? 0) > 0) {
                $_GET['phenomenon_id'] = (string) ((int) $result['phenomenon_id']);
            }
        } elseif ($action === 'set_baseline') {
            $jobId = (int) ($_POST['job_id'] ?? 0);
            $job = get_vibration_job_by_id($jobId);

            if ($job === null) {
                $message = 'El registro seleccionado no existe.';
                $messageType = 'error';
            } elseif (!is_system_admin() && (int) ($job['organization_id'] ?? 0) !== $currentOrganizationId) {
                $message = 'No tienes permisos para marcar esta referencia.';
                $messageType = 'error';
            } elseif (!$canAdministerVibrations && (int) $job['user_id'] !== (int) $user['id']) {
                $message = 'No tienes permisos para marcar esta referencia.';
                $messageType = 'error';
            } else {
                $result = set_vibration_baseline($jobId);
                $message = ($result['ok'] ?? false) ? 'Referencia actualizada correctamente.' : (string) ($result['message'] ?? 'No fue posible actualizar la referencia.');
                $messageType = ($result['ok'] ?? false) ? 'success' : 'error';
                if ((int) ($job['phenomenon_id'] ?? 0) > 0) {
                    $_GET['phenomenon_id'] = (string) ((int) $job['phenomenon_id']);
                }
            }
        } elseif ($action === 'delete_job') {
            $jobId = (int) ($_POST['job_id'] ?? 0);
            $job = get_vibration_job_by_id($jobId);

            if ($job === null) {
                $message = 'El registro seleccionado no existe.';
                $messageType = 'error';
            } elseif (!is_system_admin() && (int) ($job['organization_id'] ?? 0) !== $currentOrganizationId) {
                $message = 'No tienes permisos para eliminar este registro.';
                $messageType = 'error';
            } elseif (!$canAdministerVibrations) {
                $message = 'No tienes permisos para eliminar este registro.';
                $messageType = 'error';
            } else {
                $result = delete_vibration_job_record($jobId);
                $message = ($result['ok'] ?? false) ? 'Registro eliminado correctamente.' : (string) ($result['message'] ?? 'No fue posible eliminar el registro.');
                $messageType = ($result['ok'] ?? false) ? 'success' : 'error';
                if ((int) ($job['phenomenon_id'] ?? 0) > 0) {
                    $_GET['phenomenon_id'] = (string) ((int) $job['phenomenon_id']);
                }
            }
        } else {
            $upload = $_FILES['dat_file'] ?? null;
            if (!is_array($upload)) {
                $message = 'Debes adjuntar un archivo .dat.';
                $messageType = 'error';
            } else {
                $result = handle_vibrations_upload((int) $user['id'], $upload, $_POST);
                $message = ($result['ok'] ?? false) ? 'Archivo DATS procesado correctamente. Ya puedes revisar el análisis.' : (string) ($result['message'] ?? 'No fue posible procesar el archivo.');
                $messageType = ($result['ok'] ?? false) ? 'success' : 'error';
                if ((int) ($_POST['phenomenon_id'] ?? 0) > 0) {
                    $_GET['phenomenon_id'] = (string) ((int) $_POST['phenomenon_id']);
                }
            }
        }
    }
}

$jobs = list_vibration_jobs_for_user((int) $user['id'], $currentOrganizationId);
$adminJobs = $canAdministerVibrations ? list_recent_vibration_jobs(50, $currentOrganizationId) : [];
$phenomena = list_vibration_phenomena_for_user((int) $user['id'], $currentOrganizationId, $canAdministerVibrations);
$jobsByPhenomenon = [];
foreach ($phenomena as $phenomenon) {
    $jobsByPhenomenon[(int) $phenomenon['id']] = list_vibration_jobs_by_phenomenon((int) $phenomenon['id'], 30);
}
$vibrationsCoins = product_coin_balance((int) $user['id'], 'vibrations');
$selectedJob = null;
$selectedAnalysis = null;
$selectedJobId = (int) ($_GET['job_id'] ?? 0);
if ($selectedJobId > 0) {
    $candidateJob = get_vibration_job_by_id($selectedJobId);
    if ($candidateJob === null) {
        $message = 'El análisis solicitado no existe.';
        $messageType = 'error';
    } elseif (!is_system_admin() && (int) ($candidateJob['organization_id'] ?? 0) !== $currentOrganizationId) {
        $message = 'No tienes permisos para consultar este análisis.';
        $messageType = 'error';
    } elseif (!$canAdministerVibrations && (int) $candidateJob['user_id'] !== (int) $user['id']) {
        $message = 'No tienes permisos para consultar este análisis.';
        $messageType = 'error';
    } else {
        $selectedJob = $candidateJob;
        $selectedAnalysis = vibrations_load_analysis_for_job($candidateJob);
        if (!is_array($selectedAnalysis)) {
            $message = 'Este registro todavía no tiene un análisis disponible.';
            $messageType = 'error';
            $selectedJob = null;
        }
    }
}

$selectedPhenomenonId = (int) ($_GET['phenomenon_id'] ?? 0);
if ($selectedPhenomenonId <= 0 && $selectedJob !== null) {
    $selectedPhenomenonId = (int) ($selectedJob['phenomenon_id'] ?? 0);
}
$selectedPhenomenon = null;
if ($selectedPhenomenonId > 0) {
    foreach ($phenomena as $phenomenon) {
        if ((int) $phenomenon['id'] === $selectedPhenomenonId) {
            $selectedPhenomenon = $phenomenon;
            break;
        }
    }
    if ($selectedPhenomenon === null) {
        $message = 'El fenómeno seleccionado no existe o no tienes permisos para verlo.';
        $messageType = 'error';
        $selectedPhenomenonId = 0;
    }
}
$selectedPhenomenonJobs = $selectedPhenomenonId > 0 ? ($jobsByPhenomenon[$selectedPhenomenonId] ?? []) : [];
$selectedPhenomenonCompletedJobs = array_values(array_filter($selectedPhenomenonJobs, static fn(array $job): bool => ($job['status'] ?? '') === 'completed'));

$completedJobs = count(array_filter($jobs, static fn(array $job): bool => ($job['status'] ?? '') === 'completed'));
$failedJobs = count(array_filter($jobs, static fn(array $job): bool => ($job['status'] ?? '') === 'failed'));
$baselineJobs = count(array_filter($jobs, static fn(array $job): bool => (int) ($job['is_baseline'] ?? 0) === 1));

function vibrations_output_metrics_csv(array $analysis, string $filename): never
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    $output = fopen('php://output', 'wb');
    if ($output === false) {
        exit;
    }

    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, ['grupo', 'métrica', 'clave', 'valor', 'unidad', 'descripción', 'fuente']);
    foreach (vibrations_metric_rows_from_analysis($analysis) as $row) {
        fputcsv($output, [
            $row['metric_group_label'] ?? '',
            $row['metric_label'] ?? '',
            $row['metric_key'] ?? '',
            $row['metric_value_text'] ?? '',
            $row['unit'] ?? '',
            $row['description'] ?? '',
            $row['source_path'] ?? '',
        ]);
    }

    fclose($output);
    exit;
}

function vibrations_trend_metric_value(array $analysis, string $metricKey, ?string $sensor = null, ?string $path = null): ?float
{
    $metrics = vibrations_numeric_metrics_from_analysis($analysis);
    if (is_numeric($metrics[$metricKey] ?? null)) {
        return (float) $metrics[$metricKey];
    }

    if ($sensor !== null && $path !== null) {
        $value = vibrations_analysis_stat($analysis, $sensor, $path);
        if (is_numeric($value)) {
            return (float) $value;
        }
    }

    return null;
}

function vibrations_trend_points(array $jobs, array $definition): array
{
    $points = [];
    $orderedJobs = array_reverse($jobs);
    foreach ($orderedJobs as $job) {
        if (($job['status'] ?? '') !== 'completed') {
            continue;
        }

        $analysis = vibrations_load_analysis_for_job($job);
        if (!is_array($analysis)) {
            continue;
        }

        $value = vibrations_trend_metric_value(
            $analysis,
            (string) $definition['key'],
            $definition['sensor'] ?? null,
            $definition['path'] ?? null
        );
        if ($value === null) {
            continue;
        }

        $points[] = [
            'job_id' => (int) $job['id'],
            'label' => (string) ($job['processed_at'] ?: $job['created_at']),
            'value' => $value,
            'is_baseline' => (int) ($job['is_baseline'] ?? 0) === 1,
        ];
    }

    return $points;
}

function vibrations_trend_summary_html(array $points, ?float $baselineValue, string $unit = ''): string
{
    if ($points === []) {
        return '';
    }

    $latest = $points[array_key_last($points)];
    $latestValue = (float) ($latest['value'] ?? 0.0);
    $delta = $baselineValue === null ? null : $latestValue - $baselineValue;
    $deltaPercent = null;
    if ($delta !== null) {
        $denominator = abs($baselineValue);
        $deltaPercent = $denominator < 0.000001 ? null : ($delta / $denominator) * 100.0;
    }

    $deltaText = $delta === null
        ? 'sin referencia'
        : (($delta >= 0 ? '+' : '') . vibrations_format_value($delta, $unit) . ($deltaPercent === null ? '' : ' / ' . ($deltaPercent >= 0 ? '+' : '') . vibrations_format_value($deltaPercent, '%')));

    return '<div class="vibrations-trend-stats">'
        . '<span><strong>' . htmlspecialchars(vibrations_format_value($latestValue, $unit), ENT_QUOTES, 'UTF-8') . '</strong> último</span>'
        . '<span><strong>' . htmlspecialchars($baselineValue === null ? 'n/d' : vibrations_format_value($baselineValue, $unit), ENT_QUOTES, 'UTF-8') . '</strong> referencia</span>'
        . '<span><strong>' . htmlspecialchars($deltaText, ENT_QUOTES, 'UTF-8') . '</strong> desviación</span>'
        . '</div>';
}

function vibrations_delta_percent(?float $current, ?float $reference): ?float
{
    if ($current === null || $reference === null || abs($reference) < 0.000001) {
        return null;
    }

    return (($current - $reference) / abs($reference)) * 100.0;
}

function vibrations_signed_delta_text(?float $current, ?float $reference, string $unit = ''): string
{
    if ($current === null || $reference === null) {
        return 'n/d';
    }

    $delta = $current - $reference;
    $percent = vibrations_delta_percent($current, $reference);
    $prefix = $delta >= 0 ? '+' : '';
    $text = $prefix . vibrations_format_value($delta, $unit);

    if ($percent !== null) {
        $text .= ' / ' . ($percent >= 0 ? '+' : '') . vibrations_format_value($percent, '%');
    }

    return $text;
}

function vibrations_control_status(?float $distanceOrDeltaPercent): array
{
    if ($distanceOrDeltaPercent === null) {
        return ['label' => 'Sin datos', 'class' => 'is-muted'];
    }

    $value = abs($distanceOrDeltaPercent);
    if ($value >= 75.0) {
        return ['label' => 'Cambio alto', 'class' => 'is-high'];
    }

    if ($value >= 25.0) {
        return ['label' => 'Cambio medio', 'class' => 'is-medium'];
    }

    return ['label' => 'Cerca de referencia', 'class' => 'is-stable'];
}

function vibrations_metric_display_label(string $metricKey): string
{
    $sensorLabels = [
        'accelerometer' => 'Acelerómetro',
        'gyroscope' => 'Giroscopio',
    ];
    $metricLabels = [
        'jerk_max_abs' => 'Jerk máximo absoluto',
        'jerk_rms' => 'Jerk RMS',
        'magnitude_peak_to_peak' => 'Magnitud pico a pico',
        'spectral_bandwidth_hz' => 'Ancho de banda espectral',
        'estimated_sample_rate_hz' => 'Frecuencia de muestreo estimada',
        'band_power_low' => 'Potencia en banda baja',
        'dynamic_peak_to_peak' => 'Dinámica pico a pico',
        'dynamic_peak_abs' => 'Pico dinámico absoluto',
        'dynamic_rms' => 'RMS dinámico',
        'spectral_centroid_hz' => 'Centroide espectral',
        'dominant_frequency_hz' => 'Frecuencia dominante',
    ];

    foreach ($sensorLabels as $prefix => $sensorLabel) {
        $prefixText = $prefix . '_';
        if (str_starts_with($metricKey, $prefixText)) {
            $metricName = substr($metricKey, strlen($prefixText));
            return $sensorLabel . ' - ' . ($metricLabels[$metricName] ?? ucfirst(str_replace('_', ' ', $metricName)));
        }
    }

    return ucfirst(str_replace('_', ' ', $metricKey));
}

function vibrations_svg_polyline(array $points, ?float $baselineValue = null, string $scale = 'linear'): string
{
    if ($points === []) {
        return '<div class="message is-success"><strong>Sin datos suficientes</strong><span>Sube más capturas completadas para ver la evolución.</span></div>';
    }

    $values = array_map(static fn(array $point): float => (float) $point['value'], $points);
    if ($baselineValue !== null) {
        $values[] = $baselineValue;
    }

    $rawMin = min($values);
    $rawMax = max($values);
    $allNonNegative = $rawMin >= 0.0;
    if ($allNonNegative) {
        $rawMin = 0.0;
    }
    if (abs($rawMax - $rawMin) < 0.000001) {
        if ($allNonNegative) {
            $rawMax = max(1.0, $rawMax * 1.15);
        } else {
            $rawMin -= 1.0;
            $rawMax += 1.0;
        }
    } elseif ($allNonNegative) {
        $rawMax *= 1.08;
    }

    $transform = static function (float $value) use ($scale): float {
        if ($scale === 'log') {
            return log1p(max(0.0, $value));
        }

        return $value;
    };

    $plotValues = array_map($transform, [$rawMin, $rawMax]);
    $min = min($plotValues);
    $max = max($plotValues);
    if (abs($max - $min) < 0.000001) {
        $max = $min + 1.0;
    }

    $width = 520;
    $height = 150;
    $left = 38;
    $right = 16;
    $top = 16;
    $bottom = 30;
    $plotWidth = $width - $left - $right;
    $plotHeight = $height - $top - $bottom;
    $count = count($points);

    $coords = [];
    foreach ($points as $index => $point) {
        $x = $left + ($count === 1 ? $plotWidth / 2 : ($plotWidth * $index / ($count - 1)));
        $plotValue = $transform((float) $point['value']);
        $ratio = ($plotValue - $min) / ($max - $min);
        $y = $top + $plotHeight - ($ratio * $plotHeight);
        $coords[] = round($x, 2) . ',' . round($y, 2);
    }

    $baselineLine = '';
    if ($baselineValue !== null) {
        $ratio = ($transform($baselineValue) - $min) / ($max - $min);
        $baselineY = $top + $plotHeight - ($ratio * $plotHeight);
        $baselineLine = '<line class="vibrations-chart-baseline" x1="' . $left . '" y1="' . round($baselineY, 2) . '" x2="' . ($width - $right) . '" y2="' . round($baselineY, 2) . '"></line>';
    }

    $dots = '';
    foreach ($points as $index => $point) {
        [$x, $y] = explode(',', $coords[$index]);
        $class = !empty($point['is_baseline']) ? 'vibrations-chart-dot is-baseline' : 'vibrations-chart-dot';
        $dots .= '<circle class="' . $class . '" cx="' . htmlspecialchars($x, ENT_QUOTES, 'UTF-8') . '" cy="' . htmlspecialchars($y, ENT_QUOTES, 'UTF-8') . '" r="4"></circle>';
    }

    return '<svg class="vibrations-trend-chart" viewBox="0 0 ' . $width . ' ' . $height . '" role="img" aria-label="Evolución de métrica frente a la referencia">'
        . '<line class="vibrations-chart-axis" x1="' . $left . '" y1="' . ($height - $bottom) . '" x2="' . ($width - $right) . '" y2="' . ($height - $bottom) . '"></line>'
        . '<line class="vibrations-chart-axis" x1="' . $left . '" y1="' . $top . '" x2="' . $left . '" y2="' . ($height - $bottom) . '"></line>'
        . $baselineLine
        . '<polyline class="vibrations-chart-line" points="' . htmlspecialchars(implode(' ', $coords), ENT_QUOTES, 'UTF-8') . '"></polyline>'
        . $dots
        . '<text class="vibrations-chart-label" x="' . $left . '" y="' . ($height - 8) . '">' . htmlspecialchars(vibrations_format_value($rawMin), ENT_QUOTES, 'UTF-8') . '</text>'
        . '<text class="vibrations-chart-label" x="' . ($width - 132) . '" y="' . ($height - 8) . '">' . htmlspecialchars(vibrations_format_value($rawMax), ENT_QUOTES, 'UTF-8') . '</text>'
        . '</svg>';
}

function vibrations_distance_svg(array $jobs): string
{
    $points = [];
    foreach (array_reverse($jobs) as $job) {
        if (($job['status'] ?? '') !== 'completed' || !is_numeric($job['baseline_distance_score'] ?? null)) {
            continue;
        }
        $points[] = [
            'value' => (float) $job['baseline_distance_score'],
            'is_baseline' => (int) ($job['is_baseline'] ?? 0) === 1,
            'label' => (string) ($job['processed_at'] ?: $job['created_at']),
        ];
    }

    return vibrations_svg_polyline($points, 0.0, 'log');
}

function vibrations_scalar_csv_value(mixed $value): string
{
    if (is_bool($value)) {
        return $value ? '1' : '0';
    }

    if (is_scalar($value) || $value === null) {
        return (string) $value;
    }

    return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function vibrations_output_windows_csv(array $analysis, string $filename): never
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
        'ventana',
        'inicio_s',
        'fin_s',
        'sensor',
    ];

    $payloadKeys = [];
    $rows = [];
    $windows = is_array($analysis['windows'] ?? null) ? $analysis['windows'] : [];
    foreach ($windows as $window) {
        if (!is_array($window)) {
            continue;
        }

        $sensors = is_array($window['sensors'] ?? null) ? $window['sensors'] : [];
        foreach ($sensors as $sensorName => $sensorPayload) {
            if (!is_array($sensorPayload)) {
                continue;
            }

            foreach (array_keys($sensorPayload) as $key) {
                $key = (string) $key;
                if (!in_array($key, $payloadKeys, true)) {
                    $payloadKeys[] = $key;
                }
            }

            $rows[] = [
                'base' => [
                    vibrations_scalar_csv_value($window['index'] ?? ''),
                    vibrations_scalar_csv_value($window['start_seconds'] ?? ''),
                    vibrations_scalar_csv_value($window['end_seconds'] ?? ''),
                    (string) $sensorName,
                ],
                'payload' => $sensorPayload,
            ];
        }
    }

    fputcsv($output, [...$baseColumns, ...$payloadKeys]);
    foreach ($rows as $row) {
        $payload = is_array($row['payload'] ?? null) ? $row['payload'] : [];
        $csvRow = is_array($row['base'] ?? null) ? $row['base'] : [];
        foreach ($payloadKeys as $key) {
            $csvRow[] = vibrations_scalar_csv_value($payload[$key] ?? '');
        }
        fputcsv($output, $csvRow);
    }

    fclose($output);
    exit;
}

if ($selectedJob !== null && $selectedAnalysis !== null) {
    $download = (string) ($_GET['download'] ?? '');
    if ($download === 'metrics_csv') {
        vibrations_output_metrics_csv($selectedAnalysis, 'vibrations_metricas_' . (int) $selectedJob['id'] . '.csv');
    }
    if ($download === 'windows_csv') {
        vibrations_output_windows_csv($selectedAnalysis, 'vibrations_ventanas_' . (int) $selectedJob['id'] . '.csv');
    }
}

$csrfToken = csrf_token();

render_app_header('Vibrations | Análisis DATS');
?>
<section class="page-stack">
  <section class="hero">
    <div class="dashboard-hero">
      <div>
        <span class="role-badge">Vibrations</span>
        <h1>Análisis de acelerómetro y giroscopio por ventanas de observación.</h1>
        <p class="lead">Carga archivos <code>.dat</code> capturados por sensores inerciales. La API calcula métricas globales y ventanas de 500 ms para detectar vibraciones fuertes, cambios bruscos y señales que puedan alimentar una referencia histórica.</p>
      </div>
      <div class="stats-grid">
        <article class="stat-card">
          <strong><?= (int) $vibrationsCoins ?></strong>
          <span>coins disponibles</span>
        </article>
        <article class="stat-card">
          <strong><?= count($phenomena) ?></strong>
          <span>fenómenos activos</span>
        </article>
        <article class="stat-card">
          <strong><?= $completedJobs ?></strong>
          <span>análisis completos</span>
        </article>
        <article class="stat-card">
          <strong><?= $baselineJobs ?></strong>
          <span>referencias activas</span>
        </article>
      </div>
      <div class="table-actions">
        <?php if ($selectedPhenomenon !== null): ?>
          <a class="button-secondary" href="#baseline-dashboard">Ver comparación con referencia</a>
        <?php else: ?>
          <a class="button-secondary" href="#phenomena-list">Elegir fenómeno</a>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <?php if ($message !== null): ?>
    <div class="message <?= $messageType === 'error' ? 'is-error' : 'is-success' ?>">
      <strong><?= $messageType === 'error' ? 'Revisión necesaria' : 'Operación completada' ?></strong>
      <span><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></span>
    </div>
  <?php endif; ?>

  <?php if ($selectedAnalysis !== null && $selectedJob !== null): ?>
    <?php
      $capture = is_array($selectedAnalysis['capture'] ?? null) ? $selectedAnalysis['capture'] : [];
      $sensors = is_array($capture['sensors'] ?? null) ? $capture['sensors'] : [];
      $windows = is_array($selectedAnalysis['windows'] ?? null) ? $selectedAnalysis['windows'] : [];
      $plots = is_array($selectedAnalysis['plots'] ?? null) ? $selectedAnalysis['plots'] : [];
      $baselineSummary = json_decode((string) ($selectedJob['baseline_summary_json'] ?? ''), true);
      $baselineSummary = is_array($baselineSummary) ? $baselineSummary : null;
      $strongWindows = 0;
      foreach ($windows as $window) {
          foreach (($window['sensors'] ?? []) as $sensorPayload) {
              if (is_array($sensorPayload) && ($sensorPayload['strong_change'] ?? false)) {
                  $strongWindows++;
                  break;
              }
          }
      }
    ?>
    <article class="card" id="vibrations-report">
      <div class="section-heading">
        <div>
          <span class="section-tag">Análisis</span>
          <h2><?= htmlspecialchars((string) ($selectedJob['phenomenon_label'] ?: $selectedJob['original_filename']), ENT_QUOTES, 'UTF-8') ?></h2>
          <p>Este bloque se abre bajo demanda desde el historial. Muestra el resumen del fenómeno, las visuales principales y deja las métricas completas como archivos descargables.</p>
        </div>
        <div class="table-actions">
          <a class="button-secondary" href="/portal/vibrations.php?job_id=<?= (int) $selectedJob['id'] ?>&download=metrics_csv">Descargar métricas CSV</a>
          <a class="button-secondary" href="/portal/vibrations.php?job_id=<?= (int) $selectedJob['id'] ?>&download=windows_csv">Descargar ventanas CSV</a>
          <a class="button-secondary" href="/portal/vibrations.php?phenomenon_id=<?= (int) ($selectedJob['phenomenon_id'] ?? 0) ?>#phenomenon-workspace">Cerrar análisis</a>
        </div>
      </div>

      <div class="audioprint-summary-grid">
        <article class="stat-card">
          <strong><?= vibrations_format_value($capture['duration_seconds'] ?? null, 's') ?></strong>
          <span>duración</span>
        </article>
        <article class="stat-card">
          <strong><?= htmlspecialchars(implode(', ', array_map('strval', $sensors)), ENT_QUOTES, 'UTF-8') ?></strong>
          <span>sensores</span>
        </article>
        <article class="stat-card">
          <strong><?= (int) ($capture['window_count'] ?? 0) ?></strong>
          <span>ventanas</span>
        </article>
        <article class="stat-card">
          <strong><?= $strongWindows ?></strong>
          <span>ventanas fuertes</span>
        </article>
        <article class="stat-card">
          <strong><?= ((int) ($selectedJob['is_baseline'] ?? 0)) === 1 ? 'Sí' : vibrations_format_value($selectedJob['baseline_distance_score'] ?? null, '%') ?></strong>
          <span><?= ((int) ($selectedJob['is_baseline'] ?? 0)) === 1 ? 'referencia activa' : 'distancia a referencia' ?></span>
        </article>
      </div>

      <?php if ($baselineSummary !== null): ?>
        <?php
          $distanceStatus = vibrations_control_status(is_numeric($baselineSummary['distance_score'] ?? null) ? (float) $baselineSummary['distance_score'] : null);
          $topDifferences = is_array($baselineSummary['top_differences'] ?? null) ? $baselineSummary['top_differences'] : [];
        ?>
        <div class="vibrations-reference-summary">
          <span><strong>#<?= (int) ($baselineSummary['baseline_job_id'] ?? 0) ?></strong> captura de referencia</span>
          <span><strong><?= (int) ($baselineSummary['compared_metric_count'] ?? 0) ?></strong> métricas comparadas</span>
          <span><strong><?= htmlspecialchars(vibrations_format_value($baselineSummary['distance_score'] ?? null, '%'), ENT_QUOTES, 'UTF-8') ?></strong> distancia global</span>
          <span><strong class="vibrations-control-status <?= htmlspecialchars($distanceStatus['class'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($distanceStatus['label'], ENT_QUOTES, 'UTF-8') ?></strong> estado</span>
        </div>

        <?php if ($topDifferences !== []): ?>
          <div class="table-shell">
            <table class="users-table vibrations-comparison-table">
              <thead>
                <tr>
                  <th>Métrica con mayor cambio</th>
                  <th>Referencia</th>
                  <th>Actual</th>
                  <th>Cambio</th>
                  <th>Estado</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($topDifferences as $difference): ?>
                  <?php if (!is_array($difference)) { continue; } ?>
                  <?php
                    $metricKey = (string) ($difference['metric_key'] ?? '');
                    $deltaPercent = is_numeric($difference['relative_delta_percent'] ?? null) ? (float) $difference['relative_delta_percent'] : null;
                    $status = vibrations_control_status($deltaPercent);
                  ?>
                  <tr>
                    <td>
                      <strong><?= htmlspecialchars(vibrations_metric_display_label($metricKey), ENT_QUOTES, 'UTF-8') ?></strong>
                      <span><?= htmlspecialchars($metricKey, ENT_QUOTES, 'UTF-8') ?></span>
                    </td>
                    <td><?= htmlspecialchars(vibrations_format_value($difference['baseline'] ?? null), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars(vibrations_format_value($difference['current'] ?? null), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($deltaPercent === null ? 'n/d' : (($deltaPercent >= 0 ? '+' : '') . vibrations_format_value($deltaPercent, '%')), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><span class="vibrations-control-status <?= htmlspecialchars($status['class'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($status['label'], ENT_QUOTES, 'UTF-8') ?></span></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      <?php elseif (((int) ($selectedJob['is_baseline'] ?? 0)) !== 1): ?>
        <div class="message is-success">
          <strong>Sin referencia comparable</strong>
          <span>Marca una captura completada del mismo fenómeno o ID externo como referencia para comparar nuevos archivos.</span>
        </div>
      <?php endif; ?>

      <div class="audioprint-summary-grid">
        <?php foreach ($sensors as $sensor): ?>
          <?php $sensor = (string) $sensor; ?>
          <article class="stat-card">
            <strong><?= htmlspecialchars(vibrations_format_value(vibrations_analysis_stat($selectedAnalysis, $sensor, 'dynamic.rms')), ENT_QUOTES, 'UTF-8') ?></strong>
            <span><?= htmlspecialchars($sensor, ENT_QUOTES, 'UTF-8') ?> RMS dinámico</span>
          </article>
          <article class="stat-card">
            <strong><?= htmlspecialchars(vibrations_format_value(vibrations_analysis_stat($selectedAnalysis, $sensor, 'dynamic.peak_abs')), ENT_QUOTES, 'UTF-8') ?></strong>
            <span><?= htmlspecialchars($sensor, ENT_QUOTES, 'UTF-8') ?> pico dinámico</span>
          </article>
          <article class="stat-card">
            <strong><?= htmlspecialchars(vibrations_format_value(vibrations_analysis_stat($selectedAnalysis, $sensor, 'spectrum.dominant_frequency_hz'), 'Hz'), ENT_QUOTES, 'UTF-8') ?></strong>
            <span><?= htmlspecialchars($sensor, ENT_QUOTES, 'UTF-8') ?> frecuencia dominante</span>
          </article>
        <?php endforeach; ?>
      </div>

      <?php if ($plots !== []): ?>
        <div class="audioprint-plot-grid">
          <?php foreach ($plots as $plot): ?>
            <?php
              if (!is_array($plot) || !is_string($plot['image_base64'] ?? null)) {
                  continue;
              }
              $plotTitle = (string) ($plot['title'] ?? 'Gráfico');
              $contentType = (string) ($plot['content_type'] ?? 'image/png');
            ?>
            <article class="card">
              <span class="section-tag"><?= htmlspecialchars($plotTitle, ENT_QUOTES, 'UTF-8') ?></span>
              <img class="audioprint-thumb" src="data:<?= htmlspecialchars($contentType, ENT_QUOTES, 'UTF-8') ?>;base64,<?= htmlspecialchars((string) $plot['image_base64'], ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($plotTitle, ENT_QUOTES, 'UTF-8') ?>">
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <div class="helper">
        <strong>Métricas por ventana</strong>
        <span>Las métricas de cada ventana de observación están disponibles en el CSV de ventanas para análisis posterior sin saturar esta vista.</span>
      </div>
    </article>
  <?php endif; ?>

  <article class="card" id="phenomena-list">
    <div class="section-heading">
      <div>
        <span class="section-tag">Fenómenos</span>
        <h2>Fenómenos monitoreados</h2>
        <p>Panel de control de los fenómenos disponibles. Entra a uno para cargar capturas, revisar historial, fijar referencia y ver desviaciones.</p>
      </div>
    </div>

    <?php if ($phenomena === []): ?>
      <div class="message is-success">
        <strong>Sin fenómenos todavía</strong>
        <span>Crea el primero al cargar un archivo DATS.</span>
      </div>
    <?php else: ?>
      <div class="table-shell">
        <table class="users-table">
          <thead>
            <tr>
              <th>Fenómeno</th>
              <th>ID externo</th>
              <th>Descripción</th>
              <th>Capturas</th>
              <th>Referencia</th>
              <th>Distancia a referencia</th>
              <th>Última captura</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($phenomena as $phenomenon): ?>
              <?php
                $phenomenonJobs = $jobsByPhenomenon[(int) $phenomenon['id']] ?? [];
                $latestJob = $phenomenonJobs[0] ?? null;
              ?>
              <tr>
                <td><strong><?= htmlspecialchars((string) $phenomenon['name'], ENT_QUOTES, 'UTF-8') ?></strong></td>
                <td><?= htmlspecialchars((string) (($phenomenon['external_id'] ?? '') !== '' ? $phenomenon['external_id'] : 'n/d'), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string) (($phenomenon['description'] ?? '') !== '' ? $phenomenon['description'] : 'Sin descripción'), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= count($phenomenonJobs) ?></td>
                <td>
                  <?= ((int) ($phenomenon['baseline_job_id'] ?? 0)) > 0
                      ? 'Captura #' . (int) $phenomenon['baseline_job_id']
                      : 'Sin referencia' ?>
                </td>
                <td>
                  <?= $latestJob !== null && is_numeric($latestJob['baseline_distance_score'] ?? null)
                      ? htmlspecialchars(vibrations_format_value($latestJob['baseline_distance_score'], '%'), ENT_QUOTES, 'UTF-8')
                      : 'n/d' ?>
                </td>
                <td><?= $latestJob !== null ? htmlspecialchars((string) $latestJob['created_at'], ENT_QUOTES, 'UTF-8') : 'n/d' ?></td>
                <td class="table-actions">
                  <a class="button-secondary" href="/portal/vibrations.php?phenomenon_id=<?= (int) $phenomenon['id'] ?>#phenomenon-workspace">Estudiar</a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </article>

  <article class="card">
    <span class="section-tag">Nuevo fenómeno</span>
    <h2>Crear fenómeno monitoreado</h2>
    <p>Registra primero el equipo, activo o fenómeno que quieres seguir. Después entra a su espacio para cargar archivos, elegir una referencia y revisar comparaciones.</p>
    <form method="post" action="/portal/vibrations.php" class="form-block">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="action" value="create_phenomenon">
      <div class="form-grid two">
        <div>
          <label for="create_phenomenon_label">Fenómeno observado</label>
          <input id="create_phenomenon_label" name="phenomenon_label" type="text" maxlength="190" placeholder="Motor, carro, bomba, estructura" required>
        </div>
        <div>
          <label for="create_external_id">ID externo</label>
          <input id="create_external_id" name="external_id" type="text" maxlength="120" placeholder="Equipo, activo o referencia">
        </div>
      </div>
      <div>
        <label for="create_phenomenon_description">Descripción</label>
        <input id="create_phenomenon_description" name="phenomenon_description" type="text" maxlength="255" placeholder="Contexto, ubicación, montaje o condición de medición">
      </div>
      <button class="button" type="submit">Crear fenómeno</button>
    </form>
  </article>

  <?php if ($selectedPhenomenon !== null): ?>
  <article class="card" id="phenomenon-workspace">
    <div class="section-heading">
      <div>
        <span class="section-tag">Fenómeno seleccionado</span>
        <h2><?= htmlspecialchars((string) $selectedPhenomenon['name'], ENT_QUOTES, 'UTF-8') ?></h2>
        <p><?= (string) ($selectedPhenomenon['external_id'] ?? '') !== '' ? htmlspecialchars((string) $selectedPhenomenon['external_id'], ENT_QUOTES, 'UTF-8') : 'Sin ID externo' ?></p>
      </div>
      <a class="button-secondary" href="/portal/vibrations.php">Cambiar fenómeno</a>
    </div>
    <div class="vibrations-phenomenon-metrics">
      <span><strong><?= count($selectedPhenomenonJobs) ?></strong> capturas recientes</span>
      <span><strong><?= count($selectedPhenomenonCompletedJobs) ?></strong> completadas</span>
      <span><strong><?= ((int) ($selectedPhenomenon['baseline_job_id'] ?? 0)) > 0 ? '#' . (int) $selectedPhenomenon['baseline_job_id'] : 'n/d' ?></strong> referencia</span>
    </div>
  </article>

  <details class="card vibrations-baseline-details" id="baseline-dashboard">
    <summary>
      <div>
        <span class="section-tag">Comparación con referencia</span>
        <h2>Evolución por fenómeno</h2>
        <p>Abre este cuadro para revisar distancia a la referencia y evolución histórica de métricas clave.</p>
      </div>
      <span class="button-secondary">Ver comparación</span>
    </summary>

    <?php
      $trendDefinitions = [
          [
              'key' => 'accelerometer_dynamic_rms',
              'label' => 'Acelerómetro RMS dinámico',
              'sensor' => 'accelerometer',
              'path' => 'dynamic.rms',
          ],
          [
              'key' => 'gyroscope_dynamic_rms',
              'label' => 'Giroscopio RMS dinámico',
              'sensor' => 'gyroscope',
              'path' => 'dynamic.rms',
          ],
          [
              'key' => 'accelerometer_jerk_rms',
              'label' => 'Acelerómetro jerk RMS',
              'sensor' => 'accelerometer',
              'path' => 'jerk.rms',
          ],
      ];
    ?>

    <?php if ($selectedPhenomenon !== null): ?>
      <div class="vibrations-baseline-grid">
          <?php
            $phenomenon = $selectedPhenomenon;
            $phenomenonJobs = $selectedPhenomenonJobs;
            $completedPhenomenonJobs = array_values(array_filter($phenomenonJobs, static fn(array $job): bool => ($job['status'] ?? '') === 'completed'));
            $latestCompletedJob = $completedPhenomenonJobs[0] ?? null;
            $baselineJobId = (int) ($phenomenon['baseline_job_id'] ?? 0);
            $baselineJob = $baselineJobId > 0 ? get_vibration_job_by_id($baselineJobId) : null;
            $baselineAnalysis = is_array($baselineJob) ? vibrations_load_analysis_for_job($baselineJob) : null;
            $latestAnalysis = is_array($latestCompletedJob ?? null) ? vibrations_load_analysis_for_job($latestCompletedJob) : null;
          ?>
          <section class="vibrations-baseline-card">
            <div class="vibrations-baseline-head">
              <div>
                <strong><?= htmlspecialchars((string) $phenomenon['name'], ENT_QUOTES, 'UTF-8') ?></strong>
                <?php if ((string) ($phenomenon['external_id'] ?? '') !== ''): ?>
                  <span><?= htmlspecialchars((string) $phenomenon['external_id'], ENT_QUOTES, 'UTF-8') ?></span>
                <?php endif; ?>
              </div>
              <span class="status-pill <?= $baselineJobId > 0 ? 'is-active' : 'is-inactive' ?>"><?= $baselineJobId > 0 ? 'Referencia #' . $baselineJobId : 'Sin referencia' ?></span>
            </div>

            <div class="vibrations-baseline-summary">
              <span>
                <strong><?= $latestCompletedJob !== null && is_numeric($latestCompletedJob['baseline_distance_score'] ?? null) ? htmlspecialchars(vibrations_format_value($latestCompletedJob['baseline_distance_score'], '%'), ENT_QUOTES, 'UTF-8') : 'n/d' ?></strong>
                última distancia
              </span>
              <span>
                <strong><?= count($completedPhenomenonJobs) ?></strong>
                capturas completadas
              </span>
            </div>

            <?php
              $distanceValue = is_array($latestCompletedJob) && is_numeric($latestCompletedJob['baseline_distance_score'] ?? null)
                  ? (float) $latestCompletedJob['baseline_distance_score']
                  : null;
              $distanceStatus = vibrations_control_status($distanceValue);
            ?>
            <div class="table-shell">
              <table class="users-table vibrations-comparison-table">
                <thead>
                  <tr>
                    <th>Métrica de control</th>
                    <th>Referencia</th>
                    <th>Última captura</th>
                    <th>Desviación</th>
                    <th>Estado</th>
                  </tr>
                </thead>
                <tbody>
                  <tr>
                    <td>
                      <strong>Distancia a referencia</strong>
                      <span>Índice global de separación contra la captura de referencia.</span>
                    </td>
                    <td>0 %</td>
                    <td><?= htmlspecialchars($distanceValue === null ? 'n/d' : vibrations_format_value($distanceValue, '%'), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($distanceValue === null ? 'n/d' : '+' . vibrations_format_value($distanceValue, '%'), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><span class="vibrations-control-status <?= htmlspecialchars($distanceStatus['class'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($distanceStatus['label'], ENT_QUOTES, 'UTF-8') ?></span></td>
                  </tr>

                  <?php foreach ($trendDefinitions as $definition): ?>
                    <?php
                      $currentValue = is_array($latestAnalysis)
                          ? vibrations_trend_metric_value($latestAnalysis, (string) $definition['key'], $definition['sensor'] ?? null, $definition['path'] ?? null)
                          : null;
                      $baselineValue = is_array($baselineAnalysis)
                          ? vibrations_trend_metric_value($baselineAnalysis, (string) $definition['key'], $definition['sensor'] ?? null, $definition['path'] ?? null)
                          : null;
                      $deltaPercent = vibrations_delta_percent($currentValue, $baselineValue);
                      $status = vibrations_control_status($deltaPercent);
                    ?>
                    <tr>
                      <td>
                        <strong><?= htmlspecialchars((string) $definition['label'], ENT_QUOTES, 'UTF-8') ?></strong>
                        <span>Seguimiento de cambio respecto a la referencia del fenómeno.</span>
                      </td>
                      <td><?= htmlspecialchars($baselineValue === null ? 'n/d' : vibrations_format_value($baselineValue), ENT_QUOTES, 'UTF-8') ?></td>
                      <td><?= htmlspecialchars($currentValue === null ? 'n/d' : vibrations_format_value($currentValue), ENT_QUOTES, 'UTF-8') ?></td>
                      <td><?= htmlspecialchars(vibrations_signed_delta_text($currentValue, $baselineValue), ENT_QUOTES, 'UTF-8') ?></td>
                      <td><span class="vibrations-control-status <?= htmlspecialchars($status['class'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($status['label'], ENT_QUOTES, 'UTF-8') ?></span></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </section>
      </div>
    <?php endif; ?>
  </details>

  <section class="panel-grid">
    <article class="card">
      <span class="section-tag">Nuevo análisis</span>
      <h2>Cargar archivo DATS</h2>
      <p>La captura se asociará a <?= htmlspecialchars((string) $selectedPhenomenon['name'], ENT_QUOTES, 'UTF-8') ?> y se comparará contra la referencia de este fenómeno cuando exista.</p>
      <div class="coin-balance-strip">
        <strong><?= (int) $vibrationsCoins ?></strong>
        <span>coins disponibles para Vibrations</span>
      </div>

      <form method="post" action="/portal/vibrations.php" enctype="multipart/form-data" class="form-block">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="action" value="upload">
        <input type="hidden" name="phenomenon_id" value="<?= (int) $selectedPhenomenon['id'] ?>">

        <div class="form-grid two">
          <div>
            <label for="window_ms">Ventana de observación</label>
            <select id="window_ms" name="window_ms" <?= $vibrationsCoins > 0 ? '' : 'disabled' ?>>
              <option value="500" selected>500 ms</option>
              <option value="250">250 ms</option>
              <option value="1000">1000 ms</option>
            </select>
          </div>
          <div>
            <label for="dat_file">Archivo .dat</label>
            <input id="dat_file" name="dat_file" type="file" accept=".dat,text/plain" <?= $vibrationsCoins > 0 ? '' : 'disabled' ?> required>
          </div>
        </div>

        <button class="button" type="submit" <?= $vibrationsCoins > 0 ? '' : 'disabled' ?>>Procesar DATS</button>
      </form>

      <div class="helper">
        <strong>Qué ocurre al subir</strong>
        <span><?= $vibrationsCoins > 0 ? 'Cada procesamiento completado consume 1 coin de Vibrations.' : 'No tienes coins disponibles para Vibrations. Solicita una recarga al superadmin.' ?></span>
      </div>
    </article>

    <article class="card">
      <span class="section-tag">Referencia</span>
      <h2>Comparación por fenómeno</h2>
      <p>El primer objetivo es fijar una captura representativa para cada fenómeno. A partir de ahí, cada nuevo archivo se compara contra ese punto de referencia.</p>
      <ul class="service-list">
        <li>Marca un análisis completado como referencia desde su historial.</li>
        <li>Las distancias se calculan solo dentro del mismo fenómeno.</li>
        <li>Los cambios fuertes quedan visibles en ventanas de observación.</li>
      </ul>
    </article>
  </section>

  <article class="card">
    <span class="section-tag">Historial</span>
    <h2>Capturas de <?= htmlspecialchars((string) $selectedPhenomenon['name'], ENT_QUOTES, 'UTF-8') ?></h2>
    <div class="table-shell">
      <table class="users-table">
        <thead>
          <tr>
            <th>Fecha</th>
            <th>Fenómeno</th>
            <th>Archivo</th>
            <th>Ventana</th>
            <th>Referencia</th>
            <th>Estado</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php if ($selectedPhenomenonJobs === []): ?>
            <tr><td colspan="7">Todavía no hay archivos DATS procesados.</td></tr>
          <?php endif; ?>
          <?php foreach ($selectedPhenomenonJobs as $job): ?>
            <tr>
              <td><?= htmlspecialchars((string) $job['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars((string) ($job['phenomenon_label'] ?: 'Sin etiqueta'), ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars((string) $job['original_filename'], ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= (int) $job['window_ms'] ?> ms</td>
              <td>
                <?php if ((int) ($job['is_baseline'] ?? 0) === 1): ?>
                  Referencia
                <?php elseif (is_numeric($job['baseline_distance_score'] ?? null)): ?>
                  <?= htmlspecialchars(vibrations_format_value($job['baseline_distance_score'], '%'), ENT_QUOTES, 'UTF-8') ?>
                <?php else: ?>
                  n/d
                <?php endif; ?>
              </td>
              <td><?= htmlspecialchars((string) $job['status'], ENT_QUOTES, 'UTF-8') ?></td>
              <td class="table-actions">
                <?php if (($job['status'] ?? '') === 'completed'): ?>
                  <a class="button-secondary" href="/portal/vibrations.php?job_id=<?= (int) $job['id'] ?>#vibrations-report">Ver análisis</a>
                  <?php if ((int) ($job['is_baseline'] ?? 0) !== 1): ?>
                    <form method="post" action="/portal/vibrations.php" class="inline-form" onsubmit="return confirm('¿Reemplazar la referencia de este fenómeno por este análisis?');">
                      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                      <input type="hidden" name="action" value="set_baseline">
                      <input type="hidden" name="job_id" value="<?= (int) $job['id'] ?>">
                      <button class="button-secondary" type="submit">Usar como referencia</button>
                    </form>
                  <?php else: ?>
                    <span class="status-pill is-active">Referencia actual</span>
                  <?php endif; ?>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </article>

  <?php endif; ?>

  <?php if ($selectedPhenomenon !== null && $canAdministerVibrations): ?>
    <article class="card">
      <span class="section-tag">Admin</span>
      <h2>Últimos análisis de este fenómeno</h2>
      <div class="table-shell">
        <table class="users-table">
          <thead>
            <tr>
              <th>Fecha</th>
              <th>Usuario</th>
              <th>Fenómeno</th>
              <th>Archivo</th>
              <th>Referencia</th>
              <th>Estado</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach (array_filter($adminJobs, static fn(array $job): bool => (int) ($job['phenomenon_id'] ?? 0) === $selectedPhenomenonId) as $job): ?>
              <tr>
                <td><?= htmlspecialchars((string) $job['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string) ($job['user_name'] ?? $job['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string) ($job['phenomenon_label'] ?: 'Sin etiqueta'), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string) $job['original_filename'], ENT_QUOTES, 'UTF-8') ?></td>
                <td>
                  <?php if ((int) ($job['is_baseline'] ?? 0) === 1): ?>
                    Referencia
                  <?php elseif (is_numeric($job['baseline_distance_score'] ?? null)): ?>
                    <?= htmlspecialchars(vibrations_format_value($job['baseline_distance_score'], '%'), ENT_QUOTES, 'UTF-8') ?>
                  <?php else: ?>
                    n/d
                  <?php endif; ?>
                </td>
                <td><?= htmlspecialchars((string) $job['status'], ENT_QUOTES, 'UTF-8') ?></td>
                <td class="table-actions">
                  <?php if (($job['status'] ?? '') === 'completed'): ?>
                    <a class="button-secondary" href="/portal/vibrations.php?job_id=<?= (int) $job['id'] ?>#vibrations-report">Ver análisis</a>
                  <?php endif; ?>
                  <form method="post" action="/portal/vibrations.php" class="inline-form" onsubmit="return confirm('¿Eliminar este análisis?');">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="delete_job">
                    <input type="hidden" name="job_id" value="<?= (int) $job['id'] ?>">
                    <button class="button-danger" type="submit">Eliminar</button>
                  </form>
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
