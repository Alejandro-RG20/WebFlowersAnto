<?php
/**
 * Retorno de Google. Esta es la URI que hay que autorizar en la consola:
 *   {APP_URL}/cuenta/google-callback.php
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/lib/google.php';

if (!Google::configurado()) {
    flash('error', 'El acceso con Google no está configurado.');
    redirigir('cuenta/entrar.php');
}

// El usuario canceló en la pantalla de Google.
if (texto('error', 60, $_GET) !== '') {
    flash('info', 'Cancelaste el acceso con Google.');
    redirigir('cuenta/entrar.php');
}

$codigo = texto('code', 512, $_GET);
$state  = texto('state', 64, $_GET);

if ($codigo === '') {
    flash('error', 'Google no devolvió la información necesaria.');
    redirigir('cuenta/entrar.php');
}

if (!limitar($pdo, 'google:' . ip_cliente(), 15, 900)) {
    flash('error', 'Demasiados intentos seguidos. Espera unos minutos.');
    redirigir('cuenta/entrar.php');
}

$resultado = Google::perfilDesdeCodigo($codigo, $state);
if (!$resultado['ok']) {
    Auditoria::registrar($pdo, 'inicio_sesion_google', 'usuarios', [
        'resultado' => 'fallo', 'descripcion' => $resultado['error'],
    ]);
    flash('error', $resultado['error']);
    redirigir('cuenta/entrar.php');
}

$usuario = Google::vincularUsuario($pdo, $resultado['perfil']);
if (!$usuario) {
    flash('error', 'No pudimos abrir tu cuenta. Escríbenos y lo revisamos.');
    redirigir('cuenta/entrar.php');
}

Auth::abrirSesion($usuario);
Favoritos::fusionarAlEntrar($pdo, (int)$usuario['id']);
Carrito::fusionarAlEntrar($pdo, (int)$usuario['id']);

Auditoria::registrar($pdo, 'inicio_sesion_google', 'usuarios', [
    'recurso_tipo' => 'usuario', 'recurso_id' => (string)$usuario['id'],
    'descripcion'  => 'Inicio de sesión con Google.',
]);

$destino = $_SESSION['volver_a'] ?? '';
unset($_SESSION['volver_a']);

flash('exito', '¡Hola, ' . Auth::nombreCompleto() . '!');
redirigir($destino !== '' ? $destino : (Auth::esPersonal() ? 'admin/' : 'cuenta/pedidos.php'));
