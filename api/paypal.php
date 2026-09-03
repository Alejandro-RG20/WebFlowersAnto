<?php
/**
 * Crear y capturar el pago de PayPal (Orders v2).
 *
 * Dos reglas sostienen todo esto:
 *
 *   1. El importe no viaja nunca desde el navegador. Se recalcula aquí a
 *      partir del carrito, la zona de envío y el cupón, con la misma función
 *      que pinta el resumen.
 *   2. El pedido se comprueba ENTERO antes de abrir la ventana de PayPal, con
 *      las mismas reglas del checkout normal. Sin esto, alguien podía pagar y
 *      encontrarse después con que la web rechazaba su pedido por una
 *      dirección corta: dinero cobrado y ningún pedido.
 *
 * Al capturar, el pedido se registra aquí mismo. El navegador solo recibe la
 * dirección a la que ir. Así no queda ninguna ventana entre el cobro y el
 * pedido que dependa de que el navegador siga vivo.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorJson('Método no permitido', 405);
}
exigirToken();

if (!PayPal::activo()) {
    errorJson('El pago con PayPal no está disponible en este momento.', 403);
}
if (!Ajustes::activo('pedido_web_activo', true)) {
    errorJson('Los pedidos por la web están pausados en este momento.', 403);
}

// Unos pocos intentos por conexión: crear órdenes en bucle no debe salir gratis.
if (!limitar($pdo, 'paypal:' . ip_cliente(), 20, 600)) {
    errorJson('Demasiados intentos seguidos. Espera unos minutos.', 429);
}

$accion = opcion('accion', ['crear', 'capturar'], 'crear');

// ---------------------------------------------------------------------
//  El pedido, revisado con las reglas de siempre
// ---------------------------------------------------------------------
$revisado = Checkout::revisar($pdo, $_POST);
$datos    = $revisado['datos'];
$detalle  = $revisado['detalle'];

if (!$detalle['items']) {
    errorJson('Tu carrito está vacío.', 400);
}
$datos['metodo_pago'] = 'paypal';

if ($revisado['errores']) {
    // Se devuelven por campo para poder marcarlos en el formulario. La ventana
    // de PayPal no llega a abrirse.
    responderJson([
        'ok'     => false,
        'campos' => $revisado['errores'],
        'error'  => 'Antes de pagar, revisa los datos marcados.',
    ], 422);
}

$total = (float)$detalle['total'];

try {
    // -----------------------------------------------------------------
    //  Crear la orden
    // -----------------------------------------------------------------
    if ($accion === 'crear') {
        if (!limitar($pdo, 'pedido:' . ip_cliente(), 10, 900)) {
            errorJson('Recibimos varios intentos seguidos desde esta conexión. Espera unos minutos.', 429);
        }

        $orden = PayPal::crearOrden(
            $total,
            'FA-' . date('YmdHis'),
            'Pedido en ' . Ajustes::texto('nombre_tienda', 'Flowers Anto'),
            $datos
        );

        // Se guarda para comprobar, al capturar, que la orden que dice el
        // navegador es la que creamos nosotros y por el importe que dijimos.
        $_SESSION['paypal_orden'] = ['id' => $orden, 'total' => $total, 'creada' => time()];

        responderJson([
            'ok'      => true,
            'orden'   => $orden,
            'importe' => number_format(PayPal::enMonedaDeCobro($total), 2, '.', ''),
            'moneda'  => PayPal::moneda(),
        ]);
    }

    // -----------------------------------------------------------------
    //  Capturar y registrar el pedido
    // -----------------------------------------------------------------
    $orden    = trim(crudo('orden'));
    $guardada = $_SESSION['paypal_orden'] ?? null;

    if ($orden === '' || !is_array($guardada) || $orden !== (string)($guardada['id'] ?? '')) {
        errorJson('Esa orden no corresponde a esta compra.', 409);
    }
    // El total no puede haber cambiado entre abrir PayPal y volver: si el
    // carrito se tocó en otra pestaña, se corta antes de cobrar.
    if (abs((float)($guardada['total'] ?? 0) - $total) > 0.01) {
        unset($_SESSION['paypal_orden']);
        errorJson('El carrito cambió mientras pagabas. Vuelve a empezar el pago.', 409);
    }

    $captura = PayPal::capturar($orden, $total);
    if (!$captura['ok']) {
        responderJson(['ok' => false, 'error' => $captura['error']]);
    }

    // El cobro ya es real aunque el pedido todavía no exista. Esta línea es lo
    // que permite encontrar el dinero si algo falla en los pasos siguientes.
    Auditoria::registrar($pdo, 'cobrar', 'paypal', [
        'recurso_tipo' => 'paypal',
        'recurso_id'   => $captura['captura'],
        'descripcion'  => 'PayPal capturó ' . number_format($captura['importe'], 2) . ' '
                        . $captura['moneda'] . ' (orden ' . $orden . '). Falta registrar el pedido.',
        'detalles'     => ['orden' => $orden, 'total_local' => $total],
    ]);

    // Lo recoge Pedidos::crearDesdeCarrito(), que es el único sitio donde se
    // escribe en `pedidos`. Si el registro fallara, esta clave sobrevive y el
    // botón normal del formulario todavía puede terminar el pedido.
    $_SESSION['paypal_pagado'] = [
        'orden'   => $orden,
        'captura' => $captura['captura'],
        'importe' => $captura['importe'],
        'moneda'  => $captura['moneda'],
        'total'   => $total,
    ];
    unset($_SESSION['paypal_orden']);

    $problemaCuenta = Checkout::crearCuentaSiSePide($pdo, $datos, $_POST);
    if ($problemaCuenta) {
        // El cobro está hecho: no se puede dejar al cliente sin pedido por no
        // poder crearle la cuenta. Se registra el pedido igual y se le dice.
        error_log('Flowers Anto — PayPal, cuenta no creada: ' . implode(' | ', $problemaCuenta));
    }

    $resultado = Checkout::registrar($pdo, $datos, $_POST);
    if (!$resultado['ok']) {
        // El dinero está cobrado y anotado en la auditoría, y el pago sigue en
        // la sesión: el cliente puede terminar con «Confirmar pedido».
        error_log('Flowers Anto — PayPal cobrado sin pedido: ' . ($resultado['error'] ?? ''));
        responderJson([
            'ok'    => false,
            'error' => 'Cobramos el pago pero no pudimos registrar el pedido. '
                     . 'Pulsa «Confirmar pedido» para terminarlo; el cobro ya está guardado. '
                     . 'Si no funciona, escríbenos por WhatsApp con el número '
                     . $captura['captura'] . '.',
        ]);
    }

    responderJson([
        'ok'        => true,
        'captura'   => $captura['captura'],
        'mensaje'   => 'Pago confirmado. Estamos abriendo tu pedido…',
        'redirigir' => Pedidos::enlaceSeguimiento($resultado['pedido']),
    ]);

} catch (RuntimeException $e) {
    error_log('Flowers Anto — PayPal: ' . $e->getMessage());
    responderJson(['ok' => false, 'error' => $e->getMessage()]);
}
