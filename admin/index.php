<?php
/**
 * Resumen del panel.
 *
 * Solo se consulta lo que el usuario tiene permiso de ver: un empleado de
 * productos no dispara las consultas de ventas ni las ve en pantalla.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/lib/estadisticas.php';

$tituloPanel    = 'Resumen';
$subtituloPanel = 'Cómo va el negocio, ' . date('j/n/Y');
$seccion        = 'resumen';
$jsPanel        = ['assets/js/admin-graficos.js'];

// La comprobación de permisos va antes de imprimir nada: si falta el permiso
// se muestra la página de acceso denegado, no media pantalla del panel.
Rbac::exigirPanel();
Rbac::exigir('dashboard.ver');

$verPedidos   = Rbac::puede('pedidos.ver');
$verProductos = Rbac::puede('productos.ver');
$verClientes  = Rbac::puede('clientes.ver');

// --- Período del análisis --------------------------------------------
// Un solo selector arriba decide el rango de todas las cifras y gráficos de
// la página, para que nunca se contradigan entre sí.
$periodo = (int)opcion('periodo', array_map('strval', Estadisticas::PERIODOS), '30', $_GET);

// --- Lo de hoy: lo que pide atención ya --------------------------------
$m = ['pendientes' => 0, 'revision' => 0, 'preparacion' => 0, 'hoy' => 0];
if ($verPedidos) {
    $inicioHoy = (new DateTimeImmutable('today'))->getTimestamp();
    $st = $pdo->prepare(
        "SELECT
            COALESCE(SUM(estado = 'pendiente'), 0)                                  AS pendientes,
            COALESCE(SUM(estado_pago IN ('comprobante_recibido','en_revision')), 0) AS revision,
            COALESCE(SUM(estado IN ('confirmado','preparacion','listo')), 0)        AS preparacion,
            COALESCE(SUM(created_at >= FROM_UNIXTIME(?)), 0)                        AS hoy
           FROM pedidos"
    );
    $st->execute([$inicioHoy]);
    $m = array_map('intval', $st->fetch() ?: $m);
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

// --- Cómo va el negocio en el período ----------------------------------
$ventas      = $verPedidos ? Estadisticas::ventas($pdo, $periodo) : null;
$nuevos      = $verClientes ? Estadisticas::clientesNuevos($pdo, $periodo) : null;
$masVendidos = $verPedidos ? Estadisticas::masVendidos($pdo, $periodo) : [];
$porEstado   = $verPedidos ? Estadisticas::porEstado($pdo, $periodo) : [];

$ticket = static fn(array $p): float => $p['aprobados'] > 0 ? $p['cobrado'] / $p['aprobados'] : 0.0;

/** «1 oct»: la fecha corta de los ejes y las tablas. */
$diaCorto = static function (string $iso): string {
    static $meses = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
    $t = strtotime($iso);
    return (int)date('j', $t) . ' ' . $meses[(int)date('n', $t) - 1];
};

/**
 * Variación frente al período anterior. La flecha y la palabra dicen la
 * dirección; el color solo lo refuerza.
 */
$delta = static function (?float $variacion) use ($periodo): string {
    if ($variacion === null) {
        return '<span class="delta igual">Sin datos del período anterior</span>';
    }
    $redondeo = (int)round($variacion);
    $clase    = $redondeo > 0 ? 'sube' : ($redondeo < 0 ? 'baja' : 'igual');
    $flecha   = $redondeo > 0 ? '▲' : ($redondeo < 0 ? '▼' : '＝');
    $texto    = $redondeo === 0 ? 'Igual' : ($redondeo > 0 ? '+' : '−') . abs($redondeo) . ' %';
    return '<span class="delta ' . $clase . '"><span aria-hidden="true">' . $flecha . '</span> ' . e($texto)
         . '</span> <span class="delta-base">vs ' . $periodo . ' días anteriores</span>';
};

/**
 * Minigráfico de tendencia para una cifra. Gris de fondo y el último día en
 * el color de acento: dice «hacia dónde va» sin competir con el número.
 */
$tendencia = static function (array $valores): string {
    $n = count($valores);
    if ($n < 2 || max($valores) <= 0) {
        return '';
    }
    [$ancho, $alto, $margen] = [120, 32, 4];
    $max = max($valores);
    $puntos = [];
    foreach (array_values($valores) as $i => $v) {
        $x = round($margen + $i * ($ancho - 2 * $margen) / ($n - 1), 1);
        $y = round($alto - $margen - ($v / $max) * ($alto - 2 * $margen), 1);
        $puntos[] = $x . ',' . $y;
    }
    [$ux, $uy] = explode(',', end($puntos));
    return '<svg class="tendencia" viewBox="0 0 ' . $ancho . ' ' . $alto . '" width="' . $ancho . '" height="' . $alto
         . '" aria-hidden="true" focusable="false"><polyline points="' . implode(' ', $puntos) . '"/>'
         . '<circle cx="' . $ux . '" cy="' . $uy . '" r="3.5"/></svg>';
};

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

<?php if ($verPedidos || $verClientes): ?>
  <nav class="filtro-periodo" aria-label="Período de las cifras">
    <span class="filtro-periodo-titulo">Período</span>
    <?php foreach (Estadisticas::PERIODOS as $p): ?>
      <a href="<?= e(url('admin/?periodo=' . $p)) ?>" class="<?= $p === $periodo ? 'actual' : '' ?>"
         <?= $p === $periodo ? 'aria-current="true"' : '' ?>>Últimos <?= $p ?> días</a>
    <?php endforeach; ?>
  </nav>
<?php endif; ?>

<?php if ($verPedidos || $verProductos || $verClientes): ?>
<h2 class="titulo-bloque">Para hoy</h2>
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
    <a class="metrica" href="<?= e(url('admin/pedidos.php')) ?>">
      <span class="metrica-etiqueta"><i class="fa-solid fa-bag-shopping" aria-hidden="true"></i> Pedidos de hoy</span>
      <span class="metrica-valor"><?= (int)$m['hoy'] ?></span>
      <span class="metrica-nota">Desde las 00:00</span>
    </a>
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
      <span class="metrica-nota">Cuentas activas</span>
    </a>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($ventas || $nuevos): ?>
<h2 class="titulo-bloque">Últimos <?= (int)$periodo ?> días</h2>
<div class="rejilla-kpi">
  <?php if ($ventas): ?>
    <div class="kpi">
      <p class="kpi-etiqueta">Ventas cobradas</p>
      <p class="kpi-valor"><?= e(dinero($ventas['actual']['cobrado'])) ?></p>
      <p class="kpi-delta"><?= $delta(Estadisticas::variacion($ventas['actual']['cobrado'], $ventas['anterior']['cobrado'])) ?></p>
      <?= $tendencia(array_column($ventas['serie'], 'cobrado')) ?>
    </div>
    <div class="kpi">
      <p class="kpi-etiqueta">Pedidos</p>
      <p class="kpi-valor"><?= number_format($ventas['actual']['pedidos']) ?></p>
      <p class="kpi-delta"><?= $delta(Estadisticas::variacion($ventas['actual']['pedidos'], $ventas['anterior']['pedidos'])) ?></p>
      <?= $tendencia(array_column($ventas['serie'], 'pedidos')) ?>
    </div>
    <div class="kpi">
      <p class="kpi-etiqueta">Ticket promedio</p>
      <p class="kpi-valor"><?= e(dinero($ticket($ventas['actual']))) ?></p>
      <p class="kpi-delta"><?= $delta(Estadisticas::variacion($ticket($ventas['actual']), $ticket($ventas['anterior']))) ?></p>
      <p class="kpi-nota">Por pedido con pago aprobado</p>
    </div>
  <?php endif; ?>
  <?php if ($nuevos): ?>
    <div class="kpi">
      <p class="kpi-etiqueta">Clientes nuevos</p>
      <p class="kpi-valor"><?= number_format($nuevos['actual']) ?></p>
      <p class="kpi-delta"><?= $delta(Estadisticas::variacion($nuevos['actual'], $nuevos['anterior'])) ?></p>
      <p class="kpi-nota">Cuentas creadas en el período</p>
    </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($ventas): ?>
<div class="rejilla-graficos">
  <section class="panel">
    <div class="panel-cabecera"><div>
      <h2>Ventas cobradas por día</h2>
      <p>Pedidos con pago aprobado, sin cancelados. Pasa el cursor para ver cada día.</p>
    </div>
    <?php
      $hoyVentas = end($ventas['serie']);
      $mejorDia  = array_reduce($ventas['serie'], fn($a, $d) => $a === null || $d['cobrado'] > $a['cobrado'] ? $d : $a);
    ?>
    <dl class="resumen-grafico">
      <div><dt>Hoy</dt><dd><?= e(dinero($hoyVentas['cobrado'])) ?></dd></div>
      <?php if ($mejorDia && $mejorDia['cobrado'] > 0): ?>
        <div><dt>Mejor día · <?= e($diaCorto($mejorDia['dia'])) ?></dt><dd><?= e(dinero($mejorDia['cobrado'])) ?></dd></div>
      <?php endif; ?>
    </dl>
    </div>
    <div class="panel-cuerpo">
      <?php
        $datosLinea = array_map(fn($d) => [
            'e' => $diaCorto($d['dia']), 'v' => round($d['cobrado'], 2), 'p' => $d['pedidos'],
        ], $ventas['serie']);
      ?>
      <figure class="grafico-linea" data-grafico-linea data-moneda="<?= e(Ajustes::texto('moneda_local', 'C$')) ?>"
              aria-label="Ventas cobradas por día en los últimos <?= (int)$periodo ?> días">
        <script type="application/json"><?= json_para_html($datosLinea) ?></script>
        <noscript><p class="celda-sub">El gráfico necesita JavaScript. Abajo tienes los mismos datos en una tabla.</p></noscript>
      </figure>
      <details class="vista-tabla">
        <summary>Ver como tabla</summary>
        <div class="tabla-envoltura">
          <table class="tabla tabla-compacta">
            <thead><tr><th>Día</th><th class="num">Pedidos</th><th class="num">Cobrado</th></tr></thead>
            <tbody>
              <?php foreach (array_reverse($ventas['serie']) as $d): ?>
                <tr><td><?= e($diaCorto($d['dia'])) ?></td><td class="num"><?= (int)$d['pedidos'] ?></td>
                    <td class="num"><?= e(dinero($d['cobrado'])) ?></td></tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </details>
    </div>
  </section>

  <section class="panel">
    <div class="panel-cabecera"><div>
      <h2>Más vendidos</h2>
      <p>Unidades pedidas en el período.</p>
    </div></div>
    <div class="panel-cuerpo">
      <?php if (!$masVendidos): ?>
        <div class="vacio vacio-compacto">
          <i class="fa-solid fa-seedling" aria-hidden="true"></i>
          <h3>Sin ventas en el período</h3>
        </div>
      <?php else:
          $maxUnidades = max(array_column($masVendidos, 'unidades')); ?>
        <ol class="barras-h">
          <?php foreach ($masVendidos as $item): ?>
            <li>
              <span class="barras-h-etiqueta"><?= e($item['nombre']) ?></span>
              <span class="barras-h-fila">
                <span class="barras-h-barra" style="--v: <?= round($item['unidades'] / $maxUnidades, 4) ?>"></span>
                <span class="barras-h-valor"><?= (int)$item['unidades'] ?>
                  <small><?= e(dinero($item['importe'])) ?></small></span>
              </span>
            </li>
          <?php endforeach; ?>
        </ol>
      <?php endif; ?>
    </div>
  </section>
</div>
<?php endif; ?>

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
    <?php if ($porEstado): ?>
      <section class="panel">
        <div class="panel-cabecera"><div>
          <h2>Pedidos por estado</h2>
          <p>Los creados en los últimos <?= (int)$periodo ?> días, según cómo están ahora.</p>
        </div></div>
        <div class="panel-cuerpo">
          <?php $maxEstado = max(array_column($porEstado, 'cantidad')); ?>
          <ol class="barras-h">
            <?php foreach ($porEstado as $fila):
                $est = Pedidos::estado($pdo, 'pedido', $fila['codigo']); ?>
              <li>
                <span class="barras-h-etiqueta"><?= e((string)$est['nombre']) ?></span>
                <span class="barras-h-fila">
                  <span class="barras-h-barra" style="--v: <?= round($fila['cantidad'] / $maxEstado, 4) ?>"></span>
                  <span class="barras-h-valor"><?= (int)$fila['cantidad'] ?></span>
                </span>
              </li>
            <?php endforeach; ?>
          </ol>
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
