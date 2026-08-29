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
     * Envía un correo HTML. Devuelve true si el transporte lo aceptó.
     * Un fallo nunca interrumpe la operación que lo disparó: se registra y ya.
     */
    public static function enviar(string $para, string $asunto, string $cuerpoHtml, string $cuerpoTexto = ''): bool
    {
        if (!filter_var($para, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        $cuerpoTexto = $cuerpoTexto !== '' ? $cuerpoTexto : trim(html_entity_decode(strip_tags($cuerpoHtml)));
        $transporte  = Entorno::texto('MAIL_TRANSPORTE', 'log');

        try {
            return match ($transporte) {
                'smtp'  => self::porSmtp($para, $asunto, $cuerpoHtml, $cuerpoTexto),
                'mail'  => self::porMail($para, $asunto, $cuerpoHtml),
                default => self::porArchivo($para, $asunto, $cuerpoHtml),
            };
        } catch (Throwable $ex) {
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
        return (bool)@file_put_contents(DIR_LOGS . '/correos.log', $linea, FILE_APPEND | LOCK_EX);
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
            error_log('Flowers Anto — SMTP_HOST vacío; el correo no se envió.');
            return false;
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
    public static function plantilla(string $titulo, string $contenidoHtml, array $boton = []): string
    {
        $tienda = e(Ajustes::texto('nombre_tienda', 'Flowers Anto'));
        $rosa   = e(Ajustes::texto('color_primario', '#C4788F'));
        $anio   = date('Y');

        $htmlBoton = '';
        if (!empty($boton['url']) && !empty($boton['texto'])) {
            $htmlBoton = '<tr><td style="padding:8px 32px 28px;">
                <a href="' . e($boton['url']) . '"
                   style="display:inline-block;background:#2C2124;color:#ffffff;text-decoration:none;
                          padding:13px 26px;border-radius:9px;font-weight:600;font-size:15px;">'
                . e($boton['texto']) . '</a></td></tr>';
        }

        return '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1"><title>' . e($titulo) . '</title></head>
<body style="margin:0;padding:24px 12px;background:#FBF7F6;
             font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;">
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"
       style="max-width:560px;margin:0 auto;background:#ffffff;border:1px solid #E8DCDF;border-radius:14px;overflow:hidden;">
  <tr><td style="padding:26px 32px 0;">
    <div style="font-size:13px;letter-spacing:.14em;text-transform:uppercase;color:' . $rosa . ';font-weight:600;">'
      . $tienda . '</div>
    <h1 style="margin:12px 0 0;font-size:22px;line-height:1.3;color:#2C2124;font-weight:600;">' . e($titulo) . '</h1>
  </td></tr>
  <tr><td style="padding:16px 32px 8px;color:#4A3B3D;font-size:15px;line-height:1.65;">' . $contenidoHtml . '</td></tr>
  ' . $htmlBoton . '
  <tr><td style="padding:20px 32px 26px;border-top:1px solid #F1E7E9;color:#8A7A7D;font-size:12.5px;line-height:1.6;">
    Este mensaje se envió automáticamente desde ' . $tienda . '. Si no esperabas este correo, puedes ignorarlo.
    <br>© ' . $anio . ' ' . $tienda . '.
  </td></tr>
</table></body></html>';
    }
}
