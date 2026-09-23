<?php
/**
 * Asistente de compras — endpoint.
 *
 * Recibe un solo mensaje del cliente y devuelve la respuesta, las tarjetas de
 * producto que la acompañan y, si cambió, el estado del carrito. El historial
 * de la conversación vive en la sesión del servidor: el navegador no lo manda
 * ni lo puede tocar.
 *
 * Si la IA no está configurada, falla o tarda demasiado, se responde con un
 * mensaje amable y el resto de la tienda sigue funcionando igual: este
 * endpoint no participa en ningún otro flujo.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
foreach (['config', 'cliente', 'registro', 'agente', 'herramientas_cliente'] as $f) {
    require_once __DIR__ . '/../includes/lib/ia/' . $f . '.php';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorJson('Método no permitido', 405);
}
exigirToken();

if (!IaConfig::clienteActivo($pdo)) {
    errorJson('El asistente no está disponible ahora mismo.', 503, ['codigo' => 'no_disponible']);
}

const CLAVE_SESION = 'ia_cliente';

/** Mensajes que se vuelven a pintar al cambiar de página. */
const MAX_VISTA = 30;

$accion = opcion('accion', ['mensaje', 'reiniciar', 'historial'], 'mensaje');
if ($accion === 'reiniciar') {
    unset($_SESSION[CLAVE_SESION]);
    responderJson(['ok' => true]);
}
// La conversación sigue al cambiar de página. Se devuelve lo que se pintó
// —texto y tarjetas—, no el historial técnico con los bloques del modelo.
if ($accion === 'historial') {
    responderJson(['ok' => true, 'mensajes' => $_SESSION[CLAVE_SESION]['vista'] ?? []]);
}

// El mensaje del cliente: texto plano, sin caracteres de control, con tope.
$mensaje = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', '', crudo('mensaje')) ?? '');
if ($mensaje === '') {
    errorJson('Escribe tu pregunta.', 422);
}
if (mb_strlen($mensaje) > 800) {
    errorJson('Tu mensaje es muy largo. Resúmelo en unas líneas.', 422);
}

// Tres frenos: por conversación, por conexión y para toda la tienda al día.
// El último es el techo de gasto: pase lo que pase, la factura de la API no
// puede crecer más allá de lo previsto.
$sesionId = session_id();
if (!limitar($pdo, 'ia-sesion:' . $sesionId, 20, 600)
    || !limitar($pdo, 'ia-ip:' . ip_cliente(), 40, 600)) {
    IaRegistro::anotar($pdo, 'cliente', 'conversacion', ['estado' => 'denegado', 'detalle' => 'límite de mensajes']);
    errorJson('Vas muy rápido. Espera un par de minutos y seguimos.', 429, ['codigo' => 'limite']);
}
if (IaConfig::limiteDiario() > 0
    && !limitar($pdo, 'ia-dia:' . date('Y-m-d'), IaConfig::limiteDiario(), 86400)) {
    IaRegistro::anotar($pdo, 'cliente', 'conversacion', ['estado' => 'denegado', 'detalle' => 'tope diario']);
    errorJson('El asistente descansa por hoy. Escríbenos por WhatsApp y te atendemos.', 503, ['codigo' => 'no_disponible']);
}

$conversacion = $_SESSION[CLAVE_SESION] ?? ['mensajes' => [], 'turnos' => 0, 'vista' => []];
$aviso = '';
if ((int)($conversacion['turnos'] ?? 0) >= IaAgente::MAX_TURNOS) {
    $conversacion = ['mensajes' => [], 'turnos' => 0, 'vista' => []];
    $aviso = 'Empezamos una conversación nueva para mantener las respuestas rápidas.';
}

$caja   = new IaHerramientasCliente($pdo);
$inicio = microtime(true);

// Mientras se espera a la API, la sesión queda libre para otras pestañas.
IaSesion::soltar();
try {
    $r = IaAgente::responder('cliente', $caja, (array)$conversacion['mensajes'], $mensaje);
} catch (IaError $e) {
    IaSesion::retomar();
    IaRegistro::anotar($pdo, 'cliente', 'conversacion', [
        'estado' => 'error', 'detalle' => $e->codigo,
        'ms' => (int)((microtime(true) - $inicio) * 1000),
    ]);
    error_log('Flowers Anto — asistente: ' . $e->codigo . ' ' . $e->getMessage());
    errorJson(match ($e->codigo) {
        'tiempo'           => 'Estoy tardando más de la cuenta. Inténtalo otra vez en un momento.',
        'limite'           => 'Tengo muchas consultas a la vez. Inténtalo en un minuto.',
        default            => 'No puedo responder ahora mismo. Puedes seguir comprando con normalidad '
                            . 'o escribirnos por WhatsApp.',
    }, 503, ['codigo' => 'no_disponible']);
}
IaSesion::retomar();

$efectos   = $r['efectos'];
$tarjetas  = $caja->tarjetas($r['texto']);
$respuesta = [
    'texto'     => $r['texto'],
    'productos' => $tarjetas,
    'carrito'   => $efectos['carrito'] ?? null,
    'pedido'    => $efectos['pedido'] ?? null,
];

$vista   = (array)($conversacion['vista'] ?? []);
$vista[] = ['rol' => 'cliente', 'texto' => $mensaje];
$vista[] = ['rol' => 'asistente'] + $respuesta;
$vista   = array_slice($vista, -MAX_VISTA);

$_SESSION[CLAVE_SESION] = $r['reiniciar']
    ? ['mensajes' => [], 'turnos' => 0, 'vista' => []]
    : ['mensajes' => $r['historial'], 'turnos' => (int)($conversacion['turnos'] ?? 0) + 1, 'vista' => $vista];

IaRegistro::anotar($pdo, 'cliente', 'conversacion', [
    'estado'         => $r['estado'] === 'rechazada' ? 'rechazada' : ($r['estado'] === 'error' ? 'error' : 'ok'),
    'detalle'        => $r['herramientas'] ? implode(',', array_unique($r['herramientas'])) : null,
    'tokens_entrada' => $r['uso']['entrada'],
    'tokens_salida'  => $r['uso']['salida'],
    'tokens_cache'   => $r['uso']['cache'],
    'ms'             => (int)((microtime(true) - $inicio) * 1000),
]);

responderJson(['ok' => true, 'aviso' => $aviso] + $respuesta);
