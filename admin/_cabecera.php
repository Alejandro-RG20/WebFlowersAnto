<?php
/**
 * Cabecera del panel.
 *
 * Cada página del panel empieza con require de este archivo, que ya exige
 * sesión de personal y el permiso `panel.acceder`. Los permisos concretos de
 * cada sección se exigen después, dentro de la propia página.
 *
 * Variables opcionales: $tituloPanel, $seccion, $accionesCabecera
 */

declare(strict_types=1);

if (!defined('RAIZ')) {
    http_response_code(403);
    exit('Acceso directo no permitido.');
}

Rbac::exigirPanel();

$tienda  = Ajustes::texto('nombre_tienda', 'Flowers Anto');
$usuario = Auth::usuario();
$seccion = $seccion ?? '';
$mensaje = flash();

/** Un elemento del menú lateral, solo si el usuario tiene el permiso. */
function itemMenu(string $clave, string $ruta, string $icono, string $texto, string $permiso, string $seccionActiva): void
{
    if ($permiso !== '' && !Rbac::puede($permiso)) {
        return;
    }
    printf(
        '<a href="%s" class="opcion%s"><i class="%s" aria-hidden="true"></i><span>%s</span></a>',
        e(url('admin/' . $ruta)),
        $seccionActiva === $clave ? ' activa' : '',
        e($icono),
        e($texto)
    );
}

// Contadores del menú: se calculan de una vez para no repetir consultas.
$pendientesPago = 0;
if (Rbac::puede('pedidos.ver')) {
    try {
        $pendientesPago = (int)$pdo->query(
            "SELECT COUNT(*) FROM pedidos WHERE estado_pago IN ('comprobante_recibido','en_revision')"
        )->fetchColumn();
    } catch (PDOException) {
        $pendientesPago = 0;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e(($tituloPanel ?? 'Panel') . ' — ' . $tienda) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="<?= e(url('assets/css/admin.css')) ?>">
<link rel="icon" href="<?= e(url_imagen(Ajustes::texto('favicon_url', 'images/placeholders/logo.svg'))) ?>">
</head>
<body data-base="<?= e(url()) ?>" data-csrf="<?= e(generarToken()) ?>">

<a class="saltar-contenido" href="#panel">Saltar al contenido</a>

<button class="abrir-menu" id="abrirMenu" aria-label="Abrir menú" aria-expanded="false" aria-controls="barraLateral">
  <i class="fa-solid fa-bars" aria-hidden="true"></i>
</button>

<div class="capa-menu" id="capaMenu" hidden></div>

<aside class="barra-lateral" id="barraLateral">
  <div class="marca">
    <img src="<?= e(url_imagen(Ajustes::texto('logo_url', 'images/placeholders/logo.svg'))) ?>" alt="" width="36" height="36">
    <div>
      <strong><?= e($tienda) ?></strong>
      <small>Panel de administración</small>
    </div>
  </div>

  <nav class="menu" aria-label="Secciones del panel">
    <?php itemMenu('resumen',    'index.php',        'fa-solid fa-gauge',          'Resumen',       'dashboard.ver',       $seccion); ?>

    <p class="grupo">Ventas</p>
    <?php
      if (Rbac::puede('pedidos.ver')) {
          printf(
              '<a href="%s" class="opcion%s"><i class="fa-solid fa-receipt" aria-hidden="true"></i><span>Pedidos</span>%s</a>',
              e(url('admin/pedidos.php')),
              $seccion === 'pedidos' ? ' activa' : '',
              $pendientesPago > 0 ? '<span class="globo">' . (int)$pendientesPago . '</span>' : ''
          );
      }
      itemMenu('clientes',     'clientes.php',     'fa-solid fa-users',      'Clientes',     'clientes.ver',     $seccion);
      itemMenu('repartidores', 'repartidores.php', 'fa-solid fa-motorcycle', 'Repartidores', 'repartidores.ver', $seccion);
    ?>

    <p class="grupo">Catálogo</p>
    <?php
      itemMenu('productos',  'productos.php',  'fa-solid fa-seedling',      'Productos',  'productos.ver',        $seccion);
      itemMenu('categorias', 'categorias.php', 'fa-solid fa-tags',          'Categorías', 'categorias.gestionar', $seccion);
      itemMenu('temporadas', 'temporadas.php', 'fa-solid fa-calendar-day',  'Temporadas', 'temporadas.gestionar', $seccion);
      itemMenu('galeria',    'galeria.php',    'fa-solid fa-images',        'Galería',    'galeria.gestionar',    $seccion);
    ?>

    <p class="grupo">Sistema</p>
    <?php
      itemMenu('configuracion', 'configuracion.php', 'fa-solid fa-sliders',       'Configuración', 'configuracion.ver', $seccion);
      itemMenu('empleados',     'empleados.php',     'fa-solid fa-user-shield',   'Empleados',     'empleados.ver',     $seccion);
      itemMenu('roles',         'roles.php',         'fa-solid fa-key',           'Roles y permisos', 'roles.gestionar', $seccion);
      itemMenu('auditoria',     'auditoria.php',     'fa-solid fa-clipboard-list','Auditoría',     'auditoria.ver',     $seccion);
      itemMenu('respaldos',     'respaldos.php',     'fa-solid fa-database',      'Respaldos',     'respaldos.ver',     $seccion);
      itemMenu('base-datos',    'base-datos.php',    'fa-solid fa-server',        'Base de datos', 'sistema.migrar',    $seccion);
    ?>
  </nav>

  <div class="pie-barra">
    <div class="usuario-actual">
      <span class="avatar"><?= e(mb_strtoupper(mb_substr(Auth::nombreCompleto(), 0, 1))) ?></span>
      <div>
        <strong><?= e(Auth::nombreCompleto()) ?></strong>
        <small><?= e((string)($usuario['rol_nombre'] ?? 'Personal')) ?></small>
      </div>
    </div>
    <div class="acciones-pie">
      <a href="<?= e(url()) ?>" target="_blank" rel="noopener" title="Ver el sitio">
        <i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i> Ver el sitio</a>
      <form method="post" action="<?= e(url('cuenta/salir.php')) ?>">
        <?= campoToken() ?>
        <button type="submit"><i class="fa-solid fa-arrow-right-from-bracket" aria-hidden="true"></i> Salir</button>
      </form>
    </div>
  </div>
</aside>

<main class="contenido-panel" id="panel">
  <header class="cabecera-panel">
    <div>
      <h1><?= e($tituloPanel ?? 'Panel') ?></h1>
      <?php if (!empty($subtituloPanel)): ?><p><?= e($subtituloPanel) ?></p><?php endif; ?>
    </div>
    <?php if (!empty($accionesCabecera)): ?>
      <div class="acciones-cabecera"><?= $accionesCabecera ?></div>
    <?php endif; ?>
  </header>

  <?php if ($mensaje): ?>
    <div class="caja-aviso <?= e((string)$mensaje['tipo']) ?>" role="status">
      <i class="fa-solid <?= $mensaje['tipo'] === 'error' ? 'fa-circle-exclamation'
          : ($mensaje['tipo'] === 'exito' ? 'fa-circle-check' : 'fa-circle-info') ?>" aria-hidden="true"></i>
      <span><?= e((string)$mensaje['mensaje']) ?></span>
    </div>
  <?php endif; ?>
