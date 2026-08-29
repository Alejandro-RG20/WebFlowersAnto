<?php
/**
 * Estado de la base de datos y aplicación de migraciones.
 *
 * Existe para los hostings sin acceso a consola, donde `php db/migrar.php` no
 * es una opción. Requiere el permiso `sistema.migrar` (solo super admin).
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../db/Migrador.php';

$seccion = 'base-datos';
Rbac::exigirPanel();
Rbac::exigir('sistema.migrar');

$migrador = new Migrador($pdo, RAIZ . '/db/migraciones', Entorno::texto('DB_NAME', 'flowers_anto'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirToken(false, 'admin/base-datos.php');

    $resultado = $migrador->ejecutar();
    if ($resultado['errores']) {
        Auditoria::registrar($pdo, 'migrar', 'sistema', [
            'resultado'   => 'fallo',
            'descripcion' => 'La migración se detuvo con error.',
            'detalles'    => $resultado['errores'],
        ]);
        flash('error', 'La migración se detuvo: ' . implode(' · ', $resultado['errores']));
    } else {
        Auditoria::registrar($pdo, 'migrar', 'sistema', [
            'descripcion' => count($resultado['aplicadas']) . ' migraciones aplicadas.',
            'detalles'    => ['aplicadas' => $resultado['aplicadas']],
        ]);
        flash('exito', count($resultado['aplicadas']) . ' migración(es) aplicada(s) correctamente.');
    }
    redirigir('admin/base-datos.php');
}

$aplicadas  = $migrador->aplicadas();
$pendientes = $migrador->pendientes();

$tablas = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
$filas  = [];
foreach (['productos', 'pedidos', 'usuarios', 'auditoria', 'pedido_items'] as $tabla) {
    if (in_array($tabla, $tablas, true)) {
        $filas[$tabla] = (int)$pdo->query("SELECT COUNT(*) FROM `$tabla`")->fetchColumn();
    }
}

$tituloPanel    = 'Base de datos';
$subtituloPanel = count($tablas) . ' tablas · ' . count($aplicadas) . ' migraciones aplicadas';

require __DIR__ . '/_cabecera.php';
?>

<div class="rejilla-detalle">
  <div>
    <section class="panel">
      <div class="panel-cabecera"><div>
        <h2>Migraciones</h2>
        <p>Cambios de estructura versionados. Se aplican en orden y solo una vez.</p>
      </div></div>
      <div class="panel-cuerpo">
        <?php if ($pendientes): ?>
          <div class="caja-aviso alerta">
            <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
            <span>Hay <?= count($pendientes) ?> migración(es) sin aplicar. Hasta que se apliquen,
              algunas funciones pueden fallar. Crea un respaldo antes por precaución.</span>
          </div>
          <ul style="margin:0 0 18px 20px; font-size:.9rem; color:var(--p-suave);">
            <?php foreach ($pendientes as $p): ?><li><code><?= e($p) ?></code></li><?php endforeach; ?>
          </ul>
          <div style="display:flex; gap:9px; flex-wrap:wrap;">
            <a class="boton boton-claro" href="<?= e(url('admin/respaldos.php')) ?>">
              <i class="fa-solid fa-database" aria-hidden="true"></i> Crear respaldo primero</a>
            <form method="post" action="<?= e(url('admin/base-datos.php')) ?>" data-una-vez
                  data-confirmar="¿Aplicar las migraciones pendientes? Conviene tener un respaldo reciente.">
              <?= campoToken() ?>
              <button type="submit" class="boton boton-principal">
                <i class="fa-solid fa-play" aria-hidden="true"></i> Aplicar migraciones</button>
            </form>
          </div>
        <?php else: ?>
          <div class="caja-aviso exito">
            <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
            <span>La estructura está al día. No hay nada pendiente.</span>
          </div>
        <?php endif; ?>

        <p class="etiqueta" style="margin-top:20px;">Ya aplicadas</p>
        <ul style="margin:0 0 0 20px; font-size:.87rem; color:var(--p-tenue);">
          <?php foreach ($aplicadas as $a): ?><li><code><?= e($a) ?></code></li><?php endforeach; ?>
        </ul>
      </div>
    </section>
  </div>

  <div>
    <section class="panel">
      <div class="panel-cabecera"><div><h2>Contenido</h2></div></div>
      <div class="panel-cuerpo">
        <dl class="lista-datos">
          <?php foreach ($filas as $tabla => $cuantas): ?>
            <div><dt><?= e(ucfirst(str_replace('_', ' ', $tabla))) ?></dt>
                <dd><?= number_format($cuantas) ?></dd></div>
          <?php endforeach; ?>
          <div><dt>Tablas totales</dt><dd><?= count($tablas) ?></dd></div>
        </dl>
      </div>
    </section>

    <section class="panel">
      <div class="panel-cabecera"><div><h2>Conexión</h2></div></div>
      <div class="panel-cuerpo">
        <dl class="lista-datos">
          <div><dt>Base</dt><dd><?= e(Entorno::texto('DB_NAME', 'flowers_anto')) ?></dd></div>
          <div><dt>Servidor</dt><dd><?= e(Entorno::texto('DB_HOST', 'localhost')) ?></dd></div>
          <div><dt>Motor</dt><dd><?= e($pdo->getAttribute(PDO::ATTR_SERVER_VERSION)) ?></dd></div>
          <div><dt>PHP</dt><dd><?= e(PHP_VERSION) ?></dd></div>
          <div><dt>Entorno</dt><dd><?= e(ENTORNO) ?></dd></div>
        </dl>
        <?php if (ENTORNO === 'dev'): ?>
          <div class="caja-aviso alerta" style="margin-top:14px;">
            <i class="fa-solid fa-bug" aria-hidden="true"></i>
            <span>El entorno está en <code>dev</code>: los errores se muestran en pantalla.
              En producción hay que ponerlo en <code>prod</code>.</span>
          </div>
        <?php endif; ?>
      </div>
    </section>
  </div>
</div>

<?php require __DIR__ . '/_pie.php'; ?>
