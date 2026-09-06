<?php
/**
 * La hoja de la factura. La usan la página del cliente y la del panel, para
 * que las dos impriman exactamente el mismo documento.
 *
 * Espera $factura (con sus `items`).
 */

declare(strict_types=1);

if (!defined('RAIZ') || !isset($factura)) {
    return;
}

$anulada  = Facturas::anulada($factura);
$conIva   = (float)$factura['iva_tasa'] > 0;
$tasaTxt  = rtrim(rtrim(number_format((float)$factura['iva_tasa'], 2), '0'), '.');

$emisor = array_values(array_filter([
    trim((string)$factura['emisor_direccion']),
    trim((string)$factura['emisor_telefono']),
], fn($v) => $v !== ''));
?>
<article class="hoja-factura<?= $anulada ? ' hoja-factura--anulada' : '' ?>">

  <?php if ($anulada): ?>
    <p class="factura-anulada-aviso">
      <i class="fa-solid fa-ban" aria-hidden="true"></i>
      <span><strong>Factura anulada</strong>
        <?php if ((string)$factura['anulada_motivo'] !== ''): ?>
          — <?= e((string)$factura['anulada_motivo']) ?>
        <?php endif; ?>
      </span>
    </p>
  <?php endif; ?>

  <header class="factura-cabecera">
    <div class="factura-emisor">
      <?php if (imagen_disponible(Ajustes::texto('logo_url', ''))): ?>
        <img class="factura-logo" src="<?= e(url_imagen(Ajustes::texto('logo_url', ''))) ?>"
             alt="" width="58" height="58">
      <?php endif; ?>
      <div>
        <h1><?= e((string)$factura['emisor_nombre']) ?></h1>
        <?php if ((string)$factura['emisor_ruc'] !== ''): ?>
          <p class="factura-ruc">RUC <?= e((string)$factura['emisor_ruc']) ?></p>
        <?php endif; ?>
        <?php if ($emisor): ?>
          <p><?= e(implode(' · ', $emisor)) ?></p>
        <?php endif; ?>
      </div>
    </div>

    <div class="factura-folio">
      <span class="factura-etiqueta">Factura</span>
      <strong><?= e((string)$factura['folio']) ?></strong>
      <p><?= e(fecha_larga((string)$factura['emitida_en'])) ?></p>
      <p>Pedido <?= e((string)$factura['pedido_codigo']) ?></p>
    </div>
  </header>

  <section class="factura-cliente">
    <h2>Cliente</h2>
    <p><strong><?= e((string)$factura['cliente_nombre']) ?></strong></p>
    <?php foreach (['cliente_telefono' => '', 'cliente_email' => '', 'cliente_direccion' => ''] as $campo => $_):
        if (trim((string)$factura[$campo]) !== ''): ?>
      <p><?= e((string)$factura[$campo]) ?></p>
    <?php endif; endforeach; ?>
  </section>

  <table class="factura-tabla">
    <thead>
      <tr>
        <th scope="col">Descripción</th>
        <th scope="col" class="num">Cant.</th>
        <th scope="col" class="num">Precio</th>
        <th scope="col" class="num">Importe</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ((array)$factura['items'] as $it): ?>
        <tr>
          <td><?= e((string)$it['descripcion']) ?></td>
          <td class="num"><?= (int)$it['cantidad'] ?></td>
          <td class="num"><?= e(dinero($it['precio_unitario'])) ?></td>
          <td class="num"><?= e(dinero($it['subtotal'])) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <div class="factura-totales">
    <dl>
      <dt>Subtotal</dt><dd><?= e(dinero($factura['subtotal'])) ?></dd>

      <?php if ((float)$factura['descuento'] > 0): ?>
        <dt>Descuento<?= (string)$factura['cupon_codigo'] !== ''
              ? ' (' . e((string)$factura['cupon_codigo']) . ')' : '' ?></dt>
        <dd>−<?= e(dinero($factura['descuento'])) ?></dd>
      <?php endif; ?>

      <?php if ((float)$factura['envio'] > 0): ?>
        <dt>Envío</dt><dd><?= e(dinero($factura['envio'])) ?></dd>
      <?php endif; ?>

      <?php if ($conIva): ?>
        <dt class="suave">Base imponible</dt>
        <dd class="suave"><?= e(dinero($factura['base_imponible'])) ?></dd>
        <dt class="suave">IVA <?= e($tasaTxt) ?>% <small>(incluido en el precio)</small></dt>
        <dd class="suave"><?= e(dinero($factura['iva'])) ?></dd>
      <?php endif; ?>

      <div class="factura-raya" aria-hidden="true"></div>
      <dt class="factura-total">Total</dt>
      <dd class="factura-total"><?= e(dinero($factura['total'])) ?></dd>
    </dl>
  </div>

  <footer class="factura-pie">
    <p>Pagado con <strong><?= e(Facturas::metodoPago((string)$factura['metodo_pago'])) ?></strong>.</p>
    <?php if ((string)$factura['pie'] !== ''): ?>
      <p class="factura-nota"><?= nl2br(e((string)$factura['pie'])) ?></p>
    <?php endif; ?>
  </footer>
</article>
