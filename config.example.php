<?php
/**
 * Configuración alternativa para hostings donde no se pueden usar
 * variables de entorno ni subir archivos que empiecen por punto.
 *
 * Copia este archivo como `config.local.php`. Los valores que definas aquí
 * se usan solo cuando la variable de entorno equivalente no existe.
 * Orden de prioridad: variable de entorno real > .env > config.local.php.
 *
 * `config.local.php` está en .gitignore.
 */
return [
    'APP_ENTORNO'  => 'dev',
    'APP_BASE_URL' => '',
    'APP_URL'      => 'http://localhost/webANTO',

    'DB_HOST'    => 'localhost',
    'DB_PORT'    => '3306',
    'DB_NAME'    => 'flowers_anto',
    'DB_USER'    => 'root',
    'DB_PASS'    => '',
    'DB_CHARSET' => 'utf8mb4',

    'MAX_UPLOAD_MB'      => '5',
    'MAX_COMPROBANTE_MB' => '8',
    'MAX_RESPALDO_MB'    => '64',

    'GOOGLE_CLIENT_ID'     => '',
    'GOOGLE_CLIENT_SECRET' => '',

    // --- Correo saliente -------------------------------------------------
    // MAIL_TRANSPORTE: log | mail | smtp
    //   log   -> escribe los correos en storage/logs/correos.log y NO envía nada
    //   mail  -> función mail() de PHP. En XAMPP no funciona, y muchos hostings
    //            gratuitos la desactivan
    //   smtp  -> servidor de correo propio. Es el único que se recomienda
    'MAIL_TRANSPORTE'       => 'log',
    // Con Gmail, esta dirección debe ser la MISMA que SMTP_USUARIO: Google
    // reescribe el remitente si no coincide con la cuenta autenticada.
    'MAIL_REMITENTE'        => 'no-responder@flowersanto.com',
    'MAIL_REMITENTE_NOMBRE' => 'Flowers Anto',

    // Solo hacen falta con MAIL_TRANSPORTE = 'smtp'.
    // Gmail: smtp.gmail.com, puerto 587, seguridad tls, y como contraseña una
    // «contraseña de aplicación» de https://myaccount.google.com/apppasswords
    // (requiere verificación en dos pasos). La contraseña normal de la cuenta
    // NO sirve.
    'SMTP_HOST'      => '',
    'SMTP_PORT'      => '587',
    // SMTP_SEGURIDAD: tls | ssl | ninguna
    'SMTP_SEGURIDAD' => 'tls',
    'SMTP_USUARIO'   => '',
    'SMTP_PASSWORD'  => '',

    // --- Respaldos ---------------------------------------------------------
    // Ruta a mysqldump. Si se deja vacío o no existe, se usa el volcador propio
    // en PHP, que funciona en cualquier hosting sin acceso a consola.
    'MYSQLDUMP_BIN' => '',
];
