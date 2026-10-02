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
require_once __DIR__ . '/includes/lib/miniaturas.php';

$id    = (int)($_GET['id'] ?? 0);
$ancho = (int)($_GET['w'] ?? 0);

if ($id <= 0) {
    http_response_code(404);
    exit;
}
if ($ancho > 0 && !in_array($ancho, Miniaturas::ANCHOS, true)) {
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
$convertible = Miniaturas::convertible((string)$meta['mime']);
$salida      = ($aceptaWebp && $convertible) ? 'image/webp' : (string)$meta['mime'];

// El sufijo distingue las copias en disco: sin él, la versión WebP y la
// original de un mismo ancho compartirían archivo y se serviría una por otra.
$sufijo = Miniaturas::sufijo($ancho, $salida !== $meta['mime']);

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
    $carpeta = Miniaturas::carpeta();
    $cache   = Miniaturas::ruta((string)$meta['sha256'], $sufijo);

    if (is_file($cache)) {
        header('Content-Length: ' . (string)filesize($cache));
        readfile($cache);
        exit;
    }

    if (!is_dir($carpeta)) {
        @mkdir($carpeta, 0775, true);
    }

    // Turno para convertir.
    //
    // Una página del catálogo pide una docena de fotos a la vez y, la primera
    // vez, cada una dispara una conversión con GD. En hosting compartido eso
    // supera el límite de procesos del servidor y devuelve 508: la foto no
    // llega y el hueco queda vacío. Con el turno solo convierte una petición
    // a la vez; las demás sirven el original, que pesa más pero se ve. En dos
    // o tres visitas la caché queda completa y ya nadie convierte nada.
    $turno = @fopen($carpeta . '/.turno', 'c');
    $tengoTurno = $turno !== false && @flock($turno, LOCK_EX | LOCK_NB);

    $reducida = $tengoTurno
        ? Miniaturas::transformar($pdo, $id, $ancho, (string)$meta['mime'], $salida)
        : null;

    if ($turno !== false) {
        if ($tengoTurno) {
            @flock($turno, LOCK_UN);
        }
        @fclose($turno);
    }

    if ($reducida !== null) {
        Miniaturas::guardar($cache, $reducida);
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
    // Si no hubo turno, el original sale solo por esta vez y no se guarda: se
    // pidió la copia reducida y el original puede pesar un mega. Antes salía
    // con caché de un año, así que quien llegaba con la caché del servidor aún
    // vacía (tras un cambio de hosting, por ejemplo) se quedaba con el original
    // pesado para siempre, en su navegador y en el CDN. Si hubo turno y aun así
    // no se pudo reducir, el original es la respuesta buena y sí se guarda.
    if (!$tengoTurno) {
        header('Cache-Control: no-store');
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
