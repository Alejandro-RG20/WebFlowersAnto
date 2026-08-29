<?php
/**
 * Catálogo: URLs amigables, galería de fotos por producto y control de stock.
 *
 * Las cuatro columnas imagen…imagen4 se convierten en filas de
 * `producto_imagenes`, que permite cualquier número de fotos y reordenarlas.
 * `productos.imagen` se conserva como portada: simplifica los listados y
 * mantiene compatible cualquier consulta anterior.
 */

declare(strict_types=1);

return function (PDO $pdo, Esquema $e): void {

    // -----------------------------------------------------------------
    // Categorías con URL amigable e imagen
    // -----------------------------------------------------------------
    $e->agregarColumna('categorias', 'slug',   "VARCHAR(120) DEFAULT NULL");
    $e->agregarColumna('categorias', 'imagen', "VARCHAR(255) NOT NULL DEFAULT ''");

    foreach ($pdo->query("SELECT id, nombre FROM categorias WHERE slug IS NULL OR slug = ''")->fetchAll() as $c) {
        $base = slugificar((string)$c['nombre']);
        $slug = $base;
        $n    = 2;
        $chk  = $pdo->prepare("SELECT 1 FROM categorias WHERE slug = ? AND id <> ?");
        $chk->execute([$slug, $c['id']]);
        while ($chk->fetchColumn()) {
            $slug = $base . '-' . $n++;
            $chk->execute([$slug, $c['id']]);
        }
        $pdo->prepare("UPDATE categorias SET slug = ? WHERE id = ?")->execute([$slug, $c['id']]);
    }
    $e->modificarColumna('categorias', 'slug', "VARCHAR(120) NOT NULL DEFAULT ''");
    $e->agregarIndice('categorias', 'uk_categorias_slug', '(slug)', true);

    // -----------------------------------------------------------------
    // Productos: slug, stock y orden de catálogo
    // -----------------------------------------------------------------
    foreach ([
        'slug'         => "VARCHAR(140) DEFAULT NULL AFTER nombre",
        'resumen'      => "VARCHAR(200) NOT NULL DEFAULT '' AFTER descripcion",
        'stock'        => "INT NOT NULL DEFAULT 0",
        'controla_stock' => "TINYINT(1) NOT NULL DEFAULT 0",
        'orden'        => "SMALLINT NOT NULL DEFAULT 0",
        'vendidos'     => "INT NOT NULL DEFAULT 0",
    ] as $columna => $definicion) {
        $e->agregarColumna('productos', $columna, $definicion);
    }

    foreach ($pdo->query("SELECT id, nombre FROM productos WHERE slug IS NULL OR slug = ''")->fetchAll() as $p) {
        $base = slugificar((string)$p['nombre']);
        $slug = $base;
        $n    = 2;
        $chk  = $pdo->prepare("SELECT 1 FROM productos WHERE slug = ? AND id <> ?");
        $chk->execute([$slug, $p['id']]);
        while ($chk->fetchColumn()) {
            $slug = $base . '-' . $n++;
            $chk->execute([$slug, $p['id']]);
        }
        $pdo->prepare("UPDATE productos SET slug = ? WHERE id = ?")->execute([$slug, $p['id']]);
    }
    $e->modificarColumna('productos', 'slug', "VARCHAR(140) NOT NULL DEFAULT ''");
    $e->agregarIndice('productos', 'uk_productos_slug',    '(slug)', true);
    $e->agregarIndice('productos', 'idx_productos_orden',  '(activo, orden, id)');
    $e->agregarIndice('productos', 'idx_productos_precio', '(activo, precio)');

    // Resumen automático a partir de la descripción, para las tarjetas y el SEO.
    foreach ($pdo->query("SELECT id, descripcion FROM productos WHERE resumen = ''")->fetchAll() as $p) {
        $pdo->prepare("UPDATE productos SET resumen = ? WHERE id = ?")
            ->execute([recortar((string)$p['descripcion'], 160), $p['id']]);
    }

    // -----------------------------------------------------------------
    // Galería por producto
    // -----------------------------------------------------------------
    $e->sql("CREATE TABLE IF NOT EXISTS producto_imagenes (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        producto_id INT NOT NULL,
        ruta        VARCHAR(255) NOT NULL,
        alt         VARCHAR(150) NOT NULL DEFAULT '',
        orden       SMALLINT NOT NULL DEFAULT 0,
        created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_imagen_producto FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE CASCADE,
        INDEX idx_imagenes_producto (producto_id, orden)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Traslado de imagen…imagen4 a la tabla nueva. Solo la primera vez:
    // si el producto ya tiene filas, no se duplica nada.
    $yaTiene = $pdo->prepare("SELECT COUNT(*) FROM producto_imagenes WHERE producto_id = ?");
    $insertar = $pdo->prepare("INSERT INTO producto_imagenes (producto_id, ruta, alt, orden) VALUES (?, ?, ?, ?)");

    foreach ($pdo->query("SELECT id, nombre, imagen, imagen2, imagen3, imagen4 FROM productos")->fetchAll() as $p) {
        $yaTiene->execute([$p['id']]);
        if ((int)$yaTiene->fetchColumn() > 0) {
            continue;
        }
        $orden = 0;
        foreach (['imagen', 'imagen2', 'imagen3', 'imagen4'] as $columna) {
            $ruta = trim((string)($p[$columna] ?? ''));
            if ($ruta !== '') {
                $insertar->execute([$p['id'], $ruta, (string)$p['nombre'], $orden++]);
            }
        }
    }
};
