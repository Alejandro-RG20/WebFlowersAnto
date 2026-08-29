<?php
/**
 * Buscador de pedidos para quien compró como invitado y perdió el enlace.
 *
 * Pide código y correo a la vez: ninguno de los dos por separado sirve para
 * enumerar pedidos ajenos. Está limitado por IP para que no se pueda probar
 * a fuerza bruta.
 */

declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

$error  = '';
$codigo = texto('codigo', 20, $_GET);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirToken(false, 'seguimiento.php');

    $codigo = mb_strtoupper(texto('codigo', 20));
    $correo = correoValido('email');

    if (!limitar($pdo, 'seguimiento:' . ip_cliente(), 12, 900)) {
        $error = 'Demasiadas búsquedas seguidas. Espera unos minutos e inténtalo otra vez.';
    } elseif ($codigo === '' || $correo === '') {
        $error = 'Necesitamos el código del pedido y el correo con el que lo hiciste.';
    } else {
        $pedido = Pedidos::porCodigo($pdo, $codigo);
        // La comparación del correo es en tiempo constante y el mensaje de
        // error es el mismo aunque el código exista: así no se puede
        // averiguar qué códigos son válidos.
        if ($pedido && hash_equals(mb_strtolower((string)$pedido['cliente_email']), $correo)) {
            limpiarLimite($pdo, 'seguimiento:' . ip_cliente());
            redirigir(Pedidos::enlaceSeguimiento($pedido));
        }
        $error = 'No encontramos ningún pedido con ese código y ese correo.';
    }
}

$tituloPagina      = 'Seguir mi pedido — ' . Ajustes::texto('nombre_tienda', 'Flowers Anto');
$descripcionPagina = 'Consulta el estado de tu pedido con el código y tu correo.';

require __DIR__ . '/includes/vistas/cabecera.php';
?>

<div class="container">
  <div class="marco-auth">
    <h1>Seguir mi pedido</h1>
    <p class="subtitulo">Escribe el código que te enviamos por correo y el correo con el que pediste.</p>

    <div class="tarjeta">
      <?php if ($error !== ''): ?>
        <div class="caja-aviso error">
          <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i><span><?= e($error) ?></span>
        </div>
      <?php endif; ?>

      <form method="post" action="<?= e(url('seguimiento.php')) ?>" data-una-vez>
        <?= campoToken() ?>
        <div class="campo">
          <label for="codigo">Código del pedido</label>
          <input type="text" id="codigo" name="codigo" required autofocus
                 value="<?= e($codigo) ?>" placeholder="FA-20260829-4F2A" autocomplete="off">
        </div>
        <div class="campo">
          <label for="email">Correo del pedido</label>
          <input type="email" id="email" name="email" required autocomplete="email">
        </div>
        <button type="submit" class="btn btn-primary btn-block">
          <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i> Buscar mi pedido
        </button>
      </form>

      <div class="enlaces-auth">
        <a href="<?= e(url('cuenta/entrar.php')) ?>">Tengo cuenta, iniciar sesión</a>
        <a href="<?= e(enlace_whatsapp('Hola, no encuentro mi pedido.')) ?>" target="_blank" rel="noopener">Escribir por WhatsApp</a>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/vistas/pie.php'; ?>
