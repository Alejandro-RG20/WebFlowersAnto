<?php
/**
 * Precios y ofertas.
 *
 * Un único sitio donde se decide cuánto cuesta un arreglo. La tienda lo pinta
 * en la tarjeta, en la ficha, en el carrito, en el checkout y en la factura, y
 * el panel lo vuelve a enseñar; si cada uno hiciera su cuenta, tarde o
 * temprano dos de ellos dirían cifras distintas y el cliente vería un precio y
 * pagaría otro.
 *
 * El modelo es deliberadamente simple:
 *
 *   - `precio` en la tabla es el precio de siempre y no se toca nunca al poner
 *     una oferta. Es el que se tacha.
 *   - `descuento_pct` es el porcentaje. Cero significa que no hay oferta.
 *   - El precio que se cobra se calcula a partir de los dos.
 *
 * Guardar el precio ya rebajado encima del original sería más cómodo de leer,
 * pero deja el sistema sin retorno: quitar la oferta exigiría acordarse del
 * precio viejo, y aplicar un descuento dos veces lo encadenaría. Así el
 * original siempre está donde estaba.
 *
 * Lo mismo vale para los aumentos de precio: subir C$200 cambia `precio` una
 * vez, cuando alguien pulsa el botón. No es un estado que se vuelva a sumar al
 * dibujar la página, así que no puede acumularse solo.
 */

declare(strict_types=1);

final class Precios
{
    /**
     * Descuento máximo que se puede poner desde el panel.
     *
     * No es una cifra caprichosa: por encima de esto un dedazo —teclear 90 en
     * lugar de 9— deja los arreglos casi regalados, y el pedido entraría igual.
     */
    public const TOPE_PCT = 95;

    /** Porcentajes que ofrece el panel de un vistazo. */
    public const SUGERIDOS = [5, 10, 15, 20, 25, 30, 40, 50];

    /** Aumento máximo por operación, en córdobas. */
    public const TOPE_AUMENTO = 100000.0;

    /** El precio de siempre, el que se tacha cuando hay oferta. */
    public static function base(array $producto): float
    {
        return round((float)($producto['precio'] ?? 0), 2);
    }

    /** Porcentaje de la oferta, ya acotado al rango admitido. */
    public static function porcentaje(array $producto): int
    {
        $pct = (int)($producto['descuento_pct'] ?? 0);
        return max(0, min(self::TOPE_PCT, $pct));
    }

    /** ¿Este arreglo está rebajado ahora mismo? */
    public static function enOferta(array $producto): bool
    {
        return self::porcentaje($producto) > 0 && self::base($producto) > 0;
    }

    /**
     * Lo que se cobra. Es el número que manda en todo el sistema.
     */
    public static function efectivo(array $producto): float
    {
        $base = self::base($producto);
        $pct  = self::porcentaje($producto);
        if ($pct <= 0 || $base <= 0) {
            return $base;
        }
        return round($base * (100 - $pct) / 100, 2);
    }

    /**
     * El precio en dólares, ya con la oferta aplicada.
     *
     * Se rebaja con el mismo porcentaje en lugar de recalcularlo desde el
     * córdoba: así el dólar de un arreglo rebajado guarda la misma proporción
     * que tenía antes y no aparece una tasa distinta por el camino.
     */
    public static function efectivoUsd(array $producto): float
    {
        $usd = round((float)($producto['precio_usd'] ?? 0), 2);
        $pct = self::porcentaje($producto);
        if ($pct <= 0 || $usd <= 0) {
            return $usd;
        }
        return round($usd * (100 - $pct) / 100, 2);
    }

    /** Cuánto se ahorra el cliente por unidad. */
    public static function ahorro(array $producto): float
    {
        return round(self::base($producto) - self::efectivo($producto), 2);
    }

    /**
     * El mismo cálculo a partir de valores sueltos.
     *
     * Lo usan los pedidos ya hechos, que no leen el producto de hoy sino el
     * precio y el descuento que quedaron guardados el día de la compra.
     */
    public static function efectivoDe(float $base, int $pct): float
    {
        $pct = max(0, min(self::TOPE_PCT, $pct));
        if ($pct <= 0 || $base <= 0) {
            return round($base, 2);
        }
        return round($base * (100 - $pct) / 100, 2);
    }

    /**
     * Deja un porcentaje en el rango bueno.
     *
     * Devuelve null si lo escrito no es un número: así quien llama distingue
     * «quitar la oferta» (0) de «esto no se entiende» y puede avisar.
     */
    public static function normalizarPct(mixed $valor): ?int
    {
        if (!is_numeric($valor)) {
            return null;
        }
        $pct = (int)$valor;
        if ($pct < 0 || $pct > self::TOPE_PCT) {
            return null;
        }
        return $pct;
    }
}
