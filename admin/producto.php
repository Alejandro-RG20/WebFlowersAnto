<?php
/**
 * Alta y edición de un producto, con su galería de fotos.
 *
 * Las fotos se guardan en `producto_imagenes`; la primera de la lista es la
 * portada y se copia a `productos.imagen` para que los listados no necesiten
 * un JOIN extra.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

$seccion = 'productos';
Rbac::exigirPanel();

$id      = identificador('id', $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET);
$esNuevo = $id === 0;

Rbac::exigir($esNuevo ? 'productos.crear' : 'productos.editar');

$categorias = $pdo->query("SELECT id, nombre FROM categorias ORDER BY orden, nombre")->fetchAll();
if (!$categorias) {
    flash('alerta', 'Crea al menos una categoría antes de añadir productos.');
    redirigir('admin/categorias.php');
}

// --- Datos actuales ----------------------------------------------------
$producto = [
    'nombre' => '', 'slug' => '', 'descripcion' => '', 'resumen' => '',
    'precio' => 0, 'precio_usd' => 0, 'categoria_id' => (int)$categorias[0]['id'],
    'flores' => '', 'color_acento' => '#EFD9DE', 'destacado' => 0, 'orden_hero' => 0,
    'orden' => 0, 'disponible' => 1, 'activo' => 1, 'stock' => 0, 'controla_stock' => 0,
    'imagen_hero' => '',
];
$imagenes = [];

if (!$esNuevo) {
    $st = $pdo->prepare("SELECT * FROM productos WHERE id = ?");
    $st->execute([$id]);
    $encontrado = $st->fetch();

    if (!$encontrado) {
        flash('error', 'Ese producto no existe.');
        redirigir('admin/productos.php');
    }
    $producto = $encontrado;
    $imagenes = array_column(Catalogo::imagenes($pdo, $id), 'ruta');
}

$errores = [];

// --- Guardar ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirToken(false, 'admin/productos.php');

    $antes = $producto;

    $producto['nombre']       = texto('nombre', 120);
    $producto['descripcion']  = textoLargo('descripcion', 4000);
    $producto['resumen']      = texto('resumen', 200);
    $producto['precio']       = decimal('precio');
    $producto['precio_usd']   = decimal('precio_usd');
    $producto['categoria_id'] = identificador('categoria_id');
    $producto['flores']       = mb_strtolower(texto('flores', 255));
    $producto['color_acento'] = colorHex('color_acento');
    $producto['destacado']    = casilla('destacado');
    $producto['orden_hero']   = entero('orden_hero', 0, 999);
    $producto['orden']        = entero('orden', 0, 9999);
    $producto['disponible']   = casilla('disponible');
    $producto['activo']       = casilla('activo');
    $producto['controla_stock'] = casilla('controla_stock');
    $producto['stock']        = entero('stock', 0, 99999);

    // Galería: se valida cada ruta para que nadie inyecte una externa.
    $imagenes = [];
    foreach ((array)($_POST['imagenes'] ?? []) as $bruta) {
        $ruta = rutaImagen('__tmp', '', ['__tmp' => $bruta]);
        if ($ruta !== '' && !in_array($ruta, $imagenes, true)) {
            $imagenes[] = $ruta;
        }
    }
    $imagenes = array_slice($imagenes, 0, 12);

    // Foto exclusiva del carrusel (PNG recortado). Es opcional: vacía, el
    // carrusel sigue usando la portada normal.
    $heroBruta = (array)($_POST['imagen_hero'] ?? []);
    $producto['imagen_hero'] = $heroBruta
        ? rutaImagen('__tmp', '', ['__tmp' => (string)reset($heroBruta)])
        : '';

    if (mb_strlen($producto['nombre']) < 3) {
        $errores['nombre'] = 'El nombre necesita al menos 3 caracteres.';
    }
    if (mb_strlen($producto['descripcion']) < 10) {
        $errores['descripcion'] = 'Escribe una descripción de al menos 10 caracteres.';
    }
    if ($producto['precio'] <= 0) {
        $errores['precio'] = 'El precio debe ser mayor que cero.';
    }
    $categoriaOk = $pdo->prepare("SELECT 1 FROM categorias WHERE id = ?");
    $categoriaOk->execute([$producto['categoria_id']]);
    if (!$categoriaOk->fetchColumn()) {
        $errores['categoria_id'] = 'Elige una categoría válida.';
    }
    if (!$imagenes) {
        $errores['imagenes'] = 'Añade al menos una foto: es lo primero que ve el cliente.';
    }

    if (!$errores) {
        // Slug único y estable: solo se recalcula si cambió el nombre.
        $slugBase = slugificar($producto['nombre']);
        $slug     = $esNuevo || slugificar((string)$antes['nombre']) !== $slugBase
                  ? $slugBase : (string)$antes['slug'];
        $n = 2;
        $libre = $pdo->prepare("SELECT 1 FROM productos WHERE slug = ? AND id <> ?");
        $libre->execute([$slug, $id]);
        while ($libre->fetchColumn()) {
            $slug = $slugBase . '-' . $n++;
            $libre->execute([$slug, $id]);
        }
        $producto['slug'] = $slug;

        if ($producto['resumen'] === '') {
            $producto['resumen'] = recortar($producto['descripcion'], 160);
        }

        $pdo->beginTransaction();
        try {
            $campos = [
                $producto['nombre'], $slug, $producto['descripcion'], $producto['resumen'],
                $producto['precio'], $producto['precio_usd'], $imagenes[0],
                $producto['categoria_id'], $producto['flores'], $producto['color_acento'],
                $producto['destacado'], $producto['orden_hero'], $producto['orden'],
                $producto['disponible'], $producto['activo'],
                $producto['controla_stock'], $producto['stock'],
                $producto['imagen_hero'],
            ];

            if ($esNuevo) {
                $pdo->prepare(
                    "INSERT INTO productos
                        (nombre, slug, descripcion, resumen, precio, precio_usd, imagen, categoria_id,
                         flores, color_acento, destacado, orden_hero, orden, disponible, activo,
                         controla_stock, stock, imagen_hero)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
                )->execute($campos);
                $id = (int)$pdo->lastInsertId();
            } else {
                $campos[] = $id;
                $pdo->prepare(
                    "UPDATE productos SET nombre = ?, slug = ?, descripcion = ?, resumen = ?,
                            precio = ?, precio_usd = ?, imagen = ?, categoria_id = ?, flores = ?,
                            color_acento = ?, destacado = ?, orden_hero = ?, orden = ?,
                            disponible = ?, activo = ?, controla_stock = ?, stock = ?,
                            imagen_hero = ?
                      WHERE id = ?"
                )->execute($campos);
            }

            // La galería se reescribe entera: es la forma más simple de
            // respetar el orden que dejó el usuario en pantalla.
            $pdo->prepare("DELETE FROM producto_imagenes WHERE producto_id = ?")->execute([$id]);
            $insImg = $pdo->prepare(
                "INSERT INTO producto_imagenes (producto_id, ruta, alt, orden) VALUES (?,?,?,?)"
            );
            foreach ($imagenes as $orden => $ruta) {
                $insImg->execute([$id, $ruta, $producto['nombre'], $orden]);
            }

            $pdo->commit();
        } catch (Throwable $ex) {
            $pdo->rollBack();
            error_log('Flowers Anto — guardar producto: ' . $ex->getMessage());
            $errores[] = 'No se pudo guardar el producto. Inténtalo otra vez.';
        }

        if (!$errores) {
            Auditoria::registrar($pdo, $esNuevo ? 'crear' : 'editar', 'productos', [
                'recurso_tipo' => 'producto', 'recurso_id' => (string)$id,
                'descripcion'  => ($esNuevo ? 'Producto creado: ' : 'Producto editado: ') . $producto['nombre'],
                'detalles'     => $esNuevo ? ['precio' => $producto['precio']] : Auditoria::diferencias(
                    $antes, $producto,
                    ['nombre', 'precio', 'precio_usd', 'categoria_id', 'stock', 'disponible', 'activo', 'destacado']
                ),
            ]);
            flash('exito', $esNuevo ? 'Producto creado y publicado.' : 'Cambios guardados.');
            redirigir('admin/productos.php');
        }
    }
}

$tituloPanel    = $esNuevo ? 'Nuevo producto' : 'Editar producto';
$subtituloPanel = $esNuevo ? 'Los campos marcados con * son obligatorios.' : (string)$producto['nombre'];
$accionesCabecera = !$esNuevo
    ? '<a class="boton boton-claro" target="_blank" rel="noopener" href="'
      . e(url('producto.php?p=' . rawurlencode((string)$producto['slug'])))
      . '"><i class="fa-solid fa-up-right-from-square" aria-hidden="true"></i> Ver en la web</a>'
    : '';

require __DIR__ . '/_cabecera.php';
?>

<a class="boton boton-claro boton-mini" href="<?= e(url('admin/productos.php')) ?>" style="margin-bottom:16px;">
  <i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Volver a productos</a>

<?php foreach ($errores as $clave => $mensaje): if (!is_int($clave)) continue; ?>
  <div class="caja-aviso error"><i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
    <span><?= e((string)$mensaje) ?></span></div>
<?php endforeach; ?>

<form method="post" action="<?= e(url('admin/producto.php')) ?>" novalidate>
  <?= campoToken() ?>
  <input type="hidden" name="id" value="<?= (int)$id ?>">

  <div class="rejilla-detalle">
    <div>
      <section class="panel">
        <div class="panel-cabecera"><div><h2>Información</h2></div></div>
        <div class="panel-cuerpo">
          <div class="campo<?= isset($errores['nombre']) ? ' con-error' : '' ?>">
            <label for="nombre">Nombre *</label>
            <input type="text" id="nombre" name="nombre" required maxlength="120"
                   value="<?= e((string)$producto['nombre']) ?>">
            <?php if (isset($errores['nombre'])): ?><p class="error-campo"><?= e($errores['nombre']) ?></p><?php endif; ?>
          </div>

          <div class="campo<?= isset($errores['descripcion']) ? ' con-error' : '' ?>">
            <label for="descripcion">Descripción *</label>
            <textarea id="descripcion" name="descripcion" required maxlength="4000"
                      style="min-height:130px;"><?= e((string)$producto['descripcion']) ?></textarea>
            <p class="ayuda">Qué flores lleva, para qué ocasión sirve, cómo se presenta.</p>
            <?php if (isset($errores['descripcion'])): ?><p class="error-campo"><?= e($errores['descripcion']) ?></p><?php endif; ?>
          </div>

          <div class="campo">
            <label for="resumen">Resumen corto</label>
            <input type="text" id="resumen" name="resumen" maxlength="200"
                   value="<?= e((string)$producto['resumen']) ?>">
            <p class="ayuda">Sale en las tarjetas del catálogo y en Google. Si lo dejas vacío se genera solo.</p>
          </div>

          <div class="rejilla-campos dos">
            <div class="campo<?= isset($errores['categoria_id']) ? ' con-error' : '' ?>">
              <label for="categoria_id">Categoría *</label>
              <select id="categoria_id" name="categoria_id" required>
                <?php foreach ($categorias as $c): ?>
                  <option value="<?= (int)$c['id'] ?>"<?= (int)$producto['categoria_id'] === (int)$c['id'] ? ' selected' : '' ?>>
                    <?= e((string)$c['nombre']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="campo">
              <label for="flores">Tipos de flor</label>
              <input type="text" id="flores" name="flores" maxlength="255"
                     value="<?= e((string)$producto['flores']) ?>" placeholder="rosas, girasoles, mixtas">
              <p class="ayuda">Separados por comas. Alimentan los filtros del catálogo.</p>
            </div>
          </div>
        </div>
      </section>

      <section class="panel">
        <div class="panel-cabecera"><div>
          <h2>Fotos</h2>
          <p>La primera es la portada. Arrastra archivos o usa el recuadro.</p>
        </div></div>
        <div class="panel-cuerpo">
          <?php if (isset($errores['imagenes'])): ?>
            <div class="caja-aviso error"><i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
              <span><?= e($errores['imagenes']) ?></span></div>
          <?php endif; ?>

          <div data-galeria="imagenes">
            <div class="rejilla-imagenes">
              <?php foreach ($imagenes as $indice => $ruta): ?>
                <div class="casilla-imagen">
                  <img src="<?= e(url_imagen($ruta)) ?>" alt="">
                  <button type="button" class="quitar" aria-label="Quitar imagen">
                    <i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
                  <span class="portada"<?= $indice === 0 ? '' : ' hidden' ?>>Portada</span>
                  <input type="hidden" name="imagenes[]" value="<?= e($ruta) ?>">
                </div>
              <?php endforeach; ?>

              <label class="soltar-imagen">
                <span>
                  <i class="fa-solid fa-cloud-arrow-up" aria-hidden="true"></i>
                  Añadir fotos<br><small>JPG, PNG o WEBP</small>
                </span>
                <input type="file" accept="image/jpeg,image/png,image/webp,image/gif" multiple>
              </label>
            </div>
            <p class="ayuda" style="margin-top:10px;">
              Máximo <?= (int)(MAX_UPLOAD_BYTES / 1048576) ?> MB por foto. Se validan por su contenido,
              no por la extensión del nombre.
            </p>
          </div>
        </div>
      </section>

      <section class="panel">
        <div class="panel-cabecera"><div>
          <h2>Foto para el carrusel</h2>
          <p>Opcional. Un PNG con el arreglo recortado luce mejor en la portada.</p>
        </div></div>
        <div class="panel-cuerpo">
          <div data-galeria="imagen_hero" data-maximo="1">
            <div class="rejilla-imagenes">
              <?php if (($producto['imagen_hero'] ?? '') !== ''): ?>
                <div class="casilla-imagen">
                  <img src="<?= e(url_imagen((string)$producto['imagen_hero'])) ?>" alt="">
                  <button type="button" class="quitar" aria-label="Quitar la foto del carrusel">
                    <i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
                  <input type="hidden" name="imagen_hero[]" value="<?= e((string)$producto['imagen_hero']) ?>">
                </div>
              <?php endif; ?>
              <label class="soltar-imagen">
                <span>
                  <i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i>
                  Subir PNG recortado<br><small>Fondo transparente</small>
                </span>
                <input type="file" accept="image/png,image/webp">
              </label>
            </div>
            <p class="ayuda" style="margin-top:10px;">
              Solo se usa en el carrusel de la portada. En el catálogo y en la ficha
              del producto se sigue viendo la portada normal. Si la dejas vacía, el
              carrusel también usa la portada.
            </p>
          </div>
        </div>
      </section>
    </div>

    <div>
      <section class="panel">
        <div class="panel-cabecera"><div><h2>Precio</h2></div></div>
        <div class="panel-cuerpo">
          <div class="campo<?= isset($errores['precio']) ? ' con-error' : '' ?>">
            <label for="precio">Precio en <?= e(Ajustes::texto('moneda_local', 'C$')) ?> *</label>
            <input type="number" id="precio" name="precio" step="0.01" min="0" required
                   value="<?= e(number_format((float)$producto['precio'], 2, '.', '')) ?>">
            <?php if (isset($errores['precio'])): ?><p class="error-campo"><?= e($errores['precio']) ?></p><?php endif; ?>
          </div>
          <div class="campo">
            <label for="precio_usd">Precio en dólares</label>
            <input type="number" id="precio_usd" name="precio_usd" step="0.01" min="0"
                   value="<?= e(number_format((float)$producto['precio_usd'], 2, '.', '')) ?>">
            <p class="ayuda">Solo informativo. Se muestra si está activado en Configuración.</p>
          </div>
        </div>
      </section>

      <section class="panel">
        <div class="panel-cabecera"><div><h2>Disponibilidad</h2></div></div>
        <div class="panel-cuerpo">
          <div class="interruptor">
            <input type="checkbox" id="activo" name="activo" value="1" <?= (int)$producto['activo'] ? 'checked' : '' ?>>
            <label for="activo">Publicado en la web
              <small>Si lo desmarcas, deja de aparecer en el catálogo.</small></label>
          </div>
          <div class="interruptor">
            <input type="checkbox" id="disponible" name="disponible" value="1" <?= (int)$producto['disponible'] ? 'checked' : '' ?>>
            <label for="disponible">Disponible para pedir ahora
              <small>Si lo desmarcas se muestra como «sobre pedido» y solo se puede consultar por WhatsApp.</small></label>
          </div>
          <div class="interruptor">
            <input type="checkbox" id="controla_stock" name="controla_stock" value="1"
                   <?= (int)$producto['controla_stock'] ? 'checked' : '' ?>>
            <label for="controla_stock">Llevar control de unidades
              <small>Al confirmarse un pedido se descuentan del stock automáticamente.</small></label>
          </div>
          <div class="campo">
            <label for="stock">Unidades en stock</label>
            <input type="number" id="stock" name="stock" min="0" max="99999" value="<?= (int)$producto['stock'] ?>">
          </div>
        </div>
      </section>

      <section class="panel">
        <div class="panel-cabecera"><div><h2>Presentación</h2></div></div>
        <div class="panel-cuerpo">
          <div class="interruptor">
            <input type="checkbox" id="destacado" name="destacado" value="1" <?= (int)$producto['destacado'] ? 'checked' : '' ?>>
            <label for="destacado">Destacado en la portada
              <small>Aparece en el carrusel principal y en la sección de destacados.</small></label>
          </div>
          <div class="rejilla-campos dos">
            <div class="campo">
              <label for="orden_hero">Orden en la portada</label>
              <input type="number" id="orden_hero" name="orden_hero" min="0" max="999" value="<?= (int)$producto['orden_hero'] ?>">
            </div>
            <div class="campo">
              <label for="orden">Orden en el catálogo</label>
              <input type="number" id="orden" name="orden" min="0" max="9999" value="<?= (int)$producto['orden'] ?>">
            </div>
          </div>
          <div class="campo">
            <label for="color_acento">Color de fondo en la portada</label>
            <input type="color" id="color_acento" name="color_acento" value="<?= e((string)$producto['color_acento']) ?>">
            <p class="ayuda">Se usa como fondo del carrusel cuando este arreglo es el protagonista.</p>
          </div>
        </div>
      </section>

      <button type="submit" class="boton boton-principal" style="width:100%;">
        <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>
        <?= $esNuevo ? 'Crear producto' : 'Guardar cambios' ?>
      </button>
    </div>
  </div>
</form>

<?php require __DIR__ . '/_pie.php'; ?>
