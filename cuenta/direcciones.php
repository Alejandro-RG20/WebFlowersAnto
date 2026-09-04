<?php
/**
 * Libreta de direcciones del cliente.
 *
 * Las direcciones se guardan solas al marcar la casilla en el checkout. Aquí
 * el cliente las corrige, cambia la predeterminada o borra las que ya no usa,
 * sin tener que esperar a su próximo pedido.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

Auth::exigirSesion('cuenta/direcciones.php');

$usuarioId = (int)Auth::id();
$errores   = [];
$editando  = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirToken(false, 'cuenta/direcciones.php');
    $accion = opcion('accion', ['guardar', 'eliminar', 'predeterminada'], 'guardar');

    if ($accion === 'eliminar') {
        $ok = Envios::borrarDireccion($pdo, $usuarioId, identificador('direccion_id'));
        flash($ok ? 'exito' : 'error', $ok ? 'Dirección eliminada.' : 'Esa dirección ya no existe.');
        redirigir('cuenta/direcciones.php');
    }

    if ($accion === 'predeterminada') {
        // Una sola predeterminada por cliente: se apagan todas y se enciende una.
        $id = identificador('direccion_id');
        $pdo->prepare("UPDATE direcciones_usuario SET predeterminada = 0 WHERE usuario_id = ?")
            ->execute([$usuarioId]);
        $st = $pdo->prepare(
            "UPDATE direcciones_usuario SET predeterminada = 1 WHERE id = ? AND usuario_id = ?"
        );
        $st->execute([$id, $usuarioId]);
        flash($st->rowCount() > 0 ? 'exito' : 'error',
              $st->rowCount() > 0 ? 'Esa será la dirección que aparezca primero.' : 'Esa dirección ya no existe.');
        redirigir('cuenta/direcciones.php');
    }

    // --- Guardar (alta o edición) ---------------------------------------
    $editando  = identificador('direccion_id');
    $direccion = texto('direccion', 255);
    $revision  = Envios::revisarEnlaceMapa(crudo('mapa_url'));

    if ($direccion === '') {
        $errores['direccion'] = 'Escribe la dirección.';
    }
    if (!$revision['ok']) {
        $errores['mapa_url'] = $revision['error'];
    }

    if (!$errores) {
        $datos = [
            texto('etiqueta', 60) ?: 'Mi dirección',
            texto('nombre_recibe', 120),
            telefonoValido('telefono'),
            $direccion,
            texto('referencia', 255),
            $revision['url'],
            identificador('zona_envio_id') ?: null,
        ];

        if ($editando > 0) {
            $datos[] = $editando;
            $datos[] = $usuarioId;
            $st = $pdo->prepare(
                "UPDATE direcciones_usuario
                    SET etiqueta = ?, nombre_recibe = ?, telefono = ?, direccion = ?,
                        referencia = ?, mapa_url = ?, zona_envio_id = ?
                  WHERE id = ? AND usuario_id = ?"
            );
            $st->execute($datos);
            flash('exito', 'Dirección actualizada.');
        } else {
            Envios::guardarDireccion($pdo, $usuarioId, [
                'etiqueta'      => $datos[0],
                'nombre_recibe' => $datos[1],
                'telefono'      => $datos[2],
                'direccion'     => $datos[3],
                'referencia'    => $datos[4],
                'mapa_url'      => $datos[5],
                'zona_envio_id' => (int)($datos[6] ?? 0),
            ]);
            flash('exito', 'Dirección guardada.');
        }
        redirigir('cuenta/direcciones.php');
    }
}

$direcciones = Envios::direcciones($pdo, $usuarioId);
$zonas       = Envios::zonas($pdo);

$tituloPagina  = 'Mis direcciones — ' . Ajustes::texto('nombre_tienda', 'Flowers Anto');
$paginaActiva  = 'cuenta';
$seccionCuenta = 'direcciones';

require __DIR__ . '/../includes/vistas/cabecera.php';
?>

<div class="container">
  <header class="pagina-cabecera">
    <h1>Mis direcciones</h1>
    <p>Guárdalas una vez y elígelas con un toque en tu próximo pedido.</p>
  </header>

  <?php require __DIR__ . '/../includes/vistas/aviso_verificar.php'; ?>

  <div class="diseno-cuenta">
    <?php require __DIR__ . '/../includes/vistas/menu_cuenta.php'; ?>

    <div>
      <?php if (!$direcciones): ?>
        <div class="tarjeta">
          <div class="estado-vacio">
            <i class="fa-solid fa-map-location-dot" aria-hidden="true"></i>
            <h3>Todavía no tienes direcciones guardadas</h3>
            <p>Añade una aquí, o marca «guardar esta dirección» al hacer tu próximo pedido.</p>
          </div>
        </div>
      <?php else: ?>
        <?php foreach ($direcciones as $d): ?>
          <div class="tarjeta">
            <div class="tarjeta-encabezado">
              <h2><?= e((string)$d['etiqueta']) ?>
                <?php if ((int)$d['predeterminada'] === 1): ?>
                  <span class="insignia insignia-disponible">Predeterminada</span>
                <?php endif; ?>
              </h2>
            </div>
            <p style="font-size:.93rem; color:var(--suave); line-height:1.7;">
              <?= e((string)$d['direccion']) ?>
              <?php if ($d['zona_nombre']): ?><br><?= e((string)$d['zona_nombre']) ?><?php endif; ?>
              <?php if ($d['referencia']): ?><br>Referencia: <?= e((string)$d['referencia']) ?><?php endif; ?>
              <?php if ($d['nombre_recibe']): ?><br>Recibe: <?= e((string)$d['nombre_recibe']) ?><?php endif; ?>
            </p>
            <?php if ($d['mapa_url']): ?>
              <p style="margin-top:8px; font-size:.9rem;">
                <a href="<?= e((string)$d['mapa_url']) ?>" target="_blank" rel="noopener noreferrer nofollow">
                  <i class="fa-solid fa-location-dot" aria-hidden="true"></i>
                  Ver en <?= e(Envios::servicioMapa((string)$d['mapa_url'])) ?></a>
              </p>
            <?php endif; ?>

            <div style="display:flex; gap:9px; flex-wrap:wrap; margin-top:14px;">
              <?php if ((int)$d['predeterminada'] !== 1): ?>
                <form method="post" action="<?= e(url('cuenta/direcciones.php')) ?>">
                  <?= campoToken() ?>
                  <input type="hidden" name="accion" value="predeterminada">
                  <input type="hidden" name="direccion_id" value="<?= (int)$d['id'] ?>">
                  <button type="submit" class="btn btn-outline-dark btn-sm">Usar por defecto</button>
                </form>
              <?php endif; ?>
              <form method="post" action="<?= e(url('cuenta/direcciones.php')) ?>"
                    data-confirmar="¿Eliminar la dirección «<?= e((string)$d['etiqueta']) ?>»?">
                <?= campoToken() ?>
                <input type="hidden" name="accion" value="eliminar">
                <input type="hidden" name="direccion_id" value="<?= (int)$d['id'] ?>">
                <button type="submit" class="btn btn-peligro btn-sm">Eliminar</button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>

      <div class="tarjeta">
        <div class="tarjeta-encabezado"><h2>Añadir una dirección</h2></div>

        <form method="post" action="<?= e(url('cuenta/direcciones.php')) ?>" novalidate data-una-vez>
          <?= campoToken() ?>
          <input type="hidden" name="accion" value="guardar">
          <input type="hidden" name="direccion_id" value="0">

          <div class="campo-fila">
            <div class="campo">
              <label for="etiqueta">¿Cómo la llamamos?</label>
              <input type="text" id="etiqueta" name="etiqueta" maxlength="60"
                     value="<?= e(texto('etiqueta', 60)) ?>" placeholder="Casa, Oficina, Casa de mamá…">
            </div>
            <?php if ($zonas): ?>
              <div class="campo">
                <label for="zona_envio_id">Zona de entrega</label>
                <select id="zona_envio_id" name="zona_envio_id">
                  <option value="0">Sin definir</option>
                  <?php foreach ($zonas as $z): ?>
                    <option value="<?= (int)$z['id'] ?>"
                            <?= identificador('zona_envio_id') === (int)$z['id'] ? 'selected' : '' ?>>
                      <?= e((string)$z['nombre']) ?> — <?= e(dinero($z['costo'])) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
            <?php endif; ?>
          </div>

          <div class="campo<?= isset($errores['direccion']) ? ' con-error' : '' ?>">
            <label for="direccion">Dirección *</label>
            <input type="text" id="direccion" name="direccion" maxlength="255" required
                   value="<?= e(texto('direccion', 255)) ?>"
                   placeholder="Barrio, calle, número de casa…">
            <?php if (isset($errores['direccion'])): ?>
              <p class="error-campo"><?= e($errores['direccion']) ?></p>
            <?php endif; ?>
          </div>

          <div class="campo">
            <label for="referencia">Punto de referencia</label>
            <input type="text" id="referencia" name="referencia" maxlength="255"
                   value="<?= e(texto('referencia', 255)) ?>" placeholder="Portón negro, frente a…">
          </div>

          <div class="campo<?= isset($errores['mapa_url']) ? ' con-error' : '' ?>">
            <label for="mapa_url">Ubicación en el mapa</label>
            <input type="text" id="mapa_url" name="mapa_url" maxlength="500"
                   value="<?= e(crudo('mapa_url')) ?>"
                   placeholder="https://maps.app.goo.gl/…  o  12.1364, -86.2514">
            <p class="ayuda">
              Enlace de Google Maps, Waze, Apple Maps u OpenStreetMap, o las coordenadas.
              Es lo que abre el repartidor para llegar sin llamarte.
            </p>
            <?php if (isset($errores['mapa_url'])): ?>
              <p class="error-campo"><?= e($errores['mapa_url']) ?></p>
            <?php endif; ?>
          </div>

          <div class="campo-fila">
            <div class="campo">
              <label for="nombre_recibe">¿Quién recibe?</label>
              <input type="text" id="nombre_recibe" name="nombre_recibe" maxlength="120"
                     value="<?= e(texto('nombre_recibe', 120)) ?>" placeholder="Déjalo vacío si lo recibes tú">
            </div>
            <div class="campo">
              <label for="telefono">Teléfono de quien recibe</label>
              <input type="tel" id="telefono" name="telefono" maxlength="20"
                     value="<?= e(texto('telefono', 20)) ?>">
            </div>
          </div>

          <button type="submit" class="btn btn-primary">Guardar dirección</button>
        </form>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../includes/vistas/pie.php'; ?>
