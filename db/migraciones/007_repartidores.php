<?php
/**
 * Repartidores y envío del pedido al motorizado.
 *
 * Hasta ahora los datos de la entrega se le pasaban al motorizado copiándolos
 * a mano en WhatsApp, que es donde se pierden los números de casa y las
 * referencias. Aquí quedan guardados los repartidores con su teléfono, y cada
 * pedido registra a cuál se le asignó y cuándo, para saber quién llevó qué.
 */

declare(strict_types=1);

return function (PDO $pdo, Esquema $e): void {

    // -----------------------------------------------------------------
    // Repartidores
    // -----------------------------------------------------------------
    $e->sql("CREATE TABLE IF NOT EXISTS repartidores (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        nombre     VARCHAR(120) NOT NULL,
        telefono   VARCHAR(20)  NOT NULL,
        vehiculo   VARCHAR(80)  NOT NULL DEFAULT '',
        notas      VARCHAR(255) NOT NULL DEFAULT '',
        activo     TINYINT(1)   NOT NULL DEFAULT 1,
        orden      SMALLINT     NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_repartidores_orden (activo, orden, nombre)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // -----------------------------------------------------------------
    // Asignación en el pedido
    // -----------------------------------------------------------------
    $e->agregarColumna('pedidos', 'repartidor_id', "INT DEFAULT NULL");
    // El nombre y el teléfono se copian al asignar: si el repartidor deja de
    // trabajar y se borra su ficha, el pedido sigue diciendo quién lo llevó.
    $e->agregarColumna('pedidos', 'repartidor_nombre',   "VARCHAR(120) NOT NULL DEFAULT ''");
    $e->agregarColumna('pedidos', 'repartidor_telefono', "VARCHAR(20)  NOT NULL DEFAULT ''");
    $e->agregarColumna('pedidos', 'repartidor_enviado_en', "DATETIME DEFAULT NULL");

    if (!$e->indiceExiste('pedidos', 'fk_pedido_repartidor')) {
        $e->sql("ALTER TABLE pedidos
                 ADD CONSTRAINT fk_pedido_repartidor
                 FOREIGN KEY (repartidor_id) REFERENCES repartidores(id) ON DELETE SET NULL");
    }

    // -----------------------------------------------------------------
    // Permisos
    // -----------------------------------------------------------------
    $permisos = [
        ['repartidores.ver',       'Ver los repartidores',            'pedidos'],
        ['repartidores.gestionar', 'Crear y editar repartidores',     'pedidos'],
        ['pedidos.despachar',      'Enviar un pedido a un repartidor','pedidos'],
    ];
    $ins = $pdo->prepare("INSERT INTO permisos (codigo, nombre, modulo) VALUES (?, ?, ?)
                          ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), modulo = VALUES(modulo)");
    foreach ($permisos as $p) {
        $ins->execute($p);
    }

    // Quien ya gestiona pedidos también despacha: es la misma persona en la
    // tienda. Los permisos se añaden a los roles que existen, sin tocar los
    // que el negocio haya creado a mano.
    $idsRol     = $pdo->query("SELECT codigo, id FROM roles")->fetchAll(PDO::FETCH_KEY_PAIR);
    $idsPermiso = $pdo->query("SELECT codigo, id FROM permisos")->fetchAll(PDO::FETCH_KEY_PAIR);

    $asignaciones = [
        'super_admin' => ['repartidores.ver', 'repartidores.gestionar', 'pedidos.despachar'],
        'admin'       => ['repartidores.ver', 'repartidores.gestionar', 'pedidos.despachar'],
        'pedidos'     => ['repartidores.ver', 'repartidores.gestionar', 'pedidos.despachar'],
        'auditor'     => ['repartidores.ver'],
    ];
    $insRp = $pdo->prepare("INSERT IGNORE INTO rol_permisos (rol_id, permiso_id) VALUES (?, ?)");
    foreach ($asignaciones as $codigoRol => $codigos) {
        if (!isset($idsRol[$codigoRol])) {
            continue;
        }
        foreach ($codigos as $codigo) {
            if (isset($idsPermiso[$codigo])) {
                $insRp->execute([$idsRol[$codigoRol], $idsPermiso[$codigo]]);
            }
        }
    }

    // -----------------------------------------------------------------
    // Plantilla del mensaje al motorizado
    // -----------------------------------------------------------------
    // Se guarda en la configuración para que el negocio la reescriba sin
    // tocar código. Las etiquetas entre llaves se sustituyen al enviar.
    $e->agregarColumna('configuracion', 'mensaje_repartidor', "TEXT");

    $pdo->exec("UPDATE configuracion
                   SET mensaje_repartidor = "
             . $pdo->quote(
                 "Hola {repartidor}, tienes una entrega de {tienda}.\n\n"
               . "*Pedido:* {codigo}\n"
               . "*Recibe:* {recibe}\n"
               . "*Teléfono:* {telefono}\n"
               . "*Dirección:* {direccion}\n"
               . "*Zona:* {zona}\n"
               . "*Referencia:* {referencia}\n"
               . "*Ubicación:* {mapa}\n"
               . "*Fecha:* {fecha}\n\n"
               . "*Artículos:*\n{articulos}\n\n"
               . "*Cobrar al entregar:* {cobrar}\n"
               . "{notas}"
             ) . "
                 WHERE id = 1 AND (mensaje_repartidor IS NULL OR mensaje_repartidor = '')");
};
