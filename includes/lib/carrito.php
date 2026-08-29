<?php
/**
 * Carrito.
 *
 * Vive siempre en la sesión, así que funciona igual para un visitante que para
 * alguien con cuenta. Cuando hay sesión iniciada se refleja además en
 * `carrito_items`, de modo que el carrito sobrevive al cierre del navegador y
 * aparece igual en otro dispositivo.
 *
 * Los precios NUNCA vienen del navegador: se releen de la base al calcular el
 * total y al crear el pedido.
 */

declare(strict_types=1);

final class Carrito
{
    private const CLAVE = 'carrito';
    public const MAX_UNIDADES = 20;
    public const MAX_LINEAS   = 30;

    /** @return array<int,int> producto_id => cantidad */
    public static function lineas(): array
    {
        $c = $_SESSION[self::CLAVE] ?? [];
        return is_array($c) ? $c : [];
    }

    private static function guardar(array $lineas): void
    {
        $_SESSION[self::CLAVE] = $lineas;
    }

    public static function unidades(): int
    {
        return array_sum(self::lineas());
    }

    public static function vacio(): bool
    {
        return self::lineas() === [];
    }

    /**
     * Añade unidades. Devuelve un mensaje de error, o '' si todo fue bien.
     * Valida existencia, publicación y stock contra la base.
     */
    public static function agregar(PDO $pdo, int $productoId, int $cantidad = 1): string
    {
        $cantidad = max(1, min(self::MAX_UNIDADES, $cantidad));

        $st = $pdo->prepare(
            "SELECT id, nombre, disponible, controla_stock, stock FROM productos WHERE id = ? AND activo = 1"
        );
        $st->execute([$productoId]);
        $p = $st->fetch();

        if (!$p) {
            return 'Ese arreglo ya no está disponible.';
        }
        if (!Catalogo::disponible($p)) {
            return 'Ese arreglo está agotado por ahora. Escríbenos por WhatsApp y lo preparamos sobre pedido.';
        }

        $lineas = self::lineas();
        if (!isset($lineas[$productoId]) && count($lineas) >= self::MAX_LINEAS) {
            return 'El carrito ya tiene demasiados artículos distintos.';
        }

        $nueva  = min(self::MAX_UNIDADES, ($lineas[$productoId] ?? 0) + $cantidad);
        $maximo = Catalogo::maximoPedible($p);
        if ($nueva > $maximo) {
            $nueva = $maximo;
            if ($nueva <= 0) {
                return 'No quedan unidades de ese arreglo.';
            }
            $lineas[$productoId] = $nueva;
            self::guardar($lineas);
            self::sincronizar($pdo);
            return 'Solo quedan ' . $maximo . ' unidades, ajustamos la cantidad.';
        }

        $lineas[$productoId] = $nueva;
        self::guardar($lineas);
        self::sincronizar($pdo);
        return '';
    }

    /** Fija la cantidad exacta de una línea. 0 la elimina. */
    public static function fijar(PDO $pdo, int $productoId, int $cantidad): string
    {
        $lineas = self::lineas();
        if ($cantidad <= 0) {
            unset($lineas[$productoId]);
            self::guardar($lineas);
            self::sincronizar($pdo);
            return '';
        }
        if (!isset($lineas[$productoId])) {
            return self::agregar($pdo, $productoId, $cantidad);
        }
        unset($lineas[$productoId]);
        self::guardar($lineas);
        return self::agregar($pdo, $productoId, $cantidad);
    }

    public static function quitar(PDO $pdo, int $productoId): void
    {
        $lineas = self::lineas();
        unset($lineas[$productoId]);
        self::guardar($lineas);
        self::sincronizar($pdo);
    }

    public static function vaciar(PDO $pdo): void
    {
        self::guardar([]);
        self::sincronizar($pdo);
    }

    /**
     * Contenido detallado con los precios frescos de la base.
     *
     * @return array{items: array, subtotal: float, envio: float, total: float,
     *               unidades: int, avisos: string[]}
     */
    public static function detalle(PDO $pdo): array
    {
        $lineas = self::lineas();
        if (!$lineas) {
            return ['items' => [], 'subtotal' => 0.0, 'envio' => 0.0, 'total' => 0.0,
                    'unidades' => 0, 'avisos' => []];
        }

        $productos = Catalogo::porIds($pdo, array_keys($lineas));
        $items     = [];
        $avisos    = [];
        $subtotal  = 0.0;
        $cambio    = false;

        foreach ($lineas as $id => $cantidad) {
            $p = $productos[$id] ?? null;
            if (!$p) {
                unset($lineas[$id]);
                $cambio   = true;
                $avisos[] = 'Quitamos un arreglo que ya no está en el catálogo.';
                continue;
            }
            if (!Catalogo::disponible($p)) {
                unset($lineas[$id]);
                $cambio   = true;
                $avisos[] = '«' . $p['nombre'] . '» se agotó y lo quitamos del carrito.';
                continue;
            }
            $maximo = Catalogo::maximoPedible($p);
            if ($cantidad > $maximo) {
                $cantidad = $maximo;
                $lineas[$id] = $cantidad;
                $cambio   = true;
                $avisos[] = 'Ajustamos «' . $p['nombre'] . '» a ' . $maximo . ' unidades por disponibilidad.';
            }

            $precio = (float)$p['precio'];
            $linea  = round($precio * $cantidad, 2);
            $subtotal += $linea;

            $items[] = [
                'producto_id' => (int)$id,
                'nombre'      => $p['nombre'],
                'slug'        => $p['slug'],
                'imagen'      => $p['portada'] ?? $p['imagen'],
                'precio'      => $precio,
                'precio_usd'  => (float)$p['precio_usd'],
                'cantidad'    => $cantidad,
                'subtotal'    => $linea,
                'maximo'      => $maximo,
            ];
        }

        if ($cambio) {
            self::guardar($lineas);
            self::sincronizar($pdo);
        }

        $envio = self::costoEnvio($subtotal);

        return [
            'items'    => $items,
            'subtotal' => round($subtotal, 2),
            'envio'    => $envio,
            'total'    => round($subtotal + $envio, 2),
            'unidades' => array_sum($lineas),
            'avisos'   => array_values(array_unique($avisos)),
        ];
    }

    /** Costo de envío según la configuración (con umbral de envío gratis). */
    public static function costoEnvio(float $subtotal): float
    {
        $costo  = (float)Ajustes::numero('costo_envio', 0);
        $umbral = (float)Ajustes::numero('envio_gratis_desde', 0);
        if ($costo <= 0 || ($umbral > 0 && $subtotal >= $umbral)) {
            return 0.0;
        }
        return round($costo, 2);
    }

    // -----------------------------------------------------------------
    // Persistencia de los usuarios con cuenta
    // -----------------------------------------------------------------

    /** Vuelca el carrito de sesión a la base (solo si hay sesión iniciada). */
    public static function sincronizar(PDO $pdo): void
    {
        $usuarioId = Auth::id();
        if (!$usuarioId) {
            return;
        }
        try {
            $pdo->prepare("DELETE FROM carrito_items WHERE usuario_id = ?")->execute([$usuarioId]);
            $lineas = self::lineas();
            if ($lineas) {
                $ins = $pdo->prepare(
                    "INSERT INTO carrito_items (usuario_id, producto_id, cantidad) VALUES (?, ?, ?)"
                );
                foreach ($lineas as $id => $cantidad) {
                    $ins->execute([$usuarioId, $id, $cantidad]);
                }
            }
        } catch (PDOException $ex) {
            error_log('Flowers Anto — sincronizar carrito: ' . $ex->getMessage());
        }
    }

    /**
     * Al iniciar sesión: junta el carrito guardado con el que traía la visita.
     * Gana la cantidad mayor de cada producto, nunca se pierde nada.
     */
    public static function fusionarAlEntrar(PDO $pdo, int $usuarioId): void
    {
        try {
            $st = $pdo->prepare("SELECT producto_id, cantidad FROM carrito_items WHERE usuario_id = ?");
            $st->execute([$usuarioId]);
            $guardado = $st->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (PDOException) {
            return;
        }

        $sesion = self::lineas();
        foreach ($guardado as $id => $cantidad) {
            $sesion[(int)$id] = min(self::MAX_UNIDADES, max((int)$cantidad, $sesion[(int)$id] ?? 0));
        }
        self::guardar($sesion);
        self::sincronizar($pdo);
    }

    /**
     * Mensaje de WhatsApp con todo el carrito.
     * Se arma en el servidor para que el número y el formato salgan siempre
     * de la configuración y no de un valor escrito en el JavaScript.
     */
    public static function mensajeWhatsapp(array $detalle): string
    {
        $tienda  = Ajustes::texto('nombre_tienda', 'Flowers Anto');
        $moneda  = Ajustes::texto('moneda_local', 'C$');
        $lineas  = ["Hola $tienda, quiero hacer este pedido:", ''];

        foreach ($detalle['items'] as $i) {
            $lineas[] = sprintf(
                '• %d × %s — %s%s',
                $i['cantidad'],
                $i['nombre'],
                $moneda,
                number_format($i['subtotal'], 2)
            );
        }

        $lineas[] = '';
        $lineas[] = 'Subtotal: ' . $moneda . number_format($detalle['subtotal'], 2);
        if ($detalle['envio'] > 0) {
            $lineas[] = 'Envío: ' . $moneda . number_format($detalle['envio'], 2);
        }
        $lineas[] = 'Total: ' . $moneda . number_format($detalle['total'], 2);
        $lineas[] = '';
        $lineas[] = '¿Me confirman disponibilidad y la forma de pago?';

        return implode("\n", $lineas);
    }
}
