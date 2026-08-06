<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';

// ── Parámetro ─────────────────────────────────────────────────────────────
$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: ' . SITE_URL . '/catalogo.php'); exit; }

// ── Datos de la sucursal ──────────────────────────────────────────────────
$stmt = db()->prepare("
    SELECT s.*
    FROM sucursales s
    INNER JOIN usuarios u ON u.id = s.vendedor_id
    WHERE s.id = ?
      AND s.activo = 1
      AND s.estado = 'activa'
      AND u.tipo = 'vendedor'
      AND u.estado_verificacion = 'aprobado'
");
$stmt->execute([$id]);
$suc = $stmt->fetch();
if (!$suc) { header('Location: ' . SITE_URL . '/catalogo.php'); exit; }

// ── Panadería padre ───────────────────────────────────────────────────────
$stmt2 = db()->prepare("
    SELECT id, nombre, nombre_panaderia, avatar_url, telefono, descripcion
    FROM   usuarios
    WHERE  id = ? AND tipo = 'vendedor'
");
$stmt2->execute([$suc['vendedor_id']]);
$padre = $stmt2->fetch();
if (!$padre) { header('Location: ' . SITE_URL . '/catalogo.php'); exit; }

$nombre_padre = $padre['nombre_panaderia'] ?: $padre['nombre'];

// ── Productos del padre (la sucursal comparte el catálogo) ────────────────
$prods = db()->prepare("
    SELECT * FROM productos
    WHERE  vendedor_id = ? AND activo = 1
    ORDER  BY created_at DESC
");
$prods->execute([$suc['vendedor_id']]);
$productos = $prods->fetchAll();

$page_title = h($suc['nombre']);
$extra_css  = 'catalogo.css';
include __DIR__ . '/includes/header.php';
?>

<!-- ══ HEADER ═══════════════════════════════════════════════════════════════ -->
<div class="catalogo-header">
  <div class="container">
    <p style="margin:0 0 4px;font-size:0.85rem;opacity:.8">
      <a href="catalogo.php" style="color:white">Catálogo</a> ›
      <a href="catalogo.php?vendedor=<?= $padre['id'] ?>" style="color:white">
        <?= h($nombre_padre) ?>
      </a> › Sucursal
    </p>
    <h1 style="margin:0"><?= h($suc['nombre']) ?></h1>
  </div>
</div>

<div class="container" style="padding-top:28px;padding-bottom:40px">

  <!-- Tarjeta info sucursal -->
  <div style="background:var(--blanco);border-radius:var(--radio-lg);
              box-shadow:var(--sombra);padding:24px 28px;
              display:flex;flex-wrap:wrap;gap:24px;align-items:flex-start;
              margin-bottom:28px">

    <!-- Avatar padre -->
    <div style="width:72px;height:72px;border-radius:50%;background:var(--marron);
                display:flex;align-items:center;justify-content:center;
                font-weight:700;font-size:1.4rem;color:white;flex-shrink:0;
                <?= !empty($padre['avatar_url']) ? "background:url('".$padre['avatar_url']."') center/cover;" : '' ?>">
      <?= empty($padre['avatar_url']) ? iniciales($nombre_padre) : '' ?>
    </div>

    <!-- Info -->
    <div style="flex:1;min-width:200px">
      <p style="margin:0 0 6px;font-size:0.78rem;color:var(--gris)">Sucursal de</p>
      <a href="catalogo.php?vendedor=<?= $padre['id'] ?>"
         style="font-family:'Playfair Display',serif;font-size:1.2rem;
                font-weight:700;color:var(--marron);text-decoration:none">
        🏪 <?= h($nombre_padre) ?>
      </a>

      <div style="margin-top:12px;display:flex;flex-wrap:wrap;gap:10px">
        <?php if (!empty($suc['direccion'])): ?>
          <span style="background:var(--crema);border-radius:var(--radio);
                       padding:6px 12px;font-size:0.83rem;color:var(--gris)">
            📍 <?= h($suc['direccion']) ?>
          </span>
        <?php endif; ?>
        <?php if (!empty($suc['telefono'])): ?>
          <span style="background:var(--crema);border-radius:var(--radio);
                       padding:6px 12px;font-size:0.83rem;color:var(--gris)">
            📞 <?= h($suc['telefono']) ?>
          </span>
        <?php endif; ?>
      </div>
    </div>

    <!-- Botón ver panadería padre -->
    <a href="catalogo.php?vendedor=<?= $padre['id'] ?>"
       class="btn btn-naranja btn-sm" style="align-self:center">
      Ver todos los productos
    </a>
  </div>

  <!-- Título del grid -->
  <h2 style="font-family:'Playfair Display',serif;color:var(--marron);margin-bottom:16px">
    Catálogo disponible
    <span style="font-size:0.85rem;font-weight:400;color:var(--gris);margin-left:8px">
      (<?= count($productos) ?> producto<?= count($productos) !== 1 ? 's' : '' ?>)
    </span>
  </h2>

  <!-- Grid de productos -->
  <?php if (empty($productos)): ?>
    <div style="text-align:center;padding:60px 0;color:var(--gris)">
      <span style="font-size:3rem;display:block;margin-bottom:12px">🍞</span>
      <h3>Aún no hay productos cargados</h3>
    </div>
  <?php else: ?>
    <div class="grid-productos" id="productos-grid">
      <?php foreach ($productos as $prod):
        $sin_stock = ($prod['cantidad_disponible'] !== null && (int)$prod['cantidad_disponible'] === 0);
      ?>
        <div class="card"
             data-nombre="<?= h(strtolower($prod['nombre'])) ?>"
             data-pan="<?= h(strtolower($nombre_padre)) ?>">
          <span class="card-cat"><?= cat_label($prod['categoria']) ?></span>

          <?php if (!empty($prod['imagen_url'])): ?>
            <img src="<?= h($prod['imagen_url']) ?>"
                 class="card-img" alt="<?= h($prod['nombre']) ?>"
                 loading="lazy">
          <?php else: ?>
            <div class="card-img-ph"><?= cat_emoji($prod['categoria']) ?></div>
          <?php endif; ?>

          <div class="card-body">
            <span class="card-tienda">
              <a href="sucursal.php?id=<?= $suc['id'] ?>"
                 style="color:var(--naranja)">
                🏬 <?= h($suc['nombre']) ?>
              </a>
            </span>
            <div class="card-nombre"><?= h($prod['nombre']) ?></div>
            <?php if (!empty($prod['descripcion'])): ?>
              <p class="card-desc"><?= h($prod['descripcion']) ?></p>
            <?php endif; ?>
            <div class="card-footer">
              <span class="card-precio">$<?= number_format($prod['precio'], 2) ?></span>
              <?php if ($sin_stock): ?>
                <span style="font-size:0.75rem;color:var(--gris)">Sin stock</span>
              <?php else: ?>
                <button class="btn btn-naranja btn-sm btn-agregar"
                        data-id="<?= $prod['id'] ?>"
                        data-nombre="<?= h($prod['nombre']) ?>"
                        data-precio="<?= $prod['precio'] ?>"
                        data-panaderia="<?= h($nombre_padre) ?>"
                        data-vendedor="<?= $padre['id'] ?>"
                        data-imagen="<?= h($prod['imagen_url'] ?? '') ?>">
                  + Agregar
                </button>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>

<script>
document.querySelectorAll('.btn-agregar').forEach(btn => {
  btn.addEventListener('click', e => {
    e.preventDefault();
    agregarItem({
      id:          +btn.dataset.id,
      nombre:      btn.dataset.nombre,
      precio:      +btn.dataset.precio,
      panaderia:   btn.dataset.panaderia,
      vendedor_id: +btn.dataset.vendedor,
      imagen_url:  btn.dataset.imagen,
    });
  });
});
</script>