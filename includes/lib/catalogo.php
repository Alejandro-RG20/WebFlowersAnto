<?php
/**
 * Consultas del catálogo: productos, categorías, temporadas y galería.
 *
 * Todas las listas devuelven solo productos activos. El panel usa sus propias
 * consultas porque ahí sí hay que ver los desactivados.
 */

declare(strict_types=1);

final class Catalogo
{
    /** Columnas públicas. Se listan una a una para no exponer nada de más. */
    private const COLS = 'p.id, p.nombre, p.slug, p.descripcion, p.resumen, p.precio, p.precio_usd,
                          p.imagen, p.imagen_hero, p.categoria_id, p.flores, p.color_acento, p.destacado,
                          p.orden_hero, p.orden, p.disponible, p.stock, p.controla_stock, p.created_at';

    /** Un producto por su slug (o por id, para los enlaces antiguos). */
    public static function producto(PDO $pdo, string $slug): ?array
    {
        $st = $pdo->prepare(
            "SELECT " . self::COLS . ", c.nombre AS categoria_nombre, c.slug AS categoria_slug
               FROM productos p
               JOIN categorias c ON c.id = p.categoria_id
              WHERE p.activo = 1 AND (p.slug = ? OR p.id = ?)
              LIMIT 1"
        );
        $st->execute([$slug, ctype_digit($slug) ? (int)$slug : 0]);
        $p = $st->fetch();
        if (!$p) {
            return null;
        }
        $p['imagenes'] = self::imagenes($pdo, (int)$p['id']);
        return $p;
    }

    /** Fotos de un producto, ordenadas. Siempre devuelve al menos una. */
    public static function imagenes(PDO $pdo, int $productoId): array
    {
        $st = $pdo->prepare("SELECT ruta, alt FROM producto_imagenes WHERE producto_id = ? ORDER BY orden, id");
        $st->execute([$productoId]);
        $filas = $st->fetchAll();
        if ($filas) {
            return $filas;
        }
        $st = $pdo->prepare("SELECT imagen AS ruta, nombre AS alt FROM productos WHERE id = ?");
        $st->execute([$productoId]);
        $unica = $st->fetch();
        return ($unica && $unica['ruta'] !== '') ? [$unica] : [];
    }

    /** Adjunta la portada a una lista de productos con una sola consulta. */
    private static function conPortada(PDO $pdo, array $productos): array
    {
        if (!$productos) {
            return [];
        }
        $ids  = array_column($productos, 'id');
        $huecos = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare(
            "SELECT producto_id, ruta FROM producto_imagenes
              WHERE producto_id IN ($huecos) ORDER BY producto_id, orden, id"
        );
        $st->execute($ids);

        $portadas = [];
        foreach ($st->fetchAll() as $fila) {
            $portadas[$fila['producto_id']] ??= $fila['ruta'];
        }
        foreach ($productos as &$p) {
            $p['portada'] = $portadas[$p['id']] ?? $p['imagen'];
        }
        return $productos;
    }

    /**
     * Catálogo con filtros, búsqueda, orden y paginación.
     *
     * @param array $f  categoria, flor, q, orden, pagina, por_pagina, solo_disponibles
     * @return array{items: array, total: int, paginas: int, pagina: int}
     */
    public static function buscar(PDO $pdo, array $f = []): array
    {
        $where  = ['p.activo = 1'];
        $params = [];

        if (!empty($f['categoria'])) {
            $where[]  = 'c.slug = ?';
            $params[] = (string)$f['categoria'];
        }
        if (!empty($f['flor'])) {
            $where[]  = 'p.flores LIKE ?';
            $params[] = '%' . str_replace(['%', '_'], ['\%', '\_'], (string)$f['flor']) . '%';
        }
        if (!empty($f['q'])) {
            $termino  = '%' . str_replace(['%', '_'], ['\%', '\_'], (string)$f['q']) . '%';
            $where[]  = '(p.nombre LIKE ? OR p.descripcion LIKE ? OR p.flores LIKE ? OR c.nombre LIKE ?)';
            array_push($params, $termino, $termino, $termino, $termino);
        }
        if (!empty($f['solo_disponibles'])) {
            $where[] = 'p.disponible = 1 AND (p.controla_stock = 0 OR p.stock > 0)';
        }
        if (isset($f['precio_min']) && $f['precio_min'] !== '') {
            $where[]  = 'p.precio >= ?';
            $params[] = (float)$f['precio_min'];
        }
        if (isset($f['precio_max']) && $f['precio_max'] !== '') {
            $where[]  = 'p.precio <= ?';
            $params[] = (float)$f['precio_max'];
        }

        $orden = match ($f['orden'] ?? 'destacados') {
            'precio_asc'  => 'p.precio ASC, p.id DESC',
            'precio_desc' => 'p.precio DESC, p.id DESC',
            'nombre'      => 'p.nombre ASC',
            'nuevos'      => 'p.id DESC',
            'vendidos'    => 'p.vendidos DESC, p.id DESC',
            default       => 'p.destacado DESC, p.orden ASC, p.id DESC',
        };

        $sqlWhere = implode(' AND ', $where);

        $stTotal = $pdo->prepare(
            "SELECT COUNT(*) FROM productos p JOIN categorias c ON c.id = p.categoria_id WHERE $sqlWhere"
        );
        $stTotal->execute($params);
        $total = (int)$stTotal->fetchColumn();

        $porPagina = max(1, min(48, (int)($f['por_pagina'] ?? 12)));
        $paginas   = max(1, (int)ceil($total / $porPagina));
        $pagina    = max(1, min($paginas, (int)($f['pagina'] ?? 1)));
        $salto     = ($pagina - 1) * $porPagina;

        $st = $pdo->prepare(
            "SELECT " . self::COLS . ", c.nombre AS categoria_nombre, c.slug AS categoria_slug
               FROM productos p
               JOIN categorias c ON c.id = p.categoria_id
              WHERE $sqlWhere
              ORDER BY $orden
              LIMIT $porPagina OFFSET $salto"
        );
        $st->execute($params);

        return [
            'items'   => self::conPortada($pdo, $st->fetchAll()),
            'total'   => $total,
            'paginas' => $paginas,
            'pagina'  => $pagina,
        ];
    }

    /** Productos destacados para la portada. */
    public static function destacados(PDO $pdo, int $limite = 8): array
    {
        $st = $pdo->prepare(
            "SELECT " . self::COLS . ", c.nombre AS categoria_nombre, c.slug AS categoria_slug
               FROM productos p JOIN categorias c ON c.id = p.categoria_id
              WHERE p.activo = 1 AND p.destacado = 1
              ORDER BY p.orden_hero, p.id DESC LIMIT $limite"
        );
        $st->execute();
        $filas = $st->fetchAll();
        if (!$filas) {
            $filas = $pdo->query(
                "SELECT " . self::COLS . ", c.nombre AS categoria_nombre, c.slug AS categoria_slug
                   FROM productos p JOIN categorias c ON c.id = p.categoria_id
                  WHERE p.activo = 1 ORDER BY p.id DESC LIMIT $limite"
            )->fetchAll();
        }
        return self::conPortada($pdo, $filas);
    }

    /** Últimos productos añadidos. */
    public static function recientes(PDO $pdo, int $limite = 4): array
    {
        $filas = $pdo->query(
            "SELECT " . self::COLS . ", c.nombre AS categoria_nombre, c.slug AS categoria_slug
               FROM productos p JOIN categorias c ON c.id = p.categoria_id
              WHERE p.activo = 1 ORDER BY p.created_at DESC, p.id DESC LIMIT $limite"
        )->fetchAll();
        return self::conPortada($pdo, $filas);
    }

    /** Productos de la misma categoría, excluyendo el actual. */
    public static function relacionados(PDO $pdo, array $producto, int $limite = 4): array
    {
        $st = $pdo->prepare(
            "SELECT " . self::COLS . ", c.nombre AS categoria_nombre, c.slug AS categoria_slug
               FROM productos p JOIN categorias c ON c.id = p.categoria_id
              WHERE p.activo = 1 AND p.categoria_id = ? AND p.id <> ?
              ORDER BY p.destacado DESC, RAND() LIMIT $limite"
        );
        $st->execute([$producto['categoria_id'], $producto['id']]);
        return self::conPortada($pdo, $st->fetchAll());
    }

    /** Varios productos por id, para carrito y favoritos. */
    public static function porIds(PDO $pdo, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return [];
        }
        $huecos = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare(
            "SELECT " . self::COLS . ", c.nombre AS categoria_nombre, c.slug AS categoria_slug
               FROM productos p JOIN categorias c ON c.id = p.categoria_id
              WHERE p.activo = 1 AND p.id IN ($huecos)"
        );
        $st->execute($ids);
        $filas = self::conPortada($pdo, $st->fetchAll());
        return array_column($filas, null, 'id');
    }

    /** Categorías activas, con el número de productos visibles de cada una. */
    public static function categorias(PDO $pdo): array
    {
        return $pdo->query(
            "SELECT c.id, c.nombre, c.slug, c.descripcion, c.imagen,
                    COUNT(p.id) AS total
               FROM categorias c
          LEFT JOIN productos p ON p.categoria_id = c.id AND p.activo = 1
              WHERE c.activo = 1
           GROUP BY c.id, c.nombre, c.slug, c.descripcion, c.imagen, c.orden
           ORDER BY c.orden, c.nombre"
        )->fetchAll();
    }

    /** Tipos de flor presentes en el catálogo, para los filtros. */
    public static function tiposDeFlor(PDO $pdo): array
    {
        $bruto = $pdo->query("SELECT flores FROM productos WHERE activo = 1 AND flores <> ''")
                     ->fetchAll(PDO::FETCH_COLUMN);
        $tipos = [];
        foreach ($bruto as $linea) {
            foreach (explode(',', (string)$linea) as $flor) {
                $flor = trim(mb_strtolower($flor));
                if ($flor !== '') {
                    $tipos[$flor] = ($tipos[$flor] ?? 0) + 1;
                }
            }
        }
        arsort($tipos);
        return $tipos;
    }

    /**
     * Temporada vigente hoy, sin sus productos.
     *
     * Vigente = activa, ya empezó (o no tiene fecha de inicio) y no ha
     * terminado. La cabecera la consulta en todas las páginas para aplicar el
     * color y el estilo, así que se resuelve una sola vez por petición y sin
     * arrastrar el catálogo: la portada sí necesita los productos, el resto
     * del sitio no.
     *
     * El resultado se guarda incluso cuando es null, para no repetir la
     * consulta en las semanas en que no hay ninguna campaña.
     *
     * Si la tabla todavía no existe se devuelve null en vez de reventar: esta
     * consulta corre en la cabecera de TODAS las páginas, y una de ellas es
     * `instalar.php`, que por definición se abre sobre una base vacía. Una
     * decoración de temporada no puede impedir instalar el sitio.
     */
    public static function temporadaVigente(PDO $pdo): ?array
    {
        static $cache = false;
        if ($cache !== false) {
            return $cache;
        }

        try {
            $fila = $pdo->query(
                "SELECT * FROM temporadas
                  WHERE activo = 1
                    AND (fecha_inicio IS NULL OR fecha_inicio <= CURDATE())
                    AND (fecha_fin    IS NULL OR fecha_fin    >= CURDATE())
                  ORDER BY prioridad DESC, id DESC LIMIT 1"
            )->fetch();
        } catch (PDOException $ex) {
            // Se anota para que un problema real no pase inadvertido, pero la
            // página sigue sirviéndose sin tema de temporada.
            error_log('Flowers Anto — temporada vigente: ' . $ex->getMessage());
            return $cache = null;
        }

        return $cache = ($fila ?: null);
    }

    /**
     * Temporada vigente con los productos de la campaña. La usa la portada.
     */
    public static function temporadaActiva(PDO $pdo): ?array
    {
        $fila = self::temporadaVigente($pdo);
        if (!$fila) {
            return null;
        }
        $st = $pdo->prepare(
            "SELECT " . self::COLS . ", c.nombre AS categoria_nombre, c.slug AS categoria_slug
               FROM temporada_productos tp
               JOIN productos  p ON p.id = tp.producto_id
               JOIN categorias c ON c.id = p.categoria_id
              WHERE tp.temporada_id = ? AND p.activo = 1
              ORDER BY tp.orden, p.id LIMIT 8"
        );
        $st->execute([$fila['id']]);
        $fila['productos'] = self::conPortada($pdo, $st->fetchAll());
        return $fila;
    }

    /** Productos del carrusel de portada: temporada > destacados > recientes. */
    public static function hero(PDO $pdo, ?array $temporada): array
    {
        if ($temporada && !empty($temporada['productos'])) {
            return array_slice($temporada['productos'], 0, 5);
        }
        return array_slice(self::destacados($pdo, 5), 0, 5);
    }

    public static function fotosGaleria(PDO $pdo): array
    {
        return $pdo->query(
            "SELECT id, imagen, titulo FROM clientes_fotos WHERE activo = 1 ORDER BY orden, fecha_subida DESC"
        )->fetchAll();
    }

    public static function videos(PDO $pdo): array
    {
        return $pdo->query(
            "SELECT id, titulo, enlace_youtube, descripcion FROM videos_youtube
              WHERE activo = 1 ORDER BY fecha_subida DESC"
        )->fetchAll();
    }

    /** ¿Se puede pedir este producto ahora mismo? */
    public static function disponible(array $producto): bool
    {
        if ((int)$producto['disponible'] !== 1) {
            return false;
        }
        return (int)($producto['controla_stock'] ?? 0) === 0 || (int)($producto['stock'] ?? 0) > 0;
    }

    /** Unidades que se pueden pedir de un producto. */
    public static function maximoPedible(array $producto): int
    {
        if ((int)($producto['controla_stock'] ?? 0) === 1) {
            return max(0, min(20, (int)$producto['stock']));
        }
        return 20;
    }
}
