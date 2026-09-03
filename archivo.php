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
 */

declare(strict_types=1);
require_once __DIR__ . '/includes/arranque-minimo.php';

/** Anchos que se pueden pedir. Una lista cerrada evita que alguien llene el
 *  disco pidiendo mil tamaños distintos de la misma foto. */
const ANCHOS = [320, 480, 640, 960, 1280];

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

$etag = '"' . $meta['sha256'] . ($ancho ? '-' . $ancho : '') . '"';

header('Content-Type: ' . $meta['mime']);
header('Cache-Control: public, max-age=31536000, immutable');
header('ETag: ' . $etag);
header('X-Content-Type-Options: nosniff');

$recibido = trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
if ($recibido !== '' && str_contains($recibido, trim($etag, '"'))) {
    http_response_code(304);
    exit;
}

// ---------------------------------------------------------------------
//  Versión reducida
// ---------------------------------------------------------------------
if ($ancho > 0 && function_exists('imagecreatefromstring')) {
    $carpeta = RAIZ . '/storage/cache/img';
    $cache   = $carpeta . '/' . $meta['sha256'] . '-' . $ancho . '.bin';

    if (is_file($cache)) {
        header('Content-Length: ' . (string)filesize($cache));
        readfile($cache);
        exit;
    }

    $reducida = reducir($pdo, $id, $ancho, (string)$meta['mime']);
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
    // Si no se pudo reducir, se sigue y se manda el original.
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
 * Reduce la imagen al ancho pedido. Devuelve el binario, o null si no se pudo.
 */
function reducir(PDO $pdo, int $id, int $ancho, string $mime): ?string
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
    if ($anchoOriginal <= 0 || $ancho >= $anchoOriginal) {
        imagedestroy($img);
        return null;
    }

    $alto    = (int)round($altoOriginal * ($ancho / $anchoOriginal));
    $destino = imagecreatetruecolor($ancho, max(1, $alto));

    // Sin esto, un PNG o un WEBP con fondo transparente sale con fondo negro.
    if ($mime === 'image/png' || $mime === 'image/webp' || $mime === 'image/gif') {
        imagealphablending($destino, false);
        imagesavealpha($destino, true);
        imagefill($destino, 0, 0, imagecolorallocatealpha($destino, 0, 0, 0, 127));
    }

    imagecopyresampled($destino, $img, 0, 0, 0, 0, $ancho, max(1, $alto), $anchoOriginal, $altoOriginal);
    imagedestroy($img);

    ob_start();
    $ok = match ($mime) {
        'image/png'  => imagepng($destino, null, 7),
        'image/webp' => function_exists('imagewebp') ? imagewebp($destino, null, 82) : imagejpeg($destino, null, 82),
        'image/gif'  => imagegif($destino),
        default      => imagejpeg($destino, null, 82),
    };
    $bytes = (string)ob_get_clean();
    imagedestroy($destino);

    return $ok && $bytes !== '' ? $bytes : null;
}
