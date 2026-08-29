<?php
/**
 * Inicio de sesión de clientes.
 *
 * El mismo formulario sirve para el personal: quien tenga un rol de panel
 * entra igual y le aparece el enlace al panel. La diferencia la marca el rol,
 * no una tabla aparte.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/lib/google.php';

if (Auth::autenticado()) {
    redirigir('cuenta/pedidos.php');
}

$volverA = texto('volver', 120, $_GET);
if ($volverA !== '') {
    $_SESSION['volver_a'] = $volverA;
}

$error       = '';
$identidad   = '';
const MAX_INTENTOS = 5;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirToken(false, 'cuenta/entrar.php');

    $identidad = texto('identidad', 150);
    $password  = crudo('password');
    $claveIp   = 'login-ip:' . ip_cliente();

    if (!limitar($pdo, $claveIp, 20, 900)) {
        $error = 'Demasiados intentos desde esta conexión. Espera 15 minutos.';
    } elseif ($identidad === '' || $password === '') {
        $error = 'Escribe tu correo y tu contraseña.';
    } else {
        $usuario = Auth::buscarPorIdentificador($pdo, $identidad);

        if ($usuario && Auth::bloqueado($usuario)) {
            $error = 'Por seguridad bloqueamos la cuenta 15 minutos tras varios intentos fallidos.';
        } elseif ($usuario && (int)$usuario['activo'] !== 1) {
            $error = 'Esta cuenta está desactivada. Escríbenos si crees que es un error.';
        } elseif ($usuario && Auth::verificarPassword($pdo, $usuario, $password)) {
            Auth::abrirSesion($usuario);
            Favoritos::fusionarAlEntrar($pdo, (int)$usuario['id']);
            Carrito::fusionarAlEntrar($pdo, (int)$usuario['id']);
            limpiarLimite($pdo, $claveIp);

            Auditoria::registrar($pdo, 'inicio_sesion', 'usuarios', [
                'recurso_tipo' => 'usuario', 'recurso_id' => (string)$usuario['id'],
                'descripcion'  => 'Inicio de sesión con contraseña.',
            ]);

            $destino = $_SESSION['volver_a'] ?? '';
            unset($_SESSION['volver_a']);
            flash('exito', '¡Hola de nuevo, ' . Auth::nombreCompleto() . '!');
            redirigir($destino !== '' ? $destino : (Auth::esPersonal() ? 'admin/' : 'cuenta/pedidos.php'));
        } else {
            if ($usuario) {
                Auth::anotarFallo($pdo, $usuario, MAX_INTENTOS);
            }
            Auditoria::registrar($pdo, 'inicio_sesion', 'usuarios', [
                'resultado'   => 'fallo',
                'descripcion' => 'Intento fallido de inicio de sesión.',
                'detalles'    => ['identidad' => mb_substr($identidad, 0, 80)],
            ]);
            // El mismo mensaje exista o no la cuenta: no se revela qué correos
            // están registrados.
            $error = 'Correo o contraseña incorrectos.';
        }
    }
}

$tituloPagina      = 'Iniciar sesión — ' . Ajustes::texto('nombre_tienda', 'Flowers Anto');
$descripcionPagina = 'Entra a tu cuenta para ver tus pedidos y tus favoritos.';

require __DIR__ . '/../includes/vistas/cabecera.php';
?>

<div class="container">
  <div class="marco-auth">
    <h1>Iniciar sesión</h1>
    <p class="subtitulo">Para ver tus pedidos, tus favoritos y comprar más rápido.</p>

    <div class="tarjeta">
      <?php if ($error !== ''): ?>
        <div class="caja-aviso error" role="alert">
          <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i><span><?= e($error) ?></span>
        </div>
      <?php endif; ?>

      <form method="post" action="<?= e(url('cuenta/entrar.php')) ?>" data-una-vez>
        <?= campoToken() ?>
        <div class="campo">
          <label for="identidad">Correo electrónico</label>
          <input type="text" id="identidad" name="identidad" required autofocus autocomplete="username"
                 value="<?= e($identidad) ?>" inputmode="email">
        </div>
        <div class="campo">
          <label for="password">Contraseña</label>
          <input type="password" id="password" name="password" required autocomplete="current-password">
        </div>
        <button type="submit" class="btn btn-primary btn-block">Entrar</button>
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
        <a href="<?= e(url('cuenta/recuperar.php')) ?>">Olvidé mi contraseña</a>
        <a href="<?= e(url('cuenta/registrar.php')) ?>">Crear una cuenta</a>
      </div>
    </div>

    <p style="text-align:center; margin-top:18px; font-size:.88rem; color:var(--suave);">
      ¿Compraste sin cuenta? <a href="<?= e(url('seguimiento.php')) ?>">Sigue tu pedido con el código</a>.
    </p>
  </div>
</div>

<?php require __DIR__ . '/../includes/vistas/pie.php'; ?>
