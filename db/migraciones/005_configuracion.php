<?php
/**
 * Configuración ampliada: pedidos, envío, créditos del desarrollador y SEO.
 * Y la tabla de respaldos.
 */

declare(strict_types=1);

return function (PDO $pdo, Esquema $e): void {

    foreach ([
        // Pedidos y envío
        'pedido_web_activo'       => "TINYINT(1) NOT NULL DEFAULT 1",
        'pedido_whatsapp_activo'  => "TINYINT(1) NOT NULL DEFAULT 1",
        // whatsapp_pedidos vacío = se usa el número general de contacto
        'whatsapp_pedidos'        => "VARCHAR(20) NOT NULL DEFAULT ''",
        'costo_envio'             => "DECIMAL(10,2) NOT NULL DEFAULT 0",
        // envio_gratis_desde en 0 = nunca hay envío gratis
        'envio_gratis_desde'      => "DECIMAL(10,2) NOT NULL DEFAULT 0",
        'ciudades_entrega'        => "VARCHAR(500) NOT NULL DEFAULT 'Managua, Masaya, Granada, León, Tipitapa'",
        'franjas_entrega'         => "VARCHAR(300) NOT NULL DEFAULT 'Mañana (8:00 - 12:00), Tarde (12:00 - 17:00)'",
        'permitir_retiro'         => "TINYINT(1) NOT NULL DEFAULT 1",
        'permitir_invitado'       => "TINYINT(1) NOT NULL DEFAULT 1",
        'pago_efectivo_activo'    => "TINYINT(1) NOT NULL DEFAULT 1",
        'instrucciones_pago'      => "TEXT",

        // Créditos del desarrollador (ANDRODEV)
        'dev_activo'      => "TINYINT(1) NOT NULL DEFAULT 1",
        'dev_nombre'      => "VARCHAR(80)  NOT NULL DEFAULT 'ANDRODEV'",
        'dev_descripcion' => "VARCHAR(200) NOT NULL DEFAULT 'Desarrollo de software a medida'",
        'dev_logo'        => "VARCHAR(255) NOT NULL DEFAULT ''",
        'dev_url'         => "VARCHAR(255) NOT NULL DEFAULT ''",

        // SEO
        'meta_descripcion' => "VARCHAR(300) NOT NULL DEFAULT 'Arreglos florales hechos a mano en Managua. Ramos, cajas y arreglos de temporada con entrega el mismo día.'",
        'og_imagen'        => "VARCHAR(255) NOT NULL DEFAULT ''",
    ] as $columna => $definicion) {
        $e->agregarColumna('configuracion', $columna, $definicion);
    }

    $pdo->exec("UPDATE configuracion
                   SET instrucciones_pago = 'Realiza la transferencia por el monto exacto del pedido y sube la captura o el PDF del comprobante. Un miembro del equipo lo revisa y te confirmamos por correo y por WhatsApp.'
                 WHERE instrucciones_pago IS NULL OR instrucciones_pago = ''");

    // -----------------------------------------------------------------
    // Respaldos de base de datos
    // -----------------------------------------------------------------
    $e->sql("CREATE TABLE IF NOT EXISTS respaldos (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        archivo      VARCHAR(160) NOT NULL UNIQUE,
        nombre       VARCHAR(160) NOT NULL DEFAULT '',
        tamano       BIGINT NOT NULL DEFAULT 0,
        tipo         ENUM('manual','subido','pre_restauracion') NOT NULL DEFAULT 'manual',
        estado       ENUM('completo','error','restaurado') NOT NULL DEFAULT 'completo',
        hash_sha256  CHAR(64) NOT NULL DEFAULT '',
        tablas       INT NOT NULL DEFAULT 0,
        notas        VARCHAR(400) NOT NULL DEFAULT '',
        creado_por   INT DEFAULT NULL,
        creado_texto VARCHAR(150) NOT NULL DEFAULT '',
        created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_respaldo_usuario FOREIGN KEY (creado_por) REFERENCES usuarios(id) ON DELETE SET NULL,
        INDEX idx_respaldos_fecha (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
};
