<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';

if (!esta_logueado() || ($_SESSION['user_tipo'] ?? '') !== 'admin') {
  header('Location: ' . SITE_URL . '/admin-login.php');
  exit;
}

// ── Acciones POST ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  header('Content-Type: application/json');
  $accion = $_POST['accion'] ?? '';

  // IDs
  $vid = (int)($_POST['vendedor_id'] ?? 0);
  $uid = (int)($_POST['uid'] ?? 0);

  switch ($accion) {

    // ── Verificacion (existente) ──────────────────────────────────────
    case 'aprobar':
      if (!$vid) {
        echo json_encode(['ok' => false, 'msg' => 'ID inválido']);
        exit;
      }
      db()->prepare("UPDATE usuarios SET estado_verificacion='aprobado', doc_notas_rechazo=NULL WHERE id=? AND tipo='vendedor'")->execute([$vid]);
      echo json_encode(['ok' => true, 'msg' => 'Vendedor aprobado ✅']);
      break;

    case 'rechazar':
      if (!$vid) {
        echo json_encode(['ok' => false, 'msg' => 'ID inválido']);
        exit;
      }
      db()->prepare("UPDATE usuarios SET estado_verificacion='rechazado' WHERE id=? AND tipo='vendedor'")->execute([$vid]);
      echo json_encode(['ok' => true, 'msg' => 'Vendedor rechazado ❌']);
      break;

    case 'corregir':
      if (!$vid) {
        echo json_encode(['ok' => false, 'msg' => 'ID inválido']);
        exit;
      }
      $notas = trim($_POST['notas'] ?? '');
      if (!$notas) {
        echo json_encode(['ok' => false, 'msg' => 'Escribí un mensaje']);
        exit;
      }
      db()->prepare("UPDATE usuarios SET estado_verificacion='sin_enviar', doc_notas_rechazo=? WHERE id=? AND tipo='vendedor'")->execute([$notas, $vid]);
      echo json_encode(['ok' => true, 'msg' => 'Corrección registrada ✏️']);
      break;

    // ── Editar info de PANADERIAS ───────────────────────────────
    case 'editar_panaderia':
      if (!$uid) {
        echo json_encode(['ok' => false, 'msg' => 'ID inválido']);
        exit;
      }
      $nombre           = trim($_POST['nombre'] ?? '');
      $nombre_panaderia = trim($_POST['nombre_panaderia'] ?? '');
      $email            = trim($_POST['email'] ?? '');
      $email_contacto   = trim($_POST['email_contacto'] ?? '');
      if (!$nombre || !$email) {
        echo json_encode(['ok' => false, 'msg' => 'Nombre y email son obligatorios']);
        exit;
      }
      db()->prepare("UPDATE usuarios SET nombre=?, nombre_panaderia=?, email=?, email_contacto=? WHERE id=? AND tipo='vendedor'")
        ->execute([$nombre, $nombre_panaderia, $email, $email_contacto, $uid]);
      echo json_encode(['ok' => true, 'msg' => 'Panadería actualizada ✅']);
      break;

    // ── Cambiar contraseña de PANADERIAS ────────────────────────
    case 'cambiar_pass_panaderia':
      if (!$uid) {
        echo json_encode(['ok' => false, 'msg' => 'ID inválido']);
        exit;
      }
      $pass = trim($_POST['password'] ?? '');
      if (strlen($pass) < 6) {
        echo json_encode(['ok' => false, 'msg' => 'Mínimo 6 caracteres']);
        exit;
      }
      db()->prepare("UPDATE usuarios SET password_hash=? WHERE id=? AND tipo='vendedor'")
        ->execute([password_hash($pass, PASSWORD_DEFAULT), $uid]);
      echo json_encode(['ok' => true, 'msg' => 'Contraseña actualizada ✅']);
      break;

    // ── Toggle admin de PANADERIAS ──────────────────────────────
    case 'toggle_admin':
      if (!$uid) {
        echo json_encode(['ok' => false, 'msg' => 'ID inválido']);
        exit;
      }
      $valor = (int)($_POST['valor'] ?? 0);
      db()->prepare("UPDATE usuarios SET puede_ser_admin=? WHERE id=?")->execute([$valor, $uid]);
      echo json_encode(['ok' => true, 'msg' => $valor ? 'Habilitado como Admin de Panadería ✅' : 'Removido como Admin de Panadería ❌']);
      break;

    // ── Editar datos de USUARIO (comprador/vendedor) ───────────
    case 'editar_usuario':
      if (!$uid) {
        echo json_encode(['ok' => false, 'msg' => 'ID inválido']);
        exit;
      }
      $nombre = trim($_POST['nombre'] ?? '');
      $email  = trim($_POST['email'] ?? '');
      if (!$nombre || !$email) {
        echo json_encode(['ok' => false, 'msg' => 'Nombre y email son obligatorios']);
        exit;
      }
      db()->prepare("UPDATE usuarios SET nombre=?, email=? WHERE id=?")->execute([$nombre, $email, $uid]);
      echo json_encode(['ok' => true, 'msg' => 'Usuario actualizado ✅']);
      break;

    // ── Resetear contraseña de USUARIO ─────────────────────────
    case 'reset_password':
      if (!$uid) {
        echo json_encode(['ok' => false, 'msg' => 'ID inválido']);
        exit;
      }
      $pass = trim($_POST['password'] ?? '');
      if (strlen($pass) < 6) {
        echo json_encode(['ok' => false, 'msg' => 'Mínimo 6 caracteres']);
        exit;
      }
      db()->prepare("UPDATE usuarios SET password_hash=? WHERE id=?")->execute([password_hash($pass, PASSWORD_DEFAULT), $uid]);
      break;

    case 'aprobar_admin_pan':
      $sol_id = (int)($_POST['sol_id'] ?? 0);
      $stmt = db()->prepare("SELECT * FROM solicitudes_admin WHERE id=?");
      $stmt->execute([$sol_id]);
      $sol = $stmt->fetch();
      if ($sol) {
        db()->prepare("UPDATE solicitudes_admin SET estado='aprobado' WHERE id=?")->execute([$sol_id]);
        db()->prepare("UPDATE usuarios SET is_admin_pan=1 WHERE id=?")->execute([$sol['trabajador_id']]);
        echo json_encode(['ok' => true, 'msg' => '✅ Admin de panadería aprobado']);
      } else {
        echo json_encode(['ok' => false, 'msg' => 'Solicitud no encontrada']);
      }
      break;

    case 'rechazar_admin_pan':
      $sol_id = (int)($_POST['sol_id'] ?? 0);
      $stmt2 = db()->prepare("SELECT * FROM solicitudes_admin WHERE id=?");
      $stmt2->execute([$sol_id]);
      $sol2 = $stmt2->fetch();
      if ($sol2) {
        db()->prepare("UPDATE solicitudes_admin SET estado='rechazado' WHERE id=?")->execute([$sol_id]);
        db()->prepare("UPDATE usuarios SET is_admin_pan=0 WHERE id=?")->execute([$sol2['trabajador_id']]);
        echo json_encode(['ok' => true, 'msg' => '❌ Solicitud rechazada']);
      } else {
        echo json_encode(['ok' => false, 'msg' => 'Solicitud no encontrada']);
      }
      break;

    default:
      echo json_encode(['ok' => false, 'msg' => 'Acción desconocida']);
  }
  exit;
}

// ── Cargar datos ──────────────────────────────────────────────────────────

// Vendedores (panaderias)
$vendedores = db()->query("
    SELECT id, nombre, nombre_panaderia, email, email_contacto,
           avatar_url, estado_verificacion, puede_ser_admin, doc_notas_rechazo,
           doc_bromatologia, doc_carnet_manipulador, doc_habilitacion_comercial, created_at
    FROM usuarios WHERE tipo='vendedor'
    ORDER BY FIELD(estado_verificacion,'pendiente','sin_enviar','rechazado','aprobado'), created_at DESC
")->fetchAll();

// Solicitudes de admin pendientes
$solicitudes_admin = db()->query("
    SELECT sa.*,
           t.nombre AS trab_nombre, t.email AS trab_email,
           t.documento_id,
           v.nombre_panaderia, v.nombre AS vend_nombre
    FROM   solicitudes_admin sa
    JOIN   usuarios t ON t.id = sa.trabajador_id
    JOIN   usuarios v ON v.id = sa.vendedor_id
    ORDER  BY sa.estado ASC, sa.created_at DESC
")->fetchAll();
$pendientes_count = count(array_filter($solicitudes_admin, fn($s) => $s['estado'] === 'pendiente'));

// Sucursales agrupadas por vendedor
$sucursales_por_vendedor = [];
try {
  $suc_rows = db()->query("SELECT * FROM sucursales WHERE activo=1 ORDER BY vendedor_id, nombre")->fetchAll();
  foreach ($suc_rows as $s) {
    $sucursales_por_vendedor[$s['vendedor_id']][] = $s;
  }
} catch (Exception $e) { /* tabla aun no existe */
}

// Usuarios (compradores + vendedores para gestión)
$todos_usuarios = db()->query("
    SELECT id, nombre, email, tipo, estado_verificacion, created_at
    FROM usuarios
    WHERE tipo IN ('comprador','vendedor')
    ORDER BY tipo, nombre
")->fetchAll();

$stats = ['pendiente' => 0, 'aprobado' => 0, 'rechazado' => 0, 'sin_enviar' => 0];
foreach ($vendedores as $v) {
  $e = $v['estado_verificacion'] ?? 'sin_enviar';
  if (isset($stats[$e])) $stats[$e]++;
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Panel Admin — <?= SITE_NAME ?></title>
  <link rel="stylesheet" href="<?= SITE_URL ?>/css/global.css">
  <link rel="stylesheet" href="<?= SITE_URL ?>/css/admin.css">
  <style>
    /* ── Tabs ─────────────────────────────────────────────────────── */
    .admin-tabs {
      display: flex;
      gap: 8px;
      margin-bottom: 28px;
      border-bottom: 2px solid var(--crema-dark);
      padding-bottom: 0;
    }

    .admin-tab {
      padding: 10px 22px;
      font-weight: 700;
      font-size: 0.92rem;
      cursor: pointer;
      border: none;
      background: none;
      color: var(--gris);
      border-radius: 8px 8px 0 0;
      border-bottom: 3px solid transparent;
      transition: all .2s;
    }

    .admin-tab:hover {
      color: var(--marron);
      background: var(--crema-dark);
    }

    .admin-tab.activo {
      color: var(--marron);
      border-bottom-color: var(--naranja);
      background: var(--blanco);
    }

    .tab-panel {
      display: none;
    }

    .tab-panel.activo {
      display: block;
    }

    /* ── Tabla usuarios ───────────────────────────────────────────── */
    .tabla-admin {
      width: 100%;
      border-collapse: collapse;
      background: var(--blanco);
      border-radius: var(--radio-lg);
      overflow: hidden;
      box-shadow: var(--sombra);
    }

    .tabla-admin th {
      background: var(--marron);
      color: white;
      padding: 12px 16px;
      text-align: left;
      font-size: 0.82rem;
      font-weight: 700;
    }

    .tabla-admin td {
      padding: 12px 16px;
      border-bottom: 1px solid var(--crema-dark);
      font-size: 0.88rem;
      vertical-align: middle;
    }

    .tabla-admin tr:last-child td {
      border-bottom: none;
    }

    .tabla-admin tr:hover td {
      background: var(--crema);
    }

    /* ── Cards panaderias ─────────────────────────────────────────── */
    .pan-card {
      background: var(--blanco);
      border-radius: var(--radio-lg);
      box-shadow: var(--sombra);
      margin-bottom: 16px;
      overflow: hidden;
    }

    .pan-card-head {
      display: flex;
      align-items: center;
      gap: 14px;
      padding: 16px 20px;
      cursor: pointer;
    }

    .pan-card-head:hover {
      background: var(--crema);
    }

    .pan-info {
      flex: 1;
    }

    .pan-info h3 {
      font-family: 'Playfair Display', serif;
      font-size: 1rem;
      margin: 0 0 2px;
    }

    .pan-info p {
      font-size: 0.8rem;
      color: var(--gris);
      margin: 0;
    }

    .pan-body {
      display: none;
      border-top: 1px solid var(--crema-dark);
      padding: 16px 20px;
    }

    .pan-body.open {
      display: block;
    }

    .pan-acciones {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
      margin-top: 12px;
    }

    /* ── Toggle admin ─────────────────────────────────────────────── */
    .toggle-wrap {
      display: flex;
      align-items: center;
      gap: 10px;
      background: var(--crema);
      border-radius: 50px;
      padding: 8px 14px;
    }

    .toggle-switch {
      position: relative;
      width: 44px;
      height: 24px;
      display: inline-block;
    }

    .toggle-switch input {
      opacity: 0;
      width: 0;
      height: 0;
    }

    .toggle-slider {
      position: absolute;
      inset: 0;
      background: #ccc;
      border-radius: 50px;
      cursor: pointer;
      transition: .3s;
    }

    .toggle-slider:before {
      content: '';
      position: absolute;
      width: 18px;
      height: 18px;
      left: 3px;
      bottom: 3px;
      background: white;
      border-radius: 50%;
      transition: .3s;
    }

    input:checked+.toggle-slider {
      background: var(--naranja);
    }

    input:checked+.toggle-slider:before {
      transform: translateX(20px);
    }

    /* ── Sucursales ───────────────────────────────────────────────── */
    .suc-list {
      margin-top: 10px;
    }

    .suc-item {
      display: flex;
      align-items: center;
      gap: 8px;
      padding: 6px 10px;
      background: var(--crema);
      border-radius: 8px;
      margin-bottom: 6px;
      font-size: 0.84rem;
    }

    .suc-item-vacio {
      font-size: 0.82rem;
      color: var(--gris);
      font-style: italic;
    }

    /* ── Modales ──────────────────────────────────────────────────── */
    .modal-overlay {
      position: fixed;
      inset: 0;
      background: rgba(0, 0, 0, .5);
      z-index: 1000;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 20px;
      opacity: 1 !important;
      pointer-events: all !important;
    }

    .modal-box {
      background: var(--blanco);
      border-radius: var(--radio-lg);
      padding: 28px;
      width: 100%;
      max-width: 480px;
      box-shadow: var(--sombra-lg);
    }

    .modal-box h3 {
      font-family: 'Playfair Display', serif;
      margin-bottom: 16px;
    }

    .modal-campo {
      margin-bottom: 14px;
    }

    .modal-campo label {
      display: block;
      font-size: 0.82rem;
      font-weight: 700;
      color: var(--marron);
      margin-bottom: 4px;
    }

    .modal-campo input {
      width: 100%;
      box-sizing: border-box;
    }

    .modal-acciones {
      display: flex;
      gap: 10px;
      margin-top: 18px;
    }

    .badge-tipo {
      display: inline-block;
      padding: 2px 10px;
      border-radius: 50px;
      font-size: 0.75rem;
      font-weight: 700;
    }

    .badge-vendedor {
      background: #FFF3E0;
      color: #E65100;
    }

    .badge-comprador {
      background: #E3F2FD;
      color: #1565C0;
    }
  </style>
</head>

<body>

  <!-- ══ NAVBAR ══════════════════════════════════════════════════════════════ -->
  <nav class="admin-navbar">
    <div class="admin-navbar-brand">🥖 Panaderia<span>PUMA</span> — Admin</div>
    <div style="display:flex;align-items:center;gap:12px">
      <span style="color:rgba(255,255,255,0.6);font-size:0.82rem"><?= h($_SESSION['user_nombre'] ?? 'Admin') ?></span>
      <a href="<?= SITE_URL ?>/logout.php" class="btn btn-ghost btn-sm"
        style="border-color:rgba(255,255,255,0.3);color:white">Salir 🚪</a>
    </div>
  </nav>

  <div class="admin-wrap">

    <div style="margin-bottom:24px">
      <h1 style="font-family:'Playfair Display',serif">Panel de Administración</h1>
      <p style="color:var(--gris)">Gestión de panaderías, sucursales y usuarios</p>
    </div>

    <div class="admin-tabs">
      <button class="admin-tab activo" onclick="cambiarTab('tab-panaderias', this)">🏪 Panaderías</button>
      <button class="admin-tab" onclick="cambiarTab('tab-usuarios', this)">👥 Usuarios</button>
      <button class="admin-tab" onclick="cambiarTab('tab-verificacion', this)">
        📋 Verificación
        <?php if ($stats['pendiente'] + $stats['sin_enviar'] + $stats['rechazado'] > 0): ?>
          <span style="background:var(--naranja);color:white;border-radius:50px;padding:1px 7px;font-size:0.72rem;margin-left:4px">
            <?= $stats['pendiente'] + $stats['sin_enviar'] ?>
          </span>
        <?php endif; ?>
      </button>
      <button class="admin-tab" onclick="cambiarTab('tab-solicitudes-admin', this)">
        👑 Solicitudes Admin
        <?php if ($pendientes_count > 0): ?>
          <span style="background:#C8601A;color:white;border-radius:50px;padding:1px 8px;font-size:0.72rem;margin-left:4px">
            <?= $pendientes_count ?>
          </span>
        <?php endif; ?>
      </button>
    </div>

    <!-- ════════════════════════════════════════════════════════════════════ -->
    <!-- PANADERIAS                                                   -->
    <!-- ════════════════════════════════════════════════════════════════════ -->
    <div id="tab-panaderias" class="tab-panel activo">

      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
        <h2 style="font-family:'Playfair Display',serif;font-size:1.1rem;margin:0">
          Panaderías registradas (<?= count($vendedores) ?>)
        </h2>
      </div>

      <?php if (empty($vendedores)): ?>
        <p style="color:var(--gris);text-align:center;padding:40px">No hay panaderías registradas.</p>
      <?php else: ?>
        <?php foreach ($vendedores as $v): ?>
          <?php
          $sucursales = $sucursales_por_vendedor[$v['id']] ?? [];
          $est = $v['estado_verificacion'] ?? 'sin_enviar';
          $est_label = ['aprobado' => 'Aprobada ✅', 'rechazado' => 'Rechazada ❌', 'pendiente' => 'Pendiente ⏳', 'sin_enviar' => 'Sin documentos'][$est] ?? $est;
          $est_color = ['aprobado' => '#2E7D32', 'rechazado' => '#C62828', 'pendiente' => '#E65100', 'sin_enviar' => '#757575'][$est] ?? '#757575';
          ?>
          <div class="pan-card">
            <div class="pan-card-head" onclick="togglePan(this)">
              <?= avatar_html($v, '44px', '0.9rem') ?>
              <div class="pan-info">
                <h3><?= h($v['nombre_panaderia'] ?: $v['nombre']) ?></h3>
                <p><?= h($v['email']) ?> · <span style="color:<?= $est_color ?>;font-weight:700"><?= $est_label ?></span>
                  · <?= count($sucursales) ?> sucursal(es)</p>
              </div>
              <?php if ($v['puede_ser_admin']): ?>
                <span style="background:#E8F5E9;color:#2E7D32;font-size:0.75rem;font-weight:700;padding:3px 10px;border-radius:50px">Admin ✓</span>
              <?php endif; ?>
              <span style="color:var(--gris);font-size:1.2rem">▾</span>
            </div>

            <div class="pan-body">
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:14px">
                <div>
                  <p style="font-size:0.78rem;color:var(--gris);margin:0 0 2px">Responsable</p>
                  <p style="font-weight:600;margin:0"><?= h($v['nombre']) ?></p>
                </div>
                <div>
                  <p style="font-size:0.78rem;color:var(--gris);margin:0 0 2px">Email contacto</p>
                  <p style="font-weight:600;margin:0"><?= h($v['email_contacto'] ?: '—') ?></p>
                </div>
                <div>
                  <p style="font-size:0.78rem;color:var(--gris);margin:0 0 2px">Registrado</p>
                  <p style="font-weight:600;margin:0"><?= date('d/m/Y', strtotime($v['created_at'])) ?></p>
                </div>
              </div>

              <!-- Sucursales -->
              <p style="font-weight:700;font-size:0.85rem;margin:0 0 6px">Sucursales:</p>
              <div class="suc-list">
                <?php if (empty($sucursales)): ?>
                  <p class="suc-item-vacio">Esta panadería no tiene sucursales registradas.</p>
                <?php else: ?>
                  <?php foreach ($sucursales as $s): ?>
                    <div class="suc-item">
                      🏬 <strong><?= h($s['nombre']) ?></strong>
                      <?php if ($s['direccion']): ?> · <?= h($s['direccion']) ?><?php endif; ?>
                        <?php if ($s['telefono']): ?> · 📞 <?= h($s['telefono']) ?><?php endif; ?>
                    </div>
                  <?php endforeach; ?>
                <?php endif; ?>
              </div>

              <!-- Toggle admin de panaderia -->
              <div class="toggle-wrap" style="margin-top:14px">
                <label class="toggle-switch">
                  <input type="checkbox"
                    <?= $v['puede_ser_admin'] ? 'checked' : '' ?>
                    onchange="toggleAdmin(<?= $v['id'] ?>, this.checked)">
                  <span class="toggle-slider"></span>
                </label>
                <span style="font-weight:700;font-size:0.88rem">Habilitar como Admin de Panadería</span>
                <span style="font-size:0.78rem;color:var(--gris)">(puede gestionar su propia tienda)</span>
              </div>

              <!-- Acciones -->
              <div class="pan-acciones">
                <button class="btn btn-sm btn-editar-pan"
                  data-uid="<?= $v['id'] ?>"
                  data-nombre="<?= h($v['nombre']) ?>"
                  data-nombre-pan="<?= h($v['nombre_panaderia'] ?? '') ?>"
                  data-email="<?= h($v['email']) ?>"
                  data-email-contacto="<?= h($v['email_contacto'] ?? '') ?>">
                  ✏️ Editar info
                </button>
                <button class="btn btn-sm btn-ghost btn-cambiar-pass"
                  data-uid="<?= $v['id'] ?>"
                  data-nombre-pan="<?= h($v['nombre_panaderia'] ?: $v['nombre']) ?>">
                  🔑 Cambiar contraseña
                </button>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <!-- ════════════════════════════════════════════════════════════════════ -->
    <!-- USUARIOS                                                      -->
    <!-- ════════════════════════════════════════════════════════════════════ -->
    <div id="tab-usuarios" class="tab-panel">

      <div style="margin-bottom:16px">
        <h2 style="font-family:'Playfair Display',serif;font-size:1.1rem;margin:0 0 4px">
          Todos los usuarios (<?= count($todos_usuarios) ?>)
        </h2>
        <p style="color:var(--gris);font-size:0.83rem;margin:0">Editá datos o reseteá contraseñas de cualquier cuenta</p>
      </div>

      <div style="margin-bottom:12px">
        <input type="text" id="buscador-usuarios" placeholder="Buscar por nombre o email…"
          style="width:100%;max-width:380px;box-sizing:border-box"
          oninput="filtrarUsuarios(this.value)">
      </div>

      <table class="tabla-admin" id="tabla-usuarios">
        <thead>
          <tr>
            <th>#</th>
            <th>Nombre</th>
            <th>Email</th>
            <th>Tipo</th>
            <th>Acciones</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($todos_usuarios as $u): ?>
            <tr data-nombre="<?= strtolower(h($u['nombre'])) ?>" data-email="<?= strtolower(h($u['email'])) ?>">
              <td style="color:var(--gris);font-size:0.78rem"><?= $u['id'] ?></td>
              <td><strong><?= h($u['nombre']) ?></strong></td>
              <td><?= h($u['email']) ?></td>
              <td>
                <span class="badge-tipo badge-<?= $u['tipo'] ?>">
                  <?= $u['tipo'] === 'vendedor' ? '🏪 Vendedor' : '🛒 Comprador' ?>
                </span>
              </td>
              <td>
                <button class="btn btn-sm btn-editar-user" style="margin-right:6px"
                  data-uid="<?= $u['id'] ?>"
                  data-nombre="<?= h($u['nombre']) ?>"
                  data-email="<?= h($u['email']) ?>">
                  ✏️ Editar
                </button>
                <button class="btn btn-sm btn-ghost btn-reset-pass"
                  data-uid="<?= $u['id'] ?>"
                  data-nombre="<?= h($u['nombre']) ?>">
                  🔑 Resetear pass
                </button>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- ════════════════════════════════════════════════════════════════════ -->
    <!-- VERIFICACION DE PANADERIAS                                                   -->
    <!-- ════════════════════════════════════════════════════════════════════ -->
    <div id="tab-verificacion" class="tab-panel">

      <!-- Stats -->
      <div class="admin-stats" style="margin-bottom:28px">
        <div class="admin-stat">
          <div class="num" id="st-pendientes"><?= $stats['pendiente'] ?></div>
          <div class="lbl">Pendientes</div>
        </div>
        <div class="admin-stat">
          <div class="num" id="st-aprobados"><?= $stats['aprobado'] ?></div>
          <div class="lbl">Aprobados</div>
        </div>
        <div class="admin-stat">
          <div class="num" id="st-rechazados"><?= $stats['rechazado'] ?></div>
          <div class="lbl">Rechazados</div>
        </div>
        <div class="admin-stat">
          <div class="num" id="st-sin-enviar"><?= $stats['sin_enviar'] ?></div>
          <div class="lbl">Sin documentos</div>
        </div>
      </div>

      <!-- Listado de vendedores para verificacion -->
      <div id="lista-vendedores-verif">
        <?php foreach ($vendedores as $v): ?>
          <?php
          $est = $v['estado_verificacion'] ?? 'sin_enviar';
          $docs = [
            'Bromatología'        => $v['doc_bromatologia'],
            'Carnet Manipulador'  => $v['doc_carnet_manipulador'],
            'Habilitación'        => $v['doc_habilitacion_comercial'],
          ];
          ?>
          <div class="vendedor-card" data-id="<?= $v['id'] ?>" data-estado="<?= $est ?>">
            <div class="vendedor-head">
              <?= avatar_html($v, '48px', '1rem') ?>
              <div class="vendedor-info">
                <h2 class="vendedor-nombre"><?= h($v['nombre_panaderia'] ?: $v['nombre']) ?></h2>
                <p class="vendedor-email"><?= h($v['email']) ?></p>
              </div>
              <span class="estado-badge-admin estado-<?= $est ?>">
                <?= ['aprobado' => 'Aprobado', 'rechazado' => 'Rechazado', 'pendiente' => 'Pendiente', 'sin_enviar' => 'Sin documentos'][$est] ?? $est ?>
              </span>
            </div>

            <?php if ($v['doc_notas_rechazo']): ?>
              <div style="padding:8px 20px;background:#FFF3E0;font-size:0.82rem;color:#E65100">
                📝 Nota: <?= h($v['doc_notas_rechazo']) ?>
              </div>
            <?php endif; ?>

            <div class="vendedor-docs">
              <?php foreach ($docs as $label => $url): ?>
                <div class="doc-item">
                  <p class="doc-label"><?= $label ?></p>
                  <?php if ($url): ?>
                    <a href="<?= h($url) ?>" target="_blank" class="ver-doc">Ver documento ↗</a>
                  <?php else: ?>
                    <span class="doc-vacio">No enviado</span>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>

            <div class="vendedor-acciones">
              <?php if ($est !== 'aprobado'): ?>
                <button class="btn btn-sm btn-verde" onclick="accionVendedor('aprobar', <?= $v['id'] ?>)">✅ Aprobar</button>
              <?php endif; ?>
              <?php if ($est !== 'rechazado'): ?>
                <button class="btn btn-sm" style="background:#FFEBEE;color:#C62828;border:none;font-weight:700"
                  onclick="accionVendedor('rechazar', <?= $v['id'] ?>)">❌ Rechazar</button>
              <?php endif; ?>
              <button class="btn btn-sm btn-ghost" onclick="abrirCorreccion(<?= $v['id'] ?>)">✏️ Pedir corrección</button>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div id="tab-solicitudes-admin" class="tab-panel">
      <h3 style="margin-bottom:18px">👑 Solicitudes de Admin de Panadería</h3>
      <?php if (empty($solicitudes_admin)): ?>
        <p style="color:var(--gris);text-align:center;padding:40px">No hay solicitudes aún.</p>
      <?php else: ?>
        <div style="display:grid;gap:14px">
          <?php foreach ($solicitudes_admin as $s): ?>
            <?php
            $bc = match ($s['estado']) {
              'pendiente' => 'background:#FFF8E1;color:#F57F17',
              'aprobado'  => 'background:#E8F5E9;color:#2E7D32',
              'rechazado' => 'background:#FFEBEE;color:#C62828',
            };
            $bt = match ($s['estado']) {
              'pendiente' => '⏳ Pendiente',
              'aprobado'  => '✅ Aprobado',
              'rechazado' => '❌ Rechazado',
            };
            ?>
            <div style="background:var(--blanco);border-radius:var(--radio-lg);box-shadow:var(--sombra);
                        padding:18px 22px;display:flex;align-items:center;gap:16px;flex-wrap:wrap">
              <div style="flex:1;min-width:200px">
                <p style="font-weight:700;font-size:1rem;margin:0 0 3px">
                  <?= h($s['trab_nombre']) ?>
                  <span style="font-size:0.8rem;color:var(--gris);font-weight:400">
                    · <?= h($s['trab_email']) ?>
                    <?= $s['documento_id'] ? ' · DNI: ' . h($s['documento_id']) : '' ?>
                  </span>
                </p>
                <p style="color:var(--gris);font-size:0.83rem;margin:0">
                  Panadería: <strong><?= h($s['nombre_panaderia'] ?: $s['vend_nombre']) ?></strong>
                </p>
                <p style="font-size:0.76rem;color:var(--gris);margin:4px 0 0">
                  Solicitado: <?= date('d/m/Y H:i', strtotime($s['created_at'])) ?>
                </p>
              </div>
              <span style="padding:5px 14px;border-radius:50px;font-size:0.78rem;font-weight:700;<?= $bc ?>">
                <?= $bt ?>
              </span>
              <?php if ($s['estado'] === 'pendiente'): ?>
                <div style="display:flex;gap:8px">
                  <button onclick="resolverAdmin(<?= $s['id'] ?>, 'aprobar_admin_pan')"
                    style="padding:8px 16px;background:#E8F5E9;color:#2E7D32;
                                 border:none;border-radius:50px;font-weight:700;cursor:pointer">
                    ✅ Aprobar
                  </button>
                  <button onclick="resolverAdmin(<?= $s['id'] ?>, 'rechazar_admin_pan')"
                    style="padding:8px 16px;background:#FFEBEE;color:#C62828;
                                 border:none;border-radius:50px;font-weight:700;cursor:pointer">
                    ❌ Rechazar
                  </button>
                </div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

  </div>

  <!-- ════════════════════════════════════════════════════════════════════════ -->
  <!-- MODALES                                                                  -->
  <!-- ════════════════════════════════════════════════════════════════════════ -->

  <!-- Editar PANADERIA -->
  <div id="modal-editar-pan" class="modal-overlay" style="display:none" onclick="cerrarModal('modal-editar-pan', event)">
    <div class="modal-box" onclick="event.stopPropagation()">
      <h3>✏️ Editar Panadería</h3>
      <input type="hidden" id="ep-uid">
      <div class="modal-campo">
        <label>Nombre del responsable</label>
        <input type="text" id="ep-nombre" placeholder="Nombre completo">
      </div>
      <div class="modal-campo">
        <label>Nombre de la panadería</label>
        <input type="text" id="ep-nombre-pan" placeholder="Nombre comercial">
      </div>
      <div class="modal-campo">
        <label>Email de acceso (login)</label>
        <input type="email" id="ep-email" placeholder="email@ejemplo.com">
      </div>
      <div class="modal-campo">
        <label>Email de contacto público</label>
        <input type="email" id="ep-email-contacto" placeholder="contacto@panaderia.com">
      </div>
      <div class="modal-acciones">
        <button class="btn" onclick="guardarEditarPan()">💾 Guardar cambios</button>
        <button class="btn btn-ghost" onclick="cerrarModal('modal-editar-pan')">Cancelar</button>
      </div>
    </div>
  </div>

  <!-- Cambiar contraseña PANADERIA -->
  <div id="modal-pass-pan" class="modal-overlay" style="display:none" onclick="cerrarModal('modal-pass-pan', event)">
    <div class="modal-box" onclick="event.stopPropagation()">
      <h3>🔑 Cambiar Contraseña</h3>
      <p id="pp-nombre-pan" style="color:var(--gris);margin-bottom:14px;font-size:0.88rem"></p>
      <input type="hidden" id="pp-uid">
      <div class="modal-campo">
        <label>Nueva contraseña (mín. 6 caracteres)</label>
        <input type="password" id="pp-pass" placeholder="Nueva contraseña">
      </div>
      <div class="modal-acciones">
        <button class="btn" onclick="guardarPassPan()">🔑 Cambiar contraseña</button>
        <button class="btn btn-ghost" onclick="cerrarModal('modal-pass-pan')">Cancelar</button>
      </div>
    </div>
  </div>

  <!-- Editar usuario -->
  <div id="modal-editar-usuario" class="modal-overlay" style="display:none" onclick="cerrarModal('modal-editar-usuario', event)">
    <div class="modal-box" onclick="event.stopPropagation()">
      <h3>✏️ Editar Usuario</h3>
      <input type="hidden" id="eu-uid">
      <div class="modal-campo">
        <label>Nombre</label>
        <input type="text" id="eu-nombre" placeholder="Nombre completo">
      </div>
      <div class="modal-campo">
        <label>Email</label>
        <input type="email" id="eu-email" placeholder="email@ejemplo.com">
      </div>
      <div class="modal-acciones">
        <button class="btn" onclick="guardarEditarUsuario()">💾 Guardar cambios</button>
        <button class="btn btn-ghost" onclick="cerrarModal('modal-editar-usuario')">Cancelar</button>
      </div>
    </div>
  </div>

  <!-- Resetear contraseña usuario -->
  <div id="modal-reset-pass" class="modal-overlay" style="display:none" onclick="cerrarModal('modal-reset-pass', event)">
    <div class="modal-box" onclick="event.stopPropagation()">
      <h3>🔑 Resetear Contraseña</h3>
      <p id="rp-nombre" style="color:var(--gris);margin-bottom:14px;font-size:0.88rem"></p>
      <input type="hidden" id="rp-uid">
      <div class="modal-campo">
        <label>Nueva contraseña (mín. 6 caracteres)</label>
        <input type="password" id="rp-pass" placeholder="Nueva contraseña">
      </div>
      <div class="modal-acciones">
        <button class="btn" onclick="guardarResetPass()">🔑 Resetear</button>
        <button class="btn btn-ghost" onclick="cerrarModal('modal-reset-pass')">Cancelar</button>
      </div>
    </div>
  </div>

  <div id="modal-correccion" class="modal-overlay" style="display:none" onclick="cerrarModal('modal-correccion', event)">
    <div class="modal-box" onclick="event.stopPropagation()">
      <h3>✏️ Pedir corrección</h3>
      <input type="hidden" id="corr-vid">
      <div class="modal-campo">
        <label>Mensaje para el vendedor</label>
        <textarea id="corr-notas" class="form-control" style="width:100%;min-height:100px;box-sizing:border-box"
          placeholder="Explicá qué debe corregir..."></textarea>
      </div>
      <div class="modal-acciones">
        <button class="btn" onclick="guardarCorreccion()">Enviar corrección</button>
        <button class="btn btn-ghost" onclick="cerrarModal('modal-correccion')">Cancelar</button>
      </div>
    </div>
  </div>

  <div id="toast-box" style="position:fixed;bottom:24px;right:24px;z-index:9999;display:flex;flex-direction:column;gap:8px"></div>

  <script>
    const SITE_URL = '<?= SITE_URL ?>';

    /* ══ TABS ══════════════════════════════════════════════════════════════════ */
    function cambiarTab(id, btn) {
      document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('activo'));
      document.querySelectorAll('.admin-tab').forEach(b => b.classList.remove('activo'));
      document.getElementById(id).classList.add('activo');
      btn.classList.add('activo');
    }

    /* ══ ACORDEON PANADERIAS ═══════════════════════════════════════════════════ */
    function togglePan(head) {
      const body = head.nextElementSibling;
      body.classList.toggle('open');
    }

    /* ══ TOGGLE ADMIN ══════════════════════════════════════════════════════════ */
    async function toggleAdmin(uid, habilitado) {
      const ok = await postAdmin({
        accion: 'toggle_admin',
        uid,
        valor: habilitado ? 1 : 0
      });
      if (!ok) {
        // Revertir si falló
        event.target.checked = !habilitado;
      }
    }

    /* ══ ABRIR MODALES ═════════════════════════════════════════════════════════ */
    function abrirEditarPan(uid, nombre, nombrePan, email, emailContacto) {
      document.getElementById('ep-uid').value = uid;
      document.getElementById('ep-nombre').value = nombre;
      document.getElementById('ep-nombre-pan').value = nombrePan;
      document.getElementById('ep-email').value = email;
      document.getElementById('ep-email-contacto').value = emailContacto;
      document.getElementById('modal-editar-pan').style.display = 'flex';
    }

    function abrirCambiarPass(uid, nombrePan) {
      document.getElementById('pp-uid').value = uid;
      document.getElementById('pp-nombre-pan').textContent = 'Panadería: ' + nombrePan;
      document.getElementById('pp-pass').value = '';
      document.getElementById('modal-pass-pan').style.display = 'flex';
    }

    function abrirEditarUsuario(uid, nombre, email) {
      document.getElementById('eu-uid').value = uid;
      document.getElementById('eu-nombre').value = nombre;
      document.getElementById('eu-email').value = email;
      document.getElementById('modal-editar-usuario').style.display = 'flex';
    }

    function abrirResetPass(uid, nombre) {
      document.getElementById('rp-uid').value = uid;
      document.getElementById('rp-nombre').textContent = 'Usuario: ' + nombre;
      document.getElementById('rp-pass').value = '';
      document.getElementById('modal-reset-pass').style.display = 'flex';
    }

    function abrirCorreccion(vid) {
      document.getElementById('corr-vid').value = vid;
      document.getElementById('corr-notas').value = '';
      document.getElementById('modal-correccion').style.display = 'flex';
    }

    function cerrarModal(id, event) {
      if (event && event.target !== document.getElementById(id)) return;
      document.getElementById(id).style.display = 'none';
    }

    /* ══ GUARDAR MODALES ═══════════════════════════════════════════════════════ */
    async function guardarEditarPan() {
      const ok = await postAdmin({
        accion: 'editar_panaderia',
        uid: document.getElementById('ep-uid').value,
        nombre: document.getElementById('ep-nombre').value,
        nombre_panaderia: document.getElementById('ep-nombre-pan').value,
        email: document.getElementById('ep-email').value,
        email_contacto: document.getElementById('ep-email-contacto').value,
      });
      if (ok) {
        cerrarModal('modal-editar-pan');
        setTimeout(() => location.reload(), 1200);
      }
    }

    async function guardarPassPan() {
      const ok = await postAdmin({
        accion: 'cambiar_pass_panaderia',
        uid: document.getElementById('pp-uid').value,
        password: document.getElementById('pp-pass').value,
      });
      if (ok) cerrarModal('modal-pass-pan');
    }

    async function guardarEditarUsuario() {
      const ok = await postAdmin({
        accion: 'editar_usuario',
        uid: document.getElementById('eu-uid').value,
        nombre: document.getElementById('eu-nombre').value,
        email: document.getElementById('eu-email').value,
      });
      if (ok) {
        cerrarModal('modal-editar-usuario');
        setTimeout(() => location.reload(), 1200);
      }
    }

    async function guardarResetPass() {
      const ok = await postAdmin({
        accion: 'reset_password',
        uid: document.getElementById('rp-uid').value,
        password: document.getElementById('rp-pass').value,
      });
      if (ok) cerrarModal('modal-reset-pass');
    }

    async function guardarCorreccion() {
      const ok = await postAdmin({
        accion: 'corregir',
        vendedor_id: document.getElementById('corr-vid').value,
        notas: document.getElementById('corr-notas').value,
      });
      if (ok) {
        cerrarModal('modal-correccion');
        accionVendedor._updateCard('corregir', parseInt(document.getElementById('corr-vid').value));
      }
    }

    /* ══ VERIFICACION ═══════════════════════════════════════════════ */
    async function accionVendedor(accion, vid) {
      if (accion === 'corregir') {
        abrirCorreccion(vid);
        return;
      }
      if (accion === 'rechazar' && !confirm('¿Rechazar este vendedor?')) return;

      const ok = await postAdmin({
        accion,
        vendedor_id: vid
      });
      if (ok) {
        const card = document.querySelector(`.vendedor-card[data-id="${vid}"]`);
        if (card) {
          const nuevoEstado = accion === 'aprobar' ? 'aprobado' : accion === 'rechazar' ? 'rechazado' : 'sin_enviar';
          card.dataset.estado = nuevoEstado;
          const badge = card.querySelector('.estado-badge-admin');
          if (badge) {
            badge.className = `estado-badge-admin estado-${nuevoEstado}`;
            badge.textContent = {
              aprobado: 'Aprobado',
              rechazado: 'Rechazado',
              sin_enviar: 'Sin documentos'
            } [nuevoEstado];
          }
          actualizarStats();
        }
      }
    }

    function actualizarStats() {
      const cards = document.querySelectorAll('.vendedor-card');
      const c = {
        pendiente: 0,
        aprobado: 0,
        rechazado: 0,
        sin_enviar: 0
      };
      cards.forEach(card => {
        const e = card.dataset.estado;
        if (c[e] !== undefined) c[e]++;
      });
      document.getElementById('st-pendientes').textContent = c.pendiente;
      document.getElementById('st-aprobados').textContent = c.aprobado;
      document.getElementById('st-rechazados').textContent = c.rechazado;
      document.getElementById('st-sin-enviar').textContent = c.sin_enviar;
    }

    /* ══ BUSCADOR USUARIOS ═════════════════════════════════════════════════════ */
    function filtrarUsuarios(q) {
      const term = q.toLowerCase();
      document.querySelectorAll('#tabla-usuarios tbody tr').forEach(tr => {
        const nombre = tr.dataset.nombre || '';
        const email = tr.dataset.email || '';
        tr.style.display = (!term || nombre.includes(term) || email.includes(term)) ? '' : 'none';
      });
    }

    /* ══ POST HELPER ═══════════════════════════════════════════════════════════ */
    async function postAdmin(datos) {
      try {
        const fd = new FormData();
        for (const [k, v] of Object.entries(datos)) fd.append(k, v);
        const res = await fetch(SITE_URL + '/admin.php', {
          method: 'POST',
          body: fd
        });
        const data = await res.json();
        toast(data.msg || (data.ok ? 'Listo' : 'Error'), data.ok ? 'ok' : 'err');
        return data.ok;
      } catch {
        toast('Error de conexión', 'err');
        return false;
      }
    }

    /* ══ TOAST ═════════════════════════════════════════════════════════════════ */
    function toast(msg, tipo = 'ok') {
      const box = document.getElementById('toast-box');
      const t = document.createElement('div');
      t.className = `toast toast-${tipo === 'ok' ? 'ok' : tipo === 'err' ? 'err' : 'inf'}`;
      t.textContent = msg;
      box.appendChild(t);
      setTimeout(() => t.classList.add('show'), 10);
      setTimeout(() => {
        t.classList.remove('show');
        setTimeout(() => t.remove(), 300);
      }, 3500);
    }

    /* ══ CLICK HANDLERS CON DATA ATTRIBUTES ═══════════════════════════════════ */
    document.addEventListener('click', function(e) {

      // Editar PANADERIAS
      const btnPan = e.target.closest('.btn-editar-pan');
      if (btnPan) {
        abrirEditarPan(
          btnPan.dataset.uid,
          btnPan.dataset.nombre,
          btnPan.dataset.nombrePan,
          btnPan.dataset.email,
          btnPan.dataset.emailContacto
        );
        return;
      }

      // Cambiar contraseña PANADERIAS
      const btnPass = e.target.closest('.btn-cambiar-pass');
      if (btnPass) {
        abrirCambiarPass(btnPass.dataset.uid, btnPass.dataset.nombrePan);
        return;
      }

      // Editar USUARIOS
      const btnUser = e.target.closest('.btn-editar-user');
      if (btnUser) {
        abrirEditarUsuario(btnUser.dataset.uid, btnUser.dataset.nombre, btnUser.dataset.email);
        return;
      }

      // Resetear contraseña USUARIOS
      const btnReset = e.target.closest('.btn-reset-pass');
      if (btnReset) {
        abrirResetPass(btnReset.dataset.uid, btnReset.dataset.nombre);
        return;
      }

    });

    function resolverAdmin(solId, accion) {
      const msg = accion === 'aprobar_admin_pan' ? '¿Aprobar este trabajador como admin de la panadería?' : '¿Rechazar esta solicitud?';
      if (!confirm(msg)) return;
      fetch('admin.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
          },
          body: `accion=${accion}&sol_id=${solId}`
        })
        .then(r => r.json())
        .then(d => {
          toast(d.msg, d.ok ? 'ok' : 'err');
          if (d.ok) setTimeout(() => location.reload(), 1200);
        });
    }
  </script>
</body>

</html>