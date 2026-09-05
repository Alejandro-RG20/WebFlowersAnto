<?php
/**
 * Aviso de cookies.
 *
 * No es un cartel decorativo: la decisión cambia lo que el sitio guarda.
 *
 * La web usa una sola cookie propia, la de sesión, que es la que sostiene el
 * carrito y el inicio de sesión: sin ella no hay tienda, así que no se puede
 * rechazar. Lo único opcional es la copia de los favoritos que se guarda en
 * el navegador para recuperarlos si caduca la sesión. Quien rechaza no la
 * tiene, y si ya existía se borra.
 *
 * El texto dice la verdad en las dos configuraciones posibles: si Analytics
 * está apagado, promete que no hay medición de terceros; si está encendido, lo
 * dice y explica que sin aceptar no se guarda nada suyo. Un aviso de cookies
 * que promete lo que no cumple es peor que no tenerlo.
 */

declare(strict_types=1);

$decision = $_COOKIE['fa_cookies'] ?? '';
$yaDecidio = in_array($decision, ['aceptado', 'rechazado'], true);
?>
<div class="aviso-cookies" id="avisoCookies" role="dialog" aria-modal="false"
     aria-labelledby="cookiesTitulo" aria-describedby="cookiesTexto"
     data-decision="<?= e($yaDecidio ? $decision : '') ?>"<?= $yaDecidio ? ' hidden' : '' ?>>
  <div class="ac-caja">
    <div class="ac-texto">
      <p class="ac-titulo" id="cookiesTitulo">Usamos cookies</p>
      <p id="cookiesTexto">
        Una cookie propia mantiene tu carrito y tu sesión: sin ella la tienda no funciona.
        Aparte, podemos guardar tus favoritos en este navegador para no perderlos.
        <?php if (Analitica::configurado()): ?>
          Si aceptas, usamos también Google Analytics para saber qué se visita y mejorar la
          tienda; mientras no aceptes, no guardamos nada tuyo para eso.
        <?php else: ?>
          No usamos publicidad ni rastreadores de otras empresas.
        <?php endif; ?>
        <a href="<?= e(url('legal.php?doc=privacidad')) ?>">Más detalle en la política de privacidad</a>.
      </p>
    </div>
    <div class="ac-botones">
      <button type="button" class="btn btn-outline-dark" data-cookies="rechazado">Solo lo necesario</button>
      <button type="button" class="btn btn-primary" data-cookies="aceptado">Aceptar todo</button>
    </div>
  </div>
</div>
