<?php
/**
 * Una factura vista desde el panel, lista para imprimir.
 *
 * Usa exactamente la misma hoja que ve el cliente: si aquí se imprimiera algo
 * distinto de lo que él recibió, tarde o temprano habría una discusión sobre
 * cuál de las dos es la buena.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

Rbac::exigirPanel();
Rbac::exigir('facturas.ver');

$factura = Facturas::porId($pdo, identificador('id', $_GET));

if (!$factura) {
    http_response_code(404);
    $seccion = 'facturas';
    $tituloPanel = 'Factura no encontrada';
    require __DIR__ . '/_cabecera.php';
    ?>
    <div class="vacio">
      <i class="fa-solid fa-file-invoice" aria-hidden="true"></i>
      <h3>Esa factura no existe</h3>
      <p><a href="<?= e(url('admin/facturas.php')) ?>">Volver a las facturas</a></p>
    </div>
    <?php
    require __DIR__ . '/_pie.php';
    exit;
}

$seccion     = 'facturas';
$tituloPanel = 'Factura ' . $factura['folio'];
$cssPanelExtra = ['assets/css/factura.css'];
require __DIR__ . '/_cabecera.php';
?>

<div class="factura-acciones no-imprimir">
  <a class="boton boton-claro" href="<?= e(url('admin/facturas.php')) ?>">
    <i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Volver</a>
  <a class="boton boton-claro" href="<?= e(url('admin/pedido.php?id=' . (int)$factura['pedido_id'])) ?>">
    <i class="fa-solid fa-receipt" aria-hidden="true"></i> Ver el pedido</a>
  <button type="button" class="boton boton-principal" onclick="window.print()">
    <i class="fa-solid fa-print" aria-hidden="true"></i> Imprimir o guardar en PDF</button>
</div>

<?php require __DIR__ . '/../includes/vistas/factura_hoja.php'; ?>

<?php require __DIR__ . '/_pie.php'; ?>
