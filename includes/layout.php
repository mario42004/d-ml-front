<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function render_public_header(string $title): void
{
    ?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
  <link rel="stylesheet" href="/assets/css/core.css">
  <link rel="stylesheet" href="/assets/css/portal.css">
</head>
<body>
  <main class="auth-wrap">
    <?php
}

function render_public_footer(): void
{
    ?>
  </main>
</body>
</html>
    <?php
}

function render_app_header(string $title): void
{
    $user = current_user();
    $tabs = current_product_tabs();
    $productName = $user['current_product_name'] ?? 'Portal de trabajo';
    ?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
  <link rel="stylesheet" href="/assets/css/core.css">
  <link rel="stylesheet" href="/assets/css/portal.css">
</head>
<body>
  <header class="topbar">
    <div class="shell shell-app topbar-inner">
      <a class="brand" href="/dashboard.php">
        <span class="brand-mark">d/ml</span>
        <span class="brand-copy">
          <span>d-ml</span>
          <small><?= htmlspecialchars((string) $productName, ENT_QUOTES, 'UTF-8') ?></small>
        </span>
      </a>
      <nav class="nav">
        <?php foreach ($tabs as $tab): ?>
          <a class="pill" href="<?= htmlspecialchars(dashboard_url_for_role((string) $tab['role_code'], (string) $tab['product_code']), ENT_QUOTES, 'UTF-8') ?>">
            <?= htmlspecialchars((string) $tab['product_name'], ENT_QUOTES, 'UTF-8') ?>
          </a>
        <?php endforeach; ?>
        <?php if ($user !== null): ?>
          <?php if (is_system_admin()): ?>
            <a class="pill" href="/portal/admin.php">Admin Dashboard</a>
          <?php endif; ?>
          <a class="button-danger" href="/logout.php">Salir</a>
        <?php else: ?>
          <a class="button" href="/login.php">Entrar</a>
        <?php endif; ?>
      </nav>
    </div>
  </header>
  <main class="shell shell-app page">
    <?php
}

function render_app_footer(): void
{
    ?>
  </main>
</body>
</html>
    <?php
}
