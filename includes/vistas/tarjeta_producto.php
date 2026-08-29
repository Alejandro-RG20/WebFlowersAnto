<?php
/**
 * Tarjeta de producto. Se usa en la portada, el catálogo, los favoritos y
 * los relacionados, para que el marcado no se duplique.
 *
 * Espera $p (fila de producto con `portada`) y, opcionalmente,
 * $favoritosIds (array de ids) para pintar el corazón ya marcado.
 */

declare(strict_types=1);

/** @var array $p */
$esFavorito = in_array((int)$p['id'], $favoritosIds ?? [], true);
$disponible = Catalogo::disponible($p);
$enlace     = url('producto.php?p=' . rawurlencode((string)$p['slug']));
$portada    = $p['portada'] ?? $p['imagen'];
?>
<article class="tarjeta-producto<?= $disponible ? '' : ' agotado' ?>" data-producto="<?= (int)$p['id'] ?>">
  <a class="tarjeta-imagen" href="<?= e($enlace) ?>" aria-label="<?= e((string)$p['nombre']) ?>">
    <img src="<?= e(url_imagen($portada)) ?>" alt="<?= e((string)$p['nombre']) ?>" loading="lazy" decoding="async">
    <?php if ((int)$p['destacado'] === 1 && $disponible): ?>
      <span class="etiqueta etiqueta-destacado">Destacado</span>
    <?php endif; ?>
    <?php if (!$disponible): ?>
      <span class="etiqueta etiqueta-agotado">Sobre pedido</span>
    <?php endif; ?>
  </a>

  <button type="button" class="btn-fav<?= $esFavorito ? ' active' : '' ?>"
          data-favorito="<?= (int)$p['id'] ?>"
          aria-pressed="<?= $esFavorito ? 'true' : 'false' ?>"
          aria-label="<?= $esFavorito ? 'Quitar de favoritos' : 'Añadir a favoritos' ?>">
    <i class="<?= $esFavorito ? 'fa-solid' : 'fa-regular' ?> fa-heart" aria-hidden="true"></i>
  </button>

  <div class="tarjeta-cuerpo">
    <span class="tarjeta-categoria"><?= e((string)($p['categoria_nombre'] ?? '')) ?></span>
    <h3 class="tarjeta-titulo"><a href="<?= e($enlace) ?>"><?= e((string)$p['nombre']) ?></a></h3>
    <p class="tarjeta-resumen"><?= e(recortar((string)($p['resumen'] ?: $p['descripcion']), 78)) ?></p>

    <div class="tarjeta-pie">
      <div class="tarjeta-precio">
        <strong><?= e(dinero($p['precio'])) ?></strong>
        <?php if (Ajustes::activo('mostrar_usd', true) && (float)$p['precio_usd'] > 0): ?>
          <small>≈ $<?= number_format((float)$p['precio_usd'], 2) ?></small>
        <?php endif; ?>
      </div>

      <?php if ($disponible): ?>
        <form method="post" action="<?= e(url('carrito.php')) ?>" class="form-agregar">
          <?= campoToken() ?>
          <input type="hidden" name="accion" value="agregar">
          <input type="hidden" name="producto_id" value="<?= (int)$p['id'] ?>">
          <input type="hidden" name="cantidad" value="1">
          <button type="submit" class="btn-cart-quick" aria-label="Añadir <?= e((string)$p['nombre']) ?> al carrito">
            <i class="fas fa-cart-plus" aria-hidden="true"></i>
          </button>
        </form>
      <?php else: ?>
        <a class="btn-cart-quick btn-cart-quick--wa"
           href="<?= e(enlace_whatsapp('Hola, me interesa el arreglo «' . $p['nombre'] . '». ¿Lo pueden preparar sobre pedido?')) ?>"
           target="_blank" rel="noopener" aria-label="Consultar por WhatsApp">
          <i class="fa-brands fa-whatsapp" aria-hidden="true"></i>
        </a>
      <?php endif; ?>
    </div>
  </div>
</article>
