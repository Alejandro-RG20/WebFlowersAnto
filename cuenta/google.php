<?php
/**
 * Punto de partida del acceso con Google: redirige a la pantalla de Google.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/lib/google.php';

if (Auth::autenticado()) {
    redirigir('cuenta/pedidos.php');
}

if (!Google::configurado()) {
    flash('error', 'El acceso con Google no está configurado en este sitio.');
    redirigir('cuenta/entrar.php');
}

redirigir_externo(Google::urlAutorizacion(texto('volver', 120, $_GET)));
