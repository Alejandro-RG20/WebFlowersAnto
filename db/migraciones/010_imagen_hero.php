<?php
/**
 * Foto exclusiva del carrusel de portada.
 *
 * El carrusel se ve mejor con la flor recortada sobre fondo transparente,
 * pero esa misma imagen queda pobre en la rejilla del catálogo, donde las
 * tarjetas esperan una foto rectangular con fondo.
 *
 * Se resuelve con una columna aparte en vez de tocar la galería: si
 * `imagen_hero` está vacía el carrusel sigue usando la portada de siempre,
 * así que los productos ya cargados no cambian de aspecto. Y como el
 * catálogo no lee esta columna, el PNG recortado nunca se cuela ahí.
 */

declare(strict_types=1);

return function (PDO $pdo, Esquema $e): void {
    $e->agregarColumna('productos', 'imagen_hero', "VARCHAR(255) NOT NULL DEFAULT '' AFTER imagen4");
};
