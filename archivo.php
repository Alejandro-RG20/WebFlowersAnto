<?php
/**
 * Sirve una imagen guardada en la base.
 *
 * Guardar los binarios en la base evita tener que arrastrar la carpeta
 * `uploads/` junto al respaldo, pero traslada trabajo a PHP en cada foto. La
 * respuesta a eso es la caché: el contenido de un id NUNCA cambia —subir otra
 * foto crea otro id— así que se puede declarar `immutable` con un año de
 * validez. El navegador pide cada imagen una sola vez en su vida, y quien
 * ponga un CDN delante se ahorra incluso esa.
 *
 * Además se responde a `If-None-Match` con 304, que son 0 bytes de cuerpo,
 * para los intermediarios que revalidan de todas formas.
 */

declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

$id = identificador('id', $_GET);
if ($id <= 0) {
    http_response_code(404);
    exit;
}

// La cabecera va antes que el binario: si el navegador ya la tiene, no hace
// falta leer el BLOB de la base.
$st = $pdo->prepare("SELECT mime, tamano, sha256 FROM archivos WHERE id = ?");
$st->execute([$id]);
$meta = $st->fetch();

if (!$meta) {
    http_response_code(404);
    exit;
}

$etag = '"' . $meta['sha256'] . '"';

header('Content-Type: ' . $meta['mime']);
header('Cache-Control: public, max-age=31536000, immutable');
header('ETag: ' . $etag);
header('X-Content-Type-Options: nosniff');

$recibido = trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
if ($recibido !== '' && (str_contains($recibido, $meta['sha256']) || $recibido === $etag)) {
    http_response_code(304);
    exit;
}

header('Content-Length: ' . (int)$meta['tamano']);

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
