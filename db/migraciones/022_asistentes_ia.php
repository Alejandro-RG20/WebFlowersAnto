<?php
/**
 * Asistentes de IA: registro de acciones y acciones pendientes de confirmar.
 *
 * `ai_action_logs` guarda qué hizo cada asistente y cuánto costó, para poder
 * responder «¿quién cambió este precio?» y «¿cuánto gastamos en IA este
 * mes?». No guarda los mensajes de la conversación: lo que escribe un
 * cliente puede traer datos personales, y aquí no hacen falta. Solo el nombre
 * de la herramienta, sobre qué recurso actuó, cómo terminó y los tokens.
 *
 * `ai_pending_actions` es la bandeja de lo que el asistente del panel propone
 * y todavía no se ha hecho. El asistente nunca cambia un precio, un stock o
 * un pedido por su cuenta: deja aquí la propuesta con el antes y el después, y
 * solo la persona que la pidió puede confirmarla, dentro de un plazo. Al
 * confirmar se vuelven a comprobar el permiso y que el dato no haya cambiado
 * entre tanto.
 *
 * Las columnas con JSON son TEXT: en MariaDB `JSON` es un alias de LONGTEXT y
 * algunos hostings compartidos todavía traen versiones que no lo aceptan.
 */

declare(strict_types=1);

return function (PDO $pdo, Esquema $e): void {
    if (!$e->tablaExiste('ai_action_logs')) {
        $pdo->exec(
            "CREATE TABLE ai_action_logs (
                id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                usuario_id     INT NULL,
                agente         ENUM('cliente','admin') NOT NULL,
                accion         VARCHAR(60)  NOT NULL,
                herramienta    VARCHAR(60)  NULL,
                recurso_tipo   VARCHAR(40)  NULL,
                recurso_id     VARCHAR(64)  NULL,
                estado         ENUM('ok','error','denegado','propuesta','confirmada',
                                    'cancelada','caducada','rechazada') NOT NULL DEFAULT 'ok',
                detalle        VARCHAR(255) NULL,
                tokens_entrada INT UNSIGNED NOT NULL DEFAULT 0,
                tokens_salida  INT UNSIGNED NOT NULL DEFAULT 0,
                tokens_cache   INT UNSIGNED NOT NULL DEFAULT 0,
                ms             INT UNSIGNED NOT NULL DEFAULT 0,
                ip             VARCHAR(45)  NOT NULL DEFAULT '',
                created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_ai_logs_fecha (created_at),
                KEY idx_ai_logs_usuario (usuario_id, created_at),
                KEY idx_ai_logs_agente (agente, estado, created_at),
                CONSTRAINT fk_ai_logs_usuario FOREIGN KEY (usuario_id)
                    REFERENCES usuarios (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    if (!$e->tablaExiste('ai_pending_actions')) {
        $pdo->exec(
            "CREATE TABLE ai_pending_actions (
                id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
                usuario_id    INT NOT NULL,
                tipo          VARCHAR(40)  NOT NULL,
                permiso       VARCHAR(60)  NOT NULL,
                recurso_tipo  VARCHAR(40)  NOT NULL,
                recurso_id    VARCHAR(64)  NOT NULL,
                antes         TEXT         NOT NULL,
                despues       TEXT         NOT NULL,
                resumen       VARCHAR(255) NOT NULL,
                estado        ENUM('pendiente','ejecutada','cancelada','caducada','fallida')
                              NOT NULL DEFAULT 'pendiente',
                resultado     VARCHAR(255) NULL,
                expira_en     DATETIME     NOT NULL,
                resuelta_en   DATETIME     NULL,
                created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_ai_pend_usuario (usuario_id, estado),
                CONSTRAINT fk_ai_pend_usuario FOREIGN KEY (usuario_id)
                    REFERENCES usuarios (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }
};
