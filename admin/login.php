<?php
/** Compatibilidad con la ruta de la versión anterior. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
redirigir('cuenta/entrar.php?volver=admin/');
