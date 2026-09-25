<?php
/**
 * Retorno de Google. Esta es la URI que hay que autorizar en la consola:
 *   {APP_URL}/cuenta/google-callback.php
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/lib/cuentas_externas.php';
require_once __DIR__ . '/../includes/lib/google.php';

if (!Google::configurado()) {
    flash('error', 'El acceso con Google no está configurado.');
    redirigir('cuenta/entrar.php');
}

// El usuario canceló en la pantalla de Google (error=access_denied) u
// otro error del lado de Google.
$errorGoogle = texto('error', 60, $_GET);
if ($errorGoogle !== '') {
    unset($_SESSION['google_state'], $_SESSION['google_nonce'], $_SESSION['vincular']);
    if ($errorGoogle === 'access_denied') {
        flash('info', 'Cancelaste el acceso con Google.');
    } else {
        flash('error', 'Google no pudo completar el acceso. Inténtalo de nuevo en unos minutos.');
    }
    redirigir(Auth::autenticado() ? 'cuenta/perfil.php' : 'cuenta/entrar.php');
}

$codigo = texto('code', 512, $_GET);
$state  = texto('state', 64, $_GET);

if ($codigo === '') {
    unset($_SESSION['google_state'], $_SESSION['google_nonce'], $_SESSION['vincular']);
    flash('error', 'Google no devolvió la información necesaria.');
    redirigir(Auth::autenticado() ? 'cuenta/perfil.php' : 'cuenta/entrar.php');
}

if (!limitar($pdo, 'google:' . ip_cliente(), 15, 900)) {
    flash('error', 'Demasiados intentos seguidos. Espera unos minutos.');
    redirigir('cuenta/entrar.php');
}

CuentasExternas::terminar($pdo, 'google', Google::perfilDesdeCodigo($codigo, $state));
