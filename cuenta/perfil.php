<?php
/**
 * Datos personales y cambio de contraseña del cliente.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

Auth::exigirSesion('cuenta/perfil.php');

$usuario = Auth::usuario();
$errores = [];
$erroresPassword = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirToken(false, 'cuenta/perfil.php');
    $accion = opcion('accion', ['datos', 'password'], 'datos');

    if ($accion === 'datos') {
        $nombre   = texto('nombre', 60);
        $apellido = texto('apellido', 60);
        $telefono = telefonoValido('telefono');
        $correo   = correoValido('email');

        if (mb_strlen($nombre) < 2)   { $errores['nombre']   = 'Escribe tu nombre.'; }
        if (mb_strlen($apellido) < 2) { $errores['apellido'] = 'Escribe tu apellido.'; }
        if ($telefono === '')         { $errores['telefono'] = 'El teléfono debe tener 8 dígitos o más.'; }
        if ($correo === '')           { $errores['email']    = 'Escribe un correo válido.'; }

        if (!$errores && $correo !== mb_strtolower((string)$usuario['email'])) {
            $ocupado = $pdo->prepare("SELECT 1 FROM usuarios WHERE email = ? AND id <> ?");
            $ocupado->execute([$correo, $usuario['id']]);
            if ($ocupado->fetchColumn()) {
                $errores['email'] = 'Ese correo ya está en uso por otra cuenta.';
            }
        }

        if (!$errores) {
            $pdo->prepare(
                "UPDATE usuarios SET nombre = ?, apellido = ?, telefono = ?, email = ?, nombre_completo = ?
                  WHERE id = ?"
            )->execute([$nombre, $apellido, $telefono, $correo,
                        trim($nombre . ' ' . $apellido), $usuario['id']]);

            Auditoria::registrar($pdo, 'editar_perfil', 'usuarios', [
                'recurso_tipo' => 'usuario', 'recurso_id' => (string)$usuario['id'],
                'descripcion'  => 'El cliente actualizó sus datos personales.',
                'detalles'     => Auditoria::diferencias(
                    $usuario,
                    ['nombre' => $nombre, 'apellido' => $apellido, 'telefono' => $telefono, 'email' => $correo],
                    ['nombre', 'apellido', 'telefono', 'email']
                ),
            ]);

            flash('exito', 'Guardamos tus datos.');
            redirigir('cuenta/perfil.php');
        }
    } else {
        $actual    = crudo('password_actual');
        $nueva     = crudo('password');
        $confirmar = crudo('password_confirmar');
        $tienePassword = (string)($usuario['password_hash'] ?? '') !== '';

        if ($tienePassword && !Auth::verificarPassword($pdo, $usuario, $actual)) {
            $erroresPassword['password_actual'] = 'La contraseña actual no coincide.';
        }
        $problema = revisarPassword($nueva, $confirmar);
        if ($problema !== '') {
            $erroresPassword['password'] = $problema;
        }

        if (!$erroresPassword) {
            $pdo->prepare("UPDATE usuarios SET password_hash = ? WHERE id = ?")
                ->execute([password_hash($nueva, PASSWORD_DEFAULT), $usuario['id']]);

            Auditoria::registrar($pdo, 'cambio_password', 'usuarios', [
                'recurso_tipo' => 'usuario', 'recurso_id' => (string)$usuario['id'],
                'descripcion'  => 'El cliente cambió su contraseña desde su cuenta.',
            ]);

            Correo::enviar((string)$usuario['email'], 'Tu contraseña se cambió',
                Correo::plantilla('Tu contraseña se cambió',
                    '<p>Hola ' . e((string)$usuario['nombre']) . ', acabas de cambiar tu contraseña. '
                    . 'Si no fuiste tú, escríbenos cuanto antes.</p>'));

            flash('exito', 'Contraseña actualizada.');
            redirigir('cuenta/perfil.php');
        }
    }
}

$tituloPagina  = 'Mis datos — ' . Ajustes::texto('nombre_tienda', 'Flowers Anto');
$paginaActiva  = 'cuenta';
$seccionCuenta = 'perfil';
$tienePassword = (string)($usuario['password_hash'] ?? '') !== '';

require __DIR__ . '/../includes/vistas/cabecera.php';
?>

<div class="container">
  <header class="pagina-cabecera">
    <h1>Mis datos</h1>
    <p>Estos datos se usan para tus pedidos y para avisarte de su estado.</p>
  </header>

  <?php require __DIR__ . '/../includes/vistas/aviso_verificar.php'; ?>

  <div class="diseno-cuenta">
    <?php require __DIR__ . '/../includes/vistas/menu_cuenta.php'; ?>

    <div>
      <div class="tarjeta">
        <div class="tarjeta-encabezado">
          <h2>Datos personales</h2>
          <?php if ($usuario['google_id']): ?>
            <p><i class="fa-brands fa-google" aria-hidden="true"></i> Tu cuenta está vinculada con Google.</p>
          <?php endif; ?>
        </div>

        <form method="post" action="<?= e(url('cuenta/perfil.php')) ?>" novalidate data-una-vez>
          <?= campoToken() ?>
          <input type="hidden" name="accion" value="datos">

          <div class="campo-fila">
            <div class="campo<?= isset($errores['nombre']) ? ' con-error' : '' ?>">
              <label for="nombre">Nombre</label>
              <input type="text" id="nombre" name="nombre" required
                     value="<?= e(texto('nombre') ?: (string)$usuario['nombre']) ?>">
              <?php if (isset($errores['nombre'])): ?><p class="error-campo"><?= e($errores['nombre']) ?></p><?php endif; ?>
            </div>
            <div class="campo<?= isset($errores['apellido']) ? ' con-error' : '' ?>">
              <label for="apellido">Apellido</label>
              <input type="text" id="apellido" name="apellido" required
                     value="<?= e(texto('apellido') ?: (string)$usuario['apellido']) ?>">
              <?php if (isset($errores['apellido'])): ?><p class="error-campo"><?= e($errores['apellido']) ?></p><?php endif; ?>
            </div>
          </div>

          <div class="campo<?= isset($errores['email']) ? ' con-error' : '' ?>">
            <label for="email">Correo electrónico</label>
            <input type="email" id="email" name="email" required
                   value="<?= e(correoValido('email') ?: (string)$usuario['email']) ?>">
            <?php if (isset($errores['email'])): ?><p class="error-campo"><?= e($errores['email']) ?></p><?php endif; ?>
          </div>

          <div class="campo<?= isset($errores['telefono']) ? ' con-error' : '' ?>">
            <label for="telefono">Teléfono / WhatsApp</label>
            <input type="tel" id="telefono" name="telefono" required
                   value="<?= e(texto('telefono') ?: (string)$usuario['telefono']) ?>">
            <?php if (isset($errores['telefono'])): ?><p class="error-campo"><?= e($errores['telefono']) ?></p><?php endif; ?>
          </div>

          <button type="submit" class="btn btn-primary">Guardar cambios</button>
        </form>
      </div>

      <div class="tarjeta">
        <div class="tarjeta-encabezado">
          <h2><?= $tienePassword ? 'Cambiar contraseña' : 'Crear una contraseña' ?></h2>
          <?php if (!$tienePassword): ?>
            <p>Entras con Google. Si además creas una contraseña, podrás entrar de las dos formas.</p>
          <?php endif; ?>
        </div>

        <form method="post" action="<?= e(url('cuenta/perfil.php')) ?>" novalidate data-una-vez>
          <?= campoToken() ?>
          <input type="hidden" name="accion" value="password">

          <?php if ($tienePassword): ?>
            <div class="campo<?= isset($erroresPassword['password_actual']) ? ' con-error' : '' ?>">
              <label for="password_actual">Contraseña actual</label>
              <input type="password" id="password_actual" name="password_actual" required autocomplete="current-password">
              <?php if (isset($erroresPassword['password_actual'])): ?>
                <p class="error-campo"><?= e($erroresPassword['password_actual']) ?></p>
              <?php endif; ?>
            </div>
          <?php endif; ?>

          <div class="campo<?= isset($erroresPassword['password']) ? ' con-error' : '' ?>">
            <label for="password">Contraseña nueva</label>
            <input type="password" id="password" name="password" required autocomplete="new-password" minlength="8">
            <div class="medidor-password" id="medidorPassword" aria-hidden="true"><span></span></div>
            <?php if (isset($erroresPassword['password'])): ?>
              <p class="error-campo"><?= e($erroresPassword['password']) ?></p>
            <?php endif; ?>
          </div>

          <div class="campo">
            <label for="password_confirmar">Repite la contraseña nueva</label>
            <input type="password" id="password_confirmar" name="password_confirmar" required
                   autocomplete="new-password" minlength="8">
          </div>

          <button type="submit" class="btn btn-secondary">
            <?= $tienePassword ? 'Cambiar contraseña' : 'Crear contraseña' ?>
          </button>
        </form>
      </div>
    </div>
  </div>
</div>

<div style="height:56px"></div>
<?php require __DIR__ . '/../includes/vistas/pie.php'; ?>
