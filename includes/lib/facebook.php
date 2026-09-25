<?php
/**
 * Acceso con Facebook (OAuth 2.0, flujo de código de autorización de Meta:
 * «inicio de sesión creado manualmente»).
 *
 * Igual que Google: cURL contra los endpoints oficiales, sin SDK.
 *
 * Seguridad del flujo:
 *   - `state` aleatorio guardado en la sesión, de un solo uso (anti-CSRF).
 *   - El código se canjea en el servidor con el secreto de la app; el
 *     secreto nunca sale del servidor ni aparece en registros.
 *   - El token se comprueba con /debug_token: tiene que ser de ESTA app, de
 *     tipo usuario, válido, y del mismo usuario que devuelve /me. Así no
 *     sirve un token emitido para otra app.
 *   - /me se pide con appsecret_proof (HMAC del token con el secreto).
 *   - El identificador estable es el id de Facebook para esta app. El correo
 *     nunca basta para entrar en una cuenta ya existente (Facebook no dice si
 *     está verificado): ver CuentasExternas.
 *
 * Configuración (.env): FACEBOOK_APP_ID, FACEBOOK_APP_SECRET y, opcional,
 * FACEBOOK_GRAPH_VERSION (por defecto la de GRAPH_POR_DEFECTO).
 */

declare(strict_types=1);

final class Facebook
{
    /** Versión de la Graph API. Meta mantiene cada versión unos dos años. */
    private const GRAPH_POR_DEFECTO = 'v23.0';

    public static function configurado(): bool
    {
        return preg_match('/^\d{5,20}$/', self::appId()) === 1 && self::secreto() !== '';
    }

    /** ¿Se puede usar? Configurado y con la migración 023 aplicada. */
    public static function disponible(PDO $pdo): bool
    {
        return self::configurado() && CuentasExternas::disponible($pdo, 'facebook');
    }

    private static function appId(): string
    {
        return trim(Entorno::texto('FACEBOOK_APP_ID'));
    }

    private static function secreto(): string
    {
        $s = trim(Entorno::texto('FACEBOOK_APP_SECRET'));
        return preg_match('/^[A-Za-z0-9]{16,64}$/', $s) ? $s : '';
    }

    private static function version(): string
    {
        $v = trim(Entorno::texto('FACEBOOK_GRAPH_VERSION'));
        return preg_match('/^v\d{1,2}\.\d$/', $v) ? $v : self::GRAPH_POR_DEFECTO;
    }

    public static function urlRetorno(): string
    {
        return url_absoluta('cuenta/facebook-callback.php');
    }

    /** URL del diálogo de Facebook. */
    public static function urlAutorizacion(string $volverA = ''): string
    {
        $_SESSION['facebook_state'] = bin2hex(random_bytes(16));
        if ($volverA !== '') {
            $_SESSION['volver_a'] = $volverA;
        }
        return self::destino('https://www.facebook.com/' . self::version() . '/dialog/oauth') . '?' . http_build_query([
            'client_id'     => self::appId(),
            'redirect_uri'  => self::urlRetorno(),
            'state'         => $_SESSION['facebook_state'],
            'response_type' => 'code',
            'scope'         => 'email,public_profile',
        ]);
    }

    /**
     * Canjea el código por el perfil del usuario.
     *
     * @return array{ok: bool, error?: string, perfil?: array}
     */
    public static function perfilDesdeCodigo(string $codigo, string $state): array
    {
        $esperado = (string)($_SESSION['facebook_state'] ?? '');
        unset($_SESSION['facebook_state']);
        if ($esperado === '' || !hash_equals($esperado, $state)) {
            return ['ok' => false, 'error' => 'La sesión con Facebook caducó. Vuelve a intentarlo.'];
        }

        $graph = self::destino('https://graph.facebook.com/' . self::version());

        // 1. Código → token de acceso (en el servidor, con el secreto).
        $token = self::peticion($graph . '/oauth/access_token', [
            'client_id'     => self::appId(),
            'redirect_uri'  => self::urlRetorno(),
            'client_secret' => self::secreto(),
            'code'          => $codigo,
        ]);
        $acceso = is_string($token['access_token'] ?? null) ? $token['access_token'] : '';
        if ($acceso === '') {
            return ['ok' => false, 'error' => 'Facebook no confirmó el acceso. Inténtalo de nuevo.'];
        }

        // 2. ¿El token es de esta app, de un usuario y válido?
        $debug = self::peticion($graph . '/debug_token', [
            'input_token'  => $acceso,
            'access_token' => self::appId() . '|' . self::secreto(),
        ]);
        $d = is_array($debug['data'] ?? null) ? $debug['data'] : [];
        if (($d['is_valid'] ?? false) !== true || (string)($d['app_id'] ?? '') !== self::appId()
            || (($d['type'] ?? 'USER') !== 'USER') || (string)($d['user_id'] ?? '') === ''
            || (isset($d['expires_at']) && (int)$d['expires_at'] !== 0 && (int)$d['expires_at'] < time())) {
            return ['ok' => false, 'error' => 'No pudimos verificar la respuesta de Facebook.'];
        }

        // 3. Perfil, con appsecret_proof.
        $yo = self::peticion($graph . '/me', [
            'fields'          => 'id,first_name,last_name,email,picture.type(large)',
            'access_token'    => $acceso,
            'appsecret_proof' => hash_hmac('sha256', $acceso, self::secreto()),
        ]);
        $id = (string)($yo['id'] ?? '');
        if (!preg_match('/^\d{1,64}$/', $id) || !hash_equals((string)$d['user_id'], $id)) {
            return ['ok' => false, 'error' => 'No pudimos verificar la respuesta de Facebook.'];
        }

        $email = mb_strtolower(trim((string)($yo['email'] ?? '')));
        return ['ok' => true, 'perfil' => [
            'id'               => $id,
            'email'            => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '',
            // Facebook no indica si el correo está verificado: una cuenta nueva
            // lo confirma con el enlace de siempre, y nunca sirve para entrar
            // en una cuenta ya existente con ese correo.
            'email_verificado' => false,
            'email_vinculable' => false,
            'nombre'           => (string)($yo['first_name'] ?? ''),
            'apellido'         => (string)($yo['last_name'] ?? ''),
            'avatar'           => (string)($yo['picture']['data']['url'] ?? ''),
        ]];
    }

    /**
     * GET a la Graph API con respuesta JSON. La URL lleva el secreto o el
     * token en la consulta (así lo documenta Meta), por eso nunca se registra:
     * en el registro solo va la ruta y el código de error de Meta.
     */
    private static function peticion(string $url, array $consulta): ?array
    {
        if (!function_exists('curl_init')) {
            error_log('Flowers Anto — falta la extensión cURL: el acceso con Facebook no puede funcionar.');
            return null;
        }
        $ch = curl_init($url . '?' . http_build_query($consulta));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $cuerpo = curl_exec($ch);
        $codigo = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $fallo  = curl_error($ch);

        $datos = is_string($cuerpo) ? json_decode($cuerpo, true) : null;
        if ($cuerpo === false || $codigo >= 400 || !is_array($datos)) {
            $error = is_array($datos['error'] ?? null) ? $datos['error'] : [];
            error_log(sprintf('Flowers Anto — Facebook (%s, HTTP %d): %s %s', (string)parse_url($url, PHP_URL_PATH), $codigo,
                preg_replace('/[^\w .,:()-]/u', '', (string)($error['type'] ?? '')) ?? '',
                $fallo !== '' ? $fallo : mb_substr(preg_replace('/[^\w .,:()-]/u', '', (string)($error['message'] ?? '')) ?? '', 0, 200)));
            return null;
        }
        return $datos;
    }

    /** Igual que en Google: en desarrollo, las llamadas pueden ir al simulador local. */
    private static function destino(string $url): string
    {
        $sim = rtrim(Entorno::texto('OAUTH_SIMULADOR'), '/');
        if ($sim !== '' && defined('ENTORNO') && ENTORNO === 'dev'
            && preg_match('#^http://127\.0\.0\.1:\d+$#', $sim)) {
            return $sim . '/facebook' . (string)parse_url($url, PHP_URL_PATH);
        }
        return $url;
    }
}
