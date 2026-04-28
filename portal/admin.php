<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/audioprint.php';

require_system_admin();

$currentUser = current_user();
$message = null;
$messageType = 'success';
$products = list_all_products();
$rolesByProduct = [];
foreach ($products as $product) {
    $rolesByProduct[$product['code']] = list_roles_for_product((string) $product['code']);
}
$users = list_all_users();
$jobs = list_recent_audio_jobs();
$createForm = [
    'first_name' => '',
    'last_name' => '',
    'email' => '',
    'role_code' => 'user',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? null;

    if (!verify_csrf(is_string($token) ? $token : null)) {
        $message = 'La sesión del formulario no es válida. Recarga la página e inténtalo de nuevo.';
        $messageType = 'error';
    } else {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'create_user') {
            $createForm['first_name'] = trim((string) ($_POST['first_name'] ?? ''));
            $createForm['last_name'] = trim((string) ($_POST['last_name'] ?? ''));
            $createForm['email'] = trim((string) ($_POST['email'] ?? ''));
            $createForm['role_code'] = trim((string) ($_POST['role_code'] ?? 'user'));
            $password = (string) ($_POST['password'] ?? '');
            $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');

            if ($createForm['first_name'] === '' || $createForm['last_name'] === '' || $createForm['email'] === '') {
                $message = 'Debes completar nombre, apellidos y correo para crear un usuario.';
                $messageType = 'error';
            } elseif (!filter_var($createForm['email'], FILTER_VALIDATE_EMAIL)) {
                $message = 'Debes indicar un correo válido.';
                $messageType = 'error';
            } elseif (strlen($password) < 8) {
                $message = 'La contraseña debe tener al menos 8 caracteres.';
                $messageType = 'error';
            } elseif ($password !== $passwordConfirm) {
                $message = 'Las contraseñas no coinciden.';
                $messageType = 'error';
            } else {
                $result = admin_create_user(
                    $createForm['first_name'],
                    $createForm['last_name'],
                    $createForm['email'],
                    $password,
                    'audioprint',
                    [$createForm['role_code']]
                );

                if ($result['ok'] === true) {
                    $message = 'Usuario creado correctamente para Audioprint.';
                    $messageType = 'success';
                    $createForm = ['first_name' => '', 'last_name' => '', 'email' => '', 'role_code' => 'user'];
                } else {
                    $message = $result['message'] ?? 'No fue posible crear el usuario.';
                    $messageType = 'error';
                }
            }
        }

        if ($action === 'update_membership') {
            $userId = (int) ($_POST['user_id'] ?? 0);
            $productCode = trim((string) ($_POST['product_code'] ?? 'audioprint'));
            $roleCode = trim((string) ($_POST['role_code'] ?? ''));
            $isSelf = $currentUser !== null && $userId === (int) $currentUser['id'];
            $isRemovingSelfAdmin = $isSelf && $productCode === 'audioprint' && $roleCode !== 'admin';

            if ($isRemovingSelfAdmin) {
                $message = 'No puedes quitarte el rol admin de Audioprint desde tu propia sesión.';
                $messageType = 'error';
            } else {
                if ($roleCode === '') {
                    $roleResult = remove_user_product_access($userId, $productCode);
                } else {
                    $roleResult = update_user_product_role($userId, $productCode, $roleCode);
                }

                if (($roleResult['ok'] ?? false) !== true) {
                    $message = $roleResult['message'] ?? 'No fue posible actualizar el acceso a la solución.';
                    $messageType = 'error';
                } else {
                    $message = 'Usuario actualizado correctamente.';
                    $messageType = 'success';
                }
            }
        }

        if ($action === 'update_status') {
            $userId = (int) ($_POST['user_id'] ?? 0);
            $status = ((string) ($_POST['is_active'] ?? '0')) === '1';
            $isSelf = $currentUser !== null && $userId === (int) $currentUser['id'];

            if ($isSelf && !$status) {
                $message = 'No puedes desactivar tu propia cuenta mientras estás dentro del panel.';
                $messageType = 'error';
            } else {
                update_user_status($userId, $status);
                $message = 'Estado del usuario actualizado correctamente.';
                $messageType = 'success';
            }
        }

        if ($action === 'reset_password') {
            $userId = (int) ($_POST['user_id'] ?? 0);
            $password = (string) ($_POST['new_password'] ?? '');
            $passwordConfirm = (string) ($_POST['new_password_confirm'] ?? '');

            if (strlen($password) < 8) {
                $message = 'La nueva contraseña debe tener al menos 8 caracteres.';
                $messageType = 'error';
            } elseif ($password !== $passwordConfirm) {
                $message = 'Las nuevas contraseñas no coinciden.';
                $messageType = 'error';
            } else {
                $result = admin_update_user_password($userId, $password);
                if (($result['ok'] ?? false) === true) {
                    $message = 'Contraseña actualizada correctamente.';
                    $messageType = 'success';
                } else {
                    $message = (string) ($result['message'] ?? 'No fue posible actualizar la contraseña.');
                    $messageType = 'error';
                }
            }
        }

        if ($action === 'delete_user') {
            $userId = (int) ($_POST['user_id'] ?? 0);
            $isSelf = $currentUser !== null && $userId === (int) $currentUser['id'];

            if ($isSelf) {
                $message = 'No puedes eliminar tu propia cuenta desde la sesión actual.';
                $messageType = 'error';
            } else {
                $userJobs = list_audio_jobs_for_user($userId);
                foreach ($userJobs as $userJob) {
                    delete_audio_job_record((int) $userJob['id']);
                }

                $result = admin_delete_user_account($userId);
                if (($result['ok'] ?? false) === true) {
                    $message = 'Usuario eliminado correctamente junto con sus registros asociados.';
                    $messageType = 'success';
                } else {
                    $message = (string) ($result['message'] ?? 'No fue posible eliminar el usuario.');
                    $messageType = 'error';
                }
            }
        }

        if ($action === 'delete_job') {
            $jobId = (int) ($_POST['job_id'] ?? 0);
            $result = delete_audio_job_record($jobId);

            if (($result['ok'] ?? false) === true) {
                $message = 'Registro eliminado correctamente.';
                $messageType = 'success';
            } else {
                $message = (string) ($result['message'] ?? 'No fue posible eliminar el registro.');
                $messageType = 'error';
            }
        }

        $users = list_all_users();
        $jobs = list_recent_audio_jobs();
    }
}

$totalUsers = count($users);
$activeUsers = 0;
$completedJobs = 0;
$failedJobs = 0;

foreach ($users as $userRow) {
    if ((int) $userRow['is_active'] === 1) {
        $activeUsers++;
    }
}

foreach ($jobs as $jobRow) {
    if (($jobRow['status'] ?? '') === 'completed') {
        $completedJobs++;
    }

    if (($jobRow['status'] ?? '') === 'failed') {
        $failedJobs++;
    }
}

$audioprintUsers = array_filter(
    $users,
    static fn(array $user): bool => membership_for_product($user, 'audioprint') !== null
);

$csrfToken = csrf_token();
render_app_header('d-ml | Admin');
?>
<section class="page-stack">
  <section class="hero">
    <div class="dashboard-hero">
      <div>
        <span class="role-badge">Admin global</span>
        <h1>Gestiona usuarios, accesos por solución y permisos del sistema.</h1>
        <p class="lead">Este panel no es una herramienta operativa de análisis. Aquí decides quién entra a cada solución activa de la marca y quién conserva permisos de administración global.</p>
      </div>
      <div class="stats-grid">
        <article class="stat-card">
          <strong><?= $totalUsers ?></strong>
          <span>Usuarios registrados</span>
        </article>
        <article class="stat-card">
          <strong><?= $activeUsers ?></strong>
          <span>Usuarios activos</span>
        </article>
        <article class="stat-card">
      <strong><?= $completedJobs ?></strong>
      <span>Análisis completados</span>
        </article>
        <article class="stat-card">
          <strong><?= $failedJobs ?></strong>
          <span>Procesos con error</span>
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

  <section class="panel-grid admin-management">
    <article class="card">
      <span class="section-tag">Nuevo acceso</span>
      <h2>Crear usuario para Audioprint</h2>
      <p>Da de alta una cuenta inicial dentro del sistema. Después podrás decidir a qué soluciones entra y con qué rol en cada una, a medida que la marca habilite nuevos frentes operativos.</p>

      <form method="post" action="/portal/admin.php" class="form-block">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="action" value="create_user">

        <div class="form-grid two">
          <div>
            <label for="first_name">Nombre</label>
            <input id="first_name" name="first_name" type="text" value="<?= htmlspecialchars($createForm['first_name'], ENT_QUOTES, 'UTF-8') ?>" required>
          </div>
          <div>
            <label for="last_name">Apellidos</label>
            <input id="last_name" name="last_name" type="text" value="<?= htmlspecialchars($createForm['last_name'], ENT_QUOTES, 'UTF-8') ?>" required>
          </div>
        </div>

        <div class="form-grid two">
          <div>
            <label for="email">Correo</label>
            <input id="email" name="email" type="email" value="<?= htmlspecialchars($createForm['email'], ENT_QUOTES, 'UTF-8') ?>" required>
          </div>
          <div>
            <label for="role_code">Rol</label>
            <select id="role_code" name="role_code">
              <?php foreach ($rolesByProduct['audioprint'] as $role): ?>
                <option value="<?= htmlspecialchars($role['code'], ENT_QUOTES, 'UTF-8') ?>" <?= $createForm['role_code'] === $role['code'] ? 'selected' : '' ?>>
                  <?= htmlspecialchars($role['name'], ENT_QUOTES, 'UTF-8') ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="form-grid two">
          <div>
            <label for="password">Contraseña inicial</label>
            <input id="password" name="password" type="password" placeholder="Minimo 8 caracteres" required>
          </div>
          <div>
            <label for="password_confirm">Repetir contraseña</label>
            <input id="password_confirm" name="password_confirm" type="password" placeholder="Confirma la contraseña" required>
          </div>
        </div>

        <button class="button" type="submit">Crear usuario</button>
      </form>
    </article>

    <article class="card">
      <span class="section-tag">Usuarios</span>
      <h2>Accesos activos y membresías</h2>
      <p>Controla el acceso a cada solución, el estado de la cuenta y la administración global del sistema sin mezclar esta capa de gestión con las herramientas operativas.</p>

      <div class="table-shell">
        <table class="users-table">
          <thead>
            <tr>
              <th>Usuario</th>
              <th>Correo</th>
              <th>Soluciones</th>
              <th>Admin global</th>
              <th>Estado</th>
              <th>Control global</th>
              <th>Ultimo acceso</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($users as $userRow): ?>
              <tr>
                <td>
                  <strong><?= htmlspecialchars($userRow['first_name'] . ' ' . $userRow['last_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                  <div class="table-meta">Creado: <?= htmlspecialchars((string) $userRow['created_at'], ENT_QUOTES, 'UTF-8') ?></div>
                </td>
                <td><?= htmlspecialchars($userRow['email'], ENT_QUOTES, 'UTF-8') ?></td>
                <td>
                  <div class="stack">
                    <?php foreach ($products as $product): ?>
                      <?php $membership = membership_for_product($userRow, (string) $product['code']); ?>
                      <form method="post" action="/portal/admin.php" class="form-block compact-product-form">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="action" value="update_membership">
                        <input type="hidden" name="user_id" value="<?= (int) $userRow['id'] ?>">
                        <input type="hidden" name="product_code" value="<?= htmlspecialchars((string) $product['code'], ENT_QUOTES, 'UTF-8') ?>">
                        <label><?= htmlspecialchars((string) $product['name'], ENT_QUOTES, 'UTF-8') ?></label>
                        <div class="table-actions">
                          <select name="role_code">
                            <option value="">Sin acceso</option>
                            <?php foreach ($rolesByProduct[$product['code']] as $role): ?>
                              <option value="<?= htmlspecialchars($role['code'], ENT_QUOTES, 'UTF-8') ?>" <?= (($membership['role_code'] ?? '') === $role['code']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($role['name'], ENT_QUOTES, 'UTF-8') ?>
                              </option>
                            <?php endforeach; ?>
                          </select>
                          <button class="button-secondary" type="submit">Guardar</button>
                        </div>
                      </form>
                    <?php endforeach; ?>
                  </div>
                </td>
                <td>
                  <span class="status-pill <?= ($userRow['is_system_admin'] ?? false) ? 'is-active' : 'is-inactive' ?>">
                    <?= ($userRow['is_system_admin'] ?? false) ? 'Si' : 'No' ?>
                  </span>
                </td>
                <td>
                  <form method="post" action="/portal/admin.php" class="form-block compact-product-form">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="user_id" value="<?= (int) $userRow['id'] ?>">
                    <label class="table-check">
                      <input type="checkbox" name="is_active" value="1" <?= ((int) $userRow['is_active']) === 1 ? 'checked' : '' ?>>
                      <span><?= ((int) $userRow['is_active']) === 1 ? 'Activo' : 'Inactivo' ?></span>
                    </label>
                    <button class="button-secondary" type="submit">Guardar estado</button>
                  </form>
                </td>
                <td>
                  <div class="stack">
                    <form method="post" action="/portal/admin.php" class="form-block compact-product-form">
                      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                      <input type="hidden" name="action" value="reset_password">
                      <input type="hidden" name="user_id" value="<?= (int) $userRow['id'] ?>">
                      <label>Nueva contraseña</label>
                      <input name="new_password" type="password" placeholder="Minimo 8 caracteres" required>
                      <input name="new_password_confirm" type="password" placeholder="Confirmar contraseña" required>
                      <button class="button-secondary" type="submit">Cambiar contraseña</button>
                    </form>

                    <form method="post" action="/portal/admin.php" class="inline-form" onsubmit="return confirm('¿Estas seguro de que deseas eliminar este usuario y todos sus registros asociados?');">
                      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                      <input type="hidden" name="action" value="delete_user">
                      <input type="hidden" name="user_id" value="<?= (int) $userRow['id'] ?>">
                      <button class="button-danger" type="submit">Eliminar usuario</button>
                    </form>
                  </div>
                </td>
                <td><?= htmlspecialchars((string) ($userRow['last_login_at'] ?? 'Sin acceso'), ENT_QUOTES, 'UTF-8') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </article>
  </section>

  <article class="card">
    <span class="section-tag">Actividad</span>
    <h2>Ultimos audios procesados</h2>
    <p>Un resumen rapido de los ultimos archivos subidos, con su estado y acceso directo al escalograma cuando existe.</p>

    <div class="table-shell">
      <table class="users-table">
        <thead>
          <tr>
            <th>Usuario</th>
            <th>Audio</th>
              <th>Estado</th>
              <th>Creado</th>
              <th>Resultado</th>
              <th>Acciones</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($jobs as $job): ?>
            <tr>
              <td>
                <strong><?= htmlspecialchars(trim($job['first_name'] . ' ' . $job['last_name']), ENT_QUOTES, 'UTF-8') ?></strong>
                <div class="table-meta"><?= htmlspecialchars($job['email'], ENT_QUOTES, 'UTF-8') ?></div>
              </td>
              <td><?= htmlspecialchars($job['original_filename'], ENT_QUOTES, 'UTF-8') ?></td>
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
                <?php if (!empty($job['scalogram_url'])): ?>
                  <a class="button-secondary" href="<?= htmlspecialchars((string) $job['scalogram_url'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noreferrer">Ver escalograma</a>
                <?php else: ?>
                  <span class="muted">Sin archivo</span>
                <?php endif; ?>
              </td>
              <td>
                <form method="post" action="/portal/admin.php" class="inline-form">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                  <input type="hidden" name="action" value="delete_job">
                  <input type="hidden" name="job_id" value="<?= (int) $job['id'] ?>">
                  <button class="button-secondary" type="submit">Eliminar</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </article>
</section>
<?php render_app_footer(); ?>
