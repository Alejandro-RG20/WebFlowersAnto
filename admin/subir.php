<?php
/**
 * Subida de imágenes del panel (AJAX).
 * El tipo se decide por el contenido del archivo, nunca por su extensión.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorJson('Método no permitido', 405);
}

Rbac::exigirPanel(true);
exigirToken();

if (!Rbac::puedeAlguno('productos.crear', 'productos.editar', 'categorias.gestionar',
                       'temporadas.gestionar', 'galeria.gestionar', 'configuracion.editar')) {
    Auditoria::denegado($pdo, 'subida de imágenes', 'sistema');
    errorJson('No tienes permiso para subir imágenes.', 403);
}

$resultado = Archivos::guardarImagen($_FILES['imagen'] ?? []);
if (!$resultado['ok']) {
    errorJson($resultado['error']);
}

Auditoria::registrar($pdo, 'subir_imagen', 'sistema', [
    'recurso_tipo' => 'archivo',
    'recurso_id'   => basename($resultado['ruta']),
    'descripcion'  => 'Imagen subida al catálogo.',
]);

responderJson($resultado);
