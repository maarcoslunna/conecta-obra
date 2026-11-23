<?php
declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

$isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

if (empty($_SESSION['admin'])) {
    define('CO_ADMIN_LOGIN_VIEW', true);
    require __DIR__ . '/acceso-vista.php';
    exit;
}

readfile(__DIR__ . '/admin-empresas-vista.html');
