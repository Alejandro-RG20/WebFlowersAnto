<?php
/**
 * Cierre de sesión. Solo por POST y con token: así un enlace externo no puede
 * desconectar a nadie.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verificarToken($_POST['csrf_token'] ?? null)) {
    redirigir('/');
}

if (Auth::autenticado()) {
    Auditoria::registrar($pdo, 'cierre_sesion', 'usuarios', [
        'recurso_tipo' => 'usuario',
        'recurso_id'   => (string)Auth::id(),
        'descripcion'  => 'Cierre de sesión.',
    ]);
}

Auth::cerrarSesion();
session_start();
flash('info', 'Cerraste sesión. ¡Vuelve pronto!');
redirigir('/');
