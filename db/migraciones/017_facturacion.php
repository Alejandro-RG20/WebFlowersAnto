<?php
/**
 * Facturación.
 *
 * Una factura no es una vista bonita del pedido: es un documento que tiene que
 * seguir diciendo lo mismo dentro de cinco años. Por eso se guarda como una
 * COPIA de todo lo que la compone —datos de la tienda, del cliente y de cada
 * línea— y no como un puñado de claves ajenas.
 *
 * Si mañana cambia el RUC de la floristería, sube el precio de un ramo o se
 * borra un producto del catálogo, las facturas ya emitidas no se pueden mover:
 * lo que dicen es lo que se cobró el día que se cobró.
 *
 * Por lo mismo, una factura no se borra nunca. Se anula, dejando escrito quién
 * la anuló y por qué, y su número se queda ocupado para siempre: una serie con
 * huecos es una serie de la que no te puedes fiar.
 */

declare(strict_types=1);

return function (PDO $pdo, Esquema $e): void {
    // --- Ajustes de la tienda emisora ---------------------------------
    $e->agregarColumna('configuracion', 'factura_activo', "TINYINT(1) NOT NULL DEFAULT 0");
    $e->agregarColumna('configuracion', 'factura_serie', "VARCHAR(10) NOT NULL DEFAULT 'A'");
    $e->agregarColumna('configuracion', 'factura_siguiente', "INT UNSIGNED NOT NULL DEFAULT 1");
    $e->agregarColumna('configuracion', 'factura_razon_social', "VARCHAR(160) NOT NULL DEFAULT ''");
    $e->agregarColumna('configuracion', 'factura_ruc', "VARCHAR(40) NOT NULL DEFAULT ''");
    $e->agregarColumna('configuracion', 'factura_direccion', "VARCHAR(255) NOT NULL DEFAULT ''");
    $e->agregarColumna('configuracion', 'factura_telefono', "VARCHAR(40) NOT NULL DEFAULT ''");

    // El IVA nicaragüense es del 15% y en el comercio al detalle va DENTRO del
    // precio que se muestra. Por eso la factura lo desglosa hacia atrás en vez
    // de sumarlo: el total de la factura tiene que ser, al céntimo, lo que el
    // cliente ya pagó. Una factura que no cuadra con el cobro no sirve.
    $e->agregarColumna('configuracion', 'factura_iva_activo', "TINYINT(1) NOT NULL DEFAULT 0");
    $e->agregarColumna('configuracion', 'factura_iva_tasa', "DECIMAL(5,2) NOT NULL DEFAULT 15.00");

    $e->agregarColumna('configuracion', 'factura_pie', "VARCHAR(500) NOT NULL DEFAULT ''");
    // Emitir y enviar sola al marcar el pedido como entregado.
    $e->agregarColumna('configuracion', 'factura_auto', "TINYINT(1) NOT NULL DEFAULT 1");

    // --- Facturas ------------------------------------------------------
    if (!$e->tablaExiste('facturas')) {
        $e->sql(
            "CREATE TABLE facturas (
                id              INT AUTO_INCREMENT PRIMARY KEY,
                serie           VARCHAR(10)  NOT NULL,
                numero          INT UNSIGNED NOT NULL,
                folio           VARCHAR(30)  NOT NULL,
                pedido_id       INT          NOT NULL,
                pedido_codigo   VARCHAR(20)  NOT NULL,

                -- Copia de quien emite, congelada el día de la emisión
                emisor_nombre    VARCHAR(160) NOT NULL DEFAULT '',
                emisor_ruc       VARCHAR(40)  NOT NULL DEFAULT '',
                emisor_direccion VARCHAR(255) NOT NULL DEFAULT '',
                emisor_telefono  VARCHAR(40)  NOT NULL DEFAULT '',

                -- Copia de a quién se le factura
                cliente_nombre   VARCHAR(150) NOT NULL DEFAULT '',
                cliente_email    VARCHAR(160) NOT NULL DEFAULT '',
                cliente_telefono VARCHAR(40)  NOT NULL DEFAULT '',
                cliente_direccion VARCHAR(255) NOT NULL DEFAULT '',
                cliente_ruc      VARCHAR(40)  NOT NULL DEFAULT '',

                moneda        VARCHAR(5)    NOT NULL DEFAULT 'C$',
                subtotal      DECIMAL(10,2) NOT NULL DEFAULT 0,
                descuento     DECIMAL(10,2) NOT NULL DEFAULT 0,
                envio         DECIMAL(10,2) NOT NULL DEFAULT 0,
                base_imponible DECIMAL(10,2) NOT NULL DEFAULT 0,
                iva_tasa      DECIMAL(5,2)  NOT NULL DEFAULT 0,
                iva           DECIMAL(10,2) NOT NULL DEFAULT 0,
                total         DECIMAL(10,2) NOT NULL DEFAULT 0,

                metodo_pago   VARCHAR(30)  NOT NULL DEFAULT '',
                cupon_codigo  VARCHAR(40)  NOT NULL DEFAULT '',
                pie           VARCHAR(500) NOT NULL DEFAULT '',
                notas         VARCHAR(400) NOT NULL DEFAULT '',

                emitida_en    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                emitida_por   INT NULL,
                emitida_texto VARCHAR(150) NOT NULL DEFAULT '',
                enviada_en    DATETIME NULL,
                anulada_en    DATETIME NULL,
                anulada_por   INT NULL,
                anulada_motivo VARCHAR(300) NOT NULL DEFAULT '',

                -- Una factura por pedido, y un folio que no se puede repetir.
                UNIQUE KEY uk_factura_pedido (pedido_id),
                UNIQUE KEY uk_factura_folio (serie, numero),
                KEY idx_factura_emitida (emitida_en),
                KEY idx_factura_cliente (cliente_email)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    if (!$e->tablaExiste('factura_items')) {
        $e->sql(
            "CREATE TABLE factura_items (
                id           INT AUTO_INCREMENT PRIMARY KEY,
                factura_id   INT NOT NULL,
                descripcion  VARCHAR(200)  NOT NULL,
                cantidad     SMALLINT UNSIGNED NOT NULL DEFAULT 1,
                precio_unitario DECIMAL(10,2) NOT NULL DEFAULT 0,
                subtotal     DECIMAL(10,2) NOT NULL DEFAULT 0,
                KEY idx_factura_items (factura_id),
                CONSTRAINT fk_factura_items FOREIGN KEY (factura_id)
                    REFERENCES facturas(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    // --- Permisos ------------------------------------------------------
    // Facturar y anular son cosas distintas: quien despacha pedidos puede
    // necesitar emitir, pero anular una factura ya emitida es de administración.
    foreach ([
        ['facturas.ver',    'Ver facturas'],
        ['facturas.emitir', 'Emitir y reenviar facturas'],
        ['facturas.anular', 'Anular facturas'],
    ] as [$codigo, $nombre]) {
        $pdo->prepare(
            "INSERT INTO permisos (codigo, nombre, modulo) VALUES (?,?, 'ventas')
             ON DUPLICATE KEY UPDATE nombre = VALUES(nombre)"
        )->execute([$codigo, $nombre]);
    }

    // Aquí no hay atajo para el super administrador: los permisos se leen de
    // `rol_permisos` y punto, así que hay que dárselos de verdad. Cada rol
    // recibe lo que encaja con lo que ya hacía; nadie gana capacidades nuevas
    // por la puerta de atrás.
    $asignar = static function (PDO $pdo, string $rol, array $codigos): void {
        $marcas = implode(',', array_fill(0, count($codigos), '?'));
        $st = $pdo->prepare(
            "INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
             SELECT r.id, p.id FROM roles r, permisos p
              WHERE r.codigo = ? AND p.codigo IN ($marcas)"
        );
        $st->execute(array_merge([$rol], $codigos));
    };
    $asignar($pdo, 'super_admin', ['facturas.ver', 'facturas.emitir', 'facturas.anular']);
    $asignar($pdo, 'admin',       ['facturas.ver', 'facturas.emitir', 'facturas.anular']);
    // Quien atiende pedidos puede emitir y consultar, pero no anular.
    $asignar($pdo, 'pedidos',     ['facturas.ver', 'facturas.emitir']);
    // El auditor mira y no toca: es justo para lo que existe ese rol.
    $asignar($pdo, 'auditor',     ['facturas.ver']);

    // Los datos fiscales arrancan con lo que ya está configurado en la tienda,
    // para que la primera factura no salga con los campos en blanco.
    $pdo->exec(
        "UPDATE configuracion
            SET factura_razon_social = IF(factura_razon_social = '', nombre_tienda, factura_razon_social),
                factura_direccion    = IF(factura_direccion = '', direccion, factura_direccion),
                factura_telefono     = IF(factura_telefono = '', telefono, factura_telefono)
          WHERE id = 1"
    );
};
