<?php
/**
 * Prueba de humo contra la API real del proveedor configurado.
 *
 * Se ejecuta en el servidor después de poner AI_API_KEY en el `.env`, para
 * confirmar que la clave, el proveedor, el modelo y la red funcionan antes de
 * dejar el asistente abierto a los clientes:
 *
 *     php tests/ia/probar-api-real.php
 *
 * Sirve con AI_PROVEEDOR=anthropic, openrouter y google. Con google, antes
 * de nada comprueba contra la API real que el modelo de AI_MODEL existe y
 * admite generateContent.
 * Hace cuatro comprobaciones, con unas 6 a 10 peticiones a la API en total
 * (con el plan gratuito de OpenRouter cuentan para su límite diario):
 *   1. Un mensaje simple: clave, modelo y conexión.
 *   2. «¿Cuánto cuesta la Gerbera?»: tiene que consultar el catálogo, y la
 *      guardia de precios no debe bloquear la respuesta.
 *   3. «¿Qué productos tienen disponibles?»: tiene que usar las herramientas.
 *   4. Añadir un producto al carrito. Es el carrito de este proceso de
 *      consola, que no se guarda en ningún sitio.
 *
 * Solo lee: no crea pedidos ni toca productos. No imprime la clave ni la
 * guarda en ningún sitio. Desde el navegador no se puede abrir (la carpeta
 * tests/ está cerrada en .htaccess y el script exige consola).
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

// Carrito de visitante solo en memoria de este proceso.
$_SESSION = [];

$fallos = 0;
function linea(bool $ok, string $texto): void
{
    global $fallos;
    $fallos += $ok ? 0 : 1;
    echo ($ok ? '  OK    ' : '  FALLA ') . $texto . "\n";
}

function explicar(IaError $e): void
{
    $destino = parse_url(IaConfig::urlPeticion(), PHP_URL_HOST) ?: 'la API';
    echo match ($e->codigo) {
        'config'    => "        La clave no es válida, no tiene permisos para ese modelo o la cuenta no tiene saldo/créditos.\n",
        'red'       => "        El servidor no llega a $destino (firewall o salida HTTPS bloqueada).\n",
        'tiempo'    => "        La API no respondió a tiempo; sube AI_TIMEOUT o reintenta.\n",
        'limite'    => "        El proveedor está limitando peticiones o se acabó la cuota (depende del plan, el modelo y el proyecto).\n",
        'sobrecarga'=> "        El proveedor o el modelo están caídos o saturados; prueba más tarde u otro modelo.\n",
        'peticion'  => "        La API rechazó la petición; revisa AI_MODEL (nombre exacto) y que el modelo admita herramientas.\n",
        default     => '',
    };
}

/** Pregunta a la asesora y muestra el resultado. */
function preguntar(PDO $pdo, string $pregunta, array $herramientasEsperadas): ?array
{
    $t = microtime(true);
    echo "\n  «{$pregunta}»\n";
    try {
        $r = IaAgente::responder('cliente', new IaHerramientasCliente($pdo), [], $pregunta);
    } catch (IaError $e) {
        linea(false, 'Error: ' . $e->codigo . ' — ' . $e->getMessage());
        explicar($e);
        return null;
    }
    $usadas = array_values(array_unique($r['herramientas']));
    linea((bool)array_intersect($herramientasEsperadas, $usadas), sprintf('Usó herramientas del sitio (%s) en %d ms',
        $usadas ? implode(', ', $usadas) : 'ninguna', (microtime(true) - $t) * 1000));
    echo sprintf("        Tokens: %d de entrada · %d de salida · %d de caché · estado: %s\n",
        $r['uso']['entrada'], $r['uso']['salida'], $r['uso']['cache'], $r['estado']);
    echo "        Respuesta: " . str_replace("\n", "\n                   ", wordwrap($r['texto'], 90)) . "\n";
    return $r;
}

echo "\nFlowers Anto — prueba de la API de IA\n\n";

$problemas = IaConfig::problemas();
if (!IaConfig::tablasListas($pdo)) {
    $problemas[] = 'Falta la migración 022 (tablas ai_action_logs y ai_pending_actions).';
}
linea(!$problemas, $problemas ? implode(' ', $problemas) : 'Configuración completa');
if ($problemas) {
    echo "\nCorrige lo anterior y vuelve a ejecutar.\n\n";
    exit(1);
}
echo "        Proveedor: " . IaConfig::proveedor() . " · " . IaConfig::urlPeticion() . "\n"
   . "        Modelo tienda: " . IaConfig::modelo('cliente') . " · panel: " . IaConfig::modelo('admin')
   . " · tiempo máximo: " . IaConfig::tiempoMaximo() . " s\n\n";

// 0. Con Google: el modelo existe y admite generateContent (consulta real).
if (IaConfig::proveedor() === 'google') {
    $url = preg_replace('#:generateContent$#', '', IaConfig::urlPeticion());
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => ['x-goog-api-key: ' . IaConfig::clave()],
    ]);
    $cuerpo = (string)curl_exec($ch);
    $estado = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $modelo = json_decode($cuerpo, true) ?: [];
    $admite = in_array('generateContent', (array)($modelo['supportedGenerationMethods'] ?? []), true);
    linea($estado === 200 && $admite, $estado === 200
        ? sprintf('0. Modelo %s (%s): %s', IaConfig::modelo('cliente'), (string)($modelo['displayName'] ?? '?'),
            $admite ? 'existe y admite generateContent' : 'existe pero NO admite generateContent')
        : sprintf('0. Consulta del modelo: HTTP %d — %s', $estado,
            str_replace(IaConfig::clave(), '[clave]', mb_substr((string)($modelo['error']['message'] ?? 'sin respuesta'), 0, 160))));
    if ($estado !== 200 || !$admite) {
        echo "        Revisa AI_API_KEY y AI_MODEL (modelos: https://ai.google.dev/gemini-api/docs/models).\n\n";
        exit(1);
    }
}

// 1. Mensaje simple.
$t = microtime(true);
try {
    $r = ClaudeCliente::mensajes([
        'model'      => IaConfig::modelo('cliente'),
        'max_tokens' => 200,
        'messages'   => [['role' => 'user', 'content' => 'Hola, responde brevemente confirmando que estás operativo.']],
    ], microtime(true) + IaConfig::tiempoMaximo());
    $texto = '';
    foreach ($r['content'] as $b) {
        if (($b['type'] ?? '') === 'text') {
            $texto .= $b['text'];
        }
    }
    linea(trim($texto) !== '', sprintf('1. Conexión (%d ms) · modelo que respondió: %s · «%s»',
        (microtime(true) - $t) * 1000, (string)($r['model'] ?? '?'), mb_substr(trim($texto), 0, 120)));
} catch (IaError $e) {
    linea(false, '1. Conexión: ' . $e->codigo . ' — ' . $e->getMessage());
    explicar($e);
    echo "\n";
    exit(1);
}

// 2. Precio: catálogo + guardia.
$r = preguntar($pdo, '¿Cuánto cuesta la Gerbera?', ['buscar_productos', 'ver_producto']);
if ($r) {
    linea($r['estado'] !== 'rechazada', '2. La guardia de precios no bloqueó una respuesta basada en el catálogo');
}

// 3. Disponibles.
$r = preguntar($pdo, '¿Qué productos tienen disponibles?', ['buscar_productos', 'listar_categorias', 'consultar_promociones']);

// 4. Carrito (en memoria).
$st = $pdo->query("SELECT nombre FROM productos WHERE activo = 1 ORDER BY id LIMIT 1");
$nombre = (string)$st->fetchColumn();
if ($nombre !== '') {
    $r = preguntar($pdo, "Agrega el {$nombre} al carrito, por favor.", ['agregar_al_carrito']);
    if ($r) {
        linea((int)($r['efectos']['carrito']['unidades'] ?? 0) > 0, '4. El producto quedó en el carrito (de este proceso de consola)');
    }
}

echo "\n" . ($fallos === 0
    ? "Todo en orden: el asistente puede quedar activo.\n\n"
    : "Hubo $fallos fallo(s). El resto de la tienda funciona igual; el asistente mostrará un aviso amable.\n"
      . "Si el modelo no usa herramientas, prueba otro que las admita (en OpenRouter: filtro «Tools»).\n\n");
exit($fallos === 0 ? 0 : 1);
