<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verify_csrf(?string $token): bool
{
    return isset($_SESSION['csrf_token']) && is_string($token) && hash_equals($_SESSION['csrf_token'], $token);
}

function normalize_email(string $email): string
{
    return trim(strtolower($email));
}

function present_product_name(string $productCode, string $fallbackName): string
{
    if ($productCode === 'smart_tales') {
        return 'Smart Tales';
    }

    return $fallbackName;
}

function present_product_description(string $productCode, ?string $fallbackDescription): ?string
{
    if ($productCode === 'smart_tales') {
        return 'Cuentos personalizados con voces familiares, perfiles infantiles e historial narrativo.';
    }

    return $fallbackDescription;
}

function product_dashboard_path(string $productCode): ?string
{
    if ($productCode === 'audioprint') {
        return '/portal/audioprint.php';
    }

    if ($productCode === 'qvoice') {
        return '/portal/qvoice.php';
    }

    if ($productCode === 'smart_tales') {
        return '/portal/smart_tales.php';
    }

    return null;
}

function find_product_by_code(string $productCode): ?array
{
    $stmt = db()->prepare('SELECT id, code, name, description, is_public, is_active, sort_order FROM products WHERE code = :code LIMIT 1');
    $stmt->execute(['code' => $productCode]);
    $product = $stmt->fetch();
    if (!$product) {
        return null;
    }

    $product['name'] = present_product_name((string) $product['code'], (string) $product['name']);
    $product['description'] = present_product_description((string) $product['code'], $product['description'] ?? null);

    return $product;
}

function list_all_products(): array
{
    $stmt = db()->query('SELECT id, code, name, description, is_public, is_active, sort_order FROM products WHERE is_active = 1 ORDER BY sort_order ASC, name ASC');
    $products = $stmt->fetchAll();
    $products = array_values(array_filter($products, static fn(array $product): bool => product_dashboard_path((string) $product['code']) !== null));

    return array_map(static function (array $product): array {
        $product['name'] = present_product_name((string) $product['code'], (string) $product['name']);
        $product['description'] = present_product_description((string) $product['code'], $product['description'] ?? null);
        return $product;
    }, $products);
}

function list_public_products(): array
{
    $stmt = db()->query('SELECT id, code, name, description FROM products WHERE is_public = 1 AND is_active = 1 ORDER BY sort_order ASC, name ASC');
    $products = $stmt->fetchAll();
    $products = array_values(array_filter($products, static fn(array $product): bool => product_dashboard_path((string) $product['code']) !== null));

    return array_map(static function (array $product): array {
        $product['name'] = present_product_name((string) $product['code'], (string) $product['name']);
        $product['description'] = present_product_description((string) $product['code'], $product['description'] ?? null);
        return $product;
    }, $products);
}

function list_roles_for_product(string $productCode): array
{
    $sql = <<<'SQL'
        SELECT r.id, r.code, r.name, r.description
        FROM roles r
        INNER JOIN products p ON p.id = r.product_id
        WHERE p.code = :product_code
        ORDER BY r.name ASC
    SQL;

    $stmt = db()->prepare($sql);
    $stmt->execute(['product_code' => $productCode]);
    return $stmt->fetchAll();
}

function fetch_roles_for_product_codes(string $productCode, array $roleCodes): array
{
    if ($roleCodes === []) {
        return [];
    }

    $roleCodes = array_values(array_unique($roleCodes));
    $placeholders = implode(',', array_fill(0, count($roleCodes), '?'));
    $sql = <<<SQL
        SELECT r.id, r.code, r.name, r.description
        FROM roles r
        INNER JOIN products p ON p.id = r.product_id
        WHERE p.code = ? AND r.code IN ($placeholders)
        ORDER BY r.name ASC
    SQL;

    $stmt = db()->prepare($sql);
    $stmt->execute(array_merge([$productCode], $roleCodes));
    return $stmt->fetchAll();
}

function fetch_user_memberships(int $userId): array
{
    $sql = <<<'SQL'
        SELECT
            upr.product_id,
            upr.role_id,
            p.code AS product_code,
            p.name AS product_name,
            r.code AS role_code,
            r.name AS role_name
        FROM user_product_roles upr
        INNER JOIN products p ON p.id = upr.product_id
        INNER JOIN roles r ON r.id = upr.role_id
        WHERE upr.user_id = :user_id
        ORDER BY p.sort_order ASC, p.name ASC
    SQL;

    $stmt = db()->prepare($sql);
    $stmt->execute(['user_id' => $userId]);
    $memberships = $stmt->fetchAll();
    $memberships = array_values(array_filter($memberships, static fn(array $membership): bool => product_dashboard_path((string) $membership['product_code']) !== null));

    return array_map(static function (array $membership): array {
        $membership['product_name'] = present_product_name((string) $membership['product_code'], (string) $membership['product_name']);
        return $membership;
    }, $memberships);
}

function hydrate_user(array $user): array
{
    $memberships = fetch_user_memberships((int) $user['id']);
    $user['memberships'] = $memberships;
    $user['is_system_admin'] = ((int) ($user['is_system_admin'] ?? 0)) === 1;
    return $user;
}

function membership_for_product(array $user, string $productCode): ?array
{
    foreach (($user['memberships'] ?? []) as $membership) {
        if (($membership['product_code'] ?? '') === $productCode) {
            return $membership;
        }
    }

    return null;
}

function find_user_by_email(string $email): ?array
{
    $stmt = db()->prepare('SELECT id, first_name, last_name, email, password_hash, is_active, is_system_admin, last_login_at, created_at FROM users WHERE email = :email LIMIT 1');
    $stmt->execute(['email' => normalize_email($email)]);
    $user = $stmt->fetch();

    return $user ? hydrate_user($user) : null;
}

function find_user_by_id(int $userId): ?array
{
    $stmt = db()->prepare('SELECT id, first_name, last_name, email, password_hash, is_active, is_system_admin, last_login_at, created_at FROM users WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $userId]);
    $user = $stmt->fetch();

    return $user ? hydrate_user($user) : null;
}

function list_all_users(): array
{
    $stmt = db()->query('SELECT id, first_name, last_name, email, password_hash, is_active, is_system_admin, last_login_at, created_at FROM users ORDER BY created_at DESC, id DESC');
    $rows = $stmt->fetchAll();
    return array_map('hydrate_user', $rows);
}

function list_users_with_roles(string $productCode): array
{
    return array_values(array_filter(
        list_all_users(),
        static fn(array $user): bool => membership_for_product($user, $productCode) !== null
    ));
}

function create_user_record(string $firstName, string $lastName, string $email, string $password, string $productCode, array $roleCodes): array
{
    $email = normalize_email($email);
    if (find_user_by_email($email) !== null) {
        return ['ok' => false, 'message' => 'Ya existe una cuenta con ese correo.'];
    }

    $product = find_product_by_code($productCode);
    if ($product === null || (int) $product['is_active'] !== 1) {
        return ['ok' => false, 'message' => 'Producto no disponible para registro.'];
    }

    if ($roleCodes === []) {
        return ['ok' => false, 'message' => 'Debes indicar al menos un rol.'];
    }

    $roles = fetch_roles_for_product_codes($productCode, $roleCodes);
    if (count($roles) !== count(array_unique($roleCodes))) {
        return ['ok' => false, 'message' => 'Uno o mas roles no son validos para este producto.'];
    }

    $passwordHash = password_hash($password, PASSWORD_BCRYPT);
    $pdo = db();
    $pdo->beginTransaction();

    try {
        $insertUser = $pdo->prepare('INSERT INTO users (first_name, last_name, email, password_hash, is_active, is_system_admin) VALUES (:first_name, :last_name, :email, :password_hash, 1, 0)');
        $insertUser->execute([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'password_hash' => $passwordHash,
        ]);

        $userId = (int) $pdo->lastInsertId();
        $insertMembership = $pdo->prepare(
            'INSERT INTO user_product_roles (user_id, product_id, role_id) VALUES (:user_id, :product_id, :role_id)'
        );

        foreach ($roles as $role) {
            $insertMembership->execute([
                'user_id' => $userId,
                'product_id' => $product['id'],
                'role_id' => $role['id'],
            ]);
        }

        $pdo->commit();
        return ['ok' => true, 'user_id' => $userId];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'message' => 'No fue posible crear la cuenta.'];
    }
}

function register_user(string $firstName, string $lastName, string $email, string $password, string $productCode = 'audioprint', array $roleCodes = ['user']): array
{
    return create_user_record($firstName, $lastName, $email, $password, $productCode, $roleCodes);
}

function admin_create_user(string $firstName, string $lastName, string $email, string $password, string $productCode, array $roleCodes): array
{
    return create_user_record($firstName, $lastName, $email, $password, $productCode, $roleCodes);
}

function update_user_product_role(int $userId, string $productCode, string $roleCode): array
{
    $product = find_product_by_code($productCode);
    if ($product === null) {
        return ['ok' => false, 'message' => 'Producto no encontrado.'];
    }

    $roles = fetch_roles_for_product_codes($productCode, [$roleCode]);
    if ($roles === []) {
        return ['ok' => false, 'message' => 'Rol no valido para este producto.'];
    }

    $check = db()->prepare('SELECT id FROM user_product_roles WHERE user_id = :user_id AND product_id = :product_id LIMIT 1');
    $check->execute([
        'user_id' => $userId,
        'product_id' => $product['id'],
    ]);
    $existing = $check->fetch();

    if ($existing !== false) {
        $stmt = db()->prepare(
            'UPDATE user_product_roles SET role_id = :role_id, updated_at = NOW() WHERE user_id = :user_id AND product_id = :product_id'
        );
        $stmt->execute([
            'role_id' => $roles[0]['id'],
            'user_id' => $userId,
            'product_id' => $product['id'],
        ]);
    } else {
        $stmt = db()->prepare(
            'INSERT INTO user_product_roles (user_id, product_id, role_id) VALUES (:user_id, :product_id, :role_id)'
        );
        $stmt->execute([
            'role_id' => $roles[0]['id'],
            'user_id' => $userId,
            'product_id' => $product['id'],
        ]);
    }

    return ['ok' => true];
}

function remove_user_product_access(int $userId, string $productCode): array
{
    $product = find_product_by_code($productCode);
    if ($product === null) {
        return ['ok' => false, 'message' => 'Producto no encontrado.'];
    }

    $stmt = db()->prepare('DELETE FROM user_product_roles WHERE user_id = :user_id AND product_id = :product_id');
    $stmt->execute([
        'user_id' => $userId,
        'product_id' => $product['id'],
    ]);

    return ['ok' => true];
}

function update_user_status(int $userId, bool $isActive): array
{
    $stmt = db()->prepare('UPDATE users SET is_active = :is_active WHERE id = :id');
    $stmt->execute([
        'is_active' => $isActive ? 1 : 0,
        'id' => $userId,
    ]);

    return ['ok' => true];
}

function admin_update_user_password(int $userId, string $password): array
{
    if (strlen($password) < 8) {
        return ['ok' => false, 'message' => 'La contrasena debe tener al menos 8 caracteres.'];
    }

    $passwordHash = password_hash($password, PASSWORD_BCRYPT);
    $stmt = db()->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id');
    $stmt->execute([
        'password_hash' => $passwordHash,
        'id' => $userId,
    ]);

    return ['ok' => true];
}

function admin_delete_user_account(int $userId): array
{
    $stmt = db()->prepare('DELETE FROM users WHERE id = :id');
    $stmt->execute(['id' => $userId]);

    return ['ok' => true];
}

function login_attempt(string $email, string $password, ?string $productCode = null): array
{
    $user = find_user_by_email($email);
    if ($user === null || !password_verify($password, (string) $user['password_hash'])) {
        return ['ok' => false, 'message' => 'Credenciales invalidas.'];
    }

    if ((int) $user['is_active'] !== 1) {
        return ['ok' => false, 'message' => 'La cuenta esta inactiva.'];
    }

    if (count($user['memberships']) === 0) {
        return ['ok' => false, 'message' => 'Tu cuenta no tiene productos asociados.'];
    }

    $resolvedProductCode = $productCode;
    if ($resolvedProductCode === null || $resolvedProductCode === '') {
        $resolvedProductCode = (string) ($user['memberships'][0]['product_code'] ?? '');
    }

    $membership = membership_for_product($user, $resolvedProductCode);
    if ($membership === null) {
        return ['ok' => false, 'message' => 'Tu cuenta no tiene acceso a este producto.'];
    }

    session_regenerate_id(true);
    $_SESSION['user'] = [
        'id' => (int) $user['id'],
        'first_name' => $user['first_name'],
        'last_name' => $user['last_name'],
        'email' => $user['email'],
        'is_system_admin' => ((int) $user['is_system_admin']) === 1,
        'memberships' => $user['memberships'],
        'current_product_code' => count($user['memberships']) === 1 ? $membership['product_code'] : null,
        'current_product_name' => count($user['memberships']) === 1 ? $membership['product_name'] : null,
        'primary_role' => count($user['memberships']) === 1 ? $membership['role_code'] : null,
        'primary_role_name' => count($user['memberships']) === 1 ? $membership['role_name'] : null,
    ];

    $update = db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id');
    $update->execute(['id' => $user['id']]);

    return ['ok' => true, 'user' => $_SESSION['user']];
}

function logout_user(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
    }

    session_destroy();
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function refresh_current_user(): ?array
{
    $user = current_user();
    if ($user === null) {
        return null;
    }

    $freshUser = find_user_by_id((int) $user['id']);
    if ($freshUser === null) {
        unset($_SESSION['user']);
        return null;
    }

    $_SESSION['user'] = [
        'id' => (int) $freshUser['id'],
        'first_name' => $freshUser['first_name'],
        'last_name' => $freshUser['last_name'],
        'email' => $freshUser['email'],
        'is_system_admin' => ((int) $freshUser['is_system_admin']) === 1,
        'memberships' => $freshUser['memberships'],
        'current_product_code' => $_SESSION['user']['current_product_code'] ?? null,
        'current_product_name' => $_SESSION['user']['current_product_name'] ?? null,
        'primary_role' => $_SESSION['user']['primary_role'] ?? null,
        'primary_role_name' => $_SESSION['user']['primary_role_name'] ?? null,
    ];

    if (($_SESSION['user']['current_product_code'] ?? null) === null && count($freshUser['memberships']) === 1) {
        $membership = $freshUser['memberships'][0];
        $_SESSION['user']['current_product_code'] = $membership['product_code'];
        $_SESSION['user']['current_product_name'] = $membership['product_name'];
        $_SESSION['user']['primary_role'] = $membership['role_code'];
        $_SESSION['user']['primary_role_name'] = $membership['role_name'];
    }

    return $_SESSION['user'];
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

function require_login(): void
{
    if (!is_logged_in()) {
        header('Location: /login.php');
        exit;
    }

    refresh_current_user();
}

function current_membership(?string $productCode = null): ?array
{
    $user = current_user();
    if ($user === null) {
        return null;
    }

    $selectedProduct = $productCode ?? (string) ($user['current_product_code'] ?? '');
    foreach (($user['memberships'] ?? []) as $membership) {
        if (($membership['product_code'] ?? '') === $selectedProduct) {
            return $membership;
        }
    }

    return null;
}

function current_product_tabs(): array
{
    $user = current_user();
    return $user['memberships'] ?? [];
}

function is_system_admin(): bool
{
    $user = refresh_current_user();
    return $user !== null && (($user['is_system_admin'] ?? false) === true);
}

function require_system_admin(): void
{
    require_login();

    if (!is_system_admin()) {
        http_response_code(403);
        echo 'Acceso denegado para administracion global.';
        exit;
    }
}

function set_current_product(string $productCode): void
{
    if (!is_logged_in()) {
        return;
    }

    $user = current_user();
    if ($user === null) {
        return;
    }

    $membership = membership_for_product($user, $productCode);
    if ($membership === null) {
        return;
    }

    $_SESSION['user']['current_product_code'] = $membership['product_code'];
    $_SESSION['user']['current_product_name'] = $membership['product_name'];
    $_SESSION['user']['primary_role'] = $membership['role_code'];
    $_SESSION['user']['primary_role_name'] = $membership['role_name'];
}

function user_has_product_access(string $productCode): bool
{
    return current_membership($productCode) !== null;
}

function require_product_access(string $productCode): void
{
    require_login();

    if (!user_has_product_access($productCode)) {
        http_response_code(403);
        echo 'Acceso denegado para este producto.';
        exit;
    }
}

function user_has_role(string $role, ?string $productCode = null): bool
{
    $membership = current_membership($productCode);
    return $membership !== null && (($membership['role_code'] ?? '') === $role);
}

function require_role(string $role): void
{
    require_login();

    if (!user_has_role($role)) {
        http_response_code(403);
        echo 'Acceso denegado para este rol.';
        exit;
    }
}

function require_product_role(string $productCode, string $role): void
{
    require_product_access($productCode);

    if (!user_has_role($role, $productCode)) {
        http_response_code(403);
        echo 'Acceso denegado para este rol.';
        exit;
    }
}

function can_administer_product(string $productCode): bool
{
    return is_system_admin() || user_has_role('admin', $productCode);
}

function dashboard_url_for_role(string $role, ?string $productCode = null): string
{
    $resolvedProduct = $productCode ?? ((string) (current_user()['current_product_code'] ?? ''));
    return product_dashboard_path($resolvedProduct) ?? '/dashboard.php';
}
