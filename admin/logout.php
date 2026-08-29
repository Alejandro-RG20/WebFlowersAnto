<?php
/**
 * Compatibilidad. El cierre de sesión real exige POST con token; aquí solo se
 * lleva al usuario al sitio con un aviso, sin cerrar nada por GET.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
flash('info', 'Usa el botón «Salir» del panel para cerrar la sesión.');
redirigir('admin/');
