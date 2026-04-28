<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';

if (is_logged_in()) {
    $user = current_user();
    header('Location: ' . dashboard_url_for_role($user['primary_role'] ?? '', $user['current_product_code'] ?? 'audioprint'));
    exit;
}

$error = null;
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $token = $_POST['csrf_token'] ?? null;

    if (!verify_csrf(is_string($token) ? $token : null)) {
        $error = 'La sesión del formulario no es válida. Recarga la página e inténtalo de nuevo.';
    } elseif ($email === '' || $password === '') {
        $error = 'Debes completar correo y contraseña.';
    } else {
        $result = login_attempt($email, $password);
        if ($result['ok'] === true) {
            header('Location: /dashboard.php');
            exit;
        }

        $error = $result['message'] ?? 'No fue posible iniciar sesión.';
    }
}

$csrfToken = csrf_token();
render_public_header('d-ml | Acceso');
?>
<div class="top-actions">
      <a class="brand" href="/index.html">
    <span class="brand-mark">d/ml</span>
    <span class="brand-copy">
      <span>d-ml</span>
      <small>Acceso a soluciones</small>
    </span>
  </a>
  <a class="button-secondary" href="/index.html">Volver a la landing</a>
</div>

<section class="auth-layout">
  <article class="auth-side">
    <span class="eyebrow">Acceso</span>
    <h1>Entra en las soluciones de d-ml desde un acceso claro y controlado.</h1>
    <p class="lead">Cada cuenta ve solo las herramientas que tiene habilitadas. d-ml funciona como marca y capa de acceso, mientras que cada solución mantiene su propio flujo operativo, permisos y contexto de uso.</p>
    <div class="feature-grid">
      <article class="feature-card">
        <strong>Audioprint</strong>
        <p>La solución activa para carga, análisis, historial y lectura operativa de audio.</p>
      </article>
      <article class="feature-card">
        <strong>Qvoice</strong>
        <p>La solución preparada para el seguimiento futuro de la voz humana en entornos laborales, aún sin capa analítica publicada.</p>
      </article>
      <article class="feature-card">
        <strong>Trazabilidad</strong>
        <p>Cada acceso conserva relación con sus análisis, metadatos y acciones dentro de la plataforma.</p>
      </article>
      <article class="feature-card">
        <strong>Acceso por solución</strong>
        <p>Las membresías determinan qué aplicaciones ves, mientras que la gestión global del sistema se mantiene separada.</p>
      </article>
    </div>
  </article>

  <article class="auth-panel">
    <span class="eyebrow">Inicio de sesión</span>
    <h2>Entrar al portal</h2>
    <p>Usa tus credenciales para continuar donde lo dejaste.</p>

    <?php if ($error !== null): ?>
      <div class="message is-error">
        <strong>No fue posible iniciar sesión</strong>
        <span><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></span>
      </div>
    <?php endif; ?>

    <form method="post" action="/login.php" class="form-block">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
      <div>
        <label for="email">Correo</label>
        <input id="email" name="email" type="email" value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>" placeholder="usuario@d-ml.eu" required>
      </div>
      <div>
        <label for="password">Contraseña</label>
        <input id="password" name="password" type="password" placeholder="********" required>
      </div>
      <button class="button" type="submit">Entrar</button>
    </form>

    <div class="helper">
      <strong>Acceso seguro</strong>
      <span>Tu acceso queda ligado a las soluciones asignadas a tu cuenta y se resuelve con permisos propios de cada aplicación.</span>
    </div>
  </article>
</section>
<?php render_public_footer(); ?>
