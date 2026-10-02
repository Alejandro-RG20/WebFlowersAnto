<?php
/**
 * Sirve una foto de perfil.
 *
 * Solo a la propia persona o al personal con permiso para ver clientes. A
 * cualquier otro se le responde 404, igual que si no existiera: un 403 diría
 * que esa cuenta tiene foto.
 *
 * `Cache-Control: private` impide que el CDN o un proxy guarden una copia y
 * se la den a otro. La dirección lleva la huella de la foto (`?v=`), así que
 * el navegador puede quedársela un año: al cambiar la foto cambia la
 * dirección.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

$usuario = Auth::usuario();
$id      = (int)($_GET['u'] ?? ($usuario['id'] ?? 0));

$permitido = $usuario !== null && FotoPerfil::disponible($pdo)
          && ($id === (int)$usuario['id'] || (Auth::esPersonal() && Rbac::puede('clientes.ver')));

$foto = null;
if ($permitido && $id > 0) {
    $st = $pdo->prepare("SELECT mime, sha256, datos FROM fotos_perfil WHERE usuario_id = ?");
    $st->execute([$id]);
    $foto = $st->fetch() ?: null;
}
// La sesión no hace falta para enviar los bytes: se suelta ya para no
// bloquear otras peticiones del mismo navegador mientras tanto.
session_write_close();

if (!$foto || !in_array($foto['mime'], ['image/webp', 'image/jpeg'], true)) {
    http_response_code(404);
    header('Cache-Control: no-store');
    exit;
}

$etag = '"' . $foto['sha256'] . '"';
header('Content-Type: ' . $foto['mime']);
header('Cache-Control: private, max-age=31536000, immutable');
header('ETag: ' . $etag);
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; sandbox");

if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    exit;
}
header('Content-Length: ' . strlen((string)$foto['datos']));
echo $foto['datos'];
