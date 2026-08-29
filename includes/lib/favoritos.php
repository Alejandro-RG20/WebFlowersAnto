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
    private const CLAVE = 'favoritos';

    /** @return int[] */
    public static function ids(PDO $pdo): array
    {
        $usuarioId = Auth::id();
        if ($usuarioId) {
            $st = $pdo->prepare("SELECT producto_id FROM favoritos WHERE usuario_id = ? ORDER BY created_at DESC");
            $st->execute([$usuarioId]);
            return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        }
        $lista = $_SESSION[self::CLAVE] ?? [];
        return is_array($lista) ? array_map('intval', $lista) : [];
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
                return false;
            }
            $pdo->prepare("INSERT IGNORE INTO favoritos (usuario_id, producto_id) VALUES (?, ?)")
                ->execute([$usuarioId, $productoId]);
            return true;
        }

        $lista = self::ids($pdo);
        $pos   = array_search($productoId, $lista, true);
        if ($pos !== false) {
            unset($lista[$pos]);
            $_SESSION[self::CLAVE] = array_values($lista);
            return false;
        }
        if (count($lista) >= 100) {
            array_pop($lista);
        }
        array_unshift($lista, $productoId);
        $_SESSION[self::CLAVE] = array_values($lista);
        return true;
    }

    public static function quitar(PDO $pdo, int $productoId): void
    {
        $usuarioId = Auth::id();
        if ($usuarioId) {
            $pdo->prepare("DELETE FROM favoritos WHERE usuario_id = ? AND producto_id = ?")
                ->execute([$usuarioId, $productoId]);
            return;
        }
        $lista = array_values(array_diff(self::ids($pdo), [$productoId]));
        $_SESSION[self::CLAVE] = $lista;
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

    /** Fusiona una lista enviada desde localStorage (visitantes que se registran). */
    public static function fusionarLista(PDO $pdo, array $ids): void
    {
        $usuarioId = Auth::id();
        $ids = array_slice(array_filter(array_map('intval', $ids)), 0, 100);
        if (!$ids) {
            return;
        }
        if (!$usuarioId) {
            $_SESSION[self::CLAVE] = array_values(array_unique(array_merge($ids, self::ids($pdo))));
            return;
        }
        $ins = $pdo->prepare("INSERT IGNORE INTO favoritos (usuario_id, producto_id) VALUES (?, ?)");
        foreach ($ids as $id) {
            try {
                $ins->execute([$usuarioId, $id]);
            } catch (PDOException) {
            }
        }
    }
}
