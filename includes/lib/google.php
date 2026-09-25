<?php
/**
 * Acceso con Google (OAuth 2.0, flujo de código de autorización).
 *
 * Implementado con cURL contra los endpoints oficiales en vez de con la
 * librería de Google: el proyecto no usa Composer y esto son cien líneas.
 *
 * Seguridad del flujo:
 *   - parámetro `state` aleatorio guardado en la sesión (anti-CSRF)
 *   - `nonce` incluido en la petición y verificado en el id_token
 *   - el id_token llega directamente del endpoint de tokens por TLS con el
 *     secreto del cliente, y además se valida contra tokeninfo de Google
 *     (firma y caducidad); aquí se comprueban aud, iss, exp y nonce
 *   - solo se acepta la cuenta si Google la da por verificada
 *   - la vinculación con cuentas existentes la decide CuentasExternas: solo
 *     sola si Google es la autoridad de ese correo (gmail o Workspace)
 *
 * En desarrollo, OAUTH_SIMULADOR=http://127.0.0.1:puerto manda las llamadas a
 * un simulador local (tests/auth/simulador-oauth.js). Fuera de desarrollo se
 * ignora siempre.
 */

declare(strict_types=1);

final class Google
{
    private const AUTORIZAR = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN     = 'https://oauth2.googleapis.com/token';
    private const TOKENINFO = 'https://oauth2.googleapis.com/tokeninfo';
    private const EMISORES  = ['accounts.google.com', 'https://accounts.google.com'];

    public static function configurado(): bool
    {
        return Entorno::texto('GOOGLE_CLIENT_ID') !== ''
            && Entorno::texto('GOOGLE_CLIENT_SECRET') !== '';
    }

    public static function urlRetorno(): string
    {
        return url_absoluta('cuenta/google-callback.php');
    }

    /** URL a la que se manda al usuario para que elija su cuenta de Google. */
    public static function urlAutorizacion(string $volverA = ''): string
    {
        $_SESSION['google_state'] = bin2hex(random_bytes(16));
        $_SESSION['google_nonce'] = bin2hex(random_bytes(16));
        if ($volverA !== '') {
            $_SESSION['volver_a'] = $volverA;
        }

        return self::destino(self::AUTORIZAR) . '?' . http_build_query([
            'client_id'     => Entorno::texto('GOOGLE_CLIENT_ID'),
            'redirect_uri'  => self::urlRetorno(),
            'response_type' => 'code',
            'scope'         => 'openid email profile',
            'state'         => $_SESSION['google_state'],
            'nonce'         => $_SESSION['google_nonce'],
            'prompt'        => 'select_account',
        ]);
    }

    /**
     * Canjea el código por el perfil del usuario.
     *
     * @return array{ok: bool, error?: string, perfil?: array}
     */
    public static function perfilDesdeCodigo(string $codigo, string $state): array
    {
        $esperado = $_SESSION['google_state'] ?? '';
        unset($_SESSION['google_state']);

        if ($esperado === '' || !hash_equals($esperado, $state)) {
            return ['ok' => false, 'error' => 'La sesión con Google caducó. Vuelve a intentarlo.'];
        }

        $respuesta = self::peticion(self::destino(self::TOKEN), [
            'code'          => $codigo,
            'client_id'     => Entorno::texto('GOOGLE_CLIENT_ID'),
            'client_secret' => Entorno::texto('GOOGLE_CLIENT_SECRET'),
            'redirect_uri'  => self::urlRetorno(),
            'grant_type'    => 'authorization_code',
        ]);

        if (!$respuesta || empty($respuesta['id_token'])) {
            return ['ok' => false, 'error' => 'Google no confirmó el acceso. Inténtalo de nuevo.'];
        }

        // Google valida el token por nosotros: firma, caducidad y emisor.
        $datos = self::peticion(self::destino(self::TOKENINFO) . '?id_token=' . urlencode((string)$respuesta['id_token']), null);
        if (!$datos || empty($datos['sub'])) {
            return ['ok' => false, 'error' => 'No pudimos verificar la respuesta de Google.'];
        }

        if (($datos['aud'] ?? '') !== Entorno::texto('GOOGLE_CLIENT_ID')) {
            return ['ok' => false, 'error' => 'La respuesta de Google no corresponde a este sitio.'];
        }
        // Emisor y caducidad. tokeninfo ya rechaza un token caducado; se
        // comprueba aquí también para no depender solo de él.
        if (!in_array($datos['iss'] ?? '', self::EMISORES, true) || (int)($datos['exp'] ?? 0) < time()) {
            return ['ok' => false, 'error' => 'La respuesta de Google no es válida. Vuelve a intentarlo.'];
        }

        // El nonce se exige siempre (antes, si faltaba en la sesión, no se
        // comprobaba): es lo que ata el id_token a esta misma petición.
        $nonce = (string)($_SESSION['google_nonce'] ?? '');
        unset($_SESSION['google_nonce']);
        if ($nonce === '' || !hash_equals($nonce, (string)($datos['nonce'] ?? ''))) {
            return ['ok' => false, 'error' => 'La respuesta de Google no coincide con la petición.'];
        }

        $verificado = ($datos['email_verified'] ?? 'false');
        if ($verificado !== true && $verificado !== 'true') {
            return ['ok' => false, 'error' => 'Tu correo de Google no está verificado. Usa el registro normal.'];
        }

        // Google es la autoridad del correo solo si es de Gmail o de Google
        // Workspace (claim `hd`). Una cuenta de Google creada con un correo de
        // otro proveedor lo tuvo verificado al crearla, pero Google no sabe si
        // esa persona lo sigue controlando: no basta para tomar una cuenta ya
        // existente con ese correo.
        $email = mb_strtolower((string)($datos['email'] ?? ''));
        $dominio = substr((string)strrchr($email, '@'), 1);
        $autoridad = in_array($dominio, ['gmail.com', 'googlemail.com'], true) || (string)($datos['hd'] ?? '') !== '';

        return ['ok' => true, 'perfil' => [
            'id'               => (string)$datos['sub'],
            'email'            => $email,
            'email_verificado' => true,
            'email_vinculable' => $autoridad,
            'nombre'           => (string)($datos['given_name']  ?? ''),
            'apellido'         => (string)($datos['family_name'] ?? ''),
            'avatar'           => (string)($datos['picture']     ?? ''),
        ]];
    }

    /** POST (o GET si $campos es null) con cURL y respuesta JSON. */
    private static function peticion(string $url, ?array $campos): ?array
    {
        if (!function_exists('curl_init')) {
            error_log('Flowers Anto — falta la extensión cURL: el acceso con Google no puede funcionar.');
            return null;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        if ($campos !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($campos));
        }
        $cuerpo = curl_exec($ch);
        $codigo = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $fallo  = curl_error($ch);
        curl_close($ch);

        if ($cuerpo === false || $codigo >= 400) {
            error_log('Flowers Anto — Google OAuth (' . $codigo . '): ' . ($fallo ?: substr((string)$cuerpo, 0, 300)));
            return null;
        }
        $datos = json_decode((string)$cuerpo, true);
        return is_array($datos) ? $datos : null;
    }

    /**
     * Busca, vincula o crea la cuenta local. La lógica (y sus reglas de
     * seguridad) es común a Google y Facebook: ver CuentasExternas.
     *
     * @return array{ok: bool, usuario?: array, error?: string}
     */
    public static function vincularUsuario(PDO $pdo, array $perfil): array
    {
        return CuentasExternas::entrar($pdo, 'google', $perfil);
    }

    /**
     * En desarrollo, las llamadas pueden ir a un simulador local para probar
     * el flujo completo sin Google. En cualquier otro entorno, la URL real.
     */
    private static function destino(string $url): string
    {
        $sim = rtrim(Entorno::texto('OAUTH_SIMULADOR'), '/');
        if ($sim !== '' && defined('ENTORNO') && ENTORNO === 'dev'
            && preg_match('#^http://127\.0\.0\.1:\d+$#', $sim)) {
            return $sim . '/google' . (string)parse_url($url, PHP_URL_PATH);
        }
        return $url;
    }
}
