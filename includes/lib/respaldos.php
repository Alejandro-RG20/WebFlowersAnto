<?php
/**
 * Respaldos de la base de datos.
 *
 * Se usa `mysqldump` cuando está disponible y configurado; si no, un volcador
 * escrito en PHP. El volcador propio existe porque en la mayoría de hostings
 * compartidos no hay acceso a binarios del sistema, y un respaldo que solo
 * funciona en el portátil del desarrollador no sirve de nada.
 *
 * La restauración nunca es automática: subir un archivo y restaurarlo son dos
 * acciones distintas, con permisos distintos, y antes de restaurar se crea
 * siempre una copia del estado actual.
 */

declare(strict_types=1);

final class Respaldos
{
    /** Tablas que se vuelcan. Se descubren solas para no olvidar ninguna. */
    private static function tablas(PDO $pdo): array
    {
        return $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    private static function nombreBase(): string
    {
        return Entorno::texto('DB_NAME', 'flowers_anto');
    }

    /**
     * Crea un respaldo y lo registra.
     *
     * @return array{ok: bool, error?: string, archivo?: string, id?: int}
     */
    public static function crear(PDO $pdo, string $tipo = 'manual', string $notas = ''): array
    {
        if (!is_dir(DIR_RESPALDOS) && !@mkdir(DIR_RESPALDOS, 0755, true) && !is_dir(DIR_RESPALDOS)) {
            return ['ok' => false, 'error' => 'No se pudo crear la carpeta de respaldos.'];
        }
        if (!is_writable(DIR_RESPALDOS)) {
            return ['ok' => false, 'error' => 'La carpeta storage/respaldos no tiene permiso de escritura.'];
        }

        $archivo = sprintf('respaldo_%s_%s.sql', date('Ymd_His'), bin2hex(random_bytes(4)));
        $ruta    = DIR_RESPALDOS . '/' . $archivo;

        $resultado = self::conMysqldump($ruta);
        if ($resultado === null) {
            $resultado = self::conPhp($pdo, $ruta);
        }

        if (!$resultado['ok']) {
            @unlink($ruta);
            return $resultado;
        }

        $usuario = Auth::usuario();
        $pdo->prepare(
            "INSERT INTO respaldos (archivo, nombre, tamano, tipo, estado, hash_sha256, tablas, notas,
                                    creado_por, creado_texto)
             VALUES (?,?,?,?, 'completo', ?,?,?,?,?)"
        )->execute([
            $archivo,
            'Respaldo del ' . date('d/m/Y H:i'),
            (int)filesize($ruta),
            $tipo,
            (string)hash_file('sha256', $ruta),
            $resultado['tablas'],
            mb_substr($notas, 0, 400),
            $usuario['id'] ?? null,
            mb_substr(Auth::nombreCompleto(), 0, 150),
        ]);
        $id = (int)$pdo->lastInsertId();

        Auditoria::registrar($pdo, 'crear_respaldo', 'sistema', [
            'recurso_tipo' => 'respaldo', 'recurso_id' => (string)$id,
            'descripcion'  => 'Respaldo creado (' . tamano_legible((int)filesize($ruta)) . ', ' . $tipo . ')',
        ]);

        return ['ok' => true, 'archivo' => $archivo, 'id' => $id];
    }

    /** Intenta el volcado con mysqldump. Devuelve null si no está disponible. */
    private static function conMysqldump(string $ruta): ?array
    {
        $bin = Entorno::texto('MYSQLDUMP_BIN', '');
        if ($bin === '' || !is_file($bin) || !function_exists('proc_open')) {
            return null;
        }

        $comando = [
            $bin,
            '--host=' . Entorno::texto('DB_HOST', 'localhost'),
            '--port=' . Entorno::texto('DB_PORT', '3306'),
            '--user=' . Entorno::texto('DB_USER', 'root'),
            '--single-transaction', '--quick', '--default-character-set=utf8mb4',
            '--add-drop-table', '--skip-lock-tables',
            self::nombreBase(),
        ];

        // La contraseña va por el entorno del proceso, no en la línea de
        // comandos: ahí sería visible para cualquiera con acceso a `ps`.
        $entorno = ['MYSQL_PWD' => Entorno::texto('DB_PASS', '')] + $_ENV;

        $salida = fopen($ruta, 'wb');
        if (!$salida) {
            return null;
        }

        $proceso = @proc_open(
            $comando,
            [1 => $salida, 2 => ['pipe', 'w']],
            $tuberias,
            null,
            $entorno
        );

        if (!is_resource($proceso)) {
            fclose($salida);
            return null;
        }

        $error = stream_get_contents($tuberias[2]) ?: '';
        fclose($tuberias[2]);
        $codigo = proc_close($proceso);
        fclose($salida);

        if ($codigo !== 0 || filesize($ruta) === 0) {
            error_log('Flowers Anto — mysqldump falló (' . $codigo . '): ' . substr($error, 0, 300));
            return null; // se cae al volcador propio
        }

        return ['ok' => true, 'tablas' => 0];
    }

    /**
     * Volcador en PHP. Escribe en streaming para no cargar en memoria una
     * tabla entera: en un hosting con 128 MB eso sería un fallo seguro.
     */
    private static function conPhp(PDO $pdo, string $ruta): array
    {
        $salida = @fopen($ruta, 'wb');
        if (!$salida) {
            return ['ok' => false, 'error' => 'No se pudo escribir el archivo de respaldo.'];
        }

        $base = self::nombreBase();
        fwrite($salida, "-- Flowers Anto — respaldo de la base `$base`\n");
        fwrite($salida, '-- Generado el ' . date('Y-m-d H:i:s') . " por el volcador interno\n");
        fwrite($salida, "-- Restaurar desde: Panel → Respaldos → Restaurar\n\n");
        fwrite($salida, "SET NAMES utf8mb4;\n");
        fwrite($salida, "SET FOREIGN_KEY_CHECKS = 0;\n");
        fwrite($salida, "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n");

        $tablas = self::tablas($pdo);

        try {
            foreach ($tablas as $tabla) {
                $crear = $pdo->query("SHOW CREATE TABLE `$tabla`")->fetch(PDO::FETCH_NUM);
                fwrite($salida, "\n-- ----------------------------------------------------\n");
                fwrite($salida, "-- Tabla: $tabla\n");
                fwrite($salida, "-- ----------------------------------------------------\n");
                fwrite($salida, "DROP TABLE IF EXISTS `$tabla`;\n");
                fwrite($salida, $crear[1] . ";\n\n");

                $filas = $pdo->query("SELECT * FROM `$tabla`");
                $lote  = [];
                $columnas = null;

                while ($fila = $filas->fetch(PDO::FETCH_ASSOC)) {
                    $columnas ??= '`' . implode('`, `', array_keys($fila)) . '`';
                    $valores = array_map(
                        fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v),
                        array_values($fila)
                    );
                    $lote[] = '(' . implode(',', $valores) . ')';

                    // Se escribe cada 200 filas: sentencias manejables y
                    // memoria acotada aunque la tabla tenga millones de filas.
                    if (count($lote) >= 200) {
                        fwrite($salida, "INSERT INTO `$tabla` ($columnas) VALUES\n" . implode(",\n", $lote) . ";\n");
                        $lote = [];
                    }
                }
                if ($lote) {
                    fwrite($salida, "INSERT INTO `$tabla` ($columnas) VALUES\n" . implode(",\n", $lote) . ";\n");
                }
            }

            fwrite($salida, "\nSET FOREIGN_KEY_CHECKS = 1;\n");
            fwrite($salida, '-- Fin del respaldo (' . count($tablas) . " tablas)\n");
        } catch (Throwable $ex) {
            fclose($salida);
            error_log('Flowers Anto — volcado: ' . $ex->getMessage());
            return ['ok' => false, 'error' => 'El volcado falló a mitad. Revisa el registro de errores.'];
        }

        fclose($salida);
        return ['ok' => true, 'tablas' => count($tablas)];
    }

    /**
     * Revisa un archivo antes de aceptarlo o restaurarlo.
     *
     * @return array{ok: bool, error?: string, tablas?: int, sentencias?: int}
     */
    public static function validar(string $ruta): array
    {
        if (!is_file($ruta) || !is_readable($ruta)) {
            return ['ok' => false, 'error' => 'No se encuentra el archivo del respaldo.'];
        }
        $tamano = filesize($ruta);
        if ($tamano === 0) {
            return ['ok' => false, 'error' => 'El archivo está vacío.'];
        }
        if ($tamano > MAX_RESPALDO_BYTES) {
            return ['ok' => false, 'error' => 'El archivo supera los '
                    . (int)(MAX_RESPALDO_BYTES / 1048576) . ' MB permitidos.'];
        }

        $manejador = fopen($ruta, 'rb');
        if (!$manejador) {
            return ['ok' => false, 'error' => 'No se pudo leer el archivo.'];
        }

        // Un volcado es texto plano. Si trae bytes nulos, no es un .sql.
        $muestra = (string)fread($manejador, 8192);
        if (str_contains($muestra, "\0")) {
            fclose($manejador);
            return ['ok' => false, 'error' => 'El archivo no es un volcado SQL en texto plano.'];
        }

        $tablas     = 0;
        $sentencias = 0;
        $peligrosas = [];
        rewind($manejador);

        while (($linea = fgets($manejador)) !== false) {
            $limpia = ltrim($linea);
            if ($limpia === '' || str_starts_with($limpia, '--') || str_starts_with($limpia, '/*')) {
                continue;
            }
            $sentencias++;
            $mayus = strtoupper($limpia);

            if (str_starts_with($mayus, 'CREATE TABLE')) {
                $tablas++;
            }
            // Un respaldo legítimo no crea usuarios, ni da permisos, ni
            // ejecuta comandos del sistema. Si aparece algo así, se rechaza.
            foreach (['GRANT ', 'CREATE USER', 'DROP DATABASE', 'ALTER USER',
                      'SET GLOBAL', 'LOAD DATA', 'INTO OUTFILE', 'INTO DUMPFILE',
                      'CREATE FUNCTION', 'SYSTEM ', 'SOURCE '] as $prohibida) {
                if (str_starts_with($mayus, $prohibida) || str_contains($mayus, ' ' . $prohibida)) {
                    $peligrosas[] = trim($prohibida);
                }
            }
        }
        fclose($manejador);

        if ($peligrosas) {
            return ['ok' => false, 'error' => 'El archivo contiene sentencias que no aparecen en un '
                    . 'respaldo normal (' . implode(', ', array_unique($peligrosas))
                    . '). Por seguridad no se acepta.'];
        }
        if ($tablas === 0) {
            return ['ok' => false, 'error' => 'El archivo no define ninguna tabla: no parece un respaldo de esta base.'];
        }

        return ['ok' => true, 'tablas' => $tablas, 'sentencias' => $sentencias];
    }

    /**
     * Restaura un respaldo.
     *
     * Antes de tocar nada crea una copia del estado actual, para que un error
     * no deje al negocio sin datos. Al terminar comprueba que las tablas
     * esperadas existen.
     *
     * @return array{ok: bool, error?: string, previo?: string, tablas?: int}
     */
    public static function restaurar(PDO $pdo, array $respaldo): array
    {
        $ruta = DIR_RESPALDOS . '/' . basename((string)$respaldo['archivo']);

        $revision = self::validar($ruta);
        if (!$revision['ok']) {
            return $revision;
        }

        // Comprobación de integridad: el archivo debe ser el mismo que se
        // registró. Si cambió en disco, algo raro pasa y no se restaura.
        if ($respaldo['hash_sha256'] !== '' && !hash_equals(
            (string)$respaldo['hash_sha256'], (string)hash_file('sha256', $ruta)
        )) {
            return ['ok' => false, 'error' => 'El archivo cambió desde que se registró. '
                    . 'Por seguridad no se restaura.'];
        }

        // 1. Copia del estado actual
        $previo = self::crear($pdo, 'pre_restauracion',
            'Copia automática antes de restaurar «' . $respaldo['nombre'] . '».');
        if (!$previo['ok']) {
            return ['ok' => false, 'error' => 'No se pudo crear la copia previa, así que no se '
                    . 'restauró nada: ' . ($previo['error'] ?? '')];
        }

        // 2. Ejecución
        $manejador = fopen($ruta, 'rb');
        if (!$manejador) {
            return ['ok' => false, 'error' => 'No se pudo abrir el respaldo.'];
        }

        $sentencia = '';
        $ejecutadas = 0;
        $falloEn   = null;

        try {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

            while (($linea = fgets($manejador)) !== false) {
                $limpia = trim($linea);
                if ($limpia === '' || str_starts_with($limpia, '--')
                    || (str_starts_with($limpia, '/*') && str_ends_with($limpia, '*/;'))) {
                    continue;
                }
                $sentencia .= $linea;

                // El punto y coma al final de línea cierra la sentencia. Los
                // volcados de mysqldump y del volcador propio siempre lo
                // ponen así, nunca a mitad de línea con datos detrás.
                if (str_ends_with($limpia, ';')) {
                    $pdo->exec($sentencia);
                    $ejecutadas++;
                    $sentencia = '';
                }
            }
            if (trim($sentencia) !== '') {
                $pdo->exec($sentencia);
                $ejecutadas++;
            }
        } catch (Throwable $ex) {
            $falloEn = $ex->getMessage();
        } finally {
            fclose($manejador);
            try {
                $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            } catch (Throwable) {
                // La conexión pudo quedar en mal estado; no oculta el error real.
            }
        }

        if ($falloEn !== null) {
            error_log('Flowers Anto — restauración: ' . $falloEn);
            Auditoria::registrar($pdo, 'restaurar_respaldo', 'sistema', [
                'resultado'    => 'fallo',
                'recurso_tipo' => 'respaldo', 'recurso_id' => (string)$respaldo['id'],
                'descripcion'  => 'La restauración falló tras ' . $ejecutadas . ' sentencias.',
                'detalles'     => ['error' => mb_substr($falloEn, 0, 300), 'copia_previa' => $previo['archivo']],
            ]);
            return ['ok' => false,
                    'error' => 'La restauración se detuvo en la sentencia ' . ($ejecutadas + 1) . '. '
                             . 'Guardamos una copia del estado anterior (' . $previo['archivo'] . ') '
                             . 'por si hay que volver atrás.',
                    'previo' => $previo['archivo']];
        }

        // 3. Verificación
        $tablas = self::tablas($pdo);
        $faltan = array_diff(['usuarios', 'productos', 'pedidos', 'configuracion'], $tablas);
        if ($faltan) {
            return ['ok' => false,
                    'error' => 'La restauración terminó pero faltan tablas del sistema ('
                             . implode(', ', $faltan) . '). Restaura la copia previa: ' . $previo['archivo'],
                    'previo' => $previo['archivo']];
        }

        // El volcado restaurado incluye la propia tabla `respaldos`, así que
        // acaba de sustituir el listado por el que existía cuando se hizo la
        // copia: la copia previa y el respaldo aplicado habrían desaparecido
        // del panel justo cuando más falta hacen. Se vuelven a registrar.
        self::reregistrar($pdo, $previo['archivo'], 'pre_restauracion',
            'Copia automática del estado anterior a la restauración del '
            . date('d/m/Y H:i') . '.');
        self::reregistrar($pdo, (string)$respaldo['archivo'], (string)$respaldo['tipo'],
            (string)$respaldo['notas'], 'restaurado', (string)$respaldo['nombre']);

        Auditoria::registrar($pdo, 'restaurar_respaldo', 'sistema', [
            'recurso_tipo' => 'respaldo', 'recurso_id' => (string)$respaldo['id'],
            'descripcion'  => 'Base restaurada desde «' . $respaldo['nombre'] . '» ('
                            . $ejecutadas . ' sentencias, ' . count($tablas) . ' tablas).',
            'detalles'     => ['copia_previa' => $previo['archivo']],
        ]);

        return ['ok' => true, 'previo' => $previo['archivo'], 'tablas' => count($tablas)];
    }

    /**
     * Vuelve a dejar constancia de un archivo de respaldo que sigue en disco
     * pero cuya fila se perdió al restaurar. Si la fila ya existe, solo
     * actualiza su estado.
     */
    private static function reregistrar(
        PDO $pdo, string $archivo, string $tipo, string $notas,
        string $estado = 'completo', string $nombre = ''
    ): void {
        $ruta = DIR_RESPALDOS . '/' . basename($archivo);
        if (!is_file($ruta)) {
            return;
        }

        $existe = $pdo->prepare("SELECT id FROM respaldos WHERE archivo = ?");
        $existe->execute([$archivo]);
        $id = $existe->fetchColumn();

        if ($id !== false) {
            $pdo->prepare("UPDATE respaldos SET estado = ? WHERE id = ?")->execute([$estado, $id]);
            return;
        }

        $usuario = Auth::usuario();
        $pdo->prepare(
            "INSERT INTO respaldos (archivo, nombre, tamano, tipo, estado, hash_sha256, tablas, notas,
                                    creado_por, creado_texto)
             VALUES (?,?,?,?,?,?,0,?,?,?)"
        )->execute([
            $archivo,
            $nombre !== '' ? $nombre : 'Respaldo del ' . date('d/m/Y H:i', (int)filemtime($ruta)),
            (int)filesize($ruta),
            in_array($tipo, ['manual', 'subido', 'pre_restauracion'], true) ? $tipo : 'manual',
            $estado,
            (string)hash_file('sha256', $ruta),
            mb_substr($notas, 0, 400),
            $usuario['id'] ?? null,
            mb_substr(Auth::nombreCompleto(), 0, 150),
        ]);
    }

    /** Guarda un archivo .sql subido desde el panel. NO lo restaura. */
    public static function guardarSubido(PDO $pdo, array $archivo, string $notas = ''): array
    {
        if (!isset($archivo['error'], $archivo['tmp_name'])) {
            return ['ok' => false, 'error' => 'No se recibió ningún archivo.'];
        }
        $error = Archivos::errorSubida((int)$archivo['error']);
        if ($error !== '') {
            return ['ok' => false, 'error' => $error];
        }
        if (!is_uploaded_file($archivo['tmp_name'])) {
            return ['ok' => false, 'error' => 'Archivo no válido.'];
        }
        if ((int)$archivo['size'] > MAX_RESPALDO_BYTES) {
            return ['ok' => false, 'error' => 'El archivo supera los '
                    . (int)(MAX_RESPALDO_BYTES / 1048576) . ' MB permitidos.'];
        }
        if (strtolower((string)pathinfo((string)$archivo['name'], PATHINFO_EXTENSION)) !== 'sql') {
            return ['ok' => false, 'error' => 'El respaldo debe ser un archivo .sql.'];
        }

        $revision = self::validar($archivo['tmp_name']);
        if (!$revision['ok']) {
            return $revision;
        }

        if (!is_dir(DIR_RESPALDOS) && !@mkdir(DIR_RESPALDOS, 0755, true) && !is_dir(DIR_RESPALDOS)) {
            return ['ok' => false, 'error' => 'No se pudo preparar la carpeta de respaldos.'];
        }

        $nombreArchivo = sprintf('subido_%s_%s.sql', date('Ymd_His'), bin2hex(random_bytes(4)));
        $destino       = DIR_RESPALDOS . '/' . $nombreArchivo;

        if (!move_uploaded_file($archivo['tmp_name'], $destino)) {
            return ['ok' => false, 'error' => 'No se pudo guardar el archivo.'];
        }
        @chmod($destino, 0640);

        $usuario = Auth::usuario();
        $pdo->prepare(
            "INSERT INTO respaldos (archivo, nombre, tamano, tipo, estado, hash_sha256, tablas, notas,
                                    creado_por, creado_texto)
             VALUES (?,?,?, 'subido', 'completo', ?,?,?,?,?)"
        )->execute([
            $nombreArchivo,
            mb_substr(Archivos::nombreSeguro((string)$archivo['name']), 0, 160),
            (int)filesize($destino),
            (string)hash_file('sha256', $destino),
            $revision['tablas'],
            mb_substr($notas, 0, 400),
            $usuario['id'] ?? null,
            mb_substr(Auth::nombreCompleto(), 0, 150),
        ]);
        $id = (int)$pdo->lastInsertId();

        Auditoria::registrar($pdo, 'subir_respaldo', 'sistema', [
            'recurso_tipo' => 'respaldo', 'recurso_id' => (string)$id,
            'descripcion'  => 'Respaldo subido: ' . $archivo['name'] . ' (' . $revision['tablas'] . ' tablas). '
                            . 'No se restauró.',
        ]);

        return ['ok' => true, 'id' => $id, 'tablas' => $revision['tablas']];
    }

    /** Borra el archivo y su registro. */
    public static function eliminar(PDO $pdo, array $respaldo): bool
    {
        Archivos::borrarDe(DIR_RESPALDOS, (string)$respaldo['archivo']);
        $pdo->prepare("DELETE FROM respaldos WHERE id = ?")->execute([$respaldo['id']]);

        Auditoria::registrar($pdo, 'eliminar_respaldo', 'sistema', [
            'recurso_tipo' => 'respaldo', 'recurso_id' => (string)$respaldo['id'],
            'descripcion'  => 'Respaldo eliminado: ' . $respaldo['nombre'],
        ]);
        return true;
    }
}
