<?php
/**
 * Carrito por AJAX. Devuelve el estado actualizado para refrescar el contador
 * sin recargar. Toda la validación es la misma que usa carrito.php.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorJson('Método no permitido', 405);
}
exigirToken();

$accion     = opcion('accion', ['agregar', 'fijar', 'quitar', 'vaciar'], 'agregar');
$productoId = identificador('producto_id');
$aviso      = '';

switch ($accion) {
    case 'agregar':
        $aviso = Carrito::agregar($pdo, $productoId, entero('cantidad', 1, Carrito::MAX_UNIDADES, 1));
        break;
    case 'fijar':
        $aviso = Carrito::fijar($pdo, $productoId, entero('cantidad', 0, Carrito::MAX_UNIDADES, 1));
        break;
    case 'quitar':
        Carrito::quitar($pdo, $productoId);
        break;
    case 'vaciar':
        Carrito::vaciar($pdo);
        break;
}

// Un aviso no es un fallo: la operación se hizo, pero con un ajuste
// (por ejemplo, no había tantas unidades como se pidieron).
$detalle = Carrito::detalle($pdo);

responderJson([
    'ok'       => true,
    'unidades' => $detalle['unidades'],
    'subtotal' => $detalle['subtotal'],
    'total'    => $detalle['total'],
    'aviso'    => $aviso !== '',
    'mensaje'  => $aviso !== '' ? $aviso : match ($accion) {
        'agregar' => 'Añadido al carrito',
        'quitar'  => 'Quitado del carrito',
        'vaciar'  => 'Carrito vacío',
        default   => 'Carrito actualizado',
    },
]);
