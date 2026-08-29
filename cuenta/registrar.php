<?php
/**
 * Registro de clientes.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/lib/google.php';

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

        if ($existe->fetchColumn()) {
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

        $pdo->prepare(
            "INSERT INTO usuarios (email, nombre, apellido, telefono, password_hash, rol_id, activo, nombre_completo)
             VALUES (?,?,?,?,?,?,1,?)"
        )->execute([
            $datos['email'], $datos['nombre'], $datos['apellido'], $datos['telefono'],
            password_hash($password, PASSWORD_DEFAULT), Auth::rolId($pdo, 'cliente'),
            trim($datos['nombre'] . ' ' . $datos['apellido']),
        ]);
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

        Correo::enviar(
            $datos['email'],
            'Bienvenida a ' . Ajustes::texto('nombre_tienda', 'Flowers Anto'),
            Correo::plantilla(
                '¡Tu cuenta está lista!',
                '<p>Hola ' . e($datos['nombre']) . ', gracias por crear tu cuenta.</p>'
                . '<p>Desde ahora tus favoritos y tus pedidos quedan guardados y los puedes '
                . 'consultar cuando quieras.</p>',
                ['url' => url_absoluta('productos.php'), 'texto' => 'Ver los arreglos']
            )
        );

        $destino = $_SESSION['volver_a'] ?? '';
        unset($_SESSION['volver_a']);
        flash('exito', '¡Bienvenida, ' . $datos['nombre'] . '! Tu cuenta está lista.');
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

      <?php if (Google::configurado()): ?>
        <div class="separador-o">o</div>
        <a class="btn-google" href="<?= e(url('cuenta/google.php')) ?>">
          <svg viewBox="0 0 24 24" aria-hidden="true">
            <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 0 1-2.2 3.32v2.76h3.57c2.08-1.92 3.27-4.74 3.27-8.09z"/>
            <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.76c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84A11 11 0 0 0 12 23z"/>
            <path fill="#FBBC05" d="M5.84 14.11a6.6 6.6 0 0 1 0-4.22V7.05H2.18a11 11 0 0 0 0 9.9l3.66-2.84z"/>
            <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.05l3.66 2.84C6.71 7.31 9.14 5.38 12 5.38z"/>
          </svg>
          Continuar con Google
        </a>
      <?php endif; ?>

      <div class="enlaces-auth">
        <a href="<?= e(url('cuenta/entrar.php')) ?>">Ya tengo cuenta</a>
        <a href="<?= e(url('productos.php')) ?>">Seguir sin cuenta</a>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../includes/vistas/pie.php'; ?>
