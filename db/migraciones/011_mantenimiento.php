<?php
/**
 * Permiso para la limpieza de mantenimiento.
 *
 * Va aparte de `respaldos.crear` porque no es lo mismo: crear una copia no
 * destruye nada, y esta acción borra archivos. Quien pueda restaurar la base
 * puede también limpiarla, pero un empleado con permiso para hacer copias no
 * tiene por qué poder borrar fotos del servidor.
 */

declare(strict_types=1);

return function (PDO $pdo, Esquema $e): void {

    $pdo->prepare(
        "INSERT INTO permisos (codigo, nombre, modulo) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), modulo = VALUES(modulo)"
    )->execute(['sistema.mantenimiento', 'Limpiar archivos y registros sobrantes', 'sistema']);

    $idsRol     = $pdo->query("SELECT codigo, id FROM roles")->fetchAll(PDO::FETCH_KEY_PAIR);
    $idsPermiso = $pdo->query("SELECT codigo, id FROM permisos")->fetchAll(PDO::FETCH_KEY_PAIR);

    $insRp = $pdo->prepare("INSERT IGNORE INTO rol_permisos (rol_id, permiso_id) VALUES (?, ?)");
    foreach (['super_admin', 'admin'] as $rol) {
        if (isset($idsRol[$rol], $idsPermiso['sistema.mantenimiento'])) {
            $insRp->execute([$idsRol[$rol], $idsPermiso['sistema.mantenimiento']]);
        }
    }
};
