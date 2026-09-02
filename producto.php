<?php
/**
 * Ficha de producto.
 *
 * URL amigable por slug (`producto.php?p=ramo-gerbera`). Los enlaces antiguos
 * por id siguen funcionando y redirigen al slug con un 301, para no perder el
 * posicionamiento ni los enlaces que alguien tenga guardados.
 */

declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

$referencia = texto('p', 140, $_GET);
if ($referencia === '') {
    $referencia = texto('id', 20, $_GET);
}

$producto = $referencia !== '' ? Catalogo::producto($pdo, $referencia) : null;

if (!$producto) {
    http_response_code(404);
    $tituloPagina = 'Arreglo no encontrado';
    require __DIR__ . '/includes/vistas/cabecera.php';
    ?>
    <div class="container">
      <div class="estado-vacio">
        <i class="fa-solid fa-seedling" aria-hidden="true"></i>
        <h1>Ese arreglo ya no está publicado</h1>
        <p>Puede que lo hayamos retirado del catálogo o que el enlace esté incompleto.
           Echa un vistazo al resto, seguro encuentras algo.</p>
        <div class="estado-vacio-acciones">
          <a class="btn btn-primary" href="<?= e(url('productos.php')) ?>">Ver el catálogo</a>
          <a class="btn btn-outline-dark" href="<?= e(url()) ?>">Ir al inicio</a>
        </div>
      </div>
    </div>
    <?php
    require __DIR__ . '/includes/vistas/pie.php';
    exit;
}

// Se llegó por id: se redirige a la dirección definitiva.
if ($referencia !== $producto['slug']) {
    header('Location: ' . url('producto.php?p=' . rawurlencode((string)$producto['slug'])), true, 301);
    exit;
}

$imagenes     = $producto['imagenes'] ?: [['ruta' => $producto['imagen'], 'alt' => $producto['nombre']]];
$disponible   = Catalogo::disponible($producto);
$maximo       = Catalogo::maximoPedible($producto);
$esFavorito   = Favoritos::contiene($pdo, (int)$producto['id']);
$relacionados = Catalogo::relacionados($pdo, $producto, 4);
$favoritosIds = Favoritos::ids($pdo);

$tituloPagina      = $producto['nombre'] . ' — ' . Ajustes::texto('nombre_tienda', 'Flowers Anto');
$descripcionPagina = recortar((string)($producto['resumen'] ?: $producto['descripcion']), 160);
$imagenOg          = (string)$imagenes[0]['ruta'];
$paginaActiva      = 'productos';

$datosEstructurados = [
    '@context'    => 'https://schema.org',
    '@type'       => 'Product',
    'name'        => $producto['nombre'],
    'description' => $descripcionPagina,
    'image'       => array_map(fn($i) => url_absoluta((string)$i['ruta']), $imagenes),
    'sku'         => 'FA-' . $producto['id'],
    'category'    => $producto['categoria_nombre'],
    'brand'       => ['@type' => 'Brand', 'name' => Ajustes::texto('nombre_tienda', 'Flowers Anto')],
    'offers'      => [
        '@type'         => 'Offer',
        'price'         => number_format((float)$producto['precio'], 2, '.', ''),
        'priceCurrency' => 'NIO',
        'url'           => url_absoluta('producto.php?p=' . rawurlencode((string)$producto['slug'])),
        'availability'  => $disponible
            ? 'https://schema.org/InStock'
            : 'https://schema.org/PreOrder',
    ],
];

require __DIR__ . '/includes/vistas/cabecera.php';
?>

<div class="container">
  <nav class="migas" aria-label="Ruta">
    <ol>
      <li><a href="<?= e(url()) ?>">Inicio</a></li>
      <li><a href="<?= e(url('productos.php')) ?>">Arreglos</a></li>
      <li><a href="<?= e(url('productos.php?categoria=' . rawurlencode((string)$producto['categoria_slug']))) ?>">
        <?= e((string)$producto['categoria_nombre']) ?></a></li>
      <li aria-current="page"><?= e((string)$producto['nombre']) ?></li>
    </ol>
  </nav>

  <div class="ficha">
    <!-- Galería -->
    <div>
      <div class="galeria-principal" id="galeriaProducto" tabindex="0"
           aria-roledescription="carrusel" aria-label="Fotos de <?= e((string)$producto['nombre']) ?>">
        <div class="galeria-pista">
          <?php foreach ($imagenes as $i => $img): ?>
            <div class="galeria-diapositiva">
              <img src="<?= e(url_imagen((string)$img['ruta'])) ?>"
                   alt="<?= e((string)($img['alt'] ?: $producto['nombre'])) ?>"
                   <?= $i === 0 ? 'fetchpriority="high"' : 'loading="lazy"' ?>>
            </div>
          <?php endforeach; ?>
        </div>
        <?php if (count($imagenes) > 1): ?>
          <button type="button" class="galeria-flecha anterior" aria-label="Foto anterior">
            <i class="fa-solid fa-chevron-left" aria-hidden="true"></i></button>
          <button type="button" class="galeria-flecha siguiente" aria-label="Foto siguiente">
            <i class="fa-solid fa-chevron-right" aria-hidden="true"></i></button>
          <span class="galeria-contador">1 / <?= count($imagenes) ?></span>
        <?php endif; ?>
      </div>

      <?php if (count($imagenes) > 1): ?>
        <div class="galeria-miniaturas" id="galeriaMiniaturas" role="group" aria-label="Elegir foto">
          <?php foreach ($imagenes as $i => $img): ?>
            <button type="button" aria-current="<?= $i === 0 ? 'true' : 'false' ?>"
                    aria-label="Ver foto <?= $i + 1 ?>">
              <img src="<?= e(url_imagen((string)$img['ruta'])) ?>" alt="" loading="lazy">
            </button>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- Información -->
    <div class="ficha-info">
      <span class="tarjeta-categoria"><?= e((string)$producto['categoria_nombre']) ?></span>
      <h1><?= e((string)$producto['nombre']) ?></h1>

      <?php if ($disponible): ?>
        <span class="insignia insignia-disponible">
          <i class="fa-solid fa-circle-check" aria-hidden="true"></i> Disponible
          <?php if ((int)$producto['controla_stock'] === 1 && (int)$producto['stock'] <= 5): ?>
            — quedan <?= (int)$producto['stock'] ?>
          <?php endif; ?>
        </span>
      <?php else: ?>
        <span class="insignia insignia-agotado">
          <i class="fa-solid fa-clock" aria-hidden="true"></i> Sobre pedido
        </span>
      <?php endif; ?>

      <div class="ficha-precio">
        <strong><?= e(dinero($producto['precio'])) ?></strong>
        <?php if (Ajustes::activo('mostrar_usd', true) && (float)$producto['precio_usd'] > 0): ?>
          <span>≈ $<?= number_format((float)$producto['precio_usd'], 2) ?> USD</span>
        <?php endif; ?>
      </div>

      <p class="ficha-descripcion"><?= nl2br(e((string)$producto['descripcion'])) ?></p>

      <div class="ficha-acciones">
        <?php if ($disponible): ?>
          <form method="post" action="<?= e(url('carrito.php')) ?>" data-una-vez>
            <?= campoToken() ?>
            <input type="hidden" name="accion" value="agregar">
            <input type="hidden" name="producto_id" value="<?= (int)$producto['id'] ?>">

            <label class="etiqueta-campo" for="cantidad">Cantidad</label>
            <div class="fila-comprar">
              <div class="selector-cantidad">
                <button type="button" data-paso="-1" aria-label="Quitar una unidad">
                  <i class="fa-solid fa-minus" aria-hidden="true"></i></button>
                <input type="number" id="cantidad" name="cantidad" value="1" min="1"
                       max="<?= $maximo ?>" inputmode="numeric" aria-label="Cantidad">
                <button type="button" data-paso="1" aria-label="Añadir una unidad">
                  <i class="fa-solid fa-plus" aria-hidden="true"></i></button>
              </div>
              <button type="submit" class="btn btn-primary">
                <i class="fa-solid fa-cart-plus" aria-hidden="true"></i> Añadir al carrito
              </button>
            </div>
          </form>

          <div class="fila-comprar">
            <a class="btn btn-whatsapp"
               href="<?= e(enlace_whatsapp(
                    'Hola, me interesa el arreglo «' . $producto['nombre'] . '» (' . dinero($producto['precio']) . ").\n"
                    . url_absoluta('producto.php?p=' . rawurlencode((string)$producto['slug']))
               )) ?>"
               target="_blank" rel="noopener">
              <i class="fa-brands fa-whatsapp" aria-hidden="true"></i> Pedir por WhatsApp
            </a>
            <button type="button" class="btn btn-outline-dark<?= $esFavorito ? ' activo' : '' ?>"
                    data-favorito="<?= (int)$producto['id'] ?>"
                    aria-pressed="<?= $esFavorito ? 'true' : 'false' ?>">
              <i class="<?= $esFavorito ? 'fa-solid' : 'fa-regular' ?> fa-heart" aria-hidden="true"></i>
              <span><?= $esFavorito ? 'En favoritos' : 'Guardar' ?></span>
            </button>
          </div>
        <?php else: ?>
          <div class="caja-aviso alerta">
            <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
            <span>Este arreglo no está disponible para pedido inmediato, pero lo preparamos
                  bajo encargo. Escríbenos y coordinamos la fecha.</span>
          </div>
          <div class="fila-comprar">
            <a class="btn btn-whatsapp"
               href="<?= e(enlace_whatsapp('Hola, quiero encargar el arreglo «' . $producto['nombre'] . '». ¿Para cuándo lo pueden tener?')) ?>"
               target="_blank" rel="noopener">
              <i class="fa-brands fa-whatsapp" aria-hidden="true"></i> Consultar disponibilidad
            </a>
            <button type="button" class="btn btn-outline-dark" data-favorito="<?= (int)$producto['id'] ?>"
                    aria-pressed="<?= $esFavorito ? 'true' : 'false' ?>">
              <i class="<?= $esFavorito ? 'fa-solid' : 'fa-regular' ?> fa-heart" aria-hidden="true"></i>
              <span><?= $esFavorito ? 'En favoritos' : 'Guardar' ?></span>
            </button>
          </div>
        <?php endif; ?>
      </div>

      <div class="ficha-datos">
        <?php if ($producto['flores'] !== ''): ?>
          <div><i class="fa-solid fa-seedling" aria-hidden="true"></i>
            <span><strong>Flores:</strong> <?= e(implode(', ', array_map('ucfirst',
                  array_map('trim', explode(',', (string)$producto['flores']))))) ?></span></div>
        <?php endif; ?>
        <div><i class="fa-solid fa-truck-fast" aria-hidden="true"></i>
          <span>Entrega el mismo día dentro de Managua si el pedido entra antes del mediodía.</span></div>
        <div><i class="fa-solid fa-building-columns" aria-hidden="true"></i>
          <span>Pago por transferencia bancaria. Subes el comprobante y lo verificamos antes de preparar el arreglo.</span></div>
        <div><i class="fa-solid fa-hand-holding-heart" aria-hidden="true"></i>
          <span>Puedes añadir una dedicatoria al confirmar el pedido, sin costo.</span></div>
      </div>
    </div>
  </div>
</div>

<?php if ($relacionados): ?>
<section class="section">
  <div class="container">
    <div class="section-header aparece">
      <span class="section-tag">También te puede gustar</span>
      <h2>Más de <?= e((string)$producto['categoria_nombre']) ?></h2>
    </div>
    <div class="rejilla-productos aparece">
      <?php foreach ($relacionados as $p) { require __DIR__ . '/includes/vistas/tarjeta_producto.php'; } ?>
    </div>
  </div>
</section>
<?php endif; ?>

<div class="visor" id="visor" role="dialog" aria-modal="true" aria-label="Foto ampliada">
  <button type="button" class="visor-cerrar" aria-label="Cerrar">&times;</button>
  <?php // Sin atributo `src`: uno vacío hace que el navegador vuelva a pedir
        // la propia página en cada visita. La foto la pone el JS al abrir. ?>
  <img alt="">
</div>

<?php require __DIR__ . '/includes/vistas/pie.php'; ?>
