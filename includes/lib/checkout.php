<?php
/**
 * Lectura y validación del formulario de pedido.
 *
 * Vive aparte de `checkout.php` porque hay dos caminos que necesitan las
 * MISMAS reglas: el envío normal del formulario y el pago con PayPal, que
 * tiene que comprobarlo todo ANTES de abrir la ventana de pago. Si esto
 * estuviera escrito dos veces, un día divergirían y alguien acabaría pagando
 * un pedido que la web luego rechaza por una dirección corta.
 */

declare(strict_types=1);

final class Checkout
{
    /**
     * Lee el formulario, lo valida y calcula el total con la zona elegida.
     *
     * @param array $fuente normalmente $_POST
     * @return array{datos: array, errores: array, zona: ?array, detalle: array}
     */
    public static function revisar(PDO $pdo, array $fuente): array
    {
        $usuario        = Auth::usuario();
        $zonas          = Envios::zonas($pdo);
        $permitirRetiro = Ajustes::activo('permitir_retiro', true);

        $errores = [];
        $datos   = [
            'cliente_nombre'     => texto('cliente_nombre', 120, $fuente),
            'cliente_email'      => correoValido('cliente_email', $fuente),
            'cliente_telefono'   => telefonoValido('cliente_telefono', $fuente),
            'entrega_tipo'       => $permitirRetiro
                                    ? opcion('entrega_tipo', ['domicilio', 'retiro'], 'domicilio', $fuente)
                                    : 'domicilio',
            'entrega_nombre'     => texto('entrega_nombre', 120, $fuente),
            'entrega_telefono'   => telefonoValido('entrega_telefono', $fuente),
            'entrega_direccion'  => texto('entrega_direccion', 255, $fuente),
            'entrega_ciudad'     => texto('entrega_ciudad', 80, $fuente),
            'entrega_referencia' => texto('entrega_referencia', 255, $fuente),
            'zona_envio_id'      => identificador('zona_envio_id', $fuente),
            'entrega_fecha'      => fechaOpcional('entrega_fecha', $fuente),
            'entrega_franja'     => texto('entrega_franja', 40, $fuente),
            'dedicatoria'        => textoLargo('dedicatoria', 400, $fuente),
            'notas_cliente'      => textoLargo('notas_cliente', 500, $fuente),
            'canal'              => 'web',
        ];

        $zona = Envios::zona($pdo, $datos['zona_envio_id']);

        $metodos = ['transferencia'];
        if (Ajustes::activo('pago_efectivo_activo', true)) {
            $metodos[] = 'efectivo';
        }
        if (PayPal::activo()) {
            $metodos[] = 'paypal';
        }
        $datos['metodo_pago'] = opcion('metodo_pago', $metodos, 'transferencia', $fuente);

        // El código se pasa tal cual lo tenía el cliente, valga o no: el pedido
        // lo revalida y devuelve el motivo si dejó de servir.
        $datos['cupon'] = Cupones::limpiar(
            crudo('cupon', $fuente) !== '' ? crudo('cupon', $fuente) : (string)($_SESSION['cupon'] ?? '')
        );

        if (mb_strlen($datos['cliente_nombre']) < 3) {
            $errores['cliente_nombre'] = 'Escribe tu nombre completo.';
        }
        if ($datos['cliente_email'] === '') {
            $errores['cliente_email'] = 'Necesitamos un correo válido para enviarte la confirmación y el seguimiento.';
        }
        if ($datos['cliente_telefono'] === '') {
            $errores['cliente_telefono'] = 'Escribe un teléfono de contacto de 8 dígitos o más.';
        }

        // El enlace de ubicación se comprueba siempre: si viene mal, se avisa en
        // lugar de guardar una dirección que el repartidor no podrá abrir.
        $revisionMapa = Envios::revisarEnlaceMapa(crudo('entrega_mapa_url', $fuente));
        $datos['entrega_mapa_url'] = $revisionMapa['url'];
        if (!$revisionMapa['ok']) {
            $errores['entrega_mapa_url'] = $revisionMapa['error'];
        }

        if ($datos['entrega_tipo'] === 'domicilio') {
            if (mb_strlen($datos['entrega_direccion']) < 10) {
                $errores['entrega_direccion'] = 'Necesitamos una dirección con la referencia suficiente para llegar.';
            }
            if ($zonas && !$zona) {
                $errores['zona_envio_id'] = 'Elige la zona de entrega: de ella depende el costo del envío.';
            }
            // La zona manda sobre lo que se escriba en el campo de ciudad.
            if ($zona) {
                $datos['entrega_ciudad'] = mb_substr((string)$zona['nombre'], 0, 80);
            } elseif ($datos['entrega_ciudad'] === '') {
                $errores['entrega_ciudad'] = 'Indica la ciudad de entrega.';
            }
        } else {
            // En retiro no hay zona ni ubicación que valgan.
            $datos['zona_envio_id']    = 0;
            $datos['entrega_mapa_url'] = '';
            $zona = null;
        }

        if ($datos['entrega_fecha'] !== null && $datos['entrega_fecha'] < date('Y-m-d')) {
            $errores['entrega_fecha'] = 'La fecha de entrega no puede ser anterior a hoy.';
        }

        // Quien recibe: si no se indica, es la misma persona que pide.
        if ($datos['entrega_nombre'] === '') {
            $datos['entrega_nombre'] = $datos['cliente_nombre'];
        }
        if ($datos['entrega_telefono'] === '') {
            $datos['entrega_telefono'] = $datos['cliente_telefono'];
        }

        // Con la zona ya confirmada se calcula lo que se va a cobrar.
        $cupon = null;
        if (Cupones::activos() && $datos['cupon'] !== '') {
            $base     = Carrito::detalle($pdo, $zona, $datos['entrega_tipo']);
            $revision = Cupones::revisar(
                $pdo, $datos['cupon'], $base['subtotal'], $base['envio'],
                Auth::id(), $datos['cliente_email'] ?: (string)($usuario['email'] ?? '')
            );
            $cupon = $revision['ok'] ? $revision['cupon'] : null;
        }
        $detalle = Carrito::detalle($pdo, $zona, $datos['entrega_tipo'], $cupon);

        return ['datos' => $datos, 'errores' => $errores, 'zona' => $zona, 'detalle' => $detalle];
    }

    /**
     * Crea la cuenta si el cliente marcó la casilla, y deja la sesión abierta.
     *
     * Se llama antes de registrar el pedido para que el pedido nazca ya
     * asociado a la cuenta recién creada.
     *
     * @return array errores por campo; vacío si no había que crear nada o si
     *               se creó bien
     */
    public static function crearCuentaSiSePide(PDO $pdo, array $datos, array $fuente): array
    {
        if (Auth::autenticado() || casilla('crear_cuenta', $fuente) !== 1) {
            return [];
        }

        $password = crudo('password', $fuente);
        $problema = revisarPassword($password, crudo('password_confirmar', $fuente));
        if ($problema !== '') {
            return ['password' => $problema];
        }

        $yaExiste = $pdo->prepare("SELECT id FROM usuarios WHERE email = ?");
        $yaExiste->execute([$datos['cliente_email']]);
        if ($yaExiste->fetchColumn()) {
            return ['cliente_email' => 'Ya hay una cuenta con ese correo. Inicia sesión para asociar el pedido.'];
        }

        $partes   = preg_split('/\s+/u', $datos['cliente_nombre']) ?: [];
        $nombre   = array_shift($partes) ?: $datos['cliente_nombre'];
        $apellido = implode(' ', $partes);

        $pdo->prepare(
            "INSERT INTO usuarios (email, nombre, apellido, telefono, password_hash, rol_id,
                                   activo, nombre_completo, email_verificado_en)
             VALUES (?,?,?,?,?,?,1,?,NULL)"
        )->execute([
            $datos['cliente_email'], mb_substr($nombre, 0, 60), mb_substr($apellido, 0, 60),
            $datos['cliente_telefono'], password_hash($password, PASSWORD_DEFAULT),
            Auth::rolId($pdo, 'cliente'), $datos['cliente_nombre'],
        ]);
        $nuevoId = (int)$pdo->lastInsertId();

        $st = $pdo->prepare("SELECT * FROM usuarios WHERE id = ?");
        $st->execute([$nuevoId]);
        $nuevo = $st->fetch();

        Auth::abrirSesion($nuevo);
        Favoritos::fusionarAlEntrar($pdo, $nuevoId);
        Auditoria::registrar($pdo, 'registro', 'usuarios', [
            'recurso_tipo' => 'usuario', 'recurso_id' => (string)$nuevoId,
            'descripcion'  => 'Cuenta creada durante el proceso de pedido.',
        ]);
        return [];
    }

    /**
     * Registra el pedido y lo que va con él: la dirección guardada, el cupón
     * gastado y el mensaje que verá el cliente al llegar al seguimiento.
     *
     * @return array{ok: bool, pedido?: array, error?: string}
     */
    public static function registrar(PDO $pdo, array $datos, array $fuente): array
    {
        $resultado = Pedidos::crearDesdeCarrito($pdo, $datos);
        if (!$resultado['ok']) {
            return $resultado;
        }
        $pedido = $resultado['pedido'];

        // La dirección se guarda solo si hay cuenta y el cliente lo pidió.
        if (Auth::autenticado() && casilla('guardar_direccion', $fuente) === 1
            && $datos['entrega_tipo'] === 'domicilio') {
            Envios::guardarDireccion($pdo, (int)Auth::id(), [
                'etiqueta'      => texto('etiqueta_direccion', 60, $fuente) ?: 'Mi dirección',
                'nombre_recibe' => $datos['entrega_nombre'],
                'telefono'      => $datos['entrega_telefono'],
                'direccion'     => $datos['entrega_direccion'],
                'referencia'    => $datos['entrega_referencia'],
                'mapa_url'      => $datos['entrega_mapa_url'],
                'zona_envio_id' => $datos['zona_envio_id'],
            ]);
        }

        // El cupón ya se gastó o dejó de valer: el pedido se registra igual con
        // su total correcto, pero el cliente tiene que enterarse aquí y no al
        // abrir el correo.
        unset($_SESSION['cupon']);
        if (($resultado['aviso'] ?? '') !== '') {
            flash('alerta', $resultado['aviso'] . ' Registramos tu pedido '
                          . $pedido['codigo'] . ' sin ese descuento.');
        } else {
            flash('exito', 'Recibimos tu pedido ' . $pedido['codigo'] . '.');
        }
        return ['ok' => true, 'pedido' => $pedido];
    }
}
