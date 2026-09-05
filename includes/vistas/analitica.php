<?php
/**
 * Etiqueta de Google Analytics 4, con modo de consentimiento.
 *
 * Va lo más arriba posible del <head> porque el consentimiento por defecto
 * tiene que quedar fijado ANTES de que se cargue gtag.js: si llegara después,
 * la primera medición saldría con los permisos abiertos y ya no habría forma
 * de recogerla.
 *
 * El orden aquí es la parte que importa:
 *   1. `consent default` — todo denegado, incluso antes de saber quién visita.
 *   2. La decisión ya guardada en la cookie `fa_cookies`, si existe.
 *   3. gtag.js.
 *   4. `config` con la página, y los eventos de esta pantalla.
 *
 * Cuando la persona pulsa «Aceptar todo», es `assets/js/analitica.js` quien
 * manda el `consent update`; no hace falta recargar.
 */

declare(strict_types=1);

if (!defined('RAIZ') || !Analitica::activo()) {
    return;
}

$gaId      = Analitica::id();
$gaAcepto  = ($_COOKIE['fa_cookies'] ?? '') === 'aceptado';
$gaEventos = Analitica::cola();

$gaConfig = ['currency' => Analitica::moneda()];
if (Analitica::depurar()) {
    $gaConfig['debug_mode'] = true;
}
?>
<script>
window.dataLayer = window.dataLayer || [];
function gtag(){dataLayer.push(arguments);}
gtag('consent', 'default', {
  ad_storage: 'denied', ad_user_data: 'denied', ad_personalization: 'denied',
  analytics_storage: 'denied', functionality_storage: 'denied',
  personalization_storage: 'denied', security_storage: 'granted',
  wait_for_update: 500
});
<?php if ($gaAcepto): ?>
gtag('consent', 'update', {
  ad_storage: 'granted', ad_user_data: 'granted', ad_personalization: 'granted',
  analytics_storage: 'granted', functionality_storage: 'granted',
  personalization_storage: 'granted'
});
<?php endif; ?>
gtag('js', new Date());
gtag('config', <?= json_encode($gaId, JSON_UNESCAPED_SLASHES) ?>, <?= json_encode($gaConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>);
<?php foreach ($gaEventos as $ev): ?>
gtag('event', <?= json_encode($ev['nombre'], JSON_UNESCAPED_SLASHES) ?>, <?= json_encode($ev['datos'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>);
<?php endforeach; ?>
</script>
<script async src="https://www.googletagmanager.com/gtag/js?id=<?= e(rawurlencode($gaId)) ?>"></script>
