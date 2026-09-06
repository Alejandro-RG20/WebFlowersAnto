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
    // El origen se mira antes que el token: si la petición viene de otra web,
    // no hay nada que comprobar después. Al ir aquí dentro, todo lo que ya
    // exigía token queda protegido sin tocar ni un endpoint.
    exigirMismoOrigen($json);

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
 * Rechaza las peticiones que llegan desde otro sitio web.
 *
 * Esto es la otra mitad de lo que la gente llama «configurar CORS». El
 * navegador ya impide que una web ajena LEA la respuesta de otra —para eso no
 * hay que hacer nada, basta con no mandar `Access-Control-Allow-Origin`, y este
 * sitio no lo manda en ninguna parte—. Lo que el navegador NO impide es que esa
 * web ajena ENVÍE la petición: un formulario suyo puede hacer POST aquí con la
 * sesión del cliente, y aunque no vea el resultado, la acción se habría hecho.
 *
 * Contra eso ya está el token CSRF, que es la defensa buena. Esto es la segunda
 * cerradura: se compara el origen declarado por el navegador con el del propio
 * sitio, y lo que venga de fuera no llega ni a mirarse.
 *
 * `Origin` lo pone el navegador y no se puede falsear desde JavaScript. Cuando
 * no viene —hay clientes que no lo mandan en peticiones normales— se cae al
 * `Referer`, y si tampoco hay, se deja pasar: cerrar por falta de una cabecera
 * opcional rompería a gente legítima sin ganar seguridad, porque el token CSRF
 * sigue estando delante.
 */
function exigirMismoOrigen(bool $json = true): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return;
    }

    $declarado = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($declarado === '' || $declarado === 'null') {
        $referente = (string)($_SERVER['HTTP_REFERER'] ?? '');
        if ($referente === '') {
            return;   // no hay nada que comparar
        }
        $partes = parse_url($referente);
        $declarado = isset($partes['scheme'], $partes['host'])
            ? $partes['scheme'] . '://' . $partes['host']
              . (isset($partes['port']) ? ':' . $partes['port'] : '')
            : '';
        if ($declarado === '') {
            return;
        }
    }

    // El origen propio se arma con lo que ve el servidor, no con APP_URL: en un
    // hosting compartido el sitio responde igual por dominio y por subdominio
    // temporal, y comparar contra un solo valor dejaría fuera al segundo.
    $esquema = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https' : 'http';
    $propios = [$esquema . '://' . (string)($_SERVER['HTTP_HOST'] ?? '')];

    if (APP_URL !== '') {
        $partes = parse_url(APP_URL);
        if (isset($partes['scheme'], $partes['host'])) {
            $propios[] = $partes['scheme'] . '://' . $partes['host']
                       . (isset($partes['port']) ? ':' . $partes['port'] : '');
        }
    }

    foreach ($propios as $propio) {
        if (strcasecmp($declarado, $propio) === 0) {
            return;
        }
    }

    $pdo = $GLOBALS['pdo'] ?? null;
    if ($pdo instanceof PDO && class_exists('Auditoria')) {
        Auditoria::registrar($pdo, 'origen_rechazado', 'seguridad', [
            'descripcion' => 'Petición desde otro sitio: ' . mb_substr($declarado, 0, 150),
            'resultado'   => 'error',
        ]);
    }

    if ($json) {
        errorJson('Petición rechazada: no viene de esta tienda.', 403);
    }
    http_response_code(403);
    exit('Petición rechazada: no viene de esta tienda.');
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

    // Analytics carga su biblioteca desde googletagmanager.com y manda las
    // medidas a google-analytics.com. Sin estos orígenes el navegador bloquea
    // la etiqueta en silencio y en los informes no aparece ni una visita.
    if (class_exists('Analitica') && Analitica::activo()) {
        $script  .= ' https://www.googletagmanager.com';
        $conecta .= ' https://www.google-analytics.com https://analytics.google.com '
                  . 'https://*.google-analytics.com https://*.analytics.google.com '
                  . 'https://www.googletagmanager.com';
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
