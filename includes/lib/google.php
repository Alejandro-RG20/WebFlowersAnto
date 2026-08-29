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
 *   - el id_token se valida contra el endpoint tokeninfo de Google, así que
 *     no hace falta implementar la verificación de firmas JWT a mano
 *   - solo se acepta la cuenta si Google la da por verificada
 */

declare(strict_types=1);

final class Google
{
    private const AUTORIZAR = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN     = 'https://oauth2.googleapis.com/token';
    private const TOKENINFO = 'https://oauth2.googleapis.com/tokeninfo';

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

        return self::AUTORIZAR . '?' . http_build_query([
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

        $respuesta = self::peticion(self::TOKEN, [
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
        $datos = self::peticion(self::TOKENINFO . '?id_token=' . urlencode((string)$respuesta['id_token']), null);
        if (!$datos || empty($datos['sub'])) {
            return ['ok' => false, 'error' => 'No pudimos verificar la respuesta de Google.'];
        }

        if (($datos['aud'] ?? '') !== Entorno::texto('GOOGLE_CLIENT_ID')) {
            return ['ok' => false, 'error' => 'La respuesta de Google no corresponde a este sitio.'];
        }

        $nonce = $_SESSION['google_nonce'] ?? '';
        unset($_SESSION['google_nonce']);
        if ($nonce !== '' && ($datos['nonce'] ?? '') !== $nonce) {
            return ['ok' => false, 'error' => 'La respuesta de Google no coincide con la petición.'];
        }

        $verificado = ($datos['email_verified'] ?? 'false');
        if ($verificado !== true && $verificado !== 'true') {
            return ['ok' => false, 'error' => 'Tu correo de Google no está verificado. Usa el registro normal.'];
        }

        return ['ok' => true, 'perfil' => [
            'id'       => (string)$datos['sub'],
            'email'    => mb_strtolower((string)($datos['email'] ?? '')),
            'nombre'   => (string)($datos['given_name']  ?? ''),
            'apellido' => (string)($datos['family_name'] ?? ''),
            'avatar'   => (string)($datos['picture']     ?? ''),
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
     * Busca o crea la cuenta local a partir del perfil de Google.
     * Si ya existe una cuenta con ese correo, se enlaza en vez de duplicar.
     */
    public static function vincularUsuario(PDO $pdo, array $perfil): ?array
    {
        $st = $pdo->prepare("SELECT * FROM usuarios WHERE google_id = ? LIMIT 1");
        $st->execute([$perfil['id']]);
        $usuario = $st->fetch();

        if (!$usuario && $perfil['email'] !== '') {
            $st = $pdo->prepare("SELECT * FROM usuarios WHERE email = ? LIMIT 1");
            $st->execute([$perfil['email']]);
            $usuario = $st->fetch();

            if ($usuario) {
                $pdo->prepare("UPDATE usuarios SET google_id = ?, email_verificado_en = NOW() WHERE id = ?")
                    ->execute([$perfil['id'], $usuario['id']]);
            }
        }

        if (!$usuario) {
            if ($perfil['email'] === '') {
                return null;
            }
            $nombre   = $perfil['nombre']   !== '' ? $perfil['nombre']   : strstr($perfil['email'], '@', true);
            $apellido = $perfil['apellido'];

            $pdo->prepare(
                "INSERT INTO usuarios (email, nombre, apellido, google_id, avatar_url, rol_id,
                                       activo, nombre_completo, email_verificado_en, password_hash)
                 VALUES (?,?,?,?,?,?,1,?,NOW(),NULL)"
            )->execute([
                $perfil['email'], mb_substr((string)$nombre, 0, 60), mb_substr($apellido, 0, 60),
                $perfil['id'], mb_substr($perfil['avatar'], 0, 255),
                Auth::rolId($pdo, 'cliente'),
                trim($nombre . ' ' . $apellido),
            ]);
            $id = (int)$pdo->lastInsertId();

            Auditoria::registrar($pdo, 'registro_google', 'usuarios', [
                'recurso_tipo' => 'usuario', 'recurso_id' => (string)$id,
                'descripcion'  => 'Cuenta creada mediante Google.',
            ]);
        }

        $st = $pdo->prepare(
            "SELECT u.*, r.codigo AS rol_codigo, r.nombre AS rol_nombre, r.es_personal
               FROM usuarios u LEFT JOIN roles r ON r.id = u.rol_id
              WHERE u.google_id = ? OR u.email = ? LIMIT 1"
        );
        $st->execute([$perfil['id'], $perfil['email']]);
        $usuario = $st->fetch();

        return ($usuario && (int)$usuario['activo'] === 1) ? $usuario : null;
    }
}
