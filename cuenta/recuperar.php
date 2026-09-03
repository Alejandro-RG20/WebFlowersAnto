<?php
/**
 * «Olvidé mi contraseña».
 *
 * La respuesta es siempre la misma, exista o no la cuenta: si se dijera
 * «ese correo no está registrado», cualquiera podría averiguar quién tiene
 * cuenta en la tienda probando direcciones.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

if (Auth::autenticado()) {
    redirigir('cuenta/perfil.php');
}

$enviado = false;
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirToken(false, 'cuenta/recuperar.php');

    $correo = correoValido('email');

    if (!limitar($pdo, 'recuperar:' . ip_cliente(), 5, 900)) {
        $error = 'Pediste varios enlaces seguidos. Espera unos minutos.';
    } elseif ($correo === '') {
        $error = 'Escribe un correo electrónico válido.';
    } else {
        // Un segundo límite por correo evita usar el formulario para
        // bombardear el buzón de otra persona.
        if (limitar($pdo, 'recuperar-correo:' . $correo, 3, 3600)) {
            $st = $pdo->prepare("SELECT id, nombre FROM usuarios WHERE email = ? AND activo = 1");
            $st->execute([$correo]);
            $usuario = $st->fetch();

            if ($usuario) {
                // Los enlaces anteriores dejan de servir en cuanto se pide uno nuevo.
                // Solo los de contraseña: un enlace de confirmación de correo
                // pendiente no tiene por qué caducar porque alguien pida
                // recuperar su clave.
                $pdo->prepare("UPDATE password_resets SET usado_en = NOW()
                                WHERE usuario_id = ? AND tipo = 'password' AND usado_en IS NULL")
                    ->execute([$usuario['id']]);

                $token = bin2hex(random_bytes(32));
                $pdo->prepare(
                    "INSERT INTO password_resets (usuario_id, token_hash, expira_en, ip, tipo)
                     VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 60 MINUTE), ?, 'password')"
                )->execute([$usuario['id'], hash('sha256', $token), ip_cliente()]);

                $enlace = url_absoluta('cuenta/restablecer.php?token=' . $token);
                Correo::enviar(
                    $correo,
                    'Restablecer tu contraseña — ' . Ajustes::texto('nombre_tienda', 'Flowers Anto'),
                    Correo::plantilla(
                        'Restablecer tu contraseña',
                        '<p>Hola ' . e((string)$usuario['nombre']) . ', recibimos una solicitud para '
                        . 'cambiar la contraseña de tu cuenta.</p>'
                        . '<p>El enlace funciona <strong>una sola vez</strong> y caduca en 60 minutos.</p>'
                        . '<p style="font-size:13px;color:#8A7A7D;">Si no fuiste tú, ignora este correo: '
                        . 'tu contraseña sigue siendo la misma.</p>',
                        ['url' => $enlace, 'texto' => 'Cambiar mi contraseña']
                    )
                );

                Auditoria::registrar($pdo, 'solicitar_reset', 'usuarios', [
                    'recurso_tipo' => 'usuario', 'recurso_id' => (string)$usuario['id'],
                    'descripcion'  => 'Solicitud de restablecimiento de contraseña.',
                ]);
            }
        }
        $enviado = true;
    }
}

$tituloPagina = 'Recuperar contraseña — ' . Ajustes::texto('nombre_tienda', 'Flowers Anto');

require __DIR__ . '/../includes/vistas/cabecera.php';
?>

<div class="container">
  <div class="marco-auth">
    <h1>Recuperar contraseña</h1>
    <p class="subtitulo">Te enviamos un enlace para crear una nueva.</p>

    <div class="tarjeta">
      <?php if ($enviado): ?>
        <div class="caja-aviso exito">
          <i class="fa-solid fa-envelope-circle-check" aria-hidden="true"></i>
          <span>Si ese correo tiene una cuenta con nosotros, en unos minutos recibirás el enlace
                para cambiar la contraseña. Revisa también la carpeta de correo no deseado.</span>
        </div>
        <a class="btn btn-primary btn-block" href="<?= e(url('cuenta/entrar.php')) ?>">Volver a iniciar sesión</a>
      <?php else: ?>
        <?php if ($error !== ''): ?>
          <div class="caja-aviso error" role="alert">
            <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i><span><?= e($error) ?></span>
          </div>
        <?php endif; ?>

        <form method="post" action="<?= e(url('cuenta/recuperar.php')) ?>" data-una-vez>
          <?= campoToken() ?>
          <div class="campo">
            <label for="email">Correo de tu cuenta</label>
            <input type="email" id="email" name="email" required autofocus autocomplete="email">
          </div>
          <button type="submit" class="btn btn-primary btn-block">Enviarme el enlace</button>
        </form>

        <div class="enlaces-auth">
          <a href="<?= e(url('cuenta/entrar.php')) ?>">← Volver</a>
          <a href="<?= e(url('cuenta/registrar.php')) ?>">Crear una cuenta</a>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../includes/vistas/pie.php'; ?>
