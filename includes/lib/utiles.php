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

/** Construye una URL absoluta dentro del sitio a partir de una ruta relativa. */
function url(string $ruta = ''): string
{
    $ruta = ltrim($ruta, '/');
    return BASE_URL . ($ruta === '' ? '/' : '/' . $ruta);
}

/** URL completa con esquema y dominio. Se usa en correos y metadatos SEO. */
function url_absoluta(string $ruta = ''): string
{
    if (APP_URL !== '') {
        return APP_URL . url($ruta);
    }
    $esquema = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $esquema . '://' . $host . url($ruta);
}

/**
 * URL de un archivo del catálogo. Acepta rutas relativas guardadas en la base
 * (images/... o uploads/...) y deja pasar las absolutas por si algún día se
 * sirven las fotos desde un CDN.
 */
function url_imagen(?string $ruta, string $def = 'images/placeholders/logo.svg'): string
{
    $ruta = trim((string)$ruta);
    if ($ruta === '') {
        return url($def);
    }
    if (preg_match('#^https?://#i', $ruta)) {
        return $ruta;
    }
    return url($ruta);
}

/** Redirige y termina. */
function redirigir(string $ruta, int $codigo = 302): never
{
    header('Location: ' . (preg_match('#^https?://#i', $ruta) ? $ruta : url($ruta)), true, $codigo);
    exit;
}

/** Responde en JSON y termina la ejecución. */
function responderJson(array $datos, int $codigo = 200): never
{
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
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
