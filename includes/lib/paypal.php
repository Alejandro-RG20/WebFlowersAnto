<?php
/**
 * Cliente de PayPal (Orders v2).
 *
 * Sin Composer, como el resto del proyecto: la API de PayPal es REST y con
 * cURL basta.
 *
 * Dos reglas que sostienen la seguridad de todo esto:
 *
 *   1. El importe SIEMPRE se calcula en el servidor a partir del carrito. Lo
 *      que mande el navegador se ignora: si no, cualquiera podría pagar un
 *      ramo de C$2.000 por un dólar cambiando un campo del formulario.
 *   2. Al capturar se comprueba que PayPal confirme el estado COMPLETED y que
 *      el importe cobrado sea el que se pidió. Un cobro por menos no marca el
 *      pedido como pagado.
 *
 * El secreto no sale nunca del servidor: al navegador solo va el client id,
 * que es público por diseño.
 */

declare(strict_types=1);

final class PayPal
{
    private const TIEMPO_ESPERA = 20;

    /** ¿Está configurado y encendido? */
    public static function activo(): bool
    {
        return Ajustes::activo('paypal_activo', false)
            && Ajustes::texto('paypal_client_id') !== ''
            && Ajustes::texto('paypal_secreto') !== ''
            && self::tasa() > 0;
    }

    /** Client id, que sí es público: lo necesita el botón del navegador. */
    public static function clientId(): string
    {
        return Ajustes::texto('paypal_client_id');
    }

    public static function moneda(): string
    {
        $m = strtoupper(Ajustes::texto('paypal_moneda', 'USD'));
        return preg_match('/^[A-Z]{3}$/', $m) ? $m : 'USD';
    }

    /** Córdobas por dólar. */
    public static function tasa(): float
    {
        return (float)Ajustes::texto('tasa_usd', '0');
    }

    /**
     * Convierte el total del pedido a la moneda de cobro.
     *
     * Se redondea a dos decimales hacia arriba: si por el redondeo hubiera
     * diferencia, que sea a favor de la floristería y no en su contra.
     */
    public static function enMonedaDeCobro(float $totalLocal): float
    {
        $tasa = self::tasa();
        if ($tasa <= 0) {
            return 0.0;
        }
        return ceil(($totalLocal / $tasa) * 100) / 100;
    }

    private static function base(): string
    {
        return Ajustes::texto('paypal_modo', 'sandbox') === 'vivo'
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';
    }

    /**
     * Token de acceso. Se guarda en la sesión mientras siga siendo válido:
     * PayPal los da con casi nueve horas de vida y pedir uno nuevo en cada
     * clic es una llamada de red regalada.
     */
    private static function token(): string
    {
        $guardado = $_SESSION['paypal_token'] ?? null;
        if (is_array($guardado) && ($guardado['expira'] ?? 0) > time() + 60) {
            return (string)$guardado['valor'];
        }

        $r = self::llamar('POST', '/v1/oauth2/token', 'grant_type=client_credentials', [
            'Content-Type: application/x-www-form-urlencoded',
            'Authorization: Basic ' . base64_encode(self::clientId() . ':' . Ajustes::texto('paypal_secreto')),
        ], false);

        $token = (string)($r['cuerpo']['access_token'] ?? '');
        if ($token === '') {
            throw new RuntimeException('PayPal no entregó un token de acceso.');
        }
        $_SESSION['paypal_token'] = [
            'valor'  => $token,
            'expira' => time() + max(60, (int)($r['cuerpo']['expires_in'] ?? 300)),
        ];
        return $token;
    }

    /** Crea la orden y devuelve su id. */
    public static function crearOrden(float $totalLocal, string $referencia, string $descripcion): string
    {
        $importe = self::enMonedaDeCobro($totalLocal);
        if ($importe <= 0) {
            throw new RuntimeException('No se pudo calcular el importe en ' . self::moneda() . '.');
        }

        $r = self::llamar('POST', '/v2/checkout/orders', json_encode([
            'intent'         => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => mb_substr($referencia, 0, 60),
                'description'  => mb_substr($descripcion, 0, 120),
                'amount'       => [
                    'currency_code' => self::moneda(),
                    'value'         => number_format($importe, 2, '.', ''),
                ],
            ]],
            'application_context' => [
                'shipping_preference' => 'NO_SHIPPING',   // la dirección ya la tomamos nosotros
                'user_action'         => 'PAY_NOW',
            ],
        ], JSON_UNESCAPED_UNICODE));

        $id = (string)($r['cuerpo']['id'] ?? '');
        if ($id === '') {
            throw new RuntimeException('PayPal no devolvió la orden.');
        }
        return $id;
    }

    /**
     * Captura el pago y comprueba que sea el que se pidió.
     *
     * @return array{ok:bool, captura:string, importe:float, moneda:string, error:string}
     */
    public static function capturar(string $ordenId, float $totalLocal): array
    {
        $no = static fn(string $m): array
            => ['ok' => false, 'captura' => '', 'importe' => 0.0, 'moneda' => '', 'error' => $m];

        if (!preg_match('/^[A-Z0-9]{5,40}$/i', $ordenId)) {
            return $no('Identificador de orden no válido.');
        }

        $r = self::llamar('POST', '/v2/checkout/orders/' . $ordenId . '/capture', '{}');
        $cuerpo = $r['cuerpo'];

        if (($cuerpo['status'] ?? '') !== 'COMPLETED') {
            return $no('PayPal no confirmó el pago (estado: ' . (string)($cuerpo['status'] ?? 'desconocido') . ').');
        }

        $captura = $cuerpo['purchase_units'][0]['payments']['captures'][0] ?? [];
        if (($captura['status'] ?? '') !== 'COMPLETED') {
            return $no('El cobro quedó pendiente en PayPal.');
        }

        $cobrado  = (float)($captura['amount']['value'] ?? 0);
        $moneda   = (string)($captura['amount']['currency_code'] ?? '');
        $esperado = self::enMonedaDeCobro($totalLocal);

        // Se admite un centavo de diferencia por el redondeo de PayPal, nunca más.
        if ($moneda !== self::moneda() || $cobrado + 0.01 < $esperado) {
            error_log(sprintf('Flowers Anto — PayPal cobró %s %s y se esperaban %s %s (orden %s)',
                              $cobrado, $moneda, $esperado, self::moneda(), $ordenId));
            return $no('El importe cobrado no coincide con el del pedido.');
        }

        return [
            'ok'      => true,
            'captura' => (string)($captura['id'] ?? ''),
            'importe' => $cobrado,
            'moneda'  => $moneda,
            'error'   => '',
        ];
    }

    /**
     * Llamada HTTP a PayPal.
     *
     * @return array{codigo:int, cuerpo:array}
     */
    private static function llamar(string $metodo, string $ruta, string $cuerpo,
                                   ?array $cabeceras = null, bool $conToken = true): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('El servidor no tiene cURL, que es lo que usa PayPal.');
        }

        $cabeceras ??= [
            'Content-Type: application/json',
            'Authorization: Bearer ' . self::token(),
        ];

        $ch = curl_init(self::base() . $ruta);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $metodo,
            CURLOPT_POSTFIELDS     => $cuerpo,
            CURLOPT_HTTPHEADER     => $cabeceras,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIEMPO_ESPERA,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $respuesta = curl_exec($ch);
        $codigo    = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $fallo     = curl_error($ch);
        curl_close($ch);

        if ($respuesta === false) {
            throw new RuntimeException('No se pudo conectar con PayPal: ' . $fallo);
        }
        $json = json_decode((string)$respuesta, true);
        if (!is_array($json)) {
            throw new RuntimeException('PayPal respondió algo que no se pudo leer.');
        }
        if ($codigo >= 400) {
            $detalle = (string)($json['message'] ?? $json['error_description'] ?? 'error ' . $codigo);
            error_log('Flowers Anto — PayPal ' . $ruta . ': ' . $detalle);
            throw new RuntimeException('PayPal rechazó la operación: ' . $detalle);
        }
        return ['codigo' => $codigo, 'cuerpo' => $json];
    }
}
