<?php
/**
 * Propuestas del asistente del panel: nada cambia sin una persona.
 *
 *   IA propone → se guarda el antes y el después → la persona lo ve y confirma
 *   → se comprueba otra vez el permiso → se comprueba que el dato no cambió
 *   entre tanto → se ejecuta con la lógica de siempre → auditoría
 *
 * Reglas:
 *   · Solo quien pidió la propuesta puede confirmarla o cancelarla.
 *   · Caduca a los 15 minutos: una propuesta vieja se basa en datos viejos.
 *   · El permiso se exige al proponer y otra vez al confirmar; si a la
 *     persona le quitaron el permiso entre medias, no se ejecuta.
 *   · Si el dato cambió desde la propuesta (otro empleado tocó el precio, el
 *     pedido avanzó de estado), no se ejecuta: habría que volver a proponer.
 *   · Una propuesta se ejecuta una sola vez: el paso a «ejecutada» se decide
 *     en la misma sentencia que comprueba que seguía «pendiente».
 *   · No se borran productos ni se tocan configuraciones desde aquí: borrar es
 *     irreversible y se hace a mano en el panel. Ocultar sí, porque se deshace.
 */

declare(strict_types=1);

final class IaPropuestas
{
    public const MINUTOS = 15;

    /** Tipos que existen y el permiso que exige cada uno (salvo el de pedidos, que depende). */
    public const TIPOS = [
        'precio'      => 'productos.editar',
        'descuento'   => 'productos.editar',
        'stock'       => 'productos.editar',
        'publicacion' => 'productos.editar',
        'descripcion' => 'productos.editar',
        'estado'      => 'pedidos.editar',
    ];

    /**
     * Guarda una propuesta y devuelve lo que la interfaz necesita para
     * enseñarla. El «antes» se lee aquí, de la base, no de lo que diga el
     * modelo.
     */
    public static function crear(
        PDO $pdo, string $tipo, string $permiso, string $recursoTipo, string $recursoId,
        array $antes, array $despues, string $resumen
    ): array {
        $uid = Auth::id();
        if (!$uid || !isset(self::TIPOS[$tipo])) {
            throw new IaHerramientaError('No se pudo preparar el cambio.', 'denegado');
        }
        // Una sola propuesta pendiente por recurso y persona: la nueva
        // sustituye a la anterior, así no quedan dos cambios contradictorios.
        $pdo->prepare(
            "UPDATE ai_pending_actions SET estado = 'cancelada', resuelta_en = NOW(), resultado = 'Sustituida por otra propuesta'
              WHERE usuario_id = ? AND recurso_tipo = ? AND recurso_id = ? AND estado = 'pendiente'"
        )->execute([$uid, $recursoTipo, $recursoId]);

        $pdo->prepare(
            "INSERT INTO ai_pending_actions
                (usuario_id, tipo, permiso, recurso_tipo, recurso_id, antes, despues, resumen, expira_en)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW() + INTERVAL " . self::MINUTOS . " MINUTE)"
        )->execute([
            $uid, $tipo, $permiso, $recursoTipo, $recursoId,
            json_encode($antes, JSON_UNESCAPED_UNICODE), json_encode($despues, JSON_UNESCAPED_UNICODE),
            mb_substr($resumen, 0, 255),
        ]);
        $id = (int)$pdo->lastInsertId();

        IaRegistro::anotar($pdo, 'admin', 'propuesta', [
            'herramienta' => 'proponer_' . $tipo, 'recurso_tipo' => $recursoTipo, 'recurso_id' => $recursoId,
            'estado' => 'propuesta', 'detalle' => mb_substr($resumen, 0, 255),
        ]);

        return ['id' => $id, 'tipo' => $tipo, 'resumen' => $resumen,
                'antes' => $antes, 'despues' => $despues, 'caduca_en_minutos' => self::MINUTOS];
    }

    /** @return array{ok: bool, mensaje: string} */
    public static function cancelar(PDO $pdo, int $id): array
    {
        $st = $pdo->prepare(
            "UPDATE ai_pending_actions SET estado = 'cancelada', resuelta_en = NOW(), resultado = 'Cancelada por la persona'
              WHERE id = ? AND usuario_id = ? AND estado = 'pendiente'"
        );
        $st->execute([$id, Auth::id()]);
        if ($st->rowCount() !== 1) {
            return ['ok' => false, 'mensaje' => 'Esa propuesta ya no está pendiente.'];
        }
        IaRegistro::anotar($pdo, 'admin', 'propuesta', ['recurso_id' => (string)$id, 'estado' => 'cancelada']);
        return ['ok' => true, 'mensaje' => 'Cambio descartado. No se tocó nada.'];
    }

    /** @return array{ok: bool, mensaje: string} */
    public static function confirmar(PDO $pdo, int $id): array
    {
        // La caducidad se decide en SQL, con el reloj de la base que escribió
        // `expira_en`. PHP y MySQL pueden estar en zonas distintas (en local,
        // Managua y UTC: seis horas), y comparar con strtotime() daba por
        // vigente una propuesta vencida.
        $st = $pdo->prepare("SELECT *, expira_en < NOW() AS vencida FROM ai_pending_actions WHERE id = ? AND usuario_id = ?");
        $st->execute([$id, Auth::id()]);
        $p = $st->fetch();
        if (!$p) {
            return ['ok' => false, 'mensaje' => 'No encontré esa propuesta.'];
        }
        if ($p['estado'] !== 'pendiente') {
            return ['ok' => false, 'mensaje' => 'Esa propuesta ya se resolvió antes.'];
        }
        if ((int)$p['vencida'] === 1) {
            self::cerrar($pdo, $id, 'caducada', 'Caducó sin confirmar');
            return ['ok' => false, 'mensaje' => 'La propuesta caducó. Pídele al asistente que la prepare otra vez con los datos de ahora.'];
        }
        // El permiso de hoy, no el de hace diez minutos.
        if (!Rbac::puede((string)$p['permiso'])) {
            Auditoria::denegado($pdo, (string)$p['permiso']);
            self::cerrar($pdo, $id, 'fallida', 'Sin permiso al confirmar');
            IaRegistro::anotar($pdo, 'admin', 'confirmar', ['recurso_id' => (string)$id, 'estado' => 'denegado',
                                                           'detalle' => 'sin permiso ' . $p['permiso']]);
            return ['ok' => false, 'mensaje' => 'Ya no tienes permiso para este cambio.'];
        }

        $antes   = json_decode((string)$p['antes'], true) ?: [];
        $despues = json_decode((string)$p['despues'], true) ?: [];

        // Se reclama la propuesta antes de ejecutarla: si dos clics llegan a
        // la vez, solo uno la encuentra «pendiente».
        $reclamo = $pdo->prepare(
            "UPDATE ai_pending_actions SET estado = 'ejecutada', resuelta_en = NOW()
              WHERE id = ? AND estado = 'pendiente'"
        );
        $reclamo->execute([$id]);
        if ($reclamo->rowCount() !== 1) {
            return ['ok' => false, 'mensaje' => 'Esa propuesta ya se resolvió antes.'];
        }

        try {
            $mensaje = $p['recurso_tipo'] === 'pedido'
                ? self::ejecutarPedido($pdo, (string)$p['recurso_id'], $antes, $despues)
                : self::ejecutarProducto($pdo, (string)$p['tipo'], (int)$p['recurso_id'], $antes, $despues);
        } catch (DomainException $e) {
            self::cerrar($pdo, $id, 'fallida', $e->getMessage(), true);
            IaRegistro::anotar($pdo, 'admin', 'confirmar', ['recurso_tipo' => $p['recurso_tipo'],
                'recurso_id' => $p['recurso_id'], 'estado' => 'error', 'detalle' => $e->getMessage()]);
            return ['ok' => false, 'mensaje' => $e->getMessage()];
        } catch (Throwable $e) {
            error_log('Flowers Anto — confirmar propuesta IA: ' . $e->getMessage());
            self::cerrar($pdo, $id, 'fallida', 'Error interno', true);
            return ['ok' => false, 'mensaje' => 'No se pudo aplicar el cambio. No se modificó nada.'];
        }

        $pdo->prepare("UPDATE ai_pending_actions SET resultado = ? WHERE id = ?")
            ->execute([mb_substr($mensaje, 0, 255), $id]);
        IaRegistro::anotar($pdo, 'admin', 'confirmar', [
            'herramienta' => 'proponer_' . $p['tipo'], 'recurso_tipo' => $p['recurso_tipo'],
            'recurso_id' => $p['recurso_id'], 'estado' => 'confirmada', 'detalle' => mb_substr((string)$p['resumen'], 0, 255),
        ]);
        return ['ok' => true, 'mensaje' => $mensaje];
    }

    private static function cerrar(PDO $pdo, int $id, string $estado, string $resultado, bool $yaReclamada = false): void
    {
        $pdo->prepare(
            "UPDATE ai_pending_actions SET estado = ?, resuelta_en = NOW(), resultado = ?
              WHERE id = ?" . ($yaReclamada ? '' : " AND estado = 'pendiente'")
        )->execute([$estado, mb_substr($resultado, 0, 255), $id]);
    }

    private static function ejecutarProducto(PDO $pdo, string $tipo, int $id, array $antes, array $despues): string
    {
        $pdo->beginTransaction();
        try {
            // FOR UPDATE: nadie más cambia este producto mientras se compara y se escribe.
            $st = $pdo->prepare(
                "SELECT id, nombre, precio, precio_usd, descuento_pct, stock, controla_stock, activo, descripcion
                   FROM productos WHERE id = ? FOR UPDATE"
            );
            $st->execute([$id]);
            $prod = $st->fetch();
            if (!$prod) {
                throw new DomainException('Ese producto ya no existe.');
            }

            [$sql, $params, $campo] = match ($tipo) {
                'precio'      => ["UPDATE productos SET precio = ?, precio_usd = ? WHERE id = ?",
                                  [$despues['precio'], $despues['precio_usd'], $id], 'precio'],
                'descuento'   => ["UPDATE productos SET descuento_pct = ? WHERE id = ?", [$despues['descuento_pct'], $id], 'descuento_pct'],
                'stock'       => ["UPDATE productos SET stock = ? WHERE id = ?", [$despues['stock'], $id], 'stock'],
                'publicacion' => ["UPDATE productos SET activo = ? WHERE id = ?", [$despues['activo'], $id], 'activo'],
                'descripcion' => ["UPDATE productos SET descripcion = ? WHERE id = ?", [$despues['descripcion'], $id], 'descripcion'],
                default       => throw new DomainException('Tipo de cambio desconocido.'),
            };

            // ¿Sigue como estaba cuando se propuso?
            $ahora = (string)$prod[$campo];
            $era   = (string)($antes[$campo] ?? '');
            $iguales = is_numeric($ahora) && is_numeric($era) ? abs((float)$ahora - (float)$era) < 0.005 : $ahora === $era;
            if (!$iguales) {
                throw new DomainException('El producto cambió desde que se preparó la propuesta. Pídela otra vez para ver los datos de ahora.');
            }

            $pdo->prepare($sql)->execute($params);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        Auditoria::registrar($pdo, 'editar', 'productos', [
            'recurso_tipo' => 'producto', 'recurso_id' => (string)$id,
            'descripcion'  => 'Cambio confirmado desde el asistente IA: ' . $tipo . ' de «' . $prod['nombre'] . '».',
            'detalles'     => ['antes' => $antes, 'despues' => $despues, 'origen' => 'asistente_ia'],
        ]);
        return 'Listo: se aplicó el cambio en «' . $prod['nombre'] . '».';
    }

    private static function ejecutarPedido(PDO $pdo, string $codigo, array $antes, array $despues): string
    {
        $pedido = Pedidos::porCodigo($pdo, $codigo);
        if (!$pedido) {
            throw new DomainException('Ese pedido ya no existe.');
        }
        if ($pedido['estado'] !== ($antes['estado'] ?? '')) {
            throw new DomainException('El pedido cambió de estado desde la propuesta. Pídela otra vez.');
        }
        // Cancelar exige su propio permiso, igual que en la ficha del pedido.
        $nuevo = (string)($despues['estado'] ?? '');
        if ($nuevo === 'cancelado' && !Rbac::puede('pedidos.cancelar')) {
            throw new DomainException('No tienes permiso para cancelar pedidos.');
        }
        // La misma función que usa la ficha del pedido: valida la
        // transición, devuelve stock al cancelar, avisa al cliente y audita.
        $r = Pedidos::cambiarEstado($pdo, $pedido, $nuevo, (string)($despues['nota'] ?? ''));
        if (!$r['ok']) {
            throw new DomainException($r['error'] ?? 'No se pudo cambiar el estado.');
        }
        return 'Listo: el pedido ' . $codigo . ' quedó «' . Pedidos::estado($pdo, 'pedido', $nuevo)['nombre'] . '».';
    }

    /** Propuestas pendientes de la persona, para volver a pintarlas. */
    public static function pendientes(PDO $pdo): array
    {
        $st = $pdo->prepare(
            "SELECT id, tipo, resumen, antes, despues, expira_en FROM ai_pending_actions
              WHERE usuario_id = ? AND estado = 'pendiente' AND expira_en > NOW() ORDER BY id DESC LIMIT 10"
        );
        $st->execute([Auth::id()]);
        return array_map(fn($p) => [
            'id' => (int)$p['id'], 'tipo' => $p['tipo'], 'resumen' => $p['resumen'],
            'antes' => json_decode((string)$p['antes'], true), 'despues' => json_decode((string)$p['despues'], true),
        ], $st->fetchAll());
    }
}
