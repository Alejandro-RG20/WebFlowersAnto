<?php
/**
 * Cupones de descuento.
 *
 * El descuento se guarda en el pedido como un importe propio, no restado del
 * subtotal: al mirar un pedido viejo hay que poder ver cuánto costaba, cuánto
 * se rebajó y con qué cupón. Restarlo del subtotal borraría esa información.
 *
 * `cupon_usos` lleva el registro de cada canje. Sin ella no se puede limitar
 * «un cupón por cliente», que es justo lo que evita que un código de
 * bienvenida se use veinte veces desde el mismo correo.
 */

declare(strict_types=1);

return function (PDO $pdo, Esquema $e): void {

    $e->sql("CREATE TABLE IF NOT EXISTS cupones (
        id               INT AUTO_INCREMENT PRIMARY KEY,
        codigo           VARCHAR(40)  NOT NULL,
        descripcion      VARCHAR(255) NOT NULL DEFAULT '',
        tipo             ENUM('porcentaje','fijo','envio_gratis') NOT NULL DEFAULT 'porcentaje',
        valor            DECIMAL(10,2) NOT NULL DEFAULT 0,
        compra_minima    DECIMAL(10,2) NOT NULL DEFAULT 0,
        descuento_maximo DECIMAL(10,2) NOT NULL DEFAULT 0,
        usos_maximos     INT NOT NULL DEFAULT 0,
        usos_por_cliente INT NOT NULL DEFAULT 1,
        usos             INT NOT NULL DEFAULT 0,
        fecha_inicio     DATE DEFAULT NULL,
        fecha_fin        DATE DEFAULT NULL,
        activo           TINYINT(1) NOT NULL DEFAULT 1,
        created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_cupon_codigo (codigo),
        INDEX idx_cupones_vigencia (activo, fecha_inicio, fecha_fin)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Un canje por fila. El correo se guarda además del usuario porque los
    // invitados no tienen cuenta y también hay que poder limitarlos.
    $e->sql("CREATE TABLE IF NOT EXISTS cupon_usos (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        cupon_id   INT NOT NULL,
        pedido_id  INT DEFAULT NULL,
        usuario_id INT DEFAULT NULL,
        email      VARCHAR(150) NOT NULL DEFAULT '',
        descuento  DECIMAL(10,2) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_uso_cupon  FOREIGN KEY (cupon_id)  REFERENCES cupones(id) ON DELETE CASCADE,
        CONSTRAINT fk_uso_pedido FOREIGN KEY (pedido_id) REFERENCES pedidos(id) ON DELETE SET NULL,
        INDEX idx_uso_usuario (cupon_id, usuario_id),
        INDEX idx_uso_email   (cupon_id, email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $e->agregarColumna('pedidos', 'cupon_id',     "INT DEFAULT NULL");
    // El código se copia al pedido: si el cupón se borra, el pedido sigue
    // diciendo con cuál se hizo el descuento.
    $e->agregarColumna('pedidos', 'cupon_codigo', "VARCHAR(40) NOT NULL DEFAULT ''");
    $e->agregarColumna('pedidos', 'descuento',    "DECIMAL(10,2) NOT NULL DEFAULT 0");

    if (!$e->indiceExiste('pedidos', 'fk_pedido_cupon')) {
        $e->sql("ALTER TABLE pedidos ADD CONSTRAINT fk_pedido_cupon
                 FOREIGN KEY (cupon_id) REFERENCES cupones(id) ON DELETE SET NULL");
    }

    // -----------------------------------------------------------------
    // Permisos
    // -----------------------------------------------------------------
    $permisos = [
        ['cupones.ver',       'Ver los cupones de descuento',  'pedidos'],
        ['cupones.gestionar', 'Crear y editar cupones',        'pedidos'],
    ];
    $ins = $pdo->prepare("INSERT INTO permisos (codigo, nombre, modulo) VALUES (?, ?, ?)
                          ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), modulo = VALUES(modulo)");
    foreach ($permisos as $p) {
        $ins->execute($p);
    }

    $idsRol     = $pdo->query("SELECT codigo, id FROM roles")->fetchAll(PDO::FETCH_KEY_PAIR);
    $idsPermiso = $pdo->query("SELECT codigo, id FROM permisos")->fetchAll(PDO::FETCH_KEY_PAIR);
    $asignaciones = [
        'super_admin' => ['cupones.ver', 'cupones.gestionar'],
        'admin'       => ['cupones.ver', 'cupones.gestionar'],
        'pedidos'     => ['cupones.ver'],
        'auditor'     => ['cupones.ver'],
    ];
    $insRp = $pdo->prepare("INSERT IGNORE INTO rol_permisos (rol_id, permiso_id) VALUES (?, ?)");
    foreach ($asignaciones as $rol => $codigos) {
        if (!isset($idsRol[$rol])) {
            continue;
        }
        foreach ($codigos as $c) {
            if (isset($idsPermiso[$c])) {
                $insRp->execute([$idsRol[$rol], $idsPermiso[$c]]);
            }
        }
    }

    // Interruptor general: se puede apagar el campo del checkout sin borrar
    // los cupones que ya existan.
    $e->agregarColumna('configuracion', 'cupones_activos', "TINYINT(1) NOT NULL DEFAULT 1");
};
