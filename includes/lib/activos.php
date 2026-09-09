<?php
/**
 * Une y comprime las hojas de estilo propias en un solo archivo.
 *
 * La portada pedía cuatro hojas, todas bloqueando el pintado. En una red
 * móvil cada petición cuesta un viaje de ida y vuelta —unos 150 ms— aunque el
 * archivo sea pequeño, así que lo caro no eran los bytes sino los viajes.
 *
 * El resultado se escribe en `assets/dist/`, que sí es accesible por web: lo
 * sirve el servidor directamente, sin volver a pasar por PHP. El nombre lleva
 * una huella del contenido, de modo que al cambiar cualquier hoja cambia el
 * nombre y el navegador se baja la nueva sin que haya que vaciar cachés.
 *
 * Si no se puede escribir —un hosting con la carpeta en solo lectura— la
 * función devuelve null y quien la llama vuelve a enlazar las hojas sueltas.
 * Preferimos una página más lenta a una página sin estilos.
 */

declare(strict_types=1);

final class Activos
{
    private const CARPETA = 'assets/dist';

    /**
     * Devuelve la ruta relativa del paquete, o null si no se pudo generar.
     *
     * @param string[] $hojas Rutas relativas a la raíz del proyecto, en el
     *                        orden exacto en que deben quedar: el orden es la
     *                        cascada, y cambiarlo cambia el diseño.
     */
    public static function css(array $hojas): ?string
    {
        $existentes = [];
        foreach ($hojas as $h) {
            $abs = RAIZ . '/' . ltrim($h, '/');
            if (is_file($abs)) {
                $existentes[] = $abs;
            }
        }
        if ($existentes === []) {
            return null;
        }

        // La huella incluye la fecha de cada archivo: al editar una hoja
        // cambia el nombre del paquete y nadie se queda con el anterior.
        $firma = '';
        foreach ($existentes as $abs) {
            $firma .= $abs . '|' . (string)@filemtime($abs) . '|' . (string)@filesize($abs) . "\n";
        }
        $clave   = substr(hash('sha256', $firma), 0, 16);
        $destino = self::CARPETA . '/app-' . $clave . '.css';
        $abs     = RAIZ . '/' . $destino;

        if (is_file($abs)) {
            return $destino;
        }

        $carpeta = RAIZ . '/' . self::CARPETA;
        if (!is_dir($carpeta) && !@mkdir($carpeta, 0775, true) && !is_dir($carpeta)) {
            return null;
        }
        if (!is_writable($carpeta)) {
            return null;
        }

        $css = '';
        foreach ($existentes as $ruta) {
            $contenido = @file_get_contents($ruta);
            if ($contenido === false) {
                return null;   // mejor las hojas sueltas que un paquete a medias
            }
            $css .= self::comprimir($contenido) . "\n";
        }

        // Nombre temporal y renombrado: dos visitas a la vez no pueden dejar
        // un archivo a medio escribir que luego se sirva roto.
        $temp = $abs . '.' . bin2hex(random_bytes(4));
        if (@file_put_contents($temp, $css) === false || !@rename($temp, $abs)) {
            @unlink($temp);
            return null;
        }

        // Copia comprimida junto al original. El hosting no está aplicando
        // compresión —PageSpeed lo midió: la hoja viaja entera y tarda 2,5 s—
        // así que se deja ya comprimida y el `.htaccess` la sirve a quien la
        // acepte. Si esa regla no estuviera disponible se sirve la normal:
        // por eso se escriben las dos.
        if (function_exists('gzencode')) {
            $gz = @gzencode($css, 9);
            if ($gz !== false) {
                $tempGz = $abs . '.gz.' . bin2hex(random_bytes(4));
                if (@file_put_contents($tempGz, $gz) !== false) {
                    if (!@rename($tempGz, $abs . '.gz')) {
                        @unlink($tempGz);
                    }
                }
            }
        }

        self::limpiarViejos($carpeta, basename($abs));
        return $destino;
    }

    /**
     * Quita comentarios y espacio sobrante.
     *
     * Es deliberadamente conservador. Un minificador agresivo que recorta
     * espacios alrededor de la puntuación se come cosas como `content:"a; b"`
     * o un `url(data:...)`, y el fallo aparece en una sola pantalla difícil de
     * encontrar. Aquí las cadenas y las `url()` se apartan antes de tocar
     * nada y se devuelven al final intactas. Con la compresión del servidor
     * encima, lo que se gana afinando más es marginal.
     */
    private static function comprimir(string $css): string
    {
        $guardados = [];
        $css = preg_replace_callback(
            '#(url\(\s*[^)]*\))|("(?:\\\\.|[^"\\\\])*")|(\'(?:\\\\.|[^\'\\\\])*\')#i',
            function (array $m) use (&$guardados): string {
                $guardados[] = $m[0];
                return "\x01" . (count($guardados) - 1) . "\x02";
            },
            $css
        ) ?? $css;

        $css = preg_replace('#/\*.*?\*/#s', '', $css) ?? $css;   // comentarios
        $css = preg_replace('#\s+#', ' ', $css) ?? $css;          // espacio en blanco
        // Los dos puntos quedan fuera a propósito: recortar el espacio de
        // «.tarjeta :hover» —descendiente— lo convertiría en «.tarjeta:hover»,
        // que selecciona otra cosa. Lo que se ahorraría ahí lo hace ya la
        // compresión del servidor.
        $css = preg_replace('#\s*([{};,])\s*#', '$1', $css) ?? $css;
        $css = preg_replace('#;}#', '}', $css) ?? $css;           // último punto y coma
        $css = trim($css);

        return preg_replace_callback('#\x01(\d+)\x02#', fn(array $m): string => $guardados[(int)$m[1]], $css) ?? $css;
    }

    /** Deja solo el paquete en uso: los anteriores ya no los pide nadie. */
    private static function limpiarViejos(string $carpeta, string $actual): void
    {
        foreach (@glob($carpeta . '/app-*.css*') ?: [] as $viejo) {
            $base = basename($viejo);
            if ($base !== $actual && $base !== $actual . '.gz'
                && @filemtime($viejo) < time() - 3600) {
                @unlink($viejo);
            }
        }
    }
}
