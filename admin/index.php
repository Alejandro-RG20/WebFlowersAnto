<?php
/**
 * Resumen del panel.
 *
 * Solo se consulta lo que el usuario tiene permiso de ver: un empleado de
 * productos no dispara las consultas de ventas ni las ve en pantalla.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

$tituloPanel    = 'Resumen';
$subtituloPanel = 'Cómo va el negocio hoy, ' . date('j/n/Y');
$seccion        = 'resumen';

// La comprobación de permisos va antes de imprimir nada: si falta el permiso
// se muestra la página de acceso denegado, no media pantalla del panel.
Rbac::exigirPanel();
Rbac::exigir('dashboard.ver');

$verPedidos   = Rbac::puede('pedidos.ver');
$verProductos = Rbac::puede('productos.ver');
$verClientes  = Rbac::puede('clientes.ver');

// --- Métricas de pedidos ---------------------------------------------
$m = ['pendientes' => 0, 'revision' => 0, 'preparacion' => 0, 'mes' => 0.0, 'hoy' => 0, 'total' => 0];
if ($verPedidos) {
    $fila = $pdo->query(
        "SELECT
            SUM(estado = 'pendiente')                                        AS pendientes,
            SUM(estado_pago IN ('comprobante_recibido','en_revision'))       AS revision,
            SUM(estado IN ('confirmado','preparacion','listo'))              AS preparacion,
            SUM(DATE(created_at) = CURDATE())                                AS hoy,
            COUNT(*)                                                         AS total
           FROM pedidos"
    )->fetch() ?: [];
    $m = array_map('intval', array_map(fn($v) => $v ?? 0, $fila));

    $m['mes'] = (float)$pdo->query(
        "SELECT COALESCE(SUM(total), 0) FROM pedidos
          WHERE estado_pago = 'aprobado' AND estado <> 'cancelado'
            AND YEAR(created_at) = YEAR(CURDATE()) AND MONTH(created_at) = MONTH(CURDATE())"
    )->fetchColumn();
}

$productos = ['activos' => 0, 'agotados' => 0];
if ($verProductos) {
    $fila = $pdo->query(
        "SELECT SUM(activo = 1) AS activos,
                SUM(activo = 1 AND (disponible = 0 OR (controla_stock = 1 AND stock <= 0))) AS agotados
           FROM productos"
    )->fetch() ?: [];
    $productos = array_map('intval', array_map(fn($v) => $v ?? 0, $fila));
}

$clientes = 0;
if ($verClientes) {
    $clientes = (int)$pdo->query(
        "SELECT COUNT(*) FROM usuarios u JOIN roles r ON r.id = u.rol_id
          WHERE r.codigo = 'cliente' AND u.activo = 1"
    )->fetchColumn();
}

// --- Ventas de los últimos 7 días -------------------------------------
$serie = [];
if ($verPedidos) {
    $st = $pdo->query(
        "SELECT DATE(created_at) AS dia, COUNT(*) AS pedidos, COALESCE(SUM(total), 0) AS importe
           FROM pedidos
          WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) AND estado <> 'cancelado'
       GROUP BY DATE(created_at)"
    )->fetchAll();
    $porDia = array_column($st, null, 'dia');

    for ($i = 6; $i >= 0; $i--) {
        $dia = date('Y-m-d', strtotime("-$i days"));
        $serie[] = [
            'dia'     => $dia,
            'pedidos' => (int)($porDia[$dia]['pedidos'] ?? 0),
            'importe' => (float)($porDia[$dia]['importe'] ?? 0),
        ];
    }
}
$maximoSerie = max(1, max(array_column($serie ?: [['pedidos' => 1]], 'pedidos')));

// --- Listas ------------------------------------------------------------
$porRevisar = $verPedidos ? $pdo->query(
    "SELECT id, codigo, cliente_nombre, total, moneda, created_at, estado_pago
       FROM pedidos WHERE estado_pago IN ('comprobante_recibido','en_revision')
      ORDER BY created_at ASC LIMIT 6"
)->fetchAll() : [];

$ultimos = $verPedidos ? $pdo->query(
    "SELECT id, codigo, cliente_nombre, total, moneda, estado, created_at
       FROM pedidos ORDER BY created_at DESC LIMIT 8"
)->fetchAll() : [];

$agotados = $verProductos ? $pdo->query(
    "SELECT id, nombre, slug, stock, disponible FROM productos
      WHERE activo = 1 AND (disponible = 0 OR (controla_stock = 1 AND stock <= 0))
      ORDER BY nombre LIMIT 6"
)->fetchAll() : [];

$actividad = Rbac::puede('auditoria.ver') ? $pdo->query(
    "SELECT usuario_texto, accion, modulo, descripcion, created_at, resultado
       FROM auditoria ORDER BY created_at DESC LIMIT 8"
)->fetchAll() : [];

// Revisión del estado: lo que, si está mal, no se nota hasta que lo sufre un
// cliente. Solo la ve quien puede arreglarlo.
require_once __DIR__ . '/../includes/lib/salud.php';
$salud   = Rbac::puede('configuracion.editar') ? Salud::revisar($pdo) : [];
$pendon  = array_values(array_filter($salud, fn($x) => $x['nivel'] !== 'bien'));
$conteo  = Salud::resumen($salud);

require __DIR__ . '/_cabecera.php';
?>

<?php if ($pendon): ?>
  <section class="panel panel-salud">
    <div class="panel-cabecera"><div>
      <h2>Antes de abrir al público</h2>
      <p><?= (int)$conteo['grave'] ?> <?= e(unidad_plural((int)$conteo['grave'], 'problemas')) ?>
         <?= $conteo['grave'] === 1 ? 'serio' : 'serios' ?>
         y <?= (int)$conteo['aviso'] ?> <?= e(unidad_plural((int)$conteo['aviso'], 'avisos')) ?>.
         Lo demás está en orden.</p>
    </div></div>
    <div class="panel-cuerpo">
      <?php foreach ($pendon as $x): ?>
        <div class="salud-fila <?= e($x['nivel']) ?>">
          <i class="fa-solid <?= $x['nivel'] === 'grave' ? 'fa-triangle-exclamation' : 'fa-circle-info' ?>"
             aria-hidden="true"></i>
          <div>
            <strong><?= e($x['titulo']) ?></strong>
            <p><?= e($x['detalle']) ?></p>
            <?php if ($x['arreglo'] !== ''): ?>
              <p class="salud-arreglo"><?= e($x['arreglo']) ?></p>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </section>
<?php endif; ?>

<div class="rejilla-metricas">
  <?php if ($verPedidos): ?>
    <a class="metrica<?= $m['revision'] > 0 ? ' urgente' : '' ?>" href="<?= e(url('admin/pedidos.php?pago=revision')) ?>">
      <span class="metrica-etiqueta"><i class="fa-solid fa-file-invoice-dollar" aria-hidden="true"></i> Comprobantes por revisar</span>
      <span class="metrica-valor"><?= (int)$m['revision'] ?></span>
      <span class="metrica-nota"><?= $m['revision'] > 0 ? 'Necesitan tu decisión' : 'Todo revisado' ?></span>
    </a>
    <a class="metrica" href="<?= e(url('admin/pedidos.php?estado=pendiente')) ?>">
      <span class="metrica-etiqueta"><i class="fa-solid fa-hourglass-half" aria-hidden="true"></i> Esperando pago</span>
      <span class="metrica-valor"><?= (int)$m['pendientes'] ?></span>
      <span class="metrica-nota">Sin comprobante todavía</span>
    </a>
    <a class="metrica" href="<?= e(url('admin/pedidos.php?estado=preparacion')) ?>">
      <span class="metrica-etiqueta"><i class="fa-solid fa-scissors" aria-hidden="true"></i> En proceso</span>
      <span class="metrica-valor"><?= (int)$m['preparacion'] ?></span>
      <span class="metrica-nota">Confirmados, en taller o listos</span>
    </a>
    <div class="metrica">
      <span class="metrica-etiqueta"><i class="fa-solid fa-sack-dollar" aria-hidden="true"></i> Cobrado este mes</span>
      <span class="metrica-valor"><?= e(dinero($m['mes'])) ?></span>
      <span class="metrica-nota">Solo pedidos con pago aprobado</span>
    </div>
  <?php endif; ?>

  <?php if ($verProductos): ?>
    <a class="metrica" href="<?= e(url('admin/productos.php')) ?>">
      <span class="metrica-etiqueta"><i class="fa-solid fa-seedling" aria-hidden="true"></i> Productos publicados</span>
      <span class="metrica-valor"><?= (int)$productos['activos'] ?></span>
      <span class="metrica-nota"><?= (int)$productos['agotados'] ?> sin disponibilidad</span>
    </a>
  <?php endif; ?>

  <?php if ($verClientes): ?>
    <a class="metrica" href="<?= e(url('admin/clientes.php')) ?>">
      <span class="metrica-etiqueta"><i class="fa-solid fa-users" aria-hidden="true"></i> Clientes con cuenta</span>
      <span class="metrica-valor"><?= (int)$clientes ?></span>
      <span class="metrica-nota"><?= (int)$m['hoy'] ?> pedidos hoy</span>
    </a>
  <?php endif; ?>
</div>

<div class="rejilla-detalle">
  <div>
    <?php if ($verPedidos): ?>
      <!-- Comprobantes por revisar -->
      <section class="panel">
        <div class="panel-cabecera">
          <div>
            <h2>Comprobantes esperando revisión</h2>
            <p>Nada se aprueba solo: cada pago lo confirma una persona.</p>
          </div>
          <a class="boton boton-claro boton-mini" href="<?= e(url('admin/pedidos.php?pago=revision')) ?>">Ver todos</a>
        </div>
        <?php if (!$porRevisar): ?>
          <div class="vacio">
            <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
            <h3>No hay comprobantes pendientes</h3>
            <p>Cuando un cliente suba uno, aparecerá aquí para que lo revises.</p>
          </div>
        <?php else: ?>
          <div class="tabla-envoltura">
            <table class="tabla">
              <thead><tr><th>Pedido</th><th>Cliente</th><th class="num">Total</th><th>Esperando desde</th><th></th></tr></thead>
              <tbody>
                <?php foreach ($porRevisar as $p): ?>
                  <tr>
                    <td class="celda-principal"><?= e((string)$p['codigo']) ?></td>
                    <td><?= e((string)$p['cliente_nombre']) ?></td>
                    <td class="num"><?= e((string)$p['moneda'] . number_format((float)$p['total'], 2)) ?></td>
                    <td class="celda-sub"><?= e(fecha_corta((string)$p['created_at'])) ?></td>
                    <td class="acciones">
                      <a class="boton boton-rosa boton-mini" href="<?= e(url('admin/pedido.php?id=' . (int)$p['id'])) ?>">Revisar</a>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </section>

      <!-- Últimos pedidos -->
      <section class="panel">
        <div class="panel-cabecera">
          <div><h2>Últimos pedidos</h2></div>
          <a class="boton boton-claro boton-mini" href="<?= e(url('admin/pedidos.php')) ?>">Ver todos</a>
        </div>
        <?php if (!$ultimos): ?>
          <div class="vacio">
            <i class="fa-solid fa-receipt" aria-hidden="true"></i>
            <h3>Todavía no hay pedidos</h3>
            <p>En cuanto llegue el primero lo verás aquí.</p>
          </div>
        <?php else: ?>
          <div class="tabla-envoltura">
            <table class="tabla">
              <thead><tr><th>Pedido</th><th>Cliente</th><th>Estado</th><th class="num">Total</th><th></th></tr></thead>
              <tbody>
                <?php foreach ($ultimos as $p):
                    $estado = Pedidos::estado($pdo, 'pedido', (string)$p['estado']); ?>
                  <tr>
                    <td>
                      <span class="celda-principal"><?= e((string)$p['codigo']) ?></span><br>
                      <span class="celda-sub"><?= e(fecha_corta((string)$p['created_at'])) ?></span>
                    </td>
                    <td><?= e((string)$p['cliente_nombre']) ?></td>
                    <td><span class="estado" style="background: <?= e((string)$estado['color']) ?>;"><?= e((string)$estado['nombre']) ?></span></td>
                    <td class="num"><?= e((string)$p['moneda'] . number_format((float)$p['total'], 2)) ?></td>
                    <td class="acciones">
                      <a class="boton-icono" href="<?= e(url('admin/pedido.php?id=' . (int)$p['id'])) ?>" aria-label="Abrir pedido">
                        <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </section>
    <?php endif; ?>
  </div>

  <div>
    <?php if ($serie): ?>
      <section class="panel">
        <div class="panel-cabecera"><div><h2>Pedidos de los últimos 7 días</h2></div></div>
        <div class="panel-cuerpo">
          <div class="grafico">
            <?php foreach ($serie as $dia): ?>
              <div class="grafico-barra">
                <span class="cifra"><?= (int)$dia['pedidos'] ?></span>
                <span class="valor" data-altura="<?= (int)round($dia['pedidos'] / $maximoSerie * 100) ?>"
                      style="height:0"></span>
                <span class="etiqueta"><?= e(date('D', strtotime((string)$dia['dia']))) ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </section>
    <?php endif; ?>

    <?php if ($verProductos && $agotados): ?>
      <section class="panel">
        <div class="panel-cabecera"><div>
          <h2>Sin disponibilidad</h2>
          <p>Se muestran como «sobre pedido» en la web.</p>
        </div></div>
        <div class="panel-cuerpo">
          <?php foreach ($agotados as $p): ?>
            <div class="linea-articulo">
              <div class="linea-articulo-datos">
                <strong><?= e((string)$p['nombre']) ?></strong>
                <small><?= (int)$p['disponible'] === 0 ? 'Marcado como no disponible' : 'Sin unidades en stock' ?></small>
              </div>
              <a class="boton boton-claro boton-mini" href="<?= e(url('admin/producto.php?id=' . (int)$p['id'])) ?>">Editar</a>
            </div>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endif; ?>

    <?php if ($actividad): ?>
      <section class="panel">
        <div class="panel-cabecera">
          <div><h2>Actividad reciente</h2></div>
          <a class="boton boton-claro boton-mini" href="<?= e(url('admin/auditoria.php')) ?>">Ver auditoría</a>
        </div>
        <div class="panel-cuerpo">
          <div class="historial">
            <?php foreach ($actividad as $a): ?>
              <div class="historial-item">
                <strong><?= e((string)$a['usuario_texto']) ?>
                  <span style="font-weight:400; color:var(--p-suave);">
                    · <?= e(str_replace('_', ' ', (string)$a['accion'])) ?></span></strong>
                <small><?= e(fecha_larga((string)$a['created_at'])) ?></small>
                <?php if ($a['descripcion'] !== ''): ?>
                  <p><?= e((string)$a['descripcion']) ?></p>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </section>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/_pie.php'; ?>
