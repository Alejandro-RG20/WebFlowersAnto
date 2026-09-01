<?php
/**
 * Configuración general del sitio.
 *
 * Todo lo que aquí se administra deja de estar escrito en el código: marca,
 * colores, portada, contacto, pedidos, cuentas bancarias y créditos del
 * desarrollador.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

$seccion = 'configuracion';
Rbac::exigirPanel();
Rbac::exigir('configuracion.ver');

$pestana = opcion('t', ['marca', 'portada', 'contacto', 'pedidos', 'envio', 'avisos', 'banco', 'desarrollador'], 'marca', $_GET);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirToken(false, 'admin/configuracion.php');
    Rbac::exigir('configuracion.editar');

    $accion = opcion('accion', ['guardar', 'cuenta_guardar', 'cuenta_eliminar',
                                'zona_guardar', 'zona_eliminar', 'mensajes_guardar',
                                'correo_prueba'], 'guardar');

    // --- Correo de prueba -----------------------------------------------
    if ($accion === 'correo_prueba') {
        // Sin esto no hay forma de saber si el correo sale. El transporte
        // «log» no envía nada y se comporta igual que uno bien configurado:
        // en silencio. Aquí se prueba de verdad y se dice qué pasó.
        $destino = correoValido('correo_destino')
                ?: Ajustes::texto('email_avisos', Ajustes::texto('email_contacto'));

        if ($destino === '') {
            flash('error', 'Escribe una dirección a la que mandar la prueba.');
            redirigir('admin/configuracion.php?t=avisos');
        }

        $html = Correo::plantilla(
            'Prueba de correo',
            '<p>Si estás leyendo esto, el correo de la tienda funciona: este mensaje salió '
            . 'desde el panel de <strong>' . e(Ajustes::texto('nombre_tienda', 'Flowers Anto')) . '</strong>.</p>'
            . '<p>Así es como van a ver tus clientes los avisos de su pedido.</p>'
            . '<p style="color:#8A7A7D;font-size:13.5px;">Transporte: <strong>' . e(Correo::transporte())
            . '</strong> · Enviado el ' . e(date('d/m/Y H:i')) . '</p>',
            ['url' => url_absoluta('admin/'), 'texto' => 'Ir al panel']
        );
        $ok = Correo::enviar($destino, 'Prueba de correo — ' . Ajustes::texto('nombre_tienda', 'Flowers Anto'), $html);

        Auditoria::registrar($pdo, 'editar', 'sistema', [
            'recurso_tipo' => 'correo', 'recurso_id' => 'prueba',
            'resultado'    => $ok ? 'exito' : 'error',
            'descripcion'  => 'Correo de prueba a ' . $destino . ' (' . Correo::transporte() . ').',
        ]);

        if ($ok && !Correo::entregaDeVerdad()) {
            flash('info', 'El mensaje se escribió en storage/logs/correos.log, pero NO se envió: '
                        . 'MAIL_TRANSPORTE está en «log». Cámbialo a «smtp» en el .env para que salga de verdad.');
        } elseif ($ok) {
            flash('exito', 'Correo de prueba enviado a ' . $destino . '. Revisa la bandeja y también el spam.');
        } else {
            flash('error', 'No se pudo enviar: ' . (Correo::ultimoError() ?: 'el transporte rechazó el mensaje.'));
        }
        redirigir('admin/configuracion.php?t=avisos');
    }

    // --- Zonas de envío -------------------------------------------------
    if ($accion === 'zona_eliminar') {
        $zonaId = identificador('zona_id');

        // Los pedidos guardan el nombre de la zona en su propia columna, así
        // que borrarla no borra el historial: solo deja de ofrecerse.
        $pdo->prepare("DELETE FROM zonas_envio WHERE id = ?")->execute([$zonaId]);
        Auditoria::registrar($pdo, 'eliminar', 'sistema', [
            'recurso_tipo' => 'zona_envio', 'recurso_id' => (string)$zonaId,
            'descripcion'  => 'Zona de envío eliminada.',
        ]);
        flash('exito', 'Zona eliminada.');
        redirigir('admin/configuracion.php?t=envio');
    }

    if ($accion === 'zona_guardar') {
        $zonaId = identificador('zona_id');
        $nombre = texto('zona_nombre', 100);

        if ($nombre === '') {
            flash('error', 'La zona necesita un nombre.');
            redirigir('admin/configuracion.php?t=envio');
        }

        $datos = [
            $nombre,
            texto('zona_descripcion', 255),
            decimal('zona_costo'),
            casilla('zona_es_managua'),
            entero('zona_orden', 0, 999, 0),
            casilla('zona_activo'),
        ];

        try {
            if ($zonaId > 0) {
                $datos[] = $zonaId;
                $pdo->prepare(
                    "UPDATE zonas_envio SET nombre = ?, descripcion = ?, costo = ?,
                            es_managua = ?, orden = ?, activo = ? WHERE id = ?"
                )->execute($datos);
            } else {
                $pdo->prepare(
                    "INSERT INTO zonas_envio (nombre, descripcion, costo, es_managua, orden, activo)
                     VALUES (?,?,?,?,?,?)"
                )->execute($datos);
                $zonaId = (int)$pdo->lastInsertId();
            }
        } catch (PDOException $ex) {
            // El nombre es único: dos zonas iguales confundirían al cliente.
            flash('error', 'Ya existe una zona con ese nombre.');
            redirigir('admin/configuracion.php?t=envio');
        }

        Auditoria::registrar($pdo, 'editar', 'sistema', [
            'recurso_tipo' => 'zona_envio', 'recurso_id' => (string)$zonaId,
            'descripcion'  => 'Zona de envío guardada: ' . $nombre,
        ]);
        flash('exito', 'Zona guardada.');
        redirigir('admin/configuracion.php?t=envio');
    }

    // --- Textos de los correos de estado --------------------------------
    if ($accion === 'mensajes_guardar') {
        $up = $pdo->prepare(
            "UPDATE estados_pedido SET mensaje_correo = ?, avisar_cliente = ? WHERE id = ?"
        );
        $mensajes = (array)($_POST['mensaje'] ?? []);
        $avisos   = (array)($_POST['avisar'] ?? []);
        $tocados  = 0;

        foreach ($mensajes as $id => $texto) {
            $id = (int)$id;
            if ($id <= 0) {
                continue;
            }
            $up->execute([
                mb_substr(trim((string)$texto), 0, 1000),
                isset($avisos[$id]) ? 1 : 0,
                $id,
            ]);
            $tocados++;
        }

        Auditoria::registrar($pdo, 'editar', 'sistema', [
            'recurso_tipo' => 'estados_pedido', 'recurso_id' => 'correos',
            'descripcion'  => 'Textos de los avisos por correo actualizados (' . $tocados . ' estados).',
        ]);
        flash('exito', 'Mensajes de los correos guardados.');
        redirigir('admin/configuracion.php?t=avisos');
    }

    // --- Cuentas bancarias ---------------------------------------------
    if ($accion === 'cuenta_eliminar') {
        $cuentaId = identificador('cuenta_id');
        $pdo->prepare("DELETE FROM cuentas_bancarias WHERE id = ?")->execute([$cuentaId]);
        Auditoria::registrar($pdo, 'eliminar', 'sistema', [
            'recurso_tipo' => 'cuenta_bancaria', 'recurso_id' => (string)$cuentaId,
            'descripcion'  => 'Cuenta bancaria eliminada.',
        ]);
        flash('exito', 'Cuenta bancaria eliminada.');
        redirigir('admin/configuracion.php?t=banco');
    }

    if ($accion === 'cuenta_guardar') {
        $cuentaId = identificador('cuenta_id');
        $datos = [
            texto('banco', 80),
            texto('titular', 120),
            texto('numero_cuenta', 60),
            texto('tipo_cuenta', 40),
            texto('moneda', 20),
            texto('identificacion', 40),
            texto('notas_cuenta', 255),
            entero('orden_cuenta', 0, 999),
            casilla('activo_cuenta'),
        ];

        if ($datos[0] === '' || $datos[1] === '' || $datos[2] === '') {
            flash('error', 'Banco, titular y número de cuenta son obligatorios.');
            redirigir('admin/configuracion.php?t=banco');
        }

        if ($cuentaId > 0) {
            $datos[] = $cuentaId;
            $pdo->prepare(
                "UPDATE cuentas_bancarias SET banco = ?, titular = ?, numero_cuenta = ?, tipo_cuenta = ?,
                        moneda = ?, identificacion = ?, notas = ?, orden = ?, activo = ?
                  WHERE id = ?"
            )->execute($datos);
        } else {
            $pdo->prepare(
                "INSERT INTO cuentas_bancarias (banco, titular, numero_cuenta, tipo_cuenta, moneda,
                                                identificacion, notas, orden, activo)
                 VALUES (?,?,?,?,?,?,?,?,?)"
            )->execute($datos);
            $cuentaId = (int)$pdo->lastInsertId();
        }

        // El número de cuenta no se anota entero en la auditoría.
        Auditoria::registrar($pdo, 'editar', 'sistema', [
            'recurso_tipo' => 'cuenta_bancaria', 'recurso_id' => (string)$cuentaId,
            'descripcion'  => 'Cuenta bancaria guardada: ' . $datos[0] . ' (' . $datos[4] . ')',
        ]);
        flash('exito', 'Cuenta bancaria guardada.');
        redirigir('admin/configuracion.php?t=banco');
    }

    // --- Configuración general ------------------------------------------
    // La lista tiene que incluir TODAS las pestañas que guardan ajustes. Si
    // falta una, su POST caería en el grupo por defecto y guardaría los campos
    // de otra pestaña con el formulario vacío, borrándolos. Por eso el valor
    // por defecto es vacío y abajo se rechaza en vez de escribir nada.
    $grupo  = opcion('grupo', ['marca', 'portada', 'contacto', 'pedidos',
                               'envio', 'avisos', 'banco', 'desarrollador'], '');
    $antes  = Ajustes::todos();

    $campos = match ($grupo) {
        'marca' => [
            'nombre_tienda'    => texto('nombre_tienda', 100),
            'eslogan'          => texto('eslogan', 150),
            'logo_url'         => rutaImagen('logo_url', (string)($antes['logo_url'] ?? '')),
            'favicon_url'      => rutaImagen('favicon_url', (string)($antes['favicon_url'] ?? '')),
            'color_primario'   => colorHex('color_primario', '#F8B0C2'),
            'color_secundario' => colorHex('color_secundario', '#FADADD'),
            'color_fondo'      => colorHex('color_fondo', '#FFF9F5'),
            'color_texto'      => colorHex('color_texto', '#4A3B3D'),
            'meta_descripcion' => texto('meta_descripcion', 300),
            'og_imagen'        => rutaImagen('og_imagen', (string)($antes['og_imagen'] ?? '')),
            'moneda_local'     => texto('moneda_local', 5),
            'mostrar_usd'      => casilla('mostrar_usd'),
        ],
        'portada' => [
            'hero_palabra'     => mb_strtoupper(texto('hero_palabra', 20)),
            'hero_titulo'      => texto('hero_titulo', 200),
            'hero_subtitulo'   => texto('hero_subtitulo', 255),
            'hero_cta_texto'   => texto('hero_cta_texto', 40),
            'hero_imagen'      => rutaImagen('hero_imagen', (string)($antes['hero_imagen'] ?? '')),
            'hero_color_fondo' => colorHex('hero_color_fondo', '#EFD9DE'),
            'hero_autoplay'    => casilla('hero_autoplay'),
            'nosotros_titulo'  => texto('nosotros_titulo', 150),
            'nosotros_texto'   => textoLargo('nosotros_texto', 2000),
            'nosotros_imagen'  => rutaImagen('nosotros_imagen', (string)($antes['nosotros_imagen'] ?? '')),
        ],
        'contacto' => [
            'whatsapp_numero'  => soloDigitos('whatsapp_numero'),
            'whatsapp_mensaje' => texto('whatsapp_mensaje', 255),
            'telefono'         => texto('telefono', 20),
            'email_contacto'   => correoValido('email_contacto'),
            'direccion'        => texto('direccion', 200),
            'horario'          => texto('horario', 150),
            'facebook_url'     => urlOpcional('facebook_url'),
            'instagram_url'    => urlOpcional('instagram_url'),
            'tiktok_url'       => urlOpcional('tiktok_url'),
        ],
        'pedidos' => [
            'pedido_web_activo'      => casilla('pedido_web_activo'),
            'pedido_whatsapp_activo' => casilla('pedido_whatsapp_activo'),
            'whatsapp_pedidos'       => soloDigitos('whatsapp_pedidos'),
            'permitir_invitado'      => casilla('permitir_invitado'),
            'permitir_retiro'        => casilla('permitir_retiro'),
            'pago_efectivo_activo'   => casilla('pago_efectivo_activo'),
            'cupones_activos'        => casilla('cupones_activos'),
            'franjas_entrega'        => texto('franjas_entrega', 300),
        ],
        'envio' => [
            'costo_envio'         => decimal('costo_envio'),
            'envio_gratis_desde'  => decimal('envio_gratis_desde'),
            'pedir_mapa_url'      => casilla('pedir_mapa_url'),
            'mensaje_repartidor'  => textoLargo('mensaje_repartidor', 1500),
        ],
        'avisos' => [
            'email_avisos'   => correoValido('email_avisos'),
            'avisar_pedidos' => casilla('avisar_pedidos'),
            'avisar_pagos'   => casilla('avisar_pagos'),
        ],
        'banco' => [
            'instrucciones_pago' => textoLargo('instrucciones_pago', 1500),
        ],
        'desarrollador' => [
            'dev_activo'      => casilla('dev_activo'),
            'dev_nombre'      => texto('dev_nombre', 80),
            'dev_descripcion' => texto('dev_descripcion', 200),
            'dev_logo'        => rutaImagen('dev_logo', (string)($antes['dev_logo'] ?? '')),
            'dev_url'         => urlOpcional('dev_url'),
        ],
        default => [],
    };

    if (!$campos) {
        // Grupo desconocido: no se toca la base. Antes de escribir hay que
        // saber exactamente qué columnas toca este formulario.
        error_log('Flowers Anto — configuración: grupo no reconocido «' . crudo('grupo') . '»');
        flash('error', 'No reconocimos qué sección intentabas guardar. No se cambió nada.');
        redirigir('admin/configuracion.php');
    }

    $asignaciones = implode(', ', array_map(fn($c) => "`$c` = ?", array_keys($campos)));
    $pdo->prepare("UPDATE configuracion SET $asignaciones WHERE id = 1")
        ->execute(array_values($campos));
    Ajustes::refrescar();

    Auditoria::registrar($pdo, 'editar', 'sistema', [
        'recurso_tipo' => 'configuracion', 'recurso_id' => $grupo,
        'descripcion'  => 'Configuración actualizada: ' . $grupo,
        'detalles'     => Auditoria::diferencias($antes, $campos, array_keys($campos)),
    ]);
    flash('exito', 'Configuración guardada.');
    redirigir('admin/configuracion.php?t=' . $grupo);
}

$c       = Ajustes::todos();
$cuentas = $pdo->query("SELECT * FROM cuentas_bancarias ORDER BY orden, id")->fetchAll();

// Zonas de envío y textos de los correos: solo se leen en sus pestañas.
$zonas = $pestana === 'envio'
    ? $pdo->query("SELECT * FROM zonas_envio ORDER BY es_managua DESC, orden, nombre")->fetchAll()
    : [];
$estadosCorreo = $pestana === 'avisos'
    ? $pdo->query("SELECT * FROM estados_pedido WHERE tipo = 'pedido' ORDER BY orden, id")->fetchAll()
    : [];
$editable = Rbac::puede('configuracion.editar');

$tituloPanel    = 'Configuración';
$subtituloPanel = 'Todo lo que se puede cambiar sin tocar código';

require __DIR__ . '/_cabecera.php';

/** Campo de imagen con vista previa y subida. */
function campoImagen(string $nombre, string $etiqueta, string $valor, string $ayuda = ''): void
{
    ?>
    <div class="campo" data-imagen-simple>
      <label><?= e($etiqueta) ?></label>
      <input type="hidden" name="<?= e($nombre) ?>" value="<?= e($valor) ?>">
      <img src="<?= e($valor !== '' ? url_imagen($valor) : '') ?>" alt=""
           <?= $valor === '' ? 'hidden' : '' ?>
           style="max-height:110px; width:auto; border-radius:9px; margin-bottom:9px; border:1px solid var(--p-linea);">
      <input type="file" accept="image/jpeg,image/png,image/webp,image/svg+xml">
      <?php if ($ayuda !== ''): ?><p class="ayuda"><?= e($ayuda) ?></p><?php endif; ?>
    </div>
    <?php
}
?>

<?php if (!$editable): ?>
  <div class="caja-aviso alerta">
    <i class="fa-solid fa-lock" aria-hidden="true"></i>
    <span>Puedes consultar la configuración, pero tu rol no permite modificarla.</span>
  </div>
<?php endif; ?>

<div class="pestanas">
  <?php foreach ([
      'marca'         => 'Marca y colores',
      'portada'       => 'Portada y nosotros',
      'contacto'      => 'Contacto y redes',
      'pedidos'       => 'Pedidos',
      'envio'         => 'Envío y zonas',
      'avisos'        => 'Avisos por correo',
      'banco'         => 'Transferencias',
      'desarrollador' => 'Créditos',
  ] as $clave => $nombre): ?>
    <a href="<?= e(url('admin/configuracion.php?t=' . $clave)) ?>"
       class="<?= $pestana === $clave ? 'activa' : '' ?>"><?= e($nombre) ?></a>
  <?php endforeach; ?>
</div>

<?php if ($pestana === 'marca'): ?>
  <form method="post" action="<?= e(url('admin/configuracion.php')) ?>">
    <?= campoToken() ?>
    <input type="hidden" name="grupo" value="marca">
    <section class="panel">
      <div class="panel-cabecera"><div><h2>Identidad</h2></div></div>
      <div class="panel-cuerpo">
        <div class="rejilla-campos dos">
          <div class="campo">
            <label for="nombre_tienda">Nombre de la tienda</label>
            <input type="text" id="nombre_tienda" name="nombre_tienda" maxlength="100"
                   value="<?= e((string)($c['nombre_tienda'] ?? '')) ?>">
          </div>
          <div class="campo">
            <label for="eslogan">Eslogan</label>
            <input type="text" id="eslogan" name="eslogan" maxlength="150"
                   value="<?= e((string)($c['eslogan'] ?? '')) ?>">
          </div>
        </div>
        <div class="rejilla-campos dos">
          <?php campoImagen('logo_url', 'Logo', (string)($c['logo_url'] ?? '')); ?>
          <?php campoImagen('favicon_url', 'Favicon', (string)($c['favicon_url'] ?? ''),
                            'El icono que sale en la pestaña del navegador.'); ?>
        </div>
      </div>
    </section>

    <section class="panel">
      <div class="panel-cabecera"><div><h2>Colores</h2>
        <p>Se aplican a toda la web al guardar.</p></div></div>
      <div class="panel-cuerpo">
        <div class="rejilla-campos tres">
          <?php foreach ([
              'color_primario'   => 'Color principal',
              'color_secundario' => 'Color secundario',
              'color_fondo'      => 'Fondo',
              'color_texto'      => 'Texto',
          ] as $campo => $etiqueta): ?>
            <div class="campo">
              <label for="<?= e($campo) ?>"><?= e($etiqueta) ?></label>
              <input type="color" id="<?= e($campo) ?>" name="<?= e($campo) ?>"
                     value="<?= e((string)($c[$campo] ?? '#FFFFFF')) ?>">
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </section>

    <section class="panel">
      <div class="panel-cabecera"><div><h2>Precios y buscadores</h2></div></div>
      <div class="panel-cuerpo">
        <div class="rejilla-campos dos">
          <div class="campo">
            <label for="moneda_local">Símbolo de moneda</label>
            <input type="text" id="moneda_local" name="moneda_local" maxlength="5"
                   value="<?= e((string)($c['moneda_local'] ?? 'C$')) ?>">
          </div>
          <div class="campo">
            <div class="interruptor" style="margin-top:26px;">
              <input type="checkbox" id="mostrar_usd" name="mostrar_usd" value="1"
                     <?= (int)($c['mostrar_usd'] ?? 1) ? 'checked' : '' ?>>
              <label for="mostrar_usd">Mostrar también el precio en dólares</label>
            </div>
          </div>
        </div>
        <div class="campo">
          <label for="meta_descripcion">Descripción para buscadores</label>
          <textarea id="meta_descripcion" name="meta_descripcion" maxlength="300"><?= e((string)($c['meta_descripcion'] ?? '')) ?></textarea>
          <p class="ayuda">Es el texto que aparece bajo el título en Google. Entre 120 y 160 caracteres funciona bien.</p>
        </div>
        <?php campoImagen('og_imagen', 'Imagen al compartir', (string)($c['og_imagen'] ?? ''),
                          'La que se ve cuando alguien comparte el enlace en WhatsApp o Facebook.'); ?>
      </div>
    </section>

    <?php if ($editable): ?>
      <button type="submit" class="boton boton-principal">
        <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> Guardar</button>
    <?php endif; ?>
  </form>

<?php elseif ($pestana === 'portada'): ?>
  <form method="post" action="<?= e(url('admin/configuracion.php')) ?>">
    <?= campoToken() ?>
    <input type="hidden" name="grupo" value="portada">
    <section class="panel">
      <div class="panel-cabecera"><div><h2>Portada</h2>
        <p>El carrusel se arma con los productos destacados. Esto es el resto.</p></div></div>
      <div class="panel-cuerpo">
        <div class="rejilla-campos dos">
          <div class="campo">
            <label for="hero_palabra">Palabra grande de fondo</label>
            <input type="text" id="hero_palabra" name="hero_palabra" maxlength="20"
                   value="<?= e((string)($c['hero_palabra'] ?? '')) ?>">
            <p class="ayuda">Una temporada activa la sustituye por la suya.</p>
          </div>
          <div class="campo">
            <label for="hero_cta_texto">Texto del botón</label>
            <input type="text" id="hero_cta_texto" name="hero_cta_texto" maxlength="40"
                   value="<?= e((string)($c['hero_cta_texto'] ?? '')) ?>">
          </div>
        </div>
        <div class="campo">
          <label for="hero_titulo">Título (se usa si no hay productos destacados)</label>
          <input type="text" id="hero_titulo" name="hero_titulo" maxlength="200"
                 value="<?= e((string)($c['hero_titulo'] ?? '')) ?>">
        </div>
        <div class="campo">
          <label for="hero_subtitulo">Subtítulo</label>
          <input type="text" id="hero_subtitulo" name="hero_subtitulo" maxlength="255"
                 value="<?= e((string)($c['hero_subtitulo'] ?? '')) ?>">
        </div>
        <div class="rejilla-campos dos">
          <?php campoImagen('hero_imagen', 'Imagen de respaldo', (string)($c['hero_imagen'] ?? ''),
                            'Solo se muestra si no hay ningún producto destacado.'); ?>
          <div>
            <div class="campo">
              <label for="hero_color_fondo">Color de fondo por defecto</label>
              <input type="color" id="hero_color_fondo" name="hero_color_fondo"
                     value="<?= e((string)($c['hero_color_fondo'] ?? '#EFD9DE')) ?>">
            </div>
            <div class="interruptor">
              <input type="checkbox" id="hero_autoplay" name="hero_autoplay" value="1"
                     <?= (int)($c['hero_autoplay'] ?? 0) ? 'checked' : '' ?>>
              <label for="hero_autoplay">Avance automático del carrusel
                <small>Se desactiva solo si el visitante pidió menos animaciones en su sistema.</small></label>
            </div>
          </div>
        </div>
      </div>
    </section>

    <section class="panel">
      <div class="panel-cabecera"><div><h2>Sección «Nosotros»</h2></div></div>
      <div class="panel-cuerpo">
        <div class="campo">
          <label for="nosotros_titulo">Título</label>
          <input type="text" id="nosotros_titulo" name="nosotros_titulo" maxlength="150"
                 value="<?= e((string)($c['nosotros_titulo'] ?? '')) ?>">
        </div>
        <div class="campo">
          <label for="nosotros_texto">Texto</label>
          <textarea id="nosotros_texto" name="nosotros_texto" maxlength="2000"
                    style="min-height:130px;"><?= e((string)($c['nosotros_texto'] ?? '')) ?></textarea>
        </div>
        <?php campoImagen('nosotros_imagen', 'Imagen', (string)($c['nosotros_imagen'] ?? '')); ?>
      </div>
    </section>

    <?php if ($editable): ?>
      <button type="submit" class="boton boton-principal">
        <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> Guardar</button>
    <?php endif; ?>
  </form>

<?php elseif ($pestana === 'contacto'): ?>
  <form method="post" action="<?= e(url('admin/configuracion.php')) ?>">
    <?= campoToken() ?>
    <input type="hidden" name="grupo" value="contacto">
    <section class="panel">
      <div class="panel-cabecera"><div><h2>Contacto</h2></div></div>
      <div class="panel-cuerpo">
        <div class="rejilla-campos dos">
          <div class="campo">
            <label for="whatsapp_numero">WhatsApp general</label>
            <input type="text" id="whatsapp_numero" name="whatsapp_numero" maxlength="20"
                   value="<?= e((string)($c['whatsapp_numero'] ?? '')) ?>" placeholder="50588887777">
            <p class="ayuda">Solo dígitos, con el código de país y sin el +.</p>
          </div>
          <div class="campo">
            <label for="telefono">Teléfono</label>
            <input type="text" id="telefono" name="telefono" maxlength="20"
                   value="<?= e((string)($c['telefono'] ?? '')) ?>">
          </div>
        </div>
        <div class="campo">
          <label for="whatsapp_mensaje">Mensaje inicial de WhatsApp</label>
          <input type="text" id="whatsapp_mensaje" name="whatsapp_mensaje" maxlength="255"
                 value="<?= e((string)($c['whatsapp_mensaje'] ?? '')) ?>">
        </div>
        <div class="rejilla-campos dos">
          <div class="campo">
            <label for="email_contacto">Correo de contacto</label>
            <input type="email" id="email_contacto" name="email_contacto" maxlength="100"
                   value="<?= e((string)($c['email_contacto'] ?? '')) ?>">
          </div>
          <div class="campo">
            <label for="horario">Horario</label>
            <input type="text" id="horario" name="horario" maxlength="150"
                   value="<?= e((string)($c['horario'] ?? '')) ?>">
          </div>
        </div>
        <div class="campo">
          <label for="direccion">Dirección</label>
          <input type="text" id="direccion" name="direccion" maxlength="200"
                 value="<?= e((string)($c['direccion'] ?? '')) ?>">
          <p class="ayuda">También se usa para centrar el mapa de la sección de envíos.</p>
        </div>
      </div>
    </section>

    <section class="panel">
      <div class="panel-cabecera"><div><h2>Redes sociales</h2>
        <p>Las que dejes vacías no aparecen en el pie.</p></div></div>
      <div class="panel-cuerpo">
        <div class="rejilla-campos tres">
          <?php foreach (['instagram_url' => 'Instagram', 'facebook_url' => 'Facebook',
                          'tiktok_url' => 'TikTok'] as $campo => $etiqueta): ?>
            <div class="campo">
              <label for="<?= e($campo) ?>"><?= e($etiqueta) ?></label>
              <input type="url" id="<?= e($campo) ?>" name="<?= e($campo) ?>" maxlength="255"
                     value="<?= e((string)($c[$campo] ?? '')) ?>" placeholder="https://">
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </section>

    <?php if ($editable): ?>
      <button type="submit" class="boton boton-principal">
        <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> Guardar</button>
    <?php endif; ?>
  </form>

<?php elseif ($pestana === 'pedidos'): ?>
  <form method="post" action="<?= e(url('admin/configuracion.php')) ?>">
    <?= campoToken() ?>
    <input type="hidden" name="grupo" value="pedidos">
    <section class="panel">
      <div class="panel-cabecera"><div><h2>Cómo se puede pedir</h2>
        <p>Las dos formas pueden convivir; el cliente elige.</p></div></div>
      <div class="panel-cuerpo">
        <div class="interruptor">
          <input type="checkbox" id="pedido_web_activo" name="pedido_web_activo" value="1"
                 <?= (int)($c['pedido_web_activo'] ?? 1) ? 'checked' : '' ?>>
          <label for="pedido_web_activo">Pedidos por la web
            <small>Checkout completo con transferencia y comprobante. Si lo apagas, el carrito
              solo ofrece el pedido por WhatsApp.</small></label>
        </div>
        <div class="interruptor">
          <input type="checkbox" id="pedido_whatsapp_activo" name="pedido_whatsapp_activo" value="1"
                 <?= (int)($c['pedido_whatsapp_activo'] ?? 1) ? 'checked' : '' ?>>
          <label for="pedido_whatsapp_activo">Pedidos por WhatsApp
            <small>Genera el mensaje con todo el carrito y abre el chat.</small></label>
        </div>
        <div class="interruptor">
          <input type="checkbox" id="permitir_invitado" name="permitir_invitado" value="1"
                 <?= (int)($c['permitir_invitado'] ?? 1) ? 'checked' : '' ?>>
          <label for="permitir_invitado">Permitir comprar sin cuenta
            <small>Recomendado: obligar a registrarse hace que se pierdan pedidos.</small></label>
        </div>
        <div class="interruptor">
          <input type="checkbox" id="permitir_retiro" name="permitir_retiro" value="1"
                 <?= (int)($c['permitir_retiro'] ?? 1) ? 'checked' : '' ?>>
          <label for="permitir_retiro">Permitir retiro en la tienda</label>
        </div>
        <div class="interruptor">
          <input type="checkbox" id="pago_efectivo_activo" name="pago_efectivo_activo" value="1"
                 <?= (int)($c['pago_efectivo_activo'] ?? 1) ? 'checked' : '' ?>>
          <label for="pago_efectivo_activo">Aceptar efectivo contra entrega</label>
        </div>
        <div class="interruptor">
          <input type="checkbox" id="cupones_activos" name="cupones_activos" value="1"
                 <?= (int)($c['cupones_activos'] ?? 1) ? 'checked' : '' ?>>
          <label for="cupones_activos">Aceptar cupones de descuento
            <small>Muestra el campo del código al confirmar el pedido.
              Los cupones se crean en <a href="<?= e(url('admin/cupones.php')) ?>">Cupones</a>.</small></label>
        </div>
        <div class="campo">
          <label for="whatsapp_pedidos">WhatsApp para pedidos</label>
          <input type="text" id="whatsapp_pedidos" name="whatsapp_pedidos" maxlength="20"
                 value="<?= e((string)($c['whatsapp_pedidos'] ?? '')) ?>">
          <p class="ayuda">Si lo dejas vacío se usa el número general de contacto.</p>
        </div>
      </div>
    </section>

    <section class="panel">
      <div class="panel-cabecera"><div><h2>Entrega</h2>
        <p>Los precios y las zonas viven en la pestaña <strong>Envío y zonas</strong>.</p></div></div>
      <div class="panel-cuerpo">
        <div class="campo">
          <label for="franjas_entrega">Franjas horarias</label>
          <input type="text" id="franjas_entrega" name="franjas_entrega" maxlength="300"
                 value="<?= e((string)($c['franjas_entrega'] ?? '')) ?>">
          <p class="ayuda">Separadas por comas. Ejemplo: Mañana (8:00 - 12:00), Tarde (12:00 - 17:00)</p>
        </div>
      </div>
    </section>

    <?php if ($editable): ?>
      <button type="submit" class="boton boton-principal">
        <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> Guardar</button>
    <?php endif; ?>
  </form>

<?php elseif ($pestana === 'envio'): ?>
  <div class="caja-aviso">
    <i class="fa-solid fa-truck-fast" aria-hidden="true"></i>
    <span>Cada zona lleva su propio precio. El cliente elige la suya en el checkout y el
      cobro se vuelve a leer aquí antes de registrar el pedido, así que el precio que ve
      es siempre el que tú pusiste.</span>
  </div>

  <section class="panel">
    <div class="panel-cabecera">
      <div><h2>Zonas de entrega</h2><p><?= count($zonas) ?> registradas</p></div>
      <?php if ($editable): ?>
        <button type="button" class="boton boton-principal boton-mini" data-abrir-modal="modalZona"
                data-campo-zona_id="0" data-campo-zona_nombre="" data-campo-zona_descripcion=""
                data-campo-zona_costo="<?= e(number_format((float)($c['costo_envio'] ?? 0), 2, '.', '')) ?>"
                data-campo-zona_es_managua="1" data-campo-zona_orden="0" data-campo-zona_activo="1">
          <i class="fa-solid fa-plus" aria-hidden="true"></i> Añadir zona</button>
      <?php endif; ?>
    </div>

    <?php if (!$zonas): ?>
      <div class="vacio">
        <i class="fa-solid fa-map-location-dot" aria-hidden="true"></i>
        <h3>Todavía no hay zonas</h3>
        <p>Sin zonas, todos los envíos cobran el costo general de abajo.
           Crea al menos una para Managua y otra para fuera.</p>
      </div>
    <?php else: ?>
      <div class="tabla-envoltura">
        <table class="tabla">
          <thead><tr><th>Zona</th><th>Ámbito</th><th>Costo</th><th>Orden</th><th>Estado</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($zonas as $z): ?>
              <tr>
                <td class="celda-principal">
                  <?= e((string)$z['nombre']) ?>
                  <?php if ((string)$z['descripcion'] !== ''): ?>
                    <br><span class="celda-sub"><?= e((string)$z['descripcion']) ?></span>
                  <?php endif; ?>
                </td>
                <td><?= (int)$z['es_managua'] ? 'Dentro de Managua' : 'Fuera de Managua' ?></td>
                <td><?= (float)$z['costo'] > 0 ? e(dinero($z['costo'])) : 'Sin costo' ?></td>
                <td><?= (int)$z['orden'] ?></td>
                <td><span class="estado-suave <?= (int)$z['activo'] ? 'si' : 'no' ?>">
                  <?= (int)$z['activo'] ? 'Visible' : 'Oculta' ?></span></td>
                <td class="acciones">
                  <?php if ($editable): ?>
                    <div style="display:inline-flex; gap:5px;">
                      <button type="button" class="boton-icono" data-abrir-modal="modalZona"
                              data-campo-zona_id="<?= (int)$z['id'] ?>"
                              data-campo-zona_nombre="<?= e((string)$z['nombre']) ?>"
                              data-campo-zona_descripcion="<?= e((string)$z['descripcion']) ?>"
                              data-campo-zona_costo="<?= e(number_format((float)$z['costo'], 2, '.', '')) ?>"
                              data-campo-zona_es_managua="<?= (int)$z['es_managua'] ?>"
                              data-campo-zona_orden="<?= (int)$z['orden'] ?>"
                              data-campo-zona_activo="<?= (int)$z['activo'] ?>"
                              aria-label="Editar zona"><i class="fa-solid fa-pen" aria-hidden="true"></i></button>
                      <form method="post" action="<?= e(url('admin/configuracion.php')) ?>"
                            data-confirmar="¿Eliminar la zona «<?= e((string)$z['nombre']) ?>»? Los pedidos ya hechos conservan su zona.">
                        <?= campoToken() ?>
                        <input type="hidden" name="accion" value="zona_eliminar">
                        <input type="hidden" name="zona_id" value="<?= (int)$z['id'] ?>">
                        <button type="submit" class="boton-icono peligro" aria-label="Eliminar zona">
                          <i class="fa-solid fa-trash-can" aria-hidden="true"></i></button>
                      </form>
                    </div>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

  <form method="post" action="<?= e(url('admin/configuracion.php')) ?>">
    <?= campoToken() ?>
    <input type="hidden" name="grupo" value="envio">
    <section class="panel">
      <div class="panel-cabecera"><div><h2>Reglas generales</h2></div></div>
      <div class="panel-cuerpo">
        <div class="rejilla-campos dos">
          <div class="campo">
            <label for="costo_envio">Costo de envío por defecto</label>
            <input type="number" id="costo_envio" name="costo_envio" step="0.01" min="0"
                   value="<?= e(number_format((float)($c['costo_envio'] ?? 0), 2, '.', '')) ?>">
            <p class="ayuda">Se usa solo cuando no hay ninguna zona configurada.</p>
          </div>
          <div class="campo">
            <label for="envio_gratis_desde">Envío gratis a partir de</label>
            <input type="number" id="envio_gratis_desde" name="envio_gratis_desde" step="0.01" min="0"
                   value="<?= e(number_format((float)($c['envio_gratis_desde'] ?? 0), 2, '.', '')) ?>">
            <p class="ayuda">Vale para todas las zonas. 0 = nunca hay envío gratis por importe.</p>
          </div>
        </div>
        <div class="interruptor">
          <input type="checkbox" id="pedir_mapa_url" name="pedir_mapa_url" value="1"
                 <?= (int)($c['pedir_mapa_url'] ?? 1) ? 'checked' : '' ?>>
          <label for="pedir_mapa_url">Pedir enlace de ubicación en el checkout
            <small>El cliente pega su punto de Google Maps o Waze; el repartidor lo abre desde el panel.</small></label>
        </div>

        <div class="campo" style="margin-top:8px;">
          <label for="mensaje_repartidor">Mensaje que se le manda al motorizado</label>
          <textarea id="mensaje_repartidor" name="mensaje_repartidor" maxlength="1500"
                    style="min-height:220px; font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:.85rem;"
                    ><?= e((string)($c['mensaje_repartidor'] ?? '')) ?></textarea>
          <p class="ayuda">Se envía por WhatsApp desde la ficha del pedido.
            Las etiquetas entre llaves se cambian por los datos reales:</p>
          <div class="etiquetas-plantilla">
            <?php foreach (Repartidores::etiquetas() as $etiqueta => $queEs): ?>
              <span title="<?= e($queEs) ?>"><?= e($etiqueta) ?></span>
            <?php endforeach; ?>
          </div>
        </div>

        <?php if ($editable): ?>
          <button type="submit" class="boton boton-principal">
            <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> Guardar</button>
        <?php endif; ?>
      </div>
    </section>
  </form>

  <?php if ($editable): ?>
    <dialog class="modal" id="modalZona">
      <form method="post" action="<?= e(url('admin/configuracion.php')) ?>">
        <?= campoToken() ?>
        <input type="hidden" name="accion" value="zona_guardar">
        <input type="hidden" name="zona_id" value="0">
        <div class="modal-cabecera"><h2>Zona de entrega</h2></div>
        <div class="modal-cuerpo">
          <div class="campo">
            <label for="zn_nombre">Nombre *</label>
            <input type="text" id="zn_nombre" name="zona_nombre" required maxlength="100"
                   placeholder="Managua — centro">
            <p class="ayuda">Es lo que ve el cliente en la lista del checkout.</p>
          </div>
          <div class="campo">
            <label for="zn_descripcion">Barrios o referencia</label>
            <input type="text" id="zn_descripcion" name="zona_descripcion" maxlength="255"
                   placeholder="Bolonia, Centroamérica, Altamira…">
            <p class="ayuda">Aparece como ayuda debajo de la lista para que el cliente acierte con su zona.</p>
          </div>
          <div class="rejilla-campos dos">
            <div class="campo">
              <label for="zn_costo">Costo del envío</label>
              <input type="number" id="zn_costo" name="zona_costo" step="0.01" min="0" value="0.00">
            </div>
            <div class="campo">
              <label for="zn_orden">Orden</label>
              <input type="number" id="zn_orden" name="zona_orden" min="0" max="999" value="0">
            </div>
          </div>
          <div class="interruptor">
            <input type="checkbox" id="zn_managua" name="zona_es_managua" value="1" checked>
            <label for="zn_managua">Está dentro de Managua
              <small>Solo agrupa la lista del checkout en «Dentro» y «Fuera de Managua».</small></label>
          </div>
          <div class="interruptor">
            <input type="checkbox" id="zn_activo" name="zona_activo" value="1" checked>
            <label for="zn_activo">Ofrecer esta zona a los clientes</label>
          </div>
        </div>
        <div class="modal-pie">
          <button type="button" class="boton boton-claro" data-cerrar-modal>Cancelar</button>
          <button type="submit" class="boton boton-principal">Guardar zona</button>
        </div>
      </form>
    </dialog>
  <?php endif; ?>

<?php elseif ($pestana === 'avisos'): ?>
  <form method="post" action="<?= e(url('admin/configuracion.php')) ?>">
    <?= campoToken() ?>
    <input type="hidden" name="grupo" value="avisos">
    <?php if (!Correo::entregaDeVerdad()): ?>
      <div class="caja-aviso alerta">
        <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
        <span><strong>Ahora mismo no sale ningún correo.</strong>
          <code>MAIL_TRANSPORTE</code> está en <strong>log</strong>: los mensajes se escriben en
          <code>storage/logs/correos.log</code> y nadie los recibe. Para que lleguen de verdad,
          pon <code>MAIL_TRANSPORTE=smtp</code> en el archivo <code>.env</code> junto con los datos
          de tu servidor de correo. Con <code>mail</code> tampoco salen en XAMPP.</span>
      </div>
    <?php else: ?>
      <div class="caja-aviso exito">
        <i class="fa-solid fa-paper-plane" aria-hidden="true"></i>
        <span>Los correos salen por <strong><?= e(Correo::transporte()) ?></strong>.
          Usa el botón de prueba de abajo cada vez que cambies algo del servidor de correo.</span>
      </div>
    <?php endif; ?>

    <section class="panel">
      <div class="panel-cabecera"><div><h2>Avisos al equipo</h2>
        <p>Correos que recibe la tienda, no el cliente.</p></div></div>
      <div class="panel-cuerpo">
        <div class="campo">
          <label for="email_avisos">Correo que recibe los avisos</label>
          <input type="email" id="email_avisos" name="email_avisos" maxlength="150"
                 value="<?= e((string)($c['email_avisos'] ?? '')) ?>">
          <p class="ayuda">Si lo dejas vacío se usa el correo de contacto
            (<?= e((string)($c['email_contacto'] ?? 'sin definir')) ?>).</p>
        </div>
        <div class="interruptor">
          <input type="checkbox" id="avisar_pedidos" name="avisar_pedidos" value="1"
                 <?= (int)($c['avisar_pedidos'] ?? 1) ? 'checked' : '' ?>>
          <label for="avisar_pedidos">Avisarme cuando entra un pedido nuevo
            <small>Llega con el cliente, la dirección, el enlace del mapa y el detalle.</small></label>
        </div>
        <div class="interruptor">
          <input type="checkbox" id="avisar_pagos" name="avisar_pagos" value="1"
                 <?= (int)($c['avisar_pagos'] ?? 1) ? 'checked' : '' ?>>
          <label for="avisar_pagos">Avisarme cuando suben un comprobante
            <small>Es el momento en que alguien del equipo tiene que verificarlo a mano.</small></label>
        </div>
        <?php if ($editable): ?>
          <button type="submit" class="boton boton-principal">
            <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> Guardar</button>
        <?php endif; ?>
      </div>
    </section>
  </form>

  <?php if ($editable): ?>
    <section class="panel">
      <div class="panel-cabecera"><div><h2>Probar el envío</h2>
        <p>Manda un correo real para comprobar cómo se ve y si llega.</p></div></div>
      <div class="panel-cuerpo">
        <form method="post" action="<?= e(url('admin/configuracion.php')) ?>">
          <?= campoToken() ?>
          <input type="hidden" name="accion" value="correo_prueba">
          <div class="campo">
            <label for="correo_destino">Mandar la prueba a</label>
            <input type="email" id="correo_destino" name="correo_destino" maxlength="150"
                   placeholder="<?= e((string)($c['email_avisos'] ?: $c['email_contacto'] ?: 'tucorreo@gmail.com')) ?>">
            <p class="ayuda">Si lo dejas vacío se manda al correo de avisos.
              Llega con el logo, los colores y el eslogan que tengas puestos: es el mismo
              diseño que ve el cliente.</p>
          </div>
          <button type="submit" class="boton boton-principal">
            <i class="fa-solid fa-paper-plane" aria-hidden="true"></i> Enviar correo de prueba</button>
        </form>
      </div>
    </section>
  <?php endif; ?>

  <form method="post" action="<?= e(url('admin/configuracion.php')) ?>">
    <?= campoToken() ?>
    <input type="hidden" name="accion" value="mensajes_guardar">
    <section class="panel">
      <div class="panel-cabecera"><div><h2>Correo de cada estado</h2>
        <p>Lo que lee el cliente cada vez que su pedido cambia de estado.</p></div></div>
      <div class="panel-cuerpo">
        <div class="caja-aviso">
          <i class="fa-solid fa-envelope-open-text" aria-hidden="true"></i>
          <span>Al correo se le añade solo el número del pedido y, si escribes una nota
            al cambiar el estado, esa nota. Escribe aquí el texto fijo.</span>
        </div>

        <?php foreach ($estadosCorreo as $es): ?>
          <div class="campo" style="border-left:3px solid <?= e((string)$es['color']) ?>; padding-left:14px;">
            <label for="msg_<?= (int)$es['id'] ?>"><?= e((string)$es['nombre']) ?></label>
            <textarea id="msg_<?= (int)$es['id'] ?>" name="mensaje[<?= (int)$es['id'] ?>]"
                      maxlength="1000" style="min-height:72px;"
                      placeholder="<?= e((string)$es['descripcion']) ?>"><?= e((string)($es['mensaje_correo'] ?? '')) ?></textarea>
            <div class="interruptor" style="margin-top:8px;">
              <input type="checkbox" id="avisar_<?= (int)$es['id'] ?>" name="avisar[<?= (int)$es['id'] ?>]" value="1"
                     <?= (int)($es['avisar_cliente'] ?? 1) ? 'checked' : '' ?>>
              <label for="avisar_<?= (int)$es['id'] ?>">Enviar correo al llegar a este estado</label>
            </div>
          </div>
        <?php endforeach; ?>

        <?php if ($editable): ?>
          <button type="submit" class="boton boton-principal">
            <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> Guardar mensajes</button>
        <?php endif; ?>
      </div>
    </section>
  </form>

<?php elseif ($pestana === 'banco'): ?>
  <div class="caja-aviso alerta">
    <i class="fa-solid fa-eye" aria-hidden="true"></i>
    <span>Estos datos se muestran al cliente en la página de su pedido, después de confirmarlo.
      No aparecen en el catálogo ni en el código: viven solo en la base de datos.</span>
  </div>

  <section class="panel">
    <div class="panel-cabecera">
      <div><h2>Cuentas para transferencia</h2><p><?= count($cuentas) ?> registradas</p></div>
      <?php if ($editable): ?>
        <button type="button" class="boton boton-principal boton-mini" data-abrir-modal="modalCuenta"
                data-campo-cuenta_id="0" data-campo-banco="" data-campo-titular=""
                data-campo-numero_cuenta="" data-campo-identificacion="" data-campo-notas_cuenta="">
          <i class="fa-solid fa-plus" aria-hidden="true"></i> Añadir cuenta</button>
      <?php endif; ?>
    </div>

    <?php if (!$cuentas): ?>
      <div class="vacio">
        <i class="fa-solid fa-building-columns" aria-hidden="true"></i>
        <h3>No hay ninguna cuenta registrada</h3>
        <p>Sin cuentas, el cliente no sabe a dónde transferir. Añade al menos una.</p>
      </div>
    <?php else: ?>
      <div class="tabla-envoltura">
        <table class="tabla">
          <thead><tr><th>Banco</th><th>Titular</th><th>Cuenta</th><th>Moneda</th><th>Estado</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($cuentas as $cu): ?>
              <tr>
                <td class="celda-principal"><?= e((string)$cu['banco']) ?></td>
                <td><?= e((string)$cu['titular']) ?></td>
                <td>
                  <?= e((string)$cu['numero_cuenta']) ?><br>
                  <span class="celda-sub"><?= e((string)$cu['tipo_cuenta']) ?></span>
                </td>
                <td><?= e((string)$cu['moneda']) ?></td>
                <td><span class="estado-suave <?= (int)$cu['activo'] ? 'si' : 'no' ?>">
                  <?= (int)$cu['activo'] ? 'Visible' : 'Oculta' ?></span></td>
                <td class="acciones">
                  <?php if ($editable): ?>
                    <div style="display:inline-flex; gap:5px;">
                      <button type="button" class="boton-icono" data-abrir-modal="modalCuenta"
                              data-campo-cuenta_id="<?= (int)$cu['id'] ?>"
                              data-campo-banco="<?= e((string)$cu['banco']) ?>"
                              data-campo-titular="<?= e((string)$cu['titular']) ?>"
                              data-campo-numero_cuenta="<?= e((string)$cu['numero_cuenta']) ?>"
                              data-campo-tipo_cuenta="<?= e((string)$cu['tipo_cuenta']) ?>"
                              data-campo-moneda="<?= e((string)$cu['moneda']) ?>"
                              data-campo-identificacion="<?= e((string)$cu['identificacion']) ?>"
                              data-campo-notas_cuenta="<?= e((string)$cu['notas']) ?>"
                              data-campo-orden_cuenta="<?= (int)$cu['orden'] ?>"
                              data-campo-activo_cuenta="<?= (int)$cu['activo'] ?>"
                              aria-label="Editar cuenta"><i class="fa-solid fa-pen" aria-hidden="true"></i></button>
                      <form method="post" action="<?= e(url('admin/configuracion.php')) ?>"
                            data-confirmar="¿Eliminar la cuenta de <?= e((string)$cu['banco']) ?>?">
                        <?= campoToken() ?>
                        <input type="hidden" name="accion" value="cuenta_eliminar">
                        <input type="hidden" name="cuenta_id" value="<?= (int)$cu['id'] ?>">
                        <button type="submit" class="boton-icono peligro" aria-label="Eliminar cuenta">
                          <i class="fa-solid fa-trash-can" aria-hidden="true"></i></button>
                      </form>
                    </div>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

  <form method="post" action="<?= e(url('admin/configuracion.php')) ?>">
    <?= campoToken() ?>
    <input type="hidden" name="grupo" value="banco">
    <section class="panel">
      <div class="panel-cabecera"><div><h2>Instrucciones para el cliente</h2></div></div>
      <div class="panel-cuerpo">
        <div class="campo">
          <label for="instrucciones_pago">Texto que se muestra junto a los datos bancarios</label>
          <textarea id="instrucciones_pago" name="instrucciones_pago" maxlength="1500"
                    style="min-height:110px;"><?= e((string)($c['instrucciones_pago'] ?? '')) ?></textarea>
        </div>
        <?php if ($editable): ?>
          <button type="submit" class="boton boton-principal">
            <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> Guardar</button>
        <?php endif; ?>
      </div>
    </section>
  </form>

  <?php if ($editable): ?>
    <dialog class="modal" id="modalCuenta">
      <form method="post" action="<?= e(url('admin/configuracion.php')) ?>">
        <?= campoToken() ?>
        <input type="hidden" name="accion" value="cuenta_guardar">
        <input type="hidden" name="cuenta_id" value="0">
        <div class="modal-cabecera"><h2>Cuenta bancaria</h2></div>
        <div class="modal-cuerpo">
          <div class="rejilla-campos dos">
            <div class="campo">
              <label for="cb_banco">Banco *</label>
              <input type="text" id="cb_banco" name="banco" required maxlength="80">
            </div>
            <div class="campo">
              <label for="cb_moneda">Moneda</label>
              <input type="text" id="cb_moneda" name="moneda" maxlength="20" value="Córdobas">
            </div>
          </div>
          <div class="campo">
            <label for="cb_titular">Titular *</label>
            <input type="text" id="cb_titular" name="titular" required maxlength="120">
          </div>
          <div class="rejilla-campos dos">
            <div class="campo">
              <label for="cb_numero">Número de cuenta *</label>
              <input type="text" id="cb_numero" name="numero_cuenta" required maxlength="60">
            </div>
            <div class="campo">
              <label for="cb_tipo">Tipo de cuenta</label>
              <input type="text" id="cb_tipo" name="tipo_cuenta" maxlength="40" value="Ahorro">
            </div>
          </div>
          <div class="rejilla-campos dos">
            <div class="campo">
              <label for="cb_identificacion">Cédula / RUC</label>
              <input type="text" id="cb_identificacion" name="identificacion" maxlength="40">
            </div>
            <div class="campo">
              <label for="cb_orden">Orden</label>
              <input type="number" id="cb_orden" name="orden_cuenta" min="0" max="999" value="0">
            </div>
          </div>
          <div class="campo">
            <label for="cb_notas">Nota para el cliente</label>
            <input type="text" id="cb_notas" name="notas_cuenta" maxlength="255">
          </div>
          <div class="interruptor">
            <input type="checkbox" id="cb_activo" name="activo_cuenta" value="1" checked>
            <label for="cb_activo">Mostrar esta cuenta a los clientes</label>
          </div>
        </div>
        <div class="modal-pie">
          <button type="button" class="boton boton-claro" data-cerrar-modal>Cancelar</button>
          <button type="submit" class="boton boton-principal">Guardar cuenta</button>
        </div>
      </form>
    </dialog>
  <?php endif; ?>

<?php else: ?>
  <form method="post" action="<?= e(url('admin/configuracion.php')) ?>">
    <?= campoToken() ?>
    <input type="hidden" name="grupo" value="desarrollador">
    <section class="panel">
      <div class="panel-cabecera"><div>
        <h2>Créditos del desarrollador</h2>
        <p>Bloque del pie de la web. Nada de esto está escrito en el código.</p>
      </div></div>
      <div class="panel-cuerpo">
        <div class="interruptor">
          <input type="checkbox" id="dev_activo" name="dev_activo" value="1"
                 <?= (int)($c['dev_activo'] ?? 1) ? 'checked' : '' ?>>
          <label for="dev_activo">Mostrar los créditos en el pie</label>
        </div>
        <div class="rejilla-campos dos">
          <div class="campo">
            <label for="dev_nombre">Nombre</label>
            <input type="text" id="dev_nombre" name="dev_nombre" maxlength="80"
                   value="<?= e((string)($c['dev_nombre'] ?? '')) ?>">
          </div>
          <div class="campo">
            <label for="dev_url">Sitio web</label>
            <input type="url" id="dev_url" name="dev_url" maxlength="255"
                   value="<?= e((string)($c['dev_url'] ?? '')) ?>" placeholder="https://">
            <p class="ayuda">Al tocar los créditos se abre esta dirección en una pestaña nueva.</p>
          </div>
        </div>
        <div class="campo">
          <label for="dev_descripcion">Descripción</label>
          <input type="text" id="dev_descripcion" name="dev_descripcion" maxlength="200"
                 value="<?= e((string)($c['dev_descripcion'] ?? '')) ?>">
        </div>
        <?php campoImagen('dev_logo', 'Logo', (string)($c['dev_logo'] ?? ''),
                          'Se muestra entero, sin recortar, y sustituye al texto del nombre. '
                        . 'El pie es oscuro, así que conviene subir la versión clara del logo, '
                        . 'en PNG con fondo transparente. Sin logo se muestra la inicial del nombre.'); ?>

        <div style="margin-top:18px; padding:16px; background:#2C2124; border-radius:10px;">
          <p class="etiqueta" style="color:#9A8A8D;">Vista previa</p>
          <div style="display:inline-flex; align-items:center; gap:11px; padding:9px 15px; border-radius:30px;
                      background:rgba(255,255,255,.07); border:1px solid rgba(255,255,255,.11); color:#E0D3D5;">
            <span style="font-size:.68rem;letter-spacing:.1em;text-transform:uppercase;opacity:.58;">Desarrollado por</span>
            <?php if (($logoDev = (string)($c['dev_logo'] ?? '')) !== ''): ?>
              <img src="<?= e(url_imagen($logoDev)) ?>" alt="<?= e((string)($c['dev_nombre'] ?? '')) ?>"
                   style="height:30px;width:auto;max-width:200px;object-fit:contain;display:block;">
            <?php else: ?>
              <span style="width:34px;height:34px;border-radius:50%;background:var(--p-rosa);color:#2C2124;
                           display:grid;place-items:center;font-weight:700;">
                <?= e(mb_substr((string)($c['dev_nombre'] ?? 'A'), 0, 1)) ?></span>
              <span style="display:flex;flex-direction:column;line-height:1.3;text-align:left;">
                <strong style="color:#fff;font-size:.93rem;"><?= e((string)($c['dev_nombre'] ?? '')) ?></strong>
                <small style="opacity:.62;font-size:.74rem;"><?= e((string)($c['dev_descripcion'] ?? '')) ?></small>
              </span>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </section>

    <?php if ($editable): ?>
      <button type="submit" class="boton boton-principal">
        <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> Guardar</button>
    <?php endif; ?>
  </form>
<?php endif; ?>

<?php require __DIR__ . '/_pie.php'; ?>
