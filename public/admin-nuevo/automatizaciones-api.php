<?php
declare(strict_types=1);

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

session_start();

if (empty($_SESSION['admin'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Acceso denegado']);
    exit;
}

try {
    $config = require dirname(__DIR__) . '/config.php';
    $c = $config['db'] ?? $config;

    $dsn = 'mysql:host=' . $c['host']
         . ';port=' . ($c['port'] ?? 3306)
         . ';dbname=' . $c['dbname']
         . ';charset=' . ($c['charset'] ?? 'utf8mb4');

    $db = new PDO($dsn, $c['user'], $c['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    $tablas = [
        'alert_events',
        'alert_deliveries',
        'alert_preferences',
        'alert_monthly_usage'
    ];

    $datos = [];

    foreach ($tablas as $tabla) {
        $datos[$tabla] = (int)$db
            ->query("SELECT COUNT(*) FROM `$tabla`")
            ->fetchColumn();
    }

    $eventos = $db->query(
        "SELECT status, COUNT(*) AS total
         FROM alert_events
         GROUP BY status"
    )->fetchAll();

    $entregas = $db->query(
        "SELECT status, COUNT(*) AS total
         FROM alert_deliveries
         GROUP BY status"
    )->fetchAll();

    echo json_encode([
        'ok' => true,
        'totales' => $datos,
        'eventos' => $eventos,
        'entregas' => $entregas,
        'actualizado' => date('c')
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'No se pudieron consultar las alertas'
    ]);
}
