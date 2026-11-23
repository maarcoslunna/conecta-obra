<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
function reply(int $status, string $message): never { http_response_code($status); echo json_encode(['ok'=>false,'message'=>$message],JSON_UNESCAPED_UNICODE); exit; }
if (($_SERVER['REQUEST_METHOD']??'')!=='POST') reply(405,'Método no permitido.');
if (!str_starts_with((string)($_SERVER['CONTENT_TYPE']??''),'application/json')) reply(415,'Formato no válido.');
if ((int)($_SERVER['CONTENT_LENGTH']??0)>10000) reply(413,'Solicitud demasiado grande.');
// Same-origin request only; the CSRF check below is mandatory regardless.
$origin=(string)($_SERVER['HTTP_ORIGIN']??'');
if ($origin!=='' && !in_array($origin,['https://www.conectaobra10.es','https://conectaobra10.es'],true)) reply(403,'Origen no autorizado.');
$data=json_decode((string)file_get_contents('php://input'),true);
if (!is_array($data)) reply(400,'Datos no válidos.');
$tenderId=filter_var($data['tender_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
$message=trim((string)($data['message']??''));
$csrf=(string)($data['csrf']??'');
if (!$tenderId || mb_strlen($message)<15 || mb_strlen($message)>2000 || strlen($csrf)!==64 || !ctype_xdigit($csrf)) reply(422,'Comprueba el mensaje y vuelve a intentarlo.');
// Verify company session on the actual production authentication endpoint, server-side.
// No access to production DB and no changes to /public/api.php.
$sessionCookie=(string)($_COOKIE['PHPSESSID']??'');
if ($sessionCookie==='' || !preg_match('/^[A-Za-z0-9,-]{16,128}$/D',$sessionCookie)) reply(401,'Inicia sesión como empresa.');
if (!function_exists('curl_init')) reply(503,'Verificación de sesión no disponible.');
$ch=curl_init('https://www.conectaobra10.es/api.php?action=me');
curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>8,CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_HTTPHEADER=>['Accept: application/json','Cookie: PHPSESSID='.$sessionCookie]]);
$raw=curl_exec($ch);$http=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
if (!is_string($raw)||$http!==200) reply(503,'No se pudo comprobar la sesión.');
$auth=json_decode($raw,true);
if (!is_array($auth)||($auth['ok']??false)!==true||!is_array($auth['user']??null)|| (int)($auth['user']['id']??0)<1) reply(401,'Inicia sesión como empresa.');
if (!hash_equals((string)($auth['csrf']??''),$csrf)) reply(419,'Sesión caducada. Actualiza la página.');
$company=$auth['user'];
$userId=(int)$company['id'];
$name=trim((string)($company['empresa']??''));
$email=trim((string)($company['email']??''));
if ($name===''||mb_strlen($name)>160||!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>190) reply(422,'El perfil de empresa está incompleto.');
try {
 $cfg=require dirname(__DIR__,2).'/config_licitaciones/produccion/config.php';
 if (!is_array($cfg)||($cfg['dbname']??'')!==($cfg['expected_dbname'] ?? '__sin_configurar__')||($cfg['staging_only'] ?? false) !== false) throw new RuntimeException('Invalid production config');
 $dsn=sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',$cfg['host'],(int)$cfg['port'],$cfg['dbname']);
 $db=new PDO($dsn,$cfg['user'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
 $stmt=$db->prepare("SELECT id FROM private_tenders WHERE id=? AND status='approved' LIMIT 1");$stmt->execute([$tenderId]);
 if (!$stmt->fetchColumn()) reply(404,'Esta licitación no está disponible.');
 $stmt=$db->prepare("INSERT INTO private_tender_interests (tender_id,company_user_id,company_name,company_email,message,status) VALUES (?,?,?,?,?,'pending')");
 $stmt->execute([$tenderId,$userId,$name,$email,$message]);
 echo json_encode(['ok'=>true,'message'=>'Solicitud enviada a ConectaObra para su revisión.'],JSON_UNESCAPED_UNICODE);
} catch(PDOException $e){
 if (($e->errorInfo[1]??0)==1062) reply(409,'Tu empresa ya ha mostrado interés por esta licitación.');
 error_log('Tender interest DB error: '.$e->getMessage());reply(500,'No se pudo guardar la solicitud.');
} catch(Throwable $e){error_log('Tender interest error: '.get_class($e));reply(500,'Servicio temporalmente no disponible.');}
