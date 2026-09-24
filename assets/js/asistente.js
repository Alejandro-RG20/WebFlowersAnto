/**
 * Massiel, asesora floral — el asistente de compras en la tienda.
 *
 * Solo se carga si el asistente está activo, y el panel no existe en la
 * página hasta que alguien lo abre: quien no lo usa no paga nada por él.
 *
 * Seguridad de lo que se pinta:
 *   · El texto del asistente se pinta con textContent. Nunca se interpreta
 *     como HTML, así que ni el modelo ni un cliente malintencionado pueden
 *     inyectar marcado o scripts. Solo se reconoce **negrita**.
 *   · Las tarjetas se construyen con los datos que manda el servidor, que
 *     los saca de la base; y aun así solo se aceptan rutas del propio sitio.
 *   · El botón «Agregar» es un formulario `form-agregar` normal: lo atiende
 *     el mismo código que el botón del catálogo, con su CSRF y sus avisos.
 */
(function () {
  'use strict';

  // Se puede abrir desde la barra (escritorio), desde el botón flotante
  // (móvil y tableta) o desde la invitación del catálogo. Todos comparten estado.
  const boton = document.getElementById('abrirAsesora');
  const disparadores = [boton, ...document.querySelectorAll('[data-abrir-asesora]')].filter(Boolean);
  if (!disparadores.length) { return; }
  let origen = null;

  const base = document.body.dataset.base || '/';
  const csrf = document.body.dataset.csrf || '';
  const ruta = (p) => (base.endsWith('/') ? base : base + '/') + p.replace(/^\//, '');
  const menosMovimiento = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const esEscritorio = () => window.matchMedia('(min-width: 1024px)').matches;
  const CLAVE_ABIERTA = 'fa-asesora-abierta';
  // Lo más que se espera una respuesta: el tiempo máximo del servidor
  // (AI_TIMEOUT) más un margen. Sin límite, si la conexión con el hosting se
  // quedaba colgada, los puntos de «escribiendo» no se iban nunca.
  const guion = document.currentScript;
  const ESPERA_MS = (parseInt(guion && guion.dataset.espera, 10) || 70) * 1000;

  const SUGERENCIAS = [
    'Un regalo romántico',
    'Algo para el cumpleaños de mamá',
    'Elegante, hasta C$1,500',
    '¿Hacen entregas hoy?',
    '¿Dónde está mi pedido?',
  ];

  let panel, hilo, formulario, texto, enviar, cargado = false, ocupado = false, ultimoMensaje = '';

  // -----------------------------------------------------------------------
  // Utilidades
  // -----------------------------------------------------------------------
  function el(etiqueta, clase, contenido) {
    const n = document.createElement(etiqueta);
    if (clase) { n.className = clase; }
    if (contenido !== undefined) { n.textContent = contenido; }
    return n;
  }

  /*
   * Iconos en SVG propio y no de Font Awesome. Los controles de la asesora
   * —cerrar, enviar— no pueden depender de que cargue una hoja de otro
   * servidor: si el CDN falla, quedarían botones en blanco. Los trazos son
   * del juego Lucide (licencia ISC). Son cadenas fijas de este archivo; aquí
   * nunca entra nada que venga del servidor ni del modelo.
   */
  const TRAZOS = {
    flor: '<circle cx="12" cy="12" r="3"/><path d="M12 16.5A4.5 4.5 0 1 1 7.5 12 4.5 4.5 0 1 1 12 7.5a4.5 4.5 0 1 1 4.5 4.5 4.5 4.5 0 1 1-4.5 4.5"/><path d="M12 7.5V9"/><path d="M7.5 12H9"/><path d="M16.5 12H15"/><path d="M12 16.5V15"/><path d="m8 8 1.88 1.88"/><path d="M14.12 9.88 16 8"/><path d="m8 16 1.88-1.88"/><path d="M14.12 14.12 16 16"/>',
    cerrar: '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
    nueva: '<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/>',
    enviar: '<path d="m5 12 7-7 7 7"/><path d="M12 19V5"/>',
    mas: '<path d="M5 12h14"/><path d="M12 5v14"/>',
    bolsa: '<path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/>',
    caja: '<path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/>',
  };
  function icono(nombre, tam) {
    const s = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    s.setAttribute('viewBox', '0 0 24 24');
    s.setAttribute('width', String(tam || 18));
    s.setAttribute('height', String(tam || 18));
    s.setAttribute('aria-hidden', 'true');
    s.setAttribute('focusable', 'false');
    s.setAttribute('class', 'icono-trazo');
    s.innerHTML = TRAZOS[nombre] || '';
    return s;
  }

  /** Solo rutas del propio sitio: nada de «javascript:» ni dominios ajenos. */
  function rutaSegura(u) {
    return typeof u === 'string' && /^\/(?!\/)/.test(u) ? u : '';
  }

  /** Párrafos y **negrita**, sin HTML. */
  function pintarTexto(contenedor, cadena) {
    String(cadena || '').split(/\n{2,}/).forEach((bloque) => {
      const p = el('p');
      bloque.split('\n').forEach((linea, i) => {
        if (i > 0) { p.appendChild(document.createElement('br')); }
        linea.split(/(\*\*[^*]+\*\*)/).forEach((trozo) => {
          if (/^\*\*[^*]+\*\*$/.test(trozo)) {
            p.appendChild(el('strong', '', trozo.slice(2, -2)));
          } else if (trozo) {
            p.appendChild(document.createTextNode(trozo));
          }
        });
      });
      contenedor.appendChild(p);
    });
  }

  async function llamar(datos) {
    const cuerpo = new URLSearchParams(datos);
    cuerpo.set('csrf_token', csrf);
    const corte = new AbortController();
    const reloj = setTimeout(() => corte.abort(), ESPERA_MS);
    let r;
    try {
      r = await fetch(ruta('api/asistente.php'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'fetch' },
        body: cuerpo,
        credentials: 'same-origin',
        signal: corte.signal,
      });
    } catch (e) {
      // Sin respuesta: se cortó por tiempo, se perdió la conexión o el
      // teléfono suspendió la página. El servidor pudo terminar igual.
      const error = new Error(corte.signal.aborted
        ? 'Massiel está tardando más de lo normal en responder.'
        : 'Se cortó la conexión mientras Massiel respondía.');
      error.codigo = 'sin_respuesta';
      throw error;
    } finally {
      clearTimeout(reloj);
    }
    let j = null;
    try { j = await r.json(); } catch (e) { /* respuesta no JSON: se trata como caída */ }
    if (!r.ok || !j || j.ok === false) {
      const error = new Error((j && j.error) || 'No pude responder. Revisa tu conexión e inténtalo otra vez.');
      error.codigo = (j && j.codigo) || (r.status === 429 ? 'limite' : 'no_disponible');
      throw error;
    }
    return j;
  }

  // -----------------------------------------------------------------------
  // Construcción del panel
  // -----------------------------------------------------------------------
  function construir() {
    panel = el('section', 'asesora');
    panel.id = 'asesora';
    panel.hidden = true;
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-modal', 'false');
    panel.setAttribute('aria-labelledby', 'asesoraTitulo');

    const cabecera = el('header', 'asesora-cabecera');
    const marca = el('div', 'asesora-marca');
    const sello = el('span', 'asesora-sello');
    sello.appendChild(icono('flor', 20));
    const titulos = el('div');
    const h2 = el('h2', 'asesora-titulo', 'Massiel');
    h2.id = 'asesoraTitulo';
    h2.tabIndex = -1;
    titulos.append(h2, el('p', 'asesora-subtitulo', 'Asesora floral'));
    marca.append(sello, titulos);

    const nueva = el('button', 'asesora-icono');
    nueva.type = 'button';
    nueva.setAttribute('aria-label', 'Empezar una conversación nueva');
    nueva.title = 'Nueva conversación';
    nueva.appendChild(icono('nueva'));
    nueva.addEventListener('click', reiniciar);

    const cerrarBtn = el('button', 'asesora-icono');
    cerrarBtn.type = 'button';
    cerrarBtn.setAttribute('aria-label', 'Cerrar el chat con Massiel');
    cerrarBtn.appendChild(icono('cerrar', 20));
    cerrarBtn.addEventListener('click', () => cerrar(true));
    cabecera.append(marca, nueva, cerrarBtn);

    hilo = el('div', 'asesora-hilo');
    hilo.setAttribute('role', 'log');
    hilo.setAttribute('aria-live', 'polite');
    hilo.setAttribute('aria-label', 'Conversación con Massiel');
    hilo.tabIndex = 0;

    formulario = el('form', 'asesora-form');
    const etiqueta = el('label', 'visualmente-oculto', 'Escribe tu pregunta');
    etiqueta.htmlFor = 'asesoraTexto';
    texto = el('textarea', 'asesora-entrada');
    texto.id = 'asesoraTexto';
    texto.rows = 1;
    texto.maxLength = 800;
    texto.placeholder = 'Escribe tu pregunta';
    texto.setAttribute('enterkeyhint', 'send');
    texto.setAttribute('autocomplete', 'off');
    enviar = el('button', 'asesora-enviar');
    enviar.type = 'submit';
    enviar.setAttribute('aria-label', 'Enviar pregunta');
    enviar.appendChild(icono('enviar'));
    formulario.append(etiqueta, texto, enviar);

    const nota = el('p', 'asesora-nota', 'Precios y disponibilidad salen del catálogo en tiempo real.');

    panel.append(cabecera, hilo, formulario, nota);
    document.body.appendChild(panel);

    formulario.addEventListener('submit', (ev) => { ev.preventDefault(); mandar(texto.value); });
    texto.addEventListener('keydown', (ev) => {
      if (ev.key === 'Enter' && !ev.shiftKey && !ev.isComposing) { ev.preventDefault(); mandar(texto.value); }
    });
    texto.addEventListener('input', ajustarAlto);
    panel.addEventListener('keydown', (ev) => { if (ev.key === 'Escape') { cerrar(true); } });

    // El panel empieza justo debajo de la barra: el carrito y el menú siguen
    // a la vista y se pueden usar con la asesora abierta.
    const navbar = document.getElementById('navbar');
    const medir = () => {
      const abajo = navbar ? Math.max(0, Math.round(navbar.getBoundingClientRect().bottom)) : 0;
      panel.style.setProperty('--asesora-arriba', abajo + 'px');
    };
    medir();
    if (navbar && 'ResizeObserver' in window) { new ResizeObserver(medir).observe(navbar); }
    window.addEventListener('resize', medir, { passive: true });

    // Teclado del móvil: en iOS la ventana no se encoge al abrirlo y el
    // campo quedaría tapado. Se sube el panel lo que ocupe el teclado.
    if (window.visualViewport) {
      const ajustarTeclado = () => {
        const vv = window.visualViewport;
        const tapa = Math.max(0, window.innerHeight - vv.height - vv.offsetTop);
        panel.style.setProperty('--asesora-teclado', tapa + 'px');
      };
      window.visualViewport.addEventListener('resize', ajustarTeclado);
      window.visualViewport.addEventListener('scroll', ajustarTeclado);
    }
  }

  function ajustarAlto() {
    texto.style.height = 'auto';
    texto.style.height = Math.min(texto.scrollHeight, 120) + 'px';
  }

  function bajar() {
    hilo.scrollTo({ top: hilo.scrollHeight, behavior: menosMovimiento ? 'auto' : 'smooth' });
  }

  // -----------------------------------------------------------------------
  // Mensajes
  // -----------------------------------------------------------------------
  function bienvenida() {
    const caja = el('div', 'asesora-bienvenida');
    const t = el('div', 'asesora-texto');
    pintarTexto(t, 'Hola, soy Massiel, tu asesora floral de Flowers Anto. Cuéntame para quién es el arreglo, la ocasión o tu presupuesto, y te muestro opciones con su precio de hoy.');
    const chips = el('div', 'asesora-sugerencias');
    SUGERENCIAS.forEach((s) => {
      const b = el('button', 'asesora-chip', s);
      b.type = 'button';
      b.addEventListener('click', () => mandar(s));
      chips.appendChild(b);
    });
    caja.append(t, chips);
    hilo.appendChild(caja);
  }

  function burbujaCliente(cadena) {
    const m = el('div', 'asesora-msg cliente');
    m.appendChild(el('p', '', cadena));
    hilo.appendChild(m);
  }

  function tarjeta(p) {
    const url = rutaSegura(p.url);
    const li = el('li', 'asesora-producto');

    const foto = el('a', 'asesora-producto-foto');
    foto.href = url || '#';
    foto.tabIndex = -1;
    foto.setAttribute('aria-hidden', 'true');
    const img = el('img');
    img.src = rutaSegura(p.imagen) || ruta('images/placeholders/logo.svg');
    img.alt = '';
    img.width = 64; img.height = 80;
    img.loading = 'lazy';
    img.decoding = 'async';
    foto.appendChild(img);

    const datos = el('div', 'asesora-producto-datos');
    const nombre = el('a', 'asesora-producto-nombre', p.nombre);
    nombre.href = url || '#';
    const meta = el('span', 'asesora-producto-meta', p.categoria || '');
    const precio = el('span', 'asesora-producto-precio');
    if (p.precio_antes) {
      const s = el('s', '', p.precio_antes);
      s.setAttribute('aria-label', 'Antes ' + p.precio_antes);
      precio.appendChild(s);
    }
    precio.appendChild(el('strong', '', p.precio));
    if (p.descuento) { precio.appendChild(el('span', 'asesora-producto-oferta', '−' + p.descuento)); }
    datos.append(nombre, meta, precio);
    li.append(foto, datos);

    if (p.disponible) {
      const f = el('form', 'form-agregar asesora-agregar');
      f.method = 'post';
      f.action = ruta('carrito.php');
      const id = el('input'); id.type = 'hidden'; id.name = 'producto_id'; id.value = String(parseInt(p.id, 10) || 0);
      const cant = el('input'); cant.type = 'hidden'; cant.name = 'cantidad'; cant.value = '1';
      const b = el('button', 'asesora-btn-agregar');
      b.type = 'submit';
      b.setAttribute('aria-label', 'Agregar ' + p.nombre + ' al carrito');
      b.append(icono('mas', 16), el('span', '', 'Agregar'));
      f.append(id, cant, b);
      li.appendChild(f);
    } else {
      li.appendChild(el('span', 'asesora-producto-agotado', 'Agotado'));
    }
    return li;
  }

  function actualizarCarrito(c) {
    const n = parseInt(c.unidades, 10) || 0;
    const cuenta = document.getElementById('cartCount');
    if (cuenta) {
      cuenta.textContent = String(n);
      cuenta.hidden = n <= 0;
      const enlace = cuenta.closest('a');
      if (enlace) { enlace.setAttribute('aria-label', 'Carrito' + (n ? ' (' + n + ')' : '')); }
    }
  }

  function respuestaAsistente(r, desdeHistorial) {
    const m = el('div', 'asesora-msg asistente');
    const t = el('div', 'asesora-texto');
    pintarTexto(t, r.texto);
    m.appendChild(t);

    if (Array.isArray(r.productos) && r.productos.length) {
      const ul = el('ul', 'asesora-productos');
      ul.setAttribute('aria-label', 'Arreglos sugeridos');
      r.productos.slice(0, 4).forEach((p) => ul.appendChild(tarjeta(p)));
      m.appendChild(ul);
    }
    if (r.carrito && typeof r.carrito === 'object') {
      if (!desdeHistorial) { actualizarCarrito(r.carrito); }
      const n = parseInt(r.carrito.unidades, 10) || 0;
      const fila = el('div', 'asesora-accion');
      fila.appendChild(icono('bolsa', 16));
      fila.appendChild(el('span', '', n === 1 ? '1 arreglo en tu carrito · ' + r.carrito.subtotal
                                            : n + ' arreglos en tu carrito · ' + r.carrito.subtotal));
      if (n > 0) {
        const a = el('a', 'asesora-enlace', 'Ver carrito');
        a.href = ruta('carrito.php');
        fila.appendChild(a);
      }
      m.appendChild(fila);
    }
    if (r.pedido && rutaSegura(r.pedido.url)) {
      const fila = el('div', 'asesora-accion');
      fila.appendChild(icono('caja', 16));
      fila.appendChild(el('span', '', 'Pedido ' + r.pedido.codigo));
      const a = el('a', 'asesora-enlace', 'Ver el pedido');
      a.href = rutaSegura(r.pedido.url);
      fila.appendChild(a);
      m.appendChild(fila);
    }
    hilo.appendChild(m);
  }

  function aviso(cadena) {
    hilo.appendChild(el('p', 'asesora-aviso', cadena));
  }

  function errorEnHilo(mensaje, reintentable) {
    const m = el('div', 'asesora-msg asistente asesora-error');
    m.setAttribute('role', 'alert');
    const t = el('div', 'asesora-texto');
    pintarTexto(t, mensaje);
    m.appendChild(t);
    const acciones = el('div', 'asesora-error-acciones');
    if (reintentable && ultimoMensaje) {
      const b = el('button', 'asesora-chip', 'Reintentar');
      b.type = 'button';
      b.addEventListener('click', () => { m.remove(); mandar(ultimoMensaje, true); });
      acciones.appendChild(b);
    }
    const wa = document.querySelector('.btn-nav-whatsapp');
    if (wa && /^https:\/\//.test(wa.href)) {
      const a = el('a', 'asesora-chip', 'Escribir por WhatsApp');
      a.href = wa.href; a.target = '_blank'; a.rel = 'noopener';
      acciones.appendChild(a);
    }
    m.appendChild(acciones);
    hilo.appendChild(m);
  }

  // Mientras espera, se dice qué pasa: una espera larga en silencio parece
  // una página colgada.
  const PROGRESO = [
    [6000, 'Massiel está revisando el catálogo…'],
    [15000, 'Sigue buscando, un momento más…'],
    [30000, 'Está tardando más de lo normal. Sigo esperando su respuesta…'],
  ];
  let relojesProgreso = [];

  function pensando(mostrar) {
    ocupado = mostrar;
    hilo.setAttribute('aria-busy', mostrar ? 'true' : 'false');
    enviar.disabled = mostrar;
    relojesProgreso.forEach(clearTimeout);
    relojesProgreso = [];
    let ind = hilo.querySelector('.asesora-escribiendo');
    if (!mostrar) { if (ind) { ind.remove(); } return; }
    // Se muestra tras un instante: una respuesta rápida no debe parpadear.
    relojesProgreso.push(setTimeout(() => {
      if (!ocupado || hilo.querySelector('.asesora-escribiendo')) { return; }
      ind = el('div', 'asesora-escribiendo');
      ind.appendChild(el('span', 'visualmente-oculto', 'Massiel está consultando el catálogo…'));
      for (let i = 0; i < 3; i++) { ind.appendChild(el('span', 'asesora-punto')); }
      hilo.appendChild(ind);
      bajar();
    }, 250));
    PROGRESO.forEach(([ms, frase]) => relojesProgreso.push(setTimeout(() => {
      const actual = hilo.querySelector('.asesora-escribiendo');
      if (!ocupado || !actual) { return; }
      let nota = actual.querySelector('.asesora-progreso');
      if (!nota) { nota = el('span', 'asesora-progreso'); nota.setAttribute('role', 'status'); actual.appendChild(nota); }
      nota.textContent = frase;
      bajar();
    }, ms)));
  }

  /*
   * Si la respuesta no llegó al navegador (tiempo agotado, conexión cortada,
   * teléfono bloqueado), el servidor pudo terminarla igual y guardarla en la
   * conversación. Antes de dar el error se mira ahí, un par de veces.
   */
  async function recuperar(ref) {
    for (let intento = 0; intento < 3; intento++) {
      await new Promise((ok) => setTimeout(ok, intento === 0 ? 1500 : 5000));
      try {
        const m = (await llamar({ accion: 'historial' })).mensajes || [];
        const i = m.findIndex((x) => x.rol === 'cliente' && x.ref === ref);
        if (i >= 0 && m[i + 1] && m[i + 1].rol !== 'cliente') { return m[i + 1]; }
      } catch (e) { /* sigue sin conexión: se vuelve a intentar */ }
    }
    return null;
  }

  /** Referencia de un mensaje, para reconocer su respuesta en la conversación guardada. */
  function referencia() {
    const b = new Uint8Array(8);
    (window.crypto || {}).getRandomValues ? window.crypto.getRandomValues(b) : b.forEach((_, i) => { b[i] = Math.random() * 256; });
    return Array.from(b, (x) => x.toString(16).padStart(2, '0')).join('');
  }

  // El punto verde en el botón avisa de que llegó la respuesta con el chat cerrado.
  function marcarNovedad(si) {
    disparadores.forEach((d) => d.classList.toggle('con-novedad', si));
  }
  function respuestaLista() {
    if (!panel || panel.hidden || !panel.classList.contains('abierta')) { marcarNovedad(true); }
  }

  async function mandar(cadena, reintento) {
    const limpio = String(cadena || '').trim();
    if (!limpio || ocupado) { return; }
    const bienv = hilo.querySelector('.asesora-bienvenida .asesora-sugerencias');
    if (bienv) { bienv.remove(); }
    if (!reintento) { burbujaCliente(limpio); }
    ultimoMensaje = limpio;
    texto.value = '';
    ajustarAlto();
    bajar();
    pensando(true);
    const ref = referencia();
    try {
      const r = await llamar({ accion: 'mensaje', mensaje: limpio, ref });
      pensando(false);
      if (r.aviso) { aviso(r.aviso); }
      respuestaAsistente(r, false);
      respuestaLista();
    } catch (e) {
      const guardada = e.codigo === 'sin_respuesta' ? await recuperar(ref) : null;
      pensando(false);
      if (guardada) {
        respuestaAsistente(guardada, false);
        respuestaLista();
      } else {
        errorEnHilo(e.codigo === 'sin_respuesta'
          ? e.message + ' Puedes reintentar o escribirnos por WhatsApp.'
          : e.message, e.codigo !== 'limite');
      }
    }
    bajar();
    if (esEscritorio()) { texto.focus(); }
  }

  async function reiniciar() {
    if (ocupado) { return; }
    try { await llamar({ accion: 'reiniciar' }); } catch (e) { /* sin conexión: se limpia igual la vista */ }
    hilo.textContent = '';
    ultimoMensaje = '';
    bienvenida();
    texto.focus();
  }

  async function cargarHistorial() {
    cargado = true;
    try {
      const r = await llamar({ accion: 'historial' });
      if (!r.mensajes || !r.mensajes.length) { bienvenida(); return; }
      r.mensajes.forEach((m) => {
        if (m.rol === 'cliente') { burbujaCliente(m.texto); } else { respuestaAsistente(m, true); }
      });
      hilo.scrollTop = hilo.scrollHeight;
    } catch (e) {
      bienvenida();
      if (e.codigo === 'no_disponible') { errorEnHilo(e.message, false); }
    }
  }

  // -----------------------------------------------------------------------
  // Abrir y cerrar
  // -----------------------------------------------------------------------
  function abrir(enfocar) {
    if (!panel) { construir(); }
    panel.hidden = false;
    // Un fotograma para que la transición arranque desde el estado cerrado.
    requestAnimationFrame(() => panel.classList.add('abierta'));
    document.body.classList.add('asesora-abierta');
    disparadores.forEach((d) => d.setAttribute('aria-expanded', 'true'));
    marcarNovedad(false);
    try { sessionStorage.setItem(CLAVE_ABIERTA, '1'); } catch (e) { /* almacenamiento bloqueado */ }
    if (!cargado) { cargarHistorial(); }
    if (enfocar) {
      // En pantallas táctiles no se enfoca el campo: abriría el teclado y
      // taparía media pantalla antes de que la persona decida escribir.
      (window.matchMedia('(pointer: coarse)').matches ? panel.querySelector('#asesoraTitulo') : texto).focus();
    }
  }

  function cerrar(devolverFoco) {
    if (!panel || panel.hidden) { return; }
    panel.classList.remove('abierta');
    document.body.classList.remove('asesora-abierta');
    disparadores.forEach((d) => d.setAttribute('aria-expanded', 'false'));
    try { sessionStorage.removeItem(CLAVE_ABIERTA); } catch (e) { /* almacenamiento bloqueado */ }
    const ocultar = () => { if (!panel.classList.contains('abierta')) { panel.hidden = true; } };
    if (menosMovimiento) { ocultar(); } else { setTimeout(ocultar, 220); }
    if (devolverFoco) {
      // Vuelve a quien la abrió si sigue a la vista; si fue el menú del
      // móvil, que ya se cerró, al botón de la hamburguesa.
      const visible = (n) => n && n.getClientRects().length > 0 && getComputedStyle(n).visibility !== 'hidden';
      const destino = [origen, boton, document.getElementById('hamburger')].find(visible);
      if (destino) { destino.focus(); }
    }
  }

  disparadores.forEach((d) => d.addEventListener('click', () => {
    if (panel && !panel.hidden && panel.classList.contains('abierta')) { cerrar(true); return; }
    origen = d;
    // Desde el menú del móvil: se pliega el menú, como hace al tocar un enlace.
    const menu = document.getElementById('navMenu');
    const hamb = document.getElementById('hamburger');
    if (menu && menu.contains(d) && menu.classList.contains('abierto')) {
      menu.classList.remove('abierto');
      if (hamb) { hamb.classList.remove('active'); hamb.setAttribute('aria-expanded', 'false'); hamb.setAttribute('aria-label', 'Abrir menú'); }
    }
    abrir(true);
  }));

  // En escritorio la asesora sigue abierta al cambiar de página, como una
  // compañera de compra. En el móvil no: taparía la página recién abierta.
  let seguia = false;
  try { seguia = sessionStorage.getItem(CLAVE_ABIERTA) === '1'; } catch (e) { /* almacenamiento bloqueado */ }
  if (seguia && esEscritorio()) { abrir(false); }
})();
