<?php
/**
 * Código de producto: una referencia corta y única para cada arreglo.
 *
 * `productos.codigo` (p. ej. FA-0012) es lo que el cliente puede citar por
 * WhatsApp, lo que se busca en el panel y en el catálogo y lo que sale en
 * los pedidos y las facturas. Es único: el índice lo garantiza en la base,
 * aunque el formulario ya lo comprueba antes para dar un mensaje claro. La
 * intercalación de la tabla no distingue mayúsculas, así que «fa-12» y
 * «FA-12» cuentan como el mismo código.
 *
 * Los productos que ya existen reciben FA- y su número con cuatro cifras,
 * el mismo que la ficha ya publicaba como referencia para Google. Admite
 * NULL solo para el instante entre crear un producto y asignarle el suyo.
 *
 * `pedido_items.codigo` guarda el código que tenía el arreglo al comprarse,
 * igual que ya se guarda su nombre: si mañana se cambia, los pedidos y las
 * facturas de antes siguen diciendo lo que se vendió. `factura_items.codigo`
 * hace lo mismo en la factura: se fija al emitirla y no cambia nunca. Las
 * facturas ya emitidas se quedan como están (son documentos cerrados).
 */

declare(strict_types=1);

return function (PDO $pdo, Esquema $e): void {
    $e->agregarColumna('productos', 'codigo', "VARCHAR(30) NULL DEFAULT NULL AFTER id");
    $pdo->exec("UPDATE productos SET codigo = CONCAT('FA-', LPAD(id, 4, '0'))
                 WHERE codigo IS NULL OR codigo = ''");
    $e->agregarIndice('productos', 'uq_productos_codigo', '(codigo)', true);

    $e->agregarColumna('pedido_items', 'codigo', "VARCHAR(30) NOT NULL DEFAULT '' AFTER producto_id");
    // Los pedidos anteriores toman el código actual de su producto. Los de
    // arreglos ya borrados se quedan sin código: no hay de dónde sacarlo.
    $pdo->exec("UPDATE pedido_items i JOIN productos p ON p.id = i.producto_id
                   SET i.codigo = p.codigo
                 WHERE i.codigo = ''");

    if ($e->tablaExiste('factura_items')) {
        $e->agregarColumna('factura_items', 'codigo', "VARCHAR(30) NOT NULL DEFAULT '' AFTER factura_id");
    }
};
