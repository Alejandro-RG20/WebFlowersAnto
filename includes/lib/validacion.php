<?php
/**
 * Validación y saneado de entrada.
 *
 * Regla del proyecto: ningún dato llega a la base sin pasar por una de estas
 * funciones. Las validaciones del navegador son ayuda visual, nunca la defensa.
 */

declare(strict_types=1);

/** Lee un campo crudo de POST (o del array indicado) como cadena. */
function crudo(string $campo, ?array $fuente = null): string
{
    $fuente ??= $_POST;
    $v = $fuente[$campo] ?? '';
    return is_scalar($v) ? (string)$v : '';
}

/** Texto recortado y sin etiquetas. Devuelve '' si no viene nada. */
function texto(string $campo, int $max = 255, ?array $fuente = null): string
{
    $v = trim(crudo($campo, $fuente));
    $v = strip_tags($v);
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v) ?? '';
    return mb_substr($v, 0, $max);
}

/** Texto multilínea: conserva los saltos de línea, quita etiquetas. */
function textoLargo(string $campo, int $max = 5000, ?array $fuente = null): string
{
    $v = trim(crudo($campo, $fuente));
    $v = strip_tags($v);
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v) ?? '';
    $v = preg_replace('/\R{3,}/u', "\n\n", $v) ?? '';
    return mb_substr($v, 0, $max);
}

/**
 * Entero saneado dentro de un rango.
 *
 * El rango se aplica al valor recibido, no al valor por defecto: así
 * `entero('id', 1, PHP_INT_MAX, 0)` devuelve 0 cuando no viene ningún id,
 * que es lo que distingue «crear» de «editar».
 */
function entero(string $campo, int $min = 0, int $max = PHP_INT_MAX, int $def = 0, ?array $fuente = null): int
{
    $bruto = crudo($campo, $fuente);
    if ($bruto === '') {
        return $def;
    }
    $v = filter_var($bruto, FILTER_VALIDATE_INT);
    if ($v === false) {
        return $def;
    }
    return max($min, min($max, (int)$v));
}

/**
 * Identificador de un registro.
 *
 * Devuelve 0 cuando no viene, no es un número o es negativo. Se usa un helper
 * propio en vez de `entero()` porque ahí el mínimo se aplica al valor recibido:
 * pedir `entero('id', 1)` convertiría un `id=0` (que significa «crear») en un 1
 * (que significa «editar el registro 1»). Ese matiz es demasiado fácil de pasar
 * por alto en cada llamada.
 */
function identificador(string $campo, ?array $fuente = null): int
{
    $v = filter_var(crudo($campo, $fuente), FILTER_VALIDATE_INT);
    return ($v === false || $v < 0) ? 0 : (int)$v;
}

/** Decimal saneado, nunca negativo. Acepta coma o punto decimal. */
function decimal(string $campo, float $max = 9999999.99, ?array $fuente = null): float
{
    $v = filter_var(str_replace(',', '.', crudo($campo, $fuente)), FILTER_VALIDATE_FLOAT);
    if ($v === false || $v < 0) {
        $v = 0.0;
    }
    return round(min($v, $max), 2);
}

/** Casilla de verificación: 1 si viene marcada. */
function casilla(string $campo, ?array $fuente = null): int
{
    $fuente ??= $_POST;
    return isset($fuente[$campo]) && !in_array((string)$fuente[$campo], ['', '0', 'false', 'off'], true) ? 1 : 0;
}

/** Color hexadecimal (#RRGGBB). Devuelve $def si el valor no es válido. */
function colorHex(string $campo, string $def = '#EFD9DE', ?array $fuente = null): string
{
    $v = trim(crudo($campo, $fuente));
    return preg_match('/^#[0-9A-Fa-f]{6}$/', $v) ? strtoupper($v) : $def;
}

/** Fecha YYYY-MM-DD, o null si viene vacía o mal formada. */
function fechaOpcional(string $campo, ?array $fuente = null): ?string
{
    $v = trim(crudo($campo, $fuente));
    if ($v === '') {
        return null;
    }
    $d = DateTime::createFromFormat('Y-m-d', $v);
    return ($d && $d->format('Y-m-d') === $v) ? $v : null;
}

/** URL http(s) válida, o '' si no lo es. Corta javascript: y data:. */
function urlOpcional(string $campo, int $max = 255, ?array $fuente = null): string
{
    $v = trim(crudo($campo, $fuente));
    if ($v === '') {
        return '';
    }
    if (!filter_var($v, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $v)) {
        return '';
    }
    return mb_substr($v, 0, $max);
}

/**
 * Ruta de imagen dentro del proyecto.
 * Solo se aceptan rutas relativas bajo images/ o uploads/: bloquea el
 * salto de directorio (../) y las URLs externas.
 */
function rutaImagen(string $campo, string $def = '', ?array $fuente = null): string
{
    $v = trim(crudo($campo, $fuente));
    if ($v === '') {
        return $def;
    }
    $v = str_replace('\\', '/', $v);
    if (str_contains($v, '..') || preg_match('#^([a-z]+:)?//#i', $v)) {
        return $def;
    }
    if (!preg_match('#^(images|uploads)/[A-Za-z0-9._/\x{00C0}-\x{024F}-]+$#u', $v)) {
        return $def;
    }
    return mb_substr($v, 0, 255);
}

/** Solo dígitos (números de teléfono y de WhatsApp). */
function soloDigitos(string $campo, int $max = 20, ?array $fuente = null): string
{
    return mb_substr(preg_replace('/\D+/', '', crudo($campo, $fuente)) ?? '', 0, $max);
}

/** Normaliza un correo. Devuelve '' si no es válido. */
function correoValido(string $campo, ?array $fuente = null): string
{
    $v = mb_strtolower(trim(crudo($campo, $fuente)));
    $v = filter_var($v, FILTER_SANITIZE_EMAIL);
    if (!filter_var($v, FILTER_VALIDATE_EMAIL) || mb_strlen($v) > 150) {
        return '';
    }
    // Descarta correos sin punto en el dominio (a@b), que ningún proveedor real usa.
    $dominio = substr(strrchr($v, '@') ?: '', 1);
    return str_contains($dominio, '.') ? $v : '';
}

/**
 * Teléfono: entre 8 y 15 dígitos, admite prefijo internacional.
 * Devuelve la versión normalizada (solo dígitos, con + si venía) o ''.
 */
function telefonoValido(string $campo, ?array $fuente = null): string
{
    $bruto   = trim(crudo($campo, $fuente));
    $digitos = preg_replace('/\D+/', '', $bruto) ?? '';
    $largo   = strlen($digitos);
    if ($largo < 8 || $largo > 15) {
        return '';
    }
    return (str_starts_with($bruto, '+') ? '+' : '') . $digitos;
}

/**
 * Comprueba la fuerza de una contraseña.
 * Devuelve un mensaje de error, o '' si la contraseña es aceptable.
 */
function revisarPassword(string $password, ?string $confirmacion = null): string
{
    if (mb_strlen($password) < 8) {
        return 'La contraseña debe tener al menos 8 caracteres.';
    }
    if (mb_strlen($password) > 200) {
        return 'La contraseña es demasiado larga.';
    }
    if (!preg_match('/[A-Za-zÁÉÍÓÚÑáéíóúñ]/u', $password) || !preg_match('/\d/', $password)) {
        return 'La contraseña debe combinar al menos una letra y un número.';
    }
    $comunes = ['12345678', 'password', 'contrasena', 'contraseña', 'qwerty123', 'admin123', 'flowers123'];
    if (in_array(mb_strtolower($password), $comunes, true)) {
        return 'Esa contraseña es demasiado común. Elige otra.';
    }
    if ($confirmacion !== null && $password !== $confirmacion) {
        return 'Las contraseñas no coinciden.';
    }
    return '';
}

/** Uno de los valores permitidos, o el primero de la lista. */
function opcion(string $campo, array $permitidos, ?string $def = null, ?array $fuente = null): string
{
    $v = trim(crudo($campo, $fuente));
    if (in_array($v, $permitidos, true)) {
        return $v;
    }
    return $def ?? (string)($permitidos[0] ?? '');
}
