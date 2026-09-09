<?php
/**
 * Sirve una imagen guardada en la base.
 *
 * Guardar los binarios en la base evita arrastrar la carpeta `uploads/` junto
 * al respaldo, pero traslada trabajo a PHP en cada foto. Tres cosas lo
 * compensan:
 *
 *   1. Arranque mínimo. Este archivo no necesita sesión, ni permisos, ni
 *      ajustes: solo entorno y base. Una página de catálogo pide una docena de
 *      fotos, y arrancar el proyecto entero doce veces se notaba.
 *   2. Caché inmutable. El contenido de un id NUNCA cambia —subir otra foto
 *      crea otro id— así que se declara `immutable` con un año de validez y el
 *      navegador pide cada imagen una sola vez en su vida.
 *   3. Tamaños. Con `?w=` se sirve una versión reducida, que es lo que se
 *      manda a un teléfono. La reducción se hace una vez y queda en disco;
 *      las siguientes visitas se sirven desde ahí sin tocar la base.
 *   4. Formato. Al navegador que dice entender WebP se le manda WebP, aunque
 *      en la base esté guardado como PNG o JPEG. Las fotos de producto son
 *      recortes PNG con fondo transparente de más de un mega; en WebP pesan
 *      una décima parte y la transparencia se conserva igual. El original no
 *      se toca nunca: si el navegador no pide WebP, recibe lo de siempre.
 */

declare(strict_types=1);
require_once __DIR__ . '/includes/arranque-minimo.php';

/** Anchos que se pueden pedir. Una lista cerrada evita que alguien llene el
 *  disco pidiendo mil tamaños distintos de la misma foto. */
const ANCHOS = [160, 320, 480, 640, 960, 1280];

$id    = (int)($_GET['id'] ?? 0);
$ancho = (int)($_GET['w'] ?? 0);

if ($id <= 0) {
    http_response_code(404);
    exit;
}
if ($ancho > 0 && !in_array($ancho, ANCHOS, true)) {
    $ancho = 0;   // un ancho que no está en la lista se sirve como el original
}

// La cabecera va antes que el binario: si el navegador ya lo tiene, no hace
// falta leer nada más.
$st = $pdo->prepare("SELECT mime, tamano, ancho, sha256 FROM archivos WHERE id = ?");
$st->execute([$id]);
$meta = $st->fetch();

if (!$meta) {
    http_response_code(404);
    exit;
}

// Pedir una versión más grande que el original no tiene sentido: se sirve el
// original y así no se guardan copias infladas.
if ($ancho > 0 && (int)$meta['ancho'] > 0 && $ancho >= (int)$meta['ancho']) {
    $ancho = 0;
}

// ---------------------------------------------------------------------
//  Formato de salida
// ---------------------------------------------------------------------
// Solo se transcodifica lo que se gana: una foto JPEG o PNG. El GIF queda
// fuera a propósito, porque puede estar animado y GD lo aplastaría a un solo
// fotograma; el SVG y el WEBP ya están bien como están.
$aceptaWebp  = str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'image/webp');
$convertible = in_array($meta['mime'], ['image/jpeg', 'image/png'], true)
               && function_exists('imagewebp');
$salida      = ($aceptaWebp && $convertible) ? 'image/webp' : (string)$meta['mime'];

// El sufijo distingue las copias en disco: sin él, la versión WebP y la
// original de un mismo ancho compartirían archivo y se serviría una por otra.
$sufijo = ($ancho > 0 ? '-' . $ancho : '-orig')
        . ($salida !== $meta['mime'] ? '.webp' : '');

$etag = '"' . $meta['sha256'] . $sufijo . '"';

header('Content-Type: ' . $salida);
header('Cache-Control: public, max-age=31536000, immutable');
// La respuesta depende de lo que el navegador diga aceptar, así que ningún
// intermediario debe reutilizar una copia para otro que pida algo distinto.
header('Vary: Accept');
header('ETag: ' . $etag);
header('X-Content-Type-Options: nosniff');

$recibido = trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
if ($recibido !== '' && str_contains($recibido, trim($etag, '"'))) {
    http_response_code(304);
    exit;
}

// ---------------------------------------------------------------------
//  Versión reducida o convertida
// ---------------------------------------------------------------------
$hayQueTrabajar = ($ancho > 0 || $salida !== $meta['mime'])
                  && function_exists('imagecreatefromstring');

if ($hayQueTrabajar) {
    $carpeta = RAIZ . '/storage/cache/img';
    $cache   = $carpeta . '/' . $meta['sha256'] . $sufijo . '.bin';

    if (is_file($cache)) {
        header('Content-Length: ' . (string)filesize($cache));
        readfile($cache);
        exit;
    }

    $reducida = transformar($pdo, $id, $ancho, (string)$meta['mime'], $salida);
    if ($reducida !== null) {
        if (!is_dir($carpeta)) {
            @mkdir($carpeta, 0775, true);
        }
        // Se escribe con nombre temporal y se renombra: dos visitas a la vez no
        // pueden dejar un archivo a medias que luego se sirva roto.
        $temp = $cache . '.' . bin2hex(random_bytes(4));
        if (@file_put_contents($temp, $reducida) !== false) {
            @rename($temp, $cache);
        }
        header('Content-Length: ' . (string)strlen($reducida));
        echo $reducida;
        exit;
    }
    // Si no se pudo transformar se manda el original, y entonces la cabecera
    // de tipo que se anunció arriba deja de ser cierta: hay que corregirla.
    if ($salida !== $meta['mime']) {
        header('Content-Type: ' . $meta['mime']);
        header('ETag: "' . $meta['sha256'] . '-orig"');
    }
}

// ---------------------------------------------------------------------
//  Original
// ---------------------------------------------------------------------
header('Content-Length: ' . (string)(int)$meta['tamano']);

// El BLOB se pide aparte y como flujo, para no cargarlo entero en memoria de
// PHP cuando la imagen es grande.
$blob = $pdo->prepare("SELECT datos FROM archivos WHERE id = ?");
$blob->bindValue(1, $id, PDO::PARAM_INT);
$blob->execute();
$blob->bindColumn(1, $flujo, PDO::PARAM_LOB);
$blob->fetch(PDO::FETCH_BOUND);

if (is_resource($flujo)) {
    fpassthru($flujo);
} else {
    echo (string)$flujo;
}

/**
 * Devuelve la imagen en el ancho y el formato pedidos, o null si no se pudo.
 *
 * Un ancho de 0 significa «no cambies el tamaño»: se usa cuando lo único que
 * hace falta es cambiar de formato. La transparencia se conserva siempre; es
 * lo que permite mandar en WebP los recortes de producto que están en PNG.
 */
function transformar(PDO $pdo, int $id, int $ancho, string $mimeOrigen, string $mimeSalida): ?string
{
    $st = $pdo->prepare("SELECT datos FROM archivos WHERE id = ?");
    $st->execute([$id]);
    $original = (string)$st->fetchColumn();
    if ($original === '') {
        return null;
    }

    // Un límite de memoria escaso es lo normal en hosting compartido: si la
    // foto no cabe, se manda el original en vez de tumbar la petición.
    $limite = ini_get('memory_limit');
    if ($limite !== false && $limite !== '-1'
        && (int)$limite > 0 && strlen($original) * 12 > (int)$limite * 1048576) {
        return null;
    }

    $img = @imagecreatefromstring($original);
    if ($img === false) {
        return null;
    }

    $anchoOriginal = imagesx($img);
    $altoOriginal  = imagesy($img);
    if ($anchoOriginal <= 0 || $altoOriginal <= 0) {
        imagedestroy($img);
        return null;
    }

    // Sin reducción posible y sin cambio de formato no hay nada que hacer:
    // que el llamador mande el original tal cual, que siempre será mejor.
    $reduce = $ancho > 0 && $ancho < $anchoOriginal;
    if (!$reduce && $mimeSalida === $mimeOrigen) {
        imagedestroy($img);
        return null;
    }

    $anchoFinal = $reduce ? $ancho : $anchoOriginal;
    $altoFinal  = $reduce
        ? max(1, (int)round($altoOriginal * ($ancho / $anchoOriginal)))
        : $altoOriginal;

    $destino = imagecreatetruecolor($anchoFinal, $altoFinal);

    // Sin esto, un PNG o un WEBP con fondo transparente sale con fondo negro.
    // Se mira el formato de origen y el de salida: basta con que uno de los
    // dos maneje transparencia para tener que conservarla.
    $conAlfa = in_array($mimeOrigen, ['image/png', 'image/webp', 'image/gif'], true)
            || in_array($mimeSalida, ['image/png', 'image/webp'], true);
    if ($conAlfa) {
        imagealphablending($destino, false);
        imagesavealpha($destino, true);
        imagefill($destino, 0, 0, imagecolorallocatealpha($destino, 0, 0, 0, 127));
    }

    imagecopyresampled($destino, $img, 0, 0, 0, 0,
        $anchoFinal, $altoFinal, $anchoOriginal, $altoOriginal);
    imagedestroy($img);

    ob_start();
    $ok = match ($mimeSalida) {
        'image/webp' => imagewebp($destino, null, 82),
        'image/png'  => imagepng($destino, null, 7),
        'image/gif'  => imagegif($destino),
        default      => imagejpeg($destino, null, 82),
    };
    $bytes = (string)ob_get_clean();
    imagedestroy($destino);

    return $ok && $bytes !== '' ? $bytes : null;
}
