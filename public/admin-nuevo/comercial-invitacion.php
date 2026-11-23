<?php
declare(strict_types=1);

if (!defined('CO_SALES_INVITATION_HANDLER')) {
    http_response_code(404);
    exit;
}

$idInvitacion = filter_var($datos['agent_id'] ?? null, FILTER_VALIDATE_INT, [
    'options'=>['min_range'=>1]
]);
if ($idInvitacion === false) {
    responder(['ok'=>false,'error'=>'Comercial no válido.'],422);
}

$dbInvitacion = null;
try {
    $configInvitacion = require dirname(__DIR__).'/config.php';
    $cInvitacion = $configInvitacion['db'] ?? $configInvitacion;
    $dbInvitacion = new PDO(
        'mysql:host='.$cInvitacion['host'].
        ';port='.($cInvitacion['port'] ?? 3306).
        ';dbname='.$cInvitacion['dbname'].';charset=utf8mb4',
        $cInvitacion['user'], $cInvitacion['password'],
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]
    );

    $dbInvitacion->beginTransaction();
    $buscarInvitacion = $dbInvitacion->prepare(
        'SELECT id,account_status FROM sales_agent_accounts
         WHERE agent_id=? ORDER BY id LIMIT 1 FOR UPDATE'
    );
    $buscarInvitacion->execute([$idInvitacion]);
    $cuentaInvitacion = $buscarInvitacion->fetch(PDO::FETCH_ASSOC);

    if (!$cuentaInvitacion || $cuentaInvitacion['account_status'] !== 'pending') {
        $dbInvitacion->rollBack();
        responder([
            'ok'=>false,
            'error'=>'Solo se pueden generar invitaciones para cuentas pendientes.'
        ],409);
    }

    $tokenInvitacion = bin2hex(random_bytes(32));
    $guardarInvitacion = $dbInvitacion->prepare(
        'INSERT INTO sales_account_invitations
         (account_id,token_hash,expires_at,issued_by,created_at)
         VALUES (?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 48 HOUR),?,UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE
         token_hash=VALUES(token_hash),
         expires_at=VALUES(expires_at),
         issued_by=VALUES(issued_by),
         created_at=VALUES(created_at)'
    );
    $guardarInvitacion->execute([
        $cuentaInvitacion['id'],
        hash('sha256', $tokenInvitacion),
        $adminEmail
    ]);
    $dbInvitacion->commit();

    responder([
        'ok'=>true,
        'activation_url'=>'https://conectaobra10.es/comercial/activar.php#token='.$tokenInvitacion,
        'message'=>'Invitación generada. Caduca en 48 horas. El enlace anterior queda sustituido.'
    ]);
} catch (Throwable $e) {
    if ($dbInvitacion instanceof PDO && $dbInvitacion->inTransaction()) {
        $dbInvitacion->rollBack();
    }
    error_log('ConectaObra: fallo al generar invitación comercial');
    responder(['ok'=>false,'error'=>'No se pudo generar la invitación.'],500);
}
