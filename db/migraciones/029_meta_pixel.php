<?php
/**
 * Píxel de Meta (Facebook e Instagram) y API de Conversiones.
 *
 * Igual que Analytics, vive en la base y no en el código: el identificador
 * cambia si mañana se crea otro píxel, y la tienda tiene que poder apagarlo
 * desde el panel sin volver a subir nada.
 *
 * El identificador del píxel NO es un secreto —viaja en el HTML de cualquier
 * sitio que lo use—. El token de la API de Conversiones SÍ lo es: con él se
 * pueden mandar eventos en nombre de la tienda. Por eso el panel nunca lo
 * vuelve a enseñar una vez guardado.
 *
 * Prefijo `pixel_`: `meta_` ya lo usa la descripción para buscadores
 * (`meta_descripcion`) y mezclarlos confundiría a quien lea la tabla.
 */

declare(strict_types=1);

return function (PDO $pdo, Esquema $e): void {
    // Nace apagado: hace falta el identificador de la cuenta de Meta de la
    // tienda, que no existe hasta que la dueña lo crea.
    $e->agregarColumna('configuracion', 'pixel_activo', "TINYINT(1) NOT NULL DEFAULT 0");
    // Solo dígitos: 15 o 16 hoy, se deja holgura por si Meta lo alarga.
    $e->agregarColumna('configuracion', 'pixel_id', "VARCHAR(20) NOT NULL DEFAULT ''");
    // Token de acceso de la API de Conversiones (Administrador de eventos →
    // Configuración → Generar token). Los de Meta rondan los 200 caracteres.
    $e->agregarColumna('configuracion', 'pixel_token', "VARCHAR(400) NOT NULL DEFAULT ''");
    // Código de «Probar eventos» (TEST12345). Solo mientras se comprueba: con
    // él puesto, lo que manda el servidor no cuenta para las campañas.
    $e->agregarColumna('configuracion', 'pixel_prueba', "VARCHAR(40) NOT NULL DEFAULT ''");
    // Igual que en Analytics: la dueña entra a su tienda a diario y Meta la
    // tomaría por una clienta interesadísima a la que enseñar anuncios.
    $e->agregarColumna('configuracion', 'pixel_excluir_equipo', "TINYINT(1) NOT NULL DEFAULT 1");
};
