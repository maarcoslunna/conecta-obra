/* ConectaObra 2.0 — comportamiento de la interfaz (sin dependencias). */
(() => {
  'use strict';
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
  const reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;
  const SVG = {
    menu: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>',
    close: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>'
  };

  /* ---------- Utilidades públicas ---------- */
  window.coAvatar = (el, name) => {
    if (!el) return;
    const w = String(name || '?').trim().split(/\s+/).filter(Boolean);
    el.textContent = ((w[0] || '?')[0] + (w[1] ? w[1][0] : '')).toUpperCase();
    let n = 0; for (const ch of String(name || '')) n += ch.charCodeAt(0);
    el.classList.add('tone-' + (n % 3 + 1));
  };
  window.coDate = (v) => {
    if (!v) return '';
    const d = new Date(String(v).replace(' ', 'T'));
    if (isNaN(d)) return String(v);
    const days = Math.floor((Date.now() - d) / 864e5);
    if (days <= 0) return 'hoy';
    if (days === 1) return 'ayer';
    if (days < 7) return 'hace ' + days + ' días';
    return d.toLocaleDateString('es-ES', { day: 'numeric', month: 'short', year: days > 300 ? 'numeric' : undefined });
  };
  window.coClamp = (el) => {
    if (!el || !el.textContent.trim()) return;
    el.classList.add('clamped');
    if (el.scrollHeight <= el.clientHeight + 2) { el.classList.remove('clamped'); return; }
    const b = document.createElement('button');
    b.type = 'button'; b.className = 'link-more'; b.textContent = 'Ver descripción completa'; b.setAttribute('aria-expanded', 'false');
    b.onclick = () => { const c = el.classList.toggle('clamped'); b.textContent = c ? 'Ver descripción completa' : 'Ver menos'; b.setAttribute('aria-expanded', String(!c)); };
    el.after(b);
  };
  window.coCount = (el, to) => {
    if (!el) return;
    to = Number(to) || 0;
    if (reduce || to === 0) { el.textContent = to; return; }
    const t0 = performance.now(), dur = 700;
    const tick = (t) => { const p = Math.min(1, (t - t0) / dur); el.textContent = Math.round(to * (1 - Math.pow(1 - p, 3))); if (p < 1) requestAnimationFrame(tick); };
    requestAnimationFrame(tick);
  };
  window.coToast = (text, type = 'info') => {
    if (!text) return;
    let box = $('.toasts');
    if (!box) { box = document.createElement('div'); box.className = 'toasts'; box.setAttribute('role', 'status'); box.setAttribute('aria-live', 'polite'); document.body.appendChild(box); }
    const t = document.createElement('div'); t.className = 'toast ' + type;
    const s = document.createElement('span'); s.textContent = text;
    const x = document.createElement('button'); x.type = 'button'; x.setAttribute('aria-label', 'Cerrar aviso'); x.textContent = '×';
    t.append(s, x); box.appendChild(t);
    const kill = () => { t.classList.add('out'); setTimeout(() => t.remove(), 260); };
    x.onclick = kill; setTimeout(kill, type === 'error' ? 7000 : 4200);
  };

  /* ---------- Cabecera con sombra al hacer scroll ---------- */
  const header = $('.site-header');
  if (header) {
    const f = () => header.classList.toggle('scrolled', scrollY > 12);
    f(); addEventListener('scroll', f, { passive: true });
  }

  /* ---------- Drawer de navegación móvil ---------- */
  const nav = header && $('.nav', header);
  let drawer = null, lastFocus = null;
  const links = {
    guest: [['index.html', 'Inicio'], ['como-funciona.html', 'Cómo funciona'], ['sectores.html', 'Sectores'], ['directorio.html', 'Empresas'], ['licitaciones-privadas/', 'Licitaciones privadas'], ['licitaciones-publicas/', 'Licitaciones públicas'], ['oportunidades.html', 'Oportunidades'], ['planes.html', 'Planes'], ['sobre-nosotros.html', 'Sobre nosotros'], ['contacto.html', 'Contacto'], ['/conectajob/', 'ConectaJob']],
    auth: [['panel.html', 'Mi panel'], ['directorio.html', 'Empresas'], ['licitaciones-privadas/', 'Licitaciones privadas'], ['licitaciones-publicas/', 'Licitaciones públicas'], ['oportunidades.html', 'Oportunidades'], ['perfil.html', 'Mi perfil'], ['planes.html', 'Planes y facturación'], ['contacto.html', 'Contacto'], ['/conectajob/', 'ConectaJob']]
  };
  function buildDrawer() {
    const logged = document.body.classList.contains('has-bottom-nav');
    const cur = location.pathname.split('/').pop() || 'index.html';
    drawer = document.createElement('div');
    drawer.className = 'drawer'; drawer.id = 'drawer';
    drawer.innerHTML = '<div class="drawer-back" data-drawer-close></div><div class="drawer-panel" role="dialog" aria-modal="true" aria-label="Menú"><div class="drawer-top"><span class="eyebrow" style="margin:0">Menú</span><button type="button" class="drawer-close" data-drawer-close aria-label="Cerrar menú">' + SVG.close + '</button></div><nav class="drawer-links" aria-label="Principal"></nav><div class="drawer-cta"></div></div>';
    const list = $('.drawer-links', drawer), cta = $('.drawer-cta', drawer);
    (logged ? links.auth : links.guest).forEach(([h, t]) => {
      const a = document.createElement('a'); a.href = h; a.textContent = t; if (h === cur) a.setAttribute('aria-current', 'page'); list.appendChild(a);
    });
    if (logged) {
      const b = document.createElement('button'); b.type = 'button'; b.className = 'btn btn-outline-dark'; b.textContent = 'Salir';
      b.onclick = async () => { try { await fetch('api.php?action=logout'); } catch (e) {} location.href = 'index.html'; };
      cta.appendChild(b);
    } else {
      cta.insertAdjacentHTML('beforeend', '<a class="btn btn-primary" href="registro-empresas.html">Crear perfil gratis</a><a class="btn btn-outline-dark" href="login.html">Acceder</a>');
    }
    drawer.addEventListener('click', (e) => { if (e.target.closest('[data-drawer-close]') || e.target.closest('a')) toggleDrawer(false); });
    document.body.appendChild(drawer);
  }
  function toggleDrawer(open) {
    if (open && drawer) { drawer.remove(); drawer = null; }
    if (open && !drawer) buildDrawer();
    if (!drawer) return;
    const btn = $('.menu');
    if (open) {
      lastFocus = document.activeElement;
      requestAnimationFrame(() => { drawer.classList.add('open'); const f = $('.drawer-links a', drawer); f && f.focus(); });
    } else {
      drawer.classList.remove('open'); lastFocus && lastFocus.focus && lastFocus.focus();
    }
    document.body.classList.toggle('lock', open);
    btn && btn.setAttribute('aria-expanded', String(open));
  }
  if (nav) {
    let btn = $('.menu', nav);
    if (!btn) { btn = document.createElement('button'); btn.type = 'button'; btn.className = 'menu'; btn.setAttribute('aria-label', 'Abrir menú'); nav.appendChild(btn); }
    btn.innerHTML = SVG.menu; btn.setAttribute('aria-expanded', 'false'); btn.setAttribute('aria-controls', 'drawer');
    btn.addEventListener('click', () => toggleDrawer(true));
  }
  addEventListener('keydown', (e) => {
    if (e.key !== 'Escape') return;
    if (drawer && drawer.classList.contains('open')) toggleDrawer(false);
    closeSheet();
  });
  matchMedia('(min-width:1100px)').addEventListener('change', (m) => { if (m.matches && drawer) toggleDrawer(false); });

  /* ---------- Bottom sheets (filtros) ---------- */
  let openSheet = null, sheetBack = null, sheetTrigger = null;
  function showSheet(id, trigger) {
    const s = document.getElementById(id); if (!s) return;
    if (!sheetBack) { sheetBack = document.createElement('div'); sheetBack.className = 'sheet-back'; sheetBack.setAttribute('data-sheet-close', ''); document.body.appendChild(sheetBack); }
    sheetTrigger = trigger; openSheet = s;
    s.classList.add('open'); sheetBack.classList.add('open'); document.body.classList.add('lock');
    trigger && trigger.setAttribute('aria-expanded', 'true');
    const f = $('input,select', s); f && setTimeout(() => f.focus({ preventScroll: true }), 250);
  }
  function closeSheet() {
    if (!openSheet) return;
    openSheet.classList.remove('open'); sheetBack && sheetBack.classList.remove('open');
    document.body.classList.remove('lock');
    sheetTrigger && (sheetTrigger.setAttribute('aria-expanded', 'false'), sheetTrigger.focus && sheetTrigger.focus());
    openSheet = null;
  }
  document.addEventListener('click', (e) => {
    const o = e.target.closest('[data-sheet-open]');
    if (o) { showSheet(o.dataset.sheetOpen, o); return; }
    if (e.target.closest('[data-sheet-close]')) closeSheet();
  });
  $$('form.search-form').forEach((form) => {
    const count = () => {
      const n = $$('.sheet .field input,.sheet .field select', form).filter((i) => i.value.trim() !== '').length;
      $$('[data-filter-count]', form).forEach((b) => (b.textContent = n || ''));
    };
    form.addEventListener('input', count); form.addEventListener('change', count);
    form.addEventListener('submit', () => { closeSheet(); count(); });
    const clear = $('[data-filters-clear]', form);
    clear && clear.addEventListener('click', () => { $$('.sheet input,.sheet select', form).forEach((i) => (i.value = '')); count(); });
    count();
  });

  /* ---------- Formularios por pasos ---------- */
  $$('form[data-steps]').forEach((form) => {
    const steps = $$('.form-step', form); if (steps.length < 2) return;
    let i = 0;
    form.classList.add('steps-on');
    const bar = document.createElement('ol'); bar.className = 'stepper'; bar.setAttribute('aria-hidden', 'true');
    steps.forEach(() => bar.appendChild(document.createElement('li')));
    const cap = document.createElement('div'); cap.className = 'step-caption'; cap.setAttribute('aria-live', 'polite');
    form.prepend(cap); form.prepend(bar);
    const nv = document.createElement('div'); nv.className = 'step-nav';
    nv.innerHTML = '<button type="button" class="btn btn-outline-dark btn-back">Atrás</button><button type="button" class="btn btn-primary btn-next">Continuar</button>';
    form.appendChild(nv);
    const back = $('.btn-back', nv), next = $('.btn-next', nv);
    const go = (n, focus = true) => {
      i = Math.max(0, Math.min(steps.length - 1, n));
      steps.forEach((s, k) => s.classList.toggle('is-current', k === i));
      $$('li', bar).forEach((li, k) => { li.classList.toggle('done', k < i); li.classList.toggle('now', k === i); });
      cap.innerHTML = '<strong></strong><span>Paso ' + (i + 1) + ' de ' + steps.length + '</span>';
      $('strong', cap).textContent = steps[i].dataset.title || '';
      form.classList.toggle('on-last', i === steps.length - 1);
      back.style.display = i === 0 ? 'none' : '';
      if (focus) { const f = $('input,select,textarea', steps[i]); f && f.focus({ preventScroll: true }); form.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'start' }); }
    };
    const valid = () => { for (const f of $$('input,select,textarea', steps[i])) { if (!f.checkValidity()) { f.reportValidity(); return false; } } return true; };
    next.onclick = () => valid() && go(i + 1);
    back.onclick = () => go(i - 1);
    form.addEventListener('keydown', (e) => { if (e.key === 'Enter' && e.target.matches('input:not([type=checkbox])') && i < steps.length - 1) { e.preventDefault(); next.click(); } });
    form.addEventListener('invalid', (e) => { const s = e.target.closest('.form-step'); const k = steps.indexOf(s); if (k > -1 && k !== i) { go(k, false); } }, true);
    go(0, false);
  });

  /* ---------- Indicador de carga en botones de envío ---------- */
  document.addEventListener('submit', (e) => {
    const b = $('button[type=submit]', e.target); if (!b || e.target.matches('[data-no-loading]')) return;
    b.classList.add('is-loading'); setTimeout(() => b.classList.remove('is-loading'), 1600);
  });

  /* ---------- Abrir el bloque plegable indicado en la URL (#nueva, #mis) ---------- */
  if (location.hash) { let d = null; try { d = $(location.hash); } catch (e) {} if (d && d.tagName === 'DETAILS') { d.open = true; } }
  addEventListener('hashchange', () => { let d = null; try { d = $(location.hash); } catch (e) {} if (d && d.tagName === 'DETAILS') d.open = true; });

  /* ---------- Saludo del panel ---------- */
  const g = $('#greet');
  if (g) { const h = new Date().getHours(); g.textContent = h < 13 ? 'Buenos días' : h < 21 ? 'Buenas tardes' : 'Buenas noches'; }

  /* ---------- Aparición al hacer scroll (con intención: bloques, no todo) ---------- */
  if (!reduce && 'IntersectionObserver' in window) {
    const io = new IntersectionObserver((es) => es.forEach((e) => { if (e.isIntersecting) { e.target.classList.add('in'); io.unobserve(e.target); } }), { threshold: .12, rootMargin: '0px 0px -6% 0px' });
    $$('.pricing-card,.cards-3>.card,.dash-block,.quick,.admin-section,.founder-banner').forEach((el, k) => {
      el.classList.add('rv'); el.style.setProperty('--rd', (k % 3) * 70 + 'ms'); io.observe(el);
    });
  }
})();

;(() => {
  const rm = matchMedia('(prefers-reduced-motion: reduce)').matches;
  const bar = document.createElement('div'); bar.className = 'scroll-progress'; bar.setAttribute('aria-hidden', 'true'); document.body.appendChild(bar);
  let tick = false;
  const upd = () => { const h = document.documentElement, m = h.scrollHeight - innerHeight; bar.style.setProperty('--p', m > 0 ? Math.min(1, scrollY / m) : 0); h.style.setProperty('--sy', Math.min(scrollY, 900)); tick = false; };
  addEventListener('scroll', () => { if (!tick) { tick = true; requestAnimationFrame(upd); } }, { passive: true }); upd();
  if (rm) return;

  const SEL = '.choice,.card,.opp,.pricing-card,.audience,.profile-highlights>div';
  if (matchMedia('(hover:hover)').matches) document.addEventListener('pointermove', e => {
    const t = e.target.closest && e.target.closest(SEL); if (!t) return;
    const r = t.getBoundingClientRect(); t.style.setProperty('--mx', (e.clientX - r.left) + 'px'); t.style.setProperty('--my', (e.clientY - r.top) + 'px');
  }, { passive: true });

  if ('IntersectionObserver' in window) {
    const io = new IntersectionObserver(es => es.forEach(en => {
      if (!en.isIntersecting) return; const el = en.target; el.classList.add('in'); io.unobserve(el);
      setTimeout(() => el.classList.remove('rv', 'in'), 1100);
    }), { threshold: .12, rootMargin: '0px 0px -6% 0px' });
    document.querySelectorAll('.section-head,.pricing-card,.audience,.profile-highlights>div,.company-tags>div,.admin-section,.rgpd-box,.cta-box,.invite-card,.empty-opportunity').forEach(el => {
      if (el.hasAttribute('data-anim')) return;
      el.style.setProperty('--d', ([...el.parentNode.children].indexOf(el) % 6) * 80 + 'ms');
      el.classList.add('rv'); io.observe(el);
    });
  }

  const hero = document.querySelector('.hero .hero-content');
  if (hero && !document.querySelector('.hero-net')) {
    const s = document.createElement('div'); s.className = 'hero-net'; s.setAttribute('aria-hidden', 'true');
    s.innerHTML = '<svg viewBox="0 0 400 400" fill="none"><g><line x1="70" y1="90" x2="250" y2="50"/><line x1="250" y1="50" x2="340" y2="190"/><line x1="70" y1="90" x2="180" y2="230"/><line x1="180" y1="230" x2="340" y2="190"/><line x1="180" y1="230" x2="80" y2="330"/><line x1="180" y1="230" x2="300" y2="340"/><line x1="340" y1="190" x2="300" y2="340"/><line x1="70" y1="90" x2="80" y2="330"/></g>'
      + '<g class="float"><circle class="n" cx="70" cy="90" r="16"/><circle class="n" cx="250" cy="50" r="12"/><circle class="n" cx="340" cy="190" r="18"/><circle class="n" cx="80" cy="330" r="13"/><circle class="n" cx="300" cy="340" r="15"/></g>'
      + '<circle class="ring" cx="180" cy="230" r="22"/><circle class="hub" cx="180" cy="230" r="22"/></svg>';
    hero.closest('.hero').appendChild(s);
  }
})();

;(() => {
  const grid = document.querySelector('.pricing-card') && document.querySelector('.pricing-card').parentNode;
  if (!grid || !document.querySelector('[data-plan]')) return;
  const w = document.createElement('div'); w.className = 'plan-toggle-wrap';
  w.innerHTML = '<div class="plan-toggle" role="group" aria-label="Periodo de facturación"><button type="button" aria-pressed="true" data-p="m">Mensual</button><button type="button" aria-pressed="false" data-p="y">Anual<small>ahorra</small></button></div>';
  grid.before(w);
  w.addEventListener('click', e => {
    const b = e.target.closest('button[data-p]'); if (!b) return;
    document.body.classList.toggle('plans-yearly', b.dataset.p === 'y');
    w.querySelectorAll('button').forEach(x => x.setAttribute('aria-pressed', String(x === b)));
  });
})();
