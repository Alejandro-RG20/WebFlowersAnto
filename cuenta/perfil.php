<?php
/**
 * Datos personales y cambio de contraseña del cliente.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/lib/cuentas_externas.php';
require_once __DIR__ . '/../includes/lib/google.php';
require_once __DIR__ . '/../includes/lib/facebook.php';

Auth::exigirSesion('cuenta/perfil.php');

$usuario = Auth::usuario();
$errores = [];
$erroresPassword = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirToken(false, 'cuenta/perfil.php');
    $accion = opcion('accion', ['datos', 'password', 'vincular', 'desvincular', 'foto', 'quitar_foto',
                                'correo_confirmar', 'correo_reenviar', 'correo_cancelar'], 'datos');

    // La contraseña actual se pide en varias acciones de esta página: con una
    // sesión ajena abierta no se puede probar contraseñas sin límite.
    $comprobarClave = function (string $campo) use ($pdo, $usuario): bool {
        if (!limitar($pdo, 'perfil-clave:' . (int)$usuario['id'], 10, 900)) {
            return false;
        }
        return Auth::verificarPassword($pdo, $usuario, crudo($campo));
    };

    // --- Foto de perfil ----------------------------------------------------
    if ($accion === 'foto' || $accion === 'quitar_foto') {
        if (!FotoPerfil::disponible($pdo)) {
            flash('error', 'La foto de perfil todavía no está disponible.');
            redirigir('cuenta/perfil.php');
        }
        if ($accion === 'quitar_foto') {
            FotoPerfil::quitar($pdo, (int)$usuario['id']);
            flash('exito', 'Quitamos tu foto de perfil.');
            redirigir('cuenta/perfil.php');
        }
        if (!limitar($pdo, 'foto-perfil:' . (int)$usuario['id'], 10, 3600)) {
            flash('error', 'Cambiaste la foto varias veces seguidas. Espera un rato.');
            redirigir('cuenta/perfil.php');
        }
        $r = FotoPerfil::guardar($pdo, (int)$usuario['id'], $_FILES['foto'] ?? []);
        if ($r['ok']) {
            Auditoria::registrar($pdo, 'editar_perfil', 'usuarios', [
                'recurso_tipo' => 'usuario', 'recurso_id' => (string)$usuario['id'],
                'descripcion'  => 'El cliente cambió su foto de perfil.',
            ]);
        }
        flash($r['ok'] ? 'exito' : 'error', $r['ok'] ? 'Foto de perfil actualizada.' : $r['error']);
        redirigir('cuenta/perfil.php');
    }

    // --- Cambio de correo pendiente -----------------------------------------
    if (in_array($accion, ['correo_confirmar', 'correo_reenviar', 'correo_cancelar'], true)) {
        $pendiente = CambioCorreo::pendiente($pdo, (int)$usuario['id']);
        if ($accion === 'correo_cancelar' || $pendiente === null) {
            CambioCorreo::cancelar($pdo, (int)$usuario['id']);
            flash($pendiente ? 'exito' : 'error', $pendiente
                ? 'Cancelamos el cambio. Tu correo sigue siendo ' . $usuario['email'] . '.'
                : 'Ese código ya caducó. Vuelve a escribir el correo nuevo para recibir otro.');
            redirigir('cuenta/perfil.php');
        }
        if ($accion === 'correo_reenviar') {
            $ok = limitar($pdo, 'cambiar-correo:' . (int)$usuario['id'], 3, 3600)
               && CambioCorreo::iniciar($pdo, $usuario, (string)$pendiente['destino']);
            flash($ok ? 'exito' : 'error', $ok
                ? 'Te enviamos un código nuevo a ' . $pendiente['destino'] . '.'
                : 'No pudimos enviar otro código ahora. Espera un rato y vuelve a intentarlo.');
            redirigir('cuenta/perfil.php');
        }

        $codigo = CodigoCorreo::limpiar(crudo('codigo'));
        if (!limitar($pdo, 'cambiar-correo-codigo:' . (int)$usuario['id'], 10, 900)) {
            flash('error', 'Demasiados intentos. Espera unos minutos o pide un código nuevo.');
        } elseif ($codigo === '') {
            flash('error', 'Escribe los 6 dígitos del código.');
        } else {
            $r = CambioCorreo::confirmar($pdo, $usuario, $codigo);
            match ($r['estado']) {
                'ok'      => flash('exito', 'Listo: tu correo ahora es ' . $r['nuevo'] . '.'),
                'ocupado' => flash('error', 'Ese correo ya lo usa otra cuenta. Cancelamos el cambio.'),
                'agotado' => flash('error', 'Ese código ya no sirve. Vuelve a escribir el correo nuevo para recibir otro.'),
                default   => flash('error', 'Ese código no es válido. Revisa el último correo que te enviamos.'),
            };
        }
        redirigir('cuenta/perfil.php');
    }

    // --- Conectar o desconectar Google / Facebook -------------------------
    if ($accion === 'vincular' || $accion === 'desvincular') {
        $proveedor = opcion('proveedor', ['google', 'facebook'], '');
        $disponible = match ($proveedor) {
            'google'   => Google::configurado(),
            'facebook' => Facebook::disponible($pdo),
            default    => false,
        };
        if (!$disponible) {
            flash('error', 'Esa forma de entrar no está disponible.');
            redirigir('cuenta/perfil.php');
        }
        $tieneClave = (string)($usuario['password_hash'] ?? '') !== '';
        if ($accion === 'desvincular') {
            if ($tieneClave && !$comprobarClave('password_vinculo')) {
                flash('error', 'Escribe tu contraseña actual para desconectar ' . CuentasExternas::PROVEEDORES[$proveedor]['nombre'] . '.');
                redirigir('cuenta/perfil.php');
            }
            $r = CuentasExternas::desvincular($pdo, $proveedor, $usuario);
            flash($r['ok'] ? 'exito' : 'error', $r['mensaje']);
            redirigir('cuenta/perfil.php');
        }
        if ($tieneClave && !$comprobarClave('password_vinculo')) {
            flash('error', 'Escribe tu contraseña actual para conectar ' . CuentasExternas::PROVEEDORES[$proveedor]['nombre'] . '.');
            redirigir('cuenta/perfil.php');
        }
        CuentasExternas::autorizarVinculo($proveedor, (int)$usuario['id']);
        redirigir('cuenta/' . $proveedor . '.php');
    }

    if ($accion === 'datos') {
        $nombre   = texto('nombre', 60);
        $apellido = texto('apellido', 60);
        $telefono = telefonoValido('telefono');
        $correo   = correoValido('email');
        $conFecha = FotoPerfil::disponible($pdo);
        $nacio    = $conFecha ? fechaOpcional('fecha_nacimiento') : null;

        if (mb_strlen($nombre) < 2)   { $errores['nombre']   = 'Escribe tu nombre.'; }
        if (mb_strlen($apellido) < 2) { $errores['apellido'] = 'Escribe tu apellido.'; }
        if ($telefono === '')         { $errores['telefono'] = 'El teléfono debe tener 8 dígitos o más.'; }
        if ($correo === '')           { $errores['email']    = 'Escribe un correo válido.'; }
        if ($conFecha && trim(crudo('fecha_nacimiento')) !== ''
            && ($nacio === null || $nacio < '1900-01-01' || $nacio > date('Y-m-d'))) {
            $errores['fecha_nacimiento'] = 'Esa fecha no es válida.';
        }

        // Cambiar el correo es cambiar a quién llegan la recuperación de la
        // contraseña y los avisos de los pedidos. Antes bastaba una sesión
        // abierta (un móvil prestado) y el correo nuevo quedaba como
        // «verificado» sin haberse confirmado nunca. Ahora se pide la
        // contraseña actual (si la cuenta tiene) y el correo nuevo se
        // confirma con un enlace.
        $cambiaCorreo = !$errores && $correo !== mb_strtolower((string)$usuario['email']);
        if ($cambiaCorreo) {
            $ocupado = $pdo->prepare("SELECT 1 FROM usuarios WHERE email = ? AND id <> ?");
            $ocupado->execute([$correo, $usuario['id']]);
            if ($ocupado->fetchColumn()) {
                $errores['email'] = 'Ese correo ya está en uso por otra cuenta.';
            } elseif ((string)($usuario['password_hash'] ?? '') !== ''
                && !$comprobarClave('password_actual_correo')) {
                $errores['password_actual_correo'] = 'Para cambiar el correo, escribe tu contraseña actual.';
            }
        }

        // Con códigos disponibles, el correo nuevo no se guarda todavía: queda
        // pendiente hasta que se escriba el código que le llega.
        $correoConCodigo = $cambiaCorreo && CodigoCorreo::disponible($pdo);
        if (!$errores && $correoConCodigo && !limitar($pdo, 'cambiar-correo:' . (int)$usuario['id'], 3, 3600)) {
            $errores['email'] = 'Pediste varios cambios de correo seguidos. Espera un rato.';
        }

        if (!$errores) {
            $correoGuardado = $correoConCodigo ? (string)$usuario['email'] : $correo;
            $pdo->prepare(
                "UPDATE usuarios SET nombre = ?, apellido = ?, telefono = ?, email = ?, nombre_completo = ?
                  WHERE id = ?"
            )->execute([$nombre, $apellido, $telefono, $correoGuardado,
                        trim($nombre . ' ' . $apellido), $usuario['id']]);
            if ($conFecha) {
                $pdo->prepare("UPDATE usuarios SET fecha_nacimiento = ? WHERE id = ?")
                    ->execute([$nacio, $usuario['id']]);
            }

            $codigoEnviado = $correoConCodigo && CambioCorreo::iniciar($pdo, $usuario, $correo);

            if ($cambiaCorreo && !$correoConCodigo) {
                // El correo nuevo está sin confirmar y los enlaces pendientes
                // (de contraseña o de confirmación) iban al anterior.
                $pdo->prepare("UPDATE usuarios SET email_verificado_en = NULL WHERE id = ?")
                    ->execute([$usuario['id']]);
                $pdo->prepare("UPDATE password_resets SET usado_en = NOW() WHERE usuario_id = ? AND usado_en IS NULL")
                    ->execute([$usuario['id']]);
                Verificacion::enviar($pdo, ['email_verificado_en' => null, 'email' => $correo] + $usuario);
                Correo::enviar((string)$usuario['email'], 'El correo de tu cuenta cambió',
                    Correo::plantilla('El correo de tu cuenta cambió',
                        '<p>Hola ' . e((string)$usuario['nombre']) . ', el correo de tu cuenta en '
                        . e(Ajustes::texto('nombre_tienda', 'Flowers Anto')) . ' se cambió a <strong>'
                        . e($correo) . '</strong>.</p><p>Si no fuiste tú, escríbenos cuanto antes.</p>'));
            }

            Auditoria::registrar($pdo, 'editar_perfil', 'usuarios', [
                'recurso_tipo' => 'usuario', 'recurso_id' => (string)$usuario['id'],
                'descripcion'  => 'El cliente actualizó sus datos personales.',
                'detalles'     => Auditoria::diferencias(
                    $usuario,
                    ['nombre' => $nombre, 'apellido' => $apellido, 'telefono' => $telefono,
                     'email' => $correoGuardado, 'fecha_nacimiento' => $conFecha ? $nacio : ($usuario['fecha_nacimiento'] ?? null)],
                    ['nombre', 'apellido', 'telefono', 'email', 'fecha_nacimiento']
                ),
            ]);

            flash($correoConCodigo && !$codigoEnviado ? 'error' : 'exito', match (true) {
                $correoConCodigo && $codigoEnviado => 'Guardamos tus datos. Te enviamos un código a ' . $correo
                                                    . ' para confirmar el correo nuevo; hasta entonces sigues con el de antes.',
                $correoConCodigo                   => 'Guardamos tus datos, pero no pudimos enviar el código a '
                                                    . $correo . '. Revisa que esté bien escrito.',
                $cambiaCorreo                      => 'Guardamos tus datos. Te enviamos un enlace a ' . $correo
                                                    . ' para confirmar el correo nuevo.',
                default                            => 'Guardamos tus datos.',
            });
            redirigir('cuenta/perfil.php');
        }
    } else {
        $actual    = crudo('password_actual');
        $nueva     = crudo('password');
        $confirmar = crudo('password_confirmar');
        $tienePassword = (string)($usuario['password_hash'] ?? '') !== '';

        if ($tienePassword && !$comprobarClave('password_actual')) {
            $erroresPassword['password_actual'] = 'La contraseña actual no coincide.';
        }
        $problema = revisarPassword($nueva, $confirmar);
        if ($problema !== '') {
            $erroresPassword['password'] = $problema;
        }

        if (!$erroresPassword) {
            $pdo->prepare("UPDATE usuarios SET password_hash = ? WHERE id = ?")
                ->execute([password_hash($nueva, PASSWORD_DEFAULT), $usuario['id']]);

            // Las sesiones abiertas en otros dispositivos se cierran; esta
            // sigue abierta, porque quien la usa acaba de probar la clave.
            Auth::cerrarOtrasSesiones($pdo, (int)$usuario['id'], true);

            Auditoria::registrar($pdo, 'cambio_password', 'usuarios', [
                'recurso_tipo' => 'usuario', 'recurso_id' => (string)$usuario['id'],
                'descripcion'  => 'El cliente cambió su contraseña desde su cuenta.',
            ]);

            Correo::enviar((string)$usuario['email'], 'Tu contraseña se cambió',
                Correo::plantilla('Tu contraseña se cambió',
                    '<p>Hola ' . e((string)$usuario['nombre']) . ', acabas de cambiar tu contraseña '
                    . 'y cerramos tu cuenta en los demás dispositivos. '
                    . 'Si no fuiste tú, escríbenos cuanto antes.</p>'));

            flash('exito', 'Contraseña actualizada.');
            redirigir('cuenta/perfil.php');
        }
    }
}

$tituloPagina  = 'Mis datos — ' . Ajustes::texto('nombre_tienda', 'Flowers Anto');
$paginaActiva  = 'cuenta';
$seccionCuenta = 'perfil';
$tienePassword = (string)($usuario['password_hash'] ?? '') !== '';
$conFoto       = FotoPerfil::disponible($pdo);
$urlFoto       = FotoPerfil::url($usuario);
$pendiente     = CambioCorreo::pendiente($pdo, (int)$usuario['id']);
$jsExtra       = $conFoto ? ['assets/js/recorte.js'] : [];

require __DIR__ . '/../includes/vistas/cabecera.php';
?>

<div class="container">
  <header class="pagina-cabecera">
    <h1>Mis datos</h1>
    <p>Estos datos se usan para tus pedidos y para avisarte de su estado.</p>
  </header>

  <?php require __DIR__ . '/../includes/vistas/aviso_verificar.php'; ?>

  <div class="diseno-cuenta">
    <?php require __DIR__ . '/../includes/vistas/menu_cuenta.php'; ?>

    <div>
      <div class="tarjeta perfil-resumen">
        <div class="perfil-avatar perfil-avatar-grande" aria-hidden="true">
          <?php if ($urlFoto !== ''): ?>
            <img src="<?= e($urlFoto) ?>" alt="" width="96" height="96" decoding="async">
          <?php else: ?>
            <span><?= e(FotoPerfil::iniciales($usuario)) ?></span>
          <?php endif; ?>
        </div>
        <div class="perfil-resumen-texto">
          <p class="perfil-resumen-nombre"><?= e(Auth::nombreCompleto($usuario)) ?></p>
          <p class="perfil-resumen-correo">
            <?= e((string)$usuario['email']) ?>
            <?php if (Verificacion::verificado($usuario)): ?>
              <span class="estado-suave si"><i class="fa-solid fa-circle-check" aria-hidden="true"></i> Confirmado</span>
            <?php else: ?>
              <span class="estado-suave no">Sin confirmar</span>
            <?php endif; ?>
          </p>
          <p class="perfil-resumen-desde">Cliente desde <?= e(fecha_corta((string)$usuario['created_at'])) ?></p>
        </div>
      </div>

      <?php if ($pendiente): ?>
        <div class="tarjeta tarjeta-destacada" id="cambio-correo">
          <div class="tarjeta-encabezado">
            <h2>Confirma tu correo nuevo</h2>
            <p>Te enviamos un código de 6 dígitos a <strong><?= e((string)$pendiente['destino']) ?></strong>.
               Escríbelo para terminar el cambio. Hasta entonces tu cuenta sigue con
               <strong><?= e((string)$usuario['email']) ?></strong>.</p>
          </div>
          <form method="post" action="<?= e(url('cuenta/perfil.php')) ?>" class="fila-codigo" data-una-vez>
            <?= campoToken() ?>
            <input type="hidden" name="accion" value="correo_confirmar">
            <label class="visualmente-oculto" for="codigo_correo">Código de 6 dígitos</label>
            <input type="text" id="codigo_correo" name="codigo" class="campo-codigo" required
                   inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7"
                   placeholder="000 000">
            <button type="submit" class="btn btn-primary">Confirmar cambio</button>
          </form>
          <div class="enlaces-auth">
            <form method="post" action="<?= e(url('cuenta/perfil.php')) ?>" data-una-vez>
              <?= campoToken() ?>
              <input type="hidden" name="accion" value="correo_reenviar">
              <button type="submit" class="btn-enlace">Enviarme otro código</button>
            </form>
            <form method="post" action="<?= e(url('cuenta/perfil.php')) ?>">
              <?= campoToken() ?>
              <input type="hidden" name="accion" value="correo_cancelar">
              <button type="submit" class="btn-enlace">Cancelar el cambio</button>
            </form>
          </div>
        </div>
      <?php endif; ?>

      <div class="tarjeta">
        <div class="tarjeta-encabezado">
          <h2>Datos personales</h2>
        </div>

        <form method="post" action="<?= e(url('cuenta/perfil.php')) ?>" novalidate data-una-vez>
          <?= campoToken() ?>
          <input type="hidden" name="accion" value="datos">

          <div class="campo-fila">
            <div class="campo<?= isset($errores['nombre']) ? ' con-error' : '' ?>">
              <label for="nombre">Nombre</label>
              <input type="text" id="nombre" name="nombre" required
                     value="<?= e(texto('nombre') ?: (string)$usuario['nombre']) ?>">
              <?php if (isset($errores['nombre'])): ?><p class="error-campo"><?= e($errores['nombre']) ?></p><?php endif; ?>
            </div>
            <div class="campo<?= isset($errores['apellido']) ? ' con-error' : '' ?>">
              <label for="apellido">Apellido</label>
              <input type="text" id="apellido" name="apellido" required
                     value="<?= e(texto('apellido') ?: (string)$usuario['apellido']) ?>">
              <?php if (isset($errores['apellido'])): ?><p class="error-campo"><?= e($errores['apellido']) ?></p><?php endif; ?>
            </div>
          </div>

          <div class="campo<?= isset($errores['email']) ? ' con-error' : '' ?>">
            <label for="email">Correo electrónico</label>
            <input type="email" id="email" name="email" required
                   value="<?= e(correoValido('email') ?: (string)$usuario['email']) ?>">
            <?php if (isset($errores['email'])): ?><p class="error-campo"><?= e($errores['email']) ?></p><?php endif; ?>
          </div>

          <?php if ($tienePassword): ?>
            <div class="campo<?= isset($errores['password_actual_correo']) ? ' con-error' : '' ?>">
              <label for="password_actual_correo">Contraseña actual <small>(solo si cambias el correo)</small></label>
              <input type="password" id="password_actual_correo" name="password_actual_correo" autocomplete="current-password">
              <?php if (isset($errores['password_actual_correo'])): ?>
                <p class="error-campo"><?= e($errores['password_actual_correo']) ?></p>
              <?php endif; ?>
            </div>
          <?php endif; ?>

          <div class="campo<?= isset($errores['telefono']) ? ' con-error' : '' ?>">
            <label for="telefono">Teléfono / WhatsApp</label>
            <input type="tel" id="telefono" name="telefono" required
                   value="<?= e(texto('telefono') ?: (string)$usuario['telefono']) ?>">
            <?php if (isset($errores['telefono'])): ?><p class="error-campo"><?= e($errores['telefono']) ?></p><?php endif; ?>
          </div>

          <?php if ($conFoto): ?>
            <div class="campo<?= isset($errores['fecha_nacimiento']) ? ' con-error' : '' ?>">
              <label for="fecha_nacimiento">Fecha de nacimiento <small>(opcional)</small></label>
              <input type="date" id="fecha_nacimiento" name="fecha_nacimiento" min="1900-01-01" max="<?= e(date('Y-m-d')) ?>"
                     autocomplete="bday" value="<?= e(fechaOpcional('fecha_nacimiento') ?? (string)($usuario['fecha_nacimiento'] ?? '')) ?>">
              <p class="ayuda">Solo la usamos para felicitarte. No se muestra a nadie.</p>
              <?php if (isset($errores['fecha_nacimiento'])): ?><p class="error-campo"><?= e($errores['fecha_nacimiento']) ?></p><?php endif; ?>
            </div>
          <?php endif; ?>

          <button type="submit" class="btn btn-primary">Guardar cambios</button>
        </form>
      </div>

      <?php if ($conFoto): ?>
        <div class="tarjeta" id="foto-perfil">
          <div class="tarjeta-encabezado">
            <h2>Foto de perfil <small class="texto-opcional">(opcional)</small></h2>
            <p>JPG, PNG o WEBP de hasta <?= e(tamano_legible(min(MAX_UPLOAD_BYTES, limite_subida(MAX_UPLOAD_BYTES)))) ?>.
               La recortamos en cuadrado. Solo la ves tú y el equipo de la tienda.</p>
          </div>
          <div class="perfil-foto-fila">
            <div class="perfil-avatar" aria-hidden="true">
              <?php if ($urlFoto !== ''): ?>
                <img src="<?= e($urlFoto) ?>" alt="" width="64" height="64" decoding="async">
              <?php else: ?>
                <span><?= e(FotoPerfil::iniciales($usuario)) ?></span>
              <?php endif; ?>
            </div>
            <form method="post" action="<?= e(url('cuenta/perfil.php')) ?>" enctype="multipart/form-data"
                  class="perfil-foto-form" data-una-vez>
              <?= campoToken() ?>
              <input type="hidden" name="accion" value="foto">
              <input type="hidden" name="MAX_FILE_SIZE" value="<?= (int)min(MAX_UPLOAD_BYTES, limite_subida(MAX_UPLOAD_BYTES)) ?>">
              <label class="visualmente-oculto" for="foto">Elegir foto</label>
              <input type="file" id="foto" name="foto" accept="image/jpeg,image/png,image/webp" required>
              <?php // Con el encuadre activo (recorte.js) el campo se oculta y esta
                    // etiqueta hace de botón: elegir la foto abre el encuadre. ?>
              <label for="foto" class="btn btn-secondary perfil-foto-elegir"><?= $urlFoto !== '' ? 'Cambiar foto' : 'Subir foto' ?></label>
              <button type="submit" class="btn btn-secondary perfil-foto-enviar"><?= $urlFoto !== '' ? 'Cambiar foto' : 'Subir foto' ?></button>
            </form>
            <?php if ($urlFoto !== ''): ?>
              <form method="post" action="<?= e(url('cuenta/perfil.php')) ?>" data-una-vez
                    data-confirmar="¿Quitar tu foto de perfil?">
                <?= campoToken() ?>
                <input type="hidden" name="accion" value="quitar_foto">
                <button type="submit" class="btn-enlace">Quitar foto</button>
              </form>
            <?php endif; ?>
          </div>
        </div>

        <dialog class="recorte" id="recorteFoto" aria-labelledby="recorteTitulo">
          <div class="recorte-caja">
            <h2 id="recorteTitulo">Encuadra tu foto</h2>
            <p class="recorte-ayuda">Arrástrala para centrarla y acércala con dos dedos o con el control.</p>
            <div class="recorte-marco" tabindex="0" role="img"
                 aria-label="Vista previa del encuadre. Las flechas mueven la foto; + y − la acercan o alejan.">
              <img alt="" draggable="false">
            </div>
            <div class="recorte-zoom">
              <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false"><circle cx="11" cy="11" r="7" fill="none" stroke="currentColor" stroke-width="2"/><path d="M8 11h6M20 20l-4-4" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
              <label for="recorteZoom" class="visualmente-oculto">Acercar la foto</label>
              <input type="range" id="recorteZoom" min="1" max="4" step="0.01" value="1">
              <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><circle cx="11" cy="11" r="7" fill="none" stroke="currentColor" stroke-width="2"/><path d="M8 11h6M11 8v6M20 20l-4-4" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
            </div>
            <div class="recorte-acciones">
              <button type="button" class="btn btn-outline-dark" data-recorte-cancelar>Cancelar</button>
              <button type="button" class="btn btn-primary" data-recorte-usar>Guardar foto</button>
            </div>
          </div>
        </dialog>
      <?php endif; ?>

      <div class="tarjeta">
        <div class="tarjeta-encabezado">
          <h2><?= $tienePassword ? 'Cambiar contraseña' : 'Crear una contraseña' ?></h2>
          <?php if (!$tienePassword): ?>
            <p>Entras con Google o Facebook. Si además creas una contraseña, podrás entrar también con tu correo.</p>
          <?php endif; ?>
        </div>

        <form method="post" action="<?= e(url('cuenta/perfil.php')) ?>" novalidate data-una-vez>
          <?= campoToken() ?>
          <input type="hidden" name="accion" value="password">

          <?php if ($tienePassword): ?>
            <div class="campo<?= isset($erroresPassword['password_actual']) ? ' con-error' : '' ?>">
              <label for="password_actual">Contraseña actual</label>
              <input type="password" id="password_actual" name="password_actual" required autocomplete="current-password">
              <?php if (isset($erroresPassword['password_actual'])): ?>
                <p class="error-campo"><?= e($erroresPassword['password_actual']) ?></p>
              <?php endif; ?>
            </div>
          <?php endif; ?>

          <div class="campo<?= isset($erroresPassword['password']) ? ' con-error' : '' ?>">
            <label for="password">Contraseña nueva</label>
            <input type="password" id="password" name="password" required autocomplete="new-password" minlength="8">
            <div class="medidor-password" id="medidorPassword" aria-hidden="true"><span></span></div>
            <?php if (isset($erroresPassword['password'])): ?>
              <p class="error-campo"><?= e($erroresPassword['password']) ?></p>
            <?php endif; ?>
          </div>

          <div class="campo">
            <label for="password_confirmar">Repite la contraseña nueva</label>
            <input type="password" id="password_confirmar" name="password_confirmar" required
                   autocomplete="new-password" minlength="8">
          </div>

          <button type="submit" class="btn btn-secondary">
            <?= $tienePassword ? 'Cambiar contraseña' : 'Crear contraseña' ?>
          </button>
        </form>
      </div>

      <?php
        $proveedoresPerfil = array_filter([
            'google'   => Google::configurado() || !empty($usuario['google_id']),
            'facebook' => CuentasExternas::disponible($pdo, 'facebook') && (Facebook::configurado() || !empty($usuario['facebook_id'])),
        ]);
      ?>
      <?php if ($proveedoresPerfil): ?>
      <div class="tarjeta" id="cuentas-conectadas">
        <div class="tarjeta-encabezado">
          <h2>Cuentas conectadas</h2>
          <p>Entra también con Google o Facebook. Para conectarlas o desconectarlas
             <?= $tienePassword ? 'te pedimos tu contraseña actual y ' : '' ?>te avisamos por correo.</p>
        </div>
        <?php foreach (array_keys($proveedoresPerfil) as $prov):
              $datosProv = CuentasExternas::PROVEEDORES[$prov];
              $conectado = !empty($usuario[$datosProv['columna']]); ?>
          <form method="post" action="<?= e(url('cuenta/perfil.php')) ?>" class="cuenta-conectada" data-una-vez>
            <?= campoToken() ?>
            <input type="hidden" name="proveedor" value="<?= e($prov) ?>">
            <input type="hidden" name="accion" value="<?= $conectado ? 'desvincular' : 'vincular' ?>">
            <p><strong><?= e($datosProv['nombre']) ?></strong>
               <span class="estado-suave <?= $conectado ? 'si' : 'no' ?>"><?= $conectado ? 'Conectada' : 'Sin conectar' ?></span></p>
            <?php if ($tienePassword): ?>
              <div class="campo">
                <label for="password_vinculo_<?= e($prov) ?>">Contraseña actual</label>
                <input type="password" id="password_vinculo_<?= e($prov) ?>" name="password_vinculo" required autocomplete="current-password">
              </div>
            <?php endif; ?>
            <button type="submit" class="btn <?= $conectado ? 'btn-secondary' : 'btn-primary' ?>">
              <?= $conectado ? 'Desconectar ' : 'Conectar ' ?><?= e($datosProv['nombre']) ?>
            </button>
          </form>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<div style="height:56px"></div>
<?php require __DIR__ . '/../includes/vistas/pie.php'; ?>
