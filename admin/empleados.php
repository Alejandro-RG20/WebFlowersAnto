<?php
/**
 * Empleados: cuentas con acceso al panel.
 *
 * Reglas que se aplican en el servidor, no solo en la interfaz:
 *   - nadie puede cambiarse su propio rol ni desactivarse a sí mismo
 *   - siempre debe quedar al menos un super administrador activo
 *   - solo quien tiene `roles.gestionar` puede asignar el rol de super admin
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

$seccion = 'empleados';
Rbac::exigirPanel();
Rbac::exigir('empleados.ver');

/** ¿Cuántos super administradores activos quedan? */
function superAdminsActivos(PDO $pdo, int $excepto = 0): int
{
    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM usuarios u JOIN roles r ON r.id = u.rol_id
          WHERE r.codigo = 'super_admin' AND u.activo = 1 AND u.id <> ?"
    );
    $st->execute([$excepto]);
    return (int)$st->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirToken(false, 'admin/empleados.php');
    Rbac::exigir('empleados.gestionar');

    $accion = opcion('accion', ['guardar', 'activar', 'restablecer'], 'guardar');
    $id     = identificador('id');
    $yo     = (int)Auth::id();

    $empleado = null;
    if ($id > 0) {
        $st = $pdo->prepare(
            "SELECT u.*, r.codigo AS rol_codigo FROM usuarios u
          LEFT JOIN roles r ON r.id = u.rol_id WHERE u.id = ?"
        );
        $st->execute([$id]);
        $empleado = $st->fetch() ?: null;
    }

    if ($accion === 'activar') {
        if (!$empleado) {
            flash('error', 'Esa cuenta ya no existe.');
            redirigir('admin/empleados.php');
        }
        if ($id === $yo) {
            flash('error', 'No puedes desactivar tu propia cuenta.');
            redirigir('admin/empleados.php');
        }
        $nuevo = (int)$empleado['activo'] === 1 ? 0 : 1;
        if ($nuevo === 0 && $empleado['rol_codigo'] === 'super_admin' && superAdminsActivos($pdo, $id) === 0) {
            flash('error', 'Es el único super administrador activo. Asigna otro antes de desactivarlo.');
            redirigir('admin/empleados.php');
        }
        $pdo->prepare("UPDATE usuarios SET activo = ? WHERE id = ?")->execute([$nuevo, $id]);
        Auditoria::registrar($pdo, $nuevo ? 'activar' : 'desactivar', 'usuarios', [
            'recurso_tipo' => 'usuario', 'recurso_id' => (string)$id,
            'descripcion'  => ($nuevo ? 'Empleado activado: ' : 'Empleado desactivado: ') . $empleado['email'],
        ]);
        flash('exito', $nuevo ? 'Cuenta activada.' : 'Cuenta desactivada.');
        redirigir('admin/empleados.php');
    }

    if ($accion === 'restablecer') {
        if (!$empleado) {
            flash('error', 'Esa cuenta ya no existe.');
            redirigir('admin/empleados.php');
        }
        // No se genera ni se muestra ninguna contraseña: se envía el mismo
        // enlace de un solo uso que usa la recuperación normal.
        $pdo->prepare("UPDATE password_resets SET usado_en = NOW() WHERE usuario_id = ? AND usado_en IS NULL")
            ->execute([$id]);
        $token = bin2hex(random_bytes(32));
        $pdo->prepare(
            "INSERT INTO password_resets (usuario_id, token_hash, expira_en, ip)
             VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 120 MINUTE), ?)"
        )->execute([$id, hash('sha256', $token), ip_cliente()]);

        Correo::enviar(
            (string)$empleado['email'],
            'Restablece tu contraseña del panel',
            Correo::plantilla(
                'Restablece tu contraseña',
                '<p>Hola ' . e((string)$empleado['nombre']) . ', un administrador de '
                . e(Ajustes::texto('nombre_tienda', 'Flowers Anto')) . ' pidió que cambies tu contraseña '
                . 'del panel.</p><p>El enlace caduca en dos horas y solo sirve una vez.</p>',
                ['url' => url_absoluta('cuenta/restablecer.php?token=' . $token), 'texto' => 'Elegir contraseña']
            )
        );

        Auditoria::registrar($pdo, 'solicitar_reset', 'usuarios', [
            'recurso_tipo' => 'usuario', 'recurso_id' => (string)$id,
            'descripcion'  => 'Enlace de restablecimiento enviado a ' . $empleado['email'],
        ]);
        flash('exito', 'Le enviamos un enlace para que elija su contraseña.');
        redirigir('admin/empleados.php');
    }

    // --- Alta y edición -------------------------------------------------
    $nombre   = texto('nombre', 60);
    $apellido = texto('apellido', 60);
    $correo   = correoValido('email');
    $telefono = telefonoValido('telefono');
    $rolId    = identificador('rol_id');

    $stRol = $pdo->prepare("SELECT codigo, es_personal FROM roles WHERE id = ?");
    $stRol->execute([$rolId]);
    $rol = $stRol->fetch();

    if (mb_strlen($nombre) < 2 || mb_strlen($apellido) < 2) {
        flash('error', 'El nombre y el apellido son obligatorios.');
        redirigir('admin/empleados.php');
    }
    if ($correo === '') {
        flash('error', 'Escribe un correo válido: es con lo que inicia sesión.');
        redirigir('admin/empleados.php');
    }
    if (!$rol || (int)$rol['es_personal'] !== 1) {
        flash('error', 'Elige un rol con acceso al panel.');
        redirigir('admin/empleados.php');
    }
    if ($rol['codigo'] === 'super_admin' && !Rbac::puede('roles.gestionar')) {
        Auditoria::denegado($pdo, 'roles.gestionar', 'usuarios');
        flash('error', 'Solo un super administrador puede asignar ese rol.');
        redirigir('admin/empleados.php');
    }
    if ($id === $yo && $empleado && (int)$empleado['rol_id'] !== $rolId) {
        flash('error', 'No puedes cambiar tu propio rol. Pídeselo a otro administrador.');
        redirigir('admin/empleados.php');
    }
    if ($empleado && $empleado['rol_codigo'] === 'super_admin' && $rol['codigo'] !== 'super_admin'
        && superAdminsActivos($pdo, $id) === 0) {
        flash('error', 'Es el único super administrador. Asigna ese rol a otra persona antes de cambiarlo.');
        redirigir('admin/empleados.php');
    }

    $repetido = $pdo->prepare("SELECT id FROM usuarios WHERE email = ? AND id <> ?");
    $repetido->execute([$correo, $id]);
    if ($repetido->fetchColumn()) {
        flash('error', 'Ya existe una cuenta con ese correo.');
        redirigir('admin/empleados.php');
    }

    $esNuevo = $id === 0;

    if ($esNuevo) {
        // Sin contraseña: la elige la persona con el enlace que recibe.
        $pdo->prepare(
            "INSERT INTO usuarios (email, nombre, apellido, telefono, rol_id, activo,
                                   nombre_completo, password_hash)
             VALUES (?,?,?,?,?,1,?,NULL)"
        )->execute([$correo, $nombre, $apellido, $telefono, $rolId, trim($nombre . ' ' . $apellido)]);
        $id = (int)$pdo->lastInsertId();

        $token = bin2hex(random_bytes(32));
        $pdo->prepare(
            "INSERT INTO password_resets (usuario_id, token_hash, expira_en, ip)
             VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 48 HOUR), ?)"
        )->execute([$id, hash('sha256', $token), ip_cliente()]);

        Correo::enviar($correo, 'Tu acceso al panel de ' . Ajustes::texto('nombre_tienda', 'Flowers Anto'),
            Correo::plantilla(
                'Bienvenido al equipo',
                '<p>Hola ' . e($nombre) . ', te creamos una cuenta para el panel de '
                . e(Ajustes::texto('nombre_tienda', 'Flowers Anto')) . '.</p>'
                . '<p>Elige tu contraseña con el botón de abajo. El enlace caduca en 48 horas.</p>'
                . '<p>Tu usuario será este mismo correo.</p>',
                ['url' => url_absoluta('cuenta/restablecer.php?token=' . $token), 'texto' => 'Elegir mi contraseña']
            ));

        flash('exito', 'Cuenta creada. Le enviamos un correo para que elija su contraseña.');
    } else {
        $pdo->prepare(
            "UPDATE usuarios SET nombre = ?, apellido = ?, email = ?, telefono = ?, rol_id = ?, nombre_completo = ?
              WHERE id = ?"
        )->execute([$nombre, $apellido, $correo, $telefono, $rolId, trim($nombre . ' ' . $apellido), $id]);
        flash('exito', 'Datos del empleado actualizados.');
    }

    Auditoria::registrar($pdo, $esNuevo ? 'crear' : 'editar', 'usuarios', [
        'recurso_tipo' => 'usuario', 'recurso_id' => (string)$id,
        'descripcion'  => ($esNuevo ? 'Empleado creado: ' : 'Empleado editado: ') . $correo,
        'detalles'     => ['rol' => $rol['codigo']],
    ]);
    redirigir('admin/empleados.php');
}

$empleados = $pdo->query(
    "SELECT u.*, r.nombre AS rol_nombre, r.codigo AS rol_codigo
       FROM usuarios u JOIN roles r ON r.id = u.rol_id
      WHERE r.es_personal = 1
   ORDER BY u.activo DESC, r.codigo, u.nombre"
)->fetchAll();

$roles = $pdo->query("SELECT id, codigo, nombre, descripcion FROM roles WHERE es_personal = 1 ORDER BY id")->fetchAll();
if (!Rbac::puede('roles.gestionar')) {
    $roles = array_values(array_filter($roles, fn($r) => $r['codigo'] !== 'super_admin'));
}

$tituloPanel      = 'Empleados';
$subtituloPanel   = 'Cuentas con acceso al panel';
$accionesCabecera = Rbac::puede('empleados.gestionar')
    ? '<button type="button" class="boton boton-principal" data-abrir-modal="modalEmpleado"'
      . ' data-campo-id="0" data-campo-nombre="" data-campo-apellido="" data-campo-email="" data-campo-telefono="">'
      . '<i class="fa-solid fa-user-plus" aria-hidden="true"></i> Nuevo empleado</button>'
    : '';

require __DIR__ . '/_cabecera.php';
?>

<div class="caja-aviso info">
  <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
  <span>Las contraseñas no se escriben aquí. Al crear una cuenta se envía un enlace para que
    cada persona elija la suya, y nadie más llega a conocerla.</span>
</div>

<section class="panel">
  <div class="tabla-envoltura">
    <table class="tabla">
      <thead><tr><th>Persona</th><th>Rol</th><th>Último acceso</th><th>Estado</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($empleados as $emp): ?>
          <tr>
            <td>
              <span class="celda-principal"><?= e(trim((string)$emp['nombre'] . ' ' . (string)$emp['apellido'])) ?>
                <?php if ((int)$emp['id'] === (int)Auth::id()): ?>
                  <span class="estado-suave" style="margin-left:5px;">Tú</span>
                <?php endif; ?>
              </span><br>
              <span class="celda-sub"><?= e((string)$emp['email']) ?></span>
            </td>
            <td><span class="estado-suave"><?= e((string)$emp['rol_nombre']) ?></span></td>
            <td class="celda-sub"><?= $emp['ultimo_acceso']
                ? e(fecha_corta((string)$emp['ultimo_acceso'])) : 'Nunca' ?>
              <?php if ($emp['password_hash'] === null): ?>
                <br><span class="estado-suave aviso">Sin contraseña definida</span>
              <?php endif; ?>
            </td>
            <td><span class="estado-suave <?= (int)$emp['activo'] ? 'si' : 'mal' ?>">
              <?= (int)$emp['activo'] ? 'Activa' : 'Inactiva' ?></span></td>
            <td class="acciones">
              <?php if (Rbac::puede('empleados.gestionar')): ?>
                <div style="display:inline-flex; gap:5px;">
                  <button type="button" class="boton-icono" data-abrir-modal="modalEmpleado"
                          data-campo-id="<?= (int)$emp['id'] ?>"
                          data-campo-nombre="<?= e((string)$emp['nombre']) ?>"
                          data-campo-apellido="<?= e((string)$emp['apellido']) ?>"
                          data-campo-email="<?= e((string)$emp['email']) ?>"
                          data-campo-telefono="<?= e((string)$emp['telefono']) ?>"
                          data-campo-rol_id="<?= (int)$emp['rol_id'] ?>"
                          aria-label="Editar empleado">
                    <i class="fa-solid fa-pen" aria-hidden="true"></i></button>

                  <form method="post" action="<?= e(url('admin/empleados.php')) ?>"
                        data-confirmar="¿Enviarle un enlace para restablecer su contraseña?">
                    <?= campoToken() ?>
                    <input type="hidden" name="accion" value="restablecer">
                    <input type="hidden" name="id" value="<?= (int)$emp['id'] ?>">
                    <button type="submit" class="boton-icono" aria-label="Enviar enlace de contraseña"
                            title="Enviar enlace de contraseña">
                      <i class="fa-solid fa-key" aria-hidden="true"></i></button>
                  </form>

                  <?php if ((int)$emp['id'] !== (int)Auth::id()): ?>
                    <form method="post" action="<?= e(url('admin/empleados.php')) ?>"
                          data-confirmar="<?= (int)$emp['activo'] ? '¿Desactivar esta cuenta?' : '¿Reactivar esta cuenta?' ?>">
                      <?= campoToken() ?>
                      <input type="hidden" name="accion" value="activar">
                      <input type="hidden" name="id" value="<?= (int)$emp['id'] ?>">
                      <button type="submit" class="boton-icono <?= (int)$emp['activo'] ? 'peligro' : '' ?>"
                              aria-label="<?= (int)$emp['activo'] ? 'Desactivar' : 'Activar' ?>">
                        <i class="fa-solid fa-<?= (int)$emp['activo'] ? 'user-slash' : 'user-check' ?>" aria-hidden="true"></i></button>
                    </form>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<?php if (Rbac::puede('empleados.gestionar')): ?>
<dialog class="modal" id="modalEmpleado">
  <form method="post" action="<?= e(url('admin/empleados.php')) ?>">
    <?= campoToken() ?>
    <input type="hidden" name="accion" value="guardar">
    <input type="hidden" name="id" value="0">
    <div class="modal-cabecera">
      <h2>Empleado</h2>
      <p class="sub">Recibirá un correo para elegir su contraseña.</p>
    </div>
    <div class="modal-cuerpo">
      <div class="rejilla-campos dos">
        <div class="campo">
          <label for="e_nombre">Nombre *</label>
          <input type="text" id="e_nombre" name="nombre" required maxlength="60">
        </div>
        <div class="campo">
          <label for="e_apellido">Apellido *</label>
          <input type="text" id="e_apellido" name="apellido" required maxlength="60">
        </div>
      </div>
      <div class="campo">
        <label for="e_email">Correo *</label>
        <input type="email" id="e_email" name="email" required maxlength="150">
      </div>
      <div class="campo">
        <label for="e_telefono">Teléfono</label>
        <input type="tel" id="e_telefono" name="telefono" maxlength="20">
      </div>
      <div class="campo">
        <label for="e_rol">Rol *</label>
        <select id="e_rol" name="rol_id" required>
          <?php foreach ($roles as $r): ?>
            <option value="<?= (int)$r['id'] ?>"><?= e((string)$r['nombre']) ?></option>
          <?php endforeach; ?>
        </select>
        <p class="ayuda">Cada rol define exactamente lo que esa persona puede hacer.
          Míralos en <a href="<?= e(url('admin/roles.php')) ?>">Roles y permisos</a>.</p>
      </div>
    </div>
    <div class="modal-pie">
      <button type="button" class="boton boton-claro" data-cerrar-modal>Cancelar</button>
      <button type="submit" class="boton boton-principal">Guardar</button>
    </div>
  </form>
</dialog>
<?php endif; ?>

<?php require __DIR__ . '/_pie.php'; ?>
