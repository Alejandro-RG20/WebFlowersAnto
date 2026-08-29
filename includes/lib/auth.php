<?php
/**
 * Autenticación.
 *
 * Una sola tabla `usuarios` para clientes y personal; los separa el rol.
 * La sesión guarda únicamente el id: los datos y los permisos se releen en
 * cada petición, para que desactivar una cuenta o cambiarle el rol surta
 * efecto de inmediato y no al caducar la sesión.
 */

declare(strict_types=1);

final class Auth
{
    private static ?PDO $pdo = null;
    private static ?array $usuario = null;
    private static bool $cargado = false;

    public static function iniciar(PDO $pdo): void
    {
        self::$pdo = $pdo;
        Ajustes::usar($pdo);
    }

    /** Usuario autenticado, o null. */
    public static function usuario(): ?array
    {
        if (self::$cargado) {
            return self::$usuario;
        }
        self::$cargado = true;

        $id = (int)($_SESSION['usuario_id'] ?? 0);
        if ($id <= 0 || !self::$pdo) {
            return self::$usuario = null;
        }

        try {
            $st = self::$pdo->prepare(
                "SELECT u.*, r.codigo AS rol_codigo, r.nombre AS rol_nombre, r.es_personal
                   FROM usuarios u
              LEFT JOIN roles r ON r.id = u.rol_id
                  WHERE u.id = ? AND u.activo = 1"
            );
            $st->execute([$id]);
            $fila = $st->fetch();
        } catch (PDOException) {
            return self::$usuario = null;
        }

        if (!$fila) {
            // La cuenta se desactivó o se borró mientras la sesión seguía abierta.
            self::cerrarSesion();
            return self::$usuario = null;
        }

        return self::$usuario = $fila;
    }

    public static function id(): ?int
    {
        $u = self::usuario();
        return $u ? (int)$u['id'] : null;
    }

    public static function autenticado(): bool
    {
        return self::usuario() !== null;
    }

    /** ¿Es personal de la tienda (puede entrar al panel)? */
    public static function esPersonal(): bool
    {
        $u = self::usuario();
        return $u !== null && (int)($u['es_personal'] ?? 0) === 1;
    }

    public static function nombreCompleto(?array $u = null): string
    {
        $u ??= self::usuario();
        if (!$u) {
            return 'Visitante';
        }
        $n = trim(($u['nombre'] ?? '') . ' ' . ($u['apellido'] ?? ''));
        return $n !== '' ? $n : (string)($u['nombre_completo'] ?? $u['username'] ?? 'Usuario');
    }

    /** Abre la sesión del usuario indicado. */
    public static function abrirSesion(array $usuario): void
    {
        session_regenerate_id(true);
        $_SESSION['usuario_id']    = (int)$usuario['id'];
        $_SESSION['sesion_creada'] = time();
        self::$usuario = null;
        self::$cargado = false;

        self::$pdo?->prepare(
            "UPDATE usuarios SET ultimo_acceso = NOW(), intentos_fallidos = 0, bloqueado_hasta = NULL WHERE id = ?"
        )->execute([$usuario['id']]);
    }

    public static function cerrarSesion(): void
    {
        $_SESSION = [];
        if (PHP_SAPI !== 'cli' && ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $p['path'],
                'domain'   => $p['domain'],
                'secure'   => $p['secure'],
                'httponly' => $p['httponly'],
                'samesite' => $p['samesite'] ?? 'Lax',
            ]);
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        self::$usuario = null;
        self::$cargado = true;
    }

    /**
     * Busca un usuario por correo o por nombre de usuario.
     * El personal heredado de la versión anterior entra con `username`.
     */
    public static function buscarPorIdentificador(PDO $pdo, string $identificador): ?array
    {
        $st = $pdo->prepare(
            "SELECT u.*, r.codigo AS rol_codigo, r.nombre AS rol_nombre, r.es_personal
               FROM usuarios u
          LEFT JOIN roles r ON r.id = u.rol_id
              WHERE u.email = ? OR u.username = ?
              LIMIT 1"
        );
        $st->execute([mb_strtolower($identificador), $identificador]);
        return $st->fetch() ?: null;
    }

    /** Comprueba la contraseña y re-hashea si el algoritmo quedó obsoleto. */
    public static function verificarPassword(PDO $pdo, array $usuario, string $password): bool
    {
        $hash = (string)($usuario['password_hash'] ?? '');
        if ($hash === '') {
            return false; // cuenta solo de Google, o pendiente de restablecer
        }
        if (!password_verify($password, $hash)) {
            return false;
        }
        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            $pdo->prepare("UPDATE usuarios SET password_hash = ? WHERE id = ?")
                ->execute([password_hash($password, PASSWORD_DEFAULT), $usuario['id']]);
        }
        return true;
    }

    /** ¿La cuenta está bloqueada temporalmente por intentos fallidos? */
    public static function bloqueado(array $usuario): bool
    {
        return !empty($usuario['bloqueado_hasta'])
            && strtotime((string)$usuario['bloqueado_hasta']) > time();
    }

    /** Suma un intento fallido y bloquea al llegar al máximo. */
    public static function anotarFallo(PDO $pdo, array $usuario, int $maximo = 5, int $minutos = 15): void
    {
        $intentos = (int)$usuario['intentos_fallidos'] + 1;
        $hasta    = $intentos >= $maximo ? date('Y-m-d H:i:s', time() + $minutos * 60) : null;
        $pdo->prepare("UPDATE usuarios SET intentos_fallidos = ?, bloqueado_hasta = ? WHERE id = ?")
            ->execute([$intentos >= $maximo ? 0 : $intentos, $hasta, $usuario['id']]);
    }

    /** Id del rol por su código. */
    public static function rolId(PDO $pdo, string $codigo): ?int
    {
        $st = $pdo->prepare("SELECT id FROM roles WHERE codigo = ?");
        $st->execute([$codigo]);
        $id = $st->fetchColumn();
        return $id === false ? null : (int)$id;
    }

    /**
     * Exige sesión de cliente. Si no la hay, guarda a dónde quería ir
     * y manda al inicio de sesión.
     */
    public static function exigirSesion(string $volverA = ''): void
    {
        if (self::autenticado()) {
            return;
        }
        $_SESSION['volver_a'] = $volverA !== '' ? $volverA : ($_SERVER['REQUEST_URI'] ?? '/');
        flash('info', 'Inicia sesión para continuar.');
        redirigir('cuenta/entrar.php');
    }
}
