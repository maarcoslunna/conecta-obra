<?php
declare(strict_types=1);
ini_set('display_errors','0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS']==='off') {
    http_response_code(403);
    exit('HTTPS obligatorio');
}
session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
session_start();
function responderDocumento(array $data, int $status=200): never {
    http_response_code($status);
    echo json_encode($data,JSON_UNESCAPED_UNICODE);
    exit;
}
if (empty($_SESSION['admin']) || !is_string($_SESSION['admin_email'] ?? null)) {
    responderDocumento(['ok'=>false,'error'=>'No autorizado.'],403);
}
$metodo = $_SERVER['REQUEST_METHOD'] ?? '';
if (!in_array($metodo,['GET','POST'],true)) responderDocumento(['ok'=>false,'error'=>'Método no permitido.'],405);
$id = filter_var($_GET['agent_id'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
if ($id === false) responderDocumento(['ok'=>false,'error'=>'Comercial no válido.'],422);
if ($metodo === 'POST') {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    if (!is_string($token) || !is_string($_SESSION['commercial_csrf'] ?? null) ||
        !hash_equals($_SESSION['commercial_csrf'],$token)) {
        responderDocumento(['ok'=>false,'error'=>'La sesión ha caducado. Recarga el administrador.'],403);
    }
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== 'https://'.($_SERVER['HTTP_HOST'] ?? '')) {
        responderDocumento(['ok'=>false,'error'=>'Origen no autorizado.'],403);
    }
}
$db = null;
try {
    $config = require dirname(__DIR__).'/config.php';
    $c = $config['db'] ?? $config;
    $db = new PDO('mysql:host='.$c['host'].';port='.($c['port'] ?? 3306).
        ';dbname='.$c['dbname'].';charset=utf8mb4',$c['user'],$c['password'],
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    if ($metodo === 'GET') {
        if (isset($_GET['document_id'])) {
            $documentoId = filter_var($_GET['document_id'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if ($documentoId === false) responderDocumento(['ok'=>false,'error'=>'Documento no encontrado.'],404);
            $q = $db->prepare('SELECT pdf FROM sales_commercial_documents WHERE id=? AND agent_id=?');
            $q->execute([$documentoId,$id]);
            $bytes = $q->fetchColumn();
            if ($bytes === false) responderDocumento(['ok'=>false,'error'=>'Documento no encontrado.'],404);
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="documento-comercial-'.$documentoId.'.pdf"');
            echo $bytes;
            exit;
        }
        $q = $db->prepare('SELECT id,document_name,created_at FROM sales_commercial_documents WHERE agent_id=? ORDER BY id DESC');
        $q->execute([$id]);
        responderDocumento(['ok'=>true,'documents'=>$q->fetchAll(PDO::FETCH_ASSOC)]);
    }
    $archivo = $_FILES['pdf'] ?? null;
    $nombre = $_POST['document_name'] ?? null;
    if (!is_string($nombre) || trim($nombre)==='' || mb_strlen($nombre)>190 ||
        !is_array($archivo) || ($archivo['error'] ?? null)!==UPLOAD_ERR_OK ||
        !is_string($archivo['tmp_name'] ?? null) || !is_uploaded_file($archivo['tmp_name'])) {
        responderDocumento(['ok'=>false,'error'=>'Indica un nombre y selecciona un PDF.'],422);
    }
    $ruta = $archivo['tmp_name'];
    $size = filesize($ruta);
    if ($size === false || $size<5 || $size>5*1024*1024 ||
        (new finfo(FILEINFO_MIME_TYPE))->file($ruta)!=='application/pdf' ||
        file_get_contents($ruta,false,null,0,5)!=='%PDF-') {
        responderDocumento(['ok'=>false,'error'=>'Selecciona un PDF de hasta 5 MB.'],422);
    }
    $bytes = file_get_contents($ruta);
    if ($bytes === false) throw new RuntimeException('PDF no legible');
    $hash = hash('sha256',$bytes);
    $db->beginTransaction();
    $q = $db->prepare('SELECT id FROM sales_agents WHERE id=? FOR UPDATE');
    $q->execute([$id]);
    if (!$q->fetchColumn()) {
        $db->rollBack();
        responderDocumento(['ok'=>false,'error'=>'Comercial no encontrado.'],404);
    }
    $q = $db->prepare('SELECT id FROM sales_commercial_documents WHERE agent_id=? AND content_hash=?');
    $q->execute([$id,$hash]);
    if (!$q->fetchColumn()) {
        $q = $db->prepare('INSERT INTO sales_commercial_documents
            (agent_id,document_name,content_hash,pdf,uploaded_by,created_at) VALUES (?,?,?,?,?,UTC_TIMESTAMP())');
        $q->bindValue(1,$id,PDO::PARAM_INT);
        $q->bindValue(2,trim($nombre));
        $q->bindValue(3,$hash);
        $q->bindValue(4,$bytes,PDO::PARAM_LOB);
        $q->bindValue(5,$_SESSION['admin_email']);
        $q->execute();
    }
    $db->commit();
    responderDocumento(['ok'=>true]);
} catch (Throwable $e) {
    if ($db instanceof PDO && $db->inTransaction()) $db->rollBack();
    error_log('ConectaObra: fallo de documentación de comercial');
    responderDocumento(['ok'=>false,'error'=>'No se pudo completar la operación. Comprueba el listado antes de repetirla.'],500);
}

