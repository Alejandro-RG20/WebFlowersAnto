<?php
/**
 * Cupones de descuento.
 *
 * El código se guarda siempre en mayúsculas y sin adornos, igual que se
 * normaliza lo que escribe el cliente: así «bienvenida10», «BIENVENIDA-10» y
 * «Bienvenida 10» no acaban siendo tres cupones distintos.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

$seccion = 'cupones';
Rbac::exigirPanel();
Rbac::exigir('cupones.ver');

$editable = Rbac::puede('cupones.gestionar');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirToken(false, 'admin/cupones.php');
    Rbac::exigir('cupones.gestionar');

    $accion = opcion('accion', ['guardar', 'eliminar', 'activar'], 'guardar');
    $id     = identificador('cupon_id');

    if ($accion === 'eliminar') {
        $st = $pdo->prepare("SELECT codigo FROM cupones WHERE id = ?");
        $st->execute([$id]);
        $codigo = (string)$st->fetchColumn();

        $pdo->prepare("DELETE FROM cupones WHERE id = ?")->execute([$id]);
        Auditoria::registrar($pdo, 'eliminar', 'pedidos', [
            'recurso_tipo' => 'cupon', 'recurso_id' => (string)$id,
            'descripcion'  => 'Cupón eliminado: ' . $codigo,
        ]);
        flash('exito', 'Cupón eliminado. Los pedidos que ya lo usaron conservan su código.');
        redirigir('admin/cupones.php');
    }

    if ($accion === 'activar') {
        $st = $pdo->prepare("SELECT codigo, activo FROM cupones WHERE id = ?");
        $st->execute([$id]);
        $c = $st->fetch();
        if ($c) {
            $nuevo = (int)$c['activo'] === 1 ? 0 : 1;
            $pdo->prepare("UPDATE cupones SET activo = ? WHERE id = ?")->execute([$nuevo, $id]);
            Auditoria::registrar($pdo, 'editar', 'pedidos', [
                'recurso_tipo' => 'cupon', 'recurso_id' => (string)$id,
                'descripcion'  => ($nuevo ? 'Cupón activado: ' : 'Cupón desactivado: ') . $c['codigo'],
            ]);
            flash('exito', $nuevo ? 'Cupón activo otra vez.' : 'Cupón desactivado.');
        }
        redirigir('admin/cupones.php');
    }

    // --- Alta o edición --------------------------------------------------
    $codigo = Cupones::limpiar(crudo('codigo'));
    $tipo   = opcion('tipo', array_keys(Cupones::tipos()), 'porcentaje');
    $valor  = decimal('valor');

    if (mb_strlen($codigo) < 3) {
        flash('error', 'El código necesita al menos 3 caracteres. Solo letras, números, guion y guion bajo.');
        redirigir('admin/cupones.php');
    }
    if ($tipo === 'porcentaje' && ($valor <= 0 || $valor > 100)) {
        flash('error', 'Un porcentaje tiene que estar entre 1 y 100.');
        redirigir('admin/cupones.php');
    }
    if ($tipo === 'fijo' && $valor <= 0) {
        flash('error', 'Escribe el importe que rebaja el cupón.');
        redirigir('admin/cupones.php');
    }

    $desde = fechaOpcional('fecha_inicio');
    $hasta = fechaOpcional('fecha_fin');
    if ($desde && $hasta && $hasta < $desde) {
        flash('error', 'La fecha de fin no puede ser anterior a la de inicio.');
        redirigir('admin/cupones.php');
    }

    $datos = [
        'codigo'           => $codigo,
        'descripcion'      => texto('descripcion', 255),
        'tipo'             => $tipo,
        // El envío gratis no lleva importe: lo pone el costo de la zona.
        'valor'            => $tipo === 'envio_gratis' ? 0 : $valor,
        'compra_minima'    => decimal('compra_minima'),
        'descuento_maximo' => $tipo === 'porcentaje' ? decimal('descuento_maximo') : 0,
        'usos_maximos'     => entero('usos_maximos', 0, 999999, 0),
        'usos_por_cliente' => entero('usos_por_cliente', 0, 999, 1),
        'fecha_inicio'     => $desde,
        'fecha_fin'        => $hasta,
        'activo'           => casilla('activo'),
    ];

    // Las columnas salen de las claves: añadir un campo arriba no puede
    // desalinear los valores con los marcadores.
    $columnas = array_keys($datos);
    $valores  = array_values($datos);

    try {
        if ($id > 0) {
            $valores[] = $id;
            $pdo->prepare(
                'UPDATE cupones SET '
                . implode(', ', array_map(static fn(string $c): string => "`$c` = ?", $columnas))
                . ' WHERE id = ?'
            )->execute($valores);
        } else {
            $pdo->prepare(
                'INSERT INTO cupones (`' . implode('`, `', $columnas) . '`) VALUES ('
                . implode(',', array_fill(0, count($columnas), '?')) . ')'
            )->execute($valores);
            $id = (int)$pdo->lastInsertId();
        }
    } catch (PDOException) {
        flash('error', 'Ya existe un cupón con el código ' . $codigo . '.');
        redirigir('admin/cupones.php');
    }

    Auditoria::registrar($pdo, 'editar', 'pedidos', [
        'recurso_tipo' => 'cupon', 'recurso_id' => (string)$id,
        'descripcion'  => 'Cupón guardado: ' . $codigo,
    ]);
    flash('exito', 'Cupón guardado.');
    redirigir('admin/cupones.php');
}

// Los canjes y lo descontado salen de una sola consulta agrupada, no de una
// por cupón.
$cupones = $pdo->query(
    "SELECT c.*,
            COUNT(u.id)                 AS canjes,
            COALESCE(SUM(u.descuento),0) AS descontado,
            MAX(u.created_at)           AS ultimo
       FROM cupones c
  LEFT JOIN cupon_usos u ON u.cupon_id = c.id
   GROUP BY c.id
   ORDER BY c.activo DESC, c.created_at DESC"
)->fetchAll();

$tituloPanel    = 'Cupones';
$subtituloPanel = 'Códigos de descuento para tus clientes';

require __DIR__ . '/_cabecera.php';
?>

<?php if (!$editable): ?>
  <div class="caja-aviso alerta">
    <i class="fa-solid fa-lock" aria-hidden="true"></i>
    <span>Puedes consultar los cupones, pero tu rol no permite modificarlos.</span>
  </div>
<?php endif; ?>

<?php if (!Cupones::activos()): ?>
  <div class="caja-aviso alerta">
    <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
    <span>El campo de cupones está <strong>apagado</strong> en el checkout: los clientes no
      pueden usar ninguno de estos códigos. Se enciende en
      <a href="<?= e(url('admin/configuracion.php?t=pedidos')) ?>">Configuración → Pedidos</a>.</span>
  </div>
<?php endif; ?>

<section class="panel">
  <div class="panel-cabecera">
    <div><h2>Cupones</h2><p><?= count($cupones) ?> registrados</p></div>
    <?php if ($editable): ?>
      <button type="button" class="boton boton-principal boton-mini" data-abrir-modal="modalCupon"
              data-campo-cupon_id="0" data-campo-codigo="" data-campo-descripcion=""
              data-campo-tipo="porcentaje" data-campo-valor="10" data-campo-compra_minima="0"
              data-campo-descuento_maximo="0" data-campo-usos_maximos="0"
              data-campo-usos_por_cliente="1" data-campo-fecha_inicio="" data-campo-fecha_fin=""
              data-campo-activo="1">
        <i class="fa-solid fa-plus" aria-hidden="true"></i> Crear cupón</button>
    <?php endif; ?>
  </div>

  <?php if (!$cupones): ?>
    <div class="vacio">
      <i class="fa-solid fa-ticket" aria-hidden="true"></i>
      <h3>Todavía no hay cupones</h3>
      <p>Crea uno y el cliente podrá escribir su código al confirmar el pedido.
         Sirven para campañas, para clientes que reclaman y para el boca a boca.</p>
    </div>
  <?php else: ?>
    <div class="tabla-envoltura">
      <table class="tabla">
        <thead><tr>
          <th>Código</th><th>Descuento</th><th>Condiciones</th>
          <th>Vigencia</th><th class="num">Canjes</th><th>Estado</th><th></th>
        </tr></thead>
        <tbody>
          <?php foreach ($cupones as $c):
              $agotado = (int)$c['usos_maximos'] > 0 && (int)$c['usos'] >= (int)$c['usos_maximos'];
              $vencido = $c['fecha_fin'] && $c['fecha_fin'] < date('Y-m-d');
          ?>
            <tr>
              <td class="celda-principal">
                <code style="font-size:.95rem; letter-spacing:.05em;"><?= e((string)$c['codigo']) ?></code>
                <?php if ((string)$c['descripcion'] !== ''): ?>
                  <br><span class="celda-sub"><?= e((string)$c['descripcion']) ?></span>
                <?php endif; ?>
              </td>
              <td><?= e(Cupones::resumen($c)) ?>
                <?php if ((float)$c['descuento_maximo'] > 0): ?>
                  <br><span class="celda-sub">hasta <?= e(dinero($c['descuento_maximo'])) ?></span>
                <?php endif; ?>
              </td>
              <td class="celda-sub">
                <?= (float)$c['compra_minima'] > 0
                      ? 'Compra mínima ' . e(dinero($c['compra_minima'])) : 'Sin mínimo' ?><br>
                <?= (int)$c['usos_por_cliente'] > 0
                      ? (int)$c['usos_por_cliente'] . ' por cliente' : 'Sin límite por cliente' ?>
                <?= (int)$c['usos_maximos'] > 0
                      ? ' · ' . (int)$c['usos'] . '/' . (int)$c['usos_maximos'] . ' usos' : '' ?>
              </td>
              <td class="celda-sub">
                <?= $c['fecha_inicio'] ? e(fecha_corta((string)$c['fecha_inicio'])) : 'sin inicio' ?>
                → <?= $c['fecha_fin'] ? e(fecha_corta((string)$c['fecha_fin'])) : 'sin fin' ?>
              </td>
              <td class="num">
                <?= (int)$c['canjes'] ?>
                <?php if ((float)$c['descontado'] > 0): ?>
                  <br><span class="celda-sub"><?= e(dinero($c['descontado'])) ?></span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ((int)$c['activo'] !== 1): ?>
                  <span class="estado-suave no">Inactivo</span>
                <?php elseif ($vencido): ?>
                  <span class="estado-suave no">Vencido</span>
                <?php elseif ($agotado): ?>
                  <span class="estado-suave no">Agotado</span>
                <?php else: ?>
                  <span class="estado-suave si">Activo</span>
                <?php endif; ?>
              </td>
              <td class="acciones">
                <?php if ($editable): ?>
                  <div style="display:inline-flex; gap:5px;">
                    <button type="button" class="boton-icono" data-abrir-modal="modalCupon"
                            data-campo-cupon_id="<?= (int)$c['id'] ?>"
                            data-campo-codigo="<?= e((string)$c['codigo']) ?>"
                            data-campo-descripcion="<?= e((string)$c['descripcion']) ?>"
                            data-campo-tipo="<?= e((string)$c['tipo']) ?>"
                            data-campo-valor="<?= e(number_format((float)$c['valor'], 2, '.', '')) ?>"
                            data-campo-compra_minima="<?= e(number_format((float)$c['compra_minima'], 2, '.', '')) ?>"
                            data-campo-descuento_maximo="<?= e(number_format((float)$c['descuento_maximo'], 2, '.', '')) ?>"
                            data-campo-usos_maximos="<?= (int)$c['usos_maximos'] ?>"
                            data-campo-usos_por_cliente="<?= (int)$c['usos_por_cliente'] ?>"
                            data-campo-fecha_inicio="<?= e((string)$c['fecha_inicio']) ?>"
                            data-campo-fecha_fin="<?= e((string)$c['fecha_fin']) ?>"
                            data-campo-activo="<?= (int)$c['activo'] ?>"
                            aria-label="Editar cupón"><i class="fa-solid fa-pen" aria-hidden="true"></i></button>

                    <form method="post" action="<?= e(url('admin/cupones.php')) ?>">
                      <?= campoToken() ?>
                      <input type="hidden" name="accion" value="activar">
                      <input type="hidden" name="cupon_id" value="<?= (int)$c['id'] ?>">
                      <button type="submit" class="boton-icono"
                              aria-label="<?= (int)$c['activo'] ? 'Desactivar' : 'Activar' ?>">
                        <i class="fa-solid <?= (int)$c['activo'] ? 'fa-eye-slash' : 'fa-eye' ?>" aria-hidden="true"></i>
                      </button>
                    </form>

                    <form method="post" action="<?= e(url('admin/cupones.php')) ?>"
                          data-confirmar="¿Eliminar el cupón <?= e((string)$c['codigo']) ?>? Los pedidos que ya lo usaron conservan su código.">
                      <?= campoToken() ?>
                      <input type="hidden" name="accion" value="eliminar">
                      <input type="hidden" name="cupon_id" value="<?= (int)$c['id'] ?>">
                      <button type="submit" class="boton-icono peligro" aria-label="Eliminar cupón">
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

<?php if ($editable): ?>
  <dialog class="modal" id="modalCupon">
    <form method="post" action="<?= e(url('admin/cupones.php')) ?>">
      <?= campoToken() ?>
      <input type="hidden" name="accion" value="guardar">
      <input type="hidden" name="cupon_id" value="0">
      <div class="modal-cabecera"><h2>Cupón de descuento</h2></div>
      <div class="modal-cuerpo">
        <div class="rejilla-campos dos">
          <div class="campo">
            <label for="cp_codigo">Código *</label>
            <input type="text" id="cp_codigo" name="codigo" required maxlength="40"
                   style="text-transform:uppercase; letter-spacing:.05em;"
                   placeholder="BIENVENIDA10">
            <p class="ayuda">Letras, números, guion y guion bajo. Da igual cómo lo escriba el cliente.</p>
          </div>
          <div class="campo">
            <label for="cp_tipo">Tipo de descuento</label>
            <select id="cp_tipo" name="tipo">
              <?php foreach (Cupones::tipos() as $clave => $nombre): ?>
                <option value="<?= e($clave) ?>"><?= e($nombre) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="campo">
          <label for="cp_descripcion">Para qué es</label>
          <input type="text" id="cp_descripcion" name="descripcion" maxlength="255"
                 placeholder="Campaña del Día de las Madres">
          <p class="ayuda">Solo lo ves tú en esta lista. El cliente no lo ve.</p>
        </div>

        <div class="rejilla-campos dos">
          <div class="campo">
            <label for="cp_valor">Valor</label>
            <input type="number" id="cp_valor" name="valor" step="0.01" min="0" value="10">
            <p class="ayuda">Porcentaje (1 a 100) o importe. En «envío gratis» no se usa.</p>
          </div>
          <div class="campo">
            <label for="cp_maximo">Descuento máximo</label>
            <input type="number" id="cp_maximo" name="descuento_maximo" step="0.01" min="0" value="0">
            <p class="ayuda">Tope para los porcentajes: «20 % hasta C$300». 0 = sin tope.</p>
          </div>
        </div>

        <div class="rejilla-campos tres">
          <div class="campo">
            <label for="cp_minima">Compra mínima</label>
            <input type="number" id="cp_minima" name="compra_minima" step="0.01" min="0" value="0">
            <p class="ayuda">0 = sin mínimo.</p>
          </div>
          <div class="campo">
            <label for="cp_usos">Usos totales</label>
            <input type="number" id="cp_usos" name="usos_maximos" min="0" max="999999" value="0">
            <p class="ayuda">0 = ilimitado.</p>
          </div>
          <div class="campo">
            <label for="cp_porcliente">Por cliente</label>
            <input type="number" id="cp_porcliente" name="usos_por_cliente" min="0" max="999" value="1">
            <p class="ayuda">0 = sin límite.</p>
          </div>
        </div>

        <div class="rejilla-campos dos">
          <div class="campo">
            <label for="cp_desde">Desde</label>
            <input type="date" id="cp_desde" name="fecha_inicio">
          </div>
          <div class="campo">
            <label for="cp_hasta">Hasta</label>
            <input type="date" id="cp_hasta" name="fecha_fin">
          </div>
        </div>

        <div class="interruptor">
          <input type="checkbox" id="cp_activo" name="activo" value="1" checked>
          <label for="cp_activo">Cupón activo
            <small>Desactivarlo lo deja de aceptar sin borrar su historial de canjes.</small></label>
        </div>
      </div>
      <div class="modal-pie">
        <button type="button" class="boton boton-claro" data-cerrar-modal>Cancelar</button>
        <button type="submit" class="boton boton-principal">Guardar cupón</button>
      </div>
    </form>
  </dialog>
<?php endif; ?>

<?php require __DIR__ . '/_pie.php'; ?>
