<?php
declare(strict_types=1);

// CONECTAOBRA_FREELANCE_ACCESS_V1
// Formulario deshabilitado hasta completar pruebas.

ini_set('display_errors', '0');

header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Content-Type: text/html; charset=UTF-8');

$isHttps = !empty($_SERVER['HTTPS'])
    && $_SERVER['HTTPS'] !== 'off';

if (!$isHttps) {
    http_response_code(403);
    exit('HTTPS obligatorio');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit('Metodo no permitido');
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

if (!isset($_SESSION['sales_login_csrf'])) {
    $_SESSION['sales_login_csrf'] = bin2hex(random_bytes(32));
}

$loginEnabled = false;
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Acceso comercial | ConectaObra</title>
<style>
*{box-sizing:border-box}
body{
    margin:0;
    min-height:100vh;
    display:grid;
    place-items:center;
    padding:24px;
    font-family:system-ui,Arial,sans-serif;
    background:#f3f6fa;
    color:#14273b
}
main{
    width:100%;
    max-width:430px;
    padding:34px;
    border-radius:18px;
    background:white;
    box-shadow:0 12px 40px rgba(14,35,58,.09)
}
h1{margin:0 0 8px;font-size:28px}
p{line-height:1.6;color:#536273}
.marca{font-weight:800;color:#ed7900;margin-bottom:22px}
label{display:block;margin:18px 0 7px;font-weight:600}
input{
    width:100%;
    padding:13px;
    border:1px solid #cbd4de;
    border-radius:9px;
    font:inherit
}
button{
    width:100%;
    margin-top:24px;
    padding:14px;
    border:0;
    border-radius:9px;
    background:#ed7900;
    color:white;
    font:inherit;
    font-weight:700
}
button:disabled{background:#aeb8c3;cursor:not-allowed}
.aviso{
    padding:14px;
    margin-top:22px;
    border-radius:9px;
    background:#f4f6f8;
    color:#425267
}
</style>
</head>
<body>
<main>
<div class="marca">CONECTAOBRA</div>
<h1>Área comercial</h1>
<p>Acceso exclusivo para comerciales freelance autorizados.</p>

<?php if (!$loginEnabled): ?>
<div class="aviso" role="status">
El acceso privado está en preparación.
Las cuentas comerciales todavía no están habilitadas.
</div>
<?php else: ?>
<form action="/comercial/login.php" method="post">
<input
    type="hidden"
    name="csrf"
    value="<?= htmlspecialchars(
        $_SESSION['sales_login_csrf'],
        ENT_QUOTES,
        'UTF-8'
    ) ?>"
>
<label for="email">Correo profesional</label>
<input
    id="email"
    name="email"
    type="email"
    autocomplete="username"
    maxlength="190"
    required
>
<label for="password">Contraseña</label>
<input
    id="password"
    name="password"
    type="password"
    autocomplete="current-password"
    required
>
<button type="submit">Entrar en mi área comercial</button>
</form>
<?php endif; ?>
</main>
</body>
</html>
