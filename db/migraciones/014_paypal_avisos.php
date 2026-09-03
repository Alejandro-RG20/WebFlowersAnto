<?php
/**
 * PayPal: formas de pago del botón y avisos firmados (webhooks).
 *
 * El botón de PayPal puede ofrecer además Venmo y «paga en cuotas», según el
 * país del comprador. Se dejan configurables porque no toda tienda las quiere.
 *
 * El webhook es lo que permite enterarse de un reembolso o una contracarga sin
 * mirar el panel de PayPal: PayPal avisa y la web anota el cambio en el pedido.
 */

declare(strict_types=1);

return function (PDO $pdo, Esquema $e): void {
    $e->agregarColumna('configuracion', 'paypal_venmo',      "TINYINT(1) NOT NULL DEFAULT 1");
    $e->agregarColumna('configuracion', 'paypal_cuotas',     "TINYINT(1) NOT NULL DEFAULT 1");
    $e->agregarColumna('configuracion', 'paypal_tarjeta',    "TINYINT(1) NOT NULL DEFAULT 1");
    $e->agregarColumna('configuracion', 'paypal_webhook_id', "VARCHAR(60) NOT NULL DEFAULT ''");

    // Estado del cobro según PayPal. Se separa de `estado_pago` del pedido
    // porque un reembolso no es lo mismo que un comprobante rechazado, y el
    // equipo necesita distinguirlos al mirar el pedido.
    $e->agregarColumna(
        'pedidos',
        'paypal_estado',
        "ENUM('','completado','pendiente','denegado','reembolsado','revertido') NOT NULL DEFAULT ''"
    );

    // Cada aviso de PayPal busca el pedido por su captura. Sin índice sería un
    // recorrido de toda la tabla de pedidos en cada llamada.
    $e->agregarIndice('pedidos', 'idx_pedidos_paypal_captura', '(paypal_captura_id)');
};
