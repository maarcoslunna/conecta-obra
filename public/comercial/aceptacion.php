<?php
declare(strict_types=1);

if (!defined('CO_COMMERCIAL_ACCEPTANCE')) {
    http_response_code(404);
    exit;
}

$configLegal = require dirname(__DIR__) . '/config.php';
$cLegal = $configLegal['db'] ?? $configLegal;
$dbLegal = new PDO(
    'mysql:host='.$cLegal['host'].';port='.($cLegal['port'] ?? 3306).
    ';dbname='.$cLegal['dbname'].';charset=utf8mb4',
    $cLegal['user'], $cLegal['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$dbLegal->exec("CREATE TABLE IF NOT EXISTS sales_legal_acceptances (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    account_id BIGINT UNSIGNED NOT NULL,
    version VARCHAR(64) NOT NULL,
    content_hash CHAR(64) NOT NULL,
    documents MEDIUMTEXT NOT NULL,
    accepted_at DATETIME NOT NULL,
    UNIQUE KEY uq_sales_acceptance (account_id,content_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$versionLegal = 'COMERCIAL-2026-11-19-v2';
$documentosLegales = [];
foreach ([
    'comercial/condiciones-acceso-v2.html',
    'privacidad.html',
    'condiciones.html',
    'aviso-legal.html'
] as $rutaLegal) {
    $textoLegal = file_get_contents(dirname(__DIR__).'/'.$rutaLegal);
    if ($textoLegal === false) {
        http_response_code(503);
        exit('No se pudo cargar la documentación del área comercial.');
    }
    $documentosLegales[$rutaLegal] = $textoLegal;
}
$copiaLegal = json_encode(
    $documentosLegales,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
);
$hashLegal = hash('sha256', $copiaLegal);

$cuentaLegal = $dbLegal->prepare(
    "SELECT c.id FROM sales_agent_accounts c
     JOIN sales_agents a ON a.id=c.agent_id
     WHERE c.id=? AND c.account_status='active' AND a.status='active'
     AND c.password_hash IS NOT NULL"
);
$cuentaLegal->execute([$accountId]);
if (!$cuentaLegal->fetchColumn()) {
    http_response_code(403);
    exit('La cuenta comercial no está habilitada.');
}

$consultaLegal = $dbLegal->prepare(
    'SELECT id FROM sales_legal_acceptances WHERE account_id=? AND content_hash=?'
);
$consultaLegal->execute([$accountId, $hashLegal]);
if ($consultaLegal->fetchColumn()) {
    return;
}

$_SESSION['sales_legal_csrf'] ??= bin2hex(random_bytes(32));
$errorLegal = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $tokenLegal = $_POST['csrf'] ?? null;
    $hashEnviado = $_POST['document_hash'] ?? null;
    if (!is_string($tokenLegal) ||
        !hash_equals($_SESSION['sales_legal_csrf'], $tokenLegal)) {
        http_response_code(403);
        exit('La sesión ha caducado. Recarga la página.');
    }
    if (!is_string($hashEnviado) || !hash_equals($hashLegal, $hashEnviado)) {
        $errorLegal = 'Los documentos se han actualizado. Revisa esta versión.';
    } elseif (($_POST['privacy_read'] ?? '') !== '1' ||
              ($_POST['terms_accept'] ?? '') !== '1') {
        $errorLegal = 'Confirma la lectura y la aceptación para continuar.';
    } else {
        $guardarLegal = $dbLegal->prepare(
            'INSERT INTO sales_legal_acceptances
             (account_id,version,content_hash,documents,accepted_at)
             VALUES (?,?,?,?,UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE id=id'
        );
        $guardarLegal->execute([
            $accountId, $versionLegal, $hashLegal, $copiaLegal
        ]);
        unset($_SESSION['sales_legal_csrf']);
        header('Location: /comercial/', true, 303);
        exit;
    }
}

function legalEsc(string $valor): string {
    return htmlspecialchars($valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Condiciones de acceso comercial | ConectaObra</title>
<style>
*{box-sizing:border-box}
body{margin:0;padding:20px;background:#0d1b2a;color:#fff;font:16px/1.6 system-ui,sans-serif}
main{max-width:760px;margin:auto;background:#162b3d;padding:24px;border-radius:14px}
a{color:#ffad72}
label{display:flex;gap:12px;align-items:flex-start;margin:24px 0}
input[type=checkbox]{flex-shrink:0;width:22px;height:22px}
button{padding:14px 20px;background:#ff7a1a;color:#0d1b2a;border:0;border-radius:8px;font:inherit;font-weight:700}
details{margin:16px 0}
summary{cursor:pointer;padding:10px 0}
iframe{width:100%;height:360px;background:white;border:1px solid #71889c}
</style>
<link rel="stylesheet" href="/comercial/marca.css?v=2">
</head>
<body>
<main>
<div class="co-marca"><img src="/assets/logo-symbol.png" alt=""><span>CONECTA<b>OBRA</b></span></div>

<h1>Antes de acceder</h1>
<p>Revisa las condiciones específicas, la información de privacidad y el compromiso de confidencialidad. Guardaremos la versión de los documentos y la fecha y hora de tu aceptación.</p>

<?php foreach ($documentosLegales as $rutaLegal => $textoLegal): ?>
<details>
<summary><?= legalEsc(match ($rutaLegal) {
    'comercial/condiciones-acceso-v2.html' => 'Condiciones específicas y confidencialidad',
    'privacidad.html' => 'Política de privacidad',
    'condiciones.html' => 'Condiciones generales',
    default => 'Aviso legal'
}) ?></summary>
<iframe sandbox title="Documento legal" srcdoc="<?= legalEsc($textoLegal) ?>"></iframe>
</details>
<?php endforeach; ?>

<form method="post" action="/comercial/">
<input type="hidden" name="csrf" value="<?= legalEsc($_SESSION['sales_legal_csrf']) ?>">
<input type="hidden" name="document_hash" value="<?= legalEsc($hashLegal) ?>">
<label><input type="checkbox" name="privacy_read" value="1" required>
<span>Confirmo que he leído la información de privacidad aplicable al área comercial.</span></label>
<label><input type="checkbox" name="terms_accept" value="1" required>
<span>Acepto las condiciones específicas de acceso, las condiciones generales y las obligaciones de confidencialidad y uso de los datos, y confirmo que he leído el aviso legal.</span></label>
<p role="alert"><?= legalEsc($errorLegal) ?></p>
<button type="submit">Aceptar y acceder a mi área comercial</button>
</form>
</main>
</body>
</html>
<?php exit; ?>
