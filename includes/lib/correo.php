<?php
/**
 * Envío de correo.
 *
 * Tres transportes, elegidos con MAIL_TRANSPORTE:
 *   log   escribe el mensaje en storage/logs/correos.log (desarrollo)
 *   mail  función mail() de PHP (lo habitual en hosting compartido)
 *   smtp  cliente SMTP propio con STARTTLS/SSL y AUTH LOGIN
 *
 * El cliente SMTP son ~120 líneas sobre stream_socket_client. Se escribió a
 * mano porque el proyecto no usa Composer y arrastrar PHPMailer solo por esto
 * habría cambiado la forma de desplegar el sitio.
 */

declare(strict_types=1);

final class Correo
{
    /**
     * Motivo del último envío fallido.
     *
     * Se guarda porque el panel necesita poder decir *por qué* no salió un
     * correo. Sin esto, un SMTP mal configurado se comporta igual que uno
     * bien configurado: en silencio, y el negocio se entera cuando un cliente
     * reclama que nunca le llegó nada.
     */
    private static string $ultimoError = '';

    public static function ultimoError(): string
    {
        return self::$ultimoError;
    }

    /** Transporte activo: log, mail o smtp. */
    public static function transporte(): string
    {
        $t = strtolower(trim(Entorno::texto('MAIL_TRANSPORTE', 'log')));
        return in_array($t, ['mail', 'smtp'], true) ? $t : 'log';
    }

    /** ¿El transporte configurado entrega de verdad, o solo escribe en el log? */
    public static function entregaDeVerdad(): bool
    {
        return self::transporte() !== 'log';
    }

    /**
     * Envía un correo HTML. Devuelve true si el transporte lo aceptó.
     * Un fallo nunca interrumpe la operación que lo disparó: se registra y ya.
     */
    public static function enviar(string $para, string $asunto, string $cuerpoHtml, string $cuerpoTexto = ''): bool
    {
        self::$ultimoError = '';

        if (!filter_var($para, FILTER_VALIDATE_EMAIL)) {
            self::$ultimoError = 'La dirección «' . $para . '» no es un correo válido.';
            error_log('Flowers Anto — correo: ' . self::$ultimoError);
            return false;
        }
        $cuerpoTexto = $cuerpoTexto !== '' ? $cuerpoTexto : trim(html_entity_decode(strip_tags($cuerpoHtml)));

        try {
            $ok = match (self::transporte()) {
                'smtp'  => self::porSmtp($para, $asunto, $cuerpoHtml, $cuerpoTexto),
                'mail'  => self::porMail($para, $asunto, $cuerpoHtml),
                default => self::porArchivo($para, $asunto, $cuerpoHtml),
            };
            if (!$ok && self::$ultimoError === '') {
                self::$ultimoError = self::transporte() === 'mail'
                    ? 'La función mail() de PHP devolvió error. En XAMPP y en muchos hostings '
                      . 'no hay servidor de salida: usa MAIL_TRANSPORTE=smtp.'
                    : 'El transporte no aceptó el mensaje.';
                error_log('Flowers Anto — correo a ' . $para . ': ' . self::$ultimoError);
            }
            return $ok;
        } catch (Throwable $ex) {
            self::$ultimoError = $ex->getMessage();
            error_log('Flowers Anto — correo a ' . $para . ': ' . $ex->getMessage());
            return false;
        }
    }

    private static function remitente(): array
    {
        return [
            Entorno::texto('MAIL_REMITENTE', 'no-responder@localhost'),
            Entorno::texto('MAIL_REMITENTE_NOMBRE', Ajustes::texto('nombre_tienda', 'Flowers Anto')),
        ];
    }

    // -----------------------------------------------------------------
    // Transportes
    // -----------------------------------------------------------------

    private static function porArchivo(string $para, string $asunto, string $html): bool
    {
        if (!is_dir(DIR_LOGS)) {
            @mkdir(DIR_LOGS, 0755, true);
        }
        $linea = str_repeat('=', 70) . "\n"
               . date('Y-m-d H:i:s') . "  →  $para\n"
               . "Asunto: $asunto\n" . str_repeat('-', 70) . "\n"
               . $html . "\n\n";
        if (@file_put_contents(DIR_LOGS . '/correos.log', $linea, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException('No se pudo escribir en ' . DIR_LOGS . '/correos.log.');
        }
        return true;
    }

    private static function porMail(string $para, string $asunto, string $html): bool
    {
        [$dir, $nombre] = self::remitente();
        $cabeceras = [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . self::cabeceraNombre($nombre) . ' <' . $dir . '>',
            'Reply-To: ' . $dir,
            'X-Mailer: Flowers Anto',
        ];
        return mail($para, self::cabeceraAsunto($asunto), $html, implode("\r\n", $cabeceras));
    }

    private static function porSmtp(string $para, string $asunto, string $html, string $texto): bool
    {
        $host = Entorno::texto('SMTP_HOST', '');
        if ($host === '') {
            throw new RuntimeException('MAIL_TRANSPORTE es «smtp» pero SMTP_HOST está vacío en el .env.');
        }
        $puerto    = Entorno::entero('SMTP_PORT', 587);
        $seguridad = Entorno::texto('SMTP_SEGURIDAD', 'tls');
        $usuario   = Entorno::texto('SMTP_USUARIO', '');
        $password  = Entorno::texto('SMTP_PASSWORD', '');
        [$dir, $nombre] = self::remitente();

        $destino = ($seguridad === 'ssl' ? 'ssl://' : '') . $host . ':' . $puerto;
        $socket  = @stream_socket_client($destino, $errno, $errstr, 15, STREAM_CLIENT_CONNECT);
        if (!$socket) {
            throw new RuntimeException("No se pudo conectar a $host:$puerto — $errstr");
        }
        stream_set_timeout($socket, 15);

        $leer = function () use ($socket): string {
            $respuesta = '';
            while (($linea = fgets($socket, 1024)) !== false) {
                $respuesta .= $linea;
                if (strlen($linea) < 4 || $linea[3] !== '-') {
                    break;
                }
            }
            return $respuesta;
        };
        $orden = function (string $texto, array $esperados) use ($socket, $leer): string {
            fwrite($socket, $texto . "\r\n");
            $r = $leer();
            if (!in_array((int)substr($r, 0, 3), $esperados, true)) {
                throw new RuntimeException('SMTP rechazó «' . strtok($texto, ' ') . '»: ' . trim($r));
            }
            return $r;
        };

        try {
            $bienvenida = $leer();
            if ((int)substr($bienvenida, 0, 3) !== 220) {
                throw new RuntimeException('Saludo SMTP inesperado: ' . trim($bienvenida));
            }
            $dominio = parse_url(APP_URL, PHP_URL_HOST) ?: 'localhost';
            $orden('EHLO ' . $dominio, [250]);

            if ($seguridad === 'tls') {
                $orden('STARTTLS', [220]);
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('No se pudo activar TLS.');
                }
                $orden('EHLO ' . $dominio, [250]);
            }

            if ($usuario !== '') {
                $orden('AUTH LOGIN', [334]);
                $orden(base64_encode($usuario), [334]);
                $orden(base64_encode($password), [235]);
            }

            $orden('MAIL FROM:<' . $dir . '>', [250]);
            $orden('RCPT TO:<' . $para . '>', [250, 251]);
            $orden('DATA', [354]);

            $limite  = 'fa' . bin2hex(random_bytes(12));
            $mensaje = implode("\r\n", [
                'From: ' . self::cabeceraNombre($nombre) . ' <' . $dir . '>',
                'To: <' . $para . '>',
                'Subject: ' . self::cabeceraAsunto($asunto),
                'Date: ' . date('r'),
                'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $dominio . '>',
                'MIME-Version: 1.0',
                'Content-Type: multipart/alternative; boundary="' . $limite . '"',
                '',
                '--' . $limite,
                'Content-Type: text/plain; charset=UTF-8',
                'Content-Transfer-Encoding: base64',
                '',
                chunk_split(base64_encode($texto)),
                '--' . $limite,
                'Content-Type: text/html; charset=UTF-8',
                'Content-Transfer-Encoding: base64',
                '',
                chunk_split(base64_encode($html)),
                '--' . $limite . '--',
                '.',
            ]);
            fwrite($socket, $mensaje . "\r\n");
            $r = $leer();
            if ((int)substr($r, 0, 3) !== 250) {
                throw new RuntimeException('El servidor no aceptó el mensaje: ' . trim($r));
            }
            @fwrite($socket, "QUIT\r\n");
            return true;
        } finally {
            @fclose($socket);
        }
    }

    /** Codifica un nombre con acentos para la cabecera From. */
    private static function cabeceraNombre(string $nombre): string
    {
        return preg_match('/^[\x20-\x7E]*$/', $nombre)
            ? '"' . addcslashes($nombre, '"\\') . '"'
            : '=?UTF-8?B?' . base64_encode($nombre) . '?=';
    }

    private static function cabeceraAsunto(string $asunto): string
    {
        $asunto = str_replace(["\r", "\n"], '', $asunto);
        return preg_match('/^[\x20-\x7E]*$/', $asunto)
            ? $asunto
            : '=?UTF-8?B?' . base64_encode($asunto) . '?=';
    }

    // -----------------------------------------------------------------
    // Plantilla
    // -----------------------------------------------------------------

    /**
     * Envuelve el contenido en la plantilla de la marca.
     * Se usa una tabla y estilos en línea porque es lo único que renderizan
     * igual Gmail, Outlook y los clientes de móvil.
     */
    /**
     * ¿Un texto sobre este color de fondo tiene que ser claro u oscuro?
     *
     * La marca la elige el negocio desde el panel, y puede poner un rosa
     * pastel o un vino oscuro. Con la luminancia relativa (la misma fórmula
     * que usan las guías de accesibilidad) el texto siempre se lee, sin tener
     * que acordarse de cambiarlo a mano al cambiar de color.
     */
    private static function textoSobre(string $fondo): string
    {
        $hex = ltrim(trim($fondo), '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (!preg_match('/^[0-9a-f]{6}$/i', $hex)) {
            return '#2C2124';
        }

        $canal = static function (int $v): float {
            $c = $v / 255;
            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };
        $luz = 0.2126 * $canal((int)hexdec(substr($hex, 0, 2)))
             + 0.7152 * $canal((int)hexdec(substr($hex, 2, 2)))
             + 0.0722 * $canal((int)hexdec(substr($hex, 4, 2)));

        return $luz > 0.45 ? '#2C2124' : '#FFFFFF';
    }

    /**
     * Cabecera de la marca: logo si se puede, y si no el nombre.
     *
     * Los logos en SVG salen rotos en Gmail y en Outlook, que no los cargan.
     * Como el panel deja subir SVG —y el logo de ejemplo lo es— aquí solo se
     * usa <img> con formatos que todos los clientes de correo pintan; en los
     * demás casos se escribe el nombre de la tienda, que nunca falla.
     */
    private static function marca(string $colorTexto): string
    {
        $tienda = Ajustes::texto('nombre_tienda', 'Flowers Anto');
        $logo   = trim(Ajustes::texto('logo_url', ''));
        $ext    = strtolower((string)pathinfo(parse_url($logo, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));

        $nombre = '<div style="font-size:20px;font-weight:700;letter-spacing:.01em;color:' . $colorTexto . ';">'
                . e($tienda) . '</div>';

        if ($logo === '' || !in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'gif'], true)) {
            return $nombre;
        }

        // El nombre acompaña siempre al logo: si el cliente tiene las imágenes
        // bloqueadas —Gmail y Outlook lo hacen por defecto— igual sabe de quién
        // es el correo.
        return '<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>
            <td style="vertical-align:middle;padding-right:12px;">
              <img src="' . e(url_absoluta($logo)) . '" alt="" width="46" height="46"
                   style="display:block;width:46px;height:46px;border-radius:50%;object-fit:cover;border:0;">
            </td>
            <td style="vertical-align:middle;">' . $nombre . '</td>
          </tr></table>';
    }

    /**
     * Plantilla de todos los correos que salen del sitio.
     *
     * Va con la marca que hay configurada —logo, nombre, eslogan y colores—
     * porque estos mensajes son la cara de la floristería en la bandeja de
     * entrada del cliente, no un aviso de sistema.
     *
     * Está escrita con tablas y estilos en línea a propósito: es lo único que
     * pintan igual Gmail, Outlook y el correo del teléfono. Nada de flexbox,
     * de clases ni de hojas de estilo externas.
     */
    public static function plantilla(string $titulo, string $contenidoHtml, array $boton = []): string
    {
        $tienda   = e(Ajustes::texto('nombre_tienda', 'Flowers Anto'));
        $eslogan  = e(Ajustes::texto('eslogan', ''));
        $primario = Ajustes::texto('color_primario', '#F8B0C2');
        $tinta    = Ajustes::texto('color_texto', '#4A3B3D');
        $fondo    = Ajustes::texto('color_fondo', '#FFF9F5');
        $anio     = date('Y');

        // Colores validados: lo que viene de la base se pinta dentro de un
        // atributo style, así que tiene que ser un color y nada más.
        $color = static function (string $v, string $def): string {
            return preg_match('/^#[0-9a-f]{3,6}$/i', trim($v)) ? trim($v) : $def;
        };
        $primario = $color($primario, '#F8B0C2');
        $tinta    = $color($tinta, '#4A3B3D');
        $fondo    = $color($fondo, '#FFF9F5');

        $sobrePrimario = self::textoSobre($primario);
        $marca         = self::marca($sobrePrimario);
        // Los enlaces van en la tinta de la marca y no en el color primario:
        // un rosa pastel sobre fondo blanco no se lee.
        $enlace        = $tinta;

        $htmlBoton = '';
        if (!empty($boton['url']) && !empty($boton['texto'])) {
            $htmlBoton = '<tr><td align="center" style="padding:4px 32px 30px;">
                <a href="' . e($boton['url']) . '"
                   style="display:inline-block;background:' . $primario . ';color:' . $sobrePrimario . ';
                          text-decoration:none;padding:14px 30px;border-radius:10px;
                          font-weight:700;font-size:15px;">'
                . e($boton['texto']) . '</a></td></tr>';
        }

        // Datos de contacto reales en el pie: un correo de una tienda sin
        // teléfono ni dirección parece spam.
        $pie = array_filter([
            trim(Ajustes::texto('direccion', '')),
            trim(Ajustes::texto('telefono', '')),
            trim(Ajustes::texto('email_contacto', '')),
        ]);

        return '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="color-scheme" content="light only"><title>' . e($titulo) . '</title>
<style>
  .cuerpo a { color: ' . $enlace . '; text-decoration: underline; }
  @media (max-width: 480px) {
    .caja { border-radius: 0 !important; }
    .relleno { padding-left: 20px !important; padding-right: 20px !important; }
  }
</style></head>
<body style="margin:0;padding:0;background:' . $fondo . ';">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;">' . e($titulo) . '</div>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"
       style="background:' . $fondo . ';padding:24px 12px;
              font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;">
<tr><td align="center">
  <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="560" class="caja"
         style="width:100%;max-width:560px;background:#ffffff;border-radius:16px;overflow:hidden;
                box-shadow:0 1px 3px rgba(44,33,36,.08);">

    <tr><td class="relleno" style="background:' . $primario . ';padding:26px 32px;">
      <table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>
        <td style="vertical-align:middle;">' . $marca . '</td>
        ' . ($eslogan !== ''
            ? '<td style="vertical-align:middle;padding-left:14px;font-size:13px;line-height:1.5;
                          color:' . $sobrePrimario . ';opacity:.85;">' . $eslogan . '</td>'
            : '') . '
      </tr></table>
    </td></tr>

    <tr><td class="relleno" style="padding:30px 32px 0;">
      <h1 style="margin:0;font-size:22px;line-height:1.35;color:' . $tinta . ';font-weight:700;">'
        . e($titulo) . '</h1>
    </td></tr>
    <tr><td class="cuerpo relleno" style="padding:16px 32px 24px;color:' . $tinta . ';font-size:15px;line-height:1.7;">'
      . $contenidoHtml . '</td></tr>
    ' . $htmlBoton . '

    <tr><td class="relleno" style="padding:20px 32px 26px;border-top:1px solid #F1E7E9;
                   color:#8A7A7D;font-size:12.5px;line-height:1.7;">
      <strong style="color:' . $tinta . ';">' . $tienda . '</strong>'
      . ($pie ? '<br>' . e(implode(' · ', $pie)) : '') . '
      <br><br>Este mensaje se envió automáticamente. Si no esperabas este correo, puedes ignorarlo.
      <br>© ' . $anio . ' ' . $tienda . '.
    </td></tr>
  </table>
</td></tr>
</table></body></html>';
    }
}
