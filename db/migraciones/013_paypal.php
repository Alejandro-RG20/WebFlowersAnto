<?php
/**
 * Pago con PayPal.
 *
 * PayPal no admite córdobas, así que el cobro se hace en dólares: se convierte
 * el total real del pedido —con envío y descuento ya aplicados— usando una
 * tasa que se configura en el panel. Convertir el total, y no sumar los
 * `precio_usd` de cada producto, es lo único que garantiza que el cliente
 * pague exactamente lo que dice el resumen.
 *
 * El identificador de la orden y el de la captura se guardan en el pedido:
 * sin ellos no se puede conciliar un cobro con PayPal si algo se reclama.
 */

declare(strict_types=1);

return function (PDO $pdo, Esquema $e): void {

    // 'paypal' como método de pago. La columna es un ENUM, así que hay que
    // reescribirlo entero conservando los valores que ya existían.
    $e->modificarColumna(
        'pedidos',
        'metodo_pago',
        "ENUM('transferencia','efectivo','whatsapp','paypal') NOT NULL DEFAULT 'transferencia'"
    );

    $e->agregarColumna('pedidos', 'paypal_orden_id',   "VARCHAR(40) NOT NULL DEFAULT ''");
    $e->agregarColumna('pedidos', 'paypal_captura_id', "VARCHAR(40) NOT NULL DEFAULT ''");
    $e->agregarColumna('pedidos', 'total_usd',         "DECIMAL(10,2) NOT NULL DEFAULT 0.00");
    $e->agregarIndice('pedidos', 'idx_pedidos_paypal', '(paypal_orden_id)');

    // Ajustes del panel.
    $e->agregarColumna('configuracion', 'paypal_activo',    "TINYINT(1) NOT NULL DEFAULT 0");
    $e->agregarColumna('configuracion', 'paypal_modo',      "ENUM('sandbox','vivo') NOT NULL DEFAULT 'sandbox'");
    $e->agregarColumna('configuracion', 'paypal_client_id', "VARCHAR(120) NOT NULL DEFAULT ''");
    $e->agregarColumna('configuracion', 'paypal_secreto',   "VARCHAR(160) NOT NULL DEFAULT ''");
    $e->agregarColumna('configuracion', 'paypal_moneda',    "CHAR(3) NOT NULL DEFAULT 'USD'");
    // C$ por dólar. Con 0 la conversión no se puede hacer y PayPal no se ofrece.
    $e->agregarColumna('configuracion', 'tasa_usd',         "DECIMAL(10,4) NOT NULL DEFAULT 36.5000");

    $pdo->exec("UPDATE configuracion SET tasa_usd = 36.5000 WHERE tasa_usd <= 0");
};
