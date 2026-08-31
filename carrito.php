<?php
/**
 * Carrito.
 *
 * Las acciones llegan por POST y responden con redirección (patrón
 * POST-Redirect-GET), para que recargar la página no repita la operación.
 * El JavaScript intercepta lo mismo contra api/carrito.php; si no está
 * disponible, esta página sigue funcionando por sí sola.
 */

declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirToken(false, 'carrito.php');

    $accion     = opcion('accion', ['agregar', 'fijar', 'quitar', 'vaciar'], 'agregar');
    $productoId = identificador('producto_id');

    switch ($accion) {
        case 'agregar':
            $error = Carrito::agregar($pdo, $productoId, entero('cantidad', 1, Carrito::MAX_UNIDADES, 1));
            flash($error === '' ? 'exito' : 'alerta', $error === '' ? 'Arreglo añadido al carrito.' : $error);
            // Desde la ficha se vuelve al carrito; desde una tarjeta, a donde estabas.
            redirigir(texto('volver_a') !== '' ? texto('volver_a') : 'carrito.php');

        case 'fijar':
            $error = Carrito::fijar($pdo, $productoId, entero('cantidad', 0, Carrito::MAX_UNIDADES, 1));
            if ($error !== '') {
                flash('alerta', $error);
            }
            redirigir('carrito.php');

        case 'quitar':
            Carrito::quitar($pdo, $productoId);
            flash('info', 'Arreglo quitado del carrito.');
            redirigir('carrito.php');

        case 'vaciar':
            Carrito::vaciar($pdo);
            flash('info', 'Vaciamos tu carrito.');
            redirigir('carrito.php');
    }
}

$detalle = Carrito::detalle($pdo);

$tienda        = Ajustes::texto('nombre_tienda', 'Flowers Anto');
$pedidoWeb     = Ajustes::activo('pedido_web_activo', true);
$pedidoWa      = Ajustes::activo('pedido_whatsapp_activo', true) && Ajustes::whatsappPedidos() !== '';
$umbralEnvio   = (float)Ajustes::numero('envio_gratis_desde', 0);
$faltaParaEnvio = $umbralEnvio > 0 ? max(0, $umbralEnvio - $detalle['subtotal']) : 0;

$tituloPagina = 'Tu carrito — ' . $tienda;
$descripcionPagina = 'Revisa los arreglos que elegiste antes de completar tu pedido.';
$paginaActiva = 'carrito';

require __DIR__ . '/includes/vistas/cabecera.php';
?>

<div class="container">
  <nav class="migas" aria-label="Ruta">
    <ol>
      <li><a href="<?= e(url()) ?>">Inicio</a></li>
      <li aria-current="page">Carrito</li>
    </ol>
  </nav>

  <header class="pagina-cabecera">
    <h1>Tu carrito</h1>
    <?php if ($detalle['items']): ?>
      <p><?= (int)$detalle['unidades'] ?>
         <?= (int)$detalle['unidades'] === 1 ? 'arreglo listo' : 'arreglos listos' ?> para pedir.</p>
    <?php endif; ?>
  </header>

  <?php foreach ($detalle['avisos'] as $aviso): ?>
    <div class="caja-aviso alerta">
      <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i><span><?= e($aviso) ?></span>
    </div>
  <?php endforeach; ?>

  <?php if (!$detalle['items']): ?>
    <div class="estado-vacio">
      <i class="fa-solid fa-cart-shopping" aria-hidden="true"></i>
      <h2>Tu carrito está vacío</h2>
      <p>Cuando encuentres un arreglo que te guste, añádelo aquí y podrás completar el pedido
         en la web o pedirlo por WhatsApp.</p>
      <div class="estado-vacio-acciones">
        <a class="btn btn-primary" href="<?= e(url('productos.php')) ?>">Ver arreglos</a>
        <a class="btn btn-outline-dark" href="<?= e(url('favoritos.php')) ?>">Mis favoritos</a>
      </div>
    </div>
  <?php else: ?>
    <div class="diseno-compra">
      <!-- Artículos -->
      <div class="tarjeta">
        <?php foreach ($detalle['items'] as $i): ?>
          <div class="linea-carrito">
            <a href="<?= e(url('producto.php?p=' . rawurlencode((string)$i['slug']))) ?>">
              <img src="<?= e(url_imagen((string)$i['imagen'])) ?>" alt="<?= e((string)$i['nombre']) ?>" loading="lazy">
            </a>
            <div class="linea-carrito-datos">
              <h3><a href="<?= e(url('producto.php?p=' . rawurlencode((string)$i['slug']))) ?>"><?= e((string)$i['nombre']) ?></a></h3>
              <span class="linea-carrito-precio"><?= e(dinero($i['precio'])) ?> cada uno</span>

              <div class="linea-carrito-controles">
                <form method="post" action="<?= e(url('carrito.php')) ?>" data-autoenviar>
                  <?= campoToken() ?>
                  <input type="hidden" name="accion" value="fijar">
                  <input type="hidden" name="producto_id" value="<?= (int)$i['producto_id'] ?>">
                  <div class="selector-cantidad">
                    <button type="button" data-paso="-1" aria-label="Quitar una unidad de <?= e((string)$i['nombre']) ?>">
                      <i class="fa-solid fa-minus" aria-hidden="true"></i></button>
                    <input type="number" name="cantidad" value="<?= (int)$i['cantidad'] ?>" min="1"
                           max="<?= (int)$i['maximo'] ?>" inputmode="numeric"
                           aria-label="Cantidad de <?= e((string)$i['nombre']) ?>">
                    <button type="button" data-paso="1" aria-label="Añadir una unidad de <?= e((string)$i['nombre']) ?>">
                      <i class="fa-solid fa-plus" aria-hidden="true"></i></button>
                  </div>
                  <noscript><button type="submit" class="btn btn-sm btn-outline-dark">Actualizar</button></noscript>
                </form>

                <form method="post" action="<?= e(url('carrito.php')) ?>">
                  <?= campoToken() ?>
                  <input type="hidden" name="accion" value="quitar">
                  <input type="hidden" name="producto_id" value="<?= (int)$i['producto_id'] ?>">
                  <button type="submit" class="btn-quitar">
                    <i class="fa-solid fa-trash-can" aria-hidden="true"></i> Quitar
                  </button>
                </form>

                <span class="linea-carrito-total"><?= e(dinero($i['subtotal'])) ?></span>
              </div>
            </div>
          </div>
        <?php endforeach; ?>

        <div style="display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap; margin-top:20px;">
          <a class="btn btn-outline-dark btn-sm" href="<?= e(url('productos.php')) ?>">
            <i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Seguir comprando
          </a>
          <form method="post" action="<?= e(url('carrito.php')) ?>"
                data-confirmar="¿Seguro que quieres vaciar el carrito?">
            <?= campoToken() ?>
            <input type="hidden" name="accion" value="vaciar">
            <button type="submit" class="btn-quitar"><i class="fa-solid fa-xmark" aria-hidden="true"></i> Vaciar carrito</button>
          </form>
        </div>
      </div>

      <!-- Resumen -->
      <div class="columna-resumen">
        <div class="tarjeta">
          <div class="tarjeta-encabezado">
            <h2>Resumen</h2>
          </div>

          <div class="resumen-totales">
            <div><span>Subtotal</span><span><?= e(dinero($detalle['subtotal'])) ?></span></div>
            <div>
              <span>Envío</span>
              <?php if ($detalle['envio'] > 0): ?>
                <span><?= e(dinero($detalle['envio'])) ?></span>
              <?php else: ?>
                <span class="gratis">Gratis</span>
              <?php endif; ?>
            </div>
            <div class="total"><span>Total</span><span><?= e(dinero($detalle['total'])) ?></span></div>
          </div>

          <?php if ($faltaParaEnvio > 0): ?>
            <div class="caja-aviso info" style="margin-top:16px;">
              <i class="fa-solid fa-truck" aria-hidden="true"></i>
              <span>Te faltan <strong><?= e(dinero($faltaParaEnvio)) ?></strong> para el envío gratis.</span>
            </div>
          <?php endif; ?>

          <div style="display:grid; gap:10px; margin-top:20px;">
            <?php if ($pedidoWeb): ?>
              <a class="btn btn-primary btn-block" href="<?= e(url('checkout.php')) ?>">
                <i class="fa-solid fa-lock" aria-hidden="true"></i> Completar pedido
              </a>
            <?php endif; ?>

            <?php if ($pedidoWa): ?>
              <a class="btn btn-whatsapp btn-block"
                 href="<?= e(enlace_whatsapp(Carrito::mensajeWhatsapp($detalle), Ajustes::whatsappPedidos())) ?>"
                 target="_blank" rel="noopener">
                <i class="fa-brands fa-whatsapp" aria-hidden="true"></i> Pedir por WhatsApp
              </a>
            <?php endif; ?>
          </div>

          <?php if ($pedidoWeb && $pedidoWa): ?>
            <div style="margin-top:16px; font-size:.85rem; color:var(--suave); line-height:1.6;
                        border-top:1px solid var(--linea); padding-top:14px;">
              <p style="margin-bottom:7px;"><strong style="color:var(--tinta);">Completar pedido:</strong>
                 registras la entrega, pagas por transferencia y sigues el estado desde la web.</p>
              <p><strong style="color:var(--tinta);">Pedir por WhatsApp:</strong>
                 abrimos un chat con el detalle del carrito y coordinamos todo por mensaje.</p>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  <?php endif; ?>
</div>

<div style="height:56px"></div>
<?php require __DIR__ . '/includes/vistas/pie.php'; ?>
