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
    /**
     * Fragmentos que delatan un dato que no debe quedar escrito.
     *
     * Se comparan como SUBCADENA del nombre del campo, no como nombre exacto:
     * así «paypal_secreto», «smtp_password» o «token_hash» quedan cubiertos sin
     * tener que enumerarlos uno a uno, y un campo que se añada mañana con un
     * nombre parecido nace protegido.
     *
     * Van los términos en español y en inglés porque el proyecto mezcla los
     * dos: las columnas se llaman en español, pero los datos que vienen de
     * PayPal o de SMTP llegan con sus nombres originales.
     */
    private const CLAVES_PROHIBIDAS = [
        'password', 'password_hash', 'contrasena', 'contraseña', 'clave',
        'token', 'csrf_token', 'token_hash', 'secret', 'secreto', 'client_secret',
        'respuesta_seguridad', 'nueva_password', 'confirmar_password', 'db_pass',
        'llave', 'api_key', 'apikey', 'authorization', 'autorizacion', 'bearer', 'cvv',
    ];

    /**
     * Hasta dónde baja el filtrado dentro de estructuras anidadas.
     *
     * Un tope hace falta: sin él, un dato con referencias circulares o muy
     * hondo podría dar vueltas hasta agotar la memoria. Tres niveles cubren de
     * sobra cualquier detalle que se anote aquí.
     */
    private const HONDURA_MAX = 3;

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

        return mb_substr(
            (string)json_encode(self::filtrar($detalles, 0), JSON_UNESCAPED_UNICODE),
            0,
            2000
        );
    }

    /**
     * Oculta los valores sensibles a cualquier profundidad.
     *
     * Antes solo se miraba el primer nivel. Con un detalle plano bastaba, que
     * es lo único que se anota hoy, pero una clave escondida un nivel más
     * abajo —«config» → «paypal_secreto»— se serializaba entera y el secreto
     * acababa escrito en claro. Bajar por toda la estructura cuesta lo mismo y
     * cierra el agujero antes de que alguien lo abra sin darse cuenta.
     *
     * @param array<array-key,mixed> $datos
     * @return array<array-key,mixed>
     */
    private static function filtrar(array $datos, int $hondura): array
    {
        $limpio = [];

        foreach ($datos as $clave => $valor) {
            if (self::claveSensible((string)$clave)) {
                $limpio[$clave] = '[oculto]';
                continue;
            }

            if (is_array($valor)) {
                // Más hondo de la cuenta no se sigue mirando, así que tampoco
                // se guarda: vale más perder un detalle que escribir un secreto.
                $limpio[$clave] = $hondura < self::HONDURA_MAX
                    ? self::filtrar($valor, $hondura + 1)
                    : '[…]';
                continue;
            }

            if (is_scalar($valor) || $valor === null) {
                $limpio[$clave] = is_string($valor) ? mb_substr($valor, 0, 300) : $valor;
                continue;
            }

            // Objetos y demás: se anota el tipo, nunca el contenido, que puede
            // arrastrar cualquier cosa dentro.
            $limpio[$clave] = '[' . get_debug_type($valor) . ']';
        }

        return $limpio;
    }

    /** ¿El nombre de este campo delata un dato que no debe quedar escrito? */
    private static function claveSensible(string $clave): bool
    {
        $normal = mb_strtolower($clave);
        foreach (self::CLAVES_PROHIBIDAS as $prohibida) {
            if (str_contains($normal, $prohibida)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Compara dos filas y devuelve solo lo que cambió, para anotarlo.
     *
     * De los campos sensibles se anota QUE cambiaron y nada más. El valor no
     * llega a existir en el array que se devuelve: `limpiarDetalles()` lo
     * ocultaría igual al guardar, pero este método es público y su resultado
     * podría acabar mañana en otro sitio —una descripción, un registro de
     * error— donde ya no habría filtro. Un secreto que nunca se copia no se
     * puede filtrar por descuido.
     *
     * Y para la auditoría es lo que importa: quién tocó la credencial y
     * cuándo. El valor no aporta nada que se pueda usar sin poder usarse
     * también para robarlo.
     */
    public static function diferencias(array $antes, array $despues, array $campos): array
    {
        $cambios = [];
        foreach ($campos as $campo) {
            $a = $antes[$campo] ?? null;
            $d = $despues[$campo] ?? null;
            if ((string)$a === (string)$d) {
                continue;
            }
            $cambios[$campo] = self::claveSensible((string)$campo)
                ? 'cambiado'
                : ['antes' => $a, 'ahora' => $d];
        }
        return $cambios;
    }
}
