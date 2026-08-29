/**
 * Flowers Anto — comportamiento del sitio público.
 *
 * El HTML lo genera el servidor y funciona sin JavaScript: los formularios de
 * carrito y favoritos se envían de forma nativa si esto no carga. Lo que hay
 * aquí es mejora progresiva — enviar sin recargar, avisos, galería y menús.
 */

(function () {
  'use strict';

  const base = document.body.dataset.base || '/';
  const csrf = document.body.dataset.csrf || '';
  const menosMovimiento = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  const $  = (sel, ctx) => (ctx || document).querySelector(sel);
  const $$ = (sel, ctx) => Array.from((ctx || document).querySelectorAll(sel));
  const ruta = (p) => (base.endsWith('/') ? base : base + '/') + p.replace(/^\//, '');

  // -------------------------------------------------------------------
  // Avisos
  // -------------------------------------------------------------------
  const iconos = { exito: 'fa-circle-check', error: 'fa-circle-exclamation', info: 'fa-circle-info' };

  function aviso(mensaje, tipo = 'exito') {
    const caja = $('#toastContainer');
    if (!caja) { return; }
    const el = document.createElement('div');
    el.className = 'toast ' + tipo;
    el.setAttribute('role', tipo === 'error' ? 'alert' : 'status');
    el.innerHTML = '<i class="fa-solid ' + (iconos[tipo] || iconos.info) + '" aria-hidden="true"></i><span></span>';
    el.querySelector('span').textContent = mensaje;
    caja.appendChild(el);
    setTimeout(() => {
      el.classList.add('saliendo');
      el.addEventListener('animationend', () => el.remove(), { once: true });
      setTimeout(() => el.remove(), 400);
    }, 3600);
  }
  window.avisoFlowers = aviso;

  // -------------------------------------------------------------------
  // Peticiones a la API
  // -------------------------------------------------------------------
  async function pedir(endpoint, datos) {
    const cuerpo = new URLSearchParams(datos || {});
    cuerpo.set('csrf_token', csrf);
    const respuesta = await fetch(ruta('api/' + endpoint), {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'fetch' },
      body: cuerpo,
      credentials: 'same-origin'
    });
    let json;
    try {
      json = await respuesta.json();
    } catch (e) {
      throw new Error('No pudimos conectar con el servidor. Revisa tu conexión.');
    }
    if (!respuesta.ok || json.ok === false) {
      throw new Error(json.error || 'Algo salió mal. Vuelve a intentarlo.');
    }
    return json;
  }

  function pintarContador(id, valor) {
    const el = document.getElementById(id);
    if (!el) { return; }
    el.textContent = valor;
    el.hidden = valor <= 0;
    if (valor > 0 && !menosMovimiento) {
      el.animate(
        [{ transform: 'scale(1)' }, { transform: 'scale(1.35)' }, { transform: 'scale(1)' }],
        { duration: 320, easing: 'cubic-bezier(.3,1.4,.5,1)' }
      );
    }
  }

  // -------------------------------------------------------------------
  // Carrito: los formularios «añadir» se envían sin recargar
  // -------------------------------------------------------------------
  document.addEventListener('submit', async (ev) => {
    const form = ev.target;
    if (!form.classList.contains('form-agregar')) { return; }
    ev.preventDefault();

    const boton = form.querySelector('button[type="submit"]');
    const datos = Object.fromEntries(new FormData(form).entries());
    boton && boton.classList.add('btn-cargando');

    try {
      const r = await pedir('carrito.php', { accion: 'agregar', producto_id: datos.producto_id, cantidad: datos.cantidad || 1 });
      pintarContador('cartCount', r.unidades);
      aviso(r.mensaje || 'Añadido al carrito', r.aviso ? 'info' : 'exito');
    } catch (e) {
      aviso(e.message, 'error');
    } finally {
      boton && boton.classList.remove('btn-cargando');
    }
  });

  // -------------------------------------------------------------------
  // Favoritos
  // -------------------------------------------------------------------
  document.addEventListener('click', async (ev) => {
    const boton = ev.target.closest('[data-favorito]');
    if (!boton) { return; }
    ev.preventDefault();

    const id = boton.dataset.favorito;
    try {
      const r = await pedir('favoritos.php', { accion: 'alternar', producto_id: id });
      // Todas las apariciones del mismo producto en la página se actualizan a la vez.
      $$('[data-favorito="' + id + '"]').forEach((b) => {
        b.classList.toggle('active', r.favorito);
        b.setAttribute('aria-pressed', r.favorito ? 'true' : 'false');
        b.setAttribute('aria-label', r.favorito ? 'Quitar de favoritos' : 'Añadir a favoritos');
        const icono = b.querySelector('i');
        if (icono) { icono.className = (r.favorito ? 'fa-solid' : 'fa-regular') + ' fa-heart'; }
      });
      if (!menosMovimiento && r.favorito) {
        boton.classList.add('latido');
        setTimeout(() => boton.classList.remove('latido'), 500);
      }
      pintarContador('favCount', r.total);
      aviso(r.favorito ? 'Guardado en favoritos' : 'Quitado de favoritos', 'exito');

      // En la página de favoritos, la tarjeta desaparece al quitarla.
      if (!r.favorito && document.body.classList.contains('pagina-favoritos')) {
        const tarjeta = boton.closest('[data-producto]');
        if (tarjeta) {
          tarjeta.style.transition = 'opacity .25s, transform .25s';
          tarjeta.style.opacity = '0';
          tarjeta.style.transform = 'scale(.96)';
          setTimeout(() => {
            tarjeta.remove();
            if (!$('.rejilla-productos [data-producto]')) { window.location.reload(); }
          }, 260);
        }
      }
    } catch (e) {
      aviso(e.message, 'error');
    }
  });

  // -------------------------------------------------------------------
  // Barra de navegación
  // -------------------------------------------------------------------
  const navbar = $('#navbar');
  if (navbar) {
    const alHacerScroll = () => navbar.classList.toggle('scrolled', window.scrollY > 40);
    alHacerScroll();
    window.addEventListener('scroll', alHacerScroll, { passive: true });
  }

  const hamburguesa = $('#hamburger');
  const menu = $('#navMenu');
  if (hamburguesa && menu) {
    hamburguesa.addEventListener('click', () => {
      const abierto = menu.classList.toggle('abierto');
      hamburguesa.classList.toggle('active', abierto);
      hamburguesa.setAttribute('aria-expanded', abierto ? 'true' : 'false');
      hamburguesa.setAttribute('aria-label', abierto ? 'Cerrar menú' : 'Abrir menú');
    });
    menu.addEventListener('click', (ev) => {
      if (ev.target.closest('a')) {
        menu.classList.remove('abierto');
        hamburguesa.classList.remove('active');
        hamburguesa.setAttribute('aria-expanded', 'false');
      }
    });
  }

  // Menú de la cuenta
  const btnUsuario = $('#btnUsuario');
  const menuUsuario = $('#menuUsuario');
  if (btnUsuario && menuUsuario) {
    btnUsuario.addEventListener('click', (ev) => {
      ev.stopPropagation();
      const abierto = menuUsuario.hidden;
      menuUsuario.hidden = !abierto;
      btnUsuario.setAttribute('aria-expanded', abierto ? 'true' : 'false');
    });
    document.addEventListener('click', (ev) => {
      if (!menuUsuario.hidden && !menuUsuario.contains(ev.target)) {
        menuUsuario.hidden = true;
        btnUsuario.setAttribute('aria-expanded', 'false');
      }
    });
    document.addEventListener('keydown', (ev) => {
      if (ev.key === 'Escape' && !menuUsuario.hidden) {
        menuUsuario.hidden = true;
        btnUsuario.setAttribute('aria-expanded', 'false');
        btnUsuario.focus();
      }
    });
  }

  // -------------------------------------------------------------------
  // Aparición de bloques al entrar en pantalla
  // -------------------------------------------------------------------
  const aparecen = $$('.aparece');
  if (aparecen.length) {
    if (menosMovimiento || !('IntersectionObserver' in window)) {
      aparecen.forEach((el) => el.classList.add('visible'));
    } else {
      const observador = new IntersectionObserver((entradas) => {
        entradas.forEach((entrada) => {
          if (entrada.isIntersecting) {
            entrada.target.classList.add('visible');
            observador.unobserve(entrada.target);
          }
        });
      }, { threshold: 0.12, rootMargin: '0px 0px -40px' });
      aparecen.forEach((el) => observador.observe(el));
    }
  }

  // -------------------------------------------------------------------
  // Selector de cantidad
  // -------------------------------------------------------------------
  $$('.selector-cantidad').forEach((selector) => {
    const entrada = $('input', selector);
    const menos = $('[data-paso="-1"]', selector);
    const mas = $('[data-paso="1"]', selector);
    if (!entrada) { return; }

    const limites = () => {
      const v = parseInt(entrada.value, 10) || 1;
      if (menos) { menos.disabled = v <= (parseInt(entrada.min, 10) || 1); }
      if (mas) { mas.disabled = v >= (parseInt(entrada.max, 10) || 20); }
    };
    const cambiar = (paso) => {
      const min = parseInt(entrada.min, 10) || 1;
      const max = parseInt(entrada.max, 10) || 20;
      entrada.value = Math.max(min, Math.min(max, (parseInt(entrada.value, 10) || min) + paso));
      entrada.dispatchEvent(new Event('change', { bubbles: true }));
      limites();
    };
    menos && menos.addEventListener('click', () => cambiar(-1));
    mas && mas.addEventListener('click', () => cambiar(1));
    entrada.addEventListener('change', limites);
    limites();
  });

  // Las líneas del carrito se envían solas al cambiar la cantidad.
  $$('form[data-autoenviar]').forEach((form) => {
    const entrada = $('input[name="cantidad"]', form);
    if (!entrada) { return; }
    let temporizador;
    entrada.addEventListener('change', () => {
      clearTimeout(temporizador);
      temporizador = setTimeout(() => form.submit(), 320);
    });
  });

  // -------------------------------------------------------------------
  // Galería de la ficha de producto
  // -------------------------------------------------------------------
  const galeria = $('#galeriaProducto');
  if (galeria) {
    const pista = $('.galeria-pista', galeria);
    const diapositivas = $$('.galeria-diapositiva', galeria);
    const miniaturas = $$('#galeriaMiniaturas button');
    const anterior = $('.galeria-flecha.anterior', galeria);
    const siguiente = $('.galeria-flecha.siguiente', galeria);
    const contador = $('.galeria-contador', galeria);
    let indice = 0;

    function mostrar(n) {
      indice = Math.max(0, Math.min(diapositivas.length - 1, n));
      pista.style.transform = 'translateX(' + (-indice * 100) + '%)';
      miniaturas.forEach((m, i) => m.setAttribute('aria-current', i === indice ? 'true' : 'false'));
      if (anterior) { anterior.disabled = indice === 0; }
      if (siguiente) { siguiente.disabled = indice === diapositivas.length - 1; }
      if (contador) { contador.textContent = (indice + 1) + ' / ' + diapositivas.length; }
    }

    anterior && anterior.addEventListener('click', () => mostrar(indice - 1));
    siguiente && siguiente.addEventListener('click', () => mostrar(indice + 1));
    miniaturas.forEach((m, i) => m.addEventListener('click', () => mostrar(i)));

    galeria.addEventListener('keydown', (ev) => {
      if (ev.key === 'ArrowLeft') { mostrar(indice - 1); }
      if (ev.key === 'ArrowRight') { mostrar(indice + 1); }
    });

    // Deslizar con el dedo
    let inicioX = 0, inicioY = 0, arrastrando = false;
    galeria.addEventListener('touchstart', (ev) => {
      inicioX = ev.touches[0].clientX;
      inicioY = ev.touches[0].clientY;
      arrastrando = true;
    }, { passive: true });
    galeria.addEventListener('touchend', (ev) => {
      if (!arrastrando) { return; }
      arrastrando = false;
      const dx = ev.changedTouches[0].clientX - inicioX;
      const dy = ev.changedTouches[0].clientY - inicioY;
      // Solo cuenta si el gesto fue claramente horizontal: si no, es un scroll.
      if (Math.abs(dx) > 45 && Math.abs(dx) > Math.abs(dy) * 1.4) {
        mostrar(indice + (dx < 0 ? 1 : -1));
      }
    }, { passive: true });

    mostrar(0);

    // Visor a pantalla completa
    const visor = $('#visor');
    if (visor) {
      const visorImg = $('img', visor);
      let ultimoFoco = null;
      const abrir = (src, alt) => {
        ultimoFoco = document.activeElement;
        visorImg.src = src;
        visorImg.alt = alt || '';
        visor.classList.add('abierto');
        document.body.style.overflow = 'hidden';
        $('.visor-cerrar', visor).focus();
      };
      const cerrar = () => {
        visor.classList.remove('abierto');
        document.body.style.overflow = '';
        ultimoFoco && ultimoFoco.focus();
      };
      $$('.galeria-diapositiva img', galeria).forEach((img) => {
        img.addEventListener('click', () => abrir(img.src, img.alt));
      });
      $('.visor-cerrar', visor).addEventListener('click', cerrar);
      visor.addEventListener('click', (ev) => { if (ev.target === visor) { cerrar(); } });
      document.addEventListener('keydown', (ev) => {
        if (ev.key === 'Escape' && visor.classList.contains('abierto')) { cerrar(); }
      });
    }
  }

  // -------------------------------------------------------------------
  // Botones «copiar» de los datos bancarios
  // -------------------------------------------------------------------
  $$('[data-copiar]').forEach((boton) => {
    boton.addEventListener('click', async () => {
      const texto = boton.dataset.copiar;
      try {
        if (navigator.clipboard && window.isSecureContext) {
          await navigator.clipboard.writeText(texto);
        } else {
          // Sin HTTPS el portapapeles moderno no está disponible.
          const tmp = document.createElement('textarea');
          tmp.value = texto;
          tmp.style.position = 'fixed';
          tmp.style.opacity = '0';
          document.body.appendChild(tmp);
          tmp.select();
          document.execCommand('copy');
          tmp.remove();
        }
        const original = boton.textContent;
        boton.textContent = '¡Copiado!';
        boton.classList.add('copiado');
        setTimeout(() => { boton.textContent = original; boton.classList.remove('copiado'); }, 1800);
      } catch (e) {
        aviso('No se pudo copiar. Selecciónalo a mano.', 'error');
      }
    });
  });

  // -------------------------------------------------------------------
  // Subida de comprobante: vista previa y validación en el navegador
  // (el servidor vuelve a validarlo todo; esto solo evita un viaje inútil)
  // -------------------------------------------------------------------
  const zona = $('#zonaComprobante');
  if (zona) {
    const entrada = $('input[type="file"]', zona.parentElement) || $('#archivoComprobante');
    const previa = $('#vistaPrevia');
    const maximo = parseInt(zona.dataset.maxBytes, 10) || 8388608;
    const tipos = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf'];

    const mostrarArchivo = (archivo) => {
      if (!archivo) { return; }
      if (!tipos.includes(archivo.type)) {
        aviso('El comprobante debe ser una imagen (JPG, PNG, WEBP) o un PDF.', 'error');
        entrada.value = '';
        return;
      }
      if (archivo.size > maximo) {
        aviso('El archivo pesa más de ' + Math.round(maximo / 1048576) + ' MB.', 'error');
        entrada.value = '';
        return;
      }
      const kb = archivo.size < 1048576
        ? Math.round(archivo.size / 1024) + ' KB'
        : (archivo.size / 1048576).toFixed(1) + ' MB';

      const visual = archivo.type === 'application/pdf'
        ? '<div class="vista-previa-icono"><i class="fa-solid fa-file-pdf"></i></div>'
        : '<img alt="Vista previa del comprobante">';

      previa.innerHTML = '<div class="vista-previa-caja">' + visual +
        '<div class="vista-previa-datos"><strong></strong><small>' + kb + '</small></div>' +
        '<button type="button" class="btn-quitar" id="quitarArchivo">' +
        '<i class="fa-solid fa-xmark"></i> Quitar</button></div>';
      previa.querySelector('strong').textContent = archivo.name;
      previa.classList.add('visible');

      const img = previa.querySelector('img');
      if (img) {
        const url = URL.createObjectURL(archivo);
        img.src = url;
        img.addEventListener('load', () => URL.revokeObjectURL(url), { once: true });
      }
      $('#quitarArchivo').addEventListener('click', () => {
        entrada.value = '';
        previa.classList.remove('visible');
        previa.innerHTML = '';
      });
    };

    entrada && entrada.addEventListener('change', () => mostrarArchivo(entrada.files[0]));

    ['dragenter', 'dragover'].forEach((evento) => {
      zona.addEventListener(evento, (ev) => { ev.preventDefault(); zona.classList.add('encima'); });
    });
    ['dragleave', 'drop'].forEach((evento) => {
      zona.addEventListener(evento, (ev) => { ev.preventDefault(); zona.classList.remove('encima'); });
    });
    zona.addEventListener('drop', (ev) => {
      const archivo = ev.dataTransfer.files[0];
      if (archivo && entrada) {
        const dt = new DataTransfer();
        dt.items.add(archivo);
        entrada.files = dt.files;
        mostrarArchivo(archivo);
      }
    });
  }

  // -------------------------------------------------------------------
  // Medidor de fuerza de la contraseña
  // -------------------------------------------------------------------
  const campoPassword = $('#password');
  const medidor = $('#medidorPassword');
  if (campoPassword && medidor) {
    const barra = medidor.querySelector('span');
    campoPassword.addEventListener('input', () => {
      const v = campoPassword.value;
      let puntos = 0;
      if (v.length >= 8) { puntos++; }
      if (v.length >= 12) { puntos++; }
      if (/[a-zà-ÿ]/i.test(v) && /\d/.test(v)) { puntos++; }
      if (/[^\w\s]/.test(v)) { puntos++; }
      const colores = ['#D98B93', '#D9A85C', '#C9B449', '#6FA36B', '#2F6B44'];
      barra.style.width = (puntos / 4 * 100) + '%';
      barra.style.background = colores[puntos] || colores[0];
    });
  }

  // -------------------------------------------------------------------
  // Formularios: evitar el doble envío
  // -------------------------------------------------------------------
  $$('form[data-una-vez]').forEach((form) => {
    form.addEventListener('submit', () => {
      const boton = form.querySelector('button[type="submit"]');
      if (boton) {
        boton.classList.add('btn-cargando');
        // Se desactiva después del envío para no anular el name del botón.
        setTimeout(() => { boton.disabled = true; }, 0);
      }
    });
  });

  // -------------------------------------------------------------------
  // Confirmación de acciones destructivas
  // -------------------------------------------------------------------
  document.addEventListener('submit', (ev) => {
    const mensaje = ev.target.dataset ? ev.target.dataset.confirmar : null;
    if (mensaje && !window.confirm(mensaje)) {
      ev.preventDefault();
    }
  });

  // -------------------------------------------------------------------
  // Los favoritos del visitante viajan con él hasta que abre una cuenta
  // -------------------------------------------------------------------
  const CLAVE_FAVS = 'flowersanto:favs';
  function sincronizarFavoritosLocales() {
    if (document.body.dataset.autenticado === '1') {
      try { localStorage.removeItem(CLAVE_FAVS); } catch (e) { /* modo privado */ }
      return;
    }
    let guardados = [];
    try { guardados = JSON.parse(localStorage.getItem(CLAVE_FAVS) || '[]'); } catch (e) { guardados = []; }
    const enPagina = $$('[data-favorito].active').map((b) => parseInt(b.dataset.favorito, 10));
    const pendientes = guardados.filter((id) => Number.isInteger(id));

    if (pendientes.length && document.body.dataset.favsSincronizados !== '1') {
      pedir('favoritos.php', { accion: 'fusionar', ids: pendientes.join(',') })
        .then((r) => {
          document.body.dataset.favsSincronizados = '1';
          pintarContador('favCount', r.total);
        })
        .catch(() => { /* si falla, se reintenta en la siguiente página */ });
    }
    if (enPagina.length) {
      try {
        const union = Array.from(new Set(pendientes.concat(enPagina)));
        localStorage.setItem(CLAVE_FAVS, JSON.stringify(union));
      } catch (e) { /* almacenamiento lleno o bloqueado */ }
    }
  }
  sincronizarFavoritosLocales();
})();
