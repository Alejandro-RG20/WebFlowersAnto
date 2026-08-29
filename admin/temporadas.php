<?php
/**
 * Temporadas y campañas.
 *
 * Una temporada se publica sola cuando está activa y la fecha de hoy cae
 * dentro de su rango. Si hay varias vigentes, gana la de mayor prioridad.
 * Al terminar, la portada vuelve sola a su estado normal.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

$seccion = 'temporadas';
Rbac::exigirPanel();
Rbac::exigir('temporadas.gestionar');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirToken(false, 'admin/temporadas.php');
    $accion = opcion('accion', ['guardar', 'activar', 'eliminar'], 'guardar');
    $id     = identificador('id');

    if ($accion === 'eliminar' && $id > 0) {
        $st = $pdo->prepare("SELECT nombre FROM temporadas WHERE id = ?");
        $st->execute([$id]);
        $nombre = (string)$st->fetchColumn();
        $pdo->prepare("DELETE FROM temporadas WHERE id = ?")->execute([$id]);
        Auditoria::registrar($pdo, 'eliminar', 'productos', [
            'recurso_tipo' => 'temporada', 'recurso_id' => (string)$id,
            'descripcion'  => 'Temporada eliminada: ' . $nombre,
        ]);
        flash('exito', 'Temporada eliminada.');
        redirigir('admin/temporadas.php');
    }

    if ($accion === 'activar' && $id > 0) {
        $st = $pdo->prepare("SELECT nombre, activo FROM temporadas WHERE id = ?");
        $st->execute([$id]);
        $t = $st->fetch();
        if ($t) {
            $nuevo = (int)$t['activo'] === 1 ? 0 : 1;
            $pdo->prepare("UPDATE temporadas SET activo = ? WHERE id = ?")->execute([$nuevo, $id]);
            Auditoria::registrar($pdo, 'editar', 'productos', [
                'recurso_tipo' => 'temporada', 'recurso_id' => (string)$id,
                'descripcion'  => ($nuevo ? 'Temporada activada: ' : 'Temporada desactivada: ') . $t['nombre'],
            ]);
            flash('exito', $nuevo ? 'Temporada activada.' : 'Temporada desactivada.');
        }
        redirigir('admin/temporadas.php');
    }

    $datos = [
        'nombre'       => texto('nombre', 80),
        'titulo'       => texto('titulo', 150),
        'subtitulo'    => texto('subtitulo', 255),
        'descripcion'  => textoLargo('descripcion', 1000),
        'palabra_hero' => mb_strtoupper(texto('palabra_hero', 20)),
        'banner'       => rutaImagen('banner'),
        'color_acento' => colorHex('color_acento'),
        'fecha_inicio' => fechaOpcional('fecha_inicio'),
        'fecha_fin'    => fechaOpcional('fecha_fin'),
        'prioridad'    => entero('prioridad', 0, 999),
        'activo'       => casilla('activo'),
    ];

    if (mb_strlen($datos['nombre']) < 3 || mb_strlen($datos['titulo']) < 3) {
        flash('error', 'El nombre interno y el título necesitan al menos 3 caracteres.');
        redirigir('admin/temporadas.php');
    }
    if ($datos['fecha_inicio'] && $datos['fecha_fin'] && $datos['fecha_fin'] < $datos['fecha_inicio']) {
        flash('error', 'La fecha de fin no puede ser anterior a la de inicio.');
        redirigir('admin/temporadas.php');
    }

    $esNueva = $id === 0;
    $valores = array_values($datos);

    if ($esNueva) {
        $pdo->prepare(
            "INSERT INTO temporadas
                (nombre, titulo, subtitulo, descripcion, palabra_hero, banner, color_acento,
                 fecha_inicio, fecha_fin, prioridad, activo)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)"
        )->execute($valores);
        $id = (int)$pdo->lastInsertId();
    } else {
        $valores[] = $id;
        $pdo->prepare(
            "UPDATE temporadas SET nombre = ?, titulo = ?, subtitulo = ?, descripcion = ?,
                    palabra_hero = ?, banner = ?, color_acento = ?, fecha_inicio = ?,
                    fecha_fin = ?, prioridad = ?, activo = ?
              WHERE id = ?"
        )->execute($valores);
    }

    // Productos asociados a la campaña
    $pdo->prepare("DELETE FROM temporada_productos WHERE temporada_id = ?")->execute([$id]);
    $ins = $pdo->prepare("INSERT IGNORE INTO temporada_productos (temporada_id, producto_id, orden) VALUES (?,?,?)");
    foreach (array_slice((array)($_POST['productos'] ?? []), 0, 12) as $orden => $productoId) {
        $productoId = (int)$productoId;
        if ($productoId > 0) {
            $ins->execute([$id, $productoId, $orden]);
        }
    }

    Auditoria::registrar($pdo, $esNueva ? 'crear' : 'editar', 'productos', [
        'recurso_tipo' => 'temporada', 'recurso_id' => (string)$id,
        'descripcion'  => 'Temporada guardada: ' . $datos['nombre'],
    ]);
    flash('exito', 'Temporada guardada.');
    redirigir('admin/temporadas.php');
}

$temporadas = $pdo->query("SELECT * FROM temporadas ORDER BY prioridad DESC, id DESC")->fetchAll();
$productos  = $pdo->query("SELECT id, nombre FROM productos WHERE activo = 1 ORDER BY nombre")->fetchAll();

$asociados = [];
foreach ($pdo->query("SELECT temporada_id, producto_id FROM temporada_productos ORDER BY orden")->fetchAll() as $fila) {
    $asociados[(int)$fila['temporada_id']][] = (int)$fila['producto_id'];
}

$vigente = Catalogo::temporadaActiva($pdo);

$tituloPanel      = 'Temporadas';
$subtituloPanel   = 'Campañas con fecha, como San Valentín o el Día de las Madres';
$accionesCabecera = '<button type="button" class="boton boton-principal" data-abrir-modal="modalTemporada">'
                  . '<i class="fa-solid fa-plus" aria-hidden="true"></i> Nueva temporada</button>';

require __DIR__ . '/_cabecera.php';
?>

<?php if ($vigente): ?>
  <div class="caja-aviso exito">
    <i class="fa-solid fa-bullhorn" aria-hidden="true"></i>
    <span>Ahora mismo está publicada <strong><?= e((string)$vigente['titulo']) ?></strong>.
      La portada muestra su palabra de fondo y sus productos.</span>
  </div>
<?php else: ?>
  <div class="caja-aviso info">
    <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
    <span>No hay ninguna campaña vigente. La portada usa la configuración normal.</span>
  </div>
<?php endif; ?>

<section class="panel">
  <?php if (!$temporadas): ?>
    <div class="vacio">
      <i class="fa-solid fa-calendar-day" aria-hidden="true"></i>
      <h3>Todavía no hay temporadas</h3>
      <p>Crea una campaña con sus fechas y se publicará y retirará sola.</p>
    </div>
  <?php else: ?>
    <div class="tabla-envoltura">
      <table class="tabla">
        <thead><tr><th>Campaña</th><th>Vigencia</th><th class="num">Prioridad</th>
                   <th class="num">Productos</th><th>Estado</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($temporadas as $t):
              $esVigente = $vigente && (int)$vigente['id'] === (int)$t['id'];
              $productosDe = $asociados[(int)$t['id']] ?? [];
          ?>
            <tr>
              <td>
                <span class="celda-principal"><?= e((string)$t['titulo']) ?></span>
                <?php if ($esVigente): ?>
                  <span class="estado" style="background:#2F6B44;">En el aire</span>
                <?php endif; ?>
                <br><span class="celda-sub"><?= e((string)$t['nombre']) ?>
                  <?= $t['palabra_hero'] ? ' · palabra: ' . e((string)$t['palabra_hero']) : '' ?></span>
              </td>
              <td class="celda-sub">
                <?= $t['fecha_inicio'] ? e(fecha_corta((string)$t['fecha_inicio'])) : 'sin inicio' ?>
                →
                <?= $t['fecha_fin'] ? e(fecha_corta((string)$t['fecha_fin'])) : 'sin fin' ?>
              </td>
              <td class="num"><?= (int)$t['prioridad'] ?></td>
              <td class="num"><?= count($productosDe) ?></td>
              <td><span class="estado-suave <?= (int)$t['activo'] ? 'si' : 'no' ?>">
                <?= (int)$t['activo'] ? 'Activa' : 'Inactiva' ?></span></td>
              <td class="acciones">
                <div style="display:inline-flex; gap:5px;">
                  <button type="button" class="boton-icono" data-abrir-modal="modalTemporada"
                          data-campo-id="<?= (int)$t['id'] ?>"
                          data-campo-nombre="<?= e((string)$t['nombre']) ?>"
                          data-campo-titulo="<?= e((string)$t['titulo']) ?>"
                          data-campo-subtitulo="<?= e((string)$t['subtitulo']) ?>"
                          data-campo-descripcion="<?= e((string)$t['descripcion']) ?>"
                          data-campo-palabra_hero="<?= e((string)$t['palabra_hero']) ?>"
                          data-campo-color_acento="<?= e((string)$t['color_acento']) ?>"
                          data-campo-fecha_inicio="<?= e((string)$t['fecha_inicio']) ?>"
                          data-campo-fecha_fin="<?= e((string)$t['fecha_fin']) ?>"
                          data-campo-prioridad="<?= (int)$t['prioridad'] ?>"
                          data-campo-activo="<?= (int)$t['activo'] ?>"
                          aria-label="Editar <?= e((string)$t['titulo']) ?>">
                    <i class="fa-solid fa-pen" aria-hidden="true"></i></button>

                  <form method="post" action="<?= e(url('admin/temporadas.php')) ?>">
                    <?= campoToken() ?>
                    <input type="hidden" name="accion" value="activar">
                    <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                    <button type="submit" class="boton-icono"
                            aria-label="<?= (int)$t['activo'] ? 'Desactivar' : 'Activar' ?>">
                      <i class="fa-solid fa-<?= (int)$t['activo'] ? 'toggle-on' : 'toggle-off' ?>" aria-hidden="true"></i></button>
                  </form>

                  <form method="post" action="<?= e(url('admin/temporadas.php')) ?>"
                        data-confirmar="¿Eliminar la temporada «<?= e((string)$t['titulo']) ?>»?">
                    <?= campoToken() ?>
                    <input type="hidden" name="accion" value="eliminar">
                    <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                    <button type="submit" class="boton-icono peligro" aria-label="Eliminar">
                      <i class="fa-solid fa-trash-can" aria-hidden="true"></i></button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<dialog class="modal" id="modalTemporada" style="width:min(94vw,620px);">
  <form method="post" action="<?= e(url('admin/temporadas.php')) ?>">
    <?= campoToken() ?>
    <input type="hidden" name="accion" value="guardar">
    <input type="hidden" name="id" value="0">
    <div class="modal-cabecera">
      <h2>Temporada</h2>
      <p class="sub">Se publica sola dentro de su rango de fechas si está activa.</p>
    </div>
    <div class="modal-cuerpo" style="max-height:65vh; overflow-y:auto;">
      <div class="rejilla-campos dos">
        <div class="campo">
          <label for="t_nombre">Nombre interno *</label>
          <input type="text" id="t_nombre" name="nombre" required maxlength="80" placeholder="San Valentín 2027">
        </div>
        <div class="campo">
          <label for="t_titulo">Título visible *</label>
          <input type="text" id="t_titulo" name="titulo" required maxlength="150" placeholder="Flores para San Valentín">
        </div>
      </div>
      <div class="campo">
        <label for="t_subtitulo">Subtítulo</label>
        <input type="text" id="t_subtitulo" name="subtitulo" maxlength="255" placeholder="Del 10 al 14 de febrero">
      </div>
      <div class="campo">
        <label for="t_descripcion">Descripción</label>
        <textarea id="t_descripcion" name="descripcion" maxlength="1000"></textarea>
      </div>
      <div class="rejilla-campos dos">
        <div class="campo">
          <label for="t_palabra_hero">Palabra de fondo en la portada</label>
          <input type="text" id="t_palabra_hero" name="palabra_hero" maxlength="20" placeholder="AMOR">
          <p class="ayuda">Sustituye a la palabra general mientras la campaña esté vigente.</p>
        </div>
        <div class="campo">
          <label for="t_color_acento">Color de la campaña</label>
          <input type="color" id="t_color_acento" name="color_acento" value="#EFD9DE">
        </div>
      </div>
      <div class="rejilla-campos tres">
        <div class="campo">
          <label for="t_fecha_inicio">Desde</label>
          <input type="date" id="t_fecha_inicio" name="fecha_inicio">
        </div>
        <div class="campo">
          <label for="t_fecha_fin">Hasta</label>
          <input type="date" id="t_fecha_fin" name="fecha_fin">
        </div>
        <div class="campo">
          <label for="t_prioridad">Prioridad</label>
          <input type="number" id="t_prioridad" name="prioridad" min="0" max="999" value="0">
        </div>
      </div>
      <div class="campo">
        <label for="t_productos">Productos de la campaña</label>
        <select id="t_productos" name="productos[]" multiple size="7">
          <?php foreach ($productos as $p): ?>
            <option value="<?= (int)$p['id'] ?>"><?= e((string)$p['nombre']) ?></option>
          <?php endforeach; ?>
        </select>
        <p class="ayuda">Ctrl/Cmd para elegir varios. Se muestran en la portada y en la sección de temporada.</p>
      </div>
      <div class="interruptor">
        <input type="checkbox" id="t_activo" name="activo" value="1" checked>
        <label for="t_activo">Campaña activa
          <small>Aun activa, solo se publica dentro de su rango de fechas.</small></label>
      </div>
    </div>
    <div class="modal-pie">
      <button type="button" class="boton boton-claro" data-cerrar-modal>Cancelar</button>
      <button type="submit" class="boton boton-principal">Guardar temporada</button>
    </div>
  </form>
</dialog>

<?php require __DIR__ . '/_pie.php'; ?>
