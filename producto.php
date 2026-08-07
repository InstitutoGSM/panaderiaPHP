<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';

// ── Parametro ID ──────────────────────────────────────────────────────────
$id = (int)($_GET['id'] ?? 0);
if (!$id) {
  header('Location: ' . SITE_URL . '/catalogo.php');
  exit;
}

// ── Producto ──────────────────────────────────────────────────────────────
$stmt = db()->prepare("
    SELECT p.*,
           v.nombre          AS v_nombre,
           v.nombre_panaderia,
           v.telefono,
           v.avatar_url      AS v_avatar
    FROM   productos p
    JOIN   usuarios  v ON v.id = p.vendedor_id
    WHERE  p.id = ?
  AND p.activo = 1
  AND v.estado_verificacion = 'aprobado'
");
$stmt->execute([$id]);
$p = $stmt->fetch();
if (!$p) {
  header('Location: ' . SITE_URL . '/catalogo.php');
  exit;
}

// ── Fotos adicionales ─────────────────────────────────────────────────────
$fotos_stmt = db()->prepare("
    SELECT url FROM producto_fotos
    WHERE  producto_id = ? ORDER BY orden ASC
");
$fotos_stmt->execute([$id]);
$fotos_extra = $fotos_stmt->fetchAll(PDO::FETCH_COLUMN);

// Imagen principal + extras
$todas_fotos = [];
if ($p['imagen_url'])   $todas_fotos[] = $p['imagen_url'];
foreach ($fotos_extra as $f) $todas_fotos[] = $f;

// ── Calificaciones ────────────────────────────────────────────────────────
$cal_stmt = db()->prepare("
    SELECT AVG(estrellas) AS promedio, COUNT(*) AS total
    FROM calificaciones WHERE producto_id = ?
");
$cal_stmt->execute([$id]);
$cal = $cal_stmt->fetch();
$promedio = round((float)($cal['promedio'] ?? 0), 1);
$total_cal = (int)($cal['total'] ?? 0);

// Calificacion propia (si está logueado)
$mi_cal = 0;
if (esta_logueado()) {
  $mia = db()->prepare("
        SELECT estrellas FROM calificaciones
        WHERE producto_id = ? AND comprador_id = ?
    ");
  $mia->execute([$id, $_SESSION['user_id']]);
  $mi_cal = (int)($mia->fetchColumn() ?: 0);
}

// ── Meta ──────────────────────────────────────────────────────────────────
$nombre_pan = $p['nombre_panaderia'] ?: $p['v_nombre'] ?: 'Panadería';
$sin_stock  = ($p['cantidad_disponible'] !== null && (int)$p['cantidad_disponible'] === 0);

// ── Helpers locales ───────────────────────────────────────────────────────
function stars_html(float $prom): string
{
  $html = '';
  for ($n = 1; $n <= 5; $n++) {
    if ($prom >= $n)       $cls = 'full';
    elseif ($prom >= $n - 0.5) $cls = 'half';
    else                   $cls = 'empty';
    $html .= "<span class=\"star $cls\">★</span>";
  }
  return $html;
}

$page_title = h($p['nombre']);
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

  <?php include __DIR__ . '/includes/header.php'; ?>

  <div class="container">
    <div class="prod-layout">

      <!-- ══ GALERIA ══════════════════════════════════════ -->
      <div>
        <?php if (!empty($todas_fotos)): ?>
          <img id="main-img"
            class="prod-galeria-main"
            src="<?= h($todas_fotos[0]) ?>"
            alt="<?= h($p['nombre']) ?>">

          <?php if (count($todas_fotos) > 1): ?>
            <div class="prod-thumbs">
              <?php foreach ($todas_fotos as $i => $url): ?>
                <img src="<?= h($url) ?>"
                  class="prod-thumb <?= $i === 0 ? 'on' : '' ?>"
                  data-src="<?= h($url) ?>"
                  alt="Foto <?= $i + 1 ?>">
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

        <?php else: ?>
          <div class="prod-galeria-main"
            style="display:flex;align-items:center;justify-content:center;font-size:5rem;background:var(--crema-dark)">
            <?= cat_emoji($p['categoria']) ?>
          </div>
        <?php endif; ?>
      </div>

      <!-- ══ INFO ════════════════════════════════════════════ -->
      <div>
        <span class="badge badge-<?= h($p['categoria']) ?>">
          <?= cat_emoji($p['categoria']) ?> <?= h(cat_label($p['categoria'])) ?>
        </span>

        <h1 style="margin:10px 0 4px"><?= h($p['nombre']) ?></h1>

        <!-- Link a la tienda del vendedor -->
        <a href="<?= SITE_URL ?>/tienda.php?id=<?= $p['vendedor_id'] ?>"
          style="color:var(--naranja);font-weight:700;font-size:0.95rem;display:block;margin-bottom:10px">
          🏪 <?= h($nombre_pan) ?> →
        </a>

        <!-- ── Estrellas ─────────────────────────────────────────────────── -->
        <div class="estrellas-wrap" id="estrellas-prod" data-pid="<?= $p['id'] ?>">
          <div class="estrellas-display" title="<?= $promedio ?> / 5">
            <?= stars_html($promedio) ?>
          </div>
          <span class="estrellas-count">
            <?= $total_cal > 0 ? "$promedio ($total_cal)" : 'Sin calificaciones' ?>
          </span>

          <?php if (esta_logueado()): ?>
            <div class="estrellas-votar" title="Tu calificación">
              <?php for ($n = 1; $n <= 5; $n++): ?>
                <button class="star-btn <?= $mi_cal >= $n ? 'on' : '' ?>"
                  data-val="<?= $n ?>" data-pid="<?= $p['id'] ?>"
                  aria-label="<?= $n ?> estrella<?= $n > 1 ? 's' : '' ?>">★</button>
              <?php endfor; ?>
              <?php if ($mi_cal): ?>
                <span style="font-size:0.72rem;color:var(--gris);margin-left:4px">
                  Tu voto: <?= $mi_cal ?>★
                </span>
              <?php endif; ?>
            </div>
          <?php else: ?>
            <span class="estrellas-hint">
              <a href="<?= SITE_URL ?>/login.php" style="color:var(--naranja)">Iniciá sesión</a>
              para calificar
            </span>
          <?php endif; ?>
        </div>

        <!-- ── Descripcion ──────────────────────────────────────────────── -->
        <?php if ($p['descripcion']): ?>
          <p style="color:var(--gris);margin:14px 0;line-height:1.7">
            <?= h($p['descripcion']) ?>
          </p>
        <?php endif; ?>

        <?php if ($p['dato_extra']): ?>
          <div style="background:var(--crema);padding:10px 14px;border-radius:var(--radio);
                    font-size:0.88rem;margin-bottom:16px">
            ℹ️ <?= h($p['dato_extra']) ?>
          </div>
        <?php endif; ?>

        <!-- ── Precios ──────────────────────────────────────────────────── -->
        <div class="prod-precios">
          <?php if ($p['unidad_venta'] === 'kilo'): ?>
            <div class="prod-precio-row">
              <span>Por kilo</span>
              <span class="prod-precio-val"><?= precio($p['precio']) ?></span>
            </div>
          <?php else: ?>
            <div class="prod-precio-row">
              <span>Unidad</span>
              <span class="prod-precio-val"><?= precio($p['precio']) ?></span>
            </div>
            <?php if ($p['precio_media_docena']): ?>
              <div class="prod-precio-row">
                <span>Media docena (6 u.)</span>
                <span class="prod-precio-val"><?= precio($p['precio_media_docena']) ?></span>
              </div>
            <?php endif; ?>
            <?php if ($p['precio_docena']): ?>
              <div class="prod-precio-row">
                <span>Docena (12 u.)</span>
                <span class="prod-precio-val"><?= precio($p['precio_docena']) ?></span>
              </div>
            <?php endif; ?>
          <?php endif; ?>
        </div>

        <!-- ── Sin stock ────────────────────────────────────────────────── -->
        <?php if ($sin_stock): ?>
          <div class="sin-stock-overlay">
            <div style="font-size:2rem;margin-bottom:6px">😔</div>
            <strong>Sin stock disponible</strong>
            <p style="font-size:0.85rem;color:var(--gris);margin-top:4px">
              Consultá al vendedor si podés hacer un pedido especial
            </p>
          </div>
        <?php endif; ?>

        <!-- ── Botones ──────────────────────────────────────────────────── -->
        <div style="display:flex;flex-direction:column;gap:10px;margin-top:4px">

          <?php if (!$sin_stock): ?>
            <!-- Variante (si hay media docena o docena) -->
            <?php if ($p['precio_media_docena'] || $p['precio_docena']): ?>
              <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:2px">
                <button class="btn btn-ghost btn-sm var-btn on"
                  data-var="unidad"
                  data-precio="<?= $p['precio'] ?>">
                  Unidad
                </button>
                <?php if ($p['precio_media_docena']): ?>
                  <button class="btn btn-ghost btn-sm var-btn"
                    data-var="media_docena"
                    data-precio="<?= $p['precio_media_docena'] ?>">
                    Media docena
                  </button>
                <?php endif; ?>
                <?php if ($p['precio_docena']): ?>
                  <button class="btn btn-ghost btn-sm var-btn"
                    data-var="docena"
                    data-precio="<?= $p['precio_docena'] ?>">
                    Docena
                  </button>
                <?php endif; ?>
              </div>
            <?php endif; ?>

            <button class="btn btn-naranja btn-full" id="btn-agregar">
              🛒 Agregar al carrito
            </button>
          <?php endif; ?>

          <?php if ($p['telefono']): ?>
            <?php
            $tel_clean = preg_replace('/\D/', '', $p['telefono']);
            $msg_wa    = urlencode('Hola! Vi el producto "' . $p['nombre'] . '" en PanaderiaMarket y me interesa 🥖');
            ?>
            <a href="https://wa.me/<?= $tel_clean ?>?text=<?= $msg_wa ?>"
              target="_blank" rel="noopener"
              class="btn btn-full"
              style="background:#25D366;color:white;justify-content:center">
              💬 Consultar por WhatsApp
            </a>
          <?php endif; ?>

          <div style="display:flex;gap:8px">
            <a href="<?= SITE_URL ?>/tienda.php?id=<?= $p['vendedor_id'] ?>"
              class="btn btn-ghost btn-full">
              Ver toda la tienda
            </a>
            <button class="btn btn-ghost" id="btn-compartir" title="Compartir producto">🔗</button>
          </div>
        </div>

      </div>
    </div>

    <!-- ══ CALIFICACIONES RECIENTES ════════════════════════════════════════ -->
    <?php
    $reseñas_stmt = db()->prepare("
        SELECT c.estrellas, c.comentario, c.created_at,
               u.nombre AS comprador
        FROM   calificaciones c
        JOIN   usuarios        u ON u.id = c.comprador_id
        WHERE  c.producto_id = ?
        ORDER  BY c.created_at DESC
        LIMIT  10
    ");
    $reseñas_stmt->execute([$id]);
    $reseñas = $reseñas_stmt->fetchAll();
    ?>
    <?php if (!empty($reseñas)): ?>
      <section style="padding:0 0 60px">
        <h2 style="font-family:'Playfair Display',serif;margin-bottom:20px">
          Calificaciones de compradores
        </h2>
        <div style="display:flex;flex-direction:column;gap:14px">
          <?php foreach ($reseñas as $r): ?>
            <div style="background:var(--blanco);border-radius:var(--radio-lg);
                      padding:16px 20px;box-shadow:var(--sombra)">
              <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px">
                <div style="width:36px;height:36px;border-radius:50%;background:var(--naranja);
                          color:white;display:flex;align-items:center;justify-content:center;
                          font-weight:900;font-size:0.85rem;flex-shrink:0">
                  <?= iniciales($r['comprador']) ?>
                </div>
                <div>
                  <strong style="font-size:0.9rem"><?= h($r['comprador']) ?></strong>
                  <div style="font-size:0.72rem;color:var(--gris)">
                    <?= date('d/m/Y', strtotime($r['created_at'])) ?>
                  </div>
                </div>
                <div style="margin-left:auto;color:#F59E0B;font-size:1rem">
                  <?= str_repeat('★', (int)$r['estrellas']) ?>
                  <?= str_repeat('☆', 5 - (int)$r['estrellas']) ?>
                </div>
              </div>
              <?php if ($r['comentario']): ?>
                <p style="font-size:0.88rem;color:var(--gris);margin:0">
                  <?= h($r['comentario']) ?>
                </p>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endif; ?>

  </div>

  <?php include __DIR__ . '/includes/footer.php'; ?>

  <!-- ── Datos del producto para JS ────────────────────────────────────────── -->
  <script>
    const SITE_URL = '<?= SITE_URL ?>';
    const LOGUEADO = <?= esta_logueado() ? 'true' : 'false' ?>;

    const PRODUCTO = {
      id: <?= $p['id'] ?>,
      nombre: <?= json_encode($p['nombre']) ?>,
      precio: <?= (float)$p['precio'] ?>,
      imagen_url: <?= json_encode($p['imagen_url'] ?? '') ?>,
      categoria: <?= json_encode($p['categoria']) ?>,
      vendedor_id: <?= $p['vendedor_id'] ?>,
      nombre_panaderia: <?= json_encode($nombre_pan) ?>,
      unidad_venta: <?= json_encode($p['unidad_venta'] ?? 'unidad') ?>,
    };

    let varianteSel = 'unidad';
    let precioSel = PRODUCTO.precio;

    /* ── Variantes ─────────────────────────────────────────────────────────── */
    document.querySelectorAll('.var-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        document.querySelectorAll('.var-btn').forEach(b => b.classList.remove('on'));
        btn.classList.add('on');
        varianteSel = btn.dataset.var;
        precioSel = parseFloat(btn.dataset.precio);
      });
    });

    /* ── Agregar al carrito ─────────────────────────────────────────────────── */
    document.getElementById('btn-agregar')?.addEventListener('click', () => {
      if (typeof agregarItem === 'function') {
        agregarItem({
          id: PRODUCTO.id,
          nombre: PRODUCTO.nombre + (varianteSel !== 'unidad' ? ' (' + varianteSel.replace('_', ' ') + ')' : ''),
          precio: precioSel,
          imagen_url: PRODUCTO.imagen_url,
          categoria: PRODUCTO.categoria,
          vendedor_id: PRODUCTO.vendedor_id,
          nombre_panaderia: PRODUCTO.nombre_panaderia,
          variante: varianteSel,
        });
        toast(PRODUCTO.nombre + ' agregado 🛒', 'ok');
      }
    });

    /* ── Galería ────────────────────────────────────────────────────────────── */
    document.querySelectorAll('.prod-thumb').forEach(thumb => {
      thumb.addEventListener('click', () => {
        document.getElementById('main-img').src = thumb.dataset.src;
        document.querySelectorAll('.prod-thumb').forEach(t => t.classList.remove('on'));
        thumb.classList.add('on');
      });
    });

    /* ── Compartir ─────────────────────────────────────────────────────────── */
    document.getElementById('btn-compartir')?.addEventListener('click', () => {
      if (navigator.share) {
        navigator.share({
          title: PRODUCTO.nombre,
          url: location.href
        });
      } else {
        navigator.clipboard.writeText(location.href)
          .then(() => toast('Link copiado al portapapeles 🔗', 'ok'))
          .catch(() => toast('No se pudo copiar el link', 'err'));
      }
    });

    /* ── Calificacion con estrellas ────────────────────────────────────────── */
    const starsWrap = document.getElementById('estrellas-prod');
    if (starsWrap && LOGUEADO) {
      starsWrap.querySelectorAll('.star-btn').forEach(btn => {
        // Hover
        btn.addEventListener('mouseenter', () => {
          const val = parseInt(btn.dataset.val);
          starsWrap.querySelectorAll('.star-btn').forEach(b =>
            b.classList.toggle('hover', parseInt(b.dataset.val) <= val));
        });
        btn.addEventListener('mouseleave', () =>
          starsWrap.querySelectorAll('.star-btn').forEach(b => b.classList.remove('hover')));

        btn.addEventListener('click', async () => {
          const estrellas = parseInt(btn.dataset.val);
          const pid = btn.dataset.pid;

          try {
            const res = await fetch(`${SITE_URL}/api/calificar.php`, {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json'
              },
              body: JSON.stringify({
                producto_id: pid,
                estrellas
              }),
            });
            const data = await res.json();
            if (data.ok) {
              toast('¡Gracias por tu calificación! ⭐', 'ok');
              setTimeout(() => location.reload(), 800);
            } else {
              toast(data.msg || 'Error al calificar', 'err');
            }
          } catch {
            toast('Error de conexión', 'err');
          }
        });
      });
    }
  </script>

</body>

</html>