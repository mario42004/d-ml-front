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

        if ($action === 'set_baseline') {
            $jobId = (int) ($_POST['job_id'] ?? 0);
            $job = get_vibration_job_by_id($jobId);

            if ($job === null) {
                $message = 'El registro seleccionado no existe.';
                $messageType = 'error';
            } elseif (!is_system_admin() && (int) ($job['organization_id'] ?? 0) !== $currentOrganizationId) {
                $message = 'No tienes permisos para marcar este baseline.';
                $messageType = 'error';
            } elseif (!$canAdministerVibrations && (int) $job['user_id'] !== (int) $user['id']) {
                $message = 'No tienes permisos para marcar este baseline.';
                $messageType = 'error';
            } else {
                $result = set_vibration_baseline($jobId);
                $message = ($result['ok'] ?? false) ? 'Baseline actualizado correctamente.' : (string) ($result['message'] ?? 'No fue posible actualizar el baseline.');
                $messageType = ($result['ok'] ?? false) ? 'success' : 'error';
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
            }
        }
    }
}

$jobs = list_vibration_jobs_for_user((int) $user['id'], $currentOrganizationId);
$adminJobs = $canAdministerVibrations ? list_recent_vibration_jobs(50, $currentOrganizationId) : [];
$phenomena = list_vibration_phenomena_for_user((int) $user['id'], $currentOrganizationId, $canAdministerVibrations);
$jobsByPhenomenon = [];
foreach ($phenomena as $phenomenon) {
    $jobsByPhenomenon[(int) $phenomenon['id']] = list_vibration_jobs_by_phenomenon((int) $phenomenon['id'], 4);
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

$completedJobs = count(array_filter($jobs, static fn(array $job): bool => ($job['status'] ?? '') === 'completed'));
$failedJobs = count(array_filter($jobs, static fn(array $job): bool => ($job['status'] ?? '') === 'failed'));
$baselineJobs = count(array_filter($jobs, static fn(array $job): bool => (int) ($job['is_baseline'] ?? 0) === 1));
$csrfToken = csrf_token();

render_app_header('Vibrations | Análisis DATS');
?>
<section class="page-stack">
  <section class="hero">
    <div class="dashboard-hero">
      <div>
        <span class="role-badge">Vibrations</span>
        <h1>Análisis de acelerómetro y giroscopio por ventanas de observación.</h1>
        <p class="lead">Carga archivos <code>.dat</code> capturados por sensores inerciales. La API calcula métricas globales y ventanas de 500 ms para detectar vibraciones fuertes, cambios bruscos y señales que puedan alimentar un baseline histórico.</p>
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
          <span>baselines activos</span>
        </article>
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
          <span class="section-tag">Reporte</span>
          <h2><?= htmlspecialchars((string) ($selectedJob['phenomenon_label'] ?: $selectedJob['original_filename']), ENT_QUOTES, 'UTF-8') ?></h2>
          <p><?= htmlspecialchars((string) $selectedJob['original_filename'], ENT_QUOTES, 'UTF-8') ?></p>
        </div>
        <a class="button-secondary" href="/portal/vibrations.php">Cerrar reporte</a>
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
          <span><?= ((int) ($selectedJob['is_baseline'] ?? 0)) === 1 ? 'baseline activo' : 'distancia al baseline' ?></span>
        </article>
      </div>

      <?php if ($baselineSummary !== null): ?>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Baseline</th>
                <th>Métricas comparadas</th>
                <th>Distancia</th>
                <th>Severidad</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td>#<?= (int) ($baselineSummary['baseline_job_id'] ?? 0) ?></td>
                <td><?= (int) ($baselineSummary['compared_metric_count'] ?? 0) ?></td>
                <td><?= htmlspecialchars(vibrations_format_value($baselineSummary['distance_score'] ?? null, '%'), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string) ($baselineSummary['severity'] ?? 'normal'), ENT_QUOTES, 'UTF-8') ?></td>
              </tr>
            </tbody>
          </table>
        </div>

        <?php $topDifferences = is_array($baselineSummary['top_differences'] ?? null) ? $baselineSummary['top_differences'] : []; ?>
        <?php if ($topDifferences !== []): ?>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Métrica</th>
                  <th>Baseline</th>
                  <th>Actual</th>
                  <th>Diferencia</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($topDifferences as $difference): ?>
                  <?php if (!is_array($difference)) { continue; } ?>
                  <tr>
                    <td><?= htmlspecialchars((string) ($difference['metric_key'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars(vibrations_format_value($difference['baseline'] ?? null), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars(vibrations_format_value($difference['current'] ?? null), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars(vibrations_format_value($difference['relative_delta_percent'] ?? null, '%'), ENT_QUOTES, 'UTF-8') ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      <?php elseif (((int) ($selectedJob['is_baseline'] ?? 0)) !== 1): ?>
        <div class="message is-success">
          <strong>Sin baseline comparable</strong>
          <span>Marca una captura completada del mismo fenómeno o ID externo como baseline para comparar nuevos archivos.</span>
        </div>
      <?php endif; ?>

      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Sensor</th>
              <th>Muestras</th>
              <th>Frecuencia</th>
              <th>RMS dinámico</th>
              <th>Pico dinámico</th>
              <th>Jerk RMS</th>
              <th>Frecuencia dominante</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($sensors as $sensor): ?>
              <?php $sensor = (string) $sensor; ?>
              <tr>
                <td><?= htmlspecialchars($sensor, ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars(vibrations_format_value(vibrations_analysis_stat($selectedAnalysis, $sensor, 'sample_count')), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars(vibrations_format_value(vibrations_analysis_stat($selectedAnalysis, $sensor, 'estimated_sample_rate_hz'), 'Hz'), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars(vibrations_format_value(vibrations_analysis_stat($selectedAnalysis, $sensor, 'dynamic.rms')), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars(vibrations_format_value(vibrations_analysis_stat($selectedAnalysis, $sensor, 'dynamic.peak_abs')), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars(vibrations_format_value(vibrations_analysis_stat($selectedAnalysis, $sensor, 'jerk.rms')), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars(vibrations_format_value(vibrations_analysis_stat($selectedAnalysis, $sensor, 'spectrum.dominant_frequency_hz'), 'Hz'), ENT_QUOTES, 'UTF-8') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
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

      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Ventana</th>
              <th>Inicio</th>
              <th>Fin</th>
              <th>Sensor</th>
              <th>Score</th>
              <th>Severidad</th>
              <th>RMS dinámico</th>
              <th>Pico</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach (array_slice($windows, 0, 24) as $window): ?>
              <?php foreach (($window['sensors'] ?? []) as $sensorName => $sensorPayload): ?>
                <?php if (!is_array($sensorPayload)) { continue; } ?>
                <tr>
                  <td><?= (int) ($window['index'] ?? 0) ?></td>
                  <td><?= htmlspecialchars(vibrations_format_value($window['start_seconds'] ?? null, 's'), ENT_QUOTES, 'UTF-8') ?></td>
                  <td><?= htmlspecialchars(vibrations_format_value($window['end_seconds'] ?? null, 's'), ENT_QUOTES, 'UTF-8') ?></td>
                  <td><?= htmlspecialchars((string) $sensorName, ENT_QUOTES, 'UTF-8') ?></td>
                  <td><?= htmlspecialchars(vibrations_format_value($sensorPayload['change_score'] ?? null), ENT_QUOTES, 'UTF-8') ?></td>
                  <td><?= htmlspecialchars((string) ($sensorPayload['severity'] ?? 'normal'), ENT_QUOTES, 'UTF-8') ?></td>
                  <td><?= htmlspecialchars(vibrations_format_value($sensorPayload['dynamic_rms'] ?? null), ENT_QUOTES, 'UTF-8') ?></td>
                  <td><?= htmlspecialchars(vibrations_format_value($sensorPayload['dynamic_peak_abs'] ?? null), ENT_QUOTES, 'UTF-8') ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </article>
  <?php endif; ?>

  <article class="card">
    <div class="section-heading">
      <div>
        <span class="section-tag">Fenómenos</span>
        <h2>Fenómenos observados a tu cargo</h2>
        <p>Cada fenómeno mantiene su propio historial, baseline y distancia de cambio. Las mediciones de un equipo no se mezclan con las de otro.</p>
      </div>
    </div>

    <?php if ($phenomena === []): ?>
      <div class="message is-success">
        <strong>Sin fenómenos todavía</strong>
        <span>Crea el primero al cargar un archivo DATS.</span>
      </div>
    <?php else: ?>
      <div class="vibrations-phenomena-grid">
        <?php foreach ($phenomena as $phenomenon): ?>
          <?php
            $phenomenonJobs = $jobsByPhenomenon[(int) $phenomenon['id']] ?? [];
            $latestJob = $phenomenonJobs[0] ?? null;
            $completedCount = count(array_filter($phenomenonJobs, static fn(array $job): bool => ($job['status'] ?? '') === 'completed'));
          ?>
          <article class="vibrations-phenomenon-card">
            <div class="vibrations-phenomenon-head">
              <div>
                <strong><?= htmlspecialchars((string) $phenomenon['name'], ENT_QUOTES, 'UTF-8') ?></strong>
                <?php if ((string) ($phenomenon['external_id'] ?? '') !== ''): ?>
                  <span><?= htmlspecialchars((string) $phenomenon['external_id'], ENT_QUOTES, 'UTF-8') ?></span>
                <?php endif; ?>
              </div>
              <span class="status-pill"><?= ((int) ($phenomenon['baseline_job_id'] ?? 0)) > 0 ? 'Baseline #' . (int) $phenomenon['baseline_job_id'] : 'Sin baseline' ?></span>
            </div>

            <?php if ((string) ($phenomenon['description'] ?? '') !== ''): ?>
              <p><?= htmlspecialchars((string) $phenomenon['description'], ENT_QUOTES, 'UTF-8') ?></p>
            <?php endif; ?>

            <div class="vibrations-phenomenon-metrics">
              <span><strong><?= count($phenomenonJobs) ?></strong> recientes</span>
              <span><strong><?= $completedCount ?></strong> completos</span>
              <span>
                <strong><?= $latestJob !== null && is_numeric($latestJob['baseline_distance_score'] ?? null) ? htmlspecialchars(vibrations_format_value($latestJob['baseline_distance_score'], '%'), ENT_QUOTES, 'UTF-8') : 'n/d' ?></strong>
                distancia
              </span>
            </div>

            <?php if ($phenomenonJobs !== []): ?>
              <div class="vibrations-mini-history">
                <?php foreach ($phenomenonJobs as $job): ?>
                  <div>
                    <span><?= htmlspecialchars((string) $job['created_at'], ENT_QUOTES, 'UTF-8') ?></span>
                    <span><?= htmlspecialchars((string) $job['status'], ENT_QUOTES, 'UTF-8') ?></span>
                    <?php if (($job['status'] ?? '') === 'completed'): ?>
                      <a href="/portal/vibrations.php?job_id=<?= (int) $job['id'] ?>#vibrations-report">Ver</a>
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </article>

  <section class="panel-grid">
    <article class="card">
      <span class="section-tag">Nuevo análisis</span>
      <h2>Cargar archivo DATS</h2>
      <p>Selecciona el fenómeno observado antes de cargar la captura. Si es nuevo, deja el selector en crear y completa su identificación.</p>

      <form method="post" action="/portal/vibrations.php" enctype="multipart/form-data" class="form-block">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="action" value="upload">

        <?php if ($phenomena !== []): ?>
          <div>
            <label for="phenomenon_id">Fenómeno observado existente</label>
            <select id="phenomenon_id" name="phenomenon_id">
              <option value="0">Crear nuevo fenómeno</option>
              <?php foreach ($phenomena as $phenomenon): ?>
                <option value="<?= (int) $phenomenon['id'] ?>">
                  <?= htmlspecialchars((string) $phenomenon['name'] . ((string) ($phenomenon['external_id'] ?? '') !== '' ? ' · ' . (string) $phenomenon['external_id'] : ''), ENT_QUOTES, 'UTF-8') ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        <?php endif; ?>

        <div class="form-grid two">
          <div>
            <label for="phenomenon_label">Nuevo fenómeno observado</label>
            <input id="phenomenon_label" name="phenomenon_label" type="text" maxlength="190" placeholder="Motor, carro, bomba, estructura">
          </div>
          <div>
            <label for="external_id">ID externo</label>
            <input id="external_id" name="external_id" type="text" maxlength="120" placeholder="Equipo, activo o referencia">
          </div>
        </div>

        <div>
          <label for="phenomenon_description">Descripción del fenómeno</label>
          <input id="phenomenon_description" name="phenomenon_description" type="text" maxlength="255" placeholder="Contexto, ubicación, montaje o condición de medición">
        </div>

        <div class="form-grid two">
          <div>
            <label for="window_ms">Ventana de observación</label>
            <select id="window_ms" name="window_ms">
              <option value="500" selected>500 ms</option>
              <option value="250">250 ms</option>
              <option value="1000">1000 ms</option>
            </select>
          </div>
          <div>
            <label for="dat_file">Archivo .dat</label>
            <input id="dat_file" name="dat_file" type="file" accept=".dat,text/plain" required>
          </div>
        </div>

        <button class="button" type="submit" <?= $vibrationsCoins > 0 ? '' : 'disabled' ?>>Procesar DATS</button>
      </form>
    </article>

    <article class="card">
      <span class="section-tag">Baseline</span>
      <h2>Comparación por fenómeno</h2>
      <p>El primer objetivo es fijar una captura representativa para cada fenómeno. A partir de ahí, cada nuevo archivo se compara contra ese punto de referencia.</p>
      <ul class="service-list">
        <li>Marca un análisis completado como baseline desde su historial.</li>
        <li>Las distancias se calculan solo dentro del mismo fenómeno.</li>
        <li>Los cambios fuertes quedan visibles en ventanas de observación.</li>
      </ul>
    </article>
  </section>

  <article class="card">
    <span class="section-tag">Historial</span>
    <h2>Tus archivos procesados</h2>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Fecha</th>
            <th>Fenómeno</th>
            <th>Archivo</th>
            <th>Ventana</th>
            <th>Baseline</th>
            <th>Estado</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php if ($jobs === []): ?>
            <tr><td colspan="7">Todavía no hay archivos DATS procesados.</td></tr>
          <?php endif; ?>
          <?php foreach ($jobs as $job): ?>
            <tr>
              <td><?= htmlspecialchars((string) $job['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars((string) ($job['phenomenon_label'] ?: 'Sin etiqueta'), ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars((string) $job['original_filename'], ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= (int) $job['window_ms'] ?> ms</td>
              <td>
                <?php if ((int) ($job['is_baseline'] ?? 0) === 1): ?>
                  Baseline
                <?php elseif (is_numeric($job['baseline_distance_score'] ?? null)): ?>
                  <?= htmlspecialchars(vibrations_format_value($job['baseline_distance_score'], '%'), ENT_QUOTES, 'UTF-8') ?>
                <?php else: ?>
                  n/d
                <?php endif; ?>
              </td>
              <td><?= htmlspecialchars((string) $job['status'], ENT_QUOTES, 'UTF-8') ?></td>
              <td class="table-actions">
                <?php if (($job['status'] ?? '') === 'completed'): ?>
                  <a class="button-secondary" href="/portal/vibrations.php?job_id=<?= (int) $job['id'] ?>#vibrations-report">Ver</a>
                  <?php if ((int) ($job['is_baseline'] ?? 0) !== 1): ?>
                    <form method="post" action="/portal/vibrations.php" class="inline-form" onsubmit="return confirm('¿Usar este análisis como baseline para este fenómeno?');">
                      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                      <input type="hidden" name="action" value="set_baseline">
                      <input type="hidden" name="job_id" value="<?= (int) $job['id'] ?>">
                      <button class="button-secondary" type="submit">Baseline</button>
                    </form>
                  <?php endif; ?>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </article>

  <?php if ($canAdministerVibrations): ?>
    <article class="card">
      <span class="section-tag">Admin</span>
      <h2>Últimos análisis del producto</h2>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Fecha</th>
              <th>Usuario</th>
              <th>Fenómeno</th>
              <th>Archivo</th>
              <th>Baseline</th>
              <th>Estado</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($adminJobs as $job): ?>
              <tr>
                <td><?= htmlspecialchars((string) $job['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string) ($job['user_name'] ?? $job['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string) ($job['phenomenon_label'] ?: 'Sin etiqueta'), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string) $job['original_filename'], ENT_QUOTES, 'UTF-8') ?></td>
                <td>
                  <?php if ((int) ($job['is_baseline'] ?? 0) === 1): ?>
                    Baseline
                  <?php elseif (is_numeric($job['baseline_distance_score'] ?? null)): ?>
                    <?= htmlspecialchars(vibrations_format_value($job['baseline_distance_score'], '%'), ENT_QUOTES, 'UTF-8') ?>
                  <?php else: ?>
                    n/d
                  <?php endif; ?>
                </td>
                <td><?= htmlspecialchars((string) $job['status'], ENT_QUOTES, 'UTF-8') ?></td>
                <td class="table-actions">
                  <?php if (($job['status'] ?? '') === 'completed'): ?>
                    <a class="button-secondary" href="/portal/vibrations.php?job_id=<?= (int) $job['id'] ?>#vibrations-report">Ver</a>
                    <?php if ((int) ($job['is_baseline'] ?? 0) !== 1): ?>
                      <form method="post" action="/portal/vibrations.php" class="inline-form" onsubmit="return confirm('¿Usar este análisis como baseline para este fenómeno?');">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="action" value="set_baseline">
                        <input type="hidden" name="job_id" value="<?= (int) $job['id'] ?>">
                        <button class="button-secondary" type="submit">Baseline</button>
                      </form>
                    <?php endif; ?>
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
