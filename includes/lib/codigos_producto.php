<?php
/**
 * Códigos de producto (p. ej. FA-0012).
 *
 * Un código se escribe siempre igual: en mayúsculas, con letras, números y
 * guiones, de 3 a 30 caracteres. «fa 12» se guarda como «FA-12». Así no hay
 * dos formas de escribir el mismo y la búsqueda encuentra lo que se teclea.
 *
 * Mientras la migración 027 no esté aplicada, `disponible()` devuelve false
 * y el resto del sitio funciona como antes, sin código.
 */

declare(strict_types=1);

final class CodigosProducto
{
    public const PREFIJO = 'FA-';
    public const MAXIMO  = 30;

    private static ?bool $hay = null;

    public static function disponible(PDO $pdo): bool
    {
        if (self::$hay === null) {
            try {
                $pdo->query("SELECT codigo FROM productos LIMIT 0");
                $pdo->query("SELECT codigo FROM pedido_items LIMIT 0");
                self::$hay = true;
            } catch (PDOException $e) {
                self::$hay = false;
            }
        }
        return self::$hay;
    }

    /** Forma canónica de lo que se escribió: «fa 12» → «FA-12». */
    public static function normalizar(string $bruto): string
    {
        $c = mb_strtoupper(trim($bruto), 'UTF-8');
        $c = preg_replace('/[\s_\/.]+/u', '-', $c);
        $c = preg_replace('/[^A-Z0-9-]/', '', (string)$c);
        $c = preg_replace('/-{2,}/', '-', (string)$c);
        return mb_substr(trim((string)$c, '-'), 0, self::MAXIMO);
    }

    public static function valido(string $codigo): bool
    {
        return (bool)preg_match('/^[A-Z0-9](?:[A-Z0-9-]{1,' . (self::MAXIMO - 2) . '})[A-Z0-9]$/', $codigo);
    }

    /** Producto que ya usa ese código (sin contar el que se está editando), o null. */
    public static function usadoPor(PDO $pdo, string $codigo, int $exceptoId = 0): ?array
    {
        $st = $pdo->prepare("SELECT id, nombre FROM productos WHERE codigo = ? AND id <> ? LIMIT 1");
        $st->execute([$codigo, $exceptoId]);
        return $st->fetch() ?: null;
    }

    /**
     * El código que le toca a un producto si no se le pone uno: FA- y su
     * número con cuatro cifras. Si alguien ya lo eligió a mano para otro
     * arreglo, se le añade una letra (FA-0012B…) hasta dar con uno libre.
     */
    public static function sugerido(PDO $pdo, int $id): string
    {
        $base = self::PREFIJO . str_pad((string)$id, 4, '0', STR_PAD_LEFT);
        $codigo = $base;
        foreach (range('B', 'Z') as $letra) {
            if (!self::usadoPor($pdo, $codigo, $id)) {
                return $codigo;
            }
            $codigo = $base . $letra;
        }
        return $base . '-' . bin2hex(random_bytes(2));
    }

    /**
     * Condición SQL para buscar por código lo que escribió alguien, o null si
     * no hay nada que buscar. Encuentra el código aunque se escriba distinto:
     * «fa 0012», «FA-0012» y «fa 12» encuentran FA-0012 (el número se compara
     * sin ceros a la izquierda). Solo lleva letras, números y guiones: es
     * seguro como parámetro, también en el REGEXP.
     *
     * @return array{0: string, 1: list<string>}|null  [sql, parámetros]
     */
    public static function filtro(PDO $pdo, string $consulta, string $columna = 'p.codigo'): ?array
    {
        $c = self::normalizar($consulta);
        if ($c === '' || !self::disponible($pdo)) {
            return null;
        }
        $sql = "$columna LIKE ?";
        $params = ['%' . $c . '%'];
        if (preg_match('/^([A-Z]+)-?0*([0-9]+)$/', $c, $m)) {
            $sql .= " OR $columna REGEXP ?";
            $params[] = '^' . $m[1] . '-?0*' . $m[2] . '$';
        }
        return ['(' . $sql . ')', $params];
    }

    /** Valida lo que se escribió en el formulario. Devuelve el error o null. */
    public static function error(PDO $pdo, string $codigo, int $exceptoId): ?string
    {
        if (!self::valido($codigo)) {
            return 'Usa de 3 a ' . self::MAXIMO . ' letras, números o guiones (por ejemplo FA-0012).';
        }
        $otro = self::usadoPor($pdo, $codigo, $exceptoId);
        return $otro ? 'Ese código ya lo tiene «' . $otro['nombre'] . '». Cada arreglo necesita el suyo.' : null;
    }
}
