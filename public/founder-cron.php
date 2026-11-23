<?php
// Cron de recordatorios para Empresas Fundadoras.
// Configura este script como tarea cron una vez al día.
// No contiene claves secretas; carga config.php del mismo directorio.
declare(strict_types=1);
$config = require __DIR__ . '/config.php';

try {
    $dsn = "mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}";
    $db = new PDO($dsn, (string)$config['user'], (string)$config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Throwable $e) {
    error_log('ConectaObra founder cron DB error: '.$e->getMessage());
    exit(1);
}

$now = new DateTimeImmutable('now');
$rows = $db->query("SELECT id,empresa,email,founder_expires_at,founder_reminder_30_sent,founder_reminder_7_sent,founder_reminder_1_sent FROM users WHERE founder_company=1 AND founder_status='active' AND founder_expires_at IS NOT NULL")->fetchAll();

foreach ($rows as $u) {
    try {
        $expires = new DateTimeImmutable($u['founder_expires_at']);
        $days = (int)$now->diff($expires)->format('%r%a');

        if ($days < 0) {
            $db->prepare("UPDATE users SET founder_status='expired',updated_at=? WHERE id=? AND founder_status='active'")
               ->execute([$now->format('Y-m-d H:i:s'), $u['id']]);
            continue;
        }

        $kind = null;
        $column = null;
        if ($days <= 1 && !(int)$u['founder_reminder_1_sent']) {
            $kind = '1 día'; $column = 'founder_reminder_1_sent';
        } elseif ($days <= 7 && !(int)$u['founder_reminder_7_sent']) {
            $kind = '7 días'; $column = 'founder_reminder_7_sent';
        } elseif ($days <= 30 && !(int)$u['founder_reminder_30_sent']) {
            $kind = '30 días'; $column = 'founder_reminder_30_sent';
        }

        if ($kind === null) continue;

        $subject = 'Tu promoción de Empresa Fundadora finaliza en '.$kind;
        $body = "Hola {$u['empresa']},\n\n"
              . "Tu promoción de Empresa Fundadora de ConectaObra finaliza el "
              . $expires->format('d/m/Y') . ".\n\n"
              . "Durante estos 12 meses has disfrutado del Plan Profesional gratis. "
              . "Antes de la fecha de finalización puedes elegir continuar con Profesional (49 €/mes o 499 €/año), "
              . "pasar a Premium (89 €/mes o 999 €/año) o volver al plan Gratuito.\n\n"
              . "No se realizará ningún cobro automático por esta promoción.\n\n"
              . "Accede a tu panel para gestionar tu plan:\n"
              . "https://conectaobra10.es/panel.html\n\n"
              . "ConectaObra\ninfo@conectaobra10.es";

        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'From: ConectaObra <info@conectaobra10.es>',
            'Reply-To: info@conectaobra10.es'
        ];
        $sent = mail($u['email'], '=?UTF-8?B?'.base64_encode($subject).'?=', $body, implode("\r\n",$headers));
        if ($sent) {
            $db->prepare("UPDATE users SET {$column}=1,updated_at=? WHERE id=?")
               ->execute([$now->format('Y-m-d H:i:s'), $u['id']]);
        } else {
            error_log('ConectaObra founder reminder mail error for user '.$u['id']);
        }
    } catch (Throwable $e) {
        error_log('ConectaObra founder cron user error: '.$e->getMessage());
    }
}
