<?php
/**
 * Estilos visuales de temporada.
 *
 * El apartado de temporadas ya decide *cuándo* hay campaña. Esto añade el
 * *cómo se ve*: cada temporada puede llevar asociado un estilo de una lista
 * cerrada, y el sitio adopta su color y sus animaciones mientras esté vigente.
 *
 * La lista es cerrada a propósito. El estilo se traduce en una clase CSS y en
 * unas formas SVG concretas; dejar que se escriba a mano un identificador
 * cualquiera solo produciría temporadas sin animación y sin forma de saber por
 * qué. Añadir un estilo nuevo es añadir una entrada aquí y un bloque en
 * `assets/css/temporada.css`.
 */

declare(strict_types=1);

final class Temporadas
{
    /**
     * Estilos disponibles.
     *
     * - `nombre`  lo que se lee en el panel
     * - `icono`   icono de Font Awesome para la lista del panel
     * - `color`   color sugerido; el que manda es el de la temporada
     * - `formas`  símbolos SVG que caen en la capa ambiental, en orden de peso
     * - `chispa`  símbolo del pequeño estallido al interactuar
     */
    private const ESTILOS = [
        'flores_amarillas' => [
            'nombre' => 'Flores amarillas',
            'icono'  => 'fa-sun',
            'color'  => '#F4C400',
            'formas' => ['petalo', 'girasol', 'hoja'],
            'chispa' => 'petalo',
        ],
        'san_valentin' => [
            'nombre' => 'San Valentín',
            'icono'  => 'fa-heart',
            'color'  => '#E04B6E',
            'formas' => ['corazon', 'petalo', 'destello'],
            'chispa' => 'corazon',
        ],
        'primavera' => [
            'nombre' => 'Primavera',
            'icono'  => 'fa-seedling',
            'color'  => '#7FBF6A',
            'formas' => ['flor', 'hoja', 'petalo'],
            'chispa' => 'flor',
        ],
        'dia_madres' => [
            'nombre' => 'Día de las Madres',
            'icono'  => 'fa-hand-holding-heart',
            'color'  => '#E39BB4',
            'formas' => ['petalo', 'corazon', 'destello'],
            'chispa' => 'corazon',
        ],
        'navidad' => [
            'nombre' => 'Navidad',
            'icono'  => 'fa-tree',
            'color'  => '#C0392B',
            'formas' => ['copo', 'estrella', 'destello'],
            'chispa' => 'estrella',
        ],
        'halloween' => [
            'nombre' => 'Halloween',
            'icono'  => 'fa-ghost',
            'color'  => '#E8720C',
            'formas' => ['calabaza', 'murcielago', 'destello'],
            'chispa' => 'calabaza',
        ],
        'ano_nuevo' => [
            'nombre' => 'Año Nuevo',
            'icono'  => 'fa-champagne-glasses',
            'color'  => '#C9A227',
            'formas' => ['confeti', 'estrella', 'destello'],
            'chispa' => 'confeti',
        ],
        'verano' => [
            'nombre' => 'Verano',
            'icono'  => 'fa-umbrella-beach',
            'color'  => '#2FA8C4',
            'formas' => ['sol', 'hoja', 'destello'],
            'chispa' => 'destello',
        ],
    ];

    /** @return array<string,array> Estilos para pintar el selector del panel. */
    public static function estilos(): array
    {
        return self::ESTILOS;
    }

    public static function estilo(?string $id): ?array
    {
        $id = trim((string)$id);
        return self::ESTILOS[$id] ?? null;
    }

    /** ¿Es un identificador de estilo que existe? Vacío también vale: «sin estilo». */
    public static function estiloValido(string $id): string
    {
        return isset(self::ESTILOS[$id]) ? $id : '';
    }

    // -----------------------------------------------------------------
    // Tema vigente
    // -----------------------------------------------------------------

    /**
     * Tema de la temporada vigente, o null.
     *
     * Devuelve el color —que es lo que hay que aplicar aunque la temporada no
     * tenga estilo ni productos— y el estilo si lo tiene.
     *
     * @return array{temporada:array, color:string, estilo:?array, estilo_id:string}|null
     */
    public static function tema(PDO $pdo): ?array
    {
        $temporada = Catalogo::temporadaVigente($pdo);
        if (!$temporada) {
            return null;
        }

        $estiloId = self::estiloValido((string)($temporada['estilo'] ?? ''));
        $color    = trim((string)($temporada['color_acento'] ?? ''));
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
            // Sin color propio se usa el del estilo, y si tampoco hay, el de la marca.
            $color = self::ESTILOS[$estiloId]['color']
                  ?? Ajustes::texto('color_primario', '#F8B0C2');
        }

        return [
            'temporada' => $temporada,
            'color'     => strtoupper($color),
            'estilo'    => self::estilo($estiloId),
            'estilo_id' => $estiloId,
        ];
    }

    // -----------------------------------------------------------------
    // Color
    // -----------------------------------------------------------------

    /** Descompone un color #RRGGBB en sus tres canales. */
    private static function canales(string $hex): array
    {
        $hex = ltrim(trim($hex), '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (!preg_match('/^[0-9a-f]{6}$/i', $hex)) {
            return [248, 176, 194]; // el rosa de la marca
        }
        return [
            (int)hexdec(substr($hex, 0, 2)),
            (int)hexdec(substr($hex, 2, 2)),
            (int)hexdec(substr($hex, 4, 2)),
        ];
    }

    /** Mezcla un color con blanco. $cantidad = 0 no cambia nada, 1 es blanco puro. */
    public static function aclarar(string $hex, float $cantidad): string
    {
        [$r, $g, $b] = self::canales($hex);
        $m = static fn(int $c): int => (int)round($c + (255 - $c) * max(0, min(1, $cantidad)));
        return sprintf('#%02X%02X%02X', $m($r), $m($g), $m($b));
    }

    /** Mezcla un color con negro. */
    public static function oscurecer(string $hex, float $cantidad): string
    {
        [$r, $g, $b] = self::canales($hex);
        $m = static fn(int $c): int => (int)round($c * (1 - max(0, min(1, $cantidad))));
        return sprintf('#%02X%02X%02X', $m($r), $m($g), $m($b));
    }

    /**
     * Texto legible sobre ese fondo.
     *
     * El color lo elige el negocio y puede ser un amarillo brillante o un vino
     * oscuro; con la luminancia relativa el texto se lee en los dos casos sin
     * tener que acordarse de cambiarlo.
     */
    public static function textoSobre(string $hex): string
    {
        [$r, $g, $b] = self::canales($hex);
        $canal = static function (int $v): float {
            $c = $v / 255;
            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };
        $luz = 0.2126 * $canal($r) + 0.7152 * $canal($g) + 0.0722 * $canal($b);
        return $luz > 0.45 ? '#2C2124' : '#FFFFFF';
    }

    /** El mismo color en componentes, para poder componer rgba() en el CSS. */
    public static function rgb(string $hex): string
    {
        return implode(' ', self::canales($hex));
    }

    /**
     * Variables CSS del tema, listas para el bloque :root de la cabecera.
     *
     * Se calculan en PHP y no con color-mix() para que el tema se vea igual en
     * cualquier navegador, incluidos los que llegan desde un teléfono viejo.
     */
    public static function variablesCss(array $tema): array
    {
        $c = $tema['color'];
        return [
            '--temporada-color'      => $c,
            '--temporada-rgb'        => self::rgb($c),
            '--temporada-claro'      => self::aclarar($c, 0.80),
            '--temporada-suave'      => self::aclarar($c, 0.62),
            '--temporada-medio'      => self::aclarar($c, 0.25),
            '--temporada-fuerte'     => self::oscurecer($c, 0.22),
            '--temporada-contraste'  => self::textoSobre($c),
        ];
    }

    // -----------------------------------------------------------------
    // Formas
    // -----------------------------------------------------------------

    /**
     * Dibujos de las partículas.
     *
     * Van en SVG y no en emoji ni en imágenes: se ven nítidos en cualquier
     * pantalla, pesan unos cientos de bytes, toman el color del tema con
     * `fill: currentColor` y no piden ni una petición más al servidor.
     */
    private const FORMAS = [
        'petalo'     => '<path d="M12 2C7 6 5 11 8 16c2 3 5 5 4 6 4-1 8-5 8-10 0-5-4-8-8-10z"/>',
        'girasol'    => '<ellipse cx="12" cy="5.6" rx="2.15" ry="4.3" transform="rotate(0 12 12)"/>'
                      . '<ellipse cx="12" cy="5.6" rx="2.15" ry="4.3" transform="rotate(45 12 12)"/>'
                      . '<ellipse cx="12" cy="5.6" rx="2.15" ry="4.3" transform="rotate(90 12 12)"/>'
                      . '<ellipse cx="12" cy="5.6" rx="2.15" ry="4.3" transform="rotate(135 12 12)"/>'
                      . '<ellipse cx="12" cy="5.6" rx="2.15" ry="4.3" transform="rotate(180 12 12)"/>'
                      . '<ellipse cx="12" cy="5.6" rx="2.15" ry="4.3" transform="rotate(225 12 12)"/>'
                      . '<ellipse cx="12" cy="5.6" rx="2.15" ry="4.3" transform="rotate(270 12 12)"/>'
                      . '<ellipse cx="12" cy="5.6" rx="2.15" ry="4.3" transform="rotate(315 12 12)"/>'
                      . '<circle cx="12" cy="12" r="3.9"/>',
        'hoja'       => '<path d="M4 20c0-8 5-14 16-16 1 10-4 16-12 16-1 0-2 1-3 2z"/>',
        'flor'       => '<ellipse cx="12" cy="6.4" rx="3.1" ry="4.4" transform="rotate(0 12 12)"/>'
                      . '<ellipse cx="12" cy="6.4" rx="3.1" ry="4.4" transform="rotate(72 12 12)"/>'
                      . '<ellipse cx="12" cy="6.4" rx="3.1" ry="4.4" transform="rotate(144 12 12)"/>'
                      . '<ellipse cx="12" cy="6.4" rx="3.1" ry="4.4" transform="rotate(216 12 12)"/>'
                      . '<ellipse cx="12" cy="6.4" rx="3.1" ry="4.4" transform="rotate(288 12 12)"/>'
                      . '<circle cx="12" cy="12" r="2.5"/>',
        'corazon'    => '<path d="M12 21s-8-5-8-10.5C4 7 6.5 5 9 5c1.7 0 2.6.9 3 1.5C12.4 5.9 13.3 5 15 5c2.5 0 5 2 5 5.5C20 16 12 21 12 21z"/>',
        'destello'   => '<path d="M12 0l2.1 8.2L22 12l-7.9 3.8L12 24l-2.1-8.2L2 12l7.9-3.8z"/>',
        'copo'       => '<path d="M11 0h2v7.3l2.6-2.6 1.4 1.4L13 10.2v1.1l3.9-2.3 1-2.7 1.9.7-.9 2.4 2.5-.9.7 1.9-2.7 1L23 13l-3.9 2.3 2.7 1-.7 1.9-2.5-.9.9 2.4-1.9.7-1-2.7L13 15.7v1.1l4 4.1-1.4 1.4L13 19.7V24h-2v-4.3l-2.6 2.6L7 20.9l4-4.1v-1.1L7.1 18l-1 2.7-1.9-.7.9-2.4-2.5.9-.7-1.9 2.7-1L1 13l3.9-2.3-2.7-1 .7-1.9 2.5.9-.9-2.4 1.9-.7 1 2.7L11 11.3v-1.1L7 6.1 8.4 4.7 11 7.3z"/>',
        'estrella'   => '<path d="M12 1.5l3.1 6.6 7.2.9-5.3 5 1.4 7.1L12 17.6 5.6 21.1 7 14 1.7 9l7.2-.9z"/>',
        'calabaza'   => '<path d="M12 5c.4-2 1.6-3.4 3.4-3.6l.3 1.9C14.6 3.5 14 4.2 13.7 5.1c3.6.4 6.3 3.7 6.3 7.9 0 4.4-3 8-8 8s-8-3.6-8-8c0-4.3 2.8-7.6 6.5-7.9.5 0 1 0 1.5.0z"/>',
        'murcielago' => '<path d="M12 7.4c.9 0 1.6.8 1.6 1.7v6.4c0 .9-.7 1.7-1.6 1.7s-1.6-.8-1.6-1.7V9.1c0-.9.7-1.7 1.6-1.7z"/>'
                      . '<path d="m10.3 6.9-.9-3.1 2.4 2.6zm3.4 0 .9-3.1-2.4 2.6z"/>'
                      . '<path d="M10.6 9.7C9.2 7.5 6.7 6.1 3.6 6.1c.6 1 .8 2.1.5 3.1-1-.8-2.3-1.1-3.6-.8 1.7 1 2.9 2.7 3.2 4.7.2 1.4 1.4 2.4 2.8 2.4 1 0 1.9-.5 2.4-1.3.4.7 1.1 1.2 1.9 1.3z"/>'
                      . '<path d="M13.4 9.7c1.4-2.2 3.9-3.6 7-3.6-.6 1-.8 2.1-.5 3.1 1-.8 2.3-1.1 3.6-.8-1.7 1-2.9 2.7-3.2 4.7-.2 1.4-1.4 2.4-2.8 2.4-1 0-1.9-.5-2.4-1.3-.4.7-1.1 1.2-1.9 1.3z"/>',
        'confeti'    => '<rect x="8" y="1" width="8" height="22" rx="4"/>',
        'sol'        => '<path d="M12 7a5 5 0 100 10 5 5 0 000-10zm0-7h0l1.5 3.5h-3zM12 24l-1.5-3.5h3zM0 12l3.5-1.5v3zm24 0l-3.5 1.5v-3zM3.5 3.5l3.6 1.4-2.2 2.2zm17 17l-3.6-1.4 2.2-2.2zm0-17l-1.4 3.6-2.2-2.2zm-17 17l1.4-3.6 2.2 2.2z"/>',
    ];

    /**
     * Sprite SVG con las formas que usa un estilo, y nada más.
     *
     * Solo se manda lo que se va a pintar: un estilo usa tres o cuatro formas,
     * no las doce.
     */
    public static function sprite(array $estilo): string
    {
        $ids = array_unique(array_merge($estilo['formas'], [$estilo['chispa']]));
        $out = '';
        foreach ($ids as $id) {
            if (isset(self::FORMAS[$id])) {
                $out .= '<symbol id="tf-' . $id . '" viewBox="0 0 24 24" fill="currentColor">'
                      . self::FORMAS[$id] . '</symbol>';
            }
        }
        return $out;
    }
}
