<?php
/**
 * Cuentas de Google y Facebook asociadas a una cuenta de la tienda.
 *
 * Una sola tabla `usuarios`: cada proveedor guarda su identificador estable
 * (el `sub` de Google, el id de Facebook para esta app) en su columna. La
 * sesión, los roles y el resto del sistema no distinguen cómo entró nadie.
 *
 * Reglas para no confundir identidades ni permitir que alguien se quede con
 * una cuenta ajena («mismo correo» no basta para ser la misma persona):
 *
 *   1. Se busca primero por el identificador del proveedor. Es la única forma
 *      de entrar sin más comprobaciones.
 *   2. Si no está vinculado y ya hay una cuenta con ese correo, se vincula
 *      sola SOLO si el proveedor es la autoridad de ese correo (Google con
 *      @gmail.com o una cuenta de Google Workspace). Facebook nunca: no dice
 *      si el correo está verificado. En los demás casos se explica que entre
 *      como siempre y conecte el proveedor desde «Mis datos».
 *   3. Si esa cuenta existente nunca confirmó su correo, quien la creó no
 *      demostró ser el dueño de la dirección. Al vincularla el dueño real, la
 *      contraseña y las otras identidades de esa cuenta se anulan y se
 *      cierran sus sesiones (ataque de «cuenta preparada de antemano»).
 *   4. Una cuenta nueva solo se crea si el proveedor da un correo.
 *   5. Vincular o desvincular desde «Mis datos» exige la sesión abierta y,
 *      si la cuenta tiene contraseña, escribirla; se avisa por correo.
 */

declare(strict_types=1);

final class CuentasExternas
{
    public const PROVEEDORES = [
        'google'   => ['columna' => 'google_id',   'nombre' => 'Google'],
        'facebook' => ['columna' => 'facebook_id', 'nombre' => 'Facebook'],
    ];

    /** Minutos que dura el permiso para vincular tras escribir la contraseña. */
    private const MINUTOS_VINCULAR = 10;

    /** Peticiones a un proveedor que pueden estar abiertas a la vez, y su vida. */
    private const PETICIONES_MAX = 5;
    private const PETICION_SEGUNDOS = 600;

    private static array $columnas = [];

    /** ¿Existe la columna del proveedor? (facebook_id llega con la migración 023) */
    public static function disponible(PDO $pdo, string $proveedor): bool
    {
        $columna = self::PROVEEDORES[$proveedor]['columna'] ?? '';
        if ($columna === '') {
            return false;
        }
        if (!array_key_exists($columna, self::$columnas)) {
            try {
                $pdo->query("SELECT `$columna` FROM usuarios LIMIT 0");
                self::$columnas[$columna] = true;
            } catch (PDOException) {
                self::$columnas[$columna] = false;
            }
        }
        return self::$columnas[$columna];
    }

    // ---------------------------------------------------------------------
    // Peticiones abiertas (state y, en Google, nonce)
    // ---------------------------------------------------------------------

    /**
     * Abre una petición al proveedor y devuelve su `state`.
     *
     * Antes había un único hueco por proveedor: en el móvil, un doble toque,
     * volver atrás y pulsar otra vez o tener dos pestañas pisaban el state
     * de la primera, y cuando el proveedor volvía con ella la tienda la
     * rechazaba («la sesión caducó») y había que empezar de nuevo. Ahora se
     * guardan las últimas PETICIONES_MAX, cada una de un solo uso y con
     * caducidad: la protección contra CSRF es la misma.
     */
    public static function abrirPeticion(string $proveedor, array $datos = []): string
    {
        $state = bin2hex(random_bytes(16));
        $lista = self::peticionesVigentes($proveedor);
        $lista[$state] = ['hasta' => time() + self::PETICION_SEGUNDOS] + $datos;
        $_SESSION['oauth'][$proveedor] = array_slice($lista, -self::PETICIONES_MAX, null, true);
        return $state;
    }

    /** Cierra (consume) la petición de ese `state`. Devuelve sus datos o null. */
    public static function cerrarPeticion(string $proveedor, string $state): ?array
    {
        $lista = self::peticionesVigentes($proveedor);
        $datos = null;
        foreach ($lista as $clave => $peticion) {
            if ($state !== '' && hash_equals((string)$clave, $state)) {
                $datos = $peticion;
                unset($lista[$clave]);
            }
        }
        $_SESSION['oauth'][$proveedor] = $lista;
        return $datos;
    }

    private static function peticionesVigentes(string $proveedor): array
    {
        $lista = $_SESSION['oauth'][$proveedor] ?? [];
        return is_array($lista)
            ? array_filter($lista, fn($p) => is_array($p) && (int)($p['hasta'] ?? 0) >= time())
            : [];
    }

    // ---------------------------------------------------------------------
    // Inicio de sesión con el proveedor
    // ---------------------------------------------------------------------

    /**
     * Busca, vincula o crea la cuenta local para una identidad externa.
     *
     * @param array $perfil id, email, email_verificado (bool),
     *                      email_vinculable (bool), nombre, apellido, avatar
     * @return array{ok: bool, usuario?: array, error?: string}
     */
    public static function entrar(PDO $pdo, string $proveedor, array $perfil): array
    {
        $columna = self::PROVEEDORES[$proveedor]['columna'];
        $nombreProveedor = self::PROVEEDORES[$proveedor]['nombre'];
        $idExterno = (string)$perfil['id'];
        $email = mb_strtolower(trim((string)($perfil['email'] ?? '')));

        // 1. Ya vinculada.
        $st = $pdo->prepare("SELECT id FROM usuarios WHERE `$columna` = ? LIMIT 1");
        $st->execute([$idExterno]);
        $id = (int)$st->fetchColumn();

        if ($id === 0) {
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return ['ok' => false, 'error' => "{$nombreProveedor} no nos compartió tu correo electrónico. "
                    . 'Vuelve a intentarlo y deja marcada «Dirección de correo electrónico» al dar permiso, '
                    . 'o crea tu cuenta con tu correo.'];
            }

            $st = $pdo->prepare("SELECT * FROM usuarios WHERE email = ? LIMIT 1");
            $st->execute([$email]);
            $existente = $st->fetch();

            if ($existente) {
                // 2. Cuenta con ese correo, sin este proveedor.
                if (!empty($existente[$columna])) {
                    return ['ok' => false, 'error' => "Ese correo ya está conectado a otra cuenta de {$nombreProveedor}."];
                }
                if (empty($perfil['email_vinculable'])) {
                    return ['ok' => false, 'error' => 'Ya existe una cuenta con ese correo. Entra con tu contraseña '
                        . "(o como entras siempre) y conecta {$nombreProveedor} desde «Mis datos»."];
                }
                self::vincularYSanear($pdo, $proveedor, $idExterno, $existente);
                $id = (int)$existente['id'];
            } else {
                // 4. Cuenta nueva.
                $id = self::crear($pdo, $proveedor, $perfil, $email);
                if ($id === 0) {
                    return ['ok' => false, 'error' => 'No pudimos crear tu cuenta. Vuelve a intentarlo.'];
                }
            }
        }

        $usuario = self::porId($pdo, $id);
        if (!$usuario || (int)$usuario['activo'] !== 1) {
            return ['ok' => false, 'error' => 'No pudimos abrir tu cuenta. Escríbenos y lo revisamos.'];
        }
        return ['ok' => true, 'usuario' => $usuario];
    }

    /** Vincula el proveedor a una cuenta existente y aplica la regla 3. */
    private static function vincularYSanear(PDO $pdo, string $proveedor, string $idExterno, array $cuenta): void
    {
        $columna = self::PROVEEDORES[$proveedor]['columna'];
        $sinConfirmar = empty($cuenta['email_verificado_en']);

        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE usuarios SET `$columna` = ?, email_verificado_en = COALESCE(email_verificado_en, NOW())
                            WHERE id = ?")->execute([$idExterno, $cuenta['id']]);
            if ($sinConfirmar) {
                $otras = [];
                foreach (self::PROVEEDORES as $p => $d) {
                    if ($p !== $proveedor && self::disponible($pdo, $p)) {
                        $otras[] = "`{$d['columna']}` = NULL";
                    }
                }
                $pdo->prepare("UPDATE usuarios SET password_hash = NULL" . ($otras ? ', ' . implode(', ', $otras) : '')
                              . " WHERE id = ?")->execute([$cuenta['id']]);
                $pdo->prepare("UPDATE password_resets SET usado_en = NOW() WHERE usuario_id = ? AND usado_en IS NULL")
                    ->execute([$cuenta['id']]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        if ($sinConfirmar) {
            Auth::cerrarOtrasSesiones($pdo, (int)$cuenta['id']);
        }

        Auditoria::registrar($pdo, 'vincular_' . $proveedor, 'usuarios', [
            'recurso_tipo' => 'usuario', 'recurso_id' => (string)$cuenta['id'],
            'descripcion'  => self::PROVEEDORES[$proveedor]['nombre'] . ' vinculado al entrar'
                            . ($sinConfirmar ? ' (el correo no estaba confirmado: se anularon la contraseña y otros accesos)' : '') . '.',
        ]);
        self::avisar($cuenta, $proveedor, true);
    }

    private static function crear(PDO $pdo, string $proveedor, array $perfil, string $email): int
    {
        $columna  = self::PROVEEDORES[$proveedor]['columna'];
        // Igual que el formulario de alta (texto()): sin etiquetas.
        $nombre   = trim(strip_tags((string)($perfil['nombre'] ?? '')));
        $nombre   = $nombre !== '' ? $nombre : (string)strstr($email, '@', true);
        $apellido = trim(strip_tags((string)($perfil['apellido'] ?? '')));
        $verificado = !empty($perfil['email_verificado']);
        $avatar   = (string)($perfil['avatar'] ?? '');
        $avatar   = preg_match('#^https://#i', $avatar) ? mb_substr($avatar, 0, 255) : null;

        try {
            $pdo->prepare(
                "INSERT INTO usuarios (email, nombre, apellido, `$columna`, avatar_url, rol_id,
                                       activo, nombre_completo, email_verificado_en, password_hash)
                 VALUES (?,?,?,?,?,?,1,?," . ($verificado ? 'NOW()' : 'NULL') . ",NULL)"
            )->execute([
                $email, mb_substr($nombre, 0, 60), mb_substr($apellido, 0, 60), (string)$perfil['id'], $avatar,
                Auth::rolId($pdo, 'cliente'), mb_substr(trim($nombre . ' ' . $apellido), 0, 120),
            ]);
        } catch (PDOException $e) {
            if ($e->getCode() !== '23000') {
                throw $e;
            }
            return 0; // dos altas simultáneas con el mismo correo
        }
        $id = (int)$pdo->lastInsertId();

        Auditoria::registrar($pdo, 'registro_' . $proveedor, 'usuarios', [
            'recurso_tipo' => 'usuario', 'recurso_id' => (string)$id,
            'descripcion'  => 'Cuenta creada mediante ' . self::PROVEEDORES[$proveedor]['nombre'] . '.',
        ]);
        if (!$verificado && class_exists('Verificacion')) {
            $nuevo = self::porId($pdo, $id);
            if ($nuevo) {
                Verificacion::enviar($pdo, $nuevo);
            }
        }
        return $id;
    }

    // ---------------------------------------------------------------------
    // Vincular y desvincular desde «Mis datos»
    // ---------------------------------------------------------------------

    /**
     * Permiso para vincular: la cuenta está abierta y, si tiene contraseña,
     * la acaba de escribir. Así una sesión olvidada abierta no basta para que
     * otra persona añada su propia cuenta de Google o Facebook.
     */
    public static function autorizarVinculo(string $proveedor, int $usuarioId): void
    {
        $_SESSION['vincular'] = ['proveedor' => $proveedor, 'usuario' => $usuarioId,
                                 'hasta' => time() + self::MINUTOS_VINCULAR * 60];
    }

    /** Consume el permiso de vincular. Devuelve el id de usuario o 0. */
    public static function consumirVinculo(string $proveedor): int
    {
        $v = $_SESSION['vincular'] ?? null;
        unset($_SESSION['vincular']);
        if (!is_array($v) || ($v['proveedor'] ?? '') !== $proveedor || (int)($v['hasta'] ?? 0) < time()) {
            return 0;
        }
        $id = (int)($v['usuario'] ?? 0);
        return $id > 0 && $id === (int)Auth::id() ? $id : 0;
    }

    /** @return array{ok: bool, mensaje: string} */
    public static function vincular(PDO $pdo, string $proveedor, array $perfil, int $usuarioId): array
    {
        $columna = self::PROVEEDORES[$proveedor]['columna'];
        $nombre  = self::PROVEEDORES[$proveedor]['nombre'];
        $usuario = self::porId($pdo, $usuarioId);
        if (!$usuario) {
            return ['ok' => false, 'mensaje' => 'Tu sesión cambió. Vuelve a intentarlo.'];
        }
        $st = $pdo->prepare("SELECT id FROM usuarios WHERE `$columna` = ? LIMIT 1");
        $st->execute([(string)$perfil['id']]);
        $otro = (int)$st->fetchColumn();
        if ($otro === $usuarioId) {
            return ['ok' => true, 'mensaje' => "Tu cuenta de {$nombre} ya estaba conectada."];
        }
        if ($otro !== 0) {
            return ['ok' => false, 'mensaje' => "Esa cuenta de {$nombre} ya está conectada a otra cuenta de la tienda."];
        }
        if (!empty($usuario[$columna])) {
            return ['ok' => false, 'mensaje' => "Ya tienes otra cuenta de {$nombre} conectada. Desconéctala primero."];
        }
        try {
            $pdo->prepare("UPDATE usuarios SET `$columna` = ? WHERE id = ? AND `$columna` IS NULL")
                ->execute([(string)$perfil['id'], $usuarioId]);
        } catch (PDOException $e) {
            if ($e->getCode() !== '23000') {
                throw $e;
            }
            return ['ok' => false, 'mensaje' => "Esa cuenta de {$nombre} ya está conectada a otra cuenta de la tienda."];
        }
        Auditoria::registrar($pdo, 'vincular_' . $proveedor, 'usuarios', [
            'recurso_tipo' => 'usuario', 'recurso_id' => (string)$usuarioId,
            'descripcion'  => "{$nombre} conectado desde «Mis datos».",
        ]);
        self::avisar($usuario, $proveedor, true);
        return ['ok' => true, 'mensaje' => "Listo: ya puedes entrar con {$nombre}."];
    }

    /** @return array{ok: bool, mensaje: string} */
    public static function desvincular(PDO $pdo, string $proveedor, array $usuario): array
    {
        $columna = self::PROVEEDORES[$proveedor]['columna'];
        $nombre  = self::PROVEEDORES[$proveedor]['nombre'];
        if (empty($usuario[$columna])) {
            return ['ok' => false, 'mensaje' => "No tienes {$nombre} conectado."];
        }
        // Que no se quede sin forma de entrar.
        $otroAcceso = (string)($usuario['password_hash'] ?? '') !== '';
        foreach (self::PROVEEDORES as $p => $d) {
            if ($p !== $proveedor && !empty($usuario[$d['columna']])) {
                $otroAcceso = true;
            }
        }
        if (!$otroAcceso) {
            return ['ok' => false, 'mensaje' => "Crea antes una contraseña: si desconectas {$nombre}, no tendrías cómo entrar."];
        }
        $pdo->prepare("UPDATE usuarios SET `$columna` = NULL WHERE id = ?")->execute([$usuario['id']]);
        Auditoria::registrar($pdo, 'desvincular_' . $proveedor, 'usuarios', [
            'recurso_tipo' => 'usuario', 'recurso_id' => (string)$usuario['id'],
            'descripcion'  => "{$nombre} desconectado desde «Mis datos».",
        ]);
        self::avisar($usuario, $proveedor, false);
        return ['ok' => true, 'mensaje' => "Desconectamos {$nombre}."];
    }

    /**
     * Al restablecer la contraseña de una cuenta cuyo correo nunca se había
     * confirmado, las identidades externas que tuviera se quitan: se
     * añadieron antes de que nadie demostrara ser el dueño del correo.
     */
    public static function quitarSinConfirmar(PDO $pdo, int $usuarioId): void
    {
        $columnas = [];
        foreach (self::PROVEEDORES as $p => $d) {
            if (self::disponible($pdo, $p)) {
                $columnas[] = "`{$d['columna']}` = NULL";
            }
        }
        if ($columnas) {
            $pdo->prepare("UPDATE usuarios SET " . implode(', ', $columnas) . " WHERE id = ?")->execute([$usuarioId]);
        }
    }

    // ---------------------------------------------------------------------
    // Final común de los dos callbacks
    // ---------------------------------------------------------------------

    /**
     * Termina el retorno del proveedor: vincula (si se pidió desde «Mis
     * datos») o abre la sesión. Nunca devuelve: siempre redirige dentro del
     * sitio.
     *
     * @param array $resultado ['ok' => bool, 'perfil' => array, 'error' => string]
     */
    public static function terminar(PDO $pdo, string $proveedor, array $resultado): never
    {
        $nombre = self::PROVEEDORES[$proveedor]['nombre'];
        $vincularA = Auth::autenticado() ? self::consumirVinculo($proveedor) : 0;

        if (!$resultado['ok']) {
            Auditoria::registrar($pdo, 'inicio_sesion_' . $proveedor, 'usuarios', [
                'resultado' => 'fallo', 'descripcion' => mb_substr((string)$resultado['error'], 0, 200),
            ]);
            flash('error', (string)$resultado['error']);
            redirigir(Auth::autenticado() ? 'cuenta/perfil.php' : 'cuenta/entrar.php');
        }

        if (Auth::autenticado()) {
            if ($vincularA === 0) {
                flash('error', "Para conectar {$nombre}, hazlo desde «Mis datos».");
                redirigir('cuenta/perfil.php');
            }
            $r = self::vincular($pdo, $proveedor, $resultado['perfil'], $vincularA);
            flash($r['ok'] ? 'exito' : 'error', $r['mensaje']);
            redirigir('cuenta/perfil.php');
        }

        $acceso = self::entrar($pdo, $proveedor, $resultado['perfil']);
        if (!$acceso['ok']) {
            Auditoria::registrar($pdo, 'inicio_sesion_' . $proveedor, 'usuarios', [
                'resultado' => 'fallo', 'descripcion' => mb_substr((string)$acceso['error'], 0, 200),
            ]);
            flash('error', (string)$acceso['error']);
            redirigir('cuenta/entrar.php');
        }
        $usuario = $acceso['usuario'];

        Auth::abrirSesion($usuario);
        Favoritos::fusionarAlEntrar($pdo, (int)$usuario['id']);
        Carrito::fusionarAlEntrar($pdo, (int)$usuario['id']);
        Auditoria::registrar($pdo, 'inicio_sesion_' . $proveedor, 'usuarios', [
            'recurso_tipo' => 'usuario', 'recurso_id' => (string)$usuario['id'],
            'descripcion'  => "Inicio de sesión con {$nombre}.",
        ]);
        if (class_exists('Analitica')) {
            Analitica::eventoDiferido('login', ['method' => $proveedor]);
        }

        $destino = $_SESSION['volver_a'] ?? '';
        unset($_SESSION['volver_a']);
        flash('exito', '¡Hola, ' . Auth::nombreCompleto() . '!');
        redirigir($destino !== '' ? $destino : (Auth::esPersonal() ? 'admin/' : 'cuenta/pedidos.php'));
    }

    /**
     * Inicio común de los dos proveedores (cuenta/google.php y
     * cuenta/facebook.php). Con la sesión abierta, solo se sigue si es para
     * vincular desde «Mis datos».
     */
    public static function puedeEmpezar(string $proveedor): bool
    {
        if (!Auth::autenticado()) {
            return true;
        }
        $v = $_SESSION['vincular'] ?? null;
        return is_array($v) && ($v['proveedor'] ?? '') === $proveedor && (int)($v['hasta'] ?? 0) >= time()
            && (int)($v['usuario'] ?? 0) === (int)Auth::id();
    }

    // ---------------------------------------------------------------------

    public static function porId(PDO $pdo, int $id): ?array
    {
        $st = $pdo->prepare(
            "SELECT u.*, r.codigo AS rol_codigo, r.nombre AS rol_nombre, r.es_personal
               FROM usuarios u LEFT JOIN roles r ON r.id = u.rol_id WHERE u.id = ?"
        );
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    private static function avisar(array $usuario, string $proveedor, bool $conectado): void
    {
        $correo = (string)($usuario['email'] ?? '');
        if ($correo === '') {
            return;
        }
        $nombre = self::PROVEEDORES[$proveedor]['nombre'];
        $tienda = Ajustes::texto('nombre_tienda', 'Flowers Anto');
        Correo::enviar($correo,
            ($conectado ? "Conectaste {$nombre} a tu cuenta" : "Desconectaste {$nombre} de tu cuenta") . " — {$tienda}",
            Correo::plantilla($conectado ? "{$nombre} conectado" : "{$nombre} desconectado",
                '<p>Hola ' . e((string)($usuario['nombre'] ?? '')) . ', '
                . ($conectado ? "desde ahora también puedes entrar a tu cuenta con {$nombre}." : "ya no puedes entrar con {$nombre}.")
                . '</p><p>Si no fuiste tú, cambia tu contraseña y escríbenos cuanto antes.</p>',
                ['url' => url_absoluta('cuenta/perfil.php'), 'texto' => 'Revisar mi cuenta']));
    }
}
