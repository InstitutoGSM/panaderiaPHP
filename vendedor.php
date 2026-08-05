<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';
requerir_vendedor();

$u        = usuario_actual();
$uid      = $u['id'];
$tipo_suc = $u['tipo_sucursal'] ?? null; // 'padre', 'hija', o null (sin clasificar)

$seccion = $_GET['sec'] ?? 'inicio';
$msg_ok  = '';
$msg_err = '';

/* ══════════════════════════════════════════════════════════════════════════
   POST HANDLERS
══════════════════════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $accion = $_POST['accion'] ?? '';

  /* ── Agregar producto ─────────────────────────────────────────────── */
  if ($accion === 'add_producto') {
    $nombre  = trim($_POST['nombre']   ?? '');
    $desc    = trim($_POST['desc']     ?? '');
    $precio  = (float)($_POST['precio'] ?? 0);
    $cat     = $_POST['cat']           ?? 'pan';
    $unidad  = $_POST['unidad']        ?? 'unidad';
    $med_doc = $unidad === 'kilo' ? null : (($_POST['media_doc'] ?? '') !== '' ? (float)$_POST['media_doc'] : null);
    $docena  = $unidad === 'kilo' ? null : (($_POST['docena']    ?? '') !== '' ? (float)$_POST['docena']    : null);
    $stock   = (int)($_POST['stock']   ?? 0);
    $extra   = trim($_POST['extra']    ?? '') ?: null;

    if (!$nombre || $precio <= 0) {
      $msg_err = 'Completá nombre y precio.';
      $seccion = 'add';
    } else {
      $img_url = null;
      if (!empty($_FILES['imagen']['name'])) {
        $img_url = subir_imagen($_FILES['imagen'], 'prod');
        if (!$img_url) {
          $msg_err = 'Error al subir imagen (máx 5MB, jpg/png/webp).';
          $seccion = 'add';
        }
      }
      if (!$msg_err) {
        db()->prepare("
                    INSERT INTO productos
                      (vendedor_id, nombre, descripcion, precio, categoria,
                       unidad_venta, precio_media_docena, precio_docena,
                       cantidad_disponible, dato_extra, imagen_url)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?)
                ")->execute([$uid, $nombre, $desc, $precio, $cat, $unidad, $med_doc, $docena, $stock, $extra, $img_url]);
        $msg_ok  = '¡Producto publicado! 🎉';
        $seccion = 'productos';
      }
    }
  }

  /* ── Editar producto ──────────────────────────────────────────────── */
  if ($accion === 'edit_producto') {
    $pid     = (int)($_POST['pid']     ?? 0);
    $nombre  = trim($_POST['nombre']   ?? '');
    $desc    = trim($_POST['desc']     ?? '');
    $precio  = (float)($_POST['precio'] ?? 0);
    $cat     = $_POST['cat']           ?? 'pan';
    $unidad  = $_POST['unidad']        ?? 'unidad';
    $med_doc = $unidad === 'kilo' ? null : (($_POST['media_doc'] ?? '') !== '' ? (float)$_POST['media_doc'] : null);
    $docena  = $unidad === 'kilo' ? null : (($_POST['docena']    ?? '') !== '' ? (float)$_POST['docena']    : null);
    $stock   = (int)($_POST['stock']   ?? 0);
    $extra   = trim($_POST['extra']    ?? '') ?: null;

    $chk = db()->prepare("SELECT id FROM productos WHERE id=? AND vendedor_id=?");
    $chk->execute([$pid, $uid]);
    if (!$chk->fetch()) {
      $msg_err = 'Producto no encontrado.';
      $seccion = 'productos';
    } elseif (!$nombre || $precio <= 0) {
      $msg_err = 'Completá nombre y precio.';
      $seccion = 'add';
    } else {
      $img_url = null;
      if (!empty($_FILES['imagen']['name'])) $img_url = subir_imagen($_FILES['imagen'], 'prod');
      if ($img_url) {
        db()->prepare("UPDATE productos SET nombre=?,descripcion=?,precio=?,categoria=?,
                    unidad_venta=?,precio_media_docena=?,precio_docena=?,
                    cantidad_disponible=?,dato_extra=?,imagen_url=? WHERE id=? AND vendedor_id=?")
          ->execute([$nombre, $desc, $precio, $cat, $unidad, $med_doc, $docena, $stock, $extra, $img_url, $pid, $uid]);
      } else {
        db()->prepare("UPDATE productos SET nombre=?,descripcion=?,precio=?,categoria=?,
                    unidad_venta=?,precio_media_docena=?,precio_docena=?,
                    cantidad_disponible=?,dato_extra=? WHERE id=? AND vendedor_id=?")
          ->execute([$nombre, $desc, $precio, $cat, $unidad, $med_doc, $docena, $stock, $extra, $pid, $uid]);
      }
      $msg_ok  = 'Producto actualizado ✅';
      $seccion = 'productos';
    }
  }

  /* ── Toggle activo ────────────────────────────────────────────────── */
  if ($accion === 'toggle') {
    $pid = (int)($_POST['pid'] ?? 0);
    db()->prepare("UPDATE productos SET activo = NOT activo WHERE id=? AND vendedor_id=?")->execute([$pid, $uid]);
    header('Location: vendedor.php?sec=productos');
    exit;
  }

  /* ── Eliminar producto ────────────────────────────────────────────── */
  if ($accion === 'delete') {
    $pid = (int)($_POST['pid'] ?? 0);
    db()->prepare("DELETE FROM productos WHERE id=? AND vendedor_id=?")->execute([$pid, $uid]);
    header('Location: vendedor.php?sec=productos&ok=eliminado');
    exit;
  }

  /* ── Estado pedido ────────────────────────────────────────────────── */
  if ($accion === 'estado_pedido') {
    $pid    = (int)($_POST['pedido_id'] ?? 0);
    $estado = $_POST['estado'] ?? '';
    if (in_array($estado, ['pendiente', 'confirmado', 'listo', 'entregado'])) {
      db()->prepare("UPDATE pedidos SET estado=? WHERE id=? AND vendedor_id=?")->execute([$estado, $pid, $uid]);
    }
    header('Location: vendedor.php?sec=pedidos');
    exit;
  }

  /* ── Solo Encargados clasificados pueden gestionar trabajadores */
  if (!$tipo_suc && in_array($accion, ['set_identificador','crear_trabajador','eliminar_trabajador'])) {
    $msg_err = 'Tu cuenta aún no tiene un rol asignado. Contactá al Administrador.';
    $seccion = 'inicio';
  }

  /* ── Set identificador ────────────────────────────────────────────── */
  if ($accion === 'set_identificador' && $u['is_admin_pan']) {
    $ident = preg_replace('/[^a-zA-Z0-9_]/', '', trim($_POST['identificador'] ?? ''));
    if (!$ident) {
      $msg_err = 'Identificador inválido.';
      $seccion = 'trabajadores';
    } else {
      try {
        db()->prepare("UPDATE usuarios SET identificador=? WHERE id=?")->execute([$ident, $uid]);
        $u = usuario_actual();
        $msg_ok = '¡Identificador @' . $ident . ' configurado!';
      } catch (Exception $e) {
        $msg_err = 'Ese identificador ya está en uso, elegí otro.';
      }
      $seccion = 'trabajadores';
    }
  }

  /* ── Crear trabajador ─────────────────────────────────────────────── */
  if ($accion === 'crear_trabajador') {
    $nombre    = trim($_POST['nombre']       ?? '');
    $ident_t   = preg_replace('/[^a-zA-Z0-9_]/', '', trim($_POST['identificador'] ?? ''));
    $documento = trim($_POST['documento_id'] ?? '');
    $email_t   = trim($_POST['email']        ?? '');
    $pass_t    = trim($_POST['password']     ?? '');
    if (!$nombre || !$ident_t || !$documento || !$email_t || strlen($pass_t) < 6) {
      $msg_err = 'Todos los campos son obligatorios (contraseña mínimo 6 caracteres).';
      $seccion = 'trabajadores';
    } else {
      $avatar_t = null;
      if (!empty($_FILES['avatar']['name'])) $avatar_t = subir_imagen($_FILES['avatar'], 'avatar');
      try {
        db()->prepare("
                    INSERT INTO usuarios (nombre, identificador, documento_id, email, password_hash, tipo, panaderia_id, avatar_url, estado_verificacion)
                    VALUES (?,?,?,?,?, 'trabajador', ?,?, 'aprobado')
                ")->execute([$nombre, $ident_t, $documento, $email_t, password_hash($pass_t, PASSWORD_DEFAULT), $uid, $avatar_t]);
        $msg_ok = '¡Trabajador ' . $nombre . ' creado! ✅';
      } catch (Exception $e) {
        $msg = $e->getMessage();
        if (str_contains($msg, 'email') || str_contains($msg, 'Duplicate') && str_contains($msg, 'email')) {
          $msg_err = 'Ese email ya está registrado en el sistema (puede ser una cuenta compradora). Usá otro.';
        } elseif (str_contains($msg, 'identificador')) {
          $msg_err = 'Ese @identificador ya lo usa otra cuenta. Elegí uno diferente.';
        } else {
          $msg_err = 'Error al crear trabajador. Detalle: ' . $e->getMessage();
        }
      }
      $seccion = 'trabajadores';
    }
  }

  /* ── Eliminar trabajador ──────────────────────────────────────────── */
  if ($accion === 'eliminar_trabajador') {
    $trab_id = (int)($_POST['trab_id'] ?? 0);
    db()->prepare("DELETE FROM usuarios WHERE id=? AND tipo='trabajador' AND panaderia_id=?")->execute([$trab_id, $uid]);
    $msg_ok  = 'Trabajador eliminado.';
    $seccion = 'trabajadores';
  }
  
  /* ── Guardar perfil ───────────────────────────────────────────────── */
  if ($accion === 'perfil') {
    $nombre_pan = trim($_POST['nombre_panaderia'] ?? '');
    $nombre     = trim($_POST['nombre']           ?? '');
    $desc       = trim($_POST['descripcion']      ?? '');
    $instagram  = trim($_POST['instagram']        ?? '');
    $telefono   = trim($_POST['telefono']         ?? '');
    $email_c    = trim($_POST['email_contacto']   ?? '');
    $banner     = trim($_POST['banner']           ?? '') ?: null;
    $cbu        = trim($_POST['cbu']              ?? '');
    $alias      = trim($_POST['alias_cbu']        ?? '');
    $titular    = trim($_POST['titular_cuenta']   ?? '');

    $medios = ['efectivo'];
    if (!empty($_POST['medio_transferencia'])) $medios[] = 'transferencia';
    if (!empty($_POST['medio_debito']))         $medios[] = 'debito';
    if (!empty($_POST['medio_credito']))        $medios[] = 'credito';

    if (in_array('transferencia', $medios) && !$cbu && !$alias) {
      $msg_err = 'Para aceptar transferencias ingresá al menos el CBU o el alias.';
      $seccion = 'perfil';
    } else {
      $medios_str = implode(',', $medios);
      $avatar_url = $u['avatar_url'] ?? null;
      if (!empty($_FILES['avatar']['name'])) {
        $nueva = subir_imagen($_FILES['avatar'], 'avatar');
        if ($nueva) $avatar_url = $nueva;
      }
      db()->prepare("UPDATE usuarios SET
                nombre=?, nombre_panaderia=?, descripcion=?, banner_anuncio=?,
                instagram=?, telefono=?, email_contacto=?,
                cbu=?, alias_cbu=?, titular_cuenta=?,
                medios_pago=?, avatar_url=?
              WHERE id=?")
        ->execute([
          $nombre,
          $nombre_pan,
          $desc,
          $banner,
          $instagram,
          $telefono,
          $email_c,
          $cbu,
          $alias,
          $titular,
          $medios_str,
          $avatar_url,
          $uid
        ]);
      $u       = usuario_actual();
      $msg_ok  = 'Perfil actualizado ✅';
      $seccion = 'perfil';
    }
  }
}

/* ══════════════════════════════════════════════════════════════════════════
   DATOS
══════════════════════════════════════════════════════════════════════════ */
$st_q = db()->prepare("SELECT
  (SELECT COUNT(*) FROM productos WHERE vendedor_id=? AND activo=1)                    AS activos,
  (SELECT COUNT(*) FROM productos WHERE vendedor_id=?)                                  AS total_prods,
  (SELECT COUNT(*) FROM pedidos   WHERE vendedor_id=?)                                  AS total_pedidos,
  (SELECT COUNT(*) FROM pedidos   WHERE vendedor_id=? AND estado='pendiente')           AS pend,
  (SELECT COALESCE(SUM(total),0)  FROM pedidos WHERE vendedor_id=? AND estado='entregado') AS ingresos
");
$st_q->execute([$uid, $uid, $uid, $uid, $uid]);
$st = $st_q->fetch();

$prods_q = db()->prepare("SELECT * FROM productos WHERE vendedor_id=? ORDER BY created_at DESC");
$prods_q->execute([$uid]);
$productos = $prods_q->fetchAll();

$peds_q = db()->prepare("
    SELECT p.*, u.nombre AS nombre_comprador
    FROM pedidos p JOIN usuarios u ON u.id = p.comprador_id
    WHERE p.vendedor_id=? ORDER BY p.created_at DESC LIMIT 60
");
$peds_q->execute([$uid]);
$pedidos = $peds_q->fetchAll();

$pedidos_items = [];
if (!empty($pedidos)) {
  $pids    = implode(',', array_column($pedidos, 'id'));
  $items_q = db()->query("SELECT * FROM pedido_items WHERE pedido_id IN ($pids)");
  foreach ($items_q->fetchAll() as $it) $pedidos_items[$it['pedido_id']][] = $it;
}

$edit_prod = null;
if (!empty($_GET['edit'])) {
  $ep = db()->prepare("SELECT * FROM productos WHERE id=? AND vendedor_id=?");
  $ep->execute([(int)$_GET['edit'], $uid]);
  $edit_prod = $ep->fetch() ?: null;
  if ($edit_prod) $seccion = 'add';
}

$medios_actuales = array_filter(explode(',', $u['medios_pago'] ?? 'efectivo'));
$tiene_transf    = in_array('transferencia', $medios_actuales);

// ── Trabajadores de esta panadería ────────────────────────────────────────
$trabajadores = db()->query("
    SELECT id, nombre, email, identificador, documento_id, avatar_url, created_at
    FROM usuarios
    WHERE tipo = 'trabajador' AND panaderia_id = $uid
    ORDER BY nombre
")->fetchAll();

// ── Sucursales Hija de este Padre ─────────────────────────────────────────
$mis_hijas = [];
if ($tipo_suc === 'padre') {
  $hijas_stmt = db()->prepare("
      SELECT id, nombre, nombre_panaderia, email, avatar_url, estado_verificacion
      FROM usuarios
      WHERE tipo='vendedor' AND sucursal_padre_id = ?
      ORDER BY nombre_panaderia
  ");
  $hijas_stmt->execute([$uid]);
  $mis_hijas = $hijas_stmt->fetchAll();
}

// ── Padre de esta Hija ────────────────────────────────────────────────────
$mi_padre = null;
if ($tipo_suc === 'hija' && !empty($u['sucursal_padre_id'])) {
  $padre_stmt = db()->prepare("SELECT id, nombre, nombre_panaderia, email FROM usuarios WHERE id=?");
  $padre_stmt->execute([$u['sucursal_padre_id']]);
  $mi_padre = $padre_stmt->fetch() ?: null;
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Mi Panel — <?= SITE_NAME ?></title>
  <link rel="stylesheet" href="<?= SITE_URL ?>/css/global.css">
  <link rel="stylesheet" href="<?= SITE_URL ?>/css/vendedor.css">
</head>

<body>

  <div class="sidebar-overlay" id="sidebar-overlay"></div>

  <div class="dash-layout">

    <!-- ══ SIDEBAR ══════════════════════════════════════════════════════════════ -->
    <nav class="sidebar" id="sidebar">
      <div class="sidebar-logo">
        🥖 Panaderia<span>PUMA</span>
        <small>Panel del vendedor</small>
      </div>
      <ul class="sidebar-nav">
        <li><a href="vendedor.php?sec=inicio" class="<?= $seccion === 'inicio'    ? 'on' : '' ?>"><span class="nav-ico">📊</span> Inicio</a></li>
        <li><a href="vendedor.php?sec=productos" class="<?= $seccion === 'productos' ? 'on' : '' ?>">
          <span class="nav-ico">🍞</span>
          <?= $tipo_suc === 'hija' ? 'Productos Heredados' : 'Mis Productos' ?>
        </a></li>
        <?php if ($tipo_suc === 'padre'): ?>
        <li><a href="vendedor.php?sec=add" class="<?= $seccion === 'add' ? 'on' : '' ?>"><span class="nav-ico">➕</span> Agregar Producto</a></li>
        <?php endif; ?>
        <li>
          <a href="vendedor.php?sec=pedidos" class="<?= $seccion === 'pedidos' ? 'on' : '' ?>">
            <span class="nav-ico">📦</span> Pedidos
            <?php if ($st['pend'] > 0): ?>
              <span style="background:var(--rojo);color:white;border-radius:50%;
                       width:18px;height:18px;font-size:0.7rem;font-weight:700;
                       display:inline-flex;align-items:center;justify-content:center;
                       margin-left:auto"><?= $st['pend'] ?></span>
            <?php endif; ?>
          </a>
        </li>
        <?php if ($tipo_suc === 'padre'): ?>
        <li><a href="vendedor.php?sec=hijas" class="<?= $seccion === 'hijas' ? 'on' : '' ?>"><span class="nav-ico">🏬</span> Mis Sucursales Hija</a></li>
        <li><a href="vendedor.php?sec=metricas" class="<?= $seccion === 'metricas' ? 'on' : '' ?>"><span class="nav-ico">�</span> Métricas</a></li>
        <?php endif; ?>
        <?php if ($tipo_suc): ?>
        <li><a href="vendedor.php?sec=trabajadores" class="<?= $seccion === 'trabajadores' ? 'on' : '' ?>"><span class="nav-ico">👥</span> Trabajadores</a></li>
        <?php endif; ?>
        <li><a href="vendedor.php?sec=perfil" class="<?= $seccion === 'perfil' ? 'on' : '' ?>"><span class="nav-ico">⚙️</span> Mi Perfil</a></li>
        <li>
          <a href="vendedor.php?sec=documentos" class="<?= $seccion === 'documentos' ? 'on' : '' ?>">
            <span class="nav-ico">📂</span> Mis Documentos
            <?php if (in_array($u['estado_verificacion'] ?? '', ['sin_enviar', 'rechazado'])): ?>
              <span style="background:var(--naranja);color:white;border-radius:50%;
                       width:8px;height:8px;margin-left:auto;display:inline-block"></span>
            <?php endif; ?>
          </a>
        </li>
      </ul>
      <div class="sidebar-bottom">
        <ul class="sidebar-nav">
          <li><a href="<?= SITE_URL ?>/catalogo.php" target="_blank"><span class="nav-ico">🏪</span> Ver catálogo</a></li>
          <li><a href="<?= SITE_URL ?>/logout.php"><span class="nav-ico">🚪</span> Salir</a></li>
        </ul>
      </div>
    </nav>

    <!-- ══ MAIN ══════════════════════════════════════════════════════════════════ -->
    <main class="dash-main">

      <div class="dash-topbar" style="margin-bottom:20px">
        <div style="display:flex;align-items:center;gap:10px">
          <button class="btn btn-ghost btn-sm mob-menu-btn" id="mob-menu-btn">☰</button>
          <div>
            <h1>
              <?php
              $titulos = [
                'inicio'       => 'Mi Panel',
                'productos'    => $tipo_suc === 'hija' ? 'Productos Heredados' : 'Mis Productos',
                'add'          => ($edit_prod ? 'Editar Producto' : 'Agregar Producto'),
                'pedidos'      => 'Pedidos recibidos',
                'perfil'       => 'Mi Perfil',
                'documentos'   => '📂 Mis Documentos',
                'trabajadores' => '👥 Mis Trabajadores',
                'hijas'        => '🏬 Mis Sucursales Hija',
                'metricas'     => '📈 Métricas de Red',
              ];
              echo $titulos[$seccion] ?? 'Mi Panel';
              ?>
            </h1>
            <p style="color:var(--gris);font-size:0.9rem;margin-top:2px;display:flex;align-items:center;gap:8px">
              <?= h($u['nombre_panaderia'] ?: $u['nombre']) ?> — <?= date('d/m/Y') ?>
              <?php if ($tipo_suc === 'padre'): ?>
                <span style="background:#E3F2FD;color:#1565C0;font-size:0.72rem;font-weight:700;
                             padding:2px 9px;border-radius:50px">🔵 Sucursal Padre</span>
              <?php elseif ($tipo_suc === 'hija'): ?>
                <span style="background:#F3E5F5;color:#6A1B9A;font-size:0.72rem;font-weight:700;
                             padding:2px 9px;border-radius:50px">🟣 Sucursal Hija
                  <?= $mi_padre ? '· ' . h($mi_padre['nombre_panaderia'] ?: $mi_padre['nombre']) : '' ?>
                </span>
              <?php else: ?>
                <span style="background:#FFF8E1;color:#E65100;font-size:0.72rem;font-weight:700;
                             padding:2px 9px;border-radius:50px">⏳ Sin clasificar</span>
              <?php endif; ?>
            </p>
          </div>
        </div>
        <?php if ($tipo_suc === 'padre' && in_array($seccion, ['inicio', 'productos'])): ?>
          <a href="vendedor.php?sec=add" class="btn btn-naranja btn-sm">+ Nuevo producto</a>
        <?php endif; ?>
      </div>

      <!-- ── Alertas ── -->
      <?php if ($msg_ok): ?>
        <div style="background:#E8F5E9;border-left:4px solid var(--verde);padding:12px 16px;
                border-radius:var(--radio);margin-bottom:20px;color:#2E7D32;font-weight:600">
          <?= h($msg_ok) ?>
        </div>
      <?php endif; ?>
      <?php if ($msg_err): ?>
        <div style="background:#FFEBEE;border-left:4px solid var(--rojo);padding:12px 16px;
                border-radius:var(--radio);margin-bottom:20px;color:var(--rojo);font-weight:600">
          ⚠️ <?= h($msg_err) ?>
        </div>
      <?php endif; ?>
      <?php if (($_GET['ok'] ?? '') === 'eliminado'): ?>
        <div style="background:#E8F5E9;border-left:4px solid var(--verde);padding:12px 16px;
                border-radius:var(--radio);margin-bottom:20px;color:#2E7D32;font-weight:600">
          Producto eliminado ✅
        </div>
      <?php endif; ?>

      <?php /* ══════════════════ INICIO ══════════════════ */ if ($seccion === 'inicio'): ?>

        <?php if (!$tipo_suc): ?>
          <div style="background:#FFF8E1;border-left:4px solid var(--naranja);padding:20px 24px;
                      border-radius:var(--radio);margin-bottom:24px">
            <h3 style="margin:0 0 6px;color:#E65100">⏳ Rol pendiente de asignación</h3>
            <p style="margin:0;color:#BF360C;font-size:0.92rem">
              Tu cuenta fue aprobada pero el Administrador todavía no te asignó un rol de sucursal
              (<strong>Padre</strong> o <strong>Hija</strong>).
              Mientras tanto podés completar tu perfil y subir tus documentos.
              Una vez asignado el rol vas a poder gestionar productos y trabajadores.
            </p>
            <div style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap">
              <a href="vendedor.php?sec=perfil" class="btn btn-naranja btn-sm">Completar perfil →</a>
              <a href="vendedor.php?sec=documentos" class="btn btn-ghost btn-sm">Mis documentos →</a>
            </div>
          </div>
        <?php endif; ?>

        <?php if ($u['estado_verificacion'] !== 'aprobado'): ?>
          <div class="onboarding">
            <h2>¡Bienvenido/a a PanaderiaMarket! 🥖</h2>
            <p>Seguí estos pasos para empezar a vender hoy mismo</p>
            <div class="ob-steps">
              <div class="ob-step">
                <div class="ob-step-ico">⚙️</div>
                <div class="ob-step-txt"><strong>1. Completá tu perfil</strong><span>Agregá foto, descripción y contacto</span></div>
              </div>
              <div class="ob-step">
                <div class="ob-step-ico">📸</div>
                <div class="ob-step-txt"><strong>2. Publicá tu primer producto</strong><span>Con foto, precio y descripción</span></div>
              </div>
              <div class="ob-step">
                <div class="ob-step-ico">📲</div>
                <div class="ob-step-txt"><strong>3. Compartí tu tienda</strong><span>Mandá el link por WhatsApp o Instagram</span></div>
              </div>
            </div>
            <div class="ob-actions">
              <a href="vendedor.php?sec=perfil" class="btn btn-naranja btn-sm">Completar perfil →</a>
              <a href="vendedor.php?sec=add" class="btn btn-ghost btn-sm"
                style="border-color:rgba(255,255,255,0.4);color:white">
                Agregar producto →
              </a>
            </div>
          </div>
        <?php endif; ?>

        <div class="stats-grid">
          <div class="stat-card">
            <div class="stat-label">Productos activos</div>
            <div class="stat-value"><?= $st['activos'] ?></div>
            <div class="stat-sub"><?= $st['total_prods'] ?> en total</div>
          </div>
          <div class="stat-card">
            <div class="stat-label">Pedidos pendientes</div>
            <div class="stat-value"><?= $st['pend'] ?></div>
            <div class="stat-sub"><?= $st['total_pedidos'] ?> en total</div>
          </div>
          <div class="stat-card">
            <div class="stat-label">Ingresos entregados</div>
            <div class="stat-value" style="font-size:1.4rem"><?= precio((float)$st['ingresos']) ?></div>
          </div>
          <div class="stat-card">
            <div class="stat-label">Estado de cuenta</div>
            <div style="margin-top:8px">
              <span class="estado-badge estado-<?= h($u['estado_verificacion']) ?>">
                <?= h($u['estado_verificacion']) ?>
              </span>
            </div>
          </div>
        </div>

        <div class="sec-card">
          <div class="sec-card-top">
            <h2>Últimos pedidos</h2>
            <a href="vendedor.php?sec=pedidos" class="btn btn-ghost btn-sm">Ver todos</a>
          </div>
          <?php $ults = array_slice($pedidos, 0, 4); ?>
          <?php if (empty($ults)): ?>
            <p style="color:var(--gris);text-align:center;padding:24px 0">Aún no recibiste pedidos.</p>
          <?php else: ?>
            <?php foreach ($ults as $p): ?>
              <div class="pedido-card">
                <div class="pedido-top">
                  <div>
                    <span class="pedido-id">Pedido #<?= $p['id'] ?></span>
                    <span class="pedido-fecha" style="margin-left:10px">
                      <?= date('d/m/Y H:i', strtotime($p['created_at'])) ?>
                    </span>
                  </div>
                  <span class="estado-badge estado-<?= $p['estado'] ?>">
                    <?= estado_label($p['estado']) ?>
                  </span>
                </div>
                <div class="pedido-total">
                  <span style="color:var(--gris);font-size:0.88rem"><?= h($p['nombre_comprador']) ?></span>
                  <span><?= precio((float)$p['total']) ?></span>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>

      <?php /* ══════════════════ MIS PRODUCTOS ══════════════════ */ elseif ($seccion === 'productos'): ?>

        <div class="sec-card">
          <div class="sec-card-top">
            <h2>Mis Productos</h2>
            <a href="vendedor.php?sec=add" class="btn btn-naranja btn-sm">+ Nuevo</a>
          </div>
          <?php if (empty($productos)): ?>
            <div style="text-align:center;padding:48px 0;color:var(--gris)">
              <span style="font-size:3rem;display:block;margin-bottom:12px">🍞</span>
              <p>Todavía no cargaste ningún producto.</p>
              <a href="vendedor.php?sec=add" class="btn btn-naranja" style="margin-top:14px">
                Agregar mi primer producto
              </a>
            </div>
          <?php else: ?>
            <div class="tabla-wrap">
              <table class="tabla">
                <thead>
                  <tr>
                    <th>Producto</th>
                    <th>Categoría</th>
                    <th>Precio</th>
                    <th>Stock</th>
                    <th>Estado</th>
                    <th>Acciones</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($productos as $p): ?>
                    <tr>
                      <td>
                        <div style="display:flex;align-items:center;gap:10px">
                          <?php if ($p['imagen_url']): ?>
                            <img src="<?= h($p['imagen_url']) ?>"
                              style="width:40px;height:40px;border-radius:var(--radio);
                                  object-fit:cover;border:2px solid var(--crema-dark)" alt="">
                          <?php else: ?>
                            <div style="width:40px;height:40px;background:var(--crema-dark);
                                  border-radius:var(--radio);display:flex;align-items:center;
                                  justify-content:center;font-size:1.3rem">
                              <?= cat_emoji($p['categoria']) ?>
                            </div>
                          <?php endif; ?>
                          <span class="td-nombre"><?= h($p['nombre']) ?></span>
                        </div>
                      </td>
                      <td><?= cat_label($p['categoria']) ?></td>
                      <td class="td-precio">
                        <?= precio((float)$p['precio']) ?>
                        <span style="font-size:0.72rem;color:var(--gris)">
                          <?= ($p['unidad_venta'] ?? 'unidad') === 'kilo' ? '/kg' : '/u' ?>
                        </span>
                      </td>
                      <td><?= $p['cantidad_disponible'] ?? '—' ?></td>
                      <td>
                        <form method="POST" style="display:inline">
                          <input type="hidden" name="accion" value="toggle">
                          <input type="hidden" name="pid" value="<?= $p['id'] ?>">
                          <button type="submit" class="toggle-estado <?= $p['activo'] ? 'activo' : 'inactivo' ?>">
                            <?= $p['activo'] ? '✓ Activo' : '✗ Inactivo' ?>
                          </button>
                        </form>
                      </td>
                      <td>
                        <div style="display:flex;gap:6px">
                          <a href="vendedor.php?edit=<?= $p['id'] ?>" class="btn btn-ghost btn-sm">✏️</a>
                          <form method="POST" onsubmit="return confirm('¿Eliminar este producto?')">
                            <input type="hidden" name="accion" value="delete">
                            <input type="hidden" name="pid" value="<?= $p['id'] ?>">
                            <button type="submit" class="btn btn-danger btn-sm">🗑</button>
                          </form>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>

      <?php /* ══════════════════ AGREGAR / EDITAR ══════════════════ */ elseif ($seccion === 'add'): ?>

        <?php if ($tipo_suc !== 'padre'): ?>
          <div style="background:#FFEBEE;border-left:4px solid var(--rojo);padding:20px 24px;border-radius:var(--radio)">
            <h3 style="margin:0 0 6px;color:#C62828">🚫 Acción no permitida</h3>
            <p style="margin:0;color:#B71C1C;font-size:0.92rem">
              Las Sucursales Hija no pueden crear productos propios. Los productos son asignados por la Sucursal Padre.
            </p>
            <a href="vendedor.php?sec=productos" class="btn btn-ghost btn-sm" style="margin-top:14px;display:inline-block">← Volver</a>
          </div>
        <?php else: ?>

        <div class="sec-card" style="max-width:680px">
          <div class="sec-card-top">
            <h2><?= $edit_prod ? '✏️ Editar Producto' : '➕ Agregar Producto' ?></h2>
            <?php if ($edit_prod): ?>
              <a href="vendedor.php?sec=productos" class="btn btn-ghost btn-sm">Cancelar</a>
            <?php endif; ?>
          </div>

          <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="accion" value="<?= $edit_prod ? 'edit_producto' : 'add_producto' ?>">
            <?php if ($edit_prod): ?>
              <input type="hidden" name="pid" value="<?= $edit_prod['id'] ?>">
            <?php endif; ?>

            <div class="form-row">
              <div class="field">
                <label>Nombre *</label>
                <input type="text" name="nombre"
                  value="<?= h($edit_prod['nombre'] ?? '') ?>"
                  placeholder="Ej: Pan Francés" required>
              </div>
              <div class="field">
                <label>Categoría *</label>
                <select name="cat">
                  <?php foreach (['pan' => '🍞 Pan', 'facturas' => '🥐 Facturas', 'galletas' => '🍪 Galletas', 'cakes' => '🎂 Cakes', 'otro' => '✨ Otro'] as $k => $v): ?>
                    <option value="<?= $k ?>"
                      <?= ($edit_prod['categoria'] ?? 'pan') === $k ? 'selected' : '' ?>>
                      <?= $v ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>

            <div class="form-row">
              <div class="field">
                <label>Se vende por *</label>
                <select name="unidad" id="sel-unidad">
                  <option value="unidad" <?= ($edit_prod['unidad_venta'] ?? 'unidad') === 'unidad' ? 'selected' : '' ?>>
                    Unidad / Media doc. / Docena
                  </option>
                  <option value="kilo" <?= ($edit_prod['unidad_venta'] ?? '') === 'kilo' ? 'selected' : '' ?>>
                    Kilo (precio por kg)
                  </option>
                </select>
              </div>
              <div class="field">
                <label>Cantidad disponible</label>
                <input type="number" name="stock" min="0"
                  value="<?= $edit_prod['cantidad_disponible'] ?? 0 ?>"
                  placeholder="0">
              </div>
            </div>

            <div class="field">
              <label>Descripción</label>
              <textarea name="desc" rows="2"
                placeholder="Contale al cliente qué hace especial este producto..."><?= h($edit_prod['descripcion'] ?? '') ?></textarea>
            </div>

            <div class="form-row">
              <div class="field">
                <label>
                  Precio *
                  <span id="lbl-precio-hint" style="font-weight:400;color:var(--gris)">(por unidad)</span>
                </label>
                <input type="number" name="precio" min="0" step="50"
                  value="<?= $edit_prod['precio'] ?? '' ?>"
                  placeholder="0" required>
                <div id="hint-kilo" class="hint-campo" style="display:none">
                  💡 Ej: ponés <strong>$2.500</strong> → 1kg = $2.500
                </div>
              </div>
            </div>

            <div class="form-row" id="campos-docena">
              <div class="field">
                <label>Precio media docena</label>
                <input type="number" name="media_doc" min="0" step="50"
                  value="<?= $edit_prod['precio_media_docena'] ?? '' ?>"
                  placeholder="Opcional">
              </div>
              <div class="field">
                <label>Precio por docena</label>
                <input type="number" name="docena" min="0" step="50"
                  value="<?= $edit_prod['precio_docena'] ?? '' ?>"
                  placeholder="Opcional">
              </div>
            </div>

            <div class="field">
              <label>Dato extra 💡</label>
              <input type="text" name="extra"
                value="<?= h($edit_prod['dato_extra'] ?? '') ?>"
                placeholder="Sin TACC · Vegano · Horneado a leña · Por encargo...">
            </div>

            <!-- Imagen principal -->
            <div class="field">
              <label>Imagen principal</label>
              <div style="margin-bottom:10px">
                <label for="p-img-file" class="btn btn-ghost btn-sm"
                  style="cursor:pointer;display:inline-flex">
                  📁 Subir desde galería
                </label>
                <input type="file" id="p-img-file" name="imagen"
                  accept="image/*" style="display:none">
                <span style="font-size:0.78rem;color:var(--gris);margin-left:10px">
                  JPG, PNG — máx 5MB
                </span>
              </div>
              <?php if (!empty($edit_prod['imagen_url'])): ?>
                <img id="img-preview" class="img-preview"
                  src="<?= h($edit_prod['imagen_url']) ?>"
                  style="display:block" alt="Preview">
                <div style="font-size:0.75rem;color:var(--gris);margin-top:4px">
                  Subí una nueva para reemplazarla
                </div>
              <?php else: ?>
                <img id="img-preview" class="img-preview" alt="Preview">
              <?php endif; ?>
            </div>

            <!-- Fotos extra -->
            <div class="field">
              <label>Fotos adicionales</label>
              <label for="p-fotos-extra" class="btn btn-ghost btn-sm"
                style="cursor:pointer;display:inline-flex">
                📁 Agregar más fotos
              </label>
              <input type="file" id="p-fotos-extra" accept="image/*"
                multiple style="display:none">
              <span style="font-size:0.78rem;color:var(--gris);margin-left:10px">
                Hasta 4 fotos
              </span>
              <div id="fotos-extra-preview"
                style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px"></div>
            </div>

            <button type="submit" class="btn btn-naranja">
              <?= $edit_prod ? '💾 Guardar cambios' : '💾 Guardar producto' ?>
            </button>
          </form>
        </div>

      <?php /* ══════════════════ PEDIDOS ══════════════════ */ elseif ($seccion === 'pedidos'): ?>

        <div class="sec-card">
          <div class="sec-card-top">
            <h2>📦 Pedidos recibidos</h2>
          </div>

          <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px" id="filtros-estado">
            <?php foreach (['todos' => 'Todos', 'pendiente' => 'Pendiente', 'confirmado' => 'Confirmado', 'listo' => 'Listo', 'entregado' => 'Entregado'] as $k => $v): ?>
              <button class="filtro <?= $k === 'todos' ? 'on' : '' ?>" data-estado="<?= $k ?>">
                <?= $v ?>
              </button>
            <?php endforeach; ?>
          </div>

          <div style="max-width:320px;margin-bottom:16px">
            <input type="search" id="buscar-pedidos"
              placeholder="Buscar por nombre del comprador...">
          </div>

          <div id="lista-pedidos">
            <?php if (empty($pedidos)): ?>
              <div style="text-align:center;padding:40px 0;color:var(--gris)">
                <span style="font-size:3rem;display:block;margin-bottom:12px">📦</span>
                <p>Aún no recibiste pedidos.</p>
              </div>
            <?php else: ?>
              <?php foreach ($pedidos as $p): ?>
                <div class="pedido-card"
                  data-estado="<?= $p['estado'] ?>"
                  data-nombre="<?= h(strtolower($p['nombre_comprador'])) ?>">
                  <div class="pedido-top">
                    <div>
                      <span class="pedido-id">Pedido #<?= $p['id'] ?></span>
                      <span class="pedido-fecha" style="margin-left:8px">
                        <?= date('d/m/Y H:i', strtotime($p['created_at'])) ?>
                      </span>
                      <div style="font-size:0.83rem;color:var(--gris);margin-top:3px">
                        👤 <?= h($p['nombre_comprador']) ?>
                      </div>
                    </div>
                    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                      <span class="estado-badge estado-<?= $p['estado'] ?>">
                        <?= estado_label($p['estado']) ?>
                      </span>
                      <form method="POST">
                        <input type="hidden" name="accion" value="estado_pedido">
                        <input type="hidden" name="pedido_id" value="<?= $p['id'] ?>">
                        <select name="estado" onchange="this.form.submit()"
                          style="width:auto;margin:0;font-size:0.82rem;padding:5px 10px">
                          <?php foreach (['pendiente' => 'Pendiente', 'confirmado' => 'Confirmado', 'listo' => 'Listo', 'entregado' => 'Entregado'] as $k => $v): ?>
                            <option value="<?= $k ?>" <?= $p['estado'] === $k ? 'selected' : '' ?>><?= $v ?></option>
                          <?php endforeach; ?>
                        </select>
                      </form>
                    </div>
                  </div>

                  <?php foreach ($pedidos_items[$p['id']] ?? [] as $it): ?>
                    <div class="pedido-item">
                      <span><?= h($it['nombre_producto']) ?> × <?= $it['cantidad'] ?></span>
                      <span style="font-weight:700">
                        <?= precio($it['precio_unitario'] * $it['cantidad']) ?>
                      </span>
                    </div>
                  <?php endforeach; ?>

                  <div class="pedido-total">
                    <span>Total</span>
                    <span><?= precio((float)$p['total']) ?></span>
                  </div>

                  <?php if ($p['notas']): ?>
                    <div style="margin-top:10px;font-size:0.82rem;background:white;
                          padding:8px 12px;border-radius:6px">
                      📝 <?= h($p['notas']) ?>
                    </div>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>

          <div id="empty-pedidos"
            style="display:none;text-align:center;padding:28px 0;color:var(--gris)">
            No hay pedidos que coincidan.
          </div>
        </div>

        <?php endif; // fin if tipo_suc === 'padre' para sec=add ?>

      <?php /* ══════════════════ TRABAJADORES ══════════════════ */ elseif ($seccion === 'trabajadores'): ?>

        <div class="sec-card">
          <div class="sec-card-top">
            <h2>👥 Mis Trabajadores</h2>
            <button class="btn btn-naranja btn-sm" onclick="document.getElementById('modal-crear-trab').style.display='flex'">
              + Agregar trabajador
            </button>
          </div>

          <!-- Mi identificador único -->
          <div style="background:var(--crema);border-radius:var(--radio);padding:14px 18px;margin-bottom:20px;display:flex;align-items:center;gap:14px">
            <span style="font-size:1.6rem">🪪</span>
            <div style="flex:1">
              <p style="font-weight:700;margin:0 0 2px">Tu identificador único como Admin</p>
              <p style="color:var(--gris);font-size:0.83rem;margin:0">
                <?php if (!empty($u['identificador'])): ?>
                  <strong style="color:var(--marron);font-size:1rem">@<?= h($u['identificador']) ?></strong>
                <?php else: ?>
                  <em>Aún no configurado</em>
                <?php endif; ?>
              </p>
            </div>
            <button class="btn btn-ghost btn-sm" onclick="document.getElementById('modal-identificador').style.display='flex'">
              <?= !empty($u['identificador']) ? '✏️ Cambiar' : '+ Configurar' ?>
            </button>
          </div>

          <?php if (empty($trabajadores)): ?>
            <p style="color:var(--gris);text-align:center;padding:32px 0">No hay trabajadores aún. Agregá el primero.</p>
          <?php else: ?>
            <div style="display:grid;gap:12px">
              <?php foreach ($trabajadores as $t): ?>
                <?php $sol = $solicitudes_map[$t['id']] ?? null; ?>
                <div style="display:flex;align-items:center;gap:14px;padding:14px;background:var(--crema);border-radius:var(--radio)">
                  <?php if (!empty($t['avatar_url'])): ?>
                    <img src="<?= h($t['avatar_url']) ?>" style="width:44px;height:44px;border-radius:50%;object-fit:cover" alt="">
                  <?php else: ?>
                    <div style="width:44px;height:44px;border-radius:50%;background:var(--naranja);color:white;
                    display:flex;align-items:center;justify-content:center;font-weight:900;font-size:1rem">
                      <?= strtoupper(mb_substr($t['nombre'], 0, 1)) ?>
                    </div>
                  <?php endif; ?>

                  <div style="flex:1">
                    <p style="font-weight:700;margin:0 0 2px"><?= h($t['nombre']) ?></p>
                    <p style="color:var(--gris);font-size:0.82rem;margin:0">
                      <?= !empty($t['identificador']) ? '@' . h($t['identificador']) . ' · ' : '' ?>
                      <?= h($t['email']) ?>
                      <?= !empty($t['documento_id']) ? ' · DNI: ' . h($t['documento_id']) : '' ?>
                    </p>
                    <?php if ($sol): ?>
                      <?php
                      $badge_color = match ($sol['estado']) {
                        'pendiente'  => '#FFF8E1; color:#F57F17',
                        'aprobado'   => '#E8F5E9; color:#2E7D32',
                        'rechazado'  => '#FFEBEE; color:#C62828',
                      };
                      $badge_txt = match ($sol['estado']) {
                        'pendiente'  => '⏳ Solicitud pendiente',
                        'aprobado'   => '✅ Admin aprobado',
                        'rechazado'  => '❌ Solicitud rechazada',
                      };
                      ?>
                      <span style="display:inline-block;margin-top:4px;padding:2px 10px;border-radius:50px;
                             font-size:0.75rem;font-weight:700;background:<?= $badge_color ?>">
                        <?= $badge_txt ?>
                      </span>
                    <?php endif; ?>
                  </div>

                  <div style="display:flex;gap:8px;align-items:center">
                    <?php if (!$sol || $sol['estado'] === 'rechazado'): ?>
                      <form method="POST">
                        <input type="hidden" name="accion" value="solicitar_admin">
                        <input type="hidden" name="trab_id" value="<?= $t['id'] ?>">
                        <button class="btn btn-sm" style="background:#E8F5E9;color:#2E7D32;border:none;font-weight:700"
                          title="Solicitar que sea admin de la panadería">
                          👑 Pedir admin
                        </button>
                      </form>
                    <?php endif; ?>

                    <form method="POST" onsubmit="return confirm('¿Eliminar este trabajador?')">
                      <input type="hidden" name="accion" value="eliminar_trabajador">
                      <input type="hidden" name="trab_id" value="<?= $t['id'] ?>">
                      <button class="btn btn-sm" style="background:#FFEBEE;color:#C62828;border:none;font-weight:700">🗑️</button>
                    </form>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <!-- Modal: Configurar identificador -->
        <div id="modal-identificador" class="modal-overlay" style="display:none" onclick="if(event.target===this)this.style.display='none'">
          <div class="modal-box" onclick="event.stopPropagation()">
            <h3>🪪 Tu Identificador Único</h3>
            <p style="color:var(--gris);font-size:0.85rem;margin-bottom:16px">Solo letras, números y guiones bajos.</p>
            <form method="POST">
              <input type="hidden" name="accion" value="set_identificador">
              <div style="margin-bottom:14px">
                <label style="display:block;font-weight:700;margin-bottom:4px">Identificador</label>
                <div style="display:flex;align-items:center;gap:6px">
                  <span style="font-size:1.1rem;color:var(--gris)">@</span>
                  <input type="text" name="identificador" pattern="[a-zA-Z0-9_]+" required
                    value="<?= h($u['identificador'] ?? '') ?>"
                    placeholder="mi_panaderia_2024" style="flex:1">
                </div>
              </div>
              <div style="display:flex;gap:10px">
                <button class="btn" type="submit">💾 Guardar</button>
                <button class="btn btn-ghost" type="button" onclick="document.getElementById('modal-identificador').style.display='none'">Cancelar</button>
              </div>
            </form>
          </div>
        </div>

        <!-- Modal: Crear trabajador -->
        <div id="modal-crear-trab" class="modal-overlay" style="display:none" onclick="if(event.target===this)this.style.display='none'">
          <div class="modal-box" onclick="event.stopPropagation()" style="max-width:520px">
            <h3>👤 Agregar Trabajador</h3>
            <form method="POST" enctype="multipart/form-data">
              <input type="hidden" name="accion" value="crear_trabajador">
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px">
                <div>
                  <label style="display:block;font-weight:700;font-size:0.85rem;margin-bottom:4px">Nombre completo *</label>
                  <input type="text" name="nombre" required placeholder="Juan Pérez">
                </div>
                <div>
                  <label style="display:block;font-weight:700;font-size:0.85rem;margin-bottom:4px">Identificador *</label>
                  <div style="display:flex;align-items:center;gap:4px">
                    <span style="color:var(--gris)">@</span>
                    <input type="text" name="identificador" pattern="[a-zA-Z0-9_]+" required placeholder="usuario123" style="flex:1">
                  </div>
                </div>
                <div>
                  <label style="display:block;font-weight:700;font-size:0.85rem;margin-bottom:4px">DNI / ID *</label>
                  <input type="text" name="documento_id" required placeholder="12345678">
                </div>
                <div>
                  <label style="display:block;font-weight:700;font-size:0.85rem;margin-bottom:4px">Gmail *</label>
                  <input type="email" name="email" required placeholder="trabajador@gmail.com">
                </div>
              </div>
              <div style="margin-bottom:12px">
                <label style="display:block;font-weight:700;font-size:0.85rem;margin-bottom:4px">Contraseña *</label>
                <input type="password" name="password" required minlength="6" placeholder="Mínimo 6 caracteres" style="width:100%;box-sizing:border-box">
              </div>
              <div style="margin-bottom:16px">
                <label style="display:block;font-weight:700;font-size:0.85rem;margin-bottom:4px">Foto de perfil (opcional)</label>
                <input type="file" name="avatar" accept="image/*">
              </div>
              <div style="display:flex;gap:10px">
                <button class="btn btn-naranja" type="submit">✅ Crear trabajador</button>
                <button class="btn btn-ghost" type="button" onclick="document.getElementById('modal-crear-trab').style.display='none'">Cancelar</button>
              </div>
              <?php if ($msg_err && isset($_POST['accion']) && $_POST['accion'] === 'crear_trabajador'): ?>
                <div style="background:#FFEBEE;border-left:4px solid #e53935;padding:10px 14px;
              border-radius:8px;margin-bottom:12px;color:#c62828;font-size:0.85rem;font-weight:600">
                  ⚠️ <?= h($msg_err) ?>
                </div>
              <?php endif; ?>
            </form>
          </div>
        </div>

      <?php /* ══════════════════ MIS HIJAS ══════════════════ */ elseif ($seccion === 'hijas'): ?>

        <?php if ($tipo_suc !== 'padre'): ?>
          <div style="background:#FFEBEE;border-left:4px solid var(--rojo);padding:20px 24px;border-radius:var(--radio)">
            <p style="margin:0;color:#C62828;font-weight:700">🚫 Solo el Encargado Padre puede ver esta sección.</p>
          </div>
        <?php else: ?>
          <div class="sec-card">
            <div class="sec-card-top">
              <h2>🏬 Mis Sucursales Hija (<?= count($mis_hijas) ?>)</h2>
            </div>
            <?php if (empty($mis_hijas)): ?>
              <p style="color:var(--gris);text-align:center;padding:32px 0">
                Todavía no tenés sucursales Hija vinculadas.<br>
                <small>El Administrador Global debe asignarles el rol "Hija" y vincularte como Padre.</small>
              </p>
            <?php else: ?>
              <div style="display:grid;gap:12px">
                <?php foreach ($mis_hijas as $h): ?>
                  <div style="padding:16px 20px;background:var(--crema);border-radius:var(--radio);
                              display:flex;align-items:center;gap:14px;flex-wrap:wrap">
                    <?= avatar_html($h, '42px', '0.9rem') ?>
                    <div style="flex:1;min-width:160px">
                      <p style="font-weight:700;margin:0 0 2px"><?= h($h['nombre_panaderia'] ?: $h['nombre']) ?></p>
                      <p style="color:var(--gris);font-size:0.82rem;margin:0"><?= h($h['email']) ?></p>
                    </div>
                    <span style="padding:4px 12px;border-radius:50px;font-size:0.78rem;font-weight:700;
                      background:<?= $h['estado_verificacion'] === 'aprobado' ? '#E8F5E9' : '#FFF8E1' ?>;
                      color:<?= $h['estado_verificacion'] === 'aprobado' ? '#2E7D32' : '#E65100' ?>">
                      <?= $h['estado_verificacion'] === 'aprobado' ? '✅ Activa' : '⏳ ' . h($h['estado_verificacion']) ?>
                    </span>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <div class="sec-card">
          <div class="sec-card-top">
            <h2>🏬 Mis Sucursales</h2>
            <button class="btn btn-naranja btn-sm" onclick="document.getElementById('modal-crear-suc').style.display='flex'">
              + Nueva sucursal
            </button>
          </div>

          <?php if (empty($mis_sucursales)): ?>
            <p style="color:var(--gris);text-align:center;padding:32px 0">No hay sucursales aún. Creá la primera.</p>
          <?php else: ?>
            <div style="display:grid;gap:12px">
              <?php foreach ($mis_sucursales as $s): ?>
                <div style="padding:16px;background:var(--crema);border-radius:var(--radio);display:flex;align-items:center;gap:14px">
                  <span style="font-size:1.8rem">🏬</span>
                  <div style="flex:1">
                    <p style="font-weight:700;margin:0 0 2px"><?= h($s['nombre']) ?></p>
                    <p style="color:var(--gris);font-size:0.82rem;margin:0">
                      <?= !empty($s['direccion']) ? '📍 ' . h($s['direccion']) : 'Sin dirección' ?>
                      <?= !empty($s['telefono'])  ? ' · 📞 ' . h($s['telefono']) : '' ?>
                    </p>
                  </div>
                  <form method="POST" onsubmit="return confirm('¿Eliminar esta sucursal?')">
                    <input type="hidden" name="accion" value="eliminar_sucursal">
                    <input type="hidden" name="suc_id" value="<?= $s['id'] ?>">
                    <button class="btn btn-sm" style="background:#FFEBEE;color:#C62828;border:none;font-weight:700">🗑️</button>
                  </form>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <!-- Modal: Crear sucursal -->
        <div id="modal-crear-suc" class="modal-overlay" style="display:none" onclick="if(event.target===this)this.style.display='none'">
          <div class="modal-box" onclick="event.stopPropagation()">
            <h3>🏬 Nueva Sucursal</h3>
            <form method="POST">
              <input type="hidden" name="accion" value="crear_sucursal">
              <div style="margin-bottom:12px">
                <label style="display:block;font-weight:700;font-size:0.85rem;margin-bottom:4px">Nombre de la sucursal *</label>
                <input type="text" name="nombre" required placeholder="Sucursal Centro">
              </div>
              <div style="margin-bottom:12px">
                <label style="display:block;font-weight:700;font-size:0.85rem;margin-bottom:4px">Dirección</label>
                <input type="text" name="direccion" placeholder="Av. San Martín 123">
              </div>
              <div style="margin-bottom:16px">
                <label style="display:block;font-weight:700;font-size:0.85rem;margin-bottom:4px">Teléfono</label>
                <input type="text" name="telefono" placeholder="3834-000000">
              </div>
              <div style="display:flex;gap:10px">
                <button class="btn btn-naranja" type="submit">✅ Crear sucursal</button>
                <button class="btn btn-ghost" type="button" onclick="document.getElementById('modal-crear-suc').style.display='none'">Cancelar</button>
              </div>
            </form>
          </div>
        </div>

      <?php /* ══════════════════ PERFIL ══════════════════ */ elseif ($seccion === 'perfil'): ?>

        <div class="sec-card perfil-wrap">
          <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="accion" value="perfil">

            <!-- Avatar -->
            <div style="display:flex;align-items:center;gap:20px;margin-bottom:24px">
              <div class="avatar-circle" id="avatar-preview">
                <?php if (!empty($u['avatar_url'])): ?>
                  <img src="<?= h($u['avatar_url']) ?>" alt="">
                <?php else: ?>
                  <?= mb_strtoupper(mb_substr($u['nombre_panaderia'] ?: $u['nombre'], 0, 1)) ?>
                <?php endif; ?>
              </div>
              <div>
                <label for="pf-avatar" class="btn btn-ghost btn-sm"
                  style="cursor:pointer;display:inline-flex">
                  📷 Cambiar foto de perfil
                </label>
                <input type="file" id="pf-avatar" name="avatar"
                  accept="image/*" style="display:none">
                <p style="font-size:0.78rem;color:var(--gris);margin-top:6px">
                  JPG o PNG — máx 2MB
                </p>
              </div>
            </div>

            <div class="form-row">
              <div class="field">
                <label>Nombre completo</label>
                <input type="text" name="nombre" value="<?= h($u['nombre']) ?>">
              </div>
              <div class="field">
                <label>Nombre de la panadería</label>
                <input type="text" name="nombre_panaderia"
                  value="<?= h($u['nombre_panaderia'] ?? '') ?>"
                  placeholder="Ej: Panadería Los Pumas">
              </div>
            </div>

            <div class="field">
              <label>Descripción</label>
              <textarea name="descripcion" rows="3"
                placeholder="Contales quiénes son, qué los hace únicos..."><?= h($u['descripcion'] ?? '') ?></textarea>
            </div>

            <div class="field">
              <label>
                📢 Banner de anuncio
                <span style="font-weight:400;color:var(--gris)">(aparece en tu tienda)</span>
              </label>
              <input type="text" name="banner"
                value="<?= h($u['banner_anuncio'] ?? '') ?>"
                placeholder="Ej: ¡Esta semana 10% off en medialunas! 🥐"
                maxlength="120">
              <div style="font-size:0.75rem;color:var(--gris);margin-top:4px">
                Máx 120 caracteres. Dejalo vacío para no mostrar.
              </div>
            </div>

            <div class="form-row">
              <div class="field">
                <label>Instagram (sin @)</label>
                <input type="text" name="instagram"
                  value="<?= h($u['instagram'] ?? '') ?>"
                  placeholder="mibakery">
              </div>
              <div class="field">
                <label>Teléfono / WhatsApp</label>
                <input type="tel" name="telefono"
                  value="<?= h($u['telefono'] ?? '') ?>"
                  placeholder="+54 9 383 000-0000">
              </div>
            </div>

            <div class="field">
              <label>Email de contacto</label>
              <input type="email" name="email_contacto"
                value="<?= h($u['email_contacto'] ?? '') ?>">
            </div>

            <hr style="border:none;border-top:1px solid var(--crema-dark);margin:24px 0">
            <h3 style="margin-bottom:6px">💳 Medios de pago que aceptás</h3>
            <p class="medios-pago-hint">
              El efectivo siempre está disponible. Activá los demás que uses.
            </p>

            <div class="medios-pago-grid">
              <!-- Efectivo: siempre activo, no editable -->
              <label class="medio-check on disabled">
                <input type="checkbox" checked disabled>
                <span class="medio-ico">💵</span> Efectivo
              </label>

              <!-- Transferencia: muestra panel CBU al activar -->
              <label class="medio-check <?= $tiene_transf ? 'on' : '' ?>" id="lbl-transf">
                <input type="checkbox" name="medio_transferencia" id="chk-transf"
                  <?= $tiene_transf ? 'checked' : '' ?>>
                <span class="medio-ico">📲</span> Transferencia
              </label>

              <label class="medio-check <?= in_array('debito', $medios_actuales) ? 'on' : '' ?>">
                <input type="checkbox" name="medio_debito" id="chk-debito"
                  <?= in_array('debito', $medios_actuales) ? 'checked' : '' ?>>
                <span class="medio-ico">💳</span> Débito
              </label>

              <label class="medio-check <?= in_array('credito', $medios_actuales) ? 'on' : '' ?>">
                <input type="checkbox" name="medio_credito" id="chk-credito"
                  <?= in_array('credito', $medios_actuales) ? 'checked' : '' ?>>
                <span class="medio-ico">💳</span> Crédito
              </label>
            </div>

            <!-- Panel datos de transferencia -->
            <div class="transferencia-panel" id="panel-transf"
              style="display:<?= $tiene_transf ? 'block' : 'none' ?>">
              <div style="font-weight:700;margin-bottom:12px;color:var(--marron)">
                📲 Datos para transferencia
              </div>
              <div class="field">
                <label>CBU / CVU</label>
                <input type="text" name="cbu"
                  value="<?= h($u['cbu'] ?? '') ?>"
                  placeholder="0000003100000000000000"
                  maxlength="22" inputmode="numeric">
              </div>
              <div class="form-row">
                <div class="field">
                  <label>Alias</label>
                  <input type="text" name="alias_cbu"
                    value="<?= h($u['alias_cbu'] ?? '') ?>"
                    placeholder="mi.alias.mp">
                </div>
                <div class="field">
                  <label>Titular de la cuenta</label>
                  <input type="text" name="titular_cuenta"
                    value="<?= h($u['titular_cuenta'] ?? '') ?>"
                    placeholder="Nombre Apellido">
                </div>
              </div>
              <div style="font-size:0.78rem;color:var(--gris);margin-top:-4px">
                Ingresá al menos el CBU o el alias para que los compradores puedan transferirte.
              </div>
            </div>

            <hr style="border:none;border-top:1px solid var(--crema-dark);margin:24px 0">
            <button type="submit" class="btn btn-marron">💾 Guardar cambios</button>
          </form>
        </div>

      <?php /* ══════════════════ DOCUMENTOS ══════════════════ */ elseif ($seccion === 'documentos'): ?>

        <div class="sec-card">
          <div class="sec-card-top" style="margin-bottom:20px">
            <h2>📂 Mis Documentos</h2>
          </div>

          <?php
          $ev   = $u['estado_verificacion'] ?? 'sin_enviar';
          $nota = $u['doc_notas_rechazo']   ?? '';
          $cfgs = [
            'sin_enviar' => [
              'ico' => '📂',
              'color' => '#757575',
              'bg' => '#F5F5F5',
              'titulo' => 'Documentos no enviados',
              'msg' => 'Subí tus 3 documentos y envialos para que podamos verificar tu panadería.'
            ],
            'pendiente'  => [
              'ico' => '🕐',
              'color' => '#F57F17',
              'bg' => '#FFF8E1',
              'titulo' => 'Documentos en revisión',
              'msg' => 'Recibimos tu documentación. Te notificaremos por email cuando esté lista la revisión.'
            ],
            'aprobado'   => [
              'ico' => '✅',
              'color' => '#2E7D32',
              'bg' => '#E8F5E9',
              'titulo' => '¡Panadería verificada!',
              'msg' => 'Tu documentación fue aprobada. Tus productos ya son visibles en el catálogo.'
            ],
            'rechazado'  => [
              'ico' => '❌',
              'color' => '#C62828',
              'bg' => '#FFEBEE',
              'titulo' => 'Documentación rechazada',
              'msg' => 'Tu documentación fue rechazada. Revisá el mensaje del administrador y volvé a subir los documentos corregidos.'
            ],
          ];
          $cfg = $cfgs[$ev] ?? $cfgs['sin_enviar'];
          ?>

          <!-- Banner estado -->
          <div style="background:<?= $cfg['bg'] ?>;border-radius:var(--radio);
                  padding:14px 18px;display:flex;gap:12px;align-items:flex-start;
                  margin-bottom:24px">
            <span style="font-size:1.5rem;flex-shrink:0"><?= $cfg['ico'] ?></span>
            <div>
              <div style="font-weight:700;color:<?= $cfg['color'] ?>;margin-bottom:3px">
                <?= $cfg['titulo'] ?>
              </div>
              <div style="font-size:0.85rem;color:var(--gris)"><?= h($cfg['msg']) ?></div>
              <?php if ($ev === 'rechazado' && $nota): ?>
                <div style="margin-top:8px;padding:10px;background:rgba(198,40,40,.08);
                        border-radius:8px;font-size:0.83rem;color:#C62828">
                  <strong>Mensaje del administrador:</strong> <?= h($nota) ?>
                </div>
              <?php endif; ?>
            </div>
          </div>

          <p style="font-size:0.88rem;color:var(--gris);margin-bottom:20px;line-height:1.7">
            Para poder vender en PanaderiaMarket necesitás subir los siguientes documentos obligatorios.
            Una vez enviados, el equipo los revisará y te notificará por email en 24–48hs.
          </p>

          <?php
          $doc_lista = [
            [
              'col' => 'doc_bromatologia',
              'n' => 1,
              'ico' => '📋',
              'titulo' => 'Habilitación Bromatológica Municipal',
              'sub'  => 'Emitida por la Dirección de Calidad Alimentaria del Municipio de Catamarca'
            ],
            [
              'col' => 'doc_carnet_manipulador',
              'n' => 2,
              'ico' => '🪪',
              'titulo' => 'Carnet de Manipulador de Alimentos',
              'sub'  => 'Emitido por la autoridad sanitaria municipal o provincial. Al menos 1 por establecimiento.'
            ],
            [
              'col' => 'doc_habilitacion_comercial',
              'n' => 3,
              'ico' => '🏪',
              'titulo' => 'Habilitación Comercial Municipal',
              'sub'  => 'Formulario Único de Habilitación Comercial del Municipio de Catamarca'
            ],
          ];
          foreach ($doc_lista as $d):
            $url = $u[$d['col']] ?? '';
          ?>
            <div class="sec-card"
              style="box-shadow:none;border:2px solid var(--crema-dark);
                  margin-bottom:14px;padding:18px">
              <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px">
                <span style="font-size:1.6rem"><?= $d['ico'] ?></span>
                <div>
                  <div style="font-weight:700"><?= h($d['titulo']) ?></div>
                  <div style="font-size:0.78rem;color:var(--gris)"><?= h($d['sub']) ?></div>
                </div>
                <span id="ico-doc-<?= $d['n'] ?>" style="margin-left:auto;font-size:1.2rem">
                  <?= $url ? '✅' : '' ?>
                </span>
              </div>

              <div id="preview-doc-<?= $d['n'] ?>" style="margin-bottom:10px">
                <?php if ($url): ?>
                  <?php if (str_ends_with(strtolower($url), '.pdf')): ?>
                    <div style="padding:10px;background:var(--crema);border-radius:8px;
                          font-size:0.82rem;color:var(--marron)">
                      📄 Archivo PDF subido —
                      <a href="<?= SITE_URL ?>/<?= h($url) ?>" target="_blank"
                        style="color:var(--naranja)">Ver</a>
                    </div>
                  <?php else: ?>
                    <img src="<?= SITE_URL ?>/<?= h($url) ?>"
                      style="width:100%;max-height:140px;object-fit:cover;
                          border-radius:8px;border:2px solid var(--crema-dark)">
                  <?php endif; ?>
                <?php endif; ?>
              </div>

              <label for="file-doc-<?= $d['n'] ?>" class="btn btn-ghost btn-sm"
                style="cursor:pointer;display:inline-flex">
                📁 <?= $url ? 'Reemplazar archivo' : 'Subir archivo' ?>
              </label>
              <input type="file" id="file-doc-<?= $d['n'] ?>"
                accept="image/*,.pdf" style="display:none">
              <span style="font-size:0.75rem;color:var(--gris);margin-left:10px">
                JPG, PNG o PDF — máx 5MB
              </span>
            </div>
          <?php endforeach; ?>

          <button class="btn btn-naranja" id="btn-enviar-docs"
            <?= $ev === 'pendiente' ? 'disabled title="Ya enviados, aguardá la revisión"' : '' ?>>
            📤 Enviar documentos para revisión
          </button>
          <p style="font-size:0.75rem;color:var(--gris);margin-top:8px">
            Una vez enviados, el equipo revisará tu documentación en un plazo de 24–48hs.
          </p>
        </div>

      <?php endif; ?>

    </main>
  </div><!-- /dash-layout -->

  <div id="toast-box"></div>

  <script>
    /* ── Sidebar mobile ─────────────────────────────────────────────────────── */
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebar-overlay');
    document.getElementById('mob-menu-btn')?.addEventListener('click', () => {
      sidebar.classList.toggle('open');
      overlay.classList.toggle('open');
    });
    overlay?.addEventListener('click', () => {
      sidebar.classList.remove('open');
      overlay.classList.remove('open');
    });

    /* ── Preview imagen principal ───────────────────────────────────────────── */
    document.getElementById('p-img-file')?.addEventListener('change', function() {
      const file = this.files[0];
      if (!file) return;
      const reader = new FileReader();
      reader.onload = e => {
        const prev = document.getElementById('img-preview');
        prev.src = e.target.result;
        prev.style.display = 'block';
      };
      reader.readAsDataURL(file);
    });

    /* ── Preview fotos adicionales ──────────────────────────────────────────── */
    document.getElementById('p-fotos-extra')?.addEventListener('change', function() {
      const wrap = document.getElementById('fotos-extra-preview');
      wrap.innerHTML = '';
      Array.from(this.files).slice(0, 4).forEach(f => {
        const reader = new FileReader();
        reader.onload = e => {
          const img = document.createElement('img');
          img.src = e.target.result;
          img.className = 'fotos-extra-thumb';
          wrap.appendChild(img);
        };
        reader.readAsDataURL(f);
      });
    });

    /* ── Preview avatar ─────────────────────────────────────────────────────── */
    document.getElementById('pf-avatar')?.addEventListener('change', function() {
      const file = this.files[0];
      if (!file) return;
      const reader = new FileReader();
      reader.onload = e => {
        const av = document.getElementById('avatar-preview');
        av.innerHTML = `<img src="${e.target.result}"
                         style="width:100%;height:100%;object-fit:cover">`;
      };
      reader.readAsDataURL(file);
    });

    /* ── Se vende por: toggle campos docena ────────────────────────────────── */
    const selUnidad = document.getElementById('sel-unidad');
    const camposDoc = document.getElementById('campos-docena');
    const hintKilo = document.getElementById('hint-kilo');
    const lblHint = document.getElementById('lbl-precio-hint');

    function actualizarUnidad() {
      if (!selUnidad) return;
      const esKilo = selUnidad.value === 'kilo';
      if (camposDoc) camposDoc.style.display = esKilo ? 'none' : 'grid';
      if (hintKilo) hintKilo.style.display = esKilo ? 'block' : 'none';
      if (lblHint) lblHint.textContent = esKilo ? '(por kg)' : '(por unidad)';
    }
    selUnidad?.addEventListener('change', actualizarUnidad);
    actualizarUnidad();

    /* ── Medios de pago: toggle estilo ─────────────────────────────────────── */
    document.querySelectorAll('.medios-pago-grid .medio-check:not(.disabled)').forEach(lbl => {
      const chk = lbl.querySelector('input[type=checkbox]');
      chk?.addEventListener('change', () => {
        lbl.classList.toggle('on', chk.checked);
      });
    });

    /* ── Panel transferencia ────────────────────────────────────────────────── */
    const chkTransf = document.getElementById('chk-transf');
    const panelTransf = document.getElementById('panel-transf');
    chkTransf?.addEventListener('change', function() {
      if (panelTransf) panelTransf.style.display = this.checked ? 'block' : 'none';
    });

    /* ── Filtro + búsqueda de pedidos ───────────────────────────────────────── */
    let filtroEstado = 'todos';
    let busqNombre = '';

    function filtrarPedidos() {
      const cards = document.querySelectorAll('#lista-pedidos .pedido-card');
      let visible = 0;
      cards.forEach(c => {
        const okEstado = filtroEstado === 'todos' || c.dataset.estado === filtroEstado;
        const okNombre = !busqNombre || (c.dataset.nombre || '').includes(busqNombre);
        const show = okEstado && okNombre;
        c.style.display = show ? '' : 'none';
        if (show) visible++;
      });
      const empty = document.getElementById('empty-pedidos');
      if (empty) empty.style.display = (visible === 0 && cards.length > 0) ? 'block' : 'none';
    }

    document.querySelectorAll('#filtros-estado .filtro').forEach(btn => {
      btn.addEventListener('click', () => {
        document.querySelectorAll('#filtros-estado .filtro').forEach(b => b.classList.remove('on'));
        btn.classList.add('on');
        filtroEstado = btn.dataset.estado;
        filtrarPedidos();
      });
    });

    document.getElementById('buscar-pedidos')?.addEventListener('input', function() {
      busqNombre = this.value.toLowerCase().trim();
      filtrarPedidos();
    });

    /* ── Toast ──────────────────────────────────────────────────────────────── */
    function toast(msg, tipo = 'ok') {
      const box = document.getElementById('toast-box');
      if (!box) return;
      const t = document.createElement('div');
      t.className = `toast toast-${tipo === 'ok' ? 'ok' : tipo === 'err' ? 'err' : 'inf'}`;
      t.innerHTML = `<div class="toast-icon">${tipo === 'ok' ? '✓' : '!'}</div>${msg}`;
      box.appendChild(t);
      setTimeout(() => t.remove(), 3200);
    }

    <?php if ($msg_ok): ?>toast('<?= addslashes($msg_ok) ?>', 'ok');
    <?php endif; ?>
  </script>

</body>

</html>