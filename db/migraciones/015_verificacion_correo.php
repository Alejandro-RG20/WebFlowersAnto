<?php
/**
 * Verificación del correo al crear una cuenta.
 *
 * Sin esto, cualquiera puede registrarse con el correo de otra persona. En una
 * floristería importa poco para entrar, pero sí para los cupones: el límite de
 * «uno por cliente» se comprueba por correo, y un correo sin verificar se
 * puede inventar tantas veces como haga falta.
 *
 * Se reaprovecha la misma tabla de tokens que la recuperación de contraseña,
 * distinguiéndolos por `tipo`. Una tabla menos que mantener y que respaldar.
 */

declare(strict_types=1);

return function (PDO $pdo, Esquema $e): void {
    $e->agregarColumna(
        'password_resets',
        'tipo',
        "ENUM('password','verificar_email') NOT NULL DEFAULT 'password'"
    );
    $e->agregarIndice('password_resets', 'idx_resets_tipo', '(tipo, usado_en)');
};
