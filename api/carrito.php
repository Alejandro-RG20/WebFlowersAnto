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
$agregadas  = 0;
$meta       = null;

switch ($accion) {
    case 'agregar':
        // Lo que de verdad entró se mide por la diferencia: un aviso puede
        // ser «ajustamos la cantidad» (sí entró) o «está agotado» (no entró),
        // y solo lo primero es un «añadir al carrito».
        $previas = (int)(Carrito::lineas()[$productoId] ?? 0);
        $aviso = Carrito::agregar($pdo, $productoId, entero('cantidad', 1, Carrito::MAX_UNIDADES, 1));
        $agregadas = max(0, (int)(Carrito::lineas()[$productoId] ?? 0) - $previas);

        // Meta: se manda desde aquí con un identificador y el navegador manda
        // el mismo con ese identificador, para que Meta los cuente como uno.
        if ($agregadas > 0 && MetaPixel::activo()
            && ($p = Catalogo::porIds($pdo, [$productoId])[$productoId] ?? null)) {
            $item = Analitica::item($p, $agregadas);
            $meta = MetaPixel::enRespuesta('add_to_cart', [
                'currency' => Analitica::moneda(),
                'value'    => round((float)$item['price'] * $agregadas, 2),
                'items'    => [$item],
            ]);
        }
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
    'agregadas' => $agregadas,
    'meta'     => $meta,
    'mensaje'  => $aviso !== '' ? $aviso : match ($accion) {
        'agregar' => 'Añadido al carrito',
        'quitar'  => 'Quitado del carrito',
        'vaciar'  => 'Carrito vacío',
        default   => 'Carrito actualizado',
    },
]);
