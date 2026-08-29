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

    'MAIL_TRANSPORTE'       => 'log',
    'MAIL_REMITENTE'        => 'no-responder@flowersanto.com',
    'MAIL_REMITENTE_NOMBRE' => 'Flowers Anto',

    'APP_CLAVE' => '',
];
