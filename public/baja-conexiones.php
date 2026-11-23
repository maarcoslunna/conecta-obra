<?php
declare(strict_types=1);
ini_set('display_errors','0');
header('Cache-Control: private, no-store');header('Referrer-Policy: no-referrer');header('X-Content-Type-Options: nosniff');header('X-Frame-Options: DENY');
if(empty($_SERVER['HTTPS'])||$_SERVER['HTTPS']==='off'){http_response_code(403);exit('HTTPS obligatorio');}
if(!in_array($_SERVER['REQUEST_METHOD']??'',['GET','POST'],true)){http_response_code(405);exit;}
session_name('CO_CONNECTIONS_UNSUB');session_set_cookie_params(['lifetime'=>0,'path'=>'/baja-conexiones.php','secure'=>true,'httponly'=>true,'samesite'=>'Strict']);session_start();
$_SESSION['csrf']??=bin2hex(random_bytes(32));
$token=$_SERVER['REQUEST_METHOD']==='POST'?($_POST['token']??''):($_GET['token']??'');
$valid=false;$done=false;$error='';$db=null;
try{
    if(!is_string($token)||!preg_match('/^[a-f0-9]{64}$/D',$token))throw new InvalidArgumentException('El enlace no es válido. Puedes gestionar los avisos desde tu cuenta.');
    $config=require __DIR__.'/config.php';$c=$config['db']??$config;
    $db=new PDO('mysql:host='.$c['host'].';port='.($c['port']??3306).';dbname='.$c['dbname'].';charset=utf8mb4',$c['user'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $q=$db->prepare('SELECT user_id FROM co_connection_unsubscribe_tokens WHERE token_hash=?');$q->execute([hash('sha256',$token)]);$uid=$q->fetchColumn();
    if(!$uid)throw new InvalidArgumentException('El enlace no está disponible. Puedes gestionar los avisos desde tu cuenta.');
    $valid=true;
    if($_SERVER['REQUEST_METHOD']==='POST'){
        if(!is_string($_POST['csrf']??null)||!hash_equals($_SESSION['csrf'],$_POST['csrf']))throw new InvalidArgumentException('Recarga la página y vuelve a confirmar.');
        $db->beginTransaction();$q=$db->prepare('SELECT * FROM co_connection_preferences WHERE user_id=? FOR UPDATE');$q->execute([(int)$uid]);$prefs=$q->fetch();
        if($prefs&&(int)$prefs['email_enabled']===1){
            $revision=(int)$prefs['revision']+1;$now=gmdate('Y-m-d H:i:s');
            $db->prepare('UPDATE co_connection_preferences SET email_enabled=0,unsubscribed_at=?,updated_at=?,revision=? WHERE user_id=?')->execute([$now,$now,$revision,(int)$uid]);
            $snapshot=[];foreach(['mode','frequency','availability'] as $k)$snapshot[$k]=$prefs[$k];
            foreach(['offered_topics','needed_topics','provinces','keywords'] as $k)$snapshot[$k]=json_decode($prefs[$k],true,512,JSON_THROW_ON_ERROR);
            $snapshot['national']=(bool)$prefs['national'];$snapshot['email_enabled']=false;
            $q=$db->prepare('INSERT INTO co_connection_preference_history (user_id,revision,email_enabled,consent_version,consent_text,preferences_snapshot,created_at) VALUES (?,?,0,?,?,?,?)');
            $q->execute([(int)$uid,$revision,'CONEXIONES-EMAIL-v1','Baja de avisos confirmada desde el enlace recibido por correo.',json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$now]);
        }
        $db->commit();$done=true;
    }
}catch(InvalidArgumentException $e){if($db instanceof PDO&&$db->inTransaction())$db->rollBack();$error=$e->getMessage();http_response_code(400);}
catch(Throwable $e){if($db instanceof PDO&&$db->inTransaction())$db->rollBack();$valid=false;$error='No se pudo completar la operación. Puedes gestionar los avisos desde tu cuenta o escribir a info@conectaobra10.es.';http_response_code(500);error_log('ConectaObra unsubscribe failure');}
function h(string $v):string{return htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Avisos de conexiones | ConectaObra</title><link rel="stylesheet" href="assets/conexiones-v1.css?v=1"></head><body><header><a class="brand" href="index.html"><img src="assets/logo-symbol.png" width="40" height="40" alt=""><strong>CONECTA<span>OBRA</span></strong></a></header><main><section><h1>Avisos de conexiones</h1>
<?php if($done): ?><p role="status">Tus avisos por correo están desactivados. Tu cuenta, tu plan y tus contactos no cambian.</p><p>Puedes volver a activarlos cuando quieras desde tus preferencias.</p>
<?php else: ?>
<?php if($error!==''): ?><p class="error" role="alert"><?=h($error)?></p><?php endif; ?>
<?php if($valid): ?><p>Confirma si quieres dejar de recibir los avisos por correo de Conexiones ConectaObra.</p><p>Abrir este enlace no ha cambiado tus preferencias. Tu cuenta, plan y contactos se mantienen.</p><form method="post" action="baja-conexiones.php"><input type="hidden" name="token" value="<?=h($token)?>"><input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>"><button type="submit">Desactivar avisos</button></form><?php endif; ?>
<?php endif; ?><p><a href="conexiones.html">Gestionar mis preferencias</a></p></section></main></body></html>
