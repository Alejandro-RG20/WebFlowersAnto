<?php
/**
 * Comercio: favoritos, carrito persistente, pedidos, comprobantes de pago,
 * historial de estados y cuentas bancarias.
 *
 * Los estados viven en su propia tabla para que el nombre, el color y el orden
 * se puedan cambiar desde el panel. El `codigo` sí es fijo: es lo que consulta
 * la lógica del negocio, así que la tabla es configurable en presentación y
 * estable en comportamiento.
 */

declare(strict_types=1);

return function (PDO $pdo, Esquema $e): void {

    // -----------------------------------------------------------------
    // Favoritos y carrito de usuarios con cuenta
    // -----------------------------------------------------------------
    $e->sql("CREATE TABLE IF NOT EXISTS favoritos (
        usuario_id  INT NOT NULL,
        producto_id INT NOT NULL,
        created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (usuario_id, producto_id),
        CONSTRAINT fk_fav_usuario  FOREIGN KEY (usuario_id)  REFERENCES usuarios(id)  ON DELETE CASCADE,
        CONSTRAINT fk_fav_producto FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $e->sql("CREATE TABLE IF NOT EXISTS carrito_items (
        usuario_id  INT NOT NULL,
        producto_id INT NOT NULL,
        cantidad    SMALLINT UNSIGNED NOT NULL DEFAULT 1,
        updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (usuario_id, producto_id),
        CONSTRAINT fk_carrito_usuario  FOREIGN KEY (usuario_id)  REFERENCES usuarios(id)  ON DELETE CASCADE,
        CONSTRAINT fk_carrito_producto FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // -----------------------------------------------------------------
    // Catálogo de estados
    // -----------------------------------------------------------------
    $e->sql("CREATE TABLE IF NOT EXISTS estados_pedido (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        tipo        ENUM('pedido','pago') NOT NULL,
        codigo      VARCHAR(40)  NOT NULL,
        nombre      VARCHAR(60)  NOT NULL,
        descripcion VARCHAR(255) NOT NULL DEFAULT '',
        color       VARCHAR(7)   NOT NULL DEFAULT '#8A7A7D',
        orden       SMALLINT     NOT NULL DEFAULT 0,
        es_final    TINYINT(1)   NOT NULL DEFAULT 0,
        activo      TINYINT(1)   NOT NULL DEFAULT 1,
        UNIQUE KEY uk_estado (tipo, codigo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $estados = [
        // tipo,   código,                 nombre,                     descripción,                                                  color,     orden, final
        ['pedido', 'pendiente',            'Pendiente',                'Recibimos el pedido y estamos a la espera del pago.',        '#B08968', 1,  0],
        ['pedido', 'pago_revision',        'Pago en revisión',         'Estamos verificando el comprobante enviado.',                '#C79A2E', 2,  0],
        ['pedido', 'confirmado',           'Pedido confirmado',        'El pago fue validado y el pedido entró en cola.',            '#4C8C6B', 3,  0],
        ['pedido', 'preparacion',          'En preparación',           'El arreglo se está montando en el taller.',                  '#7A6FB0', 4,  0],
        ['pedido', 'listo',                'Listo para entrega',       'El arreglo está terminado y esperando la salida.',           '#3E7CB1', 5,  0],
        ['pedido', 'enviado',              'Enviado',                  'El pedido va en camino a la dirección de entrega.',          '#2F6FA8', 6,  0],
        ['pedido', 'completado',           'Entregado',                'El pedido se entregó correctamente.',                        '#2F6B44', 7,  1],
        ['pedido', 'cancelado',            'Cancelado',                'El pedido fue cancelado.',                                   '#93313A', 8,  1],

        ['pago',   'no_aplica',            'Sin pago en línea',        'El pedido se coordina y se paga por WhatsApp.',              '#8A7A7D', 1,  1],
        ['pago',   'pendiente_comprobante','Pendiente de comprobante', 'Falta que el cliente envíe el comprobante de la transferencia.', '#B08968', 2, 0],
        ['pago',   'comprobante_recibido', 'Comprobante recibido',     'El comprobante llegó y está en cola de revisión.',           '#C79A2E', 3,  0],
        ['pago',   'en_revision',          'En revisión',              'Un miembro del equipo está verificando el comprobante.',     '#3E7CB1', 4,  0],
        ['pago',   'aprobado',             'Pago aprobado',            'La transferencia fue verificada.',                           '#2F6B44', 5,  1],
        ['pago',   'rechazado',            'Pago rechazado',           'El comprobante no se pudo validar. El cliente puede enviar otro.', '#93313A', 6, 0],
    ];
    $ins = $pdo->prepare(
        "INSERT INTO estados_pedido (tipo, codigo, nombre, descripcion, color, orden, es_final)
         VALUES (?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion)"
    );
    foreach ($estados as $fila) {
        $ins->execute($fila);
    }

    // -----------------------------------------------------------------
    // Pedidos
    // -----------------------------------------------------------------
    $e->sql("CREATE TABLE IF NOT EXISTS pedidos (
        id                INT AUTO_INCREMENT PRIMARY KEY,
        codigo            VARCHAR(20)  NOT NULL UNIQUE,
        usuario_id        INT          DEFAULT NULL,   -- NULL = pedido de invitado

        cliente_nombre    VARCHAR(120) NOT NULL,
        cliente_email     VARCHAR(150) NOT NULL DEFAULT '',
        cliente_telefono  VARCHAR(20)  NOT NULL,

        entrega_tipo      ENUM('domicilio','retiro') NOT NULL DEFAULT 'domicilio',
        entrega_nombre    VARCHAR(120) NOT NULL DEFAULT '',
        entrega_telefono  VARCHAR(20)  NOT NULL DEFAULT '',
        entrega_direccion VARCHAR(255) NOT NULL DEFAULT '',
        entrega_ciudad    VARCHAR(80)  NOT NULL DEFAULT '',
        entrega_referencia VARCHAR(255) NOT NULL DEFAULT '',
        entrega_fecha     DATE         DEFAULT NULL,
        entrega_franja    VARCHAR(40)  NOT NULL DEFAULT '',
        dedicatoria       VARCHAR(400) NOT NULL DEFAULT '',
        notas_cliente     VARCHAR(500) NOT NULL DEFAULT '',

        canal             ENUM('web','whatsapp','panel') NOT NULL DEFAULT 'web',
        metodo_pago       ENUM('transferencia','efectivo','whatsapp') NOT NULL DEFAULT 'transferencia',
        estado            VARCHAR(40)  NOT NULL DEFAULT 'pendiente',
        estado_pago       VARCHAR(40)  NOT NULL DEFAULT 'pendiente_comprobante',

        moneda            VARCHAR(5)   NOT NULL DEFAULT 'C\$',
        subtotal          DECIMAL(10,2) NOT NULL DEFAULT 0,
        envio             DECIMAL(10,2) NOT NULL DEFAULT 0,
        total             DECIMAL(10,2) NOT NULL DEFAULT 0,

        notas_internas    VARCHAR(1000) NOT NULL DEFAULT '',
        token_seguimiento CHAR(32)     NOT NULL DEFAULT '',
        ip                VARCHAR(45)  NOT NULL DEFAULT '',
        created_at        TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
        updated_at        TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

        CONSTRAINT fk_pedido_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL,
        INDEX idx_pedidos_estado  (estado, created_at),
        INDEX idx_pedidos_pago    (estado_pago, created_at),
        INDEX idx_pedidos_usuario (usuario_id, created_at),
        INDEX idx_pedidos_fecha   (created_at),
        INDEX idx_pedidos_email   (cliente_email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $e->sql("CREATE TABLE IF NOT EXISTS pedido_items (
        id             INT AUTO_INCREMENT PRIMARY KEY,
        pedido_id      INT NOT NULL,
        producto_id    INT DEFAULT NULL,
        nombre         VARCHAR(150)  NOT NULL,   -- copia del nombre al momento de comprar
        imagen         VARCHAR(255)  NOT NULL DEFAULT '',
        precio_unitario DECIMAL(10,2) NOT NULL DEFAULT 0,
        cantidad       SMALLINT UNSIGNED NOT NULL DEFAULT 1,
        subtotal       DECIMAL(10,2) NOT NULL DEFAULT 0,
        CONSTRAINT fk_item_pedido   FOREIGN KEY (pedido_id)   REFERENCES pedidos(id)   ON DELETE CASCADE,
        CONSTRAINT fk_item_producto FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE SET NULL,
        INDEX idx_items_pedido (pedido_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // -----------------------------------------------------------------
    // Comprobantes de pago
    // -----------------------------------------------------------------
    $e->sql("CREATE TABLE IF NOT EXISTS pedido_comprobantes (
        id             INT AUTO_INCREMENT PRIMARY KEY,
        pedido_id      INT NOT NULL,
        archivo        VARCHAR(120) NOT NULL,   -- nombre dentro de storage/comprobantes
        nombre_original VARCHAR(150) NOT NULL DEFAULT '',
        mime           VARCHAR(60)  NOT NULL DEFAULT '',
        tamano         INT          NOT NULL DEFAULT 0,
        hash_sha256    CHAR(64)     NOT NULL DEFAULT '',
        referencia     VARCHAR(80)  NOT NULL DEFAULT '',  -- número de transacción indicado por el cliente
        banco          VARCHAR(80)  NOT NULL DEFAULT '',
        monto          DECIMAL(10,2) NOT NULL DEFAULT 0,
        estado         ENUM('recibido','en_revision','aprobado','rechazado') NOT NULL DEFAULT 'recibido',
        motivo_rechazo VARCHAR(400) NOT NULL DEFAULT '',
        revisado_por   INT DEFAULT NULL,
        revisado_en    DATETIME DEFAULT NULL,
        subido_en      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_comp_pedido  FOREIGN KEY (pedido_id)    REFERENCES pedidos(id)  ON DELETE CASCADE,
        CONSTRAINT fk_comp_revisor FOREIGN KEY (revisado_por) REFERENCES usuarios(id) ON DELETE SET NULL,
        INDEX idx_comprobantes_pedido (pedido_id, subido_en),
        INDEX idx_comprobantes_estado (estado, subido_en)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // -----------------------------------------------------------------
    // Historial de estados
    // -----------------------------------------------------------------
    $e->sql("CREATE TABLE IF NOT EXISTS pedido_historial (
        id             INT AUTO_INCREMENT PRIMARY KEY,
        pedido_id      INT NOT NULL,
        tipo           ENUM('pedido','pago','nota') NOT NULL DEFAULT 'pedido',
        estado_anterior VARCHAR(40) NOT NULL DEFAULT '',
        estado_nuevo   VARCHAR(40) NOT NULL DEFAULT '',
        nota           VARCHAR(500) NOT NULL DEFAULT '',
        usuario_id     INT DEFAULT NULL,
        usuario_texto  VARCHAR(150) NOT NULL DEFAULT 'Sistema',
        created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_hist_pedido  FOREIGN KEY (pedido_id)  REFERENCES pedidos(id)  ON DELETE CASCADE,
        CONSTRAINT fk_hist_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL,
        INDEX idx_historial_pedido (pedido_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // -----------------------------------------------------------------
    // Cuentas bancarias para la transferencia
    // -----------------------------------------------------------------
    $e->sql("CREATE TABLE IF NOT EXISTS cuentas_bancarias (
        id             INT AUTO_INCREMENT PRIMARY KEY,
        banco          VARCHAR(80)  NOT NULL,
        titular        VARCHAR(120) NOT NULL,
        numero_cuenta  VARCHAR(60)  NOT NULL,
        tipo_cuenta    VARCHAR(40)  NOT NULL DEFAULT 'Ahorro',
        moneda         VARCHAR(20)  NOT NULL DEFAULT 'Córdobas',
        identificacion VARCHAR(40)  NOT NULL DEFAULT '',
        notas          VARCHAR(255) NOT NULL DEFAULT '',
        orden          SMALLINT     NOT NULL DEFAULT 0,
        activo         TINYINT(1)   NOT NULL DEFAULT 1,
        created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_cuentas_orden (activo, orden)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
};
