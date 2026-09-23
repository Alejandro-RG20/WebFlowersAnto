/**
 * AI Manager — conversación del asistente del panel.
 *
 * Todo se pinta con textContent: ni el texto del modelo ni los datos de la
 * base (nombres de clientes incluidos) se interpretan como HTML.
 *
 * Una propuesta de cambio se enseña con el antes y el después de cada campo
 * y dos botones. Confirmar la manda al servidor, que vuelve a comprobar el
 * permiso y que el dato no haya cambiado; lo que se ve aquí es solo el
 * resultado que devuelve.
 */
(function () {
  'use strict';

  const hilo = document.querySelector('[data-ia-hilo]');
  if (!hilo) { return; }
  const form = document.querySelector('[data-ia-form]');
  const texto = document.querySelector('[data-ia-texto]');
  const enviar = document.querySelector('[data-ia-enviar]');
  const base = document.body.dataset.base || '/';
  const csrf = document.body.dataset.csrf || '';
  const ruta = (p) => (base.endsWith('/') ? base : base + '/') + p.replace(/^\//, '');
  const aviso = window.avisoPanel || ((m) => window.alert(m));
  let ocupado = false;

  const CAMPOS = {
    precio: 'Precio', precio_usd: 'Precio en dólares', descuento_pct: 'Descuento', stock: 'Stock',
    activo: 'Publicado', descripcion: 'Descripción', estado: 'Estado', nota: 'Nota para el historial',
  };
  const ESTADOS_PROPUESTA = {
    pendiente: 'Pendiente de tu confirmación', ejecutada: 'Aplicado', cancelada: 'Descartado',
    caducada: 'Caducó sin confirmar', fallida: 'No se aplicó',
  };

  function el(tag, clase, contenido) {
    const n = document.createElement(tag);
    if (clase) { n.className = clase; }
    if (contenido !== undefined && contenido !== null) { n.textContent = String(contenido); }
    return n;
  }

  function pintarTexto(caja, cadena) {
    String(cadena || '').split(/\n{2,}/).forEach((bloque) => {
      const p = el('p');
      bloque.split('\n').forEach((linea, i) => {
        if (i) { p.appendChild(document.createElement('br')); }
        linea.split(/(\*\*[^*]+\*\*)/).forEach((t) => {
          if (/^\*\*[^*]+\*\*$/.test(t)) { p.appendChild(el('strong', '', t.slice(2, -2))); }
          else if (t) { p.appendChild(document.createTextNode(t)); }
        });
      });
      caja.appendChild(p);
    });
  }

  function formato(campo, v) {
    if (v === null || v === undefined || v === '') { return '—'; }
    if (campo === 'precio') { return 'C$' + Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    if (campo === 'precio_usd') { return 'US$' + Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    if (campo === 'descuento_pct') { return Number(v) === 0 ? 'Sin oferta' : v + '%'; }
    if (campo === 'activo') { return Number(v) === 1 ? 'Sí' : 'No (oculto)'; }
    if (campo === 'estado') { const s = String(v).replace(/_/g, ' '); return s.charAt(0).toUpperCase() + s.slice(1); }
    return String(v);
  }

  async function llamar(datos) {
    const cuerpo = new URLSearchParams(datos);
    cuerpo.set('csrf_token', csrf);
    const r = await fetch(ruta('admin/asistente-api.php'), {
      method: 'POST', credentials: 'same-origin', body: cuerpo,
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'fetch' },
    });
    let j = null;
    try { j = await r.json(); } catch (e) { /* respuesta no JSON */ }
    if (!r.ok || !j) {
      throw new Error((j && j.error) || 'No se pudo contactar con el asistente. El resto del panel funciona con normalidad.');
    }
    return j;
  }

  // -------------------------------------------------------------------
  function mensajePersona(t) {
    const m = el('div', 'ia-msg persona');
    m.appendChild(el('p', '', t));
    hilo.appendChild(m);
  }

  function tabla(t) {
    const caja = el('div', 'ia-tabla');
    const env = el('div', 'tabla-envoltura');
    const tb = el('table', 'tabla');
    tb.appendChild(el('caption', 'ia-tabla-titulo', t.titulo || ''));
    const thead = el('thead'); const trh = el('tr');
    (t.columnas || []).forEach((c) => trh.appendChild(el('th', '', c)));
    thead.appendChild(trh); tb.appendChild(thead);
    const tbody = el('tbody');
    (t.filas || []).forEach((f) => {
      const tr = el('tr');
      (f || []).forEach((c) => tr.appendChild(el('td', '', c)));
      tbody.appendChild(tr);
    });
    if (!(t.filas || []).length) {
      const tr = el('tr'); const td = el('td', 'ia-tabla-vacia', 'Sin resultados.');
      td.colSpan = (t.columnas || []).length || 1; tr.appendChild(td); tbody.appendChild(tr);
    }
    tb.appendChild(tbody); env.appendChild(tb); caja.appendChild(env);
    if (typeof t.enlace === 'string' && /^admin\/[\w\-./?=&]*$/.test(t.enlace)) {
      const a = el('a', 'ia-enlace', 'Abrir en el panel');
      a.href = ruta(t.enlace);
      caja.appendChild(a);
    }
    return caja;
  }

  function propuesta(p) {
    const art = el('article', 'ia-propuesta');
    art.dataset.estado = p.estado || 'pendiente';
    const cab = el('header', 'ia-propuesta-cabecera');
    cab.appendChild(el('span', 'ia-propuesta-etiqueta', 'Cambio propuesto'));
    cab.appendChild(el('strong', 'ia-propuesta-resumen', p.resumen));
    art.appendChild(cab);

    const dl = el('dl', 'ia-diff');
    Object.keys(p.despues || {}).forEach((campo) => {
      if (!(campo in CAMPOS)) { return; }
      const fila = el('div', 'ia-diff-fila' + (campo === 'descripcion' ? ' largo' : ''));
      fila.appendChild(el('dt', '', CAMPOS[campo]));
      const dd = el('dd');
      if (campo !== 'nota') {
        const antes = el('span', 'ia-antes', formato(campo, (p.antes || {})[campo]));
        antes.setAttribute('aria-label', 'Antes: ' + antes.textContent);
        dd.appendChild(antes);
        dd.appendChild(el('span', 'ia-flecha', '→')).setAttribute('aria-hidden', 'true');
      }
      const despues = el('span', 'ia-despues', formato(campo, p.despues[campo]));
      despues.setAttribute('aria-label', (campo === 'nota' ? '' : 'Después: ') + despues.textContent);
      dd.appendChild(despues);
      fila.appendChild(dd);
      dl.appendChild(fila);
    });
    art.appendChild(dl);

    const pie = el('footer', 'ia-propuesta-pie');
    const estado = el('p', 'ia-propuesta-estado', ESTADOS_PROPUESTA[p.estado || 'pendiente'] || '');
    estado.setAttribute('role', 'status');
    pie.appendChild(estado);
    // El resultado guardado a veces repite la etiqueta («Caducó sin confirmar»).
    if (p.resultado && p.estado !== 'pendiente' && p.resultado !== estado.textContent) {
      estado.textContent += ' · ' + p.resultado;
    }

    if ((p.estado || 'pendiente') === 'pendiente') {
      const acciones = el('div', 'ia-propuesta-acciones');
      const si = el('button', 'boton boton-principal', 'Confirmar cambio'); si.type = 'button';
      const no = el('button', 'boton boton-claro', 'Descartar'); no.type = 'button';
      const resolver = async (accion) => {
        si.disabled = true; no.disabled = true;
        try {
          const r = await llamar({ accion, id: String(parseInt(p.id, 10) || 0) });
          art.dataset.estado = r.ok ? (accion === 'confirmar' ? 'ejecutada' : 'cancelada') : 'fallida';
          estado.textContent = r.mensaje;
          acciones.remove();
          aviso(r.mensaje, r.ok ? 'exito' : 'error');
        } catch (e) {
          si.disabled = false; no.disabled = false;
          aviso(e.message, 'error');
        }
      };
      si.addEventListener('click', () => resolver('confirmar'));
      no.addEventListener('click', () => resolver('cancelar'));
      acciones.append(si, no);
      pie.appendChild(acciones);
    }
    art.appendChild(pie);
    return art;
  }

  function mensajeAsistente(r) {
    const m = el('div', 'ia-msg asistente');
    const t = el('div', 'ia-texto');
    pintarTexto(t, r.texto);
    m.appendChild(t);
    if (r.tabla) { m.appendChild(tabla(r.tabla)); }
    (r.propuestas || []).forEach((p) => m.appendChild(propuesta(p)));
    hilo.appendChild(m);
  }

  function errorEnHilo(msg) {
    const m = el('div', 'ia-msg asistente ia-error');
    m.setAttribute('role', 'alert');
    pintarTexto(m, msg);
    hilo.appendChild(m);
  }

  function bajar() { hilo.scrollTop = hilo.scrollHeight; }

  function ocupar(si) {
    ocupado = si;
    hilo.setAttribute('aria-busy', si ? 'true' : 'false');
    enviar.disabled = si;
    let ind = hilo.querySelector('.ia-pensando');
    if (!si) { if (ind) { ind.remove(); } return; }
    setTimeout(() => {
      if (!ocupado || hilo.querySelector('.ia-pensando')) { return; }
      ind = el('p', 'ia-pensando', 'Consultando los datos…');
      hilo.appendChild(ind); bajar();
    }, 250);
  }

  async function mandar(cadena) {
    const limpio = String(cadena || '').trim();
    if (!limpio || ocupado) { return; }
    const bienvenida = hilo.querySelector('[data-ia-bienvenida]');
    if (bienvenida) { bienvenida.remove(); }
    mensajePersona(limpio);
    texto.value = ''; ajustar();
    ocupar(true); bajar();
    try {
      const r = await llamar({ accion: 'mensaje', mensaje: limpio });
      ocupar(false);
      if (r.ok === false) { errorEnHilo(r.error || 'No se pudo responder.'); }
      else {
        if (r.aviso) { hilo.appendChild(el('p', 'ia-aviso', r.aviso)); }
        mensajeAsistente(r);
      }
    } catch (e) {
      ocupar(false);
      errorEnHilo(e.message);
    }
    bajar();
    texto.focus();
  }

  function ajustar() {
    texto.style.height = 'auto';
    texto.style.height = Math.min(texto.scrollHeight, 160) + 'px';
  }

  form.addEventListener('submit', (ev) => { ev.preventDefault(); mandar(texto.value); });
  texto.addEventListener('keydown', (ev) => {
    if (ev.key === 'Enter' && !ev.shiftKey && !ev.isComposing) { ev.preventDefault(); mandar(texto.value); }
  });
  texto.addEventListener('input', ajustar);
  document.querySelectorAll('[data-ia-sugerencia]').forEach((b) => b.addEventListener('click', () => mandar(b.textContent)));

  const reiniciar = document.querySelector('[data-ia-reiniciar]');
  if (reiniciar) {
    reiniciar.addEventListener('click', async () => {
      if (ocupado) { return; }
      try { await llamar({ accion: 'reiniciar' }); } catch (e) { /* se limpia igual la vista */ }
      window.location.reload();
    });
  }

  // La conversación sigue al volver a la página, con el estado real de cada
  // propuesta (si alguien la confirmó o caducó entretanto, se ve así).
  (async () => {
    try {
      const r = await llamar({ accion: 'historial' });
      if (r.mensajes && r.mensajes.length) {
        const bienvenida = hilo.querySelector('[data-ia-bienvenida]');
        if (bienvenida) { bienvenida.remove(); }
        r.mensajes.forEach((m) => (m.rol === 'cliente' ? mensajePersona(m.texto) : mensajeAsistente(m)));
        bajar();
      }
    } catch (e) { /* sin historial: se queda la bienvenida */ }
  })();
})();
