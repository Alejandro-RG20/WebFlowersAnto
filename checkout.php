<?php
/**
 * Checkout.
 *
 * Un solo formulario con las secciones del flujo (identificación, entrega,
 * pago y resumen). Se hizo en una página en vez de en cuatro pasos porque en
 * móvil cada salto es una oportunidad de abandonar el pedido, y el formulario
 * completo cabe con desplazamiento.
 *
 * No obliga a crear cuenta: se puede comprar como invitado, iniciar sesión o
 * registrarse durante el proceso.
 */

declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

if (!Ajustes::activo('pedido_web_activo', true)) {
    flash('info', 'Los pedidos por la web están pausados. Escríbenos por WhatsApp y te atendemos.');
    redirigir('carrito.php');
}

$detalle = Carrito::detalle($pdo);
if (!$detalle['items']) {
    flash('info', 'Tu carrito está vacío.');
    redirigir('carrito.php');
}

$usuario   = Auth::usuario();
$cuentas   = Ajustes::cuentasBancarias($pdo);
$ciudades  = Ajustes::lista('ciudades_entrega', ['Managua']);
$franjas   = Ajustes::lista('franjas_entrega', ['Mañana (8:00 - 12:00)', 'Tarde (12:00 - 17:00)']);
$permitirRetiro   = Ajustes::activo('permitir_retiro', true);
$permitirInvitado = Ajustes::activo('permitir_invitado', true);
$efectivoActivo   = Ajustes::activo('pago_efectivo_activo', true);

$errores = [];
$datos = [
    'cliente_nombre'     => $usuario ? Auth::nombreCompleto() : '',
    'cliente_email'      => (string)($usuario['email'] ?? ''),
    'cliente_telefono'   => (string)($usuario['telefono'] ?? ''),
    'entrega_tipo'       => 'domicilio',
    'entrega_nombre'     => '',
    'entrega_telefono'   => '',
    'entrega_direccion'  => '',
    'entrega_ciudad'     => $ciudades[0] ?? 'Managua',
    'entrega_referencia' => '',
    'entrega_fecha'      => '',
    'entrega_franja'     => $franjas[0] ?? '',
    'dedicatoria'        => '',
    'notas_cliente'      => '',
    'metodo_pago'        => 'transferencia',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirToken(false, 'checkout.php');

    if (!limitar($pdo, 'pedido:' . ip_cliente(), 10, 900)) {
        $errores[] = 'Recibimos varios intentos seguidos desde esta conexión. Espera unos minutos e inténtalo de nuevo.';
    }

    $datos['cliente_nombre']     = texto('cliente_nombre', 120);
    $datos['cliente_email']      = correoValido('cliente_email');
    $datos['cliente_telefono']   = telefonoValido('cliente_telefono');
    $datos['entrega_tipo']       = $permitirRetiro ? opcion('entrega_tipo', ['domicilio', 'retiro'], 'domicilio') : 'domicilio';
    $datos['entrega_nombre']     = texto('entrega_nombre', 120);
    $datos['entrega_telefono']   = telefonoValido('entrega_telefono');
    $datos['entrega_direccion']  = texto('entrega_direccion', 255);
    $datos['entrega_ciudad']     = texto('entrega_ciudad', 80);
    $datos['entrega_referencia'] = texto('entrega_referencia', 255);
    $datos['entrega_fecha']      = fechaOpcional('entrega_fecha');
    $datos['entrega_franja']     = texto('entrega_franja', 40);
    $datos['dedicatoria']        = textoLargo('dedicatoria', 400);
    $datos['notas_cliente']      = textoLargo('notas_cliente', 500);

    $metodosValidos = ['transferencia'];
    if ($efectivoActivo) {
        $metodosValidos[] = 'efectivo';
    }
    $datos['metodo_pago'] = opcion('metodo_pago', $metodosValidos, 'transferencia');
    $datos['canal']       = 'web';

    // --- Validación ---------------------------------------------------
    if (mb_strlen($datos['cliente_nombre']) < 3) {
        $errores['cliente_nombre'] = 'Escribe tu nombre completo.';
    }
    if ($datos['cliente_email'] === '') {
        $errores['cliente_email'] = 'Necesitamos un correo válido para enviarte la confirmación y el seguimiento.';
    }
    if ($datos['cliente_telefono'] === '') {
        $errores['cliente_telefono'] = 'Escribe un teléfono de contacto de 8 dígitos o más.';
    }
    if ($datos['entrega_tipo'] === 'domicilio') {
        if (mb_strlen($datos['entrega_direccion']) < 10) {
            $errores['entrega_direccion'] = 'Necesitamos una dirección con la referencia suficiente para llegar.';
        }
        if ($datos['entrega_ciudad'] === '') {
            $errores['entrega_ciudad'] = 'Indica la ciudad de entrega.';
        }
    }
    if ($datos['entrega_fecha'] !== null && $datos['entrega_fecha'] < date('Y-m-d')) {
        $errores['entrega_fecha'] = 'La fecha de entrega no puede ser anterior a hoy.';
    }

    // Quien recibe: si no se indica, es la misma persona que pide.
    if ($datos['entrega_nombre'] === '') {
        $datos['entrega_nombre'] = $datos['cliente_nombre'];
    }
    if ($datos['entrega_telefono'] === '') {
        $datos['entrega_telefono'] = $datos['cliente_telefono'];
    }

    // --- Crear cuenta durante el pedido, si se pidió ---------------------
    $quiereCuenta = !Auth::autenticado() && casilla('crear_cuenta');
    if ($quiereCuenta && !$errores) {
        $password = crudo('password');
        $problema = revisarPassword($password, crudo('password_confirmar'));
        if ($problema !== '') {
            $errores['password'] = $problema;
        } else {
            $yaExiste = $pdo->prepare("SELECT id FROM usuarios WHERE email = ?");
            $yaExiste->execute([$datos['cliente_email']]);
            if ($yaExiste->fetchColumn()) {
                $errores['cliente_email'] = 'Ya hay una cuenta con ese correo. Inicia sesión para asociar el pedido.';
            } else {
                $partes   = preg_split('/\s+/u', $datos['cliente_nombre']) ?: [];
                $nombre   = array_shift($partes) ?: $datos['cliente_nombre'];
                $apellido = implode(' ', $partes);

                $pdo->prepare(
                    "INSERT INTO usuarios (email, nombre, apellido, telefono, password_hash, rol_id,
                                           activo, nombre_completo, email_verificado_en)
                     VALUES (?,?,?,?,?,?,1,?,NULL)"
                )->execute([
                    $datos['cliente_email'], mb_substr($nombre, 0, 60), mb_substr($apellido, 0, 60),
                    $datos['cliente_telefono'], password_hash($password, PASSWORD_DEFAULT),
                    Auth::rolId($pdo, 'cliente'), $datos['cliente_nombre'],
                ]);
                $nuevoId = (int)$pdo->lastInsertId();

                $st = $pdo->prepare("SELECT * FROM usuarios WHERE id = ?");
                $st->execute([$nuevoId]);
                $nuevo = $st->fetch();

                Auth::abrirSesion($nuevo);
                Favoritos::fusionarAlEntrar($pdo, $nuevoId);
                Auditoria::registrar($pdo, 'registro', 'usuarios', [
                    'recurso_tipo' => 'usuario', 'recurso_id' => (string)$nuevoId,
                    'descripcion'  => 'Cuenta creada durante el proceso de pedido.',
                ]);
            }
        }
    }

    // --- Crear el pedido -------------------------------------------------
    if (!$errores) {
        $resultado = Pedidos::crearDesdeCarrito($pdo, $datos);
        if ($resultado['ok']) {
            $pedido = $resultado['pedido'];
            flash('exito', 'Recibimos tu pedido ' . $pedido['codigo'] . '.');
            redirigir(Pedidos::enlaceSeguimiento($pedido));
        }
        $errores[] = $resultado['error'];
    }
}

$tienda            = Ajustes::texto('nombre_tienda', 'Flowers Anto');
$tituloPagina      = 'Completar pedido — ' . $tienda;
$descripcionPagina = 'Completa tus datos de entrega y elige cómo pagar.';
$paginaActiva      = 'carrito';

require __DIR__ . '/includes/vistas/cabecera.php';
?>

<div class="container">
  <nav class="migas" aria-label="Ruta">
    <ol>
      <li><a href="<?= e(url()) ?>">Inicio</a></li>
      <li><a href="<?= e(url('carrito.php')) ?>">Carrito</a></li>
      <li aria-current="page">Pedido</li>
    </ol>
  </nav>

  <header class="pagina-cabecera">
    <h1>Completar pedido</h1>
    <p>Faltan tus datos de entrega y elegir cómo pagas. No tarda ni dos minutos.</p>
  </header>

  <ol class="pasos">
    <li class="paso hecho"><strong>1. Carrito</strong> <?= (int)$detalle['unidades'] ?> artículos</li>
    <li class="paso activo"><strong>2. Datos y entrega</strong> Ahora</li>
    <li class="paso"><strong>3. Pago</strong> Transferencia</li>
    <li class="paso"><strong>4. Comprobante</strong> Después de pagar</li>
  </ol>

  <?php foreach ($errores as $clave => $mensaje): if (!is_int($clave)) continue; ?>
    <div class="caja-aviso error">
      <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i><span><?= e((string)$mensaje) ?></span>
    </div>
  <?php endforeach; ?>
  <?php if ($errores && !array_filter(array_keys($errores), 'is_int')): ?>
    <div class="caja-aviso error">
      <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
      <span>Revisa los campos marcados abajo para poder continuar.</span>
    </div>
  <?php endif; ?>

  <form method="post" action="<?= e(url('checkout.php')) ?>" novalidate data-una-vez>
    <?= campoToken() ?>
    <div class="diseno-compra">
      <div>
        <!-- 1. Identificación -->
        <div class="tarjeta">
          <div class="tarjeta-encabezado">
            <h2>Tus datos</h2>
            <?php if (Auth::autenticado()): ?>
              <p>Estás como <strong><?= e(Auth::nombreCompleto()) ?></strong>. El pedido quedará en tu historial.</p>
            <?php else: ?>
              <p>¿Ya tienes cuenta? <a href="<?= e(url('cuenta/entrar.php?volver=checkout.php')) ?>">Inicia sesión</a>
                 y rellenamos esto por ti.</p>
            <?php endif; ?>
          </div>

          <div class="campo<?= isset($errores['cliente_nombre']) ? ' con-error' : '' ?>">
            <label for="cliente_nombre">Nombre completo *</label>
            <input type="text" id="cliente_nombre" name="cliente_nombre" required autocomplete="name"
                   value="<?= e($datos['cliente_nombre']) ?>"
                   <?= isset($errores['cliente_nombre']) ? 'aria-invalid="true"' : '' ?>>
            <?php if (isset($errores['cliente_nombre'])): ?>
              <p class="error-campo"><?= e($errores['cliente_nombre']) ?></p>
            <?php endif; ?>
          </div>

          <div class="campo-fila">
            <div class="campo<?= isset($errores['cliente_email']) ? ' con-error' : '' ?>">
              <label for="cliente_email">Correo electrónico *</label>
              <input type="email" id="cliente_email" name="cliente_email" required autocomplete="email"
                     value="<?= e($datos['cliente_email']) ?>"
                     <?= isset($errores['cliente_email']) ? 'aria-invalid="true"' : '' ?>>
              <p class="ayuda">Ahí te enviamos la confirmación y el enlace para seguir el pedido.</p>
              <?php if (isset($errores['cliente_email'])): ?>
                <p class="error-campo"><?= e($errores['cliente_email']) ?></p>
              <?php endif; ?>
            </div>
            <div class="campo<?= isset($errores['cliente_telefono']) ? ' con-error' : '' ?>">
              <label for="cliente_telefono">Teléfono / WhatsApp *</label>
              <input type="tel" id="cliente_telefono" name="cliente_telefono" required autocomplete="tel"
                     value="<?= e($datos['cliente_telefono']) ?>" placeholder="+505 8888 8888">
              <?php if (isset($errores['cliente_telefono'])): ?>
                <p class="error-campo"><?= e($errores['cliente_telefono']) ?></p>
              <?php endif; ?>
            </div>
          </div>

          <?php if (!Auth::autenticado() && $permitirInvitado): ?>
            <div class="campo-casilla" style="margin-top:6px;">
              <input type="checkbox" id="crear_cuenta" name="crear_cuenta" value="1"
                     <?= casilla('crear_cuenta') ? 'checked' : '' ?>
                     onchange="document.getElementById('bloqueCuenta').hidden = !this.checked">
              <label for="crear_cuenta">Crear una cuenta para seguir mis pedidos (opcional)</label>
            </div>

            <div id="bloqueCuenta" <?= casilla('crear_cuenta') ? '' : 'hidden' ?>>
              <div class="campo-fila">
                <div class="campo<?= isset($errores['password']) ? ' con-error' : '' ?>">
                  <label for="password">Contraseña</label>
                  <input type="password" id="password" name="password" autocomplete="new-password" minlength="8">
                  <div class="medidor-password" id="medidorPassword" aria-hidden="true"><span></span></div>
                  <p class="ayuda">Mínimo 8 caracteres, con letras y números.</p>
                </div>
                <div class="campo">
                  <label for="password_confirmar">Repite la contraseña</label>
                  <input type="password" id="password_confirmar" name="password_confirmar" autocomplete="new-password" minlength="8">
                </div>
              </div>
              <?php if (isset($errores['password'])): ?>
                <p class="error-campo" style="margin-top:-8px;"><?= e($errores['password']) ?></p>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        </div>

        <!-- 2. Entrega -->
        <div class="tarjeta">
          <div class="tarjeta-encabezado">
            <h2>Entrega</h2>
            <p>¿A dónde llevamos el arreglo?</p>
          </div>

          <?php if ($permitirRetiro): ?>
            <div class="opciones-radio" style="margin-bottom:18px;">
              <label class="opcion-radio">
                <input type="radio" name="entrega_tipo" value="domicilio"
                       <?= $datos['entrega_tipo'] === 'domicilio' ? 'checked' : '' ?>
                       onchange="document.getElementById('bloqueDomicilio').hidden = false">
                <span><strong>Entrega a domicilio</strong>
                  <small>Lo llevamos a la dirección que nos indiques.</small></span>
              </label>
              <label class="opcion-radio">
                <input type="radio" name="entrega_tipo" value="retiro"
                       <?= $datos['entrega_tipo'] === 'retiro' ? 'checked' : '' ?>
                       onchange="document.getElementById('bloqueDomicilio').hidden = true">
                <span><strong>Retiro en la tienda</strong>
                  <small><?= e(Ajustes::texto('direccion', 'Coordinamos el punto por WhatsApp.')) ?></small></span>
              </label>
            </div>
          <?php else: ?>
            <input type="hidden" name="entrega_tipo" value="domicilio">
          <?php endif; ?>

          <div id="bloqueDomicilio" <?= $datos['entrega_tipo'] === 'retiro' ? 'hidden' : '' ?>>
            <div class="campo<?= isset($errores['entrega_direccion']) ? ' con-error' : '' ?>">
              <label for="entrega_direccion">Dirección *</label>
              <input type="text" id="entrega_direccion" name="entrega_direccion" autocomplete="street-address"
                     value="<?= e($datos['entrega_direccion']) ?>"
                     placeholder="Barrio, calle, número de casa…">
              <?php if (isset($errores['entrega_direccion'])): ?>
                <p class="error-campo"><?= e($errores['entrega_direccion']) ?></p>
              <?php endif; ?>
            </div>

            <div class="campo-fila">
              <div class="campo<?= isset($errores['entrega_ciudad']) ? ' con-error' : '' ?>">
                <label for="entrega_ciudad">Ciudad *</label>
                <select id="entrega_ciudad" name="entrega_ciudad">
                  <?php foreach ($ciudades as $ciudad): ?>
                    <option value="<?= e($ciudad) ?>"<?= $datos['entrega_ciudad'] === $ciudad ? ' selected' : '' ?>>
                      <?= e($ciudad) ?></option>
                  <?php endforeach; ?>
                </select>
                <?php if (isset($errores['entrega_ciudad'])): ?>
                  <p class="error-campo"><?= e($errores['entrega_ciudad']) ?></p>
                <?php endif; ?>
              </div>
              <div class="campo">
                <label for="entrega_referencia">Punto de referencia</label>
                <input type="text" id="entrega_referencia" name="entrega_referencia"
                       value="<?= e($datos['entrega_referencia']) ?>" placeholder="Portón negro, frente a…">
              </div>
            </div>
          </div>

          <div class="campo-fila">
            <div class="campo">
              <label for="entrega_nombre">¿Quién recibe?</label>
              <input type="text" id="entrega_nombre" name="entrega_nombre"
                     value="<?= e($datos['entrega_nombre']) ?>" placeholder="Déjalo vacío si lo recibes tú">
            </div>
            <div class="campo">
              <label for="entrega_telefono">Teléfono de quien recibe</label>
              <input type="tel" id="entrega_telefono" name="entrega_telefono"
                     value="<?= e($datos['entrega_telefono']) ?>" placeholder="Opcional">
            </div>
          </div>

          <div class="campo-fila">
            <div class="campo<?= isset($errores['entrega_fecha']) ? ' con-error' : '' ?>">
              <label for="entrega_fecha">Fecha deseada</label>
              <input type="date" id="entrega_fecha" name="entrega_fecha" min="<?= date('Y-m-d') ?>"
                     value="<?= e((string)$datos['entrega_fecha']) ?>">
              <?php if (isset($errores['entrega_fecha'])): ?>
                <p class="error-campo"><?= e($errores['entrega_fecha']) ?></p>
              <?php endif; ?>
            </div>
            <div class="campo">
              <label for="entrega_franja">Franja horaria</label>
              <select id="entrega_franja" name="entrega_franja">
                <option value="">Sin preferencia</option>
                <?php foreach ($franjas as $franja): ?>
                  <option value="<?= e($franja) ?>"<?= $datos['entrega_franja'] === $franja ? ' selected' : '' ?>>
                    <?= e($franja) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="campo">
            <label for="dedicatoria">Dedicatoria para la tarjeta</label>
            <textarea id="dedicatoria" name="dedicatoria" maxlength="400"
                      placeholder="La escribimos a mano y la incluimos sin costo."><?= e($datos['dedicatoria']) ?></textarea>
          </div>

          <div class="campo">
            <label for="notas_cliente">Notas para el equipo</label>
            <textarea id="notas_cliente" name="notas_cliente" maxlength="500"
                      placeholder="Colores que prefieres, alergias, horario en que hay alguien en casa…"><?= e($datos['notas_cliente']) ?></textarea>
          </div>
        </div>

        <!-- 3. Pago -->
        <div class="tarjeta">
          <div class="tarjeta-encabezado">
            <h2>Cómo vas a pagar</h2>
          </div>

          <div class="opciones-radio">
            <label class="opcion-radio">
              <input type="radio" name="metodo_pago" value="transferencia"
                     <?= $datos['metodo_pago'] === 'transferencia' ? 'checked' : '' ?>>
              <span><strong>Transferencia bancaria</strong>
                <small>Te mostramos los datos de la cuenta al confirmar. Subes el comprobante
                       y lo verificamos antes de preparar el arreglo.</small></span>
            </label>

            <?php if ($efectivoActivo): ?>
              <label class="opcion-radio">
                <input type="radio" name="metodo_pago" value="efectivo"
                       <?= $datos['metodo_pago'] === 'efectivo' ? 'checked' : '' ?>>
                <span><strong>Efectivo contra entrega</strong>
                  <small>Solo dentro de Managua. Confirmamos disponibilidad por WhatsApp antes de salir.</small></span>
              </label>
            <?php endif; ?>
          </div>

          <?php if ($cuentas): ?>
            <div style="margin-top:18px;">
              <p class="etiqueta-campo">Cuentas para transferencia</p>
              <?php foreach ($cuentas as $cuenta): ?>
                <div class="cuenta-banco">
                  <h3><?= e((string)$cuenta['banco']) ?> — <?= e((string)$cuenta['moneda']) ?></h3>
                  <dl>
                    <div><dt>Titular</dt><dd><?= e((string)$cuenta['titular']) ?></dd></div>
                    <div><dt>Cuenta</dt><dd><?= e((string)$cuenta['numero_cuenta']) ?></dd></div>
                    <div><dt>Tipo</dt><dd><?= e((string)$cuenta['tipo_cuenta']) ?></dd></div>
                  </dl>
                </div>
              <?php endforeach; ?>
              <p style="font-size:.86rem; color:var(--suave);">
                Los datos completos y el botón para copiarlos aparecen en la página del pedido,
                junto con el formulario para subir el comprobante.
              </p>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Resumen -->
      <div class="columna-resumen">
        <div class="tarjeta">
          <div class="tarjeta-encabezado"><h2>Tu pedido</h2></div>

          <?php foreach ($detalle['items'] as $i): ?>
            <div class="mini-linea">
              <img src="<?= e(url_imagen((string)$i['imagen'])) ?>" alt="" loading="lazy">
              <div class="mini-linea-datos">
                <strong><?= e((string)$i['nombre']) ?></strong>
                <small><?= (int)$i['cantidad'] ?> × <?= e(dinero($i['precio'])) ?></small>
              </div>
              <span class="mini-linea-precio"><?= e(dinero($i['subtotal'])) ?></span>
            </div>
          <?php endforeach; ?>

          <div class="resumen-totales" style="margin-top:16px;">
            <div><span>Subtotal</span><span><?= e(dinero($detalle['subtotal'])) ?></span></div>
            <div><span>Envío</span>
              <?= $detalle['envio'] > 0
                    ? '<span>' . e(dinero($detalle['envio'])) . '</span>'
                    : '<span class="gratis">Gratis</span>' ?>
            </div>
            <div class="total"><span>Total</span><span><?= e(dinero($detalle['total'])) ?></span></div>
          </div>

          <button type="submit" class="btn btn-primary btn-block" style="margin-top:20px;">
            <i class="fa-solid fa-check" aria-hidden="true"></i> Confirmar pedido
          </button>

          <p style="font-size:.83rem; color:var(--tenue); margin-top:12px; line-height:1.6;">
            Al confirmar registramos el pedido y te mostramos los datos bancarios.
            Nada se cobra automáticamente: el pago lo verifica una persona del equipo.
          </p>

          <p style="text-align:center; margin-top:14px;">
            <a href="<?= e(url('carrito.php')) ?>" style="font-size:.87rem; color:var(--suave);">
              <i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Volver al carrito</a>
          </p>
        </div>
      </div>
    </div>
  </form>
</div>

<div style="height:56px"></div>
<?php require __DIR__ . '/includes/vistas/pie.php'; ?>
