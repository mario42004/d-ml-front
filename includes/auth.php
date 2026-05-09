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
    if ($productCode === 'vibrations') {
        return 'Vibrations';
    }

    if ($productCode === 'audiometer') {
        return 'Audiometer';
    }

    if ($productCode === 'smart_tales') {
        return 'Smart Tales';
    }

    return $fallbackName;
}

function present_product_description(string $productCode, ?string $fallbackDescription): ?string
{
    if ($productCode === 'vibrations') {
        return 'Analisis de acelerometro y giroscopio para seguimiento de vibraciones y cambios anomalos.';
    }

    if ($productCode === 'audiometer') {
        return 'Screening auditivo orientativo con tonos puros, audiograma relativo e historial de pruebas.';
    }

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

    if ($productCode === 'audiometer') {
        return '/portal/audiometer.php';
    }

    if ($productCode === 'vibrations') {
        return '/portal/vibrations.php';
    }

    if ($productCode === 'qvoice') {
        return '/portal/qvoice.php';
    }

    if ($productCode === 'smart_tales') {
        return '/portal/smart_tales.php';
    }

    return null;
}

function ensure_organization_schema(): void
{
    $pdo = db();
    $pdo->exec(
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS organizations (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          name VARCHAR(160) NOT NULL,
          slug VARCHAR(100) NOT NULL,
          is_active TINYINT(1) NOT NULL DEFAULT 1,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          UNIQUE KEY uq_organizations_slug (slug)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL
    );

    $generic = $pdo->query("SELECT id FROM organizations WHERE slug = 'generica' LIMIT 1")->fetch();
    $legacy = $pdo->query("SELECT id FROM organizations WHERE slug = 'default' LIMIT 1")->fetch();

    if ($generic === false && $legacy !== false) {
        $stmt = $pdo->prepare("UPDATE organizations SET name = 'Genérica', slug = 'generica', is_active = 1 WHERE id = :id");
        $stmt->execute(['id' => $legacy['id']]);
    } elseif ($generic === false) {
        $pdo->exec("INSERT INTO organizations (name, slug, is_active) VALUES ('Genérica', 'generica', 1)");
    } else {
        $pdo->exec("UPDATE organizations SET name = 'Genérica', is_active = 1 WHERE slug = 'generica'");
    }

    $genericId = (int) $pdo->query("SELECT id FROM organizations WHERE slug = 'generica' LIMIT 1")->fetchColumn();
    $legacyId = (int) ($pdo->query("SELECT id FROM organizations WHERE slug = 'default' LIMIT 1")->fetchColumn() ?: 0);
    if ($legacyId > 0 && $legacyId !== $genericId) {
        foreach (['users', 'user_product_roles', 'audio_jobs', 'audiometer_tests', 'vibration_phenomena', 'vibration_jobs'] as $table) {
            try {
                $pdo->exec("UPDATE {$table} SET organization_id = {$genericId} WHERE organization_id = {$legacyId}");
            } catch (Throwable) {
            }
        }

        try {
            $pdo->exec("DELETE FROM organizations WHERE id = {$legacyId}");
        } catch (Throwable) {
        }
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'organization_id'");
    if ($stmt !== false && $stmt->fetch() === false) {
        $pdo->exec('ALTER TABLE users ADD COLUMN organization_id BIGINT UNSIGNED NULL AFTER email');
        $pdo->exec("UPDATE users SET organization_id = {$genericId} WHERE organization_id IS NULL");
        $pdo->exec('ALTER TABLE users MODIFY organization_id BIGINT UNSIGNED NOT NULL');
    } else {
        $pdo->exec("UPDATE users SET organization_id = {$genericId} WHERE organization_id IS NULL OR organization_id = 0");
    }

    try {
        $pdo->exec('ALTER TABLE users ADD KEY idx_users_organization (organization_id)');
    } catch (Throwable) {
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM user_product_roles LIKE 'organization_id'");
    if ($stmt !== false && $stmt->fetch() === false) {
        $pdo->exec('ALTER TABLE user_product_roles ADD COLUMN organization_id BIGINT UNSIGNED NULL AFTER user_id');
        $pdo->exec("UPDATE user_product_roles SET organization_id = {$genericId} WHERE organization_id IS NULL");
        $pdo->exec('ALTER TABLE user_product_roles MODIFY organization_id BIGINT UNSIGNED NOT NULL');
    }

    $pdo->exec(
        'DELETE duplicate_role
         FROM user_product_roles duplicate_role
         INNER JOIN user_product_roles kept_role
           ON kept_role.user_id = duplicate_role.user_id
          AND kept_role.product_id = duplicate_role.product_id
          AND kept_role.id < duplicate_role.id'
    );

    $pdo->exec(
        'UPDATE user_product_roles upr
         INNER JOIN users u ON u.id = upr.user_id
         SET upr.organization_id = u.organization_id
         WHERE upr.organization_id <> u.organization_id'
    );

    try {
        $pdo->exec('ALTER TABLE user_product_roles ADD KEY idx_user_product_roles_product (product_id)');
    } catch (Throwable) {
    }

    try {
        $pdo->exec('ALTER TABLE user_product_roles ADD KEY idx_user_product_roles_org (organization_id)');
    } catch (Throwable) {
    }

    try {
        $pdo->exec('ALTER TABLE user_product_roles DROP INDEX uq_user_product_roles_user_product');
    } catch (Throwable) {
    }

    try {
        $pdo->exec('ALTER TABLE user_product_roles ADD UNIQUE KEY uq_user_org_product_roles (user_id, organization_id, product_id)');
    } catch (Throwable) {
    }
}

function default_organization(): array
{
    ensure_organization_schema();

    $stmt = db()->query('SELECT id, name, slug, is_active FROM organizations WHERE slug = "generica" LIMIT 1');
    return $stmt->fetch() ?: ['id' => 1, 'name' => 'Genérica', 'slug' => 'generica', 'is_active' => 1];
}

function normalize_slug(string $value): string
{
    $slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $value) ?? '', '-'));
    return $slug !== '' ? substr($slug, 0, 100) : 'organization';
}

function create_organization_record(string $name): array
{
    ensure_organization_schema();

    $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
    if ($name === '') {
        return ['ok' => false, 'message' => 'Debes indicar una organización.'];
    }

    $baseSlug = normalize_slug($name);
    $slug = $baseSlug;
    $counter = 2;
    while (true) {
        $check = db()->prepare('SELECT id FROM organizations WHERE slug = :slug LIMIT 1');
        $check->execute(['slug' => $slug]);
        if ($check->fetch() === false) {
            break;
        }
        $slug = substr($baseSlug, 0, 94) . '-' . $counter;
        $counter++;
    }

    $stmt = db()->prepare('INSERT INTO organizations (name, slug, is_active) VALUES (:name, :slug, 1)');
    $stmt->execute(['name' => $name, 'slug' => $slug]);

    return ['ok' => true, 'organization_id' => (int) db()->lastInsertId()];
}

function get_organization_by_id(int $organizationId): ?array
{
    ensure_organization_schema();

    $stmt = db()->prepare('SELECT id, name, slug, is_active, created_at FROM organizations WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $organizationId]);
    $organization = $stmt->fetch();

    return $organization ?: null;
}

function update_organization_record(int $organizationId, string $name, bool $isActive): array
{
    ensure_organization_schema();

    $organization = get_organization_by_id($organizationId);
    if ($organization === null) {
        return ['ok' => false, 'message' => 'Organización no encontrada.'];
    }

    $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
    if ($name === '') {
        return ['ok' => false, 'message' => 'Debes indicar el nombre de la organización.'];
    }

    $slug = ((string) $organization['slug']) === 'generica' ? 'generica' : normalize_slug($name);
    $baseSlug = $slug;
    $counter = 2;
    while (true) {
        $check = db()->prepare('SELECT id FROM organizations WHERE slug = :slug AND id <> :id LIMIT 1');
        $check->execute(['slug' => $slug, 'id' => $organizationId]);
        if ($check->fetch() === false) {
            break;
        }
        $slug = substr($baseSlug, 0, 94) . '-' . $counter;
        $counter++;
    }

    $stmt = db()->prepare('UPDATE organizations SET name = :name, slug = :slug, is_active = :is_active WHERE id = :id');
    $stmt->execute([
        'name' => $name,
        'slug' => $slug,
        'is_active' => $isActive ? 1 : 0,
        'id' => $organizationId,
    ]);

    return ['ok' => true];
}

function delete_organization_record(int $organizationId): array
{
    ensure_organization_schema();

    $organization = get_organization_by_id($organizationId);
    if ($organization === null) {
        return ['ok' => false, 'message' => 'Organización no encontrada.'];
    }

    $defaultOrganization = default_organization();
    $defaultOrganizationId = (int) $defaultOrganization['id'];
    if ($organizationId === $defaultOrganizationId || (string) $organization['slug'] === 'generica') {
        return ['ok' => false, 'message' => 'La organización Genérica no se puede eliminar.'];
    }

    $pdo = db();
    $pdo->beginTransaction();

    try {
        $userIdsStmt = $pdo->prepare('SELECT id FROM users WHERE organization_id = :organization_id');
        $userIdsStmt->execute(['organization_id' => $organizationId]);
        $userIds = array_map('intval', $userIdsStmt->fetchAll(PDO::FETCH_COLUMN));

        if ($userIds !== []) {
            $placeholders = implode(',', array_fill(0, count($userIds), '?'));

            $deleteConflictingRoles = $pdo->prepare(
                "DELETE source_role
                 FROM user_product_roles source_role
                 INNER JOIN user_product_roles default_role
                   ON default_role.user_id = source_role.user_id
                  AND default_role.product_id = source_role.product_id
                  AND default_role.organization_id = ?
                 WHERE source_role.organization_id = ?
                   AND source_role.user_id IN ($placeholders)"
            );
            $deleteConflictingRoles->execute(array_merge([$defaultOrganizationId, $organizationId], $userIds));

            $roleUpdate = $pdo->prepare(
                "UPDATE user_product_roles upr
                 INNER JOIN roles user_role
                   ON user_role.product_id = upr.product_id
                  AND user_role.code = 'user'
                 SET upr.role_id = user_role.id,
                     upr.organization_id = ?,
                     upr.updated_at = NOW()
                 WHERE upr.user_id IN ($placeholders)"
            );
            $roleUpdate->execute(array_merge([$defaultOrganizationId], $userIds));

            $userUpdate = $pdo->prepare("UPDATE users SET organization_id = ? WHERE id IN ($placeholders)");
            $userUpdate->execute(array_merge([$defaultOrganizationId], $userIds));

            foreach (['audio_jobs', 'audiometer_tests', 'vibration_phenomena', 'vibration_jobs'] as $table) {
                try {
                    $dataUpdate = $pdo->prepare("UPDATE {$table} SET organization_id = ? WHERE user_id IN ($placeholders)");
                    $dataUpdate->execute(array_merge([$defaultOrganizationId], $userIds));
                } catch (Throwable) {
                }
            }
        }

        $pdo->exec(
            'DELETE duplicate_role
             FROM user_product_roles duplicate_role
             INNER JOIN user_product_roles kept_role
               ON kept_role.user_id = duplicate_role.user_id
              AND kept_role.product_id = duplicate_role.product_id
              AND kept_role.id < duplicate_role.id'
        );

        foreach (['user_product_roles', 'audio_jobs', 'audiometer_tests', 'vibration_phenomena', 'vibration_jobs'] as $table) {
            try {
                $stmt = $pdo->prepare("UPDATE {$table} SET organization_id = :default_organization_id WHERE organization_id = :organization_id");
                $stmt->execute([
                    'default_organization_id' => $defaultOrganizationId,
                    'organization_id' => $organizationId,
                ]);
            } catch (Throwable) {
            }
        }

        $delete = $pdo->prepare('DELETE FROM organizations WHERE id = :id');
        $delete->execute(['id' => $organizationId]);

        $pdo->commit();

        return ['ok' => true, 'moved_users' => count($userIds)];
    } catch (Throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'message' => 'No fue posible eliminar la organización.'];
    }
}

function list_organizations(): array
{
    ensure_organization_schema();

    $stmt = db()->query('SELECT id, name, slug, is_active, created_at FROM organizations ORDER BY name ASC');
    return $stmt->fetchAll();
}

function find_product_by_code(string $productCode): ?array
{
    ensure_organization_schema();

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
    ensure_organization_schema();

    $stmt = db()->query('SELECT id, code, name, description, is_public, is_active, sort_order FROM products WHERE is_active = 1 ORDER BY sort_order ASC, name ASC');
    $products = $stmt->fetchAll();
    $products = array_values(array_filter($products, static fn(array $product): bool => product_dashboard_path((string) $product['code']) !== null));

    return array_map(static function (array $product): array {
        $product['name'] = present_product_name((string) $product['code'], (string) $product['name']);
        $product['description'] = present_product_description((string) $product['code'], $product['description'] ?? null);
        return $product;
    }, $products);
}

function ensure_coin_schema(): void
{
    ensure_organization_schema();

    $pdo = db();
    $pdo->exec(
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS product_coin_wallets (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          user_id BIGINT UNSIGNED NOT NULL,
          product_id BIGINT UNSIGNED NOT NULL,
          balance INT NOT NULL DEFAULT 0,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          UNIQUE KEY uq_product_coin_wallets_user_product (user_id, product_id),
          KEY idx_product_coin_wallets_product (product_id),
          CONSTRAINT fk_product_coin_wallets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
          CONSTRAINT fk_product_coin_wallets_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL
    );

    $pdo->exec(
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS product_coin_ledger (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          user_id BIGINT UNSIGNED NOT NULL,
          product_id BIGINT UNSIGNED NOT NULL,
          actor_user_id BIGINT UNSIGNED NULL,
          amount INT NOT NULL,
          balance_after INT NOT NULL,
          movement_type VARCHAR(40) NOT NULL,
          source_type VARCHAR(80) NULL,
          source_id BIGINT UNSIGNED NULL,
          description VARCHAR(255) NOT NULL DEFAULT '',
          metadata_json JSON NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          KEY idx_product_coin_ledger_user_product_time (user_id, product_id, created_at),
          KEY idx_product_coin_ledger_actor_time (actor_user_id, created_at),
          KEY idx_product_coin_ledger_source (source_type, source_id),
          CONSTRAINT fk_product_coin_ledger_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
          CONSTRAINT fk_product_coin_ledger_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
          CONSTRAINT fk_product_coin_ledger_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL
    );
}

function default_initial_product_coins(): int
{
    return 5;
}

function ensure_user_product_wallet(int $userId, int $productId, int $initialBalance = 0): void
{
    ensure_coin_schema();

    $stmt = db()->prepare(
        'INSERT INTO product_coin_wallets (user_id, product_id, balance)
         VALUES (:user_id, :product_id, :balance)
         ON DUPLICATE KEY UPDATE user_id = VALUES(user_id)'
    );
    $stmt->execute([
        'user_id' => $userId,
        'product_id' => $productId,
        'balance' => max(0, $initialBalance),
    ]);
}

function ensure_user_wallets(int $userId, int $initialBalance = 0): void
{
    ensure_coin_schema();

    $stmt = db()->query('SELECT id FROM products WHERE is_active = 1');
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $productId) {
        ensure_user_product_wallet($userId, (int) $productId, $initialBalance);
    }
}

function seed_missing_coin_wallets_for_existing_users(): void
{
    ensure_coin_schema();

    db()->exec(
        'INSERT INTO product_coin_wallets (user_id, product_id, balance)
         SELECT u.id, p.id, ' . default_initial_product_coins() . '
         FROM users u
         CROSS JOIN products p
         LEFT JOIN product_coin_wallets w ON w.user_id = u.id AND w.product_id = p.id
         WHERE p.is_active = 1 AND w.id IS NULL'
    );

    db()->exec(
        'INSERT INTO product_coin_ledger (user_id, product_id, actor_user_id, amount, balance_after, movement_type, description)
         SELECT w.user_id, w.product_id, NULL, w.balance, w.balance, \'initial_grant\', \'Saldo inicial de producto\'
         FROM product_coin_wallets w
         WHERE w.balance > 0
           AND NOT EXISTS (
             SELECT 1
             FROM product_coin_ledger l
             WHERE l.user_id = w.user_id
               AND l.product_id = w.product_id
           )'
    );
}

function product_coin_balance(int $userId, string $productCode): int
{
    ensure_coin_schema();

    $product = find_product_by_code($productCode);
    if ($product === null) {
        return 0;
    }

    ensure_user_product_wallet($userId, (int) $product['id']);

    $stmt = db()->prepare('SELECT balance FROM product_coin_wallets WHERE user_id = :user_id AND product_id = :product_id LIMIT 1');
    $stmt->execute(['user_id' => $userId, 'product_id' => $product['id']]);

    return (int) ($stmt->fetchColumn() ?: 0);
}

function list_user_coin_balances(int $userId): array
{
    seed_missing_coin_wallets_for_existing_users();

    $sql = <<<'SQL'
        SELECT
            p.id AS product_id,
            p.code AS product_code,
            p.name AS product_name,
            COALESCE(w.balance, 0) AS balance
        FROM products p
        LEFT JOIN product_coin_wallets w ON w.product_id = p.id AND w.user_id = :user_id
        WHERE p.is_active = 1
        ORDER BY p.sort_order ASC, p.name ASC
    SQL;

    $stmt = db()->prepare($sql);
    $stmt->execute(['user_id' => $userId]);
    $rows = $stmt->fetchAll();

    return array_map(static function (array $row): array {
        $row['product_name'] = present_product_name((string) $row['product_code'], (string) $row['product_name']);
        $row['balance'] = (int) $row['balance'];
        return $row;
    }, $rows);
}

function create_coin_ledger_entry(PDO $pdo, int $userId, int $productId, ?int $actorUserId, int $amount, int $balanceAfter, string $movementType, ?string $sourceType, ?int $sourceId, string $description, array $metadata = []): void
{
    $metadataJson = $metadata === [] ? null : json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $stmt = $pdo->prepare(
        'INSERT INTO product_coin_ledger (user_id, product_id, actor_user_id, amount, balance_after, movement_type, source_type, source_id, description, metadata_json)
         VALUES (:user_id, :product_id, :actor_user_id, :amount, :balance_after, :movement_type, :source_type, :source_id, :description, :metadata_json)'
    );
    $stmt->execute([
        'user_id' => $userId,
        'product_id' => $productId,
        'actor_user_id' => $actorUserId,
        'amount' => $amount,
        'balance_after' => $balanceAfter,
        'movement_type' => $movementType,
        'source_type' => $sourceType,
        'source_id' => $sourceId,
        'description' => $description,
        'metadata_json' => $metadataJson,
    ]);
}

function add_product_coins(int $userId, string $productCode, int $amount, ?int $actorUserId, string $movementType = 'manual_adjustment', string $description = 'Recarga manual de coins', ?string $sourceType = null, ?int $sourceId = null, array $metadata = []): array
{
    ensure_coin_schema();

    if ($amount <= 0) {
        return ['ok' => false, 'message' => 'La cantidad de coins debe ser mayor que cero.'];
    }

    $product = find_product_by_code($productCode);
    if ($product === null) {
        return ['ok' => false, 'message' => 'Producto no encontrado.'];
    }

    $productId = (int) $product['id'];
    ensure_user_product_wallet($userId, $productId);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $wallet = $pdo->prepare('SELECT balance FROM product_coin_wallets WHERE user_id = :user_id AND product_id = :product_id FOR UPDATE');
        $wallet->execute(['user_id' => $userId, 'product_id' => $productId]);
        $balance = (int) ($wallet->fetchColumn() ?: 0);
        $newBalance = $balance + $amount;

        $update = $pdo->prepare('UPDATE product_coin_wallets SET balance = :balance, updated_at = NOW() WHERE user_id = :user_id AND product_id = :product_id');
        $update->execute(['balance' => $newBalance, 'user_id' => $userId, 'product_id' => $productId]);

        create_coin_ledger_entry($pdo, $userId, $productId, $actorUserId, $amount, $newBalance, $movementType, $sourceType, $sourceId, $description, $metadata);

        $pdo->commit();
        return ['ok' => true, 'balance' => $newBalance];
    } catch (Throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'message' => 'No fue posible actualizar las coins.'];
    }
}

function consume_product_coin(int $userId, string $productCode, ?string $sourceType, ?int $sourceId, string $description, array $metadata = []): array
{
    ensure_coin_schema();

    $product = find_product_by_code($productCode);
    if ($product === null) {
        return ['ok' => false, 'message' => 'Producto no encontrado.'];
    }

    $productId = (int) $product['id'];
    ensure_user_product_wallet($userId, $productId);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $wallet = $pdo->prepare('SELECT balance FROM product_coin_wallets WHERE user_id = :user_id AND product_id = :product_id FOR UPDATE');
        $wallet->execute(['user_id' => $userId, 'product_id' => $productId]);
        $balance = (int) ($wallet->fetchColumn() ?: 0);
        if ($balance < 1) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'No tienes coins disponibles para este producto.'];
        }

        $newBalance = $balance - 1;
        $update = $pdo->prepare('UPDATE product_coin_wallets SET balance = :balance, updated_at = NOW() WHERE user_id = :user_id AND product_id = :product_id');
        $update->execute(['balance' => $newBalance, 'user_id' => $userId, 'product_id' => $productId]);

        create_coin_ledger_entry($pdo, $userId, $productId, null, -1, $newBalance, 'usage', $sourceType, $sourceId, $description, $metadata);

        $pdo->commit();
        return ['ok' => true, 'balance' => $newBalance];
    } catch (Throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'message' => 'No fue posible consumir la coin del producto.'];
    }
}

function refund_product_coin(int $userId, string $productCode, ?string $sourceType, ?int $sourceId, string $description, array $metadata = []): array
{
    return add_product_coins($userId, $productCode, 1, null, 'refund', $description, $sourceType, $sourceId, $metadata);
}

function grant_initial_product_coins(int $userId): void
{
    ensure_coin_schema();

    $stmt = db()->query('SELECT code FROM products WHERE is_active = 1');
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $productCode) {
        add_product_coins(
            $userId,
            (string) $productCode,
            default_initial_product_coins(),
            null,
            'initial_grant',
            'Saldo inicial de producto'
        );
    }
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
    ensure_organization_schema();

    $sql = <<<'SQL'
        SELECT
            upr.product_id,
            upr.organization_id,
            upr.role_id,
            o.name AS organization_name,
            o.slug AS organization_slug,
            p.code AS product_code,
            p.name AS product_name,
            r.code AS role_code,
            r.name AS role_name
        FROM user_product_roles upr
        INNER JOIN organizations o ON o.id = upr.organization_id
        INNER JOIN products p ON p.id = upr.product_id
        INNER JOIN roles r ON r.id = upr.role_id
        WHERE upr.user_id = :user_id
        ORDER BY o.name ASC, p.sort_order ASC, p.name ASC
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
    $user['organization_id'] = (int) ($user['organization_id'] ?? default_organization()['id']);
    $user['coin_balances'] = list_user_coin_balances((int) $user['id']);
    return $user;
}

function membership_for_product(array $user, string $productCode): ?array
{
    $selectedOrganizationId = (int) ($user['current_organization_id'] ?? 0);
    foreach (($user['memberships'] ?? []) as $membership) {
        if (($membership['product_code'] ?? '') !== $productCode) {
            continue;
        }

        if ($selectedOrganizationId === 0 || (int) ($membership['organization_id'] ?? 0) === $selectedOrganizationId) {
            return $membership;
        }
    }

    return null;
}

function find_user_by_email(string $email): ?array
{
    ensure_organization_schema();

    $stmt = db()->prepare(
        'SELECT u.id, u.first_name, u.last_name, u.email, u.organization_id, o.name AS organization_name, o.slug AS organization_slug, u.password_hash, u.is_active, u.is_system_admin, u.last_login_at, u.created_at
         FROM users u
         INNER JOIN organizations o ON o.id = u.organization_id
         WHERE u.email = :email
         LIMIT 1'
    );
    $stmt->execute(['email' => normalize_email($email)]);
    $user = $stmt->fetch();

    return $user ? hydrate_user($user) : null;
}

function find_user_by_id(int $userId): ?array
{
    ensure_organization_schema();

    $stmt = db()->prepare(
        'SELECT u.id, u.first_name, u.last_name, u.email, u.organization_id, o.name AS organization_name, o.slug AS organization_slug, u.password_hash, u.is_active, u.is_system_admin, u.last_login_at, u.created_at
         FROM users u
         INNER JOIN organizations o ON o.id = u.organization_id
         WHERE u.id = :id
         LIMIT 1'
    );
    $stmt->execute(['id' => $userId]);
    $user = $stmt->fetch();

    return $user ? hydrate_user($user) : null;
}

function list_all_users(): array
{
    ensure_organization_schema();

    $stmt = db()->query(
        'SELECT u.id, u.first_name, u.last_name, u.email, u.organization_id, o.name AS organization_name, o.slug AS organization_slug, u.password_hash, u.is_active, u.is_system_admin, u.last_login_at, u.created_at
         FROM users u
         INNER JOIN organizations o ON o.id = u.organization_id
         ORDER BY u.created_at DESC, u.id DESC'
    );
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

function create_user_record(string $firstName, string $lastName, string $email, string $password, string $productCode, array $roleCodes, ?int $organizationId = null): array
{
    ensure_organization_schema();

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

    if ($organizationId === null || $organizationId <= 0) {
        $organizationId = (int) default_organization()['id'];
    }

    $roles = fetch_roles_for_product_codes($productCode, $roleCodes);
    if (count($roles) !== count(array_unique($roleCodes))) {
        return ['ok' => false, 'message' => 'Uno o más roles no son válidos para este producto.'];
    }

    $passwordHash = password_hash($password, PASSWORD_BCRYPT);
    $pdo = db();
    $pdo->beginTransaction();

    try {
        $insertUser = $pdo->prepare('INSERT INTO users (first_name, last_name, email, organization_id, password_hash, is_active, is_system_admin) VALUES (:first_name, :last_name, :email, :organization_id, :password_hash, 1, 0)');
        $insertUser->execute([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'organization_id' => $organizationId,
            'password_hash' => $passwordHash,
        ]);

        $userId = (int) $pdo->lastInsertId();
        $insertMembership = $pdo->prepare(
            'INSERT INTO user_product_roles (user_id, organization_id, product_id, role_id) VALUES (:user_id, :organization_id, :product_id, :role_id)'
        );

        foreach ($roles as $role) {
            $insertMembership->execute([
                'user_id' => $userId,
                'organization_id' => $organizationId,
                'product_id' => $product['id'],
                'role_id' => $role['id'],
            ]);
        }

        $pdo->commit();
        grant_initial_product_coins($userId);
        return ['ok' => true, 'user_id' => $userId];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'message' => 'No fue posible crear la cuenta.'];
    }
}

function register_user(string $firstName, string $lastName, string $email, string $password, string $productCode = 'audioprint', array $roleCodes = ['user'], ?int $organizationId = null): array
{
    return create_user_record($firstName, $lastName, $email, $password, $productCode, $roleCodes, $organizationId);
}

function admin_create_user(string $firstName, string $lastName, string $email, string $password, string $productCode, array $roleCodes, ?int $organizationId = null): array
{
    return create_user_record($firstName, $lastName, $email, $password, $productCode, $roleCodes, $organizationId);
}

function admin_create_superadmin(string $firstName, string $lastName, string $email, string $password, ?int $organizationId = null): array
{
    ensure_organization_schema();

    $email = normalize_email($email);
    if (find_user_by_email($email) !== null) {
        return ['ok' => false, 'message' => 'Ya existe una cuenta con ese correo.'];
    }

    if ($organizationId === null || $organizationId <= 0 || get_organization_by_id($organizationId) === null) {
        $organizationId = (int) default_organization()['id'];
    }

    $passwordHash = password_hash($password, PASSWORD_BCRYPT);
    $stmt = db()->prepare(
        'INSERT INTO users (first_name, last_name, email, organization_id, password_hash, is_active, is_system_admin) VALUES (:first_name, :last_name, :email, :organization_id, :password_hash, 1, 1)'
    );
    $stmt->execute([
        'first_name' => $firstName,
        'last_name' => $lastName,
        'email' => $email,
        'organization_id' => $organizationId,
        'password_hash' => $passwordHash,
    ]);

    $userId = (int) db()->lastInsertId();
    grant_initial_product_coins($userId);

    return ['ok' => true, 'user_id' => $userId];
}

function user_organization_id(int $userId): int
{
    ensure_organization_schema();

    $stmt = db()->prepare('SELECT organization_id FROM users WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $userId]);
    $organizationId = (int) ($stmt->fetchColumn() ?: 0);

    return $organizationId > 0 ? $organizationId : (int) default_organization()['id'];
}

function update_user_organization(int $userId, int $organizationId): array
{
    ensure_organization_schema();

    if (find_user_by_id($userId) === null) {
        return ['ok' => false, 'message' => 'Usuario no encontrado.'];
    }

    if (get_organization_by_id($organizationId) === null) {
        return ['ok' => false, 'message' => 'Organización no encontrada.'];
    }

    $pdo = db();
    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare('UPDATE users SET organization_id = :organization_id WHERE id = :id');
        $stmt->execute(['organization_id' => $organizationId, 'id' => $userId]);

        $stmt = $pdo->prepare('UPDATE user_product_roles SET organization_id = :organization_id WHERE user_id = :user_id');
        $stmt->execute(['organization_id' => $organizationId, 'user_id' => $userId]);

        foreach (['audio_jobs', 'audiometer_tests'] as $table) {
            try {
                $stmt = $pdo->prepare("UPDATE {$table} SET organization_id = :organization_id WHERE user_id = :user_id");
                $stmt->execute(['organization_id' => $organizationId, 'user_id' => $userId]);
            } catch (Throwable) {
            }
        }

        $pdo->commit();
        return ['ok' => true];
    } catch (Throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'message' => 'No fue posible cambiar la organización del usuario.'];
    }
}

function update_user_product_role(int $userId, string $productCode, string $roleCode, ?int $organizationId = null): array
{
    ensure_organization_schema();

    $product = find_product_by_code($productCode);
    if ($product === null) {
        return ['ok' => false, 'message' => 'Producto no encontrado.'];
    }

    $roles = fetch_roles_for_product_codes($productCode, [$roleCode]);
    if ($roles === []) {
        return ['ok' => false, 'message' => 'Rol no valido para este producto.'];
    }

    $organizationId = user_organization_id($userId);

    $check = db()->prepare('SELECT id FROM user_product_roles WHERE user_id = :user_id AND organization_id = :organization_id AND product_id = :product_id LIMIT 1');
    $check->execute([
        'user_id' => $userId,
        'organization_id' => $organizationId,
        'product_id' => $product['id'],
    ]);
    $existing = $check->fetch();

    if ($existing !== false) {
        $stmt = db()->prepare(
            'UPDATE user_product_roles SET role_id = :role_id, updated_at = NOW() WHERE user_id = :user_id AND organization_id = :organization_id AND product_id = :product_id'
        );
        $stmt->execute([
            'role_id' => $roles[0]['id'],
            'user_id' => $userId,
            'organization_id' => $organizationId,
            'product_id' => $product['id'],
        ]);
    } else {
        $stmt = db()->prepare(
            'INSERT INTO user_product_roles (user_id, organization_id, product_id, role_id) VALUES (:user_id, :organization_id, :product_id, :role_id)'
        );
        $stmt->execute([
            'role_id' => $roles[0]['id'],
            'user_id' => $userId,
            'organization_id' => $organizationId,
            'product_id' => $product['id'],
        ]);
    }

    return ['ok' => true];
}

function remove_user_product_access(int $userId, string $productCode, ?int $organizationId = null): array
{
    ensure_organization_schema();

    $product = find_product_by_code($productCode);
    if ($product === null) {
        return ['ok' => false, 'message' => 'Producto no encontrado.'];
    }

    $organizationId = user_organization_id($userId);

    $stmt = db()->prepare('DELETE FROM user_product_roles WHERE user_id = :user_id AND organization_id = :organization_id AND product_id = :product_id');
    $stmt->execute([
        'user_id' => $userId,
        'organization_id' => $organizationId,
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
        return ['ok' => false, 'message' => 'La contraseña debe tener al menos 8 caracteres.'];
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

function admin_count_system_admins(): int
{
    ensure_organization_schema();

    return (int) db()->query('SELECT COUNT(*) FROM users WHERE is_system_admin = 1 AND is_active = 1')->fetchColumn();
}

function path_is_inside_project(string $path): bool
{
    $path = trim($path);
    if ($path === '') {
        return false;
    }

    $projectRoot = realpath(dirname(__DIR__));
    $realPath = realpath($path);
    if ($projectRoot === false || $realPath === false) {
        return false;
    }

    return $realPath === $projectRoot || str_starts_with($realPath, $projectRoot . DIRECTORY_SEPARATOR);
}

function admin_collect_user_file_paths(int $userId): array
{
    ensure_organization_schema();
    $paths = [];
    $pdo = db();

    $collect = static function (array $rows, array $columns) use (&$paths): void {
        foreach ($rows as $row) {
            foreach ($columns as $column) {
                $path = $row[$column] ?? null;
                if (is_string($path) && trim($path) !== '') {
                    $paths[] = $path;
                }
            }
        }
    };

    try {
        $stmt = $pdo->prepare('SELECT audio_path, scalogram_path FROM audio_jobs WHERE user_id = :user_id');
        $stmt->execute(['user_id' => $userId]);
        $audioJobs = $stmt->fetchAll();
        $collect($audioJobs, ['audio_path', 'scalogram_path']);
        foreach ($audioJobs as $job) {
            $scalogramPath = is_string($job['scalogram_path'] ?? null) ? (string) $job['scalogram_path'] : '';
            if ($scalogramPath !== '') {
                $analysisPath = preg_replace('/\.png$/i', '.json', $scalogramPath);
                if (is_string($analysisPath) && $analysisPath !== $scalogramPath) {
                    $paths[] = $analysisPath;
                }
            }
        }
    } catch (Throwable) {
    }

    try {
        $stmt = $pdo->prepare('SELECT storage_path FROM analysis_artifacts WHERE analysis_job_id IN (SELECT id FROM analysis_jobs WHERE user_id = :user_id)');
        $stmt->execute(['user_id' => $userId]);
        $collect($stmt->fetchAll(), ['storage_path']);
    } catch (Throwable) {
    }

    try {
        $stmt = $pdo->prepare('SELECT sample_audio_path FROM smart_tales_voice_profiles WHERE user_id = :user_id');
        $stmt->execute(['user_id' => $userId]);
        $collect($stmt->fetchAll(), ['sample_audio_path']);
    } catch (Throwable) {
    }

    try {
        $stmt = $pdo->prepare('SELECT audio_path FROM smart_tales_story_requests WHERE user_id = :user_id');
        $stmt->execute(['user_id' => $userId]);
        $collect($stmt->fetchAll(), ['audio_path']);
    } catch (Throwable) {
    }

    return array_values(array_unique(array_filter($paths, 'is_string')));
}

function admin_delete_user_and_data(int $userId): array
{
    ensure_organization_schema();

    $user = find_user_by_id($userId);
    if ($user === null) {
        return ['ok' => false, 'message' => 'Usuario no encontrado.'];
    }

    if (($user['is_system_admin'] ?? false) === true && admin_count_system_admins() <= 1) {
        return ['ok' => false, 'message' => 'No puedes eliminar el último superadmin activo del sistema.'];
    }

    $paths = admin_collect_user_file_paths($userId);
    $stmt = db()->prepare('DELETE FROM users WHERE id = :id');
    $stmt->execute(['id' => $userId]);

    $deletedFiles = 0;
    foreach ($paths as $path) {
        if (path_is_inside_project($path) && is_file($path) && @unlink($path)) {
            $deletedFiles++;
        }
    }

    return ['ok' => true, 'deleted_files' => $deletedFiles];
}

function login_attempt(string $email, string $password, ?string $productCode = null): array
{
    $user = find_user_by_email($email);
    if ($user === null || !password_verify($password, (string) $user['password_hash'])) {
        return ['ok' => false, 'message' => 'Credenciales inválidas.'];
    }

    if ((int) $user['is_active'] !== 1) {
        return ['ok' => false, 'message' => 'La cuenta está inactiva.'];
    }

    if (count($user['memberships']) === 0 && (($user['is_system_admin'] ?? false) !== true)) {
        return ['ok' => false, 'message' => 'Tu cuenta no tiene productos asociados.'];
    }

    $resolvedProductCode = $productCode;
    if ($resolvedProductCode === null || $resolvedProductCode === '') {
        $resolvedProductCode = (string) ($user['memberships'][0]['product_code'] ?? '');
    }

    $membership = $resolvedProductCode !== '' ? membership_for_product($user, $resolvedProductCode) : null;
    if ($membership === null && (($user['is_system_admin'] ?? false) !== true)) {
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
        'organization_id' => (int) $user['organization_id'],
        'organization_name' => $user['organization_name'] ?? default_organization()['name'],
        'current_organization_id' => (int) $user['organization_id'],
        'current_organization_name' => $user['organization_name'] ?? default_organization()['name'],
        'current_product_code' => $membership['product_code'] ?? null,
        'current_product_name' => $membership['product_name'] ?? null,
        'primary_role' => $membership['role_code'] ?? null,
        'primary_role_name' => $membership['role_name'] ?? null,
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
        'organization_id' => (int) $freshUser['organization_id'],
        'organization_name' => $freshUser['organization_name'] ?? default_organization()['name'],
        'current_organization_id' => (int) $freshUser['organization_id'],
        'current_organization_name' => $freshUser['organization_name'] ?? default_organization()['name'],
        'current_product_code' => $_SESSION['user']['current_product_code'] ?? null,
        'current_product_name' => $_SESSION['user']['current_product_name'] ?? null,
        'primary_role' => $_SESSION['user']['primary_role'] ?? null,
        'primary_role_name' => $_SESSION['user']['primary_role_name'] ?? null,
    ];

    if (($_SESSION['user']['current_product_code'] ?? null) === null && count($freshUser['memberships']) >= 1) {
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

function set_current_product(string $productCode, ?int $organizationId = null): void
{
    if (!is_logged_in()) {
        return;
    }

    $user = current_user();
    if ($user === null) {
        return;
    }

    $membership = null;
    foreach (($user['memberships'] ?? []) as $candidate) {
        if (($candidate['product_code'] ?? '') !== $productCode) {
            continue;
        }

        if ((int) ($candidate['organization_id'] ?? 0) === (int) ($user['organization_id'] ?? $user['current_organization_id'] ?? 0)) {
            $membership = $candidate;
            break;
        }
    }

    if ($membership === null) {
        return;
    }

    $_SESSION['user']['current_organization_id'] = $membership['organization_id'];
    $_SESSION['user']['current_organization_name'] = $membership['organization_name'];
    $_SESSION['user']['current_product_code'] = $membership['product_code'];
    $_SESSION['user']['current_product_name'] = $membership['product_name'];
    $_SESSION['user']['primary_role'] = $membership['role_code'];
    $_SESSION['user']['primary_role_name'] = $membership['role_name'];
}

function user_has_product_access(string $productCode): bool
{
    return is_system_admin() || current_membership($productCode) !== null;
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
    if (is_system_admin()) {
        return true;
    }

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

function current_organization_id(): int
{
    $user = current_user();
    return (int) ($user['current_organization_id'] ?? 0);
}

function dashboard_url_for_role(string $role, ?string $productCode = null): string
{
    $user = current_user();
    $resolvedProduct = $productCode ?? ((string) ($user['current_product_code'] ?? ''));
    $path = product_dashboard_path($resolvedProduct) ?? '/dashboard.php';
    $organizationId = (int) ($user['current_organization_id'] ?? 0);

    if ($organizationId > 0 && $path !== '/dashboard.php') {
        return $path . '?org_id=' . $organizationId;
    }

    return $path;
}
