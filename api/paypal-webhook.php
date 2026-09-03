<?php
/**
 * Avisos de PayPal (webhooks).
 *
 * Sirve para enterarse de lo que pasa DESPUÉS del cobro sin tener que mirar el
 * panel de PayPal: un reembolso, una contracarga, un pago que se cae. PayPal
 * llama a esta dirección y la web anota el cambio en el pedido y en su
 * historial, para que el equipo lo vea donde ya está mirando.
 *
 * Nada de lo que llegue aquí se cree sin comprobarlo: es PayPal quien valida
 * la firma del aviso. Sin firma válida, se responde 400 y no se toca nada.
 *
 * Configuración: en developer.paypal.com → tu aplicación → Webhooks, se añade
 * la URL {sitio}/api/paypal-webhook.php y se copia el «Webhook ID» al panel.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$crudo = (string)file_get_contents('php://input');
if ($crudo === '' || strlen($crudo) > 200000) {
    http_response_code(400);
    exit;
}

if (!PayPal::firmaValida(function_exists('getallheaders') ? getallheaders() : $_SERVER, $crudo)) {
    error_log('Flowers Anto — aviso de PayPal con firma no válida desde ' . ip_cliente());
    http_response_code(400);
    exit;
}

$evento = json_decode($crudo, true);
$tipo   = (string)($evento['event_type'] ?? '');
$rec    = $evento['resource'] ?? [];

// El identificador de la captura es lo que ata el aviso con nuestro pedido.
// Según el evento viene como `id` (la captura) o dentro de los enlaces.
$captura = (string)($rec['id'] ?? '');
if ($tipo === 'PAYMENT.CAPTURE.REFUNDED' || $tipo === 'PAYMENT.CAPTURE.REVERSED') {
    foreach ((array)($rec['links'] ?? []) as $enlace) {
        if (($enlace['rel'] ?? '') === 'up'
            && preg_match('#/captures/([A-Za-z0-9_-]{1,50})#', (string)($enlace['href'] ?? ''), $m)) {
            $captura = $m[1];
            break;
        }
    }
}

$nuevoEstado = match ($tipo) {
    'PAYMENT.CAPTURE.COMPLETED' => 'completado',
    'PAYMENT.CAPTURE.PENDING'   => 'pendiente',
    'PAYMENT.CAPTURE.DENIED'    => 'denegado',
    'PAYMENT.CAPTURE.REFUNDED'  => 'reembolsado',
    'PAYMENT.CAPTURE.REVERSED'  => 'revertido',
    default                     => '',
};

// Un evento que no nos toca se acepta igual: si se respondiera con error,
// PayPal lo reintentaría durante días para nada.
if ($nuevoEstado === '' || $captura === '') {
    http_response_code(200);
    exit;
}

// `ORDER BY id DESC` para que el resultado no dependa del orden del motor:
// dos filas con la misma captura solo pasan por un error de datos, pero si
// pasa, el aviso tiene que caer siempre en el mismo pedido.
$st = $pdo->prepare("SELECT * FROM pedidos WHERE paypal_captura_id = ? ORDER BY id DESC LIMIT 1");
$st->execute([$captura]);
$pedido = $st->fetch();

if (!$pedido) {
    // Puede ser un cobro que se quedó sin pedido. Queda anotado para poder
    // buscarlo: es dinero real que alguien tiene que revisar.
    Auditoria::registrar($pdo, 'aviso_sin_pedido', 'paypal', [
        'resultado'    => 'fallo',
        'recurso_tipo' => 'paypal', 'recurso_id' => $captura,
        'usuario_texto'=> 'PayPal',
        'descripcion'  => 'Aviso «' . $tipo . '» de un cobro que no está en ningún pedido.',
    ]);
    http_response_code(200);
    exit;
}

if ((string)$pedido['paypal_estado'] === $nuevoEstado) {
    http_response_code(200);   // ya estaba anotado; PayPal reintenta y no pasa nada
    exit;
}

$pdo->prepare("UPDATE pedidos SET paypal_estado = ? WHERE id = ?")
    ->execute([$nuevoEstado, $pedido['id']]);

// Un reembolso o una reversión dejan el pedido sin pago: eso el equipo tiene
// que verlo en el pedido, no solo en la auditoría.
if (in_array($nuevoEstado, ['reembolsado', 'revertido', 'denegado'], true)) {
    $pdo->prepare("UPDATE pedidos SET estado_pago = ? WHERE id = ?")
        ->execute([Pedidos::PAGO_RECHAZADO, $pedido['id']]);
    Pedidos::anotarHistorial(
        $pdo, (int)$pedido['id'], 'pago', (string)$pedido['estado_pago'], Pedidos::PAGO_RECHAZADO,
        'PayPal avisó de un cobro ' . $nuevoEstado . ' (captura ' . $captura . ').',
        null, 'PayPal'
    );
} else {
    Pedidos::anotarHistorial(
        $pdo, (int)$pedido['id'], 'pago', (string)$pedido['estado_pago'], (string)$pedido['estado_pago'],
        'PayPal confirmó el cobro ' . $captura . '.', null, 'PayPal'
    );
}

Auditoria::registrar($pdo, 'aviso', 'paypal', [
    'recurso_tipo' => 'pedido', 'recurso_id' => (string)$pedido['id'],
    'usuario_texto'=> 'PayPal',
    'descripcion'  => 'Aviso «' . $tipo . '» sobre el pedido ' . $pedido['codigo'] . '.',
    'detalles'     => ['captura' => $captura, 'estado' => $nuevoEstado],
]);

http_response_code(200);
