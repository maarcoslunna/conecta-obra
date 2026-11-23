(async function () {
  function mostrarErrorDeAcceso() {
    const aviso = document.createElement('p');
    aviso.setAttribute('role', 'alert');
    aviso.textContent = 'No se pudo verificar la sesión. ';
    const boton = document.createElement('button');
    boton.type = 'button';
    boton.textContent = 'Recargar el panel';
    boton.addEventListener('click', () => window.location.reload());
    aviso.append(boton);
    document.body.prepend(aviso);
  }

  const contenido = document.querySelector('main');
  const detalle = document.getElementById('detalle');
  if (contenido) contenido.hidden = true;
  if (detalle) detalle.hidden = true;

  try {
    const r = await fetch('../api.php?action=admin_me', {
      credentials: 'same-origin',
      cache: 'no-store'
    });
    const data = await r.json();

    if (!r.ok || !data.ok) {
      mostrarErrorDeAcceso();
      return;
    }

    if (contenido) contenido.hidden = false;
    window.dispatchEvent(new Event('admin-autorizado'));
  } catch (e) {
    mostrarErrorDeAcceso();
  }
})();
