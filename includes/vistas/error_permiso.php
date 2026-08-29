<?php
/**
 * Pantalla de acceso denegado (403). La usa Rbac::exigir().
 */
declare(strict_types=1);

$tituloPagina = 'No tienes acceso a esta sección';
$cuerpoClase  = 'pagina-simple';
require __DIR__ . '/cabecera.php';
?>
<section class="section">
  <div class="container">
    <div class="estado-vacio estado-vacio--error">
      <i class="fa-solid fa-lock" aria-hidden="true"></i>
      <h1>No tienes acceso a esta sección</h1>
      <p>Tu cuenta no cuenta con el permiso necesario. Si crees que es un error,
         pídele a un administrador que revise tu rol.</p>
      <div class="estado-vacio-acciones">
        <a class="btn btn-secondary" href="<?= e(url('admin/')) ?>">Volver al panel</a>
        <a class="btn btn-outline-dark" href="<?= e(url()) ?>">Ir al sitio</a>
      </div>
    </div>
  </div>
</section>
<?php require __DIR__ . '/pie.php'; ?>
