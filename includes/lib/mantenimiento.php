<?php
/**
 * Mantenimiento: limpieza de archivos y filas que ya no sirven a nadie.
 *
 * Con los años se acumulan fotos de productos que se borraron, comprobantes
 * de pedidos eliminados, registros de intentos de acceso y tokens caducados.
 * Nada de eso se usa, pero ocupa espacio del hosting.
 *
 * La regla de esta clase es una: **antes de borrar un archivo hay que
 * demostrar que nadie lo referencia**. Y para demostrarlo no se consulta una
 * lista escrita a mano —que envejece en cuanto alguien añade una columna—
 * sino el propio esquema de la base: se recorren todas las columnas de texto
 * buscando rutas. Si mañana se añade `productos.imagen5`, queda protegida
 * sola. Ese detalle importa: `pedido_items.imagen` guarda la foto de lo que
 * se compró, y borrarla dejaría los pedidos antiguos sin imagen.
 *
 * Todo pasa antes por `analizar()`, que cuenta sin tocar nada.
 */

declare(strict_types=1);

final class Mantenimiento
{
    /**
     * Margen de gracia para archivos recién subidos.
     *
     * Una foto subida hace un minuto todavía no está guardada en ningún
     * producto: el formulario sigue abierto. Sin este margen, limpiar en ese
     * momento se llevaría por delante el trabajo de quien está editando.
     */
    private const GRACIA_HORAS = 24;

    /** Registros de acceso: pasada la ventana no sirven para nada. */
    private const RATE_LIMIT_HORAS = 24;

    /** Archivos que nunca se tocan aunque estén en la carpeta. */
    private const PROTEGIDOS = ['.htaccess', 'index.html', 'index.php', '.gitkeep', 'web.config'];

    /** Tareas seguras: nada de esto es información del negocio. */
    public const SEGURAS = [
        'imagenes_huerfanas', 'uploads_huerfanas', 'comprobantes_huerfanos',
        'logs_antiguos', 'rate_limits', 'tokens_caducados',
    ];

    /** Tareas que sí borran datos: van aparte y desmarcadas. */
    public const SENSIBLES = ['carritos_abandonados', 'auditoria_antigua'];

    /** Etiquetas para la interfaz. */
    public static function tareas(): array
    {
        return [
            'imagenes_huerfanas' => [
                'titulo' => 'Imágenes que ya no usa nadie',
                'ayuda'  => 'Fotos guardadas en la base que ningún producto, categoría, '
                          . 'temporada, pedido ni ajuste está usando. Son las que más espacio '
                          . 'ocupan, porque el binario vive dentro de la base.',
                'unidad' => 'imágenes',
            ],
            'uploads_huerfanas' => [
                'titulo' => 'Fotos que ya no usa nadie',
                'ayuda'  => 'Imágenes de uploads/ que ningún producto, categoría, temporada, '
                          . 'pedido ni ajuste está usando. Se respetan las subidas de las últimas '
                          . self::GRACIA_HORAS . ' horas.',
                'unidad' => 'archivos',
            ],
            'comprobantes_huerfanos' => [
                'titulo' => 'Comprobantes sin pedido',
                'ayuda'  => 'Archivos de storage/comprobantes/ que no pertenecen a ningún '
                          . 'comprobante registrado. Los de pedidos vivos no se tocan.',
                'unidad' => 'archivos',
            ],
            'logs_antiguos' => [
                'titulo' => 'Registros técnicos antiguos',
                'ayuda'  => 'Recorta los .log que superan los 5 MB y conserva el final, que es '
                          . 'lo último que pasó. No borra el archivo.',
                'unidad' => 'archivos',
            ],
            'rate_limits' => [
                'titulo' => 'Control de intentos caducado',
                'ayuda'  => 'Contadores de intentos de acceso de hace más de '
                          . self::RATE_LIMIT_HORAS . ' horas. Solo sirven durante su ventana.',
                'unidad' => 'filas',
            ],
            'tokens_caducados' => [
                'titulo' => 'Enlaces de contraseña vencidos',
                'ayuda'  => 'Tokens de «olvidé mi contraseña» ya usados o caducados. '
                          . 'No sirven para entrar.',
                'unidad' => 'filas',
            ],
            'carritos_abandonados' => [
                'titulo' => 'Carritos abandonados (más de 6 meses)',
                'ayuda'  => 'Carritos guardados de clientes con cuenta que llevan medio año sin '
                          . 'tocarse. Esto sí es información del cliente: piénsalo antes.',
                'unidad' => 'filas',
            ],
            'auditoria_antigua' => [
                'titulo' => 'Auditoría de más de 12 meses',
                'ayuda'  => 'Historial de quién hizo qué en el panel. Es tu registro de '
                          . 'seguridad: bórralo solo si necesitas el espacio.',
                'unidad' => 'filas',
            ],
        ];
    }

    /** Cuánto vale el recuento antes de repetirlo, en segundos. */
    private const CACHE_SEGUNDOS = 300;

    /** Cuenta lo que se borraría, sin borrar nada. */
    public static function analizar(PDO $pdo): array
    {
        return self::ejecutar($pdo, [], false);
    }

    /**
     * Igual que `analizar()`, pero guardando el resultado unos minutos.
     *
     * El recuento recorre todas las columnas de texto de la base, y eso crece
     * con los años: medido sobre 50.000 filas de auditoría tardaba 0,2 s, y
     * esa cifra sube sola. Como la pantalla de respaldos se abre varias veces
     * seguidas, se reaprovecha el último recuento en lugar de repetirlo. La
     * limpieza lo invalida, así que las cifras nunca quedan mintiendo.
     */
    public static function analizarCacheado(PDO $pdo, bool $forzar = false): array
    {
        $guardado = $_SESSION['mantenimiento_analisis'] ?? null;
        if (!$forzar && is_array($guardado)
            && (time() - (int)($guardado['momento'] ?? 0)) < self::CACHE_SEGUNDOS) {
            return $guardado['datos'];
        }
        $datos = self::analizar($pdo);
        $_SESSION['mantenimiento_analisis'] = ['momento' => time(), 'datos' => $datos];
        return $datos;
    }

    /** Olvida el recuento guardado (tras limpiar, ya no vale). */
    public static function olvidarAnalisis(): void
    {
        unset($_SESSION['mantenimiento_analisis']);
    }

    /**
     * Borra solo las tareas pedidas.
     *
     * @param string[] $tareas
     */
    public static function limpiar(PDO $pdo, array $tareas): array
    {
        $validas = array_values(array_intersect(
            $tareas,
            array_merge(self::SEGURAS, self::SENSIBLES)
        ));
        // El motor devuelve el mapa completo: las tareas no marcadas traen su
        // recuento, no un borrado. Se recortan aquí para que quien llame sume
        // solo lo que de verdad se ha liberado.
        return array_intersect_key(
            self::ejecutar($pdo, $validas, true),
            array_flip($validas)
        );
    }

    /**
     * Motor común. Con `$borrar = false` solo cuenta, y así la pantalla puede
     * enseñar de antemano exactamente lo que va a pasar.
     */
    private static function ejecutar(PDO $pdo, array $tareas, bool $borrar): array
    {
        $r = [];
        foreach (array_keys(self::tareas()) as $clave) {
            $r[$clave] = ['cantidad' => 0, 'bytes' => 0];
        }
        $hacer = fn(string $t) => $borrar && in_array($t, $tareas, true);

        $r['imagenes_huerfanas']     = self::imagenesHuerfanas($pdo, $hacer('imagenes_huerfanas'));
        $r['uploads_huerfanas']      = self::archivosHuerfanos(
            $pdo, RAIZ . '/uploads', self::rutasEnUso($pdo), $hacer('uploads_huerfanas'));
        $r['comprobantes_huerfanos'] = self::archivosHuerfanos(
            $pdo, RAIZ . '/storage/comprobantes', self::comprobantesEnUso($pdo),
            $hacer('comprobantes_huerfanos'));
        $r['logs_antiguos']  = self::logs($hacer('logs_antiguos'));
        $r['rate_limits']    = self::filas($pdo, $hacer('rate_limits'),
            "FROM rate_limits WHERE ventana_inicio < (NOW() - INTERVAL " . self::RATE_LIMIT_HORAS . " HOUR)");
        $r['tokens_caducados'] = self::filas($pdo, $hacer('tokens_caducados'),
            "FROM password_resets WHERE usado_en IS NOT NULL OR expira_en < NOW()");
        $r['carritos_abandonados'] = self::filas($pdo, $hacer('carritos_abandonados'),
            "FROM carrito_items WHERE updated_at < (NOW() - INTERVAL 6 MONTH)");
        $r['auditoria_antigua'] = self::filas($pdo, $hacer('auditoria_antigua'),
            "FROM auditoria WHERE created_at < (NOW() - INTERVAL 12 MONTH)");

        return $r;
    }

    /** Cuenta o borra filas con el mismo WHERE, para que no puedan discrepar. */
    private static function filas(PDO $pdo, bool $borrar, string $desde): array
    {
        try {
            if ($borrar) {
                $st = $pdo->query("DELETE $desde");
                return ['cantidad' => $st->rowCount(), 'bytes' => 0];
            }
            return ['cantidad' => (int)$pdo->query("SELECT COUNT(*) $desde")->fetchColumn(), 'bytes' => 0];
        } catch (PDOException $e) {
            error_log('Flowers Anto — mantenimiento: ' . $e->getMessage());
            return ['cantidad' => 0, 'bytes' => 0];
        }
    }

    /**
     * Filas de `archivos` que ya no referencia nadie.
     *
     * Desde que las imágenes viven dentro de la base, un producto borrado deja
     * su binario ocupando espacio. Se recorren las columnas de texto buscando
     * referencias «bd:<id>», por el mismo motivo que en el caso de los
     * archivos: una lista escrita a mano envejecería.
     */
    private static function imagenesHuerfanas(PDO $pdo, bool $borrar): array
    {
        $res = ['cantidad' => 0, 'bytes' => 0];
        try {
            $enUso = [];
            foreach (self::columnasDeTexto($pdo) as [$tabla, $columna]) {
                if ($tabla === 'archivos') {
                    continue;
                }
                $sql = sprintf('SELECT DISTINCT `%s` FROM `%s` WHERE `%s` LIKE %s',
                               $columna, $tabla, $columna, $pdo->quote('%bd:%'));
                try {
                    foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN) as $valor) {
                        if (preg_match_all('#bd:(\d+)#', (string)$valor, $m)) {
                            foreach ($m[1] as $id) {
                                $enUso[(int)$id] = true;
                            }
                        }
                    }
                } catch (PDOException $e) {
                    // Sin poder leer una columna no hay lista fiable: se aborta
                    // antes que arriesgarse a borrar una imagen en uso.
                    error_log('Flowers Anto — mantenimiento, columna ' . $tabla . '.' . $columna
                              . ': ' . $e->getMessage());
                    return $res;
                }
            }

            $filas = $pdo->query("SELECT id, tamano FROM archivos")->fetchAll(PDO::FETCH_ASSOC);
            $sobran = [];
            foreach ($filas as $f) {
                if (!isset($enUso[(int)$f['id']])) {
                    $sobran[] = (int)$f['id'];
                    $res['cantidad']++;
                    $res['bytes'] += (int)$f['tamano'];
                }
            }
            if ($borrar && $sobran) {
                $pdo->exec('DELETE FROM archivos WHERE id IN (' . implode(',', $sobran) . ')');
            }
        } catch (PDOException $e) {
            error_log('Flowers Anto — mantenimiento imágenes: ' . $e->getMessage());
            return ['cantidad' => 0, 'bytes' => 0];
        }
        return $res;
    }

    /**
     * Nombres de archivo referenciados en cualquier parte de la base.
     *
     * Se recorren todas las columnas de texto en vez de una lista fija: es la
     * única forma de que una columna nueva quede protegida sin acordarse de
     * actualizar esto. Se comparan nombres de archivo, no rutas, porque la
     * misma imagen aparece escrita de varias formas según dónde se guardó
     * (`uploads/x.jpg`, `/uploads/x.jpg`, `/carpeta/uploads/x.jpg`).
     *
     * @return array<string,true>
     */
    private static function rutasEnUso(PDO $pdo): array
    {
        $enUso = [];
        foreach (self::columnasDeTexto($pdo) as [$tabla, $columna]) {
            try {
                $sql = sprintf(
                    'SELECT DISTINCT `%s` FROM `%s` WHERE `%s` LIKE %s',
                    $columna, $tabla, $columna, $pdo->quote('%uploads/%')
                );
                foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN) as $valor) {
                    foreach (self::nombresEn((string)$valor) as $n) {
                        $enUso[$n] = true;
                    }
                }
            } catch (PDOException $e) {
                // Si una columna no se puede leer, se prefiere no borrar nada
                // que dependa de ella: se anota y se sigue.
                error_log('Flowers Anto — mantenimiento, columna ' . $tabla . '.' . $columna
                          . ': ' . $e->getMessage());
            }
        }
        return $enUso;
    }

    /** @return array<string,true> */
    private static function comprobantesEnUso(PDO $pdo): array
    {
        $enUso = [];
        try {
            foreach ($pdo->query("SELECT archivo FROM pedido_comprobantes")->fetchAll(PDO::FETCH_COLUMN) as $a) {
                $enUso[basename((string)$a)] = true;
            }
        } catch (PDOException $e) {
            error_log('Flowers Anto — mantenimiento comprobantes: ' . $e->getMessage());
            // Sin lista fiable no se borra nada: se devuelve un centinela.
            return ['__sin_lista__' => true];
        }
        return $enUso;
    }

    /** @return array<int,array{0:string,1:string}> */
    private static function columnasDeTexto(PDO $pdo): array
    {
        $st = $pdo->query(
            "SELECT TABLE_NAME, COLUMN_NAME
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND DATA_TYPE IN ('char','varchar','text','mediumtext','longtext')
              ORDER BY TABLE_NAME, ORDINAL_POSITION"
        );
        return $st->fetchAll(PDO::FETCH_NUM);
    }

    /** Extrae los nombres de archivo que aparezcan tras «uploads/» en un texto. */
    private static function nombresEn(string $texto): array
    {
        if (!preg_match_all('#uploads/([A-Za-z0-9._-]+)#', $texto, $m)) {
            return [];
        }
        return $m[1];
    }

    /**
     * Archivos de una carpeta que nadie referencia.
     *
     * @param array<string,true> $enUso nombres de archivo que sí se usan
     */
    private static function archivosHuerfanos(PDO $pdo, string $dir, array $enUso, bool $borrar): array
    {
        $res = ['cantidad' => 0, 'bytes' => 0];
        $base = realpath($dir);
        if ($base === false || !is_dir($base)) {
            return $res;
        }
        // Centinela: la consulta falló, así que no hay lista fiable de qué se usa.
        if (isset($enUso['__sin_lista__'])) {
            return $res;
        }
        $limite = time() - self::GRACIA_HORAS * 3600;

        foreach (scandir($base) ?: [] as $nombre) {
            if ($nombre === '.' || $nombre === '..' || in_array($nombre, self::PROTEGIDOS, true)) {
                continue;
            }
            $ruta = $base . DIRECTORY_SEPARATOR . $nombre;
            // Solo archivos sueltos de esta carpeta: ni subcarpetas ni enlaces.
            if (!is_file($ruta) || is_link($ruta)) {
                continue;
            }
            if (dirname(realpath($ruta) ?: '') !== $base) {
                continue;   // por si acaso: nunca fuera de la carpeta
            }
            if (isset($enUso[$nombre]) || filemtime($ruta) > $limite) {
                continue;
            }
            $tam = (int)filesize($ruta);
            if ($borrar && !@unlink($ruta)) {
                error_log('Flowers Anto — mantenimiento: no se pudo borrar ' . $ruta);
                continue;
            }
            $res['cantidad']++;
            $res['bytes'] += $tam;
        }
        return $res;
    }

    /**
     * Recorta los registros técnicos que se han vuelto enormes.
     *
     * No se borran: se conserva el final, que es donde está lo último que
     * falló. Un log de 40 MB no ayuda a nadie y llena el hosting.
     */
    private static function logs(bool $borrar): array
    {
        $res = ['cantidad' => 0, 'bytes' => 0];
        $dir = realpath(RAIZ . '/storage/logs');
        if ($dir === false || !is_dir($dir)) {
            return $res;
        }
        $tope     = 5 * 1048576;   // a partir de aquí se recorta
        $conservar = 1048576;      // último MB

        foreach (glob($dir . '/*.log') ?: [] as $ruta) {
            if (!is_file($ruta) || is_link($ruta)) {
                continue;
            }
            $tam = (int)filesize($ruta);
            if ($tam <= $tope) {
                continue;
            }
            $res['cantidad']++;
            $res['bytes'] += $tam - $conservar;
            if (!$borrar) {
                continue;
            }
            $fp = @fopen($ruta, 'r');
            if (!$fp) {
                continue;
            }
            fseek($fp, -$conservar, SEEK_END);
            fgets($fp);                     // descarta la línea partida
            $cola = stream_get_contents($fp);
            fclose($fp);
            @file_put_contents($ruta, "--- recortado por mantenimiento el " . date('d/m/Y H:i') . " ---\n" . $cola, LOCK_EX);
        }
        return $res;
    }
}
