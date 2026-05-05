<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function audiometer_product(): ?array
{
    return find_product_by_code('audiometer');
}

function ensure_audiometer_tables(): void
{
    db()->exec(
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS audiometer_tests (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          user_id BIGINT UNSIGNED NOT NULL,
          organization_id BIGINT UNSIGNED NOT NULL,
          product_id BIGINT UNSIGNED NOT NULL,
          test_title VARCHAR(190) NOT NULL DEFAULT '',
          device_label VARCHAR(190) NOT NULL DEFAULT '',
          headphone_label VARCHAR(190) NOT NULL DEFAULT '',
          volume_label VARCHAR(120) NOT NULL DEFAULT '',
          environment_label VARCHAR(190) NOT NULL DEFAULT '',
          calibration_note TEXT NULL,
          result_payload_json JSON NOT NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          KEY idx_audiometer_tests_user_time (user_id, created_at),
          KEY idx_audiometer_tests_org_time (organization_id, created_at),
          KEY idx_audiometer_tests_product_time (product_id, created_at),
          CONSTRAINT fk_audiometer_tests_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
          CONSTRAINT fk_audiometer_tests_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
          CONSTRAINT fk_audiometer_tests_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL
    );

    $stmt = db()->query("SHOW COLUMNS FROM audiometer_tests LIKE 'organization_id'");
    if ($stmt !== false && $stmt->fetch() === false) {
        $organizationId = (int) default_organization()['id'];
        db()->exec("ALTER TABLE audiometer_tests ADD COLUMN organization_id BIGINT UNSIGNED NULL AFTER user_id");
        db()->exec("UPDATE audiometer_tests SET organization_id = {$organizationId} WHERE organization_id IS NULL");
        db()->exec("ALTER TABLE audiometer_tests MODIFY organization_id BIGINT UNSIGNED NOT NULL");
        db()->exec("ALTER TABLE audiometer_tests ADD KEY idx_audiometer_tests_org_time (organization_id, created_at)");
    }
}

function audiometer_trim(string $value, int $maxLength): string
{
    $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $maxLength, 'UTF-8');
    }

    return substr($value, 0, $maxLength);
}

function audiometer_decode_payload(string $rawPayload): ?array
{
    try {
        $payload = json_decode($rawPayload, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return null;
    }

    return is_array($payload) ? $payload : null;
}

function audiometer_validate_payload(array $payload): bool
{
    $ears = $payload['ears'] ?? null;
    $frequencies = $payload['frequencies'] ?? null;
    $thresholds = $payload['thresholds'] ?? null;

    if (!is_array($ears) || !is_array($frequencies) || !is_array($thresholds)) {
        return false;
    }

    foreach ($ears as $ear) {
        if (!is_string($ear) || !in_array($ear, ['left', 'right'], true)) {
            return false;
        }
    }

    foreach ($frequencies as $frequency) {
        if (!is_int($frequency) && !is_float($frequency)) {
            return false;
        }
        if ($frequency < 125 || $frequency > 12000) {
            return false;
        }
    }

    foreach ($thresholds as $ear => $rows) {
        if (!in_array($ear, ['left', 'right'], true) || !is_array($rows)) {
            return false;
        }
    }

    $status = $payload['status'] ?? 'completed';
    if (!is_string($status) || !in_array($status, ['completed', 'stopped'], true)) {
        return false;
    }

    return true;
}

function create_audiometer_test(int $userId, array $form): array
{
    $product = audiometer_product();
    if ($product === null) {
        return ['ok' => false, 'message' => 'El producto Audiometer no está registrado. Ejecuta la migración correspondiente.'];
    }

    $rawPayload = is_string($form['result_payload_json'] ?? null) ? (string) $form['result_payload_json'] : '';
    $payload = audiometer_decode_payload($rawPayload);
    if ($payload === null || !audiometer_validate_payload($payload)) {
        return ['ok' => false, 'message' => 'El resultado del test no tiene un formato válido.'];
    }

    $answeredCount = 0;
    foreach (($payload['thresholds'] ?? []) as $rows) {
        if (is_array($rows)) {
            $answeredCount += count($rows);
        }
    }
    if ($answeredCount === 0) {
        return ['ok' => false, 'message' => 'Debes registrar al menos una respuesta antes de guardar.'];
    }

    ensure_audiometer_tables();

    $organizationId = current_organization_id();
    if ($organizationId <= 0) {
        return ['ok' => false, 'message' => 'No hay una organización activa para guardar el screening.'];
    }

    $stmt = db()->prepare(
        'INSERT INTO audiometer_tests (user_id, organization_id, product_id, test_title, device_label, headphone_label, volume_label, environment_label, calibration_note, result_payload_json) VALUES (:user_id, :organization_id, :product_id, :test_title, :device_label, :headphone_label, :volume_label, :environment_label, :calibration_note, :result_payload_json)'
    );
    $stmt->execute([
        'user_id' => $userId,
        'organization_id' => $organizationId,
        'product_id' => $product['id'],
        'test_title' => audiometer_trim((string) ($form['test_title'] ?? ''), 190),
        'device_label' => audiometer_trim((string) ($form['device_label'] ?? ''), 190),
        'headphone_label' => audiometer_trim((string) ($form['headphone_label'] ?? ''), 190),
        'volume_label' => audiometer_trim((string) ($form['volume_label'] ?? ''), 120),
        'environment_label' => audiometer_trim((string) ($form['environment_label'] ?? ''), 190),
        'calibration_note' => audiometer_trim((string) ($form['calibration_note'] ?? ''), 1000),
        'result_payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);

    $testId = (int) db()->lastInsertId();
    $coinCharge = consume_product_coin($userId, 'audiometer', 'audiometer_tests', $testId, 'Screening guardado en Audiometer');
    if (($coinCharge['ok'] ?? false) !== true) {
        delete_audiometer_test_record($testId);
        return ['ok' => false, 'message' => (string) ($coinCharge['message'] ?? 'No tienes coins disponibles para Audiometer.')];
    }

    return ['ok' => true, 'test_id' => $testId];
}

function list_audiometer_tests_for_user(int $userId, ?int $organizationId = null): array
{
    ensure_audiometer_tables();
    $organizationId = $organizationId ?? current_organization_id();

    $stmt = db()->prepare(
        'SELECT id, user_id, organization_id, test_title, device_label, headphone_label, volume_label, environment_label, calibration_note, result_payload_json, created_at FROM audiometer_tests WHERE user_id = :user_id AND organization_id = :organization_id ORDER BY created_at DESC, id DESC'
    );
    $stmt->execute(['user_id' => $userId, 'organization_id' => $organizationId]);
    return $stmt->fetchAll();
}

function get_audiometer_test_by_id(int $testId): ?array
{
    ensure_audiometer_tables();

    $sql = <<<'SQL'
        SELECT
            t.id,
            t.user_id,
            t.organization_id,
            t.product_id,
            t.test_title,
            t.device_label,
            t.headphone_label,
            t.volume_label,
            t.environment_label,
            t.calibration_note,
            t.result_payload_json,
            t.created_at,
            u.first_name,
            u.last_name,
            u.email
        FROM audiometer_tests t
        INNER JOIN users u ON u.id = t.user_id
        WHERE t.id = :id
        LIMIT 1
    SQL;

    $stmt = db()->prepare($sql);
    $stmt->execute(['id' => $testId]);
    $row = $stmt->fetch();

    return $row ?: null;
}

function list_recent_audiometer_tests(int $limit = 50, ?int $organizationId = null): array
{
    ensure_audiometer_tables();
    $organizationId = $organizationId ?? current_organization_id();
    $where = is_system_admin() ? '1 = 1' : 't.organization_id = :organization_id';

    $sql = <<<'SQL'
        SELECT
            t.id,
            t.organization_id,
            t.test_title,
            t.device_label,
            t.headphone_label,
            t.volume_label,
            t.environment_label,
            t.result_payload_json,
            t.created_at,
            u.first_name,
            u.last_name,
            u.email
        FROM audiometer_tests t
        INNER JOIN users u ON u.id = t.user_id
        WHERE __WHERE__
        ORDER BY t.created_at DESC, t.id DESC
        LIMIT :limit
    SQL;
    $sql = str_replace('__WHERE__', $where, $sql);

    $stmt = db()->prepare($sql);
    if (!is_system_admin()) {
        $stmt->bindValue(':organization_id', $organizationId, PDO::PARAM_INT);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function delete_audiometer_test_record(int $testId): array
{
    ensure_audiometer_tables();

    $stmt = db()->prepare('DELETE FROM audiometer_tests WHERE id = :id');
    $stmt->execute(['id' => $testId]);

    return ['ok' => true];
}

function audiometer_payload_from_row(array $row): array
{
    $payload = audiometer_decode_payload((string) ($row['result_payload_json'] ?? ''));
    return is_array($payload) ? $payload : [];
}

function audiometer_threshold_summary(array $payload): string
{
    $thresholds = $payload['thresholds'] ?? [];
    if (!is_array($thresholds)) {
        return 'Sin umbrales';
    }

    $values = [];
    foreach ($thresholds as $rows) {
        if (!is_array($rows)) {
            continue;
        }
        foreach ($rows as $value) {
            if (is_numeric($value)) {
                $values[] = (float) $value;
            }
        }
    }

    if ($values === []) {
        return 'Sin umbrales';
    }

    $average = array_sum($values) / count($values);
    return 'Promedio relativo ' . number_format($average, 1, ',', '.') . ' dB';
}
