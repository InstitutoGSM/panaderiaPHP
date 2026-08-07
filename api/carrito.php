<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

if (!esta_logueado()) {
    echo json_encode([
        'ok' => false,
        'msg' => 'Debes iniciar sesión'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$accion = $_POST['accion'] ?? $_GET['accion'] ?? '';

if ($accion === 'get') {
    echo json_encode([
        'ok' => true,
        'carrito' => $_SESSION['carrito'] ?? []
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($accion === 'add') {
    $producto_id = (int)($_POST['producto_id'] ?? 0);
    $cantidad = max(1, (int)($_POST['cantidad'] ?? 1));
    $sucursal_id = (int)($_POST['sucursal_id'] ?? 0);

    if (!$producto_id) {
        echo json_encode([
            'ok' => false,
            'msg' => 'Producto inválido'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt = db()->prepare("
        SELECT
            p.*,
            u.nombre AS nombre_vendedor,
            u.nombre_panaderia
        FROM productos p
        INNER JOIN usuarios u ON u.id = p.vendedor_id
        WHERE p.id = ?
          AND p.activo = 1
          AND u.tipo = 'vendedor'
          AND u.estado_verificacion = 'aprobado'
        LIMIT 1
    ");

    $stmt->execute([$producto_id]);
    $producto = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$producto) {
        echo json_encode([
            'ok' => false,
            'msg' => 'Producto no disponible'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $carrito = $_SESSION['carrito'] ?? [];

    foreach ($carrito as $item) {
        if (
            (int)$item['vendedor_id'] !== (int)$producto['vendedor_id']
        ) {
            echo json_encode([
                'ok' => false,
                'msg' => 'El carrito solo puede contener productos de una panadería.',
                'conflicto' => true
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    $encontrado = false;

    foreach ($carrito as &$item) {
        $misma_sucursal =
            (int)($item['sucursal_id'] ?? 0) === $sucursal_id;

        if (
            (int)$item['producto_id'] === $producto_id &&
            $misma_sucursal
        ) {
            $item['cantidad'] += $cantidad;
            $encontrado = true;
            break;
        }
    }

    unset($item);

    if (!$encontrado) {
        $carrito[] = [
            'producto_id' => $producto_id,
            'nombre' => $producto['nombre'],
            'precio' => (float)$producto['precio'],
            'precio_media' => $producto['precio_media_docena'] !== null
                ? (float)$producto['precio_media_docena']
                : null,
            'precio_doc' => $producto['precio_docena'] !== null
                ? (float)$producto['precio_docena']
                : null,
            'unidad_venta' => $producto['unidad_venta'] ?? 'unidad',
            'imagen_url' => $producto['imagen_url'],
            'vendedor_id' => (int)$producto['vendedor_id'],
            'nombre_vendedor' =>
                $producto['nombre_panaderia'] ?: $producto['nombre_vendedor'],
            'sucursal_id' => $sucursal_id,
            'cantidad' => $cantidad
        ];
    }

    $_SESSION['carrito'] = $carrito;

    echo json_encode([
        'ok' => true,
        'total' => count($carrito),
        'msg' => '¡Agregado al carrito!'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($accion === 'set') {
    $producto_id = (int)($_POST['producto_id'] ?? 0);
    $cantidad = (int)($_POST['cantidad'] ?? 0);
    $sucursal_id = (int)($_POST['sucursal_id'] ?? 0);

    $carrito = $_SESSION['carrito'] ?? [];

    if ($cantidad <= 0) {
        $carrito = array_values(array_filter(
            $carrito,
            function ($item) use ($producto_id, $sucursal_id) {
                return !(
                    (int)$item['producto_id'] === $producto_id &&
                    (int)($item['sucursal_id'] ?? 0) === $sucursal_id
                );
            }
        ));
    } else {
        foreach ($carrito as &$item) {
            if (
                (int)$item['producto_id'] === $producto_id &&
                (int)($item['sucursal_id'] ?? 0) === $sucursal_id
            ) {
                $item['cantidad'] = $cantidad;
            }
        }

        unset($item);
    }

    $_SESSION['carrito'] = $carrito;

    echo json_encode([
        'ok' => true,
        'total' => count($carrito)
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($accion === 'clear') {
    $_SESSION['carrito'] = [];

    echo json_encode([
        'ok' => true
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'ok' => false,
    'msg' => 'Acción no válida'
], JSON_UNESCAPED_UNICODE);