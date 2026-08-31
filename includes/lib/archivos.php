<?php
/**
 * Subida de archivos.
 *
 * Dos destinos, con reglas distintas:
 *   - imágenes del catálogo → uploads/, públicas, solo formatos de imagen
 *   - comprobantes de pago  → storage/comprobantes/, fuera del alcance del
 *     navegador; se sirven por comprobante.php previa comprobación de permisos
 *
 * En los dos casos el tipo se decide por el CONTENIDO del archivo, nunca por
 * su extensión, y el nombre final lo genera el servidor.
 */

declare(strict_types=1);

final class Archivos
{
    /**
     * Lado máximo de las fotos del catálogo, en píxeles.
     *
     * Las fotos salen del teléfono a 3000–4000 px. Servidas tal cual, una
     * sola pantalla del catálogo puede pesar decenas de MB, que con datos
     * móviles es una tienda que no carga. 1600 px sobra para verlas a pantalla
     * completa en cualquier teléfono.
     */
    private const LADO_MAX = 1600;

    private const IMAGENES = [
        IMAGETYPE_JPEG => ['jpg',  'image/jpeg'],
        IMAGETYPE_PNG  => ['png',  'image/png'],
        IMAGETYPE_GIF  => ['gif',  'image/gif'],
        IMAGETYPE_WEBP => ['webp', 'image/webp'],
    ];

    /** Traduce el código de error de PHP a un mensaje para el cliente. */
    public static function errorSubida(int $codigo): string
    {
        return match ($codigo) {
            UPLOAD_ERR_OK        => '',
            UPLOAD_ERR_INI_SIZE,
            UPLOAD_ERR_FORM_SIZE => 'El archivo supera el tamaño permitido por el servidor.',
            UPLOAD_ERR_PARTIAL   => 'El archivo no llegó completo. Vuelve a intentarlo.',
            UPLOAD_ERR_NO_FILE   => 'No seleccionaste ningún archivo.',
            UPLOAD_ERR_NO_TMP_DIR,
            UPLOAD_ERR_CANT_WRITE => 'El servidor no pudo guardar el archivo. Avísanos por WhatsApp.',
            UPLOAD_ERR_EXTENSION  => 'El servidor rechazó el archivo.',
            default               => 'No pudimos procesar el archivo.',
        };
    }

    /**
     * Guarda una imagen del catálogo en uploads/.
     * @return array{ok: bool, error?: string, ruta?: string, ancho?: int, alto?: int}
     */
    public static function guardarImagen(array $archivo): array
    {
        $error = self::validarBasico($archivo, MAX_UPLOAD_BYTES);
        if ($error !== '') {
            return ['ok' => false, 'error' => $error];
        }

        $info = @getimagesize($archivo['tmp_name']);
        if ($info === false || !isset(self::IMAGENES[$info[2]])) {
            return ['ok' => false, 'error' => 'Ese archivo no es una imagen válida. Usa JPG, PNG, GIF o WEBP.'];
        }

        $dir = RAIZ . '/uploads';
        if (!self::prepararDirectorio($dir)) {
            return ['ok' => false, 'error' => 'No se pudo preparar la carpeta de subidas.'];
        }

        $nombre = 'img_' . bin2hex(random_bytes(8)) . '.' . self::IMAGENES[$info[2]][0];
        if (!move_uploaded_file($archivo['tmp_name'], $dir . '/' . $nombre)) {
            error_log('Flowers Anto — no se pudo mover la subida a ' . $dir);
            return ['ok' => false, 'error' => 'No se pudo guardar la imagen.'];
        }
        @chmod($dir . '/' . $nombre, 0644);

        [$ancho, $alto] = self::optimizar($dir . '/' . $nombre, $info[2], $info[0], $info[1]);

        return ['ok' => true, 'ruta' => 'uploads/' . $nombre, 'ancho' => $ancho, 'alto' => $alto];
    }

    /**
     * Reduce la foto si viene enorme y la endereza si el teléfono la giró.
     *
     * Al recodificar se pierden de paso los metadatos EXIF, que en una foto
     * hecha con el móvil incluyen las coordenadas del sitio donde se tomó:
     * publicar la dirección de la floristería en cada foto no hace falta.
     *
     * Si algo falla se conserva el archivo tal cual llegó. Vale más una foto
     * pesada que una subida perdida.
     *
     * @return array{0:int,1:int} ancho y alto finales
     */
    private static function optimizar(string $ruta, int $tipo, int $ancho, int $alto): array
    {
        if (!function_exists('imagecreatetruecolor') || $ancho < 1 || $alto < 1) {
            return [$ancho, $alto];
        }

        // Una imagen enorme puede agotar la memoria de PHP: si no cabe con
        // holgura, se deja como está en vez de tumbar la petición.
        $limite = self::limiteMemoria();
        if ($limite > 0 && ($ancho * $alto * 4 * 2.2) > ($limite - memory_get_usage(true))) {
            return [$ancho, $alto];
        }

        $giro = self::giroExif($ruta, $tipo);
        $escala = min(1.0, self::LADO_MAX / max($ancho, $alto));
        if ($escala >= 1.0 && $giro === 0) {
            return [$ancho, $alto];   // ya está bien: no se toca
        }

        $origen = match ($tipo) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($ruta),
            IMAGETYPE_PNG  => @imagecreatefrompng($ruta),
            IMAGETYPE_GIF  => @imagecreatefromgif($ruta),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($ruta) : false,
            default        => false,
        };
        if (!$origen) {
            return [$ancho, $alto];
        }

        $nuevoAncho = max(1, (int)round($ancho * $escala));
        $nuevoAlto  = max(1, (int)round($alto  * $escala));

        $destino = imagecreatetruecolor($nuevoAncho, $nuevoAlto);
        // PNG, GIF y WEBP pueden traer transparencia: la foto recortada del
        // carrusel depende de que se conserve.
        if ($tipo !== IMAGETYPE_JPEG) {
            imagealphablending($destino, false);
            imagesavealpha($destino, true);
            imagefill($destino, 0, 0, imagecolorallocatealpha($destino, 0, 0, 0, 127));
        }
        imagecopyresampled($destino, $origen, 0, 0, 0, 0, $nuevoAncho, $nuevoAlto, $ancho, $alto);

        if ($giro !== 0 && function_exists('imagerotate')) {
            $girada = @imagerotate($destino, $giro, 0);
            if ($girada) {
                if ($tipo !== IMAGETYPE_JPEG) { imagesavealpha($girada, true); }
                imagedestroy($destino);
                $destino = $girada;
                $nuevoAncho = imagesx($destino);
                $nuevoAlto  = imagesy($destino);
            }
        }

        $ok = match ($tipo) {
            IMAGETYPE_JPEG => imagejpeg($destino, $ruta, 82),
            IMAGETYPE_PNG  => imagepng($destino, $ruta, 6),
            IMAGETYPE_GIF  => imagegif($destino, $ruta),
            IMAGETYPE_WEBP => function_exists('imagewebp') && imagewebp($destino, $ruta, 82),
            default        => false,
        };
        imagedestroy($origen);
        imagedestroy($destino);

        if (!$ok) {
            error_log('Flowers Anto — no se pudo reescribir la imagen ' . basename($ruta));
            return [$ancho, $alto];
        }
        @chmod($ruta, 0644);
        return [$nuevoAncho, $nuevoAlto];
    }

    /** Grados que hay que girar según el EXIF del teléfono. */
    private static function giroExif(string $ruta, int $tipo): int
    {
        if ($tipo !== IMAGETYPE_JPEG || !function_exists('exif_read_data')) {
            return 0;
        }
        $exif = @exif_read_data($ruta);
        return match ((int)($exif['Orientation'] ?? 0)) {
            3       => 180,
            6       => -90,
            8       => 90,
            default => 0,
        };
    }

    /** memory_limit en bytes; 0 si es ilimitado. */
    private static function limiteMemoria(): int
    {
        $v = trim((string)ini_get('memory_limit'));
        if ($v === '' || $v === '-1') {
            return 0;
        }
        $n = (int)$v;
        return match (strtolower(substr($v, -1))) {
            'g'     => $n * 1073741824,
            'm'     => $n * 1048576,
            'k'     => $n * 1024,
            default => $n,
        };
    }

    /**
     * Guarda un comprobante de pago en storage/comprobantes/.
     * Admite imagen o PDF. Nunca guarda nada ejecutable.
     *
     * @return array{ok: bool, error?: string, nombre?: string, nombre_original?: string,
     *               mime?: string, tamano?: int, hash?: string}
     */
    public static function guardarComprobante(array $archivo): array
    {
        $error = self::validarBasico($archivo, MAX_COMPROBANTE_BYTES);
        if ($error !== '') {
            return ['ok' => false, 'error' => $error];
        }

        $ruta      = $archivo['tmp_name'];
        $extension = null;
        $mime      = null;

        // 1) ¿Es una imagen? Lo decide getimagesize, que lee la cabecera real.
        $info = @getimagesize($ruta);
        if ($info !== false && isset(self::IMAGENES[$info[2]])) {
            [$extension, $mime] = self::IMAGENES[$info[2]];
        } else {
            // 2) ¿Es un PDF? Los primeros bytes de un PDF son siempre "%PDF-".
            $manejador = @fopen($ruta, 'rb');
            $cabecera  = $manejador ? (string)fread($manejador, 5) : '';
            if ($manejador) {
                fclose($manejador);
            }
            if ($cabecera === '%PDF-') {
                $extension = 'pdf';
                $mime      = 'application/pdf';
            }
        }

        if ($extension === null) {
            return ['ok' => false,
                    'error' => 'El comprobante debe ser una imagen (JPG, PNG o WEBP) o un PDF.'];
        }

        // Comprobación cruzada con finfo: si el sistema opina otra cosa, se rechaza.
        if (class_exists('finfo')) {
            $detectado = (new finfo(FILEINFO_MIME_TYPE))->file($ruta);
            $aceptados = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'];
            if ($detectado === false || !in_array($detectado, $aceptados, true)) {
                return ['ok' => false, 'error' => 'No pudimos reconocer el archivo. Sube una captura o un PDF.'];
            }
            $mime = $detectado;
        }

        if (!self::prepararDirectorio(DIR_COMPROBANTES)) {
            return ['ok' => false, 'error' => 'No se pudo preparar la carpeta de comprobantes.'];
        }

        $nombre  = 'cmp_' . date('Ymd') . '_' . bin2hex(random_bytes(10)) . '.' . $extension;
        $destino = DIR_COMPROBANTES . '/' . $nombre;
        $hash    = (string)hash_file('sha256', $ruta);
        $tamano  = (int)$archivo['size'];

        if (!move_uploaded_file($ruta, $destino)) {
            error_log('Flowers Anto — no se pudo mover el comprobante a ' . DIR_COMPROBANTES);
            return ['ok' => false, 'error' => 'No se pudo guardar el comprobante.'];
        }
        @chmod($destino, 0640);

        return [
            'ok'              => true,
            'nombre'          => $nombre,
            'nombre_original' => mb_substr(self::nombreSeguro((string)$archivo['name']), 0, 150),
            'mime'            => (string)$mime,
            'tamano'          => $tamano,
            'hash'            => $hash,
        ];
    }

    /** Comprobaciones comunes a cualquier subida. Devuelve '' si todo va bien. */
    private static function validarBasico(array $archivo, int $maximo): string
    {
        if (!isset($archivo['error'], $archivo['tmp_name'], $archivo['size'])) {
            return 'No se recibió ningún archivo.';
        }
        $error = self::errorSubida((int)$archivo['error']);
        if ($error !== '') {
            return $error;
        }
        if (!is_uploaded_file($archivo['tmp_name'])) {
            return 'Archivo no válido.';
        }
        if ((int)$archivo['size'] <= 0) {
            return 'El archivo está vacío.';
        }
        if ((int)$archivo['size'] > $maximo) {
            return 'El archivo pesa más de ' . round($maximo / 1048576, 1) . ' MB. Reduce su tamaño.';
        }
        return '';
    }

    /** Crea el directorio si falta y lo protege del acceso directo por HTTP. */
    private static function prepararDirectorio(string $dir): bool
    {
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }
        // Dentro de storage/ no debe poder entrar nadie por URL, ni siquiera
        // si el hosting sirve la carpeta. En uploads/ sí: son fotos públicas.
        if (str_starts_with($dir, DIR_STORAGE)) {
            $htaccess = $dir . '/.htaccess';
            if (!is_file($htaccess)) {
                @file_put_contents($htaccess, "Require all denied\n<IfModule !mod_authz_core.c>\n Deny from all\n</IfModule>\n");
            }
        }
        return is_writable($dir);
    }

    /** Limpia un nombre de archivo recibido del navegador. */
    public static function nombreSeguro(string $nombre): string
    {
        $nombre = basename(str_replace('\\', '/', $nombre));
        $nombre = preg_replace('/[^\w.\- ]+/u', '', $nombre) ?? '';
        $nombre = trim(preg_replace('/\.{2,}/', '.', $nombre) ?? '', '. ');
        return $nombre !== '' ? $nombre : 'archivo';
    }

    /** Borra un archivo dentro de un directorio controlado, sin salir de él. */
    public static function borrarDe(string $dir, string $nombre): bool
    {
        $nombre = basename($nombre);
        $ruta   = $dir . '/' . $nombre;
        $real   = realpath($ruta);
        if ($real === false || !str_starts_with($real, realpath($dir) ?: $dir)) {
            return false;
        }
        return @unlink($real);
    }

    /** Entrega un archivo al navegador con las cabeceras correctas. */
    public static function servir(string $ruta, string $mime, string $nombreVisible, bool $descargar = false): never
    {
        if (!is_file($ruta) || !is_readable($ruta)) {
            http_response_code(404);
            exit('Archivo no encontrado.');
        }
        // Solo se permiten tipos que el navegador no ejecuta como HTML.
        $permitidos = ['image/jpeg', 'image/png', 'image/gif', 'image/webp',
                       'application/pdf', 'application/sql', 'application/octet-stream'];
        if (!in_array($mime, $permitidos, true)) {
            $mime = 'application/octet-stream';
        }

        if (ob_get_level()) {
            ob_end_clean();
        }
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($ruta));
        header('Content-Disposition: ' . ($descargar ? 'attachment' : 'inline')
             . '; filename="' . self::nombreSeguro($nombreVisible) . '"');
        header('X-Content-Type-Options: nosniff');
        header('Content-Security-Policy: default-src \'none\'; img-src \'self\'; object-src \'none\'');
        header('Cache-Control: private, no-store');
        readfile($ruta);
        exit;
    }
}
