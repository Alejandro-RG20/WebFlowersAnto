<?php
/**
 * Códigos de verificación por correo y recuperación de contraseña.
 *
 * Por qué un código y no un JWT
 * -----------------------------
 * Un JWT sirve para que un servidor se fíe de un dato sin consultarlo: lleva
 * la información dentro y una firma. Justo por eso no se puede anular antes
 * de que caduque, ni contar los intentos, ni gastar una sola vez sin guardar
 * algo en la base, que es lo único que de verdad importa aquí. Facebook,
 * Google o los bancos hacen lo mismo que este archivo: un número corto al
 * azar, guardado en el servidor, que caduca pronto, vale una vez y se
 * bloquea tras unos pocos fallos.
 *
 * Reglas
 * ------
 *   · 6 dígitos de `random_int()`: un millón de combinaciones.
 *   · Se guarda su hash con password_hash(). Quien lea la base no tiene el
 *     código, y probarlos todos contra un hash lento lleva más de lo que
 *     dura la solicitud.
 *   · 5 intentos por solicitud. El contador sube en la misma sentencia que
 *     comprueba el tope (`… AND intentos_codigo < 5`): cinco envíos a la vez
 *     no consiguen un sexto intento. Al agotarlos la solicitud muere entera,
 *     enlace incluido.
 *   · Con 5 intentos y 3 códigos por hora, adivinar sale a 15 entre un
 *     millón cada hora.
 *   · Un solo código vivo por cuenta y propósito: pedir otro anula el
 *     anterior.
 *   · Los mensajes no dicen si una cuenta existe.
 *
 * Todo vive en `password_resets`, separado por `tipo`. Sin la migración 024
 * no hay columna para el código y todo funciona como antes, solo con enlaces.
 */

declare(strict_types=1);

final class CodigoCorreo
{
    public const DIGITOS  = 6;
    public const INTENTOS = 5;

    private static ?bool $disponible = null;

    /** ¿Está aplicada la migración que guarda los códigos? */
    public static function disponible(PDO $pdo): bool
    {
        if (self::$disponible === null) {
            try {
                $pdo->query("SELECT codigo_hash, intentos_codigo, destino FROM password_resets LIMIT 0");
                self::$disponible = true;
            } catch (PDOException) {
                self::$disponible = false;
            }
        }
        return self::$disponible;
    }

    public static function generar(): string
    {
        return str_pad((string)random_int(0, 10 ** self::DIGITOS - 1), self::DIGITOS, '0', STR_PAD_LEFT);
    }

    public static function hash(string $codigo): string
    {
        return password_hash($codigo, PASSWORD_DEFAULT);
    }

    /**
     * Deja solo los dígitos de lo que escribió la persona.
     *
     * Del correo al formulario el código llega con espacios («482 913») o
     * con guiones si se copió de otro sitio. Devuelve '' si no son 6 dígitos.
     */
    public static function limpiar(string $entrada): string
    {
        $digitos = preg_replace('/\D+/', '', $entrada) ?? '';
        return strlen($digitos) === self::DIGITOS ? $digitos : '';
    }

    /**
     * Comprueba un código contra una fila de `password_resets`.
     *
     * La fila llega ya filtrada por quien llama (cuenta, tipo, sin usar, en
     * plazo). Aquí se gasta un intento y se compara. Si se agotan los
     * intentos, la solicitud se anula entera.
     *
     * @return 'ok'|'incorrecto'|'agotado'
     */
    public static function comprobar(PDO $pdo, ?array $fila, string $codigo): string
    {
        if ($fila === null || empty($fila['codigo_hash']) || $codigo === '') {
            // Mismo trabajo que con una fila de verdad: el tiempo de
            // respuesta no delata si había solicitud o no.
            password_verify($codigo, self::falso());
            return 'incorrecto';
        }

        $gasta = $pdo->prepare(
            "UPDATE password_resets SET intentos_codigo = intentos_codigo + 1
              WHERE id = ? AND usado_en IS NULL AND intentos_codigo < " . self::INTENTOS
        );
        $gasta->execute([$fila['id']]);
        if ($gasta->rowCount() !== 1) {
            self::anular($pdo, (int)$fila['id']);
            return 'agotado';
        }

        if (password_verify($codigo, (string)$fila['codigo_hash'])) {
            return 'ok';
        }

        if ((int)$fila['intentos_codigo'] + 1 >= self::INTENTOS) {
            self::anular($pdo, (int)$fila['id']);
            return 'agotado';
        }
        return 'incorrecto';
    }

    /** «30 minutos», «24 horas»: el plazo como lo diría una persona. */
    public static function plazo(int $minutos): string
    {
        return $minutos >= 120 ? intdiv($minutos, 60) . ' horas' : $minutos . ' minutos';
    }

    public static function anular(PDO $pdo, int $filaId): void
    {
        $pdo->prepare("UPDATE password_resets SET usado_en = NOW() WHERE id = ? AND usado_en IS NULL")
            ->execute([$filaId]);
    }

    /** El código dentro del correo, grande y fácil de copiar. */
    public static function bloqueHtml(string $codigo, string $plazo): string
    {
        $separado = substr($codigo, 0, 3) . ' ' . substr($codigo, 3);
        return '<p style="margin:22px 0 6px;font-size:13px;color:#8A7A7D;">Tu código</p>'
            . '<p style="margin:0 0 6px;font-size:32px;font-weight:700;letter-spacing:6px;'
            . 'font-family:\'SFMono-Regular\',Consolas,\'Liberation Mono\',monospace;">' . e($separado) . '</p>'
            . '<p style="margin:0 0 18px;font-size:13px;color:#8A7A7D;">Caduca en ' . e($plazo)
            . ' y sirve una sola vez. <strong>Nunca te lo pediremos por teléfono, '
            . 'WhatsApp ni redes sociales</strong>: no se lo des a nadie.</p>';
    }

    /** Un hash cualquiera para igualar tiempos cuando no hay fila. */
    private static function falso(): string
    {
        static $hash = null;
        return $hash ??= password_hash('000000-falso', PASSWORD_DEFAULT);
    }
}

/**
 * Recuperación de contraseña: enlace y código en el mismo correo.
 *
 * La persona puede pulsar el enlace o escribir el código en la tienda; las
 * dos cosas llevan a la misma pantalla de «contraseña nueva» y gastan la
 * misma solicitud. Lo usan el formulario «Olvidé mi contraseña» y el botón
 * del panel que se la envía a un cliente.
 */
final class Recuperacion
{
    /** Minutos de vida cuando la pide el propio cliente. */
    public const MINUTOS_CLIENTE = 60;

    /**
     * Minutos de vida cuando la envía la tienda desde el panel. El cliente
     * no la está esperando con el correo abierto: un día da margen.
     */
    public const MINUTOS_PANEL = 1440;

    /**
     * Crea una solicitud nueva y la envía. Las anteriores dejan de servir.
     *
     * $alTerminar envía el correo después de responder, para que el
     * formulario público tarde lo mismo exista o no la cuenta.
     */
    public static function emitir(PDO $pdo, array $usuario, string $origen = 'cliente', bool $alTerminar = false): bool
    {
        $correo = trim((string)($usuario['email'] ?? ''));
        if ($correo === '') {
            return false;
        }
        $minutos   = $origen === 'panel' ? self::MINUTOS_PANEL : self::MINUTOS_CLIENTE;
        $conCodigo = CodigoCorreo::disponible($pdo);
        $token     = bin2hex(random_bytes(32));
        $codigo    = $conCodigo ? CodigoCorreo::generar() : '';

        try {
            $pdo->beginTransaction();
            $pdo->prepare(
                "UPDATE password_resets SET usado_en = NOW()
                  WHERE usuario_id = ? AND tipo = 'password' AND usado_en IS NULL"
            )->execute([$usuario['id']]);

            if ($conCodigo) {
                $pdo->prepare(
                    "INSERT INTO password_resets (usuario_id, token_hash, codigo_hash, expira_en, ip, tipo)
                     VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL $minutos MINUTE), ?, 'password')"
                )->execute([$usuario['id'], hash('sha256', $token), CodigoCorreo::hash($codigo), ip_cliente()]);
            } else {
                $pdo->prepare(
                    "INSERT INTO password_resets (usuario_id, token_hash, expira_en, ip, tipo)
                     VALUES (?, ?, DATE_ADD(NOW(), INTERVAL $minutos MINUTE), ?, 'password')"
                )->execute([$usuario['id'], hash('sha256', $token), ip_cliente()]);
            }
            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Flowers Anto — recuperación: ' . $e->getMessage());
            return false;
        }

        $tienda  = Ajustes::texto('nombre_tienda', 'Flowers Anto');
        $plazo   = CodigoCorreo::plazo($minutos);
        $intro   = $origen === 'panel'
            ? '<p>Hola ' . e((string)($usuario['nombre'] ?? '')) . ', desde ' . e($tienda)
              . ' te enviamos este correo para que puedas crear una contraseña nueva para tu cuenta.</p>'
            : '<p>Hola ' . e((string)($usuario['nombre'] ?? '')) . ', recibimos una solicitud para '
              . 'cambiar la contraseña de tu cuenta.</p>';
        $cuerpo  = $intro
            . ($conCodigo
                ? CodigoCorreo::bloqueHtml($codigo, $plazo)
                  . '<p>Escribe el código en la página de recuperación, o pulsa el botón. '
                  . 'Las dos cosas llevan al mismo sitio.</p>'
                : '<p>El enlace funciona <strong>una sola vez</strong> y caduca en ' . e($plazo) . '.</p>')
            . '<p style="font-size:13px;color:#8A7A7D;">Si no pediste nada, ignora este correo: '
            . 'tu contraseña sigue siendo la misma.</p>';

        // El código no va en el asunto: el asunto sale en la pantalla
        // bloqueada del teléfono, a la vista de cualquiera.
        $asunto = ($conCodigo ? 'Tu código para cambiar la contraseña — ' : 'Restablecer tu contraseña — ') . $tienda;
        $html   = Correo::plantilla('Restablecer tu contraseña', $cuerpo,
            ['url' => url_absoluta('cuenta/restablecer.php?token=' . $token), 'texto' => 'Cambiar mi contraseña']);

        if ($alTerminar) {
            Correo::enviarAlTerminar($correo, $asunto, $html);
            return true;
        }
        return Correo::enviar($correo, $asunto, $html);
    }

    /**
     * Comprueba el código escrito para el correo dado.
     *
     * Devuelve la huella del token de la solicitud si el código es bueno: es
     * lo que `cuenta/restablecer.php` guarda en la sesión al abrir el enlace,
     * así que a partir de aquí el camino es el mismo.
     *
     * @return array{estado: 'ok'|'incorrecto'|'agotado', huella?: string}
     */
    public static function comprobarCodigo(PDO $pdo, string $correo, string $codigo): array
    {
        $fila = null;
        if ($correo !== '' && CodigoCorreo::disponible($pdo)) {
            $st = $pdo->prepare(
                "SELECT pr.id, pr.token_hash, pr.codigo_hash, pr.intentos_codigo
                   FROM password_resets pr
                   JOIN usuarios u ON u.id = pr.usuario_id
                  WHERE u.email = ? AND u.activo = 1
                    AND pr.tipo = 'password' AND pr.usado_en IS NULL AND pr.expira_en > NOW()
                  ORDER BY pr.id DESC LIMIT 1"
            );
            $st->execute([$correo]);
            $fila = $st->fetch() ?: null;
        }

        $estado = CodigoCorreo::comprobar($pdo, $fila, $codigo);
        return $estado === 'ok'
            ? ['estado' => 'ok', 'huella' => (string)$fila['token_hash']]
            : ['estado' => $estado];
    }
}

/**
 * Cambio de correo confirmado con código.
 *
 * El correo de una cuenta es la llave de la recuperación de contraseña. Si
 * bastara con escribir uno nuevo, quien tuviera un momento la sesión abierta
 * (un teléfono prestado) se quedaba con la cuenta. Por eso el cambio queda
 * pendiente y solo se aplica al escribir el código que llega a la dirección
 * NUEVA: así se demuestra a la vez que es de quien la pide y que existe. Al
 * correo anterior se le avisa cuando el cambio se hace.
 */
final class CambioCorreo
{
    public const MINUTOS = 30;

    /** La solicitud pendiente de esta cuenta, o null. */
    public static function pendiente(PDO $pdo, int $usuarioId): ?array
    {
        if (!CodigoCorreo::disponible($pdo)) {
            return null;
        }
        $st = $pdo->prepare(
            "SELECT id, destino, codigo_hash, intentos_codigo, expira_en FROM password_resets
              WHERE usuario_id = ? AND tipo = 'cambiar_email' AND usado_en IS NULL AND expira_en > NOW()
              ORDER BY id DESC LIMIT 1"
        );
        $st->execute([$usuarioId]);
        return $st->fetch() ?: null;
    }

    /** Abre la solicitud y manda el código al correo nuevo. */
    public static function iniciar(PDO $pdo, array $usuario, string $nuevo): bool
    {
        $codigo = CodigoCorreo::generar();
        try {
            $pdo->beginTransaction();
            self::cancelar($pdo, (int)$usuario['id']);
            // El token no se envía a nadie: la columna lo exige y así la
            // fila tiene la misma forma que las demás.
            $pdo->prepare(
                "INSERT INTO password_resets (usuario_id, token_hash, codigo_hash, destino, expira_en, ip, tipo)
                 VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL " . self::MINUTOS . " MINUTE), ?, 'cambiar_email')"
            )->execute([$usuario['id'], hash('sha256', bin2hex(random_bytes(32))),
                        CodigoCorreo::hash($codigo), $nuevo, ip_cliente()]);
            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Flowers Anto — cambio de correo: ' . $e->getMessage());
            return false;
        }

        $tienda = Ajustes::texto('nombre_tienda', 'Flowers Anto');
        return Correo::enviar(
            $nuevo,
            'Confirma tu correo nuevo — ' . $tienda,
            Correo::plantilla(
                'Confirma tu correo nuevo',
                '<p>Hola ' . e((string)($usuario['nombre'] ?? '')) . ', pediste usar esta dirección '
                . 'para tu cuenta en ' . e($tienda) . '.</p>'
                . CodigoCorreo::bloqueHtml($codigo, CodigoCorreo::plazo(self::MINUTOS))
                . '<p>Escríbelo en <strong>Mis datos</strong>, dentro de tu cuenta. Hasta entonces '
                . 'tu cuenta sigue con el correo de antes.</p>'
                . '<p style="font-size:13px;color:#8A7A7D;">Si no fuiste tú, ignora este correo: '
                . 'sin el código no cambia nada.</p>'
            )
        );
    }

    /**
     * Aplica el cambio si el código es bueno.
     *
     * @return array{estado: 'ok'|'incorrecto'|'agotado'|'ocupado', anterior?: string, nuevo?: string}
     */
    public static function confirmar(PDO $pdo, array $usuario, string $codigo): array
    {
        $fila   = self::pendiente($pdo, (int)$usuario['id']);
        $estado = CodigoCorreo::comprobar($pdo, $fila, $codigo);
        if ($estado !== 'ok') {
            return ['estado' => $estado];
        }

        $nuevo    = (string)$fila['destino'];
        $anterior = (string)$usuario['email'];
        $pdo->beginTransaction();
        try {
            $gasta = $pdo->prepare(
                "UPDATE password_resets SET usado_en = NOW()
                  WHERE id = ? AND usado_en IS NULL AND expira_en > NOW()"
            );
            $gasta->execute([$fila['id']]);
            if ($gasta->rowCount() !== 1) {
                $pdo->rollBack();
                return ['estado' => 'incorrecto'];
            }

            // El código llegó al correo nuevo: queda confirmado.
            $pdo->prepare("UPDATE usuarios SET email = ?, email_verificado_en = NOW() WHERE id = ?")
                ->execute([$nuevo, $usuario['id']]);

            // Lo que estuviera pendiente iba al correo anterior.
            $pdo->prepare("UPDATE password_resets SET usado_en = NOW() WHERE usuario_id = ? AND usado_en IS NULL")
                ->execute([$usuario['id']]);
            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            // Otra cuenta se quedó con ese correo mientras tanto.
            if ($e->getCode() === '23000') {
                return ['estado' => 'ocupado'];
            }
            error_log('Flowers Anto — confirmar cambio de correo: ' . $e->getMessage());
            return ['estado' => 'incorrecto'];
        }

        Auditoria::registrar($pdo, 'cambiar_email', 'usuarios', [
            'recurso_tipo' => 'usuario', 'recurso_id' => (string)$usuario['id'],
            'descripcion'  => 'El cliente cambió su correo, confirmado con código.',
            'detalles'     => ['antes' => $anterior, 'despues' => $nuevo],
        ]);

        Correo::enviar($anterior, 'El correo de tu cuenta cambió',
            Correo::plantilla('El correo de tu cuenta cambió',
                '<p>Hola ' . e((string)$usuario['nombre']) . ', el correo de tu cuenta en '
                . e(Ajustes::texto('nombre_tienda', 'Flowers Anto')) . ' ahora es <strong>'
                . e(Verificacion::correoTapado($nuevo)) . '</strong>.</p>'
                . '<p>Si no fuiste tú, escríbenos cuanto antes'
                . (trim(Ajustes::texto('email_contacto', '')) !== ''
                    ? ' a ' . e(trim(Ajustes::texto('email_contacto', ''))) : '') . '.</p>'));

        return ['estado' => 'ok', 'anterior' => $anterior, 'nuevo' => $nuevo];
    }

    public static function cancelar(PDO $pdo, int $usuarioId): void
    {
        $pdo->prepare(
            "UPDATE password_resets SET usado_en = NOW()
              WHERE usuario_id = ? AND tipo = 'cambiar_email' AND usado_en IS NULL"
        )->execute([$usuarioId]);
    }
}
