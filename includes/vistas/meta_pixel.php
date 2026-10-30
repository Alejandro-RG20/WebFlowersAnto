<?php
/**
 * Píxel de Meta (Facebook e Instagram), con el permiso de las cookies.
 *
 * Va en el <head>, justo después de Analytics, por la misma razón que él: los
 * eventos de esta página tienen que quedar en la cola antes de que nada
 * pueda dispararse.
 *
 * Lo que se pinta aquí:
 *   1. La cola de `fbq`, que acepta órdenes aunque el script de Meta todavía
 *      no esté. Es la misma que trae el código oficial de Meta.
 *   2. `init` con los datos del cliente ya resumidos con SHA-256, si se sabe
 *      quién es (cuenta abierta o pedido recién hecho): nunca en claro.
 *   3. PageView y los eventos de esta pantalla, cada uno con el MISMO
 *      identificador con el que lo manda el servidor, para que Meta cuente
 *      uno y no dos.
 *   4. El script de Meta, SOLO si ya se aceptaron las cookies. Si no, la
 *      cola espera: al pulsar «Aceptar todo», `assets/js/meta.js` lo carga y
 *      todo lo apuntado sale en ese momento, sin recargar. Mientras nadie
 *      acepte, el navegador no habla con Meta en absoluto.
 *
 * No lleva el <noscript> con la imagen de 1×1 del código de Meta: esa imagen
 * se descargaría siempre, haya permiso o no, y saltarse el aviso de cookies
 * no es una opción.
 */

declare(strict_types=1);

if (!defined('RAIZ') || !MetaPixel::activo()) {
    return;
}

$metaId      = MetaPixel::id();
$metaAcepto  = MetaPixel::consentido();
$metaEventos = MetaPixel::paraPagina();
$metaCliente = MetaPixel::coincidencias();
$metaJson    = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP;
?>
<script>
!function(f,n){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};
if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[]}(window);
window.faCargarMeta = function () {
  if (window.faMetaCargado) { return; }
  window.faMetaCargado = true;
  var s = document.createElement('script');
  s.async = true;
  s.src = 'https://connect.facebook.net/en_US/fbevents.js';
  document.head.appendChild(s);
};
fbq('init', <?= json_encode($metaId) ?><?= $metaCliente ? ', ' . json_encode($metaCliente, $metaJson) : '' ?>);
fbq('track', 'PageView');
<?php foreach ($metaEventos as $ev): ?>
fbq('track', <?= json_encode($ev['evento']) ?>, <?= json_encode((object)$ev['datos'], $metaJson) ?>, {eventID: <?= json_encode($ev['id'], $metaJson) ?>});
<?php endforeach; ?>
<?php if ($metaAcepto): ?>
faCargarMeta();
<?php endif; ?>
</script>
