<?php
/**
 * Registro de lo que hacen los asistentes.
 *
 * Una fila por conversación (con los tokens, para saber cuánto cuesta) y una
 * por cada acción que cambia algo o que se niega. No se guardan mensajes ni
 * datos personales: solo qué herramienta, sobre qué recurso y cómo acabó.
 *
 * Registrar nunca rompe una respuesta: si la escritura falla se anota en el
 * registro del servidor y la conversación sigue.
 */

declare(strict_types=1);

final class IaRegistro
{
    /** Días que se conservan las filas. */
    private const DIAS = 180;

    /**
     * @param array{usuario_id?: ?int, herramienta?: ?string, recurso_tipo?: ?string,
     *              recurso_id?: ?string, estado?: string, detalle?: ?string,
     *              tokens_entrada?: int, tokens_salida?: int, tokens_cache?: int, ms?: int} $d
     */
    public static function anotar(PDO $pdo, string $agente, string $accion, array $d = []): void
    {
        try {
            if (random_int(1, 200) === 1) {
                $pdo->exec("DELETE FROM ai_action_logs WHERE created_at < NOW() - INTERVAL " . self::DIAS . " DAY");
            }
            $pdo->prepare(
                "INSERT INTO ai_action_logs
                    (usuario_id, agente, accion, herramienta, recurso_tipo, recurso_id, estado, detalle,
                     tokens_entrada, tokens_salida, tokens_cache, ms, ip)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            )->execute([
                $d['usuario_id'] ?? Auth::id(),
                $agente === 'admin' ? 'admin' : 'cliente',
                mb_substr($accion, 0, 60),
                isset($d['herramienta']) ? mb_substr((string)$d['herramienta'], 0, 60) : null,
                isset($d['recurso_tipo']) ? mb_substr((string)$d['recurso_tipo'], 0, 40) : null,
                isset($d['recurso_id']) ? mb_substr((string)$d['recurso_id'], 0, 64) : null,
                $d['estado'] ?? 'ok',
                isset($d['detalle']) ? mb_substr((string)$d['detalle'], 0, 255) : null,
                max(0, (int)($d['tokens_entrada'] ?? 0)),
                max(0, (int)($d['tokens_salida'] ?? 0)),
                max(0, (int)($d['tokens_cache'] ?? 0)),
                max(0, (int)($d['ms'] ?? 0)),
                ip_cliente(),
            ]);
        } catch (Throwable $e) {
            error_log('Flowers Anto — registro de IA: ' . $e->getMessage());
        }
    }
}
