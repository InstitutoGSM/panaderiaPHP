<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

if (!esta_logueado()) {
    echo json_encode(['ok' => false, 'msg' => 'Debes iniciar sesión']);
    exit;
}

$u   = usuario_actual();
$acc = $_POST['accion'] ?? $_GET['accion'] ?? '';

// ── Leer carrito actual ───────────────────────────────────────────────────
if ($acc === 'get') {
    echo json_encode(['ok' => true, 'carrito' => $_SESSION['carrito'] ?? []]);
    exit;
}

// ── Agregar producto ──────────────────────────────────────────────────────
if ($acc === 'add') {
    $pid      = (int)($_POST['producto_id'] ?? 0);
    $cantidad = max(1, (int)($_POST['cantidad'] ?? 1));

    if (!$pid) {
        echo json_encode(['ok' => false, 'msg' => 'Producto inválido']);
        exit;
    }

    // Cargar datos del producto
    $q = db()->prepare("SELECT p.*, u.nombre AS nombre_vendedor, u.nombre_panaderia
                         FROM productos p JOIN usuarios u ON u.id = p.vendedor_id
                         WHERE p.id = ?
  AND p.activo = 1
  AND u.tipo = 'vendedor'
  AND u.estado_verificacion = 'aprobado'");
    $q->execute([$pid]);
    $prod = $q->fetch();

    if (!$prod) {
        echo json_encode(['ok' => false, 'msg' => 'Producto no disponible']);
        exit;
    }

    // Si el carrito ya tiene productos de otro vendedor, rechazar
    $carrito = $_SESSION['carrito'] ?? [];
    if (!empty($carrito)) {
        $vid_actual = $carrito[0]['vendedor_id'];
        if ($vid_actual !== $prod['vendedor_id']) {
            echo json_encode([
                'ok'  => false,
                'msg' => 'Tu carrito ya tiene productos de otra panadería. Vacialo primero.',
                'conflicto' => true
            ]);
            exit;
        }
    }

    // Buscar si ya está en el carrito
    $encontrado = false;
    foreach ($carrito as &$item) {
        if ($item['producto_id'] === $pid) {
            $item['cantidad'] += $cantidad;
            $encontrado = true;
            break;
        }
    }
    unset($item);

    if (!$encontrado) {
        $carrito[] = [
            'producto_id'  => $pid,
            'nombre'       => $prod['nombre'],
            'precio'       => (float)$prod['precio'],
            'precio_media' => $prod['precio_media_docena'] ? (float)$prod['precio_media_docena'] : null,
            'precio_doc'   => $prod['precio_docena']       ? (float)$prod['precio_docena']       : null,
            'unidad_venta' => $prod['unidad_venta'] ?? 'unidad',
            'imagen_url'   => $prod['imagen_url'],
            'vendedor_id'  => $prod['vendedor_id'],
            'nombre_vendedor' => $prod['nombre_panaderia'] ?: $prod['nombre_vendedor'],
            'cantidad'     => $cantidad,
        ];
    }

    $_SESSION['carrito'] = $carrito;
    echo json_encode([
        'ok'    => true,
        'total' => count($carrito),
        'msg'   => '¡Agregado al carrito!'
    ]);
    exit;
}

// ── Cambiar cantidad ──────────────────────────────────────────────────────
if ($acc === 'set') {
    $pid      = (int)($_POST['producto_id'] ?? 0);
    $cantidad = (int)($_POST['cantidad']    ?? 0);
    $carrito  = $_SESSION['carrito'] ?? [];

    if ($cantidad <= 0) {
        $carrito = array_values(array_filter($carrito, fn($i) => $i['producto_id'] !== $pid));
    } else {
        foreach ($carrito as &$item) {
            if ($item['producto_id'] === $pid) {
                $item['cantidad'] = $cantidad;
                break;
            }
        }
        unset($item);
    }

    $_SESSION['carrito'] = $carrito;
    echo json_encode(['ok' => true, 'total' => count($carrito)]);
    exit;
}

// ── Vaciar carrito ────────────────────────────────────────────────────────
if ($acc === 'clear') {
    $_SESSION['carrito'] = [];
    echo json_encode(['ok' => true]);
    exit;
}

echo json_encode(['ok' => false, 'msg' => 'Acción desconocida']);
