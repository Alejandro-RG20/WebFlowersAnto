<?php
/**
 * Roles y permisos.
 *
 * Los permisos los define el código (los comprueba `Rbac::exigir`), así que
 * aquí no se crean ni se borran: se marcan y desmarcan por rol. El rol de
 * super administrador no se toca — si se le pudieran quitar permisos, una
 * mala tarde dejaría el panel sin nadie que pueda arreglarlo.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

$seccion = 'roles';
Rbac::exigirPanel();
Rbac::exigir('roles.gestionar');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirToken(false, 'admin/roles.php');

    $accion = opcion('accion', ['permisos', 'crear', 'eliminar'], 'permisos');
    $rolId  = identificador('rol_id');

    $st = $pdo->prepare("SELECT * FROM roles WHERE id = ?");
    $st->execute([$rolId]);
    $rol = $st->fetch() ?: null;

    if ($accion === 'crear') {
        $nombre      = texto('nombre', 80);
        $descripcion = texto('descripcion', 255);
        if (mb_strlen($nombre) < 3) {
            flash('error', 'El nombre del rol necesita al menos 3 caracteres.');
            redirigir('admin/roles.php');
        }
        $codigo = mb_substr(str_replace('-', '_', slugificar($nombre)), 0, 40);
        $existe = $pdo->prepare("SELECT 1 FROM roles WHERE codigo = ?");
        $existe->execute([$codigo]);
        if ($existe->fetchColumn()) {
            flash('error', 'Ya existe un rol con ese nombre.');
            redirigir('admin/roles.php');
        }
        $pdo->prepare("INSERT INTO roles (codigo, nombre, descripcion, es_personal, es_sistema) VALUES (?,?,?,1,0)")
            ->execute([$codigo, $nombre, $descripcion]);
        $nuevoId = (int)$pdo->lastInsertId();

        // Un rol nuevo nace pudiendo entrar al panel y nada más.
        $pdo->prepare(
            "INSERT INTO rol_permisos (rol_id, permiso_id)
             SELECT ?, id FROM permisos WHERE codigo IN ('panel.acceder','dashboard.ver')"
        )->execute([$nuevoId]);

        Auditoria::registrar($pdo, 'crear', 'usuarios', [
            'recurso_tipo' => 'rol', 'recurso_id' => (string)$nuevoId,
            'descripcion'  => 'Rol creado: ' . $nombre,
        ]);
        flash('exito', 'Rol creado. Marca ahora lo que puede hacer.');
        redirigir('admin/roles.php');
    }

    if (!$rol) {
        flash('error', 'Ese rol no existe.');
        redirigir('admin/roles.php');
    }

    if ($accion === 'eliminar') {
        if ((int)$rol['es_sistema'] === 1) {
            flash('error', 'Los roles del sistema no se pueden eliminar.');
            redirigir('admin/roles.php');
        }
        $enUso = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE rol_id = ?");
        $enUso->execute([$rolId]);
        if ((int)$enUso->fetchColumn() > 0) {
            flash('error', 'Hay cuentas con ese rol. Cámbiales el rol antes de eliminarlo.');
            redirigir('admin/roles.php');
        }
        $pdo->prepare("DELETE FROM roles WHERE id = ?")->execute([$rolId]);
        Auditoria::registrar($pdo, 'eliminar', 'usuarios', [
            'recurso_tipo' => 'rol', 'recurso_id' => (string)$rolId,
            'descripcion'  => 'Rol eliminado: ' . $rol['nombre'],
        ]);
        flash('exito', 'Rol eliminado.');
        redirigir('admin/roles.php');
    }

    // --- Guardar permisos --------------------------------------------
    if ($rol['codigo'] === 'super_admin') {
        flash('error', 'El super administrador conserva siempre todos los permisos.');
        redirigir('admin/roles.php');
    }
    if ($rol['codigo'] === 'cliente') {
        flash('error', 'El rol de cliente no tiene permisos de panel.');
        redirigir('admin/roles.php');
    }

    $marcados = array_map('intval', (array)($_POST['permisos'] ?? []));
    $validos  = $pdo->query("SELECT id FROM permisos")->fetchAll(PDO::FETCH_COLUMN);
    $marcados = array_values(array_intersect($marcados, array_map('intval', $validos)));

    $antes = $pdo->prepare("SELECT COUNT(*) FROM rol_permisos WHERE rol_id = ?");
    $antes->execute([$rolId]);
    $cuantosAntes = (int)$antes->fetchColumn();

    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM rol_permisos WHERE rol_id = ?")->execute([$rolId]);
        $ins = $pdo->prepare("INSERT INTO rol_permisos (rol_id, permiso_id) VALUES (?, ?)");
        foreach ($marcados as $permisoId) {
            $ins->execute([$rolId, $permisoId]);
        }
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        error_log('Flowers Anto — guardar permisos: ' . $ex->getMessage());
        flash('error', 'No se pudieron guardar los permisos.');
        redirigir('admin/roles.php');
    }

    Rbac::refrescar();
    Auditoria::registrar($pdo, 'editar_permisos', 'usuarios', [
        'recurso_tipo' => 'rol', 'recurso_id' => (string)$rolId,
        'descripcion'  => 'Permisos del rol «' . $rol['nombre'] . '»: '
                        . $cuantosAntes . ' → ' . count($marcados),
    ]);
    flash('exito', 'Permisos actualizados para «' . $rol['nombre'] . '».');
    redirigir('admin/roles.php?rol=' . $rolId);
}

$roles = $pdo->query(
    "SELECT r.*, COUNT(u.id) AS cuentas
       FROM roles r LEFT JOIN usuarios u ON u.rol_id = r.id
   GROUP BY r.id ORDER BY r.es_sistema DESC, r.id"
)->fetchAll();

$permisos = $pdo->query("SELECT * FROM permisos ORDER BY modulo, codigo")->fetchAll();
$porModulo = [];
foreach ($permisos as $p) {
    $porModulo[$p['modulo']][] = $p;
}

$rolActivoId = identificador('rol', $_GET);
$rolActivo   = null;
foreach ($roles as $r) {
    if ((int)$r['id'] === $rolActivoId) {
        $rolActivo = $r;
    }
}
$rolActivo ??= $roles[0] ?? null;

$asignados = [];
if ($rolActivo) {
    $st = $pdo->prepare("SELECT permiso_id FROM rol_permisos WHERE rol_id = ?");
    $st->execute([$rolActivo['id']]);
    $asignados = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

$nombresModulo = [
    'panel'     => 'Acceso al panel',
    'pedidos'   => 'Pedidos y pagos',
    'productos' => 'Catálogo',
    'usuarios'  => 'Personas',
    'sistema'   => 'Sistema',
];

$tituloPanel      = 'Roles y permisos';
$subtituloPanel   = 'Qué puede hacer cada tipo de cuenta';
$accionesCabecera = '<button type="button" class="boton boton-principal" data-abrir-modal="modalRol">'
                  . '<i class="fa-solid fa-plus" aria-hidden="true"></i> Nuevo rol</button>';

require __DIR__ . '/_cabecera.php';
?>

<div class="caja-aviso info">
  <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
  <span>Los permisos se comprueban en el servidor antes de ejecutar cada acción. Ocultar
    un botón no es la protección: aunque alguien envíe el formulario a mano, sin el
    permiso la operación se rechaza y queda anotada en la auditoría.</span>
</div>

<div class="pestanas">
  <?php foreach ($roles as $r): ?>
    <a href="<?= e(url('admin/roles.php?rol=' . (int)$r['id'])) ?>"
       class="<?= $rolActivo && (int)$rolActivo['id'] === (int)$r['id'] ? 'activa' : '' ?>">
      <?= e((string)$r['nombre']) ?> <span class="cuenta"><?= (int)$r['cuentas'] ?></span></a>
  <?php endforeach; ?>
</div>

<?php if ($rolActivo): ?>
  <section class="panel">
    <div class="panel-cabecera">
      <div>
        <h2><?= e((string)$rolActivo['nombre']) ?></h2>
        <p><?= e((string)$rolActivo['descripcion']) ?></p>
      </div>
      <div style="display:flex; gap:8px; align-items:center;">
        <span class="estado-suave"><?= (int)$rolActivo['cuentas'] ?>
          <?= (int)$rolActivo['cuentas'] === 1 ? 'cuenta' : 'cuentas' ?></span>
        <?php if ((int)$rolActivo['es_sistema'] === 0): ?>
          <form method="post" action="<?= e(url('admin/roles.php')) ?>"
                data-confirmar="¿Eliminar el rol «<?= e((string)$rolActivo['nombre']) ?>»?">
            <?= campoToken() ?>
            <input type="hidden" name="accion" value="eliminar">
            <input type="hidden" name="rol_id" value="<?= (int)$rolActivo['id'] ?>">
            <button type="submit" class="boton boton-claro boton-mini">Eliminar rol</button>
          </form>
        <?php endif; ?>
      </div>
    </div>

    <div class="panel-cuerpo">
      <?php if ($rolActivo['codigo'] === 'super_admin'): ?>
        <div class="caja-aviso alerta">
          <i class="fa-solid fa-lock" aria-hidden="true"></i>
          <span>El super administrador tiene siempre todos los permisos y no se puede
            recortar. Es la garantía de que nunca queda el panel sin nadie que pueda entrar.</span>
        </div>
      <?php elseif ($rolActivo['codigo'] === 'cliente'): ?>
        <div class="caja-aviso info">
          <i class="fa-solid fa-user" aria-hidden="true"></i>
          <span>Es el rol de las cuentas de la tienda. No tiene ni necesita permisos de panel:
            sus páginas (pedidos, favoritos, perfil) comprueban que la cuenta es la dueña de los datos.</span>
        </div>
      <?php endif; ?>

      <form method="post" action="<?= e(url('admin/roles.php')) ?>">
        <?= campoToken() ?>
        <input type="hidden" name="accion" value="permisos">
        <input type="hidden" name="rol_id" value="<?= (int)$rolActivo['id'] ?>">

        <?php
          $bloqueado = in_array((string)$rolActivo['codigo'], ['super_admin', 'cliente'], true);
          foreach ($porModulo as $modulo => $lista):
        ?>
          <fieldset style="border:0; margin-bottom:22px;">
            <legend class="etiqueta" style="margin-bottom:10px;">
              <?= e($nombresModulo[$modulo] ?? ucfirst((string)$modulo)) ?></legend>
            <div class="rejilla-campos dos">
              <?php foreach ($lista as $p): ?>
                <div class="interruptor">
                  <input type="checkbox" id="perm<?= (int)$p['id'] ?>" name="permisos[]"
                         value="<?= (int)$p['id'] ?>"
                         <?= $rolActivo['codigo'] === 'super_admin' || in_array((int)$p['id'], $asignados, true) ? 'checked' : '' ?>
                         <?= $bloqueado ? 'disabled' : '' ?>>
                  <label for="perm<?= (int)$p['id'] ?>"><?= e((string)$p['nombre'])
                    ?><small><code><?= e((string)$p['codigo']) ?></code></small></label>
                </div>
              <?php endforeach; ?>
            </div>
          </fieldset>
        <?php endforeach; ?>

        <?php if (!$bloqueado): ?>
          <button type="submit" class="boton boton-principal">
            <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> Guardar permisos</button>
        <?php endif; ?>
      </form>
    </div>
  </section>
<?php endif; ?>

<dialog class="modal" id="modalRol">
  <form method="post" action="<?= e(url('admin/roles.php')) ?>">
    <?= campoToken() ?>
    <input type="hidden" name="accion" value="crear">
    <div class="modal-cabecera">
      <h2>Nuevo rol</h2>
      <p class="sub">Empieza pudiendo entrar al panel y ver el resumen. Después marcas el resto.</p>
    </div>
    <div class="modal-cuerpo">
      <div class="campo">
        <label for="r_nombre">Nombre del rol *</label>
        <input type="text" id="r_nombre" name="nombre" required maxlength="80" placeholder="Encargada de taller">
      </div>
      <div class="campo">
        <label for="r_descripcion">Descripción</label>
        <input type="text" id="r_descripcion" name="descripcion" maxlength="255"
               placeholder="Gestiona el estado de los pedidos en preparación">
      </div>
    </div>
    <div class="modal-pie">
      <button type="button" class="boton boton-claro" data-cerrar-modal>Cancelar</button>
      <button type="submit" class="boton boton-principal">Crear rol</button>
    </div>
  </form>
</dialog>

<?php require __DIR__ . '/_pie.php'; ?>
