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
            'formas' => ['girasol', 'petalo', 'girasol_tallo', 'hoja', 'destello'],
            'chispa' => 'petalo',
        ],
        'san_valentin' => [
            'nombre' => 'San Valentín',
            'icono'  => 'fa-heart',
            'color'  => '#E04B6E',
            'formas' => ['corazon', 'petalo', 'corazon_globo', 'destello', 'regalo'],
            'chispa' => 'corazon',
        ],
        'primavera' => [
            'nombre' => 'Primavera',
            'icono'  => 'fa-seedling',
            'color'  => '#F09BBE',
            'formas' => ['flor_cerezo', 'petalo', 'mariposa', 'rama_flor', 'destello'],
            'chispa' => 'flor_cerezo',
        ],
        'dia_madres' => [
            'nombre' => 'Día de las Madres',
            'icono'  => 'fa-hand-holding-heart',
            'color'  => '#D6425A',
            'formas' => ['rosa', 'petalo', 'corazon', 'ramo', 'destello'],
            'chispa' => 'rosa',
        ],
        'navidad' => [
            'nombre' => 'Navidad',
            'icono'  => 'fa-tree',
            'color'  => '#C0392B',
            'formas' => ['copo', 'estrella', 'bola', 'arbol', 'acebo'],
            'chispa' => 'estrella',
        ],
        'halloween' => [
            'nombre' => 'Halloween',
            'icono'  => 'fa-ghost',
            'color'  => '#E8720C',
            'formas' => ['calabaza', 'murcielago', 'hoja_otono', 'telarana', 'farol'],
            'chispa' => 'calabaza',
        ],
        'ano_nuevo' => [
            'nombre' => 'Año Nuevo',
            'icono'  => 'fa-champagne-glasses',
            'color'  => '#C9A227',
            'formas' => ['fuego', 'confeti', 'serpentina', 'estrella', 'globo'],
            'chispa' => 'confeti',
        ],
        'verano' => [
            'nombre' => 'Verano',
            'icono'  => 'fa-umbrella-beach',
            'color'  => '#2FA8C4',
            'formas' => ['palmera', 'estrella_mar', 'concha', 'ola', 'pelota_playa', 'gafas', 'flor_tropical'],
            'chispa' => 'estrella_mar',
        ],
        'playa' => [
            'nombre' => 'Playa',
            'icono'  => 'fa-water',
            'color'  => '#3FA9D4',
            'formas' => ['palmera', 'tabla_surf', 'silla_playa', 'sombrilla', 'coco', 'chancleta', 'concha'],
            'chispa' => 'concha',
        ],
        'deportes' => [
            'nombre' => 'Deportes',
            'icono'  => 'fa-medal',
            'color'  => '#D9662B',
            'formas' => ['balon_futbol', 'balon_basket', 'pelota_tenis', 'balon_voley'],
            'chispa' => 'pelota_tenis',
        ],
        'futbol' => [
            'nombre' => 'Fútbol',
            'icono'  => 'fa-futbol',
            'color'  => '#2F8B4F',
            'formas' => ['balon_futbol', 'porteria', 'silbato', 'bota', 'confeti'],
            'chispa' => 'balon_futbol',
        ],
        'baloncesto' => [
            'nombre' => 'Baloncesto',
            'icono'  => 'fa-basketball',
            'color'  => '#E07A2B',
            'formas' => ['balon_basket', 'canasta', 'confeti', 'destello'],
            'chispa' => 'balon_basket',
        ],
        'noche_luces' => [
            'nombre' => 'Noche de luces',
            'icono'  => 'fa-wand-magic-sparkles',
            'color'  => '#C9A227',
            'formas' => ['luces', 'destello', 'burbuja', 'nube', 'mariposa'],
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
     * Variante del color de temporada que se lee sobre el fondo claro del
     * sitio.
     *
     * El color lo elige la floristería desde el panel y puede ser cualquiera:
     * un amarillo de girasol, un celeste de verano. Oscurecerlo un porcentaje
     * fijo dejaba textos en 2,3:1 —ilegibles a pleno sol y suspenso en
     * cualquier revisión de accesibilidad—. Aquí se oscurece paso a paso hasta
     * alcanzar el 4.5:1 que pide la norma, conservando el tono: sigue siendo
     * el amarillo de la campaña, solo que uno que se puede leer.
     *
     * La referencia es el rosa suave —el más oscuro de los fondos claros del
     * sitio— y no el blanco: si se lee sobre ese, se lee sobre todos los
     * demás, que son más claros todavía.
     */
    public static function legibleSobreClaro(string $hex, string $fondo = '#FDE8EC'): string
    {
        $luz = static function (string $color): float {
            [$r, $g, $b] = self::canales($color);
            $canal = static function (int $v): float {
                $c = $v / 255;
                return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
            };
            return 0.2126 * $canal($r) + 0.7152 * $canal($g) + 0.0722 * $canal($b);
        };
        $razon = static function (float $a, float $b): float {
            return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
        };

        $luzFondo = $luz($fondo);
        $actual   = $hex;

        // Veinte pasos del 8 % llegan al negro de sobra; el bucle para en
        // cuanto pasa el umbral, así que casi siempre son dos o tres.
        for ($i = 0; $i < 20; $i++) {
            if ($razon($luz($actual), $luzFondo) >= 4.5) {
                return $actual;
            }
            $actual = self::oscurecer($actual, 0.08);
        }
        return '#2C2124';   // por si acaso: la tinta del sitio siempre se lee
    }

    /**
     * Texto legible sobre ese fondo.    /**
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
            // Este es el único que se usa como TEXTO sobre fondo claro, así que
            // no basta con oscurecer un porcentaje fijo: un amarillo de
            // campaña un 22 % más oscuro sigue siendo ilegible. Se oscurece
            // hasta que de verdad se lee.
            '--temporada-fuerte'     => self::legibleSobreClaro($c),
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
    /**
     * Dibujos de las partículas.
     *
     * Van en SVG y no en imágenes: se ven nítidos en cualquier pantalla, pesan
     * unos cientos de bytes cada uno y no piden ni una petición más al
     * servidor. Los que llevan color propio lo traen escrito —un girasol tiene
     * que ser amarillo aunque la campaña sea azul—; los genéricos (pétalos,
     * destellos, confeti) usan `currentColor` y se tiñen con el color de la
     * temporada, que es lo que mantiene el tema unido.
     *
     * Cada forma declara también cómo se mueve. `caer` es lo normal, dando
     * volteretas como una hoja; `derivar` también cae pero erguida, porque una
     * sombrilla o un farol boca abajo se leen como una mancha; `flotar` sube
     * (globos, burbujas), `girar` da vueltas sobre sí misma, `destellar` titila
     * sin desplazarse y `rebotar` bota como una pelota.
     */
    private const FORMAS = [
        // --- Genéricas: se tiñen con el color de la temporada ---------------
        'petalo'      => ['caer',      '<path fill="currentColor" d="M12 2C7 6 5 11 8 16c2 3 5 5 4 6 4-1 8-5 8-10 0-5-4-8-8-10z"/>'],
        'destello'    => ['destellar', '<path fill="currentColor" d="M12 0l2.1 8.2L22 12l-7.9 3.8L12 24l-2.1-8.2L2 12l7.9-3.8z"/>'],
        'confeti'     => ['caer',      '<rect fill="currentColor" x="9" y="2" width="6" height="20" rx="3"/>'],
        'burbuja'     => ['flotar',    '<circle cx="12" cy="12" r="9" fill="currentColor" opacity=".22"/><circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="1.3" opacity=".65"/><ellipse cx="8.6" cy="8.2" rx="2.4" ry="1.7" fill="#fff" opacity=".8" transform="rotate(-35 8.6 8.2)"/>'],

        // --- Flores amarillas ----------------------------------------------
        'girasol'     => ['girar', '<g fill="#F2C230"><ellipse cx="12" cy="6" rx="2.5" ry="4.6" transform="rotate(0 12 12)"/><ellipse cx="12" cy="6" rx="2.5" ry="4.6" transform="rotate(30 12 12)"/><ellipse cx="12" cy="6" rx="2.5" ry="4.6" transform="rotate(60 12 12)"/><ellipse cx="12" cy="6" rx="2.5" ry="4.6" transform="rotate(90 12 12)"/><ellipse cx="12" cy="6" rx="2.5" ry="4.6" transform="rotate(120 12 12)"/><ellipse cx="12" cy="6" rx="2.5" ry="4.6" transform="rotate(150 12 12)"/><ellipse cx="12" cy="6" rx="2.5" ry="4.6" transform="rotate(180 12 12)"/><ellipse cx="12" cy="6" rx="2.5" ry="4.6" transform="rotate(210 12 12)"/><ellipse cx="12" cy="6" rx="2.5" ry="4.6" transform="rotate(240 12 12)"/><ellipse cx="12" cy="6" rx="2.5" ry="4.6" transform="rotate(270 12 12)"/><ellipse cx="12" cy="6" rx="2.5" ry="4.6" transform="rotate(300 12 12)"/><ellipse cx="12" cy="6" rx="2.5" ry="4.6" transform="rotate(330 12 12)"/></g><circle cx="12" cy="12" r="3.6" fill="#8A5A22"/><circle cx="12" cy="12" r="2.1" fill="#6B4114"/>'],
        'girasol_tallo' => ['derivar', '<path d="M11.4 13h1.2v10h-1.2z" fill="#4E8F3C"/><path d="M11.5 18c-2.4-.3-3.9-1.6-4.4-3.6 2.2-.3 3.8.7 4.4 3.6z" fill="#5AA347"/><path d="M12.5 20.5c2.2-.4 3.5-1.7 3.9-3.6-2-.2-3.4.9-3.9 3.6z" fill="#4E8F3C"/><g fill="#F2C230"><ellipse cx="12" cy="4.6" rx="1.9" ry="3.6" transform="rotate(0 12 9.4)"/><ellipse cx="12" cy="4.6" rx="1.9" ry="3.6" transform="rotate(36 12 9.4)"/><ellipse cx="12" cy="4.6" rx="1.9" ry="3.6" transform="rotate(72 12 9.4)"/><ellipse cx="12" cy="4.6" rx="1.9" ry="3.6" transform="rotate(108 12 9.4)"/><ellipse cx="12" cy="4.6" rx="1.9" ry="3.6" transform="rotate(144 12 9.4)"/><ellipse cx="12" cy="4.6" rx="1.9" ry="3.6" transform="rotate(180 12 9.4)"/><ellipse cx="12" cy="4.6" rx="1.9" ry="3.6" transform="rotate(216 12 9.4)"/><ellipse cx="12" cy="4.6" rx="1.9" ry="3.6" transform="rotate(252 12 9.4)"/><ellipse cx="12" cy="4.6" rx="1.9" ry="3.6" transform="rotate(288 12 9.4)"/><ellipse cx="12" cy="4.6" rx="1.9" ry="3.6" transform="rotate(324 12 9.4)"/></g><circle cx="12" cy="9.4" r="2.9" fill="#8A5A22"/>'],
        'hoja'        => ['caer', '<path fill="#5AA347" d="M4 20c0-8 5-14 16-16 1 10-4 16-12 16-1 0-2 1-3 2z"/><path fill="#3F7A31" d="M5.6 19.2C7 13 11 8.6 17.6 6.1 13 9.6 9.3 13.9 6.9 19z"/>'],

        // --- San Valentín ---------------------------------------------------
        'corazon'     => ['caer', '<path fill="#D8232A" d="M12 21.5s-8.4-5.3-8.4-11C3.6 7 6.2 4.9 8.8 4.9c1.8 0 2.7 1 3.2 1.6.5-.6 1.4-1.6 3.2-1.6 2.6 0 5.2 2.1 5.2 5.6 0 5.7-8.4 11-8.4 11z"/><ellipse cx="8.6" cy="9.4" rx="1.9" ry="1.3" fill="#fff" opacity=".42" transform="rotate(-35 8.6 9.4)"/>'],
        'corazon_globo' => ['flotar', '<path fill="#E0242E" d="M12 15.6s-6.6-4.2-6.6-8.6C5.4 4.3 7.4 2.6 9.5 2.6c1.4 0 2.1.8 2.5 1.3.4-.5 1.1-1.3 2.5-1.3 2.1 0 4.1 1.7 4.1 4.4 0 4.4-6.6 8.6-6.6 8.6z"/><ellipse cx="9.4" cy="6.4" rx="1.5" ry="1" fill="#fff" opacity=".45" transform="rotate(-35 9.4 6.4)"/><path d="M12 15.4c.6 2.4-1 3.3-.4 5.1.4 1.2 1.5 1.6 1.9 3.1" fill="none" stroke="#C9A227" stroke-width="1" stroke-linecap="round"/>'],
        'regalo'      => ['derivar', '<rect x="3.4" y="9.4" width="17.2" height="12" rx="1.4" fill="#F3E3D3"/><rect x="2.4" y="6.4" width="19.2" height="4" rx="1.2" fill="#F8EFE5"/><rect x="10.4" y="6.4" width="3.2" height="15" fill="#D8232A"/><path fill="#D8232A" d="M12 6.6C10.6 3.9 8.9 2.6 7.4 3.2 6 3.8 6.2 5.7 8 6.4zM12 6.6c1.4-2.7 3.1-4 4.6-3.4 1.4.6 1.2 2.5-.6 3.2z"/>'],

        // --- Navidad ---------------------------------------------------------
        'arbol'       => ['derivar', '<path d="M10.9 19.4h2.2v3.6h-2.2z" fill="#7A4A17"/><path fill="#2F7A45" d="M12 2.6 16.6 9H7.4z"/><path fill="#358950" d="M12 7 17.6 14.3H6.4z"/><path fill="#2F7A45" d="M12 11.6 19 20H5z"/><circle cx="9.6" cy="12.6" r=".95" fill="#E0242E"/><circle cx="14.4" cy="16.4" r=".95" fill="#E8B923"/><circle cx="10.4" cy="17.6" r=".95" fill="#4FA3D1"/><path fill="#E8B923" d="m12 .6.9 1.9 2.1.3-1.5 1.5.35 2.1L12 5.4l-1.85 1-.0-2.1L8.9 2.8l2.2-.3z"/>'],
        'estrella'    => ['destellar', '<path fill="#E8B923" d="M12 1.5l3.1 6.6 7.2.9-5.3 5 1.4 7.1L12 17.6 5.6 21.1 7 14 1.7 9l7.2-.9z"/><path fill="#F6DE7C" d="M12 4.6l2 4.3 4.7.6-3.5 3.3.9 4.6L12 15.2z"/>'],
        'bola'        => ['derivar', '<path d="M11.2 2h1.6v3h-1.6z" fill="#B9A05E"/><rect x="9.9" y="3.2" width="4.2" height="2.6" rx=".7" fill="#E8B923"/><circle cx="12" cy="14.2" r="8" fill="#D8232A"/><path d="M4.4 12.4a8 8 0 0 1 15.2 0z" fill="#E8474E" opacity=".55"/><ellipse cx="8.9" cy="10.6" rx="2.1" ry="1.5" fill="#fff" opacity=".38" transform="rotate(-35 8.9 10.6)"/>'],
        'copo'        => ['caer', '<path fill="#EAF6FF" d="M11 0h2v7.3l2.6-2.6 1.4 1.4L13 10.2v1.1l3.9-2.3 1-2.7 1.9.7-.9 2.4 2.5-.9.7 1.9-2.7 1L23 13l-3.9 2.3 2.7 1-.7 1.9-2.5-.9.9 2.4-1.9.7-1-2.7L13 15.7v1.1l4 4.1-1.4 1.4L13 19.7V24h-2v-4.3l-2.6 2.6L7 20.9l4-4.1v-1.1L7.1 18l-1 2.7-1.9-.7.9-2.4-2.5.9-.7-1.9 2.7-1L1 13l3.9-2.3-2.7-1 .7-1.9 2.5.9-.9-2.4 1.9-.7 1 2.7L11 11.3v-1.1L7 6.1 8.4 4.7 11 7.3z"/>'],
        'acebo'       => ['caer', '<path fill="#2F7A45" d="M12 12.4c-2-1.2-3-3-2.4-5 1.2.4 2 1.2 2.4 2.4.4-1.2 1.2-2 2.4-2.4.6 2-.4 3.8-2.4 5z"/><path fill="#358950" d="M11.6 12.6c-2.2.6-4.2 0-5.2-1.7 1.2-.7 2.4-.7 3.6-.2-1-.8-1.4-1.9-1.2-3.2 1.9.6 2.9 2.2 2.8 5.1zM12.4 12.6c2.2.6 4.2 0 5.2-1.7-1.2-.7-2.4-.7-3.6-.2 1-.8 1.4-1.9 1.2-3.2-1.9.6-2.9 2.2-2.8 5.1z"/><circle cx="10.4" cy="15.2" r="2.1" fill="#D8232A"/><circle cx="13.8" cy="16.4" r="1.9" fill="#E0242E"/><circle cx="12.1" cy="18.4" r="1.7" fill="#C01E26"/>'],

        // --- Halloween -------------------------------------------------------
        'calabaza'    => ['caer', '<path d="M11.4 5.6h1.4V3.2q0-1 1.6-1.2v1.2q-.8.1-.8.8v1.6z" fill="#4E8F3C"/><ellipse cx="12" cy="14" rx="9" ry="8" fill="#E8720C"/><ellipse cx="7.4" cy="14" rx="3.4" ry="7.6" fill="#F2851E" opacity=".55"/><ellipse cx="16.6" cy="14" rx="3.4" ry="7.6" fill="#F2851E" opacity=".55"/><path fill="#3A1F08" d="m8.2 11.4 2.6 1.7-2.6 1.7zM15.8 11.4l-2.6 1.7 2.6 1.7zM7.6 16.6h8.8q-1.1 2.4-4.4 2.4t-4.4-2.4z"/><path fill="#FFD08A" d="m8.6 12.2 1.4.9-1.4.9zM15.4 12.2l-1.4.9 1.4.9z" opacity=".5"/>'],
        'murcielago'  => ['caer', '<g fill="#2B2130"><path d="M12 7.4c.9 0 1.6.8 1.6 1.7v6.4c0 .9-.7 1.7-1.6 1.7s-1.6-.8-1.6-1.7V9.1c0-.9.7-1.7 1.6-1.7z"/><path d="m10.3 6.9-.9-3.1 2.4 2.6zm3.4 0 .9-3.1-2.4 2.6z"/><path d="M10.6 9.7C9.2 7.5 6.7 6.1 3.6 6.1c.6 1 .8 2.1.5 3.1-1-.8-2.3-1.1-3.6-.8 1.7 1 2.9 2.7 3.2 4.7.2 1.4 1.4 2.4 2.8 2.4 1 0 1.9-.5 2.4-1.3.4.7 1.1 1.2 1.9 1.3z"/><path d="M13.4 9.7c1.4-2.2 3.9-3.6 7-3.6-.6 1-.8 2.1-.5 3.1 1-.8 2.3-1.1 3.6-.8-1.7 1-2.9 2.7-3.2 4.7-.2 1.4-1.4 2.4-2.8 2.4-1 0-1.9-.5-2.4-1.3-.4.7-1.1 1.2-1.9 1.3z"/></g><circle cx="11.1" cy="10.2" r=".55" fill="#E8720C"/><circle cx="12.9" cy="10.2" r=".55" fill="#E8720C"/>'],
        'telarana'    => ['girar', '<g fill="none" stroke="#8E8794" stroke-width="1.05" stroke-linecap="round"><path d="M12 12V1M12 12l7.8-7.8M12 12h11M12 12l7.8 7.8M12 12v11M12 12l-7.8 7.8M12 12H1M12 12 4.2 4.2"/><path d="M12 5.4 16.7 7.3 18.6 12 16.7 16.7 12 18.6 7.3 16.7 5.4 12 7.3 7.3z"/><path d="M12 8.8 14.3 9.7 15.2 12 14.3 14.3 12 15.2 9.7 14.3 8.8 12 9.7 9.7z"/></g>'],
        'hoja_otono'  => ['caer', '<path fill="#C1651C" d="M12 22v-7"/><path fill="#C1651C" d="M12 2c2.4 2 3.6 4 3.6 6l2.8-1.4-1 3.4 3.2.4-2.8 2.4 2 2.2-3.6.6.4 3-3.2-1.6L12 21l-1.4-3-3.2 1.6.4-3-3.6-.6 2-2.2L3.4 11l3.2-.4-1-3.4L8.4 8c0-2 1.2-4 3.6-6z"/><path fill="#E0821F" d="M12 4.6c1.5 1.5 2.2 3 2.2 4.6l1.8-.9-.6 2.2 2 .3-1.8 1.5 1.3 1.4-2.3.4.3 1.9-2-1L12 17l-.9-2-2 1 .3-1.9-2.3-.4 1.3-1.4-1.8-1.5 2-.3-.6-2.2 1.8.9c0-1.6.7-3.1 2.2-4.6z" opacity=".55"/>'],
        'farol'       => ['derivar', '<path d="M9.4 2.6h5.2v1.4H9.4z" fill="#6B5B3E"/><path d="M11.6 1h.8v1.6h-.8z" fill="#6B5B3E"/><path d="M8.4 4h7.2l1 3.2v12.2h-9.2V7.2z" fill="#3E3529"/><rect x="9.6" y="6.4" width="4.8" height="11" rx=".6" fill="#F6C86A"/><rect x="9.6" y="6.4" width="4.8" height="11" rx=".6" fill="none" stroke="#6B5B3E" stroke-width=".8"/><path fill="#F09A2B" d="M12 9.2c1.4 1.5 2 2.7 2 3.8a2 2 0 1 1-4 0c0-1.1.6-2.3 2-3.8z"/><path d="M7 19.4h10v1.6H7z" fill="#3E3529"/>'],

        // --- Primavera --------------------------------------------------------
        'flor_cerezo' => ['caer', '<g fill="#F7B6CB"><ellipse cx="12" cy="6.4" rx="3" ry="4.2"/><ellipse cx="12" cy="6.4" rx="3" ry="4.2" transform="rotate(72 12 12)"/><ellipse cx="12" cy="6.4" rx="3" ry="4.2" transform="rotate(144 12 12)"/><ellipse cx="12" cy="6.4" rx="3" ry="4.2" transform="rotate(216 12 12)"/><ellipse cx="12" cy="6.4" rx="3" ry="4.2" transform="rotate(288 12 12)"/></g><g fill="#F293B4"><path d="M12 3.4c.5.9.8 1.9.9 3l-1.8.0c.1-1.1.4-2.1.9-3z"/></g><circle cx="12" cy="12" r="2.3" fill="#FBE3A8"/><g fill="#E8A33D"><circle cx="12" cy="9.5" r=".55"/><circle cx="14.4" cy="11.3" r=".55"/><circle cx="13.5" cy="14.1" r=".55"/><circle cx="10.5" cy="14.1" r=".55"/><circle cx="9.6" cy="11.3" r=".55"/></g>'],
        'mariposa'    => ['flotar', '<path fill="#D96FC4" d="M11.4 11.6C9.6 7.8 6.6 5.6 3.4 6.2c-1.4.3-2 1.6-1.6 3 .5 1.6 2.2 2.4 4.2 2.6-1.8.6-3 1.8-3 3.3 0 1.5 1.3 2.4 2.8 2.1 2.4-.5 4.4-2.4 5.6-5.6z"/><path fill="#C24FB0" d="M12.6 11.6c1.8-3.8 4.8-6 8-5.4 1.4.3 2 1.6 1.6 3-.5 1.6-2.2 2.4-4.2 2.6 1.8.6 3 1.8 3 3.3 0 1.5-1.3 2.4-2.8 2.1-2.4-.5-4.4-2.4-5.6-5.6z"/><path fill="#F2A7DE" d="M10.6 9.2c-1.4-1.6-3.1-2.4-5-2.2 1.4 1.5 3.1 2.4 5 2.2z" opacity=".7"/><rect x="11.4" y="6.6" width="1.2" height="11" rx=".6" fill="#3E3243"/><path d="M11.6 6.8 9.9 4.2M12.4 6.8l1.7-2.6" stroke="#3E3243" stroke-width=".9" stroke-linecap="round" fill="none"/>'],
        'rama_flor'   => ['derivar', '<path d="M4 21C7.5 15 12 9.5 20 4" stroke="#7C5A3A" stroke-width="1.4" fill="none" stroke-linecap="round"/><g fill="#F7B6CB"><circle cx="17.6" cy="6.2" r="2.4"/><circle cx="13.2" cy="10.4" r="2.1"/><circle cx="9.2" cy="15.2" r="1.9"/></g><g fill="#FBE3A8"><circle cx="17.6" cy="6.2" r=".9"/><circle cx="13.2" cy="10.4" r=".8"/><circle cx="9.2" cy="15.2" r=".7"/></g>'],

        // --- Día de las Madres -------------------------------------------------
        'rosa'        => ['girar', '<circle cx="12" cy="10.6" r="7.4" fill="#C8102E"/><path fill="#E0384A" d="M12 4.4c3 0 5.4 2.4 5.4 5.4S15 15.2 12 15.2 6.6 12.8 6.6 9.8 9 4.4 12 4.4z"/><path fill="#C8102E" d="M12 6.6c1.8 0 3.2 1.4 3.2 3.2S13.8 13 12 13s-3.2-1.4-3.2-3.2S10.2 6.6 12 6.6z"/><path fill="#E0384A" d="M12 8.4c.9 0 1.6.7 1.6 1.6S12.9 11.6 12 11.6s-1.6-.7-1.6-1.6.7-1.6 1.6-1.6z"/><path d="M11.4 17h1.2v6h-1.2z" fill="#4E8F3C"/><path fill="#5AA347" d="M11.5 21c-2-.3-3.3-1.4-3.7-3.1 1.9-.3 3.2.6 3.7 3.1z"/>'],
        'ramo'        => ['derivar', '<path d="M9.6 14h4.8l1.6 8H8z" fill="#E8C9A0"/><path d="M9.6 14h4.8l.4 2H9.2z" fill="#D8AE83"/><g fill="#4E8F3C"><path d="M12 13.4C9.6 12.6 8 11 7.6 8.8c2.2 0 3.8 1.4 4.4 4.6zM12 13.4c2.4-.8 4-2.4 4.4-4.6-2.2 0-3.8 1.4-4.4 4.6z"/></g><circle cx="8.4" cy="7.4" r="2.7" fill="#C8102E"/><circle cx="15.6" cy="7.4" r="2.7" fill="#E0384A"/><circle cx="12" cy="4.8" r="2.9" fill="#F0899E"/><circle cx="12" cy="9.4" r="2.5" fill="#D6425A"/><circle cx="8.4" cy="7.4" r="1" fill="#9E0C24"/><circle cx="15.6" cy="7.4" r="1" fill="#B01B32"/><circle cx="12" cy="4.8" r="1.1" fill="#C8506A"/>'],

        // --- Año Nuevo ----------------------------------------------------------
        'fuego'       => ['destellar', '<g stroke="#E8B923" stroke-width="1.2" stroke-linecap="round" fill="none"><path d="M12 12V3M12 12v9M12 12H3M12 12h9M12 12 5.6 5.6M12 12l6.4-6.4M12 12l-6.4 6.4M12 12l6.4 6.4"/></g><g fill="#F6DE7C"><circle cx="12" cy="2.4" r="1.1"/><circle cx="12" cy="21.6" r="1.1"/><circle cx="2.4" cy="12" r="1.1"/><circle cx="21.6" cy="12" r="1.1"/><circle cx="5" cy="5" r="1"/><circle cx="19" cy="5" r="1"/><circle cx="5" cy="19" r="1"/><circle cx="19" cy="19" r="1"/></g><circle cx="12" cy="12" r="2" fill="#FFF3C4"/>'],
        'serpentina'  => ['derivar', '<path d="M8 1c4 2.4 4 4.8 0 7.2s-4 4.8 0 7.2 4 4.8 0 7.2" stroke="#E8B923" stroke-width="2.2" fill="none" stroke-linecap="round"/><path d="M16 2c3 2 3 4 0 6s-3 4 0 6 3 4 0 6" stroke="#D96FC4" stroke-width="1.7" fill="none" stroke-linecap="round" opacity=".8"/>'],
        'globo'       => ['flotar', '<ellipse cx="12" cy="8.6" rx="6.2" ry="7.4" fill="#E8B923"/><ellipse cx="9.4" cy="5.8" rx="1.8" ry="2.6" fill="#fff" opacity=".38" transform="rotate(-20 9.4 5.8)"/><path d="m10.6 15.8 1.4 1.6 1.4-1.6z" fill="#C9A227"/><path d="M12 17.4c.7 2.2-1 3-.4 4.6.3 1 1.3 1.4 1.7 2.6" stroke="#C9A227" stroke-width=".9" fill="none" stroke-linecap="round"/>'],

        // --- Verano y playa -------------------------------------------------------
        'palmera'     => ['derivar', '<path d="M11.6 10c.6 4.4.4 8.4-.6 12h2.4c-.4-4-.2-8 .6-12z" fill="#8A5A2B"/><g fill="#2F8B4F"><path d="M12 8.4C9.4 5.6 6 4.6 2.2 5.6c2.6-2 6.6-1.6 9.8 2.8z"/><path d="M12 8.4c2.6-2.8 6-3.8 9.8-2.8-2.6-2-6.6-1.6-9.8 2.8z"/><path d="M12 8.4C10.4 5 10.6 2.4 12.8 0c1 3-.2 5.8-.8 8.4z"/><path d="M12 8.4c-2.6-.6-4.6.4-6 2.8 2.6-.6 4.6-1.6 6-2.8z"/><path d="M12 8.4c2.6-.6 4.6.4 6 2.8-2.6-.6-4.6-1.6-6-2.8z"/></g><circle cx="12" cy="8.4" r="1.5" fill="#8A5A2B"/>'],
        'estrella_mar'=> ['girar', '<path fill="#E8834B" d="m12 1.6 3 6.6 7.2.7-5.4 4.9 1.5 7.1L12 17.3 5.7 20.9l1.5-7.1L1.8 8.9l7.2-.7z"/><g fill="#C96633"><circle cx="12" cy="7.6" r=".55"/><circle cx="10.2" cy="11.6" r=".5"/><circle cx="13.8" cy="11.6" r=".5"/><circle cx="12" cy="14.4" r=".5"/></g>'],
        'concha'      => ['caer', '<path fill="#F3D9C4" d="M12 21C6.4 21 2 16.4 2 10.6 2 6.4 6.4 3 12 3s10 3.4 10 7.6C22 16.4 17.6 21 12 21z"/><g stroke="#DCB89A" stroke-width=".9" fill="none" stroke-linecap="round"><path d="M12 20.4V4M12 20.4 5.4 6.8M12 20.4 18.6 6.8M12 20.4 3 11M12 20.4 21 11"/></g>'],
        'ola'         => ['derivar', '<path fill="#2B8FC4" d="M1 17.6c1.6-7.4 5.4-11.4 10-11.4 4 0 7 2.8 7 6.6 0 2-1.2 3.4-2.9 3.4-1.6 0-2.7-1.2-2.7-2.9 0-1.8-1.2-3-3-3-2.6 0-4.6 2.2-5.6 7.3z"/><path fill="#4FB0DE" d="M3.4 16.4c1.6-5.4 4.6-8.4 8-8.4 3 0 5.2 2.1 5.2 4.9 0 1.3-.7 2.2-1.7 2.2-.9 0-1.5-.7-1.5-1.7 0-2.4-1.8-4-4.2-4-2.6 0-4.6 2.4-5.8 7z"/><path fill="#EAF6FF" d="M11 6.2c2.8 0 5 1.4 6.2 3.6-1.8-1.3-3.8-1.8-6-1.4-2.4.4-4.4 2-5.9 4.5C6.4 8.6 8.4 6.2 11 6.2z"/><path fill="#9BD3EE" d="M1 18.2h22v3.4H1z"/>'],
        'pelota_playa'=> ['rebotar', '<circle cx="12" cy="12" r="9" fill="#F7F3EE"/><path fill="#E0384A" d="M12 3a9 9 0 0 1 6.4 2.7C15.6 8 13.5 9.9 12 12z"/><path fill="#2B8FC4" d="M12 21a9 9 0 0 1-6.4-2.7C8.4 16 10.5 14.1 12 12z"/><path fill="#F2C230" d="M21 12a9 9 0 0 1-2.7 6.4C16 15.6 14.1 13.5 12 12z"/><path fill="#2F8B4F" d="M3 12a9 9 0 0 1 2.7-6.4C8 8.4 9.9 10.5 12 12z"/><circle cx="12" cy="12" r="1.9" fill="#F7F3EE"/>'],
        'gafas'       => ['derivar', '<rect x="1.6" y="8.4" width="8.4" height="6.4" rx="3" fill="#2B2130"/><rect x="14" y="8.4" width="8.4" height="6.4" rx="3" fill="#2B2130"/><path d="M10 10.6h4v1.4h-4z" fill="#3E3243"/><path d="M2.4 9.6h6.8v1.5H2.4zM14.8 9.6h6.8v1.5h-6.8z" fill="#7FC6E8" opacity=".55"/>'],
        'flor_tropical'=> ['derivar', '<g fill="#FFFDF8" stroke="#E3D6C4" stroke-width=".7"><ellipse cx="12" cy="6.6" rx="2.8" ry="4"/><ellipse cx="12" cy="6.6" rx="2.8" ry="4" transform="rotate(72 12 12)"/><ellipse cx="12" cy="6.6" rx="2.8" ry="4" transform="rotate(144 12 12)"/><ellipse cx="12" cy="6.6" rx="2.8" ry="4" transform="rotate(216 12 12)"/><ellipse cx="12" cy="6.6" rx="2.8" ry="4" transform="rotate(288 12 12)"/></g><circle cx="12" cy="12" r="2.6" fill="#F2C230"/><circle cx="12" cy="12" r="1.3" fill="#E0A020"/>'],
        'tabla_surf'  => ['derivar', '<path fill="#F0D9C0" d="M12 1c3.4 3.6 5 8 5 11.6 0 5.4-2.2 9.4-5 10.4-2.8-1-5-5-5-10.4C7 9 8.6 4.6 12 1z"/><path fill="#E0384A" d="M11.2 2.6h1.6c.5 3.4.5 12.4 0 18.4h-1.6c-.5-6-.5-15 0-18.4z"/><path fill="#2B8FC4" d="M12 1c1.4 1.5 2.6 3.2 3.4 5H8.6C9.4 4.2 10.6 2.5 12 1z" opacity=".65"/>'],
        'silla_playa' => ['derivar', '<path d="M3.6 20.6 9 8.6l1.6.7-5.4 12z" fill="#8A5A2B"/><path d="M20.4 20.6 15 8.6l-1.6.7 5.4 12z" fill="#8A5A2B"/><path d="M6 13.6h12v1.5H6z" fill="#8A5A2B"/><path fill="#2B8FC4" d="M7.4 6.4h9.2l-1.4 8H8.8z"/><path fill="#F7F3EE" d="M9.6 6.4h1.6l-1 8H8.6zM13 6.4h1.6l-1 8H12z"/>'],
        'sombrilla'   => ['derivar', '<path d="M11.4 11h1.2v11h-1.2z" fill="#8A5A2B"/><path fill="#E0384A" d="M12 2c5.2 0 9.4 3.6 10 8.4H2C2.6 5.6 6.8 2 12 2z"/><path fill="#F7F3EE" d="M12 2c1.5 0 2.8 3.6 3 8.4h-3zM4.4 10.4C5.6 6.4 7.6 3.4 9.6 2.4c-1 2.2-1.6 5-1.7 8zM19.6 10.4c-1.2-4-3.2-7-5.2-8 1 2.2 1.6 5 1.7 8z"/><circle cx="12" cy="1.9" r="1" fill="#8A5A2B"/>'],
        'coco'        => ['flotar', '<path fill="#8A5A2B" d="M12 6.6c4 0 7 2.6 7 7.4 0 4.6-3 8-7 8s-7-3.4-7-8c0-4.8 3-7.4 7-7.4z"/><ellipse cx="12" cy="8.4" rx="5" ry="1.9" fill="#F0E2D0"/><path d="M13.4 8 17 1.4" stroke="#F7F3EE" stroke-width="1.4" stroke-linecap="round" fill="none"/><circle cx="9.4" cy="6.4" r="1.9" fill="#F0899E"/><path fill="#2F8B4F" d="M14.6 6.2c1.6-1.4 3-1.8 4.6-1.4-1 1.6-2.6 2.2-4.6 1.4z"/>'],
        'chancleta'   => ['derivar', '<path fill="#F3E6D8" d="M12 1.8c3.3 0 5.5 2.2 5.5 6.1v8c0 3.9-2.2 6.3-5.5 6.3s-5.5-2.4-5.5-6.3v-8c0-3.9 2.2-6.1 5.5-6.1z"/><path fill="none" stroke="#D8C3AC" stroke-width=".9" d="M12 3.2c2.6 0 4.3 1.8 4.3 4.9v7.8c0 3.1-1.7 5.1-4.3 5.1s-4.3-2-4.3-5.1V8.1C7.7 5 9.4 3.2 12 3.2z"/><path d="M12 10.4 8 6.6M12 10.4l4-3.8" stroke="#E0384A" stroke-width="2.1" stroke-linecap="round" fill="none"/><path d="M12 10.4v3.4" stroke="#E0384A" stroke-width="2.1" stroke-linecap="round" fill="none"/><circle cx="12" cy="10.4" r="1.5" fill="#C41E2A"/>'],

        // --- Deportes ---------------------------------------------------------------
        'balon_futbol'=> ['rebotar', '<circle cx="12" cy="12" r="9.2" fill="#F7F3EE"/><circle cx="12" cy="12" r="9.2" fill="none" stroke="#C8C2BC" stroke-width=".7"/><path fill="#2B2130" d="m12 6.4 3.4 2.5-1.3 4h-4.2l-1.3-4z"/><path fill="#2B2130" d="M12 2.9 14.9 5l-.6 1.6-2.3-1.7-2.3 1.7L9.1 5zM4.2 8.6 6.9 10l-.6 1.6-2.9-.5zM19.8 8.6 17.1 10l.6 1.6 2.9-.5zM6.6 18.4l1-2.6 1.7.6.3 2.9zM17.4 18.4l-1-2.6-1.7.6-.3 2.9z"/>'],
        'balon_basket'=> ['rebotar', '<circle cx="12" cy="12" r="9.2" fill="#E07A2B"/><g stroke="#2B2130" stroke-width="1.05" fill="none"><circle cx="12" cy="12" r="9.2"/><path d="M12 2.8v18.4M2.8 12h18.4M5.4 5.4c3.6 2 3.6 11.2 0 13.2M18.6 5.4c-3.6 2-3.6 11.2 0 13.2"/></g>'],
        'pelota_tenis'=> ['rebotar', '<circle cx="12" cy="12" r="9.2" fill="#D2E33A"/><g stroke="#F7F3EE" stroke-width="1.3" fill="none"><path d="M4.4 5.8c3.4 2.6 3.4 9.8 0 12.4M19.6 5.8c-3.4 2.6-3.4 9.8 0 12.4"/></g>'],
        'balon_voley' => ['rebotar', '<circle cx="12" cy="12" r="9.2" fill="#F7F3EE"/><g stroke="#2B8FC4" stroke-width="1.2" fill="none"><circle cx="12" cy="12" r="9.2"/><path d="M12 2.8c-3 3.4-3.4 8.4-1 12.6M12 2.8c3 3.4 3.4 8.4 1 12.6M3.2 14.4c4.4-1.4 8.8.2 11.6 3.6M20.8 14.4c-4.4-1.4-8.8.2-11.6 3.6"/></g>'],
        'porteria'    => ['derivar', '<rect x="1.8" y="6.8" width="20.4" height="12.2" rx=".6" fill="#F2F4F6"/><g stroke="#9BA6AE" stroke-width=".55" fill="none"><path d="M5.8 6.8v12.2M9.8 6.8v12.2M13.8 6.8v12.2M17.8 6.8v12.2M1.8 10.6h20.4M1.8 14.4h20.4"/></g><rect x="1.8" y="6.8" width="20.4" height="12.2" rx=".6" fill="none" stroke="#5A646C" stroke-width="1.7"/>'],
        'silbato'     => ['derivar', '<path fill="#C0C4C8" d="M4.6 8.6h9.8c2.4 0 4.4 1.9 4.4 4.3s-2 4.3-4.4 4.3H8.4c-2.4 0-4.2-1.7-4.2-4.1z"/><circle cx="14.4" cy="12.9" r="2.7" fill="#8E959B"/><path fill="#E0E4E8" d="M4.6 8.6h9.8c.9 0 1.7.3 2.4.7H5.2z"/><path d="M4.6 8.6 1 6.2" stroke="#8E959B" stroke-width="1.5" stroke-linecap="round" fill="none"/>'],
        'bota'        => ['derivar', '<path fill="#2B2130" d="M4 15.4c0-3.4 1.4-6.2 2.4-9.4h4.2c-.4 2.4-.6 4 0 5.2 1.6 1.4 4.6 2.2 8 2.8 1.6.3 2.4 1.2 2.4 2.6v1.8H4z"/><path fill="#F7F3EE" d="M4 18.4h17v1.8H4z"/><g fill="#F2C230"><circle cx="8.4" cy="14.2" r=".7"/><circle cx="11.4" cy="15.2" r=".7"/><circle cx="14.4" cy="15.9" r=".7"/><circle cx="17.4" cy="16.4" r=".7"/></g>'],
        'canasta'     => ['derivar', '<rect x="3.6" y="2.4" width="16.8" height="10.6" rx=".8" fill="#F7F3EE" stroke="#5A646C" stroke-width="1.2"/><rect x="8.4" y="5.4" width="7.2" height="5.2" fill="none" stroke="#D8232A" stroke-width="1.2"/><path d="M6.4 13.2h11.2" stroke="#E0722B" stroke-width="2.1" stroke-linecap="round" fill="none"/><path fill="none" stroke="#A8B0B6" stroke-width=".85" d="M7.4 14c.7 2.6 1.9 4.4 4.6 5.4 2.7-1 3.9-2.8 4.6-5.4M9.6 14l1 5.2M14.4 14l-1 5.2M7.9 16.4h8.2"/>'],

        // --- Otros ------------------------------------------------------------------
        'luces'       => ['destellar', '<path d="M1 5c4 5 8 7.6 11 7.6S18 10 23 5" stroke="#6B5B3E" stroke-width="1" fill="none" stroke-linecap="round"/><g><circle cx="5" cy="9.4" r="2.1" fill="#F2C230"/><circle cx="10" cy="12.4" r="2.1" fill="#E0384A"/><circle cx="15" cy="12.1" r="2.1" fill="#4FB0DE"/><circle cx="20" cy="8.6" r="2.1" fill="#2F8B4F"/></g>'],
        'nube'        => ['flotar', '<path fill="#D6E4EF" d="M6.6 18.4A4.6 4.6 0 0 1 6 9.3a6.2 6.2 0 0 1 11.8-1.6 4.4 4.4 0 0 1 .5 8.7z"/><path fill="#EEF5FA" d="M8 16.6a3 3 0 0 1-.4-5.9 4.2 4.2 0 0 1 7.4-1.2 3 3 0 0 1 .8 5.9z"/>'],
        'flor'        => ['caer', '<g fill="currentColor"><ellipse cx="12" cy="6.4" rx="3.1" ry="4.4"/><ellipse cx="12" cy="6.4" rx="3.1" ry="4.4" transform="rotate(72 12 12)"/><ellipse cx="12" cy="6.4" rx="3.1" ry="4.4" transform="rotate(144 12 12)"/><ellipse cx="12" cy="6.4" rx="3.1" ry="4.4" transform="rotate(216 12 12)"/><ellipse cx="12" cy="6.4" rx="3.1" ry="4.4" transform="rotate(288 12 12)"/></g><circle cx="12" cy="12" r="2.5" fill="#FBE3A8"/>'],
    ];

    /**
     * Movimiento de cada forma del estilo, en el mismo orden.
     *
     * Viaja al navegador junto a la lista de formas: una hoja cae, un globo
     * sube, una pelota bota y un destello titila sin moverse del sitio. Que
     * todas hicieran lo mismo es lo que delata una animación automática.
     */
    public static function movimientos(array $estilo): array
    {
        $out = [];
        foreach ($estilo['formas'] as $id) {
            $out[] = self::FORMAS[$id][0] ?? 'caer';
        }
        return $out;
    }

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
                $out .= '<symbol id="tf-' . $id . '" viewBox="0 0 24 24">'
                      . self::FORMAS[$id][1] . '</symbol>';
            }
        }
        return $out;
    }
}
