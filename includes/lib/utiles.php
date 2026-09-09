<?php
/**
 * Utilidades de uso general: escape, URLs, formato y respuestas JSON.
 */

declare(strict_types=1);

/** Escapa texto para insertarlo en HTML. */
function e(?string $valor): string
{
    return htmlspecialchars((string)$valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Escapa un valor para incrustarlo con seguridad dentro de una etiqueta <script>. */
function json_para_html(mixed $valor): string
{
    return (string)json_encode(
        $valor,
        JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );
}

/**
 * URL de un archivo estático con su versión pegada detrás.
 *
 * El número es la fecha de modificación del archivo, así que cambia solo al
 * subir una versión nueva del sitio. Sin esto, el navegador se queda con el
 * CSS y el JS que ya tenía en caché: al actualizar los archivos en el hosting
 * la web sigue comportándose como antes —con los errores incluidos— y no hay
 * forma de saber que lo que se está viendo es viejo.
 */
function url_recurso(string $ruta): string
{
    $ruta    = ltrim(trim($ruta), '/');
    $version = @filemtime(RAIZ . '/' . $ruta);

    return url($ruta) . ($version ? '?v=' . $version : '');
}

/** Construye una URL absoluta dentro del sitio a partir de una ruta relativa. */
function url(string $ruta = ''): string
{
    $ruta = ltrim($ruta, '/');
    return BASE_URL . ($ruta === '' ? '/' : '/' . $ruta);
}

/**
 * URL completa con esquema y dominio. La usan los correos, los mensajes de
 * WhatsApp y los metadatos para buscadores.
 *
 * De APP_URL se toma solo el origen (esquema, dominio y puerto). APP_URL ya
 * incluye la subcarpeta del sitio, y la ruta que devuelve `url_interna()`
 * también: concatenarlas enteras daba http://localhost/webANTO/webANTO/… y
 * dejaba sin servir, entre otros, el enlace para restablecer la contraseña.
 */
function url_absoluta(string $ruta = ''): string
{
    // Una imagen de la base se resuelve primero a su URL pública: los correos
    // la piden desde fuera y necesitan la dirección completa.
    if (preg_match('#^bd:(\d+)$#', trim($ruta), $m)) {
        $ruta = 'archivo.php?id=' . (int)$m[1];
    }
    // Deja la ruta con la base aplicada una sola vez, venga relativa
    // ('productos.php') o ya completa ('/webANTO/productos.php').
    $ruta = url_interna($ruta);

    if (APP_URL !== '') {
        $partes = parse_url(APP_URL);
        $origen = ($partes['scheme'] ?? 'http') . '://' . ($partes['host'] ?? 'localhost')
                . (isset($partes['port']) ? ':' . $partes['port'] : '');
        return $origen . $ruta;
    }

    $esquema = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $esquema . '://' . $host . $ruta;
}

/**
 * URL de un archivo del catálogo. Acepta rutas relativas guardadas en la base
 * (images/... o uploads/...) y deja pasar las absolutas por si algún día se
 * sirven las fotos desde un CDN.
 */
function url_imagen(?string $ruta, string $def = 'images/placeholders/logo.svg', ?int $ancho = null): string
{
    $ruta = trim((string)$ruta);
    if ($ruta === '') {
        return url($def);
    }
    if (preg_match('#^https?://#i', $ruta)) {
        return $ruta;
    }
    // «bd:47» es una imagen guardada dentro de la base. Se resuelve aquí, que
    // es por donde pasa todo lo que se pinta, para que el resto del código
    // siga tratando el valor como una ruta cualquiera.
    //
    // Con `$ancho` se pide una copia reducida. Sirve para los sitios donde la
    // foto se pinta pequeña y no hay `srcset` que valga: el logo de la
    // cabecera ocupa 60 px y estaba descargando el original de 1087.
    if (preg_match('#^bd:(\d+)$#', $ruta, $m)) {
        return url('archivo.php?id=' . (int)$m[1] . ($ancho ? '&w=' . $ancho : ''));
    }
    return url($ruta);
}

/**
 * Medidas reales de una imagen guardada en la base.
 *
 * Se usan para escribir `width` y `height` en el `<img>`: con ellos el
 * navegador reserva el hueco antes de descargar la foto y la página no da un
 * salto al llegar. Las fotos del carrusel no tienen todas la misma
 * proporción, así que fijar un tamaño igual para todas las deformaría; por
 * eso se leen las de cada una.
 *
 * Devuelve [] si la referencia no es «bd:» o si el archivo no está.
 */
function imagen_medidas(?string $ruta): array
{
    static $cache = [];

    $ruta = trim((string)$ruta);
    if (!preg_match('#^bd:(\d+)$#', $ruta, $m)) {
        return [];
    }
    $id = (int)$m[1];
    if (array_key_exists($id, $cache)) {
        return $cache[$id];
    }

    // Misma vía que el resto de las librerías del proyecto.
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO) {
        return $cache[$id] = [];
    }

    try {
        $st = $pdo->prepare("SELECT ancho, alto FROM archivos WHERE id = ?");
        $st->execute([$id]);
        $fila = $st->fetch();
    } catch (Throwable) {
        $fila = false;
    }

    $medidas = ($fila && (int)$fila['ancho'] > 0 && (int)$fila['alto'] > 0)
        ? ['ancho' => (int)$fila['ancho'], 'alto' => (int)$fila['alto']]
        : [];

    return $cache[$id] = $medidas;
}

/**
 * Convierte un destino en una URL interna segura.
 *
 * Resuelve dos problemas a la vez:
 *
 * 1. La ruta base se aplicaba dos veces. Hay destinos que ya la llevan —los
 *    que salen de `url()`, y los `REQUEST_URI` que se guardan para volver
 *    después de iniciar sesión— y volver a anteponerla producía
 *    /webANTO/webANTO/pedido.php y un 404. No se nota cuando el sitio está en
 *    la raíz del dominio, porque ahí la base es una cadena vacía; aparece solo
 *    al instalarlo en una subcarpeta.
 *
 * 2. Un destino externo. El parámetro `?volver=` de la pantalla de acceso lo
 *    escribe quien visita la web, así que `?volver=https://otro-sitio` habría
 *    llevado al usuario fuera después de iniciar sesión, con la credibilidad
 *    que da venir de un enlace del sitio real. Cualquier destino con esquema
 *    propio o protocolo relativo se descarta y se vuelve al inicio.
 */
function url_interna(string $destino): string
{
    $destino = trim($destino);

    // Fuera del sitio: https://otro.com, //otro.com, javascript:…
    if ($destino === ''
        || str_starts_with($destino, '//')
        || preg_match('#^[a-z][a-z0-9+.-]*:#i', $destino)) {
        return url();
    }

    // Ya viene con la ruta base: se usa tal cual.
    if (BASE_URL !== '' && ($destino === BASE_URL || str_starts_with($destino, BASE_URL . '/'))) {
        return $destino;
    }

    // El sitio vive en la raíz y el destino ya es absoluto desde ella.
    if (BASE_URL === '' && str_starts_with($destino, '/')) {
        return $destino;
    }

    return url($destino);
}

/** Redirige dentro del sitio y termina. */
function redirigir(string $ruta, int $codigo = 302): never
{
    header('Location: ' . url_interna($ruta), true, $codigo);
    exit;
}

/**
 * Redirige a una dirección de otro dominio.
 *
 * Existe aparte de `redirigir()` a propósito: así salir del sitio es siempre
 * una decisión explícita de quien escribe el código, y nunca algo que pueda
 * provocar un valor que venga de la URL. Hoy solo la usa el acceso con Google.
 */
function redirigir_externo(string $url, int $codigo = 302): never
{
    if (!preg_match('#^https://#i', $url)) {
        error_log('Flowers Anto — redirección externa rechazada: ' . $url);
        redirigir('/');
    }
    header('Location: ' . $url, true, $codigo);
    exit;
}

/** Responde en JSON y termina la ejecución. */
function responderJson(array $datos, int $codigo = 200): never
{
    // Todo lo que se haya impreso antes se descarta. En un hosting compartido
    // basta un aviso de PHP —el clásico «session_start(): ps_files_cleanup_dir
    // Permission denied» de InfinityFree— para que se cuele delante del JSON:
    // el navegador recibe «Notice: …{"ok":true}», no puede leerlo y la
    // pantalla dice «Respuesta no válida del servidor», aunque la operación se
    // haya hecho bien. Una respuesta JSON tiene que ser JSON y nada más.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    if (!headers_sent()) {
        http_response_code($codigo);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
    }
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Atajo para respuestas de error en JSON. */
function errorJson(string $mensaje, int $codigo = 400, array $extra = []): never
{
    responderJson(['ok' => false, 'error' => $mensaje] + $extra, $codigo);
}

/** Formatea un importe con el símbolo de moneda configurado. */
function dinero(float|string|null $valor, ?string $simbolo = null): string
{
    $simbolo ??= Ajustes::texto('moneda_local', 'C$');
    return $simbolo . number_format((float)$valor, 2, '.', ',');
}

/** Fecha legible en español. */
function fecha_larga(?string $iso): string
{
    if (!$iso) {
        return '—';
    }
    $ts    = strtotime($iso);
    $meses = ['enero','febrero','marzo','abril','mayo','junio','julio',
              'agosto','septiembre','octubre','noviembre','diciembre'];
    return date('j', $ts) . ' de ' . $meses[(int)date('n', $ts) - 1] . ' de ' . date('Y, H:i', $ts);
}

/** Fecha corta dd/mm/aaaa. */
function fecha_corta(?string $iso): string
{
    return $iso ? date('d/m/Y', strtotime($iso)) : '—';
}

/**
 * Convierte un texto en un identificador apto para URL.
 * Ejemplo: "Ramo Gerbera Rosé" -> "ramo-gerbera-rose"
 */
function slugificar(string $texto): string
{
    $texto = trim($texto);
    if (function_exists('transliterator_transliterate')) {
        $t = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $texto);
        if (is_string($t)) {
            $texto = $t;
        }
    } else {
        $texto = mb_strtolower($texto, 'UTF-8');
        $texto = strtr($texto, [
            'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n','ç'=>'c',
            'à'=>'a','è'=>'e','ì'=>'i','ò'=>'o','ù'=>'u','â'=>'a','ê'=>'e','ô'=>'o',
        ]);
    }
    $texto = mb_strtolower($texto, 'UTF-8');
    $texto = preg_replace('/[^a-z0-9]+/u', '-', $texto) ?? '';
    $texto = trim($texto, '-');
    return $texto === '' ? 'articulo' : mb_substr($texto, 0, 90);
}

/** Recorta un texto sin cortar palabras. */
function recortar(string $texto, int $max = 140): string
{
    $texto = trim(preg_replace('/\s+/u', ' ', strip_tags($texto)) ?? '');
    if (mb_strlen($texto) <= $max) {
        return $texto;
    }
    $corte = mb_substr($texto, 0, $max);
    $ultimo = mb_strrpos($corte, ' ');
    return rtrim($ultimo ? mb_substr($corte, 0, $ultimo) : $corte, ' ,.;:') . '…';
}

/**
 * Convierte un valor de php.ini («8M», «512K», «1G») a bytes.
 */
function bytes_de_ini(string $valor): int
{
    $valor = trim($valor);
    if ($valor === '' || $valor === '-1') {
        return 0; // sin límite
    }
    $numero = (int)$valor;
    return match (strtolower(substr($valor, -1))) {
        'g'     => $numero * 1073741824,
        'm'     => $numero * 1048576,
        'k'     => $numero * 1024,
        default => $numero,
    };
}

/**
 * Tamaño de subida que de verdad acepta este servidor.
 *
 * El tope de la aplicación no sirve de nada si PHP corta antes. En un hosting
 * compartido `upload_max_filesize` suele ser mucho más bajo, y anunciar «64 MB»
 * cuando el servidor rechaza a los 2 solo consigue que alguien pierda el rato
 * intentándolo. Se anuncia el más pequeño de los tres.
 */
function limite_subida(int $topeAplicacion): int
{
    $limites = [$topeAplicacion];
    foreach (['upload_max_filesize', 'post_max_size'] as $clave) {
        $b = bytes_de_ini((string)ini_get($clave));
        if ($b > 0) {
            $limites[] = $b;
        }
    }
    return min($limites);
}

/**
 * `srcset` para una imagen guardada en la base.
 *
 * Solo tiene sentido con las referencias «bd:»: son las que `archivo.php`
 * puede reducir. Una ruta de disco o una URL externa se dejan como están, y
 * la función devuelve cadena vacía para que el `<img>` salga sin `srcset`.
 *
 * Mandar la foto de 1600 px a un teléfono que la pinta a 320 es lo que más
 * pesa en una tienda con fotos: aquí baja de medio mega a treinta kilobytes.
 */
function imagen_srcset(?string $ruta, array $anchos = [320, 480, 640, 960]): string
{
    $ruta = trim((string)$ruta);
    if (!preg_match('#^bd:(\d+)$#', $ruta, $m)) {
        return '';
    }
    $id     = (int)$m[1];
    $piezas = [];
    foreach ($anchos as $w) {
        $w = (int)$w;
        $piezas[] = url('archivo.php?id=' . $id . '&w=' . $w) . ' ' . $w . 'w';
    }
    return implode(', ', $piezas);
}

/**
 * ¿La referencia de imagen apunta a algo que existe?
 *
 * Las referencias «bd:» y las URL externas se dan por buenas: no son
 * archivos de este disco. Una ruta local sí se comprueba, porque pintar un
 * `<img>` roto en producción se ve peor que no pintar nada.
 */
/**
 * Código de verificación de Google Search Console, ya limpio.
 *
 * Se acepta pegado de las dos formas en que Google lo enseña: el código solo,
 * o la línea entera del registro DNS —«google-site-verification=CÓDIGO»—, que
 * es la que sale en el panel cuando se verifica por dominio. Pegar la línea
 * entera en la etiqueta la dejaría inservible y el fallo no se ve a simple
 * vista, así que se corrige aquí en vez de pedir que se recorte a mano.
 *
 * Devuelve cadena vacía si lo que hay no tiene forma de código, para no pintar
 * una etiqueta rota.
 */
function verificacion_google(): string
{
    $bruto = trim(Ajustes::texto('seo_google_verificacion', ''));
    if ($bruto === '') {
        return '';
    }
    if (str_contains($bruto, '=')) {
        $bruto = trim(substr($bruto, strrpos($bruto, '=') + 1));
    }
    $bruto = trim($bruto, "\"' \t\n\r");

    // El código de Google son letras, números, guiones y guiones bajos.
    return preg_match('/^[A-Za-z0-9_-]{20,100}$/', $bruto) ? $bruto : '';
}

function imagen_disponible(?string $ruta): bool
{
    $ruta = trim((string)$ruta);
    if ($ruta === '') {
        return false;
    }
    if (preg_match('#^(https?://|bd:)#i', $ruta)) {
        return true;
    }
    return is_file(RAIZ . '/' . ltrim($ruta, '/'));
}

/**
 * Singular o plural según la cantidad.
 *
 * Las pantallas del panel enseñan cifras que a veces valen 1: «1 imágenes»
 * se lee mal en una web que sale al público. Se recibe el plural, que es la
 * forma que ya estaba escrita, y se recorta cuando toca.
 */
function unidad_plural(int $cantidad, string $plural): string
{
    if ($cantidad === 1) {
        return match ($plural) {
            'imágenes' => 'imagen',
            'archivos' => 'archivo',
            'filas'    => 'fila',
            default    => rtrim($plural, 's'),
        };
    }
    return $plural;
}

/** Tamaño de archivo legible. */
function tamano_legible(int $bytes): string
{
    $u = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    $n = (float)$bytes;
    while ($n >= 1024 && $i < count($u) - 1) {
        $n /= 1024;
        $i++;
    }
    return round($n, $i === 0 ? 0 : 1) . ' ' . $u[$i];
}

/** IP del visitante, teniendo en cuenta proxies de confianza del hosting. */
function ip_cliente(): string
{
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $clave) {
        $valor = $_SERVER[$clave] ?? '';
        if ($valor === '') {
            continue;
        }
        $ip = trim(explode(',', $valor)[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }
    return '0.0.0.0';
}

/** Mensaje flash de un solo uso, para mostrar tras una redirección. */
function flash(?string $tipo = null, ?string $mensaje = null): ?array
{
    if ($tipo !== null) {
        $_SESSION['flash'] = ['tipo' => $tipo, 'mensaje' => $mensaje];
        return null;
    }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

/** Enlace de WhatsApp con el mensaje ya codificado. */
function enlace_whatsapp(string $mensaje, ?string $numero = null): string
{
    $numero = preg_replace('/\D+/', '', $numero ?? Ajustes::texto('whatsapp_numero', ''));
    if ($numero === '') {
        return '#';
    }
    return 'https://wa.me/' . $numero . '?text=' . rawurlencode($mensaje);
}
