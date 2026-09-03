<?php
/**
 * Páginas legales: privacidad, términos y devoluciones.
 *
 * Están en un solo archivo porque comparten estructura y porque el texto sale
 * de la configuración —nombre de la tienda, correo, teléfono, dirección—, así
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
$telefono  = Ajustes::texto('telefono');
$direccion = Ajustes::texto('direccion');
$whatsapp  = enlace_whatsapp('Hola, tengo una consulta sobre mis datos personales.');

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

<?php if ($doc === 'privacidad'): ?>
    <p>Esta página explica qué datos tuyos guarda <strong><?= e($tienda) ?></strong>,
       para qué los usamos y qué puedes pedirnos que hagamos con ellos.</p>

    <h2>Quién es responsable de tus datos</h2>
    <p><?= e($tienda) ?><?= $direccion !== '' ? ', ' . e($direccion) : '' ?>.
       Para cualquier asunto sobre tus datos puedes escribirnos
       <?php if ($correo !== ''): ?>a <a href="mailto:<?= e($correo) ?>"><?= e($correo) ?></a><?php endif; ?>
       <?php if ($telefono !== ''): ?> o llamarnos al <?= e($telefono) ?><?php endif; ?>,
       o por <a href="<?= e($whatsapp) ?>" target="_blank" rel="noopener">WhatsApp</a>.</p>

    <h2>Qué guardamos y por qué</h2>
    <ul>
      <li><strong>Para entregar tu pedido:</strong> tu nombre, teléfono, correo, la dirección
          de entrega y la referencia para llegar, la fecha y la franja que elegiste, y la
          dedicatoria si escribiste una. Sin esto no podemos llevar las flores.</li>
      <li><strong>Para cobrarte:</strong> el método de pago y, si pagaste por transferencia,
          el comprobante que subiste. <strong>Nunca guardamos números de tarjeta:</strong>
          cuando pagas con tarjeta o PayPal, los datos van directamente a PayPal y no pasan
          por esta web.</li>
      <li><strong>Si creas una cuenta:</strong> tu correo y una versión cifrada de tu
          contraseña, que nadie —tampoco nosotros— puede leer. Además tus direcciones
          guardadas y tus favoritos, para que no tengas que escribirlos otra vez.</li>
      <li><strong>Para que la tienda funcione:</strong> una cookie propia que mantiene tu
          carrito y tu sesión, y un registro técnico de accesos al panel de administración.</li>
    </ul>

    <h2>Con quién se comparten</h2>
    <p>Con nadie para fines comerciales. <strong>No vendemos ni cedemos tus datos.</strong>
       Solo los ven:</p>
    <ul>
      <li>El equipo de <?= e($tienda) ?>, para preparar y entregar tu pedido.</li>
      <li>La persona que hace el reparto, que ve tu nombre, teléfono y dirección de entrega.</li>
      <li>PayPal, si eliges pagar con ellos, según
          <a href="https://www.paypal.com/es/webapps/mpp/ua/privacy-full" target="_blank"
             rel="noopener noreferrer">su propia política de privacidad</a>.</li>
      <li>El proveedor que aloja la web, que guarda la base de datos por nosotros.</li>
    </ul>

    <h2>Cuánto tiempo</h2>
    <p>Los pedidos se conservan mientras hagan falta para atender garantías, reclamaciones y
       obligaciones contables. Los comprobantes de pago se guardan mientras el pedido esté
       vivo. Si nos pides que borremos tu cuenta, la borramos, salvo lo que estemos obligados
       a conservar por ley.</p>

    <h2>Qué puedes pedirnos</h2>
    <ul>
      <li>Ver qué datos tuyos tenemos.</li>
      <li>Corregir cualquier dato equivocado.</li>
      <li>Borrar tu cuenta y tus datos.</li>
      <li>Llevarte una copia de tu información.</li>
    </ul>
    <p>Escríbenos y lo resolvemos. No cobramos por ello y no hace falta que expliques
       por qué.</p>

    <h2>Cookies</h2>
    <p>Usamos una cookie propia imprescindible: la que mantiene tu carrito y tu sesión. Sin
       ella la tienda no funciona. Aparte, si lo aceptas, guardamos tus favoritos en tu
       propio navegador para no perderlos.
       <strong>No usamos publicidad ni rastreadores de otras empresas.</strong>
       Puedes cambiar tu decisión cuando quieras desde el enlace «Cookies» del pie de página.</p>

    <h2>Menores</h2>
    <p>Esta tienda está pensada para personas mayores de edad. Si crees que un menor nos ha
       dado sus datos, avísanos y los borramos.</p>

<?php elseif ($doc === 'terminos'): ?>
    <p>Al hacer un pedido en <strong><?= e($tienda) ?></strong> aceptas lo que sigue.
       Está escrito para que se entienda, no para esconder nada.</p>

    <h2>Los productos</h2>
    <p>Trabajamos con flor natural. Las fotos son de arreglos reales, pero
       <strong>cada arreglo se monta a mano y con la flor disponible ese día</strong>, así que
       puede haber diferencias de tono, tamaño o variedad. Si una flor concreta no está,
       la sustituimos por otra de valor y aspecto equivalentes, y te avisamos si el cambio
       es importante.</p>

    <h2>Precios</h2>
    <p>Los precios están en <?= e(Ajustes::texto('moneda_local', 'C$')) ?> e incluyen el
       arreglo montado. El costo de envío se calcula según la zona y se muestra antes de
       confirmar. Si pagas con PayPal, el cobro se hace en
       <?= e(PayPal::moneda()) ?> convertido con la tasa que aparece en el momento de pagar.</p>

    <h2>Pedidos y pago</h2>
    <ul>
      <li>Un pedido queda confirmado cuando el pago está verificado. Con PayPal es
          inmediato; con transferencia, cuando una persona del equipo revisa tu comprobante.</li>
      <li>Nos reservamos el derecho de no aceptar un pedido si no podemos cumplirlo
          (falta de flor, zona fuera de reparto, fecha imposible). En ese caso se devuelve
          el importe completo.</li>
      <li>Los precios y la disponibilidad pueden cambiar sin aviso, pero
          <strong>nunca después de que confirmes tu pedido</strong>.</li>
    </ul>

    <h2>Entregas</h2>
    <ul>
      <li>Entregamos en las zonas que aparecen al hacer el pedido. La fecha y la franja
          que eliges son las que intentamos cumplir.</li>
      <li><strong>Necesitamos una dirección con referencias</strong>. Si no podemos
          encontrar el lugar y nadie contesta el teléfono, el pedido vuelve a la tienda y
          te contactamos para reprogramar.</li>
      <li>Si no hay nadie para recibir, intentamos entregar a un vecino o portero, salvo
          que nos digas lo contrario.</li>
    </ul>

    <h2>Dedicatorias y contenido</h2>
    <p>La dedicatoria la escribes tú y se entrega tal cual. No aceptamos mensajes que
       insulten, amenacen o acosen a la persona que recibe.</p>

    <h2>Tu cuenta</h2>
    <p>Si creas una cuenta, eres responsable de tu contraseña. Avísanos si crees que
       alguien más ha entrado en ella.</p>

    <h2>Ley aplicable</h2>
    <p>Estas condiciones se rigen por la ley de la República de Nicaragua.</p>

<?php else: ?>
    <p>Vendemos flor natural, que es perecedera. Aun así, si algo sale mal
       <strong>lo arreglamos</strong>. Así funciona.</p>

    <h2>Si el arreglo llega en mal estado</h2>
    <p><strong>Avísanos el mismo día de la entrega</strong> y mándanos una foto por
       <a href="<?= e($whatsapp) ?>" target="_blank" rel="noopener">WhatsApp</a>.
       Reponemos el arreglo sin costo o te devolvemos el dinero, lo que prefieras.
       No hace falta que devuelvas las flores.</p>

    <h2>Si no era lo que pediste</h2>
    <p>Si te entregamos un arreglo distinto al que compraste, lo cambiamos por el correcto
       o te devolvemos el importe completo, incluido el envío.</p>

    <h2>Si quieres cancelar</h2>
    <ul>
      <li><strong>Antes de que preparemos el arreglo:</strong> devolución completa.</li>
      <li><strong>Ya preparado pero sin salir a reparto:</strong> se devuelve el envío y
          se estudia el resto según el caso.</li>
      <li><strong>Ya entregado:</strong> por ser producto perecedero no se aceptan
          devoluciones por cambio de opinión, salvo los casos de arriba.</li>
    </ul>

    <h2>Cómo se devuelve el dinero</h2>
    <p>Por la misma vía por la que pagaste. Si pagaste con PayPal o tarjeta, el reembolso
       se hace desde PayPal y suele tardar entre tres y diez días hábiles en aparecer en tu
       cuenta, según tu banco. Si pagaste por transferencia, te lo transferimos de vuelta.</p>

    <h2>Cómo pedirlo</h2>
    <p>Escríbenos por <a href="<?= e($whatsapp) ?>" target="_blank" rel="noopener">WhatsApp</a>
       <?php if ($correo !== ''): ?>o a <a href="mailto:<?= e($correo) ?>"><?= e($correo) ?></a><?php endif; ?>
       con tu número de pedido. Te respondemos el mismo día.</p>
<?php endif; ?>

    <hr>
    <p class="legal-contacto">
      ¿Alguna duda sobre esto? Escríbenos por
      <a href="<?= e($whatsapp) ?>" target="_blank" rel="noopener">WhatsApp</a><?php
        if ($correo !== ''): ?> o a <a href="mailto:<?= e($correo) ?>"><?= e($correo) ?></a><?php endif; ?>.
    </p>
  </article>
</div>

<?php require __DIR__ . '/includes/vistas/pie.php'; ?>
