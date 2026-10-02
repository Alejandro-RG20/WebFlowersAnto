<?php
/**
 * Mis avisos: lo que la tienda le ha escrito al cliente desde el panel.
 *
 * Al abrir la página los avisos pasan a leídos, pero se pintan con la marca
 * de «Nuevo» que tenían al llegar: así se distingue qué es lo nuevo en esta
 * visita y el contador de la cabecera se apaga en la siguiente página.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

Auth::exigirSesion('cuenta/avisos.php');

$usuario = Auth::usuario();
$avisos  = Avisos::deUsuario($pdo, (int)$usuario['id']);
Avisos::marcarLeidos($pdo, (int)$usuario['id']);

$tituloPagina  = 'Mis avisos — ' . Ajustes::texto('nombre_tienda', 'Flowers Anto');
$paginaActiva  = 'cuenta';
$seccionCuenta = 'avisos';

require __DIR__ . '/../includes/vistas/cabecera.php';
?>

<div class="container">
  <header class="pagina-cabecera">
    <h1>Mis avisos</h1>
    <p>Mensajes de la tienda para ti: avisos sobre tus pedidos, sugerencias y regalos.</p>
  </header>

  <div class="diseno-cuenta">
    <?php require __DIR__ . '/../includes/vistas/menu_cuenta.php'; ?>

    <div>
      <?php if (!$avisos): ?>
        <div class="tarjeta">
          <div class="estado-vacio" style="padding:38px 16px;">
            <i class="fa-solid fa-bell" aria-hidden="true"></i>
            <h2>No tienes avisos</h2>
            <p>Cuando la tienda te escriba algo, como un aviso sobre un pedido o un regalo por tu cumpleaños, aparecerá aquí.</p>
          </div>
        </div>
      <?php else: ?>
        <ul class="avisos-cliente">
          <?php foreach ($avisos as $a):
              [$nombreTipo, $iconoTipo] = Avisos::TIPOS[$a['tipo']] ?? Avisos::TIPOS['aviso']; ?>
            <li class="tarjeta aviso-cliente tipo-<?= e((string)$a['tipo']) ?><?= $a['leida_en'] ? '' : ' nuevo' ?>">
              <div class="aviso-cliente-cabecera">
                <span class="aviso-cliente-icono" aria-hidden="true"><i class="fa-solid <?= e($iconoTipo) ?>"></i></span>
                <div>
                  <p class="aviso-cliente-tipo"><?= e($nombreTipo) ?>
                    <?php if (!$a['leida_en']): ?><span class="aviso-cliente-nuevo">Nuevo</span><?php endif; ?></p>
                  <h2 class="aviso-cliente-titulo"><?= e((string)$a['titulo']) ?></h2>
                  <p class="aviso-cliente-fecha"><?= e(fecha_larga((string)$a['created_at'])) ?></p>
                </div>
              </div>
              <p class="aviso-cliente-texto"><?= nl2br(e((string)$a['mensaje'])) ?></p>

              <?php if (!empty($a['cupon_codigo'])):
                  $estado = Avisos::estadoCupon($a);
                  $cupon  = ['tipo' => $a['cupon_tipo'], 'valor' => $a['cupon_valor']]; ?>
                <div class="cupon-regalo estado-<?= e($estado) ?>">
                  <div class="cupon-regalo-datos">
                    <p class="cupon-regalo-etiqueta">Tu cupón · <?= e(Cupones::resumen($cupon)) ?></p>
                    <p class="cupon-regalo-codigo"><?= e((string)$a['cupon_codigo']) ?></p>
                    <p class="cupon-regalo-nota">
                      <?php if ($estado === 'disponible'): ?>
                        <?= (float)$a['cupon_minima'] > 0 ? 'En compras desde ' . e(dinero($a['cupon_minima'])) . '. ' : '' ?>
                        Válido hasta el <?= e(date('d/m/Y', strtotime((string)$a['cupon_hasta']))) ?>, una sola vez,
                        con tu cuenta iniciada. Escríbelo al completar el pedido.
                      <?php elseif ($estado === 'usado'): ?>
                        Ya lo usaste. ¡Esperamos que te gustaran las flores!
                      <?php elseif ($estado === 'vencido'): ?>
                        Venció el <?= e(date('d/m/Y', strtotime((string)$a['cupon_hasta']))) ?>.
                      <?php else: ?>
                        Este cupón ya no está disponible.
                      <?php endif; ?>
                    </p>
                  </div>
                  <?php if ($estado === 'disponible'): ?>
                    <div class="cupon-regalo-acciones">
                      <button type="button" class="btn btn-outline-dark" data-copiar="<?= e((string)$a['cupon_codigo']) ?>">
                        Copiar código</button>
                      <a class="btn btn-primary" href="<?= e(url('productos.php')) ?>">Elegir mi arreglo</a>
                    </div>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  </div>
</div>

<div style="height:56px"></div>
<?php require __DIR__ . '/../includes/vistas/pie.php'; ?>
