<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

$q = trim($_GET['q'] ?? '');
if (strlen($q) < 2) { echo '[]'; exit; }

$stmt = db()->prepare("
  SELECT p.id, p.nombre, p.precio, p.categoria, p.imagen_url,
         u.nombre_panaderia, u.nombre AS nombre_vendedor
  FROM   productos p
  JOIN   usuarios  u ON u.id = p.vendedor_id
  WHERE  p.activo = 1
    AND  u.estado_verificacion = 'aprobado'
    AND  u.tipo = 'vendedor'
    AND  p.cantidad_disponible > 0
    AND  p.nombre LIKE ?
  LIMIT 8
");
$stmt->execute(['%' . $q . '%']);
$rows = $stmt->fetchAll();

$emojis = ['pan'=>'🍞','facturas'=>'🥐','galletas'=>'🍪','cakes'=>'🎂','otro'=>'🥖'];
$result = array_map(fn($r) => [
  'id'       => $r['id'],
  'nombre'   => $r['nombre'],
  'panaderia'=> $r['nombre_panaderia'] ?: $r['nombre_vendedor'],
  'precio'   => precio((float)$r['precio']),
  'emoji'    => $emojis[$r['categoria']] ?? '🥖',
], $rows);

echo json_encode($result, JSON_UNESCAPED_UNICODE);