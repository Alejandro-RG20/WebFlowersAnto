<?php
/**
 * Clientes: búsqueda, historial de pedidos y activación de cuentas.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

$seccion = 'clientes';
Rbac::exigirPanel();
Rbac::exigir('clientes.ver');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirToken(false, 'admin/clientes.php');
    Rbac::exigir('clientes.editar');

    $id = identificador('id');
    $st = $pdo->prepare(
        "SELECT u.*, r.codigo AS rol_codigo FROM usuarios u
      LEFT JOIN roles r ON r.id = u.rol_id WHERE u.id = ?"
    );
    $st->execute([$id]);
    $cliente = $st->fetch();

    if (!$cliente || $cliente['rol_codigo'] !== 'cliente') {
        flash('error', 'Esa cuenta no es un cliente. Los empleados se editan en su propia sección.');
        redirigir('admin/clientes.php');
    }

    switch (opcion('accion', ['activar', 'notas'], '')) {
        case 'activar':
            $nuevo = (int)$cliente['activo'] === 1 ? 0 : 1;
            $pdo->prepare("UPDATE usuarios SET activo = ? WHERE id = ?")->execute([$nuevo, $id]);
            Auditoria::registrar($pdo, $nuevo ? 'activar' : 'desactivar', 'usuarios', [
                'recurso_tipo' => 'usuario', 'recurso_id' => (string)$id,
                'descripcion'  => ($nuevo ? 'Cuenta reactivada: ' : 'Cuenta desactivada: ') . $cliente['email'],
            ]);
            flash('exito', $nuevo ? 'Cuenta reactivada.' : 'Cuenta desactivada: ya no podrá iniciar sesión.');
            break;

        case 'notas':
            $notas = texto('notas', 500);
            $pdo->prepare("UPDATE usuarios SET notas = ? WHERE id = ?")->execute([$notas, $id]);
            Auditoria::registrar($pdo, 'editar', 'usuarios', [
                'recurso_tipo' => 'usuario', 'recurso_id' => (string)$id,
                'descripcion'  => 'Nota interna sobre el cliente ' . $cliente['email'],
            ]);
            flash('exito', 'Nota guardada.');
            break;
    }
    redirigir('admin/clientes.php?ver=' . $id);
}

$q      = texto('q', 80, $_GET);
$verId  = identificador('ver', $_GET);
$pagina = entero('pagina', 1, 9999, 1, $_GET);
$porPagina = 25;

$where  = ["r.codigo = 'cliente'"];
$params = [];
if ($q !== '') {
    $t = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
    $where[] = '(u.nombre LIKE ? OR u.apellido LIKE ? OR u.email LIKE ? OR u.telefono LIKE ?)';
    array_push($params, $t, $t, $t, $t);
}
$sqlWhere = implode(' AND ', $where);

$stTotal = $pdo->prepare("SELECT COUNT(*) FROM usuarios u JOIN roles r ON r.id = u.rol_id WHERE $sqlWhere");
$stTotal->execute($params);
$total   = (int)$stTotal->fetchColumn();
$paginas = max(1, (int)ceil($total / $porPagina));
$pagina  = min($pagina, $paginas);
$salto   = ($pagina - 1) * $porPagina;

$st = $pdo->prepare(
    "SELECT u.*, 
            (SELECT COUNT(*) FROM pedidos p WHERE p.usuario_id = u.id) AS pedidos,
            (SELECT COALESCE(SUM(p.total), 0) FROM pedidos p
              WHERE p.usuario_id = u.id AND p.estado_pago = 'aprobado') AS gastado
       FROM usuarios u JOIN roles r ON r.id = u.rol_id
      WHERE $sqlWhere ORDER BY u.created_at DESC LIMIT $porPagina OFFSET $salto"
);
$st->execute($params);
$clientes = $st->fetchAll();

// Ficha ampliada
$detalle = null;
if ($verId > 0) {
    $st = $pdo->prepare(
        "SELECT u.* FROM usuarios u JOIN roles r ON r.id = u.rol_id
          WHERE u.id = ? AND r.codigo = 'cliente'"
    );
    $st->execute([$verId]);
    $detalle = $st->fetch() ?: null;
    if ($detalle) {
        $detalle['pedidos'] = Pedidos::deUsuario($pdo, $verId, 20);
    }
}

$tituloPanel    = 'Clientes';
$subtituloPanel = $total . ' ' . ($total === 1 ? 'cuenta de cliente' : 'cuentas de cliente');

require __DIR__ . '/_cabecera.php';
?>

<?php if ($detalle): ?>
  <a class="boton boton-claro boton-mini" href="<?= e(url('admin/clientes.php')) ?>" style="margin-bottom:16px;">
    <i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Volver a la lista</a>

  <div class="rejilla-detalle">
    <section class="panel">
      <div class="panel-cabecera">
        <div>
          <h2><?= e(trim((string)$detalle['nombre'] . ' ' . (string)$detalle['apellido'])) ?></h2>
          <p>Cliente desde <?= e(fecha_corta((string)$detalle['created_at'])) ?></p>
        </div>
        <span class="estado-suave <?= (int)$detalle['activo'] ? 'si' : 'mal' ?>">
          <?= (int)$detalle['activo'] ? 'Activa' : 'Desactivada' ?></span>
      </div>
      <div class="panel-cuerpo">
        <dl class="lista-datos">
          <div><dt>Correo</dt><dd><?= e((string)$detalle['email']) ?></dd></div>
          <div><dt>Teléfono</dt><dd><?= e((string)$detalle['telefono']) ?></dd></div>
          <div><dt>Acceso con Google</dt><dd><?= $detalle['google_id'] ? 'Sí' : 'No' ?></dd></div>
          <div><dt>Último acceso</dt><dd><?= $detalle['ultimo_acceso']
              ? e(fecha_larga((string)$detalle['ultimo_acceso'])) : 'Nunca' ?></dd></div>
        </dl>

        <?php if (Rbac::puede('clientes.editar')): ?>
          <form method="post" action="<?= e(url('admin/clientes.php')) ?>" style="margin-top:18px;">
            <?= campoToken() ?>
            <input type="hidden" name="accion" value="notas">
            <input type="hidden" name="id" value="<?= (int)$detalle['id'] ?>">
            <div class="campo">
              <label for="notas">Nota interna</label>
              <textarea id="notas" name="notas" maxlength="500"
                        placeholder="Prefiere entregas por la tarde…"><?= e((string)$detalle['notas']) ?></textarea>
            </div>
            <button type="submit" class="boton boton-claro">Guardar nota</button>
          </form>

          <form method="post" action="<?= e(url('admin/clientes.php')) ?>" style="margin-top:14px;"
                data-confirmar="<?= (int)$detalle['activo']
                    ? '¿Desactivar la cuenta? No podrá iniciar sesión.'
                    : '¿Reactivar la cuenta?' ?>">
            <?= campoToken() ?>
            <input type="hidden" name="accion" value="activar">
            <input type="hidden" name="id" value="<?= (int)$detalle['id'] ?>">
            <button type="submit" class="boton <?= (int)$detalle['activo'] ? 'boton-peligro' : 'boton-exito' ?>">
              <?= (int)$detalle['activo'] ? 'Desactivar cuenta' : 'Reactivar cuenta' ?></button>
          </form>
        <?php endif; ?>
      </div>
    </section>

    <section class="panel">
      <div class="panel-cabecera"><div><h2>Pedidos</h2></div></div>
      <?php if (!$detalle['pedidos']): ?>
        <div class="vacio" style="padding:30px 12px;">
          <i class="fa-solid fa-receipt" aria-hidden="true"></i>
          <h3>Sin pedidos todavía</h3>
        </div>
      <?php else: ?>
        <div class="tabla-envoltura">
          <table class="tabla" style="min-width:auto;">
            <thead><tr><th>Pedido</th><th>Estado</th><th class="num">Total</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($detalle['pedidos'] as $p):
                  $est = Pedidos::estado($pdo, 'pedido', (string)$p['estado']); ?>
                <tr>
                  <td><span class="celda-principal"><?= e((string)$p['codigo']) ?></span><br>
                    <span class="celda-sub"><?= e(fecha_corta((string)$p['created_at'])) ?></span></td>
                  <td><span class="estado" style="background: <?= e((string)$est['color']) ?>;">
                    <?= e((string)$est['nombre']) ?></span></td>
                  <td class="num"><?= e((string)$p['moneda'] . number_format((float)$p['total'], 2)) ?></td>
                  <td class="acciones">
                    <a class="boton-icono" href="<?= e(url('admin/pedido.php?id=' . (int)$p['id'])) ?>"
                       aria-label="Abrir pedido"><i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>
  </div>

<?php else: ?>
  <section class="panel">
    <form class="barra-herramientas" method="get" action="<?= e(url('admin/clientes.php')) ?>">
      <div class="campo">
        <label for="q">Buscar</label>
        <input type="search" id="q" name="q" value="<?= e($q) ?>" placeholder="Nombre, correo o teléfono">
      </div>
      <button type="submit" class="boton boton-principal"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i> Buscar</button>
      <?php if ($q !== ''): ?>
        <a class="boton boton-claro" href="<?= e(url('admin/clientes.php')) ?>">Limpiar</a>
      <?php endif; ?>
    </form>

    <?php if (!$clientes): ?>
      <div class="vacio">
        <i class="fa-solid fa-users" aria-hidden="true"></i>
        <h3>No hay clientes con esos datos</h3>
        <p>Ten en cuenta que los pedidos de invitados no crean cuenta.</p>
      </div>
    <?php else: ?>
      <div class="tabla-envoltura">
        <table class="tabla">
          <thead><tr><th>Cliente</th><th>Contacto</th><th class="num">Pedidos</th>
                     <th class="num">Comprado</th><th>Estado</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($clientes as $c): ?>
              <tr>
                <td>
                  <span class="celda-principal"><?= e(trim((string)$c['nombre'] . ' ' . (string)$c['apellido'])) ?></span><br>
                  <span class="celda-sub">Desde <?= e(fecha_corta((string)$c['created_at'])) ?>
                    <?= $c['google_id'] ? ' · Google' : '' ?></span>
                </td>
                <td>
                  <?= e((string)$c['email']) ?><br>
                  <span class="celda-sub"><?= e((string)$c['telefono']) ?></span>
                </td>
                <td class="num"><?= (int)$c['pedidos'] ?></td>
                <td class="num"><?= e(dinero($c['gastado'])) ?></td>
                <td><span class="estado-suave <?= (int)$c['activo'] ? 'si' : 'mal' ?>">
                  <?= (int)$c['activo'] ? 'Activa' : 'Inactiva' ?></span></td>
                <td class="acciones">
                  <a class="boton boton-claro boton-mini" href="<?= e(url('admin/clientes.php?ver=' . (int)$c['id'])) ?>">Ver ficha</a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?php if ($paginas > 1): ?>
        <nav class="paginacion">
          <?php for ($i = 1; $i <= $paginas; $i++): ?>
            <?php if ($i === $pagina): ?><span class="actual"><?= $i ?></span>
            <?php else: ?>
              <a href="<?= e(url('admin/clientes.php?' . http_build_query(array_filter(['q' => $q, 'pagina' => $i])))) ?>"><?= $i ?></a>
            <?php endif; ?>
          <?php endfor; ?>
        </nav>
      <?php endif; ?>
    <?php endif; ?>
  </section>
<?php endif; ?>

<?php require __DIR__ . '/_pie.php'; ?>
