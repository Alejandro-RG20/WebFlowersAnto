<?php
/**
 * Cambio de contraseña con el token del correo.
 *
 * El token se guarda hasheado: quien lea la base de datos no puede usarlo.
 * Se invalida al usarlo y caduca a los 60 minutos.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/lib/cuentas_externas.php';

/*
 * El token no se queda en la barra de direcciones.
 *
 * Este enlace sí es peligroso si se filtra: durante una hora permite cambiar
 * la contraseña de la cuenta. La página carga fuentes y scripts de terceros,
 * y Analytics registra la dirección completa de cada vista; con el token en
 * la URL, cualquiera de ellos lo habría recibido. Al llegar, se guarda en la
 * sesión su huella (el SHA-256, nunca el token) y se redirige a esta misma
 * página limpia.
 *
 * El enlace NO se gasta al abrirlo, solo al guardar la contraseña nueva. Los
 * filtros de correo abren los enlaces para revisarlos; si abrirlo lo gastara,
 * la persona se encontraría un enlace muerto.
 */
const VIDA_EN_SESION = 3600;

$tokenUrl = texto('token', 64, $_GET);
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $tokenUrl !== '') {
    $_SESSION['restablecer'] = [
        'huella' => (strlen($tokenUrl) === 64 && ctype_xdigit($tokenUrl)) ? hash('sha256', $tokenUrl) : '',
        'hasta'  => time() + VIDA_EN_SESION,
    ];
    redirigir('cuenta/restablecer.php');
}

$error = '';
$listo = false;

/** Devuelve la solicitud vigente asociada a la huella del token, o null. */
function solicitudValida(PDO $pdo, string $huella): ?array
{
    if (strlen($huella) !== 64 || !ctype_xdigit($huella)) {
        return null;
    }
    $st = $pdo->prepare(
        "SELECT pr.*, u.email, u.nombre
           FROM password_resets pr
           JOIN usuarios u ON u.id = pr.usuario_id
          WHERE pr.token_hash = ? AND pr.tipo = 'password'
            AND pr.usado_en IS NULL AND pr.expira_en > NOW()
            AND u.activo = 1
          LIMIT 1"
    );
    $st->execute([$huella]);
    return $st->fetch() ?: null;
}

$guardado  = $_SESSION['restablecer'] ?? null;
$huella    = (is_array($guardado) && (int)($guardado['hasta'] ?? 0) >= time()) ? (string)$guardado['huella'] : '';
$solicitud = $huella !== '' ? solicitudValida($pdo, $huella) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $solicitud) {
    exigirToken(false, 'cuenta/recuperar.php');

    $password  = crudo('password');
    $problema  = revisarPassword($password, crudo('password_confirmar'));

    if ($problema !== '') {
        $error = $problema;
    } else {
        $pdo->beginTransaction();
        try {
            // Primero se gasta el enlace, y solo si sigue libre y en plazo.
            // Si dos envíos llegan a la vez, el segundo no toca la fila y no
            // cambia nada: el «una sola vez» lo decide la base, no una
            // consulta hecha un momento antes.
            $gasta = $pdo->prepare(
                "UPDATE password_resets SET usado_en = NOW()
                  WHERE id = ? AND usado_en IS NULL AND expira_en > NOW()"
            );
            $gasta->execute([$solicitud['id']]);
            if ($gasta->rowCount() !== 1) {
                throw new DomainException('usado');
            }

            // Si el correo nunca se había confirmado, quien tenga Google o
            // Facebook conectados a esta cuenta no demostró ser el dueño del
            // correo (pudo crearla con una dirección ajena): se desconectan.
            $sinConfirmar = $pdo->prepare("SELECT email_verificado_en IS NULL FROM usuarios WHERE id = ?");
            $sinConfirmar->execute([$solicitud['usuario_id']]);
            if ((int)$sinConfirmar->fetchColumn() === 1) {
                CuentasExternas::quitarSinConfirmar($pdo, (int)$solicitud['usuario_id']);
            }

            // Cambiar la contraseña con un enlace que llegó al correo
            // demuestra también que el correo es de esta persona.
            $pdo->prepare(
                "UPDATE usuarios SET password_hash = ?, intentos_fallidos = 0, bloqueado_hasta = NULL,
                        email_verificado_en = COALESCE(email_verificado_en, NOW())
                  WHERE id = ?"
            )->execute([password_hash($password, PASSWORD_DEFAULT), $solicitud['usuario_id']]);

            $pdo->commit();
        } catch (DomainException) {
            $pdo->rollBack();
            $solicitud = null;
        } catch (Throwable $ex) {
            $pdo->rollBack();
            error_log('Flowers Anto — restablecer contraseña: ' . $ex->getMessage());
            $error = 'No pudimos guardar la contraseña nueva. Inténtalo otra vez.';
        }

        if ($solicitud && $error === '') {
            unset($_SESSION['restablecer']);

            // Quien tuviera abierta la cuenta —incluido quien motivó el
            // cambio, si la clave se había filtrado— queda fuera.
            Auth::cerrarOtrasSesiones($pdo, (int)$solicitud['usuario_id']);

            Auditoria::registrar($pdo, 'cambio_password', 'usuarios', [
                'recurso_tipo' => 'usuario', 'recurso_id' => (string)$solicitud['usuario_id'],
                'descripcion'  => 'Contraseña restablecida con el enlace enviado por correo.',
            ]);

            Correo::enviar(
                (string)$solicitud['email'],
                'Tu contraseña se cambió',
                Correo::plantilla(
                    'Tu contraseña se cambió',
                    '<p>Hola ' . e((string)$solicitud['nombre']) . ', acabamos de cambiar la contraseña '
                    . 'de tu cuenta y cerramos las sesiones que estuvieran abiertas.</p>'
                    . '<p>Si no fuiste tú, escríbenos cuanto antes.</p>',
                    ['url' => url_absoluta('cuenta/entrar.php'), 'texto' => 'Iniciar sesión']
                )
            );

            $listo = true;
        }
    }
}

$tituloPagina = 'Nueva contraseña — ' . Ajustes::texto('nombre_tienda', 'Flowers Anto');

require __DIR__ . '/../includes/vistas/cabecera.php';
?>

<div class="container">
  <div class="marco-auth">
    <h1>Nueva contraseña</h1>

    <div class="tarjeta">
      <?php if ($listo): ?>
        <div class="caja-aviso exito">
          <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
          <span>Listo. Tu contraseña quedó cambiada.</span>
        </div>
        <a class="btn btn-primary btn-block" href="<?= e(url('cuenta/entrar.php')) ?>">Iniciar sesión</a>

      <?php elseif (!$solicitud): ?>
        <div class="caja-aviso error">
          <i class="fa-solid fa-link-slash" aria-hidden="true"></i>
          <span>Este enlace ya se usó o caducó. Pide uno nuevo, tarda un segundo.</span>
        </div>
        <a class="btn btn-primary btn-block" href="<?= e(url('cuenta/recuperar.php')) ?>">Pedir un enlace nuevo</a>

      <?php else: ?>
        <p class="subtitulo" style="margin-bottom:20px;">
          Hola <?= e((string)$solicitud['nombre']) ?>, elige tu contraseña nueva.
        </p>

        <?php if ($error !== ''): ?>
          <div class="caja-aviso error" role="alert">
            <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i><span><?= e($error) ?></span>
          </div>
        <?php endif; ?>

        <form method="post" action="<?= e(url('cuenta/restablecer.php')) ?>" data-una-vez>
          <?= campoToken() ?>
          <div class="campo">
            <label for="password">Contraseña nueva</label>
            <input type="password" id="password" name="password" required autofocus
                   autocomplete="new-password" minlength="8">
            <div class="medidor-password" id="medidorPassword" aria-hidden="true"><span></span></div>
            <p class="ayuda">Mínimo 8 caracteres, combinando letras y números.</p>
          </div>
          <div class="campo">
            <label for="password_confirmar">Repite la contraseña</label>
            <input type="password" id="password_confirmar" name="password_confirmar" required
                   autocomplete="new-password" minlength="8">
          </div>
          <button type="submit" class="btn btn-primary btn-block">Guardar contraseña</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../includes/vistas/pie.php'; ?>
