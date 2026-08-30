<?php
/**
 * Cupones de descuento.
 *
 * Del navegador solo viaja el código. El importe del descuento se vuelve a
 * calcular aquí en cada paso —al escribirlo, al repintar el resumen y al
 * registrar el pedido— a partir de lo que hay en la base. Guardar el importe
 * en la sesión habría bastado para que quien edite una petición se rebaje el
 * pedido a cero.
 *
 * El canje se cuenta con un UPDATE condicional en vez de leer-y-luego-sumar:
 * dos personas usando el último uso de un cupón a la vez es raro pero pasa, y
 * la forma de que no pase es que lo decida la base de datos, no el PHP.
 */

declare(strict_types=1);

final class Cupones
{
    /** Normaliza lo que escribe el cliente: mayúsculas, sin espacios ni adornos. */
    public static function limpiar(string $codigo): string
    {
        $codigo = mb_strtoupper(trim($codigo));
        $codigo = preg_replace('/[^A-Z0-9\-_]/u', '', $codigo) ?? '';
        return mb_substr($codigo, 0, 40);
    }

    /** ¿El negocio tiene los cupones encendidos? */
    public static function activos(): bool
    {
        return Ajustes::activo('cupones_activos', true);
    }

    public static function porCodigo(PDO $pdo, string $codigo): ?array
    {
        $codigo = self::limpiar($codigo);
        if ($codigo === '') {
            return null;
        }
        try {
            $st = $pdo->prepare("SELECT * FROM cupones WHERE codigo = ? LIMIT 1");
            $st->execute([$codigo]);
            return $st->fetch() ?: null;
        } catch (PDOException) {
            return null; // la base todavía no tiene la migración 009
        }
    }

    /**
     * Comprueba un cupón contra un carrito concreto.
     *
     * Devuelve siempre la misma forma para que quien la llame no tenga que
     * distinguir entre «no existe» y «no aplica»: lo que le importa es si
     * puede usarlo y cuánto rebaja.
     *
     * @return array{ok: bool, cupon: ?array, descuento: float, error: string}
     */
    public static function revisar(
        PDO $pdo,
        string $codigo,
        float $subtotal,
        float $envio = 0.0,
        ?int $usuarioId = null,
        string $email = ''
    ): array {
        $no = static fn(string $error): array
            => ['ok' => false, 'cupon' => null, 'descuento' => 0.0, 'error' => $error];

        if (!self::activos()) {
            return $no('Los cupones no están disponibles en este momento.');
        }

        $codigo = self::limpiar($codigo);
        if ($codigo === '') {
            return $no('Escribe el código del cupón.');
        }

        $cupon = self::porCodigo($pdo, $codigo);
        // El mismo mensaje para «no existe» y «está desactivado»: decir cuál de
        // los dos es permitiría averiguar códigos válidos probando.
        if (!$cupon || (int)$cupon['activo'] !== 1) {
            return $no('Ese cupón no existe o ya no está disponible.');
        }

        $hoy = date('Y-m-d');
        if ($cupon['fecha_inicio'] && $cupon['fecha_inicio'] > $hoy) {
            return $no('Ese cupón todavía no está vigente.');
        }
        if ($cupon['fecha_fin'] && $cupon['fecha_fin'] < $hoy) {
            return $no('Ese cupón ya venció.');
        }

        $minima = (float)$cupon['compra_minima'];
        if ($minima > 0 && $subtotal < $minima) {
            return $no('Este cupón aplica a partir de ' . dinero($minima)
                     . '. Te faltan ' . dinero($minima - $subtotal) . '.');
        }

        $maximos = (int)$cupon['usos_maximos'];
        if ($maximos > 0 && (int)$cupon['usos'] >= $maximos) {
            return $no('Ese cupón ya alcanzó su límite de usos.');
        }

        $porCliente = (int)$cupon['usos_por_cliente'];
        if ($porCliente > 0 && self::usosDe($pdo, (int)$cupon['id'], $usuarioId, $email) >= $porCliente) {
            return $no($porCliente === 1
                ? 'Ya usaste este cupón en un pedido anterior.'
                : 'Ya usaste este cupón el máximo de veces permitido.');
        }

        $descuento = self::calcular($cupon, $subtotal, $envio);
        if ($descuento <= 0) {
            return $no('Este cupón no aplica a tu pedido.');
        }

        return ['ok' => true, 'cupon' => $cupon, 'descuento' => $descuento, 'error' => ''];
    }

    /**
     * Importe que rebaja el cupón.
     *
     * Nunca puede pasar del subtotal: un cupón de 500 sobre una compra de 300
     * deja el pedido en cero, no en negativo. El envío queda aparte porque el
     * negocio lo paga igual.
     */
    public static function calcular(array $cupon, float $subtotal, float $envio = 0.0): float
    {
        $valor = (float)$cupon['valor'];

        $descuento = match ((string)$cupon['tipo']) {
            'porcentaje'   => $subtotal * ($valor / 100),
            'fijo'         => $valor,
            'envio_gratis' => $envio,
            default        => 0.0,
        };

        // Tope opcional, pensado para los porcentajes: «20 % hasta C$300».
        $tope = (float)$cupon['descuento_maximo'];
        if ($tope > 0 && $descuento > $tope) {
            $descuento = $tope;
        }

        $limite = $cupon['tipo'] === 'envio_gratis' ? $envio : $subtotal;
        return round(max(0.0, min($descuento, $limite)), 2);
    }

    /** Cuántas veces ha usado este cupón esta persona. */
    private static function usosDe(PDO $pdo, int $cuponId, ?int $usuarioId, string $email): int
    {
        $email = mb_strtolower(trim($email));

        // Se cuenta por cuenta y por correo a la vez: un cliente que pidió como
        // invitado y luego se registró sigue siendo el mismo cliente.
        $condiciones = [];
        $valores     = [$cuponId];
        if ($usuarioId) {
            $condiciones[] = 'usuario_id = ?';
            $valores[]     = $usuarioId;
        }
        if ($email !== '') {
            $condiciones[] = 'email = ?';
            $valores[]     = $email;
        }
        if (!$condiciones) {
            return 0;
        }

        $st = $pdo->prepare(
            "SELECT COUNT(*) FROM cupon_usos WHERE cupon_id = ? AND (" . implode(' OR ', $condiciones) . ")"
        );
        $st->execute($valores);
        return (int)$st->fetchColumn();
    }

    /**
     * Anota el canje y descuenta un uso.
     *
     * El contador se sube con un UPDATE condicional: si entre la comprobación
     * y el registro alguien agotó el cupón, esta llamada no lo pasa del límite
     * y devuelve false. Quien la usa decide qué hacer; en el checkout el pedido
     * se registra igual, porque el cliente ya vio su total y no se le puede
     * cobrar de más por una carrera de milisegundos.
     */
    public static function registrarUso(
        PDO $pdo,
        array $cupon,
        int $pedidoId,
        ?int $usuarioId,
        string $email,
        float $descuento
    ): bool {
        $st = $pdo->prepare(
            "UPDATE cupones SET usos = usos + 1
              WHERE id = ? AND (usos_maximos = 0 OR usos < usos_maximos)"
        );
        $st->execute([(int)$cupon['id']]);
        $dentroDeLimite = $st->rowCount() > 0;

        $pdo->prepare(
            "INSERT INTO cupon_usos (cupon_id, pedido_id, usuario_id, email, descuento)
             VALUES (?,?,?,?,?)"
        )->execute([
            (int)$cupon['id'], $pedidoId ?: null, $usuarioId ?: null,
            mb_substr(mb_strtolower(trim($email)), 0, 150), $descuento,
        ]);

        return $dentroDeLimite;
    }

    /** Texto corto de lo que hace el cupón, para el resumen y el panel. */
    public static function resumen(array $cupon): string
    {
        return match ((string)$cupon['tipo']) {
            'porcentaje'   => rtrim(rtrim(number_format((float)$cupon['valor'], 2, '.', ''), '0'), '.') . ' % de descuento',
            'fijo'         => dinero($cupon['valor']) . ' de descuento',
            'envio_gratis' => 'Envío gratis',
            default        => 'Descuento',
        };
    }

    /** Los tipos, para pintar el selector del panel. */
    public static function tipos(): array
    {
        return [
            'porcentaje'   => 'Porcentaje sobre la compra',
            'fijo'         => 'Importe fijo',
            'envio_gratis' => 'Envío gratis',
        ];
    }
}
