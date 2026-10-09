<?php
/**
 * Darse de baja de las promociones por correo, desde el enlace del correo.
 *
 * No hace falta iniciar sesión: el enlace lleva el token aleatorio de la
 * cuenta (`usuarios.token_baja`). Abrir el enlace no da de baja por sí solo
 * —algunos correos abren los enlaces para revisarlos antes que la persona—:
 * se confirma con un botón. Desde aquí también se puede volver a
 * suscribirse. Los avisos de los pedidos no dependen de esto.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

$token   = (string)($_POST['t'] ?? $_GET['t'] ?? '');
$cliente = Campanas::porToken($pdo, $token);
$hecho   = '';

if ($cliente && $_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirToken(false, 'cuenta/baja-promociones.php?t=' . rawurlencode($token));
    $acepta = ($_POST['accion'] ?? '') === 'alta';
    Campanas::cambiarPromociones($pdo, (int)$cliente['id'], $acepta);
    Auditoria::registrar($pdo, 'editar_perfil', 'usuarios', [
        'recurso_tipo' => 'usuario', 'recurso_id' => (string)$cliente['id'],
        'usuario_texto' => (string)$cliente['email'],
        'descripcion'  => $acepta ? 'Volvió a aceptar promociones por correo.' : 'Se dio de baja de las promociones por correo.',
    ]);
    $cliente['acepta_promociones'] = $acepta ? 1 : 0;
    $hecho = $acepta ? 'alta' : 'baja';
}

$tituloPagina = 'Promociones por correo — ' . Ajustes::texto('nombre_tienda', 'Flowers Anto');
require __DIR__ . '/../includes/vistas/cabecera.php';

$correo = $cliente ? '<strong>' . e((string)$cliente['email']) . '</strong>' : '';
?>

<div class="container verificacion-pagina">
  <div class="tarjeta verificacion-tarjeta">
    <?php if (!$cliente): ?>
      <div class="verificacion-icono" aria-hidden="true"><i class="fa-solid fa-triangle-exclamation"></i></div>
      <h1 class="verificacion-titulo">Enlace no válido</h1>
      <p class="verificacion-texto">Este enlace no es válido o ya no existe. Para dejar de recibir promociones,
         inicia sesión y desmárcalo en <a href="<?= e(url('cuenta/perfil.php')) ?>">Mis datos</a>.</p>
    <?php elseif ((int)$cliente['acepta_promociones'] === 1 && $hecho !== 'alta'): ?>
      <div class="verificacion-icono" aria-hidden="true"><i class="fa-solid fa-envelope-open-text"></i></div>
      <h1 class="verificacion-titulo">¿Dejar de recibir promociones?</h1>
      <p class="verificacion-texto">Dejarás de recibir promociones y novedades en <?= $correo ?>.
         Los avisos sobre tus pedidos te seguirán llegando.</p>
      <form class="verificacion-acciones" method="post" action="<?= e(url('cuenta/baja-promociones.php')) ?>">
        <?= campoToken() ?>
        <input type="hidden" name="t" value="<?= e($token) ?>">
        <input type="hidden" name="accion" value="baja">
        <button type="submit" class="btn btn-primary btn-block">Sí, dar de baja</button>
        <a class="btn btn-outline-dark btn-block" href="<?= e(url('')) ?>">No, seguir recibiéndolas</a>
      </form>
    <?php elseif ((int)$cliente['acepta_promociones'] === 1): ?>
      <div class="verificacion-icono" aria-hidden="true"><i class="fa-solid fa-circle-check"></i></div>
      <h1 class="verificacion-titulo">¡De vuelta!</h1>
      <p class="verificacion-texto">Volverás a recibir nuestras promociones en <?= $correo ?>.</p>
      <div class="verificacion-acciones">
        <a class="btn btn-primary btn-block" href="<?= e(url('productos.php')) ?>">Ver los arreglos</a>
      </div>
    <?php else: ?>
      <div class="verificacion-icono" aria-hidden="true"><i class="fa-solid fa-circle-check"></i></div>
      <h1 class="verificacion-titulo">Te diste de baja</h1>
      <p class="verificacion-texto">Ya no te enviaremos promociones a <?= $correo ?>.
         Los avisos sobre tus pedidos te seguirán llegando.</p>
      <form class="verificacion-acciones" method="post" action="<?= e(url('cuenta/baja-promociones.php')) ?>">
        <?= campoToken() ?>
        <input type="hidden" name="t" value="<?= e($token) ?>">
        <input type="hidden" name="accion" value="alta">
        <button type="submit" class="btn btn-outline-dark btn-block">Me equivoqué: quiero volver a recibirlas</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/../includes/vistas/pie.php'; ?>
