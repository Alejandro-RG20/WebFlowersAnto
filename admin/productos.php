<?php
/**
 * Listado de productos del panel: filtros, orden y acciones rápidas.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

$seccion = 'productos';
Rbac::exigirPanel();
Rbac::exigir('productos.ver');

/**
 * Condición del listado según los filtros (búsqueda, categoría, qué
 * mostrar). La usan el listado y las acciones sobre «todos los del filtro»:
 * así lo que se modifica es exactamente lo que se veía.
 *
 * @return array{0: string, 1: list<mixed>}
 */
function filtro_productos(PDO $pdo, string $q, int $categoria, string $visibilidad): array
{
    $where = ['1 = 1'];
    $params = [];
    if ($q !== '') {
        $t = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
        $donde = 'p.nombre LIKE ? OR p.descripcion LIKE ? OR p.flores LIKE ?';
        array_push($params, $t, $t, $t);
        // También por código, escrito como sea: «fa 12» encuentra FA-0012.
        if ($porCodigo = CodigosProducto::filtro($pdo, $q)) {
            $donde .= ' OR ' . $porCodigo[0];
            array_push($params, ...$porCodigo[1]);
        }
        $where[] = '(' . $donde . ')';
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
    return [implode(' AND ', $where), $params];
}

const VISIBILIDADES = ['todos', 'activos', 'ocultos', 'agotados'];
const MAX_MASIVO    = 2000;

// --- Acciones sobre varios productos ----------------------------------
//
// Llegan de la barra de selección: una lista de ids marcados o, con
// `todo_filtro`, los filtros del listado para volver a buscarlos aquí. Cada
// acción es una operación puntual que se ejecuta al pulsar; un aumento de
// precio no puede aplicarse dos veces por recargar (se responde con una
// redirección).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && crudo('accion_masiva') !== '') {
    $volver = 'admin/productos.php' . (($qs = (string)crudo('volver_qs')) !== '' && preg_match('/^[\w=&%+.\-]*$/', $qs) ? '?' . $qs : '');
    exigirToken(false, $volver);

    $accion = opcion('accion_masiva', ['destacar', 'quitar_destacado', 'publicar', 'ocultar', 'disponible',
                                       'sobre_pedido', 'oferta', 'quitar_oferta', 'precio', 'categoria', 'eliminar'], '');
    Rbac::exigir($accion === 'eliminar' ? 'productos.eliminar' : 'productos.editar');

    if (casilla('todo_filtro') === 1) {
        [$w, $p] = filtro_productos($pdo, texto('f_q', 80), identificador('f_categoria'),
                                    opcion('f_visibilidad', VISIBILIDADES, 'todos'));
        $st = $pdo->prepare("SELECT p.id FROM productos p WHERE $w ORDER BY p.id LIMIT " . MAX_MASIVO);
        $st->execute($p);
        $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    } else {
        $ids = array_slice(array_values(array_unique(array_filter(
            array_map('intval', (array)($_POST['ids'] ?? [])), static fn(int $v): bool => $v > 0
        ))), 0, MAX_MASIVO);
        if ($ids) {
            // Solo los que existen: el mensaje cuenta filas reales, no lo que
            // venía en la petición (ids ya borrados, o retocados a mano).
            $huecos = implode(',', array_fill(0, count($ids), '?'));
            $st = $pdo->prepare("SELECT id FROM productos WHERE id IN ($huecos)");
            $st->execute($ids);
            $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        }
    }
    if (!$ids) {
        flash('error', 'No hay arreglos seleccionados (o ya no existen). Actualiza la página y vuelve a marcarlos.');
        redirigir($volver);
    }

    $huecos  = implode(',', array_fill(0, count($ids), '?'));
    $cuantos = count($ids) . ' ' . unidad_plural(count($ids), 'arreglos');
    // Concordancia: «1 arreglo destacado», «3 arreglos destacados».
    $s = static fn(string $uno, string $varios): string => count($ids) === 1 ? $uno : $varios;
    $auditar = static function (string $tipo, string $descripcion) use ($pdo, $ids): void {
        Auditoria::registrar($pdo, $tipo, 'productos', [
            'recurso_tipo' => 'producto', 'recurso_id' => count($ids) . ' productos',
            'descripcion'  => $descripcion, 'detalles' => ['ids' => $ids],
        ]);
    };
    // Las acciones de un solo campo: qué columna y qué valor.
    $simples = [
        'destacar'         => ['destacado', 1, "Listo: {$cuantos} " . $s('destacado', 'destacados') . ' en la portada.'],
        'quitar_destacado' => ['destacado', 0, "Listo: {$cuantos} ya no " . $s('sale', 'salen') . ' como ' . $s('destacado.', 'destacados.')],
        'publicar'         => ['activo', 1, "Listo: {$cuantos} " . $s('publicado', 'publicados') . ' en la web.'],
        'ocultar'          => ['activo', 0, "Listo: {$cuantos} " . $s('oculto: ya no se ve', 'ocultos: ya no se ven') . ' en la web.'],
        'disponible'       => ['disponible', 1, "Listo: {$cuantos} " . $s('disponible', 'disponibles') . ' para pedir ahora.'],
        'sobre_pedido'     => ['disponible', 0, "Listo: {$cuantos} " . $s('pasa', 'pasan') . ' a «sobre pedido».'],
        'quitar_oferta'    => ['descuento_pct', 0, "Listo: {$cuantos} " . $s('vuelve', 'vuelven') . ' a su precio de siempre.'],
    ];

    switch ($accion) {
        case 'oferta':
            $pct = Precios::normalizarPct(crudo('descuento_pct'));
            if ($pct === null || $pct === 0) {
                flash('error', 'El descuento va de 1 a ' . Precios::TOPE_PCT . '%. Para quitarlo usa «Quitar descuento».');
                redirigir($volver);
            }
            $pdo->prepare("UPDATE productos SET descuento_pct = ? WHERE id IN ($huecos)")->execute(array_merge([$pct], $ids));
            $auditar('editar', "Oferta del {$pct}% aplicada a {$cuantos}.");
            flash('exito', "Listo: {$cuantos} con el {$pct}% de descuento. El precio de siempre sigue guardado y vuelve al quitar la oferta.");
            break;

        case 'precio':
            $sube   = opcion('direccion', ['subir', 'bajar'], 'subir') === 'subir';
            $pctual = opcion('modo', ['monto', 'porcentaje'], 'monto') === 'porcentaje';
            $valor  = (float)str_replace(',', '', crudo('valor'));
            $paso   = (float)opcion('redondeo', ['0.01', '1', '5', '10', '50'], '0.01');
            $tope   = $pctual ? ($sube ? 300.0 : 90.0) : Precios::TOPE_AUMENTO;
            if ($valor <= 0 || $valor > $tope) {
                flash('error', $pctual
                    ? 'Escribe un porcentaje entre 1 y ' . (int)$tope . '.'
                    : 'Escribe una cantidad entre ' . dinero(1) . ' y ' . dinero($tope) . '.');
                redirigir($volver);
            }
            // Cambia el precio de siempre, no el rebajado: si el arreglo está
            // en oferta, su porcentaje se sigue aplicando sobre el nuevo. El
            // dólar mantiene la proporción que tenía cada arreglo (y si no
            // tenía, sale de la tasa de la tienda). Se calcula aquí y no en el
            // UPDATE porque el dólar depende del precio de antes.
            $tasa = max(0.0, (float)Ajustes::texto('tasa_usd', '0'));
            $lee  = $pdo->prepare("SELECT id, precio, precio_usd FROM productos WHERE id IN ($huecos)");
            $lee->execute($ids);
            $guarda = $pdo->prepare("UPDATE productos SET precio = ?, precio_usd = ? WHERE id = ?");
            $cambiados = 0;
            $saltados  = 0;
            $pdo->beginTransaction();
            try {
                foreach ($lee->fetchAll() as $fila) {
                    $viejo = (float)$fila['precio'];
                    $delta = $pctual ? $viejo * $valor / 100 : $valor;
                    $nuevo = $sube ? $viejo + $delta : $viejo - $delta;
                    $nuevo = $paso > 0.01 ? round($nuevo / $paso) * $paso : round($nuevo, 2);
                    if ($nuevo < 1) {
                        $saltados++;   // bajar no puede dejar un arreglo regalado
                        continue;
                    }
                    $usd   = (float)$fila['precio_usd'];
                    $razon = ($viejo > 0 && $usd > 0) ? $viejo / $usd : $tasa;
                    $guarda->execute([round($nuevo, 2), $razon > 0 ? round($nuevo / $razon, 2) : 0.0, $fila['id']]);
                    $cambiados++;
                }
                $pdo->commit();
            } catch (PDOException $ex) {
                $pdo->rollBack();
                error_log('Flowers Anto — cambio de precio masivo: ' . $ex->getMessage());
                flash('error', 'No se pudo cambiar el precio. Vuelve a intentarlo.');
                redirigir($volver);
            }
            $cuanto = $pctual ? rtrim(rtrim(number_format($valor, 2), '0'), '.') . '%' : dinero($valor);
            $verbo  = $sube ? 'sube' : 'baja';
            $auditar('editar', 'Precio de siempre: ' . ($sube ? 'subido ' : 'bajado ') . $cuanto . " en {$cambiados} arreglos.");
            flash($cambiados ? 'exito' : 'error', $cambiados
                ? "Listo: el precio {$verbo} {$cuanto} en {$cambiados} " . unidad_plural($cambiados, 'arreglos') . '.'
                  . ($saltados ? " {$saltados} no se cambiaron porque quedarían por debajo de " . dinero(1) . '.' : '')
                  . ' Los que están en oferta mantienen su porcentaje.'
                : 'No se cambió ningún precio: todos quedarían por debajo de ' . dinero(1) . '.');
            break;

        case 'categoria':
            $categoriaId = identificador('categoria_id');
            $st = $pdo->prepare("SELECT nombre FROM categorias WHERE id = ?");
            $st->execute([$categoriaId]);
            $nombreCat = $st->fetchColumn();
            if ($nombreCat === false) {
                flash('error', 'Elige una categoría válida.');
                redirigir($volver);
            }
            $pdo->prepare("UPDATE productos SET categoria_id = ? WHERE id IN ($huecos)")->execute(array_merge([$categoriaId], $ids));
            $auditar('editar', "{$cuantos} movidos a la categoría «{$nombreCat}».");
            flash('exito', "Listo: {$cuantos} ahora " . $s('está', 'están') . " en «{$nombreCat}».");
            break;

        case 'eliminar':
            // Los que ya forman parte de pedidos no se borran: se archivan
            // (ocultos), para no romper el historial de compras.
            $st = $pdo->prepare("SELECT DISTINCT producto_id FROM pedido_items WHERE producto_id IN ($huecos)");
            $st->execute($ids);
            $conPedidos = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            $borrables  = array_values(array_diff($ids, $conPedidos));
            $pdo->beginTransaction();
            try {
                if ($conPedidos) {
                    $h = implode(',', array_fill(0, count($conPedidos), '?'));
                    $pdo->prepare("UPDATE productos SET activo = 0 WHERE id IN ($h)")->execute($conPedidos);
                }
                if ($borrables) {
                    $h = implode(',', array_fill(0, count($borrables), '?'));
                    $pdo->prepare("DELETE FROM productos WHERE id IN ($h)")->execute($borrables);
                }
                $pdo->commit();
            } catch (PDOException $ex) {
                $pdo->rollBack();
                error_log('Flowers Anto — eliminación masiva: ' . $ex->getMessage());
                flash('error', 'No se pudieron eliminar. Vuelve a intentarlo.');
                redirigir($volver);
            }
            $auditar('eliminar', count($borrables) . ' productos eliminados y ' . count($conPedidos) . ' archivados (estaban en pedidos).');
            flash('exito', ($borrables ? count($borrables) . ' ' . unidad_plural(count($borrables), 'arreglos')
                . (count($borrables) === 1 ? ' eliminado.' : ' eliminados.') : '')
                . ($conPedidos ? ' ' . count($conPedidos) . ' ' . unidad_plural(count($conPedidos), 'arreglos')
                    . (count($conPedidos) === 1 ? ' ya estaba en pedidos: se archivó (oculto)' : ' ya estaban en pedidos: se archivaron (ocultos)')
                    . ' en lugar de borrarse.' : ''));
            break;

        default:
            if (!isset($simples[$accion])) {
                flash('error', 'Acción no reconocida.');
                break;
            }
            [$columna, $valor, $mensaje] = $simples[$accion];
            $pdo->prepare("UPDATE productos SET $columna = ? WHERE id IN ($huecos)")->execute(array_merge([$valor], $ids));
            $auditar(in_array($accion, ['publicar', 'ocultar'], true) ? $accion : 'editar', $mensaje);
            flash('exito', $mensaje);
    }
    redirigir($volver);
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
$visibilidad = opcion('visibilidad', VISIBILIDADES, 'todos', $_GET);
$pagina     = entero('pagina', 1, 9999, 1, $_GET);
$porPagina  = 20;

$conCodigo = CodigosProducto::disponible($pdo);
[$sqlWhere, $params] = filtro_productos($pdo, $q, $categoria, $visibilidad);
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

// Seleccionar varios solo tiene sentido si se puede hacer algo con ellos.
$puedeSeleccionar = Rbac::puede('productos.editar') || Rbac::puede('productos.eliminar');

require __DIR__ . '/_cabecera.php';
?>

<section class="panel" data-seleccion="productos" data-total="<?= (int)$total ?>"
         data-singular="arreglo" data-plural="arreglos" data-moneda="<?= e(Ajustes::texto('moneda_local', 'C$')) ?>">
  <form class="barra-herramientas" method="get" action="<?= e(url('admin/productos.php')) ?>" data-autofiltro>
    <div class="campo">
      <label for="q">Buscar</label>
      <input type="search" id="q" name="q" value="<?= e($q) ?>" placeholder="<?= $conCodigo ? 'Código, nombre, descripción o flor' : 'Nombre, descripción o tipo de flor' ?>">
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
    <?php if ($puedeSeleccionar && $productos): ?>
      <button type="button" class="boton boton-claro boton-seleccionar" data-seleccion-alternar aria-pressed="false"
              data-texto-activo="Terminar selección">
        <i class="fa-solid fa-list-check" aria-hidden="true"></i> <span>Seleccionar</span></button>
    <?php endif; ?>
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
      <table class="tabla tabla-seleccionable">
        <thead>
          <tr><?php if ($puedeSeleccionar): ?>
                <th class="col-sel"><input type="checkbox" data-sel-todos aria-label="Seleccionar todos los de esta página"></th>
              <?php endif; ?>
              <th></th><th>Producto</th><th>Categoría</th><th class="num">Precio</th>
              <th>Disponibilidad</th><th>Estado</th><th></th></tr>
        </thead>
        <tbody>
          <?php foreach ($productos as $p): ?>
            <tr>
              <?php if ($puedeSeleccionar): ?>
                <td class="col-sel"><input type="checkbox" value="<?= (int)$p['id'] ?>" data-sel-item
                           data-nombre="<?= e((string)$p['nombre']) ?>"
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
                <br><span class="celda-sub"><?php if ((string)($p['codigo'] ?? '') !== ''): ?><span class="codigo-producto"><?= e((string)$p['codigo']) ?></span> · <?php endif; ?><?= e((string)$p['slug']) ?></span>
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

    <?php if ($puedeSeleccionar):
        // Los filtros del listado viajan con cada acción: con «todos los del
        // filtro» el servidor vuelve a buscar exactamente estos arreglos.
        $camposFiltro = campoToken()
            . '<input type="hidden" name="f_q" value="' . e($q) . '">'
            . '<input type="hidden" name="f_categoria" value="' . (int)$categoria . '">'
            . '<input type="hidden" name="f_visibilidad" value="' . e($visibilidad) . '">'
            . '<input type="hidden" name="volver_qs" value="' . e(http_build_query(array_filter(
                ['q' => $q, 'categoria' => $categoria ?: '', 'visibilidad' => $visibilidad, 'pagina' => $pagina > 1 ? $pagina : '']))) . '">';
        $editar   = Rbac::puede('productos.editar');
        $eliminar = Rbac::puede('productos.eliminar'); ?>
      <form method="post" action="<?= e(url('admin/productos.php')) ?>" id="formSeleccion" data-seleccion-form data-una-vez>
        <?= $camposFiltro ?>
      </form>

      <div class="barra-seleccion" data-seleccion-barra role="region" aria-label="Acciones con los arreglos seleccionados" hidden>
        <div class="seleccion-info">
          <p><strong data-sel-n>0</strong> <span data-sel-palabra>arreglos</span></p>
          <button type="button" class="seleccion-filtro" data-sel-filtro hidden></button>
        </div>
        <div class="seleccion-acciones">
          <?php if ($editar): ?>
            <div class="seleccion-grupo" role="group" aria-label="Portada">
              <button type="submit" form="formSeleccion" name="accion_masiva" value="destacar" data-sel-accion>
                <i class="fa-solid fa-star" aria-hidden="true"></i> Destacar</button>
              <button type="submit" form="formSeleccion" name="accion_masiva" value="quitar_destacado" data-sel-accion>
                <i class="fa-regular fa-star" aria-hidden="true"></i> Quitar destacado</button>
            </div>
            <div class="seleccion-grupo" role="group" aria-label="Visibilidad">
              <button type="submit" form="formSeleccion" name="accion_masiva" value="publicar" data-sel-accion>
                <i class="fa-solid fa-eye" aria-hidden="true"></i> Publicar</button>
              <button type="submit" form="formSeleccion" name="accion_masiva" value="ocultar" data-sel-accion>
                <i class="fa-solid fa-eye-slash" aria-hidden="true"></i> Ocultar</button>
            </div>
            <div class="seleccion-grupo" role="group" aria-label="Disponibilidad">
              <button type="submit" form="formSeleccion" name="accion_masiva" value="disponible" data-sel-accion>
                <i class="fa-solid fa-circle-check" aria-hidden="true"></i> Disponible</button>
              <button type="submit" form="formSeleccion" name="accion_masiva" value="sobre_pedido" data-sel-accion>
                <i class="fa-solid fa-clock" aria-hidden="true"></i> Sobre pedido</button>
            </div>
            <div class="seleccion-grupo" role="group" aria-label="Precio">
              <button type="button" data-abrir-modal="modalSelOferta" data-sel-accion>
                <i class="fa-solid fa-tags" aria-hidden="true"></i> Descuento…</button>
              <button type="submit" form="formSeleccion" name="accion_masiva" value="quitar_oferta" data-sel-accion>
                <i class="fa-solid fa-xmark" aria-hidden="true"></i> Quitar descuento</button>
              <button type="button" data-abrir-modal="modalSelPrecio" data-sel-accion>
                <i class="fa-solid fa-arrow-trend-up" aria-hidden="true"></i> Cambiar precio…</button>
            </div>
            <div class="seleccion-grupo" role="group" aria-label="Organización">
              <button type="button" data-abrir-modal="modalSelCategoria" data-sel-accion>
                <i class="fa-solid fa-folder-open" aria-hidden="true"></i> Categoría…</button>
            </div>
          <?php endif; ?>
          <?php if ($eliminar): ?>
            <div class="seleccion-grupo" role="group" aria-label="Eliminar">
              <button type="submit" form="formSeleccion" name="accion_masiva" value="eliminar" class="peligro" data-sel-accion
                      data-sel-confirmar="¿Eliminar {n} {palabra}? Los que ya estén en algún pedido se archivan (quedan ocultos) en lugar de borrarse. No se puede deshacer.">
                <i class="fa-solid fa-trash-can" aria-hidden="true"></i> Eliminar</button>
            </div>
          <?php endif; ?>
        </div>
        <button type="button" class="seleccion-cerrar" data-seleccion-alternar aria-label="Terminar la selección">
          <i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
      </div>

      <?php if ($editar): ?>
        <dialog class="modal" id="modalSelOferta" aria-labelledby="tituloSelOferta">
          <form method="post" action="<?= e(url('admin/productos.php')) ?>" data-seleccion-form data-una-vez>
            <?= $camposFiltro ?>
            <input type="hidden" name="accion_masiva" value="oferta">
            <div class="modal-cabecera"><h2 id="tituloSelOferta"><i class="fa-solid fa-tags" aria-hidden="true"></i> Poner descuento</h2></div>
            <div class="modal-cuerpo">
              <p class="modal-ayuda">Se aplica a <strong data-sel-n>0</strong> <span data-sel-palabra>arreglos</span>.
                El precio de siempre no se toca: es el que se tacha en la web y vuelve al quitar el descuento.</p>
              <div class="campo">
                <label for="selPct">Descuento</label>
                <select id="selPct" name="descuento_pct">
                  <?php foreach (Precios::SUGERIDOS as $sug): ?>
                    <option value="<?= $sug ?>"<?= $sug === 10 ? ' selected' : '' ?>><?= $sug ?>%</option>
                  <?php endforeach; ?>
                </select>
              </div>
              <p class="modal-vista" data-vista-oferta role="status" aria-live="polite"></p>
            </div>
            <div class="modal-pie">
              <button type="button" class="boton boton-claro" data-cerrar-modal>Cancelar</button>
              <button type="submit" class="boton boton-principal" data-sel-accion>Aplicar descuento</button>
            </div>
          </form>
        </dialog>

        <dialog class="modal" id="modalSelPrecio" aria-labelledby="tituloSelPrecio">
          <form method="post" action="<?= e(url('admin/productos.php')) ?>" data-seleccion-form data-una-vez
                data-confirmar="El precio de siempre se cambia y no hay botón para deshacerlo. ¿Seguimos?">
            <?= $camposFiltro ?>
            <input type="hidden" name="accion_masiva" value="precio">
            <div class="modal-cabecera"><h2 id="tituloSelPrecio"><i class="fa-solid fa-arrow-trend-up" aria-hidden="true"></i> Cambiar el precio</h2></div>
            <div class="modal-cuerpo">
              <p class="modal-ayuda">Cambia el precio de siempre de <strong data-sel-n>0</strong> <span data-sel-palabra>arreglos</span>.
                Los que estén en oferta mantienen su porcentaje sobre el precio nuevo, y el precio en dólares
                se ajusta en la misma proporción.</p>
              <div class="selector-segmentado" role="radiogroup" aria-label="Subir o bajar">
                <label><input type="radio" name="direccion" value="subir" checked> <span>Subir</span></label>
                <label><input type="radio" name="direccion" value="bajar"> <span>Bajar</span></label>
              </div>
              <div class="selector-segmentado" role="radiogroup" aria-label="Cantidad fija o porcentaje">
                <label><input type="radio" name="modo" value="monto" checked> <span>Cantidad fija</span></label>
                <label><input type="radio" name="modo" value="porcentaje"> <span>Porcentaje</span></label>
              </div>
              <div class="rejilla-campos dos">
                <div class="campo">
                  <label for="selValor">Cuánto (<span data-precio-unidad><?= e(Ajustes::texto('moneda_local', 'C$')) ?></span>)</label>
                  <input type="number" id="selValor" name="valor" min="0.01" step="0.01" inputmode="decimal" required placeholder="100">
                </div>
                <div class="campo">
                  <label for="selRedondeo">Redondear a</label>
                  <select id="selRedondeo" name="redondeo">
                    <option value="0.01">Sin redondear</option>
                    <option value="1">Córdoba entero</option>
                    <option value="5">Múltiplo de 5</option>
                    <option value="10">Múltiplo de 10</option>
                    <option value="50">Múltiplo de 50</option>
                  </select>
                </div>
              </div>
              <p class="modal-vista" data-vista-precio role="status" aria-live="polite"></p>
            </div>
            <div class="modal-pie">
              <button type="button" class="boton boton-claro" data-cerrar-modal>Cancelar</button>
              <button type="submit" class="boton boton-principal" data-sel-accion>Cambiar precio</button>
            </div>
          </form>
        </dialog>

        <dialog class="modal" id="modalSelCategoria" aria-labelledby="tituloSelCategoria">
          <form method="post" action="<?= e(url('admin/productos.php')) ?>" data-seleccion-form data-una-vez>
            <?= $camposFiltro ?>
            <input type="hidden" name="accion_masiva" value="categoria">
            <div class="modal-cabecera"><h2 id="tituloSelCategoria"><i class="fa-solid fa-folder-open" aria-hidden="true"></i> Mover a otra categoría</h2></div>
            <div class="modal-cuerpo">
              <p class="modal-ayuda">Mueve <strong data-sel-n>0</strong> <span data-sel-palabra>arreglos</span> a:</p>
              <div class="campo">
                <label for="selCategoria">Categoría</label>
                <select id="selCategoria" name="categoria_id" required>
                  <?php foreach ($categorias as $c): ?>
                    <option value="<?= (int)$c['id'] ?>"><?= e((string)$c['nombre']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <div class="modal-pie">
              <button type="button" class="boton boton-claro" data-cerrar-modal>Cancelar</button>
              <button type="submit" class="boton boton-principal" data-sel-accion>Mover</button>
            </div>
          </form>
        </dialog>
      <?php endif; ?>
    <?php endif; ?>

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
