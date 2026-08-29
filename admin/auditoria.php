<?php
/**
 * Auditoría.
 *
 * Solo lectura: no hay ninguna ruta en la aplicación que borre ni edite
 * filas de esta tabla. Se puede filtrar y exportar, nada más.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

$seccion = 'auditoria';
Rbac::exigirPanel();
Rbac::exigir('auditoria.ver');

$q         = texto('q', 80, $_GET);
$modulo    = texto('modulo', 40, $_GET);
$usuarioId = identificador('usuario', $_GET);
$resultado = opcion('resultado', ['', 'exito', 'fallo', 'denegado'], '', $_GET);
$desde     = fechaOpcional('desde', $_GET);
$hasta     = fechaOpcional('hasta', $_GET);
$pagina    = entero('pagina', 1, 9999, 1, $_GET);
$porPagina = 50;

$where  = ['1 = 1'];
$params = [];

if ($q !== '') {
    $t = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
    $where[] = '(a.descripcion LIKE ? OR a.usuario_texto LIKE ? OR a.accion LIKE ? OR a.recurso_id LIKE ?)';
    array_push($params, $t, $t, $t, $t);
}
if ($modulo !== '')    { $where[] = 'a.modulo = ?';     $params[] = $modulo; }
if ($usuarioId > 0)    { $where[] = 'a.usuario_id = ?'; $params[] = $usuarioId; }
if ($resultado !== '') { $where[] = 'a.resultado = ?';  $params[] = $resultado; }
if ($desde) { $where[] = 'DATE(a.created_at) >= ?'; $params[] = $desde; }
if ($hasta) { $where[] = 'DATE(a.created_at) <= ?'; $params[] = $hasta; }

$sqlWhere = implode(' AND ', $where);

// --- Exportación a CSV -------------------------------------------------
if (texto('exportar', 10, $_GET) === 'csv') {
    Auditoria::registrar($pdo, 'exportar', 'sistema', [
        'descripcion' => 'Exportación de la auditoría a CSV con los filtros actuales.',
    ]);

    $st = $pdo->prepare(
        "SELECT a.created_at, a.usuario_texto, a.rol, a.accion, a.modulo, a.recurso_tipo,
                a.recurso_id, a.resultado, a.descripcion, a.ip
           FROM auditoria a WHERE $sqlWhere ORDER BY a.created_at DESC LIMIT 5000"
    );
    $st->execute($params);

    if (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="auditoria_' . date('Ymd_His') . '.csv"');
    header('X-Content-Type-Options: nosniff');

    $salida = fopen('php://output', 'w');
    fwrite($salida, "\xEF\xBB\xBF"); // BOM: Excel abre el UTF-8 sin romper las tildes

    // El separador, el delimitador y el escape van explícitos: PHP 8.4 avisa
    // de que el valor por defecto de $escape va a cambiar, y una cadena vacía
    // es además el comportamiento correcto para un CSV estándar.
    $linea = static fn(array $campos) => fputcsv($salida, $campos, ',', '"', '');

    $linea(['Fecha', 'Usuario', 'Rol', 'Acción', 'Módulo', 'Tipo de recurso',
            'Id del recurso', 'Resultado', 'Descripción', 'IP']);
    while ($fila = $st->fetch(PDO::FETCH_NUM)) {
        $linea($fila);
    }
    fclose($salida);
    exit;
}

$stTotal = $pdo->prepare("SELECT COUNT(*) FROM auditoria a WHERE $sqlWhere");
$stTotal->execute($params);
$total   = (int)$stTotal->fetchColumn();
$paginas = max(1, (int)ceil($total / $porPagina));
$pagina  = min($pagina, $paginas);
$salto   = ($pagina - 1) * $porPagina;

$st = $pdo->prepare("SELECT a.* FROM auditoria a WHERE $sqlWhere
                      ORDER BY a.created_at DESC, a.id DESC LIMIT $porPagina OFFSET $salto");
$st->execute($params);
$registros = $st->fetchAll();

$modulos = $pdo->query("SELECT DISTINCT modulo FROM auditoria ORDER BY modulo")->fetchAll(PDO::FETCH_COLUMN);
$personas = $pdo->query(
    "SELECT DISTINCT a.usuario_id, a.usuario_texto FROM auditoria a
      WHERE a.usuario_id IS NOT NULL ORDER BY a.usuario_texto"
)->fetchAll();

function urlAuditoria(array $cambios = []): string
{
    $actual = array_intersect_key($_GET, array_flip(['q', 'modulo', 'usuario', 'resultado', 'desde', 'hasta', 'pagina']));
    $params = array_filter(array_merge($actual, $cambios), fn($v) => $v !== '' && $v !== null);
    return url('admin/auditoria.php' . ($params ? '?' . http_build_query($params) : ''));
}

$tituloPanel      = 'Auditoría';
$subtituloPanel   = number_format($total) . ' registros con los filtros actuales';
$accionesCabecera = '<a class="boton boton-claro" href="' . e(urlAuditoria(['exportar' => 'csv', 'pagina' => '']))
                  . '"><i class="fa-solid fa-file-csv" aria-hidden="true"></i> Exportar CSV</a>';

require __DIR__ . '/_cabecera.php';
?>

<div class="caja-aviso info">
  <i class="fa-solid fa-clipboard-list" aria-hidden="true"></i>
  <span>Este registro es de solo lectura: la aplicación no tiene ninguna función para borrar
    o modificar sus filas. Tampoco guarda contraseñas ni tokens, aunque se hayan enviado
    en el formulario de la acción registrada.</span>
</div>

<section class="panel">
  <form class="barra-herramientas" method="get" action="<?= e(url('admin/auditoria.php')) ?>" data-autofiltro>
    <div class="campo">
      <label for="q">Buscar</label>
      <input type="search" id="q" name="q" value="<?= e($q) ?>" placeholder="Descripción, acción o persona">
    </div>
    <div class="campo estrecho">
      <label for="modulo">Módulo</label>
      <select id="modulo" name="modulo">
        <option value="">Todos</option>
        <?php foreach ($modulos as $m): ?>
          <option value="<?= e((string)$m) ?>"<?= $modulo === $m ? ' selected' : '' ?>><?= e(ucfirst((string)$m)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="campo estrecho">
      <label for="usuario">Persona</label>
      <select id="usuario" name="usuario">
        <option value="">Todas</option>
        <?php foreach ($personas as $p): ?>
          <option value="<?= (int)$p['usuario_id'] ?>"<?= $usuarioId === (int)$p['usuario_id'] ? ' selected' : '' ?>>
            <?= e((string)$p['usuario_texto']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="campo estrecho">
      <label for="resultado">Resultado</label>
      <select id="resultado" name="resultado">
        <option value="">Todos</option>
        <option value="exito"<?=    $resultado === 'exito'    ? ' selected' : '' ?>>Correcto</option>
        <option value="fallo"<?=    $resultado === 'fallo'    ? ' selected' : '' ?>>Fallido</option>
        <option value="denegado"<?= $resultado === 'denegado' ? ' selected' : '' ?>>Denegado</option>
      </select>
    </div>
    <div class="campo estrecho">
      <label for="desde">Desde</label>
      <input type="date" id="desde" name="desde" value="<?= e((string)$desde) ?>">
    </div>
    <div class="campo estrecho">
      <label for="hasta">Hasta</label>
      <input type="date" id="hasta" name="hasta" value="<?= e((string)$hasta) ?>">
    </div>
    <button type="submit" class="boton boton-principal"><i class="fa-solid fa-filter" aria-hidden="true"></i> Filtrar</button>
    <a class="boton boton-claro" href="<?= e(url('admin/auditoria.php')) ?>">Limpiar</a>
  </form>

  <?php if (!$registros): ?>
    <div class="vacio">
      <i class="fa-solid fa-clipboard-list" aria-hidden="true"></i>
      <h3>No hay registros con estos filtros</h3>
      <p>Prueba a ampliar el rango de fechas.</p>
    </div>
  <?php else: ?>
    <div class="tabla-envoltura">
      <table class="tabla">
        <thead><tr><th>Cuándo</th><th>Quién</th><th>Qué</th><th>Recurso</th><th>Resultado</th><th>IP</th></tr></thead>
        <tbody>
          <?php foreach ($registros as $r): ?>
            <tr>
              <td class="celda-sub" style="white-space:nowrap;"><?= e(fecha_larga((string)$r['created_at'])) ?></td>
              <td>
                <span class="celda-principal"><?= e((string)$r['usuario_texto']) ?></span>
                <?php if ($r['rol'] !== ''): ?><br><span class="celda-sub"><?= e((string)$r['rol']) ?></span><?php endif; ?>
              </td>
              <td>
                <span class="estado-suave"><?= e(str_replace('_', ' ', (string)$r['accion'])) ?></span>
                <span class="celda-sub"> · <?= e((string)$r['modulo']) ?></span>
                <?php if ($r['descripcion'] !== ''): ?>
                  <br><span style="font-size:.85rem;"><?= e((string)$r['descripcion']) ?></span>
                <?php endif; ?>
                <?php if ($r['detalles']): ?>
                  <details style="margin-top:4px;">
                    <summary class="celda-sub" style="cursor:pointer;">Detalles</summary>
                    <pre style="font-size:.76rem; background:var(--p-fondo); padding:8px; border-radius:6px;
                                margin-top:5px; white-space:pre-wrap; word-break:break-word;"><?php
                      $detalles = json_decode((string)$r['detalles'], true);
                      echo e(is_array($detalles)
                          ? (string)json_encode($detalles, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                          : (string)$r['detalles']);
                    ?></pre>
                  </details>
                <?php endif; ?>
              </td>
              <td class="celda-sub">
                <?= $r['recurso_tipo'] !== '' ? e((string)$r['recurso_tipo']) : '—' ?>
                <?= $r['recurso_id'] !== '' ? ' #' . e((string)$r['recurso_id']) : '' ?>
              </td>
              <td>
                <span class="estado-suave <?= match ((string)$r['resultado']) {
                    'exito' => 'si', 'denegado' => 'mal', default => 'aviso' } ?>">
                  <?= e(match ((string)$r['resultado']) {
                      'exito' => 'Correcto', 'fallo' => 'Fallido', 'denegado' => 'Denegado',
                      default => (string)$r['resultado'] }) ?></span>
              </td>
              <td class="celda-sub"><?= e((string)$r['ip']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($paginas > 1): ?>
      <nav class="paginacion">
        <a class="<?= $pagina <= 1 ? 'inactivo' : '' ?>" href="<?= e(urlAuditoria(['pagina' => (string)max(1, $pagina - 1)])) ?>">
          <i class="fa-solid fa-chevron-left" aria-hidden="true"></i></a>
        <?php for ($i = max(1, $pagina - 2); $i <= min($paginas, $pagina + 2); $i++): ?>
          <?php if ($i === $pagina): ?><span class="actual"><?= $i ?></span>
          <?php else: ?><a href="<?= e(urlAuditoria(['pagina' => (string)$i])) ?>"><?= $i ?></a><?php endif; ?>
        <?php endfor; ?>
        <a class="<?= $pagina >= $paginas ? 'inactivo' : '' ?>" href="<?= e(urlAuditoria(['pagina' => (string)min($paginas, $pagina + 1)])) ?>">
          <i class="fa-solid fa-chevron-right" aria-hidden="true"></i></a>
      </nav>
    <?php endif; ?>
  <?php endif; ?>
</section>

<?php require __DIR__ . '/_pie.php'; ?>
