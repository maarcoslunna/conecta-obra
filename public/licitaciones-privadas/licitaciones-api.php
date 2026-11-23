<?php
declare(strict_types=1);
// FASE 1 / PRUEBAS: endpoint separado; no modifica api.php existente.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
function respond(array $data,int $code=200): never {http_response_code($code);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
function smtpReadTender($fp): string {$s='';while(($line=fgets($fp,515))!==false){$s.=$line;if(strlen($line)>=4&&$line[3]===' ')break;}return $s;}
function smtpCmdTender($fp,string $command,array $codes): void {if($command!=='')fwrite($fp,$command."\r\n");$r=smtpReadTender($fp);if(!in_array((int)substr($r,0,3),$codes,true))throw new RuntimeException('SMTP response '.substr($r,0,3));}
function sendTenderMail(array $cfg,string $to,string $subject,string $message): bool {
  $host=(string)($cfg['smtp_host']??'');$port=(int)($cfg['smtp_port']??465);$user=(string)($cfg['smtp_user']??'');$pass=(string)($cfg['smtp_password']??'');
  // Se exige la cuenta remitente acordada; no se usa una dirección suplantada.
  if(strtolower($user)!=='info@conectaobra10.es'||!$host||!$pass)return false;
  $ctx=stream_context_create(['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'peer_name'=>$host]]);
  $fp = @stream_socket_client(
    'tcp://' . $host . ':' . $port,
    $errno,
    $errstr,
    15,
    STREAM_CLIENT_CONNECT,
    $ctx
);

if (!$fp) return false;

stream_set_timeout($fp, 15);

try {
    smtpCmdTender($fp, '', [220]);
    smtpCmdTender($fp, 'EHLO conectaobra10.es', [250]);

    // Activar cifrado TLS antes de enviar credenciales.
    smtpCmdTender($fp, 'STARTTLS', [220]);

    if (!stream_socket_enable_crypto(
        $fp,
        true,
        STREAM_CRYPTO_METHOD_TLS_CLIENT
    )) {
        throw new RuntimeException('No se pudo activar TLS');
    }

    smtpCmdTender($fp, 'EHLO conectaobra10.es', [250]);

    smtpCmdTender($fp, 'AUTH LOGIN', [334]);
    smtpCmdTender($fp, base64_encode($user), [334]);
    smtpCmdTender($fp, base64_encode($pass), [235]);
    smtpCmdTender($fp,'MAIL FROM:<'.$user.'>',[250]);smtpCmdTender($fp,'RCPT TO:<'.$to.'>',[250,251]);smtpCmdTender($fp,'DATA',[354]);
    $headers=['From: ConectaObra <'.$user.'>','To: <'.$to.'>','Subject: =?UTF-8?B?'.base64_encode($subject).'?=','MIME-Version: 1.0','Content-Type: text/plain; charset=UTF-8','Content-Transfer-Encoding: 8bit'];
    $safe=preg_replace('/^\./m','..',str_replace(["\r\n","\r"],"\n",$message));
    fwrite($fp,implode("\r\n",$headers)."\r\n\r\n".str_replace("\n","\r\n",$safe)."\r\n.\r\n");smtpCmdTender($fp,'',[250]);@fwrite($fp,"QUIT\r\n");fclose($fp);return true;
  }catch(Throwable $e){error_log('Tender SMTP error: '.get_class($e).' (detalles reservados)');@fclose($fp);return false;}
}
try {
  $cfg=require dirname(__DIR__, 2).'/config_licitaciones/produccion/config.php';
  if(($cfg['dbname']??'')!==($cfg['expected_dbname'] ?? '__sin_configurar__')||($cfg['staging_only'] ?? false) !== false)throw new RuntimeException('Configuración de producción incorrecta');
  $dsn=sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',$cfg['host'],(int)$cfg['port'],$cfg['dbname']);
  $db=new PDO($dsn,$cfg['user'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
  $action=(string)($_GET['action']??'');
  if($action==='verificar'){
    if($_SERVER['REQUEST_METHOD']!=='GET')respond(['ok'=>false,'error'=>'Método no permitido.'],405);
    $token=(string)($_GET['token']??'');
    if(!preg_match('/^[a-f0-9]{64}$/D',$token))respond(['ok'=>false,'error'=>'Enlace no válido o caducado.'],400);
    $stmt=$db->prepare("UPDATE private_tenders SET status='pending_review',verified_at=UTC_TIMESTAMP(),verification_token_hash=NULL,verification_expires_at=NULL WHERE verification_token_hash=? AND status='pending_email' AND verification_expires_at>UTC_TIMESTAMP()");
    $stmt->execute([hash('sha256',$token)]);
    if($stmt->rowCount()!==1)respond(['ok'=>false,'error'=>'Enlace no válido, utilizado o caducado.'],400);
    respond(['ok'=>true,'message'=>'Correo verificado. Tu licitación queda pendiente de revisión por ConectaObra.']);
  }
  if($action!=='crear')respond(['ok'=>false,'error'=>'Acción no disponible.'],404);
  if($_SERVER['REQUEST_METHOD']!=='POST')respond(['ok'=>false,'error'=>'Método no permitido.'],405);
  if(!str_starts_with(strtolower((string)($_SERVER['CONTENT_TYPE']??'')),'application/json'))respond(['ok'=>false,'error'=>'Formato no permitido.'],415);
  if((int)($_SERVER['CONTENT_LENGTH']??0)>15000)respond(['ok'=>false,'error'=>'Solicitud demasiado extensa.'],413);
  // Defensa adicional contra envíos desde sitios ajenos.
  $origin=(string)($_SERVER['HTTP_ORIGIN']??'');
  if($origin!==''){$originHost=parse_url($origin,PHP_URL_HOST);$host=explode(':',(string)($_SERVER['HTTP_HOST']??''))[0];if(!is_string($originHost)||strcasecmp($originHost,$host)!==0)respond(['ok'=>false,'error'=>'Origen no autorizado.'],403);}
  $d=json_decode(file_get_contents('php://input')?:'',true);
  if(!is_array($d))respond(['ok'=>false,'error'=>'Datos no válidos.'],400);
  $fields=['publisher_type','contact_name','email','title','work_type','province','municipality','description'];
  foreach($fields as $f){if(!isset($d[$f])||!is_string($d[$f]))respond(['ok'=>false,'error'=>'Faltan campos obligatorios.'],422);$d[$f]=trim($d[$f]);}
  if(!in_array($d['publisher_type'],['particular','comunidad','empresa','promotora'],true)||!filter_var($d['email'],FILTER_VALIDATE_EMAIL)||strlen($d['email'])>190)respond(['ok'=>false,'error'=>'Datos de contacto no válidos.'],422);
  foreach(['contact_name'=>120,'title'=>150,'work_type'=>100,'province'=>100,'municipality'=>120] as $k=>$max){if($d[$k]===''||mb_strlen($d[$k])>$max)respond(['ok'=>false,'error'=>'Revisa los campos del formulario.'],422);}
  $types=['Reforma y rehabilitación','Obra nueva','Instalaciones','Mantenimiento','Urbanización y obra civil','Servicios técnicos','Otros trabajos'];
  if(!in_array($d['province'],['Álava','Albacete','Alicante','Almería','Asturias','Ávila','Badajoz','Barcelona','Bizkaia','Burgos','Cáceres','Cádiz','Cantabria','Castellón','Ceuta','Ciudad Real','Córdoba','Cuenca','Gipuzkoa','Girona','Granada','Guadalajara','Huelva','Huesca','Illes Balears','Jaén','La Coruña','La Rioja','Las Palmas','León','Lleida','Lugo','Madrid','Málaga','Melilla','Murcia','Navarra','Ourense','Palencia','Pontevedra','Salamanca','Santa Cruz de Tenerife','Segovia','Sevilla','Soria','Tarragona','Teruel','Toledo','Valencia','Valladolid','Zamora','Zaragoza'],true))respond(['ok'=>false,'error'=>'Provincia no válida.'],422);
  if(!in_array($d['work_type'],$types,true)||mb_strlen($d['description'])<30||mb_strlen($d['description'])>5000)respond(['ok'=>false,'error'=>'Tipo de obra o descripción no válidos.'],422);
  if(($d['privacy']??'')!=='on'||($d['accuracy']??'')!=='on')respond(['ok'=>false,'error'=>'Debes confirmar las declaraciones del formulario.'],422);
  $budget=$d['budget']??null;
  if($budget==='')$budget=null;
  if($budget!==null&&(!is_numeric($budget)||(float)$budget<0||(float)$budget>999999999))respond(['ok'=>false,'error'=>'Presupuesto no válido.'],422);
  $dates=[];
  foreach(['start_date','deadline'] as $f){$v=$d[$f]??'';if($v===''){$dates[$f]=null;continue;}if(!is_string($v)||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$v)||DateTimeImmutable::createFromFormat('!Y-m-d',$v)?->format('Y-m-d')!==$v)respond(['ok'=>false,'error'=>'Fecha no válida.'],422);$dates[$f]=$v;}
  if($dates['start_date']&&$dates['deadline']&&$dates['deadline']>$dates['start_date'])respond(['ok'=>false,'error'=>'La fecha límite no puede ser posterior al inicio.'],422);
  $ip=(string)($_SERVER['REMOTE_ADDR']??'unknown');$bucket=hash('sha256','tender|'.$ip);$now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
  $db->beginTransaction();
  $q=$db->prepare('SELECT hits,expires_at FROM private_tender_rate_limits WHERE bucket=? FOR UPDATE');$q->execute([$bucket]);$rate=$q->fetch();
  if($rate&&$rate['expires_at']>$now->format('Y-m-d H:i:s')&&(int)$rate['hits']>=3){$db->rollBack();respond(['ok'=>false,'error'=>'Demasiadas solicitudes. Prueba dentro de una hora.'],429);}
  $hits=$rate&&$rate['expires_at']>$now->format('Y-m-d H:i:s')?(int)$rate['hits']+1:1;
  $until=$now->modify('+1 hour')->format('Y-m-d H:i:s');
  $db->prepare('INSERT INTO private_tender_rate_limits(bucket,hits,expires_at) VALUES(?,?,?) ON DUPLICATE KEY UPDATE hits=VALUES(hits),expires_at=VALUES(expires_at)')->execute([$bucket,$hits,$until]);
  $db->commit();
  $base=rtrim((string)($cfg['tender_base_url']??''),'/');
  if(!preg_match('~^https://[a-z0-9.-]+(?:/[a-zA-Z0-9/_-]*)?$~iD',$base))throw new RuntimeException('Falta tender_base_url HTTPS en configuración');
  $token=bin2hex(random_bytes(32));
  $stmt=$db->prepare("INSERT INTO private_tenders(publisher_type,contact_name,email,title,work_type,province,municipality,description,budget,start_date,deadline,status,verification_token_hash,verification_expires_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,'pending_email',?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 24 HOUR))");
  $stmt->execute([$d['publisher_type'],$d['contact_name'],$d['email'],$d['title'],$d['work_type'],$d['province'],$d['municipality'],$d['description'],$budget,$dates['start_date'],$dates['deadline'],hash('sha256',$token)]);
  // URL configurada para PRUEBAS; nunca usar cabeceras Host para crear enlaces.
  $base=rtrim((string)($cfg['tender_base_url']??''),'/');
  if(!preg_match('~^https://[a-z0-9.-]+(?:/[a-zA-Z0-9/_-]*)?$~iD',$base))throw new RuntimeException('Falta tender_base_url HTTPS en configuración');
  $url=$base.'/verificar-licitacion.html?token='.$token;
  $sent=sendTenderMail($cfg,$d['email'],'Verifica tu licitación en ConectaObra',"Hola,\n\nPara verificar tu solicitud de licitación, abre este enlace en las próximas 24 horas:\n".$url."\n\nSi no has enviado ninguna solicitud, ignora este correo.\n\nConectaObra");
  if(!$sent){$db->prepare('DELETE FROM private_tenders WHERE id=?')->execute([$db->lastInsertId()]);respond(['ok'=>false,'error'=>'No se pudo enviar el correo de verificación. Inténtalo más tarde.'],503);}
  respond(['ok'=>true,'message'=>'Solicitud recibida. Revisa tu correo y pulsa el enlace de verificación. Después quedará pendiente de aprobación.']);
}catch(Throwable $e){error_log('Tender API: '.$e->getMessage());respond(['ok'=>false,'error'=>'No se pudo completar la solicitud.'],500);}
