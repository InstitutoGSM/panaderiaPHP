<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';

$page_title = 'Catálogo';
$extra_css  = 'catalogo.css';

// ── Parametros de filtro (GET) ────────────────────────────────────────────
$q          = trim($_GET['q']        ?? '');
$cat        = $_GET['cat']           ?? 'todos';
$vendedor   = (int)($_GET['vendedor'] ?? 0);
$orden      = $_GET['orden']         ?? 'reciente';

$cats_valid = ['todos', 'pan', 'facturas', 'galletas', 'cakes', 'otro'];
if (!in_array($cat, $cats_valid)) $cat = 'todos';

$ordenes = [
  'reciente'    => 'p.created_at DESC',
  'precio_asc'  => 'p.precio ASC',
  'precio_desc' => 'p.precio DESC',
  'nombre'      => 'p.nombre ASC',
];
$order_sql = $ordenes[$orden] ?? 'p.created_at DESC';

// ── Consulta de productos ────────────────────────────────────────────────
$where  = ['p.activo = 1', "u.estado_verificacion = 'aprobado'", "u.tipo = 'vendedor'", 'p.cantidad_disponible > 0'];
$params = [];

if ($cat !== 'todos') {
  $where[]  = 'p.categoria = ?';
  $params[] = $cat;
}
if ($q !== '') {
  $where[]  = '(p.nombre LIKE ? OR p.descripcion LIKE ?)';
  $params[] = '%' . $q . '%';
  $params[] = '%' . $q . '%';
}
if ($vendedor > 0) {
  $where[]  = 'p.vendedor_id = ?';
  $params[] = $vendedor;
}

$sql = "
    SELECT p.*,
           u.nombre_panaderia, u.nombre AS nombre_vendedor,
           u.id AS uid,
           COALESCE(AVG(c.estrellas), 0) AS promedio,
           COUNT(DISTINCT c.id)           AS total_votos
    FROM   productos p
    JOIN   usuarios  u ON u.id = p.vendedor_id
    LEFT JOIN calificaciones c ON c.producto_id = p.id
    WHERE  " . implode(' AND ', $where) . "
    GROUP BY p.id
    ORDER BY $order_sql
";

$stmt = db()->prepare($sql);
$stmt->execute($params);
$productos = $stmt->fetchAll();

// ── Panaderias aprobadas para el sidebar ─────────────────────────────────
$panaderias = db()->query("
    SELECT id, nombre, nombre_panaderia, avatar_url
    FROM   usuarios
    WHERE  tipo = 'vendedor' AND estado_verificacion = 'aprobado'
    ORDER BY nombre_panaderia, nombre
")->fetchAll();

// ── Sucursales por panaderia ──────────────────────────────────────────────
$suc_por_pan = [];
try {
  $suc_all = db()->query("
    SELECT s.*
    FROM sucursales s
    INNER JOIN usuarios u ON u.id = s.vendedor_id
    WHERE s.activo = 1
      AND s.estado = 'activa'
      AND u.tipo = 'vendedor'
      AND u.estado_verificacion = 'aprobado'
    ORDER BY s.vendedor_id, s.nombre
")->fetchAll();
  foreach ($suc_all as $s) $suc_por_pan[$s['vendedor_id']][] = $s;
} catch (Exception $e) {
}

// ── Panaderia activa (para el banner) ────────────────────────────────────
$pan_activa = null;
if ($vendedor > 0) {
  $pa = db()->prepare("
    SELECT *
    FROM usuarios
    WHERE id = ?
      AND tipo = 'vendedor'
      AND estado_verificacion = 'aprobado'
");
  $pa->execute([$vendedor]);
  $pan_activa = $pa->fetch() ?: null;
}

include __DIR__ . '/includes/header.php';
?>

<!-- ══ HEADER ══════════════════════════════════════════════════════════════ -->
<div class="catalogo-header">
  <div class="container">
    <h1>Catálogo de productos</h1>
    <p>Panes, facturas, tortas y más de panaderías artesanales de Catamarca</p>
  </div>
</div>

<!-- ══ BARRA DE BUSQUEDA ════════════════════════════════════════════════════ -->
<form method="GET" action="catalogo.php" id="form-filtros">
  <div class="catalogo-search-bar">
    <div class="catalogo-search-inner">

      <button type="button" class="btn btn-ghost btn-sm btn-toggle-sidebar"
        id="btn-toggle-sidebar">🏪 Panaderías</button>

      <div class="search-wrap">
        <span class="ico">🔍</span>
        <input type="search" name="q" id="search-catalogo"
          value="<?= h($q) ?>"
          placeholder="Buscar productos…" autocomplete="off">
      </div>

      <select name="orden" id="ordenar" onchange="this.form.submit()">
        <option value="reciente" <?= $orden === 'reciente'    ? 'selected' : '' ?>>Más recientes</option>
        <option value="precio_asc" <?= $orden === 'precio_asc'  ? 'selected' : '' ?>>Menor precio</option>
        <option value="precio_desc" <?= $orden === 'precio_desc' ? 'selected' : '' ?>>Mayor precio</option>
        <option value="nombre" <?= $orden === 'nombre'      ? 'selected' : '' ?>>A–Z</option>
      </select>

      <!-- Preservar otros filtros activos -->
      <?php if ($cat !== 'todos'): ?><input type="hidden" name="cat" value="<?= h($cat) ?>"><?php endif; ?>
      <?php if ($vendedor > 0):    ?><input type="hidden" name="vendedor" value="<?= $vendedor ?>"><?php endif; ?>

      <button type="submit" class="btn btn-naranja btn-sm">Buscar</button>
    </div>
  </div>

  <!-- ══ LAYOUT ═══════════════════════════════════════════════════════════════ -->
  <div class="catalogo-layout">

    <!-- ── Sidebar panaderias ── -->
    <aside class="sidebar-pan" id="sidebar-pan">
      <h3>🏪 Panaderías</h3>
      <div class="search-pan">
        <span class="ico">🔍</span>
        <input type="search" id="search-panaderias"
          placeholder="Buscar panadería…" autocomplete="off">
      </div>

      <div id="panaderias-list">
        <a href="catalogo.php?cat=<?= h($cat) ?>&q=<?= urlencode($q) ?>&orden=<?= h($orden) ?>"
          class="pan-chip <?= $vendedor === 0 ? 'on' : '' ?>">
          <div class="pan-chip-avatar" style="background:var(--marron)">🏪</div>
          Todas las panaderías
        </a>

        <?php foreach ($panaderias as $p):
          $nombre  = $p['nombre_panaderia'] ?: $p['nombre'];
          $activa  = $vendedor === (int)$p['id'];
          $suc_pan = $suc_por_pan[$p['id']] ?? [];
        ?>
          <button type="submit" name="vendedor" value="<?= $p['id'] ?>"
            class="pan-chip <?= $activa ? 'on' : '' ?>"
            style="display:flex;align-items:center;gap:6px;width:100%;text-align:left">
            <div class="pan-chip-avatar"
              style="<?= !empty($p['avatar_url']) ? "background:url('{$p['avatar_url']}') center/cover;color:transparent" : '' ?>">
              <?= empty($p['avatar_url']) ? iniciales($nombre) : '' ?>
            </div>
            <span style="flex:1"><?= h($nombre) ?></span>
            <?php if (!empty($suc_pan)): ?>
              <span style="font-size:0.7rem;background:var(--naranja);color:white;
                         border-radius:50px;padding:1px 7px;white-space:nowrap">
                <?= count($suc_pan) ?> suc.
              </span>
            <?php endif; ?>
          </button>

          <?php if ($activa && !empty($suc_pan)): ?>
            <div style="margin:-4px 0 8px 8px;padding-left:10px;border-left:3px solid var(--naranja)">
              <?php foreach ($suc_pan as $s): ?>
                <div style="font-size:0.78rem;color:var(--gris);padding:4px 0;line-height:1.4">
                  <a href="sucursal.php?id=<?= $s['id'] ?>"
   style="color:var(--marron);text-decoration:none;font-weight:700">
  🏬 <?= h($s['nombre']) ?>
</a>
                  <?= !empty($s['direccion']) ? '<br><span style="padding-left:14px">📍 ' . h($s['direccion']) . '</span>' : '' ?>
                  <?= !empty($s['telefono'])  ? '<br><span style="padding-left:14px">📞 ' . h($s['telefono']) . '</span>'  : '' ?>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

        <?php endforeach; ?>
      </div>
    </aside>

    <!-- ── Contenido principal ── -->
    <div>
      <div class="filtros-cat">
        <!-- filtros -->
        <?php
        $categorias = [
          'todos'    => 'Todos',
          'pan'      => '🍞 Pan',
          'facturas' => '🥐 Facturas',
          'galletas' => '🍪 Galletas',
          'cakes'    => '🎂 Cakes',
          'otro'     => '✨ Otro',
        ];
        foreach ($categorias as $key => $label): ?>
          <a href="catalogo.php?cat=<?= $key ?>&q=<?= urlencode($q) ?>&orden=<?= h($orden) ?><?= $vendedor ? '&vendedor=' . $vendedor : '' ?>"
            class="filtro <?= $cat === $key ? 'on' : '' ?>">
            <?= $label ?>
          </a>
        <?php endforeach; ?>
      </div>

      <?php if ($pan_activa && !empty($suc_por_pan[$vendedor])): ?>
        <div style="background:var(--blanco);border-radius:var(--radio-lg);
                    padding:16px 20px;margin-bottom:16px;box-shadow:var(--sombra)">
          <p style="font-weight:700;font-family:'Playfair Display',serif;
                    margin:0 0 10px;color:var(--marron)">
            🏬 Sucursales de <?= h($pan_activa['nombre_panaderia'] ?: $pan_activa['nombre']) ?>
          </p>
          <div style="display:flex;flex-wrap:wrap;gap:10px">
            <?php foreach ($suc_por_pan[$vendedor] as $s): ?>
              <div style="background:var(--crema);border-radius:var(--radio);
                          padding:10px 14px;font-size:0.83rem;min-width:180px">
                <a href="sucursal.php?id=<?= $s['id'] ?>"
   style="display:block;margin-bottom:4px;color:var(--marron);
          text-decoration:none;font-weight:700">
  <?= h($s['nombre']) ?>
</a>
                <?php if (!empty($s['direccion'])): ?>
                  <span style="color:var(--gris)">📍 <?= h($s['direccion']) ?></span><br>
                <?php endif; ?>
                <?php if (!empty($s['telefono'])): ?>
                  <span style="color:var(--gris)">📞 <?= h($s['telefono']) ?></span>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>

      <div class="toolbar">
        <span class="toolbar-count">
          <?php
          $n = count($productos);
          echo $n === 0 ? 'Sin resultados'
            : ($n === 1 ? '1 producto encontrado'
              : "$n productos encontrados");
          ?>
        </span>
      </div>

      <!-- Grid de productos -->
      <?php if (empty($productos)): ?>
        <div style="text-align:center;padding:60px 0;color:var(--gris)">
          <span style="font-size:3rem;display:block;margin-bottom:12px">🔍</span>
          <h3 style="font-family:'Playfair Display',serif;color:var(--marron);margin-bottom:6px">
            Sin resultados
          </h3>
          <p>Probá con otra búsqueda o categoría</p>
        </div>
      <?php else: ?>
        <div class="grid-productos" id="productos-grid">
          <?php foreach ($productos as $prod):
            $pan_nombre = $prod['nombre_panaderia'] ?: $prod['nombre_vendedor'];
            $promedio   = (float)$prod['promedio'];
            $estrellas  = '';
            for ($s = 1; $s <= 5; $s++) {
              if ($s <= floor($promedio))      $estrellas .= '<span class="star full">★</span>';
              elseif ($s - 0.5 <= $promedio)  $estrellas .= '<span class="star half">★</span>';
              else                             $estrellas .= '<span class="star">★</span>';
            }
          ?>
            <div class="card"
              data-nombre="<?= h(strtolower($prod['nombre'])) ?>"
              data-pan="<?= h(strtolower($pan_nombre)) ?>">
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
                  <a href="tienda.php?id=<?= $prod['uid'] ?>"
                    style="color:var(--naranja)">
                    🏪 <?= h($pan_nombre) ?>
                  </a>
                </span>
                <div class="card-nombre"><?= h($prod['nombre']) ?></div>

                <?php if ($prod['total_votos'] > 0): ?>
                  <div class="estrellas-wrap" style="margin:4px 0 8px">
                    <div class="estrellas-display"><?= $estrellas ?></div>
                    <span class="estrellas-count">(<?= $prod['total_votos'] ?>)</span>
                  </div>
                <?php endif; ?>

                <div class="card-precio"><?= precio((float)$prod['precio']) ?></div>

                <div style="display:flex;gap:8px">
                  <a href="producto.php?id=<?= $prod['id'] ?>"
                    class="btn btn-ghost btn-sm" style="flex:1;justify-content:center">
                    Ver más
                  </a>
                  <button class="btn btn-naranja btn-sm btn-agregar"
                    data-id="<?= $prod['id'] ?>"
                    data-nombre="<?= h($prod['nombre']) ?>"
                    data-precio="<?= $prod['precio'] ?>"
                    data-panaderia="<?= h($pan_nombre) ?>"
                    data-vendedor="<?= $prod['uid'] ?>"
                    data-imagen="<?= h($prod['imagen_url'] ?? '') ?>">
                    + Agregar
                  </button>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

  </div>
</form>

<?php include __DIR__ . '/includes/footer.php'; ?>

<script>
  // ── Toggle sidebar en mobile ──────────────────────────────────────────────
  document.getElementById('btn-toggle-sidebar')?.addEventListener('click', () => {
    document.getElementById('sidebar-pan').classList.toggle('open');
  });

  // ── Busqueda en tiempo real dentro del sidebar ───────────────────────────
  document.getElementById('search-panaderias')?.addEventListener('input', function() {
    const q = this.value.toLowerCase();
    document.querySelectorAll('#panaderias-list .pan-chip').forEach(chip => {
      chip.style.display = chip.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
  });

  // ── Agregar al carrito ───────────────────────────────────────────────────
  document.querySelectorAll('.btn-agregar').forEach(btn => {
    btn.addEventListener('click', e => {
      e.preventDefault();
      agregarItem({
        id: +btn.dataset.id,
        nombre: btn.dataset.nombre,
        precio: +btn.dataset.precio,
        panaderia: btn.dataset.panaderia,
        vendedor_id: +btn.dataset.vendedor,
        imagen_url: btn.dataset.imagen,
      });
    });
  });

  // ── Busqueda instantánea en el grid ───────────────────────
  const searchInput = document.getElementById('search-catalogo');
  let timer;
  searchInput?.addEventListener('input', function() {
    clearTimeout(timer);
    timer = setTimeout(() => {
      const q = this.value.toLowerCase().trim();
      let visible = 0;
      document.querySelectorAll('#productos-grid .card').forEach(card => {
        const match = !q ||
          card.dataset.nombre?.includes(q) ||
          card.dataset.pan?.includes(q);
        card.style.display = match ? '' : 'none';
        if (match) visible++;
      });
    }, 200);
  });
</script>