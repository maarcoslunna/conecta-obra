<?php
declare(strict_types=1);

$isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

if (empty($_SESSION['admin'])) {
    http_response_code(403);
    echo json_encode(['ok'=>false,'error'=>'No autorizado']);
    exit;
}

function db(array $config): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    if (empty($config['dbname']) || str_starts_with($config['dbname'], 'PON_AQUI')) {
        out(['ok'=>false,'error'=>'La base de datos aún no está configurada en config.php.'], 500);
    }
    try {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $config['host'], (int)$config['port'], $config['dbname'], $config['charset']);
        $pdo = new PDO($dsn, $config['user'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        return $pdo;
    } catch (PDOException $e) {
        error_log('ConectaObra DB error: ' . $e->getMessage());
        out(['ok'=>false,'error'=>'No se pudo conectar con la base de datos. Revisa la configuración del servidor.'], 500);
    }
}

try {
    $config = require dirname(__DIR__) . '/config.php';

    $db = db($config);

    if (!isset($db) || !($db instanceof PDO)) {
        throw new RuntimeException('Conexion PDO no disponible');
    }

    $stmt = $db->query(
        'SELECT id, empresa_id, titulo, provincia, estado, created_at
         FROM job_ofertas
         ORDER BY id DESC
         LIMIT 500'
    );

    $offers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'ok' => true,
        'offers' => $offers
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log('ConectaObra admin ConectaJob: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'ok'=>false,
        'error'=>'No se pudieron consultar las ofertas'
    ]);
}
