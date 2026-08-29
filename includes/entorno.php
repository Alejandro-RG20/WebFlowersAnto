<?php
/**
 * Carga de configuración.
 *
 * Prioridad, de mayor a menor:
 *   1. variable de entorno real (putenv / SetEnv de Apache / panel del hosting)
 *   2. archivo .env en la raíz del proyecto
 *   3. config.local.php (hostings que no permiten archivos ocultos)
 *   4. valor por defecto indicado en la llamada
 *
 * No hay dependencias: el parser de .env son treinta líneas y evita
 * arrastrar Composer a un proyecto que se despliega copiando carpetas.
 */

declare(strict_types=1);

final class Entorno
{
    /** @var array<string,string> */
    private static array $valores = [];
    private static bool $cargado = false;

    public static function cargar(string $raiz): void
    {
        if (self::$cargado) {
            return;
        }
        self::$cargado = true;

        $local = $raiz . '/config.local.php';
        if (is_file($local)) {
            $datos = require $local;
            if (is_array($datos)) {
                foreach ($datos as $clave => $valor) {
                    self::$valores[(string)$clave] = (string)$valor;
                }
            }
        }

        $env = $raiz . '/.env';
        if (is_file($env) && is_readable($env)) {
            foreach (self::parsear((string)file_get_contents($env)) as $clave => $valor) {
                self::$valores[$clave] = $valor;
            }
        }
    }

    /** @return array<string,string> */
    private static function parsear(string $contenido): array
    {
        $salida = [];
        foreach (preg_split('/\R/', $contenido) ?: [] as $linea) {
            $linea = trim($linea);
            if ($linea === '' || $linea[0] === '#' || !str_contains($linea, '=')) {
                continue;
            }
            [$clave, $valor] = explode('=', $linea, 2);
            $clave = trim($clave);
            $valor = trim($valor);

            // Comillas opcionales alrededor del valor
            $len = strlen($valor);
            if ($len >= 2 && (
                ($valor[0] === '"' && $valor[$len - 1] === '"') ||
                ($valor[0] === "'" && $valor[$len - 1] === "'")
            )) {
                $valor = substr($valor, 1, -1);
            }
            if ($clave !== '') {
                $salida[$clave] = $valor;
            }
        }
        return $salida;
    }

    public static function texto(string $clave, string $def = ''): string
    {
        $real = getenv($clave);
        if ($real !== false && $real !== '') {
            return $real;
        }
        $v = self::$valores[$clave] ?? '';
        return $v !== '' ? $v : $def;
    }

    public static function entero(string $clave, int $def = 0): int
    {
        $v = self::texto($clave, '');
        return $v === '' ? $def : (int)$v;
    }

    public static function bandera(string $clave, bool $def = false): bool
    {
        $v = strtolower(self::texto($clave, ''));
        if ($v === '') {
            return $def;
        }
        return in_array($v, ['1', 'true', 'si', 'sí', 'yes', 'on'], true);
    }
}
