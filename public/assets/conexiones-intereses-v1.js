(() => {
'use strict';
if(window.coInterestsLoaded)return;window.coInterestsLoaded=true;
const node=(tag,text,cls)=>{const e=document.createElement(tag);if(text!==undefined)e.textContent=text;if(cls)e.className=cls;return e;};
const labels={pending:'Pendiente',accepted:'Aceptada',rejected:'Rechazada',withdrawn:'Retirada',closed:'Cerrada'};
let csrf='',me=null,connections=[],manager=null,notice=null;
async function json(action,data){
 const opt={credentials:'same-origin',cache:'no-store'};
 if(data){opt.method='POST';opt.headers={'Content-Type':'application/json','X-CSRF-Token':csrf};opt.body=JSON.stringify({...data,_csrf:csrf});}
 const r=await fetch('api.php?action='+encodeURIComponent(action),opt);const d=await r.json();
 if(!r.ok||!d.ok)throw Error(d.error||'No se pudo completar la operación.');return d;
}
function status(text,error=false){if(notice){notice.textContent=text;notice.className=error?'coi-error':'coi-notice';}}
function modal(action,payload,title,share){
 if(typeof HTMLDialogElement==='undefined'){status('Actualiza tu navegador para confirmar esta conexión de forma segura.',true);return;}
 const d=node('dialog',undefined,'coi-modal');const form=node('form');const h=node('h2',title);h.id='coi-title';d.setAttribute('aria-labelledby',h.id);
 const p=node('p',share?'Al aceptar, la otra empresa podrá consultar los datos profesionales que autorices. Conversar no te obliga a contratar.':'Confirma el cambio de esta conexión.');
 form.append(h,p);let consent=null,message=null;
 if(share){
  form.append(node('p','Datos que autorizas: '+(me.email||'')+' · '+(me.telefono||'')));
  const label=node('label',undefined,'coi-consent');consent=node('input');consent.type='checkbox';consent.required=true;
  label.append(consent,node('span','Autorizo compartir con la otra empresa mi correo y teléfono profesional, junto con el nombre de mi empresa y persona de contacto, únicamente cuando ambas partes acepten esta conexión. Aceptar conversar no obliga a contratar.'));form.append(label);
  const privacy=node('a','Leer privacidad');privacy.href='privacidad.html';privacy.target='_blank';privacy.rel='noopener';form.append(privacy);
 }
 if(action==='connection_interest'){
  const label=node('label','Mensaje para la empresa autora (opcional)');message=node('textarea');message.maxLength=1000;message.rows=4;message.placeholder='Describe brevemente tu experiencia o disponibilidad. No incluyas teléfonos ni correos de terceros.';label.append(message);form.append(label);
 }
 const error=node('p',undefined,'coi-error');error.setAttribute('role','status');
 const buttons=node('div',undefined,'coi-actions');const confirm=node('button',share?'Autorizar y continuar':'Confirmar');confirm.type='submit';
 const cancel=node('button','Cancelar','coi-secondary');cancel.type='button';cancel.addEventListener('click',()=>d.close());buttons.append(confirm,cancel);form.append(error,buttons);d.append(form);document.body.append(d);
 form.addEventListener('submit',async e=>{
  e.preventDefault();if(share&&!consent.checked)return;confirm.disabled=true;cancel.disabled=true;error.textContent='Guardando…';
  try{const r=await json(action,{...payload,...(share?{share_contact:true}:{}),...(message?{message:message.value}:{})});d.close();status(r.message);await refresh(false);}
  catch(err){error.textContent=err.message;}finally{confirm.disabled=false;cancel.disabled=false;}
 });
 d.addEventListener('close',()=>d.remove(),{once:true});d.showModal();
}
function button(text,action,id,share=false){const b=node('button',text);b.type='button';b.addEventListener('click',()=>modal(action,{connection_id:id},text,share));return b;}
function render(){
 if(!manager)return;
 const list=manager.querySelector('[data-coi-list]');list.replaceChildren();
 if(!connections.length){list.append(node('p','Todavía no tienes propuestas de conexión. Puedes mostrar interés desde una oportunidad activa de otra empresa.'));return;}
 for(const c of connections){
  const card=node('article',undefined,'coi-card');card.append(node('h3',c.title),node('p',(c.direction==='incoming'?'Recibida de: ':'Enviada a: ')+(c.company||'Empresa no disponible')));
  card.append(node('p',[c.specialty,c.province].filter(Boolean).join(' · ')),node('p','Estado: '+labels[c.status]));
  if(c.message)card.append(node('p',c.message));
  if(c.opportunity_active){const a=node('a','Ver oportunidad');a.href='oportunidad.html?id='+encodeURIComponent(c.opportunity_id);card.append(a);}
  if(c.status==='pending'&&!c.opportunity_active)card.append(node('p','La oportunidad ya no está activa. No se puede aceptar esta propuesta.'));
  const actions=node('div',undefined,'coi-actions');
  if(c.status==='pending'&&c.direction==='incoming'){
   if(c.opportunity_active)actions.append(button('Aceptar conexión','connection_accept',c.id,true));
   actions.append(button('Rechazar','connection_reject',c.id));
  }
  if(c.status==='pending'&&c.direction==='outgoing')actions.append(button('Retirar interés','connection_withdraw',c.id));
  if(c.status==='accepted'&&c.contact){
   const info=node('div',undefined,'coi-contact');info.append(node('strong','Contacto profesional autorizado'));
   for(const [label,key] of [['Empresa','empresa'],['Persona','contacto'],['Correo','email'],['Teléfono','telefono']])info.append(node('p',label+': '+(c.contact[key]||'—')));
   info.append(node('p','Datos facilitados en el momento de la autorización. Utilízalos para esta colaboración y respeta su confidencialidad.'));
   card.append(info);actions.append(button('Cerrar conexión','connection_close',c.id));
  }
  card.append(actions);list.append(card);
 }
}
function addInterest(container,id){
 const existing=connections.find(c=>c.opportunity_id===id);
 const b=node('button',existing?'Ver mi conexión':'Me interesa · conexión gratuita');b.type='button';b.className='coi-interest';
 b.addEventListener('click',()=>{const current=connections.find(c=>c.opportunity_id===id);if(current){location.href='conexiones.html#intereses';return;}modal('connection_interest',{opportunity_id:id},'Mostrar interés en esta oportunidad',true);});container.append(b);
}
function decorate(){
 const list=document.getElementById('match-list');if(!list)return;
 list.querySelectorAll('.match:not([data-coi-ready])').forEach(card=>{
  const a=card.querySelector('a[href]');if(!a)return;const url=new URL(a.href,location.href);const id=Number(url.searchParams.get('id'));
  if(url.origin!==location.origin||!Number.isSafeInteger(id)||id<=0)return;card.dataset.coiReady='1';addInterest(card,id);
 });
}
async function refresh(show=true){const d=await json('connections_list');csrf=d.csrf;connections=d.connections;render();decorate();if(show)status('Conexiones actualizadas. Consulta los movimientos aquí y configura los avisos por correo al final de esta página.');return d;}
async function start(){
 const session=await json('me');if(!session.user)return;me=session.user;csrf=session.csrf;
 const css=node('link');css.rel='stylesheet';css.href='assets/conexiones-intereses-v1.css?v=1';document.head.append(css);
 const isConnections=location.pathname.endsWith('/conexiones.html');
 if(isConnections){
  manager=node('section',undefined,'coi-app');manager.id='intereses';manager.append(node('h2','Mis intereses y conexiones'),node('p','Este recorrido es gratuito y no consume los contactos directos de tu plan. Los datos profesionales se muestran solo tras la autorización de ambas empresas.'));
  notice=node('p','Cargando conexiones…');notice.setAttribute('role','status');notice.setAttribute('aria-live','polite');manager.append(notice);
  const refreshButton=node('button','Actualizar conexiones','coi-secondary');refreshButton.type='button';refreshButton.addEventListener('click',()=>refresh().catch(e=>status(e.message,true)));manager.append(refreshButton);
  const list=node('div');list.setAttribute('data-coi-list','');manager.append(list);const form=document.getElementById('preferences');if(form)form.before(manager);else document.querySelector('main').append(manager);
  await refresh();const matches=document.getElementById('match-list');if(matches)new MutationObserver(decorate).observe(matches,{childList:true,subtree:true});
  if(location.hash==='#intereses')manager.scrollIntoView();
 }
}
// El detalle requiere su identificador; no utilizar una consulta sin id.
async function detail(){
 const session=await json('me');if(!session.user)return;me=session.user;csrf=session.csrf;
 const id=Number(new URLSearchParams(location.search).get('id'));if(!Number.isSafeInteger(id)||id<=0)return;
 const r=await fetch('api.php?action=opportunity&id='+encodeURIComponent(id),{credentials:'same-origin',cache:'no-store'});const d=await r.json();if(!r.ok||!d.ok||Number(d.opportunity.company_id)===Number(me.id))return;
 const css=node('link');css.rel='stylesheet';css.href='assets/conexiones-intereses-v1.css?v=1';document.head.append(css);
 await refresh(false);
 const box=node('section',undefined,'coi-app');box.append(node('h2','Propón una conexión gratuita'),node('p','Puedes mostrar interés en esta necesidad. El autor revisará tu perfil y decidirá si acepta. Los contactos profesionales solo se comparten con autorización de ambas partes.'));
 notice=node('p');notice.setAttribute('role','status');box.append(notice);addInterest(box,id);const mine=node('a','Mis conexiones');mine.href='conexiones.html#intereses';box.append(mine);
 const target=document.getElementById('contactButton');if(target)target.parentElement.append(box);else document.querySelector('main').append(box);
}
const go=()=>{(location.pathname.endsWith('/oportunidad.html')?detail():start()).catch(e=>status(e.message,true));};
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',go,{once:true});else go();
})();
