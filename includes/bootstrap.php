<?php
/**
 * Flowers Anto — núcleo de la aplicación.
 *
 * Todo punto de entrada (páginas, API y panel) empieza incluyendo este archivo.
 * Se encarga de la configuración, la sesión endurecida, la conexión PDO y de
 * cargar los módulos de la carpeta lib/.
 */

declare(strict_types=1);

define('RAIZ', dirname(__DIR__));

require_once __DIR__ . '/entorno.php';
Entorno::cargar(RAIZ);

// ---------------------------------------------------------------------
// Constantes de entorno
// ---------------------------------------------------------------------
/**
 * ¿Se puede creer el modo «desarrollo» que diga la configuración?
 *
 * El modo dev enciende `display_errors`, y un aviso de PHP impreso en mitad
 * de la tienda es exactamente lo que hizo que un cliente viera
 * «session_start(): ps_files_cleanup_dir … Permission denied» encima del
 * catálogo. El proyecto se despliega copiando carpetas, así que un `.env` o
 * un `config.local.php` de desarrollo llega al servidor público con una
 * facilidad enorme y nadie se entera hasta que un cliente lo fotografía.
 *
 * Por eso dev deja de creerse a sí mismo: solo vale si quien pide la página
 * está en la misma máquina o en la red local. `REMOTE_ADDR` lo escribe el
 * servidor web a partir de la conexión TCP, no el visitante, así que esto no
 * se puede falsear desde internet —al contrario que la cabecera `Host` o que
 * `X-Forwarded-For`, que sí manda quien llama y por eso no se usan aquí—.
 */
function peticion_desde_red_local(): bool
{
    if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
        return true;
    }
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if ($ip === '') {
        return PHP_SAPI === 'cli-server';
    }
    if ($ip === '127.0.0.1' || $ip === '::1') {
        return true;
    }
    if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
        return false; // no se sabe quién llama: se le trata como público
    }
    // Las banderas hacen fallar la validación cuando la dirección es privada
    // o reservada; que falle es justo lo que indica que viene de red local.
    return filter_var(
        $ip,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    ) === false;
}

define('ENTORNO', (Entorno::texto('APP_ENTORNO', 'prod') === 'dev' && peticion_desde_red_local())
    ? 'dev'
    : 'prod');
define('BASE_URL', rtrim(Entorno::texto('APP_BASE_URL', ''), '/'));
define('APP_URL',  rtrim(Entorno::texto('APP_URL', ''), '/'));

define('MAX_UPLOAD_BYTES',      Entorno::entero('MAX_UPLOAD_MB', 5) * 1048576);
define('MAX_COMPROBANTE_BYTES', Entorno::entero('MAX_COMPROBANTE_MB', 8) * 1048576);
define('MAX_RESPALDO_BYTES',    Entorno::entero('MAX_RESPALDO_MB', 64) * 1048576);

define('DIR_STORAGE',      RAIZ . '/storage');
define('DIR_COMPROBANTES', DIR_STORAGE . '/comprobantes');
define('DIR_RESPALDOS',    DIR_STORAGE . '/respaldos');
define('DIR_LOGS',         DIR_STORAGE . '/logs');

if (ENTORNO === 'dev') {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
}
ini_set('log_errors', '1');
if (is_dir(DIR_LOGS) && is_writable(DIR_LOGS)) {
    ini_set('error_log', DIR_LOGS . '/php.log');
}

/** ¿Esta petición espera JSON en vez de una página? */
function peticion_de_api(): bool
{
    $guion = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    return str_contains($guion, '/api/') || str_ends_with($guion, '/subir.php');
}

/**
 * En producción, un aviso de PHP se registra pero no se imprime jamás.
 *
 * `display_errors` es un ajuste del intérprete, y el hosting compartido puede
 * traerlo encendido, devolverlo a «On» por su cuenta o ignorar el `ini_set`
 * de arriba. Este manejador va por encima de todo eso: decide él qué se
 * imprime. Es la diferencia entre confiar en que el servidor esté bien
 * configurado y garantizar que la tienda nunca enseñe tripas al cliente.
 *
 * En desarrollo devuelve `false` a propósito, para que PHP siga imprimiendo
 * los avisos como siempre: quien programa los necesita ver.
 */
set_error_handler(static function (int $tipo, string $mensaje, string $archivo, int $linea): bool {
    // Una llamada precedida de `@` reduce la máscara de error_reporting.
    // Respetarlo es obligatorio: hay sitios que suprimen a conciencia.
    if ((error_reporting() & $tipo) === 0) {
        return true;
    }
    error_log(sprintf('PHP[%d] %s — %s:%d', $tipo, $mensaje, $archivo, $linea));
    return ENTORNO === 'prod';
});

/**
 * Última palabra de la aplicación cuando algo se rompió del todo.
 *
 * Se descarta lo que hubiera escrito a medias: media página cortada de golpe
 * confunde más que una disculpa entera. Si la respuesta ya empezó a salir no
 * se añade nada: puede ir comprimida, y pegarle texto plano a un flujo gzip
 * la dejaría ilegible en el navegador.
 *
 * @param string $detalle Solo en desarrollo; en producción va siempre vacío.
 */
function pagina_de_disculpa(string $detalle = ''): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (headers_sent()) {
        return;
    }
    http_response_code(500);
    $api = peticion_de_api();
    header('Content-Type: ' . ($api ? 'application/json; charset=utf-8' : 'text/html; charset=utf-8'));

    $disculpa = 'No pudimos completar la operación. Vuelve a intentarlo en unos minutos.';
    if ($api) {
        echo json_encode(
            ['ok' => false, 'error' => $detalle !== '' ? $detalle : $disculpa],
            JSON_UNESCAPED_UNICODE
        );
        return;
    }
    echo '<!doctype html><meta charset="utf-8"><title>Error</title>';
    echo $detalle !== ''
        ? '<pre>' . htmlspecialchars($detalle, ENT_QUOTES) . '</pre>'
        : '<p style="font:16px system-ui;margin:3rem auto;max-width:28rem;text-align:center">'
          . $disculpa . '</p>';
}

/**
 * Una excepción que nadie atrapó no puede acabar en «Fatal error: Uncaught
 * PDOException: SQLSTATE[…]» delante del cliente: ese texto lleva consultas,
 * rutas del servidor y a veces el usuario de la base de datos.
 */
set_exception_handler(static function (Throwable $e): void {
    error_log('Excepción sin capturar: ' . $e->getMessage()
        . ' — ' . $e->getFile() . ':' . $e->getLine());

    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
        exit(1);
    }
    pagina_de_disculpa(ENTORNO === 'dev' ? (string)$e : '');
});

/**
 * Los errores fatales no pasan por `set_error_handler`, así que se recogen al
 * cerrar. Sin esto, un fatal en producción deja media página escrita y cortada
 * de golpe, que para quien está comprando es peor que una disculpa entera.
 */
register_shutdown_function(static function (): void {
    $ultimo = error_get_last();
    if ($ultimo === null || !in_array(
        $ultimo['type'],
        [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR],
        true
    )) {
        return;
    }
    error_log(sprintf('PHP FATAL %s — %s:%d', $ultimo['message'], $ultimo['file'], $ultimo['line']));

    if (ENTORNO !== 'prod' || PHP_SAPI === 'cli') {
        return;
    }
    pagina_de_disculpa();
});

date_default_timezone_set('America/Managua');

// ---------------------------------------------------------------------
// Cabeceras de seguridad (no aplican a la CLI)
// ---------------------------------------------------------------------
/**
 * ¿La petición llegó cifrada?
 *
 * En hosting compartido el certificado suele terminar en un balanceador, así
 * que $_SERVER['HTTPS'] llega vacío aunque el visitante venga por https. La
 * cabecera X-Forwarded-Proto es la que lo dice de verdad.
 */
$peticionSegura = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
               || (($_SERVER['SERVER_PORT'] ?? '') === '443')
               || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    // Las ventanas de PayPal y de Google se abren aparte y necesitan poder
    // hablar con la que las abrió: por eso «allow-popups» y no «same-origin».
    header('Cross-Origin-Opener-Policy: same-origin-allow-popups');

    // HSTS: una vez visto el sitio por https, el navegador no vuelve a
    // intentar http. Solo se manda sobre https, como manda la especificación:
    // enviarlo por http no sirve de nada y puede atar un dominio sin
    // certificado. Se duplica con el .htaccess a propósito, porque detrás de
    // un balanceador Apache no siempre se entera de que la petición era
    // segura y PHP sí.
    if ($peticionSegura) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
    header_remove('X-Powered-By');
}

/**
 * Las respuestas JSON se escriben en un búfer.
 *
 * En un hosting compartido, un aviso de PHP ajeno al código —InfinityFree
 * suelta «session_start(): ps_files_cleanup_dir … Permission denied» en cada
 * petición— se imprime ANTES que la respuesta y la deja ilegible: el
 * navegador recibe «Notice: …{"ok":true}» y dice «Respuesta no válida del
 * servidor», aunque la foto se haya subido bien.
 *
 * Con el búfer abierto, `responderJson()` puede descartar todo eso y mandar
 * JSON limpio. Se activa solo en las llamadas de API: las descargas de
 * respaldos y de imágenes envían archivos en streaming y no deben acumularse
 * en memoria.
 */
if (PHP_SAPI !== 'cli') {
    if (peticion_de_api()) {
        ob_start();
    } elseif (comprimir_salida()) {
        ob_start('ob_gzhandler');
    }
}

/**
 * ¿Conviene comprimir el HTML de esta petición?
 *
 * El hosting no está aplicando compresión —PageSpeed lo midió: el documento
 * viaja entero y son unos 40 KB de más en cada visita—, así que la hace PHP.
 * No se toca si el servidor ya la aplica, ni en descargas o imágenes, que
 * salen en streaming y volverían a comprimirse sin ganar nada.
 */
function comprimir_salida(): bool
{
    if (headers_sent()) {
        return false;
    }
    // No se mira `ob_get_level()`: casi todos los hostings traen
    // `output_buffering` activado, así que siempre hay un búfer abierto y
    // comprobarlo dejaba la compresión sin aplicar nunca. El manejador de
    // gzip se apila encima sin problema.
    // Si ya está activada por configuración, dejarla; hacerlo dos veces
    // produce una respuesta que el navegador no puede leer.
    if (filter_var((string)ini_get('zlib.output_compression'), FILTER_VALIDATE_BOOLEAN)) {
        return false;
    }
    if (!extension_loaded('zlib') || !function_exists('ob_gzhandler')) {
        return false;
    }
    return str_contains(strtolower((string)($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '')), 'gzip');
}

// ---------------------------------------------------------------------
// Sesión
// ---------------------------------------------------------------------
/**
 * La tienda guarda sus propias sesiones, en vez de usar las del hosting.
 *
 * InfinityFree apunta `session.save_path` a `/php_sessions`, un directorio
 * que la cuenta no puede abrir. Cada vez que a PHP le toca pasar el recolector
 * de basura de sesiones —una de cada cien peticiones, por sorteo— el
 * `opendir()` falla y PHP emite el aviso que se veía impreso sobre el
 * catálogo. Silenciarlo sería tapar el síntoma: el recolector seguiría sin
 * poder hacer su trabajo. Lo que se hace aquí es dejar de depender de ese
 * directorio y usar uno propio, que sí se puede leer y limpiar.
 *
 * Se busca fuera de la carpeta pública a propósito: un archivo de sesión
 * descargable es una cuenta de administrador regalada, y no basta con confiar
 * en que el `.htaccess` esté activo. Si ningún candidato sirve, no se toca
 * nada y se sigue con el del servidor: quedarse sin sesiones sería mucho peor
 * que un aviso en el registro.
 *
 * @return string Ruta elegida, o '' si se deja la del servidor.
 */
function preparar_almacen_de_sesiones(): string
{
    $candidatos = [
        dirname(RAIZ) . '/.sesiones-flowersanto', // un nivel sobre htdocs
        sys_get_temp_dir() . '/flowersanto-sesiones',
        DIR_STORAGE . '/sesiones',                // último recurso, ya público
    ];

    foreach ($candidatos as $dir) {
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            continue;
        }
        if (!is_readable($dir) || !is_writable($dir)) {
            continue;
        }
        // Cinturón y tirantes por si el directorio acabó siendo alcanzable.
        if (!is_file($dir . '/.htaccess')) {
            @file_put_contents(
                $dir . '/.htaccess',
                "Require all denied\n<IfModule !mod_authz_core.c>\n Deny from all\n</IfModule>\n"
            );
        }
        session_save_path($dir);

        // El recolector del hosting nunca llegó a borrar nada, así que las
        // sesiones duraban indefinidamente. Ahora que sí va a funcionar, se
        // le da el mismo plazo que usa la tienda para cerrar por inactividad
        // (ocho horas): con el cuarto de hora de fábrica, el carrito de un
        // visitante se vaciaría solo mientras elige el ramo.
        ini_set('session.gc_maxlifetime', (string)(8 * 3600));
        return $dir;
    }
    return '';
}

if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    $https = $peticionSegura;

    preparar_almacen_de_sesiones();

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => BASE_URL === '' ? '/' : BASE_URL . '/',
        'httponly' => true,   // JavaScript no puede leer la cookie de sesión
        'secure'   => $https, // solo por HTTPS cuando el sitio tenga certificado
        'samesite' => 'Lax',  // corta el CSRF desde otros dominios
    ]);
    session_name('FLOWERSANTO_SESS');
    session_start();

    // Rotación periódica del identificador de sesión (cada 30 minutos).
    if (empty($_SESSION['sesion_creada'])) {
        $_SESSION['sesion_creada'] = time();
    } elseif (time() - (int)$_SESSION['sesion_creada'] > 1800) {
        session_regenerate_id(true);
        $_SESSION['sesion_creada'] = time();
    }

    // Caducidad por inactividad. Un panel abierto y olvidado en un ordenador
    // compartido —o en el móvil del repartidor— es una sesión de administrador
    // esperando a que alguien la use. Ocho horas cubren una jornada entera sin
    // molestar a quien está trabajando; el carrito de un visitante no se ve
    // afectado porque solo se cierra la sesión de quien había entrado.
    $LIMITE_INACTIVIDAD = 8 * 3600;
    $ultimoPaso = (int)($_SESSION['ultimo_paso'] ?? 0);
    if ($ultimoPaso > 0 && !empty($_SESSION['usuario_id'])
        && time() - $ultimoPaso > $LIMITE_INACTIVIDAD) {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['sesion_creada'] = time();
        $_SESSION['aviso_caducada'] = true;
    }
    $_SESSION['ultimo_paso'] = time();
}

// ---------------------------------------------------------------------
// Base de datos
// ---------------------------------------------------------------------
try {
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        Entorno::texto('DB_HOST', 'localhost'),
        Entorno::texto('DB_PORT', '3306'),
        Entorno::texto('DB_NAME', 'flowers_anto'),
        Entorno::texto('DB_CHARSET', 'utf8mb4')
    );
    $pdo = new PDO($dsn, Entorno::texto('DB_USER', 'root'), Entorno::texto('DB_PASS', ''), [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false, // consultas preparadas reales
        PDO::ATTR_STRINGIFY_FETCHES  => false,
    ]);
} catch (PDOException $e) {
    error_log('Flowers Anto — fallo de conexión: ' . $e->getMessage());
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "No se pudo conectar a la base de datos: {$e->getMessage()}\n");
        exit(1);
    }
    http_response_code(503);
    // El mensaje de PDO puede incluir usuario y host: nunca se muestra al visitante.
    exit(ENTORNO === 'dev'
        ? 'No se pudo conectar a la base de datos: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES)
        : 'El sitio no está disponible en este momento. Vuelve a intentarlo en unos minutos.');
}

// ---------------------------------------------------------------------
// Módulos
// ---------------------------------------------------------------------
require_once __DIR__ . '/lib/utiles.php';
require_once __DIR__ . '/lib/activos.php';
require_once __DIR__ . '/lib/validacion.php';
require_once __DIR__ . '/lib/seguridad.php';
require_once __DIR__ . '/lib/ajustes.php';
require_once __DIR__ . '/lib/auditoria.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/rbac.php';
require_once __DIR__ . '/lib/correo.php';
require_once __DIR__ . '/lib/catalogo.php';
require_once __DIR__ . '/lib/temporadas.php';
require_once __DIR__ . '/lib/envios.php';
require_once __DIR__ . '/lib/cupones.php';
require_once __DIR__ . '/lib/carrito.php';
require_once __DIR__ . '/lib/favoritos.php';
require_once __DIR__ . '/lib/pedidos.php';
require_once __DIR__ . '/lib/repartidores.php';
require_once __DIR__ . '/lib/archivos.php';
require_once __DIR__ . '/lib/paypal.php';
require_once __DIR__ . '/lib/verificacion.php';
require_once __DIR__ . '/lib/checkout.php';
require_once __DIR__ . '/lib/analitica.php';
require_once __DIR__ . '/lib/facturas.php';

Auth::iniciar($pdo);
