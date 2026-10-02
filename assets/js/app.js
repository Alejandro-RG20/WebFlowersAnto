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
      // La medición se entera cuando el servidor ya aceptó, no al pulsar.
      // Quien escucha esto es analitica.js, si la medición está encendida.
      // Medir nunca puede estropear una compra que ya salió bien: por eso va
      // en su propio try, y un dato mal formado se queda sin evento y ya.
      try {
        const conProducto = form.closest('[data-ga-item]');
        if (conProducto) {
          document.dispatchEvent(new CustomEvent('fa:carrito:agregado', {
            detail: {
              item: JSON.parse(conProducto.dataset.gaItem),
              cantidad: Number(datos.cantidad) || 1
            }
          }));
        }
      } catch (e) { /* la medición no manda sobre el carrito */ }
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
      espejarFavoritos(r.ids);
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
    // El evento de scroll se dispara decenas de veces por segundo. Tocar la
    // clase en cada uno obliga al navegador a recalcular estilos todo el rato;
    // en un teléfono modesto eso se nota. Aquí se agrupa el trabajo en el
    // siguiente fotograma y solo se escribe cuando el estado cambia de verdad.
    // La primera lectura se aplaza al siguiente fotograma. Leer `scrollY`
    // aquí mismo, mientras el navegador todavía está montando la página,
    // le obliga a calcular la geometría antes de tiempo; PageSpeed lo medía
    // como 33 ms de reprocesamiento forzado. Esperando un fotograma se lee
    // cuando el cálculo ya está hecho y no cuesta nada.
    let fijada = false;
    let pedido = false;
    requestAnimationFrame(() => {
      fijada = window.scrollY > 40;
      navbar.classList.toggle('scrolled', fijada);
    });

    const revisar = () => {
      pedido = false;
      const ahora = window.scrollY > 40;
      if (ahora !== fijada) {
        fijada = ahora;
        navbar.classList.toggle('scrolled', ahora);
      }
    };
    window.addEventListener('scroll', () => {
      if (!pedido) {
        pedido = true;
        requestAnimationFrame(revisar);
      }
    }, { passive: true });
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
  // Carrusel de fotos de clientes
  //
  // El desplazamiento y el gesto táctil los hace el navegador con
  // `scroll-snap`; aquí solo se leen las flechas y los puntos para que
  // reflejen dónde está el carrusel. Por eso no hay nada que recalcular
  // por fotograma ni ninguna librería detrás.
  // -------------------------------------------------------------------
  $$('[data-carrusel-fotos]').forEach((carrusel) => {
    const pista  = $('.cf-pista', carrusel);
    const fotos  = $$('.cf-item', carrusel);
    const ant    = $('.cf-flecha.anterior', carrusel);
    const sig    = $('.cf-flecha.siguiente', carrusel);
    const puntos = $$('.cf-punto', carrusel);
    if (!pista || fotos.length === 0) { return; }

    // Posición de una foto dentro de la pista. Se mide con el rectángulo y no
    // con offsetLeft porque el ascendiente posicionado es el carrusel, no la
    // pista, y offsetLeft daría otra referencia.
    const posicion = (foto) =>
      foto.getBoundingClientRect().left - pista.getBoundingClientRect().left + pista.scrollLeft;

    // Un punto por parada real del carrusel, no por foto.
    //
    // La diferencia importa cuando se ven varias a la vez: con cinco fotos y
    // cuatro en pantalla solo quedan dos paradas, así que cinco puntos serían
    // tres que no llevan a ninguna parte. Las paradas se sacan de dónde puede
    // quedar cada foto sin pasarse del final, y las que caen casi encima se
    // funden en una. En el móvil, con una foto por pantalla, sale justo un
    // punto por foto; y cuando se suban más, el número se recalcula solo.
    function topes() {
      const max = Math.max(0, pista.scrollWidth - pista.clientWidth);
      const lista = [];
      fotos.forEach((foto) => {
        const x = Math.min(posicion(foto), max);
        if (!lista.length || x - lista[lista.length - 1] > 8) { lista.push(x); }
      });
      return lista.length ? lista : [0];
    }

    const paradaActual = (lista) => {
      let cual = 0, menor = Infinity;
      lista.forEach((x, i) => {
        const d = Math.abs(x - pista.scrollLeft);
        if (d < menor) { menor = d; cual = i; }
      });
      return cual;
    };

    // `forzada` marca una parada antes de llegar a ella. Se usa al pulsar: el
    // usuario ya eligió el destino, así que el punto no tiene por qué esperar
    // a que termine el viaje para reflejarlo.
    function pintar(forzada) {
      const lista  = topes();
      const actual = forzada === undefined ? paradaActual(lista) : forzada;
      puntos.forEach((punto, n) => {
        punto.hidden = n >= lista.length;
        punto.setAttribute('aria-current', n === actual ? 'true' : 'false');
        punto.setAttribute('aria-label', 'Ver la posición ' + (n + 1) + ' de ' + lista.length);
      });
      // El final se compara con holgura: el desplazamiento da decimales y sin
      // margen la flecha de avanzar se quedaba activa al llegar.
      if (ant) { ant.disabled = pista.scrollLeft <= 2; }
      if (sig) { sig.disabled = pista.scrollLeft >= pista.scrollWidth - pista.clientWidth - 2; }
    }

    // Mientras dura un viaje pedido con el dedo o el ratón, el punto ya marca
    // el destino; leer la pista a mitad de camino solo haría parpadear el
    // indicador por las paradas intermedias.
    let viajandoA = null;

    function irA(n) {
      const lista = topes();
      const i = Math.max(0, Math.min(lista.length - 1, n));
      viajandoA = i;
      pintar(i);
      pista.scrollTo({ left: lista[i], behavior: menosMovimiento ? 'auto' : 'smooth' });
    }

    ant && ant.addEventListener('click', () => irA(paradaActual(topes()) - 1));
    sig && sig.addEventListener('click', () => irA(paradaActual(topes()) + 1));
    puntos.forEach((punto, n) => punto.addEventListener('click', () => irA(n)));

    // Si el visitante toca la pista, manda su dedo: se abandona el destino.
    pista.addEventListener('pointerdown', () => { viajandoA = null; }, { passive: true });

    // Repintado por fotograma, no al soltar. Con un temporizador el indicador
    // llegaba unos 400 ms después de la foto y se notaba como un retraso; así
    // acompaña al arrastre mientras ocurre.
    let pendiente = false;
    pista.addEventListener('scroll', () => {
      if (pendiente) { return; }
      pendiente = true;
      requestAnimationFrame(() => {
        pendiente = false;
        if (viajandoA !== null) {
          const lista = topes();
          if (Math.abs(pista.scrollLeft - lista[viajandoA]) > 2) { return; }
          viajandoA = null;
        }
        pintar();
      });
    }, { passive: true });

    window.addEventListener('resize', () => pintar());

    pintar();
  });

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
        : '<img alt="">';

      previa.innerHTML = '<div class="vista-previa-caja">' + visual +
        '<div class="vista-previa-datos"><strong></strong><small>' + kb + '</small></div>' +
        '<button type="button" class="btn-quitar" id="quitarArchivo">' +
        '<i class="fa-solid fa-xmark"></i> Quitar</button></div>';
      previa.querySelector('strong').textContent = archivo.name;
      previa.classList.add('visible');

      const img = previa.querySelector('img');
      if (img) {
        const url = URL.createObjectURL(archivo);
        img.addEventListener('load', () => URL.revokeObjectURL(url), { once: true });
        // Si el navegador no puede pintar la miniatura, se pone el icono del
        // archivo en su lugar: mejor eso que dejar una imagen rota, que hace
        // dudar al cliente de si el comprobante se subió bien.
        img.addEventListener('error', () => {
          URL.revokeObjectURL(url);
          const icono = document.createElement('div');
          icono.className = 'vista-previa-icono';
          icono.innerHTML = '<i class="fa-solid fa-file-image"></i>';
          img.replaceWith(icono);
        }, { once: true });
        img.src = url;
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
  // Cupón de descuento
  //
  // Se aplica sin recargar para no perder lo que el cliente ya escribió en el
  // formulario del pedido. El servidor decide si vale y cuánto rebaja; aquí
  // solo se pinta lo que responde. Sin JavaScript el campo viaja con el resto
  // del formulario y se valida al confirmar.
  // -------------------------------------------------------------------
  (function cuponDescuento() {
    const bloque = $('#bloqueCupon');
    if (!bloque) { return; }

    const campo  = $('#cupon');
    const boton  = $('#btnCupon');
    const aviso  = $('#avisoCupon');
    const linea  = $('#lineaDescuento');

    function decir(texto, clase) {
      aviso.className = 'cupon-aviso' + (clase ? ' ' + clase : '');
      aviso.innerHTML = '';
      const icono = document.createElement('i');
      icono.className = 'fa-solid ' + (clase === 'bien' ? 'fa-circle-check' : 'fa-circle-exclamation');
      icono.setAttribute('aria-hidden', 'true');
      const span = document.createElement('span');
      span.textContent = texto;
      aviso.append(icono, span);
      aviso.hidden = texto === '';
    }

    function pintar(r) {
      const aplicado = !!r.aplicado;
      bloque.dataset.aplicado = aplicado ? '1' : '0';
      campo.readOnly = aplicado;
      campo.value = aplicado ? r.codigo : '';
      boton.dataset.accion = aplicado ? 'quitar' : 'aplicar';
      boton.textContent = aplicado ? 'Quitar' : 'Aplicar';

      if (linea) {
        linea.hidden = !(r.descuento > 0);
        const imp = $('#descuentoImporte');
        const eti = $('#cuponEtiqueta');
        if (imp) { imp.textContent = '−' + r.texto.descuento; }
        if (eti) { eti.textContent = aplicado ? r.codigo : ''; }
      }
      const envio = $('#envioImporte');
      const total = $('#totalImporte');
      if (envio) { envio.textContent = r.texto.envio; envio.classList.toggle('gratis', r.envio === 0); }
      if (total) { total.textContent = r.texto.total; }
    }

    async function enviar(accion) {
      const codigo = (campo.value || '').trim();
      if (accion === 'aplicar' && codigo === '') {
        decir('Escribe el código del cupón.', 'mal');
        campo.focus();
        return;
      }

      boton.classList.add('btn-cargando');
      boton.disabled = true;
      try {
        // La zona y el tipo de entrega van en la petición: de ellos depende el
        // envío, y de él dependen el total y los cupones de envío gratis.
        const zona = $('#zona_envio_id');
        const tipo = $('input[name="entrega_tipo"]:checked');
        const correo = $('#cliente_email');
        const r = await pedir('cupon.php', {
          accion,
          cupon: codigo,
          zona_envio_id: zona ? zona.value : 0,
          entrega_tipo: tipo ? tipo.value : 'domicilio',
          // El correo identifica al cliente para el límite «un uso por
          // persona»: sin él, el cupón se aceptaría aquí y se rechazaría al
          // confirmar, que es la peor forma de enterarse.
          cliente_email: correo ? correo.value : '',
        });
        pintar(r);
        decir(r.mensaje || '', r.aplicado ? 'bien' : '');
        // El bloque de zonas mantiene su propio total; se le avisa para que lo
        // recalcule con el descuento nuevo.
        document.dispatchEvent(new CustomEvent('resumen:cambiado'));
        if (r.mensaje) { aviso.dispatchEvent(new CustomEvent('cupon:ok', { bubbles: true })); }
      } catch (e) {
        decir(e.message, 'mal');
      } finally {
        boton.classList.remove('btn-cargando');
        boton.disabled = false;
      }
    }

    boton.addEventListener('click', () => enviar(boton.dataset.accion || 'aplicar'));

    // Si el cliente escribe su correo después de aplicar el cupón, se vuelve a
    // comprobar: puede que ese correo ya lo haya usado.
    const correo = $('#cliente_email');
    if (correo) {
      correo.addEventListener('change', () => {
        if (bloque.dataset.aplicado === '1') { enviar('aplicar'); }
      });
    }

    // Enter dentro del campo aplica el cupón; no confirma el pedido, que es lo
    // que haría por defecto al estar dentro del formulario grande.
    campo.addEventListener('keydown', (ev) => {
      if (ev.key === 'Enter') {
        ev.preventDefault();
        enviar(boton.dataset.accion || 'aplicar');
      }
    });
  })();

  // -------------------------------------------------------------------
  // Mensajes de validación del navegador, en español
  //
  // Sin esto Chrome escribe «Please select a file.» o «Please fill out this
  // field.» según el idioma del navegador, no el de la página: un cliente
  // nicaragüense con Chrome en inglés ve la web en español y el aviso en
  // inglés. El texto se limpia al escribir para que el navegador vuelva a
  // validar de verdad y no se quede pegado un mensaje viejo.
  // -------------------------------------------------------------------
  (function validacionEnEspanol() {
    const mensaje = (campo) => {
      const v = campo.validity;
      if (v.valueMissing) {
        if (campo.type === 'file')     { return 'Elige un archivo.'; }
        if (campo.type === 'checkbox') { return 'Marca esta casilla para continuar.'; }
        if (campo.tagName === 'SELECT') { return 'Elige una opción.'; }
        return 'Completa este campo.';
      }
      if (v.typeMismatch) {
        return campo.type === 'email'
          ? 'Escribe un correo válido, con @ y el dominio.'
          : 'El formato no es válido.';
      }
      if (v.tooShort)     { return 'Escribe al menos ' + campo.minLength + ' caracteres.'; }
      if (v.rangeUnderflow) { return 'El valor mínimo es ' + campo.min + '.'; }
      if (v.rangeOverflow)  { return 'El valor máximo es ' + campo.max + '.'; }
      if (v.patternMismatch) { return 'Ese formato no es el esperado.'; }
      return '';
    };

    document.addEventListener('invalid', (ev) => {
      const campo = ev.target;
      if (!campo.setCustomValidity) { return; }
      campo.setCustomValidity(mensaje(campo));
    }, true);

    document.addEventListener('input',  (ev) => {
      if (ev.target.setCustomValidity) { ev.target.setCustomValidity(''); }
    }, true);
    document.addEventListener('change', (ev) => {
      if (ev.target.setCustomValidity) { ev.target.setCustomValidity(''); }
    }, true);
  })();

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
  // -------------------------------------------------------------------
  // Consentimiento de cookies
  //
  // La decisión manda de verdad: sin permiso no se escribe la copia de
  // favoritos del navegador, y si ya existía se borra. La cookie de sesión no
  // entra aquí porque sin ella no hay carrito ni acceso.
  // -------------------------------------------------------------------
  const CLAVE_FAVS = 'flowersanto:favs';
  const aviso_cookies = $('#avisoCookies');

  function decisionCookies() {
    const c = document.cookie.match(/(?:^|;\s*)fa_cookies=([^;]+)/);
    return c ? decodeURIComponent(c[1]) : '';
  }
  /** Solo lo opcional necesita permiso; mientras no decida, no se guarda. */
  function puedeGuardarOpcional() {
    return decisionCookies() === 'aceptado';
  }
  function anotarDecision(valor) {
    const anio = 60 * 60 * 24 * 365;
    const seguro = location.protocol === 'https:' ? '; Secure' : '';
    document.cookie = 'fa_cookies=' + valor + '; Max-Age=' + anio
                    + '; Path=/; SameSite=Lax' + seguro;
    if (valor !== 'aceptado') {
      try { localStorage.removeItem(CLAVE_FAVS); } catch (e) { /* modo privado */ }
    }
    if (aviso_cookies) {
      aviso_cookies.dataset.decision = valor;
      aviso_cookies.hidden = true;
    }
  }

  if (aviso_cookies) {
    $$('[data-cookies]', aviso_cookies).forEach((boton) => {
      boton.addEventListener('click', () => {
        anotarDecision(boton.dataset.cookies);
        aviso(boton.dataset.cookies === 'aceptado'
          ? 'Listo, guardaremos tus favoritos en este navegador.'
          : 'De acuerdo, solo usaremos lo imprescindible.', 'exito');
      });
    });
  }
  // Los botones flotantes suben lo justo para no tapar el aviso, que cambia
  // de alto según el ancho de la pantalla (ver --alto-aviso en app.css).
  function medirAviso() {
    if (!aviso_cookies || aviso_cookies.hidden) { return; }
    const caja = aviso_cookies.firstElementChild || aviso_cookies;
    document.documentElement.style.setProperty('--alto-aviso', Math.ceil(caja.getBoundingClientRect().height) + 'px');
  }
  window.addEventListener('resize', medirAviso, { passive: true });

  // Primera visita: el aviso aparece cuando ya están las fuentes y el
  // navegador tiene un respiro, para que no se mueva al cambiar la letra.
  //
  // El orden importa. La hoja de Google Fonts se carga en diferido, y hasta
  // que llega el navegador no sabe que existe Poppins: `document.fonts.ready`
  // se cumplía en el acto, sin esperar a nada. Por eso primero se espera al
  // `load` de la página (que sí espera a esa hoja) y después a las fuentes.
  if (aviso_cookies && aviso_cookies.hasAttribute('data-pendiente') && !decisionCookies()) {
    const cargada = document.readyState === 'complete'
      ? Promise.resolve()
      : new Promise((listo) => window.addEventListener('load', listo, { once: true }));
    const fuentes = cargada.then(() => (document.fonts && document.fonts.ready) ? document.fonts.ready : null);
    const tope = new Promise((listo) => setTimeout(listo, 4000));
    Promise.race([fuentes, tope]).then(() => {
      const mostrar = () => { aviso_cookies.hidden = false; medirAviso(); };
      'requestIdleCallback' in window ? requestIdleCallback(mostrar, { timeout: 1000 }) : setTimeout(mostrar, 200);
    });
  }

  // -------------------------------------------------------------------
  // Botones flotantes recogidos como agua (catálogo, compra y cuenta)
  // -------------------------------------------------------------------
  // Los botones de WhatsApp y Massiel se recogen en la pestaña del borde
  // derecho convertidos en dos gotas que se funden mientras se esconden, y
  // salen de ella dividiéndose en dos. El líquido se dibuja en una capa SVG
  // con el filtro clásico de «metaballs»: un desenfoque y un umbral de
  // opacidad hacen que dos formas cercanas se unan con un cuello, como el
  // agua. La capa solo existe mientras dura la transición y ocupa lo justo
  // alrededor de los botones: en reposo no cuesta nada.
  const pestana = $('#pestanaFlotantes');
  const wa = $('.whatsapp-float');
  if (pestana && wa && document.body.classList.contains('flotantes-recogidos')) {
    const cuerpo = document.body;
    const circuloAsesora = $('.asesora-flotante .asesora-flotante-circulo');
    const estrecha = window.matchMedia('(max-width: 900px)');
    const quieto = window.matchMedia('(prefers-reduced-motion: reduce)');
    const etiquetaBase = pestana.getAttribute('aria-label').replace(/^Mostrar /, '');
    let abiertos = false;
    let animando = false;
    let tocado = false;
    cuerpo.classList.add('flot-listo');

    // --- Capa del líquido --------------------------------------------
    const NS = 'http://www.w3.org/2000/svg';
    const nodo = (tag, atributos, padre) => {
      const n = document.createElementNS(NS, tag);
      Object.entries(atributos).forEach(([k, v]) => n.setAttribute(k, v));
      if (padre) { padre.appendChild(n); }
      return n;
    };
    let capa = null;
    let filtro = null;
    let color = null;
    let ancla = null;
    const gotas = {};

    const montar = () => {
      capa = nodo('svg', { class: 'liquido-flotantes', 'aria-hidden': 'true', focusable: 'false' });
      capa.hidden = true;
      const defs = nodo('defs', {}, capa);
      filtro = nodo('filter', { id: 'liquidoFlotantes', filterUnits: 'userSpaceOnUse', 'color-interpolation-filters': 'sRGB' }, defs);
      nodo('feGaussianBlur', { in: 'SourceGraphic', stdDeviation: '7', result: 'difuso' }, filtro);
      nodo('feColorMatrix', { in: 'difuso', mode: 'matrix', values: '1 0 0 0 0  0 1 0 0 0  0 0 1 0 0  0 0 0 19 -8', result: 'gota' }, filtro);
      nodo('feDropShadow', { in: 'gota', dx: '0', dy: '5', stdDeviation: '5', 'flood-color': '#3B2B2F', 'flood-opacity': '.26' }, filtro);
      // Un solo degradado vertical, rosa a la altura de Massiel y verde a la
      // de WhatsApp: al fundirse, el rosa se diluye en el verde sin pasar por
      // un color turbio.
      color = nodo('linearGradient', { id: 'liquidoColor', gradientUnits: 'userSpaceOnUse', x1: '0', x2: '0' }, defs);
      nodo('stop', { offset: '0', 'stop-color': '#CF5B82' }, color);
      nodo('stop', { offset: '1', 'stop-color': '#25D366' }, color);

      const masa = nodo('g', { filter: 'url(#liquidoFlotantes)', fill: 'url(#liquidoColor)' }, capa);
      const brillos = nodo('g', { fill: '#fff' }, capa);
      const iconos = nodo('g', {}, capa);
      const gota = (icono, lado, trazo) => {
        const g = nodo('g', trazo
          ? { fill: 'none', stroke: '#fff', 'stroke-width': '2', 'stroke-linecap': 'round', 'stroke-linejoin': 'round' }
          : { style: 'color:#fff' }, iconos);
        nodo('use', { href: icono }, g);
        return { cuerpo: nodo('ellipse', {}, masa), brillo: nodo('ellipse', {}, brillos), icono: g, lado, x: 0, y: 0 };
      };
      gotas.wa = gota('#icono-wa', 32, false);
      if (circuloAsesora) { gotas.asesora = gota('#icono-massiel', 24, true); }
      ancla = nodo('circle', { r: '0' }, masa);
      cuerpo.appendChild(capa);
    };

    // --- Geometría -------------------------------------------------------
    const centro = (el) => {
      const r = el.getBoundingClientRect();
      return { x: r.left + r.width / 2, y: r.top + r.height / 2 };
    };
    const medir = () => {
      const t = pestana.getBoundingClientRect();
      return {
        w: centro(wa),
        m: circuloAsesora ? centro(circuloAsesora) : null,
        // Justo dentro del borde izquierdo de la pestaña, ya en su sitio
        // (la primera vez todavía está entrando desde fuera).
        d: { x: document.documentElement.clientWidth - pestana.offsetWidth + 9, y: t.top + t.height / 2 },
      };
    };

    const lim = (v) => Math.min(1, Math.max(0, v));
    const tramo = (p, a, b) => lim((p - a) / (b - a));
    const salir = (t) => 1 - Math.pow(1 - t, 3);
    const suave = (t) => (t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2);
    const ola = (t) => (1 - Math.cos(Math.PI * t)) / 2;

    // Dibuja una gota: se estira en la dirección en que se mueve, tanto más
    // cuanto más rápido va, como una gota de agua al deslizarse.
    const dibujar = (g, x, y, r, iconoVisible, dt) => {
      const dx = x - g.x;
      const dy = y - g.y;
      const v = dt > 0 ? Math.hypot(dx, dy) / dt : 0;
      const s = Math.min(0.42, v * 0.75);
      const giro = (Math.atan2(dy, dx) * 180) / Math.PI;
      g.x = x; g.y = y;
      const rr = Math.max(0, r);
      g.cuerpo.setAttribute('cx', x.toFixed(1));
      g.cuerpo.setAttribute('cy', y.toFixed(1));
      g.cuerpo.setAttribute('rx', (rr * (1 + s)).toFixed(2));
      g.cuerpo.setAttribute('ry', (rr * (1 - s * 0.45)).toFixed(2));
      g.cuerpo.setAttribute('transform', `rotate(${giro.toFixed(1)} ${x.toFixed(1)} ${y.toFixed(1)})`);
      // Brillo de la superficie, arriba a la izquierda.
      g.brillo.setAttribute('cx', (x - rr * 0.32).toFixed(1));
      g.brillo.setAttribute('cy', (y - rr * 0.4).toFixed(1));
      g.brillo.setAttribute('rx', (rr * 0.32).toFixed(2));
      g.brillo.setAttribute('ry', (rr * 0.17).toFixed(2));
      g.brillo.setAttribute('opacity', (0.42 * lim(rr / 30)).toFixed(3));
      // El icono se disuelve primero: lo que viaja ya es agua.
      const k = lim(rr / 30) * (g.lado === 32 ? 30 / 32 : 28 / 24);
      g.icono.setAttribute('transform', `translate(${x.toFixed(1)} ${y.toFixed(1)}) scale(${k.toFixed(3)}) translate(${-g.lado / 2} ${-g.lado / 2})`);
      g.icono.setAttribute('opacity', iconoVisible.toFixed(3));
    };

    // Un fotograma del recorrido, de p = 0 (botones) a p = 1 (todo dentro de
    // la pestaña). Abrir es el mismo recorrido hacia atrás.
    const fotograma = (p, geo, dt) => {
      // Avanzar y esconderse van juntos: cada gota se encoge a la par que se
      // acerca a la pestaña y llega a ella justo cuando se acaba. La curva
      // es simétrica (acelera y frena), así que al abrir —el mismo recorrido
      // hacia atrás— también se posan suavemente en su sitio.
      const avance = ola(tramo(p, 0.04, 0.9));
      const wx = geo.w.x + (geo.d.x - geo.w.x) * avance;
      const wy = geo.w.y + (geo.d.y - geo.w.y) * avance;
      dibujar(gotas.wa, wx, wy, 30 * Math.sqrt(1 - avance), 1 - tramo(p, 0, 0.3), dt);

      if (gotas.asesora && geo.m) {
        // Massiel cae sobre WhatsApp mientras los dos corren hacia la
        // pestaña: se funden por el camino, sin detenerse.
        const avanceM = ola(tramo(p, 0.04, 0.86));
        const mx = geo.m.x + (geo.d.x - geo.m.x) * avanceM;
        const my = geo.m.y + (wy - geo.m.y) * suave(tramo(p, 0, 0.55));
        const rm = 30 * (1 - 0.25 * salir(tramo(p, 0, 0.45))) * Math.sqrt(1 - avanceM);
        dibujar(gotas.asesora, mx, my, rm, 1 - tramo(p, 0, 0.26), dt);
      }

      // La pestaña asoma un poco de líquido para recibir la gota.
      ancla.setAttribute('cx', (geo.d.x + 3).toFixed(1));
      ancla.setAttribute('cy', geo.d.y.toFixed(1));
      ancla.setAttribute('r', (12 * Math.sin(Math.PI * tramo(p, 0.3, 1))).toFixed(2));
    };

    const absorber = () => {
      cuerpo.classList.remove('flot-absorbe');
      void pestana.offsetWidth; // reinicia la animación si se repite seguido
      cuerpo.classList.add('flot-absorbe');
      setTimeout(() => cuerpo.classList.remove('flot-absorbe'), 750);
    };

    const recorrer = (cerrar, geo, duracion, alTerminar) => {
      if (!capa) { montar(); }
      const ys = [geo.w.y, geo.d.y].concat(geo.m ? [geo.m.y] : []);
      const x0 = Math.min(geo.w.x, geo.m ? geo.m.x : geo.w.x) - 90;
      const x1 = document.documentElement.clientWidth + 12;
      const y0 = Math.min(...ys) - 80;
      const y1 = Math.max(...ys) + 80;
      const caja = [x0, y0, x1 - x0, y1 - y0].map((n) => n.toFixed(0));
      Object.assign(capa.style, { left: caja[0] + 'px', top: caja[1] + 'px', width: caja[2] + 'px', height: caja[3] + 'px' });
      capa.setAttribute('viewBox', caja.join(' '));
      ['x', 'y', 'width', 'height'].forEach((a, i) => filtro.setAttribute(a, caja[i]));
      color.setAttribute('y1', (geo.m ? geo.m.y : geo.w.y - 1).toFixed(1));
      color.setAttribute('y2', geo.w.y.toFixed(1));
      color.firstChild.setAttribute('stop-color', geo.m ? '#CF5B82' : '#25D366');

      // Primer fotograma en el mismo instante en que se ocultan los botones
      // reales: el cambio de botón a gota no se nota.
      Object.values(gotas).forEach((g) => { g.x = cerrar ? geo.w.x : geo.d.x; g.y = cerrar ? geo.w.y : geo.d.y; });
      if (gotas.asesora && geo.m && cerrar) { gotas.asesora.x = geo.m.x; gotas.asesora.y = geo.m.y; }
      fotograma(cerrar ? 0 : 0.82, geo, 0);
      capa.hidden = false;
      if (!cerrar) { absorber(); }

      let previo = performance.now();
      const inicio = previo;
      let absorbido = false;
      const paso = (ahora) => {
        const t = lim((ahora - inicio) / duracion);
        // Al abrir se empieza donde el líquido ya asoma de la pestaña: el
        // tramo final del cierre son gotas demasiado pequeñas para verse.
        fotograma(cerrar ? t : 0.82 * (1 - t), geo, ahora - previo);
        previo = ahora;
        if (cerrar && !absorbido && t >= 0.78) {
          // El líquido ya entró: la pestaña lo recibe con una onda y enseña
          // sus iconos mientras se apagan las últimas gotas.
          absorbido = true;
          absorber();
          alTerminar();
          cuerpo.classList.remove('flot-animando');
        }
        if (t < 1) { requestAnimationFrame(paso); return; }
        capa.hidden = true;
        animando = false;
        if (!cerrar) { cuerpo.classList.remove('flot-animando'); alTerminar(); }
      };
      requestAnimationFrame(paso);
    };

    // --- Estados ----------------------------------------------------------
    const aria = () => {
      pestana.setAttribute('aria-expanded', String(abiertos));
      pestana.setAttribute('aria-label', (abiertos ? 'Ocultar ' : 'Mostrar ') + etiquetaBase);
    };
    const conAgua = () => estrecha.matches && !quieto.matches && !document.hidden;

    const guardar = (primera) => {
      if (animando || (!abiertos && !primera)) { return; }
      const geo = conAgua() ? medir() : null;   // se mide antes de mover nada
      abiertos = false;
      aria();
      cuerpo.classList.add('flot-pestana');
      cuerpo.classList.remove('flot-abiertos');
      if (!geo) { cuerpo.classList.add('flot-guardados'); return; }
      animando = true;
      cuerpo.classList.add('flot-animando');
      recorrer(true, geo, primera ? 950 : 820, () => cuerpo.classList.add('flot-guardados'));
    };

    const abrir = () => {
      if (animando || abiertos) { return; }
      abiertos = true;
      aria();
      cuerpo.classList.add('flot-abiertos');
      if (!conAgua()) { cuerpo.classList.remove('flot-guardados'); return; }
      animando = true;
      cuerpo.classList.add('flot-animando');
      cuerpo.classList.remove('flot-guardados');
      recorrer(false, medir(), 760, () => {});   // se mide ya en su sitio de abiertos
    };

    pestana.addEventListener('click', () => { tocado = true; abiertos ? guardar(false) : abrir(); });
    // Al abrir Massiel desde su botón se guardan al instante: su panel ocupa
    // la pantalla y al cerrarlo la página queda despejada.
    $$('[data-abrir-asesora]').forEach((d) => d.addEventListener('click', () => {
      if (!abiertos || animando) { return; }
      abiertos = false;
      aria();
      cuerpo.classList.remove('flot-abiertos');
      cuerpo.classList.add('flot-guardados');
    }));
    // Un toque fuera de la pestaña y de los botones los vuelve a guardar.
    document.addEventListener('click', (e) => {
      if (abiertos && !e.target.closest('#pestanaFlotantes, .whatsapp-float, .asesora-flotante')) { guardar(false); }
    });
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && abiertos) { guardar(false); pestana.focus(); }
    });

    // Se recogen solos al poco de llegar, cuando ya se vio que están ahí.
    const recogerSolos = () => {
      if (tocado || abiertos || cuerpo.classList.contains('flot-guardados') || !estrecha.matches) { return; }
      if (document.hidden) { document.addEventListener('visibilitychange', recogerSolos, { once: true }); return; }
      if (cuerpo.classList.contains('asesora-abierta')) { setTimeout(recogerSolos, 1500); return; }
      guardar(true);
    };
    setTimeout(recogerSolos, 1300);
    estrecha.addEventListener('change', () => { if (estrecha.matches) { setTimeout(recogerSolos, 300); } });
  }

  // Enlace del pie para cambiar de opinión más tarde.
  $$('[data-abrir-cookies]').forEach((enlace) => {
    enlace.addEventListener('click', (ev) => {
      ev.preventDefault();
      if (!aviso_cookies) { return; }
      aviso_cookies.hidden = false;
      medirAviso();
      const primero = $('[data-cookies]', aviso_cookies);
      primero && primero.focus();
    });
  });

  // localStorage es una copia de seguridad por si caduca la sesión de PHP,
  // no una segunda fuente de verdad. Por eso se reescribe entera con lo que
  // diga el servidor: antes sólo se le añadían ids y nunca se le quitaban,
  // así que lo que el visitante borraba volvía en la siguiente página.
  function espejarFavoritos(ids) {
    if (!Array.isArray(ids) || !puedeGuardarOpcional()) { return; }
    try {
      localStorage.setItem(CLAVE_FAVS, JSON.stringify(ids.map(Number).filter(esId)));
    } catch (e) { /* almacenamiento lleno o bloqueado */ }
  }

  // Una cadena vacía se parte en [''] y Number('') es 0: hay que exigir id real.
  const esId = (n) => Number.isInteger(n) && n > 0;

  function listaDelCuerpo() {
    return (document.body.dataset.favoritos || '').split(',').map(Number).filter(esId);
  }

  function sincronizarFavoritosLocales() {
    const cuerpo = document.body;
    if (cuerpo.dataset.autenticado === '1') {
      // Con cuenta abierta manda la base de datos; la copia local sobra.
      try { localStorage.removeItem(CLAVE_FAVS); } catch (e) { /* modo privado */ }
      return;
    }
    if (cuerpo.dataset.favsSembrados === '1') {
      espejarFavoritos(listaDelCuerpo());
      return;
    }
    let guardados = [];
    if (puedeGuardarOpcional()) {
      try { guardados = JSON.parse(localStorage.getItem(CLAVE_FAVS) || '[]'); } catch (e) { guardados = []; }
    }
    guardados = guardados.map(Number).filter(esId);
    if (!guardados.length) {
      espejarFavoritos(listaDelCuerpo());
      return;
    }
    // Sesión nueva con copia en el navegador: se restaura una sola vez.
    pedir('favoritos.php', { accion: 'fusionar', ids: guardados.join(',') })
      .then((r) => {
        cuerpo.dataset.favsSembrados = '1';
        pintarContador('favCount', r.total);
        espejarFavoritos(r.ids);
        // La lista de favoritos se pinta en el servidor, así que si acabamos
        // de restaurarla hay que volver a pedirla para que se vea. En la
        // siguiente carga la sesión ya está sembrada y esto no se repite.
        if (r.total && cuerpo.classList.contains('pagina-favoritos') && !$('[data-favorito]')) {
          window.location.reload();
        }
      })
      .catch(() => { /* si falla, se reintenta en la siguiente página */ });
  }
  sincronizarFavoritosLocales();

  // -------------------------------------------------------------------
  // Checkout: el envío cambia según la zona, y las direcciones guardadas
  // rellenan el formulario de una vez.
  //
  // Todo esto es presentación: el servidor vuelve a leer el precio de la
  // zona en la base antes de registrar el pedido, así que nada de lo que
  // se toque aquí puede abaratar un envío.
  // -------------------------------------------------------------------
  (function checkoutEnvio() {
    const resumen = $('[data-resumen]');
    const selZona = $('#zona_envio_id');
    if (!resumen) { return; }

    const moneda   = resumen.dataset.moneda || 'C$';
    const subtotal = parseFloat(resumen.dataset.subtotal || '0') || 0;
    const umbral   = parseFloat(resumen.dataset.umbral || '0') || 0;
    const elZona   = $('#envioZona');
    const elEnvio  = $('#envioImporte');
    const elTotal  = $('#totalImporte');
    const elAyuda  = $('#ayudaZona');

    function importe(valor) {
      return moneda + valor.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    function tipoEntrega() {
      const marcado = $('input[name="entrega_tipo"]:checked') || $('input[name="entrega_tipo"]');
      return marcado ? marcado.value : 'domicilio';
    }

    function repintar() {
      const retiro = tipoEntrega() === 'retiro';
      const opcion = selZona ? selZona.options[selZona.selectedIndex] : null;

      let envio  = 0;
      let nombre = '';
      if (!retiro && opcion) {
        envio  = parseFloat(opcion.dataset.costo || '0') || 0;
        // El nombre viene en su propio atributo: varias zonas lo llevan con
        // guion largo dentro («Managua — zona sur») y partir el texto lo cortaba.
        nombre = opcion.dataset.nombre || opcion.textContent.trim();
      }

      // El envío gratis por monto lo decide el mismo umbral que usa el servidor.
      const gratisPorMonto = !retiro && umbral > 0 && subtotal >= umbral;
      if (gratisPorMonto) { envio = 0; }

      if (elZona) {
        elZona.textContent = retiro ? 'retiro en tienda'
                           : (gratisPorMonto && nombre ? nombre + ' · gratis por tu compra' : nombre);
      }
      if (elEnvio) {
        elEnvio.textContent = envio > 0 ? importe(envio) : 'Gratis';
        elEnvio.classList.toggle('gratis', envio === 0);
      }
      // El descuento del cupón se lee de la línea que ya está pintada: al
      // cambiar de zona solo cambia el envío, y el total tiene que seguir
      // llevando la rebaja.
      let descuento = 0;
      const lineaDesc = $('#lineaDescuento');
      if (lineaDesc && !lineaDesc.hidden) {
        const t = ($('#descuentoImporte') || {}).textContent || '';
        descuento = parseFloat(t.replace(/[^\d.]/g, '')) || 0;
      }

      if (elTotal) { elTotal.textContent = importe(Math.max(0, subtotal - descuento + envio)); }
      if (elAyuda && opcion && opcion.dataset.ayuda !== undefined) {
        elAyuda.textContent = opcion.dataset.ayuda || 'El costo del envío depende de la zona.';
      }
    }

    if (selZona) { selZona.addEventListener('change', repintar); }
    $$('input[name="entrega_tipo"]').forEach((r) => r.addEventListener('change', repintar));
    // Al aplicar o quitar un cupón el total cambia, y este bloque es quien lo
    // recompone cuando además se toca la zona.
    document.addEventListener('resumen:cambiado', repintar);
    repintar();

    // Direcciones guardadas: un clic rellena dirección, referencia, zona y mapa.
    $$('[data-direccion]').forEach((chip) => {
      chip.addEventListener('click', () => {
        let datos;
        try { datos = JSON.parse(chip.dataset.direccion); } catch (e) { return; }

        const domicilio = $('input[name="entrega_tipo"][value="domicilio"]');
        if (domicilio && !domicilio.checked) {
          domicilio.checked = true;
          const bloque = $('#bloqueDomicilio');
          if (bloque) { bloque.hidden = false; }
        }

        const poner = (id, valor) => {
          const campo = $('#' + id);
          if (campo && valor) { campo.value = valor; }
        };
        poner('entrega_direccion', datos.direccion);
        poner('entrega_referencia', datos.referencia);
        poner('entrega_mapa_url', datos.mapa);
        poner('entrega_nombre', datos.nombre);
        poner('entrega_telefono', datos.telefono);

        if (selZona && datos.zona) {
          const opcion = selZona.querySelector('option[value="' + String(parseInt(datos.zona, 10)) + '"]');
          if (opcion) { selZona.value = opcion.value; }
        }

        $$('[data-direccion]').forEach((c) => c.classList.toggle('activo', c === chip));
        repintar();
        aviso('Dirección cargada. Revísala antes de confirmar.');
      });
    });
  })();

  // -------------------------------------------------------------------
  // Capa de espera
  // -------------------------------------------------------------------
  /**
   * Tapa la página mientras algo tarda, y la destapa al terminar.
   *
   * Sale de un caso muy concreto: entre que PayPal aprueba el cobro y la web
   * abre el pedido pasan varios segundos —se captura el dinero, se registra
   * el pedido, se manda el correo—. Sin nada en pantalla el cliente cree que
   * se colgó, y entonces vuelve a pulsar o cierra la pestaña con el cobro ya
   * hecho. La capa cubre la página entera, así que además del aviso resuelve
   * el doble clic: no hay nada que pulsar por detrás.
   *
   * Tres seguros para que no se quede pegada nunca:
   *   · un máximo de tiempo en pantalla, para una acción que no vuelve;
   *   · se retira si el navegador devuelve la página desde su memoria, que
   *     es lo que pasa al volver con «atrás» después de un envío;
   *   · con `demora` solo aparece si la acción tarda de verdad, de modo que
   *     lo que responde al instante no da un parpadeo.
   *
   * Se publica como `window.esperaFlowers` para poder usarla desde cualquier
   * otro script de la tienda: `esperaFlowers.mostrar('Guardando…')`.
   */
  const espera = (function () {
    const capa  = $('#capaEspera');
    const texto = $('#capaEsperaTexto');
    // Sin el marcado del pie no hay nada que enseñar, pero quien llame tiene
    // que poder hacerlo sin comprobar nada.
    if (!capa) { return { mostrar() {}, ocultar() {} }; }

    const MAXIMO   = 30000;
    const ORIGINAL = texto ? texto.textContent : 'Un momento…';
    let alta = null, maximo = null, baja = null;

    function pintar(mensaje) {
      if (texto) { texto.textContent = mensaje || ORIGINAL; }
      clearTimeout(baja);
      capa.hidden = false;
      // Un fotograma con el `hidden` ya quitado: sin esta espera el navegador
      // se salta la transición y la capa entra de golpe.
      requestAnimationFrame(() => capa.classList.add('visible'));

      // Un botón que siguiera enfocado se puede volver a activar con Enter
      // por detrás de la capa, que es justo lo que se quiere evitar.
      const foco = document.activeElement;
      if (foco && foco !== document.body && typeof foco.blur === 'function') {
        try { foco.blur(); } catch (e) { /* da igual */ }
      }
      maximo = setTimeout(ocultar, MAXIMO);
    }

    function mostrar(mensaje, opciones) {
      const demora = (opciones && opciones.demora) || 0;
      clearTimeout(alta);
      clearTimeout(maximo);
      if (demora > 0) {
        alta = setTimeout(() => pintar(mensaje), demora);
        return;
      }
      pintar(mensaje);
    }

    function ocultar() {
      clearTimeout(alta);
      clearTimeout(maximo);
      capa.classList.remove('visible');
      // El `hidden` vuelve cuando acaba el fundido, no antes: si no, la capa
      // desaparecería sin transición.
      baja = setTimeout(() => {
        if (!capa.classList.contains('visible')) { capa.hidden = true; }
      }, menosMovimiento ? 0 : 280);
    }

    window.addEventListener('pageshow', (ev) => { if (ev.persisted) { ocultar(); } });

    return { mostrar, ocultar };
  })();
  window.esperaFlowers = espera;

  // -------------------------------------------------------------------
  // Espera en los formularios que no admiten un segundo envío
  // -------------------------------------------------------------------
  // Este bloque va al final del archivo a propósito. Los manejadores de
  // arriba son los que cancelan envíos —el carrito los manda por detrás,
  // `data-confirmar` puede echarse atrás—, y al registrarse después de ellos
  // aquí ya se sabe si el envío sigue en pie. Tapar la página en un envío
  // cancelado la dejaría bloqueada sin que llegue ninguna página nueva.
  //
  // El mensaje se puede afinar por formulario con `data-espera="…"`.
  //
  // Aquí también se bloquea el botón contra el doble envío: antes se hacía
  // en cuanto se pulsaba, y si luego se cancelaba la confirmación («¿Quitar
  // tu foto?» → Cancelar) el botón se quedaba desactivado e invisible hasta
  // recargar la página.
  document.addEventListener('submit', (ev) => {
    const form = ev.target;
    if (ev.defaultPrevented || !form || !form.matches || !form.matches('form[data-una-vez]')) { return; }
    // Un envío que abre otra pestaña o baja un archivo no recarga esta
    // página: no llegaría nada que retirase la capa ni reactivase el botón.
    if (form.target && form.target !== '_self') { return; }
    const boton = form.querySelector('button[type="submit"]');
    if (boton) {
      boton.classList.add('btn-cargando');
      // Se desactiva después del envío para no anular el name del botón.
      setTimeout(() => { boton.disabled = true; }, 0);
    }
    espera.mostrar(form.dataset.espera, { demora: 220 });
  });
  // Al volver con «Atrás» la página sale de la memoria del navegador tal como
  // se dejó: los botones vuelven a estar disponibles.
  window.addEventListener('pageshow', (ev) => {
    if (!ev.persisted) { return; }
    $$('form[data-una-vez] button.btn-cargando').forEach((b) => { b.classList.remove('btn-cargando'); b.disabled = false; });
  });
})();
