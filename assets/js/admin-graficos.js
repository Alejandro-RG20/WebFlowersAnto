/*
 * Gráfico de líneas del resumen del panel.
 *
 * Se dibuja en SVG al ancho real de la tarjeta (y se vuelve a dibujar si
 * cambia), en vez de escalar un dibujo fijo: así los textos de los ejes no se
 * encogen en el móvil ni se estiran en una pantalla ancha.
 *
 * Lectura:
 *   · una sola serie, una sola escala, línea de 2 px y un velo del 10 %;
 *   · el último día y el mejor van escritos en el encabezado de la tarjeta;
 *     el resto está en el globo y en la tabla de debajo («Ver como tabla»),
 *     que tiene todos los datos;
 *   · una línea vertical sigue al cursor y salta al día más cercano. Con el
 *     teclado: Tab hasta el gráfico y flechas izquierda / derecha.
 *
 * Los textos se insertan con textContent, nunca como HTML.
 */
(function () {
  'use strict';

  var NS = 'http://www.w3.org/2000/svg';
  var ALTO = 240;
  var MARGEN = { arriba: 26, derecha: 18, abajo: 28, izquierda: 58 };

  function el(nombre, atributos, padre) {
    var nodo = document.createElementNS(NS, nombre);
    Object.keys(atributos || {}).forEach(function (k) { nodo.setAttribute(k, atributos[k]); });
    if (padre) { padre.appendChild(nodo); }
    return nodo;
  }

  /** Techo «redondo» para el eje: 0 · 2 500 · 5 000 · 7 500 · 10 000. */
  function techo(maximo, divisiones) {
    if (maximo <= 0) { return divisiones; }
    var paso = maximo / divisiones;
    var potencia = Math.pow(10, Math.floor(Math.log10(paso)));
    var bonitos = [1, 2, 2.5, 5, 10];
    for (var i = 0; i < bonitos.length; i++) {
      if (bonitos[i] * potencia >= paso) { return bonitos[i] * potencia * divisiones; }
    }
    return 10 * potencia * divisiones;
  }

  function preparar(figura) {
    var fuente = figura.querySelector('script[type="application/json"]');
    var datos;
    try { datos = JSON.parse(fuente ? fuente.textContent : '[]'); } catch (e) { datos = []; }
    if (!Array.isArray(datos) || datos.length === 0) { return; }

    var moneda   = figura.getAttribute('data-moneda') || '';
    var dinero   = new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    var compacto = new Intl.NumberFormat('es', { notation: 'compact', maximumFractionDigits: 1 });
    var formato  = function (v) { return moneda + dinero.format(v); };

    var burbuja = document.createElement('div');
    burbuja.className = 'grafico-burbuja';
    burbuja.hidden = true;
    burbuja.setAttribute('aria-hidden', 'true');
    var bValor = document.createElement('strong');
    var bDetalle = document.createElement('span');
    burbuja.appendChild(bValor);
    burbuja.appendChild(bDetalle);
    figura.appendChild(burbuja);

    var svg = null;
    var indiceActual = datos.length - 1;

    function dibujar() {
      var ancho = Math.max(280, Math.floor(figura.clientWidth));
      if (svg) { svg.remove(); }
      svg = el('svg', { viewBox: '0 0 ' + ancho + ' ' + ALTO, width: ancho, height: ALTO, role: 'img',
                        'aria-label': figura.getAttribute('aria-label') || 'Gráfico' });
      figura.insertBefore(svg, burbuja);

      var izq = MARGEN.izquierda, der = ancho - MARGEN.derecha;
      var arr = MARGEN.arriba, aba = ALTO - MARGEN.abajo;
      var maxValor = Math.max.apply(null, datos.map(function (d) { return d.v; }));
      var tope = techo(maxValor, 4);
      var paso = datos.length > 1 ? (der - izq) / (datos.length - 1) : 0;
      var x = function (i) { return datos.length > 1 ? izq + i * paso : (izq + der) / 2; };
      var y = function (v) { return aba - (v / tope) * (aba - arr); };

      // Rejilla y eje Y: líneas finas y lisas, un tono por encima del fondo.
      var rejilla = el('g', { class: 'rejilla' }, svg);
      var eje = el('g', { class: 'eje' }, svg);
      for (var t = 0; t <= 4; t++) {
        var valor = tope * t / 4;
        var yy = Math.round(y(valor)) + 0.5;
        if (t > 0) { el('line', { x1: izq, x2: der, y1: yy, y2: yy }, rejilla); }
        var texto = el('text', { x: izq - 10, y: yy + 4, 'text-anchor': 'end' }, eje);
        texto.textContent = valor === 0 ? '0' : moneda + compacto.format(valor);
      }
      el('line', { class: 'base', x1: izq, x2: der, y1: Math.round(aba) + 0.5, y2: Math.round(aba) + 0.5 }, svg);

      // Eje X: tantas fechas como quepan sin pisarse (unos 64 px cada una,
      // siete como mucho), repartidas y siempre con la última.
      var caben = Math.max(2, Math.min(7, Math.floor((der - izq) / 64)));
      var cada = Math.max(1, Math.ceil(datos.length / caben));
      datos.forEach(function (d, i) {
        var ultima = i === datos.length - 1;
        if (i % cada !== 0 && !ultima) { return; }
        if (!ultima && datos.length - 1 - i < cada / 2) { return; }
        var tx = el('text', { x: x(i), y: ALTO - 8, 'text-anchor': ultima ? 'end' : (i === 0 ? 'start' : 'middle') }, eje);
        tx.textContent = d.e;
      });

      // Área y línea.
      var puntos = datos.map(function (d, i) { return [x(i), y(d.v)]; });
      var trazo = puntos.map(function (p, i) { return (i ? 'L' : 'M') + p[0].toFixed(1) + ' ' + p[1].toFixed(1); }).join(' ');
      el('path', { class: 'area', d: trazo + ' L' + x(datos.length - 1).toFixed(1) + ' ' + aba + ' L' + izq + ' ' + aba + ' Z' }, svg);
      el('path', { class: 'linea', d: trazo }, svg);

      // Último día: punto con aro del color del fondo. Su valor va escrito
      // en el encabezado de la tarjeta: dentro del dibujo chocaba con la
      // línea cada vez que el día anterior había sido alto.
      var ultimo = puntos[puntos.length - 1];
      el('circle', { class: 'punto', cx: ultimo[0], cy: ultimo[1], r: 4.5 }, svg);

      if (maxValor <= 0) {
        var vacio = el('text', { x: (izq + der) / 2, y: (arr + aba) / 2, 'text-anchor': 'middle', class: 'eje' }, svg);
        vacio.textContent = 'Sin ventas cobradas en el período';
        vacio.setAttribute('fill', 'currentColor');
      }

      // Capa de lectura: la línea vertical y el punto que siguen al cursor.
      var cruz = el('line', { class: 'cruz', y1: arr - 6, y2: aba, visibility: 'hidden' }, svg);
      var foco = el('circle', { class: 'punto', r: 5, visibility: 'hidden' }, svg);
      var capa = el('rect', { class: 'capa', x: izq - paso / 2, y: 0, width: (der - izq) + paso, height: aba,
                              tabindex: '0', role: 'slider', 'aria-label': 'Recorrer los días del gráfico',
                              'aria-valuemin': '0', 'aria-valuemax': String(datos.length - 1) }, svg);

      function mostrar(i) {
        indiceActual = Math.max(0, Math.min(datos.length - 1, i));
        var d = datos[indiceActual], px = x(indiceActual), py = y(d.v);
        cruz.setAttribute('x1', Math.round(px) + 0.5);
        cruz.setAttribute('x2', Math.round(px) + 0.5);
        cruz.setAttribute('visibility', 'visible');
        foco.setAttribute('cx', px);
        foco.setAttribute('cy', py);
        foco.setAttribute('visibility', 'visible');
        bValor.textContent = formato(d.v);
        bDetalle.textContent = d.e + ' · ' + d.p + (d.p === 1 ? ' pedido' : ' pedidos');
        var mitad = 70;
        burbuja.style.left = Math.max(mitad, Math.min(ancho - mitad, px)) + 'px';
        burbuja.style.top = Math.max(56, py) + 'px';
        burbuja.hidden = false;
        capa.setAttribute('aria-valuenow', String(indiceActual));
        capa.setAttribute('aria-valuetext', d.e + ': ' + formato(d.v) + ', ' + d.p + ' pedidos');
      }
      function ocultar() {
        cruz.setAttribute('visibility', 'hidden');
        foco.setAttribute('visibility', 'hidden');
        burbuja.hidden = true;
      }
      function indiceDesde(evento) {
        var caja = svg.getBoundingClientRect();
        var px = (evento.clientX - caja.left) * (ancho / caja.width);
        return datos.length > 1 ? Math.round((px - izq) / paso) : 0;
      }

      capa.addEventListener('pointermove', function (e) { mostrar(indiceDesde(e)); });
      capa.addEventListener('pointerdown', function (e) { mostrar(indiceDesde(e)); });
      capa.addEventListener('pointerleave', function () { if (document.activeElement !== capa) { ocultar(); } });
      capa.addEventListener('focus', function () { mostrar(indiceActual); });
      capa.addEventListener('blur', ocultar);
      capa.addEventListener('keydown', function (e) {
        var mov = { ArrowLeft: -1, ArrowRight: 1, Home: -Infinity, End: Infinity }[e.key];
        if (mov === undefined) { return; }
        e.preventDefault();
        mostrar(mov === -Infinity ? 0 : mov === Infinity ? datos.length - 1 : indiceActual + mov);
      });
    }

    dibujar();
    var pendiente = 0, ultimoAncho = figura.clientWidth;
    if ('ResizeObserver' in window) {
      new ResizeObserver(function () {
        if (Math.abs(figura.clientWidth - ultimoAncho) < 2) { return; }
        ultimoAncho = figura.clientWidth;
        cancelAnimationFrame(pendiente);
        pendiente = requestAnimationFrame(dibujar);
      }).observe(figura);
    }
  }

  document.querySelectorAll('[data-grafico-linea]').forEach(preparar);
})();
