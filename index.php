<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';

$page_title = 'Inicio';
$extra_css  = 'landing.css';

// ── Productos destacados (los mas pedidos) ─────────────────────────────
$destacados = db()->query("
  SELECT p.id, p.nombre, p.precio, p.categoria, p.imagen_url,
         u.nombre_panaderia, u.nombre AS nombre_vendedor, u.id AS vendedor_id,
         COUNT(pi.id) AS total_pedidos
  FROM   productos p
  JOIN   usuarios  u  ON u.id  = p.vendedor_id
  LEFT JOIN pedido_items pi ON pi.producto_id = p.id
  WHERE  p.activo = 1
    AND  u.estado_verificacion = 'aprobado'
  GROUP BY p.id
  ORDER BY total_pedidos DESC, p.created_at DESC
  LIMIT 9
")->fetchAll();

// ── Panaderias aprobadas ───────────────────────────────────────────────
$panaderias = db()->query("
  SELECT id, nombre, nombre_panaderia, descripcion, avatar_url
  FROM   usuarios
  WHERE  tipo = 'vendedor' AND estado_verificacion = 'aprobado'
  ORDER BY created_at DESC
  LIMIT 8
")->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<!-- ══ HERO ══════════════════════════════════════════════════════════════ -->
<section class="hero-landing" aria-label="Bienvenida">
  <div class="hero-content">
    <div class="hero-texto">
      <h1>El pan artesanal<br>que <span>estabas buscando</span> 🥖</h1>
      <p>
        Conectamos panaderías artesanales de Catamarca con vos.
        Productos frescos, hechos con amor y entregados bajo pedido.
      </p>
      <div class="hero-btns">
        <a href="<?= SITE_URL ?>/catalogo.php" class="btn-hero-primary">
          🛍️ Ver productos
        </a>
      </div>
    </div>

    <div class="hero-visual" aria-hidden="true">
      <div class="hero-cards-grid">
        <div class="hero-prod-card" style="--c1:#8B4513;--c2:#D2691E">
          <div class="hpc-img">🍞</div>
          <div class="hpc-body">
            <div class="hpc-titulo">Pan Artesanal</div>
            <div class="hpc-sub">Horneado diario · Sin conservantes</div>
            <div class="hpc-tag">⭐ Más pedido</div>
          </div>
        </div>
        <div class="hero-prod-card" style="--c1:#B8860B;--c2:#FFD700">
          <div class="hpc-img">🥐</div>
          <div class="hpc-body">
            <div class="hpc-titulo">Facturas</div>
            <div class="hpc-sub">Medialunas, cuernitos y más</div>
            <div class="hpc-tag">🔥 Tendencia</div>
          </div>
        </div>
        <div class="hero-prod-card" style="--c1:#6B2737;--c2:#C0392B">
          <div class="hpc-img">🎂</div>
          <div class="hpc-body">
            <div class="hpc-titulo">Tortas</div>
            <div class="hpc-sub">Para cada ocasión especial</div>
            <div class="hpc-tag">🎉 A pedido</div>
          </div>
        </div>
        <div class="hero-prod-card" style="--c1:#2C6E49;--c2:#52B788">
          <div class="hpc-img">🍪</div>
          <div class="hpc-body">
            <div class="hpc-titulo">Galletas</div>
            <div class="hpc-sub">Recetas tradicionales</div>
            <div class="hpc-tag">💚 Sin TACC</div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ══ COMO FUNCIONA ══════════════════════════════════════════════════════ -->
<section class="como-sec" aria-label="Cómo funciona">
  <div class="como-inner">
    <div class="sec-label">Simple y rápido</div>
    <h2 class="sec-titulo-lg">¿Cómo funciona? 🤔</h2>
    <p class="sec-sub">En tres pasos tenés tu pedido listo.</p>
    <div class="pasos-grid">
      <div class="paso-card" data-num="1">
        <span class="paso-ico">🔍</span>
        <h3>Explorá</h3>
        <p>Navegá el catálogo de panaderías y productos artesanales de Catamarca.</p>
      </div>
      <div class="paso-card" data-num="2">
        <span class="paso-ico">🛒</span>
        <h3>Elegí</h3>
        <p>Agregá lo que querés al carrito y elegí cómo querés pagarlo.</p>
      </div>
      <div class="paso-card" data-num="3">
        <span class="paso-ico">📦</span>
        <h3>Recibí</h3>
        <p>El vendedor prepara tu pedido y coordinan la entrega directamente.</p>
      </div>
      <div class="paso-card" data-num="4">
        <span class="paso-ico">⭐</span>
        <h3>Calificá</h3>
        <p>Dejá tu opinión para ayudar a otros compradores a elegir mejor.</p>
      </div>
    </div>
  </div>
</section>

<!-- ══ PRODUCTOS DESTACADOS ═══════════════════════════════════════════════ -->
<section class="destacados-sec" aria-label="Productos destacados">
  <div class="destacados-inner">
    <div class="sec-label">Lo mejor de la semana</div>
    <h2 class="sec-titulo-lg">Productos destacados ✨</h2>
    <p class="sec-sub">🏆 Más vendidos · ⭐ Mejor calificados · 🔥 Tendencia</p>

    <?php if (empty($destacados)): ?>
      <p style="color:var(--gris);padding:40px 0">
        Aún no hay productos. ¡Registrá tu panadería y empezá a vender!
      </p>
    <?php else: ?>
      <div class="carrusel-wrap" id="carrusel-wrap">
        <div class="carrusel-track" id="carrusel-track">
          <?php foreach ($destacados as $i => $d):
            $pan = $d['nombre_panaderia'] ?: $d['nombre_vendedor'];
            $badge = $i === 0 ? ['cls' => 'badge-vendido', 'ico' => '🏆', 'txt' => 'Más vendido']
              : ($i === 1 ? ['cls' => 'badge-calificado', 'ico' => '⭐', 'txt' => 'Mejor calificado']
                : ['cls' => 'badge-tendencia', 'ico' => '🔥', 'txt' => 'Tendencia']);
          ?>
            <a href="<?= SITE_URL ?>/producto.php?id=<?= $d['id'] ?>"
              class="carrusel-card" data-id="<?= $d['id'] ?>"
              data-nombre="<?= h($d['nombre']) ?>"
              data-precio="<?= $d['precio'] ?>"
              data-panaderia="<?= h($pan) ?>"
              data-vendedor="<?= $d['vendedor_id'] ?>"
              data-imagen="<?= h($d['imagen_url'] ?? '') ?>">
              <?php if (!empty($d['imagen_url'])): ?>
                <img src="<?= h($d['imagen_url']) ?>" class="carrusel-img" alt="<?= h($d['nombre']) ?>">
              <?php else: ?>
                <div class="carrusel-img-ph"><?= cat_emoji($d['categoria']) ?></div>
              <?php endif; ?>
              <div class="carrusel-body">
                <span class="carrusel-badge <?= $badge['cls'] ?>"><?= $badge['ico'] ?> <?= $badge['txt'] ?></span>
                <div class="carrusel-nombre"><?= h($d['nombre']) ?></div>
                <div class="carrusel-pan"><?= h($pan) ?></div>
                <div class="carrusel-precio"><?= precio((float)$d['precio']) ?></div>
              </div>
            </a>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="carrusel-controles">
        <button class="carrusel-btn" id="carr-prev" aria-label="Anterior">←</button>
        <div class="carrusel-dots" id="carrusel-dots"></div>
        <button class="carrusel-btn" id="carr-next" aria-label="Siguiente">→</button>
      </div>
    <?php endif; ?>
  </div>
</section>

<!-- ══ PANADERIAS ═════════════════════════════════════════════════════════ -->
<section class="pans-sec" aria-label="Panaderías">
  <div class="pans-inner">
    <div class="sec-label">Quiénes somos</div>
    <h2 class="sec-titulo-lg">Nuestras panaderías 🏪</h2>
    <p class="sec-sub">Todas verificadas y habilitadas para elaborar productos en Catamarca.</p>

    <?php if (empty($panaderias)): ?>
      <p style="color:var(--gris);padding:32px 0">Aún no hay panaderías registradas.</p>
    <?php else: ?>
      <div class="pans-grid">
        <?php foreach ($panaderias as $pan):
          $nombre = $pan['nombre_panaderia'] ?: $pan['nombre'];
        ?>
          <a href="<?= SITE_URL ?>/tienda.php?id=<?= $pan['id'] ?>" class="pan-landing-card">
            <div class="pan-landing-avatar">
              <?php if (!empty($pan['avatar_url'])): ?>
                <img src="<?= h($pan['avatar_url']) ?>" alt="<?= h($nombre) ?>">
              <?php else: ?>
                <?= iniciales($nombre) ?>
              <?php endif; ?>
            </div>
            <div class="pan-landing-nombre"><?= h($nombre) ?></div>
            <?php if (!empty($pan['descripcion'])): ?>
              <div class="pan-landing-desc"><?= h(mb_substr($pan['descripcion'], 0, 60)) ?>…</div>
            <?php endif; ?>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>

<!-- ══ CTA FINAL ══════════════════════════════════════════════════════════ -->
<section class="cta-sec" aria-label="Llamada a la acción">
  <h2>¿Tenés una panadería? 🥖</h2>
  <p>Registrate gratis, subí tus productos y empezá a recibir pedidos hoy mismo.</p>
  <a href="<?= SITE_URL ?>/login.php?tab=registro" class="btn-hero-primary" style="display:inline-flex">
    Registrar mi panadería →
  </a>
</section>

<!-- ══ JS (LANDING) ═══════════════════════════════════════════════════ -->
<script>
  // Carrusel
  const track = document.getElementById('carrusel-track');
  const dotsEl = document.getElementById('carrusel-dots');
  if (track) {
    const cards = track.querySelectorAll('.carrusel-card');
    const visible = window.innerWidth < 768 ? 1 : window.innerWidth < 1024 ? 2 : 3;
    const total = Math.ceil(cards.length / visible);
    let current = 0;

    for (let i = 0; i < total; i++) {
      const d = document.createElement('div');
      d.className = 'carrusel-dot' + (i === 0 ? ' on' : '');
      d.addEventListener('click', () => irA(i));
      dotsEl?.appendChild(d);
    }

    function irA(idx) {
      current = Math.max(0, Math.min(idx, total - 1));
      const ancho = track.parentElement.offsetWidth;
      track.style.transform = `translateX(-${current * ancho}px)`;
      dotsEl?.querySelectorAll('.carrusel-dot').forEach((d, i) => {
        d.classList.toggle('on', i === current);
      });
    }

    document.getElementById('carr-next')?.addEventListener('click', () => irA(current + 1));
    document.getElementById('carr-prev')?.addEventListener('click', () => irA(current - 1));

    // Btn agregar al carrito desde la card
    track.querySelectorAll('.carrusel-card').forEach(card => {
      card.addEventListener('click', e => {
        if (e.target.closest('.btn-agregar')) {
          e.preventDefault();
          agregarItem({
            id: +card.dataset.id,
            nombre: card.dataset.nombre,
            precio: +card.dataset.precio,
            panaderia: card.dataset.panaderia,
            vendedor_id: +card.dataset.vendedor,
            imagen_url: card.dataset.imagen,
          });
        }
      });
    });
  }
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>