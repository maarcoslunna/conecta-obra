<?php
declare(strict_types=1);

// CONECTAOBRA_ACCESO_REGISTRADOS
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
header('Cache-Control: no-store, private');
if (empty($_SESSION['uid']) && empty($_SESSION['admin'])) {
    header('Cache-Control: no-store, private');
    header('Location: /login.html', true, 302);
    exit;
}

header('Content-Type: text/html; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
function h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function param(string $name, int $max = 100): string { $v = $_GET[$name] ?? ''; return is_string($v) ? mb_substr(trim($v), 0, $max) : ''; }
$q = param('q', 100);
$provincia = param('provincia', 100);
$cpv = param('cpv', 12);
$hasta = param('hasta', 10);
if ($hasta !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta) || !checkdate((int)substr($hasta,5,2),(int)substr($hasta,8,2),(int)substr($hasta,0,4)))) $hasta='';
if ($cpv !== '' && !preg_match('/^[0-9]{1,8}$/', $cpv)) $cpv='';
$page = max(1, min(10000, (int)($_GET['pagina'] ?? 1)));
$size = 20;
$error = false;
$rows = [];
$total = 0;
try {
    $pdo = require '/home/www/conectaobra_publicas_sync/conexion_publicas.php';
    if (!$pdo instanceof PDO) throw new RuntimeException('Conexión no disponible');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $where = ["deleted = 0", "status_code = 'PUB'", "deadline >= CURDATE()", "official_url LIKE 'https://%'"];
    $bind = [];
    if ($q !== '') { $where[] = '(title LIKE :qt OR organismo LIKE :qo OR expediente LIKE :qe)'; $like = '%'.addcslashes($q, '%_\\').'%'; $bind[':qt']=$like; $bind[':qo']=$like; $bind[':qe']=$like; }
    if ($provincia !== '') { $where[] = 'provincia = :provincia'; $bind[':provincia']=$provincia; }
    if ($cpv !== '') { $where[] = 'cpv LIKE :cpv'; $bind[':cpv']='%'.addcslashes($cpv, '%_\\').'%'; }
    if ($hasta !== '') { $where[] = 'deadline <= :hasta'; $bind[':hasta']=$hasta; }
    $filter = implode(' AND ', $where);
    $count = $pdo->prepare('SELECT COUNT(*) FROM co_licitaciones_publicas WHERE '.$filter);
    foreach ($bind as $k=>$v) $count->bindValue($k,$v,PDO::PARAM_STR);
    $count->execute(); $total = (int)$count->fetchColumn();
    $pages = max(1,(int)ceil($total/$size)); $page=min($page,$pages);
    $sql = 'SELECT title, official_url, organismo, provincia, cpv, deadline, expediente, presupuesto_sin_iva FROM co_licitaciones_publicas WHERE '.$filter.' ORDER BY deadline ASC, source_id_hash ASC LIMIT :limit OFFSET :offset';
    $stmt=$pdo->prepare($sql);
    foreach ($bind as $k=>$v) $stmt->bindValue($k,$v,PDO::PARAM_STR);
    $stmt->bindValue(':limit',$size,PDO::PARAM_INT);
    $stmt->bindValue(':offset',($page-1)*$size,PDO::PARAM_INT);
    $stmt->execute(); $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('ConectaObra publicas: '.$e->getMessage());
    http_response_code(503); $error=true;
}
function pageUrl(int $n): string { $params=$_GET; $params['pagina']=$n; return '?'.http_build_query($params, '', '&', PHP_QUERY_RFC3986); }
?><!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,follow"><title>Licitaciones públicas | ConectaObra</title>
<style>
:root{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;color:#183047;background:#f5f7fa}*{box-sizing:border-box}body{margin:0}header{background:#102d46;color:white;padding:22px max(20px,calc((100vw - 1080px)/2))}header a{color:#fff;text-decoration:none;font-weight:750;font-size:1.3rem}main{max-width:1080px;margin:30px auto;padding:0 18px}h1{font-size:clamp(1.7rem,4vw,2.5rem);margin-bottom:8px}p{line-height:1.5}.sub{color:#5a6877}.filters{display:grid;grid-template-columns:2fr 1fr 1fr 1fr auto;gap:10px;background:white;border:1px solid #e1e6ed;border-radius:12px;padding:18px;margin:24px 0}label{font-size:.82rem;font-weight:650;display:block;margin-bottom:6px}input{width:100%;padding:11px;border:1px solid #b9c5d1;border-radius:7px;font:inherit}button,.btn{border:0;background:#1765a0;color:white;padding:12px 16px;border-radius:8px;font:inherit;font-weight:650;cursor:pointer;text-decoration:none;display:inline-block}.submit{align-self:end}.results{display:flex;align-items:center;justify-content:space-between;gap:15px}.item{background:white;border:1px solid #e1e6ed;border-radius:12px;padding:20px;margin:12px 0}.item h2{font-size:1.12rem;margin:0 0 10px}.meta{display:flex;gap:10px 20px;flex-wrap:wrap;color:#526373;font-size:.9rem;margin:12px 0}.item a{color:#1262a0;font-weight:650}.pager{display:flex;justify-content:space-between;align-items:center;gap:10px;margin:24px 0}.pager a{padding:10px 16px;border:1px solid #c5d0db;border-radius:7px;background:white;text-decoration:none;color:#1262a0}footer{max-width:1080px;margin:40px auto;padding:0 18px 30px;color:#667482;font-size:.87rem}@media(max-width:850px){.filters{grid-template-columns:1fr 1fr}.filters>div:first-child{grid-column:1/-1}.submit{grid-column:1/-1}.results{align-items:start;flex-direction:column}}
</style></head><body><header><a href="/">ConectaObra</a></header><main>
<h1>Licitaciones públicas de construcción</h1><p class="sub">Consulta anuncios de contratación pública en España. Datos procedentes de fuentes oficiales; verifica siempre los detalles y plazos en el anuncio original.</p>
<form class="filters" method="get" action="">
<div><label for="q">Buscar por palabra o expediente</label><input id="q" name="q" maxlength="100" value="<?=h($q)?>" placeholder="Obra, rehabilitación, organismo..."></div>
<div><label for="provincia">Provincia (nombre exacto)</label><input id="provincia" name="provincia" maxlength="100" value="<?=h($provincia)?>" placeholder="Sevilla"></div>
<div><label for="cpv">Código CPV</label><input id="cpv" name="cpv" inputmode="numeric" maxlength="8" value="<?=h($cpv)?>" placeholder="45 o 71"></div>
<div><label for="hasta">Plazo hasta</label><input id="hasta" type="date" name="hasta" value="<?=h($hasta)?>"></div>
<button class="submit" type="submit">Buscar</button></form>
<?php if($error): ?><div class="item"><h2>Servicio temporalmente no disponible</h2><p>Estamos revisando la conexión. Inténtalo más tarde.</p></div>
<?php else: ?><div class="results"><strong><?=number_format($total,0,',','.')?> resultados</strong><span class="sub">Página <?=$page?> de <?=max(1,(int)ceil($total/$size))?></span></div>
<?php if (!$rows): ?><div class="item">No hay resultados con estos filtros. Prueba una búsqueda más amplia.</div><?php endif; ?>
<?php foreach($rows as $r): ?>
<article class="item"><h2><?=h($r['title'])?></h2><div class="meta">
<span><strong>Organismo:</strong> <?=h($r['organismo'] ?: 'No indicado')?></span>
<span><strong>Provincia:</strong> <?=h($r['provincia'] ?: 'No determinada')?></span>
<span><strong>Plazo:</strong> <?=h($r['deadline'])?></span>
<?php if ($r['expediente']): ?><span><strong>Expediente:</strong> <?=h($r['expediente'])?></span><?php endif; ?>
<?php if ($r['presupuesto_sin_iva'] !== null): ?><span><strong>Presupuesto sin IVA:</strong> <?=number_format((float)$r['presupuesto_sin_iva'],2,',','.')?> €</span><?php endif; ?>
</div><a href="<?=h($r['official_url'])?>" target="_blank" rel="noopener noreferrer nofollow">Ver anuncio en la fuente oficial ↗</a></article>
<?php endforeach; ?>
<?php if ($total>$size): ?><nav class="pager" aria-label="Paginación"><span><?php if($page>1): ?><a href="<?=h(pageUrl($page-1))?>">← Anterior</a><?php endif; ?></span><span><?php if($page<(int)ceil($total/$size)): ?><a href="<?=h(pageUrl($page+1))?>">Siguiente →</a><?php endif; ?></span></nav><?php endif; ?>
<?php endif; ?></main><footer>ConectaObra · Información orientativa. La situación y los plazos pueden cambiar; consulta siempre la fuente oficial. Actualización automática aún pendiente de activar.</footer></body></html>
