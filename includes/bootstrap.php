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
define('ENTORNO',  Entorno::texto('APP_ENTORNO', 'prod') === 'dev' ? 'dev' : 'prod');
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

date_default_timezone_set('America/Managua');

// ---------------------------------------------------------------------
// Cabeceras de seguridad (no aplican a la CLI)
// ---------------------------------------------------------------------
if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    header_remove('X-Powered-By');
}

// ---------------------------------------------------------------------
// Sesión
// ---------------------------------------------------------------------
if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || (($_SERVER['SERVER_PORT'] ?? '') === '443')
          || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

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
require_once __DIR__ . '/lib/checkout.php';

Auth::iniciar($pdo);
