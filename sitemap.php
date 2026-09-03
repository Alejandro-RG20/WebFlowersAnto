<?php
/**
 * Mapa del sitio. Se genera a partir del catálogo, así que un producto nuevo
 * aparece sin que nadie tenga que acordarse de nada.
 */

declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=3600');

$urls = [
    ['loc' => url_absoluta(),                 'prioridad' => '1.0', 'frecuencia' => 'daily'],
    ['loc' => url_absoluta('productos.php'),  'prioridad' => '0.9', 'frecuencia' => 'daily'],
    ['loc' => url_absoluta('seguimiento.php'),'prioridad' => '0.3', 'frecuencia' => 'yearly'],
    ['loc' => url_absoluta('legal.php?doc=privacidad'),   'prioridad' => '0.2', 'frecuencia' => 'yearly'],
    ['loc' => url_absoluta('legal.php?doc=terminos'),     'prioridad' => '0.2', 'frecuencia' => 'yearly'],
    ['loc' => url_absoluta('legal.php?doc=devoluciones'), 'prioridad' => '0.2', 'frecuencia' => 'yearly'],
];

foreach (Catalogo::categorias($pdo) as $c) {
    $urls[] = [
        'loc'        => url_absoluta('productos.php?categoria=' . rawurlencode((string)$c['slug'])),
        'prioridad'  => '0.7',
        'frecuencia' => 'weekly',
    ];
}

$productos = $pdo->query(
    "SELECT slug, updated_at FROM productos WHERE activo = 1 ORDER BY updated_at DESC LIMIT 2000"
)->fetchAll();

foreach ($productos as $p) {
    $urls[] = [
        'loc'        => url_absoluta('producto.php?p=' . rawurlencode((string)$p['slug'])),
        'prioridad'  => '0.8',
        'frecuencia' => 'weekly',
        'fecha'      => date('Y-m-d', strtotime((string)$p['updated_at'])),
    ];
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($urls as $u) {
    echo "  <url>\n";
    echo '    <loc>' . e($u['loc']) . "</loc>\n";
    if (!empty($u['fecha'])) {
        echo '    <lastmod>' . e($u['fecha']) . "</lastmod>\n";
    }
    echo '    <changefreq>' . e($u['frecuencia']) . "</changefreq>\n";
    echo '    <priority>' . e($u['prioridad']) . "</priority>\n";
    echo "  </url>\n";
}
echo '</urlset>';
