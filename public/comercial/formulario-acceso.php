<?php
declare(strict_types=1);

if (!defined('CO_SALES_LOGIN_FORM')) {
    http_response_code(404);
    exit;
}

session_name('CONECTAOBRA_SALES');
session_set_cookie_params([
    'lifetime'=>0,
    'path'=>'/comercial',
    'secure'=>true,
    'httponly'=>true,
    'samesite'=>'Strict'
]);
session_start();
$_SESSION['sales_login_csrf'] ??= bin2hex(random_bytes(32));
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Acceso comercial | ConectaObra</title>
<style>
*{box-sizing:border-box}
body{margin:0;min-height:100vh;display:grid;place-items:center;padding:20px;background:#0d1b2a;color:white;font:16px/1.5 system-ui,sans-serif}
main{width:min(100%,440px);padding:28px;background:#162b3d;border-radius:14px}
label{display:block;margin-top:18px}
input,button{width:100%;min-height:48px;padding:12px;font:inherit;border-radius:8px}
input{margin-top:6px;border:1px solid #71889c}
button{margin-top:24px;background:#ff7a1a;color:#0d1b2a;border:0;font-weight:700}
strong span{color:#ff7a1a}
</style>
<link rel="stylesheet" href="/comercial/marca.css?v=2">
</head>
<body>
<main>
<div class="co-marca"><img src="/assets/logo-symbol.png" alt=""><span>CONECTA<b>OBRA</b></span></div>

<h1>Acceso comercial</h1>
<form method="post" action="/comercial/login.php">
<input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['sales_login_csrf'], ENT_QUOTES, 'UTF-8') ?>">
<label for="correo">Correo electrónico</label>
<input id="correo" name="email" type="email" maxlength="190" autocomplete="username" required>
<label for="clave">Contraseña</label>
<input id="clave" name="password" type="password" maxlength="1024" autocomplete="current-password" required>
<button type="submit">Entrar</button>
</form>
</main>
</body>
</html>
<?php exit; ?>
