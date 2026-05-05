<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';

if (is_logged_in()) {
    $user = current_user();
    header('Location: ' . dashboard_url_for_role($user['primary_role'] ?? '', $user['current_product_code'] ?? 'audioprint'));
    exit;
}

$error = null;
$success = null;
$productCode = 'audioprint';
$form = [
    'first_name' => '',
    'last_name' => '',
    'email' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['first_name'] = trim((string) ($_POST['first_name'] ?? ''));
    $form['last_name'] = trim((string) ($_POST['last_name'] ?? ''));
    $form['email'] = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');
    $productCode = trim((string) ($_POST['product_code'] ?? 'audioprint'));
    $token = $_POST['csrf_token'] ?? null;

    if (!verify_csrf(is_string($token) ? $token : null)) {
        $error = 'La sesión del formulario no es válida. Recarga la página e inténtalo de nuevo.';
    } elseif ($form['first_name'] === '' || $form['last_name'] === '' || $form['email'] === '') {
        $error = 'Debes completar nombre, apellidos y correo.';
    } elseif (!filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
        $error = 'Debes indicar un correo válido.';
    } elseif (strlen($password) < 8) {
        $error = 'La contraseña debe tener al menos 8 caracteres.';
    } elseif ($password !== $passwordConfirm) {
        $error = 'Las contraseñas no coinciden.';
    } else {
        $result = register_user($form['first_name'], $form['last_name'], $form['email'], $password, $productCode, ['user'], (int) default_organization()['id']);
        if ($result['ok'] === true) {
            $success = 'Cuenta creada correctamente. Ya puedes iniciar sesión.';
            $form = ['first_name' => '', 'last_name' => '', 'email' => ''];
        } else {
            $error = $result['message'] ?? 'No fue posible crear la cuenta.';
        }
    }
}

$csrfToken = csrf_token();
render_public_header('d-ml | Crear cuenta');
?>
<div class="top-actions">
  <a class="brand" href="/index.html">
    <span class="brand-mark">d/ml</span>
    <span class="brand-copy">
      <span>d-ml</span>
      <small>Registro de acceso</small>
    </span>
  </a>
  <a class="button-secondary" href="/index.html">Volver a la landing</a>
</div>

<section class="auth-layout">
  <article class="auth-side">
    <span class="eyebrow">Registro</span>
    <h1>Crea tu acceso para empezar a trabajar con las soluciones de d-ml.</h1>
    <p class="lead">El alta abierta habilita tu entrada inicial en Audioprint, la solución activa para procesar audio, conservar análisis por captura y construir un historial reútilizable.</p>
    <div class="stack">
      <article class="feature-card">
        <strong>Registro seguro</strong>
        <p>Un alta simple, cuidada y protegida.</p>
      </article>
      <article class="feature-card">
        <strong>Rol inicial</strong>
        <p>Las nuevas cuentas creadas aquí entran como <em>User</em> dentro de Audioprint: pueden subir audios y operar solo sobre su propio historial.</p>
      </article>
      <article class="feature-card">
        <strong>Escalado de permisos</strong>
        <p>Los <em>Admin</em> de Audioprint se asignan internamente y además pueden supervisar el historial global de la solución.</p>
      </article>
      <article class="feature-card">
        <strong>Trazabilidad desde el inicio</strong>
        <p>La cuenta queda preparada para trabajar con audios, análisis asociados, metadatos y seguimiento posterior.</p>
      </article>
      <article class="feature-card">
        <strong>Qvoice como siguiente capa</strong>
        <p>La marca también incorpora Qvoice como solución orientada al seguimiento de la voz humana en entornos laborales, preparada para completarse con futuras APIs.</p>
      </article>
      <article class="feature-card">
        <strong>Siguiente paso</strong>
        <p>Después del alta ya puedes entrar, subir un audio y obtener su análisis completo.</p>
      </article>
    </div>
  </article>

  <article class="auth-panel">
    <span class="eyebrow">Crear cuenta</span>
    <h2>Nuevo acceso</h2>
    <p>Completa tus datos para dejar tu acceso preparado.</p>

    <?php if ($error !== null): ?>
      <div class="message is-error">
        <strong>Revisión necesaria</strong>
        <span><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></span>
      </div>
    <?php endif; ?>

    <?php if ($success !== null): ?>
      <div class="message is-success">
        <strong>Cuenta creada</strong>
        <span><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></span>
      </div>
    <?php endif; ?>

    <form method="post" action="/signup.php" class="form-block">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="product_code" value="<?= htmlspecialchars($productCode, ENT_QUOTES, 'UTF-8') ?>">
      <div class="form-grid two">
        <div>
          <label for="first_name">Nombre</label>
          <input id="first_name" name="first_name" type="text" value="<?= htmlspecialchars($form['first_name'], ENT_QUOTES, 'UTF-8') ?>" required>
        </div>
        <div>
          <label for="last_name">Apellidos</label>
          <input id="last_name" name="last_name" type="text" value="<?= htmlspecialchars($form['last_name'], ENT_QUOTES, 'UTF-8') ?>" required>
        </div>
      </div>
      <div>
        <label for="email">Correo</label>
        <input id="email" name="email" type="email" value="<?= htmlspecialchars($form['email'], ENT_QUOTES, 'UTF-8') ?>" placeholder="persona@d-ml.eu" required>
      </div>
      <div class="form-grid two">
        <div>
          <label for="password">Contraseña</label>
          <input id="password" name="password" type="password" placeholder="Mínimo 8 caracteres" required>
        </div>
        <div>
          <label for="password_confirm">Repetir contraseña</label>
          <input id="password_confirm" name="password_confirm" type="password" placeholder="Repite la contraseña" required>
        </div>
      </div>
      <button class="button" type="submit">Crear cuenta</button>
    </form>

    <div class="top-actions" style="margin:0; justify-content:flex-start;">
      <a class="button-secondary" href="/login.php">Ya tengo cuenta</a>
    </div>

    <div class="helper">
      <strong>Como funciona</strong>
      <span>El alta abierta crea acceso inicial a Audioprint con rol user. Qvoice se irá habilitando por membresía cuando su capa de integración analítica esté lista.</span>
    </div>
  </article>
</section>
<?php render_public_footer(); ?>
