<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/analysis.php';

require_login();

$user = current_user();
$viewerUserId = (int) ($user['id'] ?? 0);
$canViewAll = is_system_admin();
$selectedJobId = isset($_GET['analysis_job_id']) ? (int) $_GET['analysis_job_id'] : 0;
$jobs = list_analysis_jobs_for_viewer($viewerUserId, $canViewAll, 100);
$selectedJob = $selectedJobId > 0 ? get_analysis_job_for_viewer($selectedJobId, $viewerUserId, $canViewAll) : null;
$metrics = $selectedJob !== null ? list_analysis_metrics((int) $selectedJob['id']) : [];
$artifacts = $selectedJob !== null ? list_analysis_artifacts((int) $selectedJob['id']) : [];

function analysis_panel_bytes(int $bytes): string
{
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    }

    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 1) . ' KB';
    }

    return $bytes . ' B';
}

function analysis_panel_value(mixed $value): string
{
    if ($value === null || $value === '') {
        return 'n/d';
    }

    if (is_numeric($value)) {
        return rtrim(rtrim(number_format((float) $value, 6, '.', ''), '0'), '.');
    }

    return (string) $value;
}

render_app_header('d-ml | Análisis');
?>
<section class="page-stack">
  <section class="hero">
    <div class="dashboard-hero">
      <div>
        <span class="role-badge">Análisis</span>
        <h1>Historial común de análisis.</h1>
        <p class="lead">Este panel lee la capa genérica preparada para Audioprint, machine learning y descomposición wavelets.</p>
      </div>
      <div class="stats-grid">
        <article class="stat-card">
          <strong><?= count($jobs) ?></strong>
          <span><?= $canViewAll ? 'Registros recientes' : 'Tus registros' ?></span>
        </article>
        <article class="stat-card">
          <strong><?= $canViewAll ? 'Admin' : 'Usuario' ?></strong>
          <span>Alcance de consulta</span>
        </article>
      </div>
    </div>
  </section>

  <?php if ($selectedJob !== null): ?>
    <article class="card" id="detalle">
      <span class="section-tag">Detalle</span>
      <h2><?= htmlspecialchars((string) (($selectedJob['input_title'] ?? '') ?: 'Análisis #' . $selectedJob['id']), ENT_QUOTES, 'UTF-8') ?></h2>
      <p>
        <?= htmlspecialchars((string) ($selectedJob['product_name'] ?? 'Producto'), ENT_QUOTES, 'UTF-8') ?>
        · <?= htmlspecialchars((string) ($selectedJob['engine_name'] ?? 'Motor sin nombre'), ENT_QUOTES, 'UTF-8') ?>
        · <?= htmlspecialchars((string) ($selectedJob['status'] ?? 'pending'), ENT_QUOTES, 'UTF-8') ?>
      </p>

      <div class="audioprint-summary-grid">
        <article class="metric-card">
          <span>Archivo</span>
          <strong><?= htmlspecialchars((string) ($selectedJob['input_filename'] ?? 'n/d'), ENT_QUOTES, 'UTF-8') ?></strong>
          <small><?= htmlspecialchars(analysis_panel_bytes((int) ($selectedJob['input_size_bytes'] ?? 0)), ENT_QUOTES, 'UTF-8') ?></small>
        </article>
        <article class="metric-card">
          <span>Usuario</span>
          <strong><?= htmlspecialchars((string) ($selectedJob['user_name'] ?? 'n/d'), ENT_QUOTES, 'UTF-8') ?></strong>
          <small><?= htmlspecialchars((string) ($selectedJob['user_email'] ?? ''), ENT_QUOTES, 'UTF-8') ?></small>
        </article>
        <article class="metric-card">
          <span>Fecha</span>
          <strong><?= htmlspecialchars((string) ($selectedJob['created_at'] ?? 'n/d'), ENT_QUOTES, 'UTF-8') ?></strong>
          <small>Procesado: <?= htmlspecialchars((string) ($selectedJob['processed_at'] ?? 'n/d'), ENT_QUOTES, 'UTF-8') ?></small>
        </article>
      </div>

      <?php if ($artifacts !== []): ?>
        <h3>Archivos asociados</h3>
        <div class="table-shell">
          <table class="users-table">
            <thead>
              <tr>
                <th>Tipo</th>
                <th>Título</th>
                <th>Formato</th>
                <th>Acceso</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($artifacts as $artifact): ?>
                <tr>
                  <td><?= htmlspecialchars((string) $artifact['artifact_type'], ENT_QUOTES, 'UTF-8') ?></td>
                  <td><?= htmlspecialchars((string) $artifact['title'], ENT_QUOTES, 'UTF-8') ?></td>
                  <td><?= htmlspecialchars((string) ($artifact['media_type'] ?? 'n/d'), ENT_QUOTES, 'UTF-8') ?></td>
                  <td>
                    <?php if (($artifact['public_url'] ?? '') !== ''): ?>
                      <a class="button-secondary" href="<?= htmlspecialchars((string) $artifact['public_url'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">Abrir</a>
                    <?php else: ?>
                      n/d
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>

      <?php if ($metrics !== []): ?>
        <h3>Métricas normalizadas</h3>
        <div class="table-shell">
          <table class="users-table">
            <thead>
              <tr>
                <th>Grupo</th>
                <th>Métrica</th>
                <th>Valor</th>
                <th>Unidad</th>
                <th>Fuente</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($metrics as $metric): ?>
                <tr>
                  <td><?= htmlspecialchars((string) $metric['metric_group_label'], ENT_QUOTES, 'UTF-8') ?></td>
                  <td>
                    <strong><?= htmlspecialchars((string) $metric['metric_label'], ENT_QUOTES, 'UTF-8') ?></strong>
                    <div class="table-meta"><?= htmlspecialchars((string) ($metric['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                  </td>
                  <td><?= htmlspecialchars(analysis_panel_value($metric['metric_value_text'] ?? $metric['metric_value_number'] ?? null), ENT_QUOTES, 'UTF-8') ?></td>
                  <td><?= htmlspecialchars((string) ($metric['unit'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                  <td><?= htmlspecialchars((string) ($metric['source_path'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </article>
  <?php endif; ?>

  <article class="card">
    <span class="section-tag">Historial</span>
    <h2>Análisis registrados</h2>
    <p><?= $canViewAll ? 'Vista global para administración.' : 'Vista limitada al usuario activo.' ?></p>

    <div class="table-shell">
      <table class="users-table">
        <thead>
          <tr>
            <th>Análisis</th>
            <th>Producto</th>
            <th>Motor</th>
            <th>Estado</th>
            <th>Contenido</th>
            <th>Fecha</th>
            <th>Acción</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($jobs as $job): ?>
            <tr>
              <td>
                <strong><?= htmlspecialchars((string) (($job['input_title'] ?? '') ?: 'Análisis #' . $job['id']), ENT_QUOTES, 'UTF-8') ?></strong>
                <div class="table-meta"><?= htmlspecialchars((string) ($job['input_filename'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
              </td>
              <td><?= htmlspecialchars((string) ($job['product_name'] ?? 'n/d'), ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars((string) ($job['engine_name'] ?? 'n/d'), ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars((string) ($job['status'] ?? 'pending'), ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= (int) ($job['metric_count'] ?? 0) ?> métricas · <?= (int) ($job['artifact_count'] ?? 0) ?> archivos</td>
              <td><?= htmlspecialchars((string) ($job['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
              <td><a class="button-secondary" href="/portal/analisis.php?analysis_job_id=<?= (int) $job['id'] ?>#detalle">Detalle</a></td>
            </tr>
          <?php endforeach; ?>
          <?php if ($jobs === []): ?>
            <tr>
              <td colspan="7">Aún no hay análisis registrados en la capa común.</td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </article>
</section>
<?php render_app_footer(); ?>
