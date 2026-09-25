<?php
/**
 * Datos personales y cambio de contraseña del cliente.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/lib/cuentas_externas.php';
require_once __DIR__ . '/../includes/lib/google.php';
require_once __DIR__ . '/../includes/lib/facebook.php';

Auth::exigirSesion('cuenta/perfil.php');

$usuario = Auth::usuario();
$errores = [];
$erroresPassword = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirToken(false, 'cuenta/perfil.php');
    $accion = opcion('accion', ['datos', 'password', 'vincular', 'desvincular'], 'datos');

    // La contraseña actual se pide en varias acciones de esta página: con una
    // sesión ajena abierta no se puede probar contraseñas sin límite.
    $comprobarClave = function (string $campo) use ($pdo, $usuario): bool {
        if (!limitar($pdo, 'perfil-clave:' . (int)$usuario['id'], 10, 900)) {
            return false;
        }
        return Auth::verificarPassword($pdo, $usuario, crudo($campo));
    };

    // --- Conectar o desconectar Google / Facebook -------------------------
    if ($accion === 'vincular' || $accion === 'desvincular') {
        $proveedor = opcion('proveedor', ['google', 'facebook'], '');
        $disponible = match ($proveedor) {
            'google'   => Google::configurado(),
            'facebook' => Facebook::disponible($pdo),
            default    => false,
        };
        if (!$disponible) {
            flash('error', 'Esa forma de entrar no está disponible.');
            redirigir('cuenta/perfil.php');
        }
        $tieneClave = (string)($usuario['password_hash'] ?? '') !== '';
        if ($accion === 'desvincular') {
            if ($tieneClave && !$comprobarClave('password_vinculo')) {
                flash('error', 'Escribe tu contraseña actual para desconectar ' . CuentasExternas::PROVEEDORES[$proveedor]['nombre'] . '.');
                redirigir('cuenta/perfil.php');
            }
            $r = CuentasExternas::desvincular($pdo, $proveedor, $usuario);
            flash($r['ok'] ? 'exito' : 'error', $r['mensaje']);
            redirigir('cuenta/perfil.php');
        }
        if ($tieneClave && !$comprobarClave('password_vinculo')) {
            flash('error', 'Escribe tu contraseña actual para conectar ' . CuentasExternas::PROVEEDORES[$proveedor]['nombre'] . '.');
            redirigir('cuenta/perfil.php');
        }
        CuentasExternas::autorizarVinculo($proveedor, (int)$usuario['id']);
        redirigir('cuenta/' . $proveedor . '.php');
    }

    if ($accion === 'datos') {
        $nombre   = texto('nombre', 60);
        $apellido = texto('apellido', 60);
        $telefono = telefonoValido('telefono');
        $correo   = correoValido('email');

        if (mb_strlen($nombre) < 2)   { $errores['nombre']   = 'Escribe tu nombre.'; }
        if (mb_strlen($apellido) < 2) { $errores['apellido'] = 'Escribe tu apellido.'; }
        if ($telefono === '')         { $errores['telefono'] = 'El teléfono debe tener 8 dígitos o más.'; }
        if ($correo === '')           { $errores['email']    = 'Escribe un correo válido.'; }

        // Cambiar el correo es cambiar a quién llegan la recuperación de la
        // contraseña y los avisos de los pedidos. Antes bastaba una sesión
        // abierta (un móvil prestado) y el correo nuevo quedaba como
        // «verificado» sin haberse confirmado nunca. Ahora se pide la
        // contraseña actual (si la cuenta tiene) y el correo nuevo se
        // confirma con un enlace.
        $cambiaCorreo = !$errores && $correo !== mb_strtolower((string)$usuario['email']);
        if ($cambiaCorreo) {
            $ocupado = $pdo->prepare("SELECT 1 FROM usuarios WHERE email = ? AND id <> ?");
            $ocupado->execute([$correo, $usuario['id']]);
            if ($ocupado->fetchColumn()) {
                $errores['email'] = 'Ese correo ya está en uso por otra cuenta.';
            } elseif ((string)($usuario['password_hash'] ?? '') !== ''
                && !$comprobarClave('password_actual_correo')) {
                $errores['password_actual_correo'] = 'Para cambiar el correo, escribe tu contraseña actual.';
            }
        }

        if (!$errores) {
            $pdo->prepare(
                "UPDATE usuarios SET nombre = ?, apellido = ?, telefono = ?, email = ?, nombre_completo = ?
                  WHERE id = ?"
            )->execute([$nombre, $apellido, $telefono, $correo,
                        trim($nombre . ' ' . $apellido), $usuario['id']]);

            if ($cambiaCorreo) {
                // El correo nuevo está sin confirmar y los enlaces pendientes
                // (de contraseña o de confirmación) iban al anterior.
                $pdo->prepare("UPDATE usuarios SET email_verificado_en = NULL WHERE id = ?")
                    ->execute([$usuario['id']]);
                $pdo->prepare("UPDATE password_resets SET usado_en = NOW() WHERE usuario_id = ? AND usado_en IS NULL")
                    ->execute([$usuario['id']]);
                Verificacion::enviar($pdo, ['email_verificado_en' => null, 'email' => $correo] + $usuario);
                Correo::enviar((string)$usuario['email'], 'El correo de tu cuenta cambió',
                    Correo::plantilla('El correo de tu cuenta cambió',
                        '<p>Hola ' . e((string)$usuario['nombre']) . ', el correo de tu cuenta en '
                        . e(Ajustes::texto('nombre_tienda', 'Flowers Anto')) . ' se cambió a <strong>'
                        . e($correo) . '</strong>.</p><p>Si no fuiste tú, escríbenos cuanto antes.</p>'));
            }

            Auditoria::registrar($pdo, 'editar_perfil', 'usuarios', [
                'recurso_tipo' => 'usuario', 'recurso_id' => (string)$usuario['id'],
                'descripcion'  => 'El cliente actualizó sus datos personales.',
                'detalles'     => Auditoria::diferencias(
                    $usuario,
                    ['nombre' => $nombre, 'apellido' => $apellido, 'telefono' => $telefono, 'email' => $correo],
                    ['nombre', 'apellido', 'telefono', 'email']
                ),
            ]);

            flash('exito', $cambiaCorreo
                ? 'Guardamos tus datos. Te enviamos un enlace a ' . $correo . ' para confirmar el correo nuevo.'
                : 'Guardamos tus datos.');
            redirigir('cuenta/perfil.php');
        }
    } else {
        $actual    = crudo('password_actual');
        $nueva     = crudo('password');
        $confirmar = crudo('password_confirmar');
        $tienePassword = (string)($usuario['password_hash'] ?? '') !== '';

        if ($tienePassword && !$comprobarClave('password_actual')) {
            $erroresPassword['password_actual'] = 'La contraseña actual no coincide.';
        }
        $problema = revisarPassword($nueva, $confirmar);
        if ($problema !== '') {
            $erroresPassword['password'] = $problema;
        }

        if (!$erroresPassword) {
            $pdo->prepare("UPDATE usuarios SET password_hash = ? WHERE id = ?")
                ->execute([password_hash($nueva, PASSWORD_DEFAULT), $usuario['id']]);

            // Las sesiones abiertas en otros dispositivos se cierran; esta
            // sigue abierta, porque quien la usa acaba de probar la clave.
            Auth::cerrarOtrasSesiones($pdo, (int)$usuario['id'], true);

            Auditoria::registrar($pdo, 'cambio_password', 'usuarios', [
                'recurso_tipo' => 'usuario', 'recurso_id' => (string)$usuario['id'],
                'descripcion'  => 'El cliente cambió su contraseña desde su cuenta.',
            ]);

            Correo::enviar((string)$usuario['email'], 'Tu contraseña se cambió',
                Correo::plantilla('Tu contraseña se cambió',
                    '<p>Hola ' . e((string)$usuario['nombre']) . ', acabas de cambiar tu contraseña '
                    . 'y cerramos tu cuenta en los demás dispositivos. '
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

          <?php if ($tienePassword): ?>
            <div class="campo<?= isset($errores['password_actual_correo']) ? ' con-error' : '' ?>">
              <label for="password_actual_correo">Contraseña actual <small>(solo si cambias el correo)</small></label>
              <input type="password" id="password_actual_correo" name="password_actual_correo" autocomplete="current-password">
              <?php if (isset($errores['password_actual_correo'])): ?>
                <p class="error-campo"><?= e($errores['password_actual_correo']) ?></p>
              <?php endif; ?>
            </div>
          <?php endif; ?>

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
            <p>Entras con Google o Facebook. Si además creas una contraseña, podrás entrar también con tu correo.</p>
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

      <?php
        $proveedoresPerfil = array_filter([
            'google'   => Google::configurado() || !empty($usuario['google_id']),
            'facebook' => CuentasExternas::disponible($pdo, 'facebook') && (Facebook::configurado() || !empty($usuario['facebook_id'])),
        ]);
      ?>
      <?php if ($proveedoresPerfil): ?>
      <div class="tarjeta" id="cuentas-conectadas">
        <div class="tarjeta-encabezado">
          <h2>Cuentas conectadas</h2>
          <p>Entra también con Google o Facebook. Para conectarlas o desconectarlas
             <?= $tienePassword ? 'te pedimos tu contraseña actual y ' : '' ?>te avisamos por correo.</p>
        </div>
        <?php foreach (array_keys($proveedoresPerfil) as $prov):
              $datosProv = CuentasExternas::PROVEEDORES[$prov];
              $conectado = !empty($usuario[$datosProv['columna']]); ?>
          <form method="post" action="<?= e(url('cuenta/perfil.php')) ?>" class="cuenta-conectada" data-una-vez>
            <?= campoToken() ?>
            <input type="hidden" name="proveedor" value="<?= e($prov) ?>">
            <input type="hidden" name="accion" value="<?= $conectado ? 'desvincular' : 'vincular' ?>">
            <p><strong><?= e($datosProv['nombre']) ?></strong>
               <span class="estado-suave <?= $conectado ? 'si' : 'no' ?>"><?= $conectado ? 'Conectada' : 'Sin conectar' ?></span></p>
            <?php if ($tienePassword): ?>
              <div class="campo">
                <label for="password_vinculo_<?= e($prov) ?>">Contraseña actual</label>
                <input type="password" id="password_vinculo_<?= e($prov) ?>" name="password_vinculo" required autocomplete="current-password">
              </div>
            <?php endif; ?>
            <button type="submit" class="btn <?= $conectado ? 'btn-secondary' : 'btn-primary' ?>">
              <?= $conectado ? 'Desconectar ' : 'Conectar ' ?><?= e($datosProv['nombre']) ?>
            </button>
          </form>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<div style="height:56px"></div>
<?php require __DIR__ . '/../includes/vistas/pie.php'; ?>
