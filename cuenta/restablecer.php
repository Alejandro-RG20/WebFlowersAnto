<?php
/**
 * Cambio de contraseña con el token del correo.
 *
 * El token se guarda hasheado: quien lea la base de datos no puede usarlo.
 * Se invalida al usarlo y caduca a los 60 minutos.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

$token = texto('token', 64, $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET);
$error = '';
$listo = false;

/** Devuelve la solicitud vigente asociada al token, o null. */
function solicitudValida(PDO $pdo, string $token): ?array
{
    if (strlen($token) !== 64 || !ctype_xdigit($token)) {
        return null;
    }
    $st = $pdo->prepare(
        "SELECT pr.*, u.email, u.nombre
           FROM password_resets pr
           JOIN usuarios u ON u.id = pr.usuario_id
          WHERE pr.token_hash = ? AND pr.usado_en IS NULL AND pr.expira_en > NOW()
            AND u.activo = 1
          LIMIT 1"
    );
    $st->execute([hash('sha256', $token)]);
    return $st->fetch() ?: null;
}

$solicitud = $token !== '' ? solicitudValida($pdo, $token) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $solicitud) {
    exigirToken(false, 'cuenta/recuperar.php');

    $password  = crudo('password');
    $problema  = revisarPassword($password, crudo('password_confirmar'));

    if ($problema !== '') {
        $error = $problema;
    } else {
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE usuarios SET password_hash = ?, intentos_fallidos = 0, bloqueado_hasta = NULL
                            WHERE id = ?")
                ->execute([password_hash($password, PASSWORD_DEFAULT), $solicitud['usuario_id']]);

            // El token se marca usado aquí mismo: no vale una segunda vez.
            $pdo->prepare("UPDATE password_resets SET usado_en = NOW() WHERE id = ?")
                ->execute([$solicitud['id']]);

            $pdo->commit();
        } catch (Throwable $ex) {
            $pdo->rollBack();
            error_log('Flowers Anto — restablecer contraseña: ' . $ex->getMessage());
            $error = 'No pudimos guardar la contraseña nueva. Inténtalo otra vez.';
        }

        if ($error === '') {
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
                    . 'de tu cuenta.</p><p>Si no fuiste tú, escríbenos cuanto antes.</p>',
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
          <input type="hidden" name="token" value="<?= e($token) ?>">
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
