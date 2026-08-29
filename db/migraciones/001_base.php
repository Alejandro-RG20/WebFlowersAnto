<?php
/**
 * Esquema base del sitio: catálogo, temporadas, galería y configuración.
 *
 * Es idempotente a propósito. Sirve tanto para una instalación desde cero como
 * para una base `flowers_anto` que ya venía de la versión anterior: crea lo que
 * falta y completa las columnas que el código usaba pero no existían.
 */

declare(strict_types=1);

return function (PDO $pdo, Esquema $e): void {

    // -----------------------------------------------------------------
    // Usuarios (en la migración 002 se amplían con roles y datos de cliente)
    // -----------------------------------------------------------------
    $e->sql("CREATE TABLE IF NOT EXISTS usuarios (
        id                  INT AUTO_INCREMENT PRIMARY KEY,
        username            VARCHAR(50)  NULL UNIQUE,
        password_hash       VARCHAR(255) NULL,
        nombre_completo     VARCHAR(120) NOT NULL DEFAULT '',
        pregunta_seguridad  VARCHAR(255) DEFAULT NULL,
        respuesta_seguridad VARCHAR(255) DEFAULT NULL,
        intentos_fallidos   TINYINT UNSIGNED NOT NULL DEFAULT 0,
        bloqueado_hasta     DATETIME  DEFAULT NULL,
        ultimo_acceso       DATETIME  DEFAULT NULL,
        created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $e->agregarColumna('usuarios', 'intentos_fallidos', "TINYINT UNSIGNED NOT NULL DEFAULT 0");
    $e->agregarColumna('usuarios', 'bloqueado_hasta',   "DATETIME DEFAULT NULL");
    $e->agregarColumna('usuarios', 'ultimo_acceso',     "DATETIME DEFAULT NULL");
    $e->agregarColumna('usuarios', 'created_at',        "TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
    $e->modificarColumna('usuarios', 'username', "VARCHAR(50) NULL");
    $e->modificarColumna('usuarios', 'password_hash', "VARCHAR(255) NULL");

    // -----------------------------------------------------------------
    // Categorías
    // -----------------------------------------------------------------
    $e->sql("CREATE TABLE IF NOT EXISTS categorias (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        nombre      VARCHAR(100) NOT NULL UNIQUE,
        descripcion VARCHAR(255) DEFAULT NULL,
        orden       SMALLINT NOT NULL DEFAULT 0,
        activo      TINYINT(1) NOT NULL DEFAULT 1
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $e->agregarColumna('categorias', 'orden',  "SMALLINT NOT NULL DEFAULT 0");
    $e->agregarColumna('categorias', 'activo', "TINYINT(1) NOT NULL DEFAULT 1");
    $e->agregarIndice('categorias', 'idx_categorias_orden', '(activo, orden)');

    if ((int)$pdo->query("SELECT COUNT(*) FROM categorias")->fetchColumn() === 0) {
        $pdo->exec("INSERT INTO categorias (nombre, descripcion, orden) VALUES
            ('Ramos',            'Arreglos en forma de ramo',   1),
            ('Arreglos',         'Arreglos florales generales', 2),
            ('Arreglos de Base', 'Arreglos con base o soporte', 3),
            ('Cajas',            'Flores en cajas decoradas',   4)");
    }

    // -----------------------------------------------------------------
    // Configuración del sitio (fila única, id = 1)
    // -----------------------------------------------------------------
    $e->sql("CREATE TABLE IF NOT EXISTS configuracion (
        id                 INT PRIMARY KEY,
        nombre_tienda      VARCHAR(100) DEFAULT 'Flowers Anto',
        eslogan            VARCHAR(150) DEFAULT 'Flores que dicen lo que no cabe en palabras',
        logo_url           VARCHAR(255) DEFAULT 'images/placeholders/logo.svg',
        favicon_url        VARCHAR(255) DEFAULT 'images/placeholders/logo.svg',
        color_primario     VARCHAR(7)   DEFAULT '#F8B0C2',
        color_secundario   VARCHAR(7)   DEFAULT '#FADADD',
        color_fondo        VARCHAR(7)   DEFAULT '#FFF9F5',
        color_texto        VARCHAR(7)   DEFAULT '#4A3B3D',
        hero_palabra       VARCHAR(20)  DEFAULT 'FLORES',
        hero_titulo        VARCHAR(200) DEFAULT 'Creamos el arreglo perfecto para cada momento',
        hero_subtitulo     VARCHAR(255) DEFAULT 'Diseños únicos con flores de temporada.',
        hero_cta_texto     VARCHAR(40)  DEFAULT 'Ver arreglos',
        hero_imagen        VARCHAR(255) DEFAULT 'images/placeholders/hero-01.svg',
        hero_color_fondo   VARCHAR(7)   DEFAULT '#EFD9DE',
        hero_autoplay      TINYINT(1)   DEFAULT 0,
        whatsapp_numero    VARCHAR(20)  DEFAULT '',
        whatsapp_mensaje   VARCHAR(255) DEFAULT 'Hola Flowers Anto, quiero asesoría para mi arreglo',
        facebook_url       VARCHAR(255) DEFAULT '',
        instagram_url      VARCHAR(255) DEFAULT '',
        tiktok_url         VARCHAR(255) DEFAULT '',
        email_contacto     VARCHAR(100) DEFAULT '',
        direccion          VARCHAR(200) DEFAULT '',
        telefono           VARCHAR(20)  DEFAULT '',
        horario            VARCHAR(150) DEFAULT 'Lunes a sábado, 8:00 a.m. – 6:00 p.m.',
        nosotros_titulo    VARCHAR(150) DEFAULT 'Flores con historia',
        nosotros_texto     TEXT,
        nosotros_imagen    VARCHAR(255) DEFAULT 'images/placeholders/nosotros.svg',
        moneda_local       VARCHAR(5)   DEFAULT 'C$',
        mostrar_usd        TINYINT(1)   DEFAULT 1,
        updated_at         TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    foreach ([
        'eslogan'          => "VARCHAR(150) DEFAULT 'Flores que dicen lo que no cabe en palabras'",
        'favicon_url'      => "VARCHAR(255) DEFAULT 'images/placeholders/logo.svg'",
        'hero_palabra'     => "VARCHAR(20)  DEFAULT 'FLORES'",
        'hero_cta_texto'   => "VARCHAR(40)  DEFAULT 'Ver arreglos'",
        'hero_color_fondo' => "VARCHAR(7)   DEFAULT '#EFD9DE'",
        'hero_autoplay'    => "TINYINT(1)   DEFAULT 0",
        'whatsapp_mensaje' => "VARCHAR(255) DEFAULT 'Hola Flowers Anto, quiero asesoría para mi arreglo'",
        'horario'          => "VARCHAR(150) DEFAULT 'Lunes a sábado, 8:00 a.m. – 6:00 p.m.'",
        'nosotros_titulo'  => "VARCHAR(150) DEFAULT 'Flores con historia'",
        'nosotros_texto'   => "TEXT",
        'nosotros_imagen'  => "VARCHAR(255) DEFAULT 'images/placeholders/nosotros.svg'",
        'moneda_local'     => "VARCHAR(5)   DEFAULT 'C$'",
        'mostrar_usd'      => "TINYINT(1)   DEFAULT 1",
        'updated_at'       => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
    ] as $columna => $definicion) {
        $e->agregarColumna('configuracion', $columna, $definicion);
    }
    $e->modificarColumna('configuracion', 'hero_subtitulo', 'VARCHAR(255)');

    if ((int)$pdo->query("SELECT COUNT(*) FROM configuracion")->fetchColumn() === 0) {
        $pdo->exec("INSERT INTO configuracion (id, nosotros_texto) VALUES (1,
            'Somos un taller floral en Managua. Cada arreglo se compone a mano el mismo día del envío, con flores frescas de temporada.')");
    }

    // -----------------------------------------------------------------
    // Productos
    // -----------------------------------------------------------------
    $e->sql("CREATE TABLE IF NOT EXISTS productos (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        nombre        VARCHAR(120)   NOT NULL,
        descripcion   TEXT           NOT NULL,
        precio        DECIMAL(10,2)  NOT NULL DEFAULT 0,
        precio_usd    DECIMAL(10,2)  NOT NULL DEFAULT 0,
        imagen        VARCHAR(255)   NOT NULL DEFAULT '',
        imagen2       VARCHAR(255)   DEFAULT NULL,
        imagen3       VARCHAR(255)   DEFAULT NULL,
        imagen4       VARCHAR(255)   DEFAULT NULL,
        categoria_id  INT            NOT NULL,
        flores        VARCHAR(255)   NOT NULL DEFAULT '',
        color_acento  VARCHAR(7)     DEFAULT '#EFD9DE',
        destacado     TINYINT(1)     NOT NULL DEFAULT 0,
        orden_hero    SMALLINT       NOT NULL DEFAULT 0,
        disponible    TINYINT(1)     NOT NULL DEFAULT 1,
        activo        TINYINT(1)     NOT NULL DEFAULT 1,
        created_at    TIMESTAMP      DEFAULT CURRENT_TIMESTAMP,
        updated_at    TIMESTAMP      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_producto_categoria FOREIGN KEY (categoria_id)
            REFERENCES categorias(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    foreach ([
        'imagen2'      => "VARCHAR(255) DEFAULT NULL",
        'imagen3'      => "VARCHAR(255) DEFAULT NULL",
        'imagen4'      => "VARCHAR(255) DEFAULT NULL",
        'color_acento' => "VARCHAR(7) DEFAULT '#EFD9DE'",
        'destacado'    => "TINYINT(1) NOT NULL DEFAULT 0",
        'orden_hero'   => "SMALLINT NOT NULL DEFAULT 0",
        'disponible'   => "TINYINT(1) NOT NULL DEFAULT 1",
        'updated_at'   => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
    ] as $columna => $definicion) {
        $e->agregarColumna('productos', $columna, $definicion);
    }
    $e->agregarIndice('productos', 'idx_productos_catalogo', '(activo, categoria_id)');
    $e->agregarIndice('productos', 'idx_productos_hero',     '(activo, destacado, orden_hero)');

    // -----------------------------------------------------------------
    // Temporadas / campañas
    // -----------------------------------------------------------------
    $e->sql("CREATE TABLE IF NOT EXISTS temporadas (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        nombre       VARCHAR(80)  NOT NULL,
        titulo       VARCHAR(150) NOT NULL,
        subtitulo    VARCHAR(255) DEFAULT NULL,
        descripcion  TEXT         DEFAULT NULL,
        palabra_hero VARCHAR(20)  DEFAULT NULL,
        imagen       VARCHAR(255) DEFAULT NULL,
        banner       VARCHAR(255) DEFAULT NULL,
        color_acento VARCHAR(7)   DEFAULT '#EFD9DE',
        fecha_inicio DATE         DEFAULT NULL,
        fecha_fin    DATE         DEFAULT NULL,
        prioridad    SMALLINT     NOT NULL DEFAULT 0,
        activo       TINYINT(1)   NOT NULL DEFAULT 1,
        created_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_temporadas_vigencia (activo, fecha_inicio, fecha_fin, prioridad)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $e->sql("CREATE TABLE IF NOT EXISTS temporada_productos (
        temporada_id INT NOT NULL,
        producto_id  INT NOT NULL,
        orden        SMALLINT NOT NULL DEFAULT 0,
        PRIMARY KEY (temporada_id, producto_id),
        CONSTRAINT fk_tp_temporada FOREIGN KEY (temporada_id) REFERENCES temporadas(id) ON DELETE CASCADE,
        CONSTRAINT fk_tp_producto  FOREIGN KEY (producto_id)  REFERENCES productos(id)  ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // -----------------------------------------------------------------
    // Galería
    // -----------------------------------------------------------------
    $e->sql("CREATE TABLE IF NOT EXISTS clientes_fotos (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        imagen       VARCHAR(255) NOT NULL,
        titulo       VARCHAR(150) DEFAULT NULL,
        orden        SMALLINT NOT NULL DEFAULT 0,
        activo       TINYINT(1) NOT NULL DEFAULT 1,
        fecha_subida TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_fotos_orden (activo, orden, id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $e->agregarColumna('clientes_fotos', 'orden',  "SMALLINT NOT NULL DEFAULT 0");
    $e->agregarColumna('clientes_fotos', 'activo', "TINYINT(1) NOT NULL DEFAULT 1");

    $e->sql("CREATE TABLE IF NOT EXISTS videos_youtube (
        id             INT AUTO_INCREMENT PRIMARY KEY,
        titulo         VARCHAR(150) NOT NULL,
        enlace_youtube VARCHAR(255) NOT NULL,
        descripcion    TEXT DEFAULT NULL,
        activo         TINYINT(1) NOT NULL DEFAULT 1,
        fecha_subida   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_videos_activo (activo, id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // -----------------------------------------------------------------
    // Rutas de imagen heredadas que apuntaban a archivos inexistentes
    // -----------------------------------------------------------------
    foreach ([
        '%BaseRosas%'              => 'images/placeholders/base-01.svg',
        '%CanastaTropical%'        => 'images/placeholders/canasta-01.svg',
        '%arregloBebe%'            => 'images/placeholders/arreglo-01.svg',
        '%ramoGirazol%'            => 'images/placeholders/ramo-02.svg',
        '%FloralMixto%'            => 'images/placeholders/mixto-01.svg',
        '%FelizCumplea%Tropical%'  => 'images/placeholders/arreglo-02.svg',
        '%RamoRomantico%'          => 'images/placeholders/ramo-01.svg',
    ] as $patron => $destino) {
        $pdo->prepare("UPDATE productos SET imagen = ? WHERE imagen LIKE ?")->execute([$destino, $patron]);
    }
    $pdo->exec("UPDATE configuracion SET hero_imagen = 'images/placeholders/hero-01.svg'
                 WHERE hero_imagen LIKE '%nosotros1%'");

    // El hash bcrypt de fábrica de la versión 1 correspondía a la contraseña
    // 'password' y está publicado en internet. Se invalida forzando el
    // restablecimiento: la instalación crea después el administrador real.
    $pdo->exec("UPDATE usuarios SET password_hash = NULL
                 WHERE password_hash = '\$2y\$10\$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'");
};
