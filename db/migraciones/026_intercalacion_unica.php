<?php
/**
 * Una sola intercalación (utf8mb4_unicode_ci) en toda la base.
 *
 * Todas las tablas de la tienda se crean con utf8mb4_unicode_ci, pero la de
 * control de migraciones se creaba sin decirlo y tomaba la del servidor, que
 * cambia de una versión a otra (en Hostinger, utf8mb4_uca1400_ai_ci). Lo mismo
 * pasaría con una tabla importada de un respaldo hecho en otro servidor. Dos
 * intercalaciones distintas en la misma base acaban, tarde o temprano, en un
 * «Illegal mix of collations» al comparar textos de una y otra.
 *
 * Convierte a utf8mb4_unicode_ci las tablas que usen otra y deja esa como
 * predeterminada de la base, para lo que se cree en el futuro. Las tablas que
 * ya la tienen no se tocan, así que repetirla no hace nada.
 */

declare(strict_types=1);

return function (PDO $pdo, Esquema $e): void {
    $distintas = $pdo->query(
        "SELECT TABLE_NAME FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
            AND TABLE_COLLATION <> 'utf8mb4_unicode_ci'"
    )->fetchAll(PDO::FETCH_COLUMN);

    foreach ($distintas as $tabla) {
        if (!preg_match('/^[A-Za-z0-9_]+$/', (string)$tabla)) {
            continue;
        }
        $pdo->exec("ALTER TABLE `$tabla` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    }

    // La predeterminada de la base. Algunos hostings no dan permiso para
    // cambiarla; no es imprescindible (las migraciones dicen siempre la suya).
    try {
        $pdo->exec("ALTER DATABASE CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    } catch (PDOException $ex) {
        error_log('Flowers Anto — no se pudo cambiar la intercalación de la base: ' . $ex->getMessage());
    }
};
