<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

function responder(bool $ok, string $mensaje = '', array $extra = []): void
{
  echo json_encode(
    array_merge(
      [
        'ok' => $ok,
        'msg' => $mensaje
      ],
      $extra
    ),
    JSON_UNESCAPED_UNICODE
  );
  exit;
}

if (!esta_logueado()) {
  responder(false, 'Sesión expirada. Iniciá sesión nuevamente.');
}

$body = json_decode(file_get_contents('php://input'), true);

if (!is_array($body)) {
  responder(false, 'Datos inválidos.');
}

$comprador_id = (int)($_SESSION['user_id'] ?? 0);
$nombre       = trim($body['nombre'] ?? '');
$email        = trim($body['email'] ?? '');
$codigo_postal = trim($body['cp'] ?? '') ?: null;
$direccion    = trim($body['direccion'] ?? '') ?: null;
$notas        = trim($body['notas'] ?? '') ?: null;
$grupos       = $body['grupos'] ?? [];

if (!$comprador_id) {
  responder(false, 'Comprador inválido.');
}

if ($nombre === '' || $email === '') {
  responder(false, 'Nombre y email son obligatorios.');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
  responder(false, 'El email no es válido.');
}

if (!is_array($grupos) || empty($grupos)) {
  responder(false, 'El carrito está vacío.');
}

$pdo = db();
$pedidos_creados = [];

try {
  $pdo->beginTransaction();

  foreach ($grupos as $grupo) {
    $vendedor_id = (int)($grupo['vendedor_id'] ?? 0);
    $sucursal_id = (int)($grupo['sucursal_id'] ?? 0);
    $medio_pago  = $grupo['medio_pago'] ?? 'efectivo';
    $items       = $grupo['items'] ?? [];

    if (!$vendedor_id || !$sucursal_id || !is_array($items) || empty($items)) {
      throw new RuntimeException('El grupo del carrito es inválido.');
    }

    if (!in_array($medio_pago, [
      'efectivo',
      'transferencia',
      'debito',
      'credito'
    ], true)) {
      $medio_pago = 'efectivo';
    }

    /*
         * Validar sucursal y obtener el Padre real.
         *
         * En una sucursal Padre:
         *   padre_id IS NULL
         *   vendedor_id = usuario Padre
         *
         * En una sucursal Hija:
         *   padre_id = usuario Padre
         *   vendedor_id = usuario Hija
         */
    $sucursal_stmt = $pdo->prepare("
            SELECT
                s.id,
                s.vendedor_id,
                s.padre_id,
                s.nombre,
                CASE
                    WHEN s.padre_id IS NULL THEN s.vendedor_id
                    ELSE s.padre_id
                END AS padre_usuario_id
            FROM sucursales s
            WHERE s.id = ?
              AND s.activo = 1
              AND s.estado = 'activa'
            LIMIT 1
            FOR UPDATE
        ");

    $sucursal_stmt->execute([$sucursal_id]);
    $sucursal = $sucursal_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$sucursal) {
      throw new RuntimeException('La sucursal no está disponible.');
    }

    $padre_usuario_id = (int)$sucursal['padre_usuario_id'];

    /*
         * Los productos pertenecen al Padre, no a la Hija.
         */
    if ($vendedor_id !== $padre_usuario_id) {
      throw new RuntimeException('La sucursal no pertenece a esta panadería.');
    }

    $padre_stmt = $pdo->prepare("
            SELECT id
            FROM usuarios
            WHERE id = ?
              AND tipo = 'vendedor'
              AND estado_verificacion = 'aprobado'
            LIMIT 1
        ");

    $padre_stmt->execute([$padre_usuario_id]);

    if (!$padre_stmt->fetchColumn()) {
      throw new RuntimeException('La panadería no está habilitada.');
    }

    $es_sucursal_padre = empty($sucursal['padre_id']);

    $lineas = [];
    $total = 0;

    foreach ($items as $item) {
      $producto_id = (int)($item['producto_id'] ?? 0);
      $cantidad    = (int)($item['cantidad'] ?? 0);
      $variante    = $item['variante'] ?? 'unidad';

      if (!$producto_id || $cantidad <= 0) {
        throw new RuntimeException('Hay un producto inválido en el carrito.');
      }

      if (!in_array($variante, [
        'unidad',
        'media_docena',
        'docena'
      ], true)) {
        $variante = 'unidad';
      }

      /*
             * Si todavía no existe el stock de este producto en la
             * sucursal Padre, lo inicializamos con el stock antiguo.
             */
      if ($es_sucursal_padre) {
        $crear_stock = $pdo->prepare("
                    INSERT IGNORE INTO stock_sucursal (
                        producto_id,
                        sucursal_id,
                        cantidad_disponible,
                        stock_minimo,
                        activo
                    )
                    SELECT
                        p.id,
                        ?,
                        COALESCE(p.cantidad_disponible, 0),
                        0,
                        p.activo
                    FROM productos p
                    WHERE p.id = ?
                      AND p.vendedor_id = ?
                ");

        $crear_stock->execute([
          $sucursal_id,
          $producto_id,
          $padre_usuario_id
        ]);
      } else {
        /*
                 * Para una Hija el stock empieza en cero.
                 */
        $crear_stock = $pdo->prepare("
                    INSERT IGNORE INTO stock_sucursal (
                        producto_id,
                        sucursal_id,
                        cantidad_disponible,
                        stock_minimo,
                        activo
                    )
                    VALUES (?, ?, 0, 0, 1)
                ");

        $crear_stock->execute([
          $producto_id,
          $sucursal_id
        ]);
      }

      /*
             * Producto + herencia + stock.
             */
      $producto_stmt = $pdo->prepare("
                SELECT
                    p.id,
                    p.nombre,
                    p.precio,
                    p.precio_media_docena,
                    p.precio_docena,
                    p.activo,
                    ss.cantidad_disponible AS stock_actual,
                    h.id AS herencia_id,
                    h.aceptado,
                    h.precio_minimo,
                    h.precio_sucursal
                FROM productos p
                INNER JOIN stock_sucursal ss
                    ON ss.producto_id = p.id
                   AND ss.sucursal_id = ?
                LEFT JOIN herencia_productos h
                    ON h.producto_id = p.id
                   AND h.sucursal_id = ?
                WHERE p.id = ?
                  AND p.vendedor_id = ?
                  AND p.activo = 1
                LIMIT 1
                FOR UPDATE
            ");

      $producto_stmt->execute([
        $sucursal_id,
        $sucursal_id,
        $producto_id,
        $padre_usuario_id
      ]);

      $producto = $producto_stmt->fetch(PDO::FETCH_ASSOC);

      if (!$producto) {
        throw new RuntimeException(
          'Producto no encontrado o inactivo.'
        );
      }

      /*
             * Las Hijas solo pueden comprar productos heredados,
             * aceptados y con precio establecido.
             */
      if (!$es_sucursal_padre) {
        if (
          empty($producto['herencia_id']) ||
          (int)$producto['aceptado'] !== 1 ||
          $producto['precio_sucursal'] === null
        ) {
          throw new RuntimeException(
            'Este producto no está habilitado en la sucursal Hija.'
          );
        }

        if (
          (float)$producto['precio_sucursal'] <
          (float)$producto['precio_minimo']
        ) {
          throw new RuntimeException(
            'El precio del producto no respeta el mínimo establecido.'
          );
        }
      }

      if ((int)$producto['stock_actual'] < $cantidad) {
        throw new RuntimeException(
          'Stock insuficiente para "' .
            $producto['nombre'] .
            '". Disponible: ' .
            $producto['stock_actual']
        );
      }

      /*
             * Precio real tomado desde la BD.
             * Nunca usamos el precio enviado por JavaScript.
             */
      if ($es_sucursal_padre) {
        if ($variante === 'media_docena' && $producto['precio_media_docena'] !== null) {
          $precio_real = (float)$producto['precio_media_docena'];
        } elseif ($variante === 'docena' && $producto['precio_docena'] !== null) {
          $precio_real = (float)$producto['precio_docena'];
        } else {
          $precio_real = (float)$producto['precio'];
        }
      } else {
        /*
                 * La Hija usa el precio de reventa configurado
                 * por ella, siempre respetando precio_minimo.
                 */
        $precio_real = (float)$producto['precio_sucursal'];
      }

      if ($precio_real <= 0) {
        throw new RuntimeException(
          'El producto no tiene un precio válido.'
        );
      }

      /*
             * Descontar stock con protección contra cantidades negativas.
             */
      $descontar = $pdo->prepare("
                UPDATE stock_sucursal
                SET cantidad_disponible = cantidad_disponible - ?
                WHERE producto_id = ?
                  AND sucursal_id = ?
                  AND cantidad_disponible >= ?
            ");

      $descontar->execute([
        $cantidad,
        $producto_id,
        $sucursal_id,
        $cantidad
      ]);

      if ($descontar->rowCount() !== 1) {
        throw new RuntimeException(
          'No se pudo actualizar el stock del producto.'
        );
      }

      $subtotal = $precio_real * $cantidad;
      $total += $subtotal;

      $lineas[] = [
        'producto_id' => $producto_id,
        'nombre' => $producto['nombre'],
        'precio' => $precio_real,
        'cantidad' => $cantidad,
        'variante' => $variante
      ];
    }

    /*
         * La estructura real de pedidos no tiene ticket_id,
         * nombre_comprador ni datos de tarjeta.
         */
    $pedido_stmt = $pdo->prepare("
            INSERT INTO pedidos (
                comprador_id,
                vendedor_id,
                sucursal_id,
                estado,
                metodo_pago,
                total,
                notas,
                codigo_postal,
                direccion,
                created_at
            )
            VALUES (?, ?, ?, 'pendiente', ?, ?, ?, ?, ?, NOW())
        ");

    $pedido_stmt->execute([
      $comprador_id,
      $padre_usuario_id,
      $sucursal_id,
      $medio_pago,
      $total,
      $notas,
      $codigo_postal,
      $direccion
    ]);

    $pedido_id = (int)$pdo->lastInsertId();

    $item_stmt = $pdo->prepare("
            INSERT INTO pedido_items (
                pedido_id,
                producto_id,
                nombre_producto,
                precio_unitario,
                cantidad
            )
            VALUES (?, ?, ?, ?, ?)
        ");

    foreach ($lineas as $linea) {
      $item_stmt->execute([
        $pedido_id,
        $linea['producto_id'],
        $linea['nombre'],
        $linea['precio'],
        $linea['cantidad']
      ]);
    }

    $pedidos_creados[] = [
      'pedido_id' => $pedido_id,
      'sucursal_id' => $sucursal_id,
      'total' => $total,
      'medio_pago' => $medio_pago,
      'items' => $lineas
    ];
  }

  if (empty($pedidos_creados)) {
    throw new RuntimeException('No hay pedidos válidos para crear.');
  }

  $pdo->commit();

  responder(true, '¡Pedido confirmado!', [
    'pedidos' => $pedidos_creados,
    'ticket_html' => null
  ]);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }

  error_log('Error en checkout: ' . $e->getMessage());

  responder(
    false,
    'No se pudo procesar el pedido. Verificá los datos e intentá nuevamente.'
  );
}
