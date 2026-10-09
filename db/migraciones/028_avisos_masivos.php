<?php
/**
 * Avisos a muchos clientes a la vez y promociones por correo.
 *
 * `campanas_avisos`: cada envío masivo desde el panel (a los clientes
 * seleccionados o a todos los de un filtro). Cada destinatario recibe su
 * propia fila en `notificaciones`, igual que un aviso individual, con
 * `campana_id` apuntando aquí: así sale en su «Mis avisos» y el contador.
 *
 * Los correos no se mandan en la misma petición que crea la campaña: con
 * cientos de clientes la página se cortaría a medias y el servidor de correo
 * del hosting los rechazaría por ráfaga. Se mandan en tandas pequeñas desde
 * el panel, mientras se ve el avance. Esta tabla junto con
 * `notificaciones.correo_enviado` / `correo_intentos` es esa cola: si se
 * cierra la pestaña, el envío sigue donde se quedó la próxima vez.
 *
 * `usuarios.acepta_promociones`: las promociones por correo solo van a quien
 * no se ha dado de baja (cada correo de promoción lleva el enlace para
 * hacerlo, con `usuarios.token_baja`, un valor aleatorio por cliente). Los
 * avisos sobre pedidos o la cuenta no dependen de esto. El aviso dentro de la
 * web llega siempre.
 */

declare(strict_types=1);

return function (PDO $pdo, Esquema $e): void {
    $e->modificarColumna(
        'notificaciones',
        'tipo',
        "ENUM('aviso','sugerencia','advertencia','cumpleanos','promocion') NOT NULL DEFAULT 'aviso'"
    );

    $e->sql("CREATE TABLE IF NOT EXISTS campanas_avisos (
        id             INT AUTO_INCREMENT PRIMARY KEY,
        tipo           VARCHAR(20)  NOT NULL,
        titulo         VARCHAR(120) NOT NULL,
        mensaje        TEXT         NOT NULL,
        por_correo     TINYINT(1)   NOT NULL DEFAULT 0,
        destinatarios  INT          NOT NULL DEFAULT 0,
        con_correo     INT          NOT NULL DEFAULT 0,
        enviado_por    INT          NULL,
        created_at     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
        terminada_en   DATETIME     NULL,
        CONSTRAINT fk_campana_autor FOREIGN KEY (enviado_por) REFERENCES usuarios(id) ON DELETE SET NULL,
        INDEX idx_campanas_fecha (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $e->agregarColumna('notificaciones', 'campana_id', "INT NULL DEFAULT NULL AFTER cupon_id");
    $e->agregarColumna('notificaciones', 'correo_intentos', "TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER correo_enviado");
    // La cola: los pendientes de una campaña se buscan por aquí.
    $e->agregarIndice('notificaciones', 'idx_notif_cola', '(campana_id, correo_enviado, por_correo)');
    $existe = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
          WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'notificaciones'
            AND CONSTRAINT_NAME = 'fk_notif_campana'"
    )->fetchColumn();
    if (!(int)$existe) {
        $e->sql("ALTER TABLE notificaciones ADD CONSTRAINT fk_notif_campana
                 FOREIGN KEY (campana_id) REFERENCES campanas_avisos(id) ON DELETE SET NULL");
    }

    $e->agregarColumna('usuarios', 'acepta_promociones', "TINYINT(1) NOT NULL DEFAULT 1");
    $e->agregarColumna('usuarios', 'token_baja', "CHAR(32) NULL DEFAULT NULL");
    $e->agregarIndice('usuarios', 'uq_usuarios_token_baja', '(token_baja)', true);
};
