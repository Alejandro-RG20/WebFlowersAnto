<?php
/**
 * Ejecuta las migraciones pendientes desde la consola.
 *
 *     php db/migrar.php            aplica lo que falte
 *     php db/migrar.php --estado   solo informa, no toca nada
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Este script solo se ejecuta desde la consola. Usa el panel: Administración → Base de datos.');
}

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/Migrador.php';

$migrador = new Migrador($pdo, __DIR__ . '/migraciones', Entorno::texto('DB_NAME', 'flowers_anto'));
$pendientes = $migrador->pendientes();

if (in_array('--estado', $argv, true)) {
    echo "Aplicadas: " . count($migrador->aplicadas()) . "\n";
    echo "Pendientes: " . count($pendientes) . "\n";
    foreach ($pendientes as $p) {
        echo "  - $p\n";
    }
    exit(0);
}

if (!$pendientes) {
    echo "No hay migraciones pendientes.\n";
    exit(0);
}

echo "Aplicando " . count($pendientes) . " migración(es)…\n";
$r = $migrador->ejecutar(function (string $nombre, ?string $error): void {
    echo $error === null ? "  ✓ $nombre\n" : "  ✗ $nombre — $error\n";
});

if ($r['errores']) {
    fwrite(STDERR, "\nLa migración se detuvo por un error. Corrígelo y vuelve a ejecutar.\n");
    exit(1);
}
echo "\nListo.\n";
