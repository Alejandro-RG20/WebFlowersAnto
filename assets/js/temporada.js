/**
 * Estilos de temporada — capa ambiental y estallido al interactuar.
 *
 * Solo se carga cuando hay una temporada vigente con estilo. Lo que hace es
 * crear los elementos y darles unas variables CSS; el movimiento lo lleva
 * entero la hoja de estilos, así que no hay ningún cálculo por fotograma y la
 * página no se vuelve más pesada por tener animación.
 */
(function () {
  'use strict';

  const cuerpo = document.body;
  const estilo = cuerpo.dataset.temporada;
  if (!estilo) { return; }

  // Quien tiene desactivadas las animaciones no ve ninguna, y tampoco se
  // crean: no tiene sentido montar el DOM para dejarlo oculto.
  const consulta = window.matchMedia('(prefers-reduced-motion: reduce)');
  if (consulta.matches) { return; }

  const formas = (cuerpo.dataset.temporadaFormas || '').split(',').filter(Boolean);
  const movs   = (cuerpo.dataset.temporadaMovs   || '').split(',');
  const chispa = cuerpo.dataset.temporadaChispa || formas[0];
  if (!formas.length) { return; }

  const NS = 'http://www.w3.org/2000/svg';
  const azar = (min, max) => min + Math.random() * (max - min);

  function nuevaForma(id) {
    const svg = document.createElementNS(NS, 'svg');
    const uso = document.createElementNS(NS, 'use');
    uso.setAttribute('href', '#tf-' + id);
    svg.appendChild(uso);
    svg.setAttribute('aria-hidden', 'true');
    svg.setAttribute('focusable', 'false');
    return svg;
  }

  // -------------------------------------------------------------------
  // Capa ambiental
  //
  // Las partículas se reparten en dos franjas laterales y dejan el centro
  // libre: por ahí pasan los títulos, los botones y las fichas de producto, y
  // una flor cruzándolos distrae en vez de acompañar.
  // -------------------------------------------------------------------
  const TOTAL = 22;

  function montarCapa() {
    const capa = document.createElement('div');
    capa.className = 'capa-temporada';
    capa.setAttribute('aria-hidden', 'true');

    for (let i = 0; i < TOTAL; i++) {
      const cual = i % formas.length;
      const mov  = movs[cual] || 'caer';
      const svg  = nuevaForma(formas[cual]);
      svg.classList.add('mov-' + mov);

      // Las que se desplazan van en franjas estrechas a los lados: el centro,
      // donde están los títulos, los botones y las fichas, queda despejado.
      // Las que rebotan cruzan por abajo y las que titilan se reparten por
      // toda la pantalla, porque no tapan nada al no moverse.
      if (mov === 'rebotar') {
        svg.style.left = azar(-4, 24) + '%';
      } else if (mov === 'destellar') {
        svg.style.left = azar(2, 96) + '%';
        svg.style.setProperty('--alto', azar(8, 82).toFixed(0) + 'vh');
      } else {
        svg.style.left = (i % 2 === 0 ? azar(0, 15) : azar(85, 99)) + '%';
      }

      // Cada movimiento pide su propio tamaño y su propio ritmo.
      // Los dibujos llevan detalle —una palmera, un farol, un ramo— y cuanto
      // más grandes, mejor se reconocen. Los destellos van algo menores: son
      // acompañamiento, no protagonistas.
      const tam = mov === 'rebotar'   ? azar(40, 64)
                : mov === 'destellar' ? azar(24, 42)
                : azar(32, 58);
      const dur = mov === 'destellar' ? azar(3.5, 7)
                : mov === 'rebotar'   ? azar(11, 19)
                : mov === 'flotar'    ? azar(20, 34)
                : mov === 'derivar'   ? azar(19, 33)
                : azar(16, 30);

      svg.style.setProperty('--tam', tam.toFixed(0) + 'px');
      svg.style.setProperty('--dur', dur.toFixed(1) + 's');
      // Retraso negativo: al cargar la página ya hay piezas repartidas por la
      // pantalla, en vez de empezar todas juntas desde el mismo borde.
      svg.style.setProperty('--esperar', '-' + azar(0, dur).toFixed(1) + 's');
      svg.style.setProperty('--deriva', azar(-70, 70).toFixed(0) + 'px');
      svg.style.setProperty('--giro',
        mov === 'girar' ? azar(360, 900).toFixed(0) + 'deg' : azar(-320, 320).toFixed(0) + 'deg');
      capa.appendChild(svg);
    }
    document.body.appendChild(capa);
    return capa;
  }

  let capa = montarCapa();

  // Con la pestaña en segundo plano no se anima nada: el navegador ya frena
  // las animaciones CSS, pero pausarlas explícitamente ahorra batería en el
  // teléfono cuando la página queda abierta detrás de otra.
  document.addEventListener('visibilitychange', () => {
    if (capa) {
      capa.style.animationPlayState = document.hidden ? 'paused' : 'running';
      capa.querySelectorAll('svg').forEach((s) => {
        s.style.animationPlayState = document.hidden ? 'paused' : 'running';
      });
    }
  });

  // Si el visitante activa «reducir movimiento» mientras navega, se retira.
  consulta.addEventListener('change', (ev) => {
    if (ev.matches && capa) { capa.remove(); capa = null; }
  });

  // -------------------------------------------------------------------
  // Estallido al interactuar
  //
  // El detalle que celebra la acción: añadir al carrito, marcar un favorito o
  // confirmar el pedido. Sale del punto que se tocó, dura menos de un segundo
  // y se borra solo.
  // -------------------------------------------------------------------
  const PIEZAS = 10;
  let ultimo = 0;

  function estallar(x, y) {
    // Un estallido cada 400 ms como mucho: quien pulse repetido no llena la
    // pantalla de corazones.
    const ahora = Date.now();
    if (ahora - ultimo < 400) { return; }
    ultimo = ahora;

    const trozos = [];
    for (let i = 0; i < PIEZAS; i++) {
      const svg = nuevaForma(i % 3 === 0 ? chispa : formas[i % formas.length]);
      svg.setAttribute('class', 'chispa-temporada');
      svg.style.left = x + 'px';
      svg.style.top  = y + 'px';

      // Abanico hacia arriba, ligeramente abierto a los lados.
      const angulo = (-90 + azar(-52, 52)) * Math.PI / 180;
      const fuerza = azar(55, 130);
      svg.style.setProperty('--dx',  (Math.cos(angulo) * fuerza).toFixed(0) + 'px');
      svg.style.setProperty('--dy',  (Math.sin(angulo) * fuerza).toFixed(0) + 'px');
      svg.style.setProperty('--esc', azar(.6, 1.25).toFixed(2));
      svg.style.setProperty('--rot', azar(-200, 200).toFixed(0) + 'deg');
      svg.style.animationDelay = azar(0, 90).toFixed(0) + 'ms';

      svg.addEventListener('animationend', () => svg.remove(), { once: true });
      trozos.push(svg);
    }
    // Una sola inserción en el documento en vez de diez.
    const grupo = document.createDocumentFragment();
    trozos.forEach((t) => grupo.appendChild(t));
    document.body.appendChild(grupo);
  }

  function desde(ev) {
    const objetivo = ev.target.closest('button, a');
    if (objetivo) {
      const r = objetivo.getBoundingClientRect();
      estallar(r.left + r.width / 2, r.top + r.height / 2);
      return;
    }
    estallar(ev.clientX, ev.clientY);
  }

  // Se escucha aparte, sin tocar los manejadores que ya existen: si mañana
  // cambia la lógica del carrito, esto sigue funcionando o deja de dispararse,
  // pero nunca la rompe.
  document.addEventListener('click', (ev) => {
    if (ev.target.closest('[data-favorito]')) { desde(ev); }
  }, { passive: true });

  document.addEventListener('submit', (ev) => {
    if (ev.target.matches('.form-agregar')) { desde(ev); }
  }, { passive: true, capture: true });

  // El momento de celebrar: la página llega con un aviso de éxito —pedido
  // confirmado, comprobante recibido— y el estallido sale de ese aviso. Se
  // engancha al mensaje que el servidor ya pinta, así que vale para cualquier
  // acción que termine bien, hoy y las que se añadan después.
  const exito = document.querySelector('.aviso-global.aviso-exito');
  if (exito) {
    const r = exito.getBoundingClientRect();
    // Un respiro antes: el estallido acompaña a la página ya dibujada.
    setTimeout(() => estallar(r.left + r.width / 2, r.top + r.height / 2), 260);
  }
})();
