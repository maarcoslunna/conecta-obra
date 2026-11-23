<?php
declare(strict_types=1);
ini_set('display_errors','0');
header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');header('X-Frame-Options: DENY');header('Referrer-Policy: no-referrer');
if(empty($_SERVER['HTTPS'])||$_SERVER['HTTPS']==='off'){http_response_code(403);exit('HTTPS obligatorio');}
$method=$_SERVER['REQUEST_METHOD']??'';if(!in_array($method,['GET','POST'],true)){http_response_code(405);header('Allow: GET, POST');exit;}
session_name('CO_NOTICES_UNSUB');session_set_cookie_params(['lifetime'=>0,'path'=>'/baja-avisos-conexiones.php','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);session_start();
if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));
$token=$method==='POST'?($_POST['token']??''):($_GET['token']??'');$message='Confirma que quieres desactivar los avisos de intereses y respuestas. Los resúmenes de oportunidades se gestionan por separado.';$valid=false;$done=false;
try{
 if(!is_string($token)||!preg_match('/^[a-f0-9]{64}$/D',$token))throw new InvalidArgumentException('Enlace no válido. Puedes gestionar tus avisos desde tu cuenta.');
 $config=require __DIR__.'/config.php';$c=$config['db']??$config;$db=new PDO('mysql:host='.$c['host'].';port='.($c['port']??3306).';dbname='.$c['dbname'].';charset=utf8mb4',$c['user'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
 define('CO_CONNECTION_NOTICES_INTERNAL',true);require __DIR__.'/mega-bloque/conexiones/notices-core.php';
 $s=$db->prepare('SELECT t.user_id,p.revision,p.enabled FROM co_connection_notice_tokens t JOIN co_connection_notice_preferences p ON p.user_id=t.user_id JOIN users u ON u.id=t.user_id WHERE t.token_hash=?');$s->execute([hash('sha256',$token)]);$row=$s->fetch();if(!$row)throw new InvalidArgumentException('Enlace no válido. Puedes gestionar tus avisos desde tu cuenta.');$valid=true;
 if($method==='POST'){
  if(!is_string($_POST['_csrf']??null)||!hash_equals($_SESSION['csrf'],$_POST['_csrf'])){http_response_code(403);throw new InvalidArgumentException('La confirmación ha caducado. Recarga la página antes de confirmar.');}
  if((int)$row['enabled']===1)coNoticeSave($db,(int)$row['user_id'],false,(int)$row['revision']);
  $message='Avisos de intereses y respuestas desactivados. Puedes volver a activarlos voluntariamente desde tu cuenta. Tus resúmenes de oportunidades no han cambiado.';$done=true;
 }
}catch(InvalidArgumentException $e){$message=$e->getMessage();if(!$valid)http_response_code(400);}
catch(Throwable $e){error_log('ConectaObra notice unsubscribe failure');http_response_code(500);$valid=false;$message='No se pudo completar la operación. Puedes desactivar estos avisos desde tu cuenta o contactar con info@conectaobra10.es.';}
$esc=static fn(string $v):string=>htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Avisos de conexiones | ConectaObra</title><style>body{margin:0;background:#0d1b2a;color:#fff;font:16px/1.6 Arial,sans-serif}main{max-width:600px;margin:8vh auto;padding:24px;background:#172d3e;border:1px solid #3d6079;border-radius:14px}a{color:#ff9b55}button{background:#ff6a00;color:#0d1b2a;border:0;border-radius:8px;padding:14px;font-weight:bold;cursor:pointer}img{vertical-align:middle}.brand{display:flex;gap:10px;align-items:center;text-decoration:none;color:white}span{color:#ff9b55}</style></head><body><main><a class="brand" href="/"><img src="assets/logo-symbol.png" width="38" height="38" alt=""><strong>CONECTA<span>OBRA</span></strong></a><h1>Avisos de conexiones</h1><p><?= $esc($message) ?></p><?php if($valid&&!$done): ?><form method="post" action="/baja-avisos-conexiones.php"><input type="hidden" name="token" value="<?= $esc($token) ?>"><input type="hidden" name="_csrf" value="<?= $esc($_SESSION['csrf']) ?>"><button type="submit">Confirmar baja de estos avisos</button></form><?php endif; ?><p><a href="conexiones.html#avisos-conexiones">Gestionar mis preferencias</a></p><p>ConectaObra<br>Ayuda: <a href="mailto:info@conectaobra10.es">info@conectaobra10.es</a></p></main></body></html>
