<?php
/**
 * Crear y capturar el pago de PayPal.
 *
 * El importe no viaja nunca desde el navegador: se recalcula aquí a partir
 * del carrito, la zona de envío y el cupón de la sesión, con la misma función
 * que pinta el resumen. Lo único que manda el cliente es el identificador de
 * la orden que PayPal le devolvió.
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

// Unos pocos intentos por conexión: crear órdenes en bucle no debe salir gratis.
if (!limitar($pdo, 'paypal:' . ip_cliente(), 20, 600)) {
    errorJson('Demasiados intentos seguidos. Espera unos minutos.', 429);
}

$zona    = Envios::zona($pdo, identificador('zona_envio_id'));
$entrega = opcion('entrega_tipo', ['domicilio', 'retiro'], 'domicilio');
$cupon   = null;

if (!empty($_SESSION['cupon'])) {
    $revision = Cupones::revisar(
        $pdo, (string)$_SESSION['cupon'],
        Carrito::detalle($pdo, $zona, $entrega)['subtotal'],
        0.0, Auth::id(), (string)(Auth::usuario()['email'] ?? '')
    );
    $cupon = $revision['ok'] ? $revision['cupon'] : null;
}

$detalle = Carrito::detalle($pdo, $zona, $entrega, $cupon);
if (!$detalle['items']) {
    errorJson('Tu carrito está vacío.', 400);
}

$total = (float)$detalle['total'];

try {
    if (opcion('accion', ['crear', 'capturar'], 'crear') === 'crear') {
        $orden = PayPal::crearOrden(
            $total,
            'FA-' . date('YmdHis'),
            'Pedido en ' . Ajustes::texto('nombre_tienda', 'Flowers Anto')
        );
        // Se guarda en la sesión para poder comprobar, al capturar, que la
        // orden que dice el navegador es la que creamos nosotros.
        $_SESSION['paypal_orden'] = $orden;
        responderJson([
            'ok'      => true,
            'orden'   => $orden,
            'importe' => number_format(PayPal::enMonedaDeCobro($total), 2, '.', ''),
            'moneda'  => PayPal::moneda(),
        ]);
    }

    $orden = trim(crudo('orden'));
    if ($orden === '' || $orden !== (string)($_SESSION['paypal_orden'] ?? '')) {
        errorJson('Esa orden no corresponde a esta compra.', 409);
    }

    $captura = PayPal::capturar($orden, $total);
    if (!$captura['ok']) {
        responderJson(['ok' => false, 'error' => $captura['error']]);
    }

    // El pago queda anotado en la sesión: lo recoge checkout.php al registrar
    // el pedido, que es el único sitio donde se escribe en `pedidos`.
    $_SESSION['paypal_pagado'] = [
        'orden'   => $orden,
        'captura' => $captura['captura'],
        'importe' => $captura['importe'],
        'moneda'  => $captura['moneda'],
        'total'   => $total,
    ];

    responderJson([
        'ok'      => true,
        'captura' => $captura['captura'],
        'mensaje' => 'Pago confirmado. Estamos registrando tu pedido…',
    ]);

} catch (RuntimeException $e) {
    error_log('Flowers Anto — PayPal: ' . $e->getMessage());
    responderJson(['ok' => false, 'error' => $e->getMessage()]);
}
