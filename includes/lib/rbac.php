<?php
/**
 * Control de acceso por roles.
 *
 * Los permisos se comprueban SIEMPRE en el servidor, antes de ejecutar la
 * acción. Ocultar un botón en el panel es solo cortesía visual: la puerta real
 * es `exigirPermiso()` al principio de cada operación de escritura.
 */

declare(strict_types=1);

final class Rbac
{
    private static ?array $permisos = null;

    /** Códigos de permiso del usuario actual. */
    public static function permisos(PDO $pdo): array
    {
        if (self::$permisos !== null) {
            return self::$permisos;
        }
        $u = Auth::usuario();
        if (!$u || empty($u['rol_id'])) {
            return self::$permisos = [];
        }
        try {
            $st = $pdo->prepare(
                "SELECT p.codigo FROM rol_permisos rp
                   JOIN permisos p ON p.id = rp.permiso_id
                  WHERE rp.rol_id = ?"
            );
            $st->execute([$u['rol_id']]);
            return self::$permisos = $st->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException) {
            return self::$permisos = [];
        }
    }

    /** ¿El usuario actual tiene este permiso? */
    public static function puede(string $codigo): bool
    {
        $pdo = $GLOBALS['pdo'] ?? null;
        if (!$pdo instanceof PDO || !Auth::autenticado()) {
            return false;
        }
        return in_array($codigo, self::permisos($pdo), true);
    }

    /** ¿Tiene al menos uno de los permisos indicados? */
    public static function puedeAlguno(string ...$codigos): bool
    {
        foreach ($codigos as $c) {
            if (self::puede($c)) {
                return true;
            }
        }
        return false;
    }

    public static function esSuperAdmin(): bool
    {
        return (Auth::usuario()['rol_codigo'] ?? '') === 'super_admin';
    }

    /**
     * Corta la petición si falta el permiso. Deja constancia en la auditoría.
     *
     * @param bool $json true en endpoints de API, false en páginas del panel.
     */
    public static function exigir(string $codigo, bool $json = false): void
    {
        if (self::puede($codigo)) {
            return;
        }
        $pdo = $GLOBALS['pdo'] ?? null;
        if ($pdo instanceof PDO && Auth::autenticado()) {
            Auditoria::denegado($pdo, $codigo);
        }
        if ($json) {
            errorJson('No tienes permiso para esta acción.', 403);
        }
        http_response_code(403);
        require RAIZ . '/includes/vistas/error_permiso.php';
        exit;
    }

    /** Exige sesión de personal con acceso al panel. */
    public static function exigirPanel(bool $json = false): void
    {
        if (!Auth::autenticado()) {
            if ($json) {
                errorJson('Tu sesión expiró. Vuelve a iniciar sesión.', 401);
            }
            $_SESSION['volver_a'] = $_SERVER['REQUEST_URI'] ?? '';
            redirigir('admin/entrar.php');
        }
        if (!Auth::esPersonal()) {
            $pdo = $GLOBALS['pdo'] ?? null;
            if ($pdo instanceof PDO) {
                Auditoria::denegado($pdo, 'panel.acceder');
            }
            if ($json) {
                errorJson('Esta cuenta no tiene acceso al panel.', 403);
            }
            flash('error', 'Esta cuenta no tiene acceso al panel de administración.');
            redirigir('/');
        }
        self::exigir('panel.acceder', $json);
    }

    /** Vacía la caché de permisos (tras cambiar el rol de alguien). */
    public static function refrescar(): void
    {
        self::$permisos = null;
    }
}

/** Atajo legible para las plantillas: `<?php if (puede('pedidos.ver')): ?>` */
function puede(string $codigo): bool
{
    return Rbac::puede($codigo);
}
