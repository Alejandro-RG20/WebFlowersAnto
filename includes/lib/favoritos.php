<?php
/**
 * Favoritos.
 *
 * Con sesión iniciada se guardan en la tabla `favoritos` y siguen al usuario
 * entre dispositivos. Sin sesión viven en la sesión de PHP (y en localStorage
 * del navegador, para que sobrevivan al cierre de la pestaña). Al entrar, los
 * de la visita se suman a los de la cuenta.
 */

declare(strict_types=1);

final class Favoritos
{
    private const CLAVE    = 'favoritos';
    private const SEMBRADO = 'favoritos_sembrados';

    /**
     * ¿Ya se restauró en esta sesión la copia que guarda el navegador?
     *
     * Mientras no esté sembrada, la lista de localStorage puede repoblar la
     * sesión. Una vez sembrada, manda el servidor: si no, cada carga de
     * página devolvería a la vida lo que el visitante acaba de quitar.
     */
    public static function sembrado(): bool
    {
        return !empty($_SESSION[self::SEMBRADO]);
    }

    /** Escribe la lista del visitante y da la siembra por hecha. */
    private static function guardarVisita(array $ids): void
    {
        $_SESSION[self::CLAVE]    = array_values(array_unique(array_map('intval', $ids)));
        $_SESSION[self::SEMBRADO] = true;
        self::olvidar();
    }

    /**
     * Caché de la petición en curso.
     *
     * La cabecera pinta el contador y la página vuelve a pedir la lista para
     * marcar los corazones, así que sin esto una misma carga repetía la
     * consulta. Cualquier escritura la descarta.
     *
     * @var int[]|null
     */
    private static ?array $cache = null;

    /** Descarta la caché: la lista acaba de cambiar. */
    private static function olvidar(): void
    {
        self::$cache = null;
    }

    /** @return int[] */
    public static function ids(PDO $pdo): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $usuarioId = Auth::id();
        if ($usuarioId) {
            $st = $pdo->prepare("SELECT producto_id FROM favoritos WHERE usuario_id = ? ORDER BY created_at DESC");
            $st->execute([$usuarioId]);
            return self::$cache = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        }
        $lista = $_SESSION[self::CLAVE] ?? [];
        return self::$cache = (is_array($lista) ? array_map('intval', $lista) : []);
    }

    public static function contiene(PDO $pdo, int $productoId): bool
    {
        return in_array($productoId, self::ids($pdo), true);
    }

    public static function total(PDO $pdo): int
    {
        return count(self::ids($pdo));
    }

    /** Añade o quita. Devuelve true si quedó marcado como favorito. */
    public static function alternar(PDO $pdo, int $productoId): bool
    {
        $st = $pdo->prepare("SELECT 1 FROM productos WHERE id = ? AND activo = 1");
        $st->execute([$productoId]);
        if (!$st->fetchColumn()) {
            return false;
        }

        $usuarioId = Auth::id();
        if ($usuarioId) {
            $existe = $pdo->prepare("SELECT 1 FROM favoritos WHERE usuario_id = ? AND producto_id = ?");
            $existe->execute([$usuarioId, $productoId]);
            if ($existe->fetchColumn()) {
                $pdo->prepare("DELETE FROM favoritos WHERE usuario_id = ? AND producto_id = ?")
                    ->execute([$usuarioId, $productoId]);
                self::olvidar();
                return false;
            }
            $pdo->prepare("INSERT IGNORE INTO favoritos (usuario_id, producto_id) VALUES (?, ?)")
                ->execute([$usuarioId, $productoId]);
            self::olvidar();
            return true;
        }

        $lista = self::ids($pdo);
        $pos   = array_search($productoId, $lista, true);
        if ($pos !== false) {
            unset($lista[$pos]);
            self::guardarVisita($lista);
            return false;
        }
        if (count($lista) >= 100) {
            array_pop($lista);
        }
        array_unshift($lista, $productoId);
        self::guardarVisita($lista);
        return true;
    }

    public static function quitar(PDO $pdo, int $productoId): void
    {
        $usuarioId = Auth::id();
        if ($usuarioId) {
            $pdo->prepare("DELETE FROM favoritos WHERE usuario_id = ? AND producto_id = ?")
                ->execute([$usuarioId, $productoId]);
            self::olvidar();
            return;
        }
        self::guardarVisita(array_diff(self::ids($pdo), [$productoId]));
    }

    /** Productos favoritos completos, listos para pintar. */
    public static function productos(PDO $pdo): array
    {
        $ids = self::ids($pdo);
        if (!$ids) {
            return [];
        }
        $porId = Catalogo::porIds($pdo, $ids);
        $orden = [];
        foreach ($ids as $id) {
            if (isset($porId[$id])) {
                $orden[] = $porId[$id];
            }
        }
        return $orden;
    }

    /** Al iniciar sesión: pasa los favoritos de la visita a la cuenta. */
    public static function fusionarAlEntrar(PDO $pdo, int $usuarioId): void
    {
        $deVisita = $_SESSION[self::CLAVE] ?? [];
        unset($_SESSION[self::CLAVE]);
        $_SESSION[self::SEMBRADO] = true;
        self::olvidar();
        if (!is_array($deVisita) || !$deVisita) {
            return;
        }
        $ins = $pdo->prepare("INSERT IGNORE INTO favoritos (usuario_id, producto_id) VALUES (?, ?)");
        foreach (array_slice(array_map('intval', $deVisita), 0, 100) as $id) {
            try {
                $ins->execute([$usuarioId, $id]);
            } catch (PDOException) {
                // El producto pudo borrarse mientras tanto: se ignora.
            }
        }
    }

    /**
     * Restaura la copia que el navegador guarda en localStorage.
     *
     * Corre como mucho una vez por sesión. Sin ese candado, el navegador
     * reenviaba su lista en cada carga y volvía a insertar justo lo que el
     * visitante acababa de borrar: los favoritos resucitaban solos.
     */
    public static function fusionarLista(PDO $pdo, array $ids): void
    {
        if (self::sembrado()) {
            return;
        }
        $usuarioId = Auth::id();
        $ids = array_slice(array_filter(array_map('intval', $ids)), 0, 100);
        if (!$usuarioId) {
            self::guardarVisita(array_merge($ids, self::ids($pdo)));
            return;
        }
        $_SESSION[self::SEMBRADO] = true;
        if (!$ids) {
            return;
        }
        $ins = $pdo->prepare("INSERT IGNORE INTO favoritos (usuario_id, producto_id) VALUES (?, ?)");
        foreach ($ids as $id) {
            try {
                $ins->execute([$usuarioId, $id]);
            } catch (PDOException) {
            }
        }
        self::olvidar();
    }
}
