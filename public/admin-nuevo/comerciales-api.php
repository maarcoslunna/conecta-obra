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

if (empty($_SESSION['admin'])) {
    http_response_code(403);
    echo json_encode(['ok'=>false,'error'=>'No autorizado']);
    exit;
}


// CO_ADMIN_PUBLICAS_STATUS_V1
if (isset($_GET['publicas_estado'])) {
    try {
        $publicas = require '/home/www/conectaobra_publicas_sync/conexion_publicas.php';

        if (!$publicas instanceof PDO) {
            throw new RuntimeException('Conexión no disponible');
        }

        $sql = "SELECT COUNT(*) AS total,
                COALESCE(SUM(
                    deleted = 0
                    AND status_code = 'PUB'
                    AND deadline >= CURDATE()
                    AND official_url LIKE 'https://%'
                ), 0) AS abiertas
                FROM co_licitaciones_publicas";

        $datos = $publicas->query($sql)->fetch(PDO::FETCH_ASSOC);

        $archivo = '/home/www/conectaobra_publicas_sync/logs/estatal_v3_checkpoint.json';
        $fecha = is_file($archivo) ? filemtime($archivo) : false;

        echo json_encode([
            'ok' => true,
            'total' => (int)$datos['total'],
            'abiertas' => (int)$datos['abiertas'],
            'ultimo_checkpoint' => $fecha ? date('c', $fecha) : null
        ]);
    } catch (Throwable $e) {
        error_log('Admin publicas: ' . $e->getMessage());
        http_response_code(503);
        echo json_encode([
            'ok' => false,
            'error' => 'Consulta de licitaciones públicas no disponible'
        ]);
    }
    exit;
}


try {
    $config = require dirname(__DIR__) . '/config.php';
    $c = $config['db'] ?? $config;

    $dsn = 'mysql:host=' . $c['host']
         . ';port=' . ($c['port'] ?? 3306)
         . ';dbname=' . $c['dbname']
         . ';charset=' . ($c['charset'] ?? 'utf8mb4');

    $db = new PDO($dsn, $c['user'], $c['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    

    // CO_ADMIN_EMPRESAS_KPIS_V1
    if (isset($_GET['empresas_estado'])) {
        $datos = $db->query("
            SELECT
                COUNT(*) AS total,
                COALESCE(SUM(
                    founder_company = 1 AND founder_status = 'active'
                ), 0) AS fundadoras,
                COALESCE(SUM(
                    strategic_company = 1
                    AND strategic_status = 'active'
                    AND LOWER(strategic_plan) = 'premium'
                ), 0) AS premium_estrategicas,
                COALESCE(SUM(
                    COALESCE(founder_company,0) = 0
                    AND COALESCE(strategic_company,0) = 0
                ), 0) AS sin_plan_especial
            FROM users
        ")->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'ok' => true,
            'total' => (int)$datos['total'],
            'fundadoras' => (int)$datos['fundadoras'],
            'premium_estrategicas' => (int)$datos['premium_estrategicas'],
            'sin_plan_especial' => (int)$datos['sin_plan_especial']
        ]);
        exit;
    }

    $agentes = $db->query(
        "SELECT id, user_id, territory, status, created_at
         FROM sales_agents
         ORDER BY id DESC
         LIMIT 100"
    )->fetchAll();


    // CO_ADMIN_SALES_DETAIL_V1
    if (isset($_GET['agent_id'])) {
        $agentId = filter_var($_GET['agent_id'], FILTER_VALIDATE_INT);
        if (!$agentId || $agentId <= 0) {
            http_response_code(400);
            echo json_encode(['ok'=>false,'error'=>'Identificador inválido']);
            exit;
        }

        $s = $db->prepare(
            "SELECT a.id, a.territory, a.status,
                    c.full_name, c.email, c.phone, c.account_status
             FROM sales_agents a
             LEFT JOIN sales_agent_accounts c ON c.agent_id = a.id
             WHERE a.id = ? LIMIT 1"
        );
        $s->execute([$agentId]);
        $agente = $s->fetch();

        if (!$agente) {
            http_response_code(404);
            echo json_encode(['ok'=>false,'error'=>'Comercial no encontrado']);
            exit;
        }

        $s = $db->prepare(
            "SELECT id, company_name, status, created_at
             FROM sales_leads WHERE agent_id = ?
             ORDER BY id DESC LIMIT 200"
        );
        $s->execute([$agentId]);
        $oportunidades = $s->fetchAll();

        $s = $db->prepare(
            "SELECT id, amount_cents, status, created_at
             FROM sales_commissions WHERE agent_id = ?
             ORDER BY id DESC LIMIT 200"
        );
        $s->execute([$agentId]);
        $comisionesDetalle = $s->fetchAll();

        $s = $db->prepare(
            "SELECT COALESCE(SUM(amount_cents),0)
             FROM sales_commissions
             WHERE agent_id = ? AND status = 'pending'"
        );
        $s->execute([$agentId]);

        echo json_encode([
            'ok'=>true,
            'agente'=>$agente,
            'oportunidades'=>$oportunidades,
            'comisiones'=>$comisionesDetalle,
            'pendiente_cents'=>(int)$s->fetchColumn()
        ]);
        exit;
    }

    $liquidaciones = $db->query(
        "SELECT *
         FROM sales_settlements
         ORDER BY id DESC
         LIMIT 50"
    )->fetchAll();

    $comisiones = (int)$db->query(
        "SELECT COUNT(*) FROM sales_commissions"
    )->fetchColumn();

    // CO_ADMIN_KPIS_V1
    $totalAgentes = (int)$db->query(
        "SELECT COUNT(*) FROM sales_agents"
    )->fetchColumn();
    $totalLeads = (int)$db->query(
        "SELECT COUNT(*) FROM sales_leads"
    )->fetchColumn();
    $pendiente = (int)$db->query(
        "SELECT COALESCE(SUM(amount_cents),0)
         FROM sales_commissions WHERE status='pending'"
    )->fetchColumn();

    echo json_encode([
        'ok' => true,
        'kpis' => [
            'agentes' => $totalAgentes,
            'oportunidades' => $totalLeads,
            'pendiente_cents' => $pendiente
        ],
        
        'agentes' => $agentes,
        'liquidaciones' => $liquidaciones,
        'comisiones_total' => $comisiones
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok'=>false,
        'error'=>'No se pudieron consultar los comerciales'
    ]);
}
