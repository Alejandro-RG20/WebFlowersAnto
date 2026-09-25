<?php
/**
 * Punto de partida del acceso con Google: redirige a la pantalla de Google.
 * Con la sesión abierta, solo para conectar Google desde «Mis datos».
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/lib/cuentas_externas.php';
require_once __DIR__ . '/../includes/lib/google.php';

if (!CuentasExternas::puedeEmpezar('google')) {
    redirigir('cuenta/pedidos.php');
}

if (!Google::configurado()) {
    flash('error', 'El acceso con Google no está configurado en este sitio.');
    redirigir(Auth::autenticado() ? 'cuenta/perfil.php' : 'cuenta/entrar.php');
}

redirigir_externo(Google::urlAutorizacion(Auth::autenticado() ? '' : texto('volver', 120, $_GET)));
