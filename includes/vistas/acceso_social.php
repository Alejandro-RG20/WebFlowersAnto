<?php
/**
 * Botones «Continuar con Google / Facebook» de entrar.php y registrar.php.
 * Cada uno aparece solo si su proveedor está configurado (y Facebook, además,
 * con la migración 023 aplicada). Llevan `?volver=` si la página lo tenía.
 */
$conGoogle   = class_exists('Google') && Google::configurado();
$conFacebook = class_exists('Facebook') && Facebook::disponible($pdo);
$volverSocial = texto('volver', 120, $_GET);
$sufijoSocial = $volverSocial !== '' ? '?' . http_build_query(['volver' => $volverSocial]) : '';
if (!$conGoogle && !$conFacebook) {
    return;
}
?>
<div class="separador-o">o</div>
<div class="acceso-social">
  <?php if ($conGoogle): ?>
    <a class="btn-google" href="<?= e(url('cuenta/google.php') . $sufijoSocial) ?>">
      <svg viewBox="0 0 24 24" aria-hidden="true">
        <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 0 1-2.2 3.32v2.76h3.57c2.08-1.92 3.27-4.74 3.27-8.09z"/>
        <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.76c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84A11 11 0 0 0 12 23z"/>
        <path fill="#FBBC05" d="M5.84 14.11a6.6 6.6 0 0 1 0-4.22V7.05H2.18a11 11 0 0 0 0 9.9l3.66-2.84z"/>
        <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.05l3.66 2.84C6.71 7.31 9.14 5.38 12 5.38z"/>
      </svg>
      Continuar con Google
    </a>
  <?php endif; ?>
  <?php if ($conFacebook): ?>
    <a class="btn-google btn-facebook" href="<?= e(url('cuenta/facebook.php') . $sufijoSocial) ?>">
      <svg viewBox="0 0 24 24" aria-hidden="true">
        <path fill="currentColor" d="M24 12.07C24 5.4 18.63 0 12 0S0 5.4 0 12.07C0 18.1 4.39 23.1 10.13 24v-8.44H7.08v-3.49h3.05V9.41c0-3.02 1.79-4.69 4.53-4.69 1.31 0 2.68.24 2.68.24v2.97h-1.51c-1.49 0-1.96.93-1.96 1.89v2.26h3.33l-.53 3.49h-2.8V24C19.61 23.1 24 18.1 24 12.07z"/>
      </svg>
      Continuar con Facebook
    </a>
  <?php endif; ?>
</div>
