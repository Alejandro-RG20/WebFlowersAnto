<?php
/**
 * Confirmación del correo desde el enlace que se envía al registrarse.
 *
 * El token no se queda en la barra de direcciones. En cuanto llega se
 * comprueba, el resultado se guarda en la sesión y se redirige a esta misma
 * página sin él. Así no aparece en el historial del navegador, no viaja en la
 * cabecera Referer hacia las fuentes o scripts de terceros que carga la
 * página, y Analytics —que registra la dirección completa de cada vista— no
 * lo recibe nunca.
 *
 * Reenviar un enlace se permite a dos personas: a quien ya entró en su cuenta
 * y a quien acaba de abrir un enlace caducado o anulado. Lo segundo no abre
 * ninguna puerta: el enlace nuevo va al mismo correo de la cuenta, y para
 * llegar hasta aquí hacía falta tener en la mano un enlace de esa cuenta.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

/** Cuánto vale el permiso de reenvío que deja un enlace caducado. */
const VENTANA_REENVIO = 900;

// --- 1. Llega el enlace: se comprueba y se quita de la URL ---------------
$token = texto('token', 64, $_GET);
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $token !== '') {
    $r = Verificacion::confirmar($pdo, $token);

    $_SESSION['verificacion'] = ['estado' => $r['estado']];
    if (in_array($r['estado'], ['caducado', 'reemplazado'], true) && isset($r['usuario_id'])) {
        $_SESSION['verificacion_reenvio'] = [
            'usuario_id' => (int)$r['usuario_id'],
            'hasta'      => time() + VENTANA_REENVIO,
        ];
    }
    redirigir('cuenta/verificar.php');
}

// --- 2. Pedir otro enlace -------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirToken(false, 'cuenta/verificar.php');

    // El aviso aparece en varias páginas. Se vuelve a la que se estaba
    // mirando, no siempre al perfil: pedir el enlace desde el seguimiento de
    // un pedido y acabar en otra pantalla desorienta.
    // `url_interna('')` devuelve la portada, no una cadena vacía: por eso se
    // mira antes si vino algo. Sin «volver» se queda en esta página, que es
    // donde se explica el resultado.
    $pedidoVolver = texto('volver', 200);
    $desdeAqui    = $pedidoVolver === '';
    $volver       = $desdeAqui ? 'cuenta/verificar.php' : url_interna($pedidoVolver);

    $usuario = Auth::usuario();
    if (!$usuario) {
        $permiso = $_SESSION['verificacion_reenvio'] ?? null;
        if (is_array($permiso) && (int)($permiso['hasta'] ?? 0) >= time()) {
            $st = $pdo->prepare("SELECT * FROM usuarios WHERE id = ? AND activo = 1");
            $st->execute([(int)$permiso['usuario_id']]);
            $usuario = $st->fetch() ?: null;
        }
    }

    if (!$usuario) {
        flash('error', 'Entra a tu cuenta para pedir un enlace nuevo.');
        redirigir('cuenta/entrar.php');
    }
    if (Verificacion::verificado($usuario)) {
        unset($_SESSION['verificacion_reenvio']);
        $_SESSION['verificacion'] = ['estado' => 'ya_verificado'];
        redirigir('cuenta/verificar.php');
    }
    // Un freno más por conexión, aparte del de la cuenta: sin él, alguien con
    // muchos enlaces caducados de cuentas distintas podría ir pidiéndolos.
    if (!limitar($pdo, 'verificar-ip:' . ip_cliente(), 10, 3600)) {
        flash('alerta', 'Demasiadas solicitudes desde esta conexión. Espera un rato.');
        redirigir($volver);
    }
    $permitido = Verificacion::puedeReenviar($pdo, (int)$usuario['id']);
    if (!$permitido['ok']) {
        flash('alerta', $permitido['mensaje']);
        redirigir($volver);
    }

    $enviado = Verificacion::enviar($pdo, $usuario);
    unset($_SESSION['verificacion_reenvio']);
    if ($enviado) {
        $tapado = Verificacion::correoTapado((string)$usuario['email']);
        if ($desdeAqui) {
            $_SESSION['verificacion'] = ['estado' => 'enviado', 'correo' => $tapado];
        } else {
            flash('exito', 'Te enviamos un enlace nuevo a ' . $tapado . '. Caduca en 48 horas.');
        }
        redirigir($volver);
    }
    flash('error', 'No pudimos enviar el correo. Escríbenos por WhatsApp y lo confirmamos nosotros.');
    redirigir($volver);
}

// --- 3. Pintar el estado ----------------------------------------------------
$estado = (string)($_SESSION['verificacion']['estado'] ?? '');
$correoEnviado = (string)($_SESSION['verificacion']['correo'] ?? '');
unset($_SESSION['verificacion']);

$permisoReenvio = $_SESSION['verificacion_reenvio'] ?? null;
$puedePedir = (Auth::autenticado() && !Verificacion::verificado(Auth::usuario()))
           || (is_array($permisoReenvio) && (int)($permisoReenvio['hasta'] ?? 0) >= time());

$vistas = [
    'ok' => ['icono' => 'fa-circle-check', 'tono' => 'bien',
             'titulo' => 'Correo verificado correctamente',
             'texto' => 'Listo: a partir de ahora te avisaremos por correo de cada paso de tus pedidos.'],
    'ya_verificado' => ['icono' => 'fa-circle-check', 'tono' => 'bien',
             'titulo' => 'Tu correo ya está verificado',
             'texto' => 'Este enlace ya se había usado y tu cuenta quedó confirmada. No tienes que hacer nada más.'],
    'caducado' => ['icono' => 'fa-hourglass-end', 'tono' => 'aviso',
             'titulo' => 'Este enlace ha expirado',
             'texto' => 'Los enlaces de confirmación duran 48 horas. Te enviamos uno nuevo al mismo correo.'],
    'reemplazado' => ['icono' => 'fa-rotate', 'tono' => 'aviso',
             'titulo' => 'Este enlace ya no es válido',
             'texto' => 'Pediste un enlace más reciente y este quedó anulado. Usa el último que te enviamos, o pide otro.'],
    'invalido' => ['icono' => 'fa-link-slash', 'tono' => 'mal',
             'titulo' => 'No pudimos usar este enlace',
             'texto' => 'Puede que esté incompleto o mal copiado. Ábrelo directamente desde el correo que te enviamos.'],
    'enviado' => ['icono' => 'fa-paper-plane', 'tono' => 'bien',
             'titulo' => 'Te enviamos un enlace nuevo',
             'texto' => 'Revisa tu bandeja de entrada' . ($correoEnviado !== '' ? ' (' . $correoEnviado . ')' : '')
                      . '. Si no lo ves en unos minutos, mira en la carpeta de no deseados.'],
];
$vista = $vistas[$estado] ?? null;

$tituloPagina      = 'Confirmar correo — ' . Ajustes::texto('nombre_tienda', 'Flowers Anto');
$descripcionPagina = 'Confirma tu correo para recibir el estado de tus pedidos.';
$paginaActiva      = '';

require __DIR__ . '/../includes/vistas/cabecera.php';
?>

<div class="container verificacion-pagina">
  <div class="tarjeta verificacion-tarjeta<?= $vista ? ' ' . e($vista['tono']) : '' ?>">
    <?php if ($vista): ?>
      <div class="verificacion-icono" aria-hidden="true"><i class="fa-solid <?= e($vista['icono']) ?>"></i></div>
      <h1 class="verificacion-titulo"><?= e($vista['titulo']) ?></h1>
      <p class="verificacion-texto"><?= e($vista['texto']) ?></p>
    <?php else: ?>
      <div class="verificacion-icono" aria-hidden="true"><i class="fa-solid fa-envelope-open-text"></i></div>
      <h1 class="verificacion-titulo">Confirmar tu correo</h1>
      <p class="verificacion-texto">Abre el enlace que te enviamos por correo para confirmar tu dirección.</p>
    <?php endif; ?>

    <div class="verificacion-acciones">
      <?php if ($puedePedir && $estado !== 'enviado'): ?>
        <form method="post" action="<?= e(url('cuenta/verificar.php')) ?>" data-una-vez>
          <?= campoToken() ?>
          <button type="submit" class="btn btn-primary btn-block">
            <?= in_array($estado, ['caducado', 'reemplazado'], true) ? 'Reenviar correo' : 'Enviarme otro enlace' ?>
          </button>
        </form>
      <?php endif; ?>

      <?php if (in_array($estado, ['ok', 'ya_verificado'], true)): ?>
        <a class="btn btn-primary btn-block"
           href="<?= e(url(Auth::autenticado() ? 'cuenta/pedidos.php' : 'cuenta/entrar.php')) ?>">
          <?= Auth::autenticado() ? 'Ir a mis pedidos' : 'Iniciar sesión' ?></a>
        <a class="btn btn-outline-dark btn-block" href="<?= e(url('productos.php')) ?>">Ver el catálogo</a>
      <?php elseif (!Auth::autenticado() && !$puedePedir): ?>
        <a class="btn btn-outline-dark btn-block" href="<?= e(url('cuenta/entrar.php')) ?>">Entrar a mi cuenta</a>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../includes/vistas/pie.php'; ?>
