<?php
/**
 * Cliente HTTP de los proveedores de IA.
 *
 * El resto de la capa de IA habla un solo idioma: el formato de la Messages
 * API de Anthropic (bloques `text`, `tool_use` y `tool_result`, `stop_reason`,
 * `usage`). Esta clase es la única que sabe a quién se le pregunta:
 *
 *   anthropic             → se envía tal cual a /v1/messages.
 *   openrouter / openai   → se traduce a Chat Completions (/chat/completions)
 *                           y la respuesta se traduce de vuelta.
 *   google                → se traduce a Gemini (generateContent: contents,
 *                           parts, functionCall/functionResponse) y de vuelta.
 *
 * Así el agente, las herramientas, la guardia de precios y el historial de la
 * sesión no cambian con el proveedor: el historial siempre queda en el mismo
 * formato y se puede cambiar de proveedor sin romper conversaciones.
 *
 * Es HTTP directo con la extensión cURL de PHP, la misma que ya usa el cobro
 * con PayPal. Los SDK oficiales exigen Composer y un cliente HTTP PSR-18 con
 * su cadena de dependencias, y este proyecto no tiene ninguna: se despliega
 * subiendo archivos a un hosting compartido.
 *
 * Lo que garantiza, con cualquier proveedor:
 *
 *   · La clave solo sale en su cabecera (`x-api-key`, `Authorization` o
 *     `x-goog-api-key`; nunca en la URL) y
 *     nunca se registra; si apareciera en un mensaje de error, se tapa.
 *   · Tiempo máximo por llamada, con un reintento para 429 y 5xx si queda
 *     margen dentro del presupuesto de tiempo.
 *   · Los errores salen como `IaError` con un código corto; el detalle
 *     técnico va al registro del servidor, nunca a la respuesta al cliente.
 *   · No se registra el cuerpo de la petición: lleva lo que escribió el
 *     cliente, que puede incluir datos personales.
 */

declare(strict_types=1);

final class IaError extends RuntimeException
{
    /**
     * @param string $codigo config | red | tiempo | limite | sobrecarga | peticion | respuesta
     */
    public function __construct(public readonly string $codigo, string $detalle = '')
    {
        parent::__construct($detalle !== '' ? $detalle : $codigo);
    }
}

final class ClaudeCliente
{
    private const VERSION_API = '2023-06-01';

    /** Beta de los modelos de respaldo por categoría de rechazo. */
    private const BETA_RESPALDO = 'server-side-fallback-2026-07-01';

    /**
     * Causa del último fallo, tal como va al registro del servidor (sin la
     * clave). La usan el registro de IA y la página de diagnóstico del panel
     * para decir exactamente qué pasó, en hostings sin acceso al log de PHP.
     */
    private static string $ultimoDetalle = '';

    public static function ultimoDetalle(): string
    {
        return self::$ultimoDetalle;
    }

    /** Anota la causa de un fallo y la manda al registro del servidor. */
    private static function anotar(string $linea): void
    {
        self::$ultimoDetalle = mb_substr(trim($linea), 0, 400);
        error_log('Flowers Anto — IA' . $linea);
    }

    /**
     * Envía una petición al proveedor configurado y devuelve la respuesta en
     * el formato de la Messages API (content, stop_reason, usage).
     *
     * @param array $cuerpo  Petición en formato Messages (model, max_tokens, system, tools, messages…).
     * @param float $limite  Momento (microtime) en que se acaba el tiempo.
     */
    public static function mensajes(array $cuerpo, float $limite): array
    {
        if (!function_exists('curl_init')) {
            throw new IaError('config', 'La extensión cURL de PHP no está disponible.');
        }
        self::$ultimoDetalle = '';
        $clave = IaConfig::clave();
        if ($clave === '') {
            self::$ultimoDetalle = 'Falta AI_API_KEY o tiene caracteres no válidos.';
            throw new IaError('config', 'Falta AI_API_KEY.');
        }
        return match (IaConfig::proveedor()) {
            'anthropic'            => self::anthropic($cuerpo, $limite, $clave),
            'openrouter', 'openai' => self::compatibleOpenAi($cuerpo, $limite, $clave),
            'google'               => self::google($cuerpo, $limite, $clave),
            default                => throw new IaError('config', 'AI_PROVEEDOR no es válido.'),
        };
    }

    // =====================================================================
    // Anthropic
    // =====================================================================

    private static function anthropic(array $cuerpo, float $limite, string $clave): array
    {
        $cuerpo = self::prepararAnthropic($cuerpo);

        $betas = [];
        // `fallbacks: "default"`: si los filtros de seguridad del modelo
        // rechazan la petición, la API la repite en otro modelo adecuado en
        // la misma llamada, en vez de devolver el rechazo. Solo se pide en el
        // modelo para el que está documentado; en otro sería un error 400.
        if (($cuerpo['model'] ?? '') === 'claude-opus-5') {
            $cuerpo['fallbacks'] = 'default';
            $betas[] = self::BETA_RESPALDO;
        }

        $intentos = 0;
        while (true) {
            $intentos++;
            $quedan = $limite - microtime(true);
            if ($quedan < 3) {
                throw new IaError('tiempo', 'Sin tiempo para llamar a la API.');
            }
            [$estado, $respuesta, $reintentarEn, $errorCurl] =
                self::post(IaConfig::urlPeticion(), self::cabecerasAnthropic($clave, $betas), $cuerpo, $quedan);

            if ($errorCurl !== '') {
                self::$ultimoDetalle = 'Sin conexión con ' . (parse_url(IaConfig::urlPeticion(), PHP_URL_HOST) ?: 'la API')
                    . ': ' . mb_substr($errorCurl, 0, 200);
                throw new IaError(str_contains($errorCurl, 'timed out') ? 'tiempo' : 'red', $errorCurl);
            }
            if ($estado === 200) {
                $datos = json_decode($respuesta, true);
                if (!is_array($datos) || !isset($datos['content']) || !is_array($datos['content'])) {
                    throw new IaError('respuesta', 'Respuesta sin contenido reconocible.');
                }
                return $datos;
            }

            $error = json_decode($respuesta, true)['error'] ?? [];
            $tipo  = is_array($error) ? (string)($error['type'] ?? '') : '';
            $texto = is_array($error) ? self::tapar(mb_substr((string)($error['message'] ?? ''), 0, 200), $clave) : '';

            // Si algún día la API no acepta el respaldo para este modelo, no
            // se deja al asistente sin servicio: se repite sin él.
            if ($estado === 400 && isset($cuerpo['fallbacks']) && stripos($texto, 'fallback') !== false) {
                unset($cuerpo['fallbacks']);
                $betas = [];
                continue;
            }

            $reintentable = in_array($estado, [429, 500, 502, 503, 529], true);
            $espera = min(3.0, max(0.5, $reintentarEn));
            if ($reintentable && $intentos < 2 && ($limite - microtime(true)) > $espera + 5) {
                usleep((int)($espera * 1_000_000));
                continue;
            }

            self::anotar(sprintf(': HTTP %d %s %s', $estado, $tipo, $texto));
            throw new IaError(match (true) {
                $estado === 401, $estado === 403 => 'config',
                $estado === 429                  => 'limite',
                $estado >= 500                   => 'sobrecarga',
                default                          => 'peticion',
            }, "HTTP $estado $tipo");
        }
    }

    /** @return string[] */
    private static function cabecerasAnthropic(string $clave, array $betas): array
    {
        $cabeceras = [
            'content-type: application/json',
            'anthropic-version: ' . self::VERSION_API,
            'x-api-key: ' . $clave,
        ];
        if ($betas) {
            $cabeceras[] = 'anthropic-beta: ' . implode(',', $betas);
        }
        return $cabeceras;
    }

    /**
     * Deja el historial como lo exige la Messages API antes de enviarlo.
     *
     *   · `input` de un tool_use siempre como objeto. PHP decodifica `{}` como
     *     un array vacío y lo volvería a codificar como `[]`, que la API
     *     rechaza: pasaba con cualquier herramienta sin argumentos.
     *   · Sin las marcas internas `entrada_invalida` (ver compatibleOpenAi) ni
     *     `firma_google` (ver google), por si la conversación empezó con otro
     *     proveedor.
     *   · Identificadores de herramienta con los caracteres que admite la API,
     *     por si la conversación empezó con otro proveedor. El cambio es el
     *     mismo en el tool_use y en su tool_result, así que siguen emparejados.
     */
    private static function prepararAnthropic(array $cuerpo): array
    {
        $id = static fn($v): string => preg_replace('/[^A-Za-z0-9_-]/', '_', (string)$v) ?: 'id';
        foreach ($cuerpo['messages'] ?? [] as $i => $m) {
            if (!is_array($m) || !is_array($m['content'] ?? null)) {
                continue;
            }
            foreach ($m['content'] as $j => $b) {
                $tipo = is_array($b) ? ($b['type'] ?? '') : '';
                if ($tipo === 'text' && isset($b['firma_google'])) {
                    unset($cuerpo['messages'][$i]['content'][$j]['firma_google']);
                } elseif ($tipo === 'tool_use') {
                    unset($b['entrada_invalida'], $b['firma_google']);
                    if (!is_array($b['input'] ?? null) || $b['input'] === [] || array_is_list($b['input'])) {
                        $b['input'] = new stdClass();
                    }
                    $b['id'] = $id($b['id'] ?? '');
                    $cuerpo['messages'][$i]['content'][$j] = $b;
                } elseif ($tipo === 'tool_result') {
                    $cuerpo['messages'][$i]['content'][$j]['tool_use_id'] = $id($b['tool_use_id'] ?? '');
                }
            }
        }
        return $cuerpo;
    }

    // =====================================================================
    // Compatible con OpenAI (OpenRouter)
    // =====================================================================

    private static function compatibleOpenAi(array $cuerpo, float $limite, string $clave): array
    {
        $peticion  = self::aChatCompletions($cuerpo);
        $cabeceras = self::cabecerasOpenAi($clave);

        $intentos = 0;
        while (true) {
            $intentos++;
            $quedan = $limite - microtime(true);
            if ($quedan < 3) {
                throw new IaError('tiempo', 'Sin tiempo para llamar a la API.');
            }
            [$estado, $respuesta, $reintentarEn, $errorCurl] =
                self::post(IaConfig::urlPeticion(), $cabeceras, $peticion, $quedan);

            if ($errorCurl !== '') {
                self::$ultimoDetalle = 'Sin conexión con ' . (parse_url(IaConfig::urlPeticion(), PHP_URL_HOST) ?: 'la API')
                    . ': ' . mb_substr($errorCurl, 0, 200);
                throw new IaError(str_contains($errorCurl, 'timed out') ? 'tiempo' : 'red', $errorCurl);
            }

            $datos = json_decode($respuesta, true);
            // OpenRouter puede contestar 200 con un error del proveedor final
            // en el cuerpo y sin `choices`: se trata como el error que es.
            if ($estado === 200 && is_array($datos) && isset($datos['error']) && !isset($datos['choices'])) {
                $codigo = is_numeric($datos['error']['code'] ?? null) ? (int)$datos['error']['code'] : 0;
                $estado = $codigo >= 400 && $codigo <= 599 ? $codigo : 502;
            }
            if ($estado === 200) {
                return self::desdeChatCompletions(is_array($datos) ? $datos : []);
            }

            $error = is_array($datos) && is_array($datos['error'] ?? null) ? $datos['error'] : [];
            $codigo = (string)($error['code'] ?? ($error['type'] ?? ''));
            $texto  = self::tapar(mb_substr((string)($error['message'] ?? ''), 0, 200), $clave);

            $reintentable = in_array($estado, [429, 500, 502, 503, 529], true);
            $espera = min(3.0, max(0.5, $reintentarEn));
            if ($reintentable && $intentos < 2 && ($limite - microtime(true)) > $espera + 5) {
                usleep((int)($espera * 1_000_000));
                continue;
            }

            self::anotar(sprintf(' (%s): HTTP %d %s %s%s', IaConfig::proveedor(), $estado,
                mb_substr(preg_replace('/[^\w.-]/u', '', $codigo) ?? '', 0, 40), $texto,
                $estado === 402 ? ' — la cuenta del proveedor no tiene saldo o créditos suficientes' : ''));
            throw new IaError(match (true) {
                // 402: sin saldo o créditos. Es un problema de la cuenta, no
                // de la petición: se trata como configuración.
                $estado === 401, $estado === 402, $estado === 403 => 'config',
                $estado === 408                                   => 'tiempo',
                $estado === 429                                   => 'limite',
                $estado >= 500                                    => 'sobrecarga',
                default                                           => 'peticion',
            }, "HTTP $estado");
        }
    }

    /** @return string[] */
    private static function cabecerasOpenAi(string $clave): array
    {
        $cabeceras = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $clave,
        ];
        // Opcionales: con ellas OpenRouter muestra qué aplicación hace las
        // peticiones. Son datos públicos y ya vienen validados sin saltos de línea.
        $app = IaConfig::identificacionApp();
        if ($app['url'] !== '') {
            $cabeceras[] = 'HTTP-Referer: ' . $app['url'];
        }
        if ($app['nombre'] !== '') {
            $cabeceras[] = 'X-Title: ' . $app['nombre'];
        }
        return $cabeceras;
    }

    /**
     * Petición Messages → Chat Completions.
     *
     * Solo se copian los campos que existen en Chat Completions: model,
     * max_tokens, messages y tools. Lo exclusivo de Anthropic (cache_control,
     * output_config, fallbacks, bloques de razonamiento) no se envía.
     */
    private static function aChatCompletions(array $cuerpo): array
    {
        $mensajes = [];

        // Instrucciones del sistema: completas, como primer mensaje.
        $sistema = $cuerpo['system'] ?? '';
        if (is_array($sistema)) {
            $sistema = self::textoDeBloques($sistema);
        }
        if (is_string($sistema) && $sistema !== '') {
            $mensajes[] = ['role' => 'system', 'content' => $sistema];
        }

        foreach ($cuerpo['messages'] ?? [] as $m) {
            if (!is_array($m)) {
                continue;
            }
            $rol = $m['role'] ?? '';
            $contenido = $m['content'] ?? '';

            if ($rol === 'user') {
                if (is_string($contenido)) {
                    $mensajes[] = ['role' => 'user', 'content' => $contenido];
                    continue;
                }
                // Resultados de herramientas: un mensaje `tool` por cada uno,
                // en el mismo orden y con el mismo identificador.
                $textos = [];
                foreach ((array)$contenido as $b) {
                    $tipo = is_array($b) ? ($b['type'] ?? '') : '';
                    if ($tipo === 'tool_result') {
                        $resultado = $b['content'] ?? '';
                        $resultado = is_array($resultado) ? self::textoDeBloques($resultado) : (string)$resultado;
                        $mensajes[] = [
                            'role'         => 'tool',
                            'tool_call_id' => (string)($b['tool_use_id'] ?? ''),
                            'content'      => !empty($b['is_error']) ? 'ERROR: ' . $resultado : $resultado,
                        ];
                    } elseif ($tipo === 'text' && is_string($b['text'] ?? null)) {
                        $textos[] = $b['text'];
                    }
                }
                if ($textos) {
                    $mensajes[] = ['role' => 'user', 'content' => implode("\n\n", $textos)];
                }
                continue;
            }

            if ($rol === 'assistant') {
                if (is_string($contenido)) {
                    $mensajes[] = ['role' => 'assistant', 'content' => $contenido];
                    continue;
                }
                $textos = [];
                $llamadas = [];
                foreach ((array)$contenido as $b) {
                    $tipo = is_array($b) ? ($b['type'] ?? '') : '';
                    if ($tipo === 'text' && is_string($b['text'] ?? null)) {
                        $textos[] = $b['text'];
                    } elseif ($tipo === 'tool_use') {
                        $entrada = $b['input'] ?? [];
                        $llamadas[] = [
                            'id'       => (string)($b['id'] ?? ''),
                            'type'     => 'function',
                            'function' => [
                                'name'      => (string)($b['name'] ?? ''),
                                // Siempre un objeto JSON en texto; una entrada
                                // que llegó rota se devuelve como {}.
                                'arguments' => json_encode(is_array($entrada) && $entrada !== [] && empty($b['entrada_invalida'])
                                    ? $entrada : new stdClass(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                            ],
                        ];
                    }
                    // `thinking` y `redacted_thinking` son de Anthropic: no se envían.
                }
                $mensaje = ['role' => 'assistant', 'content' => $textos ? implode("\n\n", $textos) : ($llamadas ? null : '')];
                if ($llamadas) {
                    $mensaje['tool_calls'] = $llamadas;
                }
                $mensajes[] = $mensaje;
            }
        }

        $peticion = [
            'model'      => (string)($cuerpo['model'] ?? ''),
            'max_tokens' => (int)($cuerpo['max_tokens'] ?? 1024),
            'messages'   => $mensajes,
        ];

        $herramientas = [];
        foreach ($cuerpo['tools'] ?? [] as $t) {
            if (!is_array($t) || !isset($t['name'])) {
                continue;
            }
            $herramientas[] = [
                'type'     => 'function',
                'function' => [
                    'name'        => (string)$t['name'],
                    'description' => (string)($t['description'] ?? ''),
                    'parameters'  => $t['input_schema'] ?? ['type' => 'object', 'properties' => new stdClass()],
                ],
            ];
        }
        if ($herramientas) {
            $peticion['tools'] = $herramientas;
        }
        return $peticion;
    }

    /**
     * Respuesta Chat Completions → formato Messages.
     *
     * Texto en un bloque `text`; cada llamada a herramienta en un bloque
     * `tool_use` con el mismo id, el nombre y los argumentos ya decodificados.
     * Si los argumentos no son un objeto JSON válido (truncados, texto suelto,
     * una lista), el bloque lleva `entrada_invalida` y el agente devuelve un
     * error a la herramienta en vez de ejecutarla.
     *
     * Hay herramientas si `tool_calls` trae alguna, diga lo que diga
     * `finish_reason`: hay modelos que terminan en «stop» tras pedir una.
     */
    private static function desdeChatCompletions(array $datos): array
    {
        $eleccion = $datos['choices'][0] ?? null;
        $mensaje  = is_array($eleccion) ? ($eleccion['message'] ?? null) : null;
        if (!is_array($mensaje)) {
            throw new IaError('respuesta', 'Respuesta sin choices[0].message.');
        }
        $fin = (string)($eleccion['finish_reason'] ?? '');
        if ($fin === 'error') {
            throw new IaError('sobrecarga', 'El proveedor terminó la respuesta con error.');
        }

        $contenido = [];
        $texto = $mensaje['content'] ?? '';
        if (is_array($texto)) {
            $texto = self::textoDeBloques($texto);
        }
        if (is_string($texto) && trim($texto) !== '') {
            $contenido[] = ['type' => 'text', 'text' => $texto];
        }

        $hayHerramientas = false;
        foreach (is_array($mensaje['tool_calls'] ?? null) ? $mensaje['tool_calls'] : [] as $llamada) {
            if (!is_array($llamada) || !is_array($llamada['function'] ?? null)) {
                continue;
            }
            [$entrada, $valida] = self::argumentos($llamada['function']['arguments'] ?? '');
            $id = $llamada['id'] ?? '';
            $bloque = [
                'type'  => 'tool_use',
                'id'    => is_string($id) && $id !== '' ? $id : 'call_' . bin2hex(random_bytes(8)),
                'name'  => (string)($llamada['function']['name'] ?? ''),
                'input' => $entrada,
            ];
            if (!$valida) {
                $bloque['entrada_invalida'] = true;
            }
            $contenido[] = $bloque;
            $hayHerramientas = true;
        }

        $rechazo = is_string($mensaje['refusal'] ?? null) && trim($mensaje['refusal']) !== '';
        $parada = match (true) {
            $fin === 'content_filter' || $rechazo => 'refusal',
            $fin === 'length'                     => 'max_tokens',
            $hayHerramientas                      => 'tool_use',
            default                               => 'end_turn',
        };

        // En Chat Completions prompt_tokens incluye lo leído de caché; en la
        // Messages API no. Se separa para que ai_action_logs mida lo mismo.
        $uso = is_array($datos['usage'] ?? null) ? $datos['usage'] : [];
        $cache = (int)($uso['prompt_tokens_details']['cached_tokens'] ?? 0);

        return [
            'id'          => (string)($datos['id'] ?? ''),
            'type'        => 'message',
            'role'        => 'assistant',
            'model'       => (string)($datos['model'] ?? ''),
            'content'     => $contenido,
            'stop_reason' => $parada,
            'usage'       => [
                'input_tokens'            => max(0, (int)($uso['prompt_tokens'] ?? 0) - $cache),
                'output_tokens'           => (int)($uso['completion_tokens'] ?? 0),
                'cache_read_input_tokens' => $cache,
            ],
        ];
    }

    /**
     * Argumentos de una llamada: texto JSON que tiene que ser un objeto.
     *
     * @return array{0: array, 1: bool} entrada decodificada y si es válida
     */
    private static function argumentos(mixed $crudo): array
    {
        if (is_array($crudo)) {
            // Algún proveedor los manda ya decodificados: vale si es un objeto.
            return $crudo === [] || !array_is_list($crudo) ? [$crudo, true] : [[], false];
        }
        if (!is_string($crudo)) {
            return [[], false];
        }
        $crudo = trim($crudo);
        if ($crudo === '') {
            return [[], true]; // Herramienta sin argumentos.
        }
        $objeto = json_decode($crudo, false, 32);
        if (json_last_error() !== JSON_ERROR_NONE || !($objeto instanceof stdClass)) {
            return [[], false];
        }
        $entrada = json_decode($crudo, true, 32);
        return [is_array($entrada) ? $entrada : [], is_array($entrada)];
    }


    // =====================================================================
    // Google Gemini
    // =====================================================================

    /** Prefijo de los identificadores que pone Flowers Anto cuando Gemini no manda uno. */
    private const ID_PROPIO_GOOGLE = 'fa_';

    /**
     * Campos del esquema de parámetros que admite Gemini (objeto `Schema` de
     * la API, subconjunto de OpenAPI 3.0). Cualquier otro —por ejemplo
     * `additionalProperties`— la API lo rechaza con un 400.
     */
    private const CAMPOS_ESQUEMA_GOOGLE = [
        'type', 'format', 'title', 'description', 'nullable', 'enum', 'required',
        'minimum', 'maximum', 'minItems', 'maxItems', 'minLength', 'maxLength', 'pattern',
        'minProperties', 'maxProperties', 'propertyOrdering',
    ];

    /** Finales de Gemini que son un rechazo por seguridad o contenido. */
    private const RECHAZOS_GOOGLE = ['SAFETY', 'RECITATION', 'BLOCKLIST', 'PROHIBITED_CONTENT', 'SPII'];

    private static function google(array $cuerpo, float $limite, string $clave): array
    {
        $peticion  = self::aGemini($cuerpo);
        // El modelo va en la URL: se usa el de la petición (tienda o panel).
        $url       = IaConfig::urlPeticion((string)($cuerpo['model'] ?? ''));
        // La clave va en la cabecera oficial, nunca como ?key= en la URL, que
        // podría quedar en registros de proxies.
        $cabeceras = ['Content-Type: application/json', 'x-goog-api-key: ' . $clave];

        $intentos = 0;
        while (true) {
            $intentos++;
            $quedan = $limite - microtime(true);
            if ($quedan < 3) {
                throw new IaError('tiempo', 'Sin tiempo para llamar a la API.');
            }
            [$estado, $respuesta, $reintentarEn, $errorCurl] = self::post($url, $cabeceras, $peticion, $quedan);

            if ($errorCurl !== '') {
                self::$ultimoDetalle = 'Sin conexión con ' . (parse_url(IaConfig::urlPeticion(), PHP_URL_HOST) ?: 'la API')
                    . ': ' . mb_substr($errorCurl, 0, 200);
                throw new IaError(str_contains($errorCurl, 'timed out') ? 'tiempo' : 'red', $errorCurl);
            }

            $datos = json_decode($respuesta, true);
            if ($estado === 200) {
                return self::desdeGemini(is_array($datos) ? $datos : []);
            }

            $error  = is_array($datos) && is_array($datos['error'] ?? null) ? $datos['error'] : [];
            $status = preg_replace('/[^A-Z_]/', '', (string)($error['status'] ?? '')) ?? '';
            $motivo = '';
            foreach ((array)($error['details'] ?? []) as $d) {
                if (is_array($d) && is_string($d['reason'] ?? null)) {
                    $motivo = preg_replace('/[^A-Z_]/', '', $d['reason']) ?? '';
                    break;
                }
            }
            $texto = self::tapar(mb_substr((string)($error['message'] ?? ''), 0, 200), $clave);
            // Google responde 400 (no 401) a una clave que no es válida.
            $claveMala = $motivo === 'API_KEY_INVALID' || ($estado === 400 && stripos($texto, 'API key') !== false);

            $reintentable = in_array($estado, [429, 500, 502, 503], true);
            $espera = min(3.0, max(0.5, $reintentarEn));
            if ($reintentable && $intentos < 2 && ($limite - microtime(true)) > $espera + 5) {
                usleep((int)($espera * 1_000_000));
                continue;
            }

            self::anotar(sprintf(' (google): HTTP %d %s %s %s%s', $estado, $status, $motivo, $texto, match (true) {
                $claveMala        => ' — AI_API_KEY no es válida',
                $estado === 403   => ' — la clave no tiene permiso para la API de Gemini',
                $estado === 404   => ' — revisa AI_MODEL: el modelo no existe o no admite generateContent',
                $estado === 429   => ' — cuota o límite de Google alcanzado (depende del modelo y del proyecto)',
                default           => '',
            }));
            throw new IaError(match (true) {
                $claveMala, $estado === 401, $estado === 403 => 'config',
                $estado === 408, $estado === 504             => 'tiempo',
                $estado === 429                              => 'limite',
                $estado >= 500                               => 'sobrecarga',
                default                                      => 'peticion',
            }, "HTTP $estado $status");
        }
    }

    /**
     * Petición Messages → Gemini (generateContent).
     *
     *   · system          → systemInstruction (una sola vez, completo).
     *   · user/assistant  → contents con role user/model y sus parts.
     *   · tool_use        → part functionCall {id?, name, args} (+ su firma).
     *   · tool_result     → part functionResponse {id?, name, response}, en un
     *                       contenido de usuario, en el mismo orden.
     *   · herramientas    → tools[0].functionDeclarations, con el esquema
     *                       adaptado a lo que admite Gemini.
     * Lo exclusivo de Anthropic (cache_control, output_config, fallbacks,
     * razonamiento) no se envía.
     */
    private static function aGemini(array $cuerpo): array
    {
        $peticion = [];

        $sistema = $cuerpo['system'] ?? '';
        if (is_array($sistema)) {
            $sistema = self::textoDeBloques($sistema);
        }
        if (is_string($sistema) && $sistema !== '') {
            $peticion['systemInstruction'] = ['parts' => [['text' => $sistema]]];
        }

        // functionResponse necesita el nombre de la función: se toma del
        // tool_use con el mismo id, que siempre va antes en el historial.
        $nombres  = [];
        $contents = [];
        foreach ($cuerpo['messages'] ?? [] as $m) {
            if (!is_array($m)) {
                continue;
            }
            $rol = ($m['role'] ?? '') === 'assistant' ? 'model' : 'user';
            $contenido = $m['content'] ?? '';
            $partes = [];

            if (is_string($contenido)) {
                if ($contenido !== '') {
                    $partes[] = ['text' => $contenido];
                }
            } else {
                foreach ((array)$contenido as $b) {
                    $tipo = is_array($b) ? ($b['type'] ?? '') : '';
                    if ($tipo === 'text' && is_string($b['text'] ?? null) && $b['text'] !== '') {
                        $parte = ['text' => $b['text']];
                        if ($rol === 'model' && is_string($b['firma_google'] ?? null)) {
                            $parte['thoughtSignature'] = $b['firma_google'];
                        }
                        $partes[] = $parte;
                    } elseif ($tipo === 'tool_use' && $rol === 'model') {
                        $id = (string)($b['id'] ?? '');
                        $nombres[$id] = (string)($b['name'] ?? '');
                        $entrada = $b['input'] ?? [];
                        $llamada = [
                            'name' => (string)($b['name'] ?? ''),
                            'args' => is_array($entrada) && $entrada !== [] && !array_is_list($entrada)
                                && empty($b['entrada_invalida']) ? $entrada : new stdClass(),
                        ];
                        if ($id !== '' && !str_starts_with($id, self::ID_PROPIO_GOOGLE)) {
                            $llamada = ['id' => $id] + $llamada;
                        }
                        $parte = ['functionCall' => $llamada];
                        if (is_string($b['firma_google'] ?? null)) {
                            $parte['thoughtSignature'] = $b['firma_google'];
                        }
                        $partes[] = $parte;
                    } elseif ($tipo === 'tool_result' && $rol === 'user') {
                        $id = (string)($b['tool_use_id'] ?? '');
                        $resultado = $b['content'] ?? '';
                        $resultado = is_array($resultado) ? self::textoDeBloques($resultado) : (string)$resultado;
                        if (!empty($b['is_error'])) {
                            $respuesta = ['error' => $resultado];
                        } else {
                            $dato = json_decode($resultado, true);
                            $respuesta = ['result' => json_last_error() === JSON_ERROR_NONE ? $dato : $resultado];
                        }
                        $funcion = ['name' => $nombres[$id] ?? 'herramienta', 'response' => $respuesta];
                        if ($id !== '' && !str_starts_with($id, self::ID_PROPIO_GOOGLE)) {
                            $funcion = ['id' => $id] + $funcion;
                        }
                        $partes[] = ['functionResponse' => $funcion];
                    }
                    // `thinking` y `redacted_thinking` son de Anthropic: no se envían.
                }
            }
            if ($partes) {
                $contents[] = ['role' => $rol, 'parts' => $partes];
            }
        }
        $peticion['contents'] = $contents;

        $declaraciones = [];
        foreach ($cuerpo['tools'] ?? [] as $t) {
            if (!is_array($t) || !isset($t['name'])) {
                continue;
            }
            $declaracion = ['name' => (string)$t['name'], 'description' => (string)($t['description'] ?? '')];
            $parametros = self::esquemaGoogle($t['input_schema'] ?? []);
            // Una función sin parámetros se declara sin `parameters`.
            if (!empty($parametros['properties'])) {
                $declaracion['parameters'] = $parametros;
            }
            $declaraciones[] = $declaracion;
        }
        if ($declaraciones) {
            $peticion['tools'] = [['functionDeclarations' => $declaraciones]];
        }

        $peticion['generationConfig'] = ['maxOutputTokens' => (int)($cuerpo['max_tokens'] ?? 1024)];
        return $peticion;
    }

    /**
     * Esquema JSON de una herramienta → `Schema` de Gemini. Tipos en
     * mayúsculas (su forma oficial) y solo los campos que la API admite. La
     * definición original de la herramienta no se toca: esto es una copia.
     * Quitar `additionalProperties` no abre nada: las herramientas solo leen
     * sus campos conocidos (IaEntrada).
     */
    private static function esquemaGoogle(mixed $esquema): array
    {
        $esquema = json_decode((string)json_encode($esquema), true);
        if (!is_array($esquema)) {
            return [];
        }
        $salida = [];
        foreach (self::CAMPOS_ESQUEMA_GOOGLE as $campo) {
            if (array_key_exists($campo, $esquema)) {
                $salida[$campo] = $esquema[$campo];
            }
        }
        if (isset($salida['type'])) {
            $tipos = array_values(array_filter((array)$salida['type'], fn($t) => is_string($t) && strtolower($t) !== 'null'));
            if (is_array($salida['type']) && count($tipos) < count($salida['type'])) {
                $salida['nullable'] = true;
            }
            $salida['type'] = strtoupper((string)($tipos[0] ?? 'string'));
        }
        if (is_array($esquema['properties'] ?? null) && $esquema['properties'] !== []) {
            $salida['properties'] = [];
            foreach ($esquema['properties'] as $nombre => $sub) {
                $salida['properties'][(string)$nombre] = self::esquemaGoogle($sub);
            }
        }
        if (isset($esquema['items'])) {
            $salida['items'] = self::esquemaGoogle($esquema['items']);
        }
        if (isset($salida['enum'])) {
            $salida['enum'] = array_values(array_map('strval', (array)$salida['enum']));
        }
        return $salida;
    }

    /**
     * Respuesta de Gemini → formato Messages.
     *
     * Cada part de texto es un bloque `text`; cada functionCall, un bloque
     * `tool_use` con su id (el de Gemini o uno propio si no trae) y los
     * argumentos. Si `args` no es un objeto, el bloque lleva
     * `entrada_invalida` y el agente devuelve un error en vez de ejecutarla.
     * La firma de razonamiento (`thoughtSignature`) se guarda en el bloque
     * para devolverla intacta en la siguiente vuelta, como pide la API.
     * Hay herramientas si llega alguna functionCall: Gemini termina en «STOP»
     * también cuando pide una.
     */
    private static function desdeGemini(array $datos): array
    {
        $uso = is_array($datos['usageMetadata'] ?? null) ? $datos['usageMetadata'] : [];
        $cache = (int)($uso['cachedContentTokenCount'] ?? 0);
        $usage = [
            'input_tokens'            => max(0, (int)($uso['promptTokenCount'] ?? 0) - $cache),
            'output_tokens'           => (int)($uso['candidatesTokenCount'] ?? 0) + (int)($uso['thoughtsTokenCount'] ?? 0),
            'cache_read_input_tokens' => $cache,
        ];
        $base = ['id' => (string)($datos['responseId'] ?? ''), 'type' => 'message', 'role' => 'assistant',
                 'model' => (string)($datos['modelVersion'] ?? '')];

        $candidato = $datos['candidates'][0] ?? null;
        if (!is_array($candidato)) {
            // Sin candidatos: el mensaje del cliente se bloqueó por seguridad.
            if (!empty($datos['promptFeedback']['blockReason'])) {
                return $base + ['content' => [], 'stop_reason' => 'refusal', 'usage' => $usage];
            }
            throw new IaError('respuesta', 'Respuesta de Gemini sin candidatos.');
        }
        $fin = (string)($candidato['finishReason'] ?? '');
        if ($fin === 'MISSING_THOUGHT_SIGNATURE') {
            // El historial no traía una firma que el modelo exige: el agente
            // lo repite con la conversación limpia.
            throw new IaError('peticion', 'Gemini: ' . $fin);
        }
        if (in_array($fin, ['MALFORMED_FUNCTION_CALL', 'UNEXPECTED_TOOL_CALL', 'TOO_MANY_TOOL_CALLS', 'MALFORMED_RESPONSE'], true)) {
            throw new IaError('respuesta', 'Gemini: ' . $fin);
        }

        $contenido = [];
        $hayHerramientas = false;
        foreach ((array)($candidato['content']['parts'] ?? []) as $parte) {
            if (!is_array($parte) || !empty($parte['thought'])) {
                continue; // resumen del razonamiento: no es parte de la respuesta
            }
            $firma = is_string($parte['thoughtSignature'] ?? null) && strlen($parte['thoughtSignature']) <= 20000
                ? $parte['thoughtSignature'] : null;
            if (is_array($parte['functionCall'] ?? null)) {
                $llamada = $parte['functionCall'];
                $args = $llamada['args'] ?? [];
                $valida = is_array($args) && ($args === [] || !array_is_list($args));
                $id = $llamada['id'] ?? '';
                $bloque = [
                    'type'  => 'tool_use',
                    'id'    => is_string($id) && $id !== '' ? $id : self::ID_PROPIO_GOOGLE . bin2hex(random_bytes(8)),
                    'name'  => (string)($llamada['name'] ?? ''),
                    'input' => $valida ? $args : [],
                ];
                if (!$valida) {
                    $bloque['entrada_invalida'] = true;
                }
                if ($firma !== null) {
                    $bloque['firma_google'] = $firma;
                }
                $contenido[] = $bloque;
                $hayHerramientas = true;
            } elseif (is_string($parte['text'] ?? null) && trim($parte['text']) !== '') {
                $bloque = ['type' => 'text', 'text' => $parte['text']];
                if ($firma !== null) {
                    $bloque['firma_google'] = $firma;
                }
                $contenido[] = $bloque;
            }
        }

        $parada = match (true) {
            in_array($fin, self::RECHAZOS_GOOGLE, true) => 'refusal',
            $fin === 'MAX_TOKENS'                        => 'max_tokens',
            $hayHerramientas                             => 'tool_use',
            default                                      => 'end_turn',
        };
        return $base + ['content' => $contenido, 'stop_reason' => $parada, 'usage' => $usage];
    }

    // =====================================================================
    // Común
    // =====================================================================

    /** Texto de una lista de bloques o partes `{type: text, text: …}`. */
    private static function textoDeBloques(array $bloques): string
    {
        $partes = [];
        foreach ($bloques as $b) {
            if (is_array($b) && ($b['type'] ?? '') === 'text' && is_string($b['text'] ?? null)) {
                $partes[] = $b['text'];
            }
        }
        return implode("\n\n", $partes);
    }

    /** Tapa la clave si un proveedor la repitiera en un mensaje de error. */
    private static function tapar(string $texto, string $clave): string
    {
        $texto = $clave !== '' ? str_replace($clave, '[clave]', $texto) : $texto;
        return preg_replace('/Bearer\s+\S+/i', 'Bearer [clave]', $texto) ?? '';
    }

    /** @return array{0:int,1:string,2:float,3:string} estado, cuerpo, reintentar-en, error de cURL */
    private static function post(string $url, array $cabeceras, array $cuerpo, float $quedan): array
    {
        $reintentarEn = 1.0;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER     => $cabeceras,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_TIMEOUT        => max(3, (int)floor($quedan)),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            // Una redirección podría llevar la clave a otro servidor.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $linea) use (&$reintentarEn): int {
                if (stripos($linea, 'retry-after:') === 0) {
                    $reintentarEn = (float)trim(substr($linea, 12));
                }
                return strlen($linea);
            },
        ]);
        $respuesta = curl_exec($ch);
        $errorCurl = $respuesta === false ? curl_error($ch) : '';
        $estado    = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        return [$estado, is_string($respuesta) ? $respuesta : '', $reintentarEn, $errorCurl];
    }
}
