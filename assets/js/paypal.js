/**
 * Botones de PayPal en el checkout (SDK oficial, Orders v2).
 *
 * El SDK solo se descarga cuando el cliente elige PayPal: cargarlo siempre
 * añadiría cientos de kilobytes a quien va a pagar por transferencia.
 *
 * Lo que hace este archivo, y lo que NO hace:
 *   · No sabe cuánto hay que cobrar. Manda el formulario al servidor, y es el
 *     servidor quien calcula el importe desde el carrito y crea la orden.
 *   · No registra el pedido. Al aprobar, el servidor captura el cobro Y
 *     registra el pedido en la misma llamada, y responde con la dirección a la
 *     que ir. Así no hay ningún momento en el que el dinero esté cobrado y el
 *     pedido dependa de que este navegador siga vivo.
 *   · Si el formulario tiene errores, el servidor los devuelve por campo y la
 *     ventana de PayPal ni se abre.
 */
(function () {
  'use strict';

  const zona = document.getElementById('zonaPaypal');
  const form = document.querySelector('form[data-checkout]') || document.querySelector('main form');
  if (!zona || !form) { return; }

  const base   = document.body.dataset.base || '';
  const csrf   = document.body.dataset.csrf || '';
  const aviso  = zona.querySelector('.paypal-aviso');
  const caja   = document.getElementById('botonesPaypal');
  const ruta   = (p) => (base.endsWith('/') ? base : base + '/') + p.replace(/^\//, '');
  let cargando = false;
  let dibujado = false;

  // -------------------------------------------------------------------
  //  Avisos
  // -------------------------------------------------------------------
  function decir(texto, clase) {
    if (!aviso) { return; }
    aviso.textContent = texto || '';
    aviso.className = 'paypal-aviso' + (clase ? ' ' + clase : '');
  }

  function limpiarErrores() {
    form.querySelectorAll('.campo.con-error').forEach((c) => c.classList.remove('con-error'));
    form.querySelectorAll('[data-error-paypal]').forEach((p) => p.remove());
  }

  /** Marca los campos que el servidor rechazó y lleva la vista al primero. */
  function marcarErrores(campos) {
    limpiarErrores();
    let primero = null;
    Object.keys(campos || {}).forEach((nombre) => {
      const campo = form.querySelector('[name="' + nombre + '"]');
      if (!campo) { return; }
      const envoltura = campo.closest('.campo') || campo.parentElement;
      if (envoltura) {
        envoltura.classList.add('con-error');
        const p = document.createElement('p');
        p.className = 'error-campo';
        p.setAttribute('data-error-paypal', '');
        p.textContent = campos[nombre];
        envoltura.appendChild(p);
      }
      campo.setAttribute('aria-invalid', 'true');
      primero = primero || campo;
    });
    if (primero) {
      primero.scrollIntoView({ behavior: 'smooth', block: 'center' });
      try { primero.focus({ preventScroll: true }); } catch (e) { /* da igual */ }
    }
  }

  // -------------------------------------------------------------------
  //  Llamadas al servidor
  // -------------------------------------------------------------------
  /**
   * Manda el formulario entero. El servidor lo revisa con las mismas reglas
   * del checkout normal antes de tocar PayPal.
   */
  async function pedir(accion, extra) {
    const cuerpo = new FormData(form);
    cuerpo.set('accion', accion);
    cuerpo.set('csrf_token', csrf);
    cuerpo.set('metodo_pago', 'paypal');
    Object.keys(extra || {}).forEach((k) => cuerpo.set(k, extra[k]));

    const r = await fetch(ruta('api/paypal.php'), {
      method: 'POST',
      headers: { 'X-Requested-With': 'fetch' },
      body: new URLSearchParams(cuerpo),
      credentials: 'same-origin'
    });

    let json;
    try {
      json = await r.json();
    } catch (e) {
      throw new Error('No pudimos conectar con el servidor. Revisa tu conexión.');
    }

    if (json && json.campos) {
      marcarErrores(json.campos);
      throw new Error(json.error || 'Revisa los datos marcados antes de pagar.');
    }
    if (!r.ok || json.ok === false) {
      throw new Error((json && json.error) || 'PayPal no pudo procesar el pago.');
    }
    limpiarErrores();
    return json;
  }

  // -------------------------------------------------------------------
  //  Botones
  // -------------------------------------------------------------------
  function dibujar() {
    if (dibujado || !window.paypal || !window.paypal.Buttons) { return; }
    dibujado = true;
    caja.innerHTML = '';

    const botones = window.paypal.Buttons({
      style: {
        layout: 'vertical',
        color:  'gold',       // el combinado que PayPal recomienda por conversión
        shape:  'pill',
        label:  'paypal',
        height: 48,
        tagline: false
      },

      // Un pago no se puede empezar dos veces a la vez.
      onInit: function (datos, acciones) { acciones.enable(); },

      onClick: function (datos, acciones) {
        decir('');
        limpiarErrores();
        return acciones.resolve();
      },

      createOrder: function () {
        decir('Preparando el pago…');
        return pedir('crear').then(function (r) {
          decir('Se cobrarán ' + r.importe + ' ' + r.moneda + '.');
          return r.orden;
        }).catch(function (e) {
          decir(e.message, 'mal');
          throw e;
        });
      },

      onApprove: function (datos, acciones) {
        decir('Confirmando el pago con PayPal…');
        return pedir('capturar', { orden: datos.orderID }).then(function (r) {
          decir(r.mensaje || 'Pago confirmado.', 'bien');
          // El pedido ya está registrado en el servidor: solo queda ir a verlo.
          window.location.assign(r.redirigir);
        }).catch(function (e) {
          // PayPal recomienda reintentar cuando el medio de pago se rechaza:
          // el comprador elige otra tarjeta sin empezar de cero.
          if (/INSTRUMENT_DECLINED/i.test(e.message) && acciones && acciones.restart) {
            decir('Ese medio de pago fue rechazado. Elige otro en la ventana de PayPal.', 'mal');
            return acciones.restart();
          }
          decir(e.message, 'mal');
        });
      },

      onCancel: function () {
        decir('Cancelaste el pago. Tu pedido sigue aquí: puedes intentarlo otra vez.', '');
      },

      onError: function (e) {
        // El SDK avisa aquí también de los errores que ya mostramos arriba.
        const texto = (e && e.message) || '';
        if (!aviso || !aviso.textContent) {
          decir(texto || 'PayPal tuvo un problema. Vuelve a intentarlo en un momento.', 'mal');
        }
      }
    });

    if (botones.isEligible && !botones.isEligible()) {
      decir('PayPal no está disponible en este navegador. Elige otra forma de pago.', 'mal');
      return;
    }
    botones.render(caja).catch(function () {
      decir('No se pudo mostrar el botón de PayPal. Recarga la página.', 'mal');
    });
  }

  // -------------------------------------------------------------------
  //  Carga del SDK
  // -------------------------------------------------------------------
  function cargarSdk() {
    if (cargando || window.paypal) { if (window.paypal) { dibujar(); } return; }
    cargando = true;
    decir('Cargando PayPal…');

    const p = new URLSearchParams({
      'client-id': zona.dataset.clientId,
      currency:    zona.dataset.moneda,
      intent:      'capture',
      components:  'buttons',
      locale:      zona.dataset.locale || 'es_ES'
    });
    // Formas de pago que el panel deja activar. PayPal solo enseña las que el
    // comprador puede usar de verdad según su país.
    if (zona.dataset.habilitar)   { p.set('enable-funding',  zona.dataset.habilitar); }
    if (zona.dataset.deshabilitar) { p.set('disable-funding', zona.dataset.deshabilitar); }

    const s = document.createElement('script');
    s.src = 'https://www.paypal.com/sdk/js?' + p.toString();
    s.setAttribute('data-page-type', 'checkout');
    s.onload  = function () { decir(''); dibujar(); };
    s.onerror = function () {
      cargando = false;
      decir('No se pudo cargar PayPal. Revisa tu conexión e inténtalo otra vez.', 'mal');
    };
    document.head.appendChild(s);
  }

  // -------------------------------------------------------------------
  //  Con PayPal elegido, el pedido lo confirma el botón de PayPal
  // -------------------------------------------------------------------
  function revisar() {
    const elegido  = document.querySelector('input[name="metodo_pago"]:checked');
    const esPaypal = !!elegido && elegido.value === 'paypal';
    zona.hidden = !esPaypal;

    const enviar = form.querySelector('button[type=submit]');
    if (enviar) {
      enviar.hidden = esPaypal;
      // Oculto no basta: si sigue habilitado, un Enter en cualquier campo
      // enviaría el formulario sin haber pagado.
      enviar.disabled = esPaypal;
    }
    if (esPaypal) { cargarSdk(); }
  }

  document.querySelectorAll('input[name="metodo_pago"]').forEach(function (r) {
    r.addEventListener('change', revisar);
  });
  revisar();
})();
