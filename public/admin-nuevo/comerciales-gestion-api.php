<?php
declare(strict_types=1);

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax'
]);

session_start();

function responder(array $datos, int $estado = 200): never {
    http_response_code($estado);
    echo json_encode($datos);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responder(['ok'=>false,'error'=>'Metodo no permitido'],405);
}

if (empty($_SESSION['admin'])) {
    responder(['ok'=>false,'error'=>'No autorizado'],403);
}

$adminEmail = $_SESSION['admin_email'] ?? null;

if (!is_string($adminEmail) ||
    !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
    responder(['ok'=>false,'error'=>'Identidad administrativa no disponible'],403);
}

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$host = $_SERVER['HTTP_HOST'] ?? '';

if (!is_string($origin) || $origin === '' ||
    parse_url($origin, PHP_URL_HOST) !== $host ||
    parse_url($origin, PHP_URL_SCHEME) !== 'https') {
    responder(['ok'=>false,'error'=>'Origen no autorizado'],403);
}

$tipo = $_SERVER['CONTENT_TYPE'] ?? '';

if (stripos($tipo, 'application/json') !== 0) {
    responder(['ok'=>false,'error'=>'Contenido no permitido'],415);
}

$datos = json_decode(file_get_contents('php://input') ?: '{}',true);

if (!is_array($datos)) {
    responder(['ok'=>false,'error'=>'JSON no valido'],422);
}

// CONECTAOBRA_CSRF_COMERCIAL_V1
$csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

if (!is_string($csrf) ||
    !isset($_SESSION['commercial_csrf']) ||
    !is_string($_SESSION['commercial_csrf']) ||
    !hash_equals($_SESSION['commercial_csrf'], $csrf)) {
    responder(['ok'=>false,'error'=>'Token de seguridad no valido'],403);
}

$accion = $datos['action'] ?? '';

if ($accion === 'sales_account_invitation') {
    define('CO_SALES_INVITATION_HANDLER', true);
    require __DIR__ . '/comercial-invitacion.php';
    exit;
}
$permitidas = [
    'sales_agent_create',
    'sales_agent_status',
    'sales_agent_territory',
    'sales_lead_assign',
    'sales_agent_delete'
];

if ($accion === 'sales_freelance_create') {
    try {
        $config = require dirname(__DIR__) . '/config.php';
        $c = $config['db'] ?? $config;
        $dsn = 'mysql:host=' . $c['host']
             . ';port=' . ($c['port'] ?? 3306)
             . ';dbname=' . $c['dbname']
             . ';charset=' . ($c['charset'] ?? 'utf8mb4');

        $db = new PDO($dsn, $c['user'], $c['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);

        require_once dirname(__DIR__) .
            '/mega-bloque/comerciales/sales_admin_service.php';

        $servicio = new ConectaobraSalesAdminService($db);
        if (isset($datos['phone']) && !is_string($datos['phone'])) {
            responder(['ok'=>false,'error'=>'Número de teléfono no válido'],422);
        }
        $id = $servicio->createFreelance(
            $adminEmail,
            (string)($datos['full_name'] ?? ''),
            (string)($datos['email'] ?? ''),
            (string)($datos['territory'] ?? ''),
            (string)($datos['phone'] ?? '')
        );

        responder(['ok'=>true, 'agent_id'=>$id]);
    } catch (InvalidArgumentException $ex) {
        responder(['ok'=>false,'error'=>'Datos freelance no validos'],422);
    } catch (PDOException $ex) {
        error_log('ConectaObra freelance create database failure');
        responder(['ok'=>false,'error'=>'No se pudo registrar el comercial'],409);
    } catch (Throwable $ex) {
        error_log('ConectaObra freelance create failure');
        responder(['ok'=>false,'error'=>'Alta comercial no disponible'],500);
    }
}

if ($accion === 'sales_agent_create') {
    responder([
        'ok'=>false,
        'error'=>'Altas suspendidas: comerciales y empresas son entidades distintas'
    ],403);
}

if (!is_string($accion) || !in_array($accion,$permitidas,true)) {
    responder(['ok'=>false,'error'=>'Accion no permitida'],403);
}

try {
    $config = require dirname(__DIR__) . '/config.php';
    $c = $config['db'] ?? $config;

    $dsn = 'mysql:host=' . $c['host']
         . ';port=' . ($c['port'] ?? 3306)
         . ';dbname=' . $c['dbname']
         . ';charset=' . ($c['charset'] ?? 'utf8mb4');

    $db = new PDO($dsn,$c['user'],$c['password'],[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
    ]);

    if ($accion === 'sales_agent_delete') {
        $agentId = filter_var($datos['agent_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$agentId || $agentId <= 0) {
            responder(['ok'=>false,'error'=>'Comercial no válido'],422);
        }

        $db->beginTransaction();
        try {
            $agent = $db->prepare('SELECT id FROM sales_agents WHERE id = ? FOR UPDATE');
            $agent->execute([$agentId]);
            if (!$agent->fetchColumn()) {
                $db->rollBack();
                responder(['ok'=>false,'error'=>'El comercial ya no existe'],404);
            }

            $history = [
                'sales_leads' => 'fichas comerciales o empresas asignadas',
                'sales_commissions' => 'comisiones',
                'sales_commission_incidents' => 'incidencias de comisión',
                'sales_settlements' => 'liquidaciones',
                'sales_settlement_invoices' => 'facturas de liquidación',
                'sales_settlement_items' => 'partidas de liquidación',
            ];
            $found = [];
            foreach ($history as $table => $label) {
                $check = $db->prepare("SELECT 1 FROM `$table` WHERE agent_id = ? LIMIT 1 FOR UPDATE");
                $check->execute([$agentId]);
                if ($check->fetchColumn()) $found[] = $label;
            }
            if ($found) {
                $db->rollBack();
                responder([
                    'ok'=>false,
                    'error'=>'Este comercial tiene registros asociados: '.implode(', ', $found).'.',
                ],409);
            }

            $account = $db->prepare('DELETE FROM sales_agent_accounts WHERE agent_id = ?');
            $account->execute([$agentId]);
            $remove = $db->prepare('DELETE FROM sales_agents WHERE id = ?');
            $remove->execute([$agentId]);
            if ($remove->rowCount() !== 1) {
                throw new RuntimeException('No se pudo eliminar la ficha del comercial.');
            }

            $db->commit();
            responder(['ok'=>true,'message'=>'Comercial eliminado']);
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            if ($error instanceof PDOException && (int)($error->errorInfo[1] ?? 0) === 1451) {
                responder(['ok'=>false,'error'=>'Hay registros asociados que impiden eliminar el comercial.'],409);
            }
            throw $error;
        }
    }

    if ($accion === 'sales_lead_assign') {
        $agentId = filter_var($datos['agent_id'] ?? null, FILTER_VALIDATE_INT);
        $companyId = filter_var($datos['company_id'] ?? null, FILTER_VALIDATE_INT);
        if ($agentId === false || $agentId <= 0 || $companyId === false || $companyId <= 0) {
            responder(['ok'=>false,'error'=>'Selecciona un comercial y una empresa válidos'],422);
        }

        $db->beginTransaction();
        try {
            $guardar = $db->prepare(
                "INSERT INTO sales_leads (agent_id, company_id, company_name, status)
                 SELECT a.id, u.id, LEFT(COALESCE(NULLIF(TRIM(u.`empresa`), ''), ''), 200), 'registered'
                 FROM users u JOIN sales_agents a ON a.id = ?
                 WHERE u.id = ? AND COALESCE(NULLIF(TRIM(u.`empresa`), ''), '') <> ''
                 ON DUPLICATE KEY UPDATE id = sales_leads.id"
            );
            $guardar->execute([$agentId, $companyId]);

            $consulta = $db->prepare(
                "SELECT id, agent_id FROM sales_leads WHERE company_id = ? FOR UPDATE"
            );
            $consulta->execute([$companyId]);
            $lead = $consulta->fetch();

            if (!$lead || (int)$lead['agent_id'] !== $agentId) {
                $db->rollBack();
                if ($lead) {
                    responder(['ok'=>false,'error'=>'La empresa ya está asignada a otro comercial'],409);
                }
                responder(['ok'=>false,'error'=>'Empresa registrada o comercial no encontrado'],404);
            }

            $db->commit();
            responder(['ok'=>true,'lead_id'=>$lead['id']]);
        } catch (Throwable $ex) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $ex;
        }
    }

    require_once dirname(__DIR__) .
        '/mega-bloque/comerciales/sales_api.php';

    $api = new ConectaobraSalesApi($db);
    responder($api->handle($accion,$datos,$adminEmail));

} catch (InvalidArgumentException $e) {
    responder(['ok'=>false,'error'=>'Datos comerciales no validos'],422);
} catch (Throwable $e) {
    error_log('ConectaObra commercial management: ' . $e->getMessage());
    responder(['ok'=>false,'error'=>'Operacion comercial no disponible'],500);
}
