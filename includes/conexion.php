<?php
/**
 * Conexión a la base de datos, la misma para el arranque normal y el mínimo.
 *
 * La intercalación (collation) de la conexión se fija aquí y no se deja al
 * servidor. Cada versión de MariaDB y MySQL trae la suya por defecto
 * (utf8mb4_general_ci, utf8mb4_uca1400_ai_ci, utf8mb4_0900_ai_ci…) y, si no
 * coincide con la de las tablas o si el servidor asigna una a los textos
 * escritos en la consulta y otra a los valores que manda PHP, comparar dos
 * textos falla con «Illegal mix of collations». Pasó en Hostinger con el
 * aviso de cumpleaños del Resumen. Con la conexión en la intercalación de las
 * tablas (utf8mb4_unicode_ci, la de todas las migraciones), todo usa la misma.
 *
 * Lanza PDOException si no puede conectar: cada arranque decide qué mostrar.
 */

declare(strict_types=1);

function conexion_bd(): PDO
{
    $charset = Entorno::texto('DB_CHARSET', 'utf8mb4');
    $pdo = new PDO(
        sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            Entorno::texto('DB_HOST', 'localhost'),
            Entorno::texto('DB_PORT', '3306'),
            Entorno::texto('DB_NAME', 'flowers_anto'),
            $charset
        ),
        Entorno::texto('DB_USER', 'root'),
        Entorno::texto('DB_PASS', ''),
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false, // consultas preparadas reales
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ]
    );

    // Los dos valores van dentro de la sentencia, así que solo se aceptan
    // nombres válidos; la intercalación, además, ha de ser de ese juego de
    // caracteres (utf8mb4_… para utf8mb4).
    $collation = Entorno::texto('DB_COLLATION', 'utf8mb4_unicode_ci');
    if (preg_match('/^[a-z0-9]+$/', $charset)) {
        $pdo->exec(preg_match('/^[a-z0-9_]+$/', $collation) && str_starts_with($collation, $charset . '_')
            ? "SET NAMES $charset COLLATE $collation"
            : "SET NAMES $charset");
    }
    return $pdo;
}
