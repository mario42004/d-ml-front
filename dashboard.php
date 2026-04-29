<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';

require_login();

$user = current_user();
$memberships = $user['memberships'] ?? [];

if (count($memberships) === 1) {
    $membership = $memberships[0];
    set_current_product((string) $membership['product_code']);
    header('Location: ' . dashboard_url_for_role((string) $membership['role_code'], (string) $membership['product_code']));
    exit;
}

render_app_header('d-ml | Mis accesos');
?>
<section class="page-stack">
  <section class="hero">
    <div class="dashboard-hero">
      <div>
        <span class="role-badge">Dashboard</span>
        <h1>Soluciónes disponibles para tu cuenta.</h1>
        <p class="lead">Cada acceso ve solo las soluciones que tiene habilitadas. Si tu cuenta tiene una sola membresía, entrarás directamente en esa herramienta. Si tiene varias, aquí eliges.</p>
      </div>
      <div class="stats-grid">
        <article class="stat-card">
          <strong><?= count($memberships) ?></strong>
          <span>Accesos disponibles</span>
        </article>
        <?php if (is_system_admin()): ?>
          <article class="stat-card">
            <strong>Admin</strong>
            <span>Gestion global habilitada</span>
          </article>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <section class="portal-card-grid">
    <?php foreach ($memberships as $membership): ?>
      <article class="card">
        <span class="tag"><?= htmlspecialchars((string) $membership['role_name'], ENT_QUOTES, 'UTF-8') ?></span>
        <h3><?= htmlspecialchars((string) $membership['product_name'], ENT_QUOTES, 'UTF-8') ?></h3>
        <p>Acceso habilitado para está solución con rol <strong><?= htmlspecialchars((string) $membership['role_code'], ENT_QUOTES, 'UTF-8') ?></strong>. Ese rol define lo que puedes operar dentro de la herramienta, mientras que la administración global es un permiso aparte.</p>
        <div class="cta-actions">
          <a class="button" href="<?= htmlspecialchars(dashboard_url_for_role((string) $membership['role_code'], (string) $membership['product_code']), ENT_QUOTES, 'UTF-8') ?>">Entrar</a>
        </div>
      </article>
    <?php endforeach; ?>
    <?php if (is_system_admin()): ?>
      <article class="card">
        <span class="tag">Sistema</span>
        <h3>Administración global</h3>
        <p>Gestiona usuarios, permisos globales y accesos por solución desde un panel separado de las herramientas operativas.</p>
        <div class="cta-actions">
          <a class="button-secondary" href="/portal/admin.php">Ir a admin</a>
        </div>
      </article>
    <?php endif; ?>
  </section>
</section>
<?php render_app_footer(); ?>
