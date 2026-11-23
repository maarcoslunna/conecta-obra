<?php
declare(strict_types=1);

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$isHttps = !empty($_SERVER['HTTPS']) &&
    $_SERVER['HTTPS'] !== 'off';

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax'
]);

session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'GET' ||
    empty($_SESSION['admin'])) {
    http_response_code(403);
    echo json_encode(['ok'=>false]);
    exit;
}

if (!isset($_SESSION['commercial_csrf'])) {
    $_SESSION['commercial_csrf'] = bin2hex(random_bytes(32));
}

echo json_encode([
    'ok'=>true,
    'csrf'=>$_SESSION['commercial_csrf']
]);
