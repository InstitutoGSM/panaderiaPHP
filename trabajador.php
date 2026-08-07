<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';
requerir_trabajador();

$u   = usuario_actual();
$uid = (int)$u['id'];
$pan_id = (int)($u['panaderia_id'] ?? 0);

if (!$pan_id) {
    die('<p style="padding:40px;font-family:sans-serif">Tu cuenta no está asignada a ninguna panadería. Contactá al encargado.</p>');
}

$msg_ok  = '';
$msg_err = '';

// Dueño/encargado
$encargado = db()->prepare("SELECT nombre, nombre_panaderia FROM usuarios WHERE id = ? LIMIT 1");
$encargado->execute([$pan_id]);
$enc = $encargado->fetch();

// Productos de la panadería
$prods = db()->prepare("SELECT id, nombre, categoria, cantidad_disponible FROM productos WHERE vendedor_id = ? AND activo = 1 ORDER BY nombre");
$prods->execute([$pan_id]);
$productos = $prods->fetchAll();

// POST: movimiento de stock
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tipo     = $_POST['tipo']       ?? '';
    $prod_id  = (int)($_POST['producto_id'] ?? 0);
    $cantidad = (int)($_POST['cantidad']    ?? 0);
    $desc     = trim($_POST['descripcion'] ?? '');

    if (!in_array($tipo, ['entrada', 'salida']) || !$prod_id || $cantidad <= 0) {
        $msg_err = 'Completá todos los campos correctamente.';
    } else {
        // Verificar que el producto pertenece a esta panadería
        $chk = db()->prepare("SELECT id, cantidad_disponible FROM productos WHERE id = ? AND vendedor_id = ? LIMIT 1");
        $chk->execute([$prod_id, $pan_id]);
        $prod = $chk->fetch();

        if (!$prod) {
            $msg_err = 'Producto inválido.';
        } elseif ($tipo === 'salida' && $prod['cantidad_disponible'] < $cantidad) {
            $msg_err = 'Stock insuficiente (disponible: ' . $prod['cantidad_disponible'] . ').';
        } else {
            try {
                $pdo = db();
                $pdo->beginTransaction();

                $delta = $tipo === 'entrada' ? $cantidad : -$cantidad;
                $pdo->prepare("UPDATE productos SET cantidad_disponible = cantidad_disponible + ? WHERE id = ?")->execute([$delta, $prod_id]);
                $pdo->prepare("INSERT INTO movimientos (tipo, producto_id, cantidad, descripcion, trabajador_id, vendedor_id) VALUES (?,?,?,?,?,?)")->execute([$tipo, $prod_id, $cantidad, $desc ?: null, $uid, $pan_id]);

                $pdo->commit();
                $msg_ok = 'Movimiento registrado ✅';

                // Recargar productos
                $prods->execute([$pan_id]);
                $productos = $prods->fetchAll();
            } catch (Exception $e) {
                $pdo->rollBack();
                $msg_err = 'Error: ' . $e->getMessage();
            }
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
    body { background:#F5F0E8; font-family:sans-serif; }
    .wrap { max-width:860px; margin:0 auto; padding:24px 16px; }
    .card { background:#fff; border-radius:10px; padding:24px; margin-bottom:20px; box-shadow:0 1px 4px rgba(0,0,0,0.08); }
    h1 { margin:0 0 4px; font-size:1.4rem; }
    h2 { font-size:1.1rem; margin:0 0 16px; }
    .msg-ok  { background:#E8F5E9; color:#2E7D32; padding:12px 16px; border-radius:8px; margin-bottom:16px; }
    .msg-err { background:#FFEBEE; color:#C62828; padding:12px 16px; border-radius:8px; margin-bottom:16px; }
    table { width:100%; border-collapse:collapse; font-size:0.9rem; }
    th { text-align:left; padding:8px 10px; background:#F5F0E8; }
    td { padding:8px 10px; border-bottom:1px solid #F0EBE0; }
    select, input[type=number], input[type=text], textarea { width:100%; padding:8px 10px; border:1px solid #ddd; border-radius:6px; font-size:0.9rem; box-sizing:border-box; }
    .form-row { display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px; }
    .btn { padding:10px 20px; background:var(--naranja,#E65100); color:#fff; border:none; border-radius:6px; cursor:pointer; font-weight:700; }
    .badge-entrada { color:#2E7D32; font-weight:700; }
    .badge-salida  { color:#C62828; font-weight:700; }
    .topbar { display:flex; justify-content:space-between; align-items:center; margin-bottom:24px; }
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
        <thead><tr><th>Producto</th><th>Categoría</th><th>Stock</th></tr></thead>
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
        <thead><tr><th>Fecha</th><th>Producto</th><th>Tipo</th><th>Cantidad</th><th>Descripción</th></tr></thead>
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