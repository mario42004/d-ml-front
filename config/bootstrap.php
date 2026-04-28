<?php

declare(strict_types=1);

require_once __DIR__ . '/env.php';

$env = load_env(dirname(__DIR__) . '/.env');

function env_value(string $key, ?string $default = null): ?string
{
    global $env;

    if (array_key_exists($key, $env)) {
        return $env[$key];
    }

    $value = getenv($key);
    if ($value !== false) {
        return $value;
    }

    return $default;
}

$sessionSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => $sessionSecure,
    'httponly' => true,
    'samesite' => 'Lax',
]);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('dml_portal_session');
    session_start();
}

date_default_timezone_set('Europe/Madrid');
