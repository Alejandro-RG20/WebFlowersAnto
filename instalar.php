<?php
/**
 * Instalación guiada.
 *
 * Aplica las migraciones pendientes y crea la primera cuenta de super
 * administrador. Se cierra sola: en cuanto existe una cuenta de personal,
 * esta página deja de dar acceso. Así no hay ninguna contraseña de fábrica
 * publicada en el repositorio ni en la documentación.
 */

declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/db/Migrador.php';

$migrador   = new Migrador($pdo, __DIR__ . '/db/migraciones', Entorno::texto('DB_NAME', 'flowers_anto'));
$pendientes = $migrador->pendientes();

/** ¿Ya hay alguien del personal? Si lo hay, la instalación está hecha. */
function hayPersonal(PDO $pdo): bool
{
    try {
        return (int)$pdo->query(
            "SELECT COUNT(*) FROM usuarios u JOIN roles r ON r.id = u.rol_id
              WHERE r.es_personal = 1 AND u.activo = 1 AND u.password_hash IS NOT NULL"
        )->fetchColumn() > 0;
    } catch (PDOException) {
        return false; // la base todavía no tiene las tablas
    }
}

$instalado = hayPersonal($pdo);
$errores   = [];
$aviso     = '';
$datos     = ['nombre' => '', 'apellido' => '', 'email' => '', 'telefono' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$instalado) {
    exigirToken(false, 'instalar.php');
    $paso = opcion('paso', ['migrar', 'admin'], 'migrar');

    if ($paso === 'migrar') {
        $resultado = $migrador->ejecutar();
        if ($resultado['errores']) {
            $errores[] = 'La migración se detuvo: ' . implode(' · ', $resultado['errores']);
        } else {
            $aviso = 'Base de datos lista (' . count($resultado['aplicadas']) . ' migraciones aplicadas).';
        }
        $pendientes = $migrador->pendientes();
    } else {
        $datos['nombre']   = texto('nombre', 60);
        $datos['apellido'] = texto('apellido', 60);
        $datos['email']    = correoValido('email');
        $datos['telefono'] = telefonoValido('telefono');
        $password  = crudo('password');

        if ($pendientes) {
            $errores[] = 'Aplica primero las migraciones pendientes.';
        }
        if (mb_strlen($datos['nombre']) < 2)   { $errores['nombre']   = 'Escribe el nombre.'; }
        if (mb_strlen($datos['apellido']) < 2) { $errores['apellido'] = 'Escribe el apellido.'; }
        if ($datos['email'] === '')            { $errores['email']    = 'Escribe un correo válido.'; }
        if ($datos['telefono'] === '')         { $errores['telefono'] = 'Escribe un teléfono válido.'; }

        $problema = revisarPassword($password, crudo('password_confirmar'));
        if ($problema !== '') {
            $errores['password'] = $problema;
        }

        if (!$errores) {
            $rolId = Auth::rolId($pdo, 'super_admin');
            $usuarioExistente = $pdo->prepare("SELECT id FROM usuarios WHERE email = ?");
            $usuarioExistente->execute([$datos['email']]);
            $id = $usuarioExistente->fetchColumn();

            if ($id) {
                $pdo->prepare(
                    "UPDATE usuarios SET nombre = ?, apellido = ?, telefono = ?, password_hash = ?,
                                         rol_id = ?, activo = 1, nombre_completo = ?
                      WHERE id = ?"
                )->execute([
                    $datos['nombre'], $datos['apellido'], $datos['telefono'],
                    password_hash($password, PASSWORD_DEFAULT), $rolId,
                    trim($datos['nombre'] . ' ' . $datos['apellido']), $id,
                ]);
            } else {
                $pdo->prepare(
                    "INSERT INTO usuarios (email, nombre, apellido, telefono, password_hash, rol_id,
                                           activo, nombre_completo, username, email_verificado_en)
                     VALUES (?,?,?,?,?,?,1,?,?,NOW())"
                )->execute([
                    $datos['email'], $datos['nombre'], $datos['apellido'], $datos['telefono'],
                    password_hash($password, PASSWORD_DEFAULT), $rolId,
                    trim($datos['nombre'] . ' ' . $datos['apellido']),
                    mb_substr(slugificar($datos['nombre'] . '-' . $datos['apellido']), 0, 50),
                ]);
                $id = (int)$pdo->lastInsertId();
            }

            Auditoria::registrar($pdo, 'instalacion', 'sistema', [
                'recurso_tipo' => 'usuario', 'recurso_id' => (string)$id,
                'descripcion'  => 'Cuenta de super administrador creada durante la instalación.',
            ]);

            flash('exito', 'Instalación terminada. Ya puedes entrar al panel.');
            redirigir('cuenta/entrar.php');
        }
    }

    $instalado = hayPersonal($pdo);
}

$tituloPagina = 'Instalación — ' . Ajustes::texto('nombre_tienda', 'Flowers Anto');
$cuerpoClase  = 'pagina-simple';

require __DIR__ . '/includes/vistas/cabecera.php';
?>

<div class="container">
  <div class="marco-auth" style="max-width:560px;">
    <h1>Instalación</h1>
    <p class="subtitulo">Dos pasos: preparar la base de datos y crear tu cuenta de administración.</p>

    <?php if ($instalado): ?>
      <div class="tarjeta">
        <div class="caja-aviso exito">
          <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
          <span>El sitio ya está instalado. Esta página no vuelve a pedir datos: para crear más
                cuentas usa <strong>Panel → Empleados</strong>.</span>
        </div>
        <?php if ($pendientes): ?>
          <div class="caja-aviso alerta">
            <i class="fa-solid fa-database" aria-hidden="true"></i>
            <span>Hay <?= count($pendientes) ?> migraciones pendientes. Aplícalas desde
                  <strong>Panel → Base de datos</strong> o con <code>php db/migrar.php</code>.</span>
          </div>
        <?php endif; ?>
        <a class="btn btn-primary btn-block" href="<?= e(url('cuenta/entrar.php')) ?>">Entrar al panel</a>
      </div>

    <?php else: ?>
      <?php foreach ($errores as $clave => $mensaje): if (!is_int($clave)) continue; ?>
        <div class="caja-aviso error"><i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
          <span><?= e((string)$mensaje) ?></span></div>
      <?php endforeach; ?>
      <?php if ($aviso !== ''): ?>
        <div class="caja-aviso exito"><i class="fa-solid fa-circle-check" aria-hidden="true"></i>
          <span><?= e($aviso) ?></span></div>
      <?php endif; ?>

      <!-- Paso 1 -->
      <div class="tarjeta">
        <div class="tarjeta-encabezado">
          <h2>1. Base de datos</h2>
          <p>Conectado a <strong><?= e(Entorno::texto('DB_NAME', 'flowers_anto')) ?></strong>
             en <?= e(Entorno::texto('DB_HOST', 'localhost')) ?>.</p>
        </div>

        <?php if ($pendientes): ?>
          <div class="caja-aviso alerta">
            <i class="fa-solid fa-database" aria-hidden="true"></i>
            <span><?= count($pendientes) ?> migraciones por aplicar:
              <?= e(implode(', ', $pendientes)) ?></span>
          </div>
          <form method="post" action="<?= e(url('instalar.php')) ?>" data-una-vez>
            <?= campoToken() ?>
            <input type="hidden" name="paso" value="migrar">
            <button type="submit" class="btn btn-primary btn-block">Aplicar migraciones</button>
          </form>
        <?php else: ?>
          <div class="caja-aviso exito">
            <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
            <span>Todas las tablas están al día.</span>
          </div>
        <?php endif; ?>
      </div>

      <!-- Paso 2 -->
      <div class="tarjeta">
        <div class="tarjeta-encabezado">
          <h2>2. Cuenta de administración</h2>
          <p>Esta cuenta tendrá acceso total, incluidos los respaldos.</p>
        </div>

        <form method="post" action="<?= e(url('instalar.php')) ?>" novalidate data-una-vez>
          <?= campoToken() ?>
          <input type="hidden" name="paso" value="admin">

          <div class="campo-fila">
            <div class="campo<?= isset($errores['nombre']) ? ' con-error' : '' ?>">
              <label for="nombre">Nombre</label>
              <input type="text" id="nombre" name="nombre" required value="<?= e($datos['nombre']) ?>">
              <?php if (isset($errores['nombre'])): ?><p class="error-campo"><?= e($errores['nombre']) ?></p><?php endif; ?>
            </div>
            <div class="campo<?= isset($errores['apellido']) ? ' con-error' : '' ?>">
              <label for="apellido">Apellido</label>
              <input type="text" id="apellido" name="apellido" required value="<?= e($datos['apellido']) ?>">
              <?php if (isset($errores['apellido'])): ?><p class="error-campo"><?= e($errores['apellido']) ?></p><?php endif; ?>
            </div>
          </div>

          <div class="campo<?= isset($errores['email']) ? ' con-error' : '' ?>">
            <label for="email">Correo electrónico</label>
            <input type="email" id="email" name="email" required value="<?= e($datos['email']) ?>">
            <?php if (isset($errores['email'])): ?><p class="error-campo"><?= e($errores['email']) ?></p><?php endif; ?>
          </div>

          <div class="campo<?= isset($errores['telefono']) ? ' con-error' : '' ?>">
            <label for="telefono">Teléfono</label>
            <input type="tel" id="telefono" name="telefono" required value="<?= e($datos['telefono']) ?>">
            <?php if (isset($errores['telefono'])): ?><p class="error-campo"><?= e($errores['telefono']) ?></p><?php endif; ?>
          </div>

          <div class="campo<?= isset($errores['password']) ? ' con-error' : '' ?>">
            <label for="password">Contraseña</label>
            <input type="password" id="password" name="password" required autocomplete="new-password" minlength="8">
            <div class="medidor-password" id="medidorPassword" aria-hidden="true"><span></span></div>
            <?php if (isset($errores['password'])): ?><p class="error-campo"><?= e($errores['password']) ?></p><?php endif; ?>
          </div>

          <div class="campo">
            <label for="password_confirmar">Repite la contraseña</label>
            <input type="password" id="password_confirmar" name="password_confirmar" required minlength="8">
          </div>

          <button type="submit" class="btn btn-primary btn-block"<?= $pendientes ? ' disabled' : '' ?>>
            Crear cuenta y terminar
          </button>
          <?php if ($pendientes): ?>
            <p class="ayuda" style="text-align:center; margin-top:10px;">Aplica primero las migraciones.</p>
          <?php endif; ?>
        </form>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/includes/vistas/pie.php'; ?>
