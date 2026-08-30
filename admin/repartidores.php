<?php
/**
 * Repartidores.
 *
 * El motorizado no tiene cuenta ni entra al panel: solo hace falta su nombre y
 * su teléfono para mandarle la entrega por WhatsApp desde la ficha del pedido.
 * Por eso viven en su propia tabla y no como usuarios del sistema.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

$seccion = 'repartidores';
Rbac::exigirPanel();
Rbac::exigir('repartidores.ver');

$editable = Rbac::puede('repartidores.gestionar');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirToken(false, 'admin/repartidores.php');
    Rbac::exigir('repartidores.gestionar');

    $accion = opcion('accion', ['guardar', 'eliminar', 'activar'], 'guardar');
    $id     = identificador('repartidor_id');

    if ($accion === 'eliminar') {
        // Los pedidos guardan el nombre y el teléfono en sus propias columnas,
        // así que borrar la ficha no borra el historial de entregas.
        $st = $pdo->prepare("SELECT nombre FROM repartidores WHERE id = ?");
        $st->execute([$id]);
        $nombre = (string)$st->fetchColumn();

        $pdo->prepare("DELETE FROM repartidores WHERE id = ?")->execute([$id]);
        Auditoria::registrar($pdo, 'eliminar', 'pedidos', [
            'recurso_tipo' => 'repartidor', 'recurso_id' => (string)$id,
            'descripcion'  => 'Repartidor eliminado: ' . $nombre,
        ]);
        flash('exito', 'Repartidor eliminado.');
        redirigir('admin/repartidores.php');
    }

    if ($accion === 'activar') {
        $st = $pdo->prepare("SELECT nombre, activo FROM repartidores WHERE id = ?");
        $st->execute([$id]);
        $r = $st->fetch();
        if ($r) {
            $nuevo = (int)$r['activo'] === 1 ? 0 : 1;
            $pdo->prepare("UPDATE repartidores SET activo = ? WHERE id = ?")->execute([$nuevo, $id]);
            Auditoria::registrar($pdo, 'editar', 'pedidos', [
                'recurso_tipo' => 'repartidor', 'recurso_id' => (string)$id,
                'descripcion'  => ($nuevo ? 'Repartidor activado: ' : 'Repartidor desactivado: ') . $r['nombre'],
            ]);
            flash('exito', $nuevo ? 'Repartidor disponible otra vez.' : 'Repartidor desactivado.');
        }
        redirigir('admin/repartidores.php');
    }

    // --- Alta o edición --------------------------------------------------
    $nombre   = texto('nombre', 120);
    $telefono = telefonoValido('telefono');

    if ($nombre === '') {
        flash('error', 'El repartidor necesita un nombre.');
        redirigir('admin/repartidores.php');
    }
    if ($telefono === '') {
        flash('error', 'Escribe un teléfono válido: es el número al que se le manda la entrega '
                     . 'por WhatsApp. Ocho dígitos o más, con el código de país (+505…).');
        redirigir('admin/repartidores.php');
    }

    $datos = [$nombre, $telefono, texto('vehiculo', 80), texto('notas', 255),
              entero('orden', 0, 999, 0), casilla('activo')];

    if ($id > 0) {
        $datos[] = $id;
        $pdo->prepare(
            "UPDATE repartidores SET nombre = ?, telefono = ?, vehiculo = ?, notas = ?,
                    orden = ?, activo = ? WHERE id = ?"
        )->execute($datos);
    } else {
        $pdo->prepare(
            "INSERT INTO repartidores (nombre, telefono, vehiculo, notas, orden, activo)
             VALUES (?,?,?,?,?,?)"
        )->execute($datos);
        $id = (int)$pdo->lastInsertId();
    }

    Auditoria::registrar($pdo, 'editar', 'pedidos', [
        'recurso_tipo' => 'repartidor', 'recurso_id' => (string)$id,
        'descripcion'  => 'Repartidor guardado: ' . $nombre,
    ]);
    flash('exito', 'Repartidor guardado.');
    redirigir('admin/repartidores.php');
}

$repartidores = Repartidores::todos($pdo);

// Cuántas entregas lleva cada uno: es lo que distingue una lista de nombres de
// una herramienta que sirve para repartir el trabajo.
$entregas = $pdo->query(
    "SELECT repartidor_id, COUNT(*) AS total, MAX(repartidor_enviado_en) AS ultima
       FROM pedidos WHERE repartidor_id IS NOT NULL GROUP BY repartidor_id"
)->fetchAll(PDO::FETCH_UNIQUE);

$tituloPanel    = 'Repartidores';
$subtituloPanel = 'A quién se le manda la entrega por WhatsApp';

require __DIR__ . '/_cabecera.php';
?>

<?php if (!$editable): ?>
  <div class="caja-aviso alerta">
    <i class="fa-solid fa-lock" aria-hidden="true"></i>
    <span>Puedes consultar los repartidores, pero tu rol no permite modificarlos.</span>
  </div>
<?php endif; ?>

<section class="panel">
  <div class="panel-cabecera">
    <div><h2>Repartidores</h2><p><?= count($repartidores) ?> registrados</p></div>
    <?php if ($editable): ?>
      <button type="button" class="boton boton-principal boton-mini" data-abrir-modal="modalRepartidor"
              data-campo-repartidor_id="0" data-campo-nombre="" data-campo-telefono=""
              data-campo-vehiculo="" data-campo-notas="" data-campo-orden="0" data-campo-activo="1">
        <i class="fa-solid fa-plus" aria-hidden="true"></i> Añadir repartidor</button>
    <?php endif; ?>
  </div>

  <?php if (!$repartidores): ?>
    <div class="vacio">
      <i class="fa-solid fa-motorcycle" aria-hidden="true"></i>
      <h3>Todavía no hay repartidores</h3>
      <p>Añade al menos uno con su número de WhatsApp. Después, en cada pedido
         podrás mandarle la dirección, la ubicación y el detalle con un toque.</p>
    </div>
  <?php else: ?>
    <div class="tabla-envoltura">
      <table class="tabla">
        <thead><tr>
          <th>Repartidor</th><th>WhatsApp</th><th>Vehículo</th>
          <th>Entregas</th><th>Estado</th><th></th>
        </tr></thead>
        <tbody>
          <?php foreach ($repartidores as $r):
              $stats = $entregas[(int)$r['id']] ?? null; ?>
            <tr>
              <td class="celda-principal">
                <?= e((string)$r['nombre']) ?>
                <?php if ((string)$r['notas'] !== ''): ?>
                  <br><span class="celda-sub"><?= e((string)$r['notas']) ?></span>
                <?php endif; ?>
              </td>
              <td>
                <a href="<?= e(enlace_whatsapp('Hola ' . $r['nombre'] . ', te escribo de '
                        . Ajustes::texto('nombre_tienda', 'Flowers Anto') . '.', (string)$r['telefono'])) ?>"
                   target="_blank" rel="noopener noreferrer">
                  <i class="fa-brands fa-whatsapp" aria-hidden="true"></i> <?= e((string)$r['telefono']) ?></a>
              </td>
              <td><?= e((string)$r['vehiculo']) ?: '—' ?></td>
              <td>
                <?php if ($stats): ?>
                  <?= (int)$stats['total'] ?>
                  <br><span class="celda-sub">última: <?= e(fecha_corta((string)$stats['ultima'])) ?></span>
                <?php else: ?>
                  <span class="celda-sub">ninguna</span>
                <?php endif; ?>
              </td>
              <td><span class="estado-suave <?= (int)$r['activo'] ? 'si' : 'no' ?>">
                <?= (int)$r['activo'] ? 'Disponible' : 'Inactivo' ?></span></td>
              <td class="acciones">
                <?php if ($editable): ?>
                  <div style="display:inline-flex; gap:5px;">
                    <button type="button" class="boton-icono" data-abrir-modal="modalRepartidor"
                            data-campo-repartidor_id="<?= (int)$r['id'] ?>"
                            data-campo-nombre="<?= e((string)$r['nombre']) ?>"
                            data-campo-telefono="<?= e((string)$r['telefono']) ?>"
                            data-campo-vehiculo="<?= e((string)$r['vehiculo']) ?>"
                            data-campo-notas="<?= e((string)$r['notas']) ?>"
                            data-campo-orden="<?= (int)$r['orden'] ?>"
                            data-campo-activo="<?= (int)$r['activo'] ?>"
                            aria-label="Editar repartidor"><i class="fa-solid fa-pen" aria-hidden="true"></i></button>

                    <form method="post" action="<?= e(url('admin/repartidores.php')) ?>">
                      <?= campoToken() ?>
                      <input type="hidden" name="accion" value="activar">
                      <input type="hidden" name="repartidor_id" value="<?= (int)$r['id'] ?>">
                      <button type="submit" class="boton-icono"
                              aria-label="<?= (int)$r['activo'] ? 'Desactivar' : 'Activar' ?>">
                        <i class="fa-solid <?= (int)$r['activo'] ? 'fa-eye-slash' : 'fa-eye' ?>" aria-hidden="true"></i>
                      </button>
                    </form>

                    <form method="post" action="<?= e(url('admin/repartidores.php')) ?>"
                          data-confirmar="¿Eliminar a <?= e((string)$r['nombre']) ?>? Los pedidos que ya llevó conservan su nombre.">
                      <?= campoToken() ?>
                      <input type="hidden" name="accion" value="eliminar">
                      <input type="hidden" name="repartidor_id" value="<?= (int)$r['id'] ?>">
                      <button type="submit" class="boton-icono peligro" aria-label="Eliminar repartidor">
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

<div class="caja-aviso">
  <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
  <span>El texto que se le manda al motorizado se edita en
    <a href="<?= e(url('admin/configuracion.php?t=envio')) ?>">Configuración → Envío y zonas</a>.</span>
</div>

<?php if ($editable): ?>
  <dialog class="modal" id="modalRepartidor">
    <form method="post" action="<?= e(url('admin/repartidores.php')) ?>">
      <?= campoToken() ?>
      <input type="hidden" name="accion" value="guardar">
      <input type="hidden" name="repartidor_id" value="0">
      <div class="modal-cabecera"><h2>Repartidor</h2></div>
      <div class="modal-cuerpo">
        <div class="campo">
          <label for="rp_nombre">Nombre *</label>
          <input type="text" id="rp_nombre" name="nombre" required maxlength="120" placeholder="Carlos Martínez">
        </div>
        <div class="rejilla-campos dos">
          <div class="campo">
            <label for="rp_telefono">WhatsApp *</label>
            <input type="tel" id="rp_telefono" name="telefono" required maxlength="20" placeholder="+50588887777">
            <p class="ayuda">Con el código de país. Es el número al que llega la entrega.</p>
          </div>
          <div class="campo">
            <label for="rp_vehiculo">Vehículo</label>
            <input type="text" id="rp_vehiculo" name="vehiculo" maxlength="80" placeholder="Moto roja, placa M-1234">
          </div>
        </div>
        <div class="campo">
          <label for="rp_notas">Notas</label>
          <input type="text" id="rp_notas" name="notas" maxlength="255"
                 placeholder="Trabaja de lunes a viernes, cubre carretera sur…">
        </div>
        <div class="campo">
          <label for="rp_orden">Orden en la lista</label>
          <input type="number" id="rp_orden" name="orden" min="0" max="999" value="0">
          <p class="ayuda">El más bajo sale primero al elegir a quién mandarle un pedido.</p>
        </div>
        <div class="interruptor">
          <input type="checkbox" id="rp_activo" name="activo" value="1" checked>
          <label for="rp_activo">Disponible para entregas
            <small>Si lo desactivas deja de aparecer al despachar, pero se conserva su historial.</small></label>
        </div>
      </div>
      <div class="modal-pie">
        <button type="button" class="boton boton-claro" data-cerrar-modal>Cancelar</button>
        <button type="submit" class="boton boton-principal">Guardar repartidor</button>
      </div>
    </form>
  </dialog>
<?php endif; ?>

<?php require __DIR__ . '/_pie.php'; ?>
