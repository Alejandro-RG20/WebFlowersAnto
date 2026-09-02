/**
 * Botón de PayPal en el checkout.
 *
 * El SDK solo se descarga cuando el cliente elige PayPal: cargarlo siempre
 * añadiría cientos de kilobytes a todos los que van a pagar por transferencia.
 *
 * El navegador nunca dice cuánto hay que cobrar. Pide al servidor que cree la
 * orden —y es el servidor quien calcula el importe desde el carrito— y luego
 * le pide que la capture. Aquí solo se mueven identificadores.
 */
(function () {
  'use strict';
  const zona = document.getElementById('zonaPaypal');
  const form = document.querySelector('form[data-checkout], form#formCheckout, main form');
  if (!zona || !form) { return; }

  const base  = document.body.dataset.base || '';
  const csrf  = document.body.dataset.csrf || '';
  const aviso = zona.querySelector('.paypal-aviso');
  const ruta  = (p) => (base.endsWith('/') ? base : base + '/') + p.replace(/^\//, '');
  let cargado = false;

  function decir(texto, clase) {
    if (!aviso) { return; }
    aviso.textContent = texto || '';
    aviso.className = 'paypal-aviso' + (clase ? ' ' + clase : '');
  }

  async function pedir(accion, extra) {
    const cuerpo = new URLSearchParams(Object.assign({ accion, csrf_token: csrf }, extra || {}));
    const zonaSel = document.getElementById('zona_envio_id');
    const tipo    = document.querySelector('input[name="entrega_tipo"]:checked');
    if (zonaSel) { cuerpo.set('zona_envio_id', zonaSel.value); }
    if (tipo)    { cuerpo.set('entrega_tipo', tipo.value); }

    const r = await fetch(ruta('api/paypal.php'), {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'fetch' },
      body: cuerpo, credentials: 'same-origin'
    });
    const json = await r.json().catch(() => ({ ok: false, error: 'No pudimos conectar con el servidor.' }));
    if (!r.ok || json.ok === false) { throw new Error(json.error || 'PayPal no pudo procesar el pago.'); }
    return json;
  }

  function dibujar() {
    if (!window.paypal) { return; }
    window.paypal.Buttons({
      style: { layout: 'vertical', shape: 'pill', label: 'paypal' },
      createOrder: () => pedir('crear').then((r) => {
        decir('Importe a cobrar: ' + r.importe + ' ' + r.moneda + '.');
        return r.orden;
      }),
      onApprove: (datos) => pedir('capturar', { orden: datos.orderID }).then((r) => {
        decir(r.mensaje || 'Pago confirmado.', 'bien');
        // El pedido se registra por el camino de siempre: el servidor ya tiene
        // el cobro guardado en la sesión y lo asocia al crearlo.
        form.submit();
      }),
      onCancel: () => decir('Cancelaste el pago. Puedes intentarlo de nuevo.', ''),
      onError: (e) => decir((e && e.message) || 'PayPal tuvo un problema. Prueba otra vez.', 'mal'),
    }).render('#botonesPaypal').catch(() => decir('No se pudo mostrar el botón de PayPal.', 'mal'));
  }

  function cargarSdk() {
    if (cargado) { return; }
    cargado = true;
    decir('Cargando PayPal…');
    const s = document.createElement('script');
    s.src = 'https://www.paypal.com/sdk/js?client-id=' + encodeURIComponent(zona.dataset.clientId)
          + '&currency=' + encodeURIComponent(zona.dataset.moneda) + '&intent=capture';
    s.onload = () => { decir(''); dibujar(); };
    s.onerror = () => { cargado = false; decir('No se pudo cargar PayPal. Revisa tu conexión.', 'mal'); };
    document.head.appendChild(s);
  }

  function revisar() {
    const elegido = document.querySelector('input[name="metodo_pago"]:checked');
    const esPaypal = elegido && elegido.value === 'paypal';
    zona.hidden = !esPaypal;
    // Con PayPal el pedido lo confirma el botón, no el botón normal del formulario.
    const enviar = form.querySelector('button[type=submit]');
    if (enviar) { enviar.hidden = !!esPaypal; }
    if (esPaypal) { cargarSdk(); }
  }

  document.querySelectorAll('input[name="metodo_pago"]').forEach((r) =>
    r.addEventListener('change', revisar));
  revisar();
})();
