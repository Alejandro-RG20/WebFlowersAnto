<?php
/**
 * Revisión del estado del sitio.
 *
 * Reúne en un sitio las cosas que, si están mal, no se notan hasta que las
 * sufre un cliente: que el correo no sale, que el sitio va por http, que el
 * instalador sigue subido, que no hay respaldos. Todo son comprobaciones de
 * lectura: esto no cambia nada, solo mira y cuenta.
 */

declare(strict_types=1);

final class Salud
{
    /**
     * @return list<array{clave:string, nivel:string, titulo:string, detalle:string, arreglo:string}>
     *         nivel: 'grave' | 'aviso' | 'bien'
     */
    public static function revisar(PDO $pdo): array
    {
        $r = [];
        $add = static function (string $clave, string $nivel, string $titulo,
                                string $detalle, string $arreglo = '') use (&$r): void {
            $r[] = compact('clave', 'nivel', 'titulo', 'detalle', 'arreglo');
        };

        // --- HTTPS -----------------------------------------------------
        $seguro = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
               || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $local  = in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost', '127.0.0.1'], true)
               || str_starts_with($_SERVER['HTTP_HOST'] ?? '', 'localhost:')
               || str_starts_with($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1:');

        if ($seguro || $local) {
            $add('https', 'bien', 'La web va por HTTPS',
                 $local ? 'Estás en local, así que no aplica.' : 'El candado está puesto.');
        } else {
            $add('https', 'grave', 'La web NO va por HTTPS',
                 'Las contraseñas y las direcciones de tus clientes viajan sin cifrar, y la '
               . 'cookie de sesión pierde su protección.',
                 'Activa el certificado SSL gratuito en el panel de tu hosting. La web ya '
               . 'redirige sola a https en cuanto exista.');
        }

        // --- Correo ----------------------------------------------------
        if (Correo::entregaDeVerdad()) {
            $add('correo', 'bien', 'El correo está configurado',
                 'Transporte: ' . Correo::transporte() . '.');
        } else {
            $add('correo', 'grave', 'El correo no se envía',
                 'Con MAIL_TRANSPORTE=log los correos se escriben en un archivo y no salen. '
               . 'Nadie recibe la confirmación de su pedido, y si pierdes tu contraseña no '
               . 'podrás recuperarla.',
                 'Pon MAIL_TRANSPORTE=smtp en el archivo .env con los datos de tu correo, y '
               . 'pruébalo desde Configuración → Avisos.');
        }

        // --- Instalador ------------------------------------------------
        if (is_file(RAIZ . '/instalar.php')) {
            $add('instalador', 'aviso', 'El instalador sigue en el servidor',
                 'instalar.php ya no deja crear nada porque hay personal registrado, pero no '
               . 'tiene por qué seguir ahí.',
                 'Bórralo por FTP: es un archivo menos que vigilar.');
        } else {
            $add('instalador', 'bien', 'El instalador ya no está', 'Borrado, como debe ser.');
        }

        // --- Respaldos -------------------------------------------------
        try {
            $ultimo = $pdo->query("SELECT created_at FROM respaldos ORDER BY created_at DESC LIMIT 1")
                          ->fetchColumn();
        } catch (PDOException $e) {
            $ultimo = false;
        }
        if (!$ultimo) {
            $add('respaldos', 'aviso', 'No hay ningún respaldo',
                 'Si algo le pasa a la base de datos, no habría desde dónde volver.',
                 'Crea uno desde Respaldos y descárgalo a tu computadora.');
        } elseif (strtotime((string)$ultimo) < strtotime('-30 days')) {
            $add('respaldos', 'aviso', 'El último respaldo es viejo',
                 'Se hizo el ' . fecha_corta((string)$ultimo) . '.',
                 'Crea uno nuevo y guárdalo fuera del servidor.');
        } else {
            $add('respaldos', 'bien', 'Hay respaldo reciente',
                 'El último es del ' . fecha_corta((string)$ultimo) . '.');
        }

        // --- Páginas legales -------------------------------------------
        $add('legal', is_file(RAIZ . '/legal.php') ? 'bien' : 'aviso',
             'Páginas legales',
             is_file(RAIZ . '/legal.php')
                ? 'Privacidad, términos y devoluciones están publicadas.'
                : 'Faltan, y PayPal las pide para una cuenta de negocio.');

        // --- Pagos -----------------------------------------------------
        $metodos = [];
        if ($pdo->query("SELECT COUNT(*) FROM cuentas_bancarias")->fetchColumn() > 0) {
            $metodos[] = 'transferencia';
        }
        if (PayPal::activo()) {
            $metodos[] = 'PayPal';
        }
        if (Ajustes::activo('pago_efectivo_activo', true)) {
            $metodos[] = 'efectivo';
        }
        if (!$metodos) {
            $add('pagos', 'grave', 'No hay ninguna forma de pago',
                 'Un cliente no puede terminar su pedido.',
                 'Agrega una cuenta bancaria o activa PayPal en Configuración → Transferencias.');
        } else {
            $add('pagos', 'bien', 'Formas de pago activas', implode(', ', $metodos) . '.');
        }

        if (PayPal::activo() && Ajustes::texto('paypal_modo', 'sandbox') === 'sandbox') {
            $add('paypal_modo', 'aviso', 'PayPal está en modo de pruebas',
                 'Los pagos que hagan tus clientes NO son reales: el dinero no llega.',
                 'Cuando termines de probar, cambia el modo a «Cobros reales» y pon las '
               . 'credenciales Live.');
        }

        // --- Contacto y datos de la tienda -----------------------------
        $faltan = [];
        foreach (['telefono' => 'teléfono', 'email_contacto' => 'correo de contacto',
                  'direccion' => 'dirección', 'whatsapp' => 'WhatsApp'] as $clave => $nombre) {
            if (Ajustes::texto($clave) === '') {
                $faltan[] = $nombre;
            }
        }
        if ($faltan) {
            $add('contacto', 'aviso', 'Faltan datos de contacto',
                 'Sin ' . implode(', ', $faltan) . '. Los clientes los buscan antes de comprar.',
                 'Complétalos en Configuración → Contacto.');
        } else {
            $add('contacto', 'bien', 'Datos de contacto completos', 'Nada que rellenar.');
        }

        // --- Catálogo --------------------------------------------------
        $publicados = (int)$pdo->query("SELECT COUNT(*) FROM productos WHERE activo = 1")->fetchColumn();
        if ($publicados === 0) {
            $add('catalogo', 'grave', 'No hay ningún producto publicado',
                 'La tienda se ve vacía.', 'Publica arreglos desde Catálogo → Productos.');
        } else {
            $add('catalogo', 'bien', 'Catálogo publicado', $publicados . ' arreglos a la venta.');
        }

        // --- Entorno ---------------------------------------------------
        if (ENTORNO === 'dev') {
            $add('entorno', 'grave', 'La web está en modo desarrollo',
                 'Con APP_ENTORNO=dev los errores se muestran en pantalla, y un error puede '
               . 'enseñar rutas del servidor o datos de la base a cualquiera.',
                 'Pon APP_ENTORNO=prod en el archivo .env.');
        } else {
            $add('entorno', 'bien', 'Modo producción', 'Los errores no se muestran al visitante.');
        }

        return $r;
    }

    /** Cuántos problemas hay de cada nivel. */
    public static function resumen(array $revision): array
    {
        $c = ['grave' => 0, 'aviso' => 0, 'bien' => 0];
        foreach ($revision as $x) {
            $c[$x['nivel']] = ($c[$x['nivel']] ?? 0) + 1;
        }
        return $c;
    }
}
