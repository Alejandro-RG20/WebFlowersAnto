<?php
/**
 * Manda la siguiente tanda de correos de un aviso masivo y devuelve el avance.
 *
 * Lo llama el panel una vez tras otra mientras enseña la barra de progreso
 * (admin.js). Cada llamada manda unos pocos correos y responde enseguida:
 * así ninguna petición se acerca al límite de tiempo del hosting y el envío
 * se puede retomar en cualquier momento donde quedó.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorJson('Método no permitido.', 405);
}
Rbac::exigirPanel();
Rbac::exigir('clientes.editar');
exigirToken(true);

if (!Campanas::disponible($pdo)) {
    errorJson('Falta aplicar la migración 028 en Base de datos.', 409);
}

$campanaId = identificador('campana');
// Un tope por persona por si una pestaña se quedara llamando sin parar.
if (!limitar($pdo, 'avisos-envio:' . (int)Auth::id(), 240, 600)) {
    errorJson('Demasiadas tandas seguidas. Espera un momento y continúa.', 429);
}

$estado = Campanas::procesar($pdo, $campanaId);
if ($estado === null) {
    errorJson('Ese envío no existe.', 404);
}

responderJson([
    'ok'         => true,
    'con_correo' => $estado['con_correo'],
    'enviados'   => $estado['enviados'],
    'fallidos'   => $estado['fallidos'],
    'pendientes' => $estado['pendientes'],
    'terminado'  => $estado['pendientes'] === 0,
]);
