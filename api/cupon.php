<?php
/**
 * Aplica o quita un cupón por AJAX y devuelve el resumen recalculado.
 *
 * En la sesión se guarda solo el código. El descuento y el total que se
 * devuelven se calculan aquí a partir del carrito y de la base, y el checkout
 * los vuelve a calcular por su cuenta al registrar el pedido: esta respuesta
 * es para pintar, no para cobrar.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorJson('Método no permitido', 405);
}
exigirToken();

if (!Cupones::activos()) {
    errorJson('Los cupones no están disponibles en este momento.', 403);
}

// Unos pocos intentos por conexión: probar códigos a ciegas hasta acertar es
// la única forma de sacarle provecho a esto, y así deja de compensar.
if (!limitar($pdo, 'cupon:' . ip_cliente(), 12, 600)) {
    errorJson('Demasiados intentos seguidos. Espera unos minutos.', 429);
}

// El correo identifica al cliente para el límite «un uso por persona». Si
// todavía no lo escribió, el límite se comprueba igual al registrar el pedido.
$usuario = Auth::usuario();
$correo  = (string)($usuario['email'] ?? correoValido('cliente_email'));

$zona    = Envios::zona($pdo, identificador('zona_envio_id'));
$entrega = opcion('entrega_tipo', ['domicilio', 'retiro'], 'domicilio');
$base    = Carrito::detalle($pdo, $zona, $entrega);

if (!$base['items']) {
    errorJson('Tu carrito está vacío.', 400);
}

$respuesta = static function (array $detalle, string $mensaje = ''): never {
    responderJson([
        'ok'        => true,
        'aplicado'  => $detalle['cupon'] !== null,
        'codigo'    => (string)($detalle['cupon']['codigo'] ?? ''),
        'resumen'   => $detalle['cupon'] ? Cupones::resumen($detalle['cupon']) : '',
        'descuento' => $detalle['descuento'],
        'envio'     => $detalle['envio'],
        'total'     => $detalle['total'],
        'texto'     => [
            'descuento' => dinero($detalle['descuento']),
            'envio'     => $detalle['envio'] > 0 ? dinero($detalle['envio']) : 'Gratis',
            'total'     => dinero($detalle['total']),
        ],
        'mensaje'   => $mensaje,
    ]);
};

if (opcion('accion', ['aplicar', 'quitar'], 'aplicar') === 'quitar') {
    unset($_SESSION['cupon']);
    $respuesta($base, 'Quitamos el cupón.');
}

$revision = Cupones::revisar(
    $pdo,
    crudo('cupon'),
    $base['subtotal'],
    $base['envio'],
    Auth::id(),
    $correo
);

if (!$revision['ok']) {
    unset($_SESSION['cupon']);
    errorJson($revision['error'], 422);
}

$_SESSION['cupon'] = (string)$revision['cupon']['codigo'];
$detalle = Carrito::detalle($pdo, $zona, $entrega, $revision['cupon']);

$respuesta($detalle, 'Cupón aplicado: ' . Cupones::resumen($revision['cupon']) . '.');
