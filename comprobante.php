<?php
/**
 * Entrega un comprobante de pago.
 *
 * Los archivos viven en storage/comprobantes/, fuera del alcance directo del
 * navegador. Esta es la única puerta, y solo abre para el dueño del pedido
 * (por sesión o por el enlace firmado) o para personal con `pagos.revisar`.
 */

declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

$id     = identificador('id', $_GET);
$codigo = texto('codigo', 20, $_GET);
$token  = texto('t', 40, $_GET);

if ($id <= 0) {
    http_response_code(400);
    exit('Solicitud incompleta.');
}

$st = $pdo->prepare(
    "SELECT c.*, p.codigo, p.usuario_id, p.token_seguimiento
       FROM pedido_comprobantes c
       JOIN pedidos p ON p.id = c.pedido_id
      WHERE c.id = ?"
);
$st->execute([$id]);
$comprobante = $st->fetch();

if (!$comprobante) {
    http_response_code(404);
    exit('Comprobante no encontrado.');
}

$autorizado = Rbac::puede('pagos.revisar')
    || (Auth::id() !== null && (int)$comprobante['usuario_id'] === Auth::id())
    || ($token !== '' && $comprobante['token_seguimiento'] !== ''
        && hash_equals((string)$comprobante['token_seguimiento'], $token)
        && hash_equals((string)$comprobante['codigo'], $codigo));

if (!$autorizado) {
    Auditoria::registrar($pdo, 'acceso_denegado', 'pedidos', [
        'resultado'    => 'denegado',
        'recurso_tipo' => 'comprobante',
        'recurso_id'   => (string)$id,
        'descripcion'  => 'Intento de abrir un comprobante sin autorización.',
    ]);
    http_response_code(403);
    exit('No tienes permiso para ver este comprobante.');
}

if (Rbac::puede('pagos.revisar')) {
    Auditoria::registrar($pdo, 'ver_comprobante', 'pedidos', [
        'recurso_tipo' => 'comprobante',
        'recurso_id'   => (string)$id,
        'descripcion'  => 'Consulta del comprobante del pedido ' . $comprobante['codigo'],
    ]);
}

$extension = pathinfo((string)$comprobante['archivo'], PATHINFO_EXTENSION);
Archivos::servir(
    DIR_COMPROBANTES . '/' . basename((string)$comprobante['archivo']),
    (string)$comprobante['mime'],
    'comprobante-' . $comprobante['codigo'] . '.' . ($extension !== '' ? $extension : 'bin'),
    casilla('descargar', $_GET) === 1
);
