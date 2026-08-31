<?php
/**
 * Respaldos de la base de datos.
 *
 * Crear, descargar, subir y restaurar. Subir y restaurar son acciones
 * separadas a propósito: subir un archivo nunca lo aplica. Restaurar exige
 * el permiso `respaldos.restaurar` y escribir la palabra RESTAURAR.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/lib/respaldos.php';
require_once __DIR__ . '/../includes/lib/mantenimiento.php';

$seccion = 'respaldos';
Rbac::exigirPanel();
Rbac::exigir('respaldos.ver');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirToken(false, 'admin/respaldos.php');
    $accion = opcion('accion', ['crear', 'subir', 'restaurar', 'eliminar', 'limpiar'], '');
    $id     = identificador('id');

    $respaldo = null;
    if ($id > 0) {
        $st = $pdo->prepare("SELECT * FROM respaldos WHERE id = ?");
        $st->execute([$id]);
        $respaldo = $st->fetch() ?: null;
    }

    switch ($accion) {
        case 'crear':
            Rbac::exigir('respaldos.crear');
            $r = Respaldos::crear($pdo, 'manual', texto('notas', 400));
            flash($r['ok'] ? 'exito' : 'error',
                  $r['ok'] ? 'Respaldo creado correctamente.' : $r['error']);
            break;

        case 'subir':
            Rbac::exigir('respaldos.crear');
            $r = Respaldos::guardarSubido($pdo, $_FILES['respaldo'] ?? [], texto('notas', 400));
            flash($r['ok'] ? 'exito' : 'error',
                  $r['ok'] ? 'Archivo guardado y validado (' . $r['tablas'] . ' tablas). '
                           . 'No se ha restaurado: usa el botón «Restaurar» cuando quieras aplicarlo.'
                           : $r['error']);
            break;

        case 'restaurar':
            Rbac::exigir('respaldos.restaurar');
            if (!$respaldo) {
                flash('error', 'Ese respaldo ya no existe.');
                break;
            }
            if (mb_strtoupper(trim(texto('confirmacion', 20))) !== 'RESTAURAR') {
                flash('error', 'Para restaurar hay que escribir la palabra RESTAURAR.');
                break;
            }
            $r = Respaldos::restaurar($pdo, $respaldo);
            flash($r['ok'] ? 'exito' : 'error',
                  $r['ok']
                    ? 'Base restaurada (' . $r['tablas'] . ' tablas). Guardamos una copia del estado anterior: '
                      . $r['previo']
                    : $r['error']);
            break;

        case 'eliminar':
            Rbac::exigir('respaldos.crear');
            if ($respaldo) {
                Respaldos::eliminar($pdo, $respaldo);
                flash('exito', 'Respaldo eliminado.');
            }
            break;

        case 'limpiar':
            Rbac::exigir('sistema.mantenimiento');
            $tareas = array_map('strval', (array)($_POST['tareas'] ?? []));
            if (!$tareas) {
                flash('alerta', 'No marcaste nada que limpiar.');
                break;
            }
            $hecho  = Mantenimiento::limpiar($pdo, $tareas);
            Mantenimiento::olvidarAnalisis();
            $piezas = 0;
            $bytes  = 0;
            foreach ($hecho as $t) {
                $piezas += $t['cantidad'];
                $bytes  += $t['bytes'];
            }
            Auditoria::registrar($pdo, 'limpiar', 'sistema', [
                'recurso_tipo' => 'mantenimiento',
                'descripcion'  => 'Limpieza de mantenimiento: ' . $piezas . ' elementos ('
                                . implode(', ', $tareas) . ')',
            ]);
            flash($piezas ? 'exito' : 'info', $piezas
                ? 'Limpieza terminada: ' . $piezas . ' elementos y ' . tamano_legible($bytes) . ' liberados.'
                : 'No había nada que limpiar. Todo estaba en orden.');
            break;

        default:
            flash('error', 'Acción no reconocida.');
    }
    redirigir('admin/respaldos.php');
}

$respaldos = $pdo->query(
    "SELECT r.*, u.nombre AS autor_nombre, u.apellido AS autor_apellido
       FROM respaldos r LEFT JOIN usuarios u ON u.id = r.creado_por
   ORDER BY r.created_at DESC LIMIT 60"
)->fetchAll();

$espacio = 0;
foreach ($respaldos as $r) {
    $espacio += (int)$r['tamano'];
}

$puedeRestaurar = Rbac::puede('respaldos.restaurar');
$puedeCrear     = Rbac::puede('respaldos.crear');
$puedeLimpiar   = Rbac::puede('sistema.mantenimiento');

// Recuento en seco: la pantalla enseña lo que se borraría antes de borrarlo.
$analisis = $puedeLimpiar
    ? Mantenimiento::analizarCacheado($pdo, crudo('recalcular') !== '')
    : [];
$tareasInfo = Mantenimiento::tareas();
$totalSobrante = 0;
$bytesSobrante = 0;
foreach ($analisis as $t) {
    $totalSobrante += $t['cantidad'];
    $bytesSobrante += $t['bytes'];
}

$tituloPanel    = 'Respaldos';
$subtituloPanel = count($respaldos) . ' copias guardadas · ' . tamano_legible($espacio) . ' en disco';

require __DIR__ . '/_cabecera.php';
?>

<div class="caja-aviso info">
  <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
  <span><strong>Subir un respaldo no lo aplica.</strong> Son dos acciones separadas: primero se
    guarda y se valida el archivo, y solo cuando pulsas «Restaurar» se reemplazan los datos.
    Antes de cada restauración se crea automáticamente una copia del estado actual.</span>
</div>

<div class="rejilla-detalle">
  <div>
    <section class="panel">
      <div class="panel-cabecera">
        <div><h2>Copias guardadas</h2></div>
      </div>

      <?php if (!$respaldos): ?>
        <div class="vacio">
          <i class="fa-solid fa-database" aria-hidden="true"></i>
          <h3>Todavía no hay respaldos</h3>
          <p>Conviene crear uno antes de cualquier cambio grande: productos, precios o configuración.</p>
        </div>
      <?php else: ?>
        <div class="tabla-envoltura">
          <table class="tabla">
            <thead><tr><th>Respaldo</th><th>Tipo</th><th class="num">Tamaño</th>
                       <th>Creado por</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($respaldos as $r):
                  $existe = is_file(DIR_RESPALDOS . '/' . basename((string)$r['archivo']));
              ?>
                <tr>
                  <td>
                    <span class="celda-principal"><?= e((string)$r['nombre']) ?></span><br>
                    <span class="celda-sub"><?= e(fecha_larga((string)$r['created_at'])) ?>
                      <?= (int)$r['tablas'] > 0 ? ' · ' . (int)$r['tablas'] . ' tablas' : '' ?></span>
                    <?php if ($r['notas'] !== ''): ?>
                      <br><span class="celda-sub"><?= e((string)$r['notas']) ?></span>
                    <?php endif; ?>
                    <?php if (!$existe): ?>
                      <br><span class="estado-suave mal">Archivo no encontrado en disco</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <span class="estado-suave <?= $r['tipo'] === 'pre_restauracion' ? 'aviso' : '' ?>">
                      <?= e(match ((string)$r['tipo']) {
                          'manual'           => 'Manual',
                          'subido'           => 'Subido',
                          'pre_restauracion' => 'Previo a restaurar',
                          default            => (string)$r['tipo'],
                      }) ?></span>
                    <?php if ($r['estado'] === 'restaurado'): ?>
                      <br><span class="estado-suave si" style="margin-top:4px;">Ya restaurado</span>
                    <?php endif; ?>
                  </td>
                  <td class="num"><?= e(tamano_legible((int)$r['tamano'])) ?></td>
                  <td class="celda-sub"><?= e((string)$r['creado_texto']) ?></td>
                  <td class="acciones">
                    <div style="display:inline-flex; gap:5px;">
                      <?php if ($puedeCrear && $existe): ?>
                        <a class="boton-icono" href="<?= e(url('admin/descargar-respaldo.php?id=' . (int)$r['id'])) ?>"
                           aria-label="Descargar respaldo"><i class="fa-solid fa-download" aria-hidden="true"></i></a>
                      <?php endif; ?>

                      <?php if ($puedeRestaurar && $existe): ?>
                        <button type="button" class="boton boton-peligro boton-mini"
                                data-abrir-modal="modalRestaurar"
                                data-campo-id="<?= (int)$r['id'] ?>"
                                data-campo-nombre_respaldo="<?= e((string)$r['nombre']) ?>">
                          Restaurar</button>
                      <?php endif; ?>

                      <?php if ($puedeCrear): ?>
                        <form method="post" action="<?= e(url('admin/respaldos.php')) ?>"
                              data-confirmar="¿Eliminar «<?= e((string)$r['nombre']) ?>»? El archivo se borra del servidor.">
                          <?= campoToken() ?>
                          <input type="hidden" name="accion" value="eliminar">
                          <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                          <button type="submit" class="boton-icono peligro" aria-label="Eliminar respaldo">
                            <i class="fa-solid fa-trash-can" aria-hidden="true"></i></button>
                        </form>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>
  </div>

  <div>
    <?php if ($puedeCrear): ?>
      <section class="panel">
        <div class="panel-cabecera"><div>
          <h2>Crear respaldo</h2>
          <p>Vuelca toda la base a un archivo .sql.</p>
        </div></div>
        <div class="panel-cuerpo">
          <form method="post" action="<?= e(url('admin/respaldos.php')) ?>" data-una-vez>
            <?= campoToken() ?>
            <input type="hidden" name="accion" value="crear">
            <div class="campo">
              <label for="notas">Nota (opcional)</label>
              <input type="text" id="notas" name="notas" maxlength="400"
                     placeholder="Antes de subir los precios de diciembre">
            </div>
            <button type="submit" class="boton boton-principal" style="width:100%;">
              <i class="fa-solid fa-database" aria-hidden="true"></i> Crear respaldo ahora</button>
          </form>
          <p class="ayuda" style="margin-top:10px;">
            En bases grandes puede tardar unos segundos. No cierres la pestaña.
          </p>
        </div>
      </section>

      <section class="panel">
        <div class="panel-cabecera"><div>
          <h2>Subir un respaldo</h2>
          <p>Se guarda y se valida. No se aplica.</p>
        </div></div>
        <div class="panel-cuerpo">
          <form method="post" action="<?= e(url('admin/respaldos.php')) ?>" enctype="multipart/form-data" data-una-vez>
            <?= campoToken() ?>
            <input type="hidden" name="accion" value="subir">
            <input type="hidden" name="MAX_FILE_SIZE" value="<?= MAX_RESPALDO_BYTES ?>">
            <div class="campo">
              <label for="respaldo">Archivo .sql</label>
              <input type="file" id="respaldo" name="respaldo" accept=".sql,text/plain" required>
              <p class="ayuda">Máximo <?= (int)(MAX_RESPALDO_BYTES / 1048576) ?> MB.
                 Se revisa que sea un volcado válido y que no traiga sentencias fuera de lo normal.</p>
            </div>
            <div class="campo">
              <label for="notas_subida">Nota</label>
              <input type="text" id="notas_subida" name="notas" maxlength="400" placeholder="Copia del servidor anterior">
            </div>
            <button type="submit" class="boton boton-claro" style="width:100%;">
              <i class="fa-solid fa-upload" aria-hidden="true"></i> Subir y validar</button>
          </form>
        </div>
      </section>
    <?php endif; ?>

    <?php if ($puedeLimpiar): ?>
      <section class="panel" id="mantenimiento">
        <div class="panel-cabecera"><div>
          <h2>Mantenimiento</h2>
          <p>Libera espacio borrando lo que ya no usa nadie.
             <?= $totalSobrante
                 ? 'Ahora mismo sobran ' . (int)$totalSobrante . ' elementos ('
                   . e(tamano_legible($bytesSobrante)) . ').'
                 : 'Ahora mismo no sobra nada.' ?>
             <a href="<?= e(url('admin/respaldos.php?recalcular=1')) ?>#mantenimiento"
                style="white-space:nowrap;">Actualizar cifras</a></p>
        </div></div>
        <div class="panel-cuerpo">
          <div class="caja-aviso info" style="margin-bottom:14px;">
            <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
            <span>Las cifras de abajo son reales y no borran nada al calcularse. Se
              recuerdan unos minutos para que la página abra rápido; si acabas de cambiar
              algo, pulsa «Actualizar cifras». Solo se elimina lo que marques y pulses.</span>
          </div>

          <form method="post" action="<?= e(url('admin/respaldos.php')) ?>"
                data-confirmar="¿Eliminar de forma definitiva lo que has marcado?">
            <?= campoToken() ?>
            <input type="hidden" name="accion" value="limpiar">

            <p class="etiqueta" style="margin-bottom:8px;">Sobrantes técnicos</p>
            <?php foreach (Mantenimiento::SEGURAS as $clave):
                    $t = $tareasInfo[$clave]; $n = $analisis[$clave]['cantidad'] ?? 0; ?>
              <label class="fila-mantenimiento<?= $n ? '' : ' vacia' ?>">
                <input type="checkbox" name="tareas[]" value="<?= e($clave) ?>"
                       <?= $n ? 'checked' : 'disabled' ?>>
                <span class="fm-texto">
                  <span class="fm-titulo"><?= e($t['titulo']) ?></span>
                  <span class="fm-ayuda"><?= e($t['ayuda']) ?></span>
                </span>
                <span class="fm-cifra"><?= (int)$n ?> <?= e($t['unidad']) ?>
                  <?php if (!empty($analisis[$clave]['bytes'])): ?>
                    <small><?= e(tamano_legible((int)$analisis[$clave]['bytes'])) ?></small>
                  <?php endif; ?>
                </span>
              </label>
            <?php endforeach; ?>

            <p class="etiqueta" style="margin:18px 0 8px;">Información del negocio
              <small style="text-transform:none; letter-spacing:0; font-weight:400;">
                — esto no es caché. Va desmarcado a propósito.</small></p>
            <?php foreach (Mantenimiento::SENSIBLES as $clave):
                    $t = $tareasInfo[$clave]; $n = $analisis[$clave]['cantidad'] ?? 0; ?>
              <label class="fila-mantenimiento sensible<?= $n ? '' : ' vacia' ?>">
                <input type="checkbox" name="tareas[]" value="<?= e($clave) ?>" <?= $n ? '' : 'disabled' ?>>
                <span class="fm-texto">
                  <span class="fm-titulo"><?= e($t['titulo']) ?></span>
                  <span class="fm-ayuda"><?= e($t['ayuda']) ?></span>
                </span>
                <span class="fm-cifra"><?= (int)$n ?> <?= e($t['unidad']) ?></span>
              </label>
            <?php endforeach; ?>

            <button type="submit" class="boton boton-principal" style="width:100%; margin-top:16px;"
                    <?= $totalSobrante ? '' : 'disabled' ?>>
              <i class="fa-solid fa-broom" aria-hidden="true"></i> Limpiar lo marcado</button>
            <p class="ayuda" style="margin-top:10px;">
              Nunca se tocan las fotos en uso, los comprobantes de pedidos vivos ni los
              archivos subidos en las últimas 24 horas. La limpieza queda registrada en la auditoría.
            </p>
          </form>
        </div>
      </section>
    <?php endif; ?>

    <?php if (!$puedeRestaurar): ?>
      <div class="caja-aviso alerta">
        <i class="fa-solid fa-lock" aria-hidden="true"></i>
        <span>Tu rol puede ver y crear respaldos, pero no restaurarlos. Restaurar reemplaza
              todos los datos, así que está reservado al super administrador.</span>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php if ($puedeRestaurar): ?>
<dialog class="modal" id="modalRestaurar">
  <form method="post" action="<?= e(url('admin/respaldos.php')) ?>" data-frase-confirmacion="RESTAURAR" data-una-vez>
    <?= campoToken() ?>
    <input type="hidden" name="accion" value="restaurar">
    <input type="hidden" name="id" value="0">
    <div class="modal-cabecera">
      <h2 style="color:var(--p-alerta);">Restaurar la base de datos</h2>
      <p class="sub">Vas a restaurar <strong data-destino="nombre_respaldo"></strong>.</p>
    </div>
    <div class="modal-cuerpo">
      <div class="caja-aviso error">
        <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
        <span><strong>Esta acción reemplazará los datos actuales por los contenidos del respaldo.</strong>
          Los pedidos, productos y clientes creados después de esa copia dejarán de existir.</span>
      </div>
      <p style="font-size:.88rem; color:var(--p-suave); margin-bottom:14px;">
        Antes de empezar se creará automáticamente una copia del estado actual, para que
        puedas volver atrás si algo sale mal. La acción queda registrada en la auditoría a tu nombre.
      </p>
      <div class="campo">
        <label for="confirmacion">Escribe RESTAURAR para confirmar</label>
        <input type="text" id="confirmacion" name="confirmacion" autocomplete="off"
               placeholder="RESTAURAR" required>
      </div>
    </div>
    <div class="modal-pie">
      <button type="button" class="boton boton-claro" data-cerrar-modal>Cancelar</button>
      <button type="submit" class="boton boton-peligro" disabled>
        <i class="fa-solid fa-rotate-left" aria-hidden="true"></i> Restaurar ahora</button>
    </div>
  </form>
</dialog>
<?php endif; ?>

<?php require __DIR__ . '/_pie.php'; ?>
