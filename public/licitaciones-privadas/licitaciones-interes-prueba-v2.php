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
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Licitaciones e interés | ConectaObra</title>
<style>
:root{font-family:system-ui,-apple-system,Segoe UI,Arial,sans-serif;color:#1d2c3b;background:#f4f6f8}*{box-sizing:border-box}body{margin:0}header{background:#1e2e3e;color:#fff;padding:22px max(20px,calc((100vw - 1050px)/2));font-weight:800}header span{color:#ff7500}main{max-width:1050px;margin:42px auto;padding:0 20px}h1{font-size:clamp(1.5rem,3vw,2rem);margin:0 0 8px}.intro{color:#526477;margin:0 0 28px}.notice{background:#fff;border:1px solid #e0e5e9;border-radius:12px;padding:24px}.cards{display:grid;gap:20px}.card{background:#fff;border:1px solid #e1e6eb;border-radius:14px;padding:26px;box-shadow:0 6px 18px #182d3d08}.card h2{margin:0 0 18px;font-size:1.3rem}.meta{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:15px}.meta strong{display:block;font-size:.84rem;margin-bottom:5px}.meta div{overflow-wrap:anywhere}.desc{margin-top:20px;padding:18px;background:#f5f7f9;border-radius:9px;white-space:pre-wrap;overflow-wrap:anywhere;line-height:1.55}.tag{display:inline-block;color:#276345;background:#e4f5e9;padding:5px 10px;border-radius:20px;font-size:.78rem;font-weight:700;margin-bottom:12px}.interest{margin-top:18px}.interest button{background:#ef7500;color:#fff;border:0;border-radius:8px;padding:12px 18px;font-weight:700;cursor:pointer}.interest button:disabled{background:#8995a0;cursor:not-allowed}.interest textarea{display:block;width:100%;max-width:650px;min-height:100px;margin:10px 0;padding:10px;border:1px solid #bac6cf;border-radius:8px;font:inherit}.interest small{display:block;margin-top:8px;color:#526477}.session-note{background:#fff8e9;border:1px solid #e9d7ad;border-radius:10px;padding:13px;margin-bottom:20px}footer{color:#637387;font-size:.85rem;margin:30px 0}a{color:#e76600}
</style></head><body><header>CONECTA<span>OBRA</span> · Licitaciones privadas</header><main><h1>Licitaciones aprobadas</h1><p class="intro">Oportunidades de obra revisadas por ConectaObra. </p>
<div id="session-note" class="session-note" role="status">Comprobando si has iniciado sesión como empresa en ConectaObra…</div>
 <?php if($error): ?><div class="notice" role="alert">No se pueden mostrar las licitaciones en este momento. Inténtalo más tarde.</div>
<?php elseif(!$items): ?><div class="notice">Todavía no hay licitaciones aprobadas disponibles.</div>
<?php else: ?><div class="cards">
<?php foreach($items as $item): ?><article class="card"><div class="tag">Licitación aprobada</div><h2><?=h((string)$item['title'])?></h2><div class="meta"><div><strong>Tipo de obra</strong><?=h((string)$item['work_type'])?></div><div><strong>Provincia</strong><?=h((string)$item['province'])?></div><div><strong>Municipio</strong><?=h((string)$item['municipality'])?></div><div><strong>Presupuesto aproximado</strong><?php if($item['budget']!==null): ?><?=h(number_format((float)$item['budget'],2,',','.'))?> €<?php else: ?>No indicado<?php endif; ?></div></div><div class="desc"><?=h((string)$item['description'])?></div><form class="interest" data-tender="<?= (int)$item['id'] ?>"><label>Mensaje a ConectaObra (15–2000 caracteres)<textarea required minlength="15" maxlength="2000" placeholder="Describe brevemente por qué tu empresa está interesada"></textarea></label><button type="submit" disabled>Me interesa esta obra</button><small role="status">Solo ConectaObra revisará esta solicitud. No se comparten datos con el anunciante automáticamente.</small></form></article><?php endforeach; ?>
</div><?php endif; ?><footer>Solo se muestran datos de la obra. Los datos personales de contacto no se publican. · <a href="publicar-licitacion.html">Publicar una solicitud</a></footer></main><script>
'use strict';
let sessionCsrf='';
(async function(){
 const note=document.getElementById('session-note');
 try {
  const r=await fetch('/api.php?action=me',{credentials:'same-origin',cache:'no-store',headers:{'Accept':'application/json'}});
  if(!r.ok)throw new Error('Respuesta no disponible');
  const data=await r.json();
  if(data && data.ok === true && data.user && Number(data.user.id)>0){
    sessionCsrf=String(data.csrf||'');
     note.textContent='Sesión de empresa detectada. Puedes enviar una solicitud de interés para revisión por ConectaObra.';
     if(sessionCsrf.length===64)document.querySelectorAll('.interest button').forEach(b=>b.disabled=false);
  }else{
    note.textContent='Para mostrar interés necesitarás iniciar sesión con tu cuenta de empresa de ConectaObra. Inicia sesión para mostrar interés.';
  }
 }catch(e){note.textContent='No se ha podido comprobar la sesión. El envío de solicitudes permanece desactivado.';}
})();
document.querySelectorAll('form.interest').forEach(form=>form.addEventListener('submit',async event=>{
 event.preventDefault();const button=form.querySelector('button');const status=form.querySelector('small');
 if(!sessionCsrf){status.textContent='Inicia sesión para continuar.';return;}
 button.disabled=true;status.textContent='Enviando solicitud…';
 try{const r=await fetch('licitaciones-interes-api.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({tender_id:Number(form.dataset.tender),message:form.querySelector('textarea').value,csrf:sessionCsrf})});
 const result=await r.json();status.textContent=String(result.message||'No se pudo completar la solicitud.');
 if(!r.ok)button.disabled=false;else form.querySelector('textarea').disabled=true;
 }catch(e){status.textContent='Error de conexión. Inténtalo de nuevo.';button.disabled=false;}
}));
</script></body></html>
