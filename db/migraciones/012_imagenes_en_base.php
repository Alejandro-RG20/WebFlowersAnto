<?php
/**
 * Las imágenes pasan a vivir dentro de la base de datos.
 *
 * Hasta ahora la base guardaba la ruta («uploads/img_x.png») y el archivo
 * estaba en el disco del hosting. Eso obliga a mover dos cosas cada vez que
 * se migra el sitio, y un respaldo de la base no incluye las fotos: si se
 * pierde la carpeta, el catálogo queda sin imágenes.
 *
 * Ahora el binario se guarda en `archivos` y las columnas de siempre pasan a
 * contener una referencia corta, `bd:<id>`. Se conservan como VARCHAR a
 * propósito: así ninguna consulta del catálogo cambia y el cambio no se
 * propaga por todo el código.
 *
 * Dos decisiones que importan:
 *
 *   · `sha256` es único, así que subir dos veces la misma foto no ocupa el
 *     doble: se reutiliza la fila existente.
 *   · Las imágenes de `images/` (los marcadores que vienen con el código) NO
 *     se importan. Son parte del repositorio, no material del cliente, y
 *     moverlas a la base solo añadiría peso.
 */

declare(strict_types=1);

return function (PDO $pdo, Esquema $e): void {

    $e->sql("CREATE TABLE IF NOT EXISTS archivos (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        nombre     VARCHAR(150)     NOT NULL DEFAULT '',
        mime       VARCHAR(60)      NOT NULL,
        extension  VARCHAR(10)      NOT NULL DEFAULT '',
        tamano     INT UNSIGNED     NOT NULL DEFAULT 0,
        ancho      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        alto       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        sha256     CHAR(64)         NOT NULL,
        datos      LONGBLOB         NOT NULL,
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_archivos_sha (sha256)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ------------------------------------------------------------------
    // Importar lo que ya había en uploads/ y reescribir las referencias.
    //
    // Las columnas no se listan a mano: se preguntan al esquema. Si alguien
    // añadió una columna con una ruta, entra igual.
    // ------------------------------------------------------------------
    $dir = RAIZ . '/uploads';
    if (!is_dir($dir)) {
        return;
    }

    $columnas = $pdo->query(
        "SELECT TABLE_NAME, COLUMN_NAME
           FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND DATA_TYPE IN ('char','varchar','text','mediumtext','longtext')
            AND TABLE_NAME <> 'archivos'"
    )->fetchAll(PDO::FETCH_NUM);

    $mimes = [
        IMAGETYPE_JPEG => ['image/jpeg', 'jpg'],
        IMAGETYPE_PNG  => ['image/png',  'png'],
        IMAGETYPE_GIF  => ['image/gif',  'gif'],
        IMAGETYPE_WEBP => ['image/webp', 'webp'],
    ];

    $buscar    = $pdo->prepare("SELECT id FROM archivos WHERE sha256 = ?");
    $insertar  = $pdo->prepare(
        "INSERT INTO archivos (nombre, mime, extension, tamano, ancho, alto, sha256, datos)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $importados = [];   // nombre de archivo => id en la base

    /** Mete un archivo de uploads/ en la base y devuelve su id, o 0. */
    $importar = function (string $nombre) use ($dir, $mimes, $buscar, $insertar, $pdo, &$importados): int {
        if (isset($importados[$nombre])) {
            return $importados[$nombre];
        }
        $ruta = $dir . '/' . $nombre;
        if (!is_file($ruta) || is_link($ruta)) {
            return $importados[$nombre] = 0;
        }
        $datos = @file_get_contents($ruta);
        if ($datos === false || $datos === '') {
            return $importados[$nombre] = 0;
        }
        $sha = hash('sha256', $datos);
        $buscar->execute([$sha]);
        if ($id = (int)$buscar->fetchColumn()) {
            return $importados[$nombre] = $id;   // misma foto, ya guardada
        }
        $info = @getimagesize($ruta);
        [$mime, $ext] = $mimes[$info[2] ?? 0] ?? ['application/octet-stream', 'bin'];
        $insertar->execute([
            mb_substr($nombre, 0, 150), $mime, $ext, strlen($datos),
            (int)($info[0] ?? 0), (int)($info[1] ?? 0), $sha, $datos,
        ]);
        return $importados[$nombre] = (int)$pdo->lastInsertId();
    };

    foreach ($columnas as [$tabla, $columna]) {
        $sql = sprintf('SELECT DISTINCT `%s` AS v FROM `%s` WHERE `%s` LIKE %s',
                       $columna, $tabla, $columna, $pdo->quote('%uploads/%'));
        try {
            $valores = $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException) {
            continue;
        }
        foreach ($valores as $valor) {
            $nuevo = preg_replace_callback(
                '#(?:[A-Za-z0-9_./-]*/)?uploads/([A-Za-z0-9._-]+)#',
                function (array $m) use ($importar): string {
                    $id = $importar($m[1]);
                    return $id > 0 ? 'bd:' . $id : $m[0];
                },
                (string)$valor
            );
            if ($nuevo !== $valor) {
                $pdo->prepare(sprintf('UPDATE `%s` SET `%s` = ? WHERE `%s` = ?', $tabla, $columna, $columna))
                    ->execute([$nuevo, $valor]);
            }
        }
    }
};
