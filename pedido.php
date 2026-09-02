<?php
/**
 * Seguimiento del pedido y subida del comprobante.
 *
 * Accesible de tres formas: siendo el dueño de la cuenta, con el enlace
 * firmado que se envía por correo (invitados) o desde el panel con el permiso
 * `pedidos.ver`. El enlace del invitado es el que hace que no haga falta
 * crear cuenta para comprar.
 */

declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

$codigo = texto('codigo', 20, $_GET);
$token  = texto('t', 40, $_GET);

$pedido = $codigo !== '' ? Pedidos::porCodigo($pdo, $codigo) : null;

if (!$pedido || !Pedidos::puedeVer($pedido, $token !== '' ? $token : null)) {
    http_response_code($pedido ? 403 : 404);
    $tituloPagina = 'Pedido no disponible';
    require __DIR__ . '/includes/vistas/cabecera.php';
    ?>
    <div class="container">
      <div class="estado-vacio estado-vacio--error">
        <i class="fa-solid fa-receipt" aria-hidden="true"></i>
        <h1>No pudimos abrir ese pedido</h1>
        <p>El código no existe o el enlace ya no es válido. Búscalo con tu código y tu correo,
           o inicia sesión si lo hiciste con cuenta.</p>
        <div class="estado-vacio-acciones">
          <a class="btn btn-primary" href="<?= e(url('seguimiento.php')) ?>">Buscar mi pedido</a>
          <a class="btn btn-outline-dark" href="<?= e(url('cuenta/entrar.php')) ?>">Iniciar sesión</a>
        </div>
      </div>
    </div>
    <?php
    require __DIR__ . '/includes/vistas/pie.php';
    exit;
}

// --- Subida del comprobante ------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $volverA = 'pedido.php?codigo=' . rawurlencode($codigo) . ($token !== '' ? '&t=' . rawurlencode($token) : '');
    exigirToken(false, $volverA);

    if (!Pedidos::admiteComprobante($pedido)) {
        flash('alerta', 'Este pedido no está esperando un comprobante ahora mismo.');
        redirigir($volverA);
    }
    if (!limitar($pdo, 'comprobante:' . $pedido['id'], 6, 3600)) {
        flash('error', 'Subiste varios comprobantes seguidos. Espera un momento o escríbenos por WhatsApp.');
        redirigir($volverA);
    }

    $subida = Archivos::guardarComprobante($_FILES['comprobante'] ?? []);
    if (!$subida['ok']) {
        flash('error', $subida['error']);
        redirigir($volverA);
    }

    $resultado = Pedidos::registrarComprobante($pdo, $pedido, $subida, [
        'referencia' => texto('referencia', 80),
        'banco'      => texto('banco', 80),
        'monto'      => decimal('monto'),
    ]);

    if (!$resultado['ok']) {
        // El archivo ya se guardó pero la fila no: se limpia para no dejar basura.
        Archivos::borrarDe(DIR_COMPROBANTES, $subida['nombre']);
        flash('error', $resultado['error']);
    } else {
        flash('exito', 'Recibimos tu comprobante. Lo revisamos y te avisamos por correo.');
    }
    redirigir($volverA);
}

$estadoPedido = Pedidos::estado($pdo, 'pedido', (string)$pedido['estado']);
$estadoPago   = Pedidos::estado($pdo, 'pago',   (string)$pedido['estado_pago']);
$cuentas      = Ajustes::cuentasBancarias($pdo);
$puedeSubir   = Pedidos::admiteComprobante($pedido);
$flujo        = ['pendiente', 'pago_revision', 'confirmado', 'preparacion', 'listo', 'enviado', 'completado'];
$posicion     = array_search((string)$pedido['estado'], $flujo, true);
$ultimoRechazo = null;
foreach ($pedido['comprobantes'] as $c) {
    if ($c['estado'] === 'rechazado' && $ultimoRechazo === null) {
        $ultimoRechazo = $c;
    }
}

$tituloPagina      = 'Pedido ' . $pedido['codigo'] . ' — ' . Ajustes::texto('nombre_tienda', 'Flowers Anto');
$descripcionPagina = 'Estado y detalle de tu pedido.';
$paginaActiva      = 'cuenta';

require __DIR__ . '/includes/vistas/cabecera.php';
?>

<div class="container">
  <nav class="migas" aria-label="Ruta">
    <ol>
      <li><a href="<?= e(url()) ?>">Inicio</a></li>
      <?php if (Auth::autenticado()): ?>
        <li><a href="<?= e(url('cuenta/pedidos.php')) ?>">Mis pedidos</a></li>
      <?php endif; ?>
      <li aria-current="page"><?= e((string)$pedido['codigo']) ?></li>
    </ol>
  </nav>

  <header class="pagina-cabecera">
    <div class="cabecera-pedido">
      <div>
        <h1 class="codigo-pedido">Pedido <?= e((string)$pedido['codigo']) ?></h1>
        <p>Realizado el <?= e(fecha_larga((string)$pedido['created_at'])) ?></p>
      </div>
      <span class="pastilla-estado" style="background: <?= e((string)$estadoPedido['color']) ?>;">
        <?= e((string)$estadoPedido['nombre']) ?>
      </span>
    </div>
  </header>

  <div class="diseno-compra">
    <div>
      <!-- Estado del pago -->
      <div class="tarjeta">
        <div class="tarjeta-encabezado">
          <h2>Estado del pago</h2>
          <?php
            // La descripción guardada («La transferencia fue verificada»)
            // sirve para los pedidos por banco. Con PayPal el cobro es
            // automático y el cliente merece leer lo que de verdad pasó.
            $descripcionPago = $pedido['metodo_pago'] === 'paypal'
                             && $pedido['estado_pago'] === Pedidos::PAGO_APROBADO
                ? 'PayPal confirmó el cobro en el momento de la compra.'
                : (string)$estadoPago['descripcion'];
          ?>
          <p><?= e($descripcionPago) ?></p>
        </div>

        <?php if ($pedido['estado_pago'] === Pedidos::PAGO_APROBADO): ?>
          <div class="caja-aviso exito">
            <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
            <?php if ($pedido['metodo_pago'] === 'paypal'): ?>
              <span>Pago recibido por PayPal. El pedido ya está en cola de preparación.
                <?php if (($pedido['paypal_captura_id'] ?? '') !== ''): ?>
                  <br><small>Comprobante de PayPal:
                    <strong><?= e((string)$pedido['paypal_captura_id']) ?></strong>
                    <?php if ((float)($pedido['total_usd'] ?? 0) > 0): ?>
                      — <?= e(number_format((float)$pedido['total_usd'], 2)) ?>
                      <?= e(strtoupper((string)Ajustes::texto('paypal_moneda', 'USD'))) ?>
                    <?php endif; ?>
                  </small>
                <?php endif; ?>
              </span>
            <?php else: ?>
              <span>Verificamos tu transferencia. El pedido ya está en cola de preparación.</span>
            <?php endif; ?>
          </div>
        <?php elseif ($pedido['estado_pago'] === Pedidos::PAGO_RECHAZADO && $ultimoRechazo): ?>
          <div class="caja-aviso error">
            <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
            <span><strong>No pudimos validar el comprobante.</strong><br>
              <?= e((string)$ultimoRechazo['motivo_rechazo']) ?><br>
              Tu pedido sigue reservado: puedes subir otro comprobante aquí abajo.</span>
          </div>
        <?php elseif (in_array((string)$pedido['estado_pago'],
                    [Pedidos::PAGO_RECIBIDO, Pedidos::PAGO_EN_REVISION], true)): ?>
          <div class="caja-aviso info">
            <i class="fa-solid fa-hourglass-half" aria-hidden="true"></i>
            <span>Tenemos tu comprobante y lo está revisando una persona del equipo.
                  Te escribimos en cuanto quede verificado.</span>
          </div>
        <?php elseif ($pedido['metodo_pago'] !== 'transferencia'): ?>
          <div class="caja-aviso info">
            <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
            <span>Este pedido se paga <?= match ((string)$pedido['metodo_pago']) {
                 'efectivo' => 'en efectivo al recibirlo',
                 'paypal'   => 'con PayPal',
                 default    => 'de forma coordinada por WhatsApp',
            } ?>.</span>
          </div>
        <?php endif; ?>

        <?php if ($puedeSubir && $cuentas): ?>
          <p class="etiqueta-campo" style="margin-top:20px;">Datos para la transferencia</p>
          <?php foreach ($cuentas as $cuenta): ?>
            <div class="cuenta-banco">
              <h3><?= e((string)$cuenta['banco']) ?> — <?= e((string)$cuenta['moneda']) ?></h3>
              <dl>
                <div><dt>Titular</dt><dd><?= e((string)$cuenta['titular']) ?></dd></div>
                <div><dt>Número de cuenta</dt>
                  <dd><?= e((string)$cuenta['numero_cuenta']) ?>
                    <button type="button" class="btn-copiar" data-copiar="<?= e((string)$cuenta['numero_cuenta']) ?>">Copiar</button>
                  </dd></div>
                <div><dt>Tipo de cuenta</dt><dd><?= e((string)$cuenta['tipo_cuenta']) ?></dd></div>
                <?php if ($cuenta['identificacion'] !== ''): ?>
                  <div><dt>Cédula / RUC</dt><dd><?= e((string)$cuenta['identificacion']) ?></dd></div>
                <?php endif; ?>
                <div><dt>Monto a transferir</dt>
                  <dd><?= e((string)$pedido['moneda'] . number_format((float)$pedido['total'], 2)) ?>
                    <button type="button" class="btn-copiar"
                            data-copiar="<?= e(number_format((float)$pedido['total'], 2, '.', '')) ?>">Copiar</button>
                  </dd></div>
              </dl>
              <?php if ($cuenta['notas'] !== ''): ?>
                <p style="font-size:.84rem; color:var(--suave); margin-top:10px;"><?= e((string)$cuenta['notas']) ?></p>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>

          <?php if (($instrucciones = Ajustes::texto('instrucciones_pago')) !== ''): ?>
            <p style="font-size:.88rem; color:var(--suave); line-height:1.65; margin-top:12px;">
              <?= nl2br(e($instrucciones)) ?></p>
          <?php endif; ?>
        <?php endif; ?>
      </div>

      <!-- Subida del comprobante -->
      <?php if ($puedeSubir): ?>
        <div class="tarjeta" id="comprobante">
          <div class="tarjeta-encabezado">
            <h2><?= $ultimoRechazo ? 'Subir otro comprobante' : 'Subir el comprobante' ?></h2>
            <p>Acepta captura de pantalla (JPG, PNG, WEBP) o el PDF del banco,
               hasta <?= (int)(MAX_COMPROBANTE_BYTES / 1048576) ?> MB.</p>
          </div>

          <form method="post" enctype="multipart/form-data" data-una-vez
                action="<?= e(url('pedido.php?codigo=' . rawurlencode($codigo) . ($token !== '' ? '&t=' . rawurlencode($token) : ''))) ?>">
            <?= campoToken() ?>
            <input type="hidden" name="MAX_FILE_SIZE" value="<?= MAX_COMPROBANTE_BYTES ?>">

            <label class="zona-archivo" id="zonaComprobante" for="archivoComprobante"
                   data-max-bytes="<?= MAX_COMPROBANTE_BYTES ?>">
              <i class="fa-solid fa-cloud-arrow-up" aria-hidden="true"></i>
              <strong>Toca para elegir el archivo</strong>
              <small>o arrástralo hasta aquí</small>
              <input type="file" id="archivoComprobante" name="comprobante" required
                     accept="image/jpeg,image/png,image/webp,image/gif,application/pdf">
            </label>
            <div class="vista-previa" id="vistaPrevia"></div>

            <div class="campo-fila" style="margin-top:18px;">
              <div class="campo">
                <label for="banco">Banco desde el que transferiste</label>
                <input type="text" id="banco" name="banco" maxlength="80" placeholder="Opcional">
              </div>
              <div class="campo">
                <label for="referencia">Número de referencia</label>
                <input type="text" id="referencia" name="referencia" maxlength="80" placeholder="Opcional">
              </div>
            </div>

            <div class="campo">
              <label for="monto">Monto transferido</label>
              <input type="text" id="monto" name="monto" inputmode="decimal"
                     value="<?= e(number_format((float)$pedido['total'], 2, '.', '')) ?>">
              <p class="ayuda">Nos ayuda a cuadrar el pago más rápido.</p>
            </div>

            <button type="submit" class="btn btn-primary btn-block">
              <i class="fa-solid fa-paper-plane" aria-hidden="true"></i> Enviar comprobante
            </button>
            <p style="font-size:.83rem; color:var(--tenue); margin-top:12px; text-align:center;">
              El pago no queda aprobado al subirlo: una persona del equipo lo revisa antes de confirmarlo.
            </p>
          </form>
        </div>
      <?php endif; ?>

      <!-- Comprobantes enviados -->
      <?php if ($pedido['comprobantes']): ?>
        <div class="tarjeta">
          <div class="tarjeta-encabezado"><h2>Comprobantes enviados</h2></div>
          <?php foreach ($pedido['comprobantes'] as $c):
              $colores = ['recibido' => 'info', 'en_revision' => 'info', 'aprobado' => 'exito', 'rechazado' => 'error'];
              $nombres = ['recibido' => 'Recibido', 'en_revision' => 'En revisión',
                          'aprobado' => 'Aprobado', 'rechazado' => 'Rechazado'];
          ?>
            <div style="display:flex; gap:12px; align-items:center; padding:11px 0; border-bottom:1px solid var(--linea); flex-wrap:wrap;">
              <i class="fa-solid <?= str_contains((string)$c['mime'], 'pdf') ? 'fa-file-pdf' : 'fa-file-image' ?>"
                 style="font-size:1.3rem; color:var(--rose-pastel);" aria-hidden="true"></i>
              <div style="flex:1; min-width:150px;">
                <strong style="display:block; font-size:.92rem;"><?= e(fecha_larga((string)$c['subido_en'])) ?></strong>
                <small style="color:var(--tenue);"><?= e(tamano_legible((int)$c['tamano'])) ?>
                  <?= $c['referencia'] !== '' ? ' · Ref. ' . e((string)$c['referencia']) : '' ?></small>
              </div>
              <span class="caja-aviso <?= e($colores[$c['estado']] ?? 'info') ?>"
                    style="margin:0; padding:5px 12px; font-size:.82rem;">
                <?= e($nombres[$c['estado']] ?? (string)$c['estado']) ?>
              </span>
              <a class="btn btn-sm btn-outline-dark"
                 href="<?= e(url('comprobante.php?id=' . (int)$c['id']
                        . '&codigo=' . rawurlencode($codigo)
                        . ($token !== '' ? '&t=' . rawurlencode($token) : ''))) ?>"
                 target="_blank" rel="noopener">Ver</a>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <!-- Historial -->
      <div class="tarjeta">
        <div class="tarjeta-encabezado"><h2>Seguimiento</h2></div>
        <div class="linea-tiempo">
          <?php foreach ($flujo as $indice => $codigoEstado):
              if ($pedido['estado'] === Pedidos::CANCELADO && $indice > 1) { break; }
              $est   = Pedidos::estado($pdo, 'pedido', $codigoEstado);
              $hecho = $posicion !== false && $indice <= $posicion;
          ?>
            <div class="hito<?= $hecho ? ' hecho' : '' ?>">
              <strong><?= e((string)$est['nombre']) ?></strong>
              <?php if ($hecho): ?>
                <p><?= e((string)$est['descripcion']) ?></p>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
          <?php if ($pedido['estado'] === Pedidos::CANCELADO): ?>
            <div class="hito hecho">
              <strong style="color:var(--alerta);">Cancelado</strong>
              <p>Este pedido fue cancelado. Si fue un error, escríbenos por WhatsApp.</p>
            </div>
          <?php endif; ?>
        </div>

        <?php if ($pedido['historial']): ?>
          <details style="margin-top:20px;">
            <summary style="cursor:pointer; color:var(--suave); font-size:.9rem;">Ver el detalle de los cambios</summary>
            <div class="linea-tiempo" style="margin-top:16px;">
              <?php foreach (array_reverse($pedido['historial']) as $h): ?>
                <div class="hito hecho">
                  <strong><?= e(Pedidos::estado($pdo, $h['tipo'] === 'pago' ? 'pago' : 'pedido',
                                                (string)$h['estado_nuevo'])['nombre']) ?></strong>
                  <small><?= e(fecha_larga((string)$h['created_at'])) ?></small>
                  <?php if ($h['nota'] !== ''): ?><p><?= e((string)$h['nota']) ?></p><?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          </details>
        <?php endif; ?>
      </div>
    </div>

    <!-- Resumen -->
    <div class="columna-resumen">
      <div class="tarjeta">
        <div class="tarjeta-encabezado"><h2>Detalle</h2></div>

        <?php foreach ($pedido['items'] as $i): ?>
          <div class="mini-linea">
            <img src="<?= e(url_imagen((string)$i['imagen'])) ?>" alt="" loading="lazy">
            <div class="mini-linea-datos">
              <strong><?= e((string)$i['nombre']) ?></strong>
              <small><?= (int)$i['cantidad'] ?> × <?= e((string)$pedido['moneda'] . number_format((float)$i['precio_unitario'], 2)) ?></small>
            </div>
            <span class="mini-linea-precio"><?= e((string)$pedido['moneda'] . number_format((float)$i['subtotal'], 2)) ?></span>
          </div>
        <?php endforeach; ?>

        <div class="resumen-totales" style="margin-top:16px;">
          <div><span>Subtotal</span><span><?= e((string)$pedido['moneda'] . number_format((float)$pedido['subtotal'], 2)) ?></span></div>
          <?php if ((float)($pedido['descuento'] ?? 0) > 0): ?>
            <div><span>Descuento
              <small style="color:var(--tenue);"><?= e((string)$pedido['cupon_codigo']) ?></small></span>
              <span class="gratis">−<?= e((string)$pedido['moneda'] . number_format((float)$pedido['descuento'], 2)) ?></span></div>
          <?php endif; ?>
          <div><span>Envío</span>
            <?= (float)$pedido['envio'] > 0
                  ? '<span>' . e((string)$pedido['moneda'] . number_format((float)$pedido['envio'], 2)) . '</span>'
                  : '<span class="gratis">Gratis</span>' ?></div>
          <div class="total"><span>Total</span>
            <span><?= e((string)$pedido['moneda'] . number_format((float)$pedido['total'], 2)) ?></span></div>
        </div>
      </div>

      <div class="tarjeta">
        <div class="tarjeta-encabezado"><h2>Entrega</h2></div>
        <div style="font-size:.92rem; color:var(--suave); line-height:1.75;">
          <?php if ($pedido['entrega_tipo'] === 'domicilio'): ?>
            <p><strong style="color:var(--tinta);">A domicilio</strong></p>
            <p><?= e((string)$pedido['entrega_direccion']) ?></p>
            <p><?= e((string)($pedido['zona_envio_nombre'] ?: $pedido['entrega_ciudad'])) ?></p>
            <?php if ($pedido['entrega_referencia'] !== ''): ?>
              <p>Referencia: <?= e((string)$pedido['entrega_referencia']) ?></p>
            <?php endif; ?>
            <?php $mapaUrl = (string)($pedido['entrega_mapa_url'] ?? ''); ?>
            <?php if ($mapaUrl !== ''): ?>
              <p style="margin-top:8px;">
                <a href="<?= e($mapaUrl) ?>" target="_blank" rel="noopener noreferrer nofollow">
                  <i class="fa-solid fa-location-dot" aria-hidden="true"></i>
                  Ver la ubicación que enviaste (<?= e(Envios::servicioMapa($mapaUrl)) ?>)
                </a>
              </p>
            <?php endif; ?>
          <?php else: ?>
            <p><strong style="color:var(--tinta);">Retiro en la tienda</strong></p>
            <p><?= e(Ajustes::texto('direccion')) ?></p>
          <?php endif; ?>
          <?php if ($pedido['entrega_fecha']): ?>
            <p style="margin-top:8px;"><i class="fa-solid fa-calendar" aria-hidden="true"></i>
              <?= e(fecha_corta((string)$pedido['entrega_fecha'])) ?>
              <?= $pedido['entrega_franja'] !== '' ? ' · ' . e((string)$pedido['entrega_franja']) : '' ?></p>
          <?php endif; ?>
          <p style="margin-top:8px;"><i class="fa-solid fa-user" aria-hidden="true"></i>
            Recibe: <?= e((string)$pedido['entrega_nombre']) ?></p>
          <?php if ($pedido['dedicatoria'] !== ''): ?>
            <p style="margin-top:12px; padding:11px 13px; background:var(--rose-soft); border-radius:10px;
                      font-style:italic; color:var(--tinta);">
              «<?= e((string)$pedido['dedicatoria']) ?>»</p>
          <?php endif; ?>
        </div>

        <a class="btn btn-whatsapp btn-block" style="margin-top:16px;"
           href="<?= e(enlace_whatsapp('Hola, escribo por mi pedido ' . $pedido['codigo'] . '.', Ajustes::whatsappPedidos())) ?>"
           target="_blank" rel="noopener">
          <i class="fa-brands fa-whatsapp" aria-hidden="true"></i> Consultar por WhatsApp
        </a>

        <?php if (!Auth::autenticado()): ?>
          <p style="font-size:.83rem; color:var(--tenue); margin-top:14px; line-height:1.6;">
            Guarda este enlace para volver a consultar tu pedido.
            <a href="<?= e(url('cuenta/registrar.php')) ?>">Crea una cuenta</a> con
            <?= e((string)$pedido['cliente_email']) ?> y lo verás en tu historial.
          </p>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<div style="height:56px"></div>
<?php require __DIR__ . '/includes/vistas/pie.php'; ?>
