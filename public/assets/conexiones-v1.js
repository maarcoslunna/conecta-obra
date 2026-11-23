(() => {
'use strict';
const el=id=>document.getElementById(id), status=el('status'), form=el('preferences');
const provinces='Álava|Albacete|Alicante|Almería|Asturias|Ávila|Badajoz|Barcelona|Bizkaia|Burgos|Cáceres|Cádiz|Cantabria|Castellón|Ceuta|Ciudad Real|Córdoba|Cuenca|Gipuzkoa|Girona|Granada|Guadalajara|Huelva|Huesca|Illes Balears|Jaén|La Coruña|La Rioja|Las Palmas|León|Lleida|Lugo|Madrid|Málaga|Melilla|Murcia|Navarra|Ourense|Palencia|Pontevedra|Salamanca|Santa Cruz de Tenerife|Segovia|Sevilla|Soria|Tarragona|Teruel|Toledo|Valencia|Valladolid|Zamora|Zaragoza'.split('|');
let csrf='',revision=0,ready=false;
function message(text,error=false){status.textContent=text;status.className=error?'error':'';}
function check(container,value,label){const l=document.createElement('label');l.className='choice';const c=document.createElement('input');c.type='checkbox';c.value=value;l.append(c,document.createTextNode(label));el(container).append(l);}
const selected=id=>[...el(id).querySelectorAll('input:checked')].map(c=>c.value);
const mark=(id,values)=>el(id).querySelectorAll('input').forEach(c=>{c.checked=values.includes(c.value);});
async function load(){
 const r=await fetch('api.php?action=connections_preferences',{cache:'no-store',credentials:'same-origin'});
 if(r.status===401){location.replace('login.html?next=conexiones.html');return;}
 const d=await r.json();if(!r.ok||!d.ok)throw Error(d.error||'No se pudieron cargar tus preferencias.');
 if(!ready){Object.entries(d.topics).forEach(([key,v])=>{check('offered_topics',key,v[0]);check('needed_topics',key,v[0]);});provinces.forEach(p=>check('provinces',p,p));ready=true;}
 const p=d.preferences;csrf=d.csrf;revision=p.revision;
 ['mode','frequency','availability'].forEach(k=>{el(k).value=p[k];});
 ['national','email_enabled'].forEach(k=>{el(k).checked=p[k];});
 ['offered_topics','needed_topics','provinces'].forEach(k=>mark(k,p[k]));
 el('keywords').value=p.keywords.join(', ');form.hidden=false;
 const list=el('match-list');list.replaceChildren();
 if(p.availability==='unavailable'){const n=document.createElement('p');n.textContent='Has indicado que no tienes disponibilidad. Puedes actualizarla cuando cambie.';list.append(n);}
 if(!d.preview.length){const n=document.createElement('p');n.textContent=p.mode==='collaborators'?'Tus preferencias para encontrar colaboradores están guardadas. Publica una oportunidad con tu necesidad para que otras empresas puedan mostrar interés.':'Todavía no hay coincidencias en esta vista. Guarda tus servicios y zonas o revisa oportunidades.';list.append(n);}
 for(const o of d.preview){const card=document.createElement('article');card.className='match';const h=document.createElement('h3');h.textContent=o.title;const p=document.createElement('p');p.textContent=o.province+' · '+o.category;const why=document.createElement('p');why.textContent='Coincide con: '+o.reasons.join(', ');const a=document.createElement('a');a.href='oportunidad.html?id='+encodeURIComponent(o.id);a.textContent='Ver necesidad';card.append(h,p,why,a);list.append(card);}
 el('matches').hidden=false;message(d.delivery_enabled?'Preferencias cargadas. Recibirás resúmenes solo si has activado su casilla y existen oportunidades compatibles.':'Preferencias cargadas. El envío de resúmenes está pausado. Puedes consultar coincidencias y guardar tus preferencias.');
}
form.addEventListener('submit',async e=>{
 e.preventDefault();el('save').disabled=true;message('Guardando…');
 const d={revision,_csrf:csrf,mode:el('mode').value,frequency:el('frequency').value,availability:el('availability').value,national:el('national').checked,email_enabled:el('email_enabled').checked,offered_topics:selected('offered_topics'),needed_topics:selected('needed_topics'),provinces:selected('provinces'),keywords:el('keywords').value.split(',').map(s=>s.trim()).filter(Boolean)};
 try{const r=await fetch('api.php?action=connections_preferences_save',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify(d)});const v=await r.json();if(!r.ok||!v.ok)throw Error(v.error||'No se pudo guardar.');await load();message(v.message);}
 catch(err){message(err.message,true);}finally{el('save').disabled=false;}
});
load().catch(err=>message(err.message||'No se pudo conectar.',true));
})();
