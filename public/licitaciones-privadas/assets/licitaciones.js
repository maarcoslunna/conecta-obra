(() => {
'use strict';
const provinces=['Álava','Albacete','Alicante','Almería','Asturias','Ávila','Badajoz','Barcelona','Bizkaia','Burgos','Cáceres','Cádiz','Cantabria','Castellón','Ceuta','Ciudad Real','Córdoba','Cuenca','Gipuzkoa','Girona','Granada','Guadalajara','Huelva','Huesca','Illes Balears','Jaén','La Coruña','La Rioja','Las Palmas','León','Lleida','Lugo','Madrid','Málaga','Melilla','Murcia','Navarra','Ourense','Palencia','Pontevedra','Salamanca','Santa Cruz de Tenerife','Segovia','Sevilla','Soria','Tarragona','Teruel','Toledo','Valencia','Valladolid','Zamora','Zaragoza'];
const province=document.querySelector('#province');if(province)for(const name of provinces){const o=document.createElement('option');o.value=name;o.textContent=name;province.append(o);}
const form=document.querySelector('#tenderForm');if(!form)return;
const feedback=document.querySelector('#formFeedback');const button=form.querySelector('button[type=submit]');
function notice(message,ok){feedback.hidden=false;feedback.className='notice '+(ok?'success':'error');feedback.textContent=message;feedback.scrollIntoView({block:'nearest',behavior:'smooth'});}
form.addEventListener('submit',async e=>{
 e.preventDefault();if(!form.reportValidity()){notice('Revisa los campos obligatorios.',false);return;}
 const d=Object.fromEntries(new FormData(form));if(d.start_date&&d.deadline&&d.deadline>d.start_date){notice('La fecha límite no puede ser posterior al inicio de la obra.',false);return;}
 button.disabled=true;
 try{
  const response=await fetch('licitaciones-api.php?action=crear',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify(d)});
  const result=await response.json();notice(result.message||result.error||'No se pudo completar la solicitud.',!!result.ok);if(result.ok)form.reset();
 }catch(_){notice('No se pudo conectar con el servidor. Inténtalo más tarde.',false);}finally{button.disabled=false;}
});
})();
