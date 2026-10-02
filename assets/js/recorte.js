/**
 * Encuadre de la foto de perfil antes de subirla.
 *
 * Al elegir la foto se abre un cuadro donde se mueve con el dedo (o el ratón
 * o las flechas) y se acerca pellizcando, con la rueda o con el control. Al
 * guardar se recorta en el navegador a un cuadrado de 600 px y se envía ese
 * recorte en lugar del original: el encuadre es el que la persona eligió y
 * el archivo pesa una fracción de la foto del móvil.
 *
 * Sin este script (o en un navegador que no deja sustituir el archivo del
 * formulario) todo sigue como antes: se sube la foto entera y el servidor la
 * recorta al centro.
 */
(() => {
  'use strict';

  const form    = document.querySelector('.perfil-foto-form');
  const entrada = document.getElementById('foto');
  const dialogo = document.getElementById('recorteFoto');
  if (!form || !entrada || !dialogo || typeof dialogo.showModal !== 'function') { return; }

  // ¿Deja el navegador poner un archivo propio en el formulario?
  let sustituible = false;
  try { sustituible = typeof DataTransfer === 'function' && !!new DataTransfer().items; } catch (e) { /* no */ }
  if (!sustituible) { return; }

  const marco   = dialogo.querySelector('.recorte-marco');
  const imagen  = marco.querySelector('img');
  const zoom    = dialogo.querySelector('#recorteZoom');
  const usar    = dialogo.querySelector('[data-recorte-usar]');
  const LADO    = 600;
  const ZOOM_MAX = 4;

  let url = '';
  let base = 1;          // escala que hace que la foto cubra el marco
  let z = 1;             // acercamiento elegido (1 = cubre justo el marco)
  let x = 0, y = 0;      // desplazamiento del centro de la foto, en px de pantalla
  let ladoMarco = 0;
  const punteros = new Map();
  let pellizco = null;

  form.classList.add('con-recorte');

  // -------------------------------------------------------------------
  // Geometría
  // -------------------------------------------------------------------
  const limitar = () => {
    const w = imagen.naturalWidth * base * z;
    const h = imagen.naturalHeight * base * z;
    const mx = Math.max(0, (w - ladoMarco) / 2);
    const my = Math.max(0, (h - ladoMarco) / 2);
    x = Math.min(mx, Math.max(-mx, x));
    y = Math.min(my, Math.max(-my, y));
  };

  const pintar = () => {
    limitar();
    imagen.style.transform = `translate(-50%, -50%) translate(${x}px, ${y}px) scale(${z})`;
    zoom.value = String(z);
  };

  const acercar = (nuevo, cx = 0, cy = 0) => {
    // Se acerca hacia el punto indicado (centro del pellizco o del cursor),
    // como en la galería del móvil.
    const anterior = z;
    z = Math.min(ZOOM_MAX, Math.max(1, nuevo));
    const f = z / anterior;
    x = cx - (cx - x) * f;
    y = cy - (cy - y) * f;
    pintar();
  };

  const preparar = () => {
    ladoMarco = marco.clientWidth;
    base = Math.max(ladoMarco / imagen.naturalWidth, ladoMarco / imagen.naturalHeight);
    imagen.style.width  = imagen.naturalWidth * base + 'px';
    imagen.style.height = imagen.naturalHeight * base + 'px';
    z = 1; x = 0; y = 0;
    pintar();
  };

  // -------------------------------------------------------------------
  // Abrir y cerrar
  // -------------------------------------------------------------------
  const soltarImagen = () => {
    if (url) { URL.revokeObjectURL(url); url = ''; }
    imagen.removeAttribute('src');
  };

  // Safari en el iPhone deja a veces la vista desplazada al volver de la
  // galería (como si siguiera abierto el teclado): al bajar aparecía un
  // hueco en blanco bajo el pie. Quitar el foco del campo y recolocar el
  // desplazamiento devuelve la vista a su sitio.
  const recolocarVista = () => {
    requestAnimationFrame(() => window.scrollTo(window.scrollX, window.scrollY));
  };

  entrada.addEventListener('change', () => {
    const archivo = entrada.files && entrada.files[0];
    entrada.blur();
    if (!archivo || !/^image\//.test(archivo.type)) { recolocarVista(); return; }
    soltarImagen();
    url = URL.createObjectURL(archivo);
    imagen.onload = () => {
      dialogo.showModal();
      requestAnimationFrame(preparar);
    };
    // Un formato que el navegador no sabe mostrar: se envía tal cual y el
    // servidor decide (acepta o explica el error).
    imagen.onerror = () => { soltarImagen(); form.requestSubmit ? form.requestSubmit() : form.submit(); };
    imagen.src = url;
  });

  dialogo.addEventListener('close', () => {
    if (dialogo.returnValue !== 'usar') { entrada.value = ''; }
    soltarImagen();
    punteros.clear(); pellizco = null;
    recolocarVista();
  });

  dialogo.querySelector('[data-recorte-cancelar]').addEventListener('click', () => dialogo.close('cancelar'));
  // Un toque en el fondo oscuro también cancela.
  dialogo.addEventListener('click', (ev) => { if (ev.target === dialogo) { dialogo.close('cancelar'); } });

  window.addEventListener('resize', () => { if (dialogo.open) { preparar(); } });

  // -------------------------------------------------------------------
  // Mover y acercar
  // -------------------------------------------------------------------
  const relativo = (ev) => {
    const r = marco.getBoundingClientRect();
    return { cx: ev.clientX - r.left - r.width / 2, cy: ev.clientY - r.top - r.height / 2 };
  };

  marco.addEventListener('pointerdown', (ev) => {
    marco.setPointerCapture(ev.pointerId);
    punteros.set(ev.pointerId, { x: ev.clientX, y: ev.clientY });
    if (punteros.size === 2) {
      const [a, b] = [...punteros.values()];
      pellizco = { d: Math.hypot(a.x - b.x, a.y - b.y), z };
    }
  });

  marco.addEventListener('pointermove', (ev) => {
    const antes = punteros.get(ev.pointerId);
    if (!antes) { return; }
    const ahora = { x: ev.clientX, y: ev.clientY };
    punteros.set(ev.pointerId, ahora);
    if (punteros.size === 1) {
      x += ahora.x - antes.x;
      y += ahora.y - antes.y;
      pintar();
    } else if (punteros.size === 2 && pellizco) {
      const [a, b] = [...punteros.values()];
      const r = marco.getBoundingClientRect();
      const cx = (a.x + b.x) / 2 - r.left - r.width / 2;
      const cy = (a.y + b.y) / 2 - r.top - r.height / 2;
      acercar(pellizco.z * Math.hypot(a.x - b.x, a.y - b.y) / pellizco.d, cx, cy);
    }
  });

  const soltar = (ev) => {
    punteros.delete(ev.pointerId);
    if (punteros.size < 2) { pellizco = null; }
  };
  marco.addEventListener('pointerup', soltar);
  marco.addEventListener('pointercancel', soltar);

  marco.addEventListener('wheel', (ev) => {
    ev.preventDefault();
    const { cx, cy } = relativo(ev);
    acercar(z * Math.exp(-ev.deltaY * 0.0015), cx, cy);
  }, { passive: false });

  zoom.addEventListener('input', () => acercar(parseFloat(zoom.value) || 1));

  marco.addEventListener('keydown', (ev) => {
    const paso = ev.shiftKey ? 30 : 10;
    const teclas = {
      ArrowLeft: () => { x -= paso; }, ArrowRight: () => { x += paso; },
      ArrowUp: () => { y -= paso; },   ArrowDown: () => { y += paso; },
      '+': () => acercar(z + 0.1), '=': () => acercar(z + 0.1), '-': () => acercar(z - 0.1),
    };
    if (!teclas[ev.key]) { return; }
    ev.preventDefault();
    teclas[ev.key]();
    pintar();
  });

  // -------------------------------------------------------------------
  // Recortar y enviar
  // -------------------------------------------------------------------
  usar.addEventListener('click', () => {
    const escala = base * z;
    const lado = ladoMarco / escala;
    const sx = imagen.naturalWidth / 2 - (ladoMarco / 2 + x) / escala;
    const sy = imagen.naturalHeight / 2 - (ladoMarco / 2 + y) / escala;

    const lienzo = document.createElement('canvas');
    lienzo.width = lienzo.height = LADO;
    const ctx = lienzo.getContext('2d');
    ctx.imageSmoothingQuality = 'high';
    ctx.drawImage(imagen, sx, sy, lado, lado, 0, 0, LADO, LADO);

    usar.disabled = true;
    lienzo.toBlob((blob) => {
      usar.disabled = false;
      if (!blob) { dialogo.close('cancelar'); return; }
      const dt = new DataTransfer();
      dt.items.add(new File([blob], 'foto-perfil.jpg', { type: 'image/jpeg' }));
      entrada.files = dt.files;

      // La vista previa cambia al momento, mientras se guarda.
      const avatar = document.querySelector('.perfil-avatar');
      if (avatar) {
        avatar.textContent = '';
        const img = new Image(64, 64);
        img.alt = '';
        img.src = lienzo.toDataURL('image/jpeg', 0.8);
        avatar.appendChild(img);
      }
      dialogo.close('usar');
      form.requestSubmit ? form.requestSubmit() : form.submit();
    }, 'image/jpeg', 0.9);
  });
})();
