<?php
declare(strict_types=1);

ini_set('display_errors', '0');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');

if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') {
    http_response_code(403);
    exit('HTTPS obligatorio');
}
if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'POST'], true)) {
    http_response_code(405);
    exit('Método no permitido');
}

session_name('CONECTAOBRA_SALES');
session_set_cookie_params([
    'lifetime'=>0, 'path'=>'/comercial', 'secure'=>true,
    'httponly'=>true, 'samesite'=>'Strict'
]);
session_start();
$_SESSION['sales_activation_csrf'] ??= bin2hex(random_bytes(32));

$mensaje = '';
$terminado = false;
$token = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $token = is_string($_POST['token'] ?? null) ? $_POST['token'] : '';
    $csrf = $_POST['csrf'] ?? null;
    $clave = $_POST['password'] ?? null;
    $repetida = $_POST['password_repeat'] ?? null;

    if (!is_string($csrf) ||
        !hash_equals($_SESSION['sales_activation_csrf'], $csrf)) {
        http_response_code(403);
        exit('La sesión ha caducado. Abre de nuevo tu invitación.');
    }
    if (!preg_match('/^[a-f0-9]{64}$/D', $token)) {
        $mensaje = 'La invitación no es válida.';
    } elseif (!is_string($clave) || !is_string($repetida) ||
              mb_strlen($clave) < 12 || strlen($clave) > 72 ||
              $clave !== $repetida) {
        $mensaje = 'Escribe la misma contraseña en ambos campos. Debe tener al menos 12 caracteres y un máximo de 72 bytes.';
    } else {
        $db = null;
        try {
            $config = require dirname(__DIR__).'/config.php';
            $c = $config['db'] ?? $config;
            $db = new PDO(
                'mysql:host='.$c['host'].';port='.($c['port'] ?? 3306).
                ';dbname='.$c['dbname'].';charset=utf8mb4',
                $c['user'], $c['password'],
                [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]
            );
            $hashToken = hash('sha256', $token);
            $buscar = $db->prepare(
                'SELECT account_id FROM sales_account_invitations WHERE token_hash=?'
            );
            $buscar->execute([$hashToken]);
            $cuenta = $buscar->fetchColumn();
            if ($cuenta === false) {
                $mensaje = 'La invitación ha caducado, ya se utilizó o fue sustituida.';
            } else {
                $hashClave = password_hash($clave, PASSWORD_DEFAULT);
                $db->beginTransaction();
                $bloquear = $db->prepare(
                    'SELECT account_status FROM sales_agent_accounts WHERE id=? FOR UPDATE'
                );
                $bloquear->execute([$cuenta]);
                $estado = $bloquear->fetchColumn();
                $validar = $db->prepare(
                    'SELECT account_id FROM sales_account_invitations
                     WHERE account_id=? AND token_hash=? AND expires_at>UTC_TIMESTAMP()
                     FOR UPDATE'
                );
                $validar->execute([$cuenta, $hashToken]);
                if ($estado !== 'pending' || !$validar->fetchColumn()) {
                    $db->rollBack();
                    $mensaje = 'La invitación ha caducado, ya se utilizó o fue sustituida.';
                } else {
                    $actualizar = $db->prepare(
                        "UPDATE sales_agent_accounts
                         SET password_hash=?,account_status='active',updated_at=NOW()
                         WHERE id=? AND account_status='pending'"
                    );
                    $actualizar->execute([$hashClave, $cuenta]);
                    $borrar = $db->prepare(
                        'DELETE FROM sales_account_invitations WHERE account_id=?'
                    );
                    $borrar->execute([$cuenta]);
                    $db->commit();
                    unset($_SESSION['sales_activation_csrf']);
                    $terminado = true;
                    $token = '';
                    $mensaje = 'Contraseña guardada. Puedes entrar cuando ConectaObra haya activado tu ficha comercial.';
                }
            }
        } catch (Throwable $e) {
            if ($db instanceof PDO && $db->inTransaction()) $db->rollBack();
            error_log('ConectaObra: fallo en activación comercial');
            $mensaje = 'No se pudo completar la activación. Vuelve a intentarlo.';
        }
    }
}
function escaparActivacion(string $valor): string {
    return htmlspecialchars($valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Activación comercial | ConectaObra</title>
<style>
*{box-sizing:border-box}
body{margin:0;padding:20px;background:#0d1b2a;color:white;font:16px/1.6 system-ui,sans-serif}
main{max-width:480px;margin:30px auto;padding:24px;background:#162b3d;border-radius:14px}
label{display:block;margin:18px 0}
input,button{width:100%;min-height:48px;padding:10px;font:inherit;border-radius:8px}
button{background:#ff7a1a;border:0;font-weight:700}
a{color:#ffad72}
</style>
<link rel="stylesheet" href="/comercial/marca.css?v=2">
</head>
<body>
<main>
<div class="co-marca"><img src="/assets/logo-symbol.png" alt=""><span>CONECTA<b>OBRA</b></span></div>

<h1>Establece tu contraseña</h1>
<p role="status"><?= escaparActivacion($mensaje) ?></p>
<?php if (!$terminado): ?>
<form method="post" action="/comercial/activar.php">
<input type="hidden" name="csrf" value="<?= escaparActivacion($_SESSION['sales_activation_csrf']) ?>">
<input id="invitacion" type="hidden" name="token" value="<?= escaparActivacion($token) ?>">
<label>Nueva contraseña
<input name="password" type="password" minlength="12" maxlength="72" autocomplete="new-password" required>
</label>
<label>Repite la contraseña
<input name="password_repeat" type="password" minlength="12" maxlength="72" autocomplete="new-password" required>
</label>
<button type="submit">Guardar contraseña</button>
</form>
<script>
const token = new URLSearchParams(location.hash.slice(1)).get('token');
if (token) {
  document.getElementById('invitacion').value = token;
  history.replaceState(null, '', location.pathname);
}
</script>
<?php endif; ?>
<p><a href="/comercial/login.php">Ir al acceso comercial</a></p>
</main>
</body>
</html>
