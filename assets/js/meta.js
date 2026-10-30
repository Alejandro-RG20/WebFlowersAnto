/**
 * Píxel de Meta: lo que solo se puede medir en el navegador.
 *
 * Lo que pasa en el servidor —ver un arreglo, empezar el pedido, comprar— ya
 * lo dejó en la cola la propia página. Aquí queda lo que depende de un gesto:
 * aceptar las cookies, añadir al carrito, guardar en favoritos, elegir la
 * forma de pago y escribir por WhatsApp.
 *
 * Añadir al carrito y guardar en favoritos los manda también el servidor; su
 * respuesta trae el evento con el identificador que usó, y aquí se repite tal
 * cual para que Meta junte los dos en uno.
 */
(function () {
  'use strict';

  if (typeof window.fbq !== 'function') { return; }

  const $$ = (sel, raiz) => Array.from((raiz || document).querySelectorAll(sel));

  /** Identificador para los eventos que solo salen del navegador. */
  const nuevoId = (nombre) => nombre + '-' + Date.now().toString(36) + Math.random().toString(36).slice(2, 10);

  function enviar(evento, datos, id) {
    try {
      fbq('track', evento, datos || {}, { eventID: id || nuevoId(evento) });
    } catch (e) { /* medir nunca puede romper la página */ }
  }

  /** Producto de un elemento o de su ancestro, en el formato de Meta. */
  function producto(el) {
    const con = el && el.closest('[data-ga-item]');
    if (!con) { return null; }
    try {
      const it = JSON.parse(con.dataset.gaItem);
      return { content_ids: [String(it.item_id)], content_name: it.item_name, content_type: 'product' };
    } catch (e) {
      return null;
    }
  }

  // -------------------------------------------------------------------
  // Consentimiento
  //
  // Sin «Aceptar todo» el script de Meta ni siquiera se descarga: la cola
  // espera. Al aceptar se carga y sale lo que había apuntado en esta página.
  // Quien después elige «solo lo necesario» desde el pie deja de enviar.
  // -------------------------------------------------------------------
  $$('[data-cookies]').forEach((boton) => {
    boton.addEventListener('click', () => {
      if (boton.dataset.cookies === 'aceptado') {
        fbq('consent', 'grant');
        if (typeof window.faCargarMeta === 'function') { window.faCargarMeta(); }
      } else {
        fbq('consent', 'revoke');
      }
    });
  });

  // -------------------------------------------------------------------
  // Carrito y favoritos: el servidor ya mandó su copia con este identificador
  // -------------------------------------------------------------------
  ['fa:carrito:agregado', 'fa:favorito:agregado'].forEach((nombre) => {
    document.addEventListener(nombre, (ev) => {
      const m = ev.detail && ev.detail.meta;
      if (m && m.evento) { enviar(m.evento, m.datos, m.id); }
    });
  });

  // -------------------------------------------------------------------
  // Forma de pago elegida en el checkout
  // -------------------------------------------------------------------
  const pago = document.querySelector('[data-ga-checkout]');
  if (pago) {
    let enviado = false;

    function medirPago() {
      if (enviado) { return; }
      let d;
      try { d = JSON.parse(pago.dataset.gaCheckout); } catch (e) { return; }
      const items = Array.isArray(d.items) ? d.items : [];
      enviado = true;
      enviar('AddPaymentInfo', {
        content_ids: items.map((i) => String(i.item_id)),
        contents: items.map((i) => ({ id: String(i.item_id), quantity: Number(i.quantity) || 1, item_price: Number(i.price) || 0 })),
        content_type: 'product',
        num_items: items.reduce((t, i) => t + (Number(i.quantity) || 1), 0),
        value: Number(d.value) || 0,
        currency: d.currency
      });
    }

    // Una sola vez por visita al checkout: cambiar de idea entre transferencia
    // y PayPal no son dos clientes distintos dando su forma de pago.
    $$('input[name="metodo_pago"]').forEach((radio) => {
      radio.addEventListener('change', () => { if (radio.checked) { medirPago(); } });
    });
    document.addEventListener('submit', () => {
      if (document.querySelector('input[name="metodo_pago"]:checked')) { medirPago(); }
    }, true);
  }

  // -------------------------------------------------------------------
  // WhatsApp: muchas ventas de la floristería empiezan por un mensaje
  // -------------------------------------------------------------------
  document.addEventListener('click', (ev) => {
    const enlace = ev.target.closest('a[href*="wa.me"], a[href*="api.whatsapp.com"]');
    if (!enlace) { return; }
    enviar('Contact', producto(enlace) || {});
  });
}());
