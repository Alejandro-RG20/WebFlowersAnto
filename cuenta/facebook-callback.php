<?php
/**
 * Retorno de Facebook. Esta es la URI que hay que autorizar en Meta
 * (Inicio de sesión con Facebook → Configuración → URI de redireccionamiento
 * de OAuth válidos):
 *   {APP_URL}/cuenta/facebook-callback.php
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/lib/cuentas_externas.php';
require_once __DIR__ . '/../includes/lib/facebook.php';

if (!Facebook::disponible($pdo)) {
    flash('error', 'El acceso con Facebook no está configurado.');
    redirigir('cuenta/entrar.php');
}

// La persona canceló o no dio permiso (error=access_denied,
// error_reason=user_denied) u otro error del lado de Facebook.
$errorFacebook = texto('error', 60, $_GET);
$motivo        = texto('error_reason', 60, $_GET);
if ($errorFacebook !== '' || $motivo !== '') {
    CuentasExternas::cerrarPeticion('facebook', texto('state', 64, $_GET));
    unset($_SESSION['vincular']);
    if ($errorFacebook === 'access_denied' || $motivo === 'user_denied') {
        flash('info', 'Cancelaste el acceso con Facebook.');
    } else {
        flash('error', 'Facebook no pudo completar el acceso. Inténtalo de nuevo en unos minutos.');
    }
    redirigir(Auth::autenticado() ? 'cuenta/perfil.php' : 'cuenta/entrar.php');
}

$codigo = texto('code', 1024, $_GET);
$state  = texto('state', 64, $_GET);

if ($codigo === '') {
    CuentasExternas::cerrarPeticion('facebook', texto('state', 64, $_GET));
    unset($_SESSION['vincular']);
    flash('error', 'Facebook no devolvió la información necesaria.');
    redirigir(Auth::autenticado() ? 'cuenta/perfil.php' : 'cuenta/entrar.php');
}

if (!limitar($pdo, 'facebook:' . ip_cliente(), 15, 900)) {
    flash('error', 'Demasiados intentos seguidos. Espera unos minutos.');
    redirigir('cuenta/entrar.php');
}

CuentasExternas::terminar($pdo, 'facebook', Facebook::perfilDesdeCodigo($codigo, $state));
