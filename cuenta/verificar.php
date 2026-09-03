<?php
/**
 * Confirmación del correo desde el enlace que se envía al registrarse.
 * También permite pedir un enlace nuevo si el anterior caducó.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

$token = texto('token', 64, $_GET);
$hecho = null;

// Reenviar el enlace: solo a quien ya entró, y con límite para que no se use
// como forma de mandar correos a terceros.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirToken(false, 'cuenta/verificar.php');
    $usuario = Auth::usuario();

    if (!$usuario) {
        flash('error', 'Entra a tu cuenta para pedir el enlace.');
        redirigir('cuenta/entrar.php');
    }
    if (Verificacion::verificado($usuario)) {
        flash('info', 'Tu correo ya estaba confirmado.');
        redirigir('cuenta/perfil.php');
    }
    if (!limitar($pdo, 'verificar:' . (int)$usuario['id'], 3, 3600)) {
        flash('alerta', 'Ya te enviamos varios enlaces. Revisa tu correo —mira también la '
                      . 'carpeta de no deseados— y espera un rato antes de pedir otro.');
        redirigir('cuenta/perfil.php');
    }

    $enviado = Verificacion::enviar($pdo, $usuario);
    flash($enviado ? 'exito' : 'error', $enviado
        ? 'Te enviamos un enlace a ' . $usuario['email'] . '. Caduca en 48 horas.'
        : 'No pudimos enviar el correo. Escríbenos por WhatsApp y lo confirmamos nosotros.');
    redirigir('cuenta/perfil.php');
}

if ($token !== '') {
    $hecho = Verificacion::confirmar($pdo, $token);
    if ($hecho['ok']) {
        flash('exito', '¡Listo! Tu correo quedó confirmado.');
        redirigir(Auth::autenticado() ? 'cuenta/perfil.php' : 'cuenta/entrar.php');
    }
}

$tituloPagina      = 'Confirmar correo — ' . Ajustes::texto('nombre_tienda', 'Flowers Anto');
$descripcionPagina = 'Confirma tu correo para recibir el estado de tus pedidos.';
$paginaActiva      = '';

require __DIR__ . '/../includes/vistas/cabecera.php';
?>

<div class="container" style="max-width:560px; padding:40px 0 70px;">
  <div class="tarjeta">
    <div class="tarjeta-encabezado">
      <h1 style="font-size:1.4rem;">Confirmar tu correo</h1>
    </div>
    <?php if ($hecho && !$hecho['ok']): ?>
      <div class="caja-aviso error">
        <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
        <span><?= e($hecho['error']) ?></span>
      </div>
    <?php else: ?>
      <p>Abre el enlace que te enviamos por correo para confirmar tu dirección.</p>
    <?php endif; ?>

    <?php if (Auth::autenticado() && !Verificacion::verificado(Auth::usuario())): ?>
      <form method="post" action="<?= e(url('cuenta/verificar.php')) ?>" data-una-vez
            style="margin-top:16px;">
        <?= campoToken() ?>
        <button type="submit" class="btn btn-primary btn-block">Enviarme otro enlace</button>
      </form>
    <?php else: ?>
      <p style="margin-top:16px;">
        <a href="<?= e(url('cuenta/entrar.php')) ?>">Entrar a mi cuenta</a>
      </p>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/../includes/vistas/pie.php'; ?>
