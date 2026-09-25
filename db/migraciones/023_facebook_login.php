<?php
/**
 * Acceso con Facebook.
 *
 * El identificador que Facebook da a cada persona es propio de cada app
 * (app-scoped user id): es estable para esta tienda y es lo único con lo que
 * se reconoce a alguien que vuelve a entrar con Facebook. Único, como
 * google_id: una cuenta de Facebook no puede estar en dos cuentas de la tienda.
 *
 * Solo añade una columna vacía. Sin esta migración el botón de Facebook no
 * aparece y todo lo demás funciona igual.
 */

declare(strict_types=1);

return function (PDO $pdo, Esquema $e): void {
    $e->agregarColumna('usuarios', 'facebook_id', "VARCHAR(64) NULL DEFAULT NULL AFTER google_id");
    $e->agregarIndice('usuarios', 'uk_usuarios_facebook_id', '(facebook_id)', true);
};
