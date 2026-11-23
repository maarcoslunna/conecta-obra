<?php
declare(strict_types=1);

ini_set('display_errors', '0');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');

if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') {
    http_response_code(403);
    exit('HTTPS obligatorio');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    exit('Método no permitido');
}

session_name('CONECTAOBRA_SALES');
session_set_cookie_params([
    'lifetime'=>0, 'path'=>'/comercial', 'secure'=>true,
    'httponly'=>true, 'samesite'=>'Strict'
]);
session_start();

$cuenta = $_SESSION['sales_freelance_account_id'] ?? null;
$actividad = $_SESSION['sales_last_activity'] ?? null;
$ahora = time();

if (!is_int($cuenta) || $cuenta <= 0 || !is_int($actividad) ||
    $actividad > $ahora || $ahora - $actividad > 1800) {
    unset(
        $_SESSION['sales_freelance_account_id'],
        $_SESSION['sales_freelance_agent_id'],
        $_SESSION['sales_last_activity']
    );
    header('Location: /comercial/login.php', true, 302);
    exit;
}

try {
    $config = require dirname(__DIR__).'/config.php';
    $c = $config['db'] ?? $config;
    $db = new PDO(
        'mysql:host='.$c['host'].';port='.($c['port'] ?? 3306).
        ';dbname='.$c['dbname'].';charset=utf8mb4',
        $c['user'], $c['password'],
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]
    );
    $comprobar = $db->prepare(
        "SELECT c.id FROM sales_agent_accounts c
         JOIN sales_agents a ON a.id=c.agent_id
         WHERE c.id=? AND c.account_status='active'
         AND a.status='active' AND c.password_hash IS NOT NULL"
    );
    $comprobar->execute([$cuenta]);
    if (!$comprobar->fetchColumn()) {
        http_response_code(403);
        exit('La cuenta comercial no está habilitada.');
    }

    $consulta = $db->prepare(
        'SELECT version,content_hash,documents,accepted_at
         FROM sales_legal_acceptances WHERE account_id=? ORDER BY id DESC'
    );
    $consulta->execute([$cuenta]);
    $aceptaciones = $consulta->fetchAll(PDO::FETCH_ASSOC);
    $_SESSION['sales_last_activity'] = time();
} catch (Throwable $e) {
    error_log('ConectaObra: consulta de documentación comercial fallida');
    http_response_code(503);
    exit('No se pudo consultar la documentación. Vuelve a intentarlo.');
}

function documentoEsc(string $valor): string {
    return htmlspecialchars($valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
$titulos = [
    'comercial/condiciones-acceso-v1.html'=>'Condiciones específicas y confidencialidad',
    'comercial/condiciones-acceso-v2.html'=>'Condiciones específicas y confidencialidad',
    'privacidad.html'=>'Política de privacidad',
    'condiciones.html'=>'Condiciones generales',
    'aviso-legal.html'=>'Aviso legal'
];
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Documentación aceptada | ConectaObra</title>
<style>
*{box-sizing:border-box}
body{margin:0;background:#0d1b2a;color:white;font:16px/1.6 system-ui,sans-serif}
main{max-width:900px;margin:auto;padding:20px}
a{color:#ffad72}
article{margin:20px 0;padding:20px;background:#162b3d;border-radius:14px}
h1{font-size:28px}
h2{font-size:20px;overflow-wrap:anywhere}
summary{cursor:pointer;padding:12px 0}
iframe{width:100%;height:65vh;min-height:280px;background:white;border:1px solid #71889c}
</style>
<link rel="stylesheet" href="/comercial/marca.css?v=2">
</head>
<body>
<main>
<div class="co-marca"><img src="/assets/logo-symbol.png" alt=""><span>CONECTA<b>OBRA</b></span></div>
<a href="/comercial/">Volver a mi área comercial</a>
<h1>Documentación aceptada</h1>
<p>Aquí puedes consultar los textos tal como estaban cuando los aceptaste.</p>
<?php if (!$aceptaciones): ?>
<p>Todavía no hay una aceptación registrada para tu cuenta.</p>
<?php endif; ?>

<?php foreach ($aceptaciones as $aceptacion): ?>
<article>
<h2><?= documentoEsc($aceptacion['version']) ?></h2>
<p>Aceptado el <?= documentoEsc(
    (new DateTimeImmutable($aceptacion['accepted_at'], new DateTimeZone('UTC')))
    ->setTimezone(new DateTimeZone('Europe/Madrid'))
    ->format('d/m/Y H:i:s')
) ?>, hora de Madrid.</p>
<?php
$documentos = json_decode($aceptacion['documents'], true);
$integro = hash_equals(
    $aceptacion['content_hash'],
    hash('sha256', $aceptacion['documents'])
);
?>
<?php if (!is_array($documentos) || !$integro): ?>
<p>No se pudo verificar esta copia. Contacta con info@conectaobra10.es.</p>
<?php else: ?>
<?php foreach ($documentos as $ruta => $contenido): ?>
<?php if (!is_string($contenido)) continue; ?>
<details>
<summary><?= documentoEsc($titulos[$ruta] ?? 'Documento aceptado') ?></summary>
<iframe sandbox title="<?= documentoEsc($titulos[$ruta] ?? 'Documento aceptado') ?>"
srcdoc="<?= documentoEsc($contenido) ?>"></iframe>
</details>
<?php endforeach; ?>
<?php endif; ?>
</article>
<?php endforeach; ?>
</main>
</body>
</html>
