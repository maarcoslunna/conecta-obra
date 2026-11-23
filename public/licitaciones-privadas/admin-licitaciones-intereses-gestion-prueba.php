<?php
declare(strict_types=1);
// Panel independiente de PRUEBAS. No modifica el administrador principal.
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store, private');
header('Content-Security-Policy: default-src \'none\'; style-src \'unsafe-inline\'; form-action \'self\'; base-uri \'none\'; frame-ancestors \'none\'');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_secure', '1');
ini_set('session.cookie_samesite', 'Strict');
session_name('CO_LIC_ADMIN');
session_start();
function esc(mixed $s): string {return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');}
function csrf(): string {return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));}
function validCsrf(): bool {return isset($_POST['csrf']) && is_string($_POST['csrf']) && hash_equals(csrf(), $_POST['csrf']);}
function redirectSelf(): never {header('Location: admin-licitaciones-intereses-gestion-prueba.php', true, 303);exit;}
function failPage(): never {http_response_code(503);echo '<!doctype html><html lang="es"><meta charset="utf-8"><title>Panel temporalmente no disponible</title><p>Panel temporalmente no disponible. Revisa la configuración privada.</p></html>';exit;}
try {
    $base = dirname(__DIR__, 2) . '/config_licitaciones/produccion/';
    $cfg = require $base . 'config.php';
    $auth = require $base . 'admin_config.php';
    if (!is_array($cfg) || !is_array($auth) || ($cfg['staging_only'] ?? false) !== false || ($cfg['dbname'] ?? null) !== ($cfg['expected_dbname'] ?? '__sin_configurar__')
        || ($auth['admin_username'] ?? null) !== 'admin_licitaciones'
        || !is_string($auth['admin_password_hash'] ?? null)
        || password_get_info($auth['admin_password_hash'])['algo'] === null) { failPage(); }
} catch (Throwable $e) {error_log('Tender admin: private configuration unavailable');failPage();}
$error = '';
$notice = $_SESSION['notice'] ?? '';
unset($_SESSION['notice']);
$logged = ($_SESSION['lic_admin'] ?? false) === true;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validCsrf()) {http_response_code(403);$error='Sesión caducada. Recarga la página.';}
    elseif (($_POST['operation'] ?? '') === 'login' && !$logged) {
        $last = (int)($_SESSION['login_window'] ?? 0);
        if (time() - $last > 900) {$_SESSION['login_window'] = time();$_SESSION['login_attempts'] = 0;}
        if ((int)($_SESSION['login_attempts'] ?? 0) >= 5) {$error='Demasiados intentos. Espera 15 minutos.';}
        else {
            $name = (string)($_POST['username'] ?? '');
            $password = (string)($_POST['password'] ?? '');
            $ok = hash_equals($auth['admin_username'], $name) && password_verify($password, $auth['admin_password_hash']);
            unset($password);
            if ($ok) {session_regenerate_id(true);$_SESSION['lic_admin']=true;$_SESSION['login_attempts']=0;$_SESSION['csrf']=bin2hex(random_bytes(32));redirectSelf();}
            $_SESSION['login_attempts'] = (int)($_SESSION['login_attempts'] ?? 0) + 1;
            $error='Usuario o contraseña incorrectos.';
        }
    } elseif (($_POST['operation'] ?? '') === 'logout' && $logged) {
        $_SESSION=[];session_regenerate_id(true);redirectSelf();
    } elseif (($_POST['operation'] ?? '') === 'interest_decision' && $logged) {
        $id = filter_var($_POST['interest_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        $decision = (string)($_POST['decision'] ?? '');
        if (!$id || !in_array($decision, ['accepted', 'rejected'], true)) {
            $error = 'Decisión de interés no válida.';
        } else {
            try {
                $dsn=sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $cfg['host'], (int)$cfg['port'], $cfg['dbname']);
                $db=new PDO($dsn,$cfg['user'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
                $stmt=$db->prepare("UPDATE private_tender_interests SET status=? WHERE id=? AND status='pending'");
                $stmt->execute([$decision,$id]);
                $_SESSION['notice']=$stmt->rowCount()===1 ? 'Decisión sobre el interés guardada correctamente.' : 'La solicitud ya no estaba pendiente. No se modificó.';
                redirectSelf();
            } catch(Throwable $e) {error_log('Tender admin: interest decision failed');$error='No se pudo guardar la decisión sobre el interés.';}
        }
    } elseif (($_POST['operation'] ?? '') === 'change' && $logged) {
        $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        $decision = (string)($_POST['decision'] ?? '');
        if (!$id || !in_array($decision, ['approved','rejected'], true)) {$error='Solicitud no válida.';}
        else {
            try {
                $dsn=sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $cfg['host'], (int)$cfg['port'], $cfg['dbname']);
                $db=new PDO($dsn,$cfg['user'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
                $stmt=$db->prepare("UPDATE private_tenders SET status=? WHERE id=? AND status='pending_review'");
                $stmt->execute([$decision,$id]);
                $_SESSION['notice']=$stmt->rowCount()===1 ? 'Decisión guardada correctamente.' : 'La licitación ya no estaba pendiente. No se modificó.';
                redirectSelf();
            } catch(Throwable $e) {error_log('Tender admin: update failed');$error='No se pudo guardar la decisión.';}
        }
    }
}
$items=[]; $interests=[];
if ($logged) {
    try {
        if (!isset($db)) {
            $dsn=sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',$cfg['host'],(int)$cfg['port'],$cfg['dbname']);
            $db=new PDO($dsn,$cfg['user'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
        }
        $items=$db->query("SELECT id,publisher_type,contact_name,email,title,work_type,province,municipality,description,budget,start_date,deadline,verified_at,created_at FROM private_tenders WHERE status='pending_review' ORDER BY verified_at ASC,id ASC LIMIT 100")->fetchAll();
        $interests=$db->query("SELECT i.id,i.tender_id,i.company_user_id,i.company_name,i.company_email,i.message,i.status,i.admin_notes,i.created_at,t.title AS tender_title FROM private_tender_interests i INNER JOIN private_tenders t ON t.id=i.tender_id ORDER BY i.created_at DESC,i.id DESC LIMIT 100")->fetchAll();
    } catch(Throwable $e) {error_log('Tender admin: list failed');$error='No se pudieron cargar las licitaciones.';}
}
?><!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Administración de licitaciones | ConectaObra</title>
<style>
:root{font-family:system-ui,-apple-system,Segoe UI,sans-serif;color:#203040;background:#f4f6f8}*{box-sizing:border-box}body{margin:0}header{background:#203040;color:white;padding:22px max(22px,calc((100% - 1050px)/2));font-weight:750}header span{color:#ff7100}main{max-width:1050px;margin:38px auto;padding:0 20px}h1{font-size:1.65rem}h2{font-size:1.18rem}.card{background:white;border:1px solid #dce2e8;border-radius:13px;padding:25px;margin:18px 0;box-shadow:0 3px 16px #00000008}.login{max-width:450px;margin:65px auto}label{display:block;font-weight:650;margin:15px 0 6px}input{width:100%;padding:12px;border:1px solid #abb6c2;border-radius:7px;font-size:1rem}button{background:#ff7100;color:white;border:0;border-radius:7px;padding:11px 17px;font-weight:750;cursor:pointer}button:hover{filter:brightness(.94)}button.reject{background:#a83232}button.logout{background:#455769}.alert{padding:13px;border-radius:8px;background:#fff0ec;margin:15px 0}.ok{background:#e7f5eb}.top{display:flex;justify-content:space-between;align-items:center;gap:15px;flex-wrap:wrap}.meta{color:#516171;font-size:.94rem}.desc{white-space:pre-wrap;overflow-wrap:anywhere;background:#f7f8fa;padding:13px;border-radius:8px}.actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:20px}dt{font-weight:700}dd{margin:2px 0 12px;overflow-wrap:anywhere}dl{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:0 18px}p{line-height:1.5}small{color:#526171}
</style></head><body><header>CONECTA<span>OBRA</span> · Administración privada de licitaciones</header><main>
<?php if (!$logged): ?>
<section class="card login"><h1>Acceso de administrador</h1><p class="meta">Área privada independiente de la administración principal.</p>
<?php if($error):?><p class="alert" role="alert"><?=esc($error)?></p><?php endif;?>
<form method="post" autocomplete="on"><input type="hidden" name="csrf" value="<?=esc(csrf())?>"><input type="hidden" name="operation" value="login"><label for="username">Usuario</label><input id="username" name="username" required autocomplete="username" maxlength="100"><label for="password">Contraseña</label><input id="password" name="password" type="password" required autocomplete="current-password"><p><button type="submit">Entrar</button></p></form></section>
<?php else: ?>
<div class="top"><div><h1>Licitaciones pendientes de revisión</h1><p class="meta">Solo solicitudes con correo verificado. Máximo 100 resultados.</p></div><form method="post"><input type="hidden" name="csrf" value="<?=esc(csrf())?>"><input type="hidden" name="operation" value="logout"><button class="logout" type="submit">Cerrar sesión</button></form></div>
<?php if($error):?><p class="alert" role="alert"><?=esc($error)?></p><?php endif;?>
<?php if($notice):?><p class="alert ok" role="status"><?=esc($notice)?></p><?php endif;?>
<?php if(!$items):?><section class="card"><p>No hay licitaciones pendientes de revisión.</p></section><?php endif;?>
<?php foreach($items as $item):?><article class="card"><h2>#<?=esc($item['id'])?> · <?=esc($item['title'])?></h2><dl><div><dt>Tipo de anunciante</dt><dd><?=esc($item['publisher_type'])?></dd></div><div><dt>Contacto</dt><dd><?=esc($item['contact_name'])?></dd></div><div><dt>Correo</dt><dd><?=esc($item['email'])?></dd></div><div><dt>Tipo de obra</dt><dd><?=esc($item['work_type'])?></dd></div><div><dt>Provincia y municipio</dt><dd><?=esc($item['province'])?> · <?=esc($item['municipality'])?></dd></div><div><dt>Presupuesto</dt><dd><?=($item['budget']===null?'No indicado':esc(number_format((float)$item['budget'],2,',','.')).' €')?></dd></div><div><dt>Inicio previsto</dt><dd><?=esc($item['start_date']??'No indicado')?></dd></div><div><dt>Fecha límite</dt><dd><?=esc($item['deadline']??'No indicada')?></dd></div><div><dt>Correo verificado</dt><dd><?=esc($item['verified_at'])?> UTC</dd></div></dl><h3>Descripción</h3><p class="desc"><?=esc($item['description'])?></p>
<div class="actions"><form method="post"><input type="hidden" name="csrf" value="<?=esc(csrf())?>"><input type="hidden" name="operation" value="change"><input type="hidden" name="id" value="<?=esc($item['id'])?>"><input type="hidden" name="decision" value="approved"><button type="submit">Aprobar licitación</button></form><form method="post"><input type="hidden" name="csrf" value="<?=esc(csrf())?>"><input type="hidden" name="operation" value="change"><input type="hidden" name="id" value="<?=esc($item['id'])?>"><input type="hidden" name="decision" value="rejected"><button class="reject" type="submit">Rechazar licitación</button></form></div><p><small>La aprobación cambia el estado en la base de pruebas; todavía no publica la licitación en la web principal.</small></p></article><?php endforeach;?>
<?php if($error === ''): ?>
<section aria-labelledby="interest-title"><h2 id="interest-title">Empresas interesadas en licitaciones</h2><p class="meta">Solicitudes recibidas en el entorno de pruebas. Máximo 100 resultados. Puedes aceptar o rechazar solicitudes pendientes. No se envían correos ni se comparten datos automáticamente.</p>
<?php if(!$interests): ?><section class="card"><p>No hay solicitudes de interés registradas.</p></section><?php endif; ?>
<?php foreach($interests as $interest): ?><article class="card"><h3>Solicitud #<?=esc($interest['id'])?> · <?=esc($interest['tender_title'])?></h3><dl><div><dt>Licitación nº</dt><dd><?=esc($interest['tender_id'])?></dd></div><div><dt>Empresa registrada nº</dt><dd><?=esc($interest['company_user_id'])?></dd></div><div><dt>Empresa</dt><dd><?=esc($interest['company_name'])?></dd></div><div><dt>Correo de empresa</dt><dd><?=esc($interest['company_email'])?></dd></div><div><dt>Estado</dt><dd><?=esc($interest['status'])?></dd></div><div><dt>Fecha</dt><dd><?=esc($interest['created_at'])?> (hora del servidor)</dd></div></dl><h3>Mensaje</h3><p class="desc"><?=esc($interest['message'])?></p><?php if($interest['admin_notes']!==null && $interest['admin_notes']!==''): ?><h3>Notas de administración</h3><p class="desc"><?=esc($interest['admin_notes'])?></p><?php endif; ?><?php if($interest['status']==='pending'): ?><div class="actions"><form method="post"><input type="hidden" name="csrf" value="<?=esc(csrf())?>"><input type="hidden" name="operation" value="interest_decision"><input type="hidden" name="interest_id" value="<?=esc($interest['id'])?>"><input type="hidden" name="decision" value="accepted"><button type="submit">Aceptar interés</button></form><form method="post"><input type="hidden" name="csrf" value="<?=esc(csrf())?>"><input type="hidden" name="operation" value="interest_decision"><input type="hidden" name="interest_id" value="<?=esc($interest['id'])?>"><input type="hidden" name="decision" value="rejected"><button class="reject" type="submit">Rechazar interés</button></form></div><?php endif; ?></article><?php endforeach; ?></section>
<?php endif; ?>
<?php endif;?></main></body></html>
