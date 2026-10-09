<?php
/**
 * Avisos a muchos clientes a la vez (campañas) y su cola de correos.
 *
 * Crear una campaña es rápido: una fila por cliente en `notificaciones`,
 * insertadas por bloques, y el aviso ya se ve en su cuenta. Los correos van
 * después, en tandas de unos pocos por petición, que el panel pide una tras
 * otra mientras enseña el avance (`procesar`). Así una campaña de cientos de
 * clientes no corta la página a medias ni choca con el límite de envío por
 * minuto del hosting, y si se cierra la pestaña se retoma donde quedó.
 *
 * Cada correo se «reserva» antes de mandarlo subiendo su contador de
 * intentos en la misma sentencia que comprueba el valor anterior: dos
 * pestañas procesando la misma campaña no mandan dos veces el mismo correo.
 *
 * Las promociones por correo solo van a quien no se ha dado de baja, y cada
 * una lleva su enlace para hacerlo. El aviso dentro de la web llega siempre.
 */

declare(strict_types=1);

final class Campanas
{
    public const TIPOS = ['aviso', 'sugerencia', 'advertencia', 'promocion'];
    public const MAX_DESTINATARIOS = 5000;
    public const LOTE      = 8;    // correos por tanda
    public const INTENTOS  = 3;    // un correo que falla tres veces se da por fallido
    private const SEGUNDOS = 20;   // tope de una tanda, lejos del límite de PHP del hosting

    private static ?bool $hay = null;

    /** ¿Está aplicada la migración 028? */
    public static function disponible(PDO $pdo): bool
    {
        if (self::$hay === null) {
            try {
                $pdo->query("SELECT 1 FROM campanas_avisos LIMIT 0");
                $pdo->query("SELECT campana_id, correo_intentos FROM notificaciones LIMIT 0");
                $pdo->query("SELECT acepta_promociones, token_baja FROM usuarios LIMIT 0");
                self::$hay = Avisos::disponible($pdo);
            } catch (PDOException) {
                self::$hay = false;
            }
        }
        return self::$hay;
    }

    /**
     * Crea la campaña y los avisos. Solo a clientes con la cuenta activa.
     *
     * @param int[] $ids
     * @return array{ok: bool, error?: string, id?: int, destinatarios?: int, con_correo?: int, de_baja?: int}
     */
    public static function crear(PDO $pdo, array $ids, string $tipo, string $titulo, string $mensaje, bool $porCorreo): array
    {
        if (!self::disponible($pdo)) {
            return ['ok' => false, 'error' => 'Falta aplicar la migración 028 en Base de datos.'];
        }
        if (!in_array($tipo, self::TIPOS, true)) {
            return ['ok' => false, 'error' => 'Elige el tipo de aviso.'];
        }
        $titulo  = mb_substr(trim($titulo), 0, 120);
        $mensaje = mb_substr(trim($mensaje), 0, 2000);
        if (mb_strlen($titulo) < 3 || mb_strlen($mensaje) < 3) {
            return ['ok' => false, 'error' => 'Escribe un título y un mensaje.'];
        }
        $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0))),
                           0, self::MAX_DESTINATARIOS);
        if (!$ids) {
            return ['ok' => false, 'error' => 'No hay clientes seleccionados.'];
        }
        $autor = Auth::id();
        if (!limitar($pdo, 'campanas-panel:' . (int)$autor, 6, 3600)) {
            return ['ok' => false, 'error' => 'Ya se mandaron varios avisos masivos esta hora. Espera un rato.'];
        }

        // Solo clientes activos, y de cada uno lo justo para decidir el correo.
        $clientes = [];
        foreach (array_chunk($ids, 500) as $bloque) {
            $huecos = implode(',', array_fill(0, count($bloque), '?'));
            $st = $pdo->prepare(
                "SELECT u.id, u.email, u.acepta_promociones FROM usuarios u JOIN roles r ON r.id = u.rol_id
                  WHERE u.id IN ($huecos) AND r.codigo = 'cliente' AND u.activo = 1"
            );
            $st->execute($bloque);
            array_push($clientes, ...$st->fetchAll());
        }
        if (!$clientes) {
            return ['ok' => false, 'error' => 'Ninguno de los seleccionados es un cliente con la cuenta activa.'];
        }

        $conCorreo = 0;
        $deBaja    = 0;
        try {
            $pdo->beginTransaction();
            $pdo->prepare(
                "INSERT INTO campanas_avisos (tipo, titulo, mensaje, por_correo, destinatarios, enviado_por)
                 VALUES (?, ?, ?, ?, ?, ?)"
            )->execute([$tipo, $titulo, $mensaje, $porCorreo ? 1 : 0, count($clientes), $autor]);
            $campanaId = (int)$pdo->lastInsertId();

            foreach (array_chunk($clientes, 200) as $bloque) {
                $filas  = [];
                $params = [];
                foreach ($bloque as $c) {
                    $correo = $porCorreo && trim((string)$c['email']) !== '';
                    if ($correo && $tipo === 'promocion' && (int)$c['acepta_promociones'] !== 1) {
                        $correo = false;
                        $deBaja++;
                    }
                    $conCorreo += $correo ? 1 : 0;
                    $filas[] = '(?, ?, ?, ?, ?, ?, ?)';
                    array_push($params, (int)$c['id'], $tipo, $titulo, $mensaje, $campanaId, $autor, $correo ? 1 : 0);
                }
                $pdo->prepare(
                    "INSERT INTO notificaciones (usuario_id, tipo, titulo, mensaje, campana_id, enviado_por, por_correo)
                     VALUES " . implode(',', $filas)
                )->execute($params);
            }
            $pdo->prepare(
                "UPDATE campanas_avisos SET con_correo = ?, terminada_en = IF(? = 0, NOW(), NULL) WHERE id = ?"
            )->execute([$conCorreo, $conCorreo, $campanaId]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Flowers Anto — campaña de avisos: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'No se pudo crear el envío. Inténtalo otra vez.'];
        }

        Auditoria::registrar($pdo, 'enviar_campana', 'usuarios', [
            'recurso_tipo' => 'campana', 'recurso_id' => (string)$campanaId,
            'descripcion'  => (Avisos::TIPOS[$tipo][0] ?? 'Aviso') . ' a ' . count($clientes) . ' clientes: «'
                            . mb_substr($titulo, 0, 80) . '»' . ($conCorreo ? " ($conCorreo por correo)" : ''),
        ]);

        return ['ok' => true, 'id' => $campanaId, 'destinatarios' => count($clientes),
                'con_correo' => $conCorreo, 'de_baja' => $deBaja];
    }

    /** Avance de una campaña, o null si no existe. */
    public static function estado(PDO $pdo, int $id): ?array
    {
        $st = $pdo->prepare(
            "SELECT c.*,
                    SUM(n.por_correo = 1 AND n.correo_enviado = 1) AS enviados,
                    SUM(n.por_correo = 1 AND n.correo_enviado = 0 AND n.correo_intentos >= " . self::INTENTOS . ") AS fallidos,
                    SUM(n.por_correo = 1 AND n.correo_enviado = 0 AND n.correo_intentos < " . self::INTENTOS . ") AS pendientes,
                    SUM(n.leida_en IS NOT NULL) AS leidos
               FROM campanas_avisos c LEFT JOIN notificaciones n ON n.campana_id = c.id
              WHERE c.id = ? GROUP BY c.id"
        );
        $st->execute([$id]);
        $c = $st->fetch();
        if (!$c) {
            return null;
        }
        foreach (['enviados', 'fallidos', 'pendientes', 'leidos', 'destinatarios', 'con_correo'] as $k) {
            $c[$k] = (int)$c[$k];
        }
        return $c;
    }

    /** Las últimas campañas, con su avance. */
    public static function recientes(PDO $pdo, int $limite = 5): array
    {
        if (!self::disponible($pdo)) {
            return [];
        }
        $ids = $pdo->query("SELECT id FROM campanas_avisos ORDER BY id DESC LIMIT " . max(1, $limite))
                   ->fetchAll(PDO::FETCH_COLUMN);
        return array_values(array_filter(array_map(fn($id) => self::estado($pdo, (int)$id), $ids)));
    }

    /**
     * Manda la siguiente tanda de correos de una campaña.
     *
     * @return array|null el estado después de la tanda
     */
    public static function procesar(PDO $pdo, int $campanaId, int $lote = self::LOTE): ?array
    {
        $inicio = microtime(true);
        $st = $pdo->prepare(
            "SELECT n.id, n.tipo, n.titulo, n.mensaje, n.correo_intentos,
                    u.id AS usuario_id, u.nombre, u.email, u.token_baja, u.acepta_promociones
               FROM notificaciones n JOIN usuarios u ON u.id = n.usuario_id
              WHERE n.campana_id = ? AND n.por_correo = 1 AND n.correo_enviado = 0
                AND n.correo_intentos < " . self::INTENTOS . "
              ORDER BY n.id LIMIT " . max(1, min(25, $lote))
        );
        $st->execute([$campanaId]);
        $reservar = $pdo->prepare(
            "UPDATE notificaciones SET correo_intentos = correo_intentos + 1
              WHERE id = ? AND correo_enviado = 0 AND correo_intentos = ?"
        );
        $hecho = $pdo->prepare("UPDATE notificaciones SET correo_enviado = 1 WHERE id = ?");
        $sinCorreo = $pdo->prepare("UPDATE notificaciones SET por_correo = 0 WHERE id = ?");

        foreach ($st->fetchAll() as $n) {
            if (microtime(true) - $inicio > self::SEGUNDOS) {
                break;
            }
            $reservar->execute([$n['id'], $n['correo_intentos']]);
            if ($reservar->rowCount() !== 1) {
                continue; // otra pestaña ya lo tiene
            }
            $promocion = $n['tipo'] === 'promocion';
            // Se dio de baja después de crearse la campaña: el aviso queda en
            // su cuenta, pero no se le manda el correo.
            if ($promocion && (int)$n['acepta_promociones'] !== 1) {
                $sinCorreo->execute([$n['id']]);
                continue;
            }
            $baja = $promocion ? url_absoluta('cuenta/baja-promociones.php?t=' . self::token($pdo, $n)) : '';
            $html = Avisos::correoHtml(['nombre' => $n['nombre']], (string)$n['tipo'], (string)$n['titulo'],
                                       (string)$n['mensaje'], null, $baja);
            if (Correo::enviar((string)$n['email'], (string)$n['titulo'], $html)) {
                $hecho->execute([$n['id']]);
            }
        }

        $estado = self::estado($pdo, $campanaId);
        if ($estado && $estado['pendientes'] === 0 && $estado['terminada_en'] === null) {
            $pdo->prepare("UPDATE campanas_avisos SET terminada_en = NOW() WHERE id = ? AND terminada_en IS NULL")
                ->execute([$campanaId]);
            $estado['terminada_en'] = date('Y-m-d H:i:s');
        }
        return $estado;
    }

    /** Token de baja del cliente; se crea la primera vez que hace falta. */
    private static function token(PDO $pdo, array $fila): string
    {
        if (!empty($fila['token_baja'])) {
            return (string)$fila['token_baja'];
        }
        $nuevo = bin2hex(random_bytes(16));
        $pdo->prepare("UPDATE usuarios SET token_baja = ? WHERE id = ? AND token_baja IS NULL")
            ->execute([$nuevo, $fila['usuario_id']]);
        $st = $pdo->prepare("SELECT token_baja FROM usuarios WHERE id = ?");
        $st->execute([$fila['usuario_id']]);
        return (string)$st->fetchColumn();
    }

    /** El cliente de un enlace de baja, o null si el enlace no vale. */
    public static function porToken(PDO $pdo, string $token): ?array
    {
        if (!self::disponible($pdo) || !preg_match('/^[a-f0-9]{32}$/', $token)) {
            return null;
        }
        $st = $pdo->prepare("SELECT id, nombre, email, acepta_promociones FROM usuarios WHERE token_baja = ?");
        $st->execute([$token]);
        return $st->fetch() ?: null;
    }

    public static function cambiarPromociones(PDO $pdo, int $usuarioId, bool $acepta): void
    {
        $pdo->prepare("UPDATE usuarios SET acepta_promociones = ? WHERE id = ?")->execute([$acepta ? 1 : 0, $usuarioId]);
    }
}
