<?php
/**
 * Descarga de un archivo de respaldo. Solo con permiso `respaldos.crear`,
 * y siempre registrada en la auditoría: un volcado contiene todos los datos
 * del negocio y de sus clientes.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

Rbac::exigirPanel();
Rbac::exigir('respaldos.crear');

$id = identificador('id', $_GET);
$st = $pdo->prepare("SELECT * FROM respaldos WHERE id = ?");
$st->execute([$id]);
$respaldo = $st->fetch();

if (!$respaldo) {
    http_response_code(404);
    exit('Respaldo no encontrado.');
}

$ruta = DIR_RESPALDOS . '/' . basename((string)$respaldo['archivo']);
if (!is_file($ruta)) {
    flash('error', 'El archivo ya no está en el servidor.');
    redirigir('admin/respaldos.php');
}

Auditoria::registrar($pdo, 'descargar_respaldo', 'sistema', [
    'recurso_tipo' => 'respaldo', 'recurso_id' => (string)$id,
    'descripcion'  => 'Descarga del respaldo ' . $respaldo['archivo'],
]);

Archivos::servir($ruta, 'application/sql', (string)$respaldo['archivo'], true);
