<?php
/**
 * Portada.
 *
 * Se renderiza en el servidor: el catálogo, la temporada y la configuración
 * salen ya resueltos en el HTML. Así la página es indexable, se ve al instante
 * y no depende de que el JavaScript llegue antes que el usuario.
 */

declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

$temporada    = Catalogo::temporadaActiva($pdo);
$hero         = Catalogo::hero($pdo, $temporada);
$destacados   = Catalogo::destacados($pdo, 8);
$recientes    = Catalogo::recientes($pdo, 4);
$categorias   = Catalogo::categorias($pdo);
$fotos        = Catalogo::fotosGaleria($pdo);
$videos       = Catalogo::videos($pdo);
$favoritosIds = Favoritos::ids($pdo);

$tienda = Ajustes::texto('nombre_tienda', 'Flowers Anto');

// Datos que consume el carrusel de la portada.
$datosHero = [
    'config' => [
        'nombre_tienda'    => $tienda,
        'eslogan'          => Ajustes::texto('eslogan'),
        'moneda_local'     => Ajustes::texto('moneda_local', 'C$'),
        'mostrar_usd'      => Ajustes::activo('mostrar_usd', true) ? 1 : 0,
        'hero_palabra'     => Ajustes::texto('hero_palabra', 'FLORES'),
        'hero_titulo'      => Ajustes::texto('hero_titulo'),
        'hero_subtitulo'   => Ajustes::texto('hero_subtitulo'),
        'hero_cta_texto'   => Ajustes::texto('hero_cta_texto', 'Ver arreglos'),
        'hero_imagen'      => url_imagen(Ajustes::texto('hero_imagen', 'images/placeholders/hero-01.svg')),
        'hero_color_fondo' => Ajustes::texto('hero_color_fondo', '#EFD9DE'),
        'hero_autoplay'    => Ajustes::activo('hero_autoplay') ? 1 : 0,
    ],
    'hero' => array_map(fn(array $p) => [
        'nombre'           => $p['nombre'],
        'descripcion'      => recortar((string)($p['resumen'] ?: $p['descripcion']), 130),
        'imagen'           => url_imagen($p['portada'] ?? $p['imagen']),
        'enlace'           => url('producto.php?p=' . rawurlencode((string)$p['slug'])),
        'precio'           => (float)$p['precio'],
        'precio_usd'       => (float)$p['precio_usd'],
        'categoria_nombre' => $p['categoria_nombre'] ?? '',
        'color_acento'     => $p['color_acento'] ?: '#EFD9DE',
    ], $hero),
    'temporada' => $temporada ? [
        'titulo'       => $temporada['titulo'],
        'palabra_hero' => $temporada['palabra_hero'],
    ] : null,
];

$tituloPagina      = $tienda . ' — Arreglos florales artesanales en Managua';
$descripcionPagina = Ajustes::texto('meta_descripcion');
$paginaActiva      = 'inicio';
$cssExtra          = ['assets/css/hero.css'];
$jsExtra           = ['assets/js/hero.js'];
$datosEstructurados = [
    '@context' => 'https://schema.org',
    '@type'    => 'Florist',
    'name'     => $tienda,
    'url'      => url_absoluta(),
    'image'    => url_absoluta(Ajustes::texto('logo_url', 'images/logoanto.jpeg')),
    'description' => $descripcionPagina,
    'telephone'   => Ajustes::texto('telefono'),
    'email'       => Ajustes::texto('email_contacto'),
    'address'     => ['@type' => 'PostalAddress', 'streetAddress' => Ajustes::texto('direccion'),
                      'addressLocality' => 'Managua', 'addressCountry' => 'NI'],
    'openingHours' => Ajustes::texto('horario'),
];

require __DIR__ . '/includes/vistas/cabecera.php';
?>

<!-- Portada editorial: los arreglos protagonistas salen del panel -->
<section class="hero-editorial" id="heroEditorial" tabindex="-1"
         aria-roledescription="carrusel" aria-label="Arreglos destacados">
  <div class="hero-palabra" id="heroPalabra" aria-hidden="true"><span><?= e(Ajustes::texto('hero_palabra', 'FLORES')) ?></span></div>
  <div class="hero-escenario" id="heroEscenario"></div>
  <div class="hero-grano" aria-hidden="true"></div>
  <div class="hero-etiqueta" id="heroEtiqueta"></div>
  <div class="hero-puntos" id="heroPuntos" role="tablist" aria-label="Elegir arreglo"></div>

  <div class="hero-panel" id="heroPanel">
    <div class="contenido-pieza" aria-live="polite">
      <h1><?= e(Ajustes::texto('hero_titulo', 'Creamos el arreglo perfecto para cada momento')) ?></h1>
      <p class="resumen"><?= e(Ajustes::texto('hero_subtitulo')) ?></p>
    </div>
    <div class="hero-nav">
      <button type="button" id="heroPrev" aria-label="Arreglo anterior"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i></button>
      <button type="button" id="heroSig" aria-label="Arreglo siguiente"><i class="fa-solid fa-arrow-right" aria-hidden="true"></i></button>
    </div>
  </div>

  <a class="hero-llamada" id="heroLlamada" href="<?= e(url('productos.php')) ?>">
    <span><?= e(Ajustes::texto('hero_cta_texto', 'Ver arreglos')) ?></span>
    <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
  </a>
</section>
<script type="application/json" id="datosHero"><?= json_para_html($datosHero) ?></script>

<?php if ($temporada && !empty($temporada['productos'])): ?>
<!-- Temporada vigente -->
<section class="temporada-seccion" id="temporadaSeccion" style="--temporada-color: <?= e((string)$temporada['color_acento']) ?>;">
  <div class="container">
    <div class="temporada-cabecera">
      <div>
        <span class="temporada-marca"><i class="fa-solid fa-seedling" aria-hidden="true"></i> Temporada</span>
        <h2><?= e((string)$temporada['titulo']) ?></h2>
        <?php if ($temporada['subtitulo']): ?><p><?= e((string)$temporada['subtitulo']) ?></p><?php endif; ?>
      </div>
      <?php if ($temporada['fecha_fin']): ?>
        <span class="temporada-cuenta">Hasta el <?= e(fecha_corta((string)$temporada['fecha_fin'])) ?></span>
      <?php endif; ?>
    </div>
    <div class="rejilla-productos">
      <?php foreach (array_slice($temporada['productos'], 0, 4) as $p) {
          require __DIR__ . '/includes/vistas/tarjeta_producto.php';
      } ?>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- Categorías -->
<?php if ($categorias): ?>
<section class="section" id="categorias">
  <div class="container">
    <div class="section-header aparece">
      <span class="section-tag">Categorías</span>
      <h2>Encuentra por tipo de arreglo</h2>
      <p>Cada categoría se arma con flores de temporada el mismo día del envío.</p>
    </div>
    <div class="rejilla-categorias aparece">
      <?php foreach ($categorias as $c): ?>
        <a class="tarjeta-categoria-enlace" href="<?= e(url('productos.php?categoria=' . rawurlencode((string)$c['slug']))) ?>">
          <h3><?= e((string)$c['nombre']) ?></h3>
          <p><?= (int)$c['total'] ?> <?= (int)$c['total'] === 1 ? 'arreglo' : 'arreglos' ?></p>
          <i class="fa-solid fa-arrow-right flecha" aria-hidden="true"></i>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- Destacados -->
<?php if ($destacados): ?>
<section class="section" id="destacados" style="background: var(--rose-soft);">
  <div class="container">
    <div class="section-header aparece">
      <span class="section-tag">Nuestros arreglos</span>
      <h2>Creaciones que enamoran</h2>
      <p>Los que más nos piden. Todos se pueden personalizar antes de enviarlos.</p>
    </div>
    <div class="rejilla-productos aparece">
      <?php foreach ($destacados as $p) { require __DIR__ . '/includes/vistas/tarjeta_producto.php'; } ?>
    </div>
    <div style="text-align:center; margin-top:34px;">
      <a class="btn btn-secondary" href="<?= e(url('productos.php')) ?>">
        Ver el catálogo completo <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
      </a>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- Nosotros -->
<section class="section about" id="nosotros">
  <div class="container">
    <div class="about-grid">
      <div class="about-image aparece">
        <div class="image-frame">
          <img src="<?= e(url_imagen(Ajustes::texto('nosotros_imagen', 'images/principal.jpeg'))) ?>"
               alt="Taller de <?= e($tienda) ?>" loading="lazy" width="600" height="700">
        </div>
      </div>
      <div class="about-text aparece">
        <span class="section-tag">Sobre nosotros</span>
        <h2><?= e(Ajustes::texto('nosotros_titulo', 'Flores con historia')) ?></h2>
        <p><?= nl2br(e(Ajustes::texto('nosotros_texto'))) ?></p>
        <ul class="about-features">
          <li><i class="fa-solid fa-check-circle" aria-hidden="true"></i> Flores frescas seleccionadas cada mañana</li>
          <li><i class="fa-solid fa-check-circle" aria-hidden="true"></i> Diseños personalizados para toda ocasión</li>
          <li><i class="fa-solid fa-check-circle" aria-hidden="true"></i> Entrega el mismo día dentro de Managua</li>
          <li><i class="fa-solid fa-check-circle" aria-hidden="true"></i> Atención directa por WhatsApp</li>
        </ul>
        <a href="<?= e(url('productos.php')) ?>" class="btn btn-secondary">Ver los arreglos</a>
      </div>
    </div>
  </div>
</section>

<!-- Cómo comprar -->
<section class="section" id="como-comprar">
  <div class="container">
    <div class="section-header aparece">
      <span class="section-tag">Cómo comprar</span>
      <h2>Dos formas de pedir, tú eliges</h2>
    </div>
    <div class="franja-ventajas aparece" style="gap:22px;">
      <div class="tarjeta">
        <h3><i class="fa-solid fa-bag-shopping" style="color:var(--rose-pastel)" aria-hidden="true"></i> Pedido en la web</h3>
        <p style="color:var(--suave); font-size:.93rem; line-height:1.65; margin:10px 0 16px;">
          Eliges los arreglos, completas la entrega y pagas por transferencia. Subes el comprobante
          y sigues el estado del pedido desde tu enlace, con cuenta o sin ella.
        </p>
        <a class="btn btn-primary btn-sm" href="<?= e(url('productos.php')) ?>">Empezar mi pedido</a>
      </div>
      <div class="tarjeta">
        <h3><i class="fa-brands fa-whatsapp" style="color:#25D366" aria-hidden="true"></i> Pedido por WhatsApp</h3>
        <p style="color:var(--suave); font-size:.93rem; line-height:1.65; margin:10px 0 16px;">
          Si prefieres hablar con alguien, arma el carrito y te generamos el mensaje con todo el
          detalle. Coordinamos el pago y la entrega por chat.
        </p>
        <a class="btn btn-whatsapp btn-sm"
           href="<?= e(enlace_whatsapp(Ajustes::texto('whatsapp_mensaje', 'Hola ' . $tienda . ', quiero asesoría para mi arreglo'))) ?>"
           target="_blank" rel="noopener">
          <i class="fa-brands fa-whatsapp" aria-hidden="true"></i> Escribir por WhatsApp
        </a>
      </div>
    </div>
  </div>
</section>

<!-- Recientes -->
<?php if ($recientes): ?>
<section class="section" id="recientes" style="background: var(--rose-soft);">
  <div class="container">
    <div class="section-header aparece">
      <span class="section-tag">Novedades</span>
      <h2>Lo último del taller</h2>
    </div>
    <div class="rejilla-productos aparece">
      <?php foreach ($recientes as $p) { require __DIR__ . '/includes/vistas/tarjeta_producto.php'; } ?>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- Envíos -->
<section class="section shipping" id="envios">
  <div class="container">
    <div class="shipping-grid">
      <div class="shipping-text aparece">
        <span class="section-tag">Envíos</span>
        <h2>Llevamos la magia a todo el país</h2>
        <p>Entregamos en las principales ciudades. Empaque seguro para que las flores lleguen igual que salieron del taller.</p>
        <div class="shipping-icons">
          <div class="ship-item"><i class="fa-solid fa-box" aria-hidden="true"></i><span>Empaque protegido</span></div>
          <div class="ship-item"><i class="fa-solid fa-truck-fast" aria-hidden="true"></i><span>Entrega el mismo día</span></div>
          <div class="ship-item"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i><span>Garantía de frescura</span></div>
        </div>
        <?php $ciudades = Ajustes::lista('ciudades_entrega'); if ($ciudades): ?>
          <p style="margin-top:16px; font-size:.9rem; color:var(--suave);">
            <strong>Cobertura:</strong> <?= e(implode(' · ', $ciudades)) ?>
          </p>
        <?php endif; ?>
      </div>
      <div class="shipping-map aparece">
        <div class="map-wrapper">
          <iframe src="https://maps.google.com/maps?q=<?= rawurlencode(Ajustes::texto('direccion', 'Managua Nicaragua')) ?>&t=&z=15&ie=UTF8&iwloc=&output=embed"
                  width="100%" height="280" style="border:0; border-radius:16px;"
                  title="Ubicación de <?= e($tienda) ?>" loading="lazy"
                  referrerpolicy="no-referrer-when-downgrade"></iframe>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- Galería -->
<?php if ($fotos || $videos): ?>
<section class="section galeria" id="galeria">
  <div class="container">
    <div class="section-header aparece">
      <span class="section-tag">Galería</span>
      <h2>Entregas reales</h2>
      <p>Fotos de arreglos que ya están en casa de alguien.</p>
    </div>

    <?php if ($fotos): ?>
      <div class="rejilla-productos aparece" style="grid-template-columns:repeat(auto-fill,minmax(160px,1fr));">
        <?php foreach ($fotos as $foto): ?>
          <figure class="tarjeta-producto" style="cursor:default;">
            <div class="tarjeta-imagen">
              <img src="<?= e(url_imagen((string)$foto['imagen'])) ?>"
                   alt="<?= e((string)($foto['titulo'] ?? 'Entrega de ' . $tienda)) ?>" loading="lazy">
            </div>
            <?php if ($foto['titulo']): ?>
              <figcaption class="tarjeta-cuerpo" style="padding:11px 13px;">
                <span class="tarjeta-categoria"><?= e((string)$foto['titulo']) ?></span>
              </figcaption>
            <?php endif; ?>
          </figure>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($videos): ?>
      <div class="videos-header aparece" style="margin-top:38px;">
        <h3>Nuestro canal</h3>
      </div>
      <div class="rejilla-productos aparece" style="grid-template-columns:repeat(auto-fill,minmax(280px,1fr));">
        <?php foreach ($videos as $v):
            preg_match('#(?:youtu\.be/|v=|embed/|shorts/)([A-Za-z0-9_-]{11})#', (string)$v['enlace_youtube'], $m);
            $idVideo = $m[1] ?? '';
            if ($idVideo === '') { continue; }
        ?>
          <div class="tarjeta">
            <div style="position:relative; aspect-ratio:16/9; border-radius:10px; overflow:hidden;">
              <iframe src="https://www.youtube-nocookie.com/embed/<?= e($idVideo) ?>"
                      title="<?= e((string)$v['titulo']) ?>" loading="lazy" allowfullscreen
                      style="position:absolute; inset:0; width:100%; height:100%; border:0;"></iframe>
            </div>
            <h3 style="font-size:1.02rem; margin-top:12px;"><?= e((string)$v['titulo']) ?></h3>
            <?php if ($v['descripcion']): ?>
              <p style="color:var(--suave); font-size:.87rem; margin-top:4px;"><?= e(recortar((string)$v['descripcion'], 110)) ?></p>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>

<!-- Llamada final -->
<section class="section">
  <div class="container">
    <div class="bloque-cta aparece">
      <h2>¿No sabes cuál elegir?</h2>
      <p>Cuéntanos la ocasión, el presupuesto y a quién va dirigido. Te proponemos dos o tres opciones con foto.</p>
      <div class="bloque-cta-acciones">
        <a class="btn btn-primary" href="<?= e(url('productos.php')) ?>">Ver arreglos</a>
        <a class="btn btn-whatsapp"
           href="<?= e(enlace_whatsapp('Hola ' . $tienda . ', necesito ayuda para elegir un arreglo.')) ?>"
           target="_blank" rel="noopener"><i class="fa-brands fa-whatsapp" aria-hidden="true"></i> Pedir asesoría</a>
      </div>
    </div>
  </div>
</section>

<?php require __DIR__ . '/includes/vistas/pie.php'; ?>
