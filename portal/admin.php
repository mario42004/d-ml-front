<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/audioprint.php';

require_system_admin();

$currentUser = current_user();
$message = null;
$messageType = 'success';
$organizations = list_organizations();
$products = list_all_products();
$rolesByProduct = [];
foreach ($products as $product) {
    $rolesByProduct[$product['code']] = list_roles_for_product((string) $product['code']);
}
$users = list_all_users();
$jobs = list_recent_audio_jobs();
$defaultOrg = default_organization();
$createForm = [
    'first_name' => '',
    'last_name' => '',
    'email' => '',
    'organization_id' => (string) ((int) ($defaultOrg['id'] ?? 1)),
    'product_code' => 'audioprint',
    'role_code' => 'user',
];
$superadminForm = [
    'first_name' => '',
    'last_name' => '',
    'email' => '',
    'organization_id' => (string) ((int) ($defaultOrg['id'] ?? 1)),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? null;

    if (!verify_csrf(is_string($token) ? $token : null)) {
        $message = 'La sesión del formulario no es válida. Recarga la página e inténtalo de nuevo.';
        $messageType = 'error';
    } else {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'create_organization') {
            $result = create_organization_record((string) ($_POST['organization_name'] ?? ''));
            $message = ($result['ok'] ?? false) ? 'Organización creada correctamente.' : (string) ($result['message'] ?? 'No fue posible crear la organización.');
            $messageType = ($result['ok'] ?? false) ? 'success' : 'error';
        }

        if ($action === 'update_organization') {
            $result = update_organization_record(
                (int) ($_POST['organization_id'] ?? 0),
                (string) ($_POST['organization_name'] ?? ''),
                ((string) ($_POST['is_active'] ?? '0')) === '1'
            );
            $message = ($result['ok'] ?? false) ? 'Organización actualizada correctamente.' : (string) ($result['message'] ?? 'No fue posible actualizar la organización.');
            $messageType = ($result['ok'] ?? false) ? 'success' : 'error';
        }

        if ($action === 'delete_organization') {
            $organizationId = (int) ($_POST['organization_id'] ?? 0);
            $confirmation = trim((string) ($_POST['delete_confirmation'] ?? ''));
            $organization = get_organization_by_id($organizationId);

            if ($organization === null) {
                $message = 'Organización no encontrada.';
                $messageType = 'error';
            } elseif ($confirmation !== (string) $organization['name']) {
                $message = 'Para eliminar una organización debes escribir exactamente su nombre.';
                $messageType = 'error';
            } else {
                $result = delete_organization_record($organizationId);
                if (($result['ok'] ?? false) === true) {
                    $message = 'Organización eliminada. Sus usuarios fueron movidos a Genérica con roles de producto User.';
                    $messageType = 'success';
                } else {
                    $message = (string) ($result['message'] ?? 'No fue posible eliminar la organización.');
                    $messageType = 'error';
                }
            }
        }

        if ($action === 'create_user') {
            $createForm['first_name'] = trim((string) ($_POST['first_name'] ?? ''));
            $createForm['last_name'] = trim((string) ($_POST['last_name'] ?? ''));
            $createForm['email'] = trim((string) ($_POST['email'] ?? ''));
            $createForm['organization_id'] = trim((string) ($_POST['organization_id'] ?? ''));
            $createForm['product_code'] = trim((string) ($_POST['product_code'] ?? 'audioprint'));
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
                    $createForm['product_code'],
                    [$createForm['role_code']],
                    (int) $createForm['organization_id']
                );
                $message = ($result['ok'] ?? false) ? 'Usuario creado correctamente.' : (string) ($result['message'] ?? 'No fue posible crear el usuario.');
                $messageType = ($result['ok'] ?? false) ? 'success' : 'error';
                if (($result['ok'] ?? false) === true) {
                    $createForm = ['first_name' => '', 'last_name' => '', 'email' => '', 'organization_id' => (string) ((int) $defaultOrg['id']), 'product_code' => 'audioprint', 'role_code' => 'user'];
                }
            }
        }

        if ($action === 'create_superadmin') {
            $superadminForm['first_name'] = trim((string) ($_POST['first_name'] ?? ''));
            $superadminForm['last_name'] = trim((string) ($_POST['last_name'] ?? ''));
            $superadminForm['email'] = trim((string) ($_POST['email'] ?? ''));
            $superadminForm['organization_id'] = trim((string) ($_POST['organization_id'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');

            if ($superadminForm['first_name'] === '' || $superadminForm['last_name'] === '' || $superadminForm['email'] === '') {
                $message = 'Debes completar nombre, apellidos y correo para crear un superadmin.';
                $messageType = 'error';
            } elseif (!filter_var($superadminForm['email'], FILTER_VALIDATE_EMAIL)) {
                $message = 'Debes indicar un correo válido.';
                $messageType = 'error';
            } elseif (strlen($password) < 8) {
                $message = 'La contraseña debe tener al menos 8 caracteres.';
                $messageType = 'error';
            } elseif ($password !== $passwordConfirm) {
                $message = 'Las contraseñas no coinciden.';
                $messageType = 'error';
            } else {
                $result = admin_create_superadmin(
                    $superadminForm['first_name'],
                    $superadminForm['last_name'],
                    $superadminForm['email'],
                    $password,
                    (int) $superadminForm['organization_id']
                );
                $message = ($result['ok'] ?? false) ? 'Superadmin creado correctamente.' : (string) ($result['message'] ?? 'No fue posible crear el superadmin.');
                $messageType = ($result['ok'] ?? false) ? 'success' : 'error';
                if (($result['ok'] ?? false) === true) {
                    $superadminForm = ['first_name' => '', 'last_name' => '', 'email' => '', 'organization_id' => (string) ((int) $defaultOrg['id'])];
                }
            }
        }

        if ($action === 'update_user_organization') {
            $userId = (int) ($_POST['user_id'] ?? 0);
            $organizationId = (int) ($_POST['organization_id'] ?? 0);
            $result = update_user_organization($userId, $organizationId);
            $message = ($result['ok'] ?? false) ? 'Organización del usuario actualizada correctamente.' : (string) ($result['message'] ?? 'No fue posible cambiar la organización.');
            $messageType = ($result['ok'] ?? false) ? 'success' : 'error';
        }

        if ($action === 'update_membership') {
            $userId = (int) ($_POST['user_id'] ?? 0);
            $productCode = trim((string) ($_POST['product_code'] ?? 'audioprint'));
            $roleCode = trim((string) ($_POST['role_code'] ?? ''));
            $isSelf = $currentUser !== null && $userId === (int) $currentUser['id'];
            $isRemovingSelfAdmin = $isSelf && $productCode === 'audioprint' && $roleCode !== 'admin' && !($currentUser['is_system_admin'] ?? false);

            if ($isRemovingSelfAdmin) {
                $message = 'No puedes quitarte el rol admin de Audioprint desde tu propia sesión.';
                $messageType = 'error';
            } else {
                $roleResult = $roleCode === ''
                    ? remove_user_product_access($userId, $productCode)
                    : update_user_product_role($userId, $productCode, $roleCode);

                $message = ($roleResult['ok'] ?? false) ? 'Rol de producto actualizado correctamente.' : (string) ($roleResult['message'] ?? 'No fue posible actualizar el acceso.');
                $messageType = ($roleResult['ok'] ?? false) ? 'success' : 'error';
            }
        }

        if ($action === 'add_coins') {
            $userId = (int) ($_POST['user_id'] ?? 0);
            $productCode = trim((string) ($_POST['product_code'] ?? ''));
            $amount = (int) ($_POST['coin_amount'] ?? 0);

            if ($userId <= 0 || $productCode === '') {
                $message = 'Debes seleccionar un usuario y un producto para recargar coins.';
                $messageType = 'error';
            } elseif ($amount <= 0 || $amount > 100000) {
                $message = 'La recarga debe ser mayor que cero y menor o igual a 100000 coins.';
                $messageType = 'error';
            } else {
                $coinResult = add_product_coins(
                    $userId,
                    $productCode,
                    $amount,
                    $currentUser !== null ? (int) $currentUser['id'] : null,
                    'manual_adjustment',
                    'Recarga manual de coins por superadmin'
                );
                $message = ($coinResult['ok'] ?? false) ? 'Coins agregadas correctamente.' : (string) ($coinResult['message'] ?? 'No fue posible agregar coins.');
                $messageType = ($coinResult['ok'] ?? false) ? 'success' : 'error';
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
                $message = ($result['ok'] ?? false) ? 'Contraseña actualizada correctamente.' : (string) ($result['message'] ?? 'No fue posible actualizar la contraseña.');
                $messageType = ($result['ok'] ?? false) ? 'success' : 'error';
            }
        }

        if ($action === 'delete_user') {
            $userId = (int) ($_POST['user_id'] ?? 0);
            $isSelf = $currentUser !== null && $userId === (int) $currentUser['id'];
            $targetUser = find_user_by_id($userId);
            $isTargetSuperadmin = $targetUser !== null && (($targetUser['is_system_admin'] ?? false) === true);
            $superadminConfirmation = trim((string) ($_POST['superadmin_delete_confirmation'] ?? ''));

            if ($isSelf) {
                $message = 'No puedes eliminar tu propia cuenta desde la sesión actual.';
                $messageType = 'error';
            } elseif ($targetUser === null) {
                $message = 'Usuario no encontrado.';
                $messageType = 'error';
            } elseif ($isTargetSuperadmin && $superadminConfirmation !== 'ELIMINAR SUPERADMIN') {
                $message = 'Para eliminar un superadmin debes escribir exactamente ELIMINAR SUPERADMIN.';
                $messageType = 'error';
            } else {
                $result = admin_delete_user_and_data($userId);
                $message = ($result['ok'] ?? false) ? 'Usuario eliminado correctamente junto con sus archivos y registros asociados.' : (string) ($result['message'] ?? 'No fue posible eliminar el usuario.');
                $messageType = ($result['ok'] ?? false) ? 'success' : 'error';
            }
        }

        if ($action === 'delete_job') {
            $result = delete_audio_job_record((int) ($_POST['job_id'] ?? 0));
            $message = ($result['ok'] ?? false) ? 'Registro eliminado correctamente.' : (string) ($result['message'] ?? 'No fue posible eliminar el registro.');
            $messageType = ($result['ok'] ?? false) ? 'success' : 'error';
        }

        $organizations = list_organizations();
        $users = list_all_users();
        $jobs = list_recent_audio_jobs();
    }
}

$totalUsers = count($users);
$activeUsers = count(array_filter($users, static fn(array $userRow): bool => (int) $userRow['is_active'] === 1));
$completedJobs = count(array_filter($jobs, static fn(array $jobRow): bool => ($jobRow['status'] ?? '') === 'completed'));
$failedJobs = count(array_filter($jobs, static fn(array $jobRow): bool => ($jobRow['status'] ?? '') === 'failed'));
$selectedUserId = (int) ($_GET['user_id'] ?? 0);
$selectedAdminUser = $selectedUserId > 0 ? find_user_by_id($selectedUserId) : null;
$csrfToken = csrf_token();

render_app_header('d-ml | Admin');
?>
<section class="page-stack">
  <section class="hero">
    <div class="dashboard-hero">
      <div>
        <span class="role-badge">Superadmin</span>
        <h1>Gestiona organizaciones, usuarios y accesos por producto.</h1>
        <p class="lead">Cada usuario pertenece a una sola organización. Los roles se asignan por producto dentro de esa organización, y el superadmin conserva control completo del sistema.</p>
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
      <span class="section-tag">Organizaciones</span>
      <h2>Crear y editar contextos</h2>
      <p>La organización inicial del sistema es Genérica. Después puedes crear contextos reales y mover usuarios cuando haga falta.</p>

      <form method="post" action="/portal/admin.php" class="form-block compact-product-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="action" value="create_organization">
        <label for="organization_name">Nueva organización</label>
        <div class="table-actions">
          <input id="organization_name" name="organization_name" type="text" placeholder="Nombre de la organización" required>
          <button class="button-secondary" type="submit">Crear</button>
        </div>
      </form>

      <div class="stack">
        <?php foreach ($organizations as $organization): ?>
          <div class="organization-admin-row">
            <form method="post" action="/portal/admin.php" class="form-block compact-product-form">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
              <input type="hidden" name="action" value="update_organization">
              <input type="hidden" name="organization_id" value="<?= (int) $organization['id'] ?>">
              <label><?= htmlspecialchars((string) $organization['slug'], ENT_QUOTES, 'UTF-8') ?></label>
              <div class="table-actions">
                <input name="organization_name" type="text" value="<?= htmlspecialchars((string) $organization['name'], ENT_QUOTES, 'UTF-8') ?>" required>
                <label class="table-check">
                  <input type="checkbox" name="is_active" value="1" <?= ((int) $organization['is_active']) === 1 ? 'checked' : '' ?>>
                  <span>Activa</span>
                </label>
                <button class="button-secondary" type="submit">Guardar</button>
              </div>
            </form>

            <?php if ((string) $organization['slug'] !== 'generica'): ?>
              <form method="post" action="/portal/admin.php" class="form-block compact-product-form" onsubmit="return confirm('Esta acción moverá sus usuarios a Genérica con rol User y eliminará la organización. ¿Continuar?');">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="delete_organization">
                <input type="hidden" name="organization_id" value="<?= (int) $organization['id'] ?>">
                <label>Confirmar eliminación</label>
                <div class="table-actions">
                  <input name="delete_confirmation" type="text" placeholder="<?= htmlspecialchars((string) $organization['name'], ENT_QUOTES, 'UTF-8') ?>" required>
                  <button class="button-danger" type="submit">Eliminar organización</button>
                </div>
              </form>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </article>

    <article class="card">
      <span class="section-tag">Nuevo acceso</span>
      <h2>Crear usuario</h2>
      <p>Este formulario crea usuarios normales. Ninguna cuenta creada aquí recibe permisos de superadmin.</p>

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
            <label for="organization_id">Organización</label>
            <select id="organization_id" name="organization_id">
              <?php foreach ($organizations as $organization): ?>
                <option value="<?= (int) $organization['id'] ?>" <?= (int) $createForm['organization_id'] === (int) $organization['id'] ? 'selected' : '' ?>>
                  <?= htmlspecialchars((string) $organization['name'], ENT_QUOTES, 'UTF-8') ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="form-grid two">
          <div>
            <label for="product_code">Producto inicial</label>
            <select id="product_code" name="product_code">
              <?php foreach ($products as $product): ?>
                <option value="<?= htmlspecialchars((string) $product['code'], ENT_QUOTES, 'UTF-8') ?>" <?= $createForm['product_code'] === $product['code'] ? 'selected' : '' ?>>
                  <?= htmlspecialchars((string) $product['name'], ENT_QUOTES, 'UTF-8') ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label for="role_code">Rol inicial</label>
            <select id="role_code" name="role_code">
              <?php foreach ($rolesByProduct[$createForm['product_code']] ?? $rolesByProduct['audioprint'] ?? [] as $role): ?>
                <option value="<?= htmlspecialchars((string) $role['code'], ENT_QUOTES, 'UTF-8') ?>" <?= $createForm['role_code'] === $role['code'] ? 'selected' : '' ?>>
                  <?= htmlspecialchars((string) $role['name'], ENT_QUOTES, 'UTF-8') ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="form-grid two">
          <div>
            <label for="password">Contraseña inicial</label>
            <input id="password" name="password" type="password" placeholder="Mínimo 8 caracteres" required>
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
      <span class="section-tag">Superadmin</span>
      <h2>Crear superadmin</h2>
      <p>Este formulario es la única vía de interfaz para crear cuentas con control total del sistema.</p>

      <form method="post" action="/portal/admin.php" class="form-block">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="action" value="create_superadmin">

        <div class="form-grid two">
          <div>
            <label for="super_first_name">Nombre</label>
            <input id="super_first_name" name="first_name" type="text" value="<?= htmlspecialchars($superadminForm['first_name'], ENT_QUOTES, 'UTF-8') ?>" required>
          </div>
          <div>
            <label for="super_last_name">Apellidos</label>
            <input id="super_last_name" name="last_name" type="text" value="<?= htmlspecialchars($superadminForm['last_name'], ENT_QUOTES, 'UTF-8') ?>" required>
          </div>
        </div>

        <div class="form-grid two">
          <div>
            <label for="super_email">Correo</label>
            <input id="super_email" name="email" type="email" value="<?= htmlspecialchars($superadminForm['email'], ENT_QUOTES, 'UTF-8') ?>" required>
          </div>
          <div>
            <label for="super_organization_id">Organización base</label>
            <select id="super_organization_id" name="organization_id">
              <?php foreach ($organizations as $organization): ?>
                <option value="<?= (int) $organization['id'] ?>" <?= (int) $superadminForm['organization_id'] === (int) $organization['id'] ? 'selected' : '' ?>>
                  <?= htmlspecialchars((string) $organization['name'], ENT_QUOTES, 'UTF-8') ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="form-grid two">
          <div>
            <label for="super_password">Contraseña inicial</label>
            <input id="super_password" name="password" type="password" placeholder="Mínimo 8 caracteres" required>
          </div>
          <div>
            <label for="super_password_confirm">Repetir contraseña</label>
            <input id="super_password_confirm" name="password_confirm" type="password" placeholder="Confirma la contraseña" required>
          </div>
        </div>

        <button class="button-secondary" type="submit">Crear superadmin</button>
      </form>
    </article>
  </section>

  <article class="card">
    <span class="section-tag">Usuarios</span>
    <h2>Usuarios registrados</h2>
    <p>Vista compacta para entrar al panel individual de cada usuario sin saturar el dashboard principal.</p>

    <div class="table-shell">
      <table class="users-table">
        <thead>
          <tr>
            <th>Usuario</th>
            <th>Organización</th>
            <th>Coins</th>
            <th>Superadmin</th>
            <th>Estado</th>
            <th>Último acceso</th>
            <th>Acciones</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($users as $userRow): ?>
            <tr>
              <td>
                <strong><?= htmlspecialchars($userRow['first_name'] . ' ' . $userRow['last_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                <div class="table-meta"><?= htmlspecialchars((string) $userRow['email'], ENT_QUOTES, 'UTF-8') ?></div>
                <div class="table-meta">Creado: <?= htmlspecialchars((string) $userRow['created_at'], ENT_QUOTES, 'UTF-8') ?></div>
              </td>
              <td><?= htmlspecialchars((string) $userRow['organization_name'], ENT_QUOTES, 'UTF-8') ?></td>
              <td>
                <div class="coin-summary-list">
                  <?php foreach (($userRow['coin_balances'] ?? []) as $coinBalance): ?>
                    <span class="coin-mini"><?= htmlspecialchars((string) $coinBalance['product_name'], ENT_QUOTES, 'UTF-8') ?>: <?= (int) $coinBalance['balance'] ?></span>
                  <?php endforeach; ?>
                </div>
              </td>
              <td>
                <span class="status-pill <?= ($userRow['is_system_admin'] ?? false) ? 'is-active' : 'is-inactive' ?>">
                  <?= ($userRow['is_system_admin'] ?? false) ? 'Sí' : 'No' ?>
                </span>
              </td>
              <td>
                <span class="status-pill <?= ((int) $userRow['is_active']) === 1 ? 'is-active' : 'is-inactive' ?>">
                  <?= ((int) $userRow['is_active']) === 1 ? 'Activo' : 'Inactivo' ?>
                </span>
              </td>
              <td><?= htmlspecialchars((string) ($userRow['last_login_at'] ?? 'Sin acceso'), ENT_QUOTES, 'UTF-8') ?></td>
              <td>
                <a class="button-secondary" href="/portal/admin.php?user_id=<?= (int) $userRow['id'] ?>">Gestionar</a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </article>

  <?php if ($selectedAdminUser !== null): ?>
    <article class="card" id="user-admin-panel">
      <span class="section-tag">Panel de usuario</span>
      <h2><?= htmlspecialchars($selectedAdminUser['first_name'] . ' ' . $selectedAdminUser['last_name'], ENT_QUOTES, 'UTF-8') ?></h2>
      <p><?= htmlspecialchars((string) $selectedAdminUser['email'], ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars((string) $selectedAdminUser['organization_name'], ENT_QUOTES, 'UTF-8') ?></p>

      <div class="user-admin-grid">
        <section class="detail-card">
          <span class="section-tag">Organización</span>
          <form method="post" action="/portal/admin.php?user_id=<?= (int) $selectedAdminUser['id'] ?>" class="form-block compact-product-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="update_user_organization">
            <input type="hidden" name="user_id" value="<?= (int) $selectedAdminUser['id'] ?>">
            <select name="organization_id">
              <?php foreach ($organizations as $organization): ?>
                <option value="<?= (int) $organization['id'] ?>" <?= (int) $selectedAdminUser['organization_id'] === (int) $organization['id'] ? 'selected' : '' ?>>
                  <?= htmlspecialchars((string) $organization['name'], ENT_QUOTES, 'UTF-8') ?>
                </option>
              <?php endforeach; ?>
            </select>
            <button class="button-secondary" type="submit">Guardar organización</button>
          </form>
        </section>

        <section class="detail-card">
          <span class="section-tag">Estado</span>
          <form method="post" action="/portal/admin.php?user_id=<?= (int) $selectedAdminUser['id'] ?>" class="form-block compact-product-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="update_status">
            <input type="hidden" name="user_id" value="<?= (int) $selectedAdminUser['id'] ?>">
            <label class="table-check">
              <input type="checkbox" name="is_active" value="1" <?= ((int) $selectedAdminUser['is_active']) === 1 ? 'checked' : '' ?>>
              <span><?= ((int) $selectedAdminUser['is_active']) === 1 ? 'Activo' : 'Inactivo' ?></span>
            </label>
            <button class="button-secondary" type="submit">Guardar estado</button>
          </form>
        </section>

        <section class="detail-card user-admin-wide">
          <span class="section-tag">Coins</span>
          <div class="user-admin-options">
            <?php foreach (($selectedAdminUser['coin_balances'] ?? []) as $coinBalance): ?>
              <form method="post" action="/portal/admin.php?user_id=<?= (int) $selectedAdminUser['id'] ?>" class="form-block compact-product-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="add_coins">
                <input type="hidden" name="user_id" value="<?= (int) $selectedAdminUser['id'] ?>">
                <input type="hidden" name="product_code" value="<?= htmlspecialchars((string) $coinBalance['product_code'], ENT_QUOTES, 'UTF-8') ?>">
                <label><?= htmlspecialchars((string) $coinBalance['product_name'], ENT_QUOTES, 'UTF-8') ?></label>
                <div class="coin-admin-line">
                  <span class="coin-pill"><?= (int) $coinBalance['balance'] ?> coins</span>
                  <input name="coin_amount" type="number" min="1" max="100000" step="1" value="5" aria-label="Coins a agregar">
                  <button class="button-secondary" type="submit">Agregar</button>
                </div>
              </form>
            <?php endforeach; ?>
          </div>
        </section>

        <section class="detail-card user-admin-wide">
          <span class="section-tag">Productos</span>
          <div class="user-admin-options">
            <?php foreach ($products as $product): ?>
              <?php $membership = membership_for_product($selectedAdminUser, (string) $product['code']); ?>
              <form method="post" action="/portal/admin.php?user_id=<?= (int) $selectedAdminUser['id'] ?>" class="form-block compact-product-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="update_membership">
                <input type="hidden" name="user_id" value="<?= (int) $selectedAdminUser['id'] ?>">
                <input type="hidden" name="product_code" value="<?= htmlspecialchars((string) $product['code'], ENT_QUOTES, 'UTF-8') ?>">
                <label><?= htmlspecialchars((string) $product['name'], ENT_QUOTES, 'UTF-8') ?></label>
                <div class="table-actions">
                  <select name="role_code">
                    <option value="">Sin acceso</option>
                    <?php foreach ($rolesByProduct[$product['code']] as $role): ?>
                      <option value="<?= htmlspecialchars((string) $role['code'], ENT_QUOTES, 'UTF-8') ?>" <?= (($membership['role_code'] ?? '') === $role['code']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars((string) $role['name'], ENT_QUOTES, 'UTF-8') ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <button class="button-secondary" type="submit">Guardar</button>
                </div>
              </form>
            <?php endforeach; ?>
          </div>
        </section>

        <section class="detail-card">
          <span class="section-tag">Contraseña</span>
          <form method="post" action="/portal/admin.php?user_id=<?= (int) $selectedAdminUser['id'] ?>" class="form-block compact-product-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="reset_password">
            <input type="hidden" name="user_id" value="<?= (int) $selectedAdminUser['id'] ?>">
            <input name="new_password" type="password" placeholder="Mínimo 8 caracteres" required>
            <input name="new_password_confirm" type="password" placeholder="Confirmar contraseña" required>
            <button class="button-secondary" type="submit">Cambiar contraseña</button>
          </form>
        </section>

        <section class="detail-card">
          <span class="section-tag">Baja</span>
          <form method="post" action="/portal/admin.php" class="form-block compact-product-form" onsubmit="return confirm('¿Estás seguro? Se eliminará el usuario junto con todos sus audios, reportes, archivos y registros asociados.');">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="delete_user">
            <input type="hidden" name="user_id" value="<?= (int) $selectedAdminUser['id'] ?>">
            <?php if (($selectedAdminUser['is_system_admin'] ?? false) === true): ?>
              <label>Validación superadmin</label>
              <input name="superadmin_delete_confirmation" type="text" placeholder="ELIMINAR SUPERADMIN" required>
            <?php endif; ?>
            <button class="button-danger" type="submit">Eliminar usuario</button>
          </form>
        </section>
      </div>

      <div class="table-actions">
        <a class="button-secondary" href="/portal/admin.php">Cerrar panel de usuario</a>
      </div>
    </article>
  <?php elseif ($selectedUserId > 0): ?>
    <div class="message is-error">
      <strong>Usuario no encontrado</strong>
      <span>El usuario seleccionado ya no existe o fue eliminado.</span>
    </div>
  <?php endif; ?>

  <article class="card">
    <span class="section-tag">Actividad</span>
    <h2>Últimos audios procesados</h2>
    <p>Vista global para superadmin, con trazabilidad de usuario y organización.</p>

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
