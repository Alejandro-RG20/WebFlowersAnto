<?php
/**
 * «Olvidé mi contraseña».
 *
 * Dos pasos: se pide el correo y se manda un correo con un código de 6
 * dígitos y un enlace; después se escribe el código aquí mismo. Cualquiera de
 * los dos lleva a `cuenta/restablecer.php`.
 *
 * Nada de lo que se ve aquí dice si una cuenta existe:
 *   · tras pedir el código se pasa siempre a la pantalla del código;
 *   · un código malo, caducado o agotado da el mismo mensaje;
 *   · el correo se envía después de responder, así que la respuesta tarda
 *     lo mismo haya cuenta o no.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

if (Auth::autenticado()) {
    redirigir('cuenta/perfil.php');
}

/** Cuánto vale la pantalla del código antes de volver a pedir el correo. */
const VIDA_PASO_CODIGO = 3600;

$conCodigo = CodigoCorreo::disponible($pdo);
$enviado   = false;
$error     = '';

$paso = $_SESSION['recuperar'] ?? null;
if (!is_array($paso) || (int)($paso['hasta'] ?? 0) < time() || !$conCodigo) {
    unset($_SESSION['recuperar']);
    $paso = null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirToken(false, 'cuenta/recuperar.php');
    $accion = opcion('accion', ['enviar', 'codigo', 'otro_correo'], 'enviar');

    if ($accion === 'otro_correo') {
        unset($_SESSION['recuperar']);
        redirigir('cuenta/recuperar.php');
    }

    if ($accion === 'codigo' && $paso !== null) {
        $correo = (string)$paso['correo'];
        $codigo = CodigoCorreo::limpiar(crudo('codigo'));

        if (!limitar($pdo, 'recuperar-codigo-ip:' . ip_cliente(), 20, 900)
            || !limitar($pdo, 'recuperar-codigo:' . $correo, CodigoCorreo::INTENTOS, 900)) {
            $error = 'Demasiados intentos. Pide un código nuevo o espera unos minutos.';
        } elseif ($codigo === '') {
            $error = 'Escribe los 6 dígitos del código.';
        } else {
            $r = Recuperacion::comprobarCodigo($pdo, $correo, $codigo);
            if ($r['estado'] === 'ok') {
                limpiarLimite($pdo, 'recuperar-codigo:' . $correo);
                unset($_SESSION['recuperar']);
                // A partir de aquí, lo mismo que si se hubiera abierto el
                // enlace: la huella del token en la sesión, nunca el token.
                $_SESSION['restablecer'] = ['huella' => $r['huella'], 'hasta' => time() + 900];
                redirigir('cuenta/restablecer.php');
            }
            $error = 'Ese código no es válido o ya caducó. Revisa el último correo que te enviamos '
                   . 'o pide un código nuevo.';
        }
    } elseif ($accion === 'enviar') {
        $correo = correoValido('email');
        if ($correo === '' && $paso !== null) {
            $correo = (string)$paso['correo'];   // «Enviarme otro código»
        }

        if (!limitar($pdo, 'recuperar:' . ip_cliente(), 5, 900)) {
            $error = 'Pediste varios códigos seguidos. Espera unos minutos.';
        } elseif ($correo === '') {
            $error = 'Escribe un correo electrónico válido.';
        } else {
            // Un segundo límite por correo evita usar el formulario para
            // bombardear el buzón de otra persona.
            if (limitar($pdo, 'recuperar-correo:' . $correo, 3, 3600)) {
                $st = $pdo->prepare("SELECT id, nombre, email FROM usuarios WHERE email = ? AND activo = 1");
                $st->execute([$correo]);
                $usuario = $st->fetch();

                if ($usuario) {
                    Recuperacion::emitir($pdo, $usuario, 'cliente', true);
                    Auditoria::registrar($pdo, 'solicitar_reset', 'usuarios', [
                        'recurso_tipo' => 'usuario', 'recurso_id' => (string)$usuario['id'],
                        'descripcion'  => 'Solicitud de restablecimiento de contraseña.',
                    ]);
                }
                // Código nuevo, intentos nuevos. Se limpia exista o no la
                // cuenta, para que las dos se comporten igual.
                limpiarLimite($pdo, 'recuperar-codigo:' . $correo);
            }

            if ($conCodigo) {
                $_SESSION['recuperar'] = ['correo' => $correo, 'hasta' => time() + VIDA_PASO_CODIGO];
                flash('exito', 'Si ese correo tiene una cuenta, ya te enviamos el código.');
                redirigir('cuenta/recuperar.php');
            }
            $enviado = true;
        }
    }
}

$tituloPagina = 'Recuperar contraseña — ' . Ajustes::texto('nombre_tienda', 'Flowers Anto');

require __DIR__ . '/../includes/vistas/cabecera.php';
?>

<div class="container">
  <div class="marco-auth">
    <h1>Recuperar contraseña</h1>
    <p class="subtitulo"><?= $paso !== null
        ? 'Escribe el código que te enviamos por correo.'
        : 'Te enviamos un código para crear una contraseña nueva.' ?></p>

    <div class="tarjeta">
      <?php if ($error !== ''): ?>
        <div class="caja-aviso error" role="alert">
          <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i><span><?= e($error) ?></span>
        </div>
      <?php endif; ?>

      <?php if ($enviado): ?>
        <div class="caja-aviso exito">
          <i class="fa-solid fa-envelope-circle-check" aria-hidden="true"></i>
          <span>Si ese correo tiene una cuenta con nosotros, en unos minutos recibirás el enlace
                para cambiar la contraseña. Revisa también la carpeta de correo no deseado.</span>
        </div>
        <a class="btn btn-primary btn-block" href="<?= e(url('cuenta/entrar.php')) ?>">Volver a iniciar sesión</a>

      <?php elseif ($paso !== null): ?>
        <p class="nota-codigo">
          Lo enviamos a <strong><?= e(Verificacion::correoTapado((string)$paso['correo'])) ?></strong>
          si tiene una cuenta con nosotros. Tarda uno o dos minutos; mira también en no deseados.
        </p>

        <form method="post" action="<?= e(url('cuenta/recuperar.php')) ?>" data-una-vez>
          <?= campoToken() ?>
          <input type="hidden" name="accion" value="codigo">
          <div class="campo">
            <label for="codigo">Código de 6 dígitos</label>
            <input type="text" id="codigo" name="codigo" class="campo-codigo" required autofocus
                   inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7"
                   placeholder="000 000">
          </div>
          <button type="submit" class="btn btn-primary btn-block">Continuar</button>
        </form>

        <div class="enlaces-auth">
          <form method="post" action="<?= e(url('cuenta/recuperar.php')) ?>" data-una-vez>
            <?= campoToken() ?>
            <input type="hidden" name="accion" value="enviar">
            <button type="submit" class="btn-enlace">Enviarme otro código</button>
          </form>
          <form method="post" action="<?= e(url('cuenta/recuperar.php')) ?>">
            <?= campoToken() ?>
            <input type="hidden" name="accion" value="otro_correo">
            <button type="submit" class="btn-enlace">Usar otro correo</button>
          </form>
        </div>

      <?php else: ?>
        <form method="post" action="<?= e(url('cuenta/recuperar.php')) ?>" data-una-vez>
          <?= campoToken() ?>
          <input type="hidden" name="accion" value="enviar">
          <div class="campo">
            <label for="email">Correo de tu cuenta</label>
            <input type="email" id="email" name="email" required autofocus autocomplete="email">
          </div>
          <button type="submit" class="btn btn-primary btn-block">
            <?= $conCodigo ? 'Enviarme el código' : 'Enviarme el enlace' ?></button>
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
