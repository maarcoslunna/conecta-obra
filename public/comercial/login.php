<?php
declare(strict_types=1);

// CONECTAOBRA_FREELANCE_LOGIN_V1
// Formulario GET y autenticación POST del área comercial.

ini_set('display_errors', '0');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');

if (($_SERVER['HTTPS'] ?? '') === '' ||
    ($_SERVER['HTTPS'] ?? '') === 'off') {
    http_response_code(403);
    exit('HTTPS obligatorio');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    define('CO_SALES_LOGIN_FORM', true);
    require __DIR__ . '/formulario-acceso.php';
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Metodo no permitido');
}

$enabled = true;

if (!$enabled) {
    http_response_code(503);
    exit('Acceso comercial en preparacion');
}

session_name('CONECTAOBRA_SALES');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/comercial',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Strict'
]);
session_start();

function rejectLogin(): never
{
    http_response_code(401);
    exit('Credenciales incorrectas o acceso temporalmente limitado');
}

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if ($origin !== '' &&
    $origin !== 'https://' . ($_SERVER['HTTP_HOST'] ?? '')) {
    http_response_code(403);
    exit('Origen no autorizado');
}

$csrf = $_POST['csrf'] ?? '';

if (!is_string($csrf) ||
    !isset($_SESSION['sales_login_csrf']) ||
    !hash_equals($_SESSION['sales_login_csrf'], $csrf)) {
    http_response_code(403);
    exit('Solicitud no autorizada');
}

$email = $_POST['email'] ?? '';
$password = $_POST['password'] ?? '';

if (!is_string($email) ||
    !is_string($password) ||
    strlen($email) > 190 ||
    strlen($password) > 1024) {
    rejectLogin();
}

$email = strtolower(trim($email));

if (!filter_var($email, FILTER_VALIDATE_EMAIL) ||
    $password === '') {
    rejectLogin();
}

try {
    $config = require dirname(__DIR__) . '/config.php';
    $c = $config['db'] ?? $config;

    $db = new PDO(
        'mysql:host=' . $c['host'] .
        ';port=' . ($c['port'] ?? 3306) .
        ';dbname=' . $c['dbname'] .
        ';charset=' . ($c['charset'] ?? 'utf8mb4'),
        $c['user'],
        $c['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );

    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $identityHash = hash('sha256', $email);
    $ipHash = hash('sha256', $ip);

    // CONECTAOBRA_LOGIN_ATOMIC_LIMIT_V1
    // Serializa los intentos por correo e IP con bloqueos MariaDB.
    // CONECTAOBRA_LOGIN_LOCK_NAMES_V2
    // GET_LOCK admite nombres limitados: usamos 48 caracteres.
    $lockNames = [
        'co_se_' . substr($identityHash, 0, 42),
        'co_si_' . substr($ipHash, 0, 42)
    ];
    sort($lockNames, SORT_STRING);

    $acquiredLocks = [];

    try {
        foreach ($lockNames as $lockName) {
            $lockStmt = $db->prepare('SELECT GET_LOCK(?, 5)');
            $lockStmt->execute([$lockName]);

            if ((int)$lockStmt->fetchColumn() !== 1) {
                http_response_code(429);
                exit('Demasiados intentos. Prueba mas tarde.');
            }

            $acquiredLocks[] = $lockName;
        }

        $stmt = $db->prepare(
            "SELECT
                SUM(CASE WHEN identity_hash = ? THEN 1 ELSE 0 END)
                    AS identity_attempts,
                SUM(CASE WHEN ip_hash = ? THEN 1 ELSE 0 END)
                    AS ip_attempts
             FROM sales_login_attempts
             WHERE attempted_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)"
        );

        $stmt->execute([$identityHash, $ipHash]);
        $attempts = $stmt->fetch(PDO::FETCH_ASSOC);

        if ((int)($attempts['identity_attempts'] ?? 0) >= 5 ||
            (int)($attempts['ip_attempts'] ?? 0) >= 20) {
            http_response_code(429);
            exit('Demasiados intentos. Prueba mas tarde.');
        }

        // Reservamos el intento antes de verificar la contraseña.
        // El registro se conserva incluso si la autenticacion acierta.
        $insert = $db->prepare(
            "INSERT INTO sales_login_attempts
                (identity_hash, ip_hash, attempted_at)
             VALUES (?, ?, NOW())"
        );
        $insert->execute([$identityHash, $ipHash]);

    } finally {
        foreach (array_reverse($acquiredLocks) as $lockName) {
            try {
                $unlock = $db->prepare('SELECT RELEASE_LOCK(?)');
                $unlock->execute([$lockName]);
            } catch (Throwable $unlockError) {
                error_log('ConectaObra: error liberando bloqueo comercial');
            }
        }
    }

    require_once dirname(__DIR__) .
        '/mega-bloque/comerciales/sales_freelance_auth.php';

    $auth = new ConectaobraSalesFreelanceAuth($db);
    $account = $auth->authenticate($email, $password);

    if (!$account) {
        rejectLogin();
    }

    $auth->establishSession($account);
    unset($_SESSION['sales_login_csrf']);

    header('Location: /comercial/', true, 303);
    exit;

} catch (Throwable $e) {
    error_log('ConectaObra freelance login error');
    http_response_code(503);
    exit('Acceso comercial temporalmente no disponible');
}
