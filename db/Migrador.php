<?php
/**
 * Migrador de base de datos.
 *
 * Las migraciones son archivos PHP numerados en db/migraciones/ que devuelven
 * una función `function (PDO $pdo, Esquema $e) { ... }`. Se escriben en PHP y
 * no en SQL puro porque `ADD COLUMN IF NOT EXISTS` existe en MariaDB pero no en
 * MySQL 8: la clase Esquema consulta information_schema y hace que el mismo
 * archivo funcione en los dos motores y se pueda re-ejecutar sin romper nada.
 *
 * Se ejecuta desde consola:      php db/migrar.php
 * y desde el panel:              Administración → Base de datos
 */

declare(strict_types=1);

/** Operaciones de esquema idempotentes. */
final class Esquema
{
    public function __construct(private PDO $pdo, private string $baseDatos)
    {
    }

    public function tablaExiste(string $tabla): bool
    {
        $st = $this->pdo->prepare(
            "SELECT 1 FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? LIMIT 1"
        );
        $st->execute([$this->baseDatos, $tabla]);
        return (bool)$st->fetchColumn();
    }

    public function columnaExiste(string $tabla, string $columna): bool
    {
        $st = $this->pdo->prepare(
            "SELECT 1 FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1"
        );
        $st->execute([$this->baseDatos, $tabla, $columna]);
        return (bool)$st->fetchColumn();
    }

    public function indiceExiste(string $tabla, string $indice): bool
    {
        $st = $this->pdo->prepare(
            "SELECT 1 FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1"
        );
        $st->execute([$this->baseDatos, $tabla, $indice]);
        return (bool)$st->fetchColumn();
    }

    /** Añade la columna solo si falta. `$definicion` va sin el nombre. */
    public function agregarColumna(string $tabla, string $columna, string $definicion): void
    {
        if ($this->tablaExiste($tabla) && !$this->columnaExiste($tabla, $columna)) {
            $this->pdo->exec("ALTER TABLE `$tabla` ADD COLUMN `$columna` $definicion");
        }
    }

    /** Cambia la definición de una columna existente. */
    public function modificarColumna(string $tabla, string $columna, string $definicion): void
    {
        if ($this->columnaExiste($tabla, $columna)) {
            $this->pdo->exec("ALTER TABLE `$tabla` MODIFY `$columna` $definicion");
        }
    }

    /** Crea el índice solo si falta. `$columnas` va entre paréntesis. */
    public function agregarIndice(string $tabla, string $indice, string $columnas, bool $unico = false): void
    {
        if ($this->tablaExiste($tabla) && !$this->indiceExiste($tabla, $indice)) {
            $tipo = $unico ? 'UNIQUE INDEX' : 'INDEX';
            $this->pdo->exec("ALTER TABLE `$tabla` ADD $tipo `$indice` $columnas");
        }
    }

    public function eliminarIndice(string $tabla, string $indice): void
    {
        if ($this->indiceExiste($tabla, $indice)) {
            $this->pdo->exec("ALTER TABLE `$tabla` DROP INDEX `$indice`");
        }
    }

    /** Ejecuta una sentencia sin más. Atajo legible dentro de las migraciones. */
    public function sql(string $sentencia): void
    {
        $this->pdo->exec($sentencia);
    }
}

final class Migrador
{
    private Esquema $esquema;

    public function __construct(private PDO $pdo, private string $directorio, string $baseDatos)
    {
        $this->esquema = new Esquema($pdo, $baseDatos);
        $this->prepararRegistro();
    }

    private function prepararRegistro(): void
    {
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS migraciones (
                id         INT AUTO_INCREMENT PRIMARY KEY,
                nombre     VARCHAR(190) NOT NULL UNIQUE,
                lote       INT NOT NULL DEFAULT 1,
                ejecutada  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    /** @return string[] nombres de archivo, ordenados */
    public function disponibles(): array
    {
        $archivos = glob($this->directorio . '/*.php') ?: [];
        $nombres  = array_map('basename', $archivos);
        sort($nombres, SORT_NATURAL);
        return $nombres;
    }

    /** @return string[] */
    public function aplicadas(): array
    {
        return $this->pdo->query("SELECT nombre FROM migraciones ORDER BY id")
                         ->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    /** @return string[] */
    public function pendientes(): array
    {
        return array_values(array_diff($this->disponibles(), $this->aplicadas()));
    }

    /**
     * Aplica las migraciones pendientes.
     * @return array{aplicadas: string[], errores: array<string,string>}
     */
    public function ejecutar(?callable $registro = null): array
    {
        $lote = (int)$this->pdo->query("SELECT COALESCE(MAX(lote), 0) + 1 FROM migraciones")->fetchColumn();
        $hechas = [];
        $errores = [];

        foreach ($this->pendientes() as $nombre) {
            $migracion = require $this->directorio . '/' . $nombre;
            if (!is_callable($migracion)) {
                $errores[$nombre] = 'La migración no devuelve una función.';
                break;
            }
            try {
                // MySQL hace commit implícito en cada DDL, así que la transacción
                // no protegería el ALTER. Se registra migración a migración para
                // que un fallo a mitad deje claro dónde se detuvo.
                $migracion($this->pdo, $this->esquema);
                $this->pdo->prepare("INSERT INTO migraciones (nombre, lote) VALUES (?, ?)")
                          ->execute([$nombre, $lote]);
                $hechas[] = $nombre;
                if ($registro) {
                    $registro($nombre, null);
                }
            } catch (Throwable $ex) {
                $errores[$nombre] = $ex->getMessage();
                if ($registro) {
                    $registro($nombre, $ex->getMessage());
                }
                break; // no se sigue: el resto puede depender de esta
            }
        }

        return ['aplicadas' => $hechas, 'errores' => $errores];
    }
}
