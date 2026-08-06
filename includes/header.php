<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/functions.php';
$titulo = ($page_title ?? 'Inicio') . ' — ' . SITE_NAME;
$u_actual = usuario_actual();
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= h($titulo) ?></title>
  <meta name="csrf-token" content="<?= csrf_token() ?>">
  <link rel="stylesheet" href="<?= SITE_URL ?>/css/global.css">
  <?php if (!empty($extra_css)): ?>
    <link rel="stylesheet" href="<?= SITE_URL ?>/css/<?= h($extra_css) ?>">
  <?php endif; ?>
</head>
<body>

<!-- ══ NAVBAR ═══════════════════════════════════════════════════════════ -->
<nav class="navbar" role="navigation" aria-label="Navegación principal">
  <div class="navbar-inner">

    <a href="<?= SITE_URL ?>/index.php" class="navbar-logo">
      <img src="<?= SITE_URL ?>/assets/logo.png" alt="Logo"
           onerror="this.style.display='none'">
      Panaderia<span class="marca">PUMA</span>
    </a>

    <div class="navbar-search">
      <span class="ico">🔍</span>
      <input type="text" id="nav-buscar" placeholder="Buscar productos, panaderías…"
             autocomplete="off" aria-label="Buscar">
      <div class="sugerencias-drop" id="sugerencias-drop"></div>
    </div>

    <div class="navbar-actions">
      <a href="<?= SITE_URL ?>/catalogo.php" class="btn btn-ghost btn-sm">Catálogo</a>

      <?php if ($u_actual): ?>
        <?php if ($u_actual['tipo'] === 'vendedor'): ?>
          <a href="<?= SITE_URL ?>/vendedor.php" class="btn btn-ghost btn-sm">Mi panel</a>
        <?php elseif ($u_actual['tipo'] === 'admin'): ?>
          <a href="<?= SITE_URL ?>/admin.php" class="btn btn-ghost btn-sm">Admin</a>
        <?php else: ?>
          <a href="<?= SITE_URL ?>/historial.php" class="btn btn-ghost btn-sm">Mis pedidos</a>
        <?php endif; ?>
        <a href="<?= SITE_URL ?>/logout.php" class="btn btn-ghost btn-sm">Salir</a>
      <?php else: ?>
        <a href="<?= SITE_URL ?>/login.php" class="btn btn-ghost btn-sm">Ingresar</a>
      <?php endif; ?>

      <button class="cart-btn" id="cart-btn" aria-label="Carrito">
        🛒 Carrito
        <span class="cart-badge" id="cart-badge"></span>
      </button>
    </div>

  </div>
</nav>

<!-- ══ CARRITO DRAWER ════════════════════════════════════════════════════ -->
<div class="cart-overlay" id="cart-overlay"></div>
<aside class="cart-drawer" id="cart-drawer" aria-label="Carrito de compras">
  <div class="cart-header">
    <h3>🛒 Tu carrito</h3>
    <button class="cart-close" id="cart-close" aria-label="Cerrar carrito">✕</button>
  </div>
  <div id="cart-body"></div>
  <div id="cart-footer">
    <div class="cart-total">
      <span>Total</span>
      <strong id="cart-total-precio">$0</strong>
    </div>
    <a href="<?= SITE_URL ?>/checkout.php" class="btn btn-naranja btn-full" id="btn-ir-checkout">
      Confirmar pedido →
    </a>
  </div>
</aside>

<!-- ══ TOAST BOX ══════════════════════════════════════════════════════════ -->
<div id="toast-box"></div>

<!-- ══ JS GLOBAL ══════════════════════════════════════════════════════════ -->
<script>
const SITE_URL = '<?= SITE_URL ?>';
</script>
<script src="<?= SITE_URL ?>/js/global.js"></script>