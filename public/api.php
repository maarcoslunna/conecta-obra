<?php
declare(strict_types=1);

$config = require __DIR__ . '/config.php';
// Stripe Price IDs públicos (no son claves secretas). Se mantienen fuera de config.php para facilitar su despliegue.
$stripePricesFile = __DIR__ . '/stripe-prices.php';
if (is_file($stripePricesFile)) {
    $stripePrices = require $stripePricesFile;
    if (is_array($stripePrices)) { $config['stripe_prices'] = array_merge($config['stripe_prices'] ?? [], $stripePrices); }
}

$isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

function out(array $x, int $status = 200): never {
    http_response_code($status);
    echo json_encode($x, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function body(): array {
    $raw = file_get_contents('php://input');
    $d = json_decode($raw ?: '{}', true);
    return is_array($d) ? $d : [];
}
function db(array $config): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    if (empty($config['dbname']) || str_starts_with($config['dbname'], 'PON_AQUI')) {
        out(['ok'=>false,'error'=>'La base de datos aún no está configurada en config.php.'], 500);
    }
    try {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $config['host'], (int)$config['port'], $config['dbname'], $config['charset']);
        $pdo = new PDO($dsn, $config['user'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        return $pdo;
    } catch (PDOException $e) {
        error_log('ConectaObra DB error: ' . $e->getMessage());
        out(['ok'=>false,'error'=>'No se pudo conectar con la base de datos. Revisa la configuración del servidor.'], 500);
    }
}
function smtpRead($fp): string {
    $response = '';
    while (($line = fgets($fp, 515)) !== false) {
        $response .= $line;
        if (strlen($line) >= 4 && $line[3] === ' ') break;
    }
    return $response;
}
function smtpCommand($fp, string $command, array $expected): string {
    if ($command !== '') fwrite($fp, $command . "\r\n");
    $response = smtpRead($fp); $code = (int)substr($response, 0, 3);
    if (!in_array($code, $expected, true)) throw new RuntimeException('SMTP error ' . $code);
    return $response;
}
function smtpSend(array $config,string $to,string $subject,string $content,string $replyTo): bool {
    $host=trim((string)($config['smtp_host']??''));$port=(int)($config['smtp_port']??465);$user=trim((string)($config['smtp_user']??''));$pass=(string)($config['smtp_password']??'');
    if($host===''||$user===''||$pass===''){error_log('SMTP: configuración incompleta');return false;}
    if(!filter_var($to,FILTER_VALIDATE_EMAIL)||!filter_var($replyTo,FILTER_VALIDATE_EMAIL))return false;
    $context=stream_context_create(['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'peer_name'=>$host]]);$errno=0;$errstr='';
    $fp=@stream_socket_client('ssl://'.$host.':'.$port,$errno,$errstr,15,STREAM_CLIENT_CONNECT,$context);if(!$fp){error_log('SMTP conexión fallida: '.$errno);return false;}stream_set_timeout($fp,15);
    try{smtpCommand($fp,'',[220]);smtpCommand($fp,'EHLO conectaobra10.es',[250]);smtpCommand($fp,'AUTH LOGIN',[334]);smtpCommand($fp,base64_encode($user),[334]);smtpCommand($fp,base64_encode($pass),[235]);smtpCommand($fp,'MAIL FROM:<'.$user.'>',[250]);smtpCommand($fp,'RCPT TO:<'.$to.'>',[250,251]);smtpCommand($fp,'DATA',[354]);
        $encodedSubject='=?UTF-8?B?'.base64_encode($subject).'?=';$headers=['Date: '.date(DATE_RFC2822),'From: ConectaObra <'.$user.'>','To: <'.str_replace(["\r","\n"],'',$to).'>','Reply-To: <'.str_replace(["\r","\n"],'',$replyTo).'>','Subject: '.$encodedSubject,'MIME-Version: 1.0','Content-Type: text/plain; charset=UTF-8','Content-Transfer-Encoding: 8bit'];
        $body=str_replace(["\r\n","\r"],"\n",$content);$body=str_replace("\n","\r\n",$body);$body=preg_replace('/^\./m','..',$body);fwrite($fp,implode("\r\n",$headers)."\r\n\r\n".$body."\r\n.\r\n");$response=smtpRead($fp);if((int)substr($response,0,3)!==250)throw new RuntimeException('SMTP DATA error');@fwrite($fp,"QUIT\r\n");@fclose($fp);return true;
    }catch(Throwable $e){error_log('ConectaObra SMTP: '.$e->getMessage());if(is_resource($fp)){@fwrite($fp,"QUIT\r\n");@fclose($fp);}return false;}
}
function ensureSchema(PDO $db): void {
    static $done = false;
    if ($done) return;
    $db->exec("CREATE TABLE IF NOT EXISTS users (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT,
      empresa VARCHAR(160) NOT NULL,
      cif VARCHAR(30) NOT NULL,
      contacto VARCHAR(160) NOT NULL,
      email VARCHAR(190) NOT NULL,
      telefono VARCHAR(40) NOT NULL,
      provincia VARCHAR(100) NOT NULL,
      tipo VARCHAR(100) NOT NULL,
      especialidad VARCHAR(190) NOT NULL,
      zona VARCHAR(255) NULL,
      password_hash VARCHAR(255) NOT NULL,
      descripcion TEXT NULL,
      web VARCHAR(255) NULL,
      created_at DATETIME NOT NULL,
      updated_at DATETIME NOT NULL,
      PRIMARY KEY (id), UNIQUE KEY uq_users_cif (cif), UNIQUE KEY uq_users_email (email),
      KEY idx_users_provincia (provincia), KEY idx_users_tipo (tipo), KEY idx_users_especialidad (especialidad)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec("CREATE TABLE IF NOT EXISTS opportunities (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT,
      user_id INT UNSIGNED NOT NULL,
      title VARCHAR(190) NOT NULL,
      province VARCHAR(100) NOT NULL,
      category VARCHAR(120) NOT NULL,
      description TEXT NOT NULL,
      status ENUM('active','closed','draft') NOT NULL DEFAULT 'active',
      created_at DATETIME NOT NULL,
      updated_at DATETIME NULL,
      PRIMARY KEY (id), KEY idx_opp_status_created (status, created_at), KEY idx_opp_province (province), KEY idx_opp_user_status (user_id,status),
      CONSTRAINT fk_opp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // Compatible con instalaciones anteriores: añade updated_at si la tabla ya existía sin esa columna.
    try { $db->exec("ALTER TABLE opportunities ADD COLUMN updated_at DATETIME NULL AFTER created_at"); } catch (PDOException $e) { /* ya existe */ }

    // Programa de Empresas Fundadoras: 20 plazas únicas, no reutilizables.
    foreach ([
      "ALTER TABLE users ADD COLUMN founder_company TINYINT(1) NOT NULL DEFAULT 0 AFTER web",
      "ALTER TABLE users ADD COLUMN founder_started_at DATETIME NULL AFTER founder_company",
      "ALTER TABLE users ADD COLUMN founder_expires_at DATETIME NULL AFTER founder_started_at",
      "ALTER TABLE users ADD COLUMN founder_status VARCHAR(20) NOT NULL DEFAULT 'none' AFTER founder_expires_at",
      "ALTER TABLE users ADD COLUMN founder_reminder_30_sent TINYINT(1) NOT NULL DEFAULT 0 AFTER founder_status",
      "ALTER TABLE users ADD COLUMN founder_reminder_7_sent TINYINT(1) NOT NULL DEFAULT 0 AFTER founder_reminder_30_sent",
      "ALTER TABLE users ADD COLUMN founder_reminder_1_sent TINYINT(1) NOT NULL DEFAULT 0 AFTER founder_reminder_7_sent"
    ] as $alter) { try { $db->exec($alter); } catch (PDOException $e) { /* ya existe */ } }
    $db->exec("CREATE TABLE IF NOT EXISTS founder_slots (
      slot_no TINYINT UNSIGNED NOT NULL,
      user_id INT UNSIGNED NULL,
      claimed_at DATETIME NULL,
      PRIMARY KEY (slot_no),
      UNIQUE KEY uq_founder_slot_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // Inicializa las 20 plazas sin reutilizarlas. Se hace una inserción por plaza
    // para evitar incompatibilidades de parámetros con PDO/MariaDB en hostings compartidos.
    $slotStmt=$db->prepare("INSERT IGNORE INTO founder_slots (slot_no) VALUES (?)");
    for($i=1;$i<=20;$i++) $slotStmt->execute([$i]);

    // Empresas invitadas: registros públicos separados de las cuentas de usuario.
    $db->exec("CREATE TABLE IF NOT EXISTS invited_companies (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT, company VARCHAR(160) NOT NULL, cif VARCHAR(30) NOT NULL,
      contacto VARCHAR(160) NULL, email VARCHAR(190) NULL, telefono VARCHAR(40) NULL,
      address VARCHAR(255) NULL, province VARCHAR(100) NOT NULL, tipo VARCHAR(100) NOT NULL DEFAULT 'Empresa Invitada',
      especialidad VARCHAR(190) NULL, zona VARCHAR(255) NULL, descripcion TEXT NULL, web VARCHAR(255) NULL,
      status VARCHAR(30) NOT NULL DEFAULT 'invited', invitation_date DATETIME NOT NULL, registration_date DATETIME NULL,
      user_id INT UNSIGNED NULL, source VARCHAR(40) NOT NULL DEFAULT 'manual', PRIMARY KEY (id),
      UNIQUE KEY uq_invited_cif (cif), KEY idx_invited_province (province), KEY idx_invited_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // V4.40: limpia el texto provisional histórico una vez que la empresa ya está registrada.
    // Algunas empresas invitadas antiguas heredaron esta frase como descripción real.
    $legacyInviteDescription='Esta empresa está invitada a ConectaObra y todavía no ha activado su perfil.';
    $db->prepare("UPDATE users SET descripcion='' WHERE TRIM(descripcion)=?")->execute([$legacyInviteDescription]);
    $db->prepare("UPDATE invited_companies SET descripcion='' WHERE status='registered' AND TRIM(descripcion)=?")->execute([$legacyInviteDescription]);

    // Datos de contacto para empresas invitadas (compatibilidad con instalaciones anteriores).
    foreach ([
      "ALTER TABLE invited_companies ADD COLUMN contacto VARCHAR(160) NULL AFTER cif",
      "ALTER TABLE invited_companies ADD COLUMN email VARCHAR(190) NULL AFTER contacto",
      "ALTER TABLE invited_companies ADD COLUMN telefono VARCHAR(40) NULL AFTER email"
    ] as $alter) { try { $db->exec($alter); } catch (PDOException $e) { /* ya existe */ } }

    // Invitaciones seguras: el token se guarda únicamente como hash y caduca.
    $db->exec("CREATE TABLE IF NOT EXISTS company_invitations (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT,
      invited_company_id INT UNSIGNED NOT NULL,
      email_destino VARCHAR(190) NOT NULL,
      contacto VARCHAR(160) NULL,
      token_hash CHAR(64) NOT NULL,
      founder_slot_no TINYINT UNSIGNED NULL,
      status VARCHAR(20) NOT NULL DEFAULT 'pending',
      sent_at DATETIME NULL,
      expires_at DATETIME NOT NULL,
      accepted_at DATETIME NULL,
      created_at DATETIME NOT NULL,
      updated_at DATETIME NOT NULL,
      PRIMARY KEY (id),
      UNIQUE KEY uq_company_invitation_token (token_hash),
      KEY idx_company_invitation_status (status,expires_at),
      KEY idx_company_invitation_company (invited_company_id),
      KEY idx_company_invitation_founder_slot (founder_slot_no),
      CONSTRAINT fk_company_invited FOREIGN KEY (invited_company_id) REFERENCES invited_companies(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // Nivel autorizado en cada invitacion estrategica.
    try {
        $db->exec("ALTER TABLE company_invitations ADD COLUMN strategic_plan VARCHAR(20) NULL");
    } catch (PDOException $e) {
        if (($e->errorInfo[1] ?? null) != 1060) throw $e;
    }

    // Invitaciones privadas para Empresas Estrategicas.
    try {
        $db->exec("ALTER TABLE company_invitations ADD COLUMN strategic_invitation TINYINT(1) NOT NULL DEFAULT 0");
    } catch (PDOException $e) {
        if (($e->errorInfo[1] ?? null) != 1060) throw $e;
    }

    // V4.26: los enlaces se generan para envío manual; sent_at queda NULL hasta que exista un envío explícito.
    try { $db->exec("ALTER TABLE company_invitations MODIFY COLUMN sent_at DATETIME NULL"); } catch (PDOException $e) { /* compatibilidad */ }


    $db->exec("CREATE TABLE IF NOT EXISTS subscriptions (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT,
      user_id INT UNSIGNED NOT NULL,
      plan VARCHAR(40) NOT NULL,
      billing_cycle VARCHAR(20) NOT NULL,
      status VARCHAR(30) NOT NULL DEFAULT 'pending',
      stripe_customer_id VARCHAR(100) NULL,
      stripe_subscription_id VARCHAR(100) NULL,
      stripe_checkout_session_id VARCHAR(120) NULL,
      current_period_end DATETIME NULL,
      cancel_at_period_end TINYINT(1) NOT NULL DEFAULT 0,
      created_at DATETIME NOT NULL,
      updated_at DATETIME NOT NULL,
      PRIMARY KEY (id),
      UNIQUE KEY uq_sub_stripe_subscription (stripe_subscription_id),
      KEY idx_sub_user_status (user_id,status),
      CONSTRAINT fk_sub_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec("CREATE TABLE IF NOT EXISTS rate_limits (
      bucket_key CHAR(64) NOT NULL,
      action VARCHAR(32) NOT NULL,
      failures INT UNSIGNED NOT NULL DEFAULT 0,
      window_start DATETIME NOT NULL,
      updated_at DATETIME NOT NULL,
      PRIMARY KEY (bucket_key), KEY idx_rate_action_updated (action, updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec("CREATE TABLE IF NOT EXISTS contact_events (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      user_id INT UNSIGNED NOT NULL,
      target_user_id INT UNSIGNED NOT NULL,
      created_at DATETIME NOT NULL,
      PRIMARY KEY (id), KEY idx_contact_user_created (user_id, created_at), KEY idx_contact_target (target_user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // Recuperación segura de contraseñas.
    $db->exec("CREATE TABLE IF NOT EXISTS password_resets (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      user_id INT UNSIGNED NOT NULL,
      token_hash CHAR(64) NOT NULL,
      expires_at DATETIME NOT NULL,
      used_at DATETIME NULL,
      created_at DATETIME NOT NULL,
      PRIMARY KEY (id),
      UNIQUE KEY uq_password_reset_token (token_hash),
      KEY idx_password_reset_user (user_id),
      KEY idx_password_reset_expiry (expires_at),
      CONSTRAINT fk_password_reset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS mediation_requests (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      user_id INT UNSIGNED NOT NULL,
      subject VARCHAR(190) NOT NULL,
      details TEXT NOT NULL,
      status VARCHAR(24) NOT NULL DEFAULT 'pending',
      admin_notes TEXT NULL,
      created_at DATETIME NOT NULL,
      updated_at DATETIME NOT NULL,
      KEY idx_mediation_user (user_id,created_at),
      KEY idx_mediation_status (status,created_at),
      CONSTRAINT fk_mediation_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // Fase 2: datos económicos de intermediación; sin cargos automáticos.
    foreach ([
      "ALTER TABLE mediation_requests ADD COLUMN contract_amount DECIMAL(14,2) NULL",
      "ALTER TABLE mediation_requests ADD COLUMN commission_percent DECIMAL(5,2) NULL",
      "ALTER TABLE mediation_requests ADD COLUMN collected_amount DECIMAL(14,2) NULL",
      "ALTER TABLE mediation_requests ADD COLUMN agreement_confirmed TINYINT(1) NOT NULL DEFAULT 0",
      "ALTER TABLE mediation_requests ADD COLUMN payment_status VARCHAR(24) NOT NULL DEFAULT 'not_collected'"
    ] as $alter) { try { $db->exec($alter); } catch (PDOException $e) { if (($e->errorInfo[1] ?? null) != 1060) throw $e; } }
    // Beneficios privados para Empresas Estrategicas, independientes de las Fundadoras.
    foreach ([
        "ALTER TABLE users ADD COLUMN strategic_company TINYINT(1) NOT NULL DEFAULT 0",
        "ALTER TABLE users ADD COLUMN strategic_started_at DATETIME NULL",
        "ALTER TABLE users ADD COLUMN strategic_expires_at DATETIME NULL",
        "ALTER TABLE users ADD COLUMN strategic_status VARCHAR(20) NOT NULL DEFAULT 'none'",
        "ALTER TABLE users ADD COLUMN strategic_plan VARCHAR(20) NULL"
    ] as $alter) {
        try {
            $db->exec($alter);
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? null) != 1060) throw $e;
        }
    }

    $done = true;
}
function csrfToken(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return (string)$_SESSION['csrf'];
}
function requireCsrf(array $d): void {
    $token = (string)($d['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    if (!hash_equals(csrfToken(), $token)) out(['ok'=>false,'error'=>'Sesión no válida. Recarga la página e inténtalo de nuevo.'],419);
}
function currentUser(PDO $db): ?array {
    if (empty($_SESSION['uid'])) return null;
    $s = $db->prepare("SELECT id,empresa,cif,contacto,email,telefono,provincia,tipo,especialidad,zona,descripcion,web,logo,empleados,experiencia,disponibilidad,trabajos_realizados,certificaciones,areas_experiencia,founder_company,founder_started_at,founder_expires_at,founder_status,created_at,updated_at FROM users WHERE id=?");
    $s->execute([(int)$_SESSION['uid']]);
    $row=$s->fetch(); return $row ? normalizeDisplayLabels($row) : null;
}
function validateLength(string $value, int $max, string $label): string {
    $value = trim($value);
    if (mb_strlen($value) > $max) out(['ok'=>false,'error'=>"$label es demasiado largo."],422);
    return $value;
}
function rateKey(string $action, string $identity): string {
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    return hash('sha256', $action . '|' . $ip . '|' . strtolower(trim($identity)));
}
function rateLimit(PDO $db, string $action, string $identity, int $maxAttempts, int $windowSeconds): void {
    $key = rateKey($action, $identity); $now = date('Y-m-d H:i:s');
    $s = $db->prepare("SELECT failures, UNIX_TIMESTAMP(window_start) AS started FROM rate_limits WHERE bucket_key=? LIMIT 1");
    $s->execute([$key]); $row = $s->fetch(); $nowTs = time();
    if (!$row || ($nowTs - (int)$row['started']) >= $windowSeconds) {
        $db->prepare("INSERT INTO rate_limits (bucket_key,action,failures,window_start,updated_at) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE action=VALUES(action), failures=VALUES(failures), window_start=VALUES(window_start), updated_at=VALUES(updated_at)")->execute([$key,$action,1,$now,$now]);
        return;
    }
    if ((int)$row['failures'] >= $maxAttempts) out(['ok'=>false,'error'=>'Demasiados intentos. Inténtalo de nuevo más tarde.'],429);
    $db->prepare("UPDATE rate_limits SET failures=failures+1, updated_at=? WHERE bucket_key=?")->execute([$now,$key]);
}
function clearRateLimit(PDO $db, string $action, string $identity): void { $db->prepare("DELETE FROM rate_limits WHERE bucket_key=?")->execute([rateKey($action, $identity)]); }
function cleanupRateLimits(PDO $db): void { $db->exec("DELETE FROM rate_limits WHERE updated_at < (NOW() - INTERVAL 2 DAY)"); }
function requireUser(PDO $db): array { $u=currentUser($db); if(!$u) out(['ok'=>false,'error'=>'No autenticado'],401); return $u; }
function validProvince(string $province): bool {
    return in_array($province, ['Álava','Albacete','Alicante','Almería','Asturias','Ávila','Badajoz','Barcelona','Bizkaia','Burgos','Cáceres','Cádiz','Cantabria','Castellón','Ceuta','Ciudad Real','Córdoba','Cuenca','Gipuzkoa','Girona','Granada','Guadalajara','Huelva','Huesca','Illes Balears','Jaén','La Coruña','La Rioja','Las Palmas','León','Lleida','Lugo','Madrid','Málaga','Melilla','Murcia','Navarra','Ourense','Palencia','Pontevedra','Salamanca','Santa Cruz de Tenerife','Segovia','Sevilla','Soria','Tarragona','Teruel','Toledo','Valencia','Valladolid','Zamora','Zaragoza'], true);
}
function normalizeDisplayLabels(array $row): array {
    foreach (['provincia','company_province'] as $k) if (isset($row[$k])) $row[$k] = $row[$k] === 'Vizcaya' ? 'Bizkaia' : $row[$k];
    foreach (['especialidad','company_specialty'] as $k) if (isset($row[$k])) $row[$k] = $row[$k] === 'Construccion' ? 'Construcción' : $row[$k];
    if (isset($row['zona'])) $row['zona'] = $row['zona'] === 'Andalucia' ? 'Andalucía' : $row['zona'];
    return $row;
}

function siteUrl(array $config): string {
    $configured=rtrim(trim((string)($config['site_url']??'')),'/');
    if($configured!=='') return $configured;
    $scheme=(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off')?'https':'http';
    $host=preg_replace('/[^A-Za-z0-9.:-]/','',(string)($_SERVER['HTTP_HOST']??'conectaobra10.es'));
    return $scheme.'://'.$host;
}
function passwordResetUrl(array $config,string $token): string {
    return siteUrl($config).'/restablecer-password.html?token='.rawurlencode($token);
}

function invitationUrl(array $config,string $token): string { return siteUrl($config).'/activar-empresa.html?token='.rawurlencode($token); }
function invitationByToken(PDO $db,string $token): ?array {
    if(!preg_match('/^[a-f0-9]{64}$/i',$token)) return null;
    $hash=hash('sha256',$token);
    $s=$db->prepare("SELECT ci.*,ic.company,ic.cif,ic.contacto AS company_contacto,ic.email AS company_email,ic.telefono,ic.address,ic.province,ic.tipo,ic.especialidad,ic.zona,ic.descripcion,ic.web,ic.status AS company_status FROM company_invitations ci JOIN invited_companies ic ON ic.id=ci.invited_company_id WHERE ci.token_hash=? LIMIT 1");
    $s->execute([$hash]); $row=$s->fetch();
    return $row ?: null;
}

function planMeta(string $plan): ?array {
    $plans = [
      'free'=>['label'=>'Gratuito','amount'=>'0 € / siempre','cycle'=>'free','active_opportunities'=>1,'contact_limit'=>3,'visibility'=>'Básica'],
      'professional_monthly'=>['label'=>'Profesional','amount'=>'49 €/mes','cycle'=>'monthly','active_opportunities'=>20,'contact_limit'=>25,'visibility'=>'Alta'],
      'professional_yearly'=>['label'=>'Profesional','amount'=>'499 €/año','cycle'=>'yearly','active_opportunities'=>20,'contact_limit'=>25,'visibility'=>'Alta'],
      'premium_monthly'=>['label'=>'Premium','amount'=>'89 €/mes','cycle'=>'monthly','active_opportunities'=>null,'contact_limit'=>null,'visibility'=>'Máxima'],
      'premium_yearly'=>['label'=>'Premium','amount'=>'999 €/año','cycle'=>'yearly','active_opportunities'=>null,'contact_limit'=>null,'visibility'=>'Máxima'],
    ];
    return $plans[$plan] ?? null;
}
function userPlan(PDO $db, int $userId, bool $updateExpiredBenefits = true): array {
    $subscription=subscriptionForUser($db,$userId);
    if($subscription && in_array($subscription['status'],['active','trialing'],true)) {
        $meta=planMeta($subscription['plan']) ?? [];
        return ['key'=>$subscription['plan'],'label'=>$meta['label']??'Profesional','active_opportunities'=>array_key_exists('active_opportunities',$meta)?$meta['active_opportunities']:20,'contact_limit'=>array_key_exists('contact_limit',$meta)?$meta['contact_limit']:25,'visibility'=>$meta['visibility']??'Alta','subscription'=>$subscription];
    }
    $s=$db->prepare("SELECT founder_company,founder_started_at,founder_expires_at,founder_status FROM users WHERE id=? LIMIT 1");
    $s->execute([$userId]); $founder=$s->fetch();
    if($founder && (int)$founder['founder_company']===1 && $founder['founder_status']==='active' && !empty($founder['founder_expires_at'])) {
        if(strtotime($founder['founder_expires_at']) > time()) {
            $meta=planMeta('professional_monthly');
            return ['key'=>'founder_professional','label'=>'Profesional · Empresa Fundadora','amount'=>'Gratis durante 12 meses','active_opportunities'=>20,'contact_limit'=>$meta['contact_limit'],'visibility'=>$meta['visibility'],'subscription'=>null,'founder_expires_at'=>$founder['founder_expires_at']];
        }
        if ($updateExpiredBenefits) { $db->prepare("UPDATE users SET founder_status='expired',updated_at=? WHERE id=? AND founder_status='active'")->execute([date('Y-m-d H:i:s'),$userId]); }
    }
    // Empresas Estrategicas: Profesional o Premium gratuito durante 12 meses.
    // Se comprueba despues de Stripe y de las Empresas Fundadoras.
    $s=$db->prepare("SELECT strategic_company,strategic_plan,strategic_expires_at,strategic_status FROM users WHERE id=? LIMIT 1");
    $s->execute([$userId]);
    $strategic=$s->fetch();
    if($strategic && (int)$strategic['strategic_company']===1
        && $strategic['strategic_status']==='active'
        && !empty($strategic['strategic_expires_at'])) {
        if(strtotime($strategic['strategic_expires_at']) > time()) {
            $tier=$strategic['strategic_plan']==='premium' ? 'premium' : 'professional';
            $meta=planMeta($tier.'_monthly');
            return [
                'key'=>'strategic_'.$tier,
                'label'=>$meta['label'].' - Empresa Estrategica',
                'amount'=>'Gratis durante 12 meses',
                'active_opportunities'=>$meta['active_opportunities'],
                'contact_limit'=>$meta['contact_limit'],
                'visibility'=>$meta['visibility'],
                'subscription'=>null,
                'strategic_expires_at'=>$strategic['strategic_expires_at']
            ];
        }
        if ($updateExpiredBenefits) { $db->prepare("UPDATE users SET strategic_status='expired',updated_at=? WHERE id=? AND strategic_status='active'")
           ->execute([date('Y-m-d H:i:s'),$userId]); }
    }
    $meta=planMeta('free');
    return ['key'=>'free','label'=>$meta['label'],'active_opportunities'=>$meta['active_opportunities'],'contact_limit'=>$meta['contact_limit'],'visibility'=>$meta['visibility'],'subscription'=>null];
}
function stripeRequest(array $config, string $method, string $path, array $params=[]): array {
    $key=(string)($config['stripe_secret_key']??'');
    if($key==='' || str_contains($key,'REEMPLAZAR')) out(['ok'=>false,'error'=>'La pasarela de pago aún no está configurada.'],503);
    $ch=curl_init('https://api.stripe.com/v1/'.$path);
    $headers=['Authorization: Bearer '.$key,'Content-Type: application/x-www-form-urlencoded'];
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>$headers,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_TIMEOUT=>20]);
    if($method!=='GET') curl_setopt($ch,CURLOPT_POSTFIELDS,http_build_query($params));
    $raw=curl_exec($ch); $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); $err=curl_error($ch); curl_close($ch);
    if($raw===false || $err) { error_log('Stripe cURL: '.$err); out(['ok'=>false,'error'=>'No se pudo conectar con Stripe.'],502); }
    $data=json_decode($raw,true);
    if($code<200||$code>=300){
        error_log('Stripe API '.$code.': '.$raw);
        $detail = '';
        $decoded = json_decode($raw, true);
        if (is_array($decoded) && isset($decoded['error']) && is_array($decoded['error'])) {
            $type = (string)($decoded['error']['type'] ?? '');
            $message = (string)($decoded['error']['message'] ?? '');
            $param = (string)($decoded['error']['param'] ?? '');
            $detail = trim(($type !== '' ? $type.': ' : '').$message.($param !== '' ? ' (param: '.$param.')' : ''));
        }
        // En Test mostramos el detalle para diagnosticar la integración; en Live se mantiene el mensaje genérico.
        if (str_starts_with($key, 'sk_test_') && $detail !== '') {
            out(['ok'=>false,'error'=>'Stripe no ha podido crear la operación.','stripe_test_detail'=>$detail],502);
        }
        out(['ok'=>false,'error'=>'Stripe no ha podido crear la operación.'],502);
    }
    return is_array($data)?$data:[];
}
function stripeSignatureValid(string $payload, string $header, string $secret): bool {
    if($secret===''||str_contains($secret,'REEMPLAZAR')) return false;
    $timestamp=0; $signatures=[];
    foreach(explode(',',$header) as $part){$bits=explode('=',$part,2);if(count($bits)!==2)continue;if($bits[0]==='t')$timestamp=(int)$bits[1];if($bits[0]==='v1')$signatures[]=$bits[1];}
    if(!$timestamp || abs(time()-$timestamp)>300) return false;
    $expected=hash_hmac('sha256',$timestamp.'.'.$payload,$secret);
    foreach($signatures as $sig) if(hash_equals($expected,$sig)) return true;
    return false;
}
function stripeDate(?int $timestamp): ?string { return $timestamp ? date('Y-m-d H:i:s',$timestamp) : null; }
function subscriptionForUser(PDO $db,int $userId): ?array {
    $s=$db->prepare("SELECT plan,billing_cycle,status,current_period_end,cancel_at_period_end FROM subscriptions WHERE user_id=? AND status IN ('active','trialing','past_due','incomplete') ORDER BY id DESC LIMIT 1");$s->execute([$userId]);$r=$s->fetch();
    if(!$r) return null; $meta=planMeta($r['plan']);$r['plan_label']=$meta['label']??$r['plan'];$r['amount']=$meta['amount']??'';return $r;
}

function opportunityAccess(PDO $db, array $user): array {
    $plan=userPlan($db,(int)$user['id']);
    $limit=$plan['active_opportunities'];
    $activeStmt=$db->prepare("SELECT COUNT(*) FROM opportunities WHERE user_id=? AND status='active'");
    $activeStmt->execute([(int)$user['id']]);
    $active=(int)$activeStmt->fetchColumn();
    return [
        'can_access'=>true,
        'is_paid'=>$plan['key']!=='free',
        'trial_active'=>($plan['key']==='founder_professional'),
        'trial_ends_at'=>$plan['founder_expires_at']??null,
        'strategic_active'=>str_starts_with((string)$plan['key'],'strategic_'),
        'strategic_expires_at'=>$plan['strategic_expires_at']??null,
        'days_remaining'=>!empty($plan['founder_expires_at']??$plan['strategic_expires_at']??null)?max(0,(int)ceil((strtotime($plan['founder_expires_at']??$plan['strategic_expires_at'])-time())/86400)):0,
        'plan'=>$plan,
        'active_opportunities'=>$active,
        'active_opportunity_limit'=>$limit,
        'can_create_opportunity'=>$limit===null || $active < $limit,
        'subscription'=>$plan['subscription']
    ];
}

function requireOpportunityAccess(PDO $db): array {
    return requireUser($db);
}

$db = db($config); ensureSchema($db); cleanupRateLimits($db); $action = $_GET['action'] ?? '';
if ($action === 'logo_upload') {
    $u = requireUser($db);

    // Comprobar CSRF
    $csrf = (string)($_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    if (!hash_equals(csrfToken(), $csrf)) {
        out(['ok'=>false,'error'=>'Sesión no válida. Recarga la página e inténtalo de nuevo.'], 419);
    }

    // Comprobar que se ha recibido un archivo
    if (!isset($_FILES['logo']) || !is_array($_FILES['logo'])) {
        out(['ok'=>false,'error'=>'Selecciona una imagen para el logo.'], 422);
    }

    $file = $_FILES['logo'];

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        out(['ok'=>false,'error'=>'No se pudo subir la imagen.'], 422);
    }

    // Máximo 3 MB
    if (($file['size'] ?? 0) <= 0 || $file['size'] > 3 * 1024 * 1024) {
        out(['ok'=>false,'error'=>'El logo debe ocupar como máximo 3 MB.'], 422);
    }

    $tmp = (string)($file['tmp_name'] ?? '');

    if ($tmp === '' || !is_uploaded_file($tmp)) {
        out(['ok'=>false,'error'=>'El archivo recibido no es válido.'], 422);
    }

    // Comprobar el tipo real del archivo
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmp);

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp'
    ];

    if (!isset($allowed[$mime])) {
        out(['ok'=>false,'error'=>'Formato no permitido. Usa JPG, PNG o WebP.'], 422);
    }

    // Carpeta donde se guardarán los logos
    $uploadDir = __DIR__ . '/uploads/logos';

    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
        out(['ok'=>false,'error'=>'No se pudo preparar la carpeta de logos.'], 500);
    }

    $extension = $allowed[$mime];
    $filename = 'empresa_' . (int)$u['id'] . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
    $destination = $uploadDir . '/' . $filename;

    if (!move_uploaded_file($tmp, $destination)) {
        out(['ok'=>false,'error'=>'No se pudo guardar el logo en el servidor.'], 500);
    }

    $logoPath = 'uploads/logos/' . $filename;

    // Guardamos el logo anterior para eliminarlo después
    $oldLogo = trim((string)($u['logo'] ?? ''));

    $db->prepare("UPDATE users SET logo=?, updated_at=? WHERE id=?")
       ->execute([$logoPath, date('Y-m-d H:i:s'), $u['id']]);

    // Eliminar el logo anterior si era un archivo gestionado por ConectaObra
    if ($oldLogo !== '' && str_starts_with($oldLogo, 'uploads/logos/')) {
        $oldFile = __DIR__ . '/' . $oldLogo;

        if (is_file($oldFile)) {
            @unlink($oldFile);
        }
    }

    out([
        'ok'=>true,
        'logo'=>$logoPath,
        'user'=>currentUser($db),
        'csrf'=>csrfToken()
    ]);
}
if ($action === 'founder_status') {
    $s=$db->query("SELECT COUNT(*) FROM founder_slots fs WHERE fs.user_id IS NULL AND NOT EXISTS (SELECT 1 FROM company_invitations ci WHERE ci.founder_slot_no=fs.slot_no AND ci.status='pending' AND ci.expires_at>NOW())");
    $available=(int)$s->fetchColumn();
    out(['ok'=>true,'total'=>20,'available'=>$available,'claimed'=>20-$available]);
}

if ($action === 'register') {
    $d=body();
    if(empty($d['accept_conditions']) || empty($d['accept_privacy'])) out(['ok'=>false,'error'=>'Debes aceptar las Condiciones de Uso y confirmar que has leído la Política de Privacidad.'],422); $required=['empresa','cif','contacto','email','telefono','provincia','tipo','especialidad','password'];
    foreach($required as $k) if(trim((string)($d[$k]??''))==='') out(['ok'=>false,'error'=>"Falta el campo: $k"],422);
    $email=strtolower(trim((string)$d['email']));
    if(!filter_var($email,FILTER_VALIDATE_EMAIL)||preg_match('/[\\r\\n]/',$email)) out(['ok'=>false,'error'=>'Email no válido'],422);
    if(strlen((string)$d['password'])<8) out(['ok'=>false,'error'=>'La contraseña debe tener al menos 8 caracteres'],422);
    $empresa=validateLength((string)$d['empresa'],160,'La empresa'); $cif=validateLength((string)$d['cif'],30,'El CIF'); $contacto=validateLength((string)$d['contacto'],160,'El contacto'); $telefono=validateLength((string)$d['telefono'],40,'El teléfono');
    $provincia=validateLength((string)$d['provincia'],100,'La provincia'); if($provincia==='Vizcaya') $provincia='Bizkaia'; $tipo=validateLength((string)$d['tipo'],100,'El tipo'); $especialidad=validateLength((string)$d['especialidad'],190,'La especialidad'); $zona=validateLength((string)($d['zona']??''),255,'La zona'); $descripcion=validateLength((string)($d['descripcion']??''),10000,'La descripción'); if(trim($descripcion)==='Esta empresa está invitada a ConectaObra y todavía no ha activado su perfil.') $descripcion=''; $web=validateLength((string)($d['web']??''),255,'La web');
    if(!validProvince($provincia)) out(['ok'=>false,'error'=>'Selecciona una provincia válida.'],422);
    if($web!==''&&(!filter_var($web,FILTER_VALIDATE_URL)||!preg_match('~^https?://~i',$web))) out(['ok'=>false,'error'=>'La web debe ser una URL válida que empiece por http:// o https://'],422);
    rateLimit($db,'register','__ip__',3,3600);
    // El registro público crea siempre una cuenta Gratuita. Las plazas fundadoras solo se asignan mediante invitación privada desde Administración.
    try {
        $db->beginTransaction(); $now=date('Y-m-d H:i:s'); $founderCompany=0; $founderStarted=null; $founderExpires=null; $founderStatus='none'; $slotNo=null;
        $s=$db->prepare("INSERT INTO users (empresa,cif,contacto,email,telefono,provincia,tipo,especialidad,zona,password_hash,descripcion,web,founder_company,founder_started_at,founder_expires_at,founder_status,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $s->execute([$empresa,$cif,$contacto,$email,$telefono,$provincia,$tipo,$especialidad,$zona,password_hash((string)$d['password'],PASSWORD_DEFAULT),$descripcion,$web,$founderCompany,$founderStarted,$founderExpires,$founderStatus,$now,$now]);
        $userId=(int)$db->lastInsertId();
        if($slotNo!==null) $db->prepare("UPDATE founder_slots SET user_id=?,claimed_at=? WHERE slot_no=? AND user_id IS NULL")->execute([$userId,$now,$slotNo]);
        $db->commit();
        // Aviso de alta pública, solo después de guardar el registro correctamente.
        // Un fallo SMTP no debe impedir el alta ni modificar su resultado.
        try {
            $notifyTo='info@conectaobra10.es';
            $notifySubject='Nueva empresa registrada - ConectaObra: '.$empresa;
            $notifyContent="Nueva empresa registrada en ConectaObra (registro público).\n\n".
                "Empresa: {$empresa}\n".
                "CIF/NIF: {$cif}\n".
                "Contacto: {$contacto}\n".
                "Email: {$email}\n".
                "Teléfono: {$telefono}\n".
                "Provincia: {$provincia}\n".
                "Tipo: {$tipo}\n".
                "Especialidad: {$especialidad}\n".
                "Fecha de alta: {$now}\n".
                "Tipo de cuenta: Gratuita (registro público)\n\n".
                "Consulta la empresa desde el panel de administración de ConectaObra.\n";
            if (!smtpSend($config,$notifyTo,$notifySubject,$notifyContent,'info@conectaobra10.es')) {
                error_log('ConectaObra: aviso SMTP fallido para alta pública de user_id '.$userId);
            }
        } catch (Throwable $mailError) {
            error_log('ConectaObra: error de aviso de alta pública user_id '.$userId.': '.$mailError->getMessage());
        }
        session_regenerate_id(true); $_SESSION['uid']=$userId; csrfToken();

        coSendCompanyWelcome($config, $email, $contacto, $empresa, $userId);

        $u=currentUser($db);
        out(['ok'=>true,'user'=>$u,'csrf'=>csrfToken(),'founder_awarded'=>$founderCompany===1,'founder_slot'=>$slotNo,'founder_expires_at'=>$founderExpires,'founder_available'=>($db->query("SELECT COUNT(*) FROM founder_slots fs WHERE fs.user_id IS NULL AND NOT EXISTS (SELECT 1 FROM company_invitations ci WHERE ci.founder_slot_no=fs.slot_no AND ci.status='pending' AND ci.expires_at>NOW())")->fetchColumn())]);
    } catch(PDOException $e){
        if($db->inTransaction())$db->rollBack();
        if($e->getCode()==='23000') out(['ok'=>false,'error'=>'El CIF o email ya está registrado.'],409);
        error_log('ConectaObra register error: '.$e->getMessage()); out(['ok'=>false,'error'=>'No se pudo crear la cuenta.'],500);
    }
}
if ($action === 'forgot_password') {
    $d=body();
    $email=strtolower(trim((string)($d['email']??'')));
    $genericMessage='Si existe una cuenta asociada a ese correo, recibirás un enlace para restablecer tu contraseña.';
    if(!filter_var($email,FILTER_VALIDATE_EMAIL)||preg_match('/[\r\n]/',$email)) out(['ok'=>true,'message'=>$genericMessage]);
    rateLimit($db,'forgot_password',$email,5,3600);
    $s=$db->prepare("SELECT id,contacto,empresa,email FROM users WHERE email=? LIMIT 1");$s->execute([$email]);$u=$s->fetch();
    if(!$u) out(['ok'=>true,'message'=>$genericMessage]);
    $now=date('Y-m-d H:i:s');
    $db->prepare("UPDATE password_resets SET used_at=? WHERE user_id=? AND used_at IS NULL")->execute([$now,(int)$u['id']]);
    $token=bin2hex(random_bytes(32));$tokenHash=hash('sha256',$token);$expiresAt=date('Y-m-d H:i:s',time()+3600);
    $db->prepare("INSERT INTO password_resets (user_id,token_hash,expires_at,used_at,created_at) VALUES (?,?,?,NULL,?)")->execute([(int)$u['id'],$tokenHash,$expiresAt,$now]);
    $url=passwordResetUrl($config,$token);$name=trim((string)($u['contacto']??''));$company=trim((string)($u['empresa']??''));
    $content="Hola".($name!==''?" $name":'').",\n\nHemos recibido una solicitud para restablecer la contraseña de tu cuenta de ConectaObra".($company!==''?" ($company)":'').".\n\nPuedes crear una nueva contraseña desde el siguiente enlace:\n\n".$url."\n\nEste enlace caduca en 1 hora y solo puede utilizarse una vez.\n\nSi no has solicitado este cambio, puedes ignorar este correo. Tu contraseña actual seguirá funcionando.\n\nUn saludo,\nEquipo ConectaObra";
    $replyTo=trim((string)($config['smtp_user']??''));
    $sent=smtpSend($config,$email,'Restablece tu contraseña de ConectaObra',$content,$replyTo);
    if(!$sent) error_log('ConectaObra password reset: no se pudo enviar el email a user_id '.(int)$u['id']);
    out(['ok'=>true,'message'=>$genericMessage]);
}

if ($action === 'reset_password') {
    $d=body();$token=strtolower(trim((string)($d['token']??'')));$password=(string)($d['password']??'');
    if(!preg_match('/^[a-f0-9]{64}$/',$token)) out(['ok'=>false,'error'=>'El enlace de recuperación no es válido.'],422);
    if(strlen($password)<8) out(['ok'=>false,'error'=>'La contraseña debe tener al menos 8 caracteres.'],422);
    $tokenHash=hash('sha256',$token);$s=$db->prepare("SELECT id,user_id,expires_at,used_at FROM password_resets WHERE token_hash=? LIMIT 1");$s->execute([$tokenHash]);$reset=$s->fetch();
    if(!$reset) out(['ok'=>false,'error'=>'El enlace de recuperación no es válido.'],400);
    if($reset['used_at']!==null) out(['ok'=>false,'error'=>'Este enlace de recuperación ya ha sido utilizado.'],410);
    if(strtotime((string)$reset['expires_at'])<=time()) out(['ok'=>false,'error'=>'Este enlace de recuperación ha caducado. Solicita uno nuevo.'],410);
    try{
        $db->beginTransaction();$now=date('Y-m-d H:i:s');
        $db->prepare("UPDATE users SET password_hash=?,updated_at=? WHERE id=?")->execute([password_hash($password,PASSWORD_DEFAULT),$now,(int)$reset['user_id']]);
        $db->prepare("UPDATE password_resets SET used_at=? WHERE id=? AND used_at IS NULL")->execute([$now,(int)$reset['id']]);
        $db->commit();
        $emailStmt=$db->prepare("SELECT email FROM users WHERE id=? LIMIT 1");$emailStmt->execute([(int)$reset['user_id']]);$userEmail=(string)($emailStmt->fetchColumn()?:'');
        if($userEmail!=='') clearRateLimit($db,'login',$userEmail);
        out(['ok'=>true,'message'=>'Tu contraseña se ha actualizado correctamente.']);
    }catch(Throwable $e){
        if($db->inTransaction())$db->rollBack();error_log('ConectaObra reset password error: '.$e->getMessage());out(['ok'=>false,'error'=>'No se pudo actualizar la contraseña. Inténtalo de nuevo.'],500);
    }
}

if ($action === 'login') {
    $d=body(); $email=strtolower(trim((string)($d['email']??''))); $pass=(string)($d['password']??'');
    if(!filter_var($email,FILTER_VALIDATE_EMAIL)||preg_match('/[\r\n]/',$email)) out(['ok'=>false,'error'=>'Email o contraseña incorrectos.'],401);
    rateLimit($db,'login',$email,5,900); $s=$db->prepare("SELECT * FROM users WHERE email=?"); $s->execute([$email]); $u=$s->fetch();
    if(!$u||!password_verify($pass,$u['password_hash'])) out(['ok'=>false,'error'=>'Email o contraseña incorrectos.'],401);
    clearRateLimit($db,'login',$email); session_regenerate_id(true); $_SESSION['uid']=(int)$u['id']; csrfToken(); out(['ok'=>true,'user'=>currentUser($db),'csrf'=>csrfToken()]);
}
// CO_CONTACT_NOTICE_V1
if (in_array($action, ['contact_notice_status','contact_notice_seen'], true)) {
    define('CO_CONTACT_NOTICE_HANDLER', true);
    require __DIR__.'/contactos-aviso-handler.php';
    exit;
}
// CO_CONNECTIONS_BASE_V1
if (in_array($action, ['connections_preferences','connections_preferences_save'], true)) {
    define('CO_CONNECTIONS_INTERNAL', true);
    require __DIR__.'/mega-bloque/conexiones/api.php';
    exit;
}
// CO_CONNECTIONS_INTEREST_V1
if (in_array($action, ['connections_list','connection_interest','connection_accept','connection_reject','connection_withdraw','connection_close'], true)) {
    define('CO_CONNECTIONS_INTEREST_INTERNAL', true);
    require __DIR__.'/mega-bloque/conexiones/interests.php';
    exit;
}
// CO_CONNECTION_NOTICES_V1
if (in_array($action, ['connection_notice_preferences','connection_notice_preferences_save'], true)) {
    define('CO_CONNECTION_NOTICES_INTERNAL', true);
    require __DIR__.'/mega-bloque/conexiones/notices-api.php';
    exit;
}
if ($action === 'me') {
    header('Cache-Control: private, no-store');
    $u=currentUser($db);
    out([
        'ok'=>true,
        'admin_view'=>!empty($_SESSION['admin']),
        'user'=>$u,
        'csrf'=>csrfToken(),
        'opportunity_access'=>$u ? opportunityAccess($db,$u) : null
    ]);
}
if ($action === 'logout') { $_SESSION=[]; if(ini_get('session.use_cookies')){ $p=session_get_cookie_params(); setcookie(session_name(),'',['expires'=>time()-42000,'path'=>$p['path'],'domain'=>$p['domain'],'secure'=>$p['secure'],'httponly'=>$p['httponly'],'samesite'=>$p['samesite']??'Lax']); } session_destroy(); out(['ok'=>true]); }

if ($action === 'profile') {
    $u=requireUser($db); $d=body(); requireCsrf($d); $limits=['empresa'=>160,'contacto'=>160,'telefono'=>40,'provincia'=>100,'tipo'=>100,'especialidad'=>190,'zona'=>255,'descripcion'=>10000,'web'=>255,'empleados'=>100,'experiencia'=>100,'disponibilidad'=>100,'trabajos_realizados'=>10000,'certificaciones'=>10000,'areas_experiencia'=>10000];
    foreach(['empresa','contacto','telefono','provincia','tipo','especialidad'] as $k) if(array_key_exists($k,$d)&&trim((string)$d[$k])==='') out(['ok'=>false,'error'=>"El campo $k no puede quedar vacío."],422);
    foreach($limits as $k=>$max) if(array_key_exists($k,$d)){ $value=validateLength((string)$d[$k],$max,ucfirst($k)); if($k==='provincia'&&$value==='Vizcaya') $value='Bizkaia'; if($k==='provincia'&&!validProvince($value)) out(['ok'=>false,'error'=>'Selecciona una provincia válida.'],422); if($k==='web'&&$value!==''&&(!filter_var($value,FILTER_VALIDATE_URL)||!preg_match('~^https?://~i',$value))) out(['ok'=>false,'error'=>'La web debe ser una URL válida que empiece por http:// o https://'],422); $sets[]=$k.'=?';$vals[]=$value; }
    if($sets){$sets[]='updated_at=?';$vals[]=date('Y-m-d H:i:s');$vals[]=$u['id'];$db->prepare("UPDATE users SET ".implode(',',$sets)." WHERE id=?")->execute($vals);} out(['ok'=>true,'user'=>currentUser($db),'csrf'=>csrfToken()]);
}
if ($action === 'companies') {
    header('Cache-Control: private, no-store');
    $isAuthenticated = !empty($_SESSION['admin']) || (bool)currentUser($db);
    $q=trim((string)($_GET['q']??'')); $province=trim((string)($_GET['provincia']??''));
    if($province!=='' && !validProvince($province)) out(['ok'=>false,'error'=>'Provincia no válida.'],422);
    $rows=[];
    $sql="SELECT id,empresa,provincia,tipo,especialidad,zona,descripcion,web,logo,founder_company,founder_status,founder_expires_at,'registered' AS source FROM users WHERE 1=1";$p=[];
    if($q!==''){ $sql.=" AND (empresa LIKE ? OR especialidad LIKE ? OR tipo LIKE ? OR zona LIKE ?)";$like="%$q%";$p=[$like,$like,$like,$like]; }
    if($province!==''){ $sql.=" AND provincia=?";$p[]=$province; }
    $sql.=" LIMIT 100";$st=$db->prepare($sql);$st->execute($p); $rows=array_map('normalizeDisplayLabels',$st->fetchAll());
    $iq=$q!==''?" AND (ic.company LIKE ? OR ic.cif LIKE ? OR ic.address LIKE ? OR ic.tipo LIKE ? OR COALESCE(ic.especialidad,'') LIKE ? OR COALESCE(ic.zona,'') LIKE ?)":"";
    $ip=$q!==''?array_fill(0,6,"%$q%"):[];
    $isql="SELECT ic.id,ic.company AS empresa,ic.province AS provincia,ic.tipo,ic.especialidad,ic.zona,ic.descripcion,ic.web,0 AS founder_company,'none' AS founder_status,NULL AS founder_expires_at,ic.address,ic.status,'invited' AS source FROM invited_companies ic WHERE ic.status='invited' AND NOT EXISTS (SELECT 1 FROM users u WHERE (ic.cif NOT LIKE 'PEND-%' AND u.cif=ic.cif) OR (ic.email IS NOT NULL AND ic.email<>'' AND u.email=ic.email))".$iq;
    if($province!==''){$isql.=" AND ic.province=?";$ip[]=$province;}
    $isql.=" LIMIT 100";$st=$db->prepare($isql);$st->execute($ip); $invited=array_map('normalizeDisplayLabels',$st->fetchAll());
    $rows=array_merge($rows,$invited); usort($rows,fn($a,$b)=>strnatcasecmp((string)$a['empresa'],(string)$b['empresa']));
    if(!$isAuthenticated){
        $rows=array_map(function($c){
            unset($c['descripcion'],$c['web'],$c['zona'],$c['address']);
            $c['public_preview']=true;
            return $c;
        },$rows);
    }
    out(['ok'=>true,'companies'=>array_slice($rows,0,100),'authenticated'=>$isAuthenticated]);
}
if ($action === 'company') {
    header('Cache-Control: private, no-store');
    $isAuthenticated = !empty($_SESSION['admin']) || (bool)currentUser($db);
    $id=(int)($_GET['id']??0); $source=(string)($_GET['source']??'registered'); if($id<=0) out(['ok'=>false,'error'=>'Empresa no válida.'],422);
    if($source==='invited'){
        $s=$db->prepare("SELECT id,company AS empresa,cif,address,province AS provincia,tipo,especialidad,zona,descripcion,web,0 AS founder_company,'none' AS founder_status,NULL AS founder_expires_at,invitation_date AS created_at,status,'invited' AS source FROM invited_companies WHERE id=? AND status='invited' LIMIT 1");$s->execute([$id]);$company=$s->fetch();if(!$company)out(['ok'=>false,'error'=>'La empresa invitada no existe.'],404);
    } else {
        $s=$db->prepare("SELECT id,empresa,logo,provincia,tipo,especialidad,zona,descripcion,web,empleados,experiencia,disponibilidad,trabajos_realizados,certificaciones,areas_experiencia,founder_company,founder_status,founder_expires_at,created_at,'registered' AS source FROM users WHERE id=? LIMIT 1");$s->execute([$id]);$company=$s->fetch();if(!$company)out(['ok'=>false,'error'=>'La empresa no existe.'],404);
    }
    $company=normalizeDisplayLabels($company);
    if(!$isAuthenticated){
        unset($company['cif'],$company['address'],$company['zona'],$company['descripcion'],$company['web']);
        $company['public_preview']=true;
    }
    out(['ok'=>true,'company'=>$company,'authenticated'=>$isAuthenticated]);
}
if ($action === 'opportunities') {
    header('Cache-Control: private, no-store');
    // La sesión administrativa permite la consulta sin una cuenta de empresa.
    if (empty($_SESSION['admin']) || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        requireUser($db);
    }
    $province=trim((string)($_GET['provincia']??'')); $category=trim((string)($_GET['categoria']??'')); $sql="SELECT o.id,o.title,o.province,o.category,o.description,o.status,o.created_at,u.empresa AS publisher FROM opportunities o JOIN users u ON u.id=o.user_id WHERE o.status='active'";$p=[];
    if($province!==''){ if(!validProvince($province))out(['ok'=>false,'error'=>'Provincia no válida.'],422);$sql.=" AND o.province=?";$p[]=$province; }
    if($category!==''){ $sql.=" AND o.category LIKE ?";$p[]='%'.$category.'%'; }
    $sql.=" ORDER BY o.id DESC LIMIT 100";$s=$db->prepare($sql);$s->execute($p);out(['ok'=>true,'opportunities'=>$s->fetchAll()]);
}
if ($action === 'my_opportunities') {
    $u=requireOpportunityAccess($db); $s=$db->prepare("SELECT id,title,province,category,description,status,created_at,updated_at FROM opportunities WHERE user_id=? ORDER BY id DESC LIMIT 100");$s->execute([$u['id']]);out(['ok'=>true,'opportunities'=>$s->fetchAll()]);
}
if ($action === 'opportunity') {
    // Los datos de oportunidades solo están disponibles con sesión iniciada.
    requireUser($db);
    $id=(int)($_GET['id']??0); if($id<=0)out(['ok'=>false,'error'=>'Oportunidad no válida.'],422);
    $s=$db->prepare("SELECT o.id,o.title,o.province,o.category,o.description,o.status,o.created_at,u.id AS company_id,u.empresa,u.provincia AS company_province,u.tipo,u.especialidad,u.zona,u.descripcion AS company_description,u.web FROM opportunities o JOIN users u ON u.id=o.user_id WHERE o.id=? LIMIT 1");$s->execute([$id]);$opportunity=$s->fetch();if(!$opportunity)out(['ok'=>false,'error'=>'La oportunidad no existe.'],404);if($opportunity['status']!=='active')out(['ok'=>false,'error'=>'Esta oportunidad ya no está activa.'],404);$opportunity=normalizeDisplayLabels($opportunity); out(['ok'=>true,'opportunity'=>$opportunity]);
}
if ($action === 'create_opportunity') {
    $u=requireOpportunityAccess($db);$d=body();requireCsrf($d);foreach(['title','province','category','description'] as $k)if(trim((string)($d[$k]??''))==='')out(['ok'=>false,'error'=>"Falta el campo: $k"],422);
    $title=validateLength((string)$d['title'],190,'El título');$province=validateLength((string)$d['province'],100,'La provincia');$category=validateLength((string)$d['category'],120,'La categoría');$description=validateLength((string)$d['description'],10000,'La descripción');
    if(!validProvince($province))out(['ok'=>false,'error'=>'Selecciona una provincia válida.'],422); rateLimit($db,'create_opportunity',(string)$u['id'],5,3600);
    $access=opportunityAccess($db,$u);$limit=$access['active_opportunity_limit'];$active=(int)$access['active_opportunities'];
    if($limit!==null && $active >= $limit){$label=$access['plan']['label'];out(['ok'=>false,'code'=>'opportunity_limit','error'=>"Has alcanzado el máximo de {$limit} oportunidades activas de tu plan {$label}. Para publicar más, cambia de plan.",'opportunity_access'=>$access],409);}
    require_once __DIR__ . '/mega-bloque/alertas/alert_publication_transaction.php';
    $newId = conectaobraPublishWithAlerts(
        $db,
        static function () use ($db, $u, $title, $province, $category, $description): int {
            $now = date('Y-m-d H:i:s');
            $s = $db->prepare("INSERT INTO opportunities(user_id,title,province,category,description,status,created_at,updated_at) VALUES(?,?,?,?,?,'active',?,?)");
            $s->execute([$u['id'], $title, $province, $category, $description, $now, $now]);
            return (int)$db->lastInsertId();
        },
        defined('CONECTAOBRA_ALERTS_ENABLED') && CONECTAOBRA_ALERTS_ENABLED === true
    );
    out(['ok'=>true,'id'=>$newId,'message'=>'Oportunidad publicada correctamente.','csrf'=>csrfToken()]);
}
if ($action === 'update_opportunity') {
    $u=requireOpportunityAccess($db); $d=body(); requireCsrf($d); $id=(int)($d['id']??0); if($id<=0)out(['ok'=>false,'error'=>'Oportunidad no válida.'],422);
    $s=$db->prepare("SELECT id FROM opportunities WHERE id=? AND user_id=? LIMIT 1"); $s->execute([$id,$u['id']]); if(!$s->fetch())out(['ok'=>false,'error'=>'No tienes permiso sobre esta oportunidad.'],403);
    foreach(['title','province','category','description'] as $k) if(trim((string)($d[$k]??''))==='')out(['ok'=>false,'error'=>"Falta el campo: $k"],422);
    $title=validateLength((string)$d['title'],190,'El título');$province=validateLength((string)$d['province'],100,'La provincia');$category=validateLength((string)$d['category'],120,'La categoría');$description=validateLength((string)$d['description'],10000,'La descripción'); if(!validProvince($province))out(['ok'=>false,'error'=>'Selecciona una provincia válida.'],422);
    $db->prepare("UPDATE opportunities SET title=?,province=?,category=?,description=?,updated_at=? WHERE id=? AND user_id=?")->execute([$title,$province,$category,$description,date('Y-m-d H:i:s'),$id,$u['id']]); out(['ok'=>true,'message'=>'Oportunidad actualizada.']);
}
if (in_array($action,['close_opportunity','reopen_opportunity','delete_opportunity'],true)) {
    $u=requireOpportunityAccess($db);$d=body();requireCsrf($d);$id=(int)($d['id']??0);if($id<=0)out(['ok'=>false,'error'=>'Oportunidad no válida.'],422);
    $s=$db->prepare("SELECT id,status FROM opportunities WHERE id=? AND user_id=? LIMIT 1");$s->execute([$id,$u['id']]);$opp=$s->fetch();if(!$opp)out(['ok'=>false,'error'=>'No tienes permiso sobre esta oportunidad.'],403);
    if($action==='delete_opportunity'){ $db->prepare("DELETE FROM opportunities WHERE id=? AND user_id=?")->execute([$id,$u['id']]);out(['ok'=>true,'message'=>'Oportunidad eliminada.']); }
    $newStatus=$action==='close_opportunity'?'closed':'active';
    if($newStatus==='active' && $opp['status']!=='active'){
        $access=opportunityAccess($db,$u);$limit=$access['active_opportunity_limit'];$active=(int)$access['active_opportunities'];
        if($limit!==null && $active >= $limit)out(['ok'=>false,'code'=>'opportunity_limit','error'=>"No puedes reabrir esta oportunidad porque ya has alcanzado el límite de {$limit} oportunidades activas de tu plan {$access['plan']['label']}.",'opportunity_access'=>$access],409);
    }
    $db->prepare("UPDATE opportunities SET status=?,updated_at=? WHERE id=? AND user_id=?")->execute([$newStatus,date('Y-m-d H:i:s'),$id,$u['id']]);out(['ok'=>true,'message'=>$newStatus==='closed'?'Oportunidad cerrada.':'Oportunidad reabierta.']);
}

if ($action === 'billing_status') {
    $u=requireUser($db);
    $access=opportunityAccess($db,$u);
    out(['ok'=>true,'subscription'=>$access['subscription'],'opportunity_access'=>$access]);
}
if ($action === 'create_checkout') {
    $u=requireUser($db); $d=body(); requireCsrf($d);

    // Proteger los 12 meses gratuitos de las Empresas Estrategicas.
    $strategicCheck=$db->prepare(
        "SELECT strategic_company,strategic_status,strategic_expires_at
         FROM users WHERE id=? LIMIT 1"
    );
    $strategicCheck->execute([(int)$u['id']]);
    $strategicBenefit=$strategicCheck->fetch();

    if($strategicBenefit
        && (int)$strategicBenefit['strategic_company']===1
        && $strategicBenefit['strategic_status']==='active'
        && !empty($strategicBenefit['strategic_expires_at'])
        && strtotime($strategicBenefit['strategic_expires_at'])>time()
    ){
        out([
            'ok'=>false,
            'code'=>'strategic_benefit_active',
            'error'=>'Tu beneficio de Empresa Estrategica esta activo. Disfruta de tu plan gratuito hasta la fecha de vencimiento, sin necesidad de contratar otro plan.',
            'strategic_expires_at'=>$strategicBenefit['strategic_expires_at']
        ],409);
    }

    $plan=(string)($d['plan']??''); $meta=planMeta($plan);
    if(!$meta) out(['ok'=>false,'error'=>'Plan no válido.'],422);
    $prices=$config['stripe_prices']??[]; $price=(string)($prices[$plan]??''); if($price===''||str_contains($price,'REEMPLAZAR')) out(['ok'=>false,'error'=>'Los precios de Stripe aún no están configurados.'],503);
    $existing=subscriptionForUser($db,(int)$u['id']); if($existing && in_array($existing['status'],['active','trialing','past_due'],true)) out(['ok'=>false,'error'=>'Ya tienes un plan de pago activo. Gestiona tu suscripción desde el panel.'],409);
    $success=(string)($config['stripe_success_url']??'https://conectaobra10.es/pago-ok.html?session_id={CHECKOUT_SESSION_ID}');$cancel=(string)($config['stripe_cancel_url']??'https://conectaobra10.es/pago-cancelado.html');
    $params=['mode'=>'subscription','success_url'=>$success,'cancel_url'=>$cancel,'client_reference_id'=>(string)$u['id'],'customer_email'=>$u['email'],'billing_address_collection'=>'required','tax_id_collection[enabled]'=>'true','automatic_tax[enabled]'=>'true','line_items[0][price]'=>$price,'line_items[0][quantity]'=>'1','metadata[user_id]'=>(string)$u['id'],'metadata[plan]'=>$plan,'subscription_data[metadata][user_id]'=>(string)$u['id'],'subscription_data[metadata][plan]'=>$plan];
    $session=stripeRequest($config,'POST','checkout/sessions',$params);
    $now=date('Y-m-d H:i:s');$db->prepare("INSERT INTO subscriptions(user_id,plan,billing_cycle,status,stripe_checkout_session_id,created_at,updated_at) VALUES(?,?,?,?,?,?,?)")->execute([(int)$u['id'],$plan,$meta['cycle'],'pending',$session['id']??null,$now,$now]);
    out(['ok'=>true,'url'=>$session['url']??'']);
}
if ($action === 'billing_portal') {
    $u=requireUser($db); $s=$db->prepare("SELECT stripe_customer_id FROM subscriptions WHERE user_id=? AND stripe_customer_id IS NOT NULL ORDER BY id DESC LIMIT 1");$s->execute([$u['id']]);$customer=(string)($s->fetchColumn()?:''); if($customer==='')out(['ok'=>false,'error'=>'Todavía no hay una suscripción de Stripe asociada a tu cuenta.'],409);
    $return='https://conectaobra10.es/panel.html';$portal=stripeRequest($config,'POST','billing_portal/sessions',['customer'=>$customer,'return_url'=>$return]);out(['ok'=>true,'url'=>$portal['url']??'']);
}
if ($action === 'stripe_webhook') {
    // Este endpoint debe recibir el cuerpo RAW de Stripe, sin pasar por body().
    $payload=file_get_contents('php://input')?:'';$sig=(string)($_SERVER['HTTP_STRIPE_SIGNATURE']??'');$secret=(string)($config['stripe_webhook_secret']??'');
    if(!stripeSignatureValid($payload,$sig,$secret)){http_response_code(400);echo json_encode(['ok'=>false,'error'=>'Firma de webhook no válida']);exit;}
    $event=json_decode($payload,true);if(!is_array($event)){http_response_code(400);exit;}
    $type=(string)($event['type']??'');$obj=$event['data']['object']??[];$now=date('Y-m-d H:i:s');
    try {
      if($type==='checkout.session.completed'){
        $uid=(int)($obj['metadata']['user_id']??$obj['client_reference_id']??0);$subId=(string)($obj['subscription']??'');$customer=(string)($obj['customer']??'');$sessionId=(string)($obj['id']??'');
        if($uid>0){$s=$db->prepare("SELECT id,plan,billing_cycle FROM subscriptions WHERE user_id=? AND stripe_checkout_session_id=? ORDER BY id DESC LIMIT 1");$s->execute([$uid,$sessionId]);$row=$s->fetch();if($row){$db->prepare("UPDATE subscriptions SET status=?,stripe_customer_id=?,stripe_subscription_id=?,updated_at=? WHERE id=?")->execute(['active',$customer,$subId,$now,$row['id']]);}}
      } elseif(in_array($type,['customer.subscription.created','customer.subscription.updated','customer.subscription.deleted'],true)) {
        $subId=(string)($obj['id']??'');$customer=(string)($obj['customer']??'');$status=(string)($obj['status']??'');if($type==='customer.subscription.deleted')$status='canceled';$uid=(int)($obj['metadata']['user_id']??0);
        $s=$db->prepare("SELECT id FROM subscriptions WHERE stripe_subscription_id=? OR (stripe_customer_id=? AND user_id=?) ORDER BY id DESC LIMIT 1");$s->execute([$subId,$customer,$uid]);$row=$s->fetch();
        if($row){$db->prepare("UPDATE subscriptions SET status=?,stripe_customer_id=?,stripe_subscription_id=?,current_period_end=?,cancel_at_period_end=?,updated_at=? WHERE id=?")->execute([$status,$customer,$subId,stripeDate((int)($obj['current_period_end']??0)),!empty($obj['cancel_at_period_end'])?1:0,$now,$row['id']]);}
      } elseif($type==='invoice.paid' || $type==='invoice.payment_failed') {
        $subscriptionRef = $obj['subscription'] ?? null;
        if ($subscriptionRef === null || $subscriptionRef === '') {
          $subscriptionRef = $obj['parent']['subscription_details']['subscription'] ?? null;
        }
        if (is_array($subscriptionRef)) {
          $subscriptionRef = $subscriptionRef['id'] ?? null;
        }
        $obj['subscription'] = is_string($subscriptionRef) ? $subscriptionRef : '';

        if ($type==='invoice.paid') {
          require_once __DIR__.'/sales-stripe-commissions.php';
          coRegisterSalesCommission($db, $obj);
        }

        $subId=(string)($obj['subscription']??'');if($subId!==''){$status=$type==='invoice.paid'?'active':'past_due';$db->prepare("UPDATE subscriptions SET status=?,updated_at=? WHERE stripe_subscription_id=?")->execute([$status,$now,$subId]);}
      }
      http_response_code(200);echo json_encode(['received'=>true]);exit;
    } catch(Throwable $e){error_log('ConectaObra Stripe webhook error: '.$e->getMessage());http_response_code(500);echo json_encode(['ok'=>false]);exit;}
}


if ($action === 'invitation_get') {
    $token=trim((string)($_GET['token']??'')); $inv=invitationByToken($db,$token);
    if(!$inv) out(['ok'=>false,'error'=>'La invitación no es válida.'],404);
    if($inv['status']==='accepted') out(['ok'=>false,'error'=>'Esta invitación ya ha sido utilizada.'],409);
    if($inv['status']!=='pending' || strtotime((string)$inv['expires_at'])<=time()) {
        if($inv['status']==='pending') $db->prepare("UPDATE company_invitations SET status='expired',updated_at=? WHERE id=?")->execute([date('Y-m-d H:i:s'),$inv['id']]);
        out(['ok'=>false,'error'=>'La invitación ha caducado. Solicita a ConectaObra un nuevo enlace.'],410);
    }
    $inviteDescription=trim((string)($inv['descripcion']??'')); if($inviteDescription==='Esta empresa está invitada a ConectaObra y todavía no ha activado su perfil.') $inviteDescription='';
    out(['ok'=>true,'invitation'=>[
      'company'=>$inv['company'],'cif'=>(str_starts_with((string)$inv['cif'],'PEND-')?'':$inv['cif']),'contacto'=>$inv['company_contacto']?:$inv['contacto'],'email'=>$inv['email_destino'],'telefono'=>$inv['telefono'],'province'=>$inv['province'],'tipo'=>$inv['tipo']==='Empresa Invitada'?'Constructora / promotora':$inv['tipo'],'especialidad'=>$inv['especialidad'],'zona'=>$inv['zona'],'descripcion'=>$inviteDescription,'web'=>$inv['web'],'founder'=>!empty($inv['founder_slot_no']),'founder_slot'=>$inv['founder_slot_no'],'strategic_invitation'=>(int)($inv['strategic_invitation']??0)===1,'strategic_plan'=>((int)($inv['strategic_invitation']??0)===1 && in_array((string)($inv['strategic_plan']??''),['professional','premium'],true))?$inv['strategic_plan']:null,'expires_at'=>$inv['expires_at']
    ]]);
}
function coSendCompanyWelcome(
    array $config, string $email, string $contacto, string $empresa, int $userId
): void {
    $subject = 'Bienvenido a ConectaObra';
    $content =
        "Hola {$contacto},\n\n".
        "Tu empresa, {$empresa}, ya está registrada en ConectaObra.\n\n".
        "Desde tu cuenta puedes conectar con empresas del sector de la construcción ".
        "y descubrir oportunidades de colaboración.\n\n".
        "Accede a tu cuenta:\nhttps://conectaobra10.es/login.html\n\n".
        "Si necesitas ayuda, escríbenos a info@conectaobra10.es.\n\n".
        "El equipo de ConectaObra\n";

    try {
        if (!smtpSend($config, $email, $subject, $content, 'info@conectaobra10.es')) {
            error_log('ConectaObra: bienvenida SMTP fallida para user_id '.$userId);
        }
    } catch (Throwable $e) {
        error_log('ConectaObra: error al enviar la bienvenida para user_id '.$userId);
    }
}

if ($action === 'invitation_activate') {
    $d=body(); $token=trim((string)($d['token']??'')); $inv=invitationByToken($db,$token);
    if(!$inv) out(['ok'=>false,'error'=>'La invitación no es válida.'],404);
    if($inv['status']==='accepted') out(['ok'=>false,'error'=>'Esta invitación ya ha sido utilizada.'],409);
    if($inv['status']!=='pending' || strtotime((string)$inv['expires_at'])<=time()) out(['ok'=>false,'error'=>'La invitación ha caducado. Solicita un nuevo enlace.'],410);
    if(empty($d['accept_conditions']) || empty($d['accept_privacy'])) out(['ok'=>false,'error'=>'Debes aceptar las Condiciones de Uso y confirmar que has leído la Política de Privacidad.'],422);
    $password=(string)($d['password']??''); if(strlen($password)<8) out(['ok'=>false,'error'=>'La contraseña debe tener al menos 8 caracteres.'],422);
    $empresa=validateLength((string)($d['empresa']??$inv['company']),160,'La empresa');
    $cif=validateLength((string)($d['cif']??''),30,'El CIF/NIF'); if($cif==='')out(['ok'=>false,'error'=>'Completa el CIF/NIF de la empresa.'],422);
    $contacto=validateLength((string)($d['contacto']??($inv['company_contacto']?:$inv['contacto'])),160,'El contacto');
    $telefono=validateLength((string)($d['telefono']??($inv['telefono']??'')),40,'El teléfono');
    $provincia=validateLength((string)($d['provincia']??$inv['province']),100,'La provincia'); if($provincia==='Vizcaya')$provincia='Bizkaia'; if(!validProvince($provincia))out(['ok'=>false,'error'=>'Selecciona una provincia válida.'],422);
    $tipo=validateLength((string)($d['tipo']??''),100,'El tipo de empresa'); $especialidad=validateLength((string)($d['especialidad']??''),190,'La especialidad');
    if($contacto===''||$telefono===''||$tipo===''||$especialidad==='') out(['ok'=>false,'error'=>'Completa contacto, teléfono, tipo de empresa y especialidad.'],422);
    $zona=validateLength((string)($d['zona']??''),255,'La zona'); $descripcion=validateLength((string)($d['descripcion']??''),10000,'La descripción'); $web=validateLength((string)($d['web']??''),255,'La web');
    if($web!==''&&(!filter_var($web,FILTER_VALIDATE_URL)||!preg_match('~^https?://~i',$web)))out(['ok'=>false,'error'=>'La web debe empezar por http:// o https://'],422);
    $email=strtolower(trim((string)$inv['email_destino'])); $now=date('Y-m-d H:i:s');
    try {
      $db->beginTransaction();
      $lock=$db->prepare("SELECT * FROM company_invitations WHERE id=? FOR UPDATE");$lock->execute([$inv['id']]);$current=$lock->fetch();
      if(!$current||$current['status']!=='pending'||strtotime((string)$current['expires_at'])<=time()){ $db->rollBack(); out(['ok'=>false,'error'=>'La invitación ya no está disponible.'],409); }
      $founderCompany=0;$founderStarted=null;$founderExpires=null;$founderStatus='none';$slotNo=$current['founder_slot_no']!==null?(int)$current['founder_slot_no']:null;
      if($slotNo!==null){
        $slot=$db->prepare("SELECT user_id FROM founder_slots WHERE slot_no=? FOR UPDATE");$slot->execute([$slotNo]);$slotRow=$slot->fetch();
        if(!$slotRow || $slotRow['user_id']!==null){$db->rollBack();out(['ok'=>false,'error'=>'La plaza fundadora reservada ya no está disponible. Contacta con ConectaObra.'],409);}
        $founderCompany=1;$founderStarted=$now;$founderExpires=date('Y-m-d H:i:s',strtotime('+12 months'));$founderStatus='active';
      }
      $s=$db->prepare("INSERT INTO users (empresa,cif,contacto,email,telefono,provincia,tipo,especialidad,zona,password_hash,descripcion,web,founder_company,founder_started_at,founder_expires_at,founder_status,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
      $s->execute([$empresa,$cif,$contacto,$email,$telefono,$provincia,$tipo,$especialidad,$zona,password_hash($password,PASSWORD_DEFAULT),$descripcion,$web,$founderCompany,$founderStarted,$founderExpires,$founderStatus,$now,$now]);
      $userId=(int)$db->lastInsertId();

      // El beneficio estrategico procede exclusivamente de la invitacion bloqueada.
      $strategicInvitation=(int)($current['strategic_invitation']??0)===1;
      $strategicPlan=(string)($current['strategic_plan']??'');
      if($strategicInvitation){
        if($slotNo!==null || !in_array($strategicPlan,['professional','premium'],true)){
          $db->rollBack();
          out(['ok'=>false,'error'=>'La invitacion estrategica tiene una configuracion no valida. Contacta con ConectaObra.'],409);
        }
        $strategicExpires=date('Y-m-d H:i:s',strtotime('+12 months',strtotime($now)));
        $db->prepare("UPDATE users SET strategic_company=1,strategic_plan=?,strategic_started_at=?,strategic_expires_at=?,strategic_status='active' WHERE id=?")
           ->execute([$strategicPlan,$now,$strategicExpires,$userId]);
      }

      if($slotNo!==null)$db->prepare("UPDATE founder_slots SET user_id=?,claimed_at=? WHERE slot_no=? AND user_id IS NULL")->execute([$userId,$now,$slotNo]);
      $db->prepare("UPDATE invited_companies SET company=?,cif=?,contacto=?,email=?,telefono=?,province=?,tipo=?,especialidad=?,zona=?,descripcion=?,web=?,status='registered',registration_date=?,user_id=? WHERE id=?")->execute([$empresa,$cif,$contacto,$email,$telefono,$provincia,$tipo,$especialidad,$zona,$descripcion,$web,$now,$userId,$inv['invited_company_id']]);
      $db->prepare("UPDATE invited_companies SET status='registered',registration_date=COALESCE(registration_date,?),user_id=? WHERE status='invited' AND id<>? AND ((cif NOT LIKE 'PEND-%' AND cif=?) OR (email IS NOT NULL AND email<>'' AND email=?))")->execute([$now,$userId,$inv['invited_company_id'],$cif,$email]);
      $db->prepare("UPDATE company_invitations SET status='accepted',accepted_at=?,updated_at=? WHERE id=?")->execute([$now,$now,$inv['id']]);
      $db->commit();

      // Aviso administrativo de activación. Se envía después del commit para que un fallo de correo
      // nunca impida ni revierta la activación de la empresa.
      $adminNotifyEmail=strtolower(trim((string)($config['admin_email']??'info@conectaobra10.es')));
      if(!filter_var($adminNotifyEmail,FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/',$adminNotifyEmail)) $adminNotifyEmail='info@conectaobra10.es';
      $notifyPlan=$founderCompany===1
        ? 'Profesional - Empresa Fundadora'
        : ($strategicInvitation
            ? ($strategicPlan==='premium'?'Premium':'Profesional').' - Empresa Estratégica'
            : 'Cuenta estándar');
      $notifySubject=($founderCompany===1?'Nueva Empresa Fundadora activada':($strategicInvitation?'Nueva Empresa Estratégica activada':'Nueva empresa activada por invitación')).' - ConectaObra: '.$empresa;
      $notifyContent="Se ha completado una nueva activación en ConectaObra.\n\n".
        "Empresa: {$empresa}\n".
        "CIF/NIF: {$cif}\n".
        "Contacto: {$contacto}\n".
        "Email: {$email}\n".
        "Provincia: {$provincia}\n".
        "Plan: {$notifyPlan}\n".
        "Plaza fundadora: ".($slotNo!==null?(string)$slotNo:'No aplica')."\n".
        "Fecha de activación: {$now}\n".
        "Vencimiento fundador: ".($founderExpires?:'No aplica')."\n".
        "Vencimiento estratégico: ".($strategicInvitation?$strategicExpires:'No aplica')."\n\n".
        "Puedes revisar la empresa desde el panel de administración de ConectaObra.\n";
      try {
        $notifySent=smtpSend($config,$adminNotifyEmail,$notifySubject,$notifyContent,'info@conectaobra10.es');
        if(!$notifySent) error_log('ConectaObra activation notification SMTP error for user '.$userId.'. Activation remains valid.');
      } catch (Throwable $mailError) {
        error_log('ConectaObra: error de aviso administrativo de activación para user_id '.$userId);
      }

      coSendCompanyWelcome($config, $email, $contacto, $empresa, $userId);

      session_regenerate_id(true);$_SESSION['uid']=$userId;csrfToken();
      out(['ok'=>true,'user'=>currentUser($db),'csrf'=>csrfToken(),'founder_awarded'=>$founderCompany===1,'founder_expires_at'=>$founderExpires]);
    } catch(PDOException $e){if($db->inTransaction())$db->rollBack();if($e->getCode()==='23000')out(['ok'=>false,'error'=>'El CIF o el email ya están registrados en ConectaObra.'],409);error_log('Invitation activation: '.$e->getMessage());out(['ok'=>false,'error'=>'No se pudo activar la empresa.'],500);}
}

function freeContactPeriod(DateTimeImmutable $registered, DateTimeImmutable $now): array
{
    $registered = $registered->setTimezone($now->getTimezone());
    $first = $registered->modify('first day of this month');
    $offset = max(0,
        ((int)$now->format('Y') - (int)$registered->format('Y')) * 12 +
        (int)$now->format('n') - (int)$registered->format('n')
    );
    $anniversary = static function (int $months) use ($first, $registered): DateTimeImmutable {
        $month = $first->modify('+'.$months.' months');
        return $month->setDate(
            (int)$month->format('Y'),
            (int)$month->format('n'),
            min((int)$registered->format('j'), (int)$month->format('t'))
        );
    };

    if ($offset > 0 && $now < $anniversary($offset)) {
        $offset--;
    }

    return [
        'month' => $offset + 1,
        'limit' => max(1, 3 - $offset),
        'start' => $anniversary($offset),
        'end' => $anniversary($offset + 1),
    ];
}

function companyContactQuota(
    PDO $db, int $userId, array $plan, DateTimeImmutable $now
): array {
    if ($plan['key'] === 'free') {
        $q = $db->prepare('SELECT created_at FROM users WHERE id = ?');
        $q->execute([$userId]);
        $created = $q->fetchColumn();
        if (!is_string($created) || $created === '') {
            throw new RuntimeException('Fecha de alta no disponible.');
        }
        $period = freeContactPeriod(new DateTimeImmutable($created, $now->getTimezone()), $now);
    } else {
        $start = $now->modify('first day of this month')->setTime(0, 0);
        $period = [
            'month' => null,
            'limit' => $plan['contact_limit'] ?? null,
            'start' => $start,
            'end' => $start->modify('+1 month'),
        ];
    }

    $q = $db->prepare(
        'SELECT COUNT(*) FROM contact_events WHERE user_id = ? AND created_at >= ? AND created_at < ?'
    );
    $q->execute([
        $userId,
        $period['start']->format('Y-m-d H:i:s'),
        $period['end']->format('Y-m-d H:i:s'),
    ]);
    $used = (int)$q->fetchColumn();
    $limit = $period['limit'];

    return [
        'month' => $period['month'],
        'limit' => $limit,
        'used' => $used,
        'remaining' => $limit === null ? null : max(0, (int)$limit - $used),
        'resets_at' => $period['end']->format('Y-m-d H:i:s'),
    ];
}

if ($action === 'contact') {
    $d=body();$name=trim((string)($d['name']??''));$email=strtolower(trim((string)($d['email']??'')));$company=trim((string)($d['company']??''));$message=trim((string)($d['message']??''));$honeypot=trim((string)($d['website']??''));$targetId=(int)($d['target_id']??0);
    $contactUser=null; $contactPlan=null;
    if($targetId>0){
        $contactUser=requireUser($db); requireCsrf($d); $contactPlan=userPlan($db,(int)$contactUser['id']);
        $contactUserId = (int)$contactUser['id'];
        // Serializa la comprobación y el registro para esta empresa.
        $lockName = 'co_contact_'.sha1((string)$db->query('SELECT DATABASE()')->fetchColumn()).'_'.$contactUserId;
        $lock = $db->prepare('SELECT GET_LOCK(?, 5)');
        $lock->execute([$lockName]);
        if ((int)$lock->fetchColumn() !== 1) {
            out(['ok'=>false,'error'=>'Hay otro contacto en curso. Inténtalo de nuevo.'],409);
        }
        register_shutdown_function(static function () use ($db, $lockName): void {
            try {
                $db->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
            } catch (Throwable $e) {
                error_log('ConectaObra: no se pudo liberar el bloqueo de contactos.');
            }
        });
        $contactAt = new DateTimeImmutable();
        $quota = companyContactQuota($db, $contactUserId, $contactPlan, $contactAt);
        if ($quota['remaining'] === 0) {
            out(['ok'=>false,
                'error'=>'Has alcanzado el límite de contactos de tu período. Puedes mejorar tu plan para disponer de más contactos.',
                'code'=>'contact_limit'],409);
        }
    }
    if($honeypot!=='')out(['ok'=>true]); if($name===''||$email===''||$message==='')out(['ok'=>false,'error'=>'Completa nombre, email y mensaje.'],422); if(!filter_var($email,FILTER_VALIDATE_EMAIL)||preg_match('/[\r\n]/',$email))out(['ok'=>false,'error'=>'Email no válido.'],422); if(mb_strlen($name)>160||mb_strlen($company)>160||mb_strlen($message)>5000)out(['ok'=>false,'error'=>'El mensaje es demasiado largo.'],422); rateLimit($db,'contact','__ip__',3,600);
    $to='info@conectaobra10.es';$targetName='ConectaObra';
    if($targetId>0){$ts=$db->prepare("SELECT empresa,email FROM users WHERE id=? LIMIT 1");$ts->execute([$targetId]);$target=$ts->fetch();if(!$target)out(['ok'=>false,'error'=>'La empresa destinataria ya no está disponible.'],404);$to=strtolower(trim((string)$target['email']));if(!filter_var($to,FILTER_VALIDATE_EMAIL)||preg_match('/[\r\n]/',$to))out(['ok'=>false,'error'=>'La empresa no dispone de un email de contacto válido.'],409);$targetName=(string)$target['empresa'];}
    $subject=$targetId>0?'Nueva solicitud de contacto desde ConectaObra':'Nuevo mensaje desde ConectaObra';$content="Destinatario: {$targetName}\nNombre: {$name}\nEmail: {$email}\nEmpresa del remitente: ".($company?:'No indicada')."\n\nMensaje:\n{$message}\n";$headers=['MIME-Version: 1.0','Content-Type: text/plain; charset=UTF-8','From: ConectaObra <info@conectaobra10.es>',"Reply-To: {$email}"];
    $sent=smtpSend($config,$to,$subject,$content,$email);if(!$sent){error_log('ConectaObra contact mail error.');out(['ok'=>false,'error'=>'No se ha podido enviar el mensaje. Inténtalo más tarde.'],500);}
    if($targetId>0 && $contactUser){$db->prepare("INSERT INTO contact_events (user_id,target_user_id,created_at) VALUES (?,?,?)")->execute([(int)$contactUser['id'],$targetId,$contactAt->format('Y-m-d H:i:s')]);}
    out(['ok'=>true,'message'=>$targetId>0?'Solicitud enviada correctamente a la empresa.':'Mensaje enviado correctamente.']);
}
// Administración: acceso protegido mediante admin_email y admin_password_hash de config.php.
// Servicio de intermediación: independiente de los planes y de Stripe.
if ($action === 'mediation_create') {
    $u=requireUser($db); $d=body(); requireCsrf($d);
    rateLimit($db,'mediation_create',(string)$u['id'],5,3600);
    $subject=validateLength((string)($d['subject']??''),190,'El asunto');
    $details=validateLength((string)($d['details']??''),5000,'La descripción');
    if($subject==='' || $details==='') out(['ok'=>false,'error'=>'Completa el asunto y la descripción.'],422);
    $now=date('Y-m-d H:i:s');
    $db->prepare("INSERT INTO mediation_requests (user_id,subject,details,status,created_at,updated_at) VALUES (?,?,?,'pending',?,?)")->execute([(int)$u['id'],$subject,$details,$now,$now]);
    $id=(int)$db->lastInsertId();
    $adminEmail=trim((string)($config['admin_email']??''));
    $sender=trim((string)($config['smtp_user']??''));
    $notified=false;
    if(filter_var($adminEmail,FILTER_VALIDATE_EMAIL) && filter_var($sender,FILTER_VALIDATE_EMAIL)) {
        $notified=smtpSend($config,$adminEmail,'Nueva solicitud de intermediación #'.$id,
          "Nueva solicitud #$id\nEmpresa: ".$u['empresa']."\nContacto: ".$u['contacto']."\nEmail: ".$u['email']."\nAsunto: $subject\n\n$details\n\nRevisar en el panel de administración.",$sender);
    }
    if(!$notified) error_log('Intermediación #'.$id.': aviso por correo no enviado.');
    out(['ok'=>true,'id'=>$id,'email_notified'=>$notified,'message'=>'Solicitud registrada correctamente.']);
}
if ($action === 'mediation_mine') {
    $u=requireUser($db);
    $s=$db->prepare('SELECT id,subject,details,status,admin_notes,contract_amount,commission_percent,collected_amount,agreement_confirmed,payment_status,created_at,updated_at FROM mediation_requests WHERE user_id=? ORDER BY id DESC LIMIT 100');
    $s->execute([(int)$u['id']]);out(['ok'=>true,'requests'=>$s->fetchAll()]);
}
if (in_array($action, ['mediation_documents_list', 'mediation_document_upload', 'mediation_document_download'], true)) {
    $admin = !empty($_SESSION['admin']);
    $publico = !empty($_GET['cliente']);

    if ($action === 'mediation_document_upload') {
        if (!$admin) out(['ok'=>false,'error'=>'No autorizado.'],403);
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            out(['ok'=>false,'error'=>'Método no permitido.'],405);
        }
        requireCsrf($_POST);
    }

    $usuario = $admin && !$publico ? null : requireUser($db);
    $usuarioId = $usuario === null ? null : (int)$usuario['id'];
    $id = filter_var($_GET['mediation_id'] ?? null, FILTER_VALIDATE_INT, [
        'options'=>['min_range'=>1]
    ]);
    if ($id === false) out(['ok'=>false,'error'=>'Solicitud no válida.'],422);

    $consulta = $db->prepare('SELECT user_id FROM mediation_requests WHERE id=?');
    $consulta->execute([$id]);
    $propietario = $consulta->fetchColumn();
    if ($propietario === false || ($usuarioId !== null && (int)$propietario !== $usuarioId)) {
        out(['ok'=>false,'error'=>'Solicitud no encontrada.'],404);
    }

    $db->exec("CREATE TABLE IF NOT EXISTS mediation_documents (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        mediation_id INT UNSIGNED NOT NULL,
        document_name VARCHAR(190) NOT NULL,
        pdf MEDIUMBLOB NOT NULL,
        created_at DATETIME NOT NULL,
        KEY idx_mediation_documents (mediation_id,id),
        CONSTRAINT fk_mediation_documents_request
            FOREIGN KEY (mediation_id) REFERENCES mediation_requests(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    if ($action === 'mediation_documents_list') {
        $consulta = $db->prepare(
            'SELECT id,document_name,created_at FROM mediation_documents
             WHERE mediation_id=? ORDER BY id DESC'
        );
        $consulta->execute([$id]);
        out(['ok'=>true,'documents'=>$consulta->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($action === 'mediation_document_upload') {
        $archivo = $_FILES['pdf'] ?? null;
        if (!is_array($archivo) || ($archivo['error'] ?? null) !== UPLOAD_ERR_OK ||
            !is_string($archivo['tmp_name'] ?? null) || !is_uploaded_file($archivo['tmp_name'])) {
            out(['ok'=>false,'error'=>'Selecciona un PDF que se pueda subir.'],422);
        }
        $ruta = $archivo['tmp_name'];
        $tamano = filesize($ruta);
        if ($tamano === false || $tamano < 5 || $tamano > 5 * 1024 * 1024) {
            out(['ok'=>false,'error'=>'El PDF debe ocupar como máximo 5 MB.'],422);
        }
        $mime = new finfo(FILEINFO_MIME_TYPE);
        if ($mime->file($ruta) !== 'application/pdf' ||
            file_get_contents($ruta, false, null, 0, 5) !== '%PDF-') {
            out(['ok'=>false,'error'=>'El archivo debe ser un PDF.'],422);
        }

        $nombre = trim((string)($_POST['document_name'] ?? ''));
        if ($nombre === '') out(['ok'=>false,'error'=>'Escribe un nombre para identificar el documento.'],422);
        $nombre = validateLength($nombre, 190, 'El nombre del documento');

        $contenido = file_get_contents($ruta);
        if ($contenido === false) out(['ok'=>false,'error'=>'No se pudo leer el PDF.'],500);
        $consulta = $db->prepare(
            'INSERT INTO mediation_documents (mediation_id,document_name,pdf,created_at)
             VALUES (?,?,?,?)'
        );
        $consulta->bindValue(1, $id, PDO::PARAM_INT);
        $consulta->bindValue(2, $nombre, PDO::PARAM_STR);
        $consulta->bindValue(3, $contenido, PDO::PARAM_LOB);
        $consulta->bindValue(4, date('Y-m-d H:i:s'), PDO::PARAM_STR);
        $consulta->execute();
        out(['ok'=>true],201);
    }

    $documentoId = filter_var($_GET['document_id'] ?? null, FILTER_VALIDATE_INT, [
        'options'=>['min_range'=>1]
    ]);
    if ($documentoId === false) out(['ok'=>false,'error'=>'Documento no válido.'],422);
    $consulta = $db->prepare('SELECT pdf FROM mediation_documents WHERE id=? AND mediation_id=?');
    $consulta->execute([$documentoId,$id]);
    $pdf = $consulta->fetchColumn();
    if ($pdf === false) out(['ok'=>false,'error'=>'Documento no encontrado.'],404);

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="mediacion-' . $id . '-documento-' . $documentoId . '.pdf"');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    echo $pdf;
    exit;
}
if ($action === 'admin_mediation_list') {
    if(empty($_SESSION['admin'])) out(['ok'=>false,'error'=>'No autorizado'],403);
    $s=$db->query('SELECT m.id,m.subject,m.details,m.status,m.admin_notes,m.contract_amount,m.commission_percent,m.collected_amount,m.agreement_confirmed,m.payment_status,m.created_at,m.updated_at,u.empresa,u.email,u.telefono FROM mediation_requests m JOIN users u ON u.id=m.user_id ORDER BY m.id DESC LIMIT 200');
    out(['ok'=>true,'requests'=>$s->fetchAll()]);
}
if ($action === 'admin_mediation_update') {
    if(empty($_SESSION['admin'])) out(['ok'=>false,'error'=>'No autorizado'],403);
    $d=body();requireCsrf($d);$id=(int)($d['id']??0);
    $status=(string)($d['status']??'');
    if($id<=0 || !in_array($status,['pending','reviewing','accepted','rejected','completed'],true)) out(['ok'=>false,'error'=>'Datos no válidos.'],422);
    $notes=validateLength((string)($d['admin_notes']??''),5000,'Las observaciones');
    $money=function($value,string $label,int $max=999999999999): ?string {
        if($value===null || $value==='') return null;
        if(!is_scalar($value) || !preg_match('/^\d{1,12}(?:\.\d{1,2})?$/',(string)$value) || (float)$value>$max)
            out(['ok'=>false,'error'=>$label.' no es válido. Usa números positivos con hasta 2 decimales.'],422);
        return number_format((float)$value,2,'.','');
    };
    $contract=$money($d['contract_amount']??null,'El importe del contrato');
    $percent=$money($d['commission_percent']??null,'El porcentaje de comisión',100);
    $collected=$money($d['collected_amount']??null,'El importe cobrado');
    $agreed=($d['agreement_confirmed']??false)===true || ($d['agreement_confirmed']??false)===1;
    $payment=(string)($d['payment_status']??'not_collected');
    if(!in_array($payment,['not_collected','partially_collected','collected'],true))out(['ok'=>false,'error'=>'Estado de cobro no válido.'],422);
    if($collected!==null && $contract!==null && (float)$collected>(float)$contract)out(['ok'=>false,'error'=>'El importe cobrado no puede superar el importe del contrato.'],422);
    if($payment==='collected' && ($contract===null || $collected===null || (float)$collected<(float)$contract))out(['ok'=>false,'error'=>'Para marcar cobrado, el importe cobrado debe alcanzar el del contrato.'],422);
    if($payment==='partially_collected' && ($collected===null || (float)$collected<=0))out(['ok'=>false,'error'=>'Indica un importe cobrado para el cobro parcial.'],422);
    if($payment==='not_collected' && $collected!==null && (float)$collected>0)out(['ok'=>false,'error'=>'Si hay un importe cobrado, indica cobro parcial o completo.'],422);
    if($agreed && ($percent===null || $contract===null))out(['ok'=>false,'error'=>'Para confirmar un acuerdo registra el contrato y el porcentaje pactado.'],422);
    $s=$db->prepare('UPDATE mediation_requests SET status=?,admin_notes=?,contract_amount=?,commission_percent=?,collected_amount=?,agreement_confirmed=?,payment_status=?,updated_at=? WHERE id=?');
    $s->execute([$status,$notes,$contract,$percent,$collected,$agreed?1:0,$payment,date('Y-m-d H:i:s'),$id]);
    if(!$s->rowCount()){ $check=$db->prepare('SELECT id FROM mediation_requests WHERE id=?');$check->execute([$id]);if(!$check->fetch())out(['ok'=>false,'error'=>'Solicitud no encontrada.'],404); }
    out(['ok'=>true]);
}
if ($action === 'admin_login') {
    $adminEmail=strtolower(trim((string)($config['admin_email']??'')));$hash=(string)($config['admin_password_hash']??'');$d=body();$email=strtolower(trim((string)($d['email']??'')));$pass=(string)($d['password']??'');
    rateLimit($db,'admin_login',$email,5,900);
    if($adminEmail===''||$hash===''||!password_verify($pass,$hash)||!hash_equals($adminEmail,$email))out(['ok'=>false,'error'=>'Credenciales de administrador incorrectas o administrador no configurado.'],401);
    clearRateLimit($db,'admin_login',$email);session_regenerate_id(true);$_SESSION['admin']=true;$_SESSION['admin_email']=$adminEmail;csrfToken();out(['ok'=>true,'csrf'=>csrfToken()]);
}
if ($action === 'admin_company_detail') {
    if (empty($_SESSION['admin'])) out(['ok'=>false,'error'=>'No autorizado'],403);
    $id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT);
    if (!$id || $id<=0) out(['ok'=>false,'error'=>'Empresa no válida'],422);
    $stmt=$db->prepare('SELECT id,empresa,cif,contacto,email,telefono,provincia,tipo,especialidad,zona,descripcion,web,logo,empleados,experiencia,disponibilidad,trabajos_realizados,certificaciones,areas_experiencia,founder_company,founder_started_at,founder_expires_at,founder_status,created_at,updated_at FROM users WHERE id=? LIMIT 1');
    $stmt->execute([$id]);$company=$stmt->fetch();
    if (!$company) out(['ok'=>false,'error'=>'Empresa no encontrada'],404);
    $company=normalizeDisplayLabels($company);
    $plan=userPlan($db,$id);
    $subscription=$plan['subscription']??null;
    $opp=$db->prepare('SELECT id,title,province,category,status,created_at FROM opportunities WHERE user_id=? ORDER BY id DESC LIMIT 100');
    $opp->execute([$id]);
    $jobOffers=[];
    try {
        $job=$db->prepare('SELECT id,titulo,provincia,estado,created_at FROM job_ofertas WHERE empresa_id=? ORDER BY id DESC LIMIT 100');
        $job->execute([$id]);$jobOffers=$job->fetchAll();
    } catch (PDOException $e) {
        error_log('ConectaObra admin ConectaJob: '.$e->getMessage());
    }
    out(['ok'=>true,'company'=>$company,'plan'=>['key'=>$plan['key'],'label'=>$plan['label']], 'subscription'=>$subscription,'opportunities'=>$opp->fetchAll(),'job_offers'=>$jobOffers]);
}
if ($action === 'admin_me') { if(empty($_SESSION['admin']))out(['ok'=>false,'error'=>'No autorizado'],403);out(['ok'=>true,'admin'=>$_SESSION['admin_email']??true,'csrf'=>csrfToken()]); }
$adminActions=['admin_stats','admin_companies','admin_invited_companies','admin_invitations','admin_opportunities','admin_set_opportunity_status','admin_delete_opportunity','admin_delete_company','admin_create_invitation','admin_update_invited','admin_generate_invitation_for_invited','admin_resend_invitation'];
if (in_array($action,$adminActions,true)) {
    if(empty($_SESSION['admin']))out(['ok'=>false,'error'=>'No autorizado'],403);
    if($action==='admin_stats'){
      $founders=(int)$db->query("SELECT COUNT(*) FROM users WHERE founder_company=1 AND founder_status='active' AND founder_expires_at>NOW()")->fetchColumn();
      $pending=(int)$db->query("SELECT COUNT(*) FROM company_invitations WHERE status='pending' AND expires_at>NOW() AND founder_slot_no IS NOT NULL")->fetchColumn();
      $available=(int)$db->query("SELECT COUNT(*) FROM founder_slots fs WHERE fs.user_id IS NULL AND NOT EXISTS (SELECT 1 FROM company_invitations ci WHERE ci.founder_slot_no=fs.slot_no AND ci.status='pending' AND ci.expires_at>NOW())")->fetchColumn();
      out(['ok'=>true,'stats'=>['companies'=>(int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn(),'invited'=>(int)$db->query("SELECT COUNT(*) FROM invited_companies WHERE status='invited'")->fetchColumn(),'founders_active'=>$founders,'founders_pending'=>$pending,'founder_available'=>$available,'active_opportunities'=>(int)$db->query("SELECT COUNT(*) FROM opportunities WHERE status='active'")->fetchColumn()]]);
    }
    if ($action === 'admin_companies' && isset($_GET['plan_user_id'])) {
        $id = filter_var($_GET['plan_user_id'], FILTER_VALIDATE_INT);
        if (!$id || $id <= 0) {
            out(['ok'=>false,'error'=>'Empresa no válida'],422);
        }

        $company = $db->prepare('SELECT id FROM users WHERE id = ?');
        $company->execute([$id]);
        if (!$company->fetchColumn()) {
            out(['ok'=>false,'error'=>'Empresa no encontrada'],404);
        }

        $plan = userPlan($db, $id, false);
        $subscription = $plan['subscription'] ?? null;
        if (!$subscription) {
            $last = $db->prepare(
                'SELECT plan, status FROM subscriptions WHERE user_id = ? ORDER BY id DESC LIMIT 1'
            );
            $last->execute([$id]);
            $subscription = $last->fetch() ?: null;
        }
        $subscriptionMeta = $subscription ? planMeta((string)$subscription['plan']) : null;

        $benefit = null;
        if ($plan['key'] === 'founder_professional') {
            $benefit = ['label'=>'Empresa Fundadora','expires_at'=>$plan['founder_expires_at'] ?? null];
        } elseif (str_starts_with((string)$plan['key'], 'strategic_')) {
            $benefit = ['label'=>'Empresa Estratégica','expires_at'=>$plan['strategic_expires_at'] ?? null];
        }

        $label = $plan['label'];
        if ($benefit) {
            $label = $plan['key'] === 'strategic_premium' ? 'Premium' : 'Profesional';
        } elseif ($subscription && in_array($plan['key'], [
            'professional_monthly', 'professional_yearly', 'premium_monthly', 'premium_yearly',
        ], true)) {
            $label .= str_ends_with($plan['key'], '_yearly') ? ' anual' : ' mensual';
        }

        out(['ok'=>true,'plan_summary'=>[
            'label'=>$label,
            'contact_quota'=>companyContactQuota($db, $id, $plan, new DateTimeImmutable()),
            'subscription_status'=>$subscription['status'] ?? null,
            'subscription_plan'=>$subscriptionMeta['label'] ?? null,
            'benefit'=>$benefit,
        ]]);
    }
    if($action==='admin_companies'){out(['ok'=>true,'companies'=>$db->query("SELECT id,empresa,cif,contacto,email,telefono,provincia,tipo,especialidad,founder_company,founder_status,founder_expires_at,strategic_company,strategic_plan,strategic_status,strategic_expires_at,created_at FROM users ORDER BY id DESC LIMIT 500")->fetchAll()]);}
    if($action==='admin_invited_companies'){out(['ok'=>true,'companies'=>$db->query("SELECT ic.id,ic.company,CASE WHEN ic.cif LIKE 'PEND-%' THEN '' ELSE ic.cif END AS cif,ic.contacto,ic.email,ic.telefono,ic.province,ic.tipo,ic.especialidad,ic.zona,ic.web,ic.status,ic.invitation_date,ci.id AS invitation_id,ci.status AS invitation_status,ci.sent_at,ci.expires_at,ci.founder_slot_no,ci.strategic_invitation,ci.strategic_plan FROM invited_companies ic LEFT JOIN company_invitations ci ON ci.id=(SELECT ci2.id FROM company_invitations ci2 WHERE ci2.invited_company_id=ic.id ORDER BY ci2.id DESC LIMIT 1) WHERE ic.status='invited' ORDER BY ic.id DESC LIMIT 500")->fetchAll()]);}
    if($action==='admin_invitations'){out(['ok'=>true,'invitations'=>$db->query("SELECT ci.id,ci.invited_company_id,ic.company,ic.cif,ci.email_destino,ci.contacto,ci.founder_slot_no,ci.strategic_invitation,ci.strategic_plan,ci.status,ci.sent_at,ci.expires_at,ci.accepted_at FROM company_invitations ci JOIN invited_companies ic ON ic.id=ci.invited_company_id ORDER BY ci.id DESC LIMIT 500")->fetchAll()]);}
    if($action==='admin_opportunities'){out(['ok'=>true,'opportunities'=>$db->query("SELECT o.id,o.user_id,o.title,o.province,o.category,o.description,o.status,o.created_at,u.empresa FROM opportunities o JOIN users u ON u.id=o.user_id ORDER BY o.id DESC LIMIT 500")->fetchAll()]);}
    $d=body();requireCsrf($d);
    if($action==='admin_update_invited'){
      $id=(int)($d['id']??0);if($id<=0)out(['ok'=>false,'error'=>'Empresa invitada no válida.'],422);
      $company=validateLength((string)($d['company']??''),160,'La empresa');$cif=validateLength((string)($d['cif']??''),30,'El CIF');$contacto=validateLength((string)($d['contacto']??''),160,'El contacto');$email=strtolower(trim((string)($d['email']??'')));$telefono=validateLength((string)($d['telefono']??''),40,'El teléfono');$province=validateLength((string)($d['province']??''),100,'La provincia');if($province==='Vizcaya')$province='Bizkaia';$tipo=validateLength((string)($d['tipo']??'Empresa Invitada'),100,'El tipo');$especialidad=validateLength((string)($d['especialidad']??''),190,'La especialidad');$zona=validateLength((string)($d['zona']??''),255,'La zona');$web=validateLength((string)($d['web']??''),255,'La web');
      if($company===''||$contacto===''||$email===''||$province==='')out(['ok'=>false,'error'=>'Completa empresa, contacto, email y provincia.'],422);if(!filter_var($email,FILTER_VALIDATE_EMAIL)||preg_match('/[\r\n]/',$email))out(['ok'=>false,'error'=>'Email no válido.'],422);if(!validProvince($province))out(['ok'=>false,'error'=>'Provincia no válida.'],422);if($web!==''&&(!filter_var($web,FILTER_VALIDATE_URL)||!preg_match('~^https?://~i',$web)))out(['ok'=>false,'error'=>'La web debe empezar por http:// o https://'],422);
      $q=$db->prepare("SELECT cif,status FROM invited_companies WHERE id=? LIMIT 1");$q->execute([$id]);$old=$q->fetch();if(!$old)out(['ok'=>false,'error'=>'Empresa invitada no encontrada.'],404);if($old['status']!=='invited')out(['ok'=>false,'error'=>'La empresa ya no está pendiente.'],409);
      $storedCif=$cif!==''?$cif:(trim((string)$old['cif'])!==''?(string)$old['cif']:'PEND-'.strtoupper(bin2hex(random_bytes(8))));
      $db->prepare("UPDATE invited_companies SET company=?,cif=?,contacto=?,email=?,telefono=?,province=?,tipo=?,especialidad=?,zona=?,web=? WHERE id=?")->execute([$company,$storedCif,$contacto,$email,$telefono,$province,$tipo,$especialidad,$zona,$web,$id]);
      $db->prepare("UPDATE company_invitations SET email_destino=?,contacto=?,updated_at=? WHERE invited_company_id=? AND status='pending'")->execute([$email,$contacto,date('Y-m-d H:i:s'),$id]);out(['ok'=>true,'message'=>'Datos de la empresa actualizados. La invitación pendiente se mantiene.']);
    }
    if($action==='admin_create_invitation'){
        $d['founder']=false; // Programa fundador cerrado para nuevas invitaciones
        $strategicPlan=trim((string)($d['strategic_plan']??''));
        if(!in_array($strategicPlan,['','professional','premium'],true))
            out(['ok'=>false,'error'=>'Selecciona un plan estrategico valido.'],422);
        $strategicInvitation=$strategicPlan!==''?1:0;
      $company=validateLength((string)($d['company']??''),160,'La empresa');$cif=validateLength((string)($d['cif']??''),30,'El CIF');$contacto=validateLength((string)($d['contacto']??''),160,'El contacto');$email=strtolower(trim((string)($d['email']??'')));$telefono=validateLength((string)($d['telefono']??''),40,'El teléfono');$province=validateLength((string)($d['province']??''),100,'La provincia');if($province==='Vizcaya')$province='Bizkaia';
      $tipo=validateLength((string)($d['tipo']??'Empresa Invitada'),100,'El tipo');$especialidad=validateLength((string)($d['especialidad']??''),190,'La especialidad');$zona=validateLength((string)($d['zona']??''),255,'La zona');$web=validateLength((string)($d['web']??''),255,'La web');$founder=!isset($d['founder'])||!empty($d['founder']);
      if($company===''||$contacto===''||$email===''||$province==='')out(['ok'=>false,'error'=>'Completa empresa, contacto, email y provincia.'],422);if(!filter_var($email,FILTER_VALIDATE_EMAIL)||preg_match('/[\r\n]/',$email))out(['ok'=>false,'error'=>'Email no válido.'],422);if(!validProvince($province))out(['ok'=>false,'error'=>'Provincia no válida.'],422);if($web!==''&&(!filter_var($web,FILTER_VALIDATE_URL)||!preg_match('~^https?://~i',$web)))out(['ok'=>false,'error'=>'La web debe empezar por http:// o https://'],422);
      $dup=$cif!==''?$db->prepare("SELECT id FROM users WHERE cif=? OR email=? LIMIT 1"):$db->prepare("SELECT id FROM users WHERE email=? LIMIT 1");$dup->execute($cif!==''?[$cif,$email]:[$email]);if($dup->fetch())out(['ok'=>false,'error'=>'Esta empresa o email ya está registrado en ConectaObra.'],409);
      try{$db->beginTransaction();
        $existing=$cif!==''?$db->prepare("SELECT id,status FROM invited_companies WHERE cif=? LIMIT 1 FOR UPDATE"):$db->prepare("SELECT id,status FROM invited_companies WHERE email=? LIMIT 1 FOR UPDATE");$existing->execute([$cif!==''?$cif:$email]);$row=$existing->fetch();
        if($row && $row['status']==='registered'){$db->rollBack();out(['ok'=>false,'error'=>'Esta empresa ya está registrada.'],409);}
        if($row){$invitedId=(int)$row['id'];$db->prepare("UPDATE invited_companies SET company=?,contacto=?,email=?,telefono=?,province=?,tipo=?,especialidad=?,zona=?,web=?,status='invited' WHERE id=?")->execute([$company,$contacto,$email,$telefono,$province,$tipo,$especialidad,$zona,$web,$invitedId]);}
        else{$storedCif=$cif!==''?$cif:'PEND-'.strtoupper(bin2hex(random_bytes(8)));$db->prepare("INSERT INTO invited_companies (company,cif,contacto,email,telefono,province,tipo,especialidad,zona,web,status,invitation_date,source) VALUES (?,?,?,?,?,?,?,?,?,?,'invited',?,'admin')")->execute([$company,$storedCif,$contacto,$email,$telefono,$province,$tipo,$especialidad,$zona,$web,date('Y-m-d H:i:s')]);$invitedId=(int)$db->lastInsertId();}
        $db->prepare("UPDATE company_invitations SET status='revoked',updated_at=? WHERE invited_company_id=? AND status='pending'")->execute([date('Y-m-d H:i:s'),$invitedId]);
        $slotNo=null;if($founder){$st=$db->query("SELECT fs.slot_no FROM founder_slots fs WHERE fs.user_id IS NULL AND NOT EXISTS (SELECT 1 FROM company_invitations ci WHERE ci.founder_slot_no=fs.slot_no AND ci.status='pending' AND ci.expires_at>NOW()) ORDER BY fs.slot_no LIMIT 1 FOR UPDATE");$slot=$st->fetch();if(!$slot){$db->rollBack();out(['ok'=>false,'error'=>'No quedan plazas de Empresa Fundadora disponibles.'],409);}$slotNo=(int)$slot['slot_no'];}
        $token=bin2hex(random_bytes(32));$hash=hash('sha256',$token);$now=date('Y-m-d H:i:s');$expires=date('Y-m-d H:i:s',strtotime('+7 days'));
        $db->prepare("INSERT INTO company_invitations (invited_company_id,email_destino,contacto,token_hash,founder_slot_no,strategic_invitation,strategic_plan,status,sent_at,expires_at,created_at,updated_at) VALUES (?,?,?,?,?,?,?,'pending',NULL,?,?,?)")->execute([$invitedId,$email,$contacto,$hash,$slotNo,$strategicInvitation,$strategicPlan!==''?$strategicPlan:null,$expires,$now,$now]);$invitationId=(int)$db->lastInsertId();$db->commit();
        out(['ok'=>true,'sent'=>false,'manual_delivery'=>true,'invitation_id'=>$invitationId,'founder_slot'=>$slotNo,'expires_at'=>$expires,'invitation_url'=>invitationUrl($config,$token),'message'=>'Enlace generado. No se ha enviado ningún correo: cópialo y envíalo tú por email, WhatsApp o LinkedIn.']);
      }catch(PDOException $e){if($db->inTransaction())$db->rollBack();if($e->getCode()==='23000')out(['ok'=>false,'error'=>'Ya existe una empresa o invitación con esos datos.'],409);error_log('Admin invitation create: '.$e->getMessage());out(['ok'=>false,'error'=>'No se pudo crear la invitación.'],500);}
    }
    if($action==='admin_generate_invitation_for_invited'){
        out(['ok'=>false,'error'=>'El programa de Empresas Fundadoras está cerrado para nuevas invitaciones.'],403);
      $id=(int)($d['id']??0);if($id<=0)out(['ok'=>false,'error'=>'Empresa invitada no válida.'],422);
      $q=$db->prepare("SELECT id,company,contacto,email,status FROM invited_companies WHERE id=? LIMIT 1");$q->execute([$id]);$ic=$q->fetch();if(!$ic)out(['ok'=>false,'error'=>'Empresa invitada no encontrada.'],404);if($ic['status']!=='invited')out(['ok'=>false,'error'=>'La empresa ya no está pendiente.'],409);
      $email=strtolower(trim((string)($ic['email']??'')));$contacto=trim((string)($ic['contacto']??''));if($email===''||$contacto==='')out(['ok'=>false,'error'=>'Completa primero el contacto y el email de la empresa.'],422);if(!filter_var($email,FILTER_VALIDATE_EMAIL)||preg_match('/[\r\n]/',$email))out(['ok'=>false,'error'=>'Email no válido.'],422);
      try{$db->beginTransaction();
        $db->prepare("UPDATE company_invitations SET status='revoked',updated_at=? WHERE invited_company_id=? AND status='pending'")->execute([date('Y-m-d H:i:s'),$id]);
        $st=$db->query("SELECT fs.slot_no FROM founder_slots fs WHERE fs.user_id IS NULL AND NOT EXISTS (SELECT 1 FROM company_invitations ci WHERE ci.founder_slot_no=fs.slot_no AND ci.status='pending' AND ci.expires_at>NOW()) ORDER BY fs.slot_no LIMIT 1 FOR UPDATE");$slot=$st->fetch();if(!$slot){$db->rollBack();out(['ok'=>false,'error'=>'No quedan plazas de Empresa Fundadora disponibles.'],409);}$slotNo=(int)$slot['slot_no'];
        $token=bin2hex(random_bytes(32));$hash=hash('sha256',$token);$now=date('Y-m-d H:i:s');$expires=date('Y-m-d H:i:s',strtotime('+7 days'));
        $db->prepare("INSERT INTO company_invitations (invited_company_id,email_destino,contacto,token_hash,founder_slot_no,status,sent_at,expires_at,created_at,updated_at) VALUES (?,?,?,?,?,'pending',NULL,?,?,?)")->execute([$id,$email,$contacto,$hash,$slotNo,$expires,$now,$now]);$invitationId=(int)$db->lastInsertId();$db->commit();
        out(['ok'=>true,'sent'=>false,'manual_delivery'=>true,'invitation_id'=>$invitationId,'founder_slot'=>$slotNo,'expires_at'=>$expires,'invitation_url'=>invitationUrl($config,$token),'message'=>'Enlace generado. No se ha enviado ningún correo: cópialo y envíalo tú por email, WhatsApp o LinkedIn.']);
      }catch(PDOException $e){if($db->inTransaction())$db->rollBack();error_log('Admin invited link create: '.$e->getMessage());out(['ok'=>false,'error'=>'No se pudo generar el enlace.'],500);}
    }
    if($action==='admin_resend_invitation'){
      $id=(int)($d['id']??0);if($id<=0)out(['ok'=>false,'error'=>'ID no válido.'],422);$q=$db->prepare("SELECT ci.*,ic.company FROM company_invitations ci JOIN invited_companies ic ON ic.id=ci.invited_company_id WHERE ci.id=? LIMIT 1");$q->execute([$id]);$old=$q->fetch();if(!$old)out(['ok'=>false,'error'=>'Invitación no encontrada.'],404);if($old['status']==='accepted')out(['ok'=>false,'error'=>'La empresa ya activó esta invitación.'],409);
      $slotNo=$old['founder_slot_no']!==null?(int)$old['founder_slot_no']:null;
      if($slotNo!==null){
        $check=$db->prepare("SELECT fs.user_id,(SELECT COUNT(*) FROM company_invitations ci WHERE ci.founder_slot_no=fs.slot_no AND ci.id<>? AND ci.status='pending' AND ci.expires_at>NOW()) AS reserved FROM founder_slots fs WHERE fs.slot_no=? LIMIT 1");$check->execute([$id,$slotNo]);$slot=$check->fetch();
        if(!$slot || $slot['user_id']!==null || (int)$slot['reserved']>0){out(['ok'=>false,'error'=>'La plaza fundadora original no está disponible.'],409);}
      }
      $token=bin2hex(random_bytes(32));$hash=hash('sha256',$token);$now=date('Y-m-d H:i:s');$expires=date('Y-m-d H:i:s',strtotime('+7 days'));$db->prepare("UPDATE company_invitations SET token_hash=?,founder_slot_no=?,status='pending',sent_at=NULL,expires_at=?,updated_at=? WHERE id=?")->execute([$hash,$slotNo,$expires,$now,$id]);out(['ok'=>true,'sent'=>false,'manual_delivery'=>true,'founder_slot'=>$slotNo,'expires_at'=>$expires,'invitation_url'=>invitationUrl($config,$token),'message'=>'Nuevo enlace generado. El enlace anterior queda invalidado. No se ha enviado ningún correo.']);
    }
    $id=(int)($d['id']??0);if($id<=0)out(['ok'=>false,'error'=>'ID no válido.'],422);
    if($action==='admin_set_opportunity_status'){ $status=(string)($d['status']??'');if(!in_array($status,['active','closed','draft'],true))out(['ok'=>false,'error'=>'Estado no válido.'],422);$db->prepare("UPDATE opportunities SET status=?,updated_at=? WHERE id=?")->execute([$status,date('Y-m-d H:i:s'),$id]);out(['ok'=>true]); }
    if($action==='admin_delete_opportunity'){ $db->prepare("DELETE FROM opportunities WHERE id=?")->execute([$id]);out(['ok'=>true]); }
    if($action==='admin_delete_company'){ $db->prepare("DELETE FROM users WHERE id=?")->execute([$id]);out(['ok'=>true]); }
}
if ($action === 'admin_logout') { unset($_SESSION['admin'],$_SESSION['admin_email']);out(['ok'=>true]); }
out(['ok'=>false,'error'=>'Acción no válida'],404);
