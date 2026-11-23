<?php
if (!defined('CO_ADMIN_LOGIN_VIEW')) {
    http_response_code(404);
    exit;
}
?>
<!doctype html>
<html lang="es">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Acceso de administración | ConectaObra</title>
<style>
  * { box-sizing: border-box; }
  body {
    margin: 0; min-height: 100vh; padding: 20px; display: grid;
    place-items: center; background: #0d1b2a; color: #fff;
    font: 16px/1.5 system-ui, sans-serif;
  }
  main {
    width: min(100%, 440px); padding: clamp(20px, 6vw, 32px);
    background: #162b3d; border: 1px solid #39566e; border-radius: 16px;
  }
  h1 { font-size: clamp(24px, 6vw, 30px); line-height: 1.2; }
  label { display: block; margin-top: 16px; }
  input, button {
    width: 100%; min-height: 48px; padding: 10px 12px;
    border: 1px solid #71889c; border-radius: 8px; font: inherit;
  }
  input { margin-top: 6px; }
  button {
    margin-top: 22px; background: #ff7a1a; color: #0d1b2a;
    font-weight: 750; cursor: pointer;
  }
  :focus-visible { outline: 3px solid #ffb572; outline-offset: 3px; }
  #aviso { color: #ffd0ad; }
  #aviso:empty { display: none; }
</style>
<main>
  <strong>ConectaObra</strong>
  <h1>Acceso de administración</h1>
  <form id="acceso" method="post">
    <label for="email">Correo electrónico</label>
    <input id="email" name="email" type="email" autocomplete="username" required>
    <label for="clave">Contraseña</label>
    <input id="clave" name="password" type="password" autocomplete="current-password" required>
    <button type="submit">Entrar</button>
    <p id="aviso" role="status"></p>
  </form>
  <noscript>Activa JavaScript para iniciar sesión.</noscript>
</main>
<script>
  const form = document.getElementById('acceso');
  const button = form.querySelector('button');
  const aviso = document.getElementById('aviso');

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    button.disabled = true;
    aviso.textContent = '';

    try {
      const response = await fetch('/api.php?action=admin_login', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(Object.fromEntries(new FormData(form)))
      });
      const data = await response.json();
      if (response.ok && data.ok) {
        window.location.reload();
        return;
      }
      aviso.textContent = data.error || 'No se pudo iniciar sesión.';
    } catch {
      aviso.textContent = 'No se pudo completar el acceso. Vuelve a intentarlo.';
    } finally {
      button.disabled = false;
    }
  });
</script>
</html>
