<?php
/**
 * Diagnóstico de la IA.
 *
 * Para hostings sin consola ni acceso al registro de PHP: dice qué
 * configuración está leyendo la tienda (sin la clave) y, con un botón, prueba
 * la conexión con el proveedor y muestra la causa exacta si falla. Hace lo
 * mismo que `tests/ia/probar-api-real.php`.
 *
 * Solo para quien puede ver la configuración; la prueba (que gasta 2 o 3
 * peticiones de la cuota del proveedor), solo para quien puede editarla.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
foreach (['config', 'cliente', 'registro', 'agente', 'herramientas_cliente'] as $f) {
    require_once __DIR__ . '/../includes/lib/ia/' . $f . '.php';
}

$tituloPanel    = 'Diagnóstico de la IA';
$subtituloPanel = 'Qué configuración lee la tienda y si el proveedor responde. La clave nunca se muestra.';
$seccion        = 'asistente';

Rbac::exigirPanel();
Rbac::exigir('configuracion.ver');

// ---------------------------------------------------------------------------
// Configuración leída (sin secretos)
// ---------------------------------------------------------------------------
$proveedor = IaConfig::proveedor();
$clave     = IaConfig::clave();
$avisos    = IaConfig::problemas();

$prefijos = ['google' => 'AIza', 'openrouter' => 'sk-or-', 'anthropic' => 'sk-ant-'];
$claveDe  = null;
foreach ($prefijos as $p => $prefijo) {
    if ($clave !== '' && str_starts_with($clave, $prefijo)) {
        $claveDe = $p;
    }
}
if ($claveDe !== null && in_array($proveedor, ['google', 'openrouter', 'anthropic'], true) && $claveDe !== $proveedor) {
    $avisos[] = "La clave de AI_API_KEY parece de {$claveDe}, pero AI_PROVEEDOR={$proveedor}.";
}
if ($clave === '' && trim(Entorno::texto('AI_API_KEY')) !== '') {
    $avisos[] = 'AI_API_KEY tiene espacios, saltos de línea u otros caracteres no válidos: vuelve a copiarla sola, sin nada más.';
}

$urlPeticion = IaConfig::urlPeticion();
$host = (string)parse_url($urlPeticion, PHP_URL_HOST);
$hostsEsperados = ['google' => 'generativelanguage.googleapis.com', 'openrouter' => 'openrouter.ai', 'anthropic' => 'api.anthropic.com'];
if (isset($hostsEsperados[$proveedor]) && $host !== $hostsEsperados[$proveedor] && !in_array($host, ['127.0.0.1', 'localhost'], true)) {
    $avisos[] = "AI_BASE_URL apunta a {$host}, pero AI_PROVEEDOR={$proveedor}. Déjala vacía o pon https://{$hostsEsperados[$proveedor]}.";
}
$baseCruda = rtrim(trim(Entorno::texto('AI_BASE_URL')), '/');
if ($baseCruda !== '' && $baseCruda !== IaConfig::urlBase()) {
    $avisos[] = 'AI_BASE_URL no es una dirección https válida; se está usando ' . IaConfig::urlBase() . '.';
}

// Variables repetidas en el .env: gana la última, y suele ser la que sobró
// del proveedor anterior.
$repetidas = [];
$archivoEnv = RAIZ . '/.env';
if (is_readable($archivoEnv)) {
    $vistas = [];
    foreach (preg_split('/\R/', (string)file_get_contents($archivoEnv)) ?: [] as $linea) {
        if (preg_match('/^\s*(AI_[A-Z_]+)\s*=/', $linea, $m)) {
            $vistas[$m[1]] = ($vistas[$m[1]] ?? 0) + 1;
        }
    }
    foreach ($vistas as $nombre => $veces) {
        if ($veces > 1) {
            $repetidas[] = $nombre;
            $avisos[] = "{$nombre} aparece {$veces} veces en el .env: se usa la última. Deja solo una.";
        }
    }
} else {
    $avisos[] = 'No se encuentra el archivo .env en la raíz del sitio (o no se puede leer).';
}
if (!IaConfig::tablasListas($pdo)) {
    $avisos[] = 'Falta la migración 022: aplícala en Administración → Base de datos.';
}

$formatoClave = $clave === ''
    ? 'Falta'
    : sprintf('Presente · %d caracteres%s', strlen($clave), $claveDe ? ' · formato de ' . $claveDe : '');

$config = [
    'Proveedor (AI_PROVEEDOR)'   => $proveedor !== '' ? $proveedor : 'no válido: «' . mb_substr(Entorno::texto('AI_PROVEEDOR'), 0, 30) . '»',
    'Dirección de la petición'   => $urlPeticion,
    'Modelo de la tienda'        => IaConfig::modelo('cliente') ?: '(falta o no es válido)',
    'Modelo del panel'           => IaConfig::modelo('admin') ?: '(falta o no es válido)',
    'Clave (AI_API_KEY)'         => $formatoClave,
    'Tiempo máximo (AI_TIMEOUT)' => IaConfig::tiempoMaximo() . ' s',
    'Tope diario interno'        => IaConfig::limiteDiario() ?: 'sin tope',
    'Asesora de la tienda'       => IaConfig::clienteActivo($pdo) ? 'Activa' : 'Apagada',
    'AI Manager del panel'       => IaConfig::adminActivo($pdo) ? 'Activo' : 'Apagado',
];

// ---------------------------------------------------------------------------
// Prueba de conexión
// ---------------------------------------------------------------------------
$resultados = [];
$errorPrueba = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirToken(false, 'admin/ia-diagnostico.php');
    Rbac::exigir('configuracion.editar');
    if (!limitar($pdo, 'ia-diagnostico:' . Auth::id(), 10, 600)) {
        $errorPrueba = 'Hiciste varias pruebas seguidas. Espera unos minutos.';
    } elseif ($clave === '' || $proveedor === '' || IaConfig::modelo('cliente') === '') {
        $errorPrueba = 'Primero corrige la configuración de arriba.';
    } else {
        IaSesion::soltar();
        $paso = function (string $nombre, callable $prueba) use (&$resultados): bool {
            $inicio = microtime(true);
            try {
                [$ok, $detalle] = $prueba();
            } catch (IaError $e) {
                $ok = false;
                $detalle = $e->codigo . ' · ' . (ClaudeCliente::ultimoDetalle() ?: $e->getMessage());
            } catch (Throwable $e) {
                $ok = false;
                $detalle = 'Error interno (' . get_class($e) . '). Revisa el registro del servidor.';
                error_log('Flowers Anto — diagnóstico IA: ' . $e->getMessage());
            }
            $resultados[] = ['nombre' => $nombre, 'ok' => $ok, 'detalle' => $detalle,
                             'ms' => (int)((microtime(true) - $inicio) * 1000)];
            return $ok;
        };

        $seguir = true;
        // 1. Con Google: el modelo existe y admite generateContent.
        if ($proveedor === 'google') {
            $seguir = $paso('El modelo existe en Google', function () use ($clave): array {
                $ch = curl_init(preg_replace('#:generateContent$#', '', IaConfig::urlPeticion()));
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 6,
                    CURLOPT_FOLLOWLOCATION => false, CURLOPT_HTTPHEADER => ['x-goog-api-key: ' . $clave],
                ]);
                $cuerpo = curl_exec($ch);
                $estado = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                if ($cuerpo === false) {
                    return [false, 'Sin conexión con generativelanguage.googleapis.com: ' . curl_error($ch)
                        . '. El hosting puede estar bloqueando la salida.'];
                }
                $datos = json_decode((string)$cuerpo, true) ?: [];
                if ($estado !== 200) {
                    $mensaje = str_replace($clave, '[clave]', mb_substr((string)($datos['error']['message'] ?? ''), 0, 200));
                    $motivo = (string)($datos['error']['details'][0]['reason'] ?? ($datos['error']['status'] ?? ''));
                    return [false, "HTTP {$estado} {$motivo}: {$mensaje}"];
                }
                $admite = in_array('generateContent', (array)($datos['supportedGenerationMethods'] ?? []), true);
                return [$admite, sprintf('%s (%s)%s', (string)($datos['name'] ?? ''), (string)($datos['displayName'] ?? ''),
                    $admite ? ' · admite generateContent' : ' · NO admite generateContent: elige otro modelo')];
            });
        }
        // 2. Un mensaje simple.
        if ($seguir) {
            $seguir = $paso('Respuesta simple del modelo', function (): array {
                $r = ClaudeCliente::mensajes([
                    'model'      => IaConfig::modelo('cliente'),
                    'max_tokens' => 200,
                    'messages'   => [['role' => 'user', 'content' => 'Responde solo con la palabra: listo']],
                ], microtime(true) + IaConfig::tiempoMaximo());
                $texto = '';
                foreach ($r['content'] as $b) {
                    $texto .= ($b['type'] ?? '') === 'text' ? $b['text'] : '';
                }
                return [trim($texto) !== '', sprintf('Respondió «%s»%s', mb_substr(trim($texto), 0, 80),
                    !empty($r['model']) ? ' · modelo ' . $r['model'] : '')];
            });
        }
        // 3. Una pregunta que obliga a usar las herramientas de la tienda.
        if ($seguir) {
            $paso('Massiel consulta el catálogo (herramientas)', function () use ($pdo): array {
                $r = IaAgente::responder('cliente', new IaHerramientasCliente($pdo), [],
                    '¿Qué arreglos tienen disponibles? Menciona dos.');
                $usadas = array_values(array_unique($r['herramientas']));
                $ok = (bool)array_intersect($usadas, ['buscar_productos', 'listar_categorias', 'consultar_promociones'])
                    && $r['estado'] !== 'error';
                return [$ok, sprintf('Herramientas: %s · estado: %s · «%s»%s',
                    $usadas ? implode(', ', $usadas) : 'ninguna', $r['estado'], mb_substr($r['texto'], 0, 140),
                    $ok ? '' : ' — si no usó herramientas, el modelo no las admite o las usa mal: prueba otro modelo')];
            });
        }
        IaSesion::retomar();
    }
}

// Últimos fallos registrados, con su causa.
$fallos = [];
if (IaConfig::tablasListas($pdo)) {
    $fallos = $pdo->query("SELECT created_at, agente, detalle, ms FROM ai_action_logs
                            WHERE estado = 'error' ORDER BY id DESC LIMIT 10")->fetchAll();
}

require __DIR__ . '/_cabecera.php';
?>

<?php foreach ($avisos as $a): ?>
  <div class="caja-aviso alerta"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i><span><?= e($a) ?></span></div>
<?php endforeach; ?>

<section class="panel">
  <div class="panel-cabecera"><div><h2>Configuración que lee la tienda</h2>
    <p>Del archivo <code>.env</code> del servidor. La clave no se muestra.</p></div></div>
  <div class="tabla-envoltura">
    <table class="tabla">
      <tbody>
        <?php foreach ($config as $nombre => $valor): ?>
          <tr><th scope="row"><?= e($nombre) ?></th><td><?= e((string)$valor) ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<section class="panel">
  <div class="panel-cabecera"><div><h2>Probar la conexión</h2>
    <p>Hace 2 o 3 peticiones al proveedor (cuentan para su cuota) y dice exactamente qué falla.</p></div></div>
  <div class="panel-cuerpo">
    <?php if ($errorPrueba !== ''): ?>
      <div class="caja-aviso error"><i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i><span><?= e($errorPrueba) ?></span></div>
    <?php endif; ?>
    <?php if ($resultados): ?>
      <div class="tabla-envoltura">
        <table class="tabla">
          <thead><tr><th>Prueba</th><th>Resultado</th><th>Detalle</th><th>Tiempo</th></tr></thead>
          <tbody>
            <?php foreach ($resultados as $r): ?>
              <tr>
                <td><?= e($r['nombre']) ?></td>
                <td><span class="estado-suave <?= $r['ok'] ? 'si' : 'mal' ?>"><?= $r['ok'] ? 'Correcto' : 'Falla' ?></span></td>
                <td><?= e($r['detalle']) ?></td>
                <td><?= number_format($r['ms'] / 1000, 1) ?> s</td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
    <?php if (Rbac::puede('configuracion.editar')): ?>
      <form method="post" action="<?= e(url('admin/ia-diagnostico.php')) ?>">
        <?= campoToken() ?>
        <button type="submit" class="boton boton-principal">
          <i class="fa-solid fa-plug-circle-check" aria-hidden="true"></i> Probar conexión ahora</button>
      </form>
    <?php else: ?>
      <p>Solo quien puede editar la configuración puede lanzar la prueba.</p>
    <?php endif; ?>
  </div>
</section>

<section class="panel">
  <div class="panel-cabecera"><div><h2>Últimos fallos de la IA</h2>
    <p>Con la causa que dio el proveedor (sin la clave).</p></div></div>
  <?php if (!$fallos): ?>
    <div class="panel-cuerpo"><p>No hay fallos registrados.</p></div>
  <?php else: ?>
    <div class="tabla-envoltura">
      <table class="tabla">
        <thead><tr><th>Fecha</th><th>Asistente</th><th>Causa</th><th>Tiempo</th></tr></thead>
        <tbody>
          <?php foreach ($fallos as $f): ?>
            <tr>
              <td><?= e(fecha_corta((string)$f['created_at'])) ?></td>
              <td><?= $f['agente'] === 'admin' ? 'Panel' : 'Tienda' ?></td>
              <td><?= e((string)($f['detalle'] ?? '')) ?></td>
              <td><?= $f['ms'] !== null ? number_format((int)$f['ms'] / 1000, 1) . ' s' : '—' ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<?php require __DIR__ . '/_pie.php'; ?>
