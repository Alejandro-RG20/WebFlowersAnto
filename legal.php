<?php
/**
 * Páginas legales: privacidad, términos y devoluciones.
 *
 * Están en un solo archivo porque comparten estructura y porque el texto sale
 * de la configuración —nombre de la tienda, correo, dirección—, así
 * que la floristería no tiene que editar HTML para cambiar un dato de contacto.
 *
 * No sustituyen a un abogado: son el mínimo honesto que la ley de protección
 * de datos y las pasarelas de pago esperan de una tienda que vende por
 * internet, escrito en un idioma que se entiende.
 */

declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

$doc = opcion('doc', ['privacidad', 'terminos', 'devoluciones'], 'privacidad', $_GET);

$tienda    = Ajustes::texto('nombre_tienda', 'Flowers Anto');
$correo    = Ajustes::texto('email_contacto');
$direccion = Ajustes::texto('direccion');

// En los documentos legales el canal de contacto es el correo, y solo el
// correo: deja constancia escrita de cada reclamo o solicitud, que es lo que
// se espera de una tienda seria. Si aún no hay correo configurado, se manda a
// la sección de contacto de la portada en vez de dejar la frase coja.
$contacto = $correo !== ''
    ? '<a href="mailto:' . e($correo) . '">' . e($correo) . '</a>'
    : '<a href="' . e(url('index.php#contacto')) . '">nuestra sección de contacto</a>';

$titulos = [
    'privacidad'   => 'Política de privacidad',
    'terminos'     => 'Términos y condiciones',
    'devoluciones' => 'Cambios y devoluciones',
];

$tituloPagina      = $titulos[$doc] . ' — ' . $tienda;
$descripcionPagina = $titulos[$doc] . ' de ' . $tienda . '.';
$paginaActiva      = '';

require __DIR__ . '/includes/vistas/cabecera.php';
?>

<div class="container contenido-legal">
  <nav class="migas" aria-label="Ubicación">
    <ol>
      <li><a href="<?= e(url()) ?>">Inicio</a></li>
      <li aria-current="page"><?= e($titulos[$doc]) ?></li>
    </ol>
  </nav>

  <?php // Son enlaces a páginas distintas, no pestañas: `aria-current` es lo
        // que corresponde, y así el lector de pantalla anuncia cuál se está
        // leyendo sin prometer un comportamiento de pestañas que no existe. ?>
  <nav class="legal-pestanas" aria-label="Documentos legales">
    <?php foreach ($titulos as $clave => $texto): ?>
      <a class="legal-pestana<?= $doc === $clave ? ' actual' : '' ?>"
         <?= $doc === $clave ? 'aria-current="page"' : '' ?>
         href="<?= e(url('legal.php?doc=' . $clave)) ?>"><?= e($texto) ?></a>
    <?php endforeach; ?>
  </nav>

  <article class="legal-texto">
    <h1><?= e($titulos[$doc]) ?></h1>
    <p class="legal-fecha">Última actualización: <?= e(fecha_corta(date('Y-m-d'))) ?></p>

<?php require __DIR__ . '/includes/vistas/legal_textos.php'; ?>

    <hr>
    <p class="legal-contacto">
      ¿Alguna duda, queja o solicitud sobre esto? Escríbenos a <?= $contacto ?>.
    </p>
  </article>
</div>

<?php require __DIR__ . '/includes/vistas/pie.php'; ?>
