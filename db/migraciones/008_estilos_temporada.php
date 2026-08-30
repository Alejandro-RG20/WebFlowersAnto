<?php
/**
 * Estilo visual asociado a cada temporada.
 *
 * La temporada ya sabía cuándo estaba vigente y de qué color era; le faltaba
 * decir cómo se ve el sitio mientras dure. Una columna basta: el catálogo de
 * estilos vive en el código (`Temporadas::ESTILOS`), porque cada uno lleva su
 * bloque de CSS y sus formas SVG.
 */

declare(strict_types=1);

return function (PDO $pdo, Esquema $e): void {

    $e->agregarColumna('temporadas', 'estilo', "VARCHAR(40) NOT NULL DEFAULT ''");

    // A las temporadas que ya existen se les propone un estilo por su nombre,
    // para que al actualizar no haya que entrar a asignarlos uno por uno. Solo
    // se toca lo que está vacío: una elección hecha a mano nunca se pisa.
    $porNombre = [
        'flores_amarillas' => ['amarill', 'girasol'],
        'san_valentin'     => ['valentin', 'valentín', 'san valent', 'amor', 'enamorad'],
        'primavera'        => ['primavera'],
        'dia_madres'       => ['madre', 'mamá', 'mama'],
        'navidad'          => ['navid', 'christmas', 'diciembre'],
        'halloween'        => ['halloween', 'brujas'],
        'ano_nuevo'        => ['año nuevo', 'ano nuevo', 'fin de año'],
        'verano'           => ['verano'],
    ];

    $filas = $pdo->query("SELECT id, nombre, titulo FROM temporadas WHERE estilo = ''")->fetchAll();
    $up    = $pdo->prepare("UPDATE temporadas SET estilo = ? WHERE id = ? AND estilo = ''");

    foreach ($filas as $t) {
        $texto = mb_strtolower((string)$t['nombre'] . ' ' . (string)$t['titulo']);
        foreach ($porNombre as $estilo => $pistas) {
            foreach ($pistas as $pista) {
                if (mb_strpos($texto, $pista) !== false) {
                    $up->execute([$estilo, (int)$t['id']]);
                    continue 3;
                }
            }
        }
    }
};
