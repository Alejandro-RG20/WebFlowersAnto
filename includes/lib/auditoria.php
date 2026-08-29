<?php
/**
 * Registro de auditoría.
 *
 * Es de solo escritura desde la aplicación: no hay ninguna ruta que borre ni
 * edite filas, ni en el panel ni en la API. Consultarla exige el permiso
 * `auditoria.ver`.
 *
 * Nunca se guardan contraseñas, hashes, tokens ni el contenido de un
 * comprobante: `limpiarDetalles()` filtra esas claves antes de serializar.
 */

declare(strict_types=1);

final class Auditoria
{
    private const CLAVES_PROHIBIDAS = [
        'password', 'password_hash', 'contrasena', 'contraseña', 'clave',
        'token', 'csrf_token', 'token_hash', 'secret', 'client_secret',
        'respuesta_seguridad', 'nueva_password', 'confirmar_password', 'db_pass',
    ];

    /**
     * Anota una acción.
     *
     * @param string $accion      Verbo corto: crear, editar, eliminar, aprobar…
     * @param string $modulo      productos, pedidos, usuarios, sistema…
     * @param array  $opciones    recurso_tipo, recurso_id, descripcion, resultado, detalles
     */
    public static function registrar(PDO $pdo, string $accion, string $modulo, array $opciones = []): void
    {
        $usuario = Auth::usuario();

        $detalles = self::limpiarDetalles($opciones['detalles'] ?? null);
        $resultado = (string)($opciones['resultado'] ?? 'exito');
        if (!in_array($resultado, ['exito', 'fallo', 'denegado'], true)) {
            $resultado = 'exito';
        }

        try {
            $pdo->prepare(
                "INSERT INTO auditoria
                    (usuario_id, usuario_texto, rol, accion, modulo, recurso_tipo, recurso_id,
                     resultado, descripcion, detalles, ip, user_agent)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)"
            )->execute([
                $usuario['id'] ?? null,
                mb_substr((string)($opciones['usuario_texto'] ?? ($usuario ? Auth::nombreCompleto($usuario) : 'Visitante')), 0, 150),
                mb_substr((string)($usuario['rol_codigo'] ?? ''), 0, 60),
                mb_substr($accion, 0, 60),
                mb_substr($modulo, 0, 40),
                mb_substr((string)($opciones['recurso_tipo'] ?? ''), 0, 40),
                mb_substr((string)($opciones['recurso_id'] ?? ''), 0, 40),
                $resultado,
                mb_substr((string)($opciones['descripcion'] ?? ''), 0, 500),
                $detalles,
                ip_cliente(),
                mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            ]);
        } catch (PDOException $ex) {
            // La auditoría nunca debe tumbar la operación que estaba anotando.
            error_log('Flowers Anto — auditoría: ' . $ex->getMessage());
        }
    }

    /** Atajo para las acciones denegadas por falta de permisos. */
    public static function denegado(PDO $pdo, string $permiso, string $modulo = 'seguridad'): void
    {
        self::registrar($pdo, 'acceso_denegado', $modulo, [
            'resultado'   => 'denegado',
            'descripcion' => 'Intento de acción sin el permiso ' . $permiso,
        ]);
    }

    /** Serializa los detalles quitando cualquier clave sensible. */
    private static function limpiarDetalles(mixed $detalles): ?string
    {
        if ($detalles === null || $detalles === []) {
            return null;
        }
        if (is_string($detalles)) {
            return mb_substr($detalles, 0, 2000);
        }
        if (!is_array($detalles)) {
            return null;
        }

        $limpio = [];
        foreach ($detalles as $clave => $valor) {
            $normal = mb_strtolower((string)$clave);
            $sensible = false;
            foreach (self::CLAVES_PROHIBIDAS as $prohibida) {
                if (str_contains($normal, $prohibida)) {
                    $sensible = true;
                    break;
                }
            }
            if ($sensible) {
                $limpio[$clave] = '[oculto]';
            } elseif (is_scalar($valor) || $valor === null) {
                $limpio[$clave] = is_string($valor) ? mb_substr($valor, 0, 300) : $valor;
            } else {
                $limpio[$clave] = mb_substr((string)json_encode($valor, JSON_UNESCAPED_UNICODE), 0, 300);
            }
        }
        return mb_substr((string)json_encode($limpio, JSON_UNESCAPED_UNICODE), 0, 2000);
    }

    /** Compara dos filas y devuelve solo lo que cambió, para anotarlo. */
    public static function diferencias(array $antes, array $despues, array $campos): array
    {
        $cambios = [];
        foreach ($campos as $campo) {
            $a = $antes[$campo] ?? null;
            $d = $despues[$campo] ?? null;
            if ((string)$a !== (string)$d) {
                $cambios[$campo] = ['antes' => $a, 'ahora' => $d];
            }
        }
        return $cambios;
    }
}
