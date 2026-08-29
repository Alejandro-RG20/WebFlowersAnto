<?php
/**
 * Acceso a la configuración del sitio (tabla `configuracion`, fila única).
 *
 * Se lee una sola vez por petición y se cachea en memoria: la portada, el
 * encabezado, el pie y los correos consultan estos valores decenas de veces.
 */

declare(strict_types=1);

final class Ajustes
{
    private static ?array $datos = null;
    private static ?PDO $pdo = null;

    public static function usar(PDO $pdo): void
    {
        self::$pdo = $pdo;
    }

    /** @return array<string,mixed> */
    public static function todos(): array
    {
        if (self::$datos !== null) {
            return self::$datos;
        }
        $pdo = self::$pdo ?? $GLOBALS['pdo'] ?? null;
        if (!$pdo instanceof PDO) {
            return self::$datos = [];
        }
        try {
            $fila = $pdo->query("SELECT * FROM configuracion WHERE id = 1")->fetch();
        } catch (PDOException) {
            $fila = false; // base todavía sin migrar
        }
        return self::$datos = ($fila ?: []);
    }

    public static function texto(string $clave, string $def = ''): string
    {
        $v = self::todos()[$clave] ?? null;
        return ($v === null || $v === '') ? $def : (string)$v;
    }

    public static function numero(string $clave, float $def = 0): float
    {
        $v = self::todos()[$clave] ?? null;
        return $v === null ? $def : (float)$v;
    }

    public static function activo(string $clave, bool $def = false): bool
    {
        $v = self::todos()[$clave] ?? null;
        return $v === null ? $def : ((int)$v === 1);
    }

    /** Lista separada por comas, ya recortada y sin vacíos. */
    public static function lista(string $clave, array $def = []): array
    {
        $bruto = self::texto($clave, '');
        if ($bruto === '') {
            return $def;
        }
        $items = array_filter(array_map('trim', explode(',', $bruto)), fn($v) => $v !== '');
        return array_values($items) ?: $def;
    }

    /** Invalida la caché tras guardar desde el panel. */
    public static function refrescar(): void
    {
        self::$datos = null;
    }

    /** Número de WhatsApp para pedidos; si no hay uno propio, el de contacto. */
    public static function whatsappPedidos(): string
    {
        $n = self::texto('whatsapp_pedidos', '');
        return $n !== '' ? $n : self::texto('whatsapp_numero', '');
    }

    /** Cuentas bancarias activas, ordenadas. */
    public static function cuentasBancarias(PDO $pdo): array
    {
        try {
            return $pdo->query("SELECT * FROM cuentas_bancarias WHERE activo = 1 ORDER BY orden, id")->fetchAll();
        } catch (PDOException) {
            return [];
        }
    }
}
