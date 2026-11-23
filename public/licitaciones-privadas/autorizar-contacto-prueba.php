<?php
declare(strict_types=1);
// Servicio de licitaciones: respuesta del anunciante. No envía correos ni comparte contactos.
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_secure', '1');
ini_set('session.cookie_samesite', 'Lax');
session_name('CO_LIC_CONSENT');
session_start();
function h(mixed $s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function page(string $title, string $body): never {
    echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.h($title).' | ConectaObra</title><style>body{font-family:system-ui,sans-serif;background:#f4f6f8;color:#203040;margin:0}header{background:#203040;color:white;padding:24px}main{max-width:640px;margin:40px auto;padding:24px;background:white;border-radius:12px}button{border:0;border-radius:7px;padding:12px 18px;background:#f70;color:white;font-weight:bold;cursor:pointer}.no{background:#526171}form{display:inline-block;margin:8px 10px 8px 0}p{line-height:1.55}</style></head><body><header><strong>ConectaObra · Autorización de contacto</strong></header><main><h1>'.h($title).'</h1>'.$body.'</main></body></html>'; exit;
}
$token = (string)($_SERVER['REQUEST_METHOD']==='POST' ? ($_POST['token']??'') : ($_GET['token']??''));
if (!preg_match('/\A[a-f0-9]{64}\z/D', $token)) page('Enlace no válido', '<p>El enlace no es válido o ha caducado.</p>');
try {
    $cfg = require dirname(__DIR__,2).'/config_licitaciones/produccion/config.php';
    if (!is_array($cfg) || ($cfg['staging_only'] ?? false) !== false || ($cfg['dbname']??null)!==($cfg['expected_dbname'] ?? '__sin_configurar__')) throw new RuntimeException('Configuración no válida');
    $db = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',$cfg['host'],(int)$cfg['port'],$cfg['dbname']),$cfg['user'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    $sql="SELECT c.id,c.status,c.token_expires_at,i.company_name,i.status AS interest_status,t.title,t.status AS tender_status FROM private_tender_contact_consents c JOIN private_tender_interests i ON i.id=c.interest_id JOIN private_tenders t ON t.id=i.tender_id WHERE c.token_hash=? LIMIT 1";
    $q=$db->prepare($sql); $q->execute([hash('sha256',$token)]); $record=$q->fetch();
    if (!$record || $record['status']!=='pending' || $record['interest_status']!=='accepted' || $record['tender_status']!=='approved') page('Enlace no disponible','<p>Esta solicitud no está disponible o ya ha sido respondida.</p>');
    if ($record['token_expires_at']===null || $record['token_expires_at']<=gmdate('Y-m-d H:i:s')) {
        $q=$db->prepare("UPDATE private_tender_contact_consents SET status='expired',token_hash=NULL WHERE id=? AND status='pending'");$q->execute([$record['id']]);
        page('Enlace caducado','<p>El enlace ha caducado. Contacta con ConectaObra si deseas responder.</p>');
    }
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        if (!isset($_SESSION['csrf']) || !is_string($_POST['csrf']??null) || !hash_equals($_SESSION['csrf'],$_POST['csrf'])) page('Sesión caducada','<p>Abre de nuevo el enlace del correo.</p>');
        $decision=(string)($_POST['decision']??'');
        if (!in_array($decision,['authorized','denied'],true)) page('Decisión no válida','<p>No se ha guardado ninguna decisión.</p>');
        $q=$db->prepare("UPDATE private_tender_contact_consents SET status=?,decided_at=UTC_TIMESTAMP(),token_hash=NULL,token_expires_at=NULL WHERE id=? AND status='pending' AND token_hash=? AND token_expires_at>UTC_TIMESTAMP()");
        $q->execute([$decision,$record['id'],hash('sha256',$token)]);
        if ($q->rowCount()!==1) page('Enlace no disponible','<p>Esta solicitud ya no está disponible.</p>');
        unset($_SESSION['csrf']);
        page('Respuesta registrada', $decision==='authorized' ? '<p>Has autorizado a ConectaObra a facilitar tus datos de contacto a la empresa indicada para esta licitación. ConectaObra gestionará el contacto manualmente.</p>' : '<p>Has rechazado compartir tus datos de contacto con esta empresa. No se compartirán.</p>');
    }
    $_SESSION['csrf']=bin2hex(random_bytes(32));
    $form='<p>Obra: <strong>'.h($record['title']).'</strong></p><p>Empresa interesada: <strong>'.h($record['company_name']).'</strong></p><p>¿Autorizas a ConectaObra a compartir tus datos de contacto con esta empresa concreta para gestionar esta obra?</p>';
    foreach (['authorized'=>'Sí, autorizo','denied'=>'No autorizo'] as $value=>$label) {
        $form.='<form method="post"><input type="hidden" name="token" value="'.h($token).'"><input type="hidden" name="csrf" value="'.h($_SESSION['csrf']).'"><input type="hidden" name="decision" value="'.h($value).'"><button class="'.($value==='denied'?'no':'').'" type="submit">'.h($label).'</button></form>';
    }
    $form.='<p><small>La respuesta queda registrada. Ningún dato se enviará automáticamente a la empresa.</small></p>';
    page('Autorizar contacto', $form);
} catch (Throwable $e) { error_log('Tender consent response error: '.get_class($e)); page('Servicio no disponible','<p>No se ha podido completar la operación. Inténtalo más tarde.</p>'); }
