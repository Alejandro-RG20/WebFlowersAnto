<?php
/**
 * Códigos de verificación por correo y datos opcionales del perfil.
 *
 * Códigos: la recuperación de contraseña, la confirmación del correo y el
 * cambio de correo mandan, además del enlace, un código de 6 dígitos que se
 * escribe en la propia tienda. Van en la misma tabla de tokens:
 *
 *   · codigo_hash      hash del código (password_hash), nunca el código.
 *   · intentos_codigo  fallos acumulados; al quinto la solicitud muere.
 *   · destino          correo nuevo en un cambio de correo pendiente: el
 *                      cambio no se aplica hasta que llega el código.
 *
 * Perfil: foto y fecha de nacimiento, las dos opcionales.
 *
 *   · La foto va en su propia tabla, una fila por cuenta, y NO en `archivos`:
 *     lo de `archivos` se sirve a cualquiera por su número (archivo.php), y
 *     las fotos de los clientes no tienen por qué poder recorrerse así. Se
 *     sirven por cuenta/foto.php, solo a su dueño y al personal.
 *   · `usuarios.foto` guarda la huella corta de la foto actual (o NULL): sirve
 *     para saber si hay foto sin leer el binario y para que el navegador
 *     pida la nueva en cuanto cambia.
 *
 * Sin esta migración todo sigue como antes: solo enlaces, y el perfil sin
 * esos dos campos.
 */

declare(strict_types=1);

return function (PDO $pdo, Esquema $e): void {
    $e->agregarColumna('password_resets', 'codigo_hash', "VARCHAR(255) NULL DEFAULT NULL AFTER token_hash");
    $e->agregarColumna('password_resets', 'intentos_codigo', "TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER codigo_hash");
    $e->agregarColumna('password_resets', 'destino', "VARCHAR(150) NULL DEFAULT NULL AFTER intentos_codigo");
    $e->modificarColumna(
        'password_resets',
        'tipo',
        "ENUM('password','verificar_email','cambiar_email') NOT NULL DEFAULT 'password'"
    );
    $e->agregarIndice('password_resets', 'idx_resets_usuario_tipo', '(usuario_id, tipo, usado_en)');

    $e->agregarColumna('usuarios', 'foto', "VARCHAR(16) NULL DEFAULT NULL AFTER avatar_url");
    $e->sql("CREATE TABLE IF NOT EXISTS fotos_perfil (
        usuario_id INT NOT NULL PRIMARY KEY,
        mime       VARCHAR(30) NOT NULL,
        tamano     INT UNSIGNED NOT NULL,
        sha256     CHAR(64) NOT NULL,
        datos      MEDIUMBLOB NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_foto_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $e->agregarColumna('usuarios', 'fecha_nacimiento', "DATE NULL DEFAULT NULL AFTER telefono");
};
