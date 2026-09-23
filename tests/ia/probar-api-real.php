<?php
/**
 * Prueba de humo contra la API real de Claude.
 *
 * Se ejecuta una sola vez en el servidor, después de poner AI_API_KEY en el
 * `.env`, para confirmar que la clave, el modelo y la red funcionan antes de
 * dejar el asistente abierto a los clientes:
 *
 *     php tests/ia/probar-api-real.php
 *
 * Hace dos llamadas cortas (cuestan céntimos):
 *   1. Una petición mínima, para comprobar clave, modelo y conexión.
 *   2. Una pregunta de catálogo a la asesora de la tienda, que obliga al
 *      modelo a usar la herramienta de búsqueda contra la base de datos.
 *
 * Solo lee: no crea pedidos, no toca carritos ni productos. No imprime la
 * clave ni la guarda en ningún sitio. Desde el navegador no se puede abrir
 * (la carpeta tests/ está cerrada en .htaccess y el script exige consola).
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../../includes/bootstrap.php';
foreach (['config', 'cliente', 'registro', 'agente', 'herramientas_cliente'] as $f) {
    require_once __DIR__ . '/../../includes/lib/ia/' . $f . '.php';
}

function linea(bool $ok, string $texto): void
{
    echo ($ok ? '  OK    ' : '  FALLA ') . $texto . "\n";
}

echo "\nFlowers Anto — prueba de la API de IA\n\n";

$fallos = 0;
linea(IaConfig::hayClave(), 'AI_API_KEY definida en el .env');
linea(IaConfig::tablasListas($pdo), 'Migración 022 aplicada (tablas ai_action_logs y ai_pending_actions)');
if (!IaConfig::hayClave() || !IaConfig::tablasListas($pdo)) {
    echo "\nCorrige lo anterior y vuelve a ejecutar.\n\n";
    exit(1);
}
echo "        Modelo tienda: " . IaConfig::modelo('cliente') . " · panel: " . IaConfig::modelo('admin')
   . " · tiempo máximo: " . IaConfig::tiempoMaximo() . " s\n\n";

// 1. Petición mínima.
$t = microtime(true);
try {
    $r = ClaudeCliente::mensajes([
        'model'      => IaConfig::modelo('cliente'),
        'max_tokens' => 20,
        'messages'   => [['role' => 'user', 'content' => 'Responde solo con la palabra: listo']],
    ], microtime(true) + IaConfig::tiempoMaximo());
    $texto = '';
    foreach ($r['content'] as $b) {
        if (($b['type'] ?? '') === 'text') {
            $texto .= $b['text'];
        }
    }
    linea(true, sprintf('Conexión con la API (%d ms) · modelo que respondió: %s · respuesta: «%s»',
        (microtime(true) - $t) * 1000, (string)($r['model'] ?? '?'), trim($texto)));
} catch (IaError $e) {
    $fallos++;
    linea(false, 'Conexión con la API: ' . $e->codigo . ' — ' . $e->getMessage());
    echo match ($e->codigo) {
        'config'  => "        La clave no es válida o no tiene permisos para ese modelo.\n",
        'red'     => "        El servidor no llega a api.anthropic.com (firewall o salida HTTPS bloqueada).\n",
        'tiempo'  => "        La API no respondió a tiempo; sube AI_TIMEOUT o reintenta.\n",
        'limite'  => "        La cuenta está limitando peticiones; revisa los límites en la consola de Anthropic.\n",
        'peticion'=> "        La API rechazó la petición; revisa AI_MODEL (nombre exacto del modelo).\n",
        default   => '',
    };
    echo "\n";
    exit(1);
}

// 2. La asesora usando una herramienta de verdad.
$t = microtime(true);
try {
    $r = IaAgente::responder('cliente', new IaHerramientasCliente($pdo), [],
        'Busco un arreglo para regalar. Recomiéndame dos que tengan disponibles, con su precio.');
    $usoHerramienta = in_array('buscar_productos', $r['herramientas'], true)
                   || in_array('listar_categorias', $r['herramientas'], true);
    linea($usoHerramienta, sprintf('La asesora consultó el catálogo (%d ms · herramientas: %s)',
        (microtime(true) - $t) * 1000, $r['herramientas'] ? implode(', ', array_unique($r['herramientas'])) : 'ninguna'));
    $fallos += $usoHerramienta ? 0 : 1;
    linea($r['estado'] !== 'error', 'Respuesta completa (estado: ' . $r['estado'] . ')');
    $fallos += $r['estado'] !== 'error' ? 0 : 1;
    echo sprintf("        Tokens: %d de entrada · %d de salida · %d leídos de caché\n",
        $r['uso']['entrada'], $r['uso']['salida'], $r['uso']['cache']);
    echo "\n  Respuesta de la asesora:\n\n    " . str_replace("\n", "\n    ", wordwrap($r['texto'], 90)) . "\n";
} catch (IaError $e) {
    $fallos++;
    linea(false, 'Asesora: ' . $e->codigo . ' — ' . $e->getMessage());
}

echo "\n" . ($fallos === 0
    ? "Todo en orden: el asistente puede quedar activo.\n\n"
    : "Hubo $fallos fallo(s). El resto de la tienda funciona igual; el asistente mostrará un aviso amable.\n\n");
exit($fallos === 0 ? 0 : 1);
