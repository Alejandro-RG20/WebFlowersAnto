<?php
/**
 * Punto de partida del acceso con Facebook: redirige al diálogo de Facebook.
 * Con la sesión abierta, solo para conectar Facebook desde «Mis datos».
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/lib/cuentas_externas.php';
require_once __DIR__ . '/../includes/lib/facebook.php';

if (!CuentasExternas::puedeEmpezar('facebook')) {
    redirigir('cuenta/pedidos.php');
}

if (!Facebook::disponible($pdo)) {
    flash('error', 'El acceso con Facebook no está configurado en este sitio.');
    redirigir(Auth::autenticado() ? 'cuenta/perfil.php' : 'cuenta/entrar.php');
}

redirigir_externo(Facebook::urlAutorizacion(Auth::autenticado() ? '' : texto('volver', 120, $_GET)));
