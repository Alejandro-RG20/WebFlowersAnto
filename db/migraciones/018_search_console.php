<?php
/**
 * Verificación del sitio en Google Search Console.
 *
 * Search Console es lo que dice si Google encuentra la tienda, qué busca la
 * gente antes de llegar y qué páginas dan error. Para dejarle mirar hay que
 * demostrar que el sitio es tuyo, y una de las formas es dejar una etiqueta
 * con un código en el <head> de la portada.
 *
 * El código NO es un secreto: sirve para demostrar la propiedad, no para
 * acceder a nada, y viaja en el HTML de todas las páginas de cualquier sitio
 * que lo use. Por eso puede quedar escrito aquí sin problema.
 *
 * Va en la base y no en el código porque el día que se cree otra propiedad en
 * Search Console —o se verifique con otra cuenta— cambia, y nadie debería
 * tener que editar un archivo y volver a subirlo por eso.
 */

declare(strict_types=1);

return function (PDO $pdo, Esquema $e): void {
    $e->agregarColumna('configuracion', 'seo_google_verificacion', "VARCHAR(120) NOT NULL DEFAULT ''");

    $pdo->prepare(
        "UPDATE configuracion SET seo_google_verificacion = ?
          WHERE id = 1 AND seo_google_verificacion = ''"
    )->execute(['2mphJQdUI4LGCzfBKsHo0ciybj3jYRCm80_oUkm0koQ']);
};
