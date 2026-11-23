(() => {
const API='api.php';
const $=s=>document.querySelector(s);
const $$=s=>document.querySelectorAll(s);
let CSRF='';
const parseJSON=t=>{try{return JSON.parse(t)}catch(e){const a=t.indexOf('{'),b=t.lastIndexOf('}');if(a>=0&&b>a){try{return JSON.parse(t.slice(a,b+1))}catch(_){}}throw e}};
async function getJSON(url,ms=15000){
  const c=new AbortController();const t=setTimeout(()=>c.abort(),ms);let r=null,txt='';
  try{
    r=await fetch(url,{signal:c.signal,cache:'no-store'});
    txt=await r.text();
    return parseJSON(txt);
  }catch(e){
    let m;
    if(e&&e.name==='AbortError') m='El servidor tardó más de 15 s en responder';
    else if(r) m='HTTP '+r.status+' · la respuesta no es JSON'+(txt?': «'+txt.replace(/\s+/g,' ').slice(0,70)+'»':'');
    else m='No se pudo conectar ('+(e&&e.message)+')';
    const er=new Error(m);
    er.hint=/^\s*<\?php/.test(txt)?'El servidor envía el código PHP sin ejecutarlo: abre la web desde tu hosting o un servidor con PHP, no desde un archivo local.':'';
    throw er;
  }finally{clearTimeout(t)}
}
async function api(action, data=null){
  const protectedActions=['profile','create_opportunity','update_opportunity','close_opportunity','reopen_opportunity','delete_opportunity'];
  const opt={method:data?'POST':'GET',headers:data?{'Content-Type':'application/json'}:{}};
  if(CSRF && protectedActions.includes(action)) opt.headers['X-CSRF-Token']=CSRF;
  if(data) opt.body=JSON.stringify(CSRF && protectedActions.includes(action) ? {...data,_csrf:CSRF} : data);
  try {
    const r=await fetch(`${API}?action=${encodeURIComponent(action)}`,opt);
    const body=await r.text().then(parseJSON).catch(()=>({}));
    if(!r.ok && !body.error){
      body.error = r.status===429
        ? 'Demasiados intentos. Inténtalo de nuevo más tarde.'
        : `No se pudo completar la solicitud (HTTP ${r.status}).`;
    }
    return body;
  } catch (e) {
    return {ok:false,error:'No se pudo conectar con el servidor. Inténtalo de nuevo.'};
  }
}
function msg(text,type='notice'){const e=$('#appMsg');if(e){e.textContent=text;e.className='notice '+type;e.hidden=false;}if(window.coToast&&(type==='success'||type==='error'))window.coToast(text,type)}
async function boot(){
  loadFounderCounter();
  const m=await api('me');
  if(document.body.dataset.privatePage==='true' && !m.user){ location.href='login.html?next='+encodeURIComponent(location.pathname.split('/').pop()+location.search); return; }
  $$('[data-auth-company]').forEach(e=>e.textContent=m.user?.empresa||'');
  $$('[data-auth-only]').forEach(e=>e.hidden=!m.user);
  $$('[data-guest-only]').forEach(e=>e.hidden=!!m.user);
  CSRF=m.csrf||'';
  if(m.user && !document.querySelector('.bottom-nav')){const cur=location.pathname.split('/').pop()||'index.html';const L=[['panel.html','Panel','<path d="M3 12 12 3l9 9M5 10v10h14V10"/>'],['directorio.html','Empresas','<path d="M3 21h18M5 21V7l7-4 7 4v14"/>'],['oportunidades.html','Oportunidades','<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M9 7V4h6v3"/>'],['perfil.html','Perfil','<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>']];const n=document.createElement('nav');n.className='bottom-nav';n.setAttribute('aria-label','Navegación principal');n.innerHTML=L.map(x=>`<a href="${x[0]}"${cur===x[0]?' class="on" aria-current="page"':''}><svg viewBox="0 0 24 24" aria-hidden="true">${x[2]}</svg>${x[1]}</a>`).join('');document.body.appendChild(n);document.body.classList.add('has-bottom-nav');}
  if($('#statOpps') && m.user) dashboard(m);
  const billingBox=$('#billingBox');
  if(billingBox && m.user){
    const b=await api('billing_status');
    if(b.subscription){
      const access=b.opportunity_access||m.opportunity_access;
      const limit=access?.active_opportunity_limit===null?'Ilimitadas':(access?.active_opportunity_limit??10);
      const active=access?.active_opportunities??0;
      billingBox.innerHTML=`<strong>${esc(b.subscription.plan_label)}</strong><span>${esc(b.subscription.amount)} · ${active}/${limit} oportunidades activas</span><div class="billing-actions"><a class="btn btn-primary" href="planes.html">Ver planes</a><button class="btn btn-outline-dark" type="button" data-billing-portal>Gestionar facturación</button></div>`;
    } else {
      const access=b.opportunity_access||m.opportunity_access;
      const limit=access?.active_opportunity_limit===null?'Ilimitadas':(access?.active_opportunity_limit??1);
      const active=access?.active_opportunities??0;
      if(access?.strategic_active){
        const strategicPlan=access.plan?.label||'Empresa Estratégica';
        const strategicDate=access.strategic_expires_at||'';
        const contacts=access.plan?.contact_limit===null?'Ilimitados':(access.plan?.contact_limit??'—');
        billingBox.innerHTML=`<strong>🏢 Empresa Estratégica · ${esc(strategicPlan)} GRATIS</strong><span>Beneficio exclusivo durante 12 meses · Gratis hasta ${esc(strategicDate)}.</span><span>${active}/${limit} oportunidades activas · ${contacts} contactos mensuales.</span><div class="billing-actions"><a class="btn btn-outline-dark" href="oportunidades.html">Gestionar oportunidades</a></div>`;
      } else if(access?.trial_active){
        billingBox.innerHTML=`<strong>🏆 Empresa Fundadora · Profesional GRATIS</strong><span>${active}/${limit} oportunidades activas · promoción hasta ${esc(access.trial_ends_at||'')}.</span><div class="billing-actions"><a class="btn btn-primary" href="planes.html">Ver planes</a><a class="btn btn-outline-dark" href="oportunidades.html">Gestionar oportunidades</a></div>`;
      } else {
        billingBox.innerHTML=`<strong>Plan Gratuito · 0 €/mes</strong><span>${active}/${limit} oportunidades activas · acceso a directorio y oportunidades.</span><a class="btn btn-primary" href="planes.html">Mejorar plan</a>`;
      }
    }
    const pb=billingBox.querySelector('[data-billing-portal]'); if(pb) pb.addEventListener('click',async()=>{pb.disabled=true;const r=await api('billing_portal');pb.disabled=false;if(r.ok)location.href=r.url;else msg(r.error,'error')});
  }

  const reg=$('#registerForm');
  if(reg){
    const founderInput=reg.querySelector('#founderInput');
    const wantsFounder=new URLSearchParams(location.search).get('fundadora')==='1';
    if(founderInput) founderInput.value=wantsFounder?'1':'0';
    reg.addEventListener('submit',async e=>{
      e.preventDefault();
      const d=Object.fromEntries(new FormData(reg));
      if(!d.accept_conditions || !d.accept_privacy){msg('Debes aceptar las Condiciones de Uso y confirmar que has leído la Política de Privacidad.','error');return}
      const p2=reg.querySelector('#password2');
      if(p2 && d.password !== p2.value){msg('Las contraseñas no coinciden.','error');return}
      const r=await api('register',d);
      if(!r.ok){msg(r.error,'error');return}
      if(r.founder_awarded){
        msg('¡Enhorabuena! Tu empresa ha conseguido una plaza como Empresa Fundadora. Tienes el Plan Profesional gratis durante 12 meses.','success');
        setTimeout(()=>location.href='panel.html',900);
      } else {
        location.href='panel.html';
      }
    });
  }
  const forgotPassword=$('#forgotPasswordForm');
  if(forgotPassword) forgotPassword.addEventListener('submit',async e=>{
    e.preventDefault();
    const button=forgotPassword.querySelector('button[type="submit"]');
    if(button) button.disabled=true;
    const d=Object.fromEntries(new FormData(forgotPassword));
    const r=await api('forgot_password',d);
    if(button) button.disabled=false;
    const el=$('#forgotPasswordMsg');
    if(el){el.textContent=r.message||r.error||'No se pudo completar la solicitud.';el.className='notice '+(r.ok?'success':'error');}
    if(r.ok) forgotPassword.reset();
  });

  const resetPassword=$('#resetPasswordForm');
  if(resetPassword) resetPassword.addEventListener('submit',async e=>{
    e.preventDefault();
    const el=$('#resetPasswordMsg');
    const params=new URLSearchParams(location.search);
    const token=params.get('token')||'';
    const d=Object.fromEntries(new FormData(resetPassword));
    if(d.password!==d.password2){if(el){el.textContent='Las contraseñas no coinciden.';el.className='notice error';}return;}
    const button=resetPassword.querySelector('button[type="submit"]');
    if(button) button.disabled=true;
    const r=await api('reset_password',{token,password:d.password});
    if(button) button.disabled=false;
    if(el){el.textContent=r.message||r.error||'No se pudo actualizar la contraseña.';el.className='notice '+(r.ok?'success':'error');}
    if(r.ok){resetPassword.reset();setTimeout(()=>location.href='login.html?password=updated',1200);}
  });

  const login=$('#loginForm');
  if(login) login.addEventListener('submit',async e=>{
    e.preventDefault(); const d=Object.fromEntries(new FormData(login)); const r=await api('login',d);
    if(!r.ok){const el=$('#loginMsg');if(el){el.textContent=r.error;el.className='notice error';}return}
    const nextPage = new URLSearchParams(location.search).get('next');
    location.href = nextPage === 'planes.html' ? 'planes.html' : 'panel.html';
  });
  $$('[data-logout]').forEach(b=>b.addEventListener('click',async()=>{await api('logout');location.href='index.html'}));

 const profile=$('#profileForm');
if(profile){
  const r=m;
  if(!r.user){
    location.href='login.html';
    return;
  }
// Logo de empresa
const logoInput = $('#companyLogoInput');
const logoPreview = $('#companyLogoPreview');
const logoPlaceholder = $('#companyLogoPlaceholder');

// Mostrar logo guardado
if (r.user.logo && logoPreview) {
    logoPreview.src = r.user.logo;
    logoPreview.hidden = false;

    if (logoPlaceholder) {
        logoPlaceholder.hidden = true;
    }
}

// Subir nuevo logo
if (logoInput) {
    logoInput.addEventListener('change', async () => {
        const file = logoInput.files?.[0];
        if (!file) return;

        const tiposPermitidos = [
            'image/jpeg',
            'image/png',
            'image/webp'
        ];

        if (!tiposPermitidos.includes(file.type)) {
            msg('El logo debe ser JPG, PNG o WebP.', 'error');
            logoInput.value = '';
            return;
        }

        if (file.size > 3 * 1024 * 1024) {
            msg('El logo debe ocupar como máximo 3 MB.', 'error');
            logoInput.value = '';
            return;
        }

        // Previsualización
        if (logoPreview) {
            logoPreview.src = URL.createObjectURL(file);
            logoPreview.hidden = false;
        }

        if (logoPlaceholder) {
            logoPlaceholder.hidden = true;
        }

        const fd = new FormData();
        fd.append('logo', file);
        fd.append('_csrf', CSRF);

        try {
            const response = await fetch(
                `${API}?action=logo_upload`,
                {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin'
                }
            );

            const data = await response.json();

            if (!response.ok || !data.ok) {
                throw new Error(data.error || 'No se pudo subir el logo.');
            }

            if (logoPreview && data.logo) {
                logoPreview.src = data.logo + '?v=' + Date.now();
                logoPreview.hidden = false;
            }

            if (logoPlaceholder) {
                logoPlaceholder.hidden = true;
            }

            msg('Logo actualizado.', 'success');

        } catch (error) {
            msg(
                error.message || 'No se pudo subir el logo.',
                'error'
            );
        }
    });
}
  // Cargar campos normales
  for(const [k,v] of Object.entries(r.user)){
    const el=profile.elements[k];
    if(el) el.value=v||'';
  }

  // Áreas de experiencia
  const areasHidden=profile.querySelector('#areasExperiencia');
  const areasChecks=profile.querySelectorAll('.area-option input[type="checkbox"]');

  // Recuperar áreas guardadas
  const areasGuardadas=String(r.user.areas_experiencia||'')
    .split('|')
    .map(x=>x.trim())
    .filter(Boolean);

  // Marcar las casillas correspondientes
  areasChecks.forEach(check=>{
    check.checked=areasGuardadas.includes(check.value);
  });

  // Pasar las casillas seleccionadas al campo oculto
  function actualizarAreas(){
    const seleccionadas=[...areasChecks]
      .filter(check=>check.checked)
      .map(check=>check.value);

    if(areasHidden){
      areasHidden.value=seleccionadas.join('|');
    }
  }

  areasChecks.forEach(check=>{
    check.addEventListener('change',actualizarAreas);
  });

  // Guardar perfil
  profile.addEventListener('submit',async e=>{
    e.preventDefault();

    actualizarAreas();

    const d=Object.fromEntries(new FormData(profile));
    const x=await api('profile',d);

    if(x.ok){
      msg('Perfil actualizado.','success');
    }else{
      msg(x.error,'error');
    }
  });
}
  const companyList=$('#companyList');
  if(companyList){
    const q=$('#q'), province=$('#provincia'), search=$('#companySearch'), sector=$('#sector');
    const selectedSector=new URLSearchParams(location.search).get('sector')||'';
    if(sector && ['construccion','energia','instalaciones','ingenieria'].includes(selectedSector))sector.value=selectedSector;
    // Clasificación orientativa basada en los datos públicos existentes, sin modificar api.php.
    const normaliza=v=>String(v||'').normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase();
    const reglas={
      construccion:/construct|edific|obra civil|rehabilit|reforma|albanil|estructura|ciment|impermeabil|fachada|cubierta|paviment|carpinter|revestim|excav|movimiento de tierra|urbaniz|demolic|ferroviari/,
      energia:/fotovolta|solar|renovab|energia|energetic|autoconsum|eolic|bateria|almacenamiento energetico|inversor|paneles solares|parque solar|eficiencia energetica/,
      instalaciones:/instalacion|instalador|electric|fontaner|climatiz|calefacc|ventilacion|telecomunic|domotic|saneamiento|gas|aeroterm|mantenimiento|hvac/,
      ingenieria:/ingenier|arquitect|consultor|direccion tecnica|proyectist|oficina tecnica|topograf|geotecni|laboratorio|ensayo|seguridad y salud|servicios tecnicos|diseno tecnico/
    };
    const pertenece=(c,clave)=>{
      if(!clave)return true;
      // Una empresa puede pertenecer a varios sectores; solo usamos datos disponibles.
      const datos=normaliza([c.tipo,c.especialidad,c.descripcion,c.areas_experiencia].filter(Boolean).join(' '));
      return reglas[clave]?.test(datos)===true;
    };
    const load=async()=>{
      const params=new URLSearchParams();
      if(q?.value.trim())params.set('q',q.value.trim());
      if(province?.value.trim())params.set('provincia',province.value.trim());
      try{
        const r=await getJSON(`${API}?action=companies&${params.toString()}`);
        if(r.ok===false){companyList.innerHTML=`<div class="notice error" style="grid-column:1/-1">${esc(r.error||'No se pudieron cargar las empresas.')}</div>`;return;}
        const chosen=sector?.value||'';
        const rows=(r.companies||[]).filter(c=>pertenece(c,chosen));
        renderCompanies(rows);
      }catch(e){
        companyList.innerHTML=`<div class="empty-state"><h3>No hemos podido cargar las empresas</h3><p>Ha habido un problema de conexión con el servidor.</p><p class="sub" style="font-size:.82rem">${esc(e.message||'')}</p><button type="button" class="btn btn-primary" id="retryCompanies">Reintentar</button></div>`;
        const b=$('#retryCompanies');if(b)b.onclick=load;
      }
    };
    if(search)search.addEventListener('submit',e=>{e.preventDefault();load()});
    if(sector)sector.addEventListener('change',()=>{
      const u=new URL(location.href);
      if(sector.value)u.searchParams.set('sector',sector.value);else u.searchParams.delete('sector');
      history.replaceState(null,'',u.pathname+u.search+u.hash);
      load();
    });
    await load();
  }
  const oppList=$('#oppList');
  const guestOppNotice=()=>'<div class="empty-state" style="grid-column:1/-1"><h3>Oportunidades exclusivas para usuarios registrados</h3><p>Inicia sesión o crea tu perfil gratuito para consultar las necesidades reales de las empresas.</p><div class="form-actions" style="justify-content:center"><a class="btn btn-primary" href="registro-empresas.html">Crear perfil gratis</a><a class="btn btn-outline-dark" href="login.html">Acceder</a></div></div>';
  const canReadOpportunities = Boolean(m.user) || m.admin_view === true;
  if(oppList){
    if(!canReadOpportunities){
      oppList.innerHTML=guestOppNotice();
    }else{
      const r=await api('opportunities');
      if(r.ok) renderOpps(r.opportunities||[]);
      else oppList.innerHTML=`<div class="notice error">${esc(r.error||'No se pudieron cargar las oportunidades.')}</div>`;
    }
  }
  const oppProvince=$('#oppProvinceFilter'), oppCategory=$('#oppCategoryFilter'), oppSearch=$('#oppSearch');
  if(oppSearch){oppSearch.addEventListener('submit',async e=>{e.preventDefault();if(!canReadOpportunities){if(oppList)oppList.innerHTML=guestOppNotice();return;}const p=new URLSearchParams();if(oppProvince?.value)p.set('provincia',oppProvince.value);if(oppCategory?.value)p.set('categoria',oppCategory.value);try{const r=await fetch(`${API}?action=opportunities&${p.toString()}`,{cache:'no-store'}).then(x=>x.json());if(r.ok)renderOpps(r.opportunities||[]);else if(oppList)oppList.innerHTML=`<div class="notice error">${esc(r.error||'No se pudieron cargar las oportunidades.')}</div>`;}catch(e){if(oppList)oppList.innerHTML='<div class="notice error">No se pudieron cargar las oportunidades.</div>';}});}
  const oppForm=$('#opportunityForm');
  if(oppForm) oppForm.addEventListener('submit',async e=>{e.preventDefault();const d=Object.fromEntries(new FormData(oppForm));const r=await api('create_opportunity',d);if(r.ok){msg(r.message||'Oportunidad publicada correctamente.','success');oppForm.reset();await loadMyOpps()}else {msg(r.error,'error');if(r.code==='opportunity_limit'){setTimeout(()=>location.href='planes.html',900)}}});
  const myOppList=$('#myOppList');
  if(myOppList && m.user) await loadMyOpps();
  async function loadMyOpps(){const r=await api('my_opportunities');renderMyOpps(r.opportunities||[])}
  window.coRefreshMyOpps=loadMyOpps;
  async function changeOpp(action,id){const r=await api(action,{id});if(r.ok){await loadMyOpps();if(oppList){const x=await api('opportunities');renderOpps(x.opportunities||[])}}else msg(r.error,'error')}
  document.addEventListener('click',e=>{const b=e.target.closest('[data-opp-action]');if(!b)return;e.preventDefault();changeOpp(b.dataset.oppAction,Number(b.dataset.oppId));});
}
const ST={active:'Activa',closed:'Cerrada',draft:'Borrador'};
const stLabel=s=>ST[s]||s;
const fdate=v=>window.coDate?window.coDate(v):'';
async function dashboard(m){
  const [mo,co,bs]=await Promise.all([api('my_opportunities'),api('companies'),api('billing_status')]);
  const acc=bs.opportunity_access||m.opportunity_access; const mine=mo.opportunities||[]; const cos=co.companies||[];
  if(window.coCount){window.coCount($('#statOpps'),mine.filter(o=>o.status==='active').length);window.coCount($('#statCompanies'),cos.length);}
  $('#statPlan').textContent=bs.subscription?bs.subscription.plan_label:(acc?.strategic_active?'Empresa Estratégica · '+(acc.plan?.label||'Profesional'):(acc?.trial_active?'Fundadora':'Gratuito'));
  const dO=$('#dashOpps'); if(dO) dO.innerHTML=mine.length?mine.slice(0,3).map(o=>`<a class="mini" href="editar-oportunidad.html?id=${o.id}"><div><strong>${esc(o.title)}</strong><span class="sub">${esc(o.province)} · ${esc(fdate(o.created_at))}</span></div><span class="badge">${esc(stLabel(o.status))}</span></a>`).join(''):emptyState('Todavía no has publicado oportunidades','Publica una necesidad y otras empresas de la red podrán encontrarla.','oportunidades.html#nueva','Publicar oportunidad','<path d="M12 5v14M5 12h14"/>');
  const dC=$('#dashCompanies');

if(dC){
  dC.innerHTML=cos.length
    ? cos.slice(0,4).map(c=>`
        <a class="mini" href="empresa.html?id=${encodeURIComponent(c.id)}${c.source==='invited'?'&source=invited':''}">
          
          ${
            c.logo
              ? `<div class="avatar company-avatar-logo">
                   <img src="${esc(c.logo)}" alt="Logo de ${esc(c.empresa)}">
                 </div>`
              : `<div class="avatar ${tone(c.empresa)}" aria-hidden="true">
                   ${initials(c.empresa)}
                 </div>`
          }

          <div>
            <strong>${esc(c.empresa)}</strong>
            <span class="sub">
              ${esc(c.provincia)} · ${esc(c.especialidad||c.tipo||'Empresa')}
            </span>
          </div>
        </a>
      `).join('')
    : emptyState(
        'Aún no hay empresas en el directorio',
        'Cuando se incorporen nuevas empresas las verás aquí.',
        'directorio.html',
        'Ir al directorio'
      );
}
}
const IC={pin:'<svg class="ico" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 10c0 6-8 12-8 12S4 16 4 10a8 8 0 0 1 16 0z"/><circle cx="12" cy="10" r="3"/></svg>',tool:'<svg class="ico" viewBox="0 0 24 24" aria-hidden="true"><path d="M14.7 6.3a4 4 0 0 0-5.4 5.4L3 18l3 3 6.3-6.3a4 4 0 0 0 5.4-5.4l-2.5 2.5-2.4-.6-.6-2.4z"/></svg>',bld:'<svg class="ico" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 21h18M5 21V7l7-4 7 4v14M9 9h.01M9 13h.01M9 17h.01M15 9h.01M15 13h.01M15 17h.01"/></svg>',clock:'<svg class="ico" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>',arr:'<svg class="ico" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>'};
function initials(n){const w=String(n||'?').trim().split(/\s+/).filter(Boolean);return esc(((w[0]||'?')[0]+(w[1]?w[1][0]:'')).toUpperCase())}
function tone(n){let t=0;for(const c of String(n||''))t+=c.charCodeAt(0);return 'tone-'+(t%3+1)}
function emptyState(title,text,href,label,icon){return `<div class="empty-state"><div class="ico-wrap"><svg viewBox="0 0 24 24" aria-hidden="true">${icon||'<circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/>'}</svg></div><h3>${title}</h3><p>${text}</p>${href?`<a class="btn btn-primary" href="${href}">${label}</a>`:''}</div>`}
function renderCompanies(rows){const el=$('#companyList'); if(!el)return; el.innerHTML=rows.length?rows.map((c,i)=>{const invited=c.source==='invited';const guest=!!c.public_preview;const href=`empresa.html?id=${encodeURIComponent(c.id)}${invited?'&source=invited':''}`;const desc=guest?'Perfil profesional disponible para empresas registradas.':(invited?'Esta empresa está invitada a ConectaObra y todavía no ha activado su perfil.':(c.descripcion||'Empresa profesional de ConectaObra.'));const specialty=c.especialidad||'Perfil pendiente de activación';const founder=Number(c.founder_company)===1 && c.founder_status==='active';return `<a class="opp" style="--i:${i}" href="${href}"><div class="opp-head">${c.logo
  ? `<div class="avatar company-avatar-logo">
       <img src="${esc(c.logo)}" alt="Logo de ${esc(c.empresa)}">
     </div>`
  : `<div class="avatar ${tone(c.empresa)}" aria-hidden="true">
       ${initials(c.empresa)}
     </div>`
}<div><h3>${esc(c.empresa)}</h3><span class="opp-sub">${esc(c.tipo||'Empresa')}</span></div></div><div class="chips"><span class="chip">${IC.pin}${esc(c.provincia)}</span><span class="chip">${IC.tool}${esc(specialty)}</span>${founder?'<span class="chip founder">🏆 Empresa Fundadora</span>':''}</div><p class="clamp">${esc(desc)}</p><span class="opp-cta">${guest?'Acceder al perfil':(invited?'Ver empresa invitada':'Ver empresa')} ${IC.arr}</span></a>`;}).join(''):emptyState('No hay empresas con esos filtros','Prueba con otra especialidad o provincia, o consulta todo el directorio.','directorio.html','Ver todas las empresas')}
async function loadOpps(){const r=await api('opportunities');renderOpps(r.opportunities||[])}
function renderMyOpps(rows){
  const el=$('#myOppList'); if(!el)return;
  el.innerHTML=rows.length?rows.map((o,i)=>`<article class="opp opp-manage" style="--i:${i}"><div><span class="badge">${esc(stLabel(o.status))}</span><h3 style="margin-top:8px">${esc(o.title)}</h3><div class="chips"><span class="chip">${IC.pin}${esc(o.province)}</span><span class="chip">${IC.tool}${esc(o.category)}</span>${o.created_at?`<span class="chip">${IC.clock}${esc(fdate(o.created_at))}</span>`:''}</div><p class="clamp">${esc(o.description)}</p></div><div class="detail-actions"><a class="btn btn-outline-dark" href="editar-oportunidad.html?id=${o.id}">Editar</a>${o.status==='active'?`<button class="btn btn-outline-dark" data-opp-action="close_opportunity" data-opp-id="${o.id}">Cerrar</button>`:`<button class="btn btn-outline-dark" data-opp-action="reopen_opportunity" data-opp-id="${o.id}">Reabrir</button>`}<button class="btn btn-outline-dark" data-opp-action="delete_opportunity" data-opp-id="${o.id}">Eliminar</button></div></article>`).join(''):emptyState('Todavía no has publicado oportunidades','Cuando publiques una necesidad, aparecerá aquí para que puedas editarla, cerrarla o reabrirla.',null,null,'<path d="M12 5v14M5 12h14"/>');
}
function renderOpps(rows){
  const el=$('#oppList');
  if(!el)return;
  el.innerHTML=rows.length
    ? rows.map((o,i)=>`<a class="opp" style="--i:${i}" href="oportunidad.html?id=${encodeURIComponent(o.id)}"><span class="badge" style="align-self:flex-start">${esc(o.category)}</span><h3>${esc(o.title)}</h3><div class="chips"><span class="chip">${IC.pin}${esc(o.province)}</span><span class="chip">${IC.bld}${esc(o.publisher)}</span>${o.created_at?`<span class="chip">${IC.clock}${esc(fdate(o.created_at))}</span>`:''}</div><p class="clamp">${esc(o.description)}</p><span class="opp-cta">Ver oportunidad ${IC.arr}</span></a>`).join('')
    : emptyState('Todavía no hay oportunidades','Cuando se publiquen necesidades compatibles con tu actividad, las verás aquí.','directorio.html','Explorar empresas');
}
async function loadFounderCounter(){
  const els=$$('[data-founder-available]'); if(!els.length)return;
  try{
    const r=await fetch(`${API}?action=founder_status`,{cache:'no-store'}).then(x=>x.json());
    if(r.ok) els.forEach(el=>el.textContent=String(r.available));
  }catch(e){}
}
function esc(s){return String(s??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]))}
boot();
})();