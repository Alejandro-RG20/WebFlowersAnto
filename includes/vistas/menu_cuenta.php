<?php
/** Menú lateral del área de cliente. Espera $seccionCuenta. */
declare(strict_types=1);
$seccion = $seccionCuenta ?? '';
?>
<nav class="menu-cuenta" aria-label="Secciones de mi cuenta">
  <a href="<?= e(url('cuenta/pedidos.php')) ?>" class="<?= $seccion === 'pedidos' ? 'activo' : '' ?>">
    <i class="fa-solid fa-box" aria-hidden="true"></i> Mis pedidos</a>
  <a href="<?= e(url('cuenta/perfil.php')) ?>" class="<?= $seccion === 'perfil' ? 'activo' : '' ?>">
    <i class="fa-solid fa-id-card" aria-hidden="true"></i> Mis datos</a>
  <a href="<?= e(url('favoritos.php')) ?>">
    <i class="fa-regular fa-heart" aria-hidden="true"></i> Favoritos</a>
  <a href="<?= e(url('carrito.php')) ?>">
    <i class="fa-solid fa-cart-shopping" aria-hidden="true"></i> Carrito</a>
  <?php if (Auth::esPersonal()): ?>
    <a href="<?= e(url('admin/')) ?>"><i class="fa-solid fa-gauge" aria-hidden="true"></i> Panel</a>
  <?php endif; ?>
</nav>
