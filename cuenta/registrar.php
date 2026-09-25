<?php
/**
 * Registro de clientes.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/lib/cuentas_externas.php';
require_once __DIR__ . '/../includes/lib/google.php';
require_once __DIR__ . '/../includes/lib/facebook.php';

if (Auth::autenticado()) {
    redirigir('cuenta/pedidos.php');
}

$errores = [];
$datos   = ['nombre' => '', 'apellido' => '', 'email' => '', 'telefono' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirToken(false, 'cuenta/registrar.php');

    $datos['nombre']   = texto('nombre', 60);
    $datos['apellido'] = texto('apellido', 60);
    $datos['email']    = correoValido('email');
    $datos['telefono'] = telefonoValido('telefono');
    $password  = crudo('password');
    $confirmar = crudo('password_confirmar');

    if (!limitar($pdo, 'registro:' . ip_cliente(), 6, 3600)) {
        $errores[] = 'Se crearon varias cuentas desde esta conexión. Espera un momento.';
    }
    if (mb_strlen($datos['nombre']) < 2) {
        $errores['nombre'] = 'Escribe tu nombre.';
    }
    if (mb_strlen($datos['apellido']) < 2) {
        $errores['apellido'] = 'Escribe tu apellido.';
    }
    if ($datos['email'] === '') {
        $errores['email'] = 'Escribe un correo electrónico válido.';
    }
    if ($datos['telefono'] === '') {
        $errores['telefono'] = 'Escribe un teléfono de 8 dígitos o más.';
    }
    $problema = revisarPassword($password, $confirmar);
    if ($problema !== '') {
        $errores['password'] = $problema;
    }
    if (!casilla('acepto')) {
        $errores['acepto'] = 'Necesitamos tu confirmación para crear la cuenta.';
    }

    if (!$errores) {
        $existe = $pdo->prepare("SELECT id FROM usuarios WHERE email = ?");
        $existe->execute([$datos['email']]);
        $yaExiste = (bool)$existe->fetchColumn();

        if (!$yaExiste) {
            try {
                $pdo->prepare(
                    "INSERT INTO usuarios (email, nombre, apellido, telefono, password_hash, rol_id, activo, nombre_completo)
                     VALUES (?,?,?,?,?,?,1,?)"
                )->execute([
                    $datos['email'], $datos['nombre'], $datos['apellido'], $datos['telefono'],
                    password_hash($password, PASSWORD_DEFAULT), Auth::rolId($pdo, 'cliente'),
                    trim($datos['nombre'] . ' ' . $datos['apellido']),
                ]);
            } catch (PDOException $e) {
                // Dos altas con el mismo correo a la vez: la segunda choca con el
                // índice único. Se trata como un correo ya registrado.
                if ($e->getCode() !== '23000') {
                    throw $e;
                }
                $yaExiste = true;
            }
        }

        if ($yaExiste) {
            // No se confirma que el correo esté registrado: se envía un aviso a
            // esa dirección y se muestra el mismo mensaje que en un alta normal.
            Correo::enviar(
                $datos['email'],
                'Ya tienes una cuenta en ' . Ajustes::texto('nombre_tienda', 'Flowers Anto'),
                Correo::plantilla(
                    'Ya tienes una cuenta',
                    '<p>Alguien intentó registrarse con este correo. Si fuiste tú, ya tienes una '
                    . 'cuenta creada: inicia sesión con tu contraseña.</p>'
                    . '<p>Si no recuerdas la contraseña, puedes restablecerla desde el enlace '
                    . '«Olvidé mi contraseña».</p>',
                    ['url' => url_absoluta('cuenta/entrar.php'), 'texto' => 'Iniciar sesión']
                )
            );
            flash('exito', 'Cuenta lista. Revisa tu correo e inicia sesión.');
            redirigir('cuenta/entrar.php');
        }

        $id = (int)$pdo->lastInsertId();

        $st = $pdo->prepare(
            "SELECT u.*, r.codigo AS rol_codigo, r.es_personal
               FROM usuarios u LEFT JOIN roles r ON r.id = u.rol_id WHERE u.id = ?"
        );
        $st->execute([$id]);
        $usuario = $st->fetch();

        Auth::abrirSesion($usuario);
        Favoritos::fusionarAlEntrar($pdo, $id);
        Carrito::sincronizar($pdo);

        Auditoria::registrar($pdo, 'registro', 'usuarios', [
            'recurso_tipo' => 'usuario', 'recurso_id' => (string)$id,
            'descripcion'  => 'Cuenta de cliente creada.',
        ]);

        // Un solo correo, no dos: la bienvenida lleva dentro el enlace de
        // confirmación. Dos mensajes seguidos al registrarse parecen spam y es
        // más probable que ninguno se abra.
        Verificacion::enviar($pdo, $usuario);

        Analitica::eventoDiferido('sign_up', ['method' => 'formulario']);

        $destino = $_SESSION['volver_a'] ?? '';
        unset($_SESSION['volver_a']);
        // Se nombra el correo al que fue: es lo que hace que la persona sepa
        // dónde mirar, y de paso descubre una errata en su propia dirección
        // antes de esperar un mensaje que nunca va a llegar.
        flash('exito', '¡Bienvenida, ' . $datos['nombre'] . '! Tu cuenta está lista. '
                     . 'Te enviamos un correo a ' . $datos['email'] . ' para confirmarla.');
        redirigir($destino !== '' ? $destino : 'cuenta/pedidos.php');
    }
}

$tituloPagina      = 'Crear cuenta — ' . Ajustes::texto('nombre_tienda', 'Flowers Anto');
$descripcionPagina = 'Crea tu cuenta para guardar favoritos y seguir tus pedidos.';

require __DIR__ . '/../includes/vistas/cabecera.php';
?>

<div class="container">
  <div class="marco-auth">
    <h1>Crear cuenta</h1>
    <p class="subtitulo">Guarda tus favoritos, sigue tus pedidos y compra más rápido la próxima vez.</p>

    <div class="tarjeta">
      <?php foreach ($errores as $clave => $mensaje): if (!is_int($clave)) continue; ?>
        <div class="caja-aviso error" role="alert">
          <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i><span><?= e((string)$mensaje) ?></span>
        </div>
      <?php endforeach; ?>

      <form method="post" action="<?= e(url('cuenta/registrar.php')) ?>" novalidate data-una-vez>
        <?= campoToken() ?>

        <div class="campo-fila">
          <div class="campo<?= isset($errores['nombre']) ? ' con-error' : '' ?>">
            <label for="nombre">Nombre *</label>
            <input type="text" id="nombre" name="nombre" required autocomplete="given-name"
                   value="<?= e($datos['nombre']) ?>">
            <?php if (isset($errores['nombre'])): ?><p class="error-campo"><?= e($errores['nombre']) ?></p><?php endif; ?>
          </div>
          <div class="campo<?= isset($errores['apellido']) ? ' con-error' : '' ?>">
            <label for="apellido">Apellido *</label>
            <input type="text" id="apellido" name="apellido" required autocomplete="family-name"
                   value="<?= e($datos['apellido']) ?>">
            <?php if (isset($errores['apellido'])): ?><p class="error-campo"><?= e($errores['apellido']) ?></p><?php endif; ?>
          </div>
        </div>

        <div class="campo<?= isset($errores['email']) ? ' con-error' : '' ?>">
          <label for="email">Correo electrónico *</label>
          <input type="email" id="email" name="email" required autocomplete="email" value="<?= e($datos['email']) ?>">
          <?php if (isset($errores['email'])): ?><p class="error-campo"><?= e($errores['email']) ?></p><?php endif; ?>
        </div>

        <div class="campo<?= isset($errores['telefono']) ? ' con-error' : '' ?>">
          <label for="telefono">Teléfono / WhatsApp *</label>
          <input type="tel" id="telefono" name="telefono" required autocomplete="tel"
                 value="<?= e($datos['telefono']) ?>" placeholder="+505 8888 8888">
          <?php if (isset($errores['telefono'])): ?><p class="error-campo"><?= e($errores['telefono']) ?></p><?php endif; ?>
        </div>

        <div class="campo<?= isset($errores['password']) ? ' con-error' : '' ?>">
          <label for="password">Contraseña *</label>
          <input type="password" id="password" name="password" required autocomplete="new-password" minlength="8">
          <div class="medidor-password" id="medidorPassword" aria-hidden="true"><span></span></div>
          <p class="ayuda">Mínimo 8 caracteres, combinando letras y números.</p>
          <?php if (isset($errores['password'])): ?><p class="error-campo"><?= e($errores['password']) ?></p><?php endif; ?>
        </div>

        <div class="campo">
          <label for="password_confirmar">Repite la contraseña *</label>
          <input type="password" id="password_confirmar" name="password_confirmar" required
                 autocomplete="new-password" minlength="8">
        </div>

        <div class="campo-casilla<?= isset($errores['acepto']) ? ' con-error' : '' ?>">
          <input type="checkbox" id="acepto" name="acepto" value="1" required <?= casilla('acepto') ? 'checked' : '' ?>>
          <label for="acepto">Acepto que <?= e(Ajustes::texto('nombre_tienda', 'Flowers Anto')) ?> use mis datos
            para gestionar mis pedidos y contactarme sobre ellos.</label>
        </div>
        <?php if (isset($errores['acepto'])): ?>
          <p class="error-campo" style="margin:-8px 0 14px;"><?= e($errores['acepto']) ?></p>
        <?php endif; ?>

        <button type="submit" class="btn btn-primary btn-block">Crear mi cuenta</button>
      </form>

      <?php require __DIR__ . '/../includes/vistas/acceso_social.php'; ?>

      <div class="enlaces-auth">
        <a href="<?= e(url('cuenta/entrar.php')) ?>">Ya tengo cuenta</a>
        <a href="<?= e(url('productos.php')) ?>">Seguir sin cuenta</a>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../includes/vistas/pie.php'; ?>
