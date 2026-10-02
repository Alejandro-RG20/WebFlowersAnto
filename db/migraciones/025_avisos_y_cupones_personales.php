<?php
/**
 * Avisos de la tienda a un cliente y cupones personales.
 *
 * `notificaciones`: lo que el panel le manda a un cliente desde su ficha
 * (un aviso, una sugerencia, una advertencia o una felicitación). El cliente
 * lo ve en «Mis avisos» y, si se pidió, le llega además por correo. El texto
 * se guarda tal cual se escribió y se pinta siempre escapado: es texto, no HTML.
 *
 * `cupones.usuario_id`: un cupón con dueño solo lo puede canjear esa cuenta,
 * con la sesión iniciada. Es lo que permite regalar un descuento de
 * cumpleaños sin que el código sirva a quien se lo pase. NULL = cualquiera,
 * como hasta ahora. Si la cuenta se borra, su cupón personal se borra con
 * ella: dejarlo con el dueño a NULL lo convertiría en un cupón para todos.
 *
 * Solo añade. Sin esta migración la sección de avisos no aparece.
 */

declare(strict_types=1);

return function (PDO $pdo, Esquema $e): void {
    $e->agregarColumna('cupones', 'usuario_id', "INT NULL DEFAULT NULL AFTER activo");
    $e->agregarIndice('cupones', 'idx_cupones_usuario', '(usuario_id)');
    // La restricción usa el índice de arriba, así que no hay un índice con su
    // nombre: se comprueba la propia restricción para poder repetir esto.
    $existe = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
          WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'cupones'
            AND CONSTRAINT_NAME = 'fk_cupon_usuario'"
    )->fetchColumn();
    if (!(int)$existe) {
        $e->sql("ALTER TABLE cupones ADD CONSTRAINT fk_cupon_usuario
                 FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE");
    }

    $e->sql("CREATE TABLE IF NOT EXISTS notificaciones (
        id             INT AUTO_INCREMENT PRIMARY KEY,
        usuario_id     INT NOT NULL,
        tipo           ENUM('aviso','sugerencia','advertencia','cumpleanos') NOT NULL DEFAULT 'aviso',
        titulo         VARCHAR(120) NOT NULL,
        mensaje        TEXT NOT NULL,
        cupon_id       INT NULL DEFAULT NULL,
        enviado_por    INT NULL DEFAULT NULL,
        por_correo     TINYINT(1) NOT NULL DEFAULT 0,
        correo_enviado TINYINT(1) NOT NULL DEFAULT 0,
        leida_en       DATETIME NULL DEFAULT NULL,
        created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_notif_usuario FOREIGN KEY (usuario_id)  REFERENCES usuarios(id) ON DELETE CASCADE,
        CONSTRAINT fk_notif_cupon   FOREIGN KEY (cupon_id)    REFERENCES cupones(id)  ON DELETE SET NULL,
        CONSTRAINT fk_notif_autor   FOREIGN KEY (enviado_por) REFERENCES usuarios(id) ON DELETE SET NULL,
        INDEX idx_notif_usuario (usuario_id, leida_en),
        INDEX idx_notif_fecha   (usuario_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
};
