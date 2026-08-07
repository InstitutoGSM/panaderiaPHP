<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';

// ── Parámetro vendedor ────────────────────────────────────────────────────
$vid = (int)($_GET['id'] ?? 0);
if (!$vid) {
  header('Location: ' . SITE_URL . '/catalogo.php');
  exit;
}

// ── Perfil del vendedor ───────────────────────────────────────────────────
$stmt = db()->prepare("
    SELECT id, nombre, nombre_panaderia, descripcion, avatar_url,
           telefono, email_contacto, instagram,
           banner_anuncio, estado_verificacion
    FROM   usuarios
    WHERE  id = ? AND tipo = 'vendedor'
");
$stmt->execute([$vid]);
$v = $stmt->fetch();
if (!$v || $v['estado_verificacion'] !== 'aprobado') {
  header('Location: ' . SITE_URL . '/catalogo.php');
  exit;
}

// ── Productos activos del vendedor ────────────────────────────────────────
$prod_stmt = db()->prepare("
    SELECT
      p.id,
      p.nombre,
      p.descripcion,
      p.categoria,
      p.precio,
      p.precio_media_docena,
      p.precio_docena,
      p.imagen_url,
      p.unidad_venta,
      COALESCE(ss.cantidad_disponible, p.cantidad_disponible) AS cantidad_disponible,
      s.id AS sucursal_id,
      s.nombre AS sucursal_nombre
    FROM productos p
    INNER JOIN sucursales s
      ON s.vendedor_id = p.vendedor_id
     AND s.padre_id IS NULL
     AND s.activo = 1
     AND s.estado = 'activa'
    LEFT JOIN stock_sucursal ss
      ON ss.producto_id = p.id
     AND ss.sucursal_id = s.id
     AND ss.activo = 1
    WHERE p.vendedor_id = ?
      AND p.activo = 1
      AND COALESCE(ss.cantidad_disponible, p.cantidad_disponible) > 0
    ORDER BY p.created_at DESC
");
$prod_stmt->execute([$vid]);
$productos = $prod_stmt->fetchAll();

// ── Meta ──────────────────────────────────────────────────────────────────
$nombre_pan = $v['nombre_panaderia'] ?: $v['nombre'];
$categorias = array_values(array_unique(array_filter(array_column($productos, 'categoria'))));

$page_title = h($nombre_pan);
?>
<!DOCTYPE html>
<html lang="es">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $page_title ?> — <?= SITE_NAME ?></title>
  <link rel="stylesheet" href="<?= SITE_URL ?>/css/global.css">
  <link rel="stylesheet" href="<?= SITE_URL ?>/css/tienda.css">
</head>

<body>

  <!-- ══ NAVBAR CON BUSCADOR DE TIENDA ══════════════════════════════════════ -->
  <nav class="navbar" role="navigation">
    <div class="navbar-inner">
      <a href="<?= SITE_URL ?>/" class="navbar-logo">
        <img src="<?= SITE_URL ?>/assets/logo.png" alt="Logo" onerror="this.style.display='none'">
        Panaderia<span class="marca">PUMA</span>
      </a>
      <div class="navbar-search" role="search">
        <span class="ico" aria-hidden="true">🔍</span>
        <input type="search" id="search-tienda"
          placeholder="Buscar en esta tienda..."
          aria-label="Buscar en la tienda">
      </div>
      <div class="navbar-actions">
        <a href="<?= SITE_URL ?>/catalogo.php" class="btn btn-ghost btn-sm">← Volver</a>
        <button class="cart-btn" id="cart-toggle" aria-label="Carrito">
          🛒 <span class="cart-badge" id="cart-badge-count">0</span>
        </button>
      </div>
    </div>
  </nav>

  <!-- ══ HEADER DE TIENDA ════════════════════════════════════════════════════ -->
  <header class="tienda-header" id="tienda-header-wrap">
    <div class="container">
      <a href="<?= SITE_URL ?>/catalogo.php" class="volver">← Todas las panaderías</a>
      <div class="tienda-info">

        <!-- Avatar -->
        <div class="tienda-avatar">
          <?php if ($v['avatar_url']): ?>
            <img src="<?= h($v['avatar_url']) ?>" alt="<?= h($nombre_pan) ?>">
          <?php else: ?>
            <?= iniciales($nombre_pan) ?>
          <?php endif; ?>
        </div>

        <!-- Datos -->
        <div>
          <div class="tienda-nombre"><?= h($nombre_pan) ?></div>
          <p class="tienda-desc">
            <?= h($v['descripcion'] ?: 'Panadería artesanal') ?>
          </p>
          <div class="tienda-meta">
            <?php if ($v['instagram']): ?>
              <a href="https://instagram.com/<?= h($v['instagram']) ?>"
                target="_blank" rel="noopener">
                📸 @<?= h($v['instagram']) ?>
              </a>
            <?php endif; ?>
            <?php if ($v['telefono']): ?>
              <a href="tel:<?= h($v['telefono']) ?>">
                📞 <?= h($v['telefono']) ?>
              </a>
            <?php endif; ?>
            <?php if ($v['email_contacto']): ?>
              <a href="mailto:<?= h($v['email_contacto']) ?>">
                ✉️ <?= h($v['email_contacto']) ?>
              </a>
            <?php endif; ?>
          </div>
          <?php if ($v['telefono']): ?>
            <?php
            $tel_clean = preg_replace('/\D/', '', $v['telefono']);
            $msg_wa    = urlencode('Hola! Vi tu tienda en PanaderiaMarket 🥖');
            ?>
            <a href="https://wa.me/<?= $tel_clean ?>?text=<?= $msg_wa ?>"
              target="_blank" rel="noopener"
              style="display:inline-flex;align-items:center;gap:8px;
                    background:#25D366;color:white;padding:9px 18px;
                    border-radius:50px;font-weight:700;font-size:0.88rem;
                    margin-top:12px;text-decoration:none">
              💬 Consultar por WhatsApp
            </a>
          <?php endif; ?>
        </div>

      </div>
    </div>
  </header>

  <!-- Banner anuncio del vendedor -->
  <?php if ($v['banner_anuncio']): ?>
    <div style="background:linear-gradient(90deg,var(--naranja),var(--naranja-lt));
              color:white;text-align:center;padding:12px 20px;
              font-weight:700;font-size:0.9rem;">
      📢 <?= h($v['banner_anuncio']) ?>
    </div>
  <?php endif; ?>

  <!-- ══ MAIN ═══════════════════════════════════════════════════════════════ -->
  <main class="container">

    <!-- Filtros por categoría -->
    <?php if (!empty($categorias)): ?>
      <div id="filtros-tienda" class="filtros sec-sm" role="group" aria-label="Categorías">
        <button class="filtro on" data-cat="todos" aria-pressed="true">Todos</button>
        <?php foreach ($categorias as $cat): ?>
          <button class="filtro" data-cat="<?= h($cat) ?>" aria-pressed="false">
            <?= cat_emoji($cat) ?> <?= h(cat_label($cat)) ?>
          </button>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <!-- Contador -->
    <div class="toolbar">
      <span class="toolbar-count" id="count-tienda"></span>
    </div>

    <!-- Grid de productos (lo llena JS) -->
    <div id="grid-tienda" class="grid-productos sec-sm" role="list"></div>

    <!-- Estado vacío -->
    <div id="empty-tienda" class="empty-state" style="display:none">
      <span class="ico">🍞</span>
      <h3>No se encontraron productos</h3>
      <p>Probá con otro filtro o búsqueda</p>
    </div>

  </main>

  <?php include __DIR__ . '/includes/footer.php'; ?>

  <!-- ══ DATOS PARA JS ════════════════════════════════════════════════════════ -->
  <script>
    const SITE_URL = '<?= SITE_URL ?>';
    const NOMBRE_PAN = <?= json_encode($nombre_pan) ?>;

    // Todos los productos pasados desde PHP
    const TODOS_PRODUCTOS = <?= json_encode(array_values($productos)) ?>;

    /* ══ ESTADO ════════════════════════════════════════════════════════════════ */
    let catActual = 'todos';
    let busqueda = '';

    /* ══ RENDER ════════════════════════════════════════════════════════════════ */
    function formatPrecio(n) {
      return '$' + Number(n).toLocaleString('es-AR', {
        minimumFractionDigits: 0
      });
    }

    function h(str) {
      const d = document.createElement('div');
      d.textContent = str ?? '';
      return d.innerHTML;
    }

    function render() {
      const grid = document.getElementById('grid-tienda');
      const empty = document.getElementById('empty-tienda');
      const count = document.getElementById('count-tienda');
      const q = busqueda.toLowerCase().trim();

      const filtrados = TODOS_PRODUCTOS.filter(p => {
        const porCat = catActual === 'todos' || p.categoria === catActual;
        const porQ = !q ||
          p.nombre.toLowerCase().includes(q) ||
          (p.descripcion || '').toLowerCase().includes(q);
        return porCat && porQ;
      });

      count.textContent = filtrados.length ?
        `${filtrados.length} producto${filtrados.length !== 1 ? 's' : ''}` :
        '';

      if (filtrados.length === 0) {
        grid.innerHTML = '';
        empty.style.display = 'block';
        return;
      }
      empty.style.display = 'none';

      grid.innerHTML = filtrados.map(p => {
        const sinStock = p.cantidad_disponible !== null && parseInt(p.cantidad_disponible) === 0;
        const imgHtml = p.imagen_url ?
          `<img src="${h(p.imagen_url)}" alt="${h(p.nombre)}" class="card-img" loading="lazy">` :
          `<div class="card-img" style="display:flex;align-items:center;
              justify-content:center;font-size:3rem;background:var(--crema-dark)">
           🍞
         </div>`;

        return `
      <article class="card" data-id="${p.id}"
               role="listitem" tabindex="0"
               style="cursor:pointer${sinStock ? ';opacity:.7' : ''}">
        <div class="card-img-wrap" style="position:relative">
          ${imgHtml}
          ${sinStock
            ? `<span style="position:absolute;top:8px;right:8px;background:rgba(0,0,0,.55);
                            color:white;font-size:.7rem;padding:3px 8px;border-radius:50px;
                            font-weight:700">Sin stock</span>`
            : ''}
        </div>
        <div class="card-body">
          <span class="badge badge-${h(p.categoria)}" style="font-size:.7rem;margin-bottom:6px">
  ${h(p.categoria || '')}
</span>
          <h3 class="card-nombre">${h(p.nombre)}</h3>
          ${p.descripcion
            ? `<p class="card-desc">${h(p.descripcion.slice(0, 70))}${p.descripcion.length > 70 ? '…' : ''}</p>`
            : ''}
          <div class="card-footer">
            <span class="card-precio">${formatPrecio(p.precio)}</span>
            <span style="font-size:.75rem;color:var(--gris)">
              Stock: ${p.cantidad_disponible}
              </span>
            <div style="display:flex;gap:6px">
              <a href="${SITE_URL}/producto.php?id=${p.id}"
                 class="btn btn-ghost btn-sm"
                 onclick="event.stopPropagation()"
                 style="flex:1;justify-content:center;font-size:0.8rem">Ver</a>
              <button class="btn btn-naranja btn-sm btn-agregar"
                      data-id="${p.id}"
                      style="flex:2"
                      ${sinStock ? 'disabled' : ''}>
                ${sinStock ? 'Sin stock' : '+ Agregar'}
              </button>
            </div>
          </div>
        </div>
      </article>`;
      }).join('');

      // Navegar al producto al hacer clic en la card
      grid.querySelectorAll('.card').forEach(card => {
        card.addEventListener('click', e => {
          if (e.target.closest('button') || e.target.closest('a')) return;
          window.location.href = `${SITE_URL}/producto.php?id=${card.dataset.id}`;
        });
        card.addEventListener('keydown', e => {
          if (e.key === 'Enter')
            window.location.href = `${SITE_URL}/producto.php?id=${card.dataset.id}`;
        });
      });

      // Agregar al carrito
      grid.querySelectorAll('.btn-agregar').forEach(btn => {
        btn.addEventListener('click', e => {
          e.stopPropagation();
          const prod = TODOS_PRODUCTOS.find(p => String(p.id) === btn.dataset.id);
          if (prod && typeof agregarItem === 'function') {
            agregarItem({
              ...prod,
              nombre_panaderia: NOMBRE_PAN
            });
            toast(`${prod.nombre} agregado 🛒`, 'ok');
          }
        });
      });
    }

    /* ══ FILTROS ═══════════════════════════════════════════════════════════════ */
    document.querySelectorAll('#filtros-tienda .filtro').forEach(btn => {
      btn.addEventListener('click', () => {
        document.querySelectorAll('#filtros-tienda .filtro').forEach(b => {
          b.classList.remove('on');
          b.setAttribute('aria-pressed', 'false');
        });
        btn.classList.add('on');
        btn.setAttribute('aria-pressed', 'true');
        catActual = btn.dataset.cat;
        render();
      });
    });

    /* ══ BÚSQUEDA ══════════════════════════════════════════════════════════════ */
    let _debTimer;
    document.getElementById('search-tienda').addEventListener('input', e => {
      clearTimeout(_debTimer);
      _debTimer = setTimeout(() => {
        busqueda = e.target.value;
        render();
      }, 250);
    });

    /* ══ INIT ══════════════════════════════════════════════════════════════════ */
    render();
  </script>

</body>

</html>