</main>

<?php
/**
 * Pie común: contacto, enlaces, créditos del desarrollador y scripts.
 * Los créditos salen de la configuración; no hay ningún dato del
 * desarrollador escrito en el código.
 */
$tienda   = Ajustes::texto('nombre_tienda', 'Flowers Anto');
$wa       = enlace_whatsapp(Ajustes::texto('whatsapp_mensaje', 'Hola ' . $tienda));
$redes    = [
    'instagram' => ['url' => Ajustes::texto('instagram_url'), 'icono' => 'fa-brands fa-instagram',  'nombre' => 'Instagram'],
    'facebook'  => ['url' => Ajustes::texto('facebook_url'),  'icono' => 'fa-brands fa-facebook-f', 'nombre' => 'Facebook'],
    'tiktok'    => ['url' => Ajustes::texto('tiktok_url'),    'icono' => 'fa-brands fa-tiktok',     'nombre' => 'TikTok'],
];
$devActivo = Ajustes::activo('dev_activo', true) && Ajustes::texto('dev_nombre') !== '';
$devUrl    = Ajustes::texto('dev_url');
?>
<footer class="footer" id="contacto">
  <div class="container">
    <div class="footer-grid">
      <div class="footer-brand">
        <div class="logo">
          <span class="logo-icon"><img src="<?= e(url_imagen(Ajustes::texto('logo_url', 'images/logoanto.jpeg'), 'images/placeholders/logo.svg', 160)) ?>" alt="" width="60" height="60" loading="lazy"></span>
          <span class="logo-text"><?= e($tienda) ?></span>
        </div>
        <p><?= e(Ajustes::texto('eslogan', 'Convertimos tus sentimientos en flores.')) ?></p>
        <div class="social-links">
          <?php foreach ($redes as $red): if ($red['url'] === '') continue; ?>
            <a href="<?= e($red['url']) ?>" target="_blank" rel="noopener" class="social-btn"
               aria-label="<?= e($red['nombre']) ?>"><i class="<?= e($red['icono']) ?>" aria-hidden="true"></i></a>
          <?php endforeach; ?>
          <a href="<?= e($wa) ?>" target="_blank" rel="noopener" class="social-btn whatsapp-btn" aria-label="WhatsApp">
            <i class="fa-brands fa-whatsapp" aria-hidden="true"></i></a>
        </div>
      </div>

      <div class="footer-links">
        <h3>Explorar</h3>
        <ul>
          <li><a href="<?= e(url()) ?>">Inicio</a></li>
          <li><a href="<?= e(url('productos.php')) ?>">Arreglos</a></li>
          <li><a href="<?= e(url('favoritos.php')) ?>">Mis favoritos</a></li>
          <li><a href="<?= e(url('carrito.php')) ?>">Carrito</a></li>
          <li><a href="<?= e(url('seguimiento.php')) ?>">Seguir mi pedido</a></li>
          <li><a href="<?= e(url('cuenta/entrar.php')) ?>">Mi cuenta</a></li>
        </ul>
      </div>

      <div class="footer-contact">
        <h3>Contáctanos</h3>
        <?php if (($tel = Ajustes::texto('telefono')) !== ''): ?>
          <p><i class="fa-solid fa-phone" aria-hidden="true"></i>
             <a href="tel:<?= e(preg_replace('/\s+/', '', $tel) ?? '') ?>"><?= e($tel) ?></a></p>
        <?php endif; ?>
        <?php if (($mail = Ajustes::texto('email_contacto')) !== ''): ?>
          <p><i class="fa-solid fa-envelope" aria-hidden="true"></i>
             <a href="mailto:<?= e($mail) ?>"><?= e($mail) ?></a></p>
        <?php endif; ?>
        <?php if (($dir = Ajustes::texto('direccion')) !== ''): ?>
          <p><i class="fa-solid fa-location-dot" aria-hidden="true"></i> <span><?= e($dir) ?></span></p>
        <?php endif; ?>
        <?php if (($hor = Ajustes::texto('horario')) !== ''): ?>
          <p><i class="fa-solid fa-clock" aria-hidden="true"></i> <span><?= e($hor) ?></span></p>
        <?php endif; ?>
        <a href="<?= e($wa) ?>" target="_blank" rel="noopener" class="btn-footer-whatsapp">
          <i class="fa-brands fa-whatsapp" aria-hidden="true"></i> Chatea con nosotros</a>
      </div>
    </div>

    <div class="footer-bottom">
      <p>© <?= date('Y') ?> <?= e($tienda) ?>. Todos los derechos reservados.
        <a href="<?= e(url('legal.php?doc=privacidad')) ?>">Privacidad</a>
        <a href="<?= e(url('legal.php?doc=terminos')) ?>">Términos</a>
        <a href="<?= e(url('legal.php?doc=devoluciones')) ?>">Devoluciones</a>
        <button type="button" class="enlace-cookies" data-abrir-cookies>Cookies</button></p>

      <?php if ($devActivo): ?>
        <?php
          $etiqueta = $devUrl !== '' ? 'a' : 'div';
          $atributos = $devUrl !== ''
              ? ' href="' . e($devUrl) . '" target="_blank" rel="noopener noreferrer"'
              : '';
        ?>
        <?php
          // Una ruta de archivo que ya no existe pintaría un icono roto en el
          // pie de todas las páginas: sin logo se cae al crédito de texto.
          $logo = Ajustes::texto('dev_logo');
          if (!imagen_disponible($logo)) {
              $logo = '';
          }
        ?>
        <<?= $etiqueta ?> class="creditos-dev<?= $logo !== '' ? ' con-logo' : '' ?>"<?= $atributos ?>>
          <span class="creditos-dev-etiqueta">Desarrollado por</span>
          <?php if ($logo !== ''): ?>
            <?php // El logo ya lleva el nombre dentro, así que se muestra entero
                  // y no se repite el texto al lado. ?>
            <?php $medLogo = imagen_medidas($logo); ?>
            <img src="<?= e(url_imagen($logo, 'images/placeholders/logo.svg', 320)) ?>"
                 alt="<?= e(Ajustes::texto('dev_nombre')) ?>" loading="lazy" decoding="async"
                 <?php if ($medLogo): ?>width="<?= $medLogo['ancho'] ?>" height="<?= $medLogo['alto'] ?>"<?php endif; ?>>
          <?php else: ?>
            <span class="creditos-dev-inicial" aria-hidden="true"><?= e(mb_substr(Ajustes::texto('dev_nombre'), 0, 1)) ?></span>
            <span class="creditos-dev-texto">
              <strong><?= e(Ajustes::texto('dev_nombre')) ?></strong>
              <?php if (($descDev = Ajustes::texto('dev_descripcion')) !== ''): ?>
                <small><?= e($descDev) ?></small>
              <?php endif; ?>
            </span>
          <?php endif; ?>
          <?php if ($devUrl !== ''): ?>
            <i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i>
          <?php endif; ?>
        </<?= $etiqueta ?>>
      <?php endif; ?>
    </div>
  </div>
</footer>

<?php // El icono va en SVG dentro del HTML y no como tipografía de iconos:
      // es el elemento que siempre está a la vista, y así se dibuja aunque la
      // hoja de Font Awesome tarde o no llegue. También se ve nítido en
      // cualquier pantalla y no arrastra una descarga de fuente. ?>
<a href="<?= e($wa) ?>" target="_blank" rel="noopener" class="whatsapp-float" aria-label="Chatea por WhatsApp">
  <svg viewBox="0 0 32 32" width="30" height="30" aria-hidden="true" focusable="false">
    <path fill="currentColor" d="M16.04 3.2c-7.06 0-12.8 5.74-12.8 12.8 0 2.26.6 4.47 1.73 6.42L3.2 28.8l6.55-1.72a12.74 12.74 0 0 0 6.29 1.6h.01c7.05 0 12.79-5.73 12.79-12.79 0-3.42-1.33-6.63-3.75-9.05a12.7 12.7 0 0 0-9.05-3.75Zm0 23.31h-.01c-1.9 0-3.77-.51-5.4-1.48l-.39-.23-4.02 1.06 1.07-3.92-.25-.4a10.6 10.6 0 0 1-1.63-5.67c0-5.87 4.78-10.64 10.65-10.64 2.84 0 5.51 1.11 7.52 3.12a10.57 10.57 0 0 1 3.11 7.53c0 5.87-4.78 10.63-10.65 10.63Zm5.84-7.97c-.32-.16-1.89-.93-2.19-1.04-.29-.11-.5-.16-.71.16-.21.32-.82 1.04-1.01 1.25-.18.21-.37.24-.69.08-.32-.16-1.35-.5-2.57-1.59-.95-.85-1.59-1.89-1.78-2.21-.18-.32-.02-.5.14-.66.15-.14.32-.37.48-.56.16-.19.21-.32.32-.53.11-.21.05-.4-.03-.56-.08-.16-.71-1.73-.98-2.36-.26-.62-.52-.54-.71-.55l-.61-.01c-.21 0-.56.08-.85.4-.29.32-1.11 1.09-1.11 2.65s1.14 3.08 1.3 3.29c.16.21 2.24 3.42 5.43 4.8.76.33 1.35.52 1.81.67.76.24 1.45.21 2 .13.61-.09 1.89-.77 2.15-1.52.27-.75.27-1.38.19-1.52-.08-.13-.29-.21-.61-.37Z"/>
  </svg>
</a>

<?php if (!empty($temaTemporada['estilo'])): ?>
<!-- Formas de la temporada. Solo van las que usa el estilo vigente. -->
<svg width="0" height="0" aria-hidden="true" focusable="false"
     style="position:absolute"><defs><?= Temporadas::sprite($temaTemporada['estilo']) ?></defs></svg>
<script src="<?= e(url_recurso('assets/js/temporada.js')) ?>" defer></script>
<?php endif; ?>

<?php
// Capa de espera. Nace oculta y solo la levanta el JavaScript cuando una
// acción tarda; el estilo y el comportamiento están en app.css y app.js.
//
// La flor va en SVG dentro del HTML, como el botón de WhatsApp: es lo único
// que el cliente mira mientras aguanta la espera, así que no puede depender
// de que la tipografía de iconos llegue a tiempo.
?>
<div class="capa-espera" id="capaEspera" hidden role="status" aria-live="polite">
  <svg class="espera-flor" viewBox="0 0 100 100" aria-hidden="true" focusable="false">
    <g class="espera-flor-petalos">
      <ellipse class="espera-petalo" cx="50" cy="29" rx="12.5" ry="19"/>
      <ellipse class="espera-petalo" cx="50" cy="29" rx="12.5" ry="19" transform="rotate(72 50 50)"/>
      <ellipse class="espera-petalo" cx="50" cy="29" rx="12.5" ry="19" transform="rotate(144 50 50)"/>
      <ellipse class="espera-petalo" cx="50" cy="29" rx="12.5" ry="19" transform="rotate(216 50 50)"/>
      <ellipse class="espera-petalo" cx="50" cy="29" rx="12.5" ry="19" transform="rotate(288 50 50)"/>
    </g>
    <circle class="espera-flor-centro" cx="50" cy="50" r="10"/>
  </svg>
  <p class="capa-espera-texto" id="capaEsperaTexto">Un momento…</p>
  <p class="capa-espera-nota">Estamos procesando tu solicitud. No cierres ni recargues esta página.</p>
</div>

<?php require __DIR__ . '/cookies.php'; ?>

<script src="<?= e(url_recurso('assets/js/app.js')) ?>" defer></script>
<?php if (Analitica::activo()): ?>
<script src="<?= e(url_recurso('assets/js/analitica.js')) ?>" defer></script>
<?php endif; ?>
<?php if (!empty($jsExtra)): foreach ((array)$jsExtra as $script): ?>
<script src="<?= e(url_recurso($script)) ?>" defer></script>
<?php endforeach; ?>
<?php endif; ?>
</body>
</html>
