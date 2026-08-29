<?php
/**
 * Categorías del catálogo.
 *
 * No se permite borrar una categoría que todavía tenga productos: dejaría
 * filas apuntando a un id inexistente. Hay que mover o borrar sus productos
 * primero, y la pantalla lo dice.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

$seccion = 'categorias';
Rbac::exigirPanel();
Rbac::exigir('categorias.gestionar');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirToken(false, 'admin/categorias.php');
    $accion = opcion('accion', ['guardar', 'eliminar'], 'guardar');
    $id     = identificador('id');

    if ($accion === 'eliminar') {
        $cuantos = $pdo->prepare("SELECT COUNT(*) FROM productos WHERE categoria_id = ?");
        $cuantos->execute([$id]);
        $usados = (int)$cuantos->fetchColumn();

        if ($usados > 0) {
            flash('error', "No se puede borrar: hay $usados producto(s) en esa categoría. "
                         . 'Muévelos a otra categoría primero.');
        } else {
            $nombre = $pdo->prepare("SELECT nombre FROM categorias WHERE id = ?");
            $nombre->execute([$id]);
            $pdo->prepare("DELETE FROM categorias WHERE id = ?")->execute([$id]);
            Auditoria::registrar($pdo, 'eliminar', 'productos', [
                'recurso_tipo' => 'categoria', 'recurso_id' => (string)$id,
                'descripcion'  => 'Categoría eliminada: ' . (string)$nombre->fetchColumn(),
            ]);
            flash('exito', 'Categoría eliminada.');
        }
        redirigir('admin/categorias.php');
    }

    $nombre      = texto('nombre', 100);
    $descripcion = texto('descripcion', 255);
    $orden       = entero('orden', 0, 999);
    $activo      = casilla('activo');

    if (mb_strlen($nombre) < 2) {
        flash('error', 'El nombre de la categoría necesita al menos 2 caracteres.');
        redirigir('admin/categorias.php');
    }

    $repetida = $pdo->prepare("SELECT 1 FROM categorias WHERE nombre = ? AND id <> ?");
    $repetida->execute([$nombre, $id]);
    if ($repetida->fetchColumn()) {
        flash('error', 'Ya existe una categoría con ese nombre.');
        redirigir('admin/categorias.php');
    }

    $slugBase = slugificar($nombre);
    $slug     = $slugBase;
    $n        = 2;
    $libre    = $pdo->prepare("SELECT 1 FROM categorias WHERE slug = ? AND id <> ?");
    $libre->execute([$slug, $id]);
    while ($libre->fetchColumn()) {
        $slug = $slugBase . '-' . $n++;
        $libre->execute([$slug, $id]);
    }

    $esNueva = $id === 0;
    if (!$esNueva) {
        $pdo->prepare("UPDATE categorias SET nombre = ?, slug = ?, descripcion = ?, orden = ?, activo = ? WHERE id = ?")
            ->execute([$nombre, $slug, $descripcion, $orden, $activo, $id]);
    } else {
        $pdo->prepare("INSERT INTO categorias (nombre, slug, descripcion, orden, activo) VALUES (?,?,?,?,?)")
            ->execute([$nombre, $slug, $descripcion, $orden, $activo]);
        $id = (int)$pdo->lastInsertId();
    }

    Auditoria::registrar($pdo, $esNueva ? 'crear' : 'editar', 'productos', [
        'recurso_tipo' => 'categoria', 'recurso_id' => (string)$id,
        'descripcion'  => 'Categoría guardada: ' . $nombre,
    ]);
    flash('exito', 'Categoría guardada.');
    redirigir('admin/categorias.php');
}

$categorias = $pdo->query(
    "SELECT c.*, COUNT(p.id) AS productos
       FROM categorias c LEFT JOIN productos p ON p.categoria_id = c.id
   GROUP BY c.id ORDER BY c.orden, c.nombre"
)->fetchAll();

$tituloPanel      = 'Categorías';
$subtituloPanel   = 'Cómo se agrupan los arreglos en el catálogo';
$accionesCabecera = '<button type="button" class="boton boton-principal" data-abrir-modal="modalCategoria"'
                  . ' data-campo-id="0" data-campo-nombre="" data-campo-descripcion="" data-campo-orden="0">'
                  . '<i class="fa-solid fa-plus" aria-hidden="true"></i> Nueva categoría</button>';

require __DIR__ . '/_cabecera.php';
?>

<section class="panel">
  <?php if (!$categorias): ?>
    <div class="vacio">
      <i class="fa-solid fa-tags" aria-hidden="true"></i>
      <h3>Todavía no hay categorías</h3>
      <p>Las categorías organizan el catálogo y aparecen como filtros en la web.</p>
    </div>
  <?php else: ?>
    <div class="tabla-envoltura">
      <table class="tabla">
        <thead><tr><th>Categoría</th><th>Descripción</th><th class="num">Productos</th>
                   <th class="num">Orden</th><th>Estado</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($categorias as $c): ?>
            <tr>
              <td>
                <span class="celda-principal"><?= e((string)$c['nombre']) ?></span><br>
                <span class="celda-sub">/<?= e((string)$c['slug']) ?></span>
              </td>
              <td class="celda-sub"><?= e((string)$c['descripcion']) ?></td>
              <td class="num"><?= (int)$c['productos'] ?></td>
              <td class="num"><?= (int)$c['orden'] ?></td>
              <td><span class="estado-suave <?= (int)$c['activo'] ? 'si' : 'no' ?>">
                <?= (int)$c['activo'] ? 'Visible' : 'Oculta' ?></span></td>
              <td class="acciones">
                <div style="display:inline-flex; gap:5px;">
                  <button type="button" class="boton-icono" data-abrir-modal="modalCategoria"
                          data-campo-id="<?= (int)$c['id'] ?>"
                          data-campo-nombre="<?= e((string)$c['nombre']) ?>"
                          data-campo-descripcion="<?= e((string)$c['descripcion']) ?>"
                          data-campo-orden="<?= (int)$c['orden'] ?>"
                          data-campo-activo="<?= (int)$c['activo'] ?>"
                          aria-label="Editar <?= e((string)$c['nombre']) ?>">
                    <i class="fa-solid fa-pen" aria-hidden="true"></i></button>

                  <form method="post" action="<?= e(url('admin/categorias.php')) ?>"
                        data-confirmar="¿Eliminar la categoría «<?= e((string)$c['nombre']) ?>»?">
                    <?= campoToken() ?>
                    <input type="hidden" name="accion" value="eliminar">
                    <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                    <button type="submit" class="boton-icono peligro"
                            <?= (int)$c['productos'] > 0 ? 'disabled title="Tiene productos asignados"' : '' ?>
                            aria-label="Eliminar <?= e((string)$c['nombre']) ?>">
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

<dialog class="modal" id="modalCategoria">
  <form method="post" action="<?= e(url('admin/categorias.php')) ?>">
    <?= campoToken() ?>
    <input type="hidden" name="accion" value="guardar">
    <input type="hidden" name="id" value="0">
    <div class="modal-cabecera"><h2>Categoría</h2>
      <p class="sub">El nombre se convierte en la dirección de la categoría en la web.</p></div>
    <div class="modal-cuerpo">
      <div class="campo">
        <label for="cat_nombre">Nombre *</label>
        <input type="text" id="cat_nombre" name="nombre" required maxlength="100">
      </div>
      <div class="campo">
        <label for="cat_descripcion">Descripción</label>
        <input type="text" id="cat_descripcion" name="descripcion" maxlength="255">
      </div>
      <div class="campo">
        <label for="cat_orden">Orden</label>
        <input type="number" id="cat_orden" name="orden" min="0" max="999" value="0">
        <p class="ayuda">Menor número, primero en la lista.</p>
      </div>
      <div class="interruptor">
        <input type="checkbox" id="cat_activo" name="activo" value="1" checked>
        <label for="cat_activo">Visible en la web</label>
      </div>
    </div>
    <div class="modal-pie">
      <button type="button" class="boton boton-claro" data-cerrar-modal>Cancelar</button>
      <button type="submit" class="boton boton-principal">Guardar</button>
    </div>
  </form>
</dialog>

<?php require __DIR__ . '/_pie.php'; ?>
