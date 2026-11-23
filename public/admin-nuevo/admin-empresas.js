/* NAVEGACION_UNIFICADA_V1 */
/* API_POST_V1 */
let empresasTodas=[],filtroActual="todas";/* INTEGRACION_PRODUCCION_V2 */
async function cargarEmpresas(){try{const [rc,ri]=await Promise.all([fetch("../api.php?action=admin_companies",{credentials:"same-origin",cache:"no-store"}),fetch("../api.php?action=admin_invited_companies",{credentials:"same-origin",cache:"no-store"})]);if(!rc.ok||!ri.ok)throw Error("Acceso administrativo no disponible");const [co,iv]=await Promise.all([rc.json(),ri.json()]);if(!co.ok||!iv.ok)throw Error("La API no autorizó la consulta");empresasTodas=[...(co.companies||[]).map(c=>({...c,grupo:"registradas",nombre:c.empresa||c.company,provinciaVista:c.provincia||c.province})),...(iv.companies||[]).map(c=>({...c,grupo:"invitadas",nombre:c.company||c.empresa,provinciaVista:c.province||c.provincia}))];mostrarEmpresas();}catch(e){document.getElementById("empresas").textContent="No se pudieron cargar las empresas: "+e.message;}}
function mostrarEmpresas(){const q=(document.getElementById("buscar").value||"").toLocaleLowerCase();const box=document.getElementById("empresas");box.replaceChildren();const lista=empresasTodas.filter(c=>(filtroActual==="todas"||c.grupo===filtroActual)&&[c.nombre,c.provinciaVista,c.especialidad].some(v=>String(v||"").toLocaleLowerCase().includes(q)));for(const c of lista){const card=document.createElement("button");card.type="button";card.className="empresa-card";const title=document.createElement("h3");title.textContent=c.nombre||"Empresa sin nombre";const info=document.createElement("p");info.textContent=[c.provinciaVista,c.especialidad].filter(Boolean).join(" · ");const status=document.createElement("small");status.textContent=c.grupo==="registradas"?"Registrada":"Invitada";card.append(title,info,status);card.addEventListener("click",()=>abrirFicha(c));box.append(card);}if(!lista.length)box.textContent="No hay empresas que coincidan con los filtros.";}

function abrirFicha(c){const panel=document.getElementById("detalle");panel.replaceChildren();const cerrar=document.createElement("button");cerrar.type="button";cerrar.textContent="✕ Cerrar ficha";cerrar.onclick=()=>{panel.hidden=true;};const titulo=document.createElement("h2");titulo.textContent=c.nombre||"Empresa";panel.append(cerrar,titulo);if(c.grupo==="registradas") cargarPlanFicha(panel,c.id);const campos=[["Estado del registro",c.grupo==="registradas"?"Registrada":"Invitada"],["CIF",c.cif],["Contacto",c.contacto],["Email",c.email],["Teléfono",c.telefono],["Provincia",c.provinciaVista],["Tipo",c.tipo],["Especialidad",c.especialidad],["Zona",c.zona],["Web",c.web],["Descripción",c.descripcion],["Empleados",c.empleados],["Experiencia",c.experiencia],["Disponibilidad",c.disponibilidad],["Trabajos realizados",c.trabajos_realizados],["Certificaciones",c.certificaciones],["Áreas de experiencia",c.areas_experiencia],["Fecha de invitación",c.invitation_date]];for(const [nombre,valor] of campos){if(valor===null||valor===undefined||valor==="")continue;const p=document.createElement("p");const b=document.createElement("strong");b.textContent=nombre+": ";p.append(b,document.createTextNode(String(valor)));panel.append(p);}panel.hidden=false;}

document.addEventListener("DOMContentLoaded",()=>{document.getElementById("buscar").addEventListener("input",mostrarEmpresas);document.querySelectorAll("[data-filtro]").forEach(b=>b.addEventListener("click",()=>{filtroActual=b.dataset.filtro;mostrarEmpresas();}));cargarEmpresas();});

/* OPORTUNIDADES_V1 */
let coOportunidades = [];

async function coCargarOportunidades() {
  const destino = document.getElementById("co-lista-oportunidades");
  if (!destino) return;
  destino.textContent = "Cargando oportunidades...";
  try {
    const r = await fetch("../api.php?action=admin_opportunities", {
      credentials:"same-origin", cache:"no-store"
    });
    const d = await r.json();
    if (!r.ok || !d.ok) throw new Error("Consulta no autorizada");
    coOportunidades = d.opportunities || [];
    coMostrarOportunidades();
  } catch(e) {
    destino.textContent = "No se pudieron cargar las oportunidades.";
  }
}

function coMostrarOportunidades() {
  const destino = document.getElementById("co-lista-oportunidades");
  const q = (document.getElementById("co-buscar-oportunidad")?.value || "").toLowerCase();
  destino.replaceChildren();

  const items = coOportunidades.filter(o =>
    JSON.stringify([o.title,o.titulo,o.status,o.estado,o.company,o.empresa])
      .toLowerCase().includes(q)
  );

  if (!items.length) {
    destino.textContent = "No hay oportunidades para mostrar.";
    return;
  }

  for (const o of items) {
    const card = document.createElement("article");
    card.className = "co-op-card";

    const title = document.createElement("strong");
    title.textContent = o.title || o.titulo || "Oportunidad";

    const info = document.createElement("p");
    info.textContent = o.company || o.empresa || "Empresa no indicada";

    const estado = document.createElement("small");
    estado.textContent = "Estado: " + (o.status || o.estado || "Sin especificar");

    card.append(title, info, estado);
    destino.append(card);
  }
}

/* MODULOS_ADMIN_V2 */
const coDatosModulos = {invitaciones:[], mediaciones:[]};

async function coCargarModulo(tipo) {
  const accion = tipo === "invitaciones"
    ? "admin_invited_companies"
    : "admin_mediation_list";

  const destino = document.getElementById("co-lista-" + tipo);
  if (!destino) return;
  destino.textContent = "Cargando...";

  try {
    const r = await fetch("../api.php?action=" + accion, {
      credentials:"same-origin",
      cache:"no-store"
    });
    const d = await r.json();
    if (!r.ok || !d.ok) throw new Error("Consulta no autorizada");

    if (tipo === "invitaciones") {
      coDatosModulos[tipo] = d.companies || [];
    } else {
      coDatosModulos[tipo] =
        d.mediations || d.requests || d.items || [];
    }
    coMostrarModulo(tipo);
  } catch (e) {
    destino.textContent = "No se pudieron cargar los datos.";
  }
}

function coDocumentacionMediacion(id) {
  const detalle = document.createElement("details");
  detalle.className = "co-mediacion-documentos";
  const resumen = document.createElement("summary");
  resumen.textContent = "Documentación";

  const lista = document.createElement("ul");
  const aviso = document.createElement("p");
  aviso.setAttribute("role", "status");

  const formulario = document.createElement("form");
  formulario.className = "co-mediacion-form";

  const nombre = document.createElement("input");
  nombre.name = "document_name";
  nombre.required = true;
  nombre.maxLength = 190;
  const etiquetaNombre = document.createElement("label");
  etiquetaNombre.append("Nombre del documento", nombre);

  const archivo = document.createElement("input");
  archivo.type = "file";
  archivo.name = "pdf";
  archivo.accept = ".pdf,application/pdf";
  archivo.required = true;
  const etiquetaArchivo = document.createElement("label");
  etiquetaArchivo.append("PDF para archivar (máximo 5 MB)", archivo);

  const ayuda = document.createElement("p");
  ayuda.textContent = "La empresa podrá descargar los documentos que archives aquí.";
  const boton = document.createElement("button");
  boton.type = "submit";
  boton.textContent = "Archivar PDF";

  formulario.append(etiquetaNombre, etiquetaArchivo, ayuda, boton);
  detalle.append(resumen, lista, formulario, aviso);

  const url = accion => `/api.php?action=${accion}&mediation_id=${encodeURIComponent(id)}`;

  async function cargar() {
    const r = await fetch(url("mediation_documents_list"), {
      credentials: "same-origin", cache: "no-store"
    });
    const datos = await r.json();
    if (!r.ok || !datos.ok) throw new Error(datos.error || "No se pudo cargar la documentación.");

    lista.replaceChildren();
    if (!datos.documents.length) {
      const item = document.createElement("li");
      item.textContent = "Todavía no hay PDF archivados.";
      lista.append(item);
    }
    for (const documento of datos.documents) {
      const item = document.createElement("li");
      const enlace = document.createElement("a");
      enlace.href = url("mediation_document_download") + `&document_id=${encodeURIComponent(documento.id)}`;
      enlace.textContent = documento.document_name;
      item.append(enlace, ` · ${documento.created_at}`);
      lista.append(item);
    }
  }

  detalle.addEventListener("toggle", async () => {
    if (!detalle.open) return;
    try {
      await cargar();
    } catch (error) {
      aviso.textContent = error.message;
    }
  });

  formulario.addEventListener("submit", async event => {
    event.preventDefault();
    if (archivo.files[0]?.size > 5 * 1024 * 1024) {
      aviso.textContent = "El PDF supera los 5 MB.";
      return;
    }
    boton.disabled = true;
    aviso.textContent = "Archivando…";
    try {
      const sesion = await fetch("/api.php?action=admin_me", {
        credentials: "same-origin", cache: "no-store"
      });
      const acceso = await sesion.json();
      if (!sesion.ok || !acceso.ok || !acceso.csrf) {
        throw new Error("No se pudo comprobar la sesión de administración.");
      }
      const campos = new FormData(formulario);
      campos.set("_csrf", acceso.csrf);
      const r = await fetch(url("mediation_document_upload"), {
        method: "POST",
        credentials: "same-origin",
        headers: {"X-CSRF-Token": acceso.csrf},
        body: campos
      });
      const datos = await r.json();
      if (!r.ok || !datos.ok) throw new Error(datos.error || "No se pudo archivar el PDF.");
      formulario.reset();
      try {
        await cargar();
        aviso.textContent = "PDF archivado.";
      } catch {
        aviso.textContent = "PDF archivado. Vuelve a abrir Documentación para verlo.";
      }
    } catch (error) {
      aviso.textContent = error.message || "Comprueba el listado antes de volver a subir el PDF.";
    } finally {
      boton.disabled = false;
    }
  });

  return detalle;
}
function coCrearFichaMediacion(solicitud) {
  const estados = {
    pending: "Pendiente", reviewing: "En estudio", accepted: "Aceptada",
    rejected: "Rechazada", completed: "Finalizada"
  };
  const cobros = {
    not_collected: "No cobrado", partially_collected: "Cobro parcial",
    collected: "Cobrado"
  };

  const ficha = document.createElement("details");
  ficha.className = "co-modulo-card co-mediacion-ficha";
  const resumen = document.createElement("summary");
  const empresa = solicitud.empresa || solicitud.company || solicitud.company_name || "Empresa";
  const actualizarResumen = () => {
    resumen.textContent = `${empresa} · ${estados[solicitud.status] || solicitud.status || "Sin estado"}`;
  };
  actualizarResumen();

  const formulario = document.createElement("form");
  formulario.className = "co-mediacion-form";
  const titulo = document.createElement("strong");
  titulo.textContent = `#${solicitud.id} · ${solicitud.subject || "Solicitud de mediación"}`;
  const contacto = document.createElement("p");
  contacto.textContent = [solicitud.email, solicitud.telefono].filter(Boolean).join(" · ");
  const descripcion = document.createElement("p");
  descripcion.className = "co-mediacion-descripcion";
  descripcion.textContent = solicitud.details || "";
  formulario.append(titulo, contacto, descripcion);

  function campo(contenedor, etiqueta, control) {
    const label = document.createElement("label");
    label.append(etiqueta, control);
    contenedor.append(label);
    return control;
  }

  function selector(contenedor, etiqueta, opciones, valor) {
    const control = document.createElement("select");
    for (const [clave, texto] of Object.entries(opciones)) {
      control.add(new Option(texto, clave));
    }
    if (!Object.hasOwn(opciones, valor)) {
      control.add(new Option(valor || "Selecciona un estado", valor || ""));
    }
    control.value = valor || "";
    control.required = true;
    return campo(contenedor, etiqueta, control);
  }

  const estado = selector(formulario, "Estado de la solicitud", estados, solicitud.status);
  const observaciones = campo(formulario, "Observaciones visibles para la empresa", document.createElement("textarea"));
  observaciones.rows = 3;
  observaciones.value = solicitud.admin_notes ?? "";

  const economia = document.createElement("div");
  economia.className = "co-mediacion-economia";
  formulario.append(economia);

  function importe(etiqueta, valor) {
    const control = document.createElement("input");
    control.type = "number";
    control.min = "0";
    control.step = "0.01";
    control.inputMode = "decimal";
    control.value = valor ?? "";
    return campo(economia, etiqueta, control);
  }

  const contrato = importe("Importe del contrato (€)", solicitud.contract_amount);
  const porcentaje = importe("Comisión acordada (%)", solicitud.commission_percent);
  porcentaje.max = "100";
  const cobrado = importe("Importe efectivamente cobrado (€)", solicitud.collected_amount);
  const pago = selector(economia, "Estado del cobro", cobros, solicitud.payment_status || "not_collected");

  const acuerdo = document.createElement("input");
  acuerdo.type = "checkbox";
  acuerdo.checked = Number(solicitud.agreement_confirmed) === 1;
  const confirmacion = document.createElement("label");
  confirmacion.className = "co-mediacion-acuerdo";
  confirmacion.append(acuerdo, "Confirmo que existe un acuerdo de comisión por escrito.");

  const estimacion = document.createElement("p");
  const euros = new Intl.NumberFormat("es-ES", {style: "currency", currency: "EUR"});
  const calcular = () => {
    const total = acuerdo.checked && Number.isFinite(cobrado.valueAsNumber) &&
      Number.isFinite(porcentaje.valueAsNumber)
      ? euros.format(cobrado.valueAsNumber * porcentaje.valueAsNumber / 100)
      : "—";
    estimacion.textContent = `Comisión estimada sobre lo efectivamente cobrado: ${total}. No se realiza ningún cargo automático.`;
  };
  [acuerdo, cobrado, porcentaje].forEach(control => control.addEventListener("input", calcular));
  calcular();

  const boton = document.createElement("button");
  boton.type = "submit";
  boton.textContent = "Guardar cambios";
  const aviso = document.createElement("p");
  aviso.setAttribute("role", "status");
  formulario.append(confirmacion, estimacion, boton, aviso);
  ficha.append(resumen, formulario, coDocumentacionMediacion(solicitud.id));

  formulario.addEventListener("submit", async event => {
    event.preventDefault();
    const cambios = {
      status: estado.value,
      admin_notes: observaciones.value,
      contract_amount: contrato.value,
      commission_percent: porcentaje.value,
      collected_amount: cobrado.value,
      agreement_confirmed: acuerdo.checked,
      payment_status: pago.value
    };
    boton.disabled = true;
    aviso.textContent = "Guardando…";

    try {
      const sesion = await fetch("/api.php?action=admin_me", {
        credentials: "same-origin", cache: "no-store"
      });
      const acceso = await sesion.json();
      if (!sesion.ok || !acceso.ok || !acceso.csrf) {
        aviso.textContent = "No se pudo verificar la sesión. Vuelve a entrar en el panel.";
        return;
      }

      const resultado = await fetch("/api.php?action=admin_mediation_update", {
        method: "POST",
        credentials: "same-origin",
        headers: {"Content-Type": "application/json", "X-CSRF-Token": acceso.csrf},
        body: JSON.stringify({id: solicitud.id, ...cambios, _csrf: acceso.csrf})
      });
      const datos = await resultado.json();
      if (!resultado.ok || !datos.ok) {
        aviso.textContent = datos.error || "No se pudieron guardar los cambios.";
        return;
      }
      Object.assign(solicitud, cambios);
      actualizarResumen();
      aviso.textContent = "Cambios guardados.";
    } catch {
      aviso.textContent = "No se pudo confirmar el guardado. Recarga la ficha para comprobarlo.";
    } finally {
      boton.disabled = false;
    }
  });

  return ficha;
}

function coMostrarModulo(tipo) {
  const destino = document.getElementById("co-lista-" + tipo);
  const buscar = document.getElementById("co-buscar-" +
    (tipo === "invitaciones" ? "invitacion" : "mediacion"));
  const q = (buscar?.value || "").trim().toLowerCase();

  destino.replaceChildren();

  const items = coDatosModulos[tipo].filter(x =>
    Object.entries(x)
      .filter(([k,v]) => typeof v === "string" &&
        !/token|password|secret|hash/i.test(k))
      .some(([k,v]) => v.toLowerCase().includes(q))
  );

  if (!items.length) {
    destino.textContent = "No hay resultados.";
    return;
  }

  for (const x of items) {
    if (tipo === "mediaciones") {
      destino.append(coCrearFichaMediacion(x));
      continue;
    }

    const card = document.createElement("article");
    card.className = "co-modulo-card";

    const titulo = document.createElement("strong");
    titulo.textContent = x.company || x.empresa ||
      x.company_name || x.subject || "Registro";

    const info = document.createElement("p");
    info.textContent = x.province || x.provincia ||
      x.status || x.estado || "";

    card.append(titulo, info);
    destino.append(card);
  }
}

document.addEventListener("DOMContentLoaded", () => {
  const nav = document.querySelector(".co-admin-nav");
  if (!nav) return;

  const main = document.querySelector("main");
  const panel = document.getElementById("detalle");
  const secciones = [
    document.getElementById("co-oportunidades"),
    document.getElementById("co-invitaciones"),
    document.getElementById("co-mediaciones")
  ];

  nav.querySelectorAll('a[href^="#co-"]').forEach(enlace => {
    enlace.addEventListener("click", e => {
      e.preventDefault();
      const id = enlace.getAttribute("href").slice(1);

      if (main) main.hidden = true;
      if (panel) panel.hidden = true;

      secciones.forEach(s => {
        if (s) s.hidden = s.id !== id;
      });

      nav.querySelectorAll("a")
        .forEach(a => a.classList.remove("activo"));
      enlace.classList.add("activo");

      if (id === "co-oportunidades") coCargarOportunidades();
      if (id === "co-invitaciones") coCargarModulo("invitaciones");
      if (id === "co-mediaciones") coCargarModulo("mediaciones");
    });
  });

  nav.querySelector('a[href="/admin-nuevo/"]')
    ?.addEventListener("click", e => {
      e.preventDefault();
      secciones.forEach(s => { if (s) s.hidden = true; });
      if (main) main.hidden = false;
      nav.querySelectorAll("a")
        .forEach(a => a.classList.remove("activo"));
      e.currentTarget.classList.add("activo");
    });

  document.getElementById("co-buscar-oportunidad")
    ?.addEventListener("input", coMostrarOportunidades);
  document.getElementById("co-buscar-invitacion")
    ?.addEventListener("input", () => coMostrarModulo("invitaciones"));
  document.getElementById("co-buscar-mediacion")
    ?.addEventListener("input", () => coMostrarModulo("mediaciones"));
});

/* CONECTAJOB_V1 */
document.addEventListener("DOMContentLoaded", () => {
  const enlace = document.querySelector('a[href="#co-conectajob"]');
  const seccion = document.getElementById("co-conectajob");
  const nav = document.querySelector(".co-admin-nav");

  enlace?.addEventListener("click", e => {
    e.preventDefault();

    document.querySelector("main")?.setAttribute("hidden", "");
    document.getElementById("detalle")?.setAttribute("hidden", "");

    ["co-oportunidades", "co-invitaciones", "co-mediaciones"]
      .forEach(id => {
        const el = document.getElementById(id);
        if (el) el.hidden = true;
      });

    seccion.hidden = false;

    nav?.querySelectorAll("a").forEach(a => a.classList.remove("activo"));
    enlace.classList.add("activo");
  });

  nav?.querySelectorAll('a[href^="#co-"], a[href="/admin-nuevo/"]')
    .forEach(a => {
      if (a !== enlace) {
        a.addEventListener("click", () => {
          seccion.hidden = true;
        });
      }
    });
});


/* CONECTAJOB_DATOS_V1 */
(() => {
  let ofertas = [];
  let cargando = false;

  const texto = valor => {
    if (valor === null || valor === undefined) return "";
    return String(valor);
  };

  const campo = (obj, nombres) => {
    for (const nombre of nombres) {
      if (obj[nombre] !== undefined && obj[nombre] !== null) {
        return texto(obj[nombre]);
      }
    }
    return "";
  };

  function pintar() {
    const lista = document.getElementById("co-job-lista");
    const buscar = document.getElementById("co-job-buscar");
    const estado = document.getElementById("co-job-estado");
    if (!lista || !estado) return;

    const q = (buscar?.value || "").toLowerCase().trim();
    lista.replaceChildren();

    const filtradas = ofertas.filter(o =>
      JSON.stringify(o).toLowerCase().includes(q)
    );

    estado.textContent = `${filtradas.length} ofertas encontradas`;

    for (const oferta of filtradas) {
      const tarjeta = document.createElement("article");
      tarjeta.className = "co-job-oferta";

      const titulo = document.createElement("h3");
      titulo.textContent = campo(oferta, [
        "titulo", "title", "puesto", "nombre"
      ]) || "Oferta de empleo";

      const empresa = document.createElement("p");
      empresa.textContent = campo(oferta, [
        "empresa", "company", "empresa_nombre", "nombre_empresa"
      ]);

      const estadoOferta = document.createElement("span");
      estadoOferta.className = "co-job-estado";
      estadoOferta.textContent = campo(oferta, [
        "estado", "status"
      ]) || "Estado no indicado";

      tarjeta.append(titulo, empresa, estadoOferta);
      lista.appendChild(tarjeta);
    }
  }

  async function cargar() {
    if (cargando) return;
    cargando = true;

    const estado = document.getElementById("co-job-estado");
    if (estado) estado.textContent = "Cargando ofertas...";

    try {
      const respuesta = await fetch("conectajob-api.php", {
        method: "GET",
        credentials: "same-origin",
        cache: "no-store"
      });

      if (!respuesta.ok) {
        throw new Error(`API HTTP ${respuesta.status}`);
      }

      const datos = await respuesta.json();

      if (datos?.success === false || datos?.ok === false) {
        throw new Error("La API no permite consultar las ofertas");
      }

      const lista = Array.isArray(datos)
        ? datos
        : [datos?.offers, datos?.ofertas, datos?.jobs, datos?.data]
            .find(Array.isArray);

      if (!Array.isArray(lista)) {
        throw new Error("Formato de respuesta no reconocido");
      }

      ofertas = lista;
      pintar();
    } catch (error) {
      ofertas = [];
      document.getElementById("co-job-lista")?.replaceChildren();
      if (estado) {
        estado.textContent =
          "No se pudieron cargar las ofertas: " + error.message;
      }
    } finally {
      cargando = false;
    }
  }

  document.addEventListener("DOMContentLoaded", () => {
    document.getElementById("co-job-buscar")
      ?.addEventListener("input", pintar);

    document.getElementById("co-job-recargar")
      ?.addEventListener("click", cargar);

    document.querySelector('a[href="#co-conectajob"]')
      ?.addEventListener("click", cargar);
  });
})();










/* CONECTAOBRA_NAVEGACION_FINAL_V1 */
(function () {
  const ids = [
    "co-oportunidades",
    "co-invitaciones",
    "co-mediaciones",
    "co-conectajob",
    "co-comerciales",
    "co-automatizaciones"
  ];

  function ocultarModulos() {
    ids.forEach(id => {
      const el = document.getElementById(id);
      if (el) el.hidden = true;
    });
  }

  function activar(enlace) {
    const nav = document.querySelector(".co-admin-nav");
    nav?.querySelectorAll("a").forEach(a => {
      a.classList.remove("activo");
    });
    enlace.classList.add("activo");
  }

  function linea(destino, texto) {
    const p = document.createElement("p");
    p.textContent = texto;
    destino.appendChild(p);
  }

  async function cargar(url, estadoId, resumenId, pintar) {
    const estado = document.getElementById(estadoId);
    const resumen = document.getElementById(resumenId);

    if (!estado || !resumen) return;

    estado.textContent = "Consultando...";
    resumen.replaceChildren();

    try {
      const respuesta = await fetch(url, {
        credentials: "same-origin",
        cache: "no-store"
      });

      const datos = await respuesta.json();

      if (!respuesta.ok || !datos.ok) {
        throw new Error("Consulta fallida");
      }

      pintar(resumen, datos);
      estado.textContent = "Datos consultados correctamente";
    } catch (error) {
      estado.textContent = "No se pudieron consultar los datos";
    }
  }

  function cargarComerciales() {
    cargar(
      "comerciales-api.php",
      "co-sales-estado",
      "co-sales-resumen",
      (destino, datos) => {
        linea(destino, "Agentes comerciales: " + datos.agentes.length);
        linea(destino, "Comisiones registradas: " + datos.comisiones_total);
        linea(destino, "Liquidaciones: " + datos.liquidaciones.length);

        datos.agentes.forEach(a => {
          linea(
            destino,
            "Agente #" + a.id +
            " · Territorio: " + a.territory +
            " · Estado: " + a.status
          );
        });
      }
    );
  }

  function cargarAutomatizaciones() {
    cargar(
      "automatizaciones-api.php",
      "co-auto-estado",
      "co-auto-resumen",
      (destino, datos) => {
        Object.entries(datos.totales).forEach(([nombre, total]) => {
          linea(destino, nombre + ": " + total);
        });

        datos.eventos.forEach(e => {
          linea(destino, "Evento " + e.status + ": " + e.total);
        });

        datos.entregas.forEach(e => {
          linea(destino, "Entrega " + e.status + ": " + e.total);
        });
      }
    );
  }

  function mostrar(id) {
    const seccion = document.getElementById(id);
    if (!seccion) return;

    ocultarModulos();

    const main = document.querySelector("main");
    if (main) main.hidden = true;

    document.getElementById("detalle")?.setAttribute("hidden", "");

    seccion.hidden = false;

    const enlace = document.querySelector(
      '.co-admin-nav a[href="#' + id + '"]'
    );

    if (enlace) activar(enlace);

    if (id === "co-comerciales") cargarComerciales();
    if (id === "co-automatizaciones") cargarAutomatizaciones();
  }

  document.addEventListener("DOMContentLoaded", () => {
    for (const id of ["co-comerciales", "co-automatizaciones"]) {
      const enlace = document.querySelector(
        '.co-admin-nav a[href="#' + id + '"]'
      );

      enlace?.addEventListener("click", e => {
        e.preventDefault();
        history.replaceState(null, "", "#" + id);
        mostrar(id);
      });
    }

    document.getElementById("co-sales-actualizar")
      ?.addEventListener("click", cargarComerciales);

    document.getElementById("co-auto-actualizar")
      ?.addEventListener("click", cargarAutomatizaciones);

    document.querySelectorAll(
      '.co-admin-nav a[href^="#co-"], .co-admin-nav a[href="/admin-nuevo/"]'
    ).forEach(enlace => {
      const destino = enlace.getAttribute("href");

      if (destino !== "#co-comerciales" &&
          destino !== "#co-automatizaciones") {
        enlace.addEventListener("click", () => {
          document.getElementById("co-comerciales").hidden = true;
          document.getElementById("co-automatizaciones").hidden = true;
        });
      }
    });

    const hash = location.hash.slice(1);
    if (hash === "co-comerciales" || hash === "co-automatizaciones") {
      mostrar(hash);
    }
  });

  window.addEventListener("hashchange", () => {
    const hash = location.hash.slice(1);
    if (hash === "co-comerciales" || hash === "co-automatizaciones") {
      mostrar(hash);
    }
  });
})();


/* CONECTAOBRA_FORMULARIOS_COMERCIALES_V1 */
document.addEventListener("DOMContentLoaded", () => {
  const estado = document.getElementById("co-sales-operacion-estado");

  async function enviar(action, data, mensaje) {
    if (!window.confirm(mensaje)) return;

    estado.textContent = "Guardando operación comercial...";

    try {
      const respuesta = await fetch("comerciales-gestion-api.php", {
        method: "POST",
        credentials: "same-origin",
        headers: {
          "Content-Type": "application/json",
          "X-CSRF-Token": await obtenerTokenComercial()
        },
        body: JSON.stringify({action, ...data})
      });

      const resultado = await respuesta.json();

      if (!respuesta.ok || !resultado.ok) {
        throw new Error(resultado.error || "No se pudo guardar");
      }

      estado.textContent = "Operación completada correctamente. Actualiza el listado.";
    } catch (error) {
      estado.textContent = "No se pudo completar: " + error.message;
    }
  }

  document.getElementById("co-sales-alta")
    ?.addEventListener("submit", e => {
      e.preventDefault();
      const f = new FormData(e.currentTarget);
      const user_id = Number(f.get("user_id"));
      const territory = String(f.get("territory") || "").trim();
      if (!Number.isSafeInteger(user_id) || user_id <= 0 || !territory) return;

      enviar(
        "sales_agent_create",
        {user_id, territory},
        "¿Crear el agente para el usuario #" + user_id + " en estado INACTIVO?"
      );
    });

  document.getElementById("co-sales-territorio")
    ?.addEventListener("submit", e => {
      e.preventDefault();
      const f = new FormData(e.currentTarget);
      const agent_id = Number(f.get("agent_id"));
      const territory = String(f.get("territory") || "").trim();
      if (!Number.isSafeInteger(agent_id) || agent_id <= 0 || !territory) return;

      enviar(
        "sales_agent_territory",
        {agent_id, territory},
        "¿Cambiar el territorio del agente #" + agent_id + "?"
      );
    });

  document.getElementById("co-sales-estado-form")
    ?.addEventListener("submit", e => {
      e.preventDefault();
      const f = new FormData(e.currentTarget);
      const agent_id = Number(f.get("agent_id"));
      const active = f.get("active") === "true";
      if (!Number.isSafeInteger(agent_id) || agent_id <= 0) return;

      enviar(
        "sales_agent_status",
        {agent_id, active},
        "¿Cambiar el agente #" + agent_id +
        " a " + (active ? "ACTIVO" : "INACTIVO") + "?"
      );
    });
});




/* CONECTAOBRA_FREELANCE_CREATE_V1 */
document.addEventListener("DOMContentLoaded", () => {
  const form = document.getElementById("co-freelance-alta");
  const estado = document.getElementById("co-freelance-estado");
  if (!form || !estado) return;

  form.addEventListener("submit", async (evento) => {
    evento.preventDefault();

    const datos = Object.fromEntries(new FormData(form).entries());

    if (!window.confirm(
      "¿Registrar a este profesional como comercial freelance INACTIVO?"
    )) return;

    estado.textContent = "Registrando comercial...";

    try {
      const respuesta = await fetch("comerciales-gestion-api.php", {
        method: "POST",
        credentials: "same-origin",
        headers: {
          "Content-Type": "application/json",
          "X-CSRF-Token": await obtenerTokenComercial()
        },
        body: JSON.stringify({
          action: "sales_freelance_create",
          ...datos
        })
      });

      const resultado = await respuesta.json();

      if (!respuesta.ok || !resultado.ok) {
        throw new Error(resultado.error || "No se pudo registrar");
      }

      estado.textContent =
        "Comercial #" + resultado.agent_id +
        " registrado INACTIVO y sin acceso.";
      form.reset();

    } catch (error) {
      estado.textContent = "Error: " + error.message;
    }
  });
});


/* CONECTAOBRA_CSRF_COMERCIAL_V1 */
async function obtenerTokenComercial() {
  const respuesta = await fetch("comerciales-csrf.php", {
    credentials: "same-origin",
    cache: "no-store"
  });

  const datos = await respuesta.json();

  if (!respuesta.ok || !datos.ok || !datos.csrf) {
    throw new Error("No se pudo obtener el token de seguridad");
  }

  return datos.csrf;
}


async function cargarPlanFicha(panel, companyId) {
  const bloque = document.createElement('div');
  const aviso = document.createElement('p');
  aviso.setAttribute('role', 'status');
  aviso.textContent = 'Consultando plan vigente…';
  bloque.appendChild(aviso);
  panel.appendChild(bloque);

  try {
    const respuesta = await fetch(
      '../api.php?action=admin_companies&plan_user_id=' + encodeURIComponent(companyId),
      {credentials:'same-origin', cache:'no-store'}
    );
    const datos = await respuesta.json();
    if (!respuesta.ok || !datos.ok || !datos.plan_summary) {
      throw new Error('No se pudo consultar el plan.');
    }
    if (!bloque.isConnected) return;

    const plan = datos.plan_summary;
    const estados = {
      active:'Activa', trialing:'En prueba', past_due:'Pago pendiente',
      incomplete:'Incompleta', incomplete_expired:'Incompleta y vencida',
      canceled:'Cancelada', unpaid:'Impagada', paused:'Pausada', pending:'Pendiente'
    };
    const filas = [
      ['Plan vigente', plan.label],
      ['Suscripción', plan.subscription_status
        ? [estados[plan.subscription_status] || plan.subscription_status, plan.subscription_plan]
            .filter(Boolean).join(' · ')
        : 'Sin suscripción'],
    ];
    const quota = plan.contact_quota;
    if (quota) {
      const limit = quota.limit === null ? 'Sin límite' : String(quota.limit);
      filas.push(['Cupo de contactos', quota.month ? 'Mes ' + quota.month + ' · ' + limit : limit]);
      filas.push(['Contactos usados', quota.used]);
      if (quota.remaining !== null) {
        filas.push(['Contactos disponibles', quota.remaining]);
        const date = /^(\d{4})-(\d{2})-(\d{2}) (\d{2}:\d{2})/.exec(quota.resets_at || '');
        if (date) filas.push(['Se reinicia el', date[3] + '/' + date[2] + '/' + date[1] + ' a las ' + date[4]]);
      }
    }
    if (plan.benefit) {
      filas.push(['Beneficio vigente', plan.benefit.label]);
      const fecha = /^(\d{4})-(\d{2})-(\d{2})/.exec(plan.benefit.expires_at || '');
      if (fecha) filas.push(['Vence el', fecha[3] + '/' + fecha[2] + '/' + fecha[1]]);
    }

    bloque.replaceChildren();
    for (const [etiqueta, valor] of filas) {
      const fila = document.createElement('p');
      const titulo = document.createElement('strong');
      titulo.textContent = etiqueta + ': ';
      fila.append(titulo, document.createTextNode(String(valor)));
      bloque.appendChild(fila);
    }
  } catch {
    aviso.textContent = 'No se pudo consultar el plan de esta empresa.';
  }
}
