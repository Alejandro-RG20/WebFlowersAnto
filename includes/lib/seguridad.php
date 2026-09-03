<?php
/**
 * CSRF, limitación de intentos y firma de enlaces.
 */

declare(strict_types=1);

/** Token CSRF de la sesión actual (se crea la primera vez que se pide). */
function generarToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Comparación en tiempo constante del token recibido. */
function verificarToken(?string $token): bool
{
    return !empty($_SESSION['csrf_token'])
        && is_string($token)
        && hash_equals($_SESSION['csrf_token'], $token);
}

/** Campo oculto listo para pegar en cualquier formulario. */
function campoToken(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(generarToken()) . '">';
}

/**
 * Corta la petición si el token CSRF no es válido.
 * En peticiones normales redirige con un aviso; en las de API responde JSON.
 */
function exigirToken(bool $json = true, string $volverA = ''): void
{
    $token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
    if (verificarToken(is_string($token) ? $token : null)) {
        return;
    }
    if ($json) {
        errorJson('La página caducó. Recárgala e inténtalo otra vez.', 419);
    }
    flash('error', 'La página caducó por seguridad. Vuelve a enviar el formulario.');
    redirigir($volverA !== '' ? $volverA : '/');
}

/**
 * Limitación de intentos por clave (correo, IP, acción…).
 *
 * Devuelve true si la acción se permite. La ventana es deslizante y las filas
 * viejas se limpian solas: no hace falta ningún cron.
 */
function limitar(PDO $pdo, string $clave, int $maximo, int $ventanaSegundos): bool
{
    $clave = mb_substr($clave, 0, 190);
    $ahora = time();

    // Limpieza oportunista (1 de cada 20 peticiones) para que la tabla no crezca.
    if (random_int(1, 20) === 1) {
        $pdo->prepare("DELETE FROM rate_limits WHERE ventana_inicio < ?")
            ->execute([date('Y-m-d H:i:s', $ahora - 86400)]);
    }

    $st = $pdo->prepare("SELECT intentos, UNIX_TIMESTAMP(ventana_inicio) AS inicio
                           FROM rate_limits WHERE clave = ?");
    $st->execute([$clave]);
    $fila = $st->fetch();

    if (!$fila || ($ahora - (int)$fila['inicio']) > $ventanaSegundos) {
        $pdo->prepare(
            "INSERT INTO rate_limits (clave, intentos, ventana_inicio) VALUES (?, 1, NOW())
             ON DUPLICATE KEY UPDATE intentos = 1, ventana_inicio = NOW()"
        )->execute([$clave]);
        return true;
    }

    if ((int)$fila['intentos'] >= $maximo) {
        return false;
    }

    $pdo->prepare("UPDATE rate_limits SET intentos = intentos + 1 WHERE clave = ?")->execute([$clave]);
    return true;
}

/** Borra el contador de una clave (por ejemplo, tras un acceso correcto). */
function limpiarLimite(PDO $pdo, string $clave): void
{
    $pdo->prepare("DELETE FROM rate_limits WHERE clave = ?")->execute([mb_substr($clave, 0, 190)]);
}

/**
 * Cabecera Content-Security-Policy de las páginas públicas.
 *
 * La política es cerrada a propósito: solo se abre lo que hace falta y donde
 * hace falta. Por eso los orígenes de PayPal se añaden únicamente si el cobro
 * con PayPal está encendido: una tienda que solo cobra por transferencia no
 * tiene por qué permitir scripts de fuera.
 */
function cabeceraCSP(): void
{
    if (headers_sent()) {
        return;
    }

    $script  = "'self' 'unsafe-inline'";
    $marco   = "https://www.youtube.com https://www.youtube-nocookie.com "
             . "https://maps.google.com https://www.google.com";
    $conecta = "'self'";
    $formulario = "'self' https://accounts.google.com";

    // El botón de PayPal es un script suyo que abre una ventana suya y habla
    // con sus servidores: sin estos orígenes el navegador lo bloquea y el
    // cliente ve el hueco donde debería estar el botón.
    if (class_exists('PayPal') && PayPal::activo()) {
        $paypal  = "https://www.paypal.com https://www.paypalobjects.com "
                 . "https://www.sandbox.paypal.com https://c.paypal.com";
        $script .= ' ' . $paypal;
        $marco  .= ' ' . $paypal . ' https://c.sandbox.paypal.com';
        $conecta .= ' ' . $paypal . ' https://api-m.paypal.com '
                  . 'https://api-m.sandbox.paypal.com https://c.sandbox.paypal.com';
        $formulario .= ' https://www.paypal.com https://www.sandbox.paypal.com';
    }

    header(
        "Content-Security-Policy: " .
        "default-src 'self'; " .
        // blob: lo necesita la vista previa del comprobante, que pinta el
        // archivo elegido con URL.createObjectURL() antes de subirlo.
        "img-src 'self' data: blob: https:; " .
        "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com; " .
        "font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com; " .
        "script-src $script; " .
        "frame-src $marco; " .
        "connect-src $conecta; " .
        "form-action $formulario; " .
        "base-uri 'self'; " .
        "object-src 'none'"
    );
}
