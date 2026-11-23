(() => {
'use strict';
const make=(tag,text)=>{const e=document.createElement(tag);if(text!==undefined)e.textContent=text;return e;};
async function start(){
 const main=document.querySelector('main');if(!main)return;
 const r=await fetch('api.php?action=connection_notice_preferences',{credentials:'same-origin',cache:'no-store'});if(!r.ok)return;let data=await r.json();if(!data.ok)return;
 const box=make('section');box.id='avisos-conexiones';box.append(make('h2','Avisos de intereses y respuestas'),make('p','Esta preferencia es independiente de los resúmenes diarios o semanales de oportunidades. No consume contactos de tu plan.'));
 const form=make('form'),label=make('label'),check=make('input');label.className='choice';check.type='checkbox';check.checked=data.preferences.enabled;label.append(check,make('span',data.consent));
 const privacy=make('a','Política de privacidad');privacy.href='privacidad.html';privacy.target='_blank';privacy.rel='noopener';
 const note=make('p','Los avisos se agrupan y se procesan periódicamente. Solo se consideran movimientos posteriores a tu activación. El estado vigente de cada conexión está disponible en tu cuenta.');
 if(!data.delivery_enabled)note.textContent='El envío de estos avisos está pausado. Puedes guardar tu preferencia y consultar los movimientos en tu cuenta.';
 const button=make('button','Guardar avisos de conexiones');button.type='submit';const status=make('p');status.setAttribute('role','status');status.setAttribute('aria-live','polite');form.append(label,privacy,note,button,status);box.append(form);main.append(box);
 form.addEventListener('submit',async e=>{e.preventDefault();button.disabled=true;status.textContent='Guardando…';try{
  const r=await fetch('api.php?action=connection_notice_preferences_save',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':data.csrf},body:JSON.stringify({_csrf:data.csrf,revision:data.preferences.revision,enabled:check.checked})});const d=await r.json();if(!r.ok||!d.ok)throw Error(d.error||'No se pudo guardar.');data=d;check.checked=d.preferences.enabled;status.textContent=d.message;if(!d.delivery_enabled)status.textContent+=' El envío está pausado.';
 }catch(err){status.textContent=err.message;}finally{button.disabled=false;}});
}
const run=()=>start().catch(()=>{});if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',run,{once:true});else run();
})();
