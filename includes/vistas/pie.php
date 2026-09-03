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
          <span class="logo-icon"><img src="<?= e(url_imagen(Ajustes::texto('logo_url', 'images/logoanto.jpeg'))) ?>" alt="" width="60" height="60" loading="lazy"></span>
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
        <h4>Explorar</h4>
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
        <h4>Contáctanos</h4>
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
            <img src="<?= e(url_imagen($logo)) ?>" alt="<?= e(Ajustes::texto('dev_nombre')) ?>" loading="lazy">
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

<a href="<?= e($wa) ?>" target="_blank" rel="noopener" class="whatsapp-float" aria-label="Chatea por WhatsApp">
  <i class="fa-brands fa-whatsapp" aria-hidden="true"></i>
</a>

<?php if (!empty($temaTemporada['estilo'])): ?>
<!-- Formas de la temporada. Solo van las que usa el estilo vigente. -->
<svg width="0" height="0" aria-hidden="true" focusable="false"
     style="position:absolute"><defs><?= Temporadas::sprite($temaTemporada['estilo']) ?></defs></svg>
<script src="<?= e(url_recurso('assets/js/temporada.js')) ?>" defer></script>
<?php endif; ?>

<?php require __DIR__ . '/cookies.php'; ?>

<script src="<?= e(url_recurso('assets/js/app.js')) ?>" defer></script>
<?php if (!empty($jsExtra)): foreach ((array)$jsExtra as $script): ?>
<script src="<?= e(url_recurso($script)) ?>" defer></script>
<?php endforeach; ?>
<?php endif; ?>
</body>
</html>
