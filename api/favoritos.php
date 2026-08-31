<?php
/**
 * Favoritos por AJAX.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorJson('Método no permitido', 405);
}
exigirToken();

$accion = opcion('accion', ['alternar', 'quitar', 'fusionar'], 'alternar');

if ($accion === 'fusionar') {
    // Lista que el navegador guardó mientras el visitante no tenía cuenta.
    $ids = array_slice(array_filter(array_map('intval', explode(',', crudo('ids')))), 0, 100);
    Favoritos::fusionarLista($pdo, $ids);
    $actuales = Favoritos::ids($pdo);
    responderJson(['ok' => true, 'total' => count($actuales), 'ids' => $actuales]);
}

$productoId = identificador('producto_id');
if ($productoId <= 0) {
    errorJson('Producto no válido.');
}

if ($accion === 'quitar') {
    Favoritos::quitar($pdo, $productoId);
    $favorito = false;
} else {
    $favorito = Favoritos::alternar($pdo, $productoId);
}

// Devolvemos la lista completa para que el navegador refleje el servidor
// tal cual, en vez de ir acumulando ids por su cuenta.
$actuales = Favoritos::ids($pdo);
responderJson([
    'ok'       => true,
    'favorito' => $favorito,
    'total'    => count($actuales),
    'ids'      => $actuales,
]);
