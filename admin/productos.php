<?php
/**
 * Listado de productos del panel: filtros, orden y acciones rápidas.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

$seccion = 'productos';
Rbac::exigirPanel();
Rbac::exigir('productos.ver');

// --- Acciones sobre varios productos ----------------------------------
//
// Van antes de las acciones de una sola fila porque no llevan `id`: reciben
// una lista de casillas marcadas. Cada una es una operación puntual que se
// ejecuta al pulsar, no un estado que se vuelva a aplicar al dibujar la
// página; por eso un aumento de precio no puede sumarse solo dos veces.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && crudo('accion_masiva') !== '') {
    exigirToken(false, 'admin/productos.php');
    Rbac::exigir('productos.editar');

    $ids = array_values(array_unique(array_filter(
        array_map('intval', (array)($_POST['ids'] ?? [])),
        static fn(int $v): bool => $v > 0
    )));
    if (!$ids) {
        flash('error', 'Marca al menos un arreglo antes de aplicar la acción.');
        redirigir('admin/productos.php');
    }
    // Se acota lo que puede llegar de golpe: una lista enorme dejaría la
    // consulta y la auditoría fuera de control.
    $ids = array_slice($ids, 0, 200);
    $huecos = implode(',', array_fill(0, count($ids), '?'));

    // Se quedan solo los identificadores que existen de verdad.
    //
    // Antes el mensaje contaba lo que venía en la petición, así que una lista
    // con arreglos ya borrados —o retocada a mano— respondía «listo, 3
    // arreglos» sin haber tocado ninguno. Contar filas reales hace que el
    // mensaje diga lo que pasó. No se usa `rowCount()` para esto porque MySQL
    // devuelve ahí las filas CAMBIADAS: poner el mismo 10% que ya tenían daría
    // cero y parecería un fallo.
    $existe = $pdo->prepare("SELECT id FROM productos WHERE id IN ($huecos)");
    $existe->execute($ids);
    $ids = array_map('intval', $existe->fetchAll(PDO::FETCH_COLUMN));
    if (!$ids) {
        flash('error', 'Esos arreglos ya no están en el catálogo. Actualiza la página y vuelve a marcarlos.');
        redirigir('admin/productos.php');
    }
    $huecos = implode(',', array_fill(0, count($ids), '?'));

    switch (opcion('accion_masiva', ['oferta', 'quitar_oferta', 'aumentar'], '')) {
        case 'oferta':
            $pct = Precios::normalizarPct(crudo('descuento_pct'));
            if ($pct === null) {
                flash('error', 'El descuento tiene que ser un número entero entre 0 y ' .
                      Precios::TOPE_PCT . '. Con 0 el arreglo vuelve a su precio de siempre.');
                redirigir('admin/productos.php');
            }
            $pdo->prepare("UPDATE productos SET descuento_pct = ? WHERE id IN ($huecos)")
                ->execute(array_merge([$pct], $ids));
            $cuantos = count($ids) . ' ' . unidad_plural(count($ids), 'arreglos');
            Auditoria::registrar($pdo, 'editar', 'productos', [
                'recurso_tipo' => 'producto', 'recurso_id' => implode(',', $ids),
                'descripcion'  => $pct > 0
                    ? "Oferta del {$pct}% aplicada a {$cuantos}."
                    : "Oferta retirada de {$cuantos}.",
            ]);
            flash('exito', $pct > 0
                ? "Listo: {$cuantos} con el {$pct}% de descuento. El precio de siempre sigue guardado y vuelve al quitar la oferta."
                : "Listo: {$cuantos} vuelven a su precio de siempre.");
            break;

        case 'quitar_oferta':
            $pdo->prepare("UPDATE productos SET descuento_pct = 0 WHERE id IN ($huecos)")
                ->execute($ids);
            $cuantos = count($ids) . ' ' . unidad_plural(count($ids), 'arreglos');
            Auditoria::registrar($pdo, 'editar', 'productos', [
                'recurso_tipo' => 'producto', 'recurso_id' => implode(',', $ids),
                'descripcion'  => "Oferta retirada de {$cuantos}.",
            ]);
            flash('exito', "Listo: {$cuantos} vuelven a su precio de siempre.");
            break;

        case 'aumentar':
            $monto = (float)str_replace(',', '', crudo('monto'));
            if ($monto <= 0 || $monto > Precios::TOPE_AUMENTO) {
                flash('error', 'Escribe cuánto quieres subir el precio: una cantidad entre ' .
                      dinero(1) . ' y ' . dinero(Precios::TOPE_AUMENTO) . '.');
                redirigir('admin/productos.php');
            }
            // Sube el precio de siempre, no el rebajado: si el arreglo está en
            // oferta, el porcentaje se sigue aplicando sobre el precio nuevo.
            //
            // La cuenta se hace aquí y no dentro del UPDATE porque el dólar
            // depende del precio, y en una sola sentencia MySQL ya habría
            // cambiado la columna antes de leerla para la segunda asignación:
            // la tasa saldría del precio nuevo y el resultado sería otro. Cada
            // arreglo conserva su propia tasa en lugar de imponerles una común,
            // que es lo que ya hacía el catálogo.
            $lee = $pdo->prepare("SELECT id, precio, precio_usd FROM productos WHERE id IN ($huecos)");
            $lee->execute($ids);
            $sube = $pdo->prepare("UPDATE productos SET precio = ?, precio_usd = ? WHERE id = ?");

            $pdo->beginTransaction();
            try {
                foreach ($lee->fetchAll() as $fila) {
                    $viejo = (float)$fila['precio'];
                    $usd   = (float)$fila['precio_usd'];
                    $nuevo = round($viejo + $monto, 2);
                    $tasa  = ($viejo > 0 && $usd > 0) ? $viejo / $usd : 0.0;
                    $sube->execute([$nuevo, $tasa > 0 ? round($nuevo / $tasa, 2) : 0.0, $fila['id']]);
                }
                $pdo->commit();
            } catch (PDOException $ex) {
                $pdo->rollBack();
                error_log('Flowers Anto — no se pudo subir el precio: ' . $ex->getMessage());
                flash('error', 'No se pudo aplicar el aumento. Vuelve a intentarlo.');
                redirigir('admin/productos.php');
            }
            $cuantos = count($ids) . ' ' . unidad_plural(count($ids), 'arreglos');
            Auditoria::registrar($pdo, 'editar', 'productos', [
                'recurso_tipo' => 'producto', 'recurso_id' => implode(',', $ids),
                'descripcion'  => 'Precio de siempre subido ' . dinero($monto) . " en {$cuantos}.",
            ]);
            flash('exito', "Listo: {$cuantos} suben " . dinero($monto) .
                '. Los que estén en oferta mantienen su porcentaje sobre el precio nuevo.');
            break;

        default:
            flash('error', 'Acción no reconocida.');
    }
    redirigir('admin/productos.php');
}

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
    <?php if (Rbac::puede('productos.editar')): ?>
    <form method="post" action="<?= e(url('admin/productos.php')) ?>" id="formMasivo">
      <?= campoToken() ?>
      <!--
        La barra dice de antemano en qué queda cada acción.

        Antes solo preguntaba «¿seguro?» al pulsar, que no es una pregunta que
        nadie pueda responder: subir C$200 a treinta arreglos es una decisión
        distinta según cuánto valgan ahora. El renglón de abajo hace la cuenta
        con lo marcado y lo enseña antes de tocar nada, así que la confirmación
        deja de ser un trámite. El servidor vuelve a calcularlo todo por su
        cuenta; esto solo se ve.
      -->
      <div class="barra-masiva" data-barra-masiva data-moneda="<?= e(Ajustes::texto('moneda_local', 'C$')) ?>" hidden>
        <p class="masiva-cuenta"><strong data-masiva-n>0</strong> <span data-masiva-palabra>arreglos</span> marcados</p>

        <div class="masiva-grupo">
          <label class="masiva-campo" for="masivaPct">
            <span>Poner descuento</span>
            <select id="masivaPct" name="descuento_pct" data-masiva-pct>
              <?php foreach (Precios::SUGERIDOS as $sug): ?>
                <option value="<?= $sug ?>"><?= $sug ?>%</option>
              <?php endforeach; ?>
            </select>
          </label>
          <button type="submit" name="accion_masiva" value="oferta" class="boton boton-principal"
                  data-masiva-accion="oferta">Aplicar oferta</button>
          <button type="submit" name="accion_masiva" value="quitar_oferta" class="boton boton-claro"
                  data-masiva-accion="quitar">Quitar oferta</button>
        </div>

        <div class="masiva-grupo">
          <label class="masiva-campo" for="masivaMonto">
            <span>Subir el precio de siempre</span>
            <input id="masivaMonto" type="number" name="monto" min="1"
                   max="<?= (int)Precios::TOPE_AUMENTO ?>" step="1" placeholder="200"
                   inputmode="numeric" data-masiva-monto>
          </label>
          <button type="submit" name="accion_masiva" value="aumentar" class="boton boton-claro"
                  data-masiva-accion="aumentar">Aplicar aumento</button>
        </div>

        <p class="masiva-vista" data-masiva-vista role="status" aria-live="polite"></p>
      </div>
    <?php endif; ?>
    <div class="tabla-envoltura">
      <table class="tabla">
        <thead>
          <tr><?php if (Rbac::puede('productos.editar')): ?>
                <th><input type="checkbox" data-masiva-todos aria-label="Seleccionar todos"></th>
              <?php endif; ?>
              <th></th><th>Producto</th><th>Categoría</th><th class="num">Precio</th>
              <th>Disponibilidad</th><th>Estado</th><th></th></tr>
        </thead>
        <tbody>
          <?php foreach ($productos as $p): ?>
            <tr>
              <?php if (Rbac::puede('productos.editar')): ?>
                <td><input type="checkbox" name="ids[]" value="<?= (int)$p['id'] ?>" form="formMasivo"
                           data-masiva-item
                           data-precio="<?= e(number_format(Precios::base($p), 2, '.', '')) ?>"
                           data-pct="<?= Precios::porcentaje($p) ?>"
                           aria-label="Seleccionar <?= e((string)$p['nombre']) ?>"></td>
              <?php endif; ?>
              <td><img class="miniatura" width="42" height="52" loading="lazy" decoding="async"
                       src="<?= e(url_imagen((string)$p['imagen'], 'images/placeholders/logo.svg', 160)) ?>" alt=""></td>
              <td>
                <span class="celda-principal"><?= e((string)$p['nombre']) ?></span>
                <?php if ((int)$p['destacado'] === 1): ?>
                  <i class="fa-solid fa-star" style="color:#C9A96E; font-size:.75rem;" title="Destacado" aria-label="Destacado"></i>
                <?php endif; ?>
                <br><span class="celda-sub"><?= e((string)$p['slug']) ?></span>
              </td>
              <td><?= e((string)$p['categoria_nombre']) ?></td>
              <td class="num">
                <?php if (Precios::enOferta($p)): ?>
                  <span class="precio-pila">
                    <s class="precio-antes" title="Precio de siempre"><?= e(dinero(Precios::base($p))) ?></s>
                    <strong class="precio-ahora"><?= e(dinero(Precios::efectivo($p))) ?></strong>
                    <span class="estado-suave oferta">&minus;<?= Precios::porcentaje($p) ?>%</span>
                  </span>
                <?php else: ?>
                  <span class="precio-pila"><strong class="precio-ahora"><?= e(dinero(Precios::base($p))) ?></strong></span>
                <?php endif; ?>
              </td>
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
    <?php if (Rbac::puede('productos.editar')): ?></form><?php endif; ?>

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
