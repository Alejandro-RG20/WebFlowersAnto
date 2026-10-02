<?php
/**
 * Avisos de la tienda a un cliente, cumpleaños y cupones personales.
 *
 * Un aviso lo escribe alguien del panel desde la ficha del cliente: un aviso
 * general, una sugerencia, una advertencia (por un uso indebido, por ejemplo)
 * o una felicitación de cumpleaños. El cliente lo ve en «Mis avisos», con un
 * contador en la cabecera, y si se pidió le llega también por correo.
 *
 * Reglas:
 *   · El texto se guarda tal cual y se pinta siempre escapado. No admite HTML:
 *     un aviso no puede convertirse en un enlace de phishing con la cara de la
 *     tienda ni colar un script en la cuenta del cliente.
 *   · El cupón de cumpleaños es un cupón NUEVO creado a partir de uno de los
 *     existentes (mismo tipo, valor, mínimo y tope), de un solo uso y con
 *     dueño: solo lo canjea esa cuenta, con la sesión iniciada. El cupón de
 *     plantilla no se toca ni se reparte.
 *   · Límites: 10 avisos por cliente al día y 40 por persona del panel cada
 *     hora, para que un error o una cuenta robada del panel no sirva para
 *     llenar el buzón de nadie.
 *
 * Sin la migración 025 la clase dice que no está disponible y nada aparece.
 */

declare(strict_types=1);

final class Avisos
{
    /** [etiqueta, icono] de cada tipo. El orden es el del selector del panel. */
    public const TIPOS = [
        'aviso'       => ['Aviso',            'fa-circle-info'],
        'sugerencia'  => ['Sugerencia',       'fa-lightbulb'],
        'advertencia' => ['Advertencia',      'fa-triangle-exclamation'],
        'cumpleanos'  => ['Feliz cumpleaños', 'fa-cake-candles'],
    ];

    public const VIGENCIAS = [7, 15, 30];

    /** Días antes del cumpleaños en que ya se avisa en el panel. */
    public const DIAS_AVISO = 7;

    private static ?bool $disponible = null;
    private static array $noLeidos = [];

    public static function disponible(PDO $pdo): bool
    {
        if (self::$disponible === null) {
            try {
                $pdo->query("SELECT 1 FROM notificaciones LIMIT 0");
                $pdo->query("SELECT usuario_id FROM cupones LIMIT 0");
                self::$disponible = true;
            } catch (PDOException) {
                self::$disponible = false;
            }
        }
        return self::$disponible;
    }

    public static function noLeidos(PDO $pdo, int $usuarioId): int
    {
        if ($usuarioId <= 0 || !self::disponible($pdo)) {
            return 0;
        }
        if (!isset(self::$noLeidos[$usuarioId])) {
            $st = $pdo->prepare("SELECT COUNT(*) FROM notificaciones WHERE usuario_id = ? AND leida_en IS NULL");
            $st->execute([$usuarioId]);
            self::$noLeidos[$usuarioId] = (int)$st->fetchColumn();
        }
        return self::$noLeidos[$usuarioId];
    }

    /** Los avisos de una cuenta con su cupón, si lo tienen. Los más nuevos primero. */
    public static function deUsuario(PDO $pdo, int $usuarioId, int $limite = 50): array
    {
        if (!self::disponible($pdo)) {
            return [];
        }
        $st = $pdo->prepare(
            "SELECT n.*, c.codigo AS cupon_codigo, c.tipo AS cupon_tipo, c.valor AS cupon_valor,
                    c.compra_minima AS cupon_minima, c.fecha_fin AS cupon_hasta,
                    c.usos AS cupon_usos, c.usos_maximos AS cupon_maximos, c.activo AS cupon_activo,
                    TRIM(CONCAT(a.nombre, ' ', a.apellido)) AS autor
               FROM notificaciones n
          LEFT JOIN cupones c  ON c.id = n.cupon_id
          LEFT JOIN usuarios a ON a.id = n.enviado_por
              WHERE n.usuario_id = ?
           ORDER BY n.created_at DESC, n.id DESC
              LIMIT " . max(1, min(200, $limite))
        );
        $st->execute([$usuarioId]);
        return $st->fetchAll();
    }

    public static function marcarLeidos(PDO $pdo, int $usuarioId): void
    {
        if (!self::disponible($pdo)) {
            return;
        }
        $pdo->prepare("UPDATE notificaciones SET leida_en = NOW() WHERE usuario_id = ? AND leida_en IS NULL")
            ->execute([$usuarioId]);
        self::$noLeidos[$usuarioId] = 0;
    }

    /**
     * Estado del cupón de un aviso, para pintarlo.
     *
     * @return 'disponible'|'usado'|'vencido'|'anulado'
     */
    public static function estadoCupon(array $aviso): string
    {
        if ((int)($aviso['cupon_activo'] ?? 0) !== 1) {
            return 'anulado';
        }
        $maximos = (int)($aviso['cupon_maximos'] ?? 0);
        if ($maximos > 0 && (int)($aviso['cupon_usos'] ?? 0) >= $maximos) {
            return 'usado';
        }
        if (!empty($aviso['cupon_hasta']) && (string)$aviso['cupon_hasta'] < date('Y-m-d')) {
            return 'vencido';
        }
        return 'disponible';
    }

    /**
     * Manda un aviso. Crea antes el cupón personal si se pidió.
     *
     * @param array|null $plantilla Cupón existente del que copiar el descuento.
     * @return array{ok: bool, error?: string, correo?: bool, cupon?: string}
     */
    public static function enviar(
        PDO $pdo,
        array $cliente,
        string $tipo,
        string $titulo,
        string $mensaje,
        bool $porCorreo,
        ?array $plantilla = null,
        int $vigencia = 15
    ): array {
        if (!self::disponible($pdo)) {
            return ['ok' => false, 'error' => 'Falta aplicar la migración 025 en Base de datos.'];
        }
        if (!isset(self::TIPOS[$tipo])) {
            return ['ok' => false, 'error' => 'Elige el tipo de aviso.'];
        }
        $titulo  = trim($titulo);
        $mensaje = trim($mensaje);
        if (mb_strlen($titulo) < 3 || mb_strlen($mensaje) < 3) {
            return ['ok' => false, 'error' => 'Escribe un título y un mensaje.'];
        }
        $autor = Auth::id();
        if (!limitar($pdo, 'avisos-cliente:' . (int)$cliente['id'], 10, 86400)
            || !limitar($pdo, 'avisos-panel:' . (int)$autor, 40, 3600)) {
            return ['ok' => false, 'error' => 'Se enviaron demasiados avisos seguidos. Espera un rato.'];
        }

        $cupon = null;
        try {
            $pdo->beginTransaction();
            if ($plantilla !== null) {
                $cupon = self::crearCuponPersonal($pdo, $plantilla, $cliente,
                    in_array($vigencia, self::VIGENCIAS, true) ? $vigencia : 15);
            }
            $pdo->prepare(
                "INSERT INTO notificaciones (usuario_id, tipo, titulo, mensaje, cupon_id, enviado_por, por_correo)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            )->execute([(int)$cliente['id'], $tipo, mb_substr($titulo, 0, 120), mb_substr($mensaje, 0, 2000),
                        $cupon['id'] ?? null, $autor, $porCorreo ? 1 : 0]);
            $avisoId = (int)$pdo->lastInsertId();
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Flowers Anto — aviso al cliente: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'No se pudo guardar el aviso. Inténtalo otra vez.'];
        }

        $correoOk = false;
        if ($porCorreo && trim((string)$cliente['email']) !== '') {
            $correoOk = Correo::enviar((string)$cliente['email'], $titulo,
                self::correoHtml($cliente, $tipo, $titulo, $mensaje, $cupon));
            if ($correoOk) {
                $pdo->prepare("UPDATE notificaciones SET correo_enviado = 1 WHERE id = ?")->execute([$avisoId]);
            }
        }

        Auditoria::registrar($pdo, 'enviar_aviso', 'usuarios', [
            'recurso_tipo' => 'usuario', 'recurso_id' => (string)$cliente['id'],
            'descripcion'  => self::TIPOS[$tipo][0] . ' a ' . $cliente['email'] . ': «' . mb_substr($titulo, 0, 80) . '»'
                            . ($cupon ? ' con el cupón ' . $cupon['codigo'] : ''),
        ]);

        return ['ok' => true, 'correo' => $correoOk, 'cupon' => $cupon['codigo'] ?? ''];
    }

    /** Cupones que se pueden regalar: activos, vigentes y sin dueño. */
    public static function plantillasCupon(PDO $pdo): array
    {
        if (!self::disponible($pdo)) {
            return [];
        }
        return $pdo->query(
            "SELECT * FROM cupones
              WHERE activo = 1 AND usuario_id IS NULL
                AND (fecha_fin IS NULL OR fecha_fin >= CURDATE())
                AND (usos_maximos = 0 OR usos < usos_maximos)
           ORDER BY codigo"
        )->fetchAll();
    }

    /**
     * Clientes que cumplen años hoy o en los próximos días.
     *
     * Quien nació un 29 de febrero lo celebra el 28 los años que no son
     * bisiestos: si no, solo aparecería una vez cada cuatro años.
     *
     * @return list<array> con 'dias' (0 = hoy) y 'felicitado' (fecha o null)
     */
    public static function cumpleaneros(PDO $pdo, int $dias = self::DIAS_AVISO): array
    {
        if (!self::disponible($pdo) || !FotoPerfil::disponible($pdo)) {
            return [];
        }
        $claves = [];
        for ($i = 0; $i <= $dias; $i++) {
            $t = strtotime("+$i days", strtotime('today'));
            $claves[date('m-d', $t)] = $i;
            if (date('m-d', $t) === '02-28' && !checkdate(2, 29, (int)date('Y', $t))) {
                $claves['02-29'] = $i;
            }
        }
        $marcas = implode(',', array_fill(0, count($claves), '?'));
        $st = $pdo->prepare(
            "SELECT u.id, u.nombre, u.apellido, u.email, u.fecha_nacimiento, u.foto,
                    DATE_FORMAT(u.fecha_nacimiento, '%m-%d') AS md,
                    (SELECT MAX(DATE(n.created_at)) FROM notificaciones n
                      WHERE n.usuario_id = u.id AND n.tipo = 'cumpleanos'
                        AND n.created_at >= DATE_FORMAT(CURDATE(), '%Y-01-01')) AS felicitado
               FROM usuarios u JOIN roles r ON r.id = u.rol_id
              WHERE r.codigo = 'cliente' AND u.activo = 1 AND u.fecha_nacimiento IS NOT NULL
                AND DATE_FORMAT(u.fecha_nacimiento, '%m-%d') IN ($marcas)"
        );
        $st->execute(array_keys($claves));
        $lista = [];
        foreach ($st->fetchAll() as $f) {
            $f['dias'] = $claves[$f['md']];
            $lista[] = $f;
        }
        usort($lista, fn($a, $b) => [$a['dias'], $a['nombre']] <=> [$b['dias'], $b['nombre']]);
        return $lista;
    }

    /** Días que faltan para el cumpleaños (0 = hoy), o null si no hay fecha. */
    public static function diasParaCumple(?string $fecha): ?int
    {
        if (!$fecha || !preg_match('/^\d{4}-(\d{2})-(\d{2})$/', $fecha, $m)) {
            return null;
        }
        $hoy  = new DateTimeImmutable('today');
        $anio = (int)$hoy->format('Y');
        foreach ([$anio, $anio + 1] as $a) {
            $dia = ($m[1] === '02' && $m[2] === '29' && !checkdate(2, 29, $a)) ? '28' : $m[2];
            $cumple = new DateTimeImmutable("$a-{$m[1]}-$dia");
            if ($cumple >= $hoy) {
                return (int)$hoy->diff($cumple)->days;
            }
        }
        return null;
    }

    /** Fecha en que se le felicitó este año, o null. */
    public static function felicitadoEsteAnio(PDO $pdo, int $usuarioId): ?string
    {
        if (!self::disponible($pdo)) {
            return null;
        }
        $st = $pdo->prepare(
            "SELECT MAX(created_at) FROM notificaciones
              WHERE usuario_id = ? AND tipo = 'cumpleanos' AND created_at >= DATE_FORMAT(CURDATE(), '%Y-01-01')"
        );
        $st->execute([$usuarioId]);
        $f = $st->fetchColumn();
        return $f ? (string)$f : null;
    }

    /**
     * Crea un cupón de un solo uso para esta cuenta copiando el descuento de
     * otro. Va dentro de la transacción del aviso: si el aviso falla, el
     * cupón no queda suelto.
     */
    private static function crearCuponPersonal(PDO $pdo, array $plantilla, array $cliente, int $vigencia): array
    {
        $alfabeto = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';   // sin 0/O ni 1/I: se dictan sin dudas
        for ($intento = 0; $intento < 6; $intento++) {
            $codigo = 'CUMPLE-';
            for ($i = 0; $i < 6; $i++) {
                $codigo .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
            }
            try {
                $pdo->prepare(
                    "INSERT INTO cupones (codigo, descripcion, tipo, valor, compra_minima, descuento_maximo,
                                          usos_maximos, usos_por_cliente, fecha_inicio, fecha_fin, activo, usuario_id)
                     VALUES (?, ?, ?, ?, ?, ?, 1, 1, CURDATE(), DATE_ADD(CURDATE(), INTERVAL $vigencia DAY), 1, ?)"
                )->execute([
                    $codigo,
                    mb_substr('Regalo de cumpleaños para ' . trim($cliente['nombre'] . ' ' . $cliente['apellido'])
                        . ' (copia de ' . $plantilla['codigo'] . ')', 0, 255),
                    $plantilla['tipo'], $plantilla['valor'], $plantilla['compra_minima'],
                    $plantilla['descuento_maximo'], (int)$cliente['id'],
                ]);
                $id = (int)$pdo->lastInsertId();
                $st = $pdo->prepare("SELECT * FROM cupones WHERE id = ?");
                $st->execute([$id]);
                return $st->fetch();
            } catch (PDOException $e) {
                if ($e->getCode() !== '23000') {
                    throw $e;
                }
                // Código repetido: se prueba con otro.
            }
        }
        throw new RuntimeException('No se pudo generar un código de cupón único.');
    }

    private static function correoHtml(array $cliente, string $tipo, string $titulo, string $mensaje, ?array $cupon): string
    {
        $cuerpo = '<p>Hola ' . e((string)$cliente['nombre']) . ',</p>'
                . '<p>' . nl2br(e($mensaje)) . '</p>';
        if ($cupon) {
            $cuerpo .= '<p style="margin:22px 0 6px;font-size:13px;color:#8A7A7D;">Tu cupón</p>'
                . '<p style="margin:0 0 6px;font-size:26px;font-weight:700;letter-spacing:3px;'
                . 'font-family:\'SFMono-Regular\',Consolas,\'Liberation Mono\',monospace;">' . e((string)$cupon['codigo']) . '</p>'
                . '<p style="margin:0 0 18px;font-size:13px;color:#8A7A7D;">' . e(Cupones::resumen($cupon))
                . ((float)$cupon['compra_minima'] > 0 ? ' en compras desde ' . e(dinero($cupon['compra_minima'])) : '')
                . '. Válido hasta el ' . e(date('d/m/Y', strtotime((string)$cupon['fecha_fin'])))
                . ', una sola vez y solo con tu cuenta.</p>';
        }
        return Correo::plantilla($titulo, $cuerpo, [
            'url'   => url_absoluta($cupon ? 'productos.php' : 'cuenta/avisos.php'),
            'texto' => $cupon ? 'Elegir mi arreglo' : 'Ver en mi cuenta',
        ]);
    }
}
