<?php
/**
 * Contenido multimedia de redes, además de YouTube.
 *
 * La tabla `videos_youtube` ya guardaba título, enlace, descripción y si se
 * muestra. Todo eso vale igual para un reel de Instagram, un video de
 * Facebook o un TikTok: lo único que falta es saber de qué plataforma es cada
 * fila, porque cada una se incrusta con una dirección distinta.
 *
 * Por eso se añade una sola columna y no una tabla nueva. El valor por
 * defecto es `youtube`, así que las filas que ya existen quedan marcadas
 * correctamente sin tocarlas y siguen viéndose igual que antes.
 *
 * El nombre de la tabla se queda como está aun cubriendo ya más plataformas:
 * renombrarla obligaría a tocar todas las consultas que la usan a cambio de
 * nada funcional, y un cambio así solo añade ocasiones de romper algo.
 */

declare(strict_types=1);

return function (PDO $pdo, Esquema $e): void {
    $e->agregarColumna('videos_youtube', 'plataforma', "VARCHAR(20) NOT NULL DEFAULT 'youtube'");

    // Por si alguna fila vieja quedó con la columna vacía en una instalación
    // donde la columna ya existía sin valor por defecto.
    $pdo->exec("UPDATE videos_youtube SET plataforma = 'youtube' WHERE plataforma = ''");
};
