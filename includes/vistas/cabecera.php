<?php
/**
 * Cabecera común de las páginas públicas.
 *
 * Antes de incluirla se pueden definir:
 *   $tituloPagina, $descripcionPagina, $imagenOg, $urlCanonica,
 *   $cuerpoClase, $ocultarNav, $datosEstructurados (array JSON-LD)
 */

declare(strict_types=1);

if (!defined('RAIZ')) {
    http_response_code(403);
    exit('Acceso directo no permitido.');
}

cabeceraCSP();

$cfg     = Ajustes::todos();
$tienda  = Ajustes::texto('nombre_tienda', 'Flowers Anto');
$usuario = Auth::usuario();

$tituloPagina      = $tituloPagina      ?? $tienda . ' — Arreglos florales en Managua';
$descripcionPagina = $descripcionPagina ?? Ajustes::texto('meta_descripcion',
    'Arreglos florales hechos a mano en Managua. Ramos, cajas y arreglos de temporada.');
$imagenOg    = $imagenOg    ?? Ajustes::texto('og_imagen', Ajustes::texto('hero_imagen', 'images/placeholders/hero-01.svg'));
$urlCanonica = $urlCanonica ?? url_absoluta(ltrim((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/'));
$cuerpoClase = $cuerpoClase ?? '';

$unidadesCarrito = Carrito::unidades();
$totalFavoritos  = Favoritos::total($pdo);
$mensajeFlash    = flash();

$waGeneral = enlace_whatsapp(
    Ajustes::texto('whatsapp_mensaje', 'Hola ' . $tienda . ', quiero asesoría para mi arreglo')
);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($tituloPagina) ?></title>
<meta name="description" content="<?= e($descripcionPagina) ?>">
<meta name="theme-color" content="<?= e(Ajustes::texto('hero_color_fondo', '#EFD9DE')) ?>">
<link rel="canonical" href="<?= e($urlCanonica) ?>">

<meta property="og:type" content="website">
<meta property="og:site_name" content="<?= e($tienda) ?>">
<meta property="og:title" content="<?= e($tituloPagina) ?>">
<meta property="og:description" content="<?= e($descripcionPagina) ?>">
<meta property="og:url" content="<?= e($urlCanonica) ?>">
<meta property="og:image" content="<?= e(preg_match('#^https?://#i', $imagenOg) ? $imagenOg : url_absoluta($imagenOg)) ?>">
<meta name="twitter:card" content="summary_large_image">

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700;900&family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="<?= e(url('assets/css/estilos.css')) ?>">
<link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>">
<?php if (!empty($cssExtra)): foreach ((array)$cssExtra as $hoja): ?>
<link rel="stylesheet" href="<?= e(url($hoja)) ?>">
<?php endforeach; endif; ?>
<link rel="icon" href="<?= e(url_imagen(Ajustes::texto('favicon_url', 'images/placeholders/logo.svg'))) ?>">
<style>
  :root{
    --rose-pastel: <?= e(Ajustes::texto('color_primario', '#F8B0C2')) ?>;
    --rose-light:  <?= e(Ajustes::texto('color_secundario', '#FADADD')) ?>;
    --cream:       <?= e(Ajustes::texto('color_fondo', '#FFF9F5')) ?>;
    --text:        <?= e(Ajustes::texto('color_texto', '#4A3B3D')) ?>;
  }
</style>
<?php if (!empty($datosEstructurados)): ?>
<script type="application/ld+json"><?= json_para_html($datosEstructurados) ?></script>
<?php endif; ?>
</head>
<body class="<?= e($cuerpoClase) ?>" data-base="<?= e(url()) ?>" data-csrf="<?= e(generarToken()) ?>">

<a class="saltar-contenido" href="#contenido">Saltar al contenido</a>

<nav class="navbar" id="navbar">
  <div class="nav-container">
    <a href="<?= e(url()) ?>" class="logo" aria-label="<?= e($tienda) ?> — Inicio">
      <span class="logo-icon"><img src="<?= e(url_imagen(Ajustes::texto('logo_url', 'images/logoanto.jpeg'))) ?>" alt="" width="60" height="60"></span>
      <span class="logo-text"><?= e($tienda) ?></span>
    </a>

    <ul class="nav-menu" id="navMenu">
      <li><a href="<?= e(url()) ?>" class="nav-link<?= ($paginaActiva ?? '') === 'inicio' ? ' active' : '' ?>">Inicio</a></li>
      <li><a href="<?= e(url('productos.php')) ?>" class="nav-link<?= ($paginaActiva ?? '') === 'productos' ? ' active' : '' ?>">Arreglos</a></li>
      <li><a href="<?= e(url('favoritos.php')) ?>" class="nav-link<?= ($paginaActiva ?? '') === 'favoritos' ? ' active' : '' ?>">Favoritos</a></li>
      <li><a href="<?= e(url('index.php#nosotros')) ?>" class="nav-link">Nosotros</a></li>
      <li><a href="<?= e(url('index.php#contacto')) ?>" class="nav-link">Contacto</a></li>
      <?php if (Auth::autenticado()): ?>
        <li><a href="<?= e(url('cuenta/pedidos.php')) ?>" class="nav-link<?= ($paginaActiva ?? '') === 'cuenta' ? ' active' : '' ?>">Mi cuenta</a></li>
      <?php endif; ?>
    </ul>

    <div class="nav-actions">
      <a href="<?= e($waGeneral) ?>" target="_blank" rel="noopener" class="btn-nav-whatsapp" aria-label="Escribir por WhatsApp">
        <i class="fa-brands fa-whatsapp" aria-hidden="true"></i><span>Escríbenos</span>
      </a>

      <a href="<?= e(url('favoritos.php')) ?>" class="icon-btn" aria-label="Favoritos<?= $totalFavoritos ? " ($totalFavoritos)" : '' ?>">
        <i class="fa-regular fa-heart" aria-hidden="true"></i>
        <span class="icon-count" id="favCount"<?= $totalFavoritos ? '' : ' hidden' ?>><?= (int)$totalFavoritos ?></span>
      </a>

      <a href="<?= e(url('carrito.php')) ?>" class="icon-btn" aria-label="Carrito<?= $unidadesCarrito ? " ($unidadesCarrito)" : '' ?>">
        <i class="fas fa-shopping-cart" aria-hidden="true"></i>
        <span class="icon-count" id="cartCount"<?= $unidadesCarrito ? '' : ' hidden' ?>><?= (int)$unidadesCarrito ?></span>
      </a>

      <?php if (Auth::autenticado()): ?>
        <div class="menu-usuario">
          <button type="button" class="icon-btn" id="btnUsuario" aria-haspopup="true" aria-expanded="false"
                  aria-label="Menú de <?= e(Auth::nombreCompleto()) ?>">
            <i class="fa-regular fa-user" aria-hidden="true"></i>
          </button>
          <div class="menu-usuario-lista" id="menuUsuario" hidden>
            <p class="menu-usuario-nombre"><?= e(Auth::nombreCompleto()) ?><span><?= e((string)($usuario['email'] ?? '')) ?></span></p>
            <a href="<?= e(url('cuenta/pedidos.php')) ?>"><i class="fa-solid fa-box" aria-hidden="true"></i> Mis pedidos</a>
            <a href="<?= e(url('cuenta/perfil.php')) ?>"><i class="fa-solid fa-id-card" aria-hidden="true"></i> Mis datos</a>
            <a href="<?= e(url('favoritos.php')) ?>"><i class="fa-regular fa-heart" aria-hidden="true"></i> Favoritos</a>
            <?php if (Auth::esPersonal()): ?>
              <a href="<?= e(url('admin/')) ?>"><i class="fa-solid fa-gauge" aria-hidden="true"></i> Panel</a>
            <?php endif; ?>
            <form method="post" action="<?= e(url('cuenta/salir.php')) ?>">
              <?= campoToken() ?>
              <button type="submit"><i class="fa-solid fa-arrow-right-from-bracket" aria-hidden="true"></i> Cerrar sesión</button>
            </form>
          </div>
        </div>
      <?php else: ?>
        <a href="<?= e(url('cuenta/entrar.php')) ?>" class="icon-btn" aria-label="Iniciar sesión">
          <i class="fa-regular fa-user" aria-hidden="true"></i>
        </a>
      <?php endif; ?>

      <button class="hamburger" id="hamburger" aria-label="Abrir menú" aria-expanded="false" aria-controls="navMenu">
        <span></span><span></span><span></span>
      </button>
    </div>
  </div>
</nav>

<div class="toast-container" id="toastContainer" aria-live="polite" aria-atomic="true"></div>

<?php if ($mensajeFlash): ?>
<div class="aviso-global aviso-<?= e((string)$mensajeFlash['tipo']) ?>" role="status">
  <div class="container">
    <i class="fa-solid <?= $mensajeFlash['tipo'] === 'error' ? 'fa-circle-exclamation'
        : ($mensajeFlash['tipo'] === 'exito' ? 'fa-circle-check' : 'fa-circle-info') ?>" aria-hidden="true"></i>
    <span><?= e((string)$mensajeFlash['mensaje']) ?></span>
  </div>
</div>
<?php endif; ?>

<main id="contenido">
