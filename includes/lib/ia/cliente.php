<?php
/**
 * Cliente de la Messages API de Claude.
 *
 * Es HTTP directo con la extensión cURL de PHP, la misma que ya usa el cobro
 * con PayPal. El SDK oficial de PHP existe, pero exige Composer y un cliente
 * HTTP PSR-18 (Guzzle o Symfony) con su cadena de dependencias, y este
 * proyecto no tiene ninguna: se despliega subiendo archivos a un hosting
 * compartido. Toda la comunicación con la API pasa por esta clase, así que
 * cambiar al SDK el día que el proyecto use Composer es tocar un solo archivo.
 *
 * Lo que garantiza:
 *
 *   · La clave solo sale en la cabecera `x-api-key` y nunca se registra.
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
     * Envía una petición a /v1/messages y devuelve la respuesta decodificada.
     *
     * @param array $cuerpo  Petición ya armada (model, max_tokens, messages…).
     * @param float $limite  Momento (microtime) en que se acaba el tiempo.
     */
    public static function mensajes(array $cuerpo, float $limite): array
    {
        if (!function_exists('curl_init')) {
            throw new IaError('config', 'La extensión cURL de PHP no está disponible.');
        }
        $clave = IaConfig::clave();
        if ($clave === '') {
            throw new IaError('config', 'Falta AI_API_KEY.');
        }

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
            [$estado, $respuesta, $reintentarEn, $errorCurl] = self::post($cuerpo, $clave, $betas, $quedan);

            if ($errorCurl !== '') {
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
            $texto = is_array($error) ? mb_substr((string)($error['message'] ?? ''), 0, 200) : '';

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

            error_log(sprintf('Flowers Anto — IA: HTTP %d %s %s', $estado, $tipo, $texto));
            throw new IaError(match (true) {
                $estado === 401, $estado === 403 => 'config',
                $estado === 429                  => 'limite',
                $estado >= 500                   => 'sobrecarga',
                default                          => 'peticion',
            }, "HTTP $estado $tipo");
        }
    }

    /** @return array{0:int,1:string,2:float,3:string} estado, cuerpo, reintentar-en, error de cURL */
    private static function post(array $cuerpo, string $clave, array $betas, float $quedan): array
    {
        $cabeceras = [
            'content-type: application/json',
            'anthropic-version: ' . self::VERSION_API,
            'x-api-key: ' . $clave,
        ];
        if ($betas) {
            $cabeceras[] = 'anthropic-beta: ' . implode(',', $betas);
        }

        $reintentarEn = 1.0;
        $ch = curl_init(IaConfig::urlBase() . '/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER     => $cabeceras,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_TIMEOUT        => max(3, (int)floor($quedan)),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
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
