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
 * El token:
 *
 *   · Son 32 bytes de `random_bytes()`: no se deriva del id, del correo ni de
 *     la hora, así que no se puede adivinar ni dice nada de la cuenta.
 *   · Se guarda solo su SHA-256. Quien lea la base no puede usarlo.
 *   · Caduca a las 48 horas y vale una sola vez. El «una sola vez» se decide
 *     en la misma sentencia que lo gasta (`… AND usado_en IS NULL`), no en una
 *     consulta previa: dos clics simultáneos no pueden gastarlo los dos.
 *   · Pedir uno nuevo anula los anteriores: solo hay uno vivo por cuenta.
 *
 * Usa la misma tabla de tokens que la recuperación de contraseña, separada por
 * `tipo`.
 */

declare(strict_types=1);

final class Verificacion
{
    private const HORAS = 48;

    /** Segundos mínimos entre dos envíos a la misma cuenta. */
    public const ESPERA = 60;

    /** Envíos por cuenta y hora. */
    public const POR_HORA = 3;

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

        $conCodigo = CodigoCorreo::disponible($pdo);
        $codigo    = $conCodigo ? CodigoCorreo::generar() : '';

        try {
            $pdo->beginTransaction();
            $pdo->prepare(
                "UPDATE password_resets SET usado_en = NOW()
                  WHERE usuario_id = ? AND tipo = 'verificar_email' AND usado_en IS NULL"
            )->execute([$usuario['id']]);

            $token = bin2hex(random_bytes(32));
            if ($conCodigo) {
                $pdo->prepare(
                    "INSERT INTO password_resets (usuario_id, token_hash, codigo_hash, expira_en, ip, tipo)
                     VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL " . self::HORAS . " HOUR), ?, 'verificar_email')"
                )->execute([$usuario['id'], hash('sha256', $token), CodigoCorreo::hash($codigo), ip_cliente()]);
            } else {
                $pdo->prepare(
                    "INSERT INTO password_resets (usuario_id, token_hash, expira_en, ip, tipo)
                     VALUES (?, ?, DATE_ADD(NOW(), INTERVAL " . self::HORAS . " HOUR), ?, 'verificar_email')"
                )->execute([$usuario['id'], hash('sha256', $token), ip_cliente()]);
            }
            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
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
                . 'tus pedidos.</p>'
                . ($conCodigo
                    ? CodigoCorreo::bloqueHtml($codigo, self::HORAS . ' horas')
                      . '<p>Pulsa el botón o escribe el código en <strong>Mis datos</strong>, dentro de tu cuenta.</p>'
                    : '<p>El enlace caduca en ' . self::HORAS . ' horas y sirve una sola vez.</p>')
                . '<p style="font-size:13px;color:#8A7A7D;">Si no creaste ninguna cuenta, ignora '
                . 'este correo: sin confirmar no pasa nada.</p>',
                ['url' => $enlace, 'texto' => 'Confirmar mi correo']
            )
        );
    }

    /**
     * ¿Se le puede mandar otro enlace a esta cuenta ahora mismo?
     *
     * Dos frenos distintos: una espera corta entre envíos, que corta el doble
     * clic y los reintentos nerviosos, y un tope por hora, que impide usar el
     * botón para llenar el buzón de alguien. La espera se mide contra el último
     * enlace de verdad guardado, no contra un contador aparte: así no se puede
     * esquivar cambiando de IP o de navegador.
     *
     * @return array{ok: bool, espera?: int, mensaje?: string}
     */
    public static function puedeReenviar(PDO $pdo, int $usuarioId): array
    {
        $st = $pdo->prepare(
            "SELECT TIMESTAMPDIFF(SECOND, MAX(created_at), NOW())
               FROM password_resets WHERE usuario_id = ? AND tipo = 'verificar_email'"
        );
        $st->execute([$usuarioId]);
        $hace = $st->fetchColumn();
        if ($hace !== null && $hace !== false && (int)$hace < self::ESPERA) {
            $falta = self::ESPERA - (int)$hace;
            return ['ok' => false, 'espera' => $falta,
                    'mensaje' => 'Acabamos de enviarte un enlace. Espera ' . $falta
                               . ' segundos antes de pedir otro.'];
        }
        if (!limitar($pdo, 'verificar:' . $usuarioId, self::POR_HORA, 3600)) {
            return ['ok' => false,
                    'mensaje' => 'Ya te enviamos varios enlaces. Revisa tu correo —mira también la '
                               . 'carpeta de no deseados— y espera un rato antes de pedir otro.'];
        }
        return ['ok' => true];
    }

    /**
     * Comprueba el token y, si vale, marca la cuenta como verificada.
     *
     * Estados que devuelve, sin mezclar ninguno:
     *
     *   · ok            — el enlace era bueno y la cuenta quedó confirmada.
     *   · ya_verificado — la cuenta ya estaba confirmada. Pasa mucho: los
     *                     filtros de correo abren los enlaces para revisarlos
     *                     y los gastan antes que la persona. No es un error y
     *                     no se presenta como tal.
     *   · reemplazado   — se pidió un enlace más nuevo y este quedó anulado.
     *   · caducado      — pasó el plazo. Se puede pedir otro desde aquí mismo.
     *   · invalido      — no existe, está mal copiado o la cuenta no está
     *                     activa. No se distingue entre esos casos: decir
     *                     «esa cuenta no existe» serviría para sondear cuentas.
     *
     * @return array{estado: string, usuario_id?: int}
     */
    public static function confirmar(PDO $pdo, string $token): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return ['estado' => 'invalido'];
        }

        $st = $pdo->prepare(
            "SELECT pr.id, pr.usuario_id, pr.usado_en, (pr.expira_en > NOW()) AS vigente,
                    u.email_verificado_en, u.activo
               FROM password_resets pr
               JOIN usuarios u ON u.id = pr.usuario_id
              WHERE pr.token_hash = ? AND pr.tipo = 'verificar_email'
              LIMIT 1"
        );
        $st->execute([hash('sha256', $token)]);
        $fila = $st->fetch();

        if (!$fila || (int)$fila['activo'] !== 1) {
            return ['estado' => 'invalido'];
        }
        $usuarioId = (int)$fila['usuario_id'];

        if (!empty($fila['email_verificado_en'])) {
            return ['estado' => 'ya_verificado', 'usuario_id' => $usuarioId];
        }
        if ($fila['usado_en'] !== null) {
            return ['estado' => 'reemplazado', 'usuario_id' => $usuarioId];
        }
        if ((int)$fila['vigente'] !== 1) {
            return ['estado' => 'caducado', 'usuario_id' => $usuarioId];
        }

        return ['estado' => self::gastarYConfirmar($pdo, (int)$fila['id'], $usuarioId, 'enlace'),
                'usuario_id' => $usuarioId];
    }

    /**
     * Confirma el correo con el código de 6 dígitos del mismo correo.
     *
     * Solo desde la sesión de la propia cuenta: el código no lleva consigo
     * a qué cuenta pertenece, así que se busca la solicitud de quien está
     * dentro.
     *
     * @return 'ok'|'ya_verificado'|'incorrecto'|'agotado'
     */
    public static function confirmarCodigo(PDO $pdo, array $usuario, string $codigo): string
    {
        if (self::verificado($usuario)) {
            return 'ya_verificado';
        }
        $fila = null;
        if (CodigoCorreo::disponible($pdo)) {
            $st = $pdo->prepare(
                "SELECT id, codigo_hash, intentos_codigo FROM password_resets
                  WHERE usuario_id = ? AND tipo = 'verificar_email'
                    AND usado_en IS NULL AND expira_en > NOW()
                  ORDER BY id DESC LIMIT 1"
            );
            $st->execute([$usuario['id']]);
            $fila = $st->fetch() ?: null;
        }

        $estado = CodigoCorreo::comprobar($pdo, $fila, $codigo);
        if ($estado !== 'ok') {
            return $estado;
        }
        $final = self::gastarYConfirmar($pdo, (int)$fila['id'], (int)$usuario['id'], 'código');
        return in_array($final, ['ok', 'ya_verificado'], true) ? $final : 'incorrecto';
    }

    /**
     * Gasta la solicitud y marca la cuenta como confirmada.
     *
     * La solicitud se gasta aquí y solo si sigue libre y en plazo. Si otra
     * petición la gastó un instante antes, esta no toca nada.
     */
    private static function gastarYConfirmar(PDO $pdo, int $filaId, int $usuarioId, string $via): string
    {
        $pdo->beginTransaction();
        try {
            $gasta = $pdo->prepare(
                "UPDATE password_resets SET usado_en = NOW()
                  WHERE id = ? AND usado_en IS NULL AND expira_en > NOW()"
            );
            $gasta->execute([$filaId]);

            if ($gasta->rowCount() !== 1) {
                $pdo->rollBack();
                $ahora = $pdo->prepare("SELECT email_verificado_en FROM usuarios WHERE id = ?");
                $ahora->execute([$usuarioId]);
                return $ahora->fetchColumn() ? 'ya_verificado' : 'reemplazado';
            }

            $pdo->prepare(
                "UPDATE usuarios SET email_verificado_en = COALESCE(email_verificado_en, NOW()) WHERE id = ?"
            )->execute([$usuarioId]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Flowers Anto — confirmar correo: ' . $e->getMessage());
            return 'invalido';
        }

        Auditoria::registrar($pdo, 'verificar_email', 'usuarios', [
            'recurso_tipo' => 'usuario', 'recurso_id' => (string)$usuarioId,
            'descripcion'  => 'Correo confirmado por el propio cliente (' . $via . ').',
        ]);
        return 'ok';
    }

    /** Correo con el nombre tapado: «ma•••@gmail.com». */
    public static function correoTapado(string $correo): string
    {
        $partes = explode('@', $correo, 2);
        if (count($partes) !== 2) {
            return '';
        }
        $visible = mb_substr($partes[0], 0, min(2, mb_strlen($partes[0])));
        return $visible . '•••@' . $partes[1];
    }
}
