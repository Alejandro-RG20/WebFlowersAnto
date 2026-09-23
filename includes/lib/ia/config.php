<?php
/**
 * Configuración de los asistentes de IA.
 *
 * Todo lo sensible sale del entorno (.env o variables del servidor), nunca de
 * la base ni del código: la clave de la API no se puede leer desde el panel,
 * no viaja al navegador y no aparece en ningún registro.
 *
 * Los dos asistentes se pueden apagar por separado, y ambos se apagan solos
 * si falta la clave o las tablas de la migración 022. Apagados, la tienda no
 * cambia en nada: no se pinta el botón y los endpoints responden «no
 * disponible».
 */

declare(strict_types=1);

final class IaConfig
{
    /** Modelo por defecto: el más capaz de uso general en la API. */
    public const MODELO_POR_DEFECTO = 'claude-opus-5';

    private const URL_POR_DEFECTO = 'https://api.anthropic.com';

    private static ?bool $tablas = null;

    /** ¿Hay una clave configurada? No dice cuál. */
    public static function hayClave(): bool
    {
        return self::clave() !== '';
    }

    /** La clave. Solo la usa `ClaudeCliente`, y solo para la cabecera. */
    public static function clave(): string
    {
        return trim(Entorno::texto('AI_API_KEY'));
    }

    public static function modelo(string $agente): string
    {
        $modelo = $agente === 'admin' ? Entorno::texto('AI_MODEL_ADMIN') : '';
        $modelo = $modelo !== '' ? $modelo : Entorno::texto('AI_MODEL', self::MODELO_POR_DEFECTO);
        // Un identificador de modelo es corto y no lleva espacios ni
        // comillas; cualquier otra cosa es un error de configuración.
        return preg_match('/^[a-z0-9][a-z0-9.\-]{2,60}$/', $modelo) ? $modelo : self::MODELO_POR_DEFECTO;
    }

    /**
     * Dirección base de la API.
     *
     * Solo https. La excepción es un servidor de pruebas en la propia
     * máquina y en entorno de desarrollo: con cualquier otra cosa la clave
     * viajaría en claro.
     */
    public static function urlBase(): string
    {
        $url = rtrim(Entorno::texto('AI_BASE_URL', self::URL_POR_DEFECTO), '/');
        if (str_starts_with($url, 'https://')) {
            return $url;
        }
        if (defined('ENTORNO') && ENTORNO === 'dev'
            && preg_match('#^http://(127\.0\.0\.1|localhost)(:\d+)?$#', $url)) {
            return $url;
        }
        return self::URL_POR_DEFECTO;
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
            && self::tablasListas($pdo);
    }

    /** ¿Está disponible el asistente del panel? */
    public static function adminActivo(PDO $pdo): bool
    {
        return self::hayClave()
            && Entorno::bandera('AI_ADMIN_ACTIVO', true)
            && self::tablasListas($pdo);
    }
}
