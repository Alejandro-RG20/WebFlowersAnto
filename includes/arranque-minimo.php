<?php
/**
 * Arranque mínimo: solo entorno y base de datos.
 *
 * Lo usa `archivo.php`, que sirve fotos y no necesita nada más. El arranque
 * normal carga quince módulos, abre la sesión y consulta el usuario: eso está
 * bien para una página, pero una página de catálogo pide una docena de fotos y
 * cada una repetía todo ese trabajo. En un hosting compartido, donde cada
 * arranque cuesta bastante más que en local, ahí se iban segundos de la
 * primera carga.
 *
 * No hay sesión, ni permisos, ni ajustes: si algún día este archivo necesita
 * cualquiera de esas cosas, lo que toca es usar `bootstrap.php`, no ampliar
 * este.
 */

declare(strict_types=1);

define('RAIZ', dirname(__DIR__));

require_once __DIR__ . '/entorno.php';
Entorno::cargar(RAIZ);

define('ENTORNO', Entorno::texto('APP_ENTORNO', 'prod') === 'dev' ? 'dev' : 'prod');

if (ENTORNO === 'dev') {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
}

if (PHP_SAPI !== 'cli') {
    header_remove('X-Powered-By');
}

try {
    $pdo = new PDO(
        sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            Entorno::texto('DB_HOST', 'localhost'),
            Entorno::texto('DB_PORT', '3306'),
            Entorno::texto('DB_NAME', 'flowers_anto'),
            Entorno::texto('DB_CHARSET', 'utf8mb4')
        ),
        Entorno::texto('DB_USER', 'root'),
        Entorno::texto('DB_PASS', ''),
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ]
    );
} catch (PDOException $e) {
    error_log('Flowers Anto — archivo, fallo de conexión: ' . $e->getMessage());
    http_response_code(503);
    exit;
}
