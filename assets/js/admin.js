/**
 * Panel de administración — comportamiento.
 *
 * El panel es server-rendered: todo funciona sin JavaScript salvo la subida de
 * imágenes, que necesita fetch por su naturaleza. Lo demás es comodidad.
 */

(function () {
  'use strict';

  const base = document.body.dataset.base || '/';
  const csrf = document.body.dataset.csrf || '';
  const $  = (s, c) => (c || document).querySelector(s);
  const $$ = (s, c) => Array.from((c || document).querySelectorAll(s));
  const ruta = (p) => (base.endsWith('/') ? base : base + '/') + p.replace(/^\//, '');

  /**
   * URL pública de una imagen guardada.
   *
   * El servidor devuelve «bd:47» cuando la foto vive dentro de la base. Sin
   * traducirlo aquí, la vista previa apuntaba a «/bd:47» y salía rota.
   */
  function urlImagen(r) {
    if (!r) { return ''; }
    if (/^https?:\/\//i.test(r)) { return r; }
    const m = /^bd:(\d+)$/.exec(r);
    return ruta(m ? 'archivo.php?id=' + m[1] : r);
  }

  function aviso(mensaje, tipo = 'exito') {
    const caja = $('#toastContainer');
    if (!caja) { window.alert(mensaje); return; }
    const el = document.createElement('div');
    el.className = 'toast ' + tipo;
    el.setAttribute('role', tipo === 'error' ? 'alert' : 'status');
    el.innerHTML = '<i class="fa-solid ' +
      (tipo === 'error' ? 'fa-circle-exclamation' : 'fa-circle-check') + '"></i><span></span>';
    el.querySelector('span').textContent = mensaje;
    caja.appendChild(el);
    setTimeout(() => el.remove(), 4000);
  }
  window.avisoPanel = aviso;

  // -------------------------------------------------------------------
  // Menú lateral en móvil
  // -------------------------------------------------------------------
  const barra = $('#barraLateral');
  const abrir = $('#abrirMenu');
  const capa  = $('#capaMenu');
  if (barra && abrir && capa) {
    const alternar = (mostrar) => {
      barra.classList.toggle('abierta', mostrar);
      capa.classList.toggle('visible', mostrar);
      capa.hidden = !mostrar;
      abrir.setAttribute('aria-expanded', mostrar ? 'true' : 'false');
      document.body.style.overflow = mostrar ? 'hidden' : '';
    };
    abrir.addEventListener('click', () => alternar(!barra.classList.contains('abierta')));
    capa.addEventListener('click', () => alternar(false));
    document.addEventListener('keydown', (ev) => {
      if (ev.key === 'Escape' && barra.classList.contains('abierta')) { alternar(false); }
    });
  }

  // -------------------------------------------------------------------
  // Confirmaciones
  // -------------------------------------------------------------------
  // El aviso puede estar en el formulario o en el botón que lo envía. Lo
  // segundo hace falta cuando un mismo formulario tiene varias acciones y solo
  // una necesita preguntar: la barra de acciones sobre varios arreglos manda
  // ofertas, quita ofertas y sube precios con el mismo `<form>`, y solo subir
  // el precio toca el precio guardado. Mirando únicamente el formulario, ese
  // aviso no llegaba a aparecer nunca.
  document.addEventListener('submit', (ev) => {
    const emisor  = ev.submitter || null;
    const mensaje = (emisor && emisor.dataset && emisor.dataset.confirmar)
                 || (ev.target.dataset && ev.target.dataset.confirmar);
    if (mensaje && !window.confirm(mensaje)) {
      ev.preventDefault();
      return;
    }
    // Evita el doble envío sin anular el name del botón pulsado.
    const boton = ev.target.querySelector('button[type="submit"]:not([formnovalidate])');
    if (boton && ev.target.dataset.unaVez !== undefined) {
      setTimeout(() => { boton.disabled = true; }, 0);
    }
  });

  // -------------------------------------------------------------------
  // Ventanas modales
  // -------------------------------------------------------------------
  $$('[data-abrir-modal]').forEach((disparador) => {
    disparador.addEventListener('click', (ev) => {
      ev.preventDefault();
      const modal = document.getElementById(disparador.dataset.abrirModal);
      if (!modal) { return; }
      // «Nuevo» tiene que partir en blanco: si no, hereda lo del último editado.
      if (disparador.hasAttribute('data-modal-nuevo')) {
        const formulario = modal.querySelector('form');
        if (formulario) {
          formulario.reset();
          $$('input[type=hidden][name="id"]', formulario).forEach((c) => { c.value = '0'; });
          $$('[data-multi] input[type=checkbox]', formulario).forEach((c) => { c.checked = false; });
        }
      }
      // Los data-campo-* rellenan el formulario del modal antes de mostrarlo.
      Object.keys(disparador.dataset).forEach((clave) => {
        if (!clave.startsWith('campo')) { return; }
        const nombre = clave.slice(5).toLowerCase();
        const destino = modal.querySelector('[name="' + nombre + '"], [data-destino="' + nombre + '"]');
        if (!destino) { return; }
        const valor = disparador.dataset[clave];
        if (destino.hasAttribute('data-multi')) {
          // Contenedor de casillas: el valor es una lista de ids separada por comas.
          const elegidos = String(valor).split(',').filter(Boolean);
          $$('input[type=checkbox]', destino).forEach((c) => {
            c.checked = elegidos.indexOf(c.value) !== -1;
          });
        } else if (destino.type === 'checkbox') {
          destino.checked = valor === '1' || valor === 'true';
        } else if ('value' in destino && destino.tagName !== 'SPAN') {
          destino.value = valor;
        } else {
          destino.textContent = valor;
        }
      });
      $$('[data-multi][data-tope]', modal).forEach((sel) => {
        if (typeof sel.repintarSeleccion === 'function') { sel.repintarSeleccion(); }
      });
      modal.showModal();
      const primero = modal.querySelector('input:not([type=hidden]), textarea, select');
      primero && primero.focus();
    });
  });
  // -------------------------------------------------------------------
  // Selección de varias filas (productos, clientes)
  //
  // «Seleccionar» enseña las casillas y una barra con lo que se puede hacer
  // con lo marcado. Si todo lo de la página está marcado y el filtro tiene
  // más, se ofrece marcar todos los del filtro: entonces no viajan ids, sino
  // `todo_filtro=1` y los filtros, y el servidor vuelve a buscarlos.
  //
  // Los formularios con `data-seleccion-form` reciben la selección al
  // enviarse; así cada acción —la directa de la barra o la de una ventana
  // con sus campos— es su propio formulario y no hay formularios anidados.
  // El servidor comprueba permisos, ids y valores: aquí solo se decide qué
  // se ve.
  // -------------------------------------------------------------------
  $$('[data-seleccion]').forEach((zona) => {
    const barra    = $('[data-seleccion-barra]', zona);
    const casillas = $$('[data-sel-item]', zona);
    const todos    = $('[data-sel-todos]', zona);
    const verN     = $$('[data-sel-n]', document);
    const palabras = $$('[data-sel-palabra]', document);
    const filtro   = $('[data-sel-filtro]', zona);
    const total    = parseInt(zona.dataset.total, 10) || casillas.length;
    const singular = zona.dataset.singular || 'elemento';
    const plural   = zona.dataset.plural || 'elementos';
    const nombre   = zona.dataset.seleccion || 'seleccion';
    let delFiltro  = false;

    const marcadas = () => casillas.filter((c) => c.checked);
    const cuantos  = () => (delFiltro ? total : marcadas().length);

    function refrescar() {
      const n = cuantos();
      verN.forEach((el) => { el.textContent = String(n); });
      palabras.forEach((el) => { el.textContent = n === 1 ? singular : plural; });
      if (todos) {
        const m = marcadas().length;
        todos.checked = m > 0 && m === casillas.length;
        todos.indeterminate = m > 0 && m < casillas.length;
      }
      if (filtro) {
        const todaLaPagina = casillas.length > 0 && marcadas().length === casillas.length;
        filtro.hidden = !(todaLaPagina && total > casillas.length);
        filtro.textContent = delFiltro
          ? 'Quitar la selección del filtro'
          : 'Seleccionar los ' + total + ' ' + plural + ' del filtro';
        filtro.classList.toggle('activo', delFiltro);
      }
      // Los botones dicen sobre cuántos van a actuar antes de pulsarlos.
      $$('[data-sel-accion]', document).forEach((b) => {
        b.disabled = n === 0;
        if (b.dataset.selConfirmar) {
          b.dataset.confirmar = b.dataset.selConfirmar
            .replace('{n}', String(n)).replace('{palabra}', n === 1 ? singular : plural);
        }
      });
      zona.dispatchEvent(new CustomEvent('seleccion:cambio', { detail: { n, delFiltro, marcadas: marcadas() } }));
    }

    function alternar(activar) {
      const activo = activar ?? !zona.classList.contains('seleccionando');
      zona.classList.toggle('seleccionando', activo);
      if (barra) { barra.hidden = !activo; }
      $$('[data-seleccion-alternar]', zona).forEach((b) => {
        b.setAttribute('aria-pressed', String(activo));
        if (b.dataset.textoActivo) {
          b.querySelector('span') && (b.querySelector('span').textContent = activo ? b.dataset.textoActivo : b.dataset.textoInactivo);
        }
      });
      if (!activo) {
        casillas.forEach((c) => { c.checked = false; });
        delFiltro = false;
      }
      refrescar();
      try { sessionStorage.setItem('fa-sel-' + nombre, activo ? '1' : ''); } catch (e) { /* sin almacenamiento */ }
    }

    $$('[data-seleccion-alternar]', zona).forEach((b) => {
      b.dataset.textoInactivo = b.querySelector('span') ? b.querySelector('span').textContent : '';
      b.addEventListener('click', () => alternar());
    });
    casillas.forEach((c) => c.addEventListener('change', () => { delFiltro = false; refrescar(); }));
    todos && todos.addEventListener('change', () => {
      casillas.forEach((c) => { c.checked = todos.checked; });
      delFiltro = false;
      refrescar();
    });
    filtro && filtro.addEventListener('click', () => { delFiltro = !delFiltro; refrescar(); });
    document.addEventListener('keydown', (ev) => {
      if (ev.key === 'Escape' && zona.classList.contains('seleccionando') && !document.querySelector('dialog[open]')) {
        alternar(false);
      }
    });

    // Cada formulario de acción se lleva la selección al enviarse. Va en la
    // fase de captura: así los ids ya están puestos cuando otros manejadores
    // (la confirmación, el bloqueo de doble envío) miran el formulario.
    $$('form[data-seleccion-form]', document).forEach((form) => {
      if (form.dataset.seleccionForm && form.dataset.seleccionForm !== nombre) { return; }
      form.addEventListener('submit', (ev) => {
        $$('.sel-inyectado', form).forEach((i) => i.remove());
        const meter = (name, value) => {
          const i = document.createElement('input');
          i.type = 'hidden'; i.name = name; i.value = value; i.className = 'sel-inyectado';
          form.appendChild(i);
        };
        if (delFiltro) {
          meter('todo_filtro', '1');
        } else {
          const sel = marcadas();
          if (!sel.length && !form.hasAttribute('data-seleccion-opcional')) {
            ev.preventDefault();
            return;
          }
          sel.forEach((c) => meter('ids[]', c.value));
        }
      }, true);
    });

    // Al volver de aplicar una acción se sigue en modo selección.
    let seguir = false;
    try { seguir = sessionStorage.getItem('fa-sel-' + nombre) === '1'; } catch (e) { /* sin almacenamiento */ }
    alternar(seguir && casillas.length > 0);
  });

  // -------------------------------------------------------------------
  // Avisos masivos: los correos salen en tandas mientras la página está abierta
  //
  // Cada llamada manda unos pocos y devuelve el avance. Si algo falla (sin
  // conexión, límite del hosting), se para y se ofrece continuar: el servidor
  // sabe por dónde iba, así que continuar nunca repite correos.
  // -------------------------------------------------------------------
  (function () {
    const caja = $('[data-campana-progreso]');
    if (!caja) { return; }
    const relleno = $('[data-envio-relleno]', caja);
    const texto = $('[data-envio-texto]', caja);
    const barra = $('[role="progressbar"]', caja);
    const reintentar = $('[data-envio-reintentar]', caja);
    const total = parseInt(caja.dataset.conCorreo, 10) || 0;
    if (!total || !texto) { return; }
    let enMarcha = false;

    function pintar(d) {
      const pct = Math.round(100 * d.enviados / Math.max(1, total));
      if (relleno) { relleno.style.width = pct + '%'; }
      if (barra) { barra.setAttribute('aria-valuenow', String(d.enviados)); }
      texto.textContent = d.enviados + ' de ' + total + ' correos enviados. '
        + (d.terminado
          ? (d.fallidos ? d.fallidos + ' no se pudieron enviar (revisa la configuración de correo).' : 'Envío terminado.')
          : 'Enviando… deja esta página abierta.');
      caja.classList.toggle('terminado', !!d.terminado);
    }

    async function seguir() {
      if (enMarcha) { return; }
      enMarcha = true;
      if (reintentar) { reintentar.hidden = true; }
      try {
        for (;;) {
          const datos = new FormData();
          datos.append('csrf_token', csrf);
          datos.append('campana', caja.dataset.campana);
          const r = await fetch(caja.dataset.url, { method: 'POST', body: datos, credentials: 'same-origin',
                                                    headers: { Accept: 'application/json' } });
          const d = await r.json();
          if (!r.ok || !d.ok) { throw new Error(d.error || 'Error'); }
          pintar(d);
          if (d.terminado) { break; }
        }
      } catch (e) {
        texto.textContent = 'El envío se detuvo (' + e.message + '). Lo enviado ya salió; puedes continuar con el resto.';
        if (reintentar) { reintentar.hidden = false; }
      } finally {
        enMarcha = false;
      }
    }
    reintentar && reintentar.addEventListener('click', seguir);
    if ((parseInt(caja.dataset.pendientes, 10) || 0) > 0) { seguir(); }
  })();

  // La nota de las promociones solo aparece cuando el tipo es «Promoción».
  $$('[data-aviso-tipo]').forEach((sel) => {
    const nota = $('[data-aviso-nota-promo]', sel.closest('form'));
    if (!nota) { return; }
    const ver = () => { nota.hidden = sel.value !== 'promocion'; };
    sel.addEventListener('change', ver);
    ver();
  });

  // -------------------------------------------------------------------
  // Productos: vista previa de lo que harán las acciones de precio
  //
  // Cada casilla lleva el precio de siempre y el descuento del arreglo. Con
  // eso se enseña en qué quedan antes de confirmar. El servidor rehace la
  // cuenta al aplicar; esto solo informa.
  // -------------------------------------------------------------------
  (function () {
    const zona = $('[data-seleccion="productos"]');
    if (!zona) { return; }
    const simbolo = zona.dataset.moneda || 'C$';
    const importe = (v) => simbolo + (Math.round(v * 100) / 100).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    const efectivo = (base, p) => (p > 0 && base > 0 ? Math.round(base * (100 - Math.min(95, p))) / 100 : base);
    let ultima = { marcadas: [], delFiltro: false };

    function redondear(v, paso) {
      return paso > 0.01 ? Math.round(v / paso) * paso : Math.round(v * 100) / 100;
    }

    function pintar() {
      const sel = ultima.marcadas;
      const nota = ultima.delFiltro ? ' (cálculo con los de esta página)' : '';
      const vistaOferta = $('[data-vista-oferta]');
      const pct = $('#selPct');
      if (vistaOferta && pct) {
        let antes = 0, despues = 0;
        sel.forEach((c) => {
          const b = parseFloat(c.dataset.precio) || 0;
          antes += efectivo(b, parseInt(c.dataset.pct, 10) || 0);
          despues += efectivo(b, parseInt(pct.value, 10) || 0);
        });
        vistaOferta.textContent = sel.length
          ? 'Ahora suman ' + importe(antes) + '; con el ' + pct.value + '% quedarían en ' + importe(despues) + nota + '.'
          : '';
      }
      const vistaPrecio = $('[data-vista-precio]');
      const dir = $('input[name="direccion"]:checked');
      const modo = $('input[name="modo"]:checked');
      const valor = $('#selValor');
      const paso = $('#selRedondeo');
      if (vistaPrecio && dir && modo && valor) {
        const v = parseFloat(valor.value) || 0;
        const r = parseFloat(paso ? paso.value : '0.01') || 0.01;
        const ejemplos = [];
        let bajoMinimo = 0;
        sel.forEach((c) => {
          const b = parseFloat(c.dataset.precio) || 0;
          let n = modo.value === 'porcentaje' ? b * (1 + (dir.value === 'bajar' ? -v : v) / 100)
                                              : b + (dir.value === 'bajar' ? -v : v);
          n = redondear(n, r);
          if (n < 1) { bajoMinimo++; return; }
          if (ejemplos.length < 3) { ejemplos.push((c.dataset.nombre || '') + ': ' + importe(b) + ' → ' + importe(n)); }
        });
        vistaPrecio.textContent = v > 0 && sel.length
          ? ejemplos.join(' · ') + (sel.length > 3 ? ' …' : '')
            + (bajoMinimo ? ' · ' + bajoMinimo + ' quedarían por debajo de ' + importe(1) + ' y no se cambian.' : '')
            + nota
          : '';
      }
    }

    zona.addEventListener('seleccion:cambio', (ev) => { ultima = ev.detail; pintar(); });
    ['#selPct', '#selValor', '#selRedondeo'].forEach((s) => { const el = $(s); el && el.addEventListener('input', pintar); });
    $$('input[name="direccion"], input[name="modo"]').forEach((el) => el.addEventListener('change', () => {
      const modo = $('input[name="modo"]:checked');
      const unidad = $('[data-precio-unidad]');
      if (unidad && modo) { unidad.textContent = modo.value === 'porcentaje' ? '%' : simbolo; }
      pintar();
    }));
  })();

  // -------------------------------------------------------------------
  // Ficha de producto: en qué queda el precio con el descuento puesto
  //
  // El servidor ya deja escrito el resumen con lo guardado; esto lo rehace
  // mientras se teclea, para no tener que guardar y volver a mirar. Es la
  // misma cuenta que hace `Precios::efectivo()`, y el servidor la repite al
  // guardar: aquí no se decide ningún precio, solo se enseña.
  // -------------------------------------------------------------------
  (function () {
    const caja = $('[data-resumen-precio]');
    if (!caja) { return; }
    const precio = $('#precio');
    const pct    = $('#descuento_pct');
    if (!precio || !pct) { return; }

    const simbolo = caja.dataset.moneda || 'C$';
    const tope    = parseInt(caja.dataset.tope, 10) || 95;
    const elBase   = $('[data-resumen-base]', caja);
    const elFinal  = $('[data-resumen-final]', caja);
    const elAhorro = $('[data-resumen-ahorro]', caja);

    function importe(v) {
      return simbolo + (Math.round(v * 100) / 100)
        .toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    function repintar() {
      const base = parseFloat(precio.value) || 0;
      const n    = Math.max(0, Math.min(tope, parseInt(pct.value, 10) || 0));
      if (n <= 0 || base <= 0) { caja.hidden = true; return; }
      const final = Math.round(base * (100 - n)) / 100;
      caja.hidden = false;
      if (elBase)   { elBase.textContent   = importe(base); }
      if (elFinal)  { elFinal.textContent  = importe(final); }
      if (elAhorro) { elAhorro.textContent = 'El cliente se ahorra ' + importe(base - final) + '.'; }
    }

    precio.addEventListener('input', repintar);
    pct.addEventListener('input', repintar);
    repintar();
  })();

  // -------------------------------------------------------------------
  // Ficha de producto: precio en dólares calculado desde el de córdobas
  //
  // Al escribir el precio en córdobas se propone el de dólares con la tasa de
  // la tienda. Si después se toca la casilla de dólares (para redondear 43.34
  // a 44, por ejemplo), se respeta: solo vuelve a calcularse si cambia otra
  // vez el precio en córdobas. Debajo queda la cuenta exacta y un botón para
  // volver a ella.
  // -------------------------------------------------------------------
  (function () {
    const caja   = $('[data-conversion-usd]');
    const precio = $('#precio');
    const usd    = $('#precio_usd');
    if (!caja || !precio || !usd) { return; }
    const tasa = parseFloat(caja.dataset.tasa) || 0;
    if (tasa <= 0) { return; }
    const detalle = $('[data-conversion-detalle]', caja);

    const exacto = () => {
      const c = parseFloat(precio.value);
      return c > 0 ? Math.round((c / tasa) * 100) / 100 : 0;
    };

    function contar() {
      if (!detalle) { return; }
      const e = exacto();
      detalle.textContent = '';
      if (e <= 0) { return; }
      const actual = parseFloat(usd.value) || 0;
      if (Math.abs(actual - e) < 0.005) {
        detalle.textContent = 'Conversión exacta: US$' + e.toFixed(2) + '.';
        return;
      }
      detalle.append('La conversión exacta es US$' + e.toFixed(2) + '. ');
      const volver = document.createElement('button');
      volver.type = 'button';
      volver.className = 'boton-enlace';
      volver.textContent = 'Usar US$' + e.toFixed(2);
      volver.addEventListener('click', () => { usd.value = e.toFixed(2); contar(); usd.focus(); });
      detalle.append(volver);
    }

    precio.addEventListener('input', () => {
      const e = exacto();
      usd.value = e > 0 ? e.toFixed(2) : '';
      contar();
    });
    usd.addEventListener('input', contar);
    // Un producto nuevo, o uno guardado sin precio en dólares, lo recibe ya.
    if (!(parseFloat(usd.value) > 0) && exacto() > 0) { usd.value = exacto().toFixed(2); }
    contar();
  })();

  // -------------------------------------------------------------------
  // Ficha de producto: el código se comprueba mientras se escribe
  //
  // Se escribe como se guardará (mayúsculas, guiones) y se pregunta al
  // servidor si otro arreglo ya lo usa. Es solo un aviso adelantado: al
  // guardar se vuelve a comprobar y la base tiene un índice único.
  // -------------------------------------------------------------------
  (function () {
    const caja = $('[data-codigo-producto]');
    const campo = caja && $('#codigo', caja);
    const estado = caja && $('[data-codigo-estado]', caja);
    if (!campo || !estado) { return; }
    const ayudaInicial = estado.textContent.trim();
    let temporizador = 0;
    let pedido = null;

    const normalizar = (v) => v.toUpperCase().replace(/[\s_\/.]+/g, '-').replace(/[^A-Z0-9-]/g, '')
      .replace(/-{2,}/g, '-');

    function mostrar(texto, tipo) {
      estado.textContent = texto;
      caja.classList.toggle('con-error', tipo === 'error');
      estado.classList.toggle('ayuda-ok', tipo === 'ok');
    }

    campo.addEventListener('input', () => {
      const limpio = normalizar(campo.value);
      if (limpio !== campo.value) {
        const pos = campo.selectionStart;
        campo.value = limpio;
        campo.setSelectionRange(pos, pos);
      }
      clearTimeout(temporizador);
      if (pedido) { pedido.abort(); }
      const codigo = limpio.replace(/^-+|-+$/g, '');
      if (codigo === '') { mostrar(ayudaInicial, ''); return; }
      temporizador = setTimeout(async () => {
        pedido = new AbortController();
        try {
          const r = await fetch(caja.dataset.comprobar + encodeURIComponent(codigo),
            { signal: pedido.signal, headers: { Accept: 'application/json' }, credentials: 'same-origin' });
          const d = await r.json();
          if (d.libre) { mostrar('«' + d.codigo + '» está libre.', 'ok'); } else { mostrar(d.mensaje, 'error'); }
        } catch (e) {
          if (e.name !== 'AbortError') { mostrar(ayudaInicial, ''); }
        }
      }, 350);
    });
  })();

  // -------------------------------------------------------------------
  // Selector visual de productos: buscar, contar y respetar el tope
  // -------------------------------------------------------------------
  $$('[data-multi][data-tope]').forEach((selector) => {
    const casillas = $$('input[type=checkbox]', selector);
    const cuenta   = $('.selector-cuenta', selector);
    const buscar   = $('.selector-buscar', selector);
    const vacio    = $('.selector-vacio', selector);

    function repintar() {
      const tope     = parseInt(selector.dataset.tope, 10) || 0;
      const marcadas = casillas.filter((c) => c.checked).length;
      if (cuenta) {
        cuenta.textContent = marcadas + ' de ' + tope;
        cuenta.classList.toggle('lleno', marcadas >= tope);
      }
      // Con el tope alcanzado se atenúa lo no elegido, en lugar de dejar
      // marcar de más y que el servidor recorte en silencio al guardar.
      casillas.forEach((c) => {
        const bloquear = !c.checked && marcadas >= tope;
        c.disabled = bloquear;
        c.closest('.selector-item').classList.toggle('tope-alcanzado', bloquear);
      });
    }
    casillas.forEach((c) => c.addEventListener('change', repintar));

    if (buscar) {
      buscar.addEventListener('input', () => {
        const q = buscar.value.trim().toLowerCase();
        let visibles = 0;
        $$('.selector-item', selector).forEach((item) => {
          const coincide = !q || item.dataset.buscar.indexOf(q) !== -1;
          item.hidden = !coincide;
          if (coincide) { visibles++; }
        });
        if (vacio) { vacio.hidden = visibles > 0; }
      });
    }
    // Al abrir el modal las casillas cambian por código, no por el usuario.
    const modal = selector.closest('dialog');
    if (modal) { modal.addEventListener('close', repintar); }
    selector.repintarSeleccion = repintar;
    repintar();
  });

  $$('[data-cerrar-modal]').forEach((boton) => {
    boton.addEventListener('click', (ev) => {
      ev.preventDefault();
      boton.closest('dialog').close();
    });
  });

  // -------------------------------------------------------------------
  // Subida de imágenes
  // -------------------------------------------------------------------
  async function subirImagen(archivo) {
    const datos = new FormData();
    datos.append('imagen', archivo);
    datos.append('csrf_token', csrf);
    const respuesta = await fetch(ruta('admin/subir.php'), {
      method: 'POST', body: datos,
      headers: { 'X-Requested-With': 'fetch' },
      credentials: 'same-origin'
    });

    // Se lee como texto y se convierte aquí: si el hosting cuela un aviso de
    // PHP o una página suya delante del JSON, «Respuesta no válida» a secas no
    // dice nada. Enseñando el principio de lo que llegó, el problema se
    // reconoce de un vistazo en vez de a ciegas.
    const crudo = await respuesta.text();
    let json;
    try {
      json = JSON.parse(crudo);
    } catch (e) {
      const pista = crudo.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 140);
      throw new Error('El servidor respondió algo que no se pudo leer'
        + (pista ? ': “' + pista + '”' : '.'));
    }
    if (!respuesta.ok || !json.ok) { throw new Error(json.error || 'No se pudo subir la imagen.'); }
    return json.ruta;
  }

  /**
   * Galería editable de un producto. El orden de los <input hidden> es el
   * orden de las fotos, y el primero es la portada.
   */
  $$('[data-galeria]').forEach((galeria) => {
    const contenedor = $('.rejilla-imagenes', galeria);
    const entrada    = $('input[type=file]', galeria);
    const zona       = $('.soltar-imagen', galeria);
    const campoNombre = galeria.dataset.galeria;
    // data-maximo="1" convierte la galería en una ranura única (foto del carrusel).
    const maximo = parseInt(galeria.dataset.maximo, 10) || 0;

    function pintar() {
      const casillas = $$('.casilla-imagen', contenedor);
      casillas.forEach((casilla, i) => {
        const marca = $('.portada', casilla);
        if (marca) { marca.hidden = i !== 0; }
      });
      // Con la ranura llena no tiene sentido seguir ofreciendo el recuadro.
      if (maximo) { zona.hidden = casillas.length >= maximo; }
    }

    function agregar(ruta) {
      // En una ranura única, la foto nueva sustituye a la anterior.
      if (maximo === 1) { $$('.casilla-imagen', contenedor).forEach((c) => c.remove()); }
      const casilla = document.createElement('div');
      casilla.className = 'casilla-imagen';
      casilla.innerHTML =
        '<img alt="">' +
        '<button type="button" class="quitar" aria-label="Quitar imagen"><i class="fa-solid fa-xmark"></i></button>' +
        '<span class="portada" hidden>Portada</span>' +
        '<input type="hidden" name="' + campoNombre + '[]">';
      casilla.querySelector('img').src = urlImagen(ruta);
      casilla.querySelector('input').value = ruta;
      contenedor.insertBefore(casilla, zona);
      pintar();
    }

    contenedor.addEventListener('click', (ev) => {
      const quitar = ev.target.closest('.quitar');
      if (quitar) {
        quitar.closest('.casilla-imagen').remove();
        pintar();
      }
    });

    async function procesar(archivos) {
      for (const archivo of Array.from(archivos).slice(0, maximo || 8)) {
        try {
          zona.classList.add('encima');
          agregar(await subirImagen(archivo));
          aviso('Imagen subida');
        } catch (e) {
          aviso(e.message, 'error');
        } finally {
          zona.classList.remove('encima');
        }
      }
      entrada.value = '';
    }

    entrada && entrada.addEventListener('change', () => procesar(entrada.files));
    ['dragenter', 'dragover'].forEach((n) =>
      zona.addEventListener(n, (ev) => { ev.preventDefault(); zona.classList.add('encima'); }));
    ['dragleave', 'drop'].forEach((n) =>
      zona.addEventListener(n, (ev) => { ev.preventDefault(); zona.classList.remove('encima'); }));
    zona.addEventListener('drop', (ev) => { if (ev.dataTransfer.files.length) { procesar(ev.dataTransfer.files); } });

    pintar();
  });

  /** Campo de una sola imagen (logo, favicon, banner…). */
  $$('[data-imagen-simple]').forEach((bloque) => {
    const entrada = $('input[type=file]', bloque);
    const oculto  = $('input[type=hidden]', bloque);
    const vista   = $('img', bloque);
    entrada && entrada.addEventListener('change', async () => {
      const archivo = entrada.files[0];
      if (!archivo) { return; }
      try {
        const ruta = await subirImagen(archivo);
        oculto.value = ruta;
        if (vista) {
          vista.src = urlImagen(ruta);
          vista.hidden = false;
        }
        aviso('Imagen actualizada. Recuerda guardar los cambios.');
      } catch (e) {
        aviso(e.message, 'error');
      } finally {
        entrada.value = '';
      }
    });
  });

  // -------------------------------------------------------------------
  // Filtros que se aplican al cambiar
  // -------------------------------------------------------------------
  $$('[data-autofiltro] select, [data-autofiltro] input[type=checkbox]').forEach((campo) => {
    campo.addEventListener('change', () => campo.form.submit());
  });

  // -------------------------------------------------------------------
  // Restauración de respaldos: hay que escribir la palabra exacta
  // -------------------------------------------------------------------
  $$('[data-frase-confirmacion]').forEach((form) => {
    const frase  = form.dataset.fraseConfirmacion;
    const campo  = $('[name="confirmacion"]', form);
    const boton  = form.querySelector('button[type="submit"]');
    if (!campo || !boton) { return; }
    const revisar = () => { boton.disabled = campo.value.trim().toUpperCase() !== frase.toUpperCase(); };
    campo.addEventListener('input', revisar);
    revisar();
  });

  // -------------------------------------------------------------------
  // Despacho al motorizado
  //
  // El servidor ya guardó la asignación y dejó preparado el enlace; aquí solo
  // se abre WhatsApp en otra pestaña para no sacar al empleado del panel. Si
  // el navegador bloquea la ventana emergente queda el enlace visible.
  // -------------------------------------------------------------------
  const despacho = document.querySelector('[data-abrir-whatsapp]');
  if (despacho) {
    window.open(despacho.dataset.abrirWhatsapp, '_blank', 'noopener');
  }

  // -------------------------------------------------------------------
  // Pestañas: dejar a la vista la que está abierta
  //
  // La tira de pestañas se desplaza en horizontal. Con muchas pestañas, las
  // últimas quedan fuera de la pantalla y al entrar en una de ellas no se veía
  // ninguna marcada: parecía que no se había abierto nada.
  // -------------------------------------------------------------------
  const activa = document.querySelector('.pestanas a.activa');
  if (activa && activa.parentElement.scrollWidth > activa.parentElement.clientWidth) {
    activa.scrollIntoView({ block: 'nearest', inline: 'center' });
  }

})();
