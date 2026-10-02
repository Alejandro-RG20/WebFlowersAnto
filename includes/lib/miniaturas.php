<?php
/**
 * Copias reducidas y en WebP de las fotos guardadas en la base.
 *
 * `archivo.php` las crea la primera vez que alguien las pide y las deja en
 * storage/cache/img. El problema era esa primera vez: con una docena de fotos
 * pedidas a la vez solo una se convierte (el turno evita tumbar el hosting) y
 * las demás reciben el original, que en los recortes PNG de producto pasa del
 * mega. Tras un cambio de hosting la caché empieza vacía y eso lo sufría cada
 * visitante hasta que se llenaba.
 *
 * `preparar()` hace ese trabajo por adelantado: al subir cada foto y, para las
 * que ya estaban, desde Respaldos → Mantenimiento.
 *
 * Solo depende de PDO y GD: lo carga también `archivo.php`, que arranca sin
 * sesión ni ajustes.
 */

declare(strict_types=1);

final class Miniaturas
{
    /** Anchos que se pueden pedir. Una lista cerrada evita que alguien llene
     *  el disco pidiendo mil tamaños distintos de la misma foto. */
    public const ANCHOS = [160, 320, 480, 640, 960, 1280];

    public static function carpeta(): string
    {
        return RAIZ . '/storage/cache/img';
    }

    /**
     * Sufijo de la copia en disco. Distingue el ancho y el formato: sin él,
     * la versión WebP y la original de un mismo ancho compartirían archivo y
     * se serviría una por otra.
     */
    public static function sufijo(int $ancho, bool $webp): string
    {
        return ($ancho > 0 ? '-' . $ancho : '-orig') . ($webp ? '.webp' : '');
    }

    public static function ruta(string $sha256, string $sufijo): string
    {
        return self::carpeta() . '/' . $sha256 . $sufijo . '.bin';
    }

    /**
     * ¿Se gana pasándola a WebP? Solo JPEG y PNG. El GIF queda fuera porque
     * puede estar animado y GD lo aplastaría a un solo fotograma.
     */
    public static function convertible(string $mime): bool
    {
        return in_array($mime, ['image/jpeg', 'image/png'], true) && function_exists('imagewebp');
    }

    /** Escribe con nombre temporal y renombra: nunca queda un archivo a medias. */
    public static function guardar(string $ruta, string $bytes): void
    {
        if (!is_dir(dirname($ruta))) {
            @mkdir(dirname($ruta), 0775, true);
        }
        $temp = $ruta . '.' . bin2hex(random_bytes(4));
        if (@file_put_contents($temp, $bytes) !== false) {
            @rename($temp, $ruta);
        } else {
            @unlink($temp);
        }
    }

    /**
     * Devuelve la imagen en el ancho y el formato pedidos, o null si no se pudo.
     *
     * Un ancho de 0 significa «no cambies el tamaño»: se usa cuando lo único
     * que hace falta es cambiar de formato.
     */
    public static function transformar(PDO $pdo, int $id, int $ancho, string $mimeOrigen, string $mimeSalida): ?string
    {
        $img = self::abrir($pdo, $id);
        if ($img === null) {
            return null;
        }
        $bytes = self::codificar($img, $ancho, $mimeOrigen, $mimeSalida);
        imagedestroy($img);
        return $bytes;
    }

    /**
     * Crea por adelantado las copias que la web pide de una foto.
     *
     * Abre el original una sola vez y saca de él todos los anchos. Lo que ya
     * existe en disco no se vuelve a hacer. Con $hasta (microtime) se corta a
     * tiempo para no pasarse del límite de ejecución del hosting.
     *
     * @return array{hechas: int, completa: bool}
     */
    public static function preparar(PDO $pdo, int $id, float $hasta = 0.0): array
    {
        $st = $pdo->prepare("SELECT mime, ancho, sha256 FROM archivos WHERE id = ?");
        $st->execute([$id]);
        $meta = $st->fetch();
        if (!$meta || !function_exists('imagecreatefromstring')) {
            return ['hechas' => 0, 'completa' => true];
        }
        $mime = (string)$meta['mime'];
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return ['hechas' => 0, 'completa' => true];   // GIF y SVG se sirven tal cual
        }

        $pendientes = [];
        foreach (self::variantes($mime, (int)$meta['ancho']) as [$ancho, $salida]) {
            $ruta = self::ruta((string)$meta['sha256'], self::sufijo($ancho, $salida !== $mime));
            if (!is_file($ruta)) {
                $pendientes[] = [$ancho, $salida, $ruta];
            }
        }
        if (!$pendientes) {
            return ['hechas' => 0, 'completa' => true];
        }

        $img = self::abrir($pdo, $id);
        if ($img === null) {
            return ['hechas' => 0, 'completa' => true];   // no se puede: que la sirva tal cual
        }
        $hechas = 0;
        foreach ($pendientes as [$ancho, $salida, $ruta]) {
            if ($hasta > 0 && microtime(true) > $hasta) {
                imagedestroy($img);
                return ['hechas' => $hechas, 'completa' => false];
            }
            $bytes = self::codificar($img, $ancho, $mime, $salida);
            if ($bytes !== null) {
                self::guardar($ruta, $bytes);
                $hechas++;
            }
        }
        imagedestroy($img);
        return ['hechas' => $hechas, 'completa' => true];
    }

    /**
     * Cuántas fotos de la base tienen ya todas sus copias.
     *
     * @return array{listas: int, total: int}
     */
    public static function estado(PDO $pdo): array
    {
        $listas = 0;
        $total  = 0;
        foreach ($pdo->query("SELECT mime, ancho, sha256 FROM archivos
                               WHERE mime IN ('image/jpeg','image/png','image/webp')") as $f) {
            $total++;
            $completa = true;
            foreach (self::variantes((string)$f['mime'], (int)$f['ancho']) as [$ancho, $salida]) {
                if (!is_file(self::ruta((string)$f['sha256'], self::sufijo($ancho, $salida !== $f['mime'])))) {
                    $completa = false;
                    break;
                }
            }
            $listas += $completa ? 1 : 0;
        }
        return ['listas' => $listas, 'total' => $total];
    }

    /**
     * Las copias que sirve archivo.php a un navegador que entiende WebP, que
     * hoy son prácticamente todos: cada ancho menor que el original y el
     * original a tamaño completo, en WebP si se puede convertir.
     *
     * @return list<array{0:int,1:string}> [ancho, mime de salida]
     */
    private static function variantes(string $mime, int $anchoOriginal): array
    {
        $salida = self::convertible($mime) ? 'image/webp' : $mime;
        $lista  = [];
        foreach (self::ANCHOS as $w) {
            if ($anchoOriginal <= 0 || $w < $anchoOriginal) {
                $lista[] = [$w, $salida];
            }
        }
        if ($salida !== $mime) {
            $lista[] = [0, $salida];
        }
        return $lista;
    }

    /** @return GdImage|null */
    private static function abrir(PDO $pdo, int $id)
    {
        $st = $pdo->prepare("SELECT datos FROM archivos WHERE id = ?");
        $st->execute([$id]);
        $original = (string)$st->fetchColumn();
        if ($original === '') {
            return null;
        }
        // Un límite de memoria escaso es lo normal en hosting compartido: si
        // la foto no cabe, mejor mandar el original que tumbar la petición.
        $limite = ini_get('memory_limit');
        if ($limite !== false && $limite !== '-1'
            && (int)$limite > 0 && strlen($original) * 12 > (int)$limite * 1048576) {
            return null;
        }
        $img = @imagecreatefromstring($original);
        return ($img !== false && imagesx($img) > 0 && imagesy($img) > 0) ? $img : null;
    }

    /** La transparencia se conserva siempre: los recortes de producto la necesitan. */
    private static function codificar($img, int $ancho, string $mimeOrigen, string $mimeSalida): ?string
    {
        $anchoOriginal = imagesx($img);
        $altoOriginal  = imagesy($img);

        // Sin reducción posible y sin cambio de formato no hay nada que hacer:
        // el original tal cual siempre será mejor.
        $reduce = $ancho > 0 && $ancho < $anchoOriginal;
        if (!$reduce && $mimeSalida === $mimeOrigen) {
            return null;
        }

        $anchoFinal = $reduce ? $ancho : $anchoOriginal;
        $altoFinal  = $reduce
            ? max(1, (int)round($altoOriginal * ($ancho / $anchoOriginal)))
            : $altoOriginal;

        $destino = imagecreatetruecolor($anchoFinal, $altoFinal);
        $conAlfa = in_array($mimeOrigen, ['image/png', 'image/webp', 'image/gif'], true)
                || in_array($mimeSalida, ['image/png', 'image/webp'], true);
        if ($conAlfa) {
            imagealphablending($destino, false);
            imagesavealpha($destino, true);
            imagefill($destino, 0, 0, imagecolorallocatealpha($destino, 0, 0, 0, 127));
        }
        imagecopyresampled($destino, $img, 0, 0, 0, 0, $anchoFinal, $altoFinal, $anchoOriginal, $altoOriginal);

        ob_start();
        $ok = match ($mimeSalida) {
            'image/webp' => imagewebp($destino, null, 82),
            'image/png'  => imagepng($destino, null, 7),
            'image/gif'  => imagegif($destino),
            default      => imagejpeg($destino, null, 82),
        };
        $bytes = (string)ob_get_clean();
        imagedestroy($destino);

        return $ok && $bytes !== '' ? $bytes : null;
    }
}
