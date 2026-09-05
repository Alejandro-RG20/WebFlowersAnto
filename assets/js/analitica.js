/**
 * Google Analytics 4: lo que solo se puede medir en el navegador.
 *
 * Lo que pasa en el servidor —ver un producto, empezar el pago, comprar— lo
 * manda la propia página al pintarse. Aquí queda lo que depende de un gesto:
 * aceptar las cookies, añadir al carrito, quitar del carrito, entrar a un
 * producto desde una lista y escribir por WhatsApp.
 *
 * Todo lee `data-ga-item`, que ya viene puesto junto a cada producto, así que
 * el nombre y el precio son exactamente los que la persona tiene delante.
 */
(function () {
  'use strict';

  if (typeof window.gtag !== 'function') { return; }

  const $$ = (sel, raiz) => Array.from((raiz || document).querySelectorAll(sel));
  const moneda = document.body.dataset.gaMoneda || 'NIO';

  /** Lee el producto de un elemento o del ancestro que lo lleve. */
  function item(el) {
    const con = el && el.closest('[data-ga-item]');
    if (!con) { return null; }
    try {
      return JSON.parse(con.dataset.gaItem);
    } catch (e) {
      return null; // un JSON roto no debe tumbar la página
    }
  }

  function medir(nombre, items, extra) {
    if (!items || !items.length) { return; }
    const valor = items.reduce((t, i) => t + (Number(i.price) || 0) * (Number(i.quantity) || 1), 0);
    gtag('event', nombre, Object.assign({
      currency: moneda,
      value: Math.round(valor * 100) / 100,
      items: items
    }, extra || {}));
  }

  // -------------------------------------------------------------------
  // Consentimiento
  //
  // El aviso de cookies manda: hasta que no se acepta, Google recibe una
  // señal sin cookies ni identificador. Al aceptar se le concede el permiso
  // en el momento, sin recargar; al elegir «solo lo necesario» se le retira,
  // porque alguien puede cambiar de opinión desde el enlace del pie.
  // -------------------------------------------------------------------
  const PERMISOS = ['ad_storage', 'ad_user_data', 'ad_personalization',
                    'analytics_storage', 'functionality_storage', 'personalization_storage'];

  function consentimiento(concedido) {
    const estado = {};
    PERMISOS.forEach((p) => { estado[p] = concedido ? 'granted' : 'denied'; });
    gtag('consent', 'update', estado);
  }

  $$('[data-cookies]').forEach((boton) => {
    boton.addEventListener('click', () => {
      consentimiento(boton.dataset.cookies === 'aceptado');
    });
  });

  // -------------------------------------------------------------------
  // Carrito
  // -------------------------------------------------------------------
  // `add_to_cart` se mide cuando el servidor ya dijo que sí: si se midiera al
  // pulsar, un producto agotado contaría igual que uno vendido.
  document.addEventListener('fa:carrito:agregado', (ev) => {
    const datos = ev.detail || {};
    if (datos.item) {
      medir('add_to_cart', [Object.assign({}, datos.item, { quantity: datos.cantidad || 1 })]);
    }
  });

  // Quitar y vaciar el carrito NO se miden aquí: esos formularios recargan la
  // página y los mide el servidor, que además sabe si la operación salió bien.
  // Medirlos también aquí contaría cada retirada dos veces.

  // -------------------------------------------------------------------
  // Forma de pago elegida en el checkout
  //
  // Es el último escalón antes de comprar: si mucha gente llega aquí y no
  // termina, el problema está en el pago y no en el catálogo.
  // -------------------------------------------------------------------
  const pago = document.querySelector('[data-ga-checkout]');
  if (pago) {
    let elegido = '';

    function medirPago(metodo) {
      if (!metodo || metodo === elegido) { return; }
      let datos;
      try { datos = JSON.parse(pago.dataset.gaCheckout); } catch (e) { return; }
      elegido = metodo;
      gtag('event', 'add_payment_info', Object.assign({ payment_type: metodo }, datos));
    }

    $$('input[name="metodo_pago"]').forEach((radio) => {
      radio.addEventListener('change', () => { if (radio.checked) { medirPago(radio.value); } });
    });

    // Si nadie toca los botones —porque la primera forma de pago ya viene
    // marcada— el evento se manda igual al enviar el pedido. Sin esto, el
    // camino más común de todos sería justo el que no se mide.
    document.addEventListener('submit', () => {
      const marcado = document.querySelector('input[name="metodo_pago"]:checked');
      if (marcado) { medirPago(marcado.value); }
    }, true);
  }

  // -------------------------------------------------------------------
  // Clic en un producto dentro de una lista
  // -------------------------------------------------------------------
  document.addEventListener('click', (ev) => {
    const enlace = ev.target.closest('a[href*="producto.php"]');
    if (!enlace) { return; }
    const it = item(enlace);
    if (!it) { return; }
    const lista = enlace.closest('[data-ga-lista]');
    gtag('event', 'select_item', {
      item_list_name: lista ? lista.dataset.gaLista : 'Productos',
      items: [it]
    });
  });

  // -------------------------------------------------------------------
  // WhatsApp: para la floristería, un mensaje es el principio de una venta
  // -------------------------------------------------------------------
  document.addEventListener('click', (ev) => {
    const enlace = ev.target.closest('a[href*="wa.me"], a[href*="api.whatsapp.com"]');
    if (!enlace) { return; }
    const it = item(enlace);
    gtag('event', 'generate_lead', Object.assign({
      metodo: 'whatsapp',
      origen: enlace.dataset.gaOrigen || document.body.dataset.gaPagina || 'sitio'
    }, it ? { items: [it], currency: moneda, value: Number(it.price) || 0 } : {}));
  });
}());
