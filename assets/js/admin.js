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
  // Acciones sobre varios productos
  //
  // La barra solo aparece cuando hay algo marcado: enseñarla siempre vacía
  // invita a pulsar botones que no harían nada. El formulario se envía
  // entero, así que quien manda es el servidor; esto solo decide qué se ve.
  // -------------------------------------------------------------------
  (function () {
    const barra = $('[data-barra-masiva]');
    if (!barra) { return; }
    const casillas = $$('[data-masiva-item]');
    const todos    = $('[data-masiva-todos]');
    const cuenta   = $('[data-masiva-n]', barra);
    const palabra  = $('[data-masiva-palabra]', barra);
    const vista    = $('[data-masiva-vista]', barra);
    const pct      = $('[data-masiva-pct]', barra);
    const monto    = $('[data-masiva-monto]', barra);
    const simbolo  = barra.dataset.moneda || 'C$';
    const TOPE_PCT = 95;

    /** Mismo formato que `dinero()` en el servidor, para que no canten. */
    function importe(v) {
      return simbolo + (Math.round(v * 100) / 100)
        .toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    /** Lo que se cobra: el precio de siempre menos su porcentaje. */
    function efectivo(base, p) {
      const n = Math.max(0, Math.min(TOPE_PCT, p));
      return n <= 0 || base <= 0 ? base : Math.round(base * (100 - n)) / 100;
    }

    function marcadas() {
      return casillas.filter((c) => c.checked);
    }

    /**
     * El renglón que dice en qué queda la acción.
     *
     * Se calculan las tres a la vez —oferta, retirada y aumento— porque
     * cualquiera de los tres botones está a un clic, y enseñar solo una
     * obligaría a adivinar las otras. Se escribe con `textContent`: los
     * nombres de los arreglos los teclea una persona y aquí no se convierten
     * en HTML.
     */
    function repintarVista() {
      if (!vista) { return; }
      const sel = marcadas();
      if (!sel.length) { vista.textContent = ''; return; }

      let ahora = 0, siOferta = 0, siQuita = 0, siSube = 0, enOferta = 0;
      const nuevoPct = pct ? parseInt(pct.value, 10) || 0 : 0;
      const sube     = monto ? parseFloat(monto.value) || 0 : 0;

      sel.forEach((c) => {
        const base = parseFloat(c.dataset.precio) || 0;
        const p    = parseInt(c.dataset.pct, 10) || 0;
        if (p > 0) { enOferta++; }
        ahora    += efectivo(base, p);
        siOferta += efectivo(base, nuevoPct);
        siQuita  += base;
        siSube   += efectivo(base + Math.max(0, sube), p);
      });

      const partes = ['Ahora suman ' + importe(ahora) + '.'];
      if (nuevoPct > 0) {
        partes.push('Con el ' + nuevoPct + '%: ' + importe(siOferta) +
                    ' (' + importe(ahora - siOferta) + ' menos).');
      }
      if (enOferta > 0) {
        partes.push('Quitando la oferta de ' + enOferta +
                    (enOferta === 1 ? ' arreglo' : ' arreglos') + ': ' + importe(siQuita) + '.');
      }
      if (sube > 0) {
        partes.push('Subiendo ' + importe(sube) + ' cada uno: ' + importe(siSube) + '.');
      }
      vista.textContent = partes.join(' ');
    }

    function refrescar() {
      const n = marcadas().length;
      barra.hidden = n === 0;
      if (cuenta)  { cuenta.textContent = String(n); }
      if (palabra) { palabra.textContent = n === 1 ? 'arreglo' : 'arreglos'; }
      if (todos) {
        todos.checked = n > 0 && n === casillas.length;
        todos.indeterminate = n > 0 && n < casillas.length;
      }
      repintarVista();
    }

    casillas.forEach((c) => c.addEventListener('change', refrescar));
    todos && todos.addEventListener('change', () => {
      casillas.forEach((c) => { c.checked = todos.checked; });
      refrescar();
    });
    pct   && pct.addEventListener('change', repintarVista);
    monto && monto.addEventListener('input', repintarVista);

    /**
     * Lo que se pregunta antes de tocar nada.
     *
     * Subir el precio cambia el precio guardado y no hay botón para
     * deshacerlo, así que se nombra la cifra concreta en lugar de un «¿seguro?»
     * genérico. Quitar la oferta y ponerla se ven al instante en la tabla y se
     * rehacen en dos clics: ahí basta con el renglón de la vista previa.
     *
     * Las tres comprobaciones de aquí abajo existen también en el servidor;
     * esta copia solo evita un viaje y un mensaje de error.
     */
    const formulario = barra.closest('form');
    formulario && formulario.addEventListener('submit', (ev) => {
      const emisor = ev.submitter;
      const accion = emisor && emisor.dataset ? emisor.dataset.masivaAccion : '';
      if (!accion) { return; }

      const sel = marcadas();
      if (!sel.length) {
        ev.preventDefault();
        aviso('Marca al menos un arreglo antes de aplicar la acción.', 'error');
        return;
      }
      if (accion !== 'aumentar') { return; }

      const cifra = monto ? parseFloat(monto.value) : NaN;
      if (!(cifra > 0)) {
        ev.preventDefault();
        aviso('Escribe cuánto quieres subir el precio.', 'error');
        monto && monto.focus();
        return;
      }
      const texto = 'Vas a subir ' + importe(cifra) + ' el precio de siempre de ' +
        sel.length + (sel.length === 1 ? ' arreglo' : ' arreglos') +
        '. No hay forma de deshacerlo desde aquí. ¿Seguimos?';
      if (!window.confirm(texto)) { ev.preventDefault(); }
    });

    refrescar();
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
  // Barras del gráfico: se dibujan al cargar para que la altura anime
  // -------------------------------------------------------------------
  requestAnimationFrame(() => {
    $$('.grafico-barra .valor').forEach((barra) => {
      barra.style.height = (barra.dataset.altura || '0') + '%';
    });
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
