<?php
/**
 * Píxel de Meta y API de Conversiones.
 *
 * Es lo que permite que una campaña de Facebook o Instagram sepa qué anuncio
 * acabó en pedido, y que Meta enseñe los anuncios a gente parecida a quien ya
 * compra. Sin esto, las campañas se optimizan para clics y no para ventas.
 *
 * Cada evento se manda por DOS caminos con el mismo identificador:
 *
 *   1. El píxel, en el navegador (fbevents.js).
 *   2. La API de Conversiones, desde el servidor, si hay token.
 *
 * El segundo existe porque el primero se pierde mucho: bloqueadores, Safari,
 * el navegador de Instagram que cierra la pestaña antes de que salga nada. Meta
 * junta los dos por el identificador (`event_id`) y cuenta una sola compra; lo
 * que uno pierde lo recupera el otro.
 *
 * Los eventos NO se calculan aquí: se traducen de la misma cola que ya llena
 * `Analitica` para Google. Así una compra es la misma compra en los dos sitios,
 * con el mismo importe, y cada página nueva que se mida queda medida en ambos
 * sin tocar nada más.
 *
 * Sobre el permiso: el aviso de cookies manda igual que con Analytics. Hasta
 * que la persona pulsa «Aceptar todo» no se carga ni el script de Meta ni sale
 * nada del servidor. Lo que pase mientras se queda en la cola del navegador y
 * se envía en el momento en que acepta, sin recargar.
 */

declare(strict_types=1);

final class MetaPixel
{
    /** El identificador del píxel son solo dígitos (hoy 15 o 16). */
    private const PATRON_ID = '/^\d{10,20}$/';

    /**
     * Versión de la Graph API. Meta mantiene cada una unos dos años; al
     * jubilarla responde con un error claro que se ve en «Probar conexión».
     */
    public const VERSION_API = 'v24.0';

    /**
     * Evento de la cola (nombre de GA4) → evento estándar de Meta.
     *
     * Solo los que Meta usa para optimizar. Ver una lista o el carrito no
     * tiene equivalente estándar y mandarlos como personalizados solo
     * ensuciaría el Administrador de eventos.
     */
    private const EVENTOS = [
        'view_item'        => 'ViewContent',
        'search'           => 'Search',
        'add_to_cart'      => 'AddToCart',
        'add_to_wishlist'  => 'AddToWishlist',
        'begin_checkout'   => 'InitiateCheckout',
        'add_payment_info' => 'AddPaymentInfo',
        'purchase'         => 'Purchase',
        'sign_up'          => 'CompleteRegistration',
        'generate_lead'    => 'Contact',
    ];

    // =====================================================================
    // Ajustes
    // =====================================================================

    /**
     * ¿La tienda usa el píxel? Responde por la configuración, no por quién
     * mira: es la que usan los textos legales, que dicen lo mismo a todos.
     */
    public static function configurado(): bool
    {
        return Ajustes::activo('pixel_activo', false) && self::idValido(self::id());
    }

    /** ¿Se pinta el píxel en esta página y para esta visita? */
    public static function activo(): bool
    {
        if (!self::configurado()) {
            return false;
        }
        return !(Ajustes::activo('pixel_excluir_equipo', true) && Auth::esPersonal());
    }

    public static function id(): string
    {
        return preg_replace('/\D+/', '', Ajustes::texto('pixel_id')) ?? '';
    }

    public static function idValido(string $id): bool
    {
        return (bool)preg_match(self::PATRON_ID, $id);
    }

    /** ¿Hay token para mandar también desde el servidor? */
    public static function conServidor(): bool
    {
        return trim(Ajustes::texto('pixel_token')) !== '';
    }

    /** Código de «Probar eventos»; vacío fuera de las comprobaciones. */
    public static function codigoPrueba(): string
    {
        return preg_replace('/[^A-Za-z0-9]/', '', Ajustes::texto('pixel_prueba')) ?? '';
    }

    /** ¿Aceptó la persona las cookies opcionales? */
    public static function consentido(): bool
    {
        return ($_COOKIE['fa_cookies'] ?? '') === 'aceptado';
    }

    // =====================================================================
    // Traducción de eventos
    // =====================================================================

    /** Nombre del evento de Meta para uno de la cola, o '' si no se manda. */
    public static function nombre(string $eventoGa): string
    {
        return self::EVENTOS[$eventoGa] ?? '';
    }

    /**
     * Parámetros de Meta (`custom_data`) a partir de los de GA4.
     *
     * Lo que importa para vender son `content_ids`, que tienen que coincidir
     * con el identificador del producto en un catálogo de Meta si algún día
     * se conecta uno (por eso se usa el slug, el mismo `item_id` de Google),
     * y `value` con `currency`, que es lo que deja medir el retorno de cada
     * anuncio.
     */
    public static function datos(string $eventoGa, array $p): array
    {
        $items = array_values(array_filter((array)($p['items'] ?? []), 'is_array'));
        $d = [];

        if ($items) {
            $d['content_ids']  = array_map(fn($i) => (string)($i['item_id'] ?? ''), $items);
            $d['contents']     = array_map(fn($i) => [
                'id'         => (string)($i['item_id'] ?? ''),
                'quantity'   => max(1, (int)($i['quantity'] ?? 1)),
                'item_price' => round((float)($i['price'] ?? 0), 2),
            ], $items);
            $d['content_type'] = 'product';
            if (count($items) === 1) {
                $d['content_name'] = (string)($items[0]['item_name'] ?? '');
                if (!empty($items[0]['item_category'])) {
                    $d['content_category'] = (string)$items[0]['item_category'];
                }
            }
            if (in_array($eventoGa, ['begin_checkout', 'purchase', 'add_payment_info'], true)) {
                $d['num_items'] = array_sum(array_map(fn($c) => $c['quantity'], $d['contents']));
            }
        }
        if (isset($p['value'])) {
            $d['value']    = round((float)$p['value'], 2);
            $d['currency'] = (string)($p['currency'] ?? Analitica::moneda());
        }
        if ($eventoGa === 'purchase' && !empty($p['transaction_id'])) {
            $d['order_id'] = (string)$p['transaction_id'];
        }
        if ($eventoGa === 'search' && isset($p['search_term'])) {
            $d['search_string'] = (string)$p['search_term'];
        }
        return $d;
    }

    /**
     * Los eventos de esta página, listos para el navegador.
     *
     * Lee la cola de `Analitica` (la misma que pinta Google), se queda con los
     * que tienen equivalente en Meta y, si hay permiso y token, los deja
     * apuntados para mandarlos también desde el servidor cuando la página ya
     * se haya entregado.
     *
     * @return list<array{evento:string,datos:array,id:string}>
     */
    public static function paraPagina(): array
    {
        self::recordarClic();
        $salida = [];
        foreach (Analitica::cola() as $ev) {
            $meta = self::nombre((string)$ev['nombre']);
            if ($meta === '') {
                continue;
            }
            $salida[] = $m = [
                'evento' => $meta,
                'datos'  => self::datos((string)$ev['nombre'], (array)$ev['datos']),
                'id'     => (string)($ev['id'] ?? Analitica::idEvento((string)$ev['nombre'], (array)$ev['datos'])),
            ];
            self::alServidor($m['evento'], $m['datos'], $m['id'], self::urlActual());
        }
        return $salida;
    }

    /**
     * Evento que ocurre en una llamada por JavaScript (añadir al carrito,
     * guardar en favoritos).
     *
     * El servidor lo manda por su lado y devuelve lo mismo al navegador, con
     * el mismo identificador, para que el píxel lo mande por el suyo y Meta
     * los junte en uno. Devuelve null si el píxel no está activo.
     *
     * @return array{evento:string,datos:array,id:string}|null
     */
    public static function enRespuesta(string $eventoGa, array $parametros): ?array
    {
        $meta = self::nombre($eventoGa);
        if ($meta === '' || !self::activo()) {
            return null;
        }
        $m = [
            'evento' => $meta,
            'datos'  => self::datos($eventoGa, $parametros),
            'id'     => Analitica::idEvento($eventoGa, $parametros),
        ];
        // La página desde la que se pulsó, no la dirección de la API.
        $origen = (string)($_SERVER['HTTP_REFERER'] ?? '');
        self::alServidor($m['evento'], $m['datos'], $m['id'],
                         self::mismoSitio($origen) ? $origen : self::urlActual());
        return $m;
    }

    // =====================================================================
    // Datos del cliente (coincidencia avanzada)
    // =====================================================================

    /** @var array{email?:string,telefono?:string,nombre?:string,usuario_id?:int} */
    private static array $cliente = [];

    /**
     * Quién es el cliente, cuando se sabe: en la página del pedido recién
     * hecho, aunque sea un invitado. Si no se fija, se usa la cuenta abierta.
     *
     * Con esto Meta reconoce a la persona aunque el navegador haya perdido la
     * cookie, que es lo que más sube las ventas atribuidas a cada anuncio.
     * Nunca sale en claro: se resume con SHA-256 antes de mandarse, y Meta
     * solo compara resúmenes.
     */
    public static function cliente(array $datos): void
    {
        self::$cliente = array_filter([
            'email'      => trim((string)($datos['email'] ?? '')),
            'telefono'   => trim((string)($datos['telefono'] ?? '')),
            'nombre'     => trim((string)($datos['nombre'] ?? '')),
            'usuario_id' => (int)($datos['usuario_id'] ?? 0),
        ]);
    }

    /**
     * Datos del cliente ya normalizados y resumidos, con las claves que usa
     * Meta (`em`, `ph`, `fn`, `ln`, `external_id`).
     *
     * @return array<string,string>
     */
    public static function coincidencias(): array
    {
        $c = self::$cliente;
        if (!$c && ($u = Auth::usuario())) {
            $c = [
                'email'      => (string)($u['email'] ?? ''),
                'telefono'   => (string)($u['telefono'] ?? ''),
                'nombre'     => (string)($u['nombre_completo'] ?? ''),
                'usuario_id' => (int)$u['id'],
            ];
        }
        if (!$c) {
            return [];
        }

        $email = mb_strtolower(trim((string)($c['email'] ?? '')));
        $tel   = self::telefono((string)($c['telefono'] ?? ''));
        $partes = preg_split('/\s+/u', self::nombreLimpio((string)($c['nombre'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_map(fn($v) => hash('sha256', $v), array_filter([
            'em'          => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '',
            'ph'          => $tel,
            'fn'          => $partes[0] ?? '',
            'ln'          => count($partes) > 1 ? end($partes) : '',
            'external_id' => !empty($c['usuario_id']) ? 'usuario-' . (int)$c['usuario_id'] : '',
        ], fn($v) => $v !== ''));
    }

    /**
     * Teléfono con prefijo internacional y solo dígitos, como lo pide Meta.
     * En la tienda casi todos son de Nicaragua (8 cifras); los de fuera se
     * escriben con su prefijo, y 10 cifras sueltas son de EE. UU. o Canadá,
     * que es de donde más se encargan flores para la familia.
     */
    private static function telefono(string $tel): string
    {
        $d = preg_replace('/\D+/', '', $tel) ?? '';
        $d = ltrim($d, '0');
        return match (true) {
            strlen($d) === 8               => '505' . $d,
            strlen($d) === 10              => '1' . $d,
            strlen($d) >= 11 && strlen($d) <= 15 => $d,
            default                        => '',
        };
    }

    /** Minúsculas y sin signos: «María-José Pérez.» → «maría josé pérez». */
    private static function nombreLimpio(string $nombre): string
    {
        $n = mb_strtolower(trim($nombre));
        return trim(preg_replace('/[^\p{L}\s]+/u', ' ', $n) ?? '');
    }

    // =====================================================================
    // Identificador del clic en el anuncio (fbclid → fbc)
    // =====================================================================

    /**
     * Guarda el `fbclid` con el que se llega desde un anuncio.
     *
     * Es lo que une el pedido con el anuncio exacto que lo trajo. El píxel lo
     * guarda en la cookie `_fbc`, pero solo si se aceptan las cookies y solo
     * en la página donde llega; si la persona acepta dos páginas después, el
     * clic ya se perdió. Guardado en la sesión, sigue ahí hasta el pedido.
     */
    private static function recordarClic(): void
    {
        $clic = (string)($_GET['fbclid'] ?? '');
        if ($clic === '' || strlen($clic) > 500 || session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        $_SESSION['meta_fbc'] = 'fb.1.' . (int)round(microtime(true) * 1000) . '.'
                              . preg_replace('/[^\w\-]/', '', $clic);
    }

    /** El `fbc` vigente: la cookie del píxel o, si no la hay, el de la sesión. */
    public static function fbc(): string
    {
        $cookie = (string)($_COOKIE['_fbc'] ?? '');
        if (preg_match('/^fb\.\d\.\d+\.[\w\-]+$/', $cookie)) {
            return $cookie;
        }
        return (string)($_SESSION['meta_fbc'] ?? '');
    }

    // =====================================================================
    // API de Conversiones (servidor)
    // =====================================================================

    /** @var list<array> eventos pendientes de mandar al terminar la página */
    private static array $pendientes = [];

    /**
     * Apunta un evento para la API de Conversiones.
     *
     * No se manda en el momento: esperar a Meta retrasaría la página. Sale
     * cuando la respuesta ya llegó al navegador, todos juntos en una sola
     * petición.
     */
    private static function alServidor(string $evento, array $datos, string $id, string $url): void
    {
        if (!self::conServidor() || !self::consentido()) {
            return;
        }
        if (!self::$pendientes) {
            register_shutdown_function([self::class, 'enviarPendientes']);
        }
        self::$pendientes[] = array_filter([
            'event_name'       => $evento,
            'event_time'       => time(),
            'event_id'         => $id,
            'event_source_url' => self::sinLlaves($url),
            'action_source'    => 'website',
            'user_data'        => self::datosUsuario(),
            'custom_data'      => $datos ?: null,
        ], fn($v) => $v !== null && $v !== '');
    }

    /** @internal la llama `register_shutdown_function`. */
    public static function enviarPendientes(): void
    {
        if (!self::$pendientes) {
            return;
        }
        // Igual que los correos diferidos: se suelta la sesión y se cierra la
        // respuesta antes de esperar a Meta, para que nadie note la espera.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        }
        $eventos = self::$pendientes;
        self::$pendientes = [];
        try {
            self::enviar($eventos, 4);
        } catch (Throwable $e) {
            error_log('Flowers Anto — Meta: ' . $e->getMessage());
        }
    }

    /**
     * `user_data`: lo que permite a Meta reconocer a la persona.
     *
     * La IP y el navegador son obligatorios para eventos de sitio web. `fbp`
     * es la cookie del píxel y `fbc` la del clic en el anuncio: con ellas
     * Meta une este evento con el del navegador y con el anuncio, sin datos
     * personales.
     */
    private static function datosUsuario(): array
    {
        $u = [
            'client_ip_address' => ip_cliente(),
            'client_user_agent' => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 400),
        ];
        $fbp = (string)($_COOKIE['_fbp'] ?? '');
        if (preg_match('/^fb\.\d\.\d+\.\d+$/', $fbp)) {
            $u['fbp'] = $fbp;
        }
        if (($fbc = self::fbc()) !== '') {
            $u['fbc'] = $fbc;
        }
        foreach (self::coincidencias() as $clave => $resumen) {
            $u[$clave] = [$resumen];
        }
        return array_filter($u, fn($v) => $v !== '' && $v !== '0.0.0.0');
    }

    /**
     * POST a /{pixel}/events.
     *
     * @return array{ok:bool, recibidos:int, mensaje:string}
     */
    private static function enviar(array $eventos, int $espera): array
    {
        $token = trim(Ajustes::texto('pixel_token'));
        if ($token === '' || !$eventos) {
            return ['ok' => false, 'recibidos' => 0, 'mensaje' => 'No hay token de la API de Conversiones.'];
        }
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'recibidos' => 0, 'mensaje' => 'Falta la extensión cURL de PHP en el servidor.'];
        }

        // El token va en el cuerpo y no en la dirección: las direcciones
        // acaban en registros de servidores y proxys; el cuerpo, no.
        $campos = [
            'data'         => json_encode($eventos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'access_token' => $token,
        ];
        if (($prueba = self::codigoPrueba()) !== '') {
            $campos['test_event_code'] = $prueba;
        }

        $ch = curl_init(self::graph() . '/' . self::VERSION_API . '/' . self::id() . '/events');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($campos),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => $espera,
            CURLOPT_CONNECTTIMEOUT => min(3, $espera),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $respuesta = curl_exec($ch);
        $codigo    = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $fallo     = curl_error($ch);

        $datos = is_string($respuesta) ? json_decode($respuesta, true) : null;
        if ($respuesta === false || $codigo >= 400 || !is_array($datos)) {
            $error   = is_array($datos['error'] ?? null) ? $datos['error'] : [];
            $mensaje = $fallo !== '' ? 'No se pudo conectar con Meta (' . $fallo . ').'
                     : self::explicar($error, $codigo);
            error_log(sprintf('Flowers Anto — Meta CAPI (HTTP %d, código %s): %s', $codigo,
                (string)($error['code'] ?? '-'), mb_substr(preg_replace('/[^\w .,:()«»\-]/u', '', $mensaje) ?? '', 0, 300)));
            self::guardarEstado(false, $mensaje);
            return ['ok' => false, 'recibidos' => 0, 'mensaje' => $mensaje];
        }

        $recibidos = (int)($datos['events_received'] ?? 0);
        self::guardarEstado(true, '');
        return ['ok' => true, 'recibidos' => $recibidos,
                'mensaje' => 'Meta recibió ' . $recibidos . ' evento' . ($recibidos === 1 ? '' : 's') . '.'];
    }

    /** Traduce los errores de Meta que se pueden arreglar desde el panel. */
    private static function explicar(array $error, int $codigo): string
    {
        $num = (int)($error['code'] ?? 0);
        $original = mb_substr((string)($error['error_user_msg'] ?? $error['message'] ?? ''), 0, 240);
        return match (true) {
            $num === 190 => 'El token no es válido o caducó. Genera uno nuevo en el Administrador de '
                          . 'eventos → Configuración → API de Conversiones y pégalo aquí.',
            $num === 100 && stripos($original, 'pixel') !== false,
            $num === 803 => 'Meta no encuentra ese píxel. Revisa el identificador: es el número que sale '
                          . 'debajo del nombre del conjunto de datos.',
            $num === 10, $num === 200 => 'El token no tiene permiso sobre este píxel. Genéralo desde '
                          . 'el mismo conjunto de datos cuyo identificador pusiste.',
            $num === 2635, $num === 2500 && stripos($original, 'version') !== false
                         => 'Meta ya no acepta la versión ' . self::VERSION_API . ' de su API. Hay que actualizar '
                          . 'MetaPixel::VERSION_API en el código.',
            default => 'Meta respondió con un error' . ($codigo ? ' (HTTP ' . $codigo . ')' : '')
                     . ($original !== '' ? ': ' . $original : '.'),
        };
    }

    /**
     * Comprueba el píxel y el token desde el panel, antes de que lo descubra
     * una campaña que lleva días sin medir.
     *
     * Primero pregunta por el píxel (valida identificador y token sin mandar
     * ningún evento). Si además hay código de prueba, manda un evento de
     * prueba, que sale en «Probar eventos» y no cuenta para nada.
     *
     * @return array{ok:bool, mensaje:string}
     */
    public static function probar(): array
    {
        if (!self::idValido(self::id())) {
            return ['ok' => false, 'mensaje' => 'El identificador del píxel no es válido: son solo números.'];
        }
        if (!self::conServidor()) {
            return ['ok' => true, 'mensaje' => 'El píxel del navegador está listo. Para medir también desde el '
                . 'servidor —recomendado—, añade el token de la API de Conversiones.'];
        }
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'mensaje' => 'Falta la extensión cURL de PHP en el servidor.'];
        }

        $ch = curl_init(self::graph() . '/' . self::VERSION_API . '/' . self::id()
                        . '?' . http_build_query(['fields' => 'name']));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => ['Accept: application/json',
                                       'Authorization: Bearer ' . trim(Ajustes::texto('pixel_token'))],
        ]);
        $respuesta = curl_exec($ch);
        $codigo    = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $fallo     = curl_error($ch);
        $datos     = is_string($respuesta) ? json_decode($respuesta, true) : null;

        if ($respuesta === false || $codigo >= 400 || !is_array($datos)) {
            $error = is_array($datos['error'] ?? null) ? $datos['error'] : [];
            $mensaje = $fallo !== '' ? 'No se pudo conectar con Meta (' . $fallo . ').' : self::explicar($error, $codigo);
            self::guardarEstado(false, $mensaje);
            return ['ok' => false, 'mensaje' => $mensaje];
        }

        $nombre = (string)($datos['name'] ?? '');
        $mensaje = 'Conexión correcta con el píxel' . ($nombre !== '' ? ' «' . $nombre . '»' : '') . '.';

        if (self::codigoPrueba() !== '') {
            $envio = self::enviar([[
                'event_name'       => 'PageView',
                'event_time'       => time(),
                'event_id'         => 'prueba-' . bin2hex(random_bytes(6)),
                'event_source_url' => url_absoluta(),
                'action_source'    => 'website',
                'user_data'        => ['client_ip_address' => ip_cliente(),
                                       'client_user_agent' => (string)($_SERVER['HTTP_USER_AGENT'] ?? 'FlowersAnto')],
            ]], 12);
            if (!$envio['ok']) {
                return ['ok' => false, 'mensaje' => $mensaje . ' Pero el evento de prueba falló: ' . $envio['mensaje']];
            }
            $mensaje .= ' Evento de prueba enviado: búscalo en Administrador de eventos → Probar eventos.';
        } else {
            self::guardarEstado(true, '');
        }
        return ['ok' => true, 'mensaje' => $mensaje];
    }

    // =====================================================================
    // Estado del último envío (para el panel y la salud del sitio)
    // =====================================================================

    private static function archivoEstado(): string
    {
        // En logs/: carpeta que ni se publica en la web ni entra en git.
        return DIR_LOGS . '/meta-capi.json';
    }

    /**
     * Último resultado del servidor.
     *
     * Un token caducado no se nota en la tienda: los pedidos siguen entrando,
     * solo que Meta deja de enterarse. Por eso el último fallo se guarda y se
     * enseña en el panel hasta que un envío vuelva a salir bien.
     *
     * @return array{ok:bool, cuando:int, mensaje:string}|null
     */
    public static function estado(): ?array
    {
        $json = @file_get_contents(self::archivoEstado());
        $datos = is_string($json) ? json_decode($json, true) : null;
        return is_array($datos) ? ['ok' => (bool)($datos['ok'] ?? false), 'cuando' => (int)($datos['cuando'] ?? 0),
                                   'mensaje' => (string)($datos['mensaje'] ?? '')] : null;
    }

    /**
     * Solo escribe cuando cambia algo: un envío bueno tras otro bueno no toca
     * el disco, así que una tienda con mucho tráfico no escribe en cada visita.
     */
    private static function guardarEstado(bool $ok, string $mensaje): void
    {
        $antes = self::estado();
        if ($antes && $antes['ok'] === $ok && $antes['mensaje'] === $mensaje
            && ($ok || time() - $antes['cuando'] < 3600)) {
            return;
        }
        $dir = dirname(self::archivoEstado());
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents(self::archivoEstado(),
            json_encode(['ok' => $ok, 'cuando' => time(), 'mensaje' => $mensaje], JSON_UNESCAPED_UNICODE),
            LOCK_EX);
    }

    // =====================================================================
    // Utilidades
    // =====================================================================

    /** En desarrollo, las llamadas pueden ir a un simulador local. */
    private static function graph(): string
    {
        $sim = rtrim(Entorno::texto('META_GRAPH_SIMULADOR'), '/');
        if ($sim !== '' && defined('ENTORNO') && ENTORNO === 'dev'
            && preg_match('#^http://127\.0\.0\.1:\d+$#', $sim)) {
            return $sim;
        }
        return 'https://graph.facebook.com';
    }

    /** Dirección completa de la página que se está pintando. */
    private static function urlActual(): string
    {
        // REQUEST_URI ya trae la subcarpeta del sitio; `url_absoluta()` no la repite.
        return url_absoluta((string)($_SERVER['REQUEST_URI'] ?? '/'));
    }

    /**
     * La dirección sin las llaves que abren algo: el `t` del pedido de un
     * invitado da acceso a su nombre y su dirección, y Meta no tiene por qué
     * guardarlo.
     */
    private static function sinLlaves(string $url): string
    {
        $q = (string)parse_url($url, PHP_URL_QUERY);
        if ($q === '') {
            return $url;
        }
        parse_str($q, $param);
        $param = array_diff_key($param, array_flip(['t', 'token', 'csrf_token']));
        return strtok($url, '?') . ($param ? '?' . http_build_query($param) : '');
    }

    private static function mismoSitio(string $url): bool
    {
        $host = (string)parse_url($url, PHP_URL_HOST);
        return $host !== '' && strcasecmp($host, (string)parse_url(url_absoluta(), PHP_URL_HOST)) === 0;
    }
}
