<?php
/**
 * Listado de productos del panel: filtros, orden y acciones rápidas.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

$seccion = 'productos';
Rbac::exigirPanel();
Rbac::exigir('productos.ver');

// --- Acciones rápidas -------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirToken(false, 'admin/productos.php');
    $id = identificador('id');

    $st = $pdo->prepare("SELECT id, nombre, activo, destacado FROM productos WHERE id = ?");
    $st->execute([$id]);
    $producto = $st->fetch();

    if (!$producto) {
        flash('error', 'Ese producto ya no existe.');
        redirigir('admin/productos.php');
    }

    switch (opcion('accion', ['publicar', 'destacar', 'eliminar'], '')) {
        case 'publicar':
            Rbac::exigir('productos.editar');
            $nuevo = (int)$producto['activo'] === 1 ? 0 : 1;
            $pdo->prepare("UPDATE productos SET activo = ? WHERE id = ?")->execute([$nuevo, $id]);
            Auditoria::registrar($pdo, $nuevo ? 'publicar' : 'ocultar', 'productos', [
                'recurso_tipo' => 'producto', 'recurso_id' => (string)$id,
                'descripcion'  => ($nuevo ? 'Publicado' : 'Ocultado') . ': ' . $producto['nombre'],
            ]);
            flash('exito', $nuevo ? 'El producto vuelve a estar visible.' : 'El producto ya no se muestra en la web.');
            break;

        case 'destacar':
            Rbac::exigir('productos.editar');
            $nuevo = (int)$producto['destacado'] === 1 ? 0 : 1;
            $pdo->prepare("UPDATE productos SET destacado = ? WHERE id = ?")->execute([$nuevo, $id]);
            Auditoria::registrar($pdo, 'editar', 'productos', [
                'recurso_tipo' => 'producto', 'recurso_id' => (string)$id,
                'descripcion'  => ($nuevo ? 'Destacado' : 'Sin destacar') . ': ' . $producto['nombre'],
            ]);
            flash('exito', $nuevo ? 'Aparecerá en la portada.' : 'Ya no aparece en la portada.');
            break;

        case 'eliminar':
            Rbac::exigir('productos.eliminar');
            // Si el producto ya está en algún pedido no se borra: se archiva,
            // para no romper el historial de compras del cliente.
            $enPedidos = $pdo->prepare("SELECT COUNT(*) FROM pedido_items WHERE producto_id = ?");
            $enPedidos->execute([$id]);

            if ((int)$enPedidos->fetchColumn() > 0) {
                $pdo->prepare("UPDATE productos SET activo = 0 WHERE id = ?")->execute([$id]);
                Auditoria::registrar($pdo, 'archivar', 'productos', [
                    'recurso_tipo' => 'producto', 'recurso_id' => (string)$id,
                    'descripcion'  => 'Archivado (aparece en pedidos): ' . $producto['nombre'],
                ]);
                flash('info', 'Ese producto ya forma parte de pedidos, así que lo archivamos '
                            . 'en vez de borrarlo. Deja de mostrarse en la web.');
            } else {
                $pdo->prepare("DELETE FROM productos WHERE id = ?")->execute([$id]);
                Auditoria::registrar($pdo, 'eliminar', 'productos', [
                    'recurso_tipo' => 'producto', 'recurso_id' => (string)$id,
                    'descripcion'  => 'Producto eliminado: ' . $producto['nombre'],
                ]);
                flash('exito', 'Producto eliminado.');
            }
            break;
    }
    redirigir('admin/productos.php');
}

// --- Listado -----------------------------------------------------------
$q          = texto('q', 80, $_GET);
$categoria  = identificador('categoria', $_GET);
$visibilidad = opcion('visibilidad', ['todos', 'activos', 'ocultos', 'agotados'], 'todos', $_GET);
$pagina     = entero('pagina', 1, 9999, 1, $_GET);
$porPagina  = 20;

$where = ['1 = 1'];
$params = [];
if ($q !== '') {
    $t = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
    $where[] = '(p.nombre LIKE ? OR p.descripcion LIKE ? OR p.flores LIKE ?)';
    array_push($params, $t, $t, $t);
}
if ($categoria > 0) {
    $where[]  = 'p.categoria_id = ?';
    $params[] = $categoria;
}
$where[] = match ($visibilidad) {
    'activos'  => 'p.activo = 1',
    'ocultos'  => 'p.activo = 0',
    'agotados' => 'p.activo = 1 AND (p.disponible = 0 OR (p.controla_stock = 1 AND p.stock <= 0))',
    default    => '1 = 1',
};

$sqlWhere = implode(' AND ', $where);
$stTotal  = $pdo->prepare("SELECT COUNT(*) FROM productos p WHERE $sqlWhere");
$stTotal->execute($params);
$total    = (int)$stTotal->fetchColumn();
$paginas  = max(1, (int)ceil($total / $porPagina));
$pagina   = min($pagina, $paginas);
$salto    = ($pagina - 1) * $porPagina;

$st = $pdo->prepare(
    "SELECT p.*, c.nombre AS categoria_nombre
       FROM productos p JOIN categorias c ON c.id = p.categoria_id
      WHERE $sqlWhere ORDER BY p.activo DESC, p.orden, p.id DESC
      LIMIT $porPagina OFFSET $salto"
);
$st->execute($params);
$productos = $st->fetchAll();

$categorias = $pdo->query("SELECT id, nombre FROM categorias ORDER BY orden, nombre")->fetchAll();

$tituloPanel    = 'Productos';
$subtituloPanel = $total . ' ' . ($total === 1 ? 'producto' : 'productos') . ' en el catálogo';
$accionesCabecera = Rbac::puede('productos.crear')
    ? '<a class="boton boton-principal" href="' . e(url('admin/producto.php')) . '">'
      . '<i class="fa-solid fa-plus" aria-hidden="true"></i> Nuevo producto</a>'
    : '';

require __DIR__ . '/_cabecera.php';
?>

<section class="panel">
  <form class="barra-herramientas" method="get" action="<?= e(url('admin/productos.php')) ?>" data-autofiltro>
    <div class="campo">
      <label for="q">Buscar</label>
      <input type="search" id="q" name="q" value="<?= e($q) ?>" placeholder="Nombre, descripción o tipo de flor">
    </div>
    <div class="campo estrecho">
      <label for="categoria">Categoría</label>
      <select id="categoria" name="categoria">
        <option value="">Todas</option>
        <?php foreach ($categorias as $c): ?>
          <option value="<?= (int)$c['id'] ?>"<?= $categoria === (int)$c['id'] ? ' selected' : '' ?>>
            <?= e((string)$c['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="campo estrecho">
      <label for="visibilidad">Mostrar</label>
      <select id="visibilidad" name="visibilidad">
        <option value="todos"<?=    $visibilidad === 'todos'    ? ' selected' : '' ?>>Todos</option>
        <option value="activos"<?=  $visibilidad === 'activos'  ? ' selected' : '' ?>>Publicados</option>
        <option value="ocultos"<?=  $visibilidad === 'ocultos'  ? ' selected' : '' ?>>Ocultos</option>
        <option value="agotados"<?= $visibilidad === 'agotados' ? ' selected' : '' ?>>Sin disponibilidad</option>
      </select>
    </div>
    <button type="submit" class="boton boton-principal"><i class="fa-solid fa-filter" aria-hidden="true"></i> Filtrar</button>
  </form>

  <?php if (!$productos): ?>
    <div class="vacio">
      <i class="fa-solid fa-seedling" aria-hidden="true"></i>
      <h3>No hay productos con estos filtros</h3>
      <p>Crea el primero o cambia los filtros de búsqueda.</p>
      <?php if (Rbac::puede('productos.crear')): ?>
        <a class="boton boton-principal" href="<?= e(url('admin/producto.php')) ?>">Crear producto</a>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="tabla-envoltura">
      <table class="tabla">
        <thead>
          <tr><th></th><th>Producto</th><th>Categoría</th><th class="num">Precio</th>
              <th>Disponibilidad</th><th>Estado</th><th></th></tr>
        </thead>
        <tbody>
          <?php foreach ($productos as $p): ?>
            <tr>
              <td><img class="miniatura" src="<?= e(url_imagen((string)$p['imagen'])) ?>" alt="" loading="lazy"></td>
              <td>
                <span class="celda-principal"><?= e((string)$p['nombre']) ?></span>
                <?php if ((int)$p['destacado'] === 1): ?>
                  <i class="fa-solid fa-star" style="color:#C9A96E; font-size:.75rem;" title="Destacado" aria-label="Destacado"></i>
                <?php endif; ?>
                <br><span class="celda-sub"><?= e((string)$p['slug']) ?></span>
              </td>
              <td><?= e((string)$p['categoria_nombre']) ?></td>
              <td class="num"><?= e(dinero($p['precio'])) ?></td>
              <td>
                <?php if ((int)$p['disponible'] === 0): ?>
                  <span class="estado-suave aviso">Sobre pedido</span>
                <?php elseif ((int)$p['controla_stock'] === 1): ?>
                  <span class="estado-suave <?= (int)$p['stock'] > 0 ? 'si' : 'mal' ?>">
                    <?= (int)$p['stock'] ?> en stock</span>
                <?php else: ?>
                  <span class="estado-suave si">Disponible</span>
                <?php endif; ?>
              </td>
              <td>
                <span class="estado-suave <?= (int)$p['activo'] === 1 ? 'si' : 'no' ?>">
                  <?= (int)$p['activo'] === 1 ? 'Publicado' : 'Oculto' ?></span>
              </td>
              <td class="acciones">
                <div style="display:inline-flex; gap:5px;">
                  <?php if (Rbac::puede('productos.editar')): ?>
                    <a class="boton-icono" href="<?= e(url('admin/producto.php?id=' . (int)$p['id'])) ?>"
                       aria-label="Editar <?= e((string)$p['nombre']) ?>"><i class="fa-solid fa-pen" aria-hidden="true"></i></a>

                    <form method="post" action="<?= e(url('admin/productos.php')) ?>">
                      <?= campoToken() ?>
                      <input type="hidden" name="accion" value="destacar">
                      <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                      <button type="submit" class="boton-icono"
                              aria-label="<?= (int)$p['destacado'] === 1 ? 'Quitar de destacados' : 'Destacar' ?>">
                        <i class="fa-<?= (int)$p['destacado'] === 1 ? 'solid' : 'regular' ?> fa-star" aria-hidden="true"></i>
                      </button>
                    </form>

                    <form method="post" action="<?= e(url('admin/productos.php')) ?>">
                      <?= campoToken() ?>
                      <input type="hidden" name="accion" value="publicar">
                      <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                      <button type="submit" class="boton-icono"
                              aria-label="<?= (int)$p['activo'] === 1 ? 'Ocultar' : 'Publicar' ?>">
                        <i class="fa-solid fa-<?= (int)$p['activo'] === 1 ? 'eye-slash' : 'eye' ?>" aria-hidden="true"></i>
                      </button>
                    </form>
                  <?php endif; ?>

                  <?php if (Rbac::puede('productos.eliminar')): ?>
                    <form method="post" action="<?= e(url('admin/productos.php')) ?>"
                          data-confirmar="¿Eliminar «<?= e((string)$p['nombre']) ?>»? Si ya está en algún pedido se archivará en lugar de borrarse.">
                      <?= campoToken() ?>
                      <input type="hidden" name="accion" value="eliminar">
                      <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                      <button type="submit" class="boton-icono peligro" aria-label="Eliminar <?= e((string)$p['nombre']) ?>">
                        <i class="fa-solid fa-trash-can" aria-hidden="true"></i></button>
                    </form>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($paginas > 1): ?>
      <nav class="paginacion">
        <?php for ($i = 1; $i <= $paginas; $i++):
            $params = array_filter(['q' => $q, 'categoria' => $categoria ?: '',
                                    'visibilidad' => $visibilidad, 'pagina' => $i]);
        ?>
          <?php if ($i === $pagina): ?><span class="actual"><?= $i ?></span>
          <?php else: ?>
            <a href="<?= e(url('admin/productos.php?' . http_build_query($params))) ?>"><?= $i ?></a>
          <?php endif; ?>
        <?php endfor; ?>
      </nav>
    <?php endif; ?>
  <?php endif; ?>
</section>

<?php require __DIR__ . '/_pie.php'; ?>
