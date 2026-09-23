<?php
/**
 * AI Manager: el asistente del panel.
 *
 * Responde con datos reales del negocio y prepara cambios que una persona
 * confirma. La conversación la maneja `assets/js/admin-asistente.js` contra
 * `admin/asistente-api.php`; esta página solo pinta el marco, las
 * sugerencias y la actividad reciente.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/lib/ia/config.php';

$tituloPanel    = 'Asistente IA';
$subtituloPanel = 'Pregunta por ventas, pedidos, productos e inventario. Los cambios siempre los confirmas tú.';
$seccion        = 'asistente';

Rbac::exigirPanel();

$activo = IaConfig::adminActivo($pdo);

// Actividad reciente: lo que se confirmó o descartó. Cada persona ve lo suyo;
// quien tiene permiso de auditoría, lo de todo el equipo.
$actividad = [];
if ($activo) {
    $todos = Rbac::puede('auditoria.ver');
    $st = $pdo->prepare(
        "SELECT l.created_at, l.estado, l.detalle, u.nombre
           FROM ai_action_logs l LEFT JOIN usuarios u ON u.id = l.usuario_id
          WHERE l.agente = 'admin' AND l.accion IN ('confirmar', 'propuesta')
            AND l.estado IN ('confirmada', 'cancelada', 'error', 'denegado')"
        . ($todos ? '' : ' AND l.usuario_id = ?') .
        " ORDER BY l.id DESC LIMIT 8"
    );
    $st->execute($todos ? [] : [Auth::id()]);
    $actividad = $st->fetchAll();
}

$sugerencias = [
    '¿Cuántos pedidos tenemos pendientes?',
    '¿Cuánto vendimos esta semana?',
    '¿Qué productos se están vendiendo más este mes?',
    '¿Qué productos tienen poca disponibilidad?',
    'Muéstrame los pedidos de hoy',
    '¿Cuáles son los productos con menor movimiento?',
];

$jsPanel = ['assets/js/admin-asistente.js'];
require __DIR__ . '/_cabecera.php';
?>

<?php if (!$activo): ?>
  <section class="panel">
    <div class="vacio">
      <i class="fa-solid fa-plug-circle-xmark" aria-hidden="true"></i>
      <h3>El asistente no está configurado</h3>
      <p>Falta la clave de la API (<code>AI_API_KEY</code> en el archivo <code>.env</code> del servidor)
         o la migración 022 en <a href="<?= e(url('admin/base-datos.php')) ?>">Base de datos</a>.
         El resto del panel funciona igual sin él.</p>
    </div>
  </section>
<?php else: ?>
<div class="ia-rejilla">
  <section class="panel ia-conversacion" aria-labelledby="iaTitulo">
    <div class="panel-cabecera">
      <div>
        <h2 id="iaTitulo">Conversación</h2>
        <p>Datos del momento, calculados por la tienda. El asistente no cambia nada sin tu confirmación.</p>
      </div>
      <button type="button" class="boton boton-claro boton-mini" data-ia-reiniciar>
        <i class="fa-solid fa-rotate-left" aria-hidden="true"></i> Nueva conversación</button>
    </div>
    <div class="ia-hilo" data-ia-hilo role="log" aria-live="polite" aria-label="Conversación con el asistente" tabindex="0">
      <div class="ia-bienvenida" data-ia-bienvenida>
        <p>¿Qué quieres saber hoy? Algunas ideas:</p>
        <div class="ia-sugerencias">
          <?php foreach ($sugerencias as $s): ?>
            <button type="button" class="ia-chip" data-ia-sugerencia><?= e($s) ?></button>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <form class="ia-form" data-ia-form>
      <label for="iaTexto" class="visualmente-oculto">Pregunta al asistente</label>
      <textarea id="iaTexto" rows="1" maxlength="1500" data-ia-texto
                placeholder="Ej.: pon el Encanto Rosado al 20% de descuento"></textarea>
      <button type="submit" class="boton boton-principal" data-ia-enviar>
        <i class="fa-solid fa-paper-plane" aria-hidden="true"></i> Enviar</button>
    </form>
  </section>

  <aside class="ia-lateral">
    <section class="panel">
      <div class="panel-cabecera"><div><h2>Qué puede hacer</h2></div></div>
      <div class="panel-cuerpo ia-capacidades">
        <p><strong>Consultar</strong> ventas, pedidos, productos más y menos vendidos e inventario,
           según los permisos de tu usuario.</p>
        <p><strong>Preparar cambios</strong> de precio, descuento, stock, publicación, descripción o
           estado de un pedido. Verás el antes y el después y decides tú.</p>
        <p class="ia-limite"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
           No borra productos, no toca la configuración ni los usuarios.</p>
      </div>
    </section>
    <section class="panel">
      <div class="panel-cabecera"><div><h2>Actividad reciente</h2>
        <p><?= Rbac::puede('auditoria.ver') ? 'Cambios del equipo con el asistente' : 'Tus cambios con el asistente' ?></p></div></div>
      <div class="panel-cuerpo">
        <?php if (!$actividad): ?>
          <p class="ia-nada">Todavía no hay cambios confirmados.</p>
        <?php else: ?>
          <ul class="ia-actividad">
            <?php foreach ($actividad as $a): ?>
              <li>
                <span class="estado-suave <?= $a['estado'] === 'confirmada' ? 'si' : ($a['estado'] === 'cancelada' ? 'no' : 'mal') ?>">
                  <?= e(['confirmada' => 'Aplicado', 'cancelada' => 'Descartado', 'error' => 'Falló', 'denegado' => 'Sin permiso'][$a['estado']] ?? $a['estado']) ?></span>
                <span class="ia-actividad-texto"><?= e((string)($a['detalle'] ?? '')) ?></span>
                <small><?= e((string)($a['nombre'] ?? '')) ?> · <?= e(fecha_corta((string)$a['created_at'])) ?></small>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </section>
  </aside>
</div>
<?php endif; ?>

<?php require __DIR__ . '/_pie.php'; ?>
