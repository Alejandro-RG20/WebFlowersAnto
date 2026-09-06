<?php
/**
 * robots.txt generado por PHP.
 *
 * Existe por una sola razón: la línea `Sitemap:` necesita una dirección
 * absoluta, y escrita a mano se queda vieja. El archivo estático apuntaba a un
 * dominio que ya no era el de la tienda, así que Google pedía el sitemap a un
 * sitio equivocado y no encontraba nada.
 *
 * NO arranca la aplicación a propósito. Un robots.txt que responde con un
 * error del servidor es peor que uno desactualizado: Google lo lee como «no
 * rastrees nada» y la tienda se cae de las búsquedas. Si esto cargara el
 * arranque normal, un rato de base de datos caída se llevaría por delante el
 * posicionamiento del sitio —comprobado: devolvía 503—.
 *
 * Todo lo que necesita está en la propia petición, así que no depende de nada
 * que se pueda romper.
 */

declare(strict_types=1);

http_response_code(200);
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: public, max-age=86400');

// La dirección del sitio, tal y como la pidió quien está leyendo esto.
$esquema = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https' : 'http';

// El Host viene del cliente: se acota a lo que puede ser un nombre de dominio
// para que nadie inyecte otra cosa en la línea del sitemap.
$host = (string)($_SERVER['HTTP_HOST'] ?? '');
if (!preg_match('/^[A-Za-z0-9.:-]{1,253}$/', $host)) {
    $host = 'localhost';
}

// La subcarpeta, si el sitio no cuelga de la raíz del dominio.
$base = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
if ($base === '.' || $base === '/') {
    $base = '';
}

$privadas = [
    '/admin/', '/cuenta/', '/api/', '/storage/', '/includes/', '/db/',
    '/instalar.php', '/checkout.php', '/carrito.php', '/pedido.php',
    '/factura.php', '/comprobante.php',
];

echo "# Flowers Anto\n";
echo "User-agent: *\n";
echo "Allow: /\n\n";

echo "# Zonas privadas o sin valor para los buscadores\n";
foreach ($privadas as $ruta) {
    echo 'Disallow: ' . $base . $ruta . "\n";
}

echo "\n# Las fotos del catálogo sí se dejan rastrear: en una floristería la\n";
echo "# búsqueda de imágenes es una puerta de entrada real, y las fotos se\n";
echo "# sirven desde archivo.php.\n";
echo 'Allow: ' . $base . "/archivo.php\n\n";

echo 'Sitemap: ' . $esquema . '://' . $host . $base . "/sitemap.php\n";
