<?php
/**
 * Clientes: búsqueda, historial de pedidos y activación de cuentas.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

$seccion = 'clientes';
Rbac::exigirPanel();
Rbac::exigir('clientes.ver');

/**
 * Condición del listado de clientes según los filtros. La comparten el
 * listado y las acciones sobre «todos los del filtro».
 *
 * @return array{0: string, 1: list<mixed>}
 */
function filtro_clientes(string $q, string $estado): array
{
    $where  = ["r.codigo = 'cliente'"];
    $params = [];
    if ($q !== '') {
        $t = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
        $where[] = '(u.nombre LIKE ? OR u.apellido LIKE ? OR u.email LIKE ? OR u.telefono LIKE ?)';
        array_push($params, $t, $t, $t, $t);
    }
    if ($estado === 'activos') {
        $where[] = 'u.activo = 1';
    } elseif ($estado === 'inactivos') {
        $where[] = 'u.activo = 0';
    }
    return [implode(' AND ', $where), $params];
}

const ESTADOS_CLIENTE = ['todos', 'activos', 'inactivos'];

// --- Acciones sobre varios clientes -----------------------------------
//
// De la barra de selección (ids marcados) o de «Aviso a todos» y «todos los
// del filtro» (`todo_filtro`, se vuelven a buscar aquí con los filtros).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && crudo('accion_masiva') !== '') {
    $qs = (string)crudo('volver_qs');
    $volver = 'admin/clientes.php' . ($qs !== '' && preg_match('/^[\w=&%+.\-@]*$/', $qs) ? '?' . $qs : '');
    exigirToken(false, $volver);
    Rbac::exigir('clientes.editar');
    $accion = opcion('accion_masiva', ['aviso', 'activar', 'desactivar'], '');

    if (casilla('todo_filtro') === 1) {
        [$w, $p] = filtro_clientes(texto('f_q', 80), opcion('f_estado', ESTADOS_CLIENTE, 'todos'));
        $st = $pdo->prepare("SELECT u.id FROM usuarios u JOIN roles r ON r.id = u.rol_id WHERE $w
                              ORDER BY u.id LIMIT " . Campanas::MAX_DESTINATARIOS);
        $st->execute($p);
        $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    } else {
        $ids = array_slice(array_values(array_unique(array_filter(
            array_map('intval', (array)($_POST['ids'] ?? [])), static fn(int $v): bool => $v > 0
        ))), 0, Campanas::MAX_DESTINATARIOS);
        if ($ids) {
            // Solo cuentas de cliente: los empleados no se tocan desde aquí.
            $huecos = implode(',', array_fill(0, count($ids), '?'));
            $st = $pdo->prepare("SELECT u.id FROM usuarios u JOIN roles r ON r.id = u.rol_id
                                  WHERE u.id IN ($huecos) AND r.codigo = 'cliente'");
            $st->execute($ids);
            $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        }
    }
    if (!$ids) {
        flash('error', 'No hay clientes seleccionados (o ya no existen).');
        redirigir($volver);
    }
    $cuantos = count($ids) . ' ' . unidad_plural(count($ids), 'clientes');

    if ($accion === 'aviso') {
        $tipo = opcion('tipo', Campanas::TIPOS, 'aviso');
        $r = Campanas::crear($pdo, $ids, $tipo, texto('titulo', 120), textoLargo('mensaje', 2000), casilla('por_correo') === 1);
        if (!$r['ok']) {
            flash('error', $r['error']);
            redirigir($volver);
        }
        $partes = ['Aviso enviado a ' . $r['destinatarios'] . ' ' . unidad_plural($r['destinatarios'], 'clientes')
                 . ': ya lo ven en su cuenta.'];
        if ($r['con_correo'] > 0) {
            $partes[] = 'Los correos (' . $r['con_correo'] . ') salen ahora en tandas: deja esta página abierta hasta que termine.';
        }
        if ($r['de_baja'] > 0) {
            $partes[] = $r['de_baja'] . ' ' . unidad_plural($r['de_baja'], 'clientes')
                      . ($r['de_baja'] === 1 ? ' se dio de baja de las promociones: no se le manda el correo.'
                                             : ' se dieron de baja de las promociones: a ellos no se les manda el correo.');
        }
        flash('exito', implode(' ', $partes));
        redirigir('admin/clientes.php?campana=' . $r['id'] . ($qs !== '' ? '&' . $qs : ''));
    }

    if ($accion === 'activar' || $accion === 'desactivar') {
        $nuevo  = $accion === 'activar' ? 1 : 0;
        $huecos = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare("UPDATE usuarios SET activo = ? WHERE id IN ($huecos)")->execute(array_merge([$nuevo], $ids));
        Auditoria::registrar($pdo, $nuevo ? 'activar' : 'desactivar', 'usuarios', [
            'recurso_tipo' => 'usuario', 'recurso_id' => count($ids) . ' clientes',
            'descripcion'  => ($nuevo ? 'Cuentas reactivadas: ' : 'Cuentas desactivadas: ') . $cuantos . '.',
            'detalles'     => ['ids' => $ids],
        ]);
        flash('exito', $nuevo
            ? "Listo: {$cuantos} con la cuenta activa."
            : "Listo: {$cuantos} con la cuenta desactivada: ya no pueden iniciar sesión.");
        redirigir($volver);
    }

    flash('error', 'Acción no reconocida.');
    redirigir($volver);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirToken(false, 'admin/clientes.php');
    Rbac::exigir('clientes.editar');

    $id = identificador('id');
    $st = $pdo->prepare(
        "SELECT u.*, r.codigo AS rol_codigo FROM usuarios u
      LEFT JOIN roles r ON r.id = u.rol_id WHERE u.id = ?"
    );
    $st->execute([$id]);
    $cliente = $st->fetch();

    if (!$cliente || $cliente['rol_codigo'] !== 'cliente') {
        flash('error', 'Esa cuenta no es un cliente. Los empleados se editan en su propia sección.');
        redirigir('admin/clientes.php');
    }

    switch (opcion('accion', ['activar', 'notas', 'restablecer', 'aviso', 'felicitar'], '')) {
        case 'aviso':
        case 'felicitar':
            if ((int)$cliente['activo'] !== 1) {
                flash('error', 'La cuenta está desactivada: reactívala antes de mandarle avisos.');
                break;
            }
            $felicitar = ($_POST['accion'] ?? '') === 'felicitar';
            $tipo      = $felicitar ? 'cumpleanos'
                       : opcion('tipo', ['aviso', 'sugerencia', 'advertencia'], 'aviso');

            // El cupón de regalo es crear un cupón nuevo: hace falta el permiso
            // de cupones, y la plantilla tiene que ser una de las que se pueden
            // regalar (activa, vigente, sin dueño), no cualquier id del POST.
            $plantilla = null;
            $idBase    = $felicitar ? identificador('cupon_base') : 0;
            if ($idBase > 0) {
                Rbac::exigir('cupones.gestionar');
                foreach (Avisos::plantillasCupon($pdo) as $c) {
                    if ((int)$c['id'] === $idBase) {
                        $plantilla = $c;
                        break;
                    }
                }
                if ($plantilla === null) {
                    flash('error', 'Ese cupón ya no se puede regalar. Elige otro.');
                    break;
                }
            }

            $r = Avisos::enviar($pdo, $cliente, $tipo, texto('titulo', 120), textoLargo('mensaje', 2000),
                casilla('por_correo') === 1, $plantilla, entero('vigencia', 1, 90, 15));
            if (!$r['ok']) {
                flash('error', $r['error']);
                break;
            }
            $partes = [$felicitar ? 'Felicitación enviada.' : 'Aviso enviado.'];
            if ($r['cupon'] !== '') {
                $partes[] = 'Cupón ' . $r['cupon'] . ' creado: solo lo puede usar este cliente, una vez.';
            }
            $partes[] = casilla('por_correo') === 1
                ? ($r['correo'] ? 'También le llegó por correo.' : 'No se pudo enviar el correo; lo verá en su cuenta.')
                : 'Lo verá en «Mis avisos» al entrar a su cuenta.';
            flash($r['correo'] || casilla('por_correo') !== 1 ? 'exito' : 'alerta', implode(' ', $partes));
            break;

        case 'restablecer':
            // El panel nunca ve ni el enlace ni el código: van solo al correo
            // del cliente. Así nadie del equipo puede entrar en una cuenta.
            if ((int)$cliente['activo'] !== 1) {
                flash('error', 'La cuenta está desactivada. Reactívala antes de enviarle el correo.');
                break;
            }
            if (trim((string)$cliente['email']) === '') {
                flash('error', 'Esta cuenta no tiene correo.');
                break;
            }
            if (!limitar($pdo, 'panel-reset:' . $id, 3, 3600)) {
                flash('error', 'Ya se le enviaron varios correos a este cliente en la última hora. Espera un rato.');
                break;
            }
            $ok = Recuperacion::emitir($pdo, $cliente, 'panel');
            Auditoria::registrar($pdo, 'enviar_reset', 'usuarios', [
                'recurso_tipo' => 'usuario', 'recurso_id' => (string)$id,
                'descripcion'  => ($ok ? 'Enviado' : 'Falló el envío de') . ' el correo para restablecer la contraseña a '
                                . $cliente['email'],
            ]);
            flash($ok ? 'exito' : 'error', $ok
                ? 'Le enviamos a ' . $cliente['email'] . ' un correo con un enlace y un código para crear una '
                  . 'contraseña nueva. Caduca en ' . CodigoCorreo::plazo(Recuperacion::MINUTOS_PANEL) . '.'
                : 'No se pudo enviar el correo. Revisa la configuración de correo en Ajustes.');
            break;

        case 'activar':
            $nuevo = (int)$cliente['activo'] === 1 ? 0 : 1;
            $pdo->prepare("UPDATE usuarios SET activo = ? WHERE id = ?")->execute([$nuevo, $id]);
            Auditoria::registrar($pdo, $nuevo ? 'activar' : 'desactivar', 'usuarios', [
                'recurso_tipo' => 'usuario', 'recurso_id' => (string)$id,
                'descripcion'  => ($nuevo ? 'Cuenta reactivada: ' : 'Cuenta desactivada: ') . $cliente['email'],
            ]);
            flash('exito', $nuevo ? 'Cuenta reactivada.' : 'Cuenta desactivada: ya no podrá iniciar sesión.');
            break;

        case 'notas':
            $notas = texto('notas', 500);
            $pdo->prepare("UPDATE usuarios SET notas = ? WHERE id = ?")->execute([$notas, $id]);
            Auditoria::registrar($pdo, 'editar', 'usuarios', [
                'recurso_tipo' => 'usuario', 'recurso_id' => (string)$id,
                'descripcion'  => 'Nota interna sobre el cliente ' . $cliente['email'],
            ]);
            flash('exito', 'Nota guardada.');
            break;
    }
    redirigir('admin/clientes.php?ver=' . $id);
}

$q      = texto('q', 80, $_GET);
$estado = opcion('estado', ESTADOS_CLIENTE, 'todos', $_GET);
$verId  = identificador('ver', $_GET);
$pagina = entero('pagina', 1, 9999, 1, $_GET);
$porPagina = 25;

[$sqlWhere, $params] = filtro_clientes($q, $estado);

$stTotal = $pdo->prepare("SELECT COUNT(*) FROM usuarios u JOIN roles r ON r.id = u.rol_id WHERE $sqlWhere");
$stTotal->execute($params);
$total   = (int)$stTotal->fetchColumn();
$paginas = max(1, (int)ceil($total / $porPagina));
$pagina  = min($pagina, $paginas);
$salto   = ($pagina - 1) * $porPagina;

$st = $pdo->prepare(
    "SELECT u.*, 
            (SELECT COUNT(*) FROM pedidos p WHERE p.usuario_id = u.id) AS pedidos,
            (SELECT COALESCE(SUM(p.total), 0) FROM pedidos p
              WHERE p.usuario_id = u.id AND p.estado_pago = 'aprobado') AS gastado
       FROM usuarios u JOIN roles r ON r.id = u.rol_id
      WHERE $sqlWhere ORDER BY u.created_at DESC LIMIT $porPagina OFFSET $salto"
);
$st->execute($params);
$clientes = $st->fetchAll();

// Ficha ampliada
$detalle = null;
if ($verId > 0) {
    $st = $pdo->prepare(
        "SELECT u.* FROM usuarios u JOIN roles r ON r.id = u.rol_id
          WHERE u.id = ? AND r.codigo = 'cliente'"
    );
    $st->execute([$verId]);
    $detalle = $st->fetch() ?: null;
    if ($detalle) {
        $detalle['pedidos'] = Pedidos::deUsuario($pdo, $verId, 20);
    }
}
$conAvisos     = $detalle !== null && Avisos::disponible($pdo);
$avisosCliente = $conAvisos ? Avisos::deUsuario($pdo, (int)$detalle['id'], 15) : [];
$diasCumple    = $conAvisos ? Avisos::diasParaCumple($detalle['fecha_nacimiento'] ?? null) : null;
$felicitado    = ($conAvisos && $diasCumple !== null) ? Avisos::felicitadoEsteAnio($pdo, (int)$detalle['id']) : null;
$plantillas    = ($conAvisos && Rbac::puede('cupones.gestionar')) ? Avisos::plantillasCupon($pdo) : [];

// Acciones sobre varios clientes y avisos masivos.
$puedeMasivo   = Rbac::puede('clientes.editar');
$conCampanas   = Campanas::disponible($pdo);
$campanaActual = ($conCampanas && $puedeMasivo && ($cid = identificador('campana', $_GET)) > 0) ? Campanas::estado($pdo, $cid) : null;
$activosFiltro = 0;
if ($conCampanas && $puedeMasivo && !$detalle) {
    [$wa, $pa] = filtro_clientes($q, $estado);
    $st = $pdo->prepare("SELECT COUNT(*) FROM usuarios u JOIN roles r ON r.id = u.rol_id WHERE $wa AND u.activo = 1");
    $st->execute($pa);
    $activosFiltro = (int)$st->fetchColumn();
}

$tituloPanel    = 'Clientes';
$subtituloPanel = $total . ' ' . ($total === 1 ? 'cuenta de cliente' : 'cuentas de cliente');

require __DIR__ . '/_cabecera.php';
?>

<?php if ($detalle): ?>
  <a class="boton boton-claro boton-mini" href="<?= e(url('admin/clientes.php')) ?>" style="margin-bottom:16px;">
    <i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Volver a la lista</a>

  <?php if ($diasCumple !== null && $diasCumple <= Avisos::DIAS_AVISO): ?>
    <div class="cumple-banda">
      <span class="cumple-icono" aria-hidden="true"><i class="fa-solid fa-cake-candles"></i></span>
      <div class="cumple-texto">
        <strong><?= $diasCumple === 0 ? '¡Hoy es su cumpleaños!'
            : 'Cumple años ' . ($diasCumple === 1 ? 'mañana' : 'en ' . $diasCumple . ' días') ?></strong>
        <span><?= e(fecha_corta(date('Y') . substr((string)$detalle['fecha_nacimiento'], 4))) ?>
          <?= $felicitado ? ' · Ya lo felicitaste el ' . e(date('d/m', strtotime($felicitado))) . '.' : '' ?></span>
      </div>
      <?php if (Rbac::puede('clientes.editar') && (int)$detalle['activo'] === 1): ?>
        <button type="button" class="boton boton-principal" data-abrir-modal="modalCumple">
          <i class="fa-solid fa-gift" aria-hidden="true"></i>
          <?= $felicitado ? 'Felicitar otra vez' : 'Felicitar y regalar un cupón' ?></button>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="rejilla-detalle">
    <section class="panel">
      <div class="panel-cabecera">
        <div class="cliente-cabecera">
          <?php $fotoCliente = FotoPerfil::url($detalle, true); ?>
          <span class="avatar-cliente" aria-hidden="true">
            <?php if ($fotoCliente !== ''): ?>
              <img src="<?= e($fotoCliente) ?>" alt="" width="48" height="48" loading="lazy">
            <?php else: ?>
              <?= e(FotoPerfil::iniciales($detalle)) ?>
            <?php endif; ?>
          </span>
          <div>
            <h2><?= e(trim((string)$detalle['nombre'] . ' ' . (string)$detalle['apellido'])) ?></h2>
            <p>Cliente desde <?= e(fecha_corta((string)$detalle['created_at'])) ?></p>
          </div>
        </div>
        <span class="estado-suave <?= (int)$detalle['activo'] ? 'si' : 'mal' ?>">
          <?= (int)$detalle['activo'] ? 'Activa' : 'Desactivada' ?></span>
      </div>
      <div class="panel-cuerpo">
        <dl class="lista-datos">
          <div><dt>Correo</dt><dd><?= e((string)$detalle['email']) ?>
            <span class="estado-suave <?= $detalle['email_verificado_en'] ? 'si' : 'no' ?>">
              <?= $detalle['email_verificado_en'] ? 'Confirmado' : 'Sin confirmar' ?></span></dd></div>
          <div><dt>Teléfono</dt><dd><?= e((string)$detalle['telefono']) ?></dd></div>
          <?php if (!empty($detalle['fecha_nacimiento'])): ?>
            <div><dt>Cumpleaños</dt><dd><?= e(date('d/m', strtotime((string)$detalle['fecha_nacimiento']))) ?></dd></div>
          <?php endif; ?>
          <div><dt>Contraseña</dt><dd><?= (string)$detalle['password_hash'] !== '' ? 'Sí tiene' : 'No (entra con Google o Facebook)' ?></dd></div>
          <div><dt>Acceso con Google</dt><dd><?= $detalle['google_id'] ? 'Sí' : 'No' ?></dd></div>
          <?php if (array_key_exists('facebook_id', $detalle)): ?>
            <div><dt>Acceso con Facebook</dt><dd><?= $detalle['facebook_id'] ? 'Sí' : 'No' ?></dd></div>
          <?php endif; ?>
          <div><dt>Último acceso</dt><dd><?= $detalle['ultimo_acceso']
              ? e(fecha_larga((string)$detalle['ultimo_acceso'])) : 'Nunca' ?></dd></div>
        </dl>

        <?php if (Rbac::puede('clientes.editar')): ?>
          <form method="post" action="<?= e(url('admin/clientes.php')) ?>" style="margin-top:18px;">
            <?= campoToken() ?>
            <input type="hidden" name="accion" value="notas">
            <input type="hidden" name="id" value="<?= (int)$detalle['id'] ?>">
            <div class="campo">
              <label for="notas">Nota interna</label>
              <textarea id="notas" name="notas" maxlength="500"
                        placeholder="Prefiere entregas por la tarde…"><?= e((string)$detalle['notas']) ?></textarea>
            </div>
            <button type="submit" class="boton boton-claro">Guardar nota</button>
          </form>

          <?php if ((int)$detalle['activo'] === 1): ?>
            <form method="post" action="<?= e(url('admin/clientes.php')) ?>" style="margin-top:14px;" data-una-vez
                  data-confirmar="¿Enviar a <?= e((string)$detalle['email']) ?> un correo para crear una contraseña nueva?">
              <?= campoToken() ?>
              <input type="hidden" name="accion" value="restablecer">
              <input type="hidden" name="id" value="<?= (int)$detalle['id'] ?>">
              <button type="submit" class="boton boton-claro boton-multilinea">
                <i class="fa-solid fa-key" aria-hidden="true"></i> Enviar correo para restablecer la contraseña</button>
              <p class="ayuda" style="margin-top:6px;">Le llega un enlace y un código que caducan en
                <?= e(CodigoCorreo::plazo(Recuperacion::MINUTOS_PANEL)) ?>. Tú no los ves: solo el cliente.</p>
            </form>
          <?php endif; ?>

          <form method="post" action="<?= e(url('admin/clientes.php')) ?>" style="margin-top:14px;"
                data-confirmar="<?= (int)$detalle['activo']
                    ? '¿Desactivar la cuenta? No podrá iniciar sesión.'
                    : '¿Reactivar la cuenta?' ?>">
            <?= campoToken() ?>
            <input type="hidden" name="accion" value="activar">
            <input type="hidden" name="id" value="<?= (int)$detalle['id'] ?>">
            <button type="submit" class="boton <?= (int)$detalle['activo'] ? 'boton-peligro' : 'boton-exito' ?>">
              <?= (int)$detalle['activo'] ? 'Desactivar cuenta' : 'Reactivar cuenta' ?></button>
          </form>
        <?php endif; ?>
      </div>
    </section>

    <section class="panel">
      <div class="panel-cabecera"><div><h2>Pedidos</h2></div></div>
      <?php if (!$detalle['pedidos']): ?>
        <div class="vacio" style="padding:30px 12px;">
          <i class="fa-solid fa-receipt" aria-hidden="true"></i>
          <h3>Sin pedidos todavía</h3>
        </div>
      <?php else: ?>
        <div class="tabla-envoltura">
          <table class="tabla" style="min-width:auto;">
            <thead><tr><th>Pedido</th><th>Estado</th><th class="num">Total</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($detalle['pedidos'] as $p):
                  $est = Pedidos::estado($pdo, 'pedido', (string)$p['estado']); ?>
                <tr>
                  <td><span class="celda-principal"><?= e((string)$p['codigo']) ?></span><br>
                    <span class="celda-sub"><?= e(fecha_corta((string)$p['created_at'])) ?></span></td>
                  <td><span class="estado" style="background: <?= e((string)$est['color']) ?>;">
                    <?= e((string)$est['nombre']) ?></span></td>
                  <td class="num"><?= e((string)$p['moneda'] . number_format((float)$p['total'], 2)) ?></td>
                  <td class="acciones">
                    <a class="boton-icono" href="<?= e(url('admin/pedido.php?id=' . (int)$p['id'])) ?>"
                       aria-label="Abrir pedido"><i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>
  </div>

  <?php if ($conAvisos): ?>
    <section class="panel" id="avisos">
      <div class="panel-cabecera"><div>
        <h2>Avisos al cliente</h2>
        <p>Lo ve en «Mis avisos» al entrar a su cuenta y, si lo marcas, también le llega por correo.
           Es texto: no admite enlaces ni formato.</p>
      </div></div>
      <div class="panel-cuerpo avisos-rejilla">
        <?php if (Rbac::puede('clientes.editar') && (int)$detalle['activo'] === 1): ?>
          <form method="post" action="<?= e(url('admin/clientes.php')) ?>#avisos" class="avisos-formulario" data-una-vez>
            <?= campoToken() ?>
            <input type="hidden" name="accion" value="aviso">
            <input type="hidden" name="id" value="<?= (int)$detalle['id'] ?>">
            <div class="campo">
              <label for="av_tipo">Tipo</label>
              <select id="av_tipo" name="tipo">
                <option value="aviso">Aviso</option>
                <option value="sugerencia">Sugerencia</option>
                <option value="advertencia">Advertencia</option>
              </select>
            </div>
            <div class="campo">
              <label for="av_titulo">Título</label>
              <input type="text" id="av_titulo" name="titulo" required minlength="3" maxlength="120"
                     placeholder="Tu pedido del viernes">
            </div>
            <div class="campo">
              <label for="av_mensaje">Mensaje</label>
              <textarea id="av_mensaje" name="mensaje" required minlength="3" maxlength="2000" rows="5"
                        placeholder="Escribe lo que quieras decirle…"></textarea>
            </div>
            <div class="interruptor">
              <input type="checkbox" id="av_correo" name="por_correo" value="1" checked>
              <label for="av_correo">Enviárselo también por correo</label>
            </div>
            <button type="submit" class="boton boton-principal">
              <i class="fa-solid fa-paper-plane" aria-hidden="true"></i> Enviar aviso</button>
          </form>
        <?php endif; ?>

        <div class="avisos-historial">
          <p class="etiqueta">Enviados</p>
          <?php if (!$avisosCliente): ?>
            <p class="celda-sub">Todavía no se le ha enviado ningún aviso.</p>
          <?php else: ?>
            <ul class="lista-avisos">
              <?php foreach ($avisosCliente as $a):
                  [$nombreTipo, $iconoTipo] = Avisos::TIPOS[$a['tipo']] ?? Avisos::TIPOS['aviso']; ?>
                <li class="aviso-item tipo-<?= e((string)$a['tipo']) ?>">
                  <span class="aviso-icono" aria-hidden="true"><i class="fa-solid <?= e($iconoTipo) ?>"></i></span>
                  <div class="aviso-cuerpo">
                    <p class="aviso-titulo"><strong><?= e((string)$a['titulo']) ?></strong>
                      <span class="aviso-tipo"><?= e($nombreTipo) ?></span></p>
                    <p class="aviso-texto"><?= nl2br(e((string)$a['mensaje'])) ?></p>
                    <?php if (!empty($a['cupon_codigo'])):
                        $estadoCupon = Avisos::estadoCupon($a); ?>
                      <p class="aviso-cupon">Cupón <code><?= e((string)$a['cupon_codigo']) ?></code>
                        <span class="estado-suave <?= $estadoCupon === 'disponible' ? 'si' : ($estadoCupon === 'usado' ? 'aviso' : 'no') ?>">
                          <?= e(['disponible' => 'Sin usar', 'usado' => 'Usado', 'vencido' => 'Vencido', 'anulado' => 'Desactivado'][$estadoCupon]) ?></span></p>
                    <?php endif; ?>
                    <p class="celda-sub">
                      <?= e(fecha_larga((string)$a['created_at'])) ?>
                      <?= $a['autor'] ? ' · ' . e((string)$a['autor']) : '' ?>
                      · <?= $a['leida_en'] ? 'Leído' : 'Sin leer' ?>
                      <?= (int)$a['por_correo'] ? ' · ' . ((int)$a['correo_enviado'] ? 'Enviado por correo' : 'El correo no salió') : '' ?>
                    </p>
                  </div>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
      </div>
    </section>

    <?php if ($diasCumple !== null && Rbac::puede('clientes.editar') && (int)$detalle['activo'] === 1): ?>
      <dialog class="modal" id="modalCumple">
        <form method="post" action="<?= e(url('admin/clientes.php')) ?>#avisos" data-una-vez>
          <?= campoToken() ?>
          <input type="hidden" name="accion" value="felicitar">
          <input type="hidden" name="id" value="<?= (int)$detalle['id'] ?>">
          <div class="modal-cabecera"><h2><i class="fa-solid fa-cake-candles" aria-hidden="true"></i>
            Felicitar a <?= e((string)$detalle['nombre']) ?></h2></div>
          <div class="modal-cuerpo">
            <div class="campo">
              <label for="fc_titulo">Título</label>
              <input type="text" id="fc_titulo" name="titulo" required minlength="3" maxlength="120"
                     value="<?= e('¡Feliz cumpleaños, ' . $detalle['nombre'] . '!') ?>">
            </div>
            <div class="campo">
              <label for="fc_mensaje">Mensaje</label>
              <textarea id="fc_mensaje" name="mensaje" required minlength="3" maxlength="2000" rows="5"><?= e(
                  'En ' . Ajustes::texto('nombre_tienda', 'Flowers Anto') . ' te deseamos un día precioso. '
                . 'Gracias por elegirnos para tus momentos especiales: hoy el regalo te lo hacemos nosotros.') ?></textarea>
            </div>
            <?php if (Rbac::puede('cupones.gestionar')): ?>
              <div class="rejilla-campos dos">
                <div class="campo">
                  <label for="fc_cupon">Regalarle un cupón</label>
                  <select id="fc_cupon" name="cupon_base">
                    <option value="0">Sin cupón</option>
                    <?php foreach ($plantillas as $i => $c): ?>
                      <option value="<?= (int)$c['id'] ?>"<?= $i === 0 ? ' selected' : '' ?>><?= e($c['codigo'] . ' · ' . Cupones::resumen($c)
                          . ((float)$c['compra_minima'] > 0 ? ' desde ' . dinero($c['compra_minima']) : '')) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <p class="ayuda">Se crea un cupón nuevo con ese mismo descuento, de un solo uso y que solo
                    puede usar <?= e((string)$detalle['nombre']) ?> con su cuenta. El original no cambia.</p>
                </div>
                <div class="campo">
                  <label for="fc_vigencia">Válido durante</label>
                  <select id="fc_vigencia" name="vigencia">
                    <?php foreach (Avisos::VIGENCIAS as $d): ?>
                      <option value="<?= $d ?>"<?= $d === 15 ? ' selected' : '' ?>><?= $d ?> días</option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
              <?php if (!$plantillas): ?>
                <p class="ayuda">No hay cupones activos para usar de base. Crea uno en
                  <a href="<?= e(url('admin/cupones.php')) ?>">Cupones</a>.</p>
              <?php endif; ?>
            <?php endif; ?>
            <div class="interruptor">
              <input type="checkbox" id="fc_correo" name="por_correo" value="1" checked>
              <label for="fc_correo">Enviárselo también por correo</label>
            </div>
          </div>
          <div class="modal-pie">
            <button type="button" class="boton boton-claro" data-cerrar-modal>Cancelar</button>
            <button type="submit" class="boton boton-principal"><i class="fa-solid fa-gift" aria-hidden="true"></i>
              Enviar felicitación</button>
          </div>
        </form>
      </dialog>
    <?php endif; ?>
  <?php endif; ?>

<?php else: ?>
  <?php if ($campanaActual): ?>
    <?php // El envío que se acaba de crear: los correos salen en tandas mientras la página está abierta. ?>
    <section class="panel panel-envio" data-campana-progreso data-campana="<?= (int)$campanaActual['id'] ?>"
             data-url="<?= e(url('admin/avisos-envio.php')) ?>"
             data-con-correo="<?= (int)$campanaActual['con_correo'] ?>"
             data-enviados="<?= (int)$campanaActual['enviados'] ?>"
             data-pendientes="<?= (int)$campanaActual['pendientes'] ?>">
      <div class="envio-cabecera">
        <span class="envio-icono" aria-hidden="true"><i class="fa-solid <?= e((Avisos::TIPOS[$campanaActual['tipo']] ?? Avisos::TIPOS['aviso'])[1]) ?>"></i></span>
        <div>
          <h2><?= e((string)$campanaActual['titulo']) ?></h2>
          <p class="celda-sub"><?= e((Avisos::TIPOS[$campanaActual['tipo']] ?? Avisos::TIPOS['aviso'])[0]) ?>
            para <?= (int)$campanaActual['destinatarios'] ?> <?= unidad_plural((int)$campanaActual['destinatarios'], 'clientes') ?> · ya lo ven en su cuenta</p>
        </div>
      </div>
      <?php if ((int)$campanaActual['con_correo'] > 0): ?>
        <div class="envio-barra" role="progressbar" aria-label="Correos enviados" aria-valuemin="0"
             aria-valuemax="<?= (int)$campanaActual['con_correo'] ?>" aria-valuenow="<?= (int)$campanaActual['enviados'] ?>">
          <span data-envio-relleno style="width:<?= (int)round(100 * $campanaActual['enviados'] / max(1, $campanaActual['con_correo'])) ?>%"></span>
        </div>
        <p class="envio-texto" data-envio-texto role="status" aria-live="polite">
          <?= (int)$campanaActual['enviados'] ?> de <?= (int)$campanaActual['con_correo'] ?> correos enviados.
          <?= (int)$campanaActual['pendientes'] > 0 ? 'Enviando… deja esta página abierta.' : 'Envío terminado.' ?></p>
        <button type="button" class="boton boton-claro boton-mini" data-envio-reintentar hidden>
          <i class="fa-solid fa-rotate-right" aria-hidden="true"></i> Continuar el envío</button>
      <?php else: ?>
        <p class="envio-texto">Sin correo en este envío: solo el aviso en la cuenta de cada cliente.</p>
      <?php endif; ?>
    </section>
  <?php endif; ?>

  <section class="panel" data-seleccion="clientes" data-total="<?= (int)$total ?>"
           data-singular="cliente" data-plural="clientes">
    <form class="barra-herramientas" method="get" action="<?= e(url('admin/clientes.php')) ?>">
      <div class="campo">
        <label for="q">Buscar</label>
        <input type="search" id="q" name="q" value="<?= e($q) ?>" placeholder="Nombre, correo o teléfono">
      </div>
      <div class="campo estrecho">
        <label for="estado">Cuentas</label>
        <select id="estado" name="estado">
          <option value="todos"<?= $estado === 'todos' ? ' selected' : '' ?>>Todas</option>
          <option value="activos"<?= $estado === 'activos' ? ' selected' : '' ?>>Activas</option>
          <option value="inactivos"<?= $estado === 'inactivos' ? ' selected' : '' ?>>Inactivas</option>
        </select>
      </div>
      <button type="submit" class="boton boton-principal"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i> Buscar</button>
      <?php if ($q !== '' || $estado !== 'todos'): ?>
        <a class="boton boton-claro" href="<?= e(url('admin/clientes.php')) ?>">Limpiar</a>
      <?php endif; ?>
      <?php if ($puedeMasivo && $clientes): ?>
        <span class="barra-herramientas-separador" aria-hidden="true"></span>
        <button type="button" class="boton boton-claro boton-seleccionar" data-seleccion-alternar aria-pressed="false"
                data-texto-activo="Terminar selección">
          <i class="fa-solid fa-list-check" aria-hidden="true"></i> <span>Seleccionar</span></button>
        <?php if ($conCampanas && $activosFiltro > 0): ?>
          <button type="button" class="boton boton-claro" data-abrir-modal="modalAvisoTodos">
            <i class="fa-solid fa-bullhorn" aria-hidden="true"></i>
            Aviso a <?= ($q !== '' || $estado !== 'todos') ? 'los del filtro' : 'todos' ?></button>
        <?php endif; ?>
      <?php endif; ?>
    </form>

    <?php if (!$clientes): ?>
      <div class="vacio">
        <i class="fa-solid fa-users" aria-hidden="true"></i>
        <h3>No hay clientes con esos datos</h3>
        <p>Ten en cuenta que los pedidos de invitados no crean cuenta.</p>
      </div>
    <?php else: ?>
      <div class="tabla-envoltura">
        <table class="tabla tabla-seleccionable">
          <thead><tr>
            <?php if ($puedeMasivo): ?>
              <th class="col-sel"><input type="checkbox" data-sel-todos aria-label="Seleccionar todos los de esta página"></th>
            <?php endif; ?>
            <th>Cliente</th><th>Contacto</th><th class="num">Pedidos</th>
            <th class="num">Comprado</th><th>Estado</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($clientes as $c): ?>
              <tr>
                <?php if ($puedeMasivo): ?>
                  <td class="col-sel"><input type="checkbox" value="<?= (int)$c['id'] ?>" data-sel-item
                        aria-label="Seleccionar a <?= e(trim((string)$c['nombre'] . ' ' . (string)$c['apellido'])) ?>"></td>
                <?php endif; ?>
                <td>
                  <span class="celda-principal"><?= e(trim((string)$c['nombre'] . ' ' . (string)$c['apellido'])) ?></span><br>
                  <span class="celda-sub">Desde <?= e(fecha_corta((string)$c['created_at'])) ?>
                    <?= $c['google_id'] ? ' · Google' : '' ?></span>
                </td>
                <td>
                  <?= e((string)$c['email']) ?><br>
                  <span class="celda-sub"><?= e((string)$c['telefono']) ?>
                    <?php if (isset($c['acepta_promociones']) && (int)$c['acepta_promociones'] === 0): ?>
                      <span class="estado-suave no" title="Se dio de baja de las promociones por correo">Sin promociones</span>
                    <?php endif; ?></span>
                </td>
                <td class="num"><?= (int)$c['pedidos'] ?></td>
                <td class="num"><?= e(dinero($c['gastado'])) ?></td>
                <td><span class="estado-suave <?= (int)$c['activo'] ? 'si' : 'mal' ?>">
                  <?= (int)$c['activo'] ? 'Activa' : 'Inactiva' ?></span></td>
                <td class="acciones">
                  <a class="boton boton-claro boton-mini" href="<?= e(url('admin/clientes.php?ver=' . (int)$c['id'])) ?>">Ver ficha</a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?php if ($puedeMasivo):
          $camposFiltro = campoToken()
              . '<input type="hidden" name="f_q" value="' . e($q) . '">'
              . '<input type="hidden" name="f_estado" value="' . e($estado) . '">'
              . '<input type="hidden" name="volver_qs" value="' . e(http_build_query(array_filter(
                  ['q' => $q, 'estado' => $estado !== 'todos' ? $estado : '', 'pagina' => $pagina > 1 ? $pagina : '']))) . '">';
          // Los campos del aviso, iguales en «a los seleccionados» y «a todos».
          $camposAviso = static function (string $p) use ($conCampanas): string {
              ob_start(); ?>
              <div class="campo">
                <label for="<?= $p ?>Tipo">Tipo</label>
                <select id="<?= $p ?>Tipo" name="tipo" data-aviso-tipo>
                  <?php foreach (Campanas::TIPOS as $t): ?>
                    <option value="<?= e($t) ?>"><?= e(Avisos::TIPOS[$t][0]) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="campo">
                <label for="<?= $p ?>Titulo">Título</label>
                <input type="text" id="<?= $p ?>Titulo" name="titulo" required minlength="3" maxlength="120"
                       placeholder="Esta semana: 15% en rosas">
              </div>
              <div class="campo">
                <label for="<?= $p ?>Mensaje">Mensaje</label>
                <textarea id="<?= $p ?>Mensaje" name="mensaje" required minlength="3" maxlength="2000" rows="5"
                          placeholder="Escribe lo que quieras contarles…"></textarea>
              </div>
              <div class="interruptor">
                <input type="checkbox" id="<?= $p ?>Correo" name="por_correo" value="1" checked>
                <label for="<?= $p ?>Correo">Enviarlo también por correo
                  <small data-aviso-nota-promo hidden>Las promociones solo llegan por correo a quien no se ha dado
                    de baja; cada una lleva el enlace para hacerlo. El aviso en la cuenta les llega a todos.</small></label>
              </div>
              <?php return (string)ob_get_clean();
          }; ?>
        <form method="post" action="<?= e(url('admin/clientes.php')) ?>" id="formSeleccion" data-seleccion-form data-una-vez>
          <?= $camposFiltro ?>
        </form>

        <div class="barra-seleccion" data-seleccion-barra role="region" aria-label="Acciones con los clientes seleccionados" hidden>
          <div class="seleccion-info">
            <p><strong data-sel-n>0</strong> <span data-sel-palabra>clientes</span></p>
            <button type="button" class="seleccion-filtro" data-sel-filtro hidden></button>
          </div>
          <div class="seleccion-acciones">
            <?php if ($conCampanas): ?>
              <div class="seleccion-grupo" role="group" aria-label="Comunicar">
                <button type="button" data-abrir-modal="modalSelAviso" data-sel-accion>
                  <i class="fa-solid fa-paper-plane" aria-hidden="true"></i> Enviar aviso…</button>
              </div>
            <?php endif; ?>
            <div class="seleccion-grupo" role="group" aria-label="Cuenta">
              <button type="submit" form="formSeleccion" name="accion_masiva" value="activar" data-sel-accion>
                <i class="fa-solid fa-user-check" aria-hidden="true"></i> Activar</button>
              <button type="submit" form="formSeleccion" name="accion_masiva" value="desactivar" class="peligro" data-sel-accion
                      data-sel-confirmar="¿Desactivar {n} {palabra}? No podrán iniciar sesión hasta que reactives su cuenta.">
                <i class="fa-solid fa-user-slash" aria-hidden="true"></i> Desactivar</button>
            </div>
          </div>
          <button type="button" class="seleccion-cerrar" data-seleccion-alternar aria-label="Terminar la selección">
            <i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
        </div>

        <?php if ($conCampanas): ?>
          <dialog class="modal" id="modalSelAviso" aria-labelledby="tituloSelAviso">
            <form method="post" action="<?= e(url('admin/clientes.php')) ?>" data-seleccion-form data-una-vez>
              <?= $camposFiltro ?>
              <input type="hidden" name="accion_masiva" value="aviso">
              <div class="modal-cabecera"><h2 id="tituloSelAviso"><i class="fa-solid fa-paper-plane" aria-hidden="true"></i> Aviso a los seleccionados</h2></div>
              <div class="modal-cuerpo">
                <p class="modal-ayuda">Lo reciben <strong data-sel-n>0</strong> <span data-sel-palabra>clientes</span>
                  (solo las cuentas activas) en «Mis avisos».</p>
                <?= $camposAviso('selAviso') ?>
              </div>
              <div class="modal-pie">
                <button type="button" class="boton boton-claro" data-cerrar-modal>Cancelar</button>
                <button type="submit" class="boton boton-principal" data-sel-accion>
                  <i class="fa-solid fa-paper-plane" aria-hidden="true"></i> Enviar</button>
              </div>
            </form>
          </dialog>

          <dialog class="modal" id="modalAvisoTodos" aria-labelledby="tituloAvisoTodos">
            <form method="post" action="<?= e(url('admin/clientes.php')) ?>" data-una-vez
                  data-confirmar="¿Enviar este aviso a <?= (int)$activosFiltro ?> <?= unidad_plural($activosFiltro, 'clientes') ?>?">
              <?= $camposFiltro ?>
              <input type="hidden" name="accion_masiva" value="aviso">
              <input type="hidden" name="todo_filtro" value="1">
              <div class="modal-cabecera"><h2 id="tituloAvisoTodos"><i class="fa-solid fa-bullhorn" aria-hidden="true"></i>
                Aviso a <?= ($q !== '' || $estado !== 'todos') ? 'los clientes del filtro' : 'todos los clientes' ?></h2></div>
              <div class="modal-cuerpo">
                <p class="modal-ayuda">Lo reciben <strong><?= (int)$activosFiltro ?></strong>
                  <?= unidad_plural($activosFiltro, 'clientes') ?> con la cuenta activa<?= ($q !== '' || $estado !== 'todos') ? ' que coinciden con la búsqueda' : '' ?>,
                  en «Mis avisos» y, si lo marcas, por correo.</p>
                <?= $camposAviso('todos') ?>
              </div>
              <div class="modal-pie">
                <button type="button" class="boton boton-claro" data-cerrar-modal>Cancelar</button>
                <button type="submit" class="boton boton-principal">
                  <i class="fa-solid fa-bullhorn" aria-hidden="true"></i> Enviar a <?= (int)$activosFiltro ?></button>
              </div>
            </form>
          </dialog>
        <?php endif; ?>
      <?php endif; ?>

      <?php if ($paginas > 1): ?>
        <nav class="paginacion">
          <?php for ($i = 1; $i <= $paginas; $i++): ?>
            <?php if ($i === $pagina): ?><span class="actual"><?= $i ?></span>
            <?php else: ?>
              <a href="<?= e(url('admin/clientes.php?' . http_build_query(array_filter(['q' => $q, 'estado' => $estado !== 'todos' ? $estado : '', 'pagina' => $i])))) ?>"><?= $i ?></a>
            <?php endif; ?>
          <?php endfor; ?>
        </nav>
      <?php endif; ?>
    <?php endif; ?>
  </section>
<?php endif; ?>

<?php require __DIR__ . '/_pie.php'; ?>
