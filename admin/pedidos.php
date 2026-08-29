<?php
/**
 * Listado de pedidos con búsqueda, filtros y paginación.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

$tituloPanel = 'Pedidos';
$seccion     = 'pedidos';

Rbac::exigirPanel();
Rbac::exigir('pedidos.ver');

$q       = texto('q', 80, $_GET);
$estado  = texto('estado', 40, $_GET);
$pago    = texto('pago', 40, $_GET);
$desde   = fechaOpcional('desde', $_GET);
$hasta   = fechaOpcional('hasta', $_GET);
$orden   = opcion('orden', ['recientes', 'antiguos', 'importe'], 'recientes', $_GET);
$pagina  = entero('pagina', 1, 9999, 1, $_GET);
$porPagina = 25;

$where  = ['1 = 1'];
$params = [];

if ($q !== '') {
    $t = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
    $where[] = '(p.codigo LIKE ? OR p.cliente_nombre LIKE ? OR p.cliente_email LIKE ? OR p.cliente_telefono LIKE ?)';
    array_push($params, $t, $t, $t, $t);
}
if ($estado !== '') {
    $where[]  = 'p.estado = ?';
    $params[] = $estado;
}
if ($pago === 'revision') {
    $where[] = "p.estado_pago IN ('comprobante_recibido','en_revision')";
} elseif ($pago !== '') {
    $where[]  = 'p.estado_pago = ?';
    $params[] = $pago;
}
if ($desde) { $where[] = 'DATE(p.created_at) >= ?'; $params[] = $desde; }
if ($hasta) { $where[] = 'DATE(p.created_at) <= ?'; $params[] = $hasta; }

$sqlWhere = implode(' AND ', $where);
$ordenSql = match ($orden) {
    'antiguos' => 'p.created_at ASC',
    'importe'  => 'p.total DESC',
    default    => 'p.created_at DESC',
};

$stTotal = $pdo->prepare("SELECT COUNT(*) FROM pedidos p WHERE $sqlWhere");
$stTotal->execute($params);
$total   = (int)$stTotal->fetchColumn();
$paginas = max(1, (int)ceil($total / $porPagina));
$pagina  = min($pagina, $paginas);
$salto   = ($pagina - 1) * $porPagina;

$st = $pdo->prepare(
    "SELECT p.*, (SELECT COUNT(*) FROM pedido_items i WHERE i.pedido_id = p.id) AS articulos
       FROM pedidos p WHERE $sqlWhere ORDER BY $ordenSql LIMIT $porPagina OFFSET $salto"
);
$st->execute($params);
$pedidos = $st->fetchAll();

// Contadores de las pestañas
$conteos = $pdo->query(
    "SELECT estado, COUNT(*) AS total FROM pedidos GROUP BY estado"
)->fetchAll(PDO::FETCH_KEY_PAIR);
$porRevisar = (int)$pdo->query(
    "SELECT COUNT(*) FROM pedidos WHERE estado_pago IN ('comprobante_recibido','en_revision')"
)->fetchColumn();

/** URL manteniendo los filtros activos. */
function urlPedidos(array $cambios = []): string
{
    $actual = array_intersect_key($_GET, array_flip(['q', 'estado', 'pago', 'desde', 'hasta', 'orden', 'pagina']));
    $params = array_filter(array_merge($actual, $cambios), fn($v) => $v !== '' && $v !== null);
    return url('admin/pedidos.php' . ($params ? '?' . http_build_query($params) : ''));
}

require __DIR__ . '/_cabecera.php';
?>

<div class="pestanas">
  <a href="<?= e(urlPedidos(['estado' => '', 'pago' => '', 'pagina' => ''])) ?>"
     class="<?= $estado === '' && $pago === '' ? 'activa' : '' ?>">
    Todos <span class="cuenta"><?= array_sum($conteos) ?></span></a>
  <a href="<?= e(urlPedidos(['estado' => '', 'pago' => 'revision', 'pagina' => ''])) ?>"
     class="<?= $pago === 'revision' ? 'activa' : '' ?>">
    Por revisar <span class="cuenta"><?= $porRevisar ?></span></a>
  <?php foreach (Pedidos::estados($pdo, 'pedido') as $codigo => $est): ?>
    <a href="<?= e(urlPedidos(['estado' => (string)$codigo, 'pago' => '', 'pagina' => ''])) ?>"
       class="<?= $estado === $codigo ? 'activa' : '' ?>">
      <?= e((string)$est['nombre']) ?> <span class="cuenta"><?= (int)($conteos[$codigo] ?? 0) ?></span></a>
  <?php endforeach; ?>
</div>

<section class="panel">
  <form class="barra-herramientas" method="get" action="<?= e(url('admin/pedidos.php')) ?>" data-autofiltro>
    <?php foreach (['estado' => $estado, 'pago' => $pago] as $nombre => $valor): if ($valor === '') continue; ?>
      <input type="hidden" name="<?= e($nombre) ?>" value="<?= e($valor) ?>">
    <?php endforeach; ?>
    <div class="campo">
      <label for="q">Buscar</label>
      <input type="search" id="q" name="q" value="<?= e($q) ?>" placeholder="Código, cliente, correo o teléfono">
    </div>
    <div class="campo estrecho">
      <label for="desde">Desde</label>
      <input type="date" id="desde" name="desde" value="<?= e((string)$desde) ?>">
    </div>
    <div class="campo estrecho">
      <label for="hasta">Hasta</label>
      <input type="date" id="hasta" name="hasta" value="<?= e((string)$hasta) ?>">
    </div>
    <div class="campo estrecho">
      <label for="orden">Orden</label>
      <select id="orden" name="orden">
        <option value="recientes"<?= $orden === 'recientes' ? ' selected' : '' ?>>Más recientes</option>
        <option value="antiguos"<?=  $orden === 'antiguos'  ? ' selected' : '' ?>>Más antiguos</option>
        <option value="importe"<?=   $orden === 'importe'   ? ' selected' : '' ?>>Mayor importe</option>
      </select>
    </div>
    <button type="submit" class="boton boton-principal"><i class="fa-solid fa-filter" aria-hidden="true"></i> Filtrar</button>
    <?php if ($q !== '' || $desde || $hasta || $estado !== '' || $pago !== ''): ?>
      <a class="boton boton-claro" href="<?= e(url('admin/pedidos.php')) ?>">Limpiar</a>
    <?php endif; ?>
  </form>

  <?php if (!$pedidos): ?>
    <div class="vacio">
      <i class="fa-solid fa-receipt" aria-hidden="true"></i>
      <h3>No hay pedidos con estos filtros</h3>
      <p>Prueba a quitar algún filtro o a ampliar el rango de fechas.</p>
      <a class="boton boton-claro" href="<?= e(url('admin/pedidos.php')) ?>">Ver todos</a>
    </div>
  <?php else: ?>
    <div class="tabla-envoltura">
      <table class="tabla">
        <thead>
          <tr>
            <th>Pedido</th><th>Cliente</th><th>Estado</th><th>Pago</th>
            <th>Entrega</th><th class="num">Total</th><th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($pedidos as $p):
              $estadoP = Pedidos::estado($pdo, 'pedido', (string)$p['estado']);
              $estadoG = Pedidos::estado($pdo, 'pago',   (string)$p['estado_pago']);
          ?>
            <tr>
              <td>
                <span class="celda-principal"><?= e((string)$p['codigo']) ?></span><br>
                <span class="celda-sub"><?= e(fecha_corta((string)$p['created_at'])) ?>
                  · <?= (int)$p['articulos'] ?> art.</span>
              </td>
              <td>
                <?= e((string)$p['cliente_nombre']) ?><br>
                <span class="celda-sub"><?= e((string)$p['cliente_telefono']) ?></span>
              </td>
              <td><span class="estado" style="background: <?= e((string)$estadoP['color']) ?>;">
                <?= e((string)$estadoP['nombre']) ?></span></td>
              <td><span class="estado" style="background: <?= e((string)$estadoG['color']) ?>;">
                <?= e((string)$estadoG['nombre']) ?></span></td>
              <td class="celda-sub">
                <?= $p['entrega_tipo'] === 'retiro' ? 'Retiro' : e((string)$p['entrega_ciudad']) ?>
                <?= $p['entrega_fecha'] ? '<br>' . e(fecha_corta((string)$p['entrega_fecha'])) : '' ?>
              </td>
              <td class="num"><strong><?= e((string)$p['moneda'] . number_format((float)$p['total'], 2)) ?></strong></td>
              <td class="acciones">
                <a class="boton boton-claro boton-mini" href="<?= e(url('admin/pedido.php?id=' . (int)$p['id'])) ?>">Abrir</a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($paginas > 1): ?>
      <nav class="paginacion" aria-label="Paginación de pedidos">
        <a class="<?= $pagina <= 1 ? 'inactivo' : '' ?>" href="<?= e(urlPedidos(['pagina' => (string)max(1, $pagina - 1)])) ?>">
          <i class="fa-solid fa-chevron-left" aria-hidden="true"></i></a>
        <?php for ($i = max(1, $pagina - 2); $i <= min($paginas, $pagina + 2); $i++): ?>
          <?php if ($i === $pagina): ?>
            <span class="actual"><?= $i ?></span>
          <?php else: ?>
            <a href="<?= e(urlPedidos(['pagina' => (string)$i])) ?>"><?= $i ?></a>
          <?php endif; ?>
        <?php endfor; ?>
        <a class="<?= $pagina >= $paginas ? 'inactivo' : '' ?>" href="<?= e(urlPedidos(['pagina' => (string)min($paginas, $pagina + 1)])) ?>">
          <i class="fa-solid fa-chevron-right" aria-hidden="true"></i></a>
      </nav>
    <?php endif; ?>

    <p style="padding:0 20px 16px; color:var(--p-tenue); font-size:.83rem;">
      <?= $total ?> <?= $total === 1 ? 'pedido' : 'pedidos' ?> con los filtros actuales.
    </p>
  <?php endif; ?>
</section>

<?php require __DIR__ . '/_pie.php'; ?>
