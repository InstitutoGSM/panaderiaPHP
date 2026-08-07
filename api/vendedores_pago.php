<?php
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');

$ids_raw = $_GET['ids'] ?? '';
$ids = array_filter(
    array_map('intval', explode(',', $ids_raw)),
    fn($id) => $id > 0
);

if (empty($ids)) {
    echo json_encode([]);
    exit;
}

$placeholders = implode(',', array_fill(0, count($ids), '?'));

$stmt = db()->prepare("
    SELECT
        id,
        nombre,
        nombre_panaderia,
        medios_pago,
        cbu,
        alias_cbu,
        titular_cuenta
    FROM usuarios
    WHERE id IN ($placeholders)
      AND tipo = 'vendedor'
      AND estado_verificacion = 'aprobado'
");

$stmt->execute(array_values($ids));

$resultado = [];

foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $vendedor) {
    $resultado[(string)$vendedor['id']] = $vendedor;
}

echo json_encode($resultado, JSON_UNESCAPED_UNICODE);