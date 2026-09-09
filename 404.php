<?php
/**
 * Página de error 404 amigable.
 *
 * El .htaccess la sirve por reescritura, no con `ErrorDocument`: la ruta de
 * `ErrorDocument` es absoluta desde la raíz del dominio y cambiaría según se
 * instale el sitio en la raíz o en una subcarpeta. El estado 404 lo pone esta
 * página, así que hay que dejar la línea de abajo donde está.
 */

declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

http_response_code(404);
$tituloPagina = 'Página no encontrada';
require __DIR__ . '/includes/vistas/cabecera.php';
?>
<div class="container">
  <div class="estado-vacio">
    <i class="fa-solid fa-seedling" aria-hidden="true"></i>
    <h1>No encontramos esa página</h1>
    <p>Puede que el enlace esté incompleto o que hayamos movido el contenido.
       Desde el catálogo llegas a todo.</p>
    <div class="estado-vacio-acciones">
      <a class="btn btn-primary" href="<?= e(url('productos.php')) ?>">Ver los arreglos</a>
      <a class="btn btn-outline-dark" href="<?= e(url()) ?>">Ir al inicio</a>
    </div>
  </div>
</div>
<?php require __DIR__ . '/includes/vistas/pie.php'; ?>
