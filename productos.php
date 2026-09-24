<?php
/**
 * Catálogo: búsqueda, filtros, ordenamiento y paginación.
 *
 * Todo el estado va en la URL, así que un filtro se puede compartir, guardar
 * en marcadores e indexar. Sin JavaScript sigue funcionando: el formulario se
 * envía por GET.
 */

declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

$filtros = [
    'q'          => texto('q', 80, $_GET),
    'categoria'  => texto('categoria', 120, $_GET),
    'flor'       => texto('flor', 60, $_GET),
    'orden'      => opcion('orden', ['destacados', 'nuevos', 'precio_asc', 'precio_desc', 'nombre', 'vendidos'],
                           'destacados', $_GET),
    'pagina'     => entero('pagina', 1, 999, 1, $_GET),
    'por_pagina' => 12,
    'solo_disponibles' => casilla('disponibles', $_GET),
];

// La campaña vigente es un eje aparte de la categoría: un mismo arreglo puede
// estar en «Girasoles» y, a la vez, dentro de la campaña de septiembre. Por eso
// no entra en el desplegable de categorías y tiene su propio acceso.
//
// En la dirección se escribe «vigente» y no el número de la campaña: así el
// enlace se puede compartir y sigue valiendo cuando entre la campaña siguiente.
// Se acepta también el número por si alguien guardó una dirección antigua.
$temporadaVigente = Catalogo::temporadaVigente($pdo);
$temporadaPedida  = texto('temporada', 20, $_GET);
$filtros['temporada'] = 0;
if ($temporadaVigente && $temporadaPedida !== ''
    && ($temporadaPedida === 'vigente' || (int)$temporadaPedida === (int)$temporadaVigente['id'])) {
    $filtros['temporada'] = (int)$temporadaVigente['id'];
}
$totalTemporada = $temporadaVigente
    ? Catalogo::totalDeTemporada($pdo, (int)$temporadaVigente['id'])
    : 0;

$resultado    = Catalogo::buscar($pdo, $filtros);
$categorias   = Catalogo::categorias($pdo);
$tiposFlor    = Catalogo::tiposDeFlor($pdo);
$favoritosIds = Favoritos::ids($pdo);

/** Reconstruye la URL actual cambiando solo los parámetros indicados. */
function urlFiltro(array $cambios): string
{
    global $filtros;
    $base = [
        'q'           => $filtros['q'],
        'categoria'   => $filtros['categoria'],
        'flor'        => $filtros['flor'],
        'orden'       => $filtros['orden'] === 'destacados' ? '' : $filtros['orden'],
        'disponibles' => $filtros['solo_disponibles'] ? '1' : '',
        'temporada'   => $filtros['temporada'] ? 'vigente' : '',
    ];
    $params = array_filter(array_merge($base, $cambios), fn($v) => $v !== '' && $v !== null);
    return url('productos.php' . ($params ? '?' . http_build_query($params) : ''));
}

$categoriaActual = null;
foreach ($categorias as $c) {
    if ($c['slug'] === $filtros['categoria']) {
        $categoriaActual = $c;
    }
}

// Lo que la persona está mirando manda sobre el título: si vino por la
// campaña, el encabezado tiene que decir la campaña; si filtró por categoría,
// la categoría. La campaña gana porque es el motivo por el que entró.
$enCampana   = $filtros['temporada'] > 0 && $temporadaVigente;
$nombreTienda = Ajustes::texto('nombre_tienda', 'Flowers Anto');

if ($enCampana) {
    $tituloCatalogo = (string)($temporadaVigente['titulo'] ?: $temporadaVigente['nombre']);
    $bajadaCatalogo = trim((string)($temporadaVigente['subtitulo'] ?? '')) !== ''
        ? (string)$temporadaVigente['subtitulo']
        : 'Todos los arreglos de la campaña.';
} elseif ($categoriaActual) {
    $tituloCatalogo = (string)$categoriaActual['nombre'];
    $bajadaCatalogo = (string)($categoriaActual['descripcion']
        ?: 'Arreglos de la categoría ' . $categoriaActual['nombre']);
} else {
    $tituloCatalogo = 'Todos los arreglos';
    $bajadaCatalogo = 'Busca por nombre, filtra por categoría o por el tipo de flor que tienes en mente.';
}

$tituloPagina      = $tituloCatalogo . ' — ' . $nombreTienda;
$descripcionPagina = $enCampana || $categoriaActual
    ? $bajadaCatalogo
    : 'Catálogo completo de ramos, arreglos y cajas de flores. Filtra por categoría, tipo de flor y precio.';
$paginaActiva = 'productos';

// --- Medición ---------------------------------------------------------
// El nombre de la lista es el que se verá en los informes de Analytics, así
// que se arma con lo que la persona realmente está mirando: una búsqueda, una
// categoría o el catálogo entero. Si todo se llamara «Catálogo» no habría
// forma de saber qué buscan y no encuentran.
$listaGa = $filtros['q'] !== ''       ? 'Búsqueda: ' . $filtros['q']
         : ($categoriaActual          ? 'Categoría: ' . $categoriaActual['nombre']
                                      : 'Catálogo');
if ($filtros['q'] !== '') {
    Analitica::evento('search', [
        'search_term'  => $filtros['q'],
        'resultados'   => (int)($resultado['total'] ?? count($resultado['items'])),
    ]);
}
if ($resultado['items']) {
    Analitica::evento('view_item_list', [
        'item_list_name' => $listaGa,
        'items'          => Analitica::lista($resultado['items'], $listaGa),
    ]);
}

require __DIR__ . '/includes/vistas/cabecera.php';
?>

<div class="container">
  <nav class="migas" aria-label="Ruta">
    <ol>
      <li><a href="<?= e(url()) ?>">Inicio</a></li>
      <?php if ($enCampana || $categoriaActual): ?>
        <li><a href="<?= e(url('productos.php')) ?>">Arreglos</a></li>
        <li aria-current="page"><?= e($tituloCatalogo) ?></li>
      <?php else: ?>
        <li aria-current="page">Arreglos</li>
      <?php endif; ?>
    </ol>
  </nav>

  <header class="pagina-cabecera">
    <h1><?= e($tituloCatalogo) ?></h1>
    <p><?= e($bajadaCatalogo) ?></p>
  </header>

  <?php if ($temporadaVigente && $totalTemporada > 0): ?>
    <?php
      // Acceso a la campaña. Va antes de los filtros y no dentro del
      // desplegable de categorías porque no es una categoría: es un corte
      // transversal que puede tocar varias. Se pinta como un botón grande
      // porque durante la campaña es lo que más gente busca.
      $nombreCampana = (string)($temporadaVigente['titulo'] ?: $temporadaVigente['nombre']);
    ?>
    <div class="acceso-campana">
      <?php if ($enCampana): ?>
        <span class="campana-chip activo">
          <i class="fa-solid fa-seedling" aria-hidden="true"></i>
          <?= e($nombreCampana) ?>
          <span class="campana-cuenta"><?= $totalTemporada ?></span>
        </span>
        <a class="campana-quitar" href="<?= e(urlFiltro(['temporada' => '', 'pagina' => ''])) ?>">
          <i class="fa-solid fa-xmark" aria-hidden="true"></i> Ver todo el catálogo</a>
      <?php else: ?>
        <a class="campana-chip" href="<?= e(urlFiltro(['temporada' => 'vigente', 'pagina' => ''])) ?>">
          <i class="fa-solid fa-seedling" aria-hidden="true"></i>
          Ver los arreglos de <?= e($nombreCampana) ?>
          <span class="campana-cuenta"><?= $totalTemporada ?></span>
        </a>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if (!empty($asesoraActiva)): ?>
    <button type="button" class="asesora-invitacion" data-abrir-asesora aria-controls="asesora" aria-haspopup="dialog">
      <svg class="icono-trazo" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="3"/><path d="M12 16.5A4.5 4.5 0 1 1 7.5 12 4.5 4.5 0 1 1 12 7.5a4.5 4.5 0 1 1 4.5 4.5 4.5 4.5 0 1 1-4.5 4.5"/><path d="M12 7.5V9"/><path d="M7.5 12H9"/><path d="M16.5 12H15"/><path d="M12 16.5V15"/><path d="m8 8 1.88 1.88"/><path d="M14.12 9.88 16 8"/><path d="m8 16 1.88-1.88"/><path d="M14.12 14.12 16 16"/></svg>
      <span>¿No sabes cuál elegir? <strong>Cuéntale a Massiel</strong>, nuestra asesora floral, para quién es y tu presupuesto.</span>
    </button>
  <?php endif; ?>

  <form class="barra-filtros" method="get" action="<?= e(url('productos.php')) ?>" role="search">
    <div class="filtros-fila">
      <div class="campo buscador-catalogo">
        <label for="q">Buscar</label>
        <i class="fas fa-search" aria-hidden="true"></i>
        <input type="search" id="q" name="q" value="<?= e($filtros['q']) ?>"
               placeholder="Rosas, girasoles, caja…" autocomplete="off">
      </div>

      <div class="campo">
        <label for="categoria">Categoría</label>
        <select id="categoria" name="categoria">
          <option value="">Todas</option>
          <?php foreach ($categorias as $c): ?>
            <option value="<?= e((string)$c['slug']) ?>"<?= $filtros['categoria'] === $c['slug'] ? ' selected' : '' ?>>
              <?= e((string)$c['nombre']) ?> (<?= (int)$c['total'] ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="campo">
        <label for="orden">Ordenar por</label>
        <select id="orden" name="orden">
          <option value="destacados"<?= $filtros['orden'] === 'destacados'  ? ' selected' : '' ?>>Recomendados</option>
          <option value="nuevos"<?=     $filtros['orden'] === 'nuevos'      ? ' selected' : '' ?>>Más recientes</option>
          <option value="precio_asc"<?= $filtros['orden'] === 'precio_asc'  ? ' selected' : '' ?>>Precio: menor a mayor</option>
          <option value="precio_desc"<?=$filtros['orden'] === 'precio_desc' ? ' selected' : '' ?>>Precio: mayor a menor</option>
          <option value="nombre"<?=     $filtros['orden'] === 'nombre'      ? ' selected' : '' ?>>Nombre (A-Z)</option>
          <option value="vendidos"<?=   $filtros['orden'] === 'vendidos'    ? ' selected' : '' ?>>Más pedidos</option>
        </select>
      </div>

      <div class="campo">
        <button type="submit" class="btn btn-secondary btn-block">
          <i class="fa-solid fa-sliders" aria-hidden="true"></i> Aplicar
        </button>
      </div>
    </div>

    <?php if ($filtros['flor'] !== ''): ?>
      <input type="hidden" name="flor" value="<?= e($filtros['flor']) ?>">
    <?php endif; ?>
    <?php // Sin esto, buscar algo estando dentro de la campaña te sacaba de
          // ella: el formulario es GET y solo envía lo que lleva dentro. ?>
    <?php if ($enCampana): ?>
      <input type="hidden" name="temporada" value="vigente">
    <?php endif; ?>

    <div class="campo-casilla" style="margin:14px 0 0;">
      <input type="checkbox" id="disponibles" name="disponibles" value="1"
             <?= $filtros['solo_disponibles'] ? 'checked' : '' ?> onchange="this.form.submit()">
      <label for="disponibles">Mostrar solo los disponibles ahora mismo</label>
    </div>
  </form>

  <?php if ($tiposFlor): ?>
    <div class="chips" role="group" aria-label="Filtrar por tipo de flor">
      <a class="chip<?= $filtros['flor'] === '' ? ' activo' : '' ?>" href="<?= e(urlFiltro(['flor' => '', 'pagina' => ''])) ?>">
        Todas las flores
      </a>
      <?php foreach (array_slice($tiposFlor, 0, 8, true) as $flor => $cuantos): ?>
        <a class="chip<?= $filtros['flor'] === $flor ? ' activo' : '' ?>"
           href="<?= e(urlFiltro(['flor' => (string)$flor, 'pagina' => ''])) ?>">
          <?= e(ucfirst((string)$flor)) ?> <span class="cuenta"><?= (int)$cuantos ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <p class="resumen-resultados" aria-live="polite">
    <span>
      <?php if ($resultado['total'] === 0): ?>
        Sin resultados
      <?php else: ?>
        <strong><?= (int)$resultado['total'] ?></strong>
        <?= $resultado['total'] === 1 ? 'arreglo encontrado' : 'arreglos encontrados' ?>
        <?php if ($resultado['paginas'] > 1): ?>
          — página <?= (int)$resultado['pagina'] ?> de <?= (int)$resultado['paginas'] ?>
        <?php endif; ?>
      <?php endif; ?>
    </span>
    <?php if ($filtros['q'] !== '' || $filtros['categoria'] !== '' || $filtros['flor'] !== '' || $filtros['solo_disponibles']): ?>
      <a class="chip" href="<?= e(url('productos.php')) ?>"><i class="fa-solid fa-xmark" aria-hidden="true"></i> Quitar filtros</a>
    <?php endif; ?>
  </p>

  <?php if ($resultado['items']): ?>
    <div class="rejilla-productos" data-ga-lista="<?= e($listaGa) ?>">
      <?php $posicionGa = 0;
            foreach ($resultado['items'] as $p) {
                $posicionGa++;
                require __DIR__ . '/includes/vistas/tarjeta_producto.php';
            } ?>
    </div>

    <?php if ($resultado['paginas'] > 1):
        $pagina  = (int)$resultado['pagina'];
        $paginas = (int)$resultado['paginas'];
        $desde   = max(1, $pagina - 2);
        $hasta   = min($paginas, $desde + 4);
        $desde   = max(1, $hasta - 4);
    ?>
      <nav class="paginacion" aria-label="Paginación del catálogo">
        <a class="<?= $pagina <= 1 ? 'inactivo' : '' ?>"
           href="<?= e(urlFiltro(['pagina' => (string)max(1, $pagina - 1)])) ?>"
           <?= $pagina <= 1 ? 'aria-disabled="true" tabindex="-1"' : '' ?> aria-label="Página anterior">
          <i class="fa-solid fa-chevron-left" aria-hidden="true"></i>
        </a>
        <?php if ($desde > 1): ?>
          <a href="<?= e(urlFiltro(['pagina' => '1'])) ?>">1</a>
          <?php if ($desde > 2): ?><span class="inactivo">…</span><?php endif; ?>
        <?php endif; ?>
        <?php for ($i = $desde; $i <= $hasta; $i++): ?>
          <?php if ($i === $pagina): ?>
            <span class="actual" aria-current="page"><?= $i ?></span>
          <?php else: ?>
            <a href="<?= e(urlFiltro(['pagina' => (string)$i])) ?>"><?= $i ?></a>
          <?php endif; ?>
        <?php endfor; ?>
        <?php if ($hasta < $paginas): ?>
          <?php if ($hasta < $paginas - 1): ?><span class="inactivo">…</span><?php endif; ?>
          <a href="<?= e(urlFiltro(['pagina' => (string)$paginas])) ?>"><?= $paginas ?></a>
        <?php endif; ?>
        <a class="<?= $pagina >= $paginas ? 'inactivo' : '' ?>"
           href="<?= e(urlFiltro(['pagina' => (string)min($paginas, $pagina + 1)])) ?>"
           <?= $pagina >= $paginas ? 'aria-disabled="true" tabindex="-1"' : '' ?> aria-label="Página siguiente">
          <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
        </a>
      </nav>
    <?php endif; ?>

  <?php else: ?>
    <div class="estado-vacio">
      <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
      <h2>No encontramos arreglos con esos filtros</h2>
      <p>Prueba con otras palabras, quita algún filtro o escríbenos: muchos arreglos los preparamos sobre pedido.</p>
      <div class="estado-vacio-acciones">
        <a class="btn btn-primary" href="<?= e(url('productos.php')) ?>">Ver todo el catálogo</a>
        <a class="btn btn-whatsapp"
           href="<?= e(enlace_whatsapp('Hola, busco un arreglo con estas características: ' . ($filtros['q'] !== '' ? $filtros['q'] : '…'))) ?>"
           target="_blank" rel="noopener"><i class="fa-brands fa-whatsapp" aria-hidden="true"></i> Consultar</a>
      </div>
    </div>
  <?php endif; ?>
</div>

<div style="height:56px"></div>
<?php require __DIR__ . '/includes/vistas/pie.php'; ?>
