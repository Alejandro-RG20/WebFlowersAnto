<?php
/**
 * Galería: fotos de entregas y videos de YouTube.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

$seccion = 'galeria';
Rbac::exigirPanel();
Rbac::exigir('galeria.gestionar');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirToken(false, 'admin/galeria.php');
    $accion = opcion('accion', ['guardar_foto', 'eliminar_foto', 'guardar_video', 'eliminar_video'], '');
    $id     = identificador('id');

    switch ($accion) {
        case 'guardar_foto':
            $imagen = rutaImagen('imagen');
            $titulo = texto('titulo', 150);
            $orden  = entero('orden', 0, 999);
            $activo = casilla('activo');

            if ($imagen === '') {
                flash('error', 'Sube una imagen antes de guardar.');
                redirigir('admin/galeria.php');
            }
            if ($id > 0) {
                $pdo->prepare("UPDATE clientes_fotos SET imagen = ?, titulo = ?, orden = ?, activo = ? WHERE id = ?")
                    ->execute([$imagen, $titulo, $orden, $activo, $id]);
            } else {
                $pdo->prepare("INSERT INTO clientes_fotos (imagen, titulo, orden, activo) VALUES (?,?,?,?)")
                    ->execute([$imagen, $titulo, $orden, $activo]);
                $id = (int)$pdo->lastInsertId();
            }
            Auditoria::registrar($pdo, 'editar', 'productos', [
                'recurso_tipo' => 'foto_galeria', 'recurso_id' => (string)$id,
                'descripcion'  => 'Foto de galería guardada.',
            ]);
            flash('exito', 'Foto guardada.');
            break;

        case 'eliminar_foto':
            $pdo->prepare("DELETE FROM clientes_fotos WHERE id = ?")->execute([$id]);
            Auditoria::registrar($pdo, 'eliminar', 'productos', [
                'recurso_tipo' => 'foto_galeria', 'recurso_id' => (string)$id,
                'descripcion'  => 'Foto de galería eliminada.',
            ]);
            flash('exito', 'Foto eliminada.');
            break;

        case 'guardar_video':
            $titulo = texto('titulo', 150);
            $enlace = urlOpcional('enlace_youtube');
            $desc   = textoLargo('descripcion', 800);
            $activo = casilla('activo');

            if ($titulo === '' || $enlace === '') {
                flash('error', 'El título y un enlace de YouTube válido son obligatorios.');
                redirigir('admin/galeria.php');
            }
            if (!preg_match('#(?:youtu\.be/|v=|embed/|shorts/)([A-Za-z0-9_-]{11})#', $enlace)) {
                flash('error', 'Ese enlace no parece un video de YouTube.');
                redirigir('admin/galeria.php');
            }
            if ($id > 0) {
                $pdo->prepare("UPDATE videos_youtube SET titulo = ?, enlace_youtube = ?, descripcion = ?, activo = ? WHERE id = ?")
                    ->execute([$titulo, $enlace, $desc, $activo, $id]);
            } else {
                $pdo->prepare("INSERT INTO videos_youtube (titulo, enlace_youtube, descripcion, activo) VALUES (?,?,?,?)")
                    ->execute([$titulo, $enlace, $desc, $activo]);
                $id = (int)$pdo->lastInsertId();
            }
            Auditoria::registrar($pdo, 'editar', 'productos', [
                'recurso_tipo' => 'video', 'recurso_id' => (string)$id,
                'descripcion'  => 'Video guardado: ' . $titulo,
            ]);
            flash('exito', 'Video guardado.');
            break;

        case 'eliminar_video':
            $pdo->prepare("DELETE FROM videos_youtube WHERE id = ?")->execute([$id]);
            Auditoria::registrar($pdo, 'eliminar', 'productos', [
                'recurso_tipo' => 'video', 'recurso_id' => (string)$id,
                'descripcion'  => 'Video eliminado.',
            ]);
            flash('exito', 'Video eliminado.');
            break;

        default:
            flash('error', 'Acción no reconocida.');
    }
    redirigir('admin/galeria.php');
}

$fotos  = $pdo->query("SELECT * FROM clientes_fotos ORDER BY orden, id DESC")->fetchAll();
$videos = $pdo->query("SELECT * FROM videos_youtube ORDER BY id DESC")->fetchAll();

$tituloPanel    = 'Galería';
$subtituloPanel = 'Fotos de entregas y videos del canal';

require __DIR__ . '/_cabecera.php';
?>

<div class="rejilla-detalle">
  <section class="panel">
    <div class="panel-cabecera">
      <div><h2>Fotos de entregas</h2><p><?= count($fotos) ?> en la galería</p></div>
      <button type="button" class="boton boton-principal boton-mini" data-abrir-modal="modalFoto"
              data-campo-id="0" data-campo-titulo="" data-campo-imagen="" data-campo-orden="0">
        <i class="fa-solid fa-plus" aria-hidden="true"></i> Añadir foto</button>
    </div>
    <div class="panel-cuerpo">
      <?php if (!$fotos): ?>
        <div class="vacio" style="padding:30px 12px;">
          <i class="fa-solid fa-images" aria-hidden="true"></i>
          <h3>Todavía no hay fotos</h3>
          <p>Suben mucho la confianza: son arreglos reales ya entregados.</p>
        </div>
      <?php else: ?>
        <div class="rejilla-imagenes">
          <?php foreach ($fotos as $f): ?>
            <div class="casilla-imagen">
              <img src="<?= e(url_imagen((string)$f['imagen'])) ?>" alt="<?= e((string)$f['titulo']) ?>" loading="lazy">
              <form method="post" action="<?= e(url('admin/galeria.php')) ?>"
                    data-confirmar="¿Eliminar esta foto de la galería?" style="position:absolute; top:6px; right:6px;">
                <?= campoToken() ?>
                <input type="hidden" name="accion" value="eliminar_foto">
                <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
                <button type="submit" class="quitar" style="position:static;" aria-label="Eliminar foto">
                  <i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
              </form>
              <?php if ((int)$f['activo'] === 0): ?>
                <span class="portada" style="background:var(--p-tenue);">Oculta</span>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </section>

  <section class="panel">
    <div class="panel-cabecera">
      <div><h2>Videos de YouTube</h2><p><?= count($videos) ?> publicados</p></div>
      <button type="button" class="boton boton-principal boton-mini" data-abrir-modal="modalVideo"
              data-campo-id="0" data-campo-titulo="" data-campo-enlace_youtube="" data-campo-descripcion="">
        <i class="fa-solid fa-plus" aria-hidden="true"></i> Añadir video</button>
    </div>
    <div class="panel-cuerpo">
      <?php if (!$videos): ?>
        <div class="vacio" style="padding:30px 12px;">
          <i class="fa-brands fa-youtube" aria-hidden="true"></i>
          <h3>No hay videos</h3>
          <p>Pega la dirección de un video de YouTube y aparecerá en la web.</p>
        </div>
      <?php else: ?>
        <?php foreach ($videos as $v): ?>
          <div class="linea-articulo">
            <div class="linea-articulo-datos">
              <strong><?= e((string)$v['titulo']) ?></strong>
              <small><?= e((string)$v['enlace_youtube']) ?></small>
            </div>
            <span class="estado-suave <?= (int)$v['activo'] ? 'si' : 'no' ?>">
              <?= (int)$v['activo'] ? 'Visible' : 'Oculto' ?></span>
            <div style="display:inline-flex; gap:5px;">
              <button type="button" class="boton-icono" data-abrir-modal="modalVideo"
                      data-campo-id="<?= (int)$v['id'] ?>"
                      data-campo-titulo="<?= e((string)$v['titulo']) ?>"
                      data-campo-enlace_youtube="<?= e((string)$v['enlace_youtube']) ?>"
                      data-campo-descripcion="<?= e((string)$v['descripcion']) ?>"
                      data-campo-activo="<?= (int)$v['activo'] ?>"
                      aria-label="Editar video">
                <i class="fa-solid fa-pen" aria-hidden="true"></i></button>
              <form method="post" action="<?= e(url('admin/galeria.php')) ?>" data-confirmar="¿Eliminar este video?">
                <?= campoToken() ?>
                <input type="hidden" name="accion" value="eliminar_video">
                <input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
                <button type="submit" class="boton-icono peligro" aria-label="Eliminar video">
                  <i class="fa-solid fa-trash-can" aria-hidden="true"></i></button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </section>
</div>

<dialog class="modal" id="modalFoto">
  <form method="post" action="<?= e(url('admin/galeria.php')) ?>">
    <?= campoToken() ?>
    <input type="hidden" name="accion" value="guardar_foto">
    <input type="hidden" name="id" value="0">
    <div class="modal-cabecera"><h2>Foto de la galería</h2></div>
    <div class="modal-cuerpo">
      <div class="campo" data-imagen-simple>
        <label>Imagen *</label>
        <input type="hidden" name="imagen" value="">
        <img alt="" hidden style="max-height:180px; border-radius:9px; margin-bottom:10px;">
        <input type="file" accept="image/jpeg,image/png,image/webp">
        <p class="ayuda">Se sube al elegirla. Después pulsa «Guardar».</p>
      </div>
      <div class="campo">
        <label for="f_titulo">Pie de foto</label>
        <input type="text" id="f_titulo" name="titulo" maxlength="150" placeholder="Entrega en Managua">
      </div>
      <div class="campo">
        <label for="f_orden">Orden</label>
        <input type="number" id="f_orden" name="orden" min="0" max="999" value="0">
      </div>
      <div class="interruptor">
        <input type="checkbox" id="f_activo" name="activo" value="1" checked>
        <label for="f_activo">Visible en la web</label>
      </div>
    </div>
    <div class="modal-pie">
      <button type="button" class="boton boton-claro" data-cerrar-modal>Cancelar</button>
      <button type="submit" class="boton boton-principal">Guardar foto</button>
    </div>
  </form>
</dialog>

<dialog class="modal" id="modalVideo">
  <form method="post" action="<?= e(url('admin/galeria.php')) ?>">
    <?= campoToken() ?>
    <input type="hidden" name="accion" value="guardar_video">
    <input type="hidden" name="id" value="0">
    <div class="modal-cabecera"><h2>Video de YouTube</h2></div>
    <div class="modal-cuerpo">
      <div class="campo">
        <label for="v_titulo">Título *</label>
        <input type="text" id="v_titulo" name="titulo" required maxlength="150">
      </div>
      <div class="campo">
        <label for="v_enlace">Enlace del video *</label>
        <input type="url" id="v_enlace" name="enlace_youtube" required maxlength="255"
               placeholder="https://www.youtube.com/watch?v=...">
      </div>
      <div class="campo">
        <label for="v_descripcion">Descripción</label>
        <textarea id="v_descripcion" name="descripcion" maxlength="800"></textarea>
      </div>
      <div class="interruptor">
        <input type="checkbox" id="v_activo" name="activo" value="1" checked>
        <label for="v_activo">Visible en la web</label>
      </div>
    </div>
    <div class="modal-pie">
      <button type="button" class="boton boton-claro" data-cerrar-modal>Cancelar</button>
      <button type="submit" class="boton boton-principal">Guardar video</button>
    </div>
  </form>
</dialog>

<?php require __DIR__ . '/_pie.php'; ?>
