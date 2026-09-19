<?php
/**
 * Contenido multimedia de redes sociales.
 *
 * El panel pide un enlace pegado y nada más: aquí se decide de qué plataforma
 * es, se saca el identificador y se construye la dirección con la que se
 * incrusta. Esa dirección la arma el servidor pieza a pieza; lo que escribió
 * la persona nunca se usa como HTML ni se mete tal cual en un `src`.
 *
 * La comprobación es del HOST, no del texto del enlace. Buscar «youtube» con
 * `str_contains` daría por bueno `https://malo.example/youtube.com/x`, que es
 * justo el agujero que se quiere evitar. Por eso se parte la URL y se compara
 * el dominio contra una lista cerrada.
 *
 * No se descarga ningún video: cada plataforma tiene una dirección oficial de
 * incrustación que funciona dentro de un `<iframe>` sin su SDK, y es la que
 * se usa. Así no entra ningún script de terceros en la tienda.
 */

declare(strict_types=1);

final class Multimedia
{
    /**
     * Dominios admitidos por plataforma, ya sin el «www.».
     *
     * Están escritos enteros a propósito: cualquier comodín dejaría entrar
     * subdominios que no controlamos.
     */
    private const DOMINIOS = [
        'youtube'   => ['youtube.com', 'youtu.be', 'youtube-nocookie.com'],
        'instagram' => ['instagram.com'],
        'facebook'  => ['facebook.com', 'fb.watch'],
        'tiktok'    => ['tiktok.com'],
    ];

    private const NOMBRES = [
        'youtube'   => 'YouTube',
        'instagram' => 'Instagram',
        'facebook'  => 'Facebook',
        'tiktok'    => 'TikTok',
    ];

    private const ICONOS = [
        'youtube'   => 'fa-brands fa-youtube',
        'instagram' => 'fa-brands fa-instagram',
        'facebook'  => 'fa-brands fa-facebook-f',
        'tiktok'    => 'fa-brands fa-tiktok',
    ];

    /** ¿Es una plataforma que conocemos? */
    public static function valida(string $plataforma): bool
    {
        return isset(self::DOMINIOS[$plataforma]);
    }

    /** Nombre para mostrar. */
    public static function nombre(string $plataforma): string
    {
        return self::NOMBRES[$plataforma] ?? 'Video';
    }

    /** Clase del icono de Font Awesome. */
    public static function icono(string $plataforma): string
    {
        return self::ICONOS[$plataforma] ?? 'fa-solid fa-play';
    }

    /** Plataformas admitidas, para pintar ayudas en el panel. */
    public static function plataformas(): array
    {
        return array_keys(self::DOMINIOS);
    }

    /**
     * Reconoce un enlace pegado.
     *
     * @return array{ok: bool, plataforma?: string, recurso?: string, enlace?: string, error?: string}
     */
    public static function reconocer(string $enlace): array
    {
        $enlace = trim($enlace);
        if ($enlace === '' || mb_strlen($enlace) > 255) {
            return ['ok' => false, 'error' => 'Pega el enlace del video.'];
        }

        $partes = parse_url($enlace);
        if ($partes === false || empty($partes['host'])) {
            return ['ok' => false, 'error' => 'Ese enlace no se entiende. Cópialo otra vez desde la aplicación.'];
        }
        // Solo https: un enlace en claro acabaría bloqueado por el navegador
        // al incrustarlo dentro de una página segura.
        $esquema = strtolower($partes['scheme'] ?? '');
        if ($esquema !== 'https') {
            return ['ok' => false, 'error' => 'El enlace tiene que empezar por https://'];
        }

        $host = strtolower($partes['host']);
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }
        // Subdominios propios de cada red («vm.tiktok.com», «m.facebook.com»)
        // se reducen a su dominio para compararlos con la lista.
        $plataforma = '';
        foreach (self::DOMINIOS as $red => $dominios) {
            foreach ($dominios as $dominio) {
                if ($host === $dominio || str_ends_with($host, '.' . $dominio)) {
                    $plataforma = $red;
                    break 2;
                }
            }
        }
        if ($plataforma === '') {
            return ['ok' => false, 'error' => 'Solo admitimos enlaces de YouTube, Instagram, Facebook y TikTok.'];
        }

        $ruta = $partes['path'] ?? '';
        return match ($plataforma) {
            'youtube'   => self::youtube($enlace),
            'instagram' => self::instagram($ruta),
            'tiktok'    => self::tiktok($ruta),
            'facebook'  => self::facebook($enlace),
        };
    }

    /** YouTube: el identificador son 11 caracteres, venga como venga el enlace. */
    private static function youtube(string $enlace): array
    {
        if (!preg_match('#(?:youtu\.be/|v=|embed/|shorts/|live/)([A-Za-z0-9_-]{11})#', $enlace, $m)) {
            return ['ok' => false, 'error' => 'No encontramos el identificador del video de YouTube en ese enlace.'];
        }
        return ['ok' => true, 'plataforma' => 'youtube', 'recurso' => $m[1], 'enlace' => $enlace];
    }

    /** Instagram: publicaciones (/p/), reels (/reel/) y videos antiguos (/tv/). */
    private static function instagram(string $ruta): array
    {
        if (!preg_match('#^/(?:p|reel|reels|tv)/([A-Za-z0-9_-]{5,32})#', $ruta, $m)) {
            return ['ok' => false, 'error' => 'Usa el enlace de una publicación o un reel de Instagram (el que contiene /p/ o /reel/).'];
        }
        return ['ok' => true, 'plataforma' => 'instagram', 'recurso' => $m[1],
                'enlace' => 'https://www.instagram.com/reel/' . $m[1] . '/'];
    }

    /**
     * TikTok: hace falta el enlace largo, el que lleva `/video/<números>`.
     *
     * Los enlaces cortos de «compartir» no llevan el identificador dentro:
     * habría que pedírselo a TikTok desde el servidor, y eso es una llamada a
     * otro sitio en mitad de un guardado. Se prefiere pedir el enlace bueno.
     */
    private static function tiktok(string $ruta): array
    {
        if (!preg_match('#/video/(\d{6,25})#', $ruta, $m)) {
            return ['ok' => false, 'error' => 'De TikTok hace falta el enlace largo, el que incluye /video/ y una serie de números. Ábrelo en el navegador y copia la dirección de arriba.'];
        }
        // Se guarda la forma canónica, no la de incrustar: esta función vuelve
        // a leer el enlace guardado para rearmar el marco, y una dirección
        // `/embed/` ya no casaría con el patrón de arriba.
        return ['ok' => true, 'plataforma' => 'tiktok', 'recurso' => $m[1],
                'enlace' => 'https://www.tiktok.com/video/' . $m[1]];
    }

    /**
     * Facebook: su reproductor recibe el enlace entero como parámetro.
     *
     * Es la única de las cuatro que no se identifica por un código corto, así
     * que se guarda el enlace tal cual —ya comprobado que el dominio es de
     * Facebook— y se codifica al construir la dirección de incrustación.
     */
    private static function facebook(string $enlace): array
    {
        return ['ok' => true, 'plataforma' => 'facebook', 'recurso' => '', 'enlace' => $enlace];
    }

    /**
     * Dirección con la que se incrusta, construida aquí desde cero.
     *
     * Devuelve '' si la fila guardada no cuadra, y entonces quien pinta
     * simplemente no dibuja el marco: más vale un hueco que un `src` raro.
     */
    public static function urlIncrustada(string $plataforma, string $enlace): string
    {
        $dato = self::reconocer($enlace);
        if (!$dato['ok'] || $dato['plataforma'] !== $plataforma) {
            return '';
        }
        return match ($plataforma) {
            'youtube'   => 'https://www.youtube-nocookie.com/embed/' . $dato['recurso'],
            'instagram' => 'https://www.instagram.com/reel/' . $dato['recurso'] . '/embed',
            'tiktok'    => 'https://www.tiktok.com/embed/v2/' . $dato['recurso'],
            'facebook'  => 'https://www.facebook.com/plugins/video.php?show_text=false&href='
                           . rawurlencode($dato['enlace']),
            default     => '',
        };
    }

    /**
     * Proporción del marco, según cómo se graba en cada sitio.
     *
     * YouTube va apaisado y las redes verticales. Antes iban todas a la misma
     * proporción para que las filas cuadraran, pero eso dejaba franjas negras
     * arriba y abajo en los reels y a los lados en YouTube. Se pintan en dos
     * bloques separados —primero el canal, después las redes—, así que cada
     * uno puede llevar su forma y dentro de cada bloque las filas siguen
     * cuadrando.
     */
    public static function proporcion(string $plataforma): string
    {
        return $plataforma === 'youtube' ? '16 / 9' : '9 / 16';
    }

    /** ¿Va en el bloque de redes sociales, debajo del canal? */
    public static function esRed(string $plataforma): bool
    {
        return $plataforma !== 'youtube' && self::valida($plataforma);
    }
}
