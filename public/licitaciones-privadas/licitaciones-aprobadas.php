<?php
declare(strict_types=1);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
function h(?string $v): string { return htmlspecialchars($v ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
$items=[]; $error=false;
try {
    $cfg=require dirname(__DIR__, 2).'/config_licitaciones/produccion/config.php';
    if (!is_array($cfg) || ($cfg['dbname']??'')!==($cfg['expected_dbname'] ?? '__sin_configurar__') || ($cfg['staging_only'] ?? false) !== false) throw new RuntimeException('Production config required');
    $dsn=sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $cfg['host'], (int)$cfg['port'], $cfg['dbname']);
    $db=new PDO($dsn,$cfg['user'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    $items=$db->query("SELECT id,title,work_type,province,municipality,budget,description FROM private_tenders WHERE status='approved' ORDER BY created_at DESC,id DESC LIMIT 100")->fetchAll();
} catch (Throwable $e) { $error=true; error_log('Tender public list failed: '.get_class($e)); }
?><!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Licitaciones aprobadas | ConectaObra</title>
<style>
:root{font-family:system-ui,-apple-system,Segoe UI,Arial,sans-serif;color:#1d2c3b;background:#f4f6f8}*{box-sizing:border-box}body{margin:0}header{background:#1e2e3e;color:#fff;padding:22px max(20px,calc((100vw - 1050px)/2));font-weight:800}header span{color:#ff7500}main{max-width:1050px;margin:42px auto;padding:0 20px}h1{font-size:clamp(1.5rem,3vw,2rem);margin:0 0 8px}.intro{color:#526477;margin:0 0 28px}.notice{background:#fff;border:1px solid #e0e5e9;border-radius:12px;padding:24px}.cards{display:grid;gap:20px}.card{background:#fff;border:1px solid #e1e6eb;border-radius:14px;padding:26px;box-shadow:0 6px 18px #182d3d08}.card h2{margin:0 0 18px;font-size:1.3rem}.meta{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:15px}.meta strong{display:block;font-size:.84rem;margin-bottom:5px}.meta div{overflow-wrap:anywhere}.desc{margin-top:20px;padding:18px;background:#f5f7f9;border-radius:9px;white-space:pre-wrap;overflow-wrap:anywhere;line-height:1.55}.tag{display:inline-block;color:#276345;background:#e4f5e9;padding:5px 10px;border-radius:20px;font-size:.78rem;font-weight:700;margin-bottom:12px}footer{color:#637387;font-size:.85rem;margin:30px 0}a{color:#e76600}
</style></head><body><header>CONECTA<span>OBRA</span> · Licitaciones privadas</header><main><h1>Licitaciones aprobadas</h1><p class="intro">Oportunidades de obra revisadas por ConectaObra. </p>
<?php if($error): ?><div class="notice" role="alert">No se pueden mostrar las licitaciones en este momento. Inténtalo más tarde.</div>
<?php elseif(!$items): ?><div class="notice">Todavía no hay licitaciones aprobadas disponibles.</div>
<?php else: ?><div class="cards">
<?php foreach($items as $item): ?><article class="card"><div class="tag">Licitación aprobada</div><h2><?=h((string)$item['title'])?></h2><div class="meta"><div><strong>Tipo de obra</strong><?=h((string)$item['work_type'])?></div><div><strong>Provincia</strong><?=h((string)$item['province'])?></div><div><strong>Municipio</strong><?=h((string)$item['municipality'])?></div><div><strong>Presupuesto aproximado</strong><?php if($item['budget']!==null): ?><?=h(number_format((float)$item['budget'],2,',','.'))?> €<?php else: ?>No indicado<?php endif; ?></div></div><div class="desc"><?=h((string)$item['description'])?></div></article><?php endforeach; ?>
</div><?php endif; ?><footer>Solo se muestran datos de la obra. Los datos personales de contacto no se publican. · <a href="publicar-licitacion.html">Publicar una solicitud</a></footer></main></body></html>
