<?php
/**
 * Historial de pedidos del cliente.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

Auth::exigirSesion('cuenta/pedidos.php');

$pedidos = Pedidos::deUsuario($pdo, (int)Auth::id());

$tituloPagina  = 'Mis pedidos — ' . Ajustes::texto('nombre_tienda', 'Flowers Anto');
$paginaActiva  = 'cuenta';
$seccionCuenta = 'pedidos';

require __DIR__ . '/../includes/vistas/cabecera.php';
?>

<div class="container">
  <header class="pagina-cabecera">
    <h1>Mis pedidos</h1>
    <p>Todo lo que has pedido, con su estado actual.</p>
  </header>

  <div class="diseno-cuenta">
    <?php require __DIR__ . '/../includes/vistas/menu_cuenta.php'; ?>

    <div>
      <?php if (!$pedidos): ?>
        <div class="tarjeta">
          <div class="estado-vacio" style="padding:38px 16px;">
            <i class="fa-solid fa-box-open" aria-hidden="true"></i>
            <h2>Todavía no hiciste ningún pedido</h2>
            <p>Cuando pidas un arreglo lo verás aquí, con su estado y su comprobante.</p>
            <div class="estado-vacio-acciones">
              <a class="btn btn-primary" href="<?= e(url('productos.php')) ?>">Ver los arreglos</a>
            </div>
          </div>
        </div>
      <?php else: ?>
        <div class="lista-pedidos">
          <?php foreach ($pedidos as $p):
              $estado = Pedidos::estado($pdo, 'pedido', (string)$p['estado']);
          ?>
            <a class="fila-pedido"
               href="<?= e(url('pedido.php?codigo=' . rawurlencode((string)$p['codigo']))) ?>">
              <div>
                <span class="fila-pedido-codigo"><?= e((string)$p['codigo']) ?></span>
                <span class="fila-pedido-fecha"> · <?= e(fecha_corta((string)$p['created_at'])) ?>
                  · <?= (int)$p['articulos'] ?> <?= (int)$p['articulos'] === 1 ? 'artículo' : 'artículos' ?></span>
              </div>
              <span class="pastilla-estado" style="background: <?= e((string)$estado['color']) ?>;">
                <?= e((string)$estado['nombre']) ?></span>
              <span class="fila-pedido-total"><?= e((string)$p['moneda'] . number_format((float)$p['total'], 2)) ?></span>
              <i class="fa-solid fa-chevron-right" style="color:var(--tenue);" aria-hidden="true"></i>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<div style="height:56px"></div>
<?php require __DIR__ . '/../includes/vistas/pie.php'; ?>
