<?php
/**
 * Zonas de envío y enlaces de ubicación.
 *
 * El costo del envío depende del destino: no es lo mismo cruzar Managua que
 * salir de la ciudad. Cada zona lleva su precio y el panel las administra.
 *
 * El precio SIEMPRE se lee de la base al calcular el total y al crear el
 * pedido. Lo que llega del formulario es solo el identificador de la zona.
 */

declare(strict_types=1);

final class Envios
{
    /**
     * Servicios de mapas aceptados en el enlace de ubicación.
     *
     * Es una lista cerrada a propósito: ese enlace lo abre el repartidor desde
     * su teléfono, y dejar pasar cualquier dirección convertiría el formulario
     * del pedido en una forma cómoda de colarle a alguien del equipo un enlace
     * a donde sea. Quien use otra aplicación puede pegar las coordenadas.
     */
    private const DOMINIOS_MAPA = [
        'google.com', 'www.google.com', 'maps.google.com', 'goo.gl',
        'maps.app.goo.gl', 'maps.apple.com', 'waze.com', 'www.waze.com',
        'ul.waze.com', 'openstreetmap.org', 'www.openstreetmap.org',
        'osm.org', 'what3words.com', 'w3w.co',
    ];

    /** Zonas activas, agrupadas en «dentro de Managua» y «fuera». */
    public static function zonas(PDO $pdo): array
    {
        try {
            return $pdo->query(
                "SELECT * FROM zonas_envio WHERE activo = 1 ORDER BY es_managua DESC, orden, nombre"
            )->fetchAll();
        } catch (PDOException) {
            return []; // la base todavía no tiene la migración 006
        }
    }

    /** Una zona por su id, solo si está activa. */
    public static function zona(PDO $pdo, int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        try {
            $st = $pdo->prepare("SELECT * FROM zonas_envio WHERE id = ? AND activo = 1");
            $st->execute([$id]);
            return $st->fetch() ?: null;
        } catch (PDOException) {
            return null;
        }
    }

    /** ¿Hay zonas configuradas? Si no las hay, se usa el costo global de siempre. */
    public static function hayZonas(PDO $pdo): bool
    {
        return self::zonas($pdo) !== [];
    }

    /**
     * Costo del envío para una zona y un subtotal.
     *
     * El umbral de envío gratis se aplica por igual a todas las zonas: es una
     * promoción del negocio, no una propiedad del destino.
     */
    public static function costo(?array $zona, float $subtotal, string $tipoEntrega = 'domicilio'): float
    {
        if ($tipoEntrega === 'retiro') {
            return 0.0;
        }

        $umbral = (float)Ajustes::numero('envio_gratis_desde', 0);
        if ($umbral > 0 && $subtotal >= $umbral) {
            return 0.0;
        }

        // Sin zona elegida se cae al costo general, que es como funcionaba
        // antes de que existieran las zonas.
        $costo = $zona !== null ? (float)$zona['costo'] : (float)Ajustes::numero('costo_envio', 0);
        return round(max(0, $costo), 2);
    }

    /**
     * Comprueba y normaliza el enlace de ubicación que escribe el cliente.
     *
     * Acepta tres formas: un enlace de un servicio de mapas conocido, unas
     * coordenadas sueltas («12.1364, -86.2514») o nada. Las coordenadas se
     * convierten en un enlace de Google Maps para que el repartidor solo tenga
     * que tocarlo.
     *
     * @return array{ok: bool, url: string, error: string}
     */
    public static function revisarEnlaceMapa(string $valor): array
    {
        $valor = trim($valor);
        if ($valor === '') {
            return ['ok' => true, 'url' => '', 'error' => ''];
        }
        if (mb_strlen($valor) > 500) {
            return ['ok' => false, 'url' => '', 'error' => 'El enlace es demasiado largo.'];
        }

        // Coordenadas sueltas: «12.1364, -86.2514» o «12.1364 -86.2514»
        if (preg_match('/^\s*(-?\d{1,3}(?:\.\d+)?)\s*[,\s]\s*(-?\d{1,3}(?:\.\d+)?)\s*$/', $valor, $m)) {
            $lat = (float)$m[1];
            $lon = (float)$m[2];
            if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
                return ['ok' => false, 'url' => '', 'error' => 'Esas coordenadas están fuera de rango.'];
            }
            return ['ok' => true, 'error' => '',
                    'url' => 'https://www.google.com/maps/search/?api=1&query=' . $lat . ',' . $lon];
        }

        if (!preg_match('#^https?://#i', $valor)) {
            return ['ok' => false, 'url' => '',
                    'error' => 'Pega el enlace completo (empieza por https://) o las coordenadas.'];
        }
        if (!filter_var($valor, FILTER_VALIDATE_URL)) {
            return ['ok' => false, 'url' => '', 'error' => 'Ese enlace no es válido.'];
        }

        $host = mb_strtolower((string)parse_url($valor, PHP_URL_HOST));
        if ($host === '' || !in_array($host, self::DOMINIOS_MAPA, true)) {
            return ['ok' => false, 'url' => '',
                    'error' => 'Solo aceptamos enlaces de Google Maps, Waze, Apple Maps, '
                             . 'OpenStreetMap o what3words. También puedes pegar las coordenadas.'];
        }

        return ['ok' => true, 'url' => $valor, 'error' => ''];
    }

    /** Nombre del servicio del enlace, para mostrarlo junto al botón. */
    public static function servicioMapa(string $url): string
    {
        $host = mb_strtolower((string)parse_url($url, PHP_URL_HOST));
        return match (true) {
            str_contains($host, 'waze')          => 'Waze',
            str_contains($host, 'apple')         => 'Apple Maps',
            str_contains($host, 'openstreetmap'),
            str_contains($host, 'osm.org')       => 'OpenStreetMap',
            str_contains($host, 'what3words'),
            str_contains($host, 'w3w.co')        => 'what3words',
            str_contains($host, 'google'),
            str_contains($host, 'goo.gl')        => 'Google Maps',
            default                              => 'Mapa',
        };
    }

    // -----------------------------------------------------------------
    // Libreta de direcciones del cliente
    // -----------------------------------------------------------------

    /** Direcciones guardadas de un usuario. */
    public static function direcciones(PDO $pdo, int $usuarioId): array
    {
        if ($usuarioId <= 0) {
            return [];
        }
        try {
            $st = $pdo->prepare(
                "SELECT d.*, z.nombre AS zona_nombre, z.costo AS zona_costo
                   FROM direcciones_usuario d
              LEFT JOIN zonas_envio z ON z.id = d.zona_envio_id
                  WHERE d.usuario_id = ?
               ORDER BY d.predeterminada DESC, d.updated_at DESC
                  LIMIT 10"
            );
            $st->execute([$usuarioId]);
            return $st->fetchAll();
        } catch (PDOException) {
            return [];
        }
    }

    /**
     * Guarda una dirección en la libreta del cliente.
     *
     * Si ya existe una igual (misma dirección y misma zona) se actualiza en vez
     * de acumular duplicados: al pedir varias veces al mismo sitio, la lista se
     * llenaría de copias.
     */
    public static function guardarDireccion(PDO $pdo, int $usuarioId, array $datos): void
    {
        if ($usuarioId <= 0 || trim((string)$datos['direccion']) === '') {
            return;
        }

        try {
            $st = $pdo->prepare(
                "SELECT id FROM direcciones_usuario
                  WHERE usuario_id = ? AND direccion = ?
                    AND (zona_envio_id <=> ?)
                  LIMIT 1"
            );
            $st->execute([$usuarioId, $datos['direccion'], $datos['zona_envio_id'] ?: null]);
            $id = $st->fetchColumn();

            if ($id !== false) {
                $pdo->prepare(
                    "UPDATE direcciones_usuario
                        SET etiqueta = ?, nombre_recibe = ?, telefono = ?, referencia = ?, mapa_url = ?
                      WHERE id = ?"
                )->execute([
                    $datos['etiqueta'], $datos['nombre_recibe'], $datos['telefono'],
                    $datos['referencia'], $datos['mapa_url'], $id,
                ]);
                return;
            }

            // La primera dirección que se guarda queda como predeterminada.
            $tiene = $pdo->prepare("SELECT COUNT(*) FROM direcciones_usuario WHERE usuario_id = ?");
            $tiene->execute([$usuarioId]);
            $primera = (int)$tiene->fetchColumn() === 0 ? 1 : 0;

            $pdo->prepare(
                "INSERT INTO direcciones_usuario
                    (usuario_id, etiqueta, nombre_recibe, telefono, direccion, referencia,
                     mapa_url, zona_envio_id, predeterminada)
                 VALUES (?,?,?,?,?,?,?,?,?)"
            )->execute([
                $usuarioId, $datos['etiqueta'], $datos['nombre_recibe'], $datos['telefono'],
                $datos['direccion'], $datos['referencia'], $datos['mapa_url'],
                $datos['zona_envio_id'] ?: null, $primera,
            ]);
        } catch (PDOException $ex) {
            // No poder guardar la dirección no debe impedir el pedido.
            error_log('Flowers Anto — guardar dirección: ' . $ex->getMessage());
        }
    }

    /** Borra una dirección, comprobando que sea de quien la pide. */
    public static function borrarDireccion(PDO $pdo, int $usuarioId, int $direccionId): bool
    {
        $st = $pdo->prepare("DELETE FROM direcciones_usuario WHERE id = ? AND usuario_id = ?");
        $st->execute([$direccionId, $usuarioId]);
        return $st->rowCount() > 0;
    }
}
