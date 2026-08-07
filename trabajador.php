<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';
requerir_trabajador();

$u   = usuario_actual();
$uid = (int)$u['id'];
$pan_id = (int)($u['panaderia_id'] ?? 0);
$sucursal_id = (int)($u['sucursal_id'] ?? 0);

if (!$pan_id) {
    die('<p style="padding:40px;font-family:sans-serif">Tu cuenta no está asignada a ninguna panadería. Contactá al encargado.</p>');
}

/*
 * Verificar que la sucursal asignada pertenezca
 * a la panadería del trabajador.
 */
if ($sucursal_id) {
    $sucursal_valida_q = db()->prepare("
        SELECT id
        FROM sucursales
        WHERE id = ?
          AND activo = 1
          AND estado = 'activa'
          AND (
              vendedor_id = ?
              OR padre_id = ?
          )
        LIMIT 1
    ");
    $sucursal_valida_q->execute([$sucursal_id, $pan_id]);

    if (!$sucursal_valida_q->fetchColumn()) {
        $sucursal_id = 0;
    }
}

/*
 * Compatibilidad con trabajadores antiguos
 * que todavía no tienen sucursal asignada.
 */
if (!$sucursal_id) {
    $sucursal_q = db()->prepare("
        SELECT id
        FROM sucursales
        WHERE activo = 1
          AND estado = 'activa'
          AND (
              vendedor_id = ?
              OR padre_id = ?
          )
        ORDER BY id
        LIMIT 1
    ");
    $sucursal_q->execute([$pan_id, $pan_id]);
    $sucursal_id = (int)($sucursal_q->fetchColumn() ?: 0);
}

if (!$sucursal_id) {
    die('<p style="padding:40px;font-family:sans-serif">Tu cuenta no está asignada a una sucursal activa. Contactá al encargado.</p>');
}

$msg_ok  = '';
$msg_err = '';

// Dueño/encargado
$encargado = db()->prepare("SELECT nombre, nombre_panaderia FROM usuarios WHERE id = ? LIMIT 1");
$encargado->execute([$pan_id]);
$enc = $encargado->fetch();

// Productos de la panadería con stock real de la sucursal asignada
$prods = db()->prepare("
    SELECT
        p.id,
        p.nombre,
        p.categoria,
        COALESCE(
            ss.cantidad_disponible,
            CASE
                WHEN s.padre_id IS NULL THEN COALESCE(p.cantidad_disponible, 0)
                ELSE 0
            END
        ) AS cantidad_disponible
    FROM productos p
    INNER JOIN sucursales s
        ON s.id = ?
       AND s.activo = 1
       AND s.estado = 'activa'
    LEFT JOIN stock_sucursal ss
        ON ss.producto_id = p.id
       AND ss.sucursal_id = s.id
    WHERE p.vendedor_id = ?
      AND p.activo = 1
    ORDER BY p.nombre
");

$prods->execute([$sucursal_id, $pan_id]);
$productos = $prods->fetchAll();

// POST: movimiento de stock
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tipo     = $_POST['tipo'] ?? '';
    $prod_id  = (int)($_POST['producto_id'] ?? 0);
    $cantidad = (int)($_POST['cantidad'] ?? 0);
    $desc     = trim($_POST['descripcion'] ?? '');

    if (!in_array($tipo, ['entrada', 'salida'], true) || !$prod_id || $cantidad <= 0) {
        $msg_err = 'Completá todos los campos correctamente.';
    } elseif (mb_strlen($desc) > 300) {
        $msg_err = 'La descripción no puede superar los 300 caracteres.';
    } else {
        $pdo = db();

        try {
            $pdo->beginTransaction();

            /*
             * Verificar que:
             * - El producto pertenece a la panadería.
             * - La sucursal está activa.
             * - La sucursal pertenece a esta panadería.
             * - La fila queda bloqueada durante la operación.
             */
            $producto_q = $pdo->prepare("
                SELECT
                    p.id,
                    p.cantidad_disponible,
                    s.padre_id
                FROM productos p
                INNER JOIN sucursales s
                    ON s.id = ?
                   AND s.activo = 1
                   AND s.estado = 'activa'
                   AND (
                       s.vendedor_id = ?
                       OR s.padre_id = ?
                   )
                WHERE p.id = ?
                  AND p.vendedor_id = ?
                  AND p.activo = 1
                LIMIT 1
                FOR UPDATE
            ");

            $producto_q->execute([
                $sucursal_id,
                $pan_id,
                $pan_id,
                $prod_id,
                $pan_id
            ]);

            $producto = $producto_q->fetch();

            if (!$producto) {
                throw new RuntimeException(
                    'El producto o la sucursal no son válidos.'
                );
            }

            /*
             * Compatibilidad:
             * - La sucursal Padre comienza usando el stock histórico
             *   de productos.cantidad_disponible.
             * - La sucursal Hija comienza con stock cero.
             *
             * INSERT IGNORE evita duplicar la fila si otro proceso
             * la creó previamente.
             */
            $stock_inicial = $producto['padre_id'] === null
                ? max(0, (int)$producto['cantidad_disponible'])
                : 0;

            $crear_stock_q = $pdo->prepare("
                INSERT IGNORE INTO stock_sucursal (
                    producto_id,
                    sucursal_id,
                    cantidad_disponible,
                    stock_minimo,
                    activo
                )
                VALUES (?, ?, ?, 0, 1)
            ");

            $crear_stock_q->execute([
                $prod_id,
                $sucursal_id,
                $stock_inicial
            ]);

            /*
             * Bloquear el stock real de esta sucursal.
             */
            $stock_q = $pdo->prepare("
                SELECT cantidad_disponible
                FROM stock_sucursal
                WHERE producto_id = ?
                  AND sucursal_id = ?
                  AND activo = 1
                LIMIT 1
                FOR UPDATE
            ");

            $stock_q->execute([
                $prod_id,
                $sucursal_id
            ]);

            $stock = $stock_q->fetch();

            if (!$stock) {
                throw new RuntimeException(
                    'No se pudo preparar el stock de la sucursal.'
                );
            }

            $stock_actual = max(0, (int)$stock['cantidad_disponible']);

            if ($tipo === 'salida') {
                if ($stock_actual < $cantidad) {
                    throw new RuntimeException(
                        'Stock insuficiente. Disponible: ' . $stock_actual . '.'
                    );
                }

                /*
                 * La condición cantidad_disponible >= ?
                 * evita stock negativo incluso ante operaciones simultáneas.
                 */
                $actualizar_stock_q = $pdo->prepare("
                    UPDATE stock_sucursal
                    SET cantidad_disponible = cantidad_disponible - ?
                    WHERE producto_id = ?
                      AND sucursal_id = ?
                      AND activo = 1
                      AND cantidad_disponible >= ?
                ");

                $actualizar_stock_q->execute([
                    $cantidad,
                    $prod_id,
                    $sucursal_id,
                    $cantidad
                ]);

                if ($actualizar_stock_q->rowCount() !== 1) {
                    throw new RuntimeException(
                        'El stock cambió mientras se registraba la salida. Intentá nuevamente.'
                    );
                }
            } else {
                /*
                 * GREATEST evita conservar valores negativos
                 * si existiera un registro antiguo inconsistente.
                 */
                $actualizar_stock_q = $pdo->prepare("
                    UPDATE stock_sucursal
                    SET cantidad_disponible =
                        GREATEST(cantidad_disponible + ?, 0)
                    WHERE producto_id = ?
                      AND sucursal_id = ?
                      AND activo = 1
                ");

                $actualizar_stock_q->execute([
                    $cantidad,
                    $prod_id,
                    $sucursal_id
                ]);

                if ($actualizar_stock_q->rowCount() !== 1) {
                    throw new RuntimeException(
                        'No se pudo actualizar el stock de la sucursal.'
                    );
                }
            }

            /*
             * Guardar el movimiento con la sucursal real.
             */
            $movimiento_q = $pdo->prepare("
                INSERT INTO movimientos (
                    tipo,
                    producto_id,
                    cantidad,
                    descripcion,
                    trabajador_id,
                    vendedor_id,
                    sucursal_id
                )
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");

            $movimiento_q->execute([
                $tipo,
                $prod_id,
                $cantidad,
                $desc !== '' ? $desc : null,
                $uid,
                $pan_id,
                $sucursal_id
            ]);

            $pdo->commit();

            $msg_ok = 'Movimiento registrado correctamente.';

            /*
             * Recargar el stock real de la sucursal.
             */
            $prods->execute([$sucursal_id, $pan_id]);
            $productos = $prods->fetchAll();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $msg_err = $e->getMessage();
        }
    }
}

// Últimos movimientos
$movs = db()->prepare("
    SELECT m.*, p.nombre AS producto_nombre
    FROM movimientos m
    JOIN productos p ON p.id = m.producto_id
    WHERE m.trabajador_id = ?
    ORDER BY m.created_at DESC
    LIMIT 20
");
$movs->execute([$uid]);
$movimientos = $movs->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel Trabajador — <?= SITE_NAME ?></title>
    <link rel="stylesheet" href="<?= SITE_URL ?>/css/global.css">
    <style>
        body {
            background: #F5F0E8;
            font-family: sans-serif;
        }

        .wrap {
            max-width: 860px;
            margin: 0 auto;
            padding: 24px 16px;
        }

        .card {
            background: #fff;
            border-radius: 10px;
            padding: 24px;
            margin-bottom: 20px;
            box-shadow: 0 1px 4px rgba(0, 0, 0, 0.08);
        }

        h1 {
            margin: 0 0 4px;
            font-size: 1.4rem;
        }

        h2 {
            font-size: 1.1rem;
            margin: 0 0 16px;
        }

        .msg-ok {
            background: #E8F5E9;
            color: #2E7D32;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 16px;
        }

        .msg-err {
            background: #FFEBEE;
            color: #C62828;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 16px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
        }

        th {
            text-align: left;
            padding: 8px 10px;
            background: #F5F0E8;
        }

        td {
            padding: 8px 10px;
            border-bottom: 1px solid #F0EBE0;
        }

        select,
        input[type=number],
        input[type=text],
        textarea {
            width: 100%;
            padding: 8px 10px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 0.9rem;
            box-sizing: border-box;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 12px;
        }

        .btn {
            padding: 10px 20px;
            background: var(--naranja, #E65100);
            color: #fff;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 700;
        }

        .badge-entrada {
            color: #2E7D32;
            font-weight: 700;
        }

        .badge-salida {
            color: #C62828;
            font-weight: 700;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
        }
    </style>
</head>

<body>
    <div class="wrap">

        <div class="topbar">
            <div>
                <h1>👷 Panel Trabajador</h1>
                <p style="margin:0;color:#777;font-size:0.88rem">
                    <?= h($u['nombre']) ?> · <?= h($enc['nombre_panaderia'] ?: $enc['nombre']) ?>
                </p>
            </div>
            <a href="<?= SITE_URL ?>/logout.php" style="color:#777;font-size:0.88rem;text-decoration:none">Salir →</a>
        </div>

        <?php if ($msg_ok):  ?><div class="msg-ok"><?= h($msg_ok) ?></div><?php endif; ?>
        <?php if ($msg_err): ?><div class="msg-err"><?= h($msg_err) ?></div><?php endif; ?>

        <!-- Registrar movimiento -->
        <div class="card">
            <h2>📦 Registrar movimiento de stock</h2>
            <form method="POST">
                <div class="form-row">
                    <div>
                        <label>Tipo</label>
                        <select name="tipo" required>
                            <option value="entrada">Entrada</option>
                            <option value="salida">Salida</option>
                        </select>
                    </div>
                    <div>
                        <label>Producto</label>
                        <select name="producto_id" required>
                            <option value="">— Seleccioná —</option>
                            <?php foreach ($productos as $p): ?>
                                <option value="<?= $p['id'] ?>">
                                    <?= h($p['nombre']) ?> (stock: <?= $p['cantidad_disponible'] ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div>
                        <label>Cantidad</label>
                        <input type="number" name="cantidad" min="1" required>
                    </div>
                    <div>
                        <label>Descripción (opcional)</label>
                        <input type="text" name="descripcion" placeholder="Ej: Carga del día">
                    </div>
                </div>
                <button type="submit" class="btn">Registrar</button>
            </form>
        </div>

        <!-- Stock actual -->
        <div class="card">
            <h2>🛒 Stock actual</h2>
            <?php if (empty($productos)): ?>
                <p style="color:#999">No hay productos activos.</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Producto</th>
                            <th>Categoría</th>
                            <th>Stock</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($productos as $p): ?>
                            <tr>
                                <td><?= h($p['nombre']) ?></td>
                                <td><?= h($p['categoria']) ?></td>
                                <td><?= $p['cantidad_disponible'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- Últimos movimientos -->
        <div class="card">
            <h2>📋 Mis últimos movimientos</h2>
            <?php if (empty($movimientos)): ?>
                <p style="color:#999">Sin movimientos registrados.</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Producto</th>
                            <th>Tipo</th>
                            <th>Cantidad</th>
                            <th>Descripción</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($movimientos as $m): ?>
                            <tr>
                                <td style="font-size:0.8rem;color:#777"><?= $m['created_at'] ?></td>
                                <td><?= h($m['producto_nombre']) ?></td>
                                <td class="badge-<?= $m['tipo'] ?>"><?= $m['tipo'] ?></td>
                                <td><?= $m['cantidad'] ?></td>
                                <td style="color:#777"><?= h($m['descripcion'] ?? '—') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

    </div>
</body>

</html>