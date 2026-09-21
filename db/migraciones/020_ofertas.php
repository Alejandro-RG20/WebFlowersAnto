<?php
/**
 * Ofertas por producto.
 *
 * Una sola columna nueva en `productos`: el porcentaje de descuento. El precio
 * de venta NO se toca, y ese es el punto. Guardar el precio rebajado encima del
 * original haría que quitar la oferta fuese imposible —el original ya no
 * existiría— y que aplicar dos veces un descuento lo encadenara. Con el
 * porcentaje aparte, el precio base siempre está intacto y el rebajado se
 * calcula cuando hace falta; quitar la oferta es poner el porcentaje a cero.
 *
 * En `pedido_items` se añaden el precio base y el porcentaje que había en el
 * momento de comprar. `precio_unitario` ya guardaba lo que se pagó, así que el
 * total de un pedido viejo no cambia; lo que faltaba era poder explicar en la
 * factura POR QUÉ se pagó eso. Los pedidos que ya existen se rellenan con su
 * propio precio y descuento cero, que es exactamente lo que ocurrió: no había
 * ofertas cuando se hicieron.
 */

declare(strict_types=1);

return function (PDO $pdo, Esquema $e): void {
    $e->agregarColumna('productos', 'descuento_pct', "TINYINT UNSIGNED NOT NULL DEFAULT 0");

    $e->agregarColumna('pedido_items', 'precio_base',   "DECIMAL(10,2) NOT NULL DEFAULT 0.00");
    $e->agregarColumna('pedido_items', 'descuento_pct', "TINYINT UNSIGNED NOT NULL DEFAULT 0");

    // Pedidos anteriores a las ofertas: lo que pagaron era el precio entero.
    $pdo->exec("UPDATE pedido_items SET precio_base = precio_unitario WHERE precio_base = 0.00");

    // El rango válido del porcentaje (0 a 95) lo impone `Precios::TOPE_PCT` al
    // guardar. Aquí no se pone un CHECK a propósito: no todos los MariaDB de
    // hosting compartido lo aceptan, y una migración que revienta en
    // producción cuesta más que la garantía que daría. `TINYINT UNSIGNED` ya
    // impide negativos, que es el error que de verdad descuadraría el cálculo.
};
