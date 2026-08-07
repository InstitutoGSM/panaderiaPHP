<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json');

function resp(bool $ok, string $msg = '', array $extra = []): void
{
  echo json_encode(array_merge(['ok' => $ok, 'msg' => $msg], $extra));
  exit;
}

// Solo logueados
if (!esta_logueado()) resp(false, 'Sesión expirada. Iniciá sesión nuevamente.');

$body = json_decode(file_get_contents('php://input'), true);
if (!$body) resp(false, 'Datos inválidos.');

$uid       = (int)$_SESSION['user_id'];
$nombre    = trim($body['nombre']       ?? '');
$email     = trim($body['email']        ?? '');
$cp        = trim($body['cp']           ?? '') ?: null;
$direccion = trim($body['direccion']    ?? '') ?: null;
$notas     = trim($body['notas']        ?? '') ?: null;
$ultimos4  = trim($body['ultimos4']     ?? '') ?: null;
$tipo_tarj = trim($body['tipo_tarjeta'] ?? '') ?: null;
$grupos    = $body['grupos']            ?? [];

if (!$nombre || !$email) resp(false, 'Nombre y email son obligatorios.');
if (empty($grupos))      resp(false, 'El carrito está vacío.');

$pdo = db();

try {
  $pdo->beginTransaction();

  $pedidos_creados = [];

  foreach ($grupos as $grupo) {
    $vid       = (int)($grupo['vendedor_id']  ?? 0);
    $sucursal_id = (int)($grupo['sucursal_id'] ?? 0) ?: null;
    $medio     = $grupo['medio_pago'] ?? 'efectivo';
    $items_grp = $grupo['items']      ?? [];

    if (!$vid || empty($items_grp)) continue;

    // Validar medios permitidos
    if (!in_array($medio, ['efectivo', 'transferencia', 'debito', 'credito'])) {
      $medio = 'efectivo';
    }

    // ── Validar y descontar stock ──────────────────────────────────────
    foreach ($items_grp as $it) {
      $pid      = (int)($it['producto_id'] ?? 0);
      $cantidad = (int)($it['cantidad']    ?? 0);
      if ($pid <= 0 || $cantidad <= 0) continue;

      // Lock de la fila para evitar race conditions
      $row = $pdo->prepare("
    SELECT p.cantidad_disponible, p.precio, p.nombre AS nombre_db
    FROM productos p
    INNER JOIN usuarios u ON u.id = p.vendedor_id
    WHERE p.id = ?
      AND p.vendedor_id = ?
      AND p.activo = 1
      AND u.tipo = 'vendedor'
      AND u.estado_verificacion = 'aprobado'
    FOR UPDATE
");
      $row->execute([$pid, $vid]);
      $prod = $row->fetch();

      if (!$prod) {
        $pdo->rollBack();
        resp(false, "Producto no encontrado o inactivo (id: $pid).");
      }

      // Guardar precio real de BD en el item
      $it['_precio_db'] = (float)$prod['precio'];
      // Actualizar el array original
      foreach ($items_grp as &$item_ref) {
        if ((int)($item_ref['producto_id'] ?? 0) === $pid) {
          $item_ref['_precio_db'] = (float)$prod['precio'];
          $item_ref['nombre']     = $prod['nombre_db'];
        }
      }
      unset($item_ref);

      if (
        $prod['cantidad_disponible'] !== null
        && $prod['cantidad_disponible'] < $cantidad
      ) {
        $pdo->rollBack();
        resp(false, "Stock insuficiente para \"{$it['nombre']}\". Disponible: {$prod['cantidad_disponible']}.");
      }

      // Descontar stock
      $pdo->prepare("
                UPDATE productos
                SET cantidad_disponible = cantidad_disponible - ?
                WHERE id = ? AND vendedor_id = ?
            ")->execute([$cantidad, $pid, $vid]);
    }

    // ── Calcular total del grupo ────────────────────────────────────────
    // Los precios vienen de la BD, no del cliente
    $total = 0;
    foreach ($items_grp as $it) {
      $pid = (int)($it['producto_id'] ?? 0);
      $cantidad = (int)($it['cantidad'] ?? 0);
      $precio_db = (float)($it['_precio_db'] ?? 0);
      $total += $precio_db * $cantidad;
    }

    // ── Generar ticket_id único ─────────────────────────────────────────
    $ticket_id = 'TK-' . strtoupper(substr(md5(uniqid('', true)), 0, 5))
      . '-' . strtoupper(substr(md5(uniqid('', true)), 0, 4));

    // ── Insertar pedido ─────────────────────────────────────────────────
    $ins = $pdo->prepare("
            INSERT INTO pedidos
              (ticket_id, comprador_id, vendedor_id, sucursal_id, total, estado,
               medio_pago, nombre_comprador, email_comprador,
               codigo_postal, direccion, notas,
               tarjeta_ultimos4, tarjeta_tipo, created_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?, NOW())
        ");
    $ins->execute([
      $ticket_id,
      $uid,
      $vid,
      $sucursal_id,
      $total,
      'pendiente',
      $medio,
      $nombre,
      $email,
      $cp,
      $direccion,
      $notas,
      $ultimos4,
      $tipo_tarj,
    ]);
    $pedido_id = (int)$pdo->lastInsertId();

    // ── Insertar items ──────────────────────────────────────────────────
    $ins_item = $pdo->prepare("
            INSERT INTO pedido_items
              (pedido_id, producto_id, nombre, cantidad, precio, variante)
            VALUES (?,?,?,?,?,?)
        ");
    foreach ($items_grp as $it) {
      $ins_item->execute([
        $pedido_id,
        (int)($it['producto_id'] ?? 0),
        $it['nombre']   ?? '',
        (int)$it['cantidad'],
        (float)($it['_precio_db'] ?? $it['precio']),
        $it['variante'] ?? 'unidad',
      ]);
    }

    $pedidos_creados[] = [
      'pedido_id'  => $pedido_id,
      'ticket_id'  => $ticket_id,
      'total'      => $total,
      'medio_pago' => $medio,
      'items'      => $items_grp,
    ];
  }

  $pdo->commit();

  // ── Generar HTML del ticket (primer pedido) ─────────────────────────────
  $primer = $pedidos_creados[0] ?? null;
  $ticket_html = $primer ? generarTicketHTML($primer, $nombre, $email) : null;

  resp(true, '¡Pedido confirmado!', [
    'pedidos'     => $pedidos_creados,
    'ticket_html' => $ticket_html,
  ]);
} catch (PDOException $e) {
  $pdo->rollBack();
  resp(false, 'Error interno al procesar el pedido. Intentá de nuevo.');
}

/* ══ GENERAR TICKET HTML ════════════════════════════════════════════════════ */
function generarTicketHTML(array $pedido, string $nombre, string $email): string
{
  $ticket_id = $pedido['ticket_id'];
  $fecha     = date('d/m/Y H:i');
  $total     = number_format($pedido['total'], 0, ',', '.');

  $filas = '';
  foreach ($pedido['items'] as $i) {
    $subtotal = number_format($i['precio'] * $i['cantidad'], 0, ',', '.');
    $precio   = number_format($i['precio'], 0, ',', '.');
    $filas .= "
        <tr>
          <td>" . htmlspecialchars($i['nombre']) . "</td>
          <td style='text-align:center'>" . htmlspecialchars($i['variante'] ?? 'unidad') . "</td>
          <td style='text-align:center'>{$i['cantidad']}</td>
          <td style='text-align:right'>\${$precio}</td>
          <td style='text-align:right'>\${$subtotal}</td>
        </tr>";
  }

  $medio_label = match ($pedido['medio_pago']) {
    'efectivo'      => '💵 Efectivo',
    'transferencia' => '📲 Transferencia',
    'debito'        => '💳 Débito',
    'credito'       => '💳 Crédito',
    default         => $pedido['medio_pago'],
  };

  return <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Ticket {$ticket_id}</title>
  <style>
    * { box-sizing:border-box; margin:0; padding:0; }
    body {
      font-family:'Courier New',monospace;
      background:#f5f5f5;
      display:flex; justify-content:center;
      padding:40px 20px;
    }
    .ticket {
      background:white; width:100%; max-width:480px;
      padding:36px 32px; border-radius:12px;
      box-shadow:0 4px 24px rgba(0,0,0,0.1);
    }
    .ticket-header { text-align:center; margin-bottom:24px; }
    .ticket-logo   { font-size:2.5rem; margin-bottom:6px; }
    .ticket-marca  { font-size:1.4rem; font-weight:900; color:#C8601A; letter-spacing:.1em; }
    .ticket-sub    { font-size:.8rem; color:#7A6A5A; margin-top:2px; }
    .divider { border:none; border-top:2px dashed #EDD9B8; margin:18px 0; }
    .ticket-id {
      text-align:center; font-size:1.1rem; font-weight:900; color:#3B1A0A;
      background:#F5ECD7; padding:10px; border-radius:8px;
      margin-bottom:18px; letter-spacing:.15em;
    }
    .info-row { display:flex; justify-content:space-between; font-size:.82rem; margin-bottom:6px; }
    .info-label { color:#7A6A5A; }
    .info-val   { font-weight:700; color:#3B1A0A; }
    table { width:100%; border-collapse:collapse; font-size:.82rem; margin-top:8px; }
    th { background:#3B1A0A; color:white; padding:8px 6px; text-align:left; font-size:.75rem; }
    td { padding:7px 6px; border-bottom:1px solid #F5ECD7; }
    tr:last-child td { border-bottom:none; }
    .total-row {
      display:flex; justify-content:space-between; align-items:center;
      margin-top:16px; padding-top:16px; border-top:2px solid #3B1A0A;
    }
    .total-label { font-size:1rem; font-weight:700; }
    .total-val   { font-size:1.5rem; font-weight:900; color:#2D7A4F; }
    .ticket-footer {
      text-align:center; margin-top:24px;
      font-size:.75rem; color:#7A6A5A; line-height:1.6;
    }
    .btn-print {
      display:block; width:100%; margin-top:24px; padding:13px;
      background:#C8601A; color:white; border:none; border-radius:8px;
      font-size:1rem; font-weight:700; cursor:pointer; letter-spacing:.04em;
    }
    @media print { .btn-print { display:none; } }
  </style>
</head>
<body>
<div class="ticket">
  <div class="ticket-header">
    <div class="ticket-logo">🥖</div>
    <div class="ticket-marca">PANADERIA PUMA</div>
    <div class="ticket-sub">Comprobante de pedido</div>
  </div>

  <div class="ticket-id">{$ticket_id}</div>
  <hr class="divider">

  <div class="info-row"><span class="info-label">Fecha</span><span class="info-val">{$fecha}</span></div>
  <div class="info-row"><span class="info-label">Comprador</span><span class="info-val">{$nombre}</span></div>
  <div class="info-row"><span class="info-label">Email</span><span class="info-val">{$email}</span></div>
  <div class="info-row"><span class="info-label">Pago</span><span class="info-val">{$medio_label}</span></div>
  <div class="info-row"><span class="info-label">Estado</span><span class="info-val">⏳ Pendiente</span></div>

  <hr class="divider">

  <table>
    <thead>
      <tr>
        <th>Producto</th><th style='text-align:center'>Var.</th>
        <th style='text-align:center'>Cant.</th>
        <th style='text-align:right'>P/u</th>
        <th style='text-align:right'>Total</th>
      </tr>
    </thead>
    <tbody>{$filas}</tbody>
  </table>

  <div class="total-row">
    <span class="total-label">TOTAL</span>
    <span class="total-val">\${$total}</span>
  </div>

  <div class="ticket-footer">
    Gracias por tu compra 🥖<br>
    PanaderiaMarket — Catamarca, Argentina<br>
    soporte-lospuma@gmail.com
  </div>

  <button class="btn-print" onclick="window.print()">🖨️ Imprimir comprobante</button>
</div>
</body>
</html>
HTML;
}
