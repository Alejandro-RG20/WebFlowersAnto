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
require_once __DIR__ . '/../includes/lib/cuentas_externas.php';
require_once __DIR__ . '/../includes/lib/google.php';
require_once __DIR__ . '/../includes/lib/facebook.php';

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

        // Tope de intentos por cuenta: 5 cada 15 minutos. Se cuenta con el
        // limitador atómico y por la identidad escrita, exista o no la
        // cuenta. Antes era un contador en la fila del usuario que se leía y
        // se escribía desde PHP (con 30 intentos a la vez se comprobaban
        // unas 17 contraseñas, no 5) y que solo existía para cuentas reales:
        // el aviso de «bloqueada» delataba qué correos estaban registrados.
        // El intento se reserva ANTES de comprobar la clave.
        $claveIdentidad = 'login-id:' . hash('sha256', mb_strtolower($identidad));
        $bloqueada = !limitar($pdo, $claveIdentidad, MAX_INTENTOS, 900);

        // «Cuenta desactivada» solo se dice a quien acierta la contraseña.
        // Antes se decía con cualquier clave, y eso bastaba para averiguar
        // qué correos tenían una cuenta desactivada en la tienda.
        $comprobable = $usuario && !$bloqueada
                    && (string)($usuario['password_hash'] ?? '') !== '';
        $claveCorrecta = $comprobable && Auth::verificarPassword($pdo, $usuario, $password);

        // Con un correo que no existe (o una cuenta solo de Google) no hay
        // contraseña que comprobar y la respuesta salía antes: midiendo el
        // tiempo se distinguían los correos registrados de los que no. Se
        // hace un cálculo de bcrypt con el coste por defecto del servidor,
        // el mismo que cuesta comprobar una contraseña guardada.
        if (!$comprobable) {
            password_hash($password, PASSWORD_DEFAULT);
        }

        if ($bloqueada) {
            $error = 'Por seguridad bloqueamos la cuenta 15 minutos tras varios intentos fallidos.';
        } elseif ($claveCorrecta && (int)$usuario['activo'] !== 1) {
            $error = 'Esta cuenta está desactivada. Escríbenos si crees que es un error.';
        } elseif ($claveCorrecta) {
            Auth::abrirSesion($usuario);
            Favoritos::fusionarAlEntrar($pdo, (int)$usuario['id']);
            Carrito::fusionarAlEntrar($pdo, (int)$usuario['id']);
            limpiarLimite($pdo, $claveIp);
            limpiarLimite($pdo, $claveIdentidad);

            Auditoria::registrar($pdo, 'inicio_sesion', 'usuarios', [
                'recurso_tipo' => 'usuario', 'recurso_id' => (string)$usuario['id'],
                'descripcion'  => 'Inicio de sesión con contraseña.',
            ]);

            // Va después de abrir la sesión: así, si quien entra es del
            // equipo, `Analitica::activo()` ya lo sabe y no lo mide.
            Analitica::eventoDiferido('login', ['method' => 'password']);

            $destino = $_SESSION['volver_a'] ?? '';
            unset($_SESSION['volver_a']);
            flash('exito', '¡Hola de nuevo, ' . Auth::nombreCompleto() . '!');
            redirigir($destino !== '' ? $destino : (Auth::esPersonal() ? 'admin/' : 'cuenta/pedidos.php'));
        } else {
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

// La sesión se cerró sola por inactividad: se dice, en vez de dejar a la
// persona preguntándose por qué la echaron.
if (!empty($_SESSION['aviso_caducada'])) {
    unset($_SESSION['aviso_caducada']);
    flash('info', 'Cerramos tu sesión por seguridad tras varias horas sin actividad. '
                . 'Vuelve a entrar y sigues donde estabas.');
}

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

      <?php require __DIR__ . '/../includes/vistas/acceso_social.php'; ?>

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
