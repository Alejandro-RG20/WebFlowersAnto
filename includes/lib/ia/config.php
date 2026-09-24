<?php
/**
 * Configuración de los asistentes de IA.
 *
 * Todo lo sensible sale del entorno (.env o variables del servidor), nunca de
 * la base ni del código: la clave de la API no se puede leer desde el panel,
 * no viaja al navegador y no aparece en ningún registro.
 *
 * Los dos asistentes se pueden apagar por separado, y ambos se apagan solos
 * si falta la clave, las tablas de la migración 022 o, con un proveedor
 * compatible con OpenAI, el modelo. Apagados, la tienda no cambia en nada: no
 * se pinta el botón y los endpoints responden «no disponible».
 *
 * Proveedores (AI_PROVEEDOR):
 *   anthropic   Messages API de Anthropic. Es el valor por defecto.
 *   openrouter  OpenRouter, por su API compatible con OpenAI (Chat Completions).
 *   openai      Cualquier otra API compatible con OpenAI (AI_BASE_URL).
 * La traducción entre formatos la hace `ClaudeCliente`; el resto de la capa
 * de IA no sabe qué proveedor hay detrás.
 */

declare(strict_types=1);

final class IaConfig
{
    /** Modelo por defecto: el más capaz de uso general en la API. */
    public const MODELO_POR_DEFECTO = 'claude-opus-5';

    private const URL_POR_DEFECTO = 'https://api.anthropic.com';

    /** Dirección por defecto de cada proveedor admitido. */
    private const URLS = [
        'anthropic'  => self::URL_POR_DEFECTO,
        'openrouter' => 'https://openrouter.ai/api/v1',
        'openai'     => 'https://api.openai.com/v1',
    ];

    /**
     * Identificador de modelo de un proveedor compatible con OpenAI:
     * `autor/modelo:variante`, p. ej. `nvidia/nemotron-3-ultra-550b-a55b:free`.
     * Solo letras, números, punto, guion y guion bajo en cada parte.
     */
    private const PATRON_MODELO_ABIERTO =
        '#^[A-Za-z0-9][A-Za-z0-9._-]{0,63}(/[A-Za-z0-9][A-Za-z0-9._-]{0,99})?(:[A-Za-z0-9._-]{1,32})?$#';

    private static ?bool $tablas = null;

    /** ¿Hay una clave configurada? No dice cuál. */
    public static function hayClave(): bool
    {
        return self::clave() !== '';
    }

    /**
     * La clave. Solo la usa `ClaudeCliente`, y solo para la cabecera.
     *
     * Una clave con espacios, saltos de línea u otros caracteres de control
     * se trata como ausente: pegada tal cual en una cabecera HTTP permitiría
     * inyectar cabeceras.
     */
    public static function clave(): string
    {
        $clave = trim(Entorno::texto('AI_API_KEY'));
        return preg_match('/^[\x21-\x7E]{1,300}$/', $clave) ? $clave : '';
    }

    /**
     * Proveedor configurado: anthropic, openrouter u openai.
     * Devuelve '' si AI_PROVEEDOR tiene un valor desconocido (asistentes apagados).
     */
    public static function proveedor(): string
    {
        $p = strtolower(trim(Entorno::texto('AI_PROVEEDOR', 'anthropic')));
        return isset(self::URLS[$p]) ? $p : '';
    }

    /** ¿El proveedor habla el formato de OpenAI (Chat Completions)? */
    public static function compatibleOpenAi(): bool
    {
        return in_array(self::proveedor(), ['openrouter', 'openai'], true);
    }

    /**
     * Modelo de un agente. AI_MODEL_ADMIN, si existe, solo para el panel.
     *
     * Con Anthropic se conserva el comportamiento de siempre: un valor que no
     * parece un modelo de Anthropic se sustituye por el modelo por defecto.
     * Con un proveedor compatible con OpenAI el modelo es obligatorio y no se
     * hereda ninguno de Anthropic: si falta o no es válido devuelve '' y el
     * asistente queda apagado.
     */
    public static function modelo(string $agente): string
    {
        $modelo = $agente === 'admin' ? trim(Entorno::texto('AI_MODEL_ADMIN')) : '';
        if (self::compatibleOpenAi()) {
            $modelo = $modelo !== '' ? $modelo : trim(Entorno::texto('AI_MODEL'));
            return strlen($modelo) <= 150 && preg_match(self::PATRON_MODELO_ABIERTO, $modelo) ? $modelo : '';
        }
        $modelo = $modelo !== '' ? $modelo : Entorno::texto('AI_MODEL', self::MODELO_POR_DEFECTO);
        // Un identificador de modelo es corto y no lleva espacios ni
        // comillas; cualquier otra cosa es un error de configuración.
        return preg_match('/^[a-z0-9][a-z0-9.\-]{2,60}$/', $modelo) ? $modelo : self::MODELO_POR_DEFECTO;
    }

    /**
     * Dirección base de la API.
     *
     * Si AI_BASE_URL está vacía, la del proveedor. Solo https. La excepción
     * es el servidor de pruebas en la propia máquina y en entorno de
     * desarrollo: con cualquier otra cosa la clave viajaría en claro.
     */
    public static function urlBase(): string
    {
        $defecto = self::URLS[self::proveedor()] ?? self::URL_POR_DEFECTO;
        $url = rtrim(trim(Entorno::texto('AI_BASE_URL', $defecto)), '/');
        if (preg_match('#^https://[A-Za-z0-9.-]+(:\d+)?(/[A-Za-z0-9._~/-]*)?$#', $url)) {
            return $url;
        }
        if (defined('ENTORNO') && ENTORNO === 'dev'
            && preg_match('#^http://(127\.0\.0\.1|localhost)(:\d+)?(/[A-Za-z0-9._~/-]*)?$#', $url)) {
            return $url;
        }
        return $defecto;
    }

    /**
     * URL completa a la que se hace la petición.
     *
     * Anthropic: {base}/v1/messages, como siempre. Compatible con OpenAI:
     * {base}/chat/completions, con la base ya incluyendo la versión
     * (https://openrouter.ai/api/v1). Si alguien pone la ruta completa en
     * AI_BASE_URL, no se duplica.
     */
    public static function urlPeticion(): string
    {
        $base = self::urlBase();
        if (!self::compatibleOpenAi()) {
            return $base . '/v1/messages';
        }
        if (str_ends_with($base, '/chat/completions')) {
            $base = substr($base, 0, -strlen('/chat/completions'));
        }
        return $base . '/chat/completions';
    }

    /**
     * Cabeceras opcionales con las que OpenRouter identifica la aplicación
     * (HTTP-Referer y X-Title). Datos públicos, nunca secretos; se descartan
     * si no son una URL https o si llevan caracteres de control.
     *
     * @return array{url: string, nombre: string}
     */
    public static function identificacionApp(): array
    {
        $url = trim(Entorno::texto('AI_APP_URL'));
        $nombre = trim(Entorno::texto('AI_APP_NOMBRE'));
        return [
            'url'    => strlen($url) <= 200 && preg_match('#^https://[A-Za-z0-9.-]+(:\d+)?(/[A-Za-z0-9._~%/?=&-]*)?$#', $url) ? $url : '',
            'nombre' => preg_match('/^[^\x00-\x1F\x7F]{1,60}$/u', $nombre) ? $nombre : '',
        ];
    }

    /**
     * Qué impide que los asistentes funcionen, en frases seguras (sin la
     * clave ni valores del .env). Vacío si la configuración está completa.
     *
     * @return string[]
     */
    public static function problemas(): array
    {
        $p = [];
        if (!self::hayClave()) {
            $p[] = 'Falta AI_API_KEY o tiene caracteres no válidos.';
        }
        if (self::proveedor() === '') {
            $p[] = 'AI_PROVEEDOR no es válido (usa anthropic, openrouter u openai).';
        } elseif (self::compatibleOpenAi()) {
            if (self::modelo('cliente') === '') {
                $p[] = 'Con AI_PROVEEDOR=' . self::proveedor() . ' hay que indicar AI_MODEL (p. ej. autor/modelo:variante).';
            }
            if (self::modelo('admin') === '' && self::modelo('cliente') !== '') {
                $p[] = 'AI_MODEL_ADMIN no es un identificador de modelo válido.';
            }
        }
        return $p;
    }

    /** Segundos máximos que puede durar una respuesta completa del asistente. */
    public static function tiempoMaximo(): int
    {
        return max(10, min(90, Entorno::entero('AI_TIMEOUT', 40)));
    }

    /** Mensajes al asistente de clientes por día en toda la tienda (0 = sin tope). */
    public static function limiteDiario(): int
    {
        return max(0, Entorno::entero('AI_LIMITE_DIARIO', 1500));
    }

    /** ¿Existen las tablas de la migración 022? */
    public static function tablasListas(PDO $pdo): bool
    {
        if (self::$tablas !== null) {
            return self::$tablas;
        }
        try {
            $pdo->query("SELECT 1 FROM ai_action_logs LIMIT 0");
            $pdo->query("SELECT 1 FROM ai_pending_actions LIMIT 0");
            return self::$tablas = true;
        } catch (PDOException) {
            return self::$tablas = false;
        }
    }

    /** ¿Se enseña y responde el asistente de compras? */
    public static function clienteActivo(PDO $pdo): bool
    {
        return self::hayClave()
            && Entorno::bandera('AI_CLIENTE_ACTIVO', true)
            && self::proveedorListo($pdo, 'cliente')
            && self::tablasListas($pdo);
    }

    /** ¿Está disponible el asistente del panel? */
    public static function adminActivo(PDO $pdo): bool
    {
        return self::hayClave()
            && Entorno::bandera('AI_ADMIN_ACTIVO', true)
            && self::proveedorListo($pdo, 'admin')
            && self::tablasListas($pdo);
    }

    /**
     * Proveedor válido y, si es compatible con OpenAI, con modelo. Si falta
     * algo, el asistente se apaga y se deja una línea en el registro del
     * servidor como mucho una vez por hora (sin la clave ni valores del .env).
     */
    private static function proveedorListo(PDO $pdo, string $agente): bool
    {
        if (self::proveedor() !== '' && self::modelo($agente) !== '') {
            return true;
        }
        try {
            if (limitar($pdo, 'ia-config-aviso', 1, 3600)) {
                error_log('Flowers Anto — IA apagada por configuración: ' . implode(' ', self::problemas()));
            }
        } catch (Throwable) {
            // Sin tabla de límites no se avisa; el asistente sigue apagado igual.
        }
        return false;
    }
}
