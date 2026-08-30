<?php
/**
 * Repartidores y despacho de pedidos.
 *
 * El motorizado no entra al panel: recibe la entrega por WhatsApp. Aquí se
 * arma ese mensaje a partir del pedido y de una plantilla que el negocio edita
 * desde la configuración, para que no haya que copiar direcciones a mano —que
 * es donde se pierden los números de casa y las referencias.
 */

declare(strict_types=1);

final class Repartidores
{
    /** Repartidores disponibles para asignar. */
    public static function activos(PDO $pdo): array
    {
        try {
            return $pdo->query(
                "SELECT * FROM repartidores WHERE activo = 1 ORDER BY orden, nombre"
            )->fetchAll();
        } catch (PDOException) {
            return []; // la base todavía no tiene la migración 007
        }
    }

    /** Todos, incluidos los inactivos: es la lista del panel. */
    public static function todos(PDO $pdo): array
    {
        try {
            return $pdo->query("SELECT * FROM repartidores ORDER BY activo DESC, orden, nombre")->fetchAll();
        } catch (PDOException) {
            return [];
        }
    }

    /** Uno por su id, solo si está activo. */
    public static function porId(PDO $pdo, int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        try {
            $st = $pdo->prepare("SELECT * FROM repartidores WHERE id = ? AND activo = 1");
            $st->execute([$id]);
            return $st->fetch() ?: null;
        } catch (PDOException) {
            return null;
        }
    }

    /**
     * Deja constancia en el pedido de a quién se le mandó la entrega.
     *
     * El nombre y el teléfono se copian: si mañana el repartidor deja de
     * trabajar y se borra su ficha, el pedido sigue diciendo quién lo llevó.
     */
    public static function asignar(PDO $pdo, array $pedido, array $repartidor): void
    {
        $pdo->prepare(
            "UPDATE pedidos
                SET repartidor_id = ?, repartidor_nombre = ?, repartidor_telefono = ?,
                    repartidor_enviado_en = NOW()
              WHERE id = ?"
        )->execute([
            (int)$repartidor['id'],
            mb_substr((string)$repartidor['nombre'], 0, 120),
            mb_substr((string)$repartidor['telefono'], 0, 20),
            (int)$pedido['id'],
        ]);

        Pedidos::anotarHistorial(
            $pdo, (int)$pedido['id'], 'nota', '', '',
            'Entrega enviada a ' . $repartidor['nombre'] . ' (' . $repartidor['telefono'] . ').',
            Auth::id(), Auth::nombreCompleto()
        );
    }

    /**
     * Arma el mensaje de WhatsApp con todo lo que el motorizado necesita.
     *
     * Se construye en el servidor y no en el navegador porque los datos del
     * pedido no deben depender de lo que haya en pantalla: el mensaje sale de
     * la base, tal cual está guardado.
     */
    public static function mensaje(PDO $pdo, array $pedido, array $repartidor): string
    {
        $plantilla = trim((string)Ajustes::texto('mensaje_repartidor', ''));
        if ($plantilla === '') {
            $plantilla = "Hola {repartidor}, tienes una entrega de {tienda}.\n\n"
                       . "*Pedido:* {codigo}\n*Recibe:* {recibe}\n*Teléfono:* {telefono}\n"
                       . "*Dirección:* {direccion}\n*Zona:* {zona}\n*Referencia:* {referencia}\n"
                       . "*Ubicación:* {mapa}\n*Fecha:* {fecha}\n\n"
                       . "*Artículos:*\n{articulos}\n\n*Cobrar al entregar:* {cobrar}\n{notas}";
        }

        // Contra entrega el motorizado cobra; por transferencia ya está pagado
        // y decírselo evita que le vuelva a cobrar al cliente.
        $cobrar = $pedido['metodo_pago'] === 'efectivo'
            ? dinero($pedido['total']) . ' en efectivo'
            : 'Nada — ya está pagado por transferencia';

        $articulos = [];
        foreach ((array)($pedido['items'] ?? []) as $i) {
            $articulos[] = '• ' . (int)$i['cantidad'] . ' × ' . (string)$i['nombre'];
        }

        $fecha = $pedido['entrega_fecha']
            ? fecha_corta((string)$pedido['entrega_fecha'])
              . ((string)$pedido['entrega_franja'] !== '' ? ' · ' . (string)$pedido['entrega_franja'] : '')
            : 'Sin fecha acordada';

        $notas = trim((string)$pedido['notas_cliente']);

        $valores = [
            '{repartidor}' => (string)$repartidor['nombre'],
            '{tienda}'     => Ajustes::texto('nombre_tienda', 'Flowers Anto'),
            '{codigo}'     => (string)$pedido['codigo'],
            '{recibe}'     => (string)$pedido['entrega_nombre'],
            '{telefono}'   => (string)($pedido['entrega_telefono'] ?: $pedido['cliente_telefono']),
            '{direccion}'  => (string)$pedido['entrega_direccion'],
            '{zona}'       => (string)($pedido['zona_envio_nombre'] ?: $pedido['entrega_ciudad']),
            '{referencia}' => (string)($pedido['entrega_referencia'] ?: 'Sin referencia'),
            '{mapa}'       => (string)($pedido['entrega_mapa_url'] ?: 'No la envió'),
            '{fecha}'      => $fecha,
            '{articulos}'  => $articulos ? implode("\n", $articulos) : 'Sin detalle',
            '{cobrar}'     => $cobrar,
            '{notas}'      => $notas !== '' ? "\n*Notas del cliente:* " . $notas : '',
            '{dedicatoria}' => (string)$pedido['dedicatoria'],
            '{total}'      => dinero($pedido['total']),
        ];

        return trim(strtr($plantilla, $valores));
    }

    /** Etiquetas que se pueden usar en la plantilla, para mostrarlas en el panel. */
    public static function etiquetas(): array
    {
        return [
            '{repartidor}' => 'Nombre del motorizado',
            '{tienda}'     => 'Nombre de la floristería',
            '{codigo}'     => 'Código del pedido',
            '{recibe}'     => 'Quién recibe',
            '{telefono}'   => 'Teléfono de quien recibe',
            '{direccion}'  => 'Dirección de entrega',
            '{zona}'       => 'Zona de entrega',
            '{referencia}' => 'Punto de referencia',
            '{mapa}'       => 'Enlace de ubicación',
            '{fecha}'      => 'Fecha y franja de entrega',
            '{articulos}'  => 'Lista de artículos',
            '{cobrar}'     => 'Cuánto cobrar al entregar',
            '{total}'      => 'Total del pedido',
            '{dedicatoria}' => 'Dedicatoria de la tarjeta',
            '{notas}'      => 'Notas del cliente',
        ];
    }
}
