<?php
/**
 * Google Analytics 4.
 *
 * Reúne en un solo sitio las tres decisiones que hacen que una medición sirva
 * de algo: si se mide, qué se mide y con qué permiso.
 *
 * Sobre el permiso: el sitio ya pregunta por las cookies antes de guardar
 * nada. Analytics se enchufa a esa misma respuesta con el «modo de
 * consentimiento» de Google —todo denegado de salida, y solo se concede si la
 * persona acepta—. Mientras no acepte, Google recibe una señal anónima sin
 * cookies y sin identificador: se sabe que hubo una visita, no quién fue. Eso
 * es lo que permite tener números honestos sin faltar a la política de
 * privacidad que la propia tienda publica.
 */

declare(strict_types=1);

final class Analitica
{
    /** Formato del identificador de medición de GA4. */
    private const PATRON_ID = '/^G-[A-Z0-9]{4,20}$/';

    /**
     * ¿La tienda mide con Analytics? Responde por la configuración del sitio,
     * no por quién esté mirando.
     *
     * Es la que usan los textos legales: lo que la política de privacidad
     * cuenta tiene que ser lo mismo para todo el mundo. Si se preguntara por
     * `activo()`, la dueña leería «no hay rastreadores» mientras sus clientes
     * leen lo contrario, y la que estaría mal sería la suya.
     */
    public static function configurado(): bool
    {
        return Ajustes::activo('ga_activo', false) && self::idValido(self::id());
    }

    /** ¿Hay que pintar la etiqueta en esta página y para esta visita? */
    public static function activo(): bool
    {
        if (!self::configurado()) {
            return false;
        }
        // Las visitas del equipo no son ventas ni clientes: si se cuentan, las
        // páginas más vistas acaban siendo las que administra la dueña.
        return !(Ajustes::activo('ga_excluir_equipo', true) && Auth::esPersonal());
    }

    public static function id(): string
    {
        return strtoupper(trim(Ajustes::texto('ga_id')));
    }

    public static function idValido(string $id): bool
    {
        return (bool)preg_match(self::PATRON_ID, $id);
    }

    /** Moneda ISO-4217; GA4 rechaza el importe si no la reconoce. */
    public static function moneda(): string
    {
        $m = strtoupper(trim(Ajustes::texto('ga_moneda', 'NIO')));
        return preg_match('/^[A-Z]{3}$/', $m) ? $m : 'NIO';
    }

    public static function depurar(): bool
    {
        return Ajustes::activo('ga_depurar', false);
    }

    /**
     * Un producto en el formato de artículo que espera GA4.
     *
     * @param array $p         fila de `productos` (o de `pedido_items`)
     * @param int   $cantidad  unidades
     * @param int   $posicion  puesto en la lista, empezando en 1
     */
    public static function item(array $p, int $cantidad = 1, int $posicion = 0): array
    {
        $item = [
            'item_id'   => (string)($p['slug'] ?? $p['producto_id'] ?? $p['id'] ?? ''),
            'item_name' => (string)($p['nombre'] ?? ''),
            'price'     => round((float)($p['precio'] ?? $p['precio_unitario'] ?? 0), 2),
            'quantity'  => max(1, $cantidad),
        ];
        if (($cat = (string)($p['categoria_nombre'] ?? '')) !== '') {
            $item['item_category'] = $cat;
        }
        if ($posicion > 0) {
            $item['index'] = $posicion;
        }
        return $item;
    }

    /**
     * Lista de productos ya numerada.
     *
     * @param array  $filas  productos
     * @param string $lista  nombre con el que se verá en los informes
     */
    public static function lista(array $filas, string $lista): array
    {
        $items = [];
        foreach (array_values($filas) as $i => $p) {
            $items[] = self::item($p, 1, $i + 1) + ['item_list_name' => $lista];
        }
        return $items;
    }

    /** Suma de un carrito o pedido, para el `value` del evento. */
    public static function valor(array $items): float
    {
        $total = 0.0;
        foreach ($items as $it) {
            $total += (float)($it['price'] ?? 0) * (int)($it['quantity'] ?? 1);
        }
        return round($total, 2);
    }

    /**
     * El atributo con el que el navegador reconoce un producto en la página.
     *
     * Se pinta una sola vez, junto al producto, y de ahí lo leen los eventos
     * que solo ocurren si alguien hace clic. Así el nombre y el precio que
     * llegan a Analytics son los mismos que la persona está viendo, sin una
     * segunda copia en JavaScript que se quede vieja.
     */
    public static function atributo(array $p, int $cantidad = 1, int $posicion = 0): string
    {
        if (!self::activo()) {
            return '';
        }
        $json = json_encode(self::item($p, $cantidad, $posicion),
                            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return ' data-ga-item="' . e((string)$json) . '"';
    }

    /**
     * Deja un evento preparado para que lo dispare el navegador al cargar.
     *
     * Se acumulan en la sesión de la página, no en `$_SESSION`: son de esta
     * respuesta y de ninguna otra.
     */
    public static function evento(string $nombre, array $parametros = []): void
    {
        if (!self::activo()) {
            return;
        }
        self::$cola[] = ['nombre' => $nombre, 'datos' => $parametros];
    }

    /** @var list<array{nombre:string,datos:array}> */
    private static array $cola = [];

    /** @return list<array{nombre:string,datos:array}> */
    public static function cola(): array
    {
        self::recogerDiferidos();
        return self::$cola;
    }

    /**
     * Evento que sobrevive a una redirección.
     *
     * Media web funciona con «POST y redirijo» para que recargar no repita la
     * operación. Sin esto, todo lo que se mide en el POST —añadir al carrito
     * desde la ficha, quitar, vaciar— se perdería antes de llegar a pintarse.
     */
    public static function eventoDiferido(string $nombre, array $parametros = []): void
    {
        if (!self::activo()) {
            return;
        }
        $_SESSION['ga_diferidos'][] = ['nombre' => $nombre, 'datos' => $parametros];
    }

    /** Recoge los diferidos y los vacía: se miden una vez y nunca más. */
    private static function recogerDiferidos(): void
    {
        foreach ((array)($_SESSION['ga_diferidos'] ?? []) as $ev) {
            if (isset($ev['nombre'])) {
                self::$cola[] = ['nombre' => (string)$ev['nombre'], 'datos' => (array)($ev['datos'] ?? [])];
            }
        }
        unset($_SESSION['ga_diferidos']);
    }

    /**
     * Marca que un pedido acaba de nacer, para medir la compra UNA sola vez.
     *
     * Va en la sesión a propósito: si el evento se disparara cada vez que se
     * abre la página del pedido, cada visita del cliente a «¿ya salió mi
     * ramo?» contaría como una venta nueva y los ingresos serían mentira.
     */
    public static function marcarCompra(int $pedidoId): void
    {
        $_SESSION['ga_compra'] = $pedidoId;
    }

    /** ¿Toca medir la compra de este pedido? Solo responde que sí una vez. */
    public static function compraPendiente(int $pedidoId): bool
    {
        if ((int)($_SESSION['ga_compra'] ?? 0) !== $pedidoId || $pedidoId <= 0) {
            return false;
        }
        unset($_SESSION['ga_compra']);
        return true;
    }
}
