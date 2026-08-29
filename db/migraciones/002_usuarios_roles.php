<?php
/**
 * Cuentas de cliente, roles y permisos (RBAC), recuperación de contraseña,
 * limitación de intentos y auditoría.
 *
 * La tabla `usuarios` pasa a albergar tanto al personal como a los clientes.
 * Los separa el rol: quien tenga un rol con `es_personal = 1` puede entrar al
 * panel. Mantener una sola tabla evita duplicar el login, el hash de la
 * contraseña, la recuperación y el enlace con los pedidos.
 */

declare(strict_types=1);

return function (PDO $pdo, Esquema $e): void {

    // -----------------------------------------------------------------
    // Roles y permisos
    // -----------------------------------------------------------------
    $e->sql("CREATE TABLE IF NOT EXISTS roles (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        codigo      VARCHAR(40)  NOT NULL UNIQUE,
        nombre      VARCHAR(80)  NOT NULL,
        descripcion VARCHAR(255) DEFAULT '',
        es_personal TINYINT(1)   NOT NULL DEFAULT 1,  -- 1 = puede entrar al panel
        es_sistema  TINYINT(1)   NOT NULL DEFAULT 0,  -- 1 = no se puede borrar
        created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $e->sql("CREATE TABLE IF NOT EXISTS permisos (
        id     INT AUTO_INCREMENT PRIMARY KEY,
        codigo VARCHAR(60) NOT NULL UNIQUE,
        nombre VARCHAR(120) NOT NULL,
        modulo VARCHAR(40)  NOT NULL,
        INDEX idx_permisos_modulo (modulo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $e->sql("CREATE TABLE IF NOT EXISTS rol_permisos (
        rol_id     INT NOT NULL,
        permiso_id INT NOT NULL,
        PRIMARY KEY (rol_id, permiso_id),
        CONSTRAINT fk_rp_rol     FOREIGN KEY (rol_id)     REFERENCES roles(id)    ON DELETE CASCADE,
        CONSTRAINT fk_rp_permiso FOREIGN KEY (permiso_id) REFERENCES permisos(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $permisos = [
        ['panel.acceder',            'Entrar al panel',                     'panel'],
        ['dashboard.ver',            'Ver el resumen y las estadísticas',   'panel'],

        ['productos.ver',            'Ver productos',                       'productos'],
        ['productos.crear',          'Crear productos',                     'productos'],
        ['productos.editar',         'Editar productos',                    'productos'],
        ['productos.eliminar',       'Eliminar productos',                  'productos'],
        ['categorias.gestionar',     'Gestionar categorías',                'productos'],
        ['temporadas.gestionar',     'Gestionar temporadas',                'productos'],
        ['galeria.gestionar',        'Gestionar galería y videos',          'productos'],

        ['pedidos.ver',              'Ver pedidos',                         'pedidos'],
        ['pedidos.editar',           'Cambiar el estado de un pedido',      'pedidos'],
        ['pedidos.cancelar',         'Cancelar pedidos',                    'pedidos'],
        ['pagos.revisar',            'Revisar comprobantes de pago',        'pedidos'],
        ['pagos.aprobar',            'Aprobar o rechazar pagos',            'pedidos'],

        ['clientes.ver',             'Ver clientes',                        'usuarios'],
        ['clientes.editar',          'Editar clientes',                     'usuarios'],
        ['empleados.ver',            'Ver empleados',                       'usuarios'],
        ['empleados.gestionar',      'Crear y editar empleados',            'usuarios'],
        ['roles.gestionar',          'Gestionar roles y permisos',          'usuarios'],

        ['auditoria.ver',            'Consultar la auditoría',              'sistema'],
        ['configuracion.ver',        'Ver la configuración',                'sistema'],
        ['configuracion.editar',     'Modificar la configuración',          'sistema'],
        ['respaldos.ver',            'Ver los respaldos',                   'sistema'],
        ['respaldos.crear',          'Crear y descargar respaldos',         'sistema'],
        ['respaldos.restaurar',      'Restaurar un respaldo',               'sistema'],
        ['sistema.migrar',           'Aplicar migraciones de base de datos','sistema'],
    ];
    $ins = $pdo->prepare("INSERT INTO permisos (codigo, nombre, modulo) VALUES (?, ?, ?)
                          ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), modulo = VALUES(modulo)");
    foreach ($permisos as $p) {
        $ins->execute($p);
    }

    $roles = [
        ['super_admin',  'Super administrador', 'Acceso total, incluidos respaldos y restauración.', 1, 1],
        ['admin',        'Administrador',       'Gestión general del negocio, sin restaurar respaldos.', 1, 1],
        ['pedidos',      'Empleado de pedidos', 'Gestiona pedidos, pagos y comprobantes.', 1, 1],
        ['productos',    'Empleado de productos', 'Gestiona el catálogo, la galería y las temporadas.', 1, 1],
        ['auditor',      'Auditor',             'Solo lectura: consulta pedidos, clientes y auditoría.', 1, 1],
        ['cliente',      'Cliente',             'Cuenta de cliente de la tienda.', 0, 1],
    ];
    $insRol = $pdo->prepare("INSERT INTO roles (codigo, nombre, descripcion, es_personal, es_sistema)
                             VALUES (?, ?, ?, ?, ?)
                             ON DUPLICATE KEY UPDATE nombre = VALUES(nombre),
                                                     descripcion = VALUES(descripcion),
                                                     es_personal = VALUES(es_personal),
                                                     es_sistema = VALUES(es_sistema)");
    foreach ($roles as $r) {
        $insRol->execute($r);
    }

    $idsRol     = $pdo->query("SELECT codigo, id FROM roles")->fetchAll(PDO::FETCH_KEY_PAIR);
    $idsPermiso = $pdo->query("SELECT codigo, id FROM permisos")->fetchAll(PDO::FETCH_KEY_PAIR);
    $todos      = array_keys($idsPermiso);

    $asignaciones = [
        'super_admin' => $todos,
        'admin'       => array_values(array_diff($todos, ['respaldos.restaurar', 'sistema.migrar', 'roles.gestionar'])),
        'pedidos'     => ['panel.acceder', 'dashboard.ver', 'pedidos.ver', 'pedidos.editar', 'pedidos.cancelar',
                          'pagos.revisar', 'pagos.aprobar', 'clientes.ver', 'productos.ver'],
        'productos'   => ['panel.acceder', 'dashboard.ver', 'productos.ver', 'productos.crear', 'productos.editar',
                          'productos.eliminar', 'categorias.gestionar', 'temporadas.gestionar', 'galeria.gestionar'],
        'auditor'     => ['panel.acceder', 'dashboard.ver', 'auditoria.ver', 'pedidos.ver', 'clientes.ver',
                          'empleados.ver', 'productos.ver', 'configuracion.ver', 'respaldos.ver'],
        'cliente'     => [],
    ];

    $insRp = $pdo->prepare("INSERT IGNORE INTO rol_permisos (rol_id, permiso_id) VALUES (?, ?)");
    foreach ($asignaciones as $codigoRol => $codigos) {
        if (!isset($idsRol[$codigoRol])) {
            continue;
        }
        foreach ($codigos as $codigoPermiso) {
            if (isset($idsPermiso[$codigoPermiso])) {
                $insRp->execute([$idsRol[$codigoRol], $idsPermiso[$codigoPermiso]]);
            }
        }
    }

    // -----------------------------------------------------------------
    // usuarios: datos de cliente, rol y acceso con Google
    // -----------------------------------------------------------------
    foreach ([
        'email'               => "VARCHAR(150) DEFAULT NULL",
        'nombre'              => "VARCHAR(60) NOT NULL DEFAULT ''",
        'apellido'            => "VARCHAR(60) NOT NULL DEFAULT ''",
        'telefono'            => "VARCHAR(20) NOT NULL DEFAULT ''",
        'rol_id'              => "INT DEFAULT NULL",
        'activo'              => "TINYINT(1) NOT NULL DEFAULT 1",
        'google_id'           => "VARCHAR(64) DEFAULT NULL",
        'avatar_url'          => "VARCHAR(255) DEFAULT NULL",
        'email_verificado_en' => "DATETIME DEFAULT NULL",
        'notas'               => "VARCHAR(500) NOT NULL DEFAULT ''",
        'updated_at'          => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
    ] as $columna => $definicion) {
        $e->agregarColumna('usuarios', $columna, $definicion);
    }

    $e->agregarIndice('usuarios', 'uk_usuarios_email',     '(email)',     true);
    $e->agregarIndice('usuarios', 'uk_usuarios_google_id', '(google_id)', true);
    $e->agregarIndice('usuarios', 'idx_usuarios_rol',      '(rol_id, activo)');

    if (!$e->indiceExiste('usuarios', 'fk_usuario_rol')) {
        try {
            $pdo->exec("ALTER TABLE usuarios ADD CONSTRAINT fk_usuario_rol
                        FOREIGN KEY (rol_id) REFERENCES roles(id) ON DELETE SET NULL");
        } catch (PDOException $ex) {
            // La restricción ya existe con otro nombre: no es un fallo.
        }
    }

    // Los usuarios que ya existían son personal del panel: se les asigna
    // super administrador y se les reparte el nombre completo en dos campos.
    $pdo->prepare("UPDATE usuarios SET rol_id = ? WHERE rol_id IS NULL")
        ->execute([$idsRol['super_admin']]);

    foreach ($pdo->query("SELECT id, nombre_completo FROM usuarios WHERE nombre = ''")->fetchAll() as $u) {
        $partes   = preg_split('/\s+/u', trim((string)$u['nombre_completo'])) ?: [];
        $nombre   = array_shift($partes) ?: 'Usuario';
        $apellido = implode(' ', $partes);
        $pdo->prepare("UPDATE usuarios SET nombre = ?, apellido = ? WHERE id = ?")
            ->execute([mb_substr($nombre, 0, 60), mb_substr($apellido, 0, 60), $u['id']]);
    }

    // -----------------------------------------------------------------
    // Recuperación de contraseña
    // -----------------------------------------------------------------
    $e->sql("CREATE TABLE IF NOT EXISTS password_resets (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        usuario_id INT NOT NULL,
        token_hash CHAR(64) NOT NULL UNIQUE,
        expira_en  DATETIME NOT NULL,
        usado_en   DATETIME DEFAULT NULL,
        ip         VARCHAR(45) NOT NULL DEFAULT '',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_reset_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
        INDEX idx_reset_usuario (usuario_id, usado_en)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // -----------------------------------------------------------------
    // Limitación de intentos
    // -----------------------------------------------------------------
    $e->sql("CREATE TABLE IF NOT EXISTS rate_limits (
        clave          VARCHAR(190) PRIMARY KEY,
        intentos       INT NOT NULL DEFAULT 0,
        ventana_inicio DATETIME NOT NULL,
        INDEX idx_rate_ventana (ventana_inicio)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // -----------------------------------------------------------------
    // Auditoría
    // -----------------------------------------------------------------
    $e->sql("CREATE TABLE IF NOT EXISTS auditoria (
        id            BIGINT AUTO_INCREMENT PRIMARY KEY,
        usuario_id    INT DEFAULT NULL,
        usuario_texto VARCHAR(150) NOT NULL DEFAULT 'Visitante',
        rol           VARCHAR(60)  NOT NULL DEFAULT '',
        accion        VARCHAR(60)  NOT NULL,
        modulo        VARCHAR(40)  NOT NULL,
        recurso_tipo  VARCHAR(40)  NOT NULL DEFAULT '',
        recurso_id    VARCHAR(40)  NOT NULL DEFAULT '',
        resultado     ENUM('exito','fallo','denegado') NOT NULL DEFAULT 'exito',
        descripcion   VARCHAR(500) NOT NULL DEFAULT '',
        detalles      TEXT DEFAULT NULL,
        ip            VARCHAR(45)  NOT NULL DEFAULT '',
        user_agent    VARCHAR(255) NOT NULL DEFAULT '',
        created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_auditoria_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL,
        INDEX idx_auditoria_fecha  (created_at),
        INDEX idx_auditoria_modulo (modulo, created_at),
        INDEX idx_auditoria_user   (usuario_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
};
