<?php
/**
 * Verificación del correo de una cuenta.
 *
 * No bloquea nada: quien se registra puede comprar igual desde el primer
 * momento. Lo que hace es dejar constancia de que ese correo es realmente de
 * quien dice serlo, que es lo que sostiene dos cosas:
 *
 *   · El límite de cupones «uno por cliente», que se comprueba por correo.
 *   · Que el enlace de seguimiento del pedido llegue a una persona de verdad.
 *
 * Usa la misma tabla de tokens que la recuperación de contraseña, separada por
 * `tipo`. Los tokens se guardan hasheados: si alguien leyera la base, no podría
 * usarlos.
 */

declare(strict_types=1);

final class Verificacion
{
    private const HORAS = 48;

    /** ¿Está verificado ese usuario? */
    public static function verificado(?array $usuario): bool
    {
        return $usuario !== null && !empty($usuario['email_verificado_en']);
    }

    /**
     * Crea un enlace nuevo y lo envía. Devuelve false si el correo no salió.
     *
     * Los enlaces anteriores se invalidan al pedir uno nuevo, para que solo
     * haya uno vivo por cuenta.
     */
    public static function enviar(PDO $pdo, array $usuario): bool
    {
        $correo = trim((string)($usuario['email'] ?? ''));
        if ($correo === '' || self::verificado($usuario)) {
            return false;
        }

        try {
            $pdo->prepare(
                "UPDATE password_resets SET usado_en = NOW()
                  WHERE usuario_id = ? AND tipo = 'verificar_email' AND usado_en IS NULL"
            )->execute([$usuario['id']]);

            $token = bin2hex(random_bytes(32));
            $pdo->prepare(
                "INSERT INTO password_resets (usuario_id, token_hash, expira_en, ip, tipo)
                 VALUES (?, ?, DATE_ADD(NOW(), INTERVAL " . self::HORAS . " HOUR), ?, 'verificar_email')"
            )->execute([$usuario['id'], hash('sha256', $token), ip_cliente()]);
        } catch (PDOException $e) {
            error_log('Flowers Anto — verificación: ' . $e->getMessage());
            return false;
        }

        $tienda = Ajustes::texto('nombre_tienda', 'Flowers Anto');
        $enlace = url_absoluta('cuenta/verificar.php?token=' . $token);

        return Correo::enviar(
            $correo,
            'Confirma tu correo — ' . $tienda,
            Correo::plantilla(
                'Confirma tu correo',
                '<p>Hola ' . e((string)($usuario['nombre'] ?? '')) . ', gracias por crear tu cuenta en '
                . e($tienda) . '.</p>'
                . '<p>Confirma que este correo es tuyo para que podamos enviarte el estado de '
                . 'tus pedidos. El enlace caduca en ' . self::HORAS . ' horas.</p>'
                . '<p style="font-size:13px;color:#8A7A7D;">Si no creaste ninguna cuenta, ignora '
                . 'este correo: sin confirmar no pasa nada.</p>',
                ['url' => $enlace, 'texto' => 'Confirmar mi correo']
            )
        );
    }

    /**
     * Comprueba el token y marca la cuenta como verificada.
     *
     * @return array{ok: bool, error?: string, usuario?: array}
     */
    public static function confirmar(PDO $pdo, string $token): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return ['ok' => false, 'error' => 'Ese enlace no es válido.'];
        }

        $st = $pdo->prepare(
            "SELECT pr.id, pr.usuario_id, u.email, u.nombre, u.email_verificado_en
               FROM password_resets pr
               JOIN usuarios u ON u.id = pr.usuario_id
              WHERE pr.token_hash = ? AND pr.tipo = 'verificar_email'
                AND pr.usado_en IS NULL AND pr.expira_en > NOW()
              LIMIT 1"
        );
        $st->execute([hash('sha256', $token)]);
        $fila = $st->fetch();

        if (!$fila) {
            return ['ok' => false, 'error' => 'Ese enlace ya se usó o caducó. '
                                            . 'Entra a tu cuenta y pide uno nuevo.'];
        }

        $pdo->prepare("UPDATE usuarios SET email_verificado_en = NOW() WHERE id = ?")
            ->execute([$fila['usuario_id']]);
        $pdo->prepare("UPDATE password_resets SET usado_en = NOW() WHERE id = ?")
            ->execute([$fila['id']]);

        Auditoria::registrar($pdo, 'verificar_email', 'usuarios', [
            'recurso_tipo' => 'usuario', 'recurso_id' => (string)$fila['usuario_id'],
            'descripcion'  => 'Correo confirmado por el propio cliente.',
        ]);

        return ['ok' => true, 'usuario' => $fila];
    }
}
