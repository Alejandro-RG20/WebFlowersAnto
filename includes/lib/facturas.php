<?php
/**
 * Facturación.
 *
 * Emite la factura de un pedido, la guarda como documento cerrado y se la
 * manda al cliente por correo.
 *
 * Tres reglas gobiernan todo lo de aquí:
 *
 * 1. Una factura es una FOTOGRAFÍA. Copia los datos de la tienda, del cliente
 *    y de cada línea en el momento de emitirla. Después, cambie lo que cambie
 *    en el catálogo o en la configuración, la factura sigue diciendo lo mismo.
 *
 * 2. El total de la factura es, al céntimo, lo que el cliente pagó. El IVA no
 *    se suma: se desglosa hacia atrás del precio, que es como se muestran los
 *    precios en el comercio al detalle. Una factura que no cuadra con el cobro
 *    no le sirve a nadie.
 *
 * 3. Una factura no se borra. Se anula, con motivo y con nombre de quien lo
 *    hizo, y su número queda ocupado para siempre.
 */

declare(strict_types=1);

final class Facturas
{
    /** ¿Está encendida la facturación? */
    public static function activo(): bool
    {
        return Ajustes::activo('factura_activo', false);
    }

    /** ¿Se emite sola al marcar el pedido como entregado? */
    public static function automatica(): bool
    {
        return self::activo() && Ajustes::activo('factura_auto', true);
    }

    public static function serie(): string
    {
        $s = strtoupper(trim(Ajustes::texto('factura_serie', 'A')));
        return preg_match('/^[A-Z0-9-]{1,10}$/', $s) ? $s : 'A';
    }

    /** Tasa de IVA aplicable, o 0 si está apagado. */
    public static function tasaIva(): float
    {
        if (!Ajustes::activo('factura_iva_activo', false)) {
            return 0.0;
        }
        $t = (float)Ajustes::numero('factura_iva_tasa', 15);
        return ($t > 0 && $t < 100) ? round($t, 2) : 0.0;
    }

    /** Nombre presentable de la forma de pago, para la hoja y el correo. */
    public static function metodoPago(string $codigo): string
    {
        return match ($codigo) {
            'transferencia' => 'Transferencia bancaria',
            'efectivo'      => 'Efectivo contra entrega',
            'paypal'        => 'PayPal',
            'whatsapp'      => 'Coordinado por WhatsApp',
            default         => 'Otro medio',
        };
    }

    /** Folio legible: serie + número con ceros a la izquierda. */
    public static function folio(string $serie, int $numero): string
    {
        return $serie . '-' . str_pad((string)$numero, 6, '0', STR_PAD_LEFT);
    }

    /** La factura de un pedido, si ya se emitió. */
    public static function porPedido(PDO $pdo, int $pedidoId): ?array
    {
        $st = $pdo->prepare("SELECT * FROM facturas WHERE pedido_id = ?");
        $st->execute([$pedidoId]);
        $f = $st->fetch();
        return $f ? self::conItems($pdo, $f) : null;
    }

    public static function porId(PDO $pdo, int $id): ?array
    {
        $st = $pdo->prepare("SELECT * FROM facturas WHERE id = ?");
        $st->execute([$id]);
        $f = $st->fetch();
        return $f ? self::conItems($pdo, $f) : null;
    }

    private static function conItems(PDO $pdo, array $factura): array
    {
        $st = $pdo->prepare("SELECT * FROM factura_items WHERE factura_id = ? ORDER BY id");
        $st->execute([$factura['id']]);
        $factura['items'] = $st->fetchAll();
        return $factura;
    }

    public static function anulada(array $factura): bool
    {
        return !empty($factura['anulada_en']);
    }

    /**
     * Emite la factura de un pedido.
     *
     * Es idempotente: si el pedido ya tiene factura, devuelve la que hay en vez
     * de emitir otra. Eso importa porque esto se dispara al marcar el pedido
     * como entregado, y a un estado se puede volver.
     *
     * @return array{ok: bool, factura?: array, error?: string, repetida?: bool}
     */
    public static function emitir(PDO $pdo, array $pedido): array
    {
        if (!self::activo()) {
            return ['ok' => false, 'error' => 'La facturación está apagada.'];
        }
        $pedidoId = (int)($pedido['id'] ?? 0);
        if ($pedidoId <= 0) {
            return ['ok' => false, 'error' => 'Pedido no válido.'];
        }

        if ($ya = self::porPedido($pdo, $pedidoId)) {
            return ['ok' => true, 'factura' => $ya, 'repetida' => true];
        }

        // Las líneas se leen del pedido, no del catálogo: el catálogo puede
        // haber cambiado de precio desde que se compró.
        $items = (array)($pedido['items'] ?? []);
        if (!$items) {
            $st = $pdo->prepare("SELECT * FROM pedido_items WHERE pedido_id = ? ORDER BY id");
            $st->execute([$pedidoId]);
            $items = $st->fetchAll();
        }
        if (!$items) {
            return ['ok' => false, 'error' => 'El pedido no tiene artículos que facturar.'];
        }

        $total     = round((float)$pedido['total'], 2);
        $envio     = round((float)$pedido['envio'], 2);
        $descuento = round((float)($pedido['descuento'] ?? 0), 2);
        $subtotal  = round((float)$pedido['subtotal'], 2);

        // El IVA sale de dentro del total, no encima: base = total / (1 + tasa).
        $tasa = self::tasaIva();
        $base = $tasa > 0 ? round($total / (1 + $tasa / 100), 2) : $total;
        $iva  = round($total - $base, 2);

        $pdo->beginTransaction();
        try {
            // El número se toma bloqueando la fila de configuración. Sin este
            // bloqueo, dos pedidos entregados a la vez podrían llevarse el
            // mismo número, y dos facturas con el mismo folio es justo lo que
            // una serie no se puede permitir.
            $st = $pdo->prepare("SELECT factura_siguiente FROM configuracion WHERE id = 1 FOR UPDATE");
            $st->execute();
            $numero = max(1, (int)$st->fetchColumn());
            $serie  = self::serie();

            // Cinturón y tirantes: si en la tabla ya hay un número igual o
            // mayor —porque se restauró un respaldo, por ejemplo—, se sigue
            // desde ahí y no se pisa nada.
            $st = $pdo->prepare("SELECT MAX(numero) FROM facturas WHERE serie = ?");
            $st->execute([$serie]);
            $numero = max($numero, (int)$st->fetchColumn() + 1);

            $usuario = Auth::usuario();
            $ins = $pdo->prepare(
                "INSERT INTO facturas
                    (serie, numero, folio, pedido_id, pedido_codigo,
                     emisor_nombre, emisor_ruc, emisor_direccion, emisor_telefono,
                     cliente_nombre, cliente_email, cliente_telefono, cliente_direccion,
                     moneda, subtotal, descuento, envio, base_imponible, iva_tasa, iva, total,
                     metodo_pago, cupon_codigo, pie, emitida_por, emitida_texto)
                 VALUES (?,?,?,?,?, ?,?,?,?, ?,?,?,?, ?,?,?,?,?,?,?,?, ?,?,?,?,?)"
            );
            $ins->execute([
                $serie, $numero, self::folio($serie, $numero), $pedidoId, (string)$pedido['codigo'],
                mb_substr(Ajustes::texto('factura_razon_social', Ajustes::texto('nombre_tienda', '')), 0, 160),
                mb_substr(Ajustes::texto('factura_ruc', ''), 0, 40),
                mb_substr(Ajustes::texto('factura_direccion', Ajustes::texto('direccion', '')), 0, 255),
                mb_substr(Ajustes::texto('factura_telefono', Ajustes::texto('telefono', '')), 0, 40),
                mb_substr((string)$pedido['cliente_nombre'], 0, 150),
                mb_substr((string)$pedido['cliente_email'], 0, 160),
                mb_substr((string)$pedido['cliente_telefono'], 0, 40),
                mb_substr(trim((string)($pedido['entrega_direccion'] ?? '')), 0, 255),
                Ajustes::texto('moneda_local', 'C$'),
                $subtotal, $descuento, $envio, $base, $tasa, $iva, $total,
                (string)$pedido['metodo_pago'],
                mb_substr((string)($pedido['cupon_codigo'] ?? ''), 0, 40),
                mb_substr(Ajustes::texto('factura_pie', ''), 0, 500),
                $usuario['id'] ?? null,
                mb_substr(Auth::nombreCompleto() ?: 'Automático', 0, 150),
            ]);
            $facturaId = (int)$pdo->lastInsertId();

            $li = $pdo->prepare(
                "INSERT INTO factura_items (factura_id, descripcion, cantidad, precio_unitario, subtotal)
                 VALUES (?,?,?,?,?)"
            );
            foreach ($items as $it) {
                $li->execute([
                    $facturaId,
                    mb_substr((string)$it['nombre'], 0, 200),
                    (int)$it['cantidad'],
                    round((float)$it['precio_unitario'], 2),
                    round((float)$it['subtotal'], 2),
                ]);
            }

            $pdo->prepare("UPDATE configuracion SET factura_siguiente = ? WHERE id = 1")
                ->execute([$numero + 1]);
            $pdo->commit();
        } catch (PDOException $ex) {
            $pdo->rollBack();
            error_log('Flowers Anto — emitir factura: ' . $ex->getMessage());
            // La clave única del pedido puede saltar si dos peticiones
            // coincidieron: en ese caso la factura buena es la que ya está.
            if ($ya = self::porPedido($pdo, $pedidoId)) {
                return ['ok' => true, 'factura' => $ya, 'repetida' => true];
            }
            return ['ok' => false, 'error' => 'No se pudo emitir la factura.'];
        }

        Ajustes::refrescar();
        $factura = self::porId($pdo, $facturaId);

        Auditoria::registrar($pdo, 'emitir_factura', 'ventas', [
            'recurso_tipo' => 'factura', 'recurso_id' => (string)$facturaId,
            'descripcion'  => 'Factura ' . $factura['folio'] . ' del pedido ' . $pedido['codigo']
                            . ' por ' . dinero($total) . '.',
        ]);

        return ['ok' => true, 'factura' => $factura];
    }

    /**
     * Anula una factura. No la borra: la deja marcada y sin efecto.
     *
     * El número no se reutiliza a propósito. Una serie de facturas con huecos
     * o con números repetidos es una serie de la que no se puede responder.
     */
    public static function anular(PDO $pdo, array $factura, string $motivo): array
    {
        if (self::anulada($factura)) {
            return ['ok' => false, 'error' => 'Esa factura ya estaba anulada.'];
        }
        $motivo = trim($motivo);
        if (mb_strlen($motivo) < 5) {
            return ['ok' => false, 'error' => 'Escribe el motivo de la anulación.'];
        }

        $pdo->prepare(
            "UPDATE facturas SET anulada_en = NOW(), anulada_por = ?, anulada_motivo = ? WHERE id = ?"
        )->execute([Auth::id(), mb_substr($motivo, 0, 300), $factura['id']]);

        Auditoria::registrar($pdo, 'anular_factura', 'ventas', [
            'recurso_tipo' => 'factura', 'recurso_id' => (string)$factura['id'],
            'descripcion'  => 'Factura ' . $factura['folio'] . ' anulada.',
            'detalles'     => ['motivo' => $motivo],
        ]);
        return ['ok' => true];
    }

    /**
     * Manda la factura al cliente.
     *
     * Va como HTML dentro del propio correo y con un enlace para verla e
     * imprimirla. No se adjunta un PDF porque generarlo exigiría una librería
     * externa, y este proyecto se despliega copiando carpetas: desde el enlace,
     * «Imprimir → Guardar como PDF» del navegador da el mismo papel.
     */
    public static function enviar(PDO $pdo, array $factura, ?array $pedido = null): bool
    {
        $para = trim((string)$factura['cliente_email']);
        if ($para === '') {
            return false;
        }
        // El enlace necesita el token del pedido; si no vino, se busca.
        $pedido = $pedido ?: Pedidos::porId($pdo, (int)$factura['pedido_id']);
        if (!$pedido) {
            return false;
        }

        $cuerpo = '<p>Hola ' . e((string)$factura['cliente_nombre']) . ', aquí tienes la factura '
                . 'de tu pedido <strong>' . e((string)$factura['pedido_codigo']) . '</strong>, '
                . 'que ya fue entregado. Gracias por confiar en nosotros.</p>'
                . self::bloqueHtml($factura);

        $ok = Correo::enviar(
            $para,
            'Factura ' . $factura['folio'] . ' — ' . Ajustes::texto('nombre_tienda', 'Flowers Anto'),
            Correo::plantilla('Tu factura ' . $factura['folio'], $cuerpo, [
                'url'   => self::enlace($pedido, true),
                'texto' => 'Ver e imprimir la factura',
            ])
        );

        if ($ok) {
            $pdo->prepare("UPDATE facturas SET enviada_en = NOW() WHERE id = ?")->execute([$factura['id']]);
        }
        return $ok;
    }

    /**
     * Enlace del cliente a su factura.
     *
     * Reutiliza el token de seguimiento del pedido, que ya existe, ya es
     * aleatorio y ya viaja en los correos: quien recibió la factura la abre
     * sin cuenta, y quien no tiene el enlace no puede ir probando números de
     * factura para leer los datos de otras personas. Una llave nueva para lo
     * mismo solo añadiría otra cosa que puede filtrarse.
     */
    public static function enlace(array $pedido, bool $absoluto = false): string
    {
        $ruta = 'factura.php?codigo=' . rawurlencode((string)$pedido['codigo'])
              . '&t=' . rawurlencode((string)$pedido['token_seguimiento']);
        return $absoluto ? url_absoluta($ruta) : url($ruta);
    }

    /** Tabla de la factura, en HTML de correo (tablas y estilos en línea). */
    public static function bloqueHtml(array $factura): string
    {
        $m = static fn($v) => e(dinero((float)$v));
        $filas = '';
        foreach ((array)($factura['items'] ?? []) as $it) {
            $filas .= '<tr>'
                . '<td style="padding:9px 0;border-bottom:1px solid #EFE6E8;">'
                . e((string)$it['descripcion'])
                . ' <span style="color:#8A7A7D;">× ' . (int)$it['cantidad'] . '</span></td>'
                . '<td align="right" style="padding:9px 0;border-bottom:1px solid #EFE6E8;white-space:nowrap;">'
                . $m($it['subtotal']) . '</td></tr>';
        }

        $totales = '<tr><td style="padding:7px 0;">Subtotal</td>'
                 . '<td align="right" style="padding:7px 0;">' . $m($factura['subtotal']) . '</td></tr>';
        if ((float)$factura['descuento'] > 0) {
            $totales .= '<tr><td style="padding:7px 0;">Descuento'
                      . ((string)$factura['cupon_codigo'] !== ''
                         ? ' (' . e((string)$factura['cupon_codigo']) . ')' : '')
                      . '</td><td align="right" style="padding:7px 0;">−' . $m($factura['descuento'])
                      . '</td></tr>';
        }
        if ((float)$factura['envio'] > 0) {
            $totales .= '<tr><td style="padding:7px 0;">Envío</td>'
                      . '<td align="right" style="padding:7px 0;">' . $m($factura['envio']) . '</td></tr>';
        }
        if ((float)$factura['iva_tasa'] > 0) {
            $totales .= '<tr><td style="padding:7px 0;color:#8A7A7D;">Base imponible</td>'
                      . '<td align="right" style="padding:7px 0;color:#8A7A7D;">'
                      . $m($factura['base_imponible']) . '</td></tr>'
                      . '<tr><td style="padding:7px 0;color:#8A7A7D;">IVA '
                      . e(rtrim(rtrim(number_format((float)$factura['iva_tasa'], 2), '0'), '.')) . '%'
                      . ' <span style="font-size:12px;">(incluido en el precio)</span></td>'
                      . '<td align="right" style="padding:7px 0;color:#8A7A7D;">'
                      . $m($factura['iva']) . '</td></tr>';
        }
        $totales .= '<tr><td style="padding:11px 0 0;font-weight:700;font-size:16px;border-top:2px solid #4A3B3D;">'
                  . 'Total</td><td align="right" style="padding:11px 0 0;font-weight:700;font-size:16px;'
                  . 'border-top:2px solid #4A3B3D;">' . $m($factura['total']) . '</td></tr>';

        $anulada = self::anulada($factura)
            ? '<p style="background:#FBEAEA;border:1px solid #F1CFD1;color:#93313A;padding:11px 14px;'
              . 'border-radius:8px;font-weight:600;">FACTURA ANULADA'
              . ((string)$factura['anulada_motivo'] !== ''
                 ? ' — ' . e((string)$factura['anulada_motivo']) : '') . '</p>'
            : '';

        $emisor = array_filter([
            (string)$factura['emisor_nombre'],
            (string)$factura['emisor_ruc'] !== '' ? 'RUC ' . $factura['emisor_ruc'] : '',
            (string)$factura['emisor_direccion'],
            (string)$factura['emisor_telefono'],
        ], fn($v) => trim((string)$v) !== '');

        return $anulada
            . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"
                      style="font-size:14px;color:#4A3B3D;margin:18px 0 6px;">
                 <tr><td style="padding-bottom:14px;line-height:1.6;">
                   <strong style="font-size:16px;">Factura ' . e((string)$factura['folio']) . '</strong><br>
                   <span style="color:#8A7A7D;">Emitida el '
                     . e(date('d/m/Y', strtotime((string)$factura['emitida_en']))) . '</span><br>
                   <span style="color:#8A7A7D;">' . e(implode(' · ', $emisor)) . '</span>
                 </td></tr>'
            . '<tr><td>' . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"
                      style="font-size:14px;color:#4A3B3D;">' . $filas . '</table></td></tr>'
            . '<tr><td style="padding-top:8px;">'
            . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"
                     style="font-size:14px;color:#4A3B3D;">' . $totales . '</table></td></tr>'
            . ((string)$factura['pie'] !== ''
               ? '<tr><td style="padding-top:16px;color:#8A7A7D;font-size:12.5px;line-height:1.6;">'
                 . nl2br(e((string)$factura['pie'])) . '</td></tr>'
               : '')
            . '</table>';
    }
}
