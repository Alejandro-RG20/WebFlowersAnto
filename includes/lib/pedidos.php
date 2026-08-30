<?php
/**
 * Pedidos: creación, estados, comprobantes y notificaciones.
 *
 * Regla del módulo: un pago NUNCA pasa a aprobado por el hecho de que el
 * cliente suba una imagen. Subir el comprobante solo mueve el pedido a
 * «pago en revisión». La aprobación es una acción explícita de alguien con el
 * permiso `pagos.aprobar`, y queda registrada en el historial y en la auditoría.
 */

declare(strict_types=1);

final class Pedidos
{
    // Estados del pedido
    public const PENDIENTE     = 'pendiente';
    public const PAGO_REVISION = 'pago_revision';
    public const CONFIRMADO    = 'confirmado';
    public const PREPARACION   = 'preparacion';
    public const LISTO         = 'listo';
    public const ENVIADO       = 'enviado';
    public const COMPLETADO    = 'completado';
    public const CANCELADO     = 'cancelado';

    // Estados del pago
    public const PAGO_NO_APLICA   = 'no_aplica';
    public const PAGO_PENDIENTE   = 'pendiente_comprobante';
    public const PAGO_RECIBIDO    = 'comprobante_recibido';
    public const PAGO_EN_REVISION = 'en_revision';
    public const PAGO_APROBADO    = 'aprobado';
    public const PAGO_RECHAZADO   = 'rechazado';

    /** Transiciones permitidas del estado del pedido. */
    private const FLUJO = [
        self::PENDIENTE     => [self::PAGO_REVISION, self::CONFIRMADO, self::CANCELADO],
        self::PAGO_REVISION => [self::CONFIRMADO, self::PENDIENTE, self::CANCELADO],
        self::CONFIRMADO    => [self::PREPARACION, self::CANCELADO],
        self::PREPARACION   => [self::LISTO, self::CANCELADO],
        self::LISTO         => [self::ENVIADO, self::COMPLETADO, self::CANCELADO],
        self::ENVIADO       => [self::COMPLETADO, self::CANCELADO],
        self::COMPLETADO    => [],
        self::CANCELADO     => [],
    ];

    // -----------------------------------------------------------------
    // Catálogo de estados
    // -----------------------------------------------------------------

    /** @return array<string,array> código => fila del estado */
    public static function estados(PDO $pdo, string $tipo = 'pedido'): array
    {
        static $cache = [];
        if (isset($cache[$tipo])) {
            return $cache[$tipo];
        }
        $st = $pdo->prepare("SELECT * FROM estados_pedido WHERE tipo = ? AND activo = 1 ORDER BY orden, id");
        $st->execute([$tipo]);
        return $cache[$tipo] = array_column($st->fetchAll(), null, 'codigo');
    }

    public static function estado(PDO $pdo, string $tipo, string $codigo): array
    {
        return self::estados($pdo, $tipo)[$codigo]
            ?? ['codigo' => $codigo, 'nombre' => ucfirst(str_replace('_', ' ', $codigo)),
                'color' => '#8A7A7D', 'descripcion' => '', 'es_final' => 0];
    }

    /** Estados a los que puede pasar un pedido desde el actual. */
    public static function siguientes(string $estadoActual): array
    {
        return self::FLUJO[$estadoActual] ?? [];
    }

    // -----------------------------------------------------------------
    // Creación
    // -----------------------------------------------------------------

    /**
     * Crea un pedido a partir del carrito.
     *
     * @param array $datos  cliente_*, entrega_*, dedicatoria, notas_cliente,
     *                      metodo_pago, canal
     * @return array{ok: bool, error?: string, pedido?: array}
     */
    public static function crearDesdeCarrito(PDO $pdo, array $datos): array
    {
        // La zona se relee de la base: del formulario solo llega su id, nunca
        // el precio. El envío se calcula aquí, no se acepta del navegador.
        $zona    = Envios::zona($pdo, (int)($datos['zona_envio_id'] ?? 0));
        $entrega = (string)($datos['entrega_tipo'] ?? 'domicilio');

        // Lo mismo con el cupón: del formulario llega el código y se vuelve a
        // validar entero contra la base —vigencia, mínimo de compra, usos—
        // antes de aplicar nada. Si dejó de ser válido mientras el cliente
        // llenaba el formulario, el pedido se registra sin descuento y con su
        // total correcto, en vez de fallar y perder la venta.
        $cupon       = null;
        $avisoCupon  = '';
        $codigoCupon = Cupones::limpiar((string)($datos['cupon'] ?? ''));
        if ($codigoCupon !== '') {
            $base     = Carrito::detalle($pdo, $zona, $entrega);
            $revision = Cupones::revisar(
                $pdo, $codigoCupon, $base['subtotal'], $base['envio'],
                Auth::id(), (string)($datos['cliente_email'] ?? '')
            );
            if ($revision['ok']) {
                $cupon = $revision['cupon'];
            } else {
                // Se avisa a quien llama para que se lo diga al cliente. Un
                // descuento que desaparece sin explicación entre la pantalla
                // anterior y el correo de confirmación es una reclamación
                // segura.
                $avisoCupon = 'No pudimos aplicar el cupón ' . $codigoCupon . ': ' . $revision['error'];
            }
        }

        $detalle = Carrito::detalle($pdo, $zona, $entrega, $cupon);
        if (!$detalle['items']) {
            return ['ok' => false, 'error' => 'Tu carrito está vacío.'];
        }
        if ($detalle['avisos']) {
            // Algo cambió de disponibilidad mientras el cliente llenaba el formulario.
            return ['ok' => false, 'error' => implode(' ', $detalle['avisos']) .
                    ' Revisa el resumen antes de confirmar.'];
        }

        $metodo = in_array($datos['metodo_pago'] ?? '', ['transferencia', 'efectivo', 'whatsapp'], true)
            ? $datos['metodo_pago'] : 'transferencia';

        $estadoPago = $metodo === 'transferencia' ? self::PAGO_PENDIENTE : self::PAGO_NO_APLICA;

        $pdo->beginTransaction();
        try {
            $codigo = self::generarCodigo($pdo);
            $token  = bin2hex(random_bytes(16));

            $st = $pdo->prepare(
                "INSERT INTO pedidos
                    (codigo, usuario_id, cliente_nombre, cliente_email, cliente_telefono,
                     entrega_tipo, entrega_nombre, entrega_telefono, entrega_direccion,
                     entrega_ciudad, entrega_referencia, entrega_fecha, entrega_franja,
                     dedicatoria, notas_cliente, canal, metodo_pago, estado, estado_pago,
                     moneda, subtotal, envio, descuento, total, token_seguimiento, ip,
                     zona_envio_id, zona_envio_nombre, entrega_mapa_url,
                     cupon_id, cupon_codigo)
                 VALUES (?,?,?,?,?, ?,?,?,?, ?,?,?,?, ?,?, ?,?,?,?, ?,?,?,?,?, ?,?, ?,?,?, ?,?)"
            );
            $st->execute([
                $codigo,
                Auth::id(),
                $datos['cliente_nombre'],
                $datos['cliente_email'] ?? '',
                $datos['cliente_telefono'],
                $datos['entrega_tipo'] ?? 'domicilio',
                $datos['entrega_nombre'] ?? '',
                $datos['entrega_telefono'] ?? '',
                $datos['entrega_direccion'] ?? '',
                $datos['entrega_ciudad'] ?? '',
                $datos['entrega_referencia'] ?? '',
                $datos['entrega_fecha'] ?? null,
                $datos['entrega_franja'] ?? '',
                $datos['dedicatoria'] ?? '',
                $datos['notas_cliente'] ?? '',
                in_array($datos['canal'] ?? 'web', ['web', 'whatsapp', 'panel'], true) ? $datos['canal'] : 'web',
                $metodo,
                self::PENDIENTE,
                $estadoPago,
                Ajustes::texto('moneda_local', 'C$'),
                $detalle['subtotal'],
                $detalle['envio'],
                $detalle['descuento'],
                $detalle['total'],
                $token,
                ip_cliente(),
                $zona['id'] ?? null,
                mb_substr((string)($zona['nombre'] ?? ($datos['entrega_ciudad'] ?? '')), 0, 100),
                mb_substr((string)($datos['entrega_mapa_url'] ?? ''), 0, 500),
                $cupon['id'] ?? null,
                (string)($cupon['codigo'] ?? ''),
            ]);
            $pedidoId = (int)$pdo->lastInsertId();

            $insItem = $pdo->prepare(
                "INSERT INTO pedido_items
                    (pedido_id, producto_id, nombre, imagen, precio_unitario, cantidad, subtotal)
                 VALUES (?,?,?,?,?,?,?)"
            );
            $bajarStock = $pdo->prepare(
                "UPDATE productos SET stock = GREATEST(0, stock - ?), vendidos = vendidos + ?
                  WHERE id = ? AND controla_stock = 1"
            );

            foreach ($detalle['items'] as $i) {
                $insItem->execute([
                    $pedidoId, $i['producto_id'], $i['nombre'], $i['imagen'],
                    $i['precio'], $i['cantidad'], $i['subtotal'],
                ]);
                $bajarStock->execute([$i['cantidad'], $i['cantidad'], $i['producto_id']]);
            }

            // El canje va dentro de la transacción: si el pedido se cae, el
            // cupón no queda gastado.
            if ($cupon && $detalle['descuento'] > 0) {
                Cupones::registrarUso(
                    $pdo, $cupon, $pedidoId, Auth::id(),
                    (string)($datos['cliente_email'] ?? ''), $detalle['descuento']
                );
            }

            self::anotarHistorial(
                $pdo, $pedidoId, 'pedido', '', self::PENDIENTE,
                'Pedido recibido desde ' . ($datos['canal'] === 'panel' ? 'el panel' : 'la web') . '.'
                . ($cupon ? ' Cupón ' . $cupon['codigo'] . ': −' . dinero($detalle['descuento']) . '.' : ''),
                Auth::id(), Auth::autenticado() ? Auth::nombreCompleto() : 'Cliente'
            );

            $pdo->commit();
        } catch (Throwable $ex) {
            $pdo->rollBack();
            error_log('Flowers Anto — crear pedido: ' . $ex->getMessage());
            return ['ok' => false, 'error' => 'No pudimos registrar el pedido. Vuelve a intentarlo en un momento.'];
        }

        Carrito::vaciar($pdo);

        $pedido = self::porId($pdo, $pedidoId);

        Auditoria::registrar($pdo, 'crear', 'pedidos', [
            'recurso_tipo' => 'pedido',
            'recurso_id'   => (string)$pedidoId,
            'descripcion'  => 'Pedido ' . $codigo . ' por ' . dinero($detalle['total']),
            'detalles'     => ['codigo' => $codigo, 'total' => $detalle['total'], 'metodo' => $metodo],
        ]);

        self::notificarNuevoPedido($pdo, $pedido);
        self::avisarEquipo($pdo, $pedido);

        return ['ok' => true, 'pedido' => $pedido, 'aviso' => $avisoCupon];
    }

    /** Código legible y único: FA-20260829-4F2A */
    private static function generarCodigo(PDO $pdo): string
    {
        $st = $pdo->prepare("SELECT 1 FROM pedidos WHERE codigo = ?");
        for ($i = 0; $i < 12; $i++) {
            $codigo = 'FA-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(2)));
            $st->execute([$codigo]);
            if (!$st->fetchColumn()) {
                return $codigo;
            }
        }
        throw new RuntimeException('No se pudo generar un código de pedido único.');
    }

    // -----------------------------------------------------------------
    // Lectura
    // -----------------------------------------------------------------

    public static function porId(PDO $pdo, int $id): ?array
    {
        $st = $pdo->prepare("SELECT * FROM pedidos WHERE id = ?");
        $st->execute([$id]);
        $p = $st->fetch();
        return $p ? self::completar($pdo, $p) : null;
    }

    public static function porCodigo(PDO $pdo, string $codigo): ?array
    {
        $st = $pdo->prepare("SELECT * FROM pedidos WHERE codigo = ?");
        $st->execute([$codigo]);
        $p = $st->fetch();
        return $p ? self::completar($pdo, $p) : null;
    }

    /** Añade artículos, comprobantes e historial. */
    private static function completar(PDO $pdo, array $pedido): array
    {
        $st = $pdo->prepare("SELECT * FROM pedido_items WHERE pedido_id = ? ORDER BY id");
        $st->execute([$pedido['id']]);
        $pedido['items'] = $st->fetchAll();

        $st = $pdo->prepare(
            "SELECT c.*, u.nombre AS revisor_nombre, u.apellido AS revisor_apellido
               FROM pedido_comprobantes c
          LEFT JOIN usuarios u ON u.id = c.revisado_por
              WHERE c.pedido_id = ? ORDER BY c.subido_en DESC, c.id DESC"
        );
        $st->execute([$pedido['id']]);
        $pedido['comprobantes'] = $st->fetchAll();

        $st = $pdo->prepare("SELECT * FROM pedido_historial WHERE pedido_id = ? ORDER BY created_at, id");
        $st->execute([$pedido['id']]);
        $pedido['historial'] = $st->fetchAll();

        return $pedido;
    }

    /**
     * ¿Puede el visitante actual ver este pedido?
     * Vale si es suyo, si es personal con permiso, o si trae el token del
     * enlace de seguimiento que se le envió por correo.
     */
    public static function puedeVer(array $pedido, ?string $token = null): bool
    {
        if (Rbac::puede('pedidos.ver')) {
            return true;
        }
        $usuarioId = Auth::id();
        if ($usuarioId && (int)$pedido['usuario_id'] === $usuarioId) {
            return true;
        }
        if ($token !== null && $pedido['token_seguimiento'] !== ''
            && hash_equals((string)$pedido['token_seguimiento'], $token)) {
            return true;
        }
        return false;
    }

    /** Enlace de seguimiento para el cliente (funciona sin cuenta). */
    public static function enlaceSeguimiento(array $pedido, bool $absoluto = false): string
    {
        $ruta = 'pedido.php?codigo=' . rawurlencode((string)$pedido['codigo'])
              . '&t=' . rawurlencode((string)$pedido['token_seguimiento']);
        return $absoluto ? url_absoluta($ruta) : url($ruta);
    }

    /** Pedidos de un usuario. */
    public static function deUsuario(PDO $pdo, int $usuarioId, int $limite = 50): array
    {
        $st = $pdo->prepare(
            "SELECT p.*, (SELECT COUNT(*) FROM pedido_items i WHERE i.pedido_id = p.id) AS articulos
               FROM pedidos p WHERE p.usuario_id = ? ORDER BY p.created_at DESC LIMIT $limite"
        );
        $st->execute([$usuarioId]);
        return $st->fetchAll();
    }

    // -----------------------------------------------------------------
    // Cambios de estado
    // -----------------------------------------------------------------

    /**
     * Cambia el estado del pedido validando la transición.
     * @return array{ok: bool, error?: string}
     */
    public static function cambiarEstado(PDO $pdo, array $pedido, string $nuevo, string $nota = ''): array
    {
        $actual = (string)$pedido['estado'];
        if ($nuevo === $actual) {
            return ['ok' => false, 'error' => 'El pedido ya está en ese estado.'];
        }
        if (!isset(self::FLUJO[$nuevo])) {
            return ['ok' => false, 'error' => 'Ese estado no existe.'];
        }
        if (!in_array($nuevo, self::siguientes($actual), true)) {
            $nombreActual = self::estado($pdo, 'pedido', $actual)['nombre'];
            $nombreNuevo  = self::estado($pdo, 'pedido', $nuevo)['nombre'];
            return ['ok' => false, 'error' => "No se puede pasar de «$nombreActual» a «$nombreNuevo»."];
        }
        if ($nuevo === self::CONFIRMADO
            && $pedido['metodo_pago'] === 'transferencia'
            && $pedido['estado_pago'] !== self::PAGO_APROBADO) {
            return ['ok' => false, 'error' => 'Primero hay que aprobar el pago de la transferencia.'];
        }

        $pdo->prepare("UPDATE pedidos SET estado = ? WHERE id = ?")->execute([$nuevo, $pedido['id']]);
        self::anotarHistorial($pdo, (int)$pedido['id'], 'pedido', $actual, $nuevo, $nota);

        // Al cancelar se devuelven al catálogo las unidades reservadas.
        if ($nuevo === self::CANCELADO) {
            self::devolverStock($pdo, (int)$pedido['id']);
        }

        Auditoria::registrar($pdo, 'cambiar_estado', 'pedidos', [
            'recurso_tipo' => 'pedido',
            'recurso_id'   => (string)$pedido['id'],
            'descripcion'  => 'Pedido ' . $pedido['codigo'] . ': ' . $actual . ' → ' . $nuevo,
            'detalles'     => ['nota' => $nota],
        ]);

        self::notificarCambioEstado($pdo, self::porId($pdo, (int)$pedido['id']), $nuevo, $nota);
        return ['ok' => true];
    }

    private static function devolverStock(PDO $pdo, int $pedidoId): void
    {
        $st = $pdo->prepare("SELECT producto_id, cantidad FROM pedido_items WHERE pedido_id = ? AND producto_id IS NOT NULL");
        $st->execute([$pedidoId]);
        $up = $pdo->prepare(
            "UPDATE productos SET stock = stock + ?, vendidos = GREATEST(0, vendidos - ?)
              WHERE id = ? AND controla_stock = 1"
        );
        foreach ($st->fetchAll() as $i) {
            $up->execute([$i['cantidad'], $i['cantidad'], $i['producto_id']]);
        }
    }

    /** Anota una línea en el historial del pedido. */
    public static function anotarHistorial(
        PDO $pdo, int $pedidoId, string $tipo, string $anterior, string $nuevo,
        string $nota = '', ?int $usuarioId = null, ?string $usuarioTexto = null
    ): void {
        $pdo->prepare(
            "INSERT INTO pedido_historial
                (pedido_id, tipo, estado_anterior, estado_nuevo, nota, usuario_id, usuario_texto)
             VALUES (?,?,?,?,?,?,?)"
        )->execute([
            $pedidoId, $tipo, $anterior, $nuevo, mb_substr($nota, 0, 500),
            $usuarioId ?? Auth::id(),
            mb_substr($usuarioTexto ?? (Auth::autenticado() ? Auth::nombreCompleto() : 'Sistema'), 0, 150),
        ]);
    }

    // -----------------------------------------------------------------
    // Pagos
    // -----------------------------------------------------------------

    /**
     * Registra un comprobante recién subido. Mueve el pedido a revisión;
     * en ningún caso lo da por pagado.
     */
    public static function registrarComprobante(PDO $pdo, array $pedido, array $archivo, array $extra = []): array
    {
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                "INSERT INTO pedido_comprobantes
                    (pedido_id, archivo, nombre_original, mime, tamano, hash_sha256, referencia, banco, monto, estado)
                 VALUES (?,?,?,?,?,?,?,?,?, 'recibido')"
            )->execute([
                $pedido['id'], $archivo['nombre'], $archivo['nombre_original'], $archivo['mime'],
                $archivo['tamano'], $archivo['hash'],
                mb_substr((string)($extra['referencia'] ?? ''), 0, 80),
                mb_substr((string)($extra['banco'] ?? ''), 0, 80),
                (float)($extra['monto'] ?? 0),
            ]);

            $pdo->prepare("UPDATE pedidos SET estado_pago = ?, estado = ? WHERE id = ?")
                ->execute([self::PAGO_RECIBIDO, self::PAGO_REVISION, $pedido['id']]);

            self::anotarHistorial(
                $pdo, (int)$pedido['id'], 'pago', (string)$pedido['estado_pago'], self::PAGO_RECIBIDO,
                'El cliente envió un comprobante. Queda pendiente de revisión.',
                Auth::id(), Auth::autenticado() ? Auth::nombreCompleto() : 'Cliente'
            );

            $pdo->commit();
        } catch (Throwable $ex) {
            $pdo->rollBack();
            error_log('Flowers Anto — comprobante: ' . $ex->getMessage());
            return ['ok' => false, 'error' => 'No pudimos guardar el comprobante. Inténtalo otra vez.'];
        }

        Auditoria::registrar($pdo, 'subir_comprobante', 'pedidos', [
            'recurso_tipo' => 'pedido',
            'recurso_id'   => (string)$pedido['id'],
            'descripcion'  => 'Comprobante recibido para el pedido ' . $pedido['codigo'],
        ]);

        $actualizado = self::porId($pdo, (int)$pedido['id']);
        self::notificarComprobante($pdo, $actualizado);
        self::avisarEquipoComprobante($actualizado, $extra);
        return ['ok' => true];
    }

    /** Marca el comprobante como «en revisión» (alguien lo abrió para verificarlo). */
    public static function tomarEnRevision(PDO $pdo, array $pedido): void
    {
        if ($pedido['estado_pago'] !== self::PAGO_RECIBIDO) {
            return;
        }
        $pdo->prepare("UPDATE pedidos SET estado_pago = ? WHERE id = ?")
            ->execute([self::PAGO_EN_REVISION, $pedido['id']]);
        $pdo->prepare(
            "UPDATE pedido_comprobantes SET estado = 'en_revision', revisado_por = ?
              WHERE pedido_id = ? AND estado = 'recibido'"
        )->execute([Auth::id(), $pedido['id']]);

        self::anotarHistorial($pdo, (int)$pedido['id'], 'pago', self::PAGO_RECIBIDO, self::PAGO_EN_REVISION,
            'Comprobante en revisión.');
    }

    /**
     * Aprueba el pago. Es una decisión humana y explícita.
     * @return array{ok: bool, error?: string}
     */
    public static function aprobarPago(PDO $pdo, array $pedido, string $nota = ''): array
    {
        if ($pedido['estado_pago'] === self::PAGO_APROBADO) {
            return ['ok' => false, 'error' => 'Este pago ya estaba aprobado.'];
        }
        if (!in_array($pedido['estado_pago'], [self::PAGO_RECIBIDO, self::PAGO_EN_REVISION, self::PAGO_RECHAZADO], true)) {
            return ['ok' => false, 'error' => 'Todavía no hay ningún comprobante que aprobar.'];
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE pedidos SET estado_pago = ?, estado = ? WHERE id = ?")
                ->execute([self::PAGO_APROBADO, self::CONFIRMADO, $pedido['id']]);

            $pdo->prepare(
                "UPDATE pedido_comprobantes
                    SET estado = 'aprobado', motivo_rechazo = '', revisado_por = ?, revisado_en = NOW()
                  WHERE pedido_id = ? AND estado <> 'aprobado'
                  ORDER BY subido_en DESC LIMIT 1"
            )->execute([Auth::id(), $pedido['id']]);

            self::anotarHistorial($pdo, (int)$pedido['id'], 'pago', (string)$pedido['estado_pago'],
                self::PAGO_APROBADO, $nota !== '' ? $nota : 'Transferencia verificada.');
            self::anotarHistorial($pdo, (int)$pedido['id'], 'pedido', (string)$pedido['estado'],
                self::CONFIRMADO, 'Pedido confirmado tras aprobar el pago.');

            $pdo->commit();
        } catch (Throwable $ex) {
            $pdo->rollBack();
            error_log('Flowers Anto — aprobar pago: ' . $ex->getMessage());
            return ['ok' => false, 'error' => 'No se pudo registrar la aprobación.'];
        }

        Auditoria::registrar($pdo, 'aprobar_pago', 'pedidos', [
            'recurso_tipo' => 'pedido',
            'recurso_id'   => (string)$pedido['id'],
            'descripcion'  => 'Pago aprobado del pedido ' . $pedido['codigo'] . ' (' . dinero($pedido['total']) . ')',
            'detalles'     => ['nota' => $nota],
        ]);

        self::notificarPagoAprobado($pdo, self::porId($pdo, (int)$pedido['id']));
        return ['ok' => true];
    }

    /**
     * Rechaza el pago indicando el motivo. El cliente puede subir otro
     * comprobante: el pedido vuelve a «pendiente de comprobante».
     */
    public static function rechazarPago(PDO $pdo, array $pedido, string $motivo): array
    {
        $motivo = trim($motivo);
        if (mb_strlen($motivo) < 10) {
            return ['ok' => false, 'error' => 'Explica en al menos 10 caracteres por qué se rechaza, el cliente lo verá.'];
        }
        if ($pedido['estado_pago'] === self::PAGO_APROBADO) {
            return ['ok' => false, 'error' => 'Este pago ya fue aprobado. Cancela el pedido si hubo un error.'];
        }
        if (!in_array($pedido['estado_pago'], [self::PAGO_RECIBIDO, self::PAGO_EN_REVISION], true)) {
            return ['ok' => false, 'error' => 'No hay ningún comprobante en revisión.'];
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE pedidos SET estado_pago = ?, estado = ? WHERE id = ?")
                ->execute([self::PAGO_RECHAZADO, self::PENDIENTE, $pedido['id']]);

            $pdo->prepare(
                "UPDATE pedido_comprobantes
                    SET estado = 'rechazado', motivo_rechazo = ?, revisado_por = ?, revisado_en = NOW()
                  WHERE pedido_id = ? AND estado IN ('recibido','en_revision')"
            )->execute([mb_substr($motivo, 0, 400), Auth::id(), $pedido['id']]);

            self::anotarHistorial($pdo, (int)$pedido['id'], 'pago', (string)$pedido['estado_pago'],
                self::PAGO_RECHAZADO, $motivo);

            $pdo->commit();
        } catch (Throwable $ex) {
            $pdo->rollBack();
            error_log('Flowers Anto — rechazar pago: ' . $ex->getMessage());
            return ['ok' => false, 'error' => 'No se pudo registrar el rechazo.'];
        }

        Auditoria::registrar($pdo, 'rechazar_pago', 'pedidos', [
            'recurso_tipo' => 'pedido',
            'recurso_id'   => (string)$pedido['id'],
            'descripcion'  => 'Pago rechazado del pedido ' . $pedido['codigo'],
            'detalles'     => ['motivo' => $motivo],
        ]);

        self::notificarPagoRechazado($pdo, self::porId($pdo, (int)$pedido['id']), $motivo);
        return ['ok' => true];
    }

    /** ¿El cliente puede subir (otro) comprobante ahora mismo? */
    public static function admiteComprobante(array $pedido): bool
    {
        return $pedido['metodo_pago'] === 'transferencia'
            && in_array($pedido['estado_pago'], [self::PAGO_PENDIENTE, self::PAGO_RECHAZADO], true)
            && !in_array($pedido['estado'], [self::CANCELADO, self::COMPLETADO], true);
    }

    // -----------------------------------------------------------------
    // WhatsApp
    // -----------------------------------------------------------------

    /** Mensaje de WhatsApp de un pedido ya registrado. */
    public static function mensajeWhatsapp(array $pedido): string
    {
        $tienda = Ajustes::texto('nombre_tienda', 'Flowers Anto');
        $m      = (string)$pedido['moneda'];
        $l      = ["Hola $tienda, este es mi pedido " . $pedido['codigo'] . ':', ''];

        foreach ($pedido['items'] as $i) {
            $l[] = sprintf('• %d × %s — %s%s', $i['cantidad'], $i['nombre'], $m, number_format((float)$i['subtotal'], 2));
        }
        $l[] = '';
        $l[] = 'Total: ' . $m . number_format((float)$pedido['total'], 2);
        $l[] = 'A nombre de: ' . $pedido['cliente_nombre'];
        if ($pedido['entrega_tipo'] === 'domicilio' && $pedido['entrega_direccion'] !== '') {
            $l[] = 'Entrega: ' . $pedido['entrega_direccion']
                 . ($pedido['entrega_ciudad'] !== '' ? ', ' . $pedido['entrega_ciudad'] : '');
        } else {
            $l[] = 'Retiro en la tienda.';
        }
        if ($pedido['entrega_fecha']) {
            $l[] = 'Fecha deseada: ' . fecha_corta((string)$pedido['entrega_fecha'])
                 . ($pedido['entrega_franja'] !== '' ? ' — ' . $pedido['entrega_franja'] : '');
        }
        $l[] = '';
        $l[] = 'Seguimiento: ' . self::enlaceSeguimiento($pedido, true);

        return implode("\n", $l);
    }

    // -----------------------------------------------------------------
    // Notificaciones por correo
    // -----------------------------------------------------------------

    private static function tablaItems(array $pedido): string
    {
        $filas = '';
        foreach ($pedido['items'] as $i) {
            $filas .= '<tr>
                <td style="padding:7px 0;border-bottom:1px solid #F1E7E9;">' . e($i['nombre'])
                . ' <span style="color:#8A7A7D;">× ' . (int)$i['cantidad'] . '</span></td>
                <td style="padding:7px 0;border-bottom:1px solid #F1E7E9;text-align:right;white-space:nowrap;">'
                . e($pedido['moneda'] . number_format((float)$i['subtotal'], 2)) . '</td></tr>';
        }
        // El descuento se enseña como línea propia: el cliente tiene que ver
        // que su cupón se aplicó, no solo un total más bajo del que esperaba.
        $descuento = '';
        if ((float)($pedido['descuento'] ?? 0) > 0) {
            $descuento = '<tr><td style="padding:8px 0 0;color:#2F6B44;">Descuento'
                       . ((string)($pedido['cupon_codigo'] ?? '') !== ''
                           ? ' <span style="color:#8A7A7D;">' . e((string)$pedido['cupon_codigo']) . '</span>' : '')
                       . '</td><td style="padding:8px 0 0;text-align:right;color:#2F6B44;white-space:nowrap;">−'
                       . e($pedido['moneda'] . number_format((float)$pedido['descuento'], 2)) . '</td></tr>';
        }

        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"
                       style="border-collapse:collapse;font-size:14.5px;margin:14px 0;">' . $filas
             . $descuento
             . '<tr><td style="padding:10px 0 0;font-weight:600;">Total</td>
                    <td style="padding:10px 0 0;text-align:right;font-weight:600;">'
             . e($pedido['moneda'] . number_format((float)$pedido['total'], 2)) . '</td></tr></table>';
    }

    /** Bloque con los datos de entrega, para los correos. */
    private static function bloqueEntrega(array $pedido): string
    {
        if ($pedido['entrega_tipo'] === 'retiro') {
            return '<p style="margin:14px 0 0;"><strong>Retiro en la tienda</strong><br>'
                 . e(Ajustes::texto('direccion')) . '</p>';
        }

        $lineas = ['<strong>Entrega a domicilio</strong>', e((string)$pedido['entrega_direccion'])];
        if (($pedido['zona_envio_nombre'] ?? '') !== '') {
            $lineas[] = e((string)$pedido['zona_envio_nombre']);
        }
        if ($pedido['entrega_referencia'] !== '') {
            $lineas[] = 'Referencia: ' . e((string)$pedido['entrega_referencia']);
        }
        if ($pedido['entrega_fecha']) {
            $lineas[] = 'Fecha: ' . e(fecha_corta((string)$pedido['entrega_fecha']))
                      . ($pedido['entrega_franja'] !== '' ? ' · ' . e((string)$pedido['entrega_franja']) : '');
        }
        return '<p style="margin:14px 0 0;line-height:1.6;">' . implode('<br>', $lineas) . '</p>';
    }

    private static function notificarNuevoPedido(PDO $pdo, ?array $pedido): void
    {
        if (!$pedido || $pedido['cliente_email'] === '') {
            return;
        }
        $enlace = self::enlaceSeguimiento($pedido, true);
        $extra  = $pedido['metodo_pago'] === 'transferencia'
            ? '<p>El siguiente paso es realizar la transferencia y subir el comprobante desde el enlace de abajo. '
              . 'Lo revisamos y te confirmamos.</p>'
            : '<p>Nos pondremos en contacto contigo por WhatsApp para coordinar el pago y la entrega.</p>';

        $html = Correo::plantilla(
            'Recibimos tu pedido ' . $pedido['codigo'],
            '<p>Hola ' . e((string)$pedido['cliente_nombre']) . ', gracias por tu pedido.</p>'
            . self::tablaItems($pedido) . self::bloqueEntrega($pedido) . $extra,
            ['url' => $enlace, 'texto' => 'Ver mi pedido']
        );
        Correo::enviar((string)$pedido['cliente_email'], 'Pedido ' . $pedido['codigo'] . ' recibido', $html);
    }

    /**
     * Avisa al equipo de que entró un pedido.
     *
     * Va aparte del correo al cliente a propósito: si el buzón del equipo
     * rebota o está mal escrito, el cliente igual recibe su confirmación.
     */
    private static function avisarEquipo(PDO $pdo, ?array $pedido): void
    {
        if (!$pedido || !Ajustes::activo('avisar_pedidos', true)) {
            return;
        }
        $destino = Ajustes::texto('email_avisos', Ajustes::texto('email_contacto'));
        if ($destino === '') {
            return;
        }

        $entrega = $pedido['entrega_tipo'] === 'retiro'
            ? 'Retiro en la tienda'
            : trim((string)$pedido['entrega_direccion'] . ' — ' . (string)$pedido['zona_envio_nombre'], ' —');

        $mapa = '';
        if (($pedido['entrega_mapa_url'] ?? '') !== '') {
            $mapa = '<p><a href="' . e((string)$pedido['entrega_mapa_url']) . '">Abrir la ubicación en '
                  . e(Envios::servicioMapa((string)$pedido['entrega_mapa_url'])) . '</a></p>';
        }

        $html = Correo::plantilla(
            'Pedido nuevo: ' . $pedido['codigo'],
            '<p>Entró un pedido por ' . e(dinero($pedido['total'])) . '.</p>'
            . '<table role="presentation" cellpadding="0" cellspacing="0" style="font-size:14.5px;line-height:1.7;">'
            . '<tr><td style="color:#8A7A7D;padding-right:14px;">Cliente</td><td>'
            . e((string)$pedido['cliente_nombre']) . '</td></tr>'
            . '<tr><td style="color:#8A7A7D;padding-right:14px;">Teléfono</td><td>'
            . e((string)$pedido['cliente_telefono']) . '</td></tr>'
            . '<tr><td style="color:#8A7A7D;padding-right:14px;">Entrega</td><td>' . e($entrega) . '</td></tr>'
            . ($pedido['entrega_fecha']
                ? '<tr><td style="color:#8A7A7D;padding-right:14px;">Fecha</td><td>'
                  . e(fecha_corta((string)$pedido['entrega_fecha'])) . '</td></tr>' : '')
            . '<tr><td style="color:#8A7A7D;padding-right:14px;">Pago</td><td>'
            . e(ucfirst((string)$pedido['metodo_pago'])) . '</td></tr>'
            . '</table>'
            . self::tablaItems($pedido)
            . $mapa
            . ($pedido['metodo_pago'] === 'transferencia'
                ? '<p style="color:#8A6410;">Queda a la espera del comprobante. Nada se aprueba solo.</p>'
                : ''),
            ['url' => url_absoluta('admin/pedido.php?id=' . (int)$pedido['id']), 'texto' => 'Abrir en el panel']
        );

        Correo::enviar($destino, 'Pedido nuevo ' . $pedido['codigo'] . ' — ' . dinero($pedido['total']), $html);
    }

    /**
     * Avisa al equipo de que hay un comprobante esperando verificación.
     *
     * Es el momento en que alguien tiene que mirar el archivo a mano: el
     * sistema nunca aprueba un pago solo, así que si nadie se entera el pedido
     * se queda parado.
     */
    private static function avisarEquipoComprobante(?array $pedido, array $extra = []): void
    {
        if (!$pedido || !Ajustes::activo('avisar_pagos', true)) {
            return;
        }
        $destino = Ajustes::texto('email_avisos', Ajustes::texto('email_contacto'));
        if ($destino === '') {
            return;
        }

        $referencia = trim((string)($extra['referencia'] ?? ''));
        $banco      = trim((string)($extra['banco'] ?? ''));
        $monto      = (float)($extra['monto'] ?? 0);

        $html = Correo::plantilla(
            'Comprobante por verificar',
            '<p>El cliente ' . e((string)$pedido['cliente_nombre']) . ' subió un comprobante del pedido '
            . '<strong>' . e((string)$pedido['codigo']) . '</strong>.</p>'
            . '<table role="presentation" cellpadding="0" cellspacing="0" style="font-size:14.5px;line-height:1.7;">'
            . '<tr><td style="color:#8A7A7D;padding-right:14px;">Total del pedido</td><td>'
            . e(dinero($pedido['total'])) . '</td></tr>'
            . ($monto > 0
                ? '<tr><td style="color:#8A7A7D;padding-right:14px;">Monto declarado</td><td>'
                  . e(dinero($monto)) . '</td></tr>' : '')
            . ($banco !== ''
                ? '<tr><td style="color:#8A7A7D;padding-right:14px;">Banco</td><td>'
                  . e($banco) . '</td></tr>' : '')
            . ($referencia !== ''
                ? '<tr><td style="color:#8A7A7D;padding-right:14px;">Referencia</td><td>'
                  . e($referencia) . '</td></tr>' : '')
            . '</table>'
            . '<p style="color:#8A6410;">El pedido queda en revisión hasta que una persona '
            . 'apruebe o rechace el comprobante desde el panel.</p>',
            ['url' => url_absoluta('admin/pedido.php?id=' . (int)$pedido['id']), 'texto' => 'Verificar el comprobante']
        );

        Correo::enviar($destino, 'Comprobante por verificar — ' . $pedido['codigo'], $html);
    }

    private static function notificarComprobante(PDO $pdo, ?array $pedido): void
    {
        if (!$pedido || $pedido['cliente_email'] === '') {
            return;
        }
        $html = Correo::plantilla(
            'Comprobante recibido',
            '<p>Hola ' . e((string)$pedido['cliente_nombre']) . ', ya tenemos tu comprobante del pedido '
            . '<strong>' . e((string)$pedido['codigo']) . '</strong>.</p>'
            . '<p>Un miembro del equipo lo va a revisar. Te avisamos en cuanto quede verificado; '
            . 'normalmente tarda unas horas en horario de atención.</p>',
            ['url' => self::enlaceSeguimiento($pedido, true), 'texto' => 'Seguir mi pedido']
        );
        Correo::enviar((string)$pedido['cliente_email'], 'Comprobante recibido — ' . $pedido['codigo'], $html);
    }

    private static function notificarPagoAprobado(PDO $pdo, ?array $pedido): void
    {
        if (!$pedido || $pedido['cliente_email'] === '') {
            return;
        }
        $html = Correo::plantilla(
            '¡Pago confirmado!',
            '<p>Hola ' . e((string)$pedido['cliente_nombre']) . ', verificamos tu transferencia del pedido '
            . '<strong>' . e((string)$pedido['codigo']) . '</strong>.</p>'
            . self::tablaItems($pedido)
            . '<p>Tu pedido pasa al taller. Te escribimos cuando esté listo para la entrega.</p>',
            ['url' => self::enlaceSeguimiento($pedido, true), 'texto' => 'Ver el estado']
        );
        Correo::enviar((string)$pedido['cliente_email'], 'Pago confirmado — ' . $pedido['codigo'], $html);
    }

    private static function notificarPagoRechazado(PDO $pdo, ?array $pedido, string $motivo): void
    {
        if (!$pedido || $pedido['cliente_email'] === '') {
            return;
        }
        $html = Correo::plantilla(
            'No pudimos validar el comprobante',
            '<p>Hola ' . e((string)$pedido['cliente_nombre']) . ', revisamos el comprobante del pedido '
            . '<strong>' . e((string)$pedido['codigo']) . '</strong> y no pudimos darlo por válido.</p>'
            . '<p style="background:#FBEAEA;border:1px solid #F1CFD1;border-radius:9px;padding:12px 14px;color:#93313A;">'
            . e($motivo) . '</p>'
            . '<p>Tu pedido sigue reservado. Puedes subir otro comprobante desde el enlace de abajo, '
            . 'o escribirnos por WhatsApp si necesitas ayuda.</p>',
            ['url' => self::enlaceSeguimiento($pedido, true), 'texto' => 'Subir otro comprobante']
        );
        Correo::enviar((string)$pedido['cliente_email'], 'Revisión del pago — ' . $pedido['codigo'], $html);
    }

    /**
     * Avisa al cliente de un cambio de estado.
     *
     * El texto sale de `estados_pedido.mensaje_correo`, que se edita desde el
     * panel: así el negocio cambia lo que dice cada aviso sin tocar código, y
     * puede desactivar los que no quiera enviar. Si el estado no tiene mensaje
     * propio se usa su descripción, para que nunca salga un correo vacío.
     *
     * La nota que escribe quien atiende el pedido se añade al final: es la
     * parte que cambia de un pedido a otro («sale a las 3», «el portón está
     * pintado de verde»).
     */
    private static function notificarCambioEstado(PDO $pdo, ?array $pedido, string $nuevo, string $nota): void
    {
        if (!$pedido || $pedido['cliente_email'] === '') {
            return;
        }

        $estado = self::estado($pdo, 'pedido', $nuevo);
        if (isset($estado['avisar_cliente']) && (int)$estado['avisar_cliente'] === 0) {
            return;
        }

        $mensaje = trim((string)($estado['mensaje_correo'] ?? ''));
        if ($mensaje === '') {
            $mensaje = (string)$estado['descripcion'];
        }

        $cuerpo = '<p>Hola ' . e((string)$pedido['cliente_nombre']) . ', tu pedido <strong>'
                . e((string)$pedido['codigo']) . '</strong> cambió de estado.</p>'
                . '<p style="display:inline-block;background:' . e((string)$estado['color'])
                . ';color:#ffffff;font-weight:600;font-size:15px;padding:7px 16px;border-radius:30px;">'
                . e((string)$estado['nombre']) . '</p>'
                . '<p style="line-height:1.7;">' . nl2br(e($mensaje)) . '</p>';

        if ($nota !== '') {
            $cuerpo .= '<p style="background:#FBF0F3;border-left:3px solid #C4788F;padding:11px 14px;'
                     . 'margin:16px 0;color:#4A3B3D;line-height:1.6;">' . nl2br(e($nota)) . '</p>';
        }

        // En los estados en que el cliente aún tiene que hacer algo o esperar
        // una entrega, se le recuerdan los datos de la entrega.
        if (in_array($nuevo, [self::CONFIRMADO, self::PREPARACION, self::LISTO, self::ENVIADO], true)) {
            $cuerpo .= self::bloqueEntrega($pedido);
        }

        Correo::enviar(
            (string)$pedido['cliente_email'],
            $estado['nombre'] . ' — pedido ' . $pedido['codigo'],
            Correo::plantilla(
                'Tu pedido: ' . $estado['nombre'],
                $cuerpo,
                ['url' => self::enlaceSeguimiento($pedido, true), 'texto' => 'Ver mi pedido']
            )
        );
    }
}
