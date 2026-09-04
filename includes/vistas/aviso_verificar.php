<?php
/**
 * Aviso de correo sin confirmar.
 *
 * Se pinta solo mientras haga falta: en cuanto la cuenta queda confirmada,
 * desaparece sola sin que nadie tenga que quitarlo.
 *
 * No es un adorno. Los avisos de estado del pedido —«tu arreglo va en
 * camino»— salen por correo, y a un correo sin confirmar no se le puede
 * escribir con confianza: puede estar mal escrito o ser de otra persona. Por
 * eso el aviso explica la consecuencia y no solo la tarea.
 *
 * Espera, opcionalmente, $volverAviso con la página a la que regresar tras
 * reenviar el enlace. Si no se indica, vuelve a la actual.
 */

declare(strict_types=1);

$usuarioAviso = Auth::usuario();

if ($usuarioAviso !== null && !Verificacion::verificado($usuarioAviso)):
    $volver = $volverAviso ?? ltrim((string)($_SERVER['REQUEST_URI'] ?? ''), '/');
?>
<div class="caja-aviso error aviso-verificar" role="status">
  <i class="fa-solid fa-envelope-circle-check" aria-hidden="true"></i>
  <div class="aviso-verificar-cuerpo">
    <p class="aviso-verificar-titulo">Te falta confirmar tu correo</p>
    <p>
      Te enviamos un enlace a <strong><?= e((string)$usuarioAviso['email']) ?></strong>.
      Ábrelo para terminar de activar tu cuenta.
      <strong>Mientras no lo confirmes no podremos avisarte por correo del estado
      de tus pedidos</strong> —cuándo se confirma el pago, cuándo sale a entrega—.
      Tu pedido se registra igual y siempre lo puedes seguir desde
      <a href="<?= e(url('cuenta/pedidos.php')) ?>">Mis pedidos</a>.
    </p>
    <form method="post" action="<?= e(url('cuenta/verificar.php')) ?>" data-una-vez
          class="aviso-verificar-acciones">
      <?= campoToken() ?>
      <input type="hidden" name="volver" value="<?= e($volver) ?>">
      <button type="submit" class="btn-enlace-aviso">Enviarme el enlace otra vez</button>
      <span class="aviso-verificar-nota">Si no lo ves, mira en la carpeta de no deseados.</span>
    </form>
  </div>
</div>
<?php endif; ?>
