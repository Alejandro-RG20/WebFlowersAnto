<?php
/**
 * Google Analytics 4.
 *
 * Los ajustes viven en la base y no en el código porque el identificador de
 * medición cambia si mañana se crea otra propiedad, y porque la tienda tiene
 * que poder apagar la medición sin tocar un archivo ni volver a subir nada.
 *
 * El identificador de medición (G-XXXXXXXX) NO es un secreto: viaja en el HTML
 * de todas las páginas de cualquier sitio que use Analytics, así que se puede
 * dejar escrito aquí. El que sí sería secreto —la clave de la API de datos—
 * no hace falta para medir y por eso no existe en este proyecto.
 */

declare(strict_types=1);

return function (PDO $pdo, Esquema $e): void {
    // Nace encendida: la cuenta de Analytics ya existe y el sitio ya pregunta
    // por las cookies antes de guardar nada, así que no hay nada que esperar.
    // Se apaga con un interruptor en Configuración → Analytics.
    $e->agregarColumna('configuracion', 'ga_activo', "TINYINT(1) NOT NULL DEFAULT 1");
    $e->agregarColumna('configuracion', 'ga_id', "VARCHAR(24) NOT NULL DEFAULT ''");
    // GA4 exige una moneda ISO-4217 de tres letras. «C$» es el símbolo, no el
    // código: con el símbolo los ingresos llegan pero sin poder convertirse.
    $e->agregarColumna('configuracion', 'ga_moneda', "VARCHAR(3) NOT NULL DEFAULT 'NIO'");
    // Con esto los eventos aparecen en el DebugView de Analytics al momento,
    // en vez de esperar a los informes. Se enciende para comprobar y se apaga.
    $e->agregarColumna('configuracion', 'ga_depurar', "TINYINT(1) NOT NULL DEFAULT 0");
    // La dueña entra a su propia tienda todos los días. Sin esto sus visitas
    // se cuentan como clientes y las estadísticas dejan de decir la verdad.
    $e->agregarColumna('configuracion', 'ga_excluir_equipo', "TINYINT(1) NOT NULL DEFAULT 1");

    // La cuenta ya existe: se deja lista para que solo haya que encenderla.
    $pdo->prepare("UPDATE configuracion SET ga_id = ? WHERE id = 1 AND ga_id = ''")
        ->execute(['G-PDKHLCW7VW']);
};
