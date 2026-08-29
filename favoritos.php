<?php
/**
 * Mis favoritos.
 *
 * Con sesión iniciada se guardan en la base y siguen al usuario entre
 * dispositivos. Sin sesión viven en la visita (y en localStorage), y se pasan
 * a la cuenta en cuanto inicia sesión o se registra.
 */

declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirToken(false, 'favoritos.php');

    $productoId = identificador('producto_id');
    if (opcion('accion', ['alternar', 'quitar'], 'alternar') === 'quitar') {
        Favoritos::quitar($pdo, $productoId);
        flash('info', 'Lo quitamos de tus favoritos.');
    } else {
        $ahora = Favoritos::alternar($pdo, $productoId);
        flash('exito', $ahora ? 'Guardado en favoritos.' : 'Lo quitamos de tus favoritos.');
    }
    redirigir('favoritos.php');
}

$productos    = Favoritos::productos($pdo);
$favoritosIds = array_map(fn($p) => (int)$p['id'], $productos);

$tituloPagina      = 'Mis favoritos — ' . Ajustes::texto('nombre_tienda', 'Flowers Anto');
$descripcionPagina = 'Los arreglos que guardaste para decidir después.';
$paginaActiva      = 'favoritos';
$cuerpoClase       = 'pagina-favoritos';

require __DIR__ . '/includes/vistas/cabecera.php';
?>

<div class="container">
  <nav class="migas" aria-label="Ruta">
    <ol>
      <li><a href="<?= e(url()) ?>">Inicio</a></li>
      <li aria-current="page">Favoritos</li>
    </ol>
  </nav>

  <header class="pagina-cabecera">
    <h1>Mis favoritos</h1>
    <p>
      <?php if ($productos): ?>
        <?= count($productos) ?> <?= count($productos) === 1 ? 'arreglo guardado' : 'arreglos guardados' ?>.
      <?php endif; ?>
      <?php if (!Auth::autenticado()): ?>
        Se guardan en este navegador.
        <a href="<?= e(url('cuenta/entrar.php')) ?>">Inicia sesión</a> para conservarlos en tu cuenta.
      <?php endif; ?>
    </p>
  </header>

  <?php if (!$productos): ?>
    <div class="estado-vacio">
      <i class="fa-regular fa-heart" aria-hidden="true"></i>
      <h2>Todavía no guardaste ningún arreglo</h2>
      <p>Toca el corazón de cualquier arreglo del catálogo para tenerlo aquí a mano
         cuando decidas.</p>
      <div class="estado-vacio-acciones">
        <a class="btn btn-primary" href="<?= e(url('productos.php')) ?>">Explorar el catálogo</a>
      </div>
    </div>
  <?php else: ?>
    <div class="rejilla-productos">
      <?php foreach ($productos as $p) { require __DIR__ . '/includes/vistas/tarjeta_producto.php'; } ?>
    </div>
  <?php endif; ?>
</div>

<div style="height:56px"></div>
<?php require __DIR__ . '/includes/vistas/pie.php'; ?>
