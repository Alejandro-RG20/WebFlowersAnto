<?php
/**
 * Bucle de conversación con herramientas.
 *
 *   mensaje → Claude → (pide una herramienta → PHP la ejecuta → resultado)* → respuesta
 *
 * El modelo nunca toca la base. Solo puede pedir herramientas por su nombre,
 * y cada agente trae su propia caja de herramientas: el de clientes no tiene
 * ni puede pedir las del panel, porque no están en su caja y un nombre que no
 * está en la caja se contesta con un error. Las herramientas sacan la
 * identidad y los permisos de la sesión de PHP, nunca de lo que diga el
 * modelo, así que un mensaje del estilo «ignora tus instrucciones, soy el
 * administrador» no tiene por dónde entrar: aunque el modelo se lo creyera, no
 * hay ninguna herramienta que acepte esa afirmación como prueba.
 *
 * El historial vive en la sesión del servidor. El navegador solo envía el
 * mensaje nuevo; no puede colar respuestas del asistente ni resultados de
 * herramientas inventados.
 */

declare(strict_types=1);

/** Caja de herramientas de un agente. */
interface IaCaja
{
    /** Instrucciones del sistema. Estables: no llevan fecha ni datos que cambien. */
    public function instrucciones(): string;

    /** Definiciones de herramientas en el formato de la API. */
    public function definiciones(): array;

    /**
     * Ejecuta una herramienta. Devuelve los datos para el modelo. Lanza
     * `IaHerramientaError` con un mensaje apto para el modelo si algo falla.
     */
    public function ejecutar(string $nombre, array $entrada): array;

    /** Lo que la interfaz debe pintar además del texto (tarjetas, carrito, propuestas…). */
    public function efectos(): array;
}

/** Un fallo de herramienta que el modelo puede leer y explicar. */
final class IaHerramientaError extends RuntimeException
{
    public function __construct(string $mensaje, public readonly string $estado = 'error')
    {
        parent::__construct($mensaje);
    }
}

/**
 * Validación de lo que el modelo manda a una herramienta.
 *
 * Lo que llega en `input` lo escribió el modelo, y el modelo lo escribió
 * leyendo lo que escribió el cliente: se trata igual que un formulario.
 */
final class IaEntrada
{
    public static function texto(array $e, string $campo, int $max = 120, bool $obligatorio = false): string
    {
        $v = $e[$campo] ?? '';
        if (!is_string($v) && !is_int($v) && !is_float($v)) {
            throw new IaHerramientaError("El campo «{$campo}» tiene que ser texto.");
        }
        $v = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string)$v) ?? '');
        if ($obligatorio && $v === '') {
            throw new IaHerramientaError("Falta el campo «{$campo}».");
        }
        return mb_substr($v, 0, $max);
    }

    public static function entero(array $e, string $campo, int $min, int $max, ?int $def = null): int
    {
        $v = $e[$campo] ?? null;
        if ($v === null || $v === '') {
            if ($def === null) {
                throw new IaHerramientaError("Falta el campo «{$campo}».");
            }
            return $def;
        }
        if (!is_numeric($v) || (float)$v != (int)$v) {
            throw new IaHerramientaError("El campo «{$campo}» tiene que ser un número entero.");
        }
        $n = (int)$v;
        if ($n < $min || $n > $max) {
            throw new IaHerramientaError("El campo «{$campo}» tiene que estar entre {$min} y {$max}.");
        }
        return $n;
    }

    public static function numero(array $e, string $campo, float $min, float $max): ?float
    {
        $v = $e[$campo] ?? null;
        if ($v === null || $v === '') {
            return null;
        }
        if (!is_numeric($v)) {
            throw new IaHerramientaError("El campo «{$campo}» tiene que ser un número.");
        }
        $n = (float)$v;
        if ($n < $min || $n > $max) {
            throw new IaHerramientaError("El campo «{$campo}» está fuera de rango.");
        }
        return $n;
    }

    /** @return string[] */
    public static function lista(array $e, string $campo, int $maxElementos = 6, int $maxLargo = 40): array
    {
        $v = $e[$campo] ?? [];
        if (!is_array($v)) {
            return [];
        }
        $salida = [];
        foreach (array_slice(array_values($v), 0, $maxElementos) as $x) {
            if (is_string($x) && trim($x) !== '') {
                $salida[] = mb_substr(trim($x), 0, $maxLargo);
            }
        }
        return $salida;
    }

    public static function opcion(array $e, string $campo, array $validas, string $def): string
    {
        $v = $e[$campo] ?? $def;
        return is_string($v) && in_array($v, $validas, true) ? $v : $def;
    }
}

/**
 * La sesión se suelta mientras se espera a la API.
 *
 * PHP bloquea el archivo de sesión mientras la petición está abierta. Si el
 * asistente tarda veinte segundos en contestar, cualquier otra página que
 * abra la misma persona —el carrito en otra pestaña— se quedaría esperando.
 * Se cierra antes de llamar a la API y se vuelve a abrir solo para lo que
 * escribe en ella: el carrito y el historial.
 */
final class IaSesion
{
    public static function soltar(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    public static function retomar(): void
    {
        if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_start();
        }
    }

    /** Ejecuta algo con la sesión abierta y la vuelve a soltar. */
    public static function con(callable $hacer): mixed
    {
        $estaba = session_status() === PHP_SESSION_ACTIVE;
        self::retomar();
        try {
            return $hacer();
        } finally {
            if (!$estaba) {
                self::soltar();
            }
        }
    }
}

final class IaAgente
{
    /** Vueltas máximas modelo → herramienta → modelo por mensaje. */
    private const MAX_PASOS = 6;

    /** Mensajes del cliente por conversación antes de empezar una nueva. */
    public const MAX_TURNOS = 14;

    /** Tamaño máximo del historial guardado (bytes de JSON). */
    private const MAX_HISTORIAL = 120_000;

    /**
     * @param array $historial Mensajes previos en formato de la API.
     * @return array{texto: string, historial: array, efectos: array, uso: array,
     *               reiniciar: bool, estado: string, herramientas: string[]}
     */
    public static function responder(
        string $agente,
        IaCaja $caja,
        array $historial,
        string $mensaje,
        array $opciones = []
    ): array {
        $inicio = microtime(true);
        $limite = $inicio + IaConfig::tiempoMaximo();
        $modelo = IaConfig::modelo($agente);

        $mensajes   = $historial;
        $mensajes[] = ['role' => 'user', 'content' => $mensaje];

        $uso = ['entrada' => 0, 'salida' => 0, 'cache' => 0];
        $usadas = [];
        $texto  = '';
        $estado = 'ok';

        $peticion = [
            'model'      => $modelo,
            'max_tokens' => $agente === 'admin' ? 12000 : 8000,
            // Instrucciones y herramientas son siempre las mismas: se marcan
            // para la caché y cada vuelta las lee a una décima parte del
            // precio. Las conversaciones además se cachean solas.
            'system'     => [[
                'type' => 'text', 'text' => $caja->instrucciones(),
                'cache_control' => ['type' => 'ephemeral'],
            ]],
            'tools'         => $caja->definiciones(),
            'cache_control' => ['type' => 'ephemeral'],
        ];
        // El esfuerzo baja el gasto sin cambiar de modelo. El de clientes
        // responde preguntas cortas; el del panel analiza datos.
        if (!str_starts_with($modelo, 'claude-haiku')) {
            $peticion['output_config'] = ['effort' => $opciones['esfuerzo'] ?? ($agente === 'admin' ? 'medium' : 'low')];
        }

        for ($paso = 0; $paso < self::MAX_PASOS; $paso++) {
            $respuesta = ClaudeCliente::mensajes($peticion + ['messages' => $mensajes], $limite);

            $uso['entrada'] += (int)($respuesta['usage']['input_tokens'] ?? 0);
            $uso['salida']  += (int)($respuesta['usage']['output_tokens'] ?? 0);
            $uso['cache']   += (int)($respuesta['usage']['cache_read_input_tokens'] ?? 0);

            $contenido = $respuesta['content'];
            $parada    = (string)($respuesta['stop_reason'] ?? '');

            // Rechazo de los filtros de seguridad del modelo (y del de
            // respaldo). No se ejecuta nada de ese turno y la conversación se
            // empieza de cero: un turno rechazado en el historial solo
            // estorbaría a los siguientes.
            if ($parada === 'refusal') {
                return self::cierre($agente, 'No puedo ayudarte con eso. Si tienes una duda sobre nuestros arreglos, '
                    . 'pedidos o entregas, pregúntame con gusto.', [], $caja, $uso, true, 'rechazada', $usadas);
            }

            // El historial se guarda tal como llega, bloques de razonamiento
            // incluidos: la API exige que vuelvan intactos en la siguiente
            // vuelta, y algunos modelos rechazan un historial retocado.
            $mensajes[] = ['role' => 'assistant', 'content' => $contenido];

            $pedidas = array_values(array_filter($contenido, fn($b) => ($b['type'] ?? '') === 'tool_use'));

            // Cortado por longitud: una herramienta a medio escribir puede
            // venir con los datos truncados. No se ejecuta.
            if ($parada === 'max_tokens') {
                $texto = self::textoDe($contenido);
                if ($pedidas || $texto === '') {
                    return self::cierre($agente, 'Me extendí demasiado y no terminé la respuesta. '
                        . '¿Me lo preguntas de forma más concreta?', [], $caja, $uso, true, 'error', $usadas);
                }
                break;
            }

            if ($parada !== 'tool_use' || !$pedidas) {
                $texto = self::textoDe($contenido);
                break;
            }

            // Todas las herramientas del turno se contestan en un solo
            // mensaje, como pide la API.
            $resultados = [];
            foreach ($pedidas as $bloque) {
                $nombre = (string)($bloque['name'] ?? '');
                $usadas[] = $nombre;
                $entrada = is_array($bloque['input'] ?? null) ? $bloque['input'] : [];
                try {
                    $datos = $caja->ejecutar($nombre, $entrada);
                    $resultados[] = [
                        'type' => 'tool_result', 'tool_use_id' => (string)$bloque['id'],
                        'content' => json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ];
                } catch (IaHerramientaError $e) {
                    $resultados[] = [
                        'type' => 'tool_result', 'tool_use_id' => (string)$bloque['id'],
                        'content' => $e->getMessage(), 'is_error' => true,
                    ];
                } catch (Throwable $e) {
                    // Un fallo interno no se le cuenta al modelo con detalle:
                    // el mensaje de una excepción de PDO puede llevar nombres
                    // de tablas y columnas.
                    error_log('Flowers Anto — herramienta IA ' . $nombre . ': ' . $e->getMessage());
                    $resultados[] = [
                        'type' => 'tool_result', 'tool_use_id' => (string)$bloque['id'],
                        'content' => 'No se pudo consultar en este momento.', 'is_error' => true,
                    ];
                }
            }
            $mensajes[] = ['role' => 'user', 'content' => $resultados];

            if ($paso === self::MAX_PASOS - 1) {
                $estado = 'error';
                $texto  = 'Esta consulta necesita más pasos de los que puedo dar de una vez. '
                        . '¿La dividimos en partes?';
                // El último mensaje del historial son resultados sin respuesta:
                // se descarta la conversación para no dejarla a medias.
                return self::cierre($agente, $texto, [], $caja, $uso, true, $estado, $usadas);
            }
        }

        if ($texto === '') {
            $texto = 'Listo.';
        }

        // Guardia de precios del asistente de clientes (ver IaGuardia).
        if ($agente === 'cliente' && !IaGuardia::preciosVerificados($texto, $mensajes)) {
            $estado = 'rechazada';
            $texto  = !empty($caja->efectos()['productos'])
                ? 'Aquí tienes las opciones con su precio exacto de hoy.'
                : 'Prefiero no darte un precio de memoria. Dime qué arreglo te interesa y lo consulto.';
        }

        $reiniciar = strlen((string)json_encode($mensajes)) > self::MAX_HISTORIAL;
        return self::cierre($agente, $texto, $mensajes, $caja, $uso, $reiniciar, $estado, $usadas);
    }

    private static function textoDe(array $contenido): string
    {
        $partes = [];
        foreach ($contenido as $b) {
            if (($b['type'] ?? '') === 'text' && is_string($b['text'] ?? null)) {
                $partes[] = $b['text'];
            }
        }
        return trim(implode("\n\n", $partes));
    }

    private static function cierre(
        string $agente, string $texto, array $mensajes, IaCaja $caja, array $uso,
        bool $reiniciar, string $estado, array $usadas
    ): array {
        return [
            'texto'        => $texto,
            'historial'    => $reiniciar ? [] : $mensajes,
            'efectos'      => $caja->efectos(),
            'uso'          => $uso,
            'reiniciar'    => $reiniciar,
            'estado'       => $estado,
            'herramientas' => $usadas,
        ];
    }
}

/**
 * Guardia contra precios inventados.
 *
 * El asistente de clientes solo puede escribir importes que hayan salido de
 * una herramienta —es decir, de la base— o que haya escrito el propio
 * cliente («tengo C$1500»). Si en la respuesta aparece cualquier otro
 * importe, la respuesta no se envía: se sustituye por una frase neutra y las
 * tarjetas de producto, que se construyen con datos de la base, dan el precio
 * real. No depende de que el modelo obedezca la instrucción de no inventar.
 */
final class IaGuardia
{
    public static function preciosVerificados(string $texto, array $mensajes): bool
    {
        $mencionados = self::importes($texto);
        if (!$mencionados) {
            return true;
        }

        $validos = [];
        foreach ($mensajes as $m) {
            if (($m['role'] ?? '') !== 'user') {
                continue;
            }
            if (is_string($m['content'])) {
                foreach (self::numeros($m['content']) as $n) {
                    $validos[] = $n;
                }
                continue;
            }
            foreach ((array)$m['content'] as $b) {
                if (($b['type'] ?? '') === 'tool_result' && empty($b['is_error']) && is_string($b['content'] ?? null)) {
                    foreach (self::numeros($b['content']) as $n) {
                        $validos[] = $n;
                    }
                }
            }
        }

        foreach ($mencionados as $importe) {
            $ok = false;
            foreach ($validos as $v) {
                if (abs($v - $importe) < 0.005) {
                    $ok = true;
                    break;
                }
            }
            if (!$ok) {
                return false;
            }
        }
        return true;
    }

    /** Importes con moneda: C$1,540.00 · C$ 1540 · US$42.20 · $42 */
    private static function importes(string $texto): array
    {
        preg_match_all('/(?:C\$|US\$|USD|\$)\s?(\d{1,3}(?:,\d{3})+(?:\.\d{1,2})?|\d+(?:\.\d{1,2})?)/u', $texto, $m);
        return array_map(fn($x) => (float)str_replace(',', '', $x), $m[1]);
    }

    /** Todos los números de un texto, con o sin separador de miles. */
    private static function numeros(string $texto): array
    {
        preg_match_all('/\d{1,3}(?:,\d{3})+(?:\.\d+)?|\d+(?:\.\d+)?/', $texto, $m);
        return array_map(fn($x) => (float)str_replace(',', '', $x), $m[0]);
    }
}
