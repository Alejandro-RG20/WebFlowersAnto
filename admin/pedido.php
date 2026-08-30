<?php
/**
 * Detalle de un pedido: revisión del comprobante, aprobación o rechazo del
 * pago, cambios de estado y notas internas.
 *
 * Cada acción exige su propio permiso. Que un botón esté oculto no basta:
 * si alguien envía el formulario a mano, Rbac::exigir() corta la petición.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

$seccion = 'pedidos';

// La autorización va antes de cualquier consulta y antes de imprimir nada:
// así una petición sin permiso no llega ni a tocar la base de datos.
Rbac::exigirPanel();
Rbac::exigir('pedidos.ver');

$id     = identificador('id', $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET);
$pedido = $id > 0 ? Pedidos::porId($pdo, $id) : null;

if (!$pedido) {
    $tituloPanel = 'Pedido no encontrado';
    require __DIR__ . '/_cabecera.php';
    ?>
    <div class="vacio">
      <i class="fa-solid fa-receipt" aria-hidden="true"></i>
      <h3>Ese pedido no existe</h3>
      <p>Puede que se haya eliminado o que el enlace esté mal.</p>
      <a class="boton boton-principal" href="<?= e(url('admin/pedidos.php')) ?>">Volver a pedidos</a>
    </div>
    <?php
    require __DIR__ . '/_pie.php';
    exit;
}

// --- Acciones ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $volverA = 'admin/pedido.php?id=' . $id;
    exigirToken(false, $volverA);

    switch (opcion('accion', ['aprobar', 'rechazar', 'estado', 'nota', 'revisar', 'despachar'], '')) {
        case 'revisar':
            Rbac::exigir('pagos.revisar');
            Pedidos::tomarEnRevision($pdo, $pedido);
            flash('info', 'Marcamos el comprobante como «en revisión».');
            redirigir($volverA);

        case 'aprobar':
            Rbac::exigir('pagos.aprobar');
            $r = Pedidos::aprobarPago($pdo, $pedido, texto('nota', 400));
            flash($r['ok'] ? 'exito' : 'error',
                  $r['ok'] ? 'Pago aprobado. El pedido pasó a «confirmado» y avisamos al cliente.' : $r['error']);
            redirigir($volverA);

        case 'rechazar':
            Rbac::exigir('pagos.aprobar');
            $r = Pedidos::rechazarPago($pdo, $pedido, texto('motivo', 400));
            flash($r['ok'] ? 'exito' : 'error',
                  $r['ok'] ? 'Pago rechazado. El cliente recibió el motivo y puede subir otro comprobante.' : $r['error']);
            redirigir($volverA);

        case 'estado':
            $nuevo = texto('estado', 40);
            Rbac::exigir($nuevo === Pedidos::CANCELADO ? 'pedidos.cancelar' : 'pedidos.editar');
            $r = Pedidos::cambiarEstado($pdo, $pedido, $nuevo, texto('nota', 400));
            flash($r['ok'] ? 'exito' : 'error', $r['ok'] ? 'Estado actualizado.' : $r['error']);
            redirigir($volverA);

        case 'despachar':
            Rbac::exigir('pedidos.despachar');

            if ($pedido['entrega_tipo'] !== 'domicilio') {
                flash('alerta', 'Este pedido es para retiro en la tienda: no hay nada que mandarle al motorizado.');
                redirigir($volverA);
            }
            $repartidor = Repartidores::porId($pdo, identificador('repartidor_id'));
            if (!$repartidor) {
                flash('error', 'Elige un repartidor disponible.');
                redirigir($volverA);
            }

            // El mensaje se arma aquí, con los datos de la base, y no en el
            // navegador: lo que se le manda al motorizado tiene que ser el
            // pedido tal como está guardado.
            $mensaje = Repartidores::mensaje($pdo, $pedido, $repartidor);
            Repartidores::asignar($pdo, $pedido, $repartidor);

            Auditoria::registrar($pdo, 'editar', 'pedidos', [
                'recurso_tipo' => 'pedido', 'recurso_id' => (string)$id,
                'descripcion'  => 'Entrega del pedido ' . $pedido['codigo']
                                . ' enviada a ' . $repartidor['nombre'] . '.',
            ]);

            // WhatsApp se abre desde el navegador, así que la URL se guarda un
            // momento en la sesión y la página de vuelta la abre en otra
            // pestaña. Redirigir directo a wa.me perdería el aviso y dejaría
            // al empleado fuera del panel.
            $_SESSION['despacho_whatsapp'] = enlace_whatsapp($mensaje, (string)$repartidor['telefono']);
            flash('exito', 'Entrega enviada a ' . $repartidor['nombre'] . '. Se abre WhatsApp con el mensaje listo.');
            redirigir($volverA);

        case 'nota':
            Rbac::exigir('pedidos.editar');
            $nota = textoLargo('notas_internas', 1000);
            $pdo->prepare("UPDATE pedidos SET notas_internas = ? WHERE id = ?")->execute([$nota, $id]);
            Pedidos::anotarHistorial($pdo, $id, 'nota', '', '', 'Nota interna actualizada.');
            Auditoria::registrar($pdo, 'editar', 'pedidos', [
                'recurso_tipo' => 'pedido', 'recurso_id' => (string)$id,
                'descripcion'  => 'Nota interna del pedido ' . $pedido['codigo'],
            ]);
            flash('exito', 'Nota guardada.');
            redirigir($volverA);

        default:
            flash('error', 'Acción no reconocida.');
            redirigir($volverA);
    }
}

// El enlace de WhatsApp lo dejó el despacho en la sesión: se consume una sola
// vez, para que al recargar la página no se vuelva a abrir la conversación.
$abrirWhatsapp = (string)($_SESSION['despacho_whatsapp'] ?? '');
unset($_SESSION['despacho_whatsapp']);

$repartidores  = Repartidores::activos($pdo);
$puedeDespachar = Rbac::puede('pedidos.despachar') && $pedido['entrega_tipo'] === 'domicilio';

$estadoPedido = Pedidos::estado($pdo, 'pedido', (string)$pedido['estado']);
$estadoPago   = Pedidos::estado($pdo, 'pago',   (string)$pedido['estado_pago']);
$siguientes   = Pedidos::siguientes((string)$pedido['estado']);
$ultimo       = $pedido['comprobantes'][0] ?? null;
$hayQueRevisar = in_array((string)$pedido['estado_pago'],
    [Pedidos::PAGO_RECIBIDO, Pedidos::PAGO_EN_REVISION], true);

$tituloPanel    = 'Pedido ' . $pedido['codigo'];
$subtituloPanel = 'Recibido el ' . fecha_larga((string)$pedido['created_at']);

require __DIR__ . '/_cabecera.php';
?>

<?php if ($abrirWhatsapp !== ''): ?>
  <div class="caja-aviso exito" data-abrir-whatsapp="<?= e($abrirWhatsapp) ?>">
    <i class="fa-brands fa-whatsapp" aria-hidden="true"></i>
    <span>Abriendo WhatsApp con la entrega…
      <a href="<?= e($abrirWhatsapp) ?>" target="_blank" rel="noopener noreferrer">
        Si no se abre solo, toca aquí</a>.</span>
  </div>
<?php endif; ?>

<div style="margin-bottom:18px; display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
  <a class="boton boton-claro boton-mini" href="<?= e(url('admin/pedidos.php')) ?>">
    <i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Pedidos</a>
  <span class="estado" style="background: <?= e((string)$estadoPedido['color']) ?>;"><?= e((string)$estadoPedido['nombre']) ?></span>
  <span class="estado" style="background: <?= e((string)$estadoPago['color']) ?>;"><?= e((string)$estadoPago['nombre']) ?></span>
  <span class="estado-suave"><?= e(ucfirst((string)$pedido['canal'])) ?></span>
  <span class="estado-suave"><?= e(ucfirst((string)$pedido['metodo_pago'])) ?></span>
</div>

<div class="rejilla-detalle">
  <div>
    <!-- Revisión del pago -->
    <section class="panel">
      <div class="panel-cabecera">
        <div>
          <h2>Pago</h2>
          <p><?= e((string)$estadoPago['descripcion']) ?></p>
        </div>
        <strong style="font-size:1.15rem;"><?= e((string)$pedido['moneda'] . number_format((float)$pedido['total'], 2)) ?></strong>
      </div>

      <div class="panel-cuerpo">
        <?php if (!$pedido['comprobantes']): ?>
          <div class="vacio" style="padding:32px 16px;">
            <i class="fa-solid fa-file-circle-question" aria-hidden="true"></i>
            <h3>Todavía no hay comprobante</h3>
            <p>El cliente aún no ha subido nada.
               <?php if ($pedido['cliente_email'] !== ''): ?>
                 Puedes reenviarle el enlace de seguimiento:
                 <a href="<?= e(Pedidos::enlaceSeguimiento($pedido, true)) ?>" target="_blank" rel="noopener">abrirlo</a>.
               <?php endif; ?></p>
          </div>
        <?php else: ?>
          <?php foreach ($pedido['comprobantes'] as $indice => $c):
              $esImagen = str_starts_with((string)$c['mime'], 'image/');
              $urlArchivo = url('comprobante.php?id=' . (int)$c['id']);
          ?>
            <div class="comprobante-vista">
              <?php if ($esImagen && $indice === 0): ?>
                <a href="<?= e($urlArchivo) ?>" target="_blank" rel="noopener">
                  <img src="<?= e($urlArchivo) ?>" alt="Comprobante del pedido <?= e((string)$pedido['codigo']) ?>">
                </a>
              <?php endif; ?>
              <div class="meta">
                <div>
                  <strong><?= e(fecha_larga((string)$c['subido_en'])) ?></strong><br>
                  <span class="celda-sub">
                    <?= e(tamano_legible((int)$c['tamano'])) ?> ·
                    <?= e((string)$c['mime']) ?>
                    <?= $c['banco'] !== ''      ? ' · ' . e((string)$c['banco']) : '' ?>
                    <?= $c['referencia'] !== '' ? ' · Ref. ' . e((string)$c['referencia']) : '' ?>
                    <?= (float)$c['monto'] > 0  ? ' · ' . e(dinero($c['monto'])) : '' ?>
                  </span>
                  <?php if ($c['estado'] === 'rechazado' && $c['motivo_rechazo'] !== ''): ?>
                    <br><span style="color:var(--p-alerta); font-size:.83rem;">
                      Rechazado: <?= e((string)$c['motivo_rechazo']) ?></span>
                  <?php endif; ?>
                  <?php if ($c['revisado_por'] && $c['revisado_en']): ?>
                    <br><span class="celda-sub">Revisado por <?= e(trim((string)$c['revisor_nombre'] . ' ' . (string)$c['revisor_apellido'])) ?>
                      el <?= e(fecha_corta((string)$c['revisado_en'])) ?></span>
                  <?php endif; ?>
                </div>
                <div style="display:flex; gap:7px; align-items:center;">
                  <span class="estado-suave <?= $c['estado'] === 'aprobado' ? 'si'
                        : ($c['estado'] === 'rechazado' ? 'mal' : 'aviso') ?>">
                    <?= e(ucfirst(str_replace('_', ' ', (string)$c['estado']))) ?></span>
                  <a class="boton boton-claro boton-mini" href="<?= e($urlArchivo) ?>" target="_blank" rel="noopener">
                    <i class="fa-solid fa-up-right-from-square" aria-hidden="true"></i> Abrir</a>
                  <a class="boton boton-claro boton-mini" href="<?= e($urlArchivo . '&descargar=1') ?>">
                    <i class="fa-solid fa-download" aria-hidden="true"></i></a>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>

        <?php if ($hayQueRevisar && Rbac::puede('pagos.aprobar')): ?>
          <div class="caja-aviso alerta">
            <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
            <span>Comprueba que el monto, la fecha y la cuenta de destino coincidan
                  antes de aprobar. La decisión queda registrada a tu nombre.</span>
          </div>
          <div style="display:flex; gap:9px; flex-wrap:wrap;">
            <button type="button" class="boton boton-exito" data-abrir-modal="modalAprobar">
              <i class="fa-solid fa-check" aria-hidden="true"></i> Aprobar pago</button>
            <button type="button" class="boton boton-peligro" data-abrir-modal="modalRechazar">
              <i class="fa-solid fa-xmark" aria-hidden="true"></i> Rechazar pago</button>
            <?php if ($pedido['estado_pago'] === Pedidos::PAGO_RECIBIDO): ?>
              <form method="post" action="<?= e(url('admin/pedido.php')) ?>">
                <?= campoToken() ?>
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="accion" value="revisar">
                <button type="submit" class="boton boton-claro">
                  <i class="fa-solid fa-eye" aria-hidden="true"></i> Marcar «en revisión»</button>
              </form>
            <?php endif; ?>
          </div>
        <?php elseif ($hayQueRevisar): ?>
          <div class="caja-aviso info">
            <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
            <span>Este comprobante está pendiente, pero tu rol no puede aprobar ni rechazar pagos.</span>
          </div>
        <?php endif; ?>
      </div>
    </section>

    <!-- Artículos -->
    <section class="panel">
      <div class="panel-cabecera"><div><h2>Artículos</h2></div></div>
      <div class="panel-cuerpo">
        <?php foreach ($pedido['items'] as $i): ?>
          <div class="linea-articulo">
            <img src="<?= e(url_imagen((string)$i['imagen'])) ?>" alt="" loading="lazy">
            <div class="linea-articulo-datos">
              <strong><?= e((string)$i['nombre']) ?></strong>
              <small><?= (int)$i['cantidad'] ?> × <?= e((string)$pedido['moneda'] . number_format((float)$i['precio_unitario'], 2)) ?></small>
            </div>
            <strong><?= e((string)$pedido['moneda'] . number_format((float)$i['subtotal'], 2)) ?></strong>
          </div>
        <?php endforeach; ?>

        <dl class="lista-datos" style="margin-top:16px; border-top:1px solid var(--p-linea); padding-top:14px;">
          <div><dt>Subtotal</dt><dd><?= e((string)$pedido['moneda'] . number_format((float)$pedido['subtotal'], 2)) ?></dd></div>
          <div><dt>Envío</dt><dd><?= (float)$pedido['envio'] > 0
              ? e((string)$pedido['moneda'] . number_format((float)$pedido['envio'], 2)) : 'Gratis' ?></dd></div>
          <div style="font-size:1.05rem; font-weight:700;"><dt style="color:var(--p-tinta);">Total</dt>
            <dd><?= e((string)$pedido['moneda'] . number_format((float)$pedido['total'], 2)) ?></dd></div>
        </dl>
      </div>
    </section>

    <!-- Historial -->
    <section class="panel">
      <div class="panel-cabecera"><div><h2>Historial</h2></div></div>
      <div class="panel-cuerpo">
        <div class="historial">
          <?php foreach (array_reverse($pedido['historial']) as $h):
              $et = $h['tipo'] === 'nota'
                  ? 'Nota interna'
                  : Pedidos::estado($pdo, $h['tipo'] === 'pago' ? 'pago' : 'pedido', (string)$h['estado_nuevo'])['nombre'];
          ?>
            <div class="historial-item">
              <strong><?= e((string)$et) ?></strong>
              <small><?= e(fecha_larga((string)$h['created_at'])) ?> · <?= e((string)$h['usuario_texto']) ?></small>
              <?php if ($h['nota'] !== ''): ?><p><?= e((string)$h['nota']) ?></p><?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </section>
  </div>

  <div>
    <!-- Estado -->
    <?php if ($siguientes && Rbac::puedeAlguno('pedidos.editar', 'pedidos.cancelar')): ?>
      <section class="panel">
        <div class="panel-cabecera"><div>
          <h2>Cambiar estado</h2>
          <p>Solo se ofrecen los pasos válidos desde «<?= e((string)$estadoPedido['nombre']) ?>».</p>
        </div></div>
        <div class="panel-cuerpo">
          <form method="post" action="<?= e(url('admin/pedido.php')) ?>">
            <?= campoToken() ?>
            <input type="hidden" name="id" value="<?= $id ?>">
            <input type="hidden" name="accion" value="estado">

            <div class="campo">
              <label for="estado">Nuevo estado</label>
              <select id="estado" name="estado" required>
                <?php foreach ($siguientes as $codigo):
                    if ($codigo === Pedidos::CANCELADO && !Rbac::puede('pedidos.cancelar')) { continue; }
                    if ($codigo !== Pedidos::CANCELADO && !Rbac::puede('pedidos.editar'))   { continue; }
                    $est = Pedidos::estado($pdo, 'pedido', $codigo);
                ?>
                  <option value="<?= e($codigo) ?>"><?= e((string)$est['nombre']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="campo">
              <label for="nota">Nota para el cliente (opcional)</label>
              <input type="text" id="nota" name="nota" maxlength="400" placeholder="Sale a las 3 de la tarde…">
              <p class="ayuda">Se incluye en el correo que recibe el cliente.</p>
            </div>
            <button type="submit" class="boton boton-principal">Actualizar estado</button>
          </form>

          <?php if ($pedido['metodo_pago'] === 'transferencia' && $pedido['estado_pago'] !== Pedidos::PAGO_APROBADO): ?>
            <p class="ayuda" style="margin-top:12px;">
              Para confirmar el pedido hay que aprobar antes el pago de la transferencia.
            </p>
          <?php endif; ?>
        </div>
      </section>
    <?php endif; ?>

    <!-- Cliente -->
    <section class="panel">
      <div class="panel-cabecera"><div><h2>Cliente</h2></div></div>
      <div class="panel-cuerpo">
        <dl class="lista-datos">
          <div><dt>Nombre</dt><dd><?= e((string)$pedido['cliente_nombre']) ?></dd></div>
          <div><dt>Correo</dt><dd><a href="mailto:<?= e((string)$pedido['cliente_email']) ?>"><?= e((string)$pedido['cliente_email']) ?></a></dd></div>
          <div><dt>Teléfono</dt><dd><a href="tel:<?= e((string)$pedido['cliente_telefono']) ?>"><?= e((string)$pedido['cliente_telefono']) ?></a></dd></div>
          <div><dt>Cuenta</dt><dd><?= $pedido['usuario_id'] ? 'Registrado' : 'Invitado' ?></dd></div>
        </dl>
        <a class="boton boton-claro" style="margin-top:14px; width:100%;"
           href="<?= e(enlace_whatsapp('Hola ' . $pedido['cliente_nombre'] . ', te escribimos por tu pedido '
                 . $pedido['codigo'] . '.', (string)$pedido['cliente_telefono'])) ?>"
           target="_blank" rel="noopener">
          <i class="fa-brands fa-whatsapp" aria-hidden="true"></i> Escribir al cliente</a>
      </div>
    </section>

    <!-- Entrega -->
    <section class="panel">
      <div class="panel-cabecera"><div><h2>Entrega</h2></div></div>
      <div class="panel-cuerpo">
        <dl class="lista-datos">
          <div><dt>Modalidad</dt><dd><?= $pedido['entrega_tipo'] === 'retiro' ? 'Retiro en tienda' : 'A domicilio' ?></dd></div>
          <?php if ($pedido['entrega_tipo'] === 'domicilio'): ?>
            <div><dt>Dirección</dt><dd><?= e((string)$pedido['entrega_direccion']) ?></dd></div>
            <div><dt>Zona</dt><dd>
              <?= e((string)($pedido['zona_envio_nombre'] ?: $pedido['entrega_ciudad'])) ?>
              <?php if ((float)$pedido['envio'] > 0): ?>
                <span class="celda-sub">envío <?= e(dinero($pedido['envio'])) ?></span>
              <?php endif; ?>
            </dd></div>
            <?php if ($pedido['entrega_referencia'] !== ''): ?>
              <div><dt>Referencia</dt><dd><?= e((string)$pedido['entrega_referencia']) ?></dd></div>
            <?php endif; ?>
          <?php endif; ?>
          <div><dt>Recibe</dt><dd><?= e((string)$pedido['entrega_nombre']) ?></dd></div>
          <?php if ($pedido['entrega_telefono'] !== ''): ?>
            <div><dt>Su teléfono</dt><dd><?= e((string)$pedido['entrega_telefono']) ?></dd></div>
          <?php endif; ?>
          <?php if ($pedido['entrega_fecha']): ?>
            <div><dt>Fecha</dt><dd><?= e(fecha_corta((string)$pedido['entrega_fecha'])) ?></dd></div>
          <?php endif; ?>
          <?php if ($pedido['entrega_franja'] !== ''): ?>
            <div><dt>Franja</dt><dd><?= e((string)$pedido['entrega_franja']) ?></dd></div>
          <?php endif; ?>
        </dl>

        <?php $mapaUrl = (string)($pedido['entrega_mapa_url'] ?? ''); ?>
        <?php if ($mapaUrl !== '' && $pedido['entrega_tipo'] === 'domicilio'): ?>
          <a href="<?= e($mapaUrl) ?>" target="_blank" rel="noopener noreferrer nofollow"
             class="boton boton-principal" style="margin-top:16px;">
            <i class="fa-solid fa-location-dot" aria-hidden="true"></i>
            Abrir en <?= e(Envios::servicioMapa($mapaUrl)) ?>
          </a>
          <p class="ayuda" style="margin-top:8px;">
            Punto exacto que marcó el cliente. Envíaselo al repartidor junto con la dirección escrita.
          </p>
        <?php endif; ?>

        <?php if ($puedeDespachar): ?>
          <div style="margin-top:20px; padding-top:18px; border-top:1px solid var(--p-linea);">
            <p class="etiqueta">Mandar al motorizado</p>

            <?php if ($pedido['repartidor_nombre'] !== ''): ?>
              <div class="caja-aviso exito" style="margin-bottom:14px;">
                <i class="fa-solid fa-motorcycle" aria-hidden="true"></i>
                <span>Ya se le mandó a <strong><?= e((string)$pedido['repartidor_nombre']) ?></strong>
                  (<?= e((string)$pedido['repartidor_telefono']) ?>)
                  el <?= e(fecha_larga((string)$pedido['repartidor_enviado_en'])) ?>.
                  Puedes volver a mandarlo o pasárselo a otro.</span>
              </div>
            <?php endif; ?>

            <?php if (!$repartidores): ?>
              <div class="caja-aviso alerta">
                <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
                <span>No hay ningún repartidor disponible.
                  <?php if (Rbac::puede('repartidores.gestionar')): ?>
                    <a href="<?= e(url('admin/repartidores.php')) ?>">Añade uno aquí</a>
                    con su número de WhatsApp.
                  <?php else: ?>
                    Pídele a un administrador que registre uno.
                  <?php endif; ?></span>
              </div>
            <?php else: ?>
              <form method="post" action="<?= e(url('admin/pedido.php')) ?>">
                <?= campoToken() ?>
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="accion" value="despachar">

                <div class="campo">
                  <label for="repartidor_id">¿A quién se lo mandamos?</label>
                  <select id="repartidor_id" name="repartidor_id" required>
                    <?php foreach ($repartidores as $rp): ?>
                      <option value="<?= (int)$rp['id'] ?>"
                              <?= (int)$pedido['repartidor_id'] === (int)$rp['id'] ? 'selected' : '' ?>>
                        <?= e((string)$rp['nombre']) ?>
                        <?= (string)$rp['vehiculo'] !== '' ? ' — ' . e((string)$rp['vehiculo']) : '' ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>

                <button type="submit" class="boton boton-principal boton-bloque">
                  <i class="fa-brands fa-whatsapp" aria-hidden="true"></i>
                  Enviar la entrega por WhatsApp
                </button>
                <p class="ayuda" style="margin-top:8px;">
                  Se abre WhatsApp con la dirección, la zona, la referencia, el enlace del mapa,
                  el detalle y cuánto cobrar. Queda anotado en el historial del pedido.
                </p>
              </form>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <?php if ($pedido['dedicatoria'] !== ''): ?>
          <p class="etiqueta" style="margin-top:16px;">Dedicatoria</p>
          <p style="padding:11px 13px; background:var(--p-rosa-sw); border-radius:9px; font-style:italic;">
            «<?= e((string)$pedido['dedicatoria']) ?>»</p>
        <?php endif; ?>

        <?php if ($pedido['notas_cliente'] !== ''): ?>
          <p class="etiqueta" style="margin-top:16px;">Notas del cliente</p>
          <p style="font-size:.89rem; color:var(--p-suave);"><?= nl2br(e((string)$pedido['notas_cliente'])) ?></p>
        <?php endif; ?>
      </div>
    </section>

    <!-- Notas internas -->
    <?php if (Rbac::puede('pedidos.editar')): ?>
      <section class="panel">
        <div class="panel-cabecera"><div>
          <h2>Notas internas</h2>
          <p>El cliente no las ve.</p>
        </div></div>
        <div class="panel-cuerpo">
          <form method="post" action="<?= e(url('admin/pedido.php')) ?>">
            <?= campoToken() ?>
            <input type="hidden" name="id" value="<?= $id ?>">
            <input type="hidden" name="accion" value="nota">
            <div class="campo">
              <label for="notas_internas" class="sr-only">Notas internas</label>
              <textarea id="notas_internas" name="notas_internas" maxlength="1000"
                        placeholder="Detalles de coordinación, incidencias, acuerdos con el cliente…"><?= e((string)$pedido['notas_internas']) ?></textarea>
            </div>
            <button type="submit" class="boton boton-claro">Guardar nota</button>
          </form>
        </div>
      </section>
    <?php endif; ?>
  </div>
</div>

<!-- Modales de aprobación y rechazo -->
<?php if ($hayQueRevisar && Rbac::puede('pagos.aprobar')): ?>
  <dialog class="modal" id="modalAprobar">
    <form method="post" action="<?= e(url('admin/pedido.php')) ?>" data-una-vez>
      <?= campoToken() ?>
      <input type="hidden" name="id" value="<?= $id ?>">
      <input type="hidden" name="accion" value="aprobar">
      <div class="modal-cabecera">
        <h2>Aprobar el pago</h2>
        <p class="sub">El pedido pasará a «confirmado» y el cliente recibirá el aviso por correo.
           Queda registrado a tu nombre en la auditoría.</p>
      </div>
      <div class="modal-cuerpo">
        <div class="campo">
          <label for="nota_aprobar">Nota interna (opcional)</label>
          <input type="text" id="nota_aprobar" name="nota" maxlength="400"
                 placeholder="Verificado contra el estado de cuenta del 29/08">
        </div>
      </div>
      <div class="modal-pie">
        <button type="button" class="boton boton-claro" data-cerrar-modal>Cancelar</button>
        <button type="submit" class="boton boton-exito">
          <i class="fa-solid fa-check" aria-hidden="true"></i> Confirmar aprobación</button>
      </div>
    </form>
  </dialog>

  <dialog class="modal" id="modalRechazar">
    <form method="post" action="<?= e(url('admin/pedido.php')) ?>" data-una-vez>
      <?= campoToken() ?>
      <input type="hidden" name="id" value="<?= $id ?>">
      <input type="hidden" name="accion" value="rechazar">
      <div class="modal-cabecera">
        <h2>Rechazar el pago</h2>
        <p class="sub">El cliente verá el motivo y podrá subir otro comprobante. Sé concreto:
           es lo único que le explica qué hacer.</p>
      </div>
      <div class="modal-cuerpo">
        <div class="campo">
          <label for="motivo">Motivo del rechazo *</label>
          <textarea id="motivo" name="motivo" required minlength="10" maxlength="400"
                    placeholder="La imagen no permite leer el monto ni la fecha de la transferencia."></textarea>
          <p class="ayuda">Mínimo 10 caracteres. Se envía tal cual en el correo.</p>
        </div>
      </div>
      <div class="modal-pie">
        <button type="button" class="boton boton-claro" data-cerrar-modal>Cancelar</button>
        <button type="submit" class="boton boton-peligro">
          <i class="fa-solid fa-xmark" aria-hidden="true"></i> Rechazar y avisar</button>
      </div>
    </form>
  </dialog>
<?php endif; ?>

<?php require __DIR__ . '/_pie.php'; ?>
