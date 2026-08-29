<?php
/**
 * El panel no tiene inicio de sesión propio: hay una sola tabla de usuarios y
 * un solo formulario. Esta ruta se conserva porque los enlaces antiguos
 * apuntaban aquí.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
redirigir('cuenta/entrar.php?volver=admin/');
