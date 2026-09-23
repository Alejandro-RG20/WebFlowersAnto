<?php
/**
 * Versión de sesión por cuenta.
 *
 * Las sesiones viven en archivos del servidor y guardan solo el id del
 * usuario. Eso deja un hueco: cuando alguien cambia su contraseña porque
 * sospecha que se la robaron, la sesión que abrió el ladrón sigue abierta —el
 * cambio de clave no la toca—.
 *
 * Con un número por cuenta se cierra. Cada sesión recuerda el número que
 * tenía la cuenta al abrirse; cambiar la contraseña lo sube, y toda sesión
 * con el número viejo deja de valer en su siguiente petición. No hay que
 * recorrer ni borrar archivos de sesión.
 *
 * El valor por defecto es 0 y las sesiones abiertas antes de esta migración
 * no llevan número, que también se lee como 0: nadie pierde la sesión al
 * desplegar.
 */

declare(strict_types=1);

return function (PDO $pdo, Esquema $e): void {
    $e->agregarColumna('usuarios', 'sesion_version', "INT UNSIGNED NOT NULL DEFAULT 0");
};
