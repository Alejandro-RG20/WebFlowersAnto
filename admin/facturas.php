<?php
/**
 * Facturas emitidas.
 *
 * Aquí no se edita nada: una factura emitida no se toca. Lo que se puede hacer
 * es consultarla, imprimirla, volver a mandársela al cliente y —con permiso
 * aparte— anularla dejando escrito el motivo.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

$seccion = 'facturas';
Rbac::exigirPanel();
Rbac::exigir('facturas.ver');

$puedeEmitir = Rbac::puede('facturas.emitir');
$puedeAnular = Rbac::puede('facturas.anular');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirToken(false, 'admin/facturas.php');

    $accion    = opcion('accion', ['emitir', 'reenviar', 'anular'], 'emitir');
    $facturaId = identificador('factura_id');
    $pedidoId  = identificador('pedido_id');

    if ($accion === 'emitir') {
        Rbac::exigir('facturas.emitir');
        $pedido = Pedidos::porId($pdo, $pedidoId);
        if (!$pedido) {
            flash('error', 'Ese pedido no existe.');
            redirigir('admin/facturas.php');
        }
        $r = Facturas::emitir($pdo, $pedido);
        if (!$r['ok']) {
            flash('error', $r['error']);
        } elseif (!empty($r['repetida'])) {
            flash('info', 'Ese pedido ya tenía la factura ' . $r['factura']['folio'] . '.');
        } else {
            $enviada = Facturas::enviar($pdo, $r['factura'], $pedido);
            flash('exito', 'Factura ' . $r['factura']['folio'] . ' emitida'
                         . ($enviada ? ' y enviada a ' . $r['factura']['cliente_email'] . '.'
                                     : '. No se pudo enviar el correo; puedes reenviarla desde la lista.'));
        }
        redirigir('admin/facturas.php');
    }

    $factura = Facturas::porId($pdo, $facturaId);
    if (!$factura) {
        flash('error', 'Esa factura no existe.');
        redirigir('admin/facturas.php');
    }

    if ($accion === 'reenviar') {
        Rbac::exigir('facturas.emitir');
        if (!limitar($pdo, 'factura_envio:' . $facturaId, 5, 3600)) {
            flash('alerta', 'Ya se reenvió esa factura varias veces seguidas. Espera un rato.');
            redirigir('admin/facturas.php');
        }
        $ok = Facturas::enviar($pdo, $factura);
        Auditoria::registrar($pdo, 'reenviar_factura', 'ventas', [
            'recurso_tipo' => 'factura', 'recurso_id' => (string)$facturaId,
            'descripcion'  => 'Factura ' . $factura['folio'] . ' reenviada a ' . $factura['cliente_email'] . '.',
            'resultado'    => $ok ? 'exito' : 'error',
        ]);
        flash($ok ? 'exito' : 'error', $ok
            ? 'Factura reenviada a ' . $factura['cliente_email'] . '.'
            : 'No se pudo enviar: ' . (Correo::ultimoError() ?: 'el transporte rechazó el mensaje.'));
        redirigir('admin/facturas.php');
    }

    if ($accion === 'anular') {
        Rbac::exigir('facturas.anular');
        $r = Facturas::anular($pdo, $factura, textoLargo('motivo', 300));
        flash($r['ok'] ? 'exito' : 'error',
              $r['ok'] ? 'Factura ' . $factura['folio'] . ' anulada.' : $r['error']);
        redirigir('admin/facturas.php');
    }
}

// --- Listado -----------------------------------------------------------
$busqueda = texto('q', 60, $_GET);
$estado   = opcion('estado', ['todas', 'vigentes', 'anuladas'], 'todas', $_GET);
$pagina   = entero('pagina', 1, 9999, 1, $_GET);
$porPagina = 25;

$donde = [];
$args  = [];
if ($busqueda !== '') {
    $donde[] = '(folio LIKE ? OR pedido_codigo LIKE ? OR cliente_nombre LIKE ? OR cliente_email LIKE ?)';
    array_push($args, ...array_fill(0, 4, '%' . $busqueda . '%'));
}
if ($estado === 'vigentes') {
    $donde[] = 'anulada_en IS NULL';
} elseif ($estado === 'anuladas') {
    $donde[] = 'anulada_en IS NOT NULL';
}
$sqlDonde = $donde ? 'WHERE ' . implode(' AND ', $donde) : '';

$st = $pdo->prepare("SELECT COUNT(*) FROM facturas $sqlDonde");
$st->execute($args);
$total   = (int)$st->fetchColumn();
$paginas = max(1, (int)ceil($total / $porPagina));
$pagina  = min($pagina, $paginas);

$st = $pdo->prepare(
    "SELECT * FROM facturas $sqlDonde ORDER BY id DESC LIMIT $porPagina OFFSET " . (($pagina - 1) * $porPagina)
);
$st->execute($args);
$facturas = $st->fetchAll();

// Lo facturado, para tener el dato a la vista sin ir a buscarlo.
$resumen = $pdo->query(
    "SELECT COUNT(*) AS n, COALESCE(SUM(total), 0) AS suma
       FROM facturas WHERE anulada_en IS NULL"
)->fetch();

// Pedidos entregados que se quedaron sin factura: es lo que hay que arreglar,
// así que se enseña arriba en vez de esconderlo en un informe.
$sinFactura = Facturas::activo()
    ? $pdo->query(
        "SELECT p.id, p.codigo, p.cliente_nombre, p.total, p.updated_at
           FROM pedidos p
      LEFT JOIN facturas f ON f.pedido_id = p.id
          WHERE p.estado = 'completado' AND f.id IS NULL
       ORDER BY p.id DESC LIMIT 12"
      )->fetchAll()
    : [];

$tituloPanel = 'Facturas';
require __DIR__ . '/_cabecera.php';
?>

<?php if (!Facturas::activo()): ?>
  <div class="caja-aviso alerta">
    <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
    <span>La facturación está <strong>apagada</strong>, así que no se emite ninguna factura.
      Se enciende en <a href="<?= e(url('admin/configuracion.php?t=factura')) ?>">Configuración →
      Facturación</a>, donde también se ponen el RUC y los datos que salen en el documento.</span>
  </div>
<?php endif; ?>

<div class="rejilla-metricas" style="margin-bottom:20px;">
  <div class="metrica">
    <span class="metrica-etiqueta"><i class="fa-solid fa-file-invoice" aria-hidden="true"></i> Facturas vigentes</span>
    <span class="metrica-valor"><?= (int)$resumen['n'] ?></span>
    <span class="metrica-nota">Sin contar las anuladas</span>
  </div>
  <div class="metrica">
    <span class="metrica-etiqueta"><i class="fa-solid fa-sack-dollar" aria-hidden="true"></i> Total facturado</span>
    <span class="metrica-valor"><?= e(dinero($resumen['suma'])) ?></span>
    <span class="metrica-nota">Suma de las facturas vigentes</span>
  </div>
  <div class="metrica">
    <span class="metrica-etiqueta"><i class="fa-solid fa-hashtag" aria-hidden="true"></i> Siguiente folio</span>
    <span class="metrica-valor"><?= e(Facturas::folio(Facturas::serie(),
        (int)Ajustes::numero('factura_siguiente', 1))) ?></span>
    <span class="metrica-nota">El que llevará la próxima</span>
  </div>
</div>

<?php if ($sinFactura): ?>
  <section class="panel" style="margin-bottom:20px;">
    <div class="panel-cabecera">
      <div><h2>Entregados sin factura</h2>
        <p><?= count($sinFactura) ?> <?= unidad_plural(count($sinFactura), 'pedidos') ?>
           que se entregaron antes de encender la facturación, o cuya emisión falló</p></div>
    </div>
    <div class="tabla-envoltura">
      <table class="tabla">
        <thead><tr><th>Pedido</th><th>Cliente</th><th class="num">Total</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($sinFactura as $p): ?>
            <tr>
              <td class="celda-principal"><?= e((string)$p['codigo']) ?></td>
              <td><?= e((string)$p['cliente_nombre']) ?></td>
              <td class="num"><?= e(dinero($p['total'])) ?></td>
              <td class="acciones">
                <?php if ($puedeEmitir): ?>
                  <form method="post" action="<?= e(url('admin/facturas.php')) ?>" data-una-vez>
                    <?= campoToken() ?>
                    <input type="hidden" name="accion" value="emitir">
                    <input type="hidden" name="pedido_id" value="<?= (int)$p['id'] ?>">
                    <button type="submit" class="boton boton-claro boton-mini">
                      <i class="fa-solid fa-file-invoice" aria-hidden="true"></i> Emitir y enviar</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
<?php endif; ?>

<section class="panel">
  <div class="panel-cabecera">
    <div><h2>Emitidas</h2><p><?= $total ?> en total</p></div>
    <form method="get" action="<?= e(url('admin/facturas.php')) ?>" class="filtros-linea">
      <input type="search" name="q" value="<?= e($busqueda) ?>" placeholder="Folio, pedido o cliente"
             aria-label="Buscar facturas">
      <select name="estado" aria-label="Estado">
        <?php foreach (['todas' => 'Todas', 'vigentes' => 'Vigentes', 'anuladas' => 'Anuladas'] as $k => $v): ?>
          <option value="<?= e($k) ?>" <?= $estado === $k ? 'selected' : '' ?>><?= e($v) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="boton boton-claro boton-mini">Buscar</button>
    </form>
  </div>

  <?php if (!$facturas): ?>
    <div class="vacio">
      <i class="fa-solid fa-file-invoice" aria-hidden="true"></i>
      <h3><?= $busqueda !== '' || $estado !== 'todas'
            ? 'Ninguna factura coincide con esa búsqueda'
            : 'Todavía no hay facturas' ?></h3>
      <p><?= $busqueda !== '' || $estado !== 'todas'
            ? 'Prueba con otro folio, otro código de pedido o el correo del cliente.'
            : 'Se emiten solas cuando marcas un pedido como entregado.' ?></p>
    </div>
  <?php else: ?>
    <div class="tabla-envoltura">
      <table class="tabla">
        <thead>
          <tr><th>Folio</th><th>Fecha</th><th>Cliente</th><th>Pedido</th>
              <th class="num">Total</th><th>Estado</th><th></th></tr>
        </thead>
        <tbody>
          <?php foreach ($facturas as $f):
              $anulada = Facturas::anulada($f); ?>
            <tr<?= $anulada ? ' style="opacity:.62;"' : '' ?>>
              <td class="celda-principal"><?= e((string)$f['folio']) ?></td>
              <td>
                <?= e(fecha_corta((string)$f['emitida_en'])) ?>
                <?php if ($f['enviada_en']): ?>
                  <br><span class="celda-sub" title="Enviada al cliente">
                    <i class="fa-solid fa-paper-plane" aria-hidden="true"></i> enviada</span>
                <?php endif; ?>
              </td>
              <td><?= e((string)$f['cliente_nombre']) ?><br>
                  <span class="celda-sub"><?= e((string)$f['cliente_email']) ?></span></td>
              <td><a href="<?= e(url('admin/pedido.php?id=' . (int)$f['pedido_id'])) ?>">
                    <?= e((string)$f['pedido_codigo']) ?></a></td>
              <td class="num"><?= e(dinero($f['total'])) ?></td>
              <td><span class="estado-suave <?= $anulada ? 'no' : 'si' ?>">
                    <?= $anulada ? 'Anulada' : 'Vigente' ?></span></td>
              <td class="acciones">
                <div style="display:inline-flex; gap:5px;">
                  <a class="boton-icono" target="_blank" rel="noopener"
                     href="<?= e(url('admin/factura.php?id=' . (int)$f['id'])) ?>"
                     aria-label="Ver la factura <?= e((string)$f['folio']) ?>">
                    <i class="fa-solid fa-eye" aria-hidden="true"></i></a>

                  <?php if ($puedeEmitir && !$anulada && (string)$f['cliente_email'] !== ''): ?>
                    <form method="post" action="<?= e(url('admin/facturas.php')) ?>" data-una-vez>
                      <?= campoToken() ?>
                      <input type="hidden" name="accion" value="reenviar">
                      <input type="hidden" name="factura_id" value="<?= (int)$f['id'] ?>">
                      <button type="submit" class="boton-icono"
                              aria-label="Reenviar la factura <?= e((string)$f['folio']) ?>">
                        <i class="fa-solid fa-paper-plane" aria-hidden="true"></i></button>
                    </form>
                  <?php endif; ?>

                  <?php if ($puedeAnular && !$anulada): ?>
                    <button type="button" class="boton-icono peligro" data-abrir-modal="modalAnular"
                            data-campo-factura_id="<?= (int)$f['id'] ?>"
                            data-campo-folio_visible="<?= e((string)$f['folio']) ?>"
                            aria-label="Anular la factura <?= e((string)$f['folio']) ?>">
                      <i class="fa-solid fa-ban" aria-hidden="true"></i></button>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($paginas > 1): ?>
      <div class="paginacion">
        <?php for ($i = 1; $i <= $paginas; $i++):
            $url = url('admin/facturas.php?' . http_build_query(array_filter([
                'q' => $busqueda, 'estado' => $estado !== 'todas' ? $estado : '', 'pagina' => $i,
            ]))); ?>
          <?php if ($i === $pagina): ?>
            <span aria-current="page"><?= $i ?></span>
          <?php else: ?>
            <a href="<?= e($url) ?>"><?= $i ?></a>
          <?php endif; ?>
        <?php endfor; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</section>

<?php if ($puedeAnular): ?>
  <dialog class="modal" id="modalAnular">
    <form method="post" action="<?= e(url('admin/facturas.php')) ?>">
      <?= campoToken() ?>
      <input type="hidden" name="accion" value="anular">
      <input type="hidden" name="factura_id" id="factura_id" value="0">
      <div class="modal-cabecera"><h2>Anular factura</h2></div>
      <div class="modal-cuerpo">
        <div class="caja-aviso alerta">
          <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
          <span>La factura no se borra: queda marcada como anulada, con este motivo y con tu
            nombre. Su número tampoco se reutiliza, para que la serie no tenga huecos.</span>
        </div>
        <div class="campo">
          <label for="motivo">Motivo de la anulación</label>
          <textarea id="motivo" name="motivo" maxlength="300" required minlength="5"
                    placeholder="Por ejemplo: el pedido se devolvió completo."></textarea>
        </div>
      </div>
      <div class="modal-pie">
        <button type="button" class="boton boton-claro" data-cerrar-modal>Cancelar</button>
        <button type="submit" class="boton boton-peligro">Anular la factura</button>
      </div>
    </form>
  </dialog>
<?php endif; ?>

<?php require __DIR__ . '/_pie.php'; ?>
