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

        return ['ok' => true, 'ruta' => 'uploads/' . $nombre, 'ancho' => $info[0], 'alto' => $info[1]];
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
