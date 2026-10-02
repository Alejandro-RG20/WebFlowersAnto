<?php
/**
 * Foto de perfil del cliente.
 *
 * Vive en `fotos_perfil`, una fila por cuenta, y se sirve por
 * cuenta/foto.php solo a su dueño y al personal con permiso para ver
 * clientes. `usuarios.foto` guarda una huella corta de la foto actual: dice
 * si hay foto sin leer el binario y cambia la dirección en cuanto cambia la
 * foto, así el navegador puede guardarla un año sin enseñar una vieja.
 *
 * Sin la migración 024 no hay tabla y la foto simplemente no se ofrece.
 */

declare(strict_types=1);

final class FotoPerfil
{
    private static ?bool $disponible = null;

    public static function disponible(PDO $pdo): bool
    {
        if (self::$disponible === null) {
            try {
                $pdo->query("SELECT 1 FROM fotos_perfil LIMIT 0");
                $pdo->query("SELECT foto, fecha_nacimiento FROM usuarios LIMIT 0");
                self::$disponible = true;
            } catch (PDOException) {
                self::$disponible = false;
            }
        }
        return self::$disponible;
    }

    /** @return array{ok: bool, error?: string} */
    public static function guardar(PDO $pdo, int $usuarioId, array $archivo): array
    {
        $r = Archivos::prepararFotoPerfil($archivo);
        if (!$r['ok']) {
            return $r;
        }
        $sha = hash('sha256', $r['datos']);

        try {
            $pdo->beginTransaction();
            $st = $pdo->prepare(
                "INSERT INTO fotos_perfil (usuario_id, mime, tamano, sha256, datos) VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE mime = VALUES(mime), tamano = VALUES(tamano),
                                         sha256 = VALUES(sha256), datos = VALUES(datos)"
            );
            $st->bindValue(1, $usuarioId, PDO::PARAM_INT);
            $st->bindValue(2, $r['mime']);
            $st->bindValue(3, strlen($r['datos']), PDO::PARAM_INT);
            $st->bindValue(4, $sha);
            $st->bindValue(5, $r['datos'], PDO::PARAM_LOB);
            $st->execute();
            $pdo->prepare("UPDATE usuarios SET foto = ? WHERE id = ?")->execute([substr($sha, 0, 16), $usuarioId]);
            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Flowers Anto — foto de perfil: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'No pudimos guardar la foto. Inténtalo otra vez.'];
        }
        return ['ok' => true];
    }

    public static function quitar(PDO $pdo, int $usuarioId): void
    {
        $pdo->prepare("DELETE FROM fotos_perfil WHERE usuario_id = ?")->execute([$usuarioId]);
        $pdo->prepare("UPDATE usuarios SET foto = NULL WHERE id = ?")->execute([$usuarioId]);
    }

    /** Dirección de la foto de esa cuenta, o '' si no tiene. */
    public static function url(?array $usuario, bool $ajena = false): string
    {
        $huella = (string)($usuario['foto'] ?? '');
        if ($huella === '' || !preg_match('/^[a-f0-9]{16}$/', $huella)) {
            return '';
        }
        $consulta = ['v' => $huella];
        if ($ajena) {
            $consulta = ['u' => (int)$usuario['id']] + $consulta;
        }
        return url('cuenta/foto.php?' . http_build_query($consulta));
    }

    /** Iniciales para cuando no hay foto: «AM». */
    public static function iniciales(?array $usuario): string
    {
        $letras = '';
        foreach ([(string)($usuario['nombre'] ?? ''), (string)($usuario['apellido'] ?? '')] as $parte) {
            $parte = trim($parte);
            if ($parte !== '') {
                $letras .= mb_strtoupper(mb_substr($parte, 0, 1));
            }
        }
        return $letras !== '' ? $letras : '?';
    }
}
