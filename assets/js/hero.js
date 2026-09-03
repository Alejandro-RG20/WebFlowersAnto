/* =====================================================================
   Hero editorial — Flowers Anto
   Carrusel por roles (centro / izq / der / fondo). Sin dependencias.
   Los datos vienen ya resueltos por el servidor en <script id="datosHero">:
   los productos, sus colores y la palabra de fondo salen del panel, no del
   código, y la portada no depende de una petición extra para pintarse.
   ===================================================================== */
(() => {
'use strict';

const DURACION   = 650;   // debe coincidir con la transición de css/hero.css
const AUTOPLAY   = 6000;
const MENOS_MOVIMIENTO = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

const $ = (sel, ctx = document) => ctx.querySelector(sel);

const esc = t => String(t ?? '').replace(/[&<>"']/g,
  c => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]));

const seccion = $('#heroEditorial');
if (!seccion) return;

const escenario = $('#heroEscenario');
const palabra   = $('#heroPalabra');
const panel     = $('#heroPanel');
const puntos    = $('#heroPuntos');
const btnPrev   = $('#heroPrev');
const btnSig    = $('#heroSig');
const llamada   = $('#heroLlamada');

let piezas   = [];
let activo   = 0;
let animando = false;
let reloj    = null;
let config   = {};

/* ------------------------------------------------------------- utilidades */
function dineroLocal(valor) {
  const n = Number(valor) || 0;
  return (config.moneda_local || 'C$') + n.toLocaleString('es-NI', { maximumFractionDigits: 0 });
}

function rolDe(indice) {
  const total = piezas.length;
  if (total === 0) return 'oculto';
  if (total === 1) return indice === activo ? 'centro' : 'oculto';

  const paso = (indice - activo + total) % total;
  if (paso === 0) return 'centro';
  if (paso === total - 1) return 'izq';
  if (paso === 1) return 'der';
  if (paso === 2 || total === 3) return 'fondo';
  return 'oculto';
}

/* ------------------------------------------------------------- pintado */
function pintarEscenario() {
  // El servidor ya dejó las piezas en el HTML para que la foto principal
  // empiece a bajar con la página. Volver a escribir innerHTML aquí tiraría
  // esas imágenes y las pediría otra vez, que es justo lo que se quiere
  // evitar: la primera vez solo se colocan los roles.
  const yaEstaban = escenario.dataset.servidor === '1'
                 && escenario.children.length === piezas.length;
  delete escenario.dataset.servidor;

  if (yaEstaban) {
    // Las piezas ya están: solo se colocan los roles.
    escenario.querySelectorAll('.hero-pieza').forEach((el, i) => {
      el.dataset.rol = rolDe(i);
    });
  } else {
    escenario.innerHTML = piezas.map((p, i) => `
      <div class="hero-pieza" data-rol="${rolDe(i)}" data-indice="${i}">
        ${p.enlace ? `<a href="${esc(p.enlace)}" tabindex="-1" aria-hidden="true">` : ''}
        <img src="${esc(p.imagen)}" alt="${esc(p.nombre)}" draggable="false" decoding="async"
             ${i === 0 ? 'fetchpriority="high"' : 'loading="lazy"'}>
        ${p.enlace ? '</a>' : ''}
      </div>`).join('');
  }

  puntos.innerHTML = piezas.map((p, i) => `
    <button type="button" data-indice="${i}" aria-pressed="${i === activo}"
            aria-label="Ver ${esc(p.nombre)}"></button>`).join('');
}

function actualizarRoles() {
  escenario.querySelectorAll('.hero-pieza').forEach(el => {
    el.dataset.rol = rolDe(+el.dataset.indice);
  });
  puntos.querySelectorAll('button').forEach((b, i) => {
    b.setAttribute('aria-pressed', String(i === activo));
  });
}

function pintarPanel() {
  const p = piezas[activo];
  if (!p) return;

  const usd = Number(config.mostrar_usd ?? 1) && Number(p.precio_usd) > 0
    ? `<small>o $${Number(p.precio_usd).toFixed(2)}</small>` : '';

  const titulo = p.enlace
    ? `<a href="${esc(p.enlace)}">${esc(p.nombre)}</a>`
    : esc(p.nombre);

  panel.querySelector('.contenido-pieza').innerHTML = `
    <span class="categoria cambia">${esc(p.categoria_nombre || '')}</span>
    <h1 class="cambia">${titulo}</h1>
    <p class="resumen cambia">${esc(p.descripcion)}</p>
    <span class="precio cambia">${p.precio > 0 ? dineroLocal(p.precio) + ' ' + usd : ''}</span>`;
}

/**
 * Tinta que se lee sobre ese fondo.
 *
 * El color del carrusel lo elige la floristería producto a producto y suele
 * ser un pastel claro. Con la letra fija en blanco, el nombre del arreglo —el
 * texto más grande de la portada— quedaba en 1.26:1: prácticamente invisible.
 * Se decide aquí con la luminancia relativa, la misma fórmula que usa el
 * servidor para el tema de temporada.
 */
function tintaSobre(hex) {
  const h = String(hex || '').replace('#', '');
  const v = h.length === 3
    ? [h[0] + h[0], h[1] + h[1], h[2] + h[2]]
    : [h.slice(0, 2), h.slice(2, 4), h.slice(4, 6)];
  const c = v.map(x => {
    const n = parseInt(x, 16) / 255;
    return Number.isNaN(n) ? 1 : (n <= 0.03928 ? n / 12.92 : Math.pow((n + 0.055) / 1.055, 2.4));
  });
  const luz = 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];
  return luz > 0.42 ? '#2C2124' : '#FFFFFF';
}

function aplicarFondo() {
  const p = piezas[activo];
  const color = (p && p.color_acento) || config.hero_color_fondo || '#EFD9DE';
  seccion.style.setProperty('--hero-fondo', color);
  const tinta = tintaSobre(color);
  seccion.style.setProperty('--hero-tinta', tinta);
  // La sombra existe para despegar la letra blanca de una foto clara; con
  // tinta oscura sobre pastel solo ensucia.
  seccion.style.setProperty('--hero-sombra',
    tinta === '#FFFFFF' ? '0 2px 18px rgba(40,24,30,.28)' : 'none');
}

/* ------------------------------------------------------------- navegación */
function irA(indice, porGesto = false) {
  if (animando || piezas.length < 2) return;
  const total = piezas.length;
  const destino = ((indice % total) + total) % total;
  if (destino === activo) return;

  animando = true;
  btnPrev.disabled = btnSig.disabled = true;

  // El texto se desvanece antes de cambiar, para que no salte a media transición.
  panel.classList.add('cambiando');

  activo = destino;
  actualizarRoles();
  aplicarFondo();

  setTimeout(() => {
    pintarPanel();
    panel.classList.remove('cambiando');
  }, MENOS_MOVIMIENTO ? 0 : 240);

  setTimeout(() => {
    animando = false;
    btnPrev.disabled = btnSig.disabled = false;
  }, MENOS_MOVIMIENTO ? 0 : DURACION);

  if (porGesto) reiniciarReloj();
}

const siguiente = () => irA(activo + 1, true);
const anterior  = () => irA(activo - 1, true);

/* --------------------------------------------------------------- autoplay */
function reiniciarReloj() {
  clearInterval(reloj);
  if (!Number(config.hero_autoplay) || MENOS_MOVIMIENTO || piezas.length < 2) return;
  reloj = setInterval(() => {
    if (!document.hidden && !animando) irA(activo + 1);
  }, AUTOPLAY);
}

/* ----------------------------------------------------------------- gestos */
function activarGestos() {
  let inicioX = 0, inicioY = 0, siguiendo = false;

  seccion.addEventListener('touchstart', e => {
    if (e.touches.length !== 1) return;
    inicioX = e.touches[0].clientX;
    inicioY = e.touches[0].clientY;
    siguiendo = true;
  }, { passive: true });

  seccion.addEventListener('touchend', e => {
    if (!siguiendo) return;
    siguiendo = false;

    const dx = e.changedTouches[0].clientX - inicioX;
    const dy = e.changedTouches[0].clientY - inicioY;

    // Solo cuenta como gesto horizontal si no fue un intento de desplazamiento vertical.
    if (Math.abs(dx) > 45 && Math.abs(dx) > Math.abs(dy) * 1.4) {
      dx < 0 ? siguiente() : anterior();
    }
  }, { passive: true });
}

/* ------------------------------------------------------------------ arranque */
function iniciar(datos) {
  config = datos.config || {};
  piezas = (datos.hero || []).filter(p => p && p.imagen);

  const temporada = datos.temporada || null;

  // Sin productos: se muestra la imagen de respaldo configurada en el panel.
  if (!piezas.length) {
    piezas = [{
      nombre: config.hero_titulo || 'Flowers Anto',
      descripcion: config.hero_subtitulo || '',
      imagen: config.hero_imagen || 'images/placeholders/hero-01.svg',
      precio: 0, precio_usd: 0, categoria_nombre: '',
      color_acento: config.hero_color_fondo || '#EFD9DE',
    }];
  }

  // La palabra de fondo: la de la temporada vigente manda sobre la general.
  const texto = (temporada && temporada.palabra_hero) || config.hero_palabra || 'FLORES';
  palabra.querySelector('span').textContent = texto;

  // Etiqueta superior
  const etiqueta = $('#heroEtiqueta');
  if (etiqueta) {
    etiqueta.innerHTML = temporada
      ? `<span class="hero-temporada"><i class="fa-solid fa-seedling"></i> ${esc(temporada.titulo)}</span>`
      : `<span>${esc(config.nombre_tienda || 'Flowers Anto')}</span>
         <span class="punto"></span>
         <span>${esc(config.eslogan || 'Managua')}</span>`;
  }

  if (llamada) {
    llamada.querySelector('span').textContent = config.hero_cta_texto || 'Ver arreglos';
  }

  pintarEscenario();
  pintarPanel();
  aplicarFondo();

  const varias = piezas.length > 1;
  btnPrev.hidden = btnSig.hidden = puntos.hidden = !varias;

  if (varias) {
    btnSig.addEventListener('click', siguiente);
    btnPrev.addEventListener('click', anterior);

    puntos.addEventListener('click', e => {
      const b = e.target.closest('button');
      if (b) irA(+b.dataset.indice, true);
    });

    seccion.addEventListener('keydown', e => {
      if (e.key === 'ArrowRight') { e.preventDefault(); siguiente(); }
      if (e.key === 'ArrowLeft')  { e.preventDefault(); anterior(); }
    });

    activarGestos();
    reiniciarReloj();

    // Precarga: la siguiente imagen ya está lista cuando el usuario avanza.
    piezas.slice(1).forEach(p => { const i = new Image(); i.src = p.imagen; });
  }

  seccion.classList.add('listo');
}

// El servidor deja los datos ya resueltos en la propia página.
const guion = document.getElementById('datosHero');
if (guion) {
  try {
    iniciar(JSON.parse(guion.textContent));
  } catch (error) {
    // Si el JSON viniera mal, la portada se queda con su contenido estático
    // en lugar de dejar un hueco en blanco.
    console.error('Flowers Anto — no se pudieron leer los datos de la portada.', error);
    seccion.classList.add('listo');
  }
}

window.addEventListener('pagehide', () => clearInterval(reloj));
document.addEventListener('visibilitychange', () => {
  if (document.hidden) clearInterval(reloj); else reiniciarReloj();
});

})();
