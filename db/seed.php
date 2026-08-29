<?php
/**
 * Datos de ejemplo.
 *
 *     php db/seed.php            catálogo de muestra (productos, galería, banco)
 *     php db/seed.php --pedidos  añade además pedidos de prueba en varios estados
 *     php db/seed.php --limpiar  borra SOLO lo marcado como demo
 *
 * Todo lo que crea este script queda marcado: los productos llevan el prefijo
 * «[DEMO] » en el resumen y los pedidos usan el código FA-DEMO-*. Así nunca se
 * confunden con datos reales del negocio ni se borran los del cliente.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Este script solo se ejecuta desde la consola.');
}

require_once __DIR__ . '/../includes/bootstrap.php';

const MARCA_DEMO = '[DEMO]';

if (in_array('--limpiar', $argv, true)) {
    $pdo->exec("DELETE FROM pedidos WHERE codigo LIKE 'FA-DEMO-%'");
    $pdo->exec("DELETE FROM productos WHERE resumen LIKE '" . MARCA_DEMO . "%'");
    $pdo->exec("DELETE FROM clientes_fotos WHERE titulo LIKE '" . MARCA_DEMO . "%'");
    $pdo->exec("DELETE FROM usuarios WHERE email LIKE '%@demo.flowersanto.test'");
    echo "Datos de demostración eliminados.\n";
    exit(0);
}

// ---------------------------------------------------------------------
// Catálogo
// ---------------------------------------------------------------------
$categorias = $pdo->query("SELECT nombre, id FROM categorias")->fetchAll(PDO::FETCH_KEY_PAIR);

$productos = [
    ['Ramo Gerbera',        'Gerberas de colores abiertos, follaje verde y envoltura de papel premium. Ideal para alegrar una mañana.',            1000, 30, 'images/guerbera.jpeg',                     'Ramos',            'gerberas',        '#F0D7DD', 1, 1],
    ['Arreglo Girasol',     'Girasoles, margaritas y lavanda montados sobre base de mimbre. El arreglo que más nos piden para cumpleaños.',       1500, 41, 'images/arregloJirasolFelizCumpleaños.jpeg', 'Arreglos',         'girasoles',       '#EFE0C4', 1, 2],
    ['Encanto Rosado',      'Orquídeas, lirios y velas aromáticas en composición vertical. Presencia y aroma en el mismo arreglo.',               1800, 50, 'images/placeholders/base-01.svg',          'Arreglos',         'orquideas,rosas', '#E6DFEC', 1, 3],
    ['Canasta Tropical',    'Flores preservadas y frescas en canasta de fibra natural. Dura semanas sin perder color.',                           1600, 44, 'images/placeholders/canasta-01.svg',       'Cajas',            'mixtas',          '#DCE8DC', 1, 4],
    ['Arreglo Bebé',        'Rosas y flores de estación en tonos pastel, para recibir a alguien nuevo.',                                          2500, 69, 'images/placeholders/arreglo-01.svg',       'Arreglos',         'rosas,mixtas',    '#F3E2D2', 0, 0],
    ['Ramo Girasol',        'Girasoles solos, sin adornos. Iluminan cualquier habitación.',                                                       1000, 28, 'images/placeholders/ramo-02.svg',          'Ramos',            'girasoles',       '#EFE0C4', 0, 0],
    ['Floral Mixto',        'Mezcla de flores frescas y preservadas presentada en caja rígida.',                                                  3800, 104,'images/placeholders/mixto-01.svg',         'Cajas',            'mixtas',          '#EDE3D4', 0, 0],
    ['Cumpleaños Tropical', 'Anturios, heliconias y follaje tropical en montaje festivo.',                                                        2600, 71, 'images/placeholders/arreglo-02.svg',       'Arreglos',         'mixtas',          '#FBE4DC', 0, 0],
    ['Ramo Romántico',      'El clásico: rosas rojas y blancas, atadas a mano con listón de seda.',                                               1500, 41, 'images/placeholders/ramo-01.svg',          'Ramos',            'rosas',           '#F0D7DD', 0, 0],
    ['Arreglo Tierno',      'Tonos suaves y flores de estación, en composición baja y abierta para centro de mesa.',                              2200, 60, 'images/ArregloTierno.jpeg',                'Arreglos de Base', 'mixtas,rosas',    '#DCE8DC', 0, 0],
];

$existe = $pdo->prepare("SELECT id FROM productos WHERE nombre = ?");
$insertar = $pdo->prepare(
    "INSERT INTO productos
        (nombre, slug, descripcion, resumen, precio, precio_usd, imagen, categoria_id,
         flores, color_acento, destacado, orden_hero, orden, disponible, activo, stock, controla_stock)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,1,1,25,0)"
);
$insertarImagen = $pdo->prepare("INSERT INTO producto_imagenes (producto_id, ruta, alt, orden) VALUES (?,?,?,?)");

$creados = 0;
foreach ($productos as $i => [$nombre, $desc, $precio, $usd, $imagen, $categoria, $flores, $color, $destacado, $ordenHero]) {
    $existe->execute([$nombre]);
    if ($existe->fetchColumn()) {
        continue;
    }
    $categoriaId = $categorias[$categoria] ?? array_values($categorias)[0];
    $insertar->execute([
        $nombre, slugificar($nombre), $desc, MARCA_DEMO . ' ' . recortar($desc, 140),
        $precio, $usd, $imagen, $categoriaId, $flores, $color, $destacado, $ordenHero, $i,
    ]);
    $insertarImagen->execute([(int)$pdo->lastInsertId(), $imagen, $nombre, 0]);
    $creados++;
}
echo "Productos creados: $creados\n";

// ---------------------------------------------------------------------
// Galería
// ---------------------------------------------------------------------
if ((int)$pdo->query("SELECT COUNT(*) FROM clientes_fotos")->fetchColumn() === 0) {
    $pdo->exec("INSERT INTO clientes_fotos (imagen, titulo, orden) VALUES
        ('images/placeholders/galeria-01.svg', '" . MARCA_DEMO . " Entrega en Managua', 1),
        ('images/placeholders/galeria-02.svg', '" . MARCA_DEMO . " Arreglo de aniversario', 2)");
    echo "Galería de ejemplo creada.\n";
}

// ---------------------------------------------------------------------
// Cuenta bancaria de ejemplo
// ---------------------------------------------------------------------
if ((int)$pdo->query("SELECT COUNT(*) FROM cuentas_bancarias")->fetchColumn() === 0) {
    $pdo->prepare(
        "INSERT INTO cuentas_bancarias (banco, titular, numero_cuenta, tipo_cuenta, moneda, identificacion, notas, orden)
         VALUES (?,?,?,?,?,?,?,1)"
    )->execute([
        'Banco de ejemplo', 'Nombre del titular', '0000-0000-0000-0000', 'Ahorro', 'Córdobas', '000-000000-0000X',
        'Cámbialo en Panel → Configuración → Transferencias antes de publicar el sitio.',
    ]);
    echo "Cuenta bancaria de ejemplo creada (hay que sustituirla por la real).\n";
}

// ---------------------------------------------------------------------
// Pedidos de prueba
// ---------------------------------------------------------------------
if (in_array('--pedidos', $argv, true)) {
    $rolCliente = Auth::rolId($pdo, 'cliente');

    $pdo->prepare(
        "INSERT IGNORE INTO usuarios (email, nombre, apellido, telefono, password_hash, rol_id, activo, nombre_completo)
         VALUES (?,?,?,?,?,?,1,?)"
    )->execute([
        'cliente@demo.flowersanto.test', 'Cliente', 'De Prueba', '+50588887777',
        password_hash('Demo1234', PASSWORD_DEFAULT), $rolCliente, 'Cliente De Prueba',
    ]);
    $clienteId = (int)$pdo->query("SELECT id FROM usuarios WHERE email = 'cliente@demo.flowersanto.test'")->fetchColumn();

    $delCatalogo = $pdo->query("SELECT id, nombre, imagen, precio FROM productos WHERE activo = 1 LIMIT 4")->fetchAll();
    if (!$delCatalogo) {
        echo "No hay productos: ejecuta el seed sin --pedidos primero.\n";
        exit(1);
    }

    $escenarios = [
        ['pendiente',     'pendiente_comprobante', 'Esperando que el cliente suba el comprobante'],
        ['pago_revision', 'comprobante_recibido',  'Comprobante recibido, pendiente de revisar'],
        ['confirmado',    'aprobado',              'Pago aprobado y pedido confirmado'],
        ['preparacion',   'aprobado',              'En el taller'],
        ['completado',    'aprobado',              'Entregado la semana pasada'],
    ];

    $insPedido = $pdo->prepare(
        "INSERT INTO pedidos
            (codigo, usuario_id, cliente_nombre, cliente_email, cliente_telefono,
             entrega_tipo, entrega_direccion, entrega_ciudad, entrega_fecha, entrega_franja,
             dedicatoria, canal, metodo_pago, estado, estado_pago, moneda, subtotal, envio, total,
             token_seguimiento, created_at)
         VALUES (?,?,?,?,?, 'domicilio',?,?,?,?, ?, 'web','transferencia',?,?,?,?,?,?,?,?)"
    );
    $insItem = $pdo->prepare(
        "INSERT INTO pedido_items (pedido_id, producto_id, nombre, imagen, precio_unitario, cantidad, subtotal)
         VALUES (?,?,?,?,?,?,?)"
    );

    $hechos = 0;
    foreach ($escenarios as $n => [$estado, $estadoPago, $nota]) {
        $codigo = 'FA-DEMO-' . str_pad((string)($n + 1), 3, '0', STR_PAD_LEFT);
        $ya = $pdo->prepare("SELECT 1 FROM pedidos WHERE codigo = ?");
        $ya->execute([$codigo]);
        if ($ya->fetchColumn()) {
            continue;
        }

        $producto = $delCatalogo[$n % count($delCatalogo)];
        $cantidad = ($n % 2) + 1;
        $subtotal = round((float)$producto['precio'] * $cantidad, 2);
        $envio    = 0.0;

        $insPedido->execute([
            $codigo, $n < 3 ? $clienteId : null,
            'Cliente De Prueba', 'cliente@demo.flowersanto.test', '+50588887777',
            'Reparto de ejemplo, casa 123', 'Managua',
            date('Y-m-d', strtotime('+' . ($n + 1) . ' days')), 'Mañana (8:00 - 12:00)',
            'Pedido de demostración, no es real.',
            $estado, $estadoPago, Ajustes::texto('moneda_local', 'C$'),
            $subtotal, $envio, $subtotal + $envio,
            bin2hex(random_bytes(16)),
            date('Y-m-d H:i:s', strtotime('-' . (10 - $n * 2) . ' days')),
        ]);
        $pedidoId = (int)$pdo->lastInsertId();
        $insItem->execute([$pedidoId, $producto['id'], $producto['nombre'], $producto['imagen'],
                           $producto['precio'], $cantidad, $subtotal]);
        Pedidos::anotarHistorial($pdo, $pedidoId, 'pedido', '', $estado, $nota, null, 'Seed de demostración');
        $hechos++;
    }
    echo "Pedidos de prueba creados: $hechos\n";
    echo "Cliente de prueba: cliente@demo.flowersanto.test / Demo1234\n";
}

echo "Listo.\n";
