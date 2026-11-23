/* CO_CONTACT_NOTICE_UI_V1 */
(() => {
  'use strict';
  if (window.coContactNoticeLoaded) return;
  window.coContactNoticeLoaded = true;
  const start = async () => {
    if (document.getElementById('co-contact-balance')) return;
    let data;
    try {
      const r = await fetch('api.php?action=contact_notice_status', {credentials:'same-origin',cache:'no-store'});
      if (!r.ok) return;
      data = await r.json();
    } catch (_) { return; }
    if (!data.ok || !data.eligible || !Number.isInteger(data.remaining) || data.remaining < 0) return;
    const css = document.createElement('link');
    css.rel = 'stylesheet'; css.href = 'assets/contactos-aviso-v1.css?v=1';
    const stylesReady = new Promise(resolve => {
      css.onload = resolve; css.onerror = () => resolve(false);
    });
    document.head.appendChild(css);
    const styled = await stylesReady;
    if (styled === false) return;
    const remaining = data.remaining;
    const balance = document.createElement('section');
    balance.id = 'co-contact-balance'; balance.className = 'co-contact-card';
    balance.setAttribute('aria-label','Tus contactos gratuitos');
    const heading = document.createElement('h2'); heading.textContent = 'Tus contactos gratuitos';
    const count = document.createElement('p'); count.className = 'co-contact-count';
    count.textContent = remaining + (remaining === 1 ? ' contacto disponible' : ' contactos disponibles');
    const renewal = document.createElement('p'); renewal.textContent = 'Próximo periodo: ' + data.resets_label + ' (hora peninsular).';
    const search = document.createElement('a'); search.href = 'directorio.html';
    search.className = 'co-contact-button'; search.textContent = 'Buscar empresas';
    balance.append(heading,count,renewal,search);
    const container = document.querySelector('main .container') || document.querySelector('main');
    if (!container) return;
    const hero = container.querySelector('.dash-hero');
    if (hero) hero.after(balance); else container.prepend(balance);
    if (data.seen || typeof HTMLDialogElement === 'undefined') return;
    const modal = document.createElement('dialog'); modal.className = 'co-contact-modal';
    modal.setAttribute('aria-labelledby','co-contact-title');
    const brand = document.createElement('div'); brand.className = 'co-contact-brand';
    const logo = document.createElement('img'); logo.src = 'assets/logo-symbol.png'; logo.alt = ''; logo.width = 40; logo.height = 40;
    const name = document.createElement('strong'); name.textContent = 'CONECTAOBRA';
    brand.append(logo,name);
    const title = document.createElement('h2'); title.id = 'co-contact-title';
    const fresh = data.month === 1 && data.used === 0;
    title.textContent = fresh ? '¡Bienvenido a ConectaObra!' : '¿Ya estás utilizando tus contactos gratuitos?';
    const intro = document.createElement('p');
    intro.textContent = remaining > 0
      ? 'Tienes ' + remaining + (remaining === 1 ? ' contacto disponible' : ' contactos disponibles') + ' para conectar con empresas por especialidad y provincia.'
      : 'Has utilizado los contactos de este periodo. Volverás a disponer de contactos el ' + data.resets_label + ' (hora peninsular).';
    const list = document.createElement('ul');
    ['Primer mes desde tu registro: 3 contactos.','Segundo mes: 2 contactos.','Desde el tercer mes: 1 contacto cada mes.'].forEach(text => {
      const li = document.createElement('li'); li.textContent = text; list.appendChild(li);
    });
    const next = document.createElement('p'); next.className = 'co-contact-renewal';
    next.textContent = 'Tu próximo periodo comienza el ' + data.resets_label + ' (hora peninsular).';
    const actions = document.createElement('div'); actions.className = 'co-contact-actions';
    const go = document.createElement('a'); go.href = 'directorio.html'; go.className = 'co-contact-button'; go.textContent = 'Buscar empresas';
    const later = document.createElement('button'); later.type = 'button'; later.className = 'co-contact-later'; later.textContent = 'Ahora no';
    actions.append(go,later); modal.append(brand,title,intro,list,next,actions); document.body.appendChild(modal);
    let receipt;
    const markSeen = () => {
      if (!receipt) receipt = fetch('api.php?action=contact_notice_seen', {
        method:'POST', credentials:'same-origin', keepalive:true,
        headers:{'Content-Type':'application/json','X-CSRF-Token':data.csrf},
        body:JSON.stringify({_csrf:data.csrf})
      }).then(r => r.ok).catch(() => false);
      return receipt;
    };
    later.addEventListener('click', () => modal.close());
    go.addEventListener('click', () => { markSeen(); });
    modal.addEventListener('close', () => modal.remove(), {once:true});
    try { modal.showModal(); markSeen(); } catch (_) { modal.remove(); }
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded',start,{once:true});
  else start();
})();
