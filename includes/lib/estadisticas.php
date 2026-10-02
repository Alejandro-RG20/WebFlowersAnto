<?php
/**
 * Cifras del resumen del panel.
 *
 * Los días se cuentan en la hora de la tienda (America/Managua), no en la del
 * servidor de base de datos: en un hosting compartido MySQL suele ir en UTC y
 * un pedido de las 11 de la noche caía en el día siguiente. Se agrupa por
 * segundos desde 1970 más el desfase de la tienda, que no depende de la zona
 * horaria de la conexión.
 *
 * Cada consulta recorre solo el rango pedido y usa los índices por fecha que
 * ya tiene `pedidos`.
 */

declare(strict_types=1);

final class Estadisticas
{
    public const PERIODOS = [7, 30, 90];

    /**
     * Ventas y pedidos del período y del período anterior de igual largo.
     *
     * @return array{serie: list<array{dia: string, pedidos: int, cobrado: float}>,
     *               actual: array{pedidos: int, aprobados: int, cobrado: float},
     *               anterior: array{pedidos: int, aprobados: int, cobrado: float}}
     */
    public static function ventas(PDO $pdo, int $dias): array
    {
        $desfase = (int)date('Z');
        $hoy     = (int)floor((time() + $desfase) / 86400);
        $inicio  = $hoy - $dias + 1;      // primer día del período
        $desde   = $inicio - $dias;       // primer día del período anterior

        $st = $pdo->prepare(
            "SELECT FLOOR((UNIX_TIMESTAMP(created_at) + :d1) / 86400) AS dia,
                    COUNT(*)                                            AS pedidos,
                    SUM(estado_pago = 'aprobado')                       AS aprobados,
                    COALESCE(SUM(CASE WHEN estado_pago = 'aprobado' THEN total END), 0) AS cobrado
               FROM pedidos
              WHERE created_at >= FROM_UNIXTIME(:desde) AND estado <> 'cancelado'
           GROUP BY dia"
        );
        $st->execute([':d1' => $desfase, ':desde' => $desde * 86400 - $desfase]);
        $porDia = [];
        foreach ($st->fetchAll() as $f) {
            $porDia[(int)$f['dia']] = $f;
        }

        $vacio    = ['pedidos' => 0, 'aprobados' => 0, 'cobrado' => 0.0];
        $actual   = $vacio;
        $anterior = $vacio;
        $serie    = [];
        for ($d = $desde; $d <= $hoy; $d++) {
            $f = $porDia[$d] ?? null;
            $fila = [
                'pedidos'   => (int)($f['pedidos'] ?? 0),
                'aprobados' => (int)($f['aprobados'] ?? 0),
                'cobrado'   => (float)($f['cobrado'] ?? 0),
            ];
            $destino = $d >= $inicio ? 'actual' : 'anterior';
            foreach ($fila as $k => $v) {
                ${$destino}[$k] += $v;
            }
            if ($d >= $inicio) {
                $serie[] = ['dia' => gmdate('Y-m-d', $d * 86400)] + $fila;
            }
        }
        return ['serie' => $serie, 'actual' => $actual, 'anterior' => $anterior];
    }

    /** Clientes que crearon su cuenta en el período y en el anterior. */
    public static function clientesNuevos(PDO $pdo, int $dias): array
    {
        $desfase = (int)date('Z');
        $hoy     = (int)floor((time() + $desfase) / 86400);
        $inicio  = ($hoy - $dias + 1) * 86400 - $desfase;
        $st = $pdo->prepare(
            "SELECT COALESCE(SUM(u.created_at >= FROM_UNIXTIME(?)), 0) AS actual,
                    COALESCE(SUM(u.created_at <  FROM_UNIXTIME(?)), 0) AS anterior
               FROM usuarios u JOIN roles r ON r.id = u.rol_id
              WHERE r.codigo = 'cliente' AND u.created_at >= FROM_UNIXTIME(?)"
        );
        $st->execute([$inicio, $inicio, $inicio - $dias * 86400]);
        $f = $st->fetch() ?: [];
        return ['actual' => (int)($f['actual'] ?? 0), 'anterior' => (int)($f['anterior'] ?? 0)];
    }

    /**
     * Los arreglos más pedidos del período, por unidades.
     *
     * @return list<array{nombre: string, unidades: int, importe: float}>
     */
    public static function masVendidos(PDO $pdo, int $dias, int $limite = 5): array
    {
        $st = $pdo->prepare(
            "SELECT MAX(pi.nombre) AS nombre, SUM(pi.cantidad) AS unidades, SUM(pi.subtotal) AS importe
               FROM pedido_items pi
               JOIN pedidos p ON p.id = pi.pedido_id
              WHERE p.created_at >= FROM_UNIXTIME(?) AND p.estado <> 'cancelado'
           GROUP BY COALESCE(CAST(pi.producto_id AS CHAR), pi.nombre)
           ORDER BY unidades DESC, importe DESC
              LIMIT " . max(1, min(20, $limite))
        );
        $st->execute([self::inicioPeriodo($dias)]);
        return array_map(fn($f) => [
            'nombre'   => (string)$f['nombre'],
            'unidades' => (int)$f['unidades'],
            'importe'  => (float)$f['importe'],
        ], $st->fetchAll());
    }

    /**
     * Pedidos del período agrupados por estado, del más frecuente al menos.
     *
     * @return list<array{codigo: string, cantidad: int}>
     */
    public static function porEstado(PDO $pdo, int $dias): array
    {
        $st = $pdo->prepare(
            "SELECT estado AS codigo, COUNT(*) AS cantidad FROM pedidos
              WHERE created_at >= FROM_UNIXTIME(?)
           GROUP BY estado ORDER BY cantidad DESC"
        );
        $st->execute([self::inicioPeriodo($dias)]);
        return array_map(fn($f) => ['codigo' => (string)$f['codigo'], 'cantidad' => (int)$f['cantidad']], $st->fetchAll());
    }

    /**
     * Variación porcentual frente al período anterior, o null si no hay base
     * con la que comparar (pasar de 0 a algo no es «+∞ %»).
     */
    public static function variacion(float $actual, float $anterior): ?float
    {
        if ($anterior <= 0) {
            return null;
        }
        return ($actual - $anterior) / $anterior * 100;
    }

    /** Instante (segundos) en que empieza el período, en la hora de la tienda. */
    private static function inicioPeriodo(int $dias): int
    {
        $desfase = (int)date('Z');
        $hoy     = (int)floor((time() + $desfase) / 86400);
        return ($hoy - $dias + 1) * 86400 - $desfase;
    }
}
