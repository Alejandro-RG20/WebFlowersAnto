<?php
/**
 * Factura de un pedido: verla, imprimirla y guardarla como PDF.
 *
 * Se llega igual que al seguimiento del pedido —siendo el dueño de la cuenta,
 * con el enlace firmado del correo, o desde el panel con permiso—, así que la
 * decisión de quién puede verla es la misma que ya estaba probada y no una
 * regla nueva que se pueda equivocar.
 *
 * No genera un PDF: la hoja está preparada para imprimirse y el propio
 * navegador ofrece «Guardar como PDF». Una librería de PDF habría obligado a
 * usar Composer, y este proyecto se despliega copiando carpetas.
 */

declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

$codigo = texto('codigo', 20, $_GET);
$token  = texto('t', 40, $_GET);

$pedido = $codigo !== '' ? Pedidos::porCodigo($pdo, $codigo) : null;

if (!$pedido || !Pedidos::puedeVer($pedido, $token !== '' ? $token : null)) {
    http_response_code($pedido ? 403 : 404);
    $tituloPagina = 'Factura no disponible';
    require __DIR__ . '/includes/vistas/cabecera.php';
    ?>
    <div class="container">
      <div class="estado-vacio estado-vacio--error">
        <i class="fa-solid fa-file-invoice" aria-hidden="true"></i>
        <h1>No pudimos abrir esa factura</h1>
        <p>El enlace ya no es válido. Ábrelo desde el correo que te enviamos,
           o inicia sesión si hiciste el pedido con cuenta.</p>
        <div class="estado-vacio-acciones">
          <a class="btn btn-primary" href="<?= e(url('seguimiento.php')) ?>">Buscar mi pedido</a>
        </div>
      </div>
    </div>
    <?php
    require __DIR__ . '/includes/vistas/pie.php';
    exit;
}

$factura = Facturas::porPedido($pdo, (int)$pedido['id']);

if (!$factura) {
    $tituloPagina = 'Factura todavía no emitida';
    require __DIR__ . '/includes/vistas/cabecera.php';
    ?>
    <div class="container">
      <div class="estado-vacio">
        <i class="fa-regular fa-clock" aria-hidden="true"></i>
        <h1>Tu factura aún no está lista</h1>
        <p>La emitimos cuando el pedido queda entregado. En cuanto esté, te llega por correo.</p>
        <div class="estado-vacio-acciones">
          <a class="btn btn-primary" href="<?= e(Pedidos::enlaceSeguimiento($pedido)) ?>">Ver mi pedido</a>
        </div>
      </div>
    </div>
    <?php
    require __DIR__ . '/includes/vistas/pie.php';
    exit;
}

$tituloPagina      = 'Factura ' . $factura['folio'] . ' — ' . Ajustes::texto('nombre_tienda', 'Flowers Anto');
$descripcionPagina = 'Factura de tu pedido ' . $factura['pedido_codigo'] . '.';
$paginaActiva      = 'cuenta';
$cssExtra          = ['assets/css/factura.css'];

require __DIR__ . '/includes/vistas/cabecera.php';
?>

<div class="container">
  <div class="factura-acciones no-imprimir">
    <a class="btn btn-outline-dark" href="<?= e(Pedidos::enlaceSeguimiento($pedido)) ?>">
      <i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Volver al pedido</a>
    <button type="button" class="btn btn-primary" onclick="window.print()">
      <i class="fa-solid fa-print" aria-hidden="true"></i> Imprimir o guardar en PDF</button>
  </div>

  <?php require __DIR__ . '/includes/vistas/factura_hoja.php'; ?>
</div>

<?php require __DIR__ . '/includes/vistas/pie.php'; ?>
