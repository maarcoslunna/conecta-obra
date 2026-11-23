function coDocumentosComercial(agentId) {
  const detalle = document.createElement('details');
  const resumen = document.createElement('summary');
  resumen.textContent = 'Documentación del comercial';
  const lista = document.createElement('ul');
  const form = document.createElement('form');
  form.style.cssText = 'display:grid;gap:12px;max-width:650px';
  const nombre = document.createElement('input');
  nombre.name = 'document_name';
  nombre.required = true;
  nombre.maxLength = 190;
  const etiqueta = document.createElement('label');
  etiqueta.textContent = 'Nombre del documento';
  etiqueta.append(nombre);
  const archivo = document.createElement('input');
  archivo.name = 'pdf';
  archivo.type = 'file';
  archivo.accept = '.pdf,application/pdf';
  archivo.required = true;
  const etiquetaArchivo = document.createElement('label');
  etiquetaArchivo.textContent = 'PDF de hasta 5 MB';
  etiquetaArchivo.append(archivo);
  for (const control of [nombre, archivo]) {
    control.style.cssText = 'display:block;width:100%;max-width:100%;box-sizing:border-box;font-size:16px;min-height:44px';
  }
  const ayuda = document.createElement('p');
  ayuda.textContent = 'El comercial podrá descargar los PDF que archives aquí. Puedes adjuntar su acuerdo de colaboración firmado.';
  const boton = document.createElement('button');
  boton.type = 'submit';
  boton.textContent = 'Archivar PDF';
  const aviso = document.createElement('p');
  aviso.setAttribute('role','status');
  form.append(etiqueta,etiquetaArchivo,ayuda,boton);
  detalle.append(resumen,lista,form,aviso);
  const url = '/admin-nuevo/comercial-documentos.php?agent_id=' + encodeURIComponent(agentId);
  async function cargar() {
    const respuesta = await fetch(url,{credentials:'same-origin',cache:'no-store'});
    const data = await respuesta.json();
    if (!respuesta.ok || !data.ok) throw new Error(data.error || 'No se pudieron consultar los documentos.');
    lista.replaceChildren();
    if (!data.documents.length) {
      const item = document.createElement('li');
      item.textContent = 'Todavía no hay PDF archivados.';
      lista.append(item);
    }
    for (const documento of data.documents) {
      const item = document.createElement('li');
      const enlace = document.createElement('a');
      enlace.href = url + '&document_id=' + encodeURIComponent(documento.id);
      enlace.textContent = documento.document_name;
      item.append(enlace, ' · ' + documento.created_at + ' UTC');
      lista.append(item);
    }
  }
  detalle.addEventListener('toggle',async () => {
    if (!detalle.open) return;
    try { await cargar(); } catch (error) { aviso.textContent = error.message; }
  });
  form.addEventListener('submit',async event => {
    event.preventDefault();
    if (archivo.files[0]?.size > 5*1024*1024) {
      aviso.textContent = 'El PDF supera los 5 MB.';
      return;
    }
    boton.disabled = true;
    aviso.textContent = 'Archivando…';
    try {
      const respuesta = await fetch(url,{
        method:'POST',credentials:'same-origin',
        headers:{'X-CSRF-Token':await obtenerTokenComercial()},body:new FormData(form)
      });
      const data = await respuesta.json();
      if (!respuesta.ok || !data.ok) throw new Error(data.error || 'No se pudo archivar el PDF.');
      form.reset();
      aviso.textContent = 'PDF archivado.';
      await cargar();
    } catch (error) { aviso.textContent = error.message; }
    finally { boton.disabled = false; }
  });
  return detalle;
}

