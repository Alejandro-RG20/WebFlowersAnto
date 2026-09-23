<?php
/**
 * AI Manager — endpoint del asistente del panel.
 *
 * Acciones: mensaje, historial, reiniciar, confirmar y cancelar propuestas.
 * Exige sesión de personal y CSRF en todas. Los permisos de cada consulta y
 * de cada cambio los comprueban las herramientas y `IaPropuestas`, con los
 * permisos de la sesión en ese momento.
 *
 * Totalmente separado del asistente de la tienda: otra caja de herramientas,
 * otra conversación en la sesión y otro endpoint. Desde la tienda no se puede
 * llegar aquí, y desde aquí no se tocan carritos.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
foreach (['config', 'cliente', 'registro', 'agente', 'propuestas', 'herramientas_admin'] as $f) {
    require_once __DIR__ . '/../includes/lib/ia/' . $f . '.php';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorJson('Método no permitido', 405);
}
Rbac::exigirPanel(true);
exigirToken();

if (!IaConfig::adminActivo($pdo)) {
    errorJson('El asistente no está disponible. Revisa AI_API_KEY y que la migración 022 esté aplicada.', 503,
              ['codigo' => 'no_disponible']);
}

const CLAVE_ADMIN = 'ia_admin';
const MAX_VISTA_ADMIN = 40;

$accion = opcion('accion', ['mensaje', 'historial', 'reiniciar', 'confirmar', 'cancelar'], 'mensaje');

/** Vuelve a leer el estado real de las propuestas que aparecen en la conversación. */
function refrescarPropuestas(PDO $pdo, array $vista): array
{
    $ids = [];
    foreach ($vista as $m) {
        foreach ($m['propuestas'] ?? [] as $p) {
            $ids[] = (int)$p['id'];
        }
    }
    if (!$ids) {
        return $vista;
    }
    $huecos = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT id, estado, resultado, expira_en < NOW() AS vencida FROM ai_pending_actions
                          WHERE usuario_id = ? AND id IN ($huecos)");
    $st->execute(array_merge([Auth::id()], $ids));
    $estados = array_column($st->fetchAll(), null, 'id');
    foreach ($vista as &$m) {
        foreach ($m['propuestas'] ?? [] as $i => $p) {
            $e = $estados[(int)$p['id']] ?? null;
            $estado = $e ? (string)$e['estado'] : 'caducada';
            if ($estado === 'pendiente' && $e && (int)$e['vencida'] === 1) {
                $estado = 'caducada';
            }
            $m['propuestas'][$i]['estado'] = $estado;
            $m['propuestas'][$i]['resultado'] = $e['resultado'] ?? null;
        }
    }
    return $vista;
}

switch ($accion) {
    case 'historial':
        responderJson(['ok' => true, 'mensajes' => refrescarPropuestas($pdo, $_SESSION[CLAVE_ADMIN]['vista'] ?? [])]);

    case 'reiniciar':
        unset($_SESSION[CLAVE_ADMIN]);
        responderJson(['ok' => true]);

    case 'confirmar':
    case 'cancelar':
        $id = identificador('id');
        if (!limitar($pdo, 'ia-admin-accion:' . Auth::id(), 30, 600)) {
            errorJson('Demasiadas confirmaciones seguidas. Espera un momento.', 429);
        }
        $r = $accion === 'confirmar' ? IaPropuestas::confirmar($pdo, $id) : IaPropuestas::cancelar($pdo, $id);
        responderJson(['ok' => $r['ok'], 'mensaje' => $r['mensaje']]);
}

// --- mensaje ---------------------------------------------------------------
$mensaje = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', '', crudo('mensaje')) ?? '');
if ($mensaje === '') {
    errorJson('Escribe tu pregunta.', 422);
}
if (mb_strlen($mensaje) > 1500) {
    errorJson('El mensaje es muy largo.', 422);
}
if (!limitar($pdo, 'ia-admin:' . Auth::id(), 60, 600)) {
    errorJson('Muchas consultas seguidas. Espera un par de minutos.', 429, ['codigo' => 'limite']);
}

$conversacion = $_SESSION[CLAVE_ADMIN] ?? ['mensajes' => [], 'turnos' => 0, 'vista' => []];
$aviso = '';
if ((int)($conversacion['turnos'] ?? 0) >= IaAgente::MAX_TURNOS) {
    $conversacion = ['mensajes' => [], 'turnos' => 0, 'vista' => []];
    $aviso = 'Empezamos una conversación nueva para mantener las respuestas rápidas.';
}

$caja = new IaHerramientasAdmin($pdo);
$inicio = microtime(true);
IaSesion::soltar();
try {
    $r = IaAgente::responder('admin', $caja, (array)$conversacion['mensajes'], $mensaje);
} catch (IaError $e) {
    IaSesion::retomar();
    IaRegistro::anotar($pdo, 'admin', 'conversacion', ['estado' => 'error', 'detalle' => $e->codigo,
        'ms' => (int)((microtime(true) - $inicio) * 1000)]);
    error_log('Flowers Anto — AI Manager: ' . $e->codigo . ' ' . $e->getMessage());
    errorJson(match ($e->codigo) {
        'config' => 'La clave de la IA no es válida o no tiene permisos. Revisa AI_API_KEY.',
        'tiempo' => 'La consulta tardó demasiado. Prueba con una pregunta más concreta.',
        'limite' => 'La API de IA está limitando las peticiones. Inténtalo en un minuto.',
        default  => 'El asistente no puede responder ahora. El resto del panel funciona con normalidad.',
    }, 503, ['codigo' => 'no_disponible']);
}
IaSesion::retomar();

$efectos = $r['efectos'];
$respuesta = [
    'texto'      => $r['texto'],
    'tabla'      => $efectos['tabla'] ?? null,
    'propuestas' => array_map(fn($p) => $p + ['estado' => 'pendiente'], $efectos['propuestas'] ?? []),
];
$vista = (array)($conversacion['vista'] ?? []);
$vista[] = ['rol' => 'cliente', 'texto' => $mensaje];
$vista[] = ['rol' => 'asistente'] + $respuesta;
$_SESSION[CLAVE_ADMIN] = $r['reiniciar']
    ? ['mensajes' => [], 'turnos' => 0, 'vista' => []]
    : ['mensajes' => $r['historial'], 'turnos' => (int)($conversacion['turnos'] ?? 0) + 1,
       'vista' => array_slice($vista, -MAX_VISTA_ADMIN)];

IaRegistro::anotar($pdo, 'admin', 'conversacion', [
    'estado'         => $r['estado'] === 'error' ? 'error' : ($r['estado'] === 'rechazada' ? 'rechazada' : 'ok'),
    'detalle'        => $r['herramientas'] ? implode(',', array_unique($r['herramientas'])) : null,
    'tokens_entrada' => $r['uso']['entrada'],
    'tokens_salida'  => $r['uso']['salida'],
    'tokens_cache'   => $r['uso']['cache'],
    'ms'             => (int)((microtime(true) - $inicio) * 1000),
]);

responderJson(['ok' => true, 'aviso' => $aviso] + $respuesta);
