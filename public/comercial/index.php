<?php
declare(strict_types=1);

ini_set('display_errors', '0');

header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');

$isHttps = !empty($_SERVER['HTTPS']) &&
    $_SERVER['HTTPS'] !== 'off';

session_name('CONECTAOBRA_SALES');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/comercial',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Strict'
]);

session_start();

// CONECTAOBRA_SALES_SESSION_TIMEOUT_V1
$now = time();
$lastActivity = $_SESSION['sales_last_activity'] ?? null;

if (
    isset($_SESSION['sales_freelance_account_id']) &&
    (
        !is_int($lastActivity) ||
        $lastActivity > $now ||
        ($now - $lastActivity) > 1800
    )
) {
    unset(
        $_SESSION['sales_freelance_account_id'],
        $_SESSION['sales_freelance_agent_id'],
        $_SESSION['sales_last_activity']
    );
}

$accountId = $_SESSION['sales_freelance_account_id'] ?? null;

if (!is_int($accountId) || $accountId <= 0) {
    header('Location: /comercial/login.php', true, 302);
    exit;
}

define('CO_COMMERCIAL_ACCEPTANCE', true);
require __DIR__ . '/aceptacion.php';

try {
    $config = require dirname(__DIR__) . '/config.php';
    $c = $config['db'] ?? $config;

    $db = new PDO(
        'mysql:host=' . $c['host']
        . ';port=' . ($c['port'] ?? 3306)
        . ';dbname=' . $c['dbname']
        . ';charset=' . ($c['charset'] ?? 'utf8mb4'),
        $c['user'],
        $c['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );

    require_once dirname(__DIR__) .
        '/mega-bloque/comerciales/sales_private.php';

    $panel = new ConectaobraSalesPrivate($db);
    $datos = $panel->dashboard($accountId);
    $_SESSION['sales_last_activity'] = time();

} catch (Throwable $e) {
    error_log('ConectaObra commercial private panel failure');
    http_response_code(403);
    exit('Acceso comercial no disponible');
}

function esc($valor): string {
    return htmlspecialchars(
        (string)$valor,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

define('CO_SALES_PANEL_V2', true);
require __DIR__ . '/panel-v2.php';
exit;
