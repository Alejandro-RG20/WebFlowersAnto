<?php
/**
 * Zonas de envío con precio propio, libreta de direcciones del cliente,
 * enlace de ubicación para el repartidor y avisos por correo configurables.
 *
 * Las zonas sustituyen a la lista suelta de `ciudades_entrega`: un texto
 * separado por comas no podía llevar un precio por destino. Las ciudades que
 * ya estuvieran configuradas se convierten en zonas con el costo de envío
 * vigente, así que ninguna instalación pierde su configuración ni se queda
 * sin destinos al actualizar.
 */

declare(strict_types=1);

return function (PDO $pdo, Esquema $e): void {

    // -----------------------------------------------------------------
    // Zonas de envío
    // -----------------------------------------------------------------
    $e->sql("CREATE TABLE IF NOT EXISTS zonas_envio (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        nombre      VARCHAR(100)  NOT NULL,
        descripcion VARCHAR(255)  NOT NULL DEFAULT '',
        costo       DECIMAL(10,2) NOT NULL DEFAULT 0,
        es_managua  TINYINT(1)    NOT NULL DEFAULT 1,
        orden       SMALLINT      NOT NULL DEFAULT 0,
        activo      TINYINT(1)    NOT NULL DEFAULT 1,
        created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_zona_nombre (nombre),
        INDEX idx_zonas_orden (activo, es_managua, orden)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    if ((int)$pdo->query("SELECT COUNT(*) FROM zonas_envio")->fetchColumn() === 0) {
        $costoActual = (float)($pdo->query("SELECT costo_envio FROM configuracion WHERE id = 1")->fetchColumn() ?: 0);
        $ciudades    = (string)($pdo->query("SELECT ciudades_entrega FROM configuracion WHERE id = 1")->fetchColumn() ?: '');

        $insertar = $pdo->prepare(
            "INSERT INTO zonas_envio (nombre, descripcion, costo, es_managua, orden) VALUES (?,?,?,?,?)"
        );

        // Las ciudades que ya estaban configuradas se conservan como zonas.
        $orden = 0;
        $creadas = [];
        foreach (array_filter(array_map('trim', explode(',', $ciudades))) as $ciudad) {
            $esManagua = mb_stripos($ciudad, 'managua') !== false ? 1 : 0;
            $insertar->execute([
                mb_substr($ciudad, 0, 100),
                $esManagua ? 'Zona dentro de Managua' : 'Entrega fuera de Managua',
                $costoActual, $esManagua, $orden += 10,
            ]);
            $creadas[] = mb_strtolower($ciudad);
        }

        // Y se añaden unas zonas de ejemplo para que se vea cómo se usa el
        // precio por destino. Todas quedan editables desde el panel.
        $ejemplo = [
            ['Managua — zona centro',  'Bolonia, Altamira, Centroamérica y alrededores.', $costoActual, 1],
            ['Managua — zona norte',   'Las Brisas, Linda Vista, Ciudad Sandino.',        $costoActual, 1],
            ['Managua — zona sur',     'Carretera Masaya, Las Colinas, Santo Domingo.',   $costoActual, 1],
            ['Fuera de Managua',       'Consultamos el costo exacto antes de confirmar.', $costoActual, 0],
        ];
        foreach ($ejemplo as [$nombre, $desc, $costo, $esManagua]) {
            if (!in_array(mb_strtolower($nombre), $creadas, true)) {
                $insertar->execute([$nombre, $desc, $costo, $esManagua, $orden += 10]);
            }
        }
    }

    // -----------------------------------------------------------------
    // Pedidos: zona, enlace de ubicación y aviso al equipo
    // -----------------------------------------------------------------
    $e->agregarColumna('pedidos', 'zona_envio_id',     "INT DEFAULT NULL");
    // El nombre se copia al crear el pedido: si la zona se renombra o se borra
    // más adelante, el pedido sigue diciendo a dónde se llevó.
    $e->agregarColumna('pedidos', 'zona_envio_nombre', "VARCHAR(100) NOT NULL DEFAULT ''");
    $e->agregarColumna('pedidos', 'entrega_mapa_url',  "VARCHAR(500) NOT NULL DEFAULT ''");

    if (!$e->indiceExiste('pedidos', 'fk_pedido_zona')) {
        try {
            $pdo->exec("ALTER TABLE pedidos ADD CONSTRAINT fk_pedido_zona
                        FOREIGN KEY (zona_envio_id) REFERENCES zonas_envio(id) ON DELETE SET NULL");
        } catch (PDOException) {
            // Ya existe con otro nombre: no es un fallo.
        }
    }

    // Los pedidos anteriores conservan la ciudad que se escribió entonces.
    $pdo->exec("UPDATE pedidos SET zona_envio_nombre = entrega_ciudad
                 WHERE zona_envio_nombre = '' AND entrega_ciudad <> ''");

    // -----------------------------------------------------------------
    // Libreta de direcciones del cliente
    // -----------------------------------------------------------------
    $e->sql("CREATE TABLE IF NOT EXISTS direcciones_usuario (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        usuario_id    INT NOT NULL,
        etiqueta      VARCHAR(60)  NOT NULL DEFAULT 'Mi dirección',
        nombre_recibe VARCHAR(120) NOT NULL DEFAULT '',
        telefono      VARCHAR(20)  NOT NULL DEFAULT '',
        direccion     VARCHAR(255) NOT NULL,
        referencia    VARCHAR(255) NOT NULL DEFAULT '',
        mapa_url      VARCHAR(500) NOT NULL DEFAULT '',
        zona_envio_id INT DEFAULT NULL,
        predeterminada TINYINT(1)  NOT NULL DEFAULT 0,
        created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_dir_usuario FOREIGN KEY (usuario_id)    REFERENCES usuarios(id)    ON DELETE CASCADE,
        CONSTRAINT fk_dir_zona    FOREIGN KEY (zona_envio_id) REFERENCES zonas_envio(id) ON DELETE SET NULL,
        INDEX idx_direcciones_usuario (usuario_id, predeterminada)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // -----------------------------------------------------------------
    // Mensaje propio en el correo de cada estado
    // -----------------------------------------------------------------
    $e->agregarColumna('estados_pedido', 'mensaje_correo', "TEXT");
    $e->agregarColumna('estados_pedido', 'avisar_cliente', "TINYINT(1) NOT NULL DEFAULT 1");

    $mensajes = [
        ['pedido', 'pendiente',
         "Recibimos tu pedido y ya está anotado. En cuanto registremos el pago empezamos a prepararlo.",
         1],
        ['pedido', 'pago_revision',
         "Tenemos tu comprobante. Una persona del equipo lo está verificando; te avisamos en cuanto quede confirmado.",
         1],
        ['pedido', 'confirmado',
         "¡Todo listo! Verificamos tu pago y tu pedido entró en la cola del taller.",
         1],
        ['pedido', 'preparacion',
         "Tu arreglo se está montando a mano en el taller, con flores frescas de hoy.",
         1],
        ['pedido', 'listo',
         "Tu arreglo ya está terminado y esperando la salida. Muy pronto va en camino.",
         1],
        ['pedido', 'enviado',
         "Tu pedido va en camino a la dirección de entrega. Ten el teléfono a mano por si el repartidor necesita ubicarte.",
         1],
        ['pedido', 'completado',
         "Tu pedido fue entregado. Gracias por confiar en nosotros: nos encantaría ver la foto del arreglo en su lugar.",
         1],
        ['pedido', 'cancelado',
         "Tu pedido quedó cancelado. Si fue un error o quieres retomarlo, escríbenos y lo resolvemos.",
         1],
    ];
    $up = $pdo->prepare(
        "UPDATE estados_pedido SET mensaje_correo = ?, avisar_cliente = ?
          WHERE tipo = ? AND codigo = ? AND (mensaje_correo IS NULL OR mensaje_correo = '')"
    );
    foreach ($mensajes as [$tipo, $codigo, $mensaje, $avisar]) {
        $up->execute([$mensaje, $avisar, $tipo, $codigo]);
    }

    // -----------------------------------------------------------------
    // Aviso al equipo cuando entra un pedido
    // -----------------------------------------------------------------
    $e->agregarColumna('configuracion', 'email_avisos',     "VARCHAR(150) NOT NULL DEFAULT ''");
    $e->agregarColumna('configuracion', 'avisar_pedidos',   "TINYINT(1) NOT NULL DEFAULT 1");
    $e->agregarColumna('configuracion', 'avisar_pagos',     "TINYINT(1) NOT NULL DEFAULT 1");
    $e->agregarColumna('configuracion', 'pedir_mapa_url',   "TINYINT(1) NOT NULL DEFAULT 1");

    // Si no se indica otro, los avisos van al correo de contacto de la tienda.
    $pdo->exec("UPDATE configuracion SET email_avisos = email_contacto
                 WHERE email_avisos = '' AND email_contacto <> ''");
};
