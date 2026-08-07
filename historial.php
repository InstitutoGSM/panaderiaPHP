<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';
requerir_login();

$u   = usuario_actual();
$uid = $u['id'];

$msg_ok  = '';
$msg_err = '';

/* ══ POST: enviar calificación ═══════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'calificar') {
    $pedido_id   = (int)($_POST['pedido_id']   ?? 0);
    $vendedor_id = (int)($_POST['vendedor_id'] ?? 0);
    $puntaje     = (int)($_POST['puntaje']     ?? 0);
    $comentario  = trim($_POST['comentario']   ?? '') ?: null;

    if ($puntaje < 1 || $puntaje > 5) {
        $msg_err = 'Seleccioná una calificación entre 1 y 5 estrellas.';
    } else {
        // Verificar que el pedido le pertenece al comprador y está entregado
        $chk = db()->prepare("
            SELECT id FROM pedidos
            WHERE id=? AND comprador_id=? AND estado='entregado'
        ");
        $chk->execute([$pedido_id, $uid]);

        if (!$chk->fetch()) {
            $msg_err = 'No podés calificar ese pedido.';
        } else {
            // Insertar o actualizar calificación
            $existe = db()->prepare("SELECT id FROM calificaciones WHERE pedido_id=? AND comprador_id=?");
            $existe->execute([$pedido_id, $uid]);

            if ($existe->fetch()) {
                db()->prepare("
                    UPDATE calificaciones
                    SET puntaje=?, comentario=?, updated_at=NOW()
                    WHERE pedido_id=? AND comprador_id=?
                ")->execute([$puntaje, $comentario, $pedido_id, $uid]);
            } else {
                db()->prepare("
                    INSERT INTO calificaciones (pedido_id, comprador_id, vendedor_id, puntaje, comentario)
                    VALUES (?,?,?,?,?)
                ")->execute([$pedido_id, $uid, $vendedor_id, $puntaje, $comentario]);
            }
            $msg_ok = '¡Gracias por tu calificación! ⭐';
        }
    }
}

/* ══ DATOS ════════════════════════════════════════════════════════════════ */
$filtro = $_GET['estado'] ?? 'todos';
$estados_validos = ['todos','pendiente','confirmado','listo','entregado'];
if (!in_array($filtro, $estados_validos)) $filtro = 'todos';

$where = $filtro === 'todos' ? '' : "AND p.estado = '$filtro'";

$pedidos_q = db()->prepare("
    SELECT p.*,
           u.nombre          AS nombre_vendedor,
           u.nombre_panaderia AS panaderia,
           u.avatar_url      AS avatar_vendedor,
           u.id              AS vid
    FROM   pedidos p
    JOIN   usuarios u ON u.id = p.vendedor_id
    WHERE  p.comprador_id = ?
    $where
    ORDER  BY p.created_at DESC
");
$pedidos_q->execute([$uid]);
$pedidos = $pedidos_q->fetchAll();

/* Items de todos los pedidos */
$items_map = [];
if (!empty($pedidos)) {
    $pids    = implode(',', array_column($pedidos, 'id'));
    $items_q = db()->query("SELECT * FROM pedido_items WHERE pedido_id IN ($pids) ORDER BY id");
    foreach ($items_q->fetchAll() as $it) {
        $items_map[$it['pedido_id']][] = $it;
    }
}

/* Calificaciones ya enviadas */
$cals_map = [];
if (!empty($pedidos)) {
    $pids   = implode(',', array_column($pedidos, 'id'));
    $cals_q = db()->query("SELECT * FROM calificaciones WHERE pedido_id IN ($pids) AND comprador_id = $uid");
    foreach ($cals_q->fetchAll() as $c) {
        $cals_map[$c['pedido_id']] = $c;
    }
}

/* Medios de pago legibles */
$medios_label = [
    'efectivo'      => '💵 Efectivo',
    'transferencia' => '📲 Transferencia',
    'debito'        => '💳 Débito',
    'credito'       => '💳 Crédito',
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Mis Pedidos — <?= SITE_NAME ?></title>
  <link rel="stylesheet" href="<?= SITE_URL ?>/css/global.css">
  <link rel="stylesheet" href="<?= SITE_URL ?>/css/historial.css">
</head>
<body>
<?php include __DIR__ . '/includes/header.php'; ?>

<div class="historial-wrap">

  <h1>Mis pedidos 📦</h1>
  <p class="historial-sub">
    <?= count($pedidos) ?> pedido<?= count($pedidos) !== 1 ? 's' : '' ?>
    <?= $filtro !== 'todos' ? '· filtrando por <strong>' . estado_label($filtro) . '</strong>' : '' ?>
  </p>

  <!-- Alertas -->
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

  <!-- Filtros de estado -->
  <div class="hist-filtros">
    <?php foreach (['todos'=>'Todos','pendiente'=>'Pendiente','confirmado'=>'Confirmado','listo'=>'Listo','entregado'=>'Entregado'] as $k => $v): ?>
      <a href="historial.php?estado=<?= $k ?>"
         class="filtro <?= $filtro === $k ? 'on' : '' ?>">
        <?= $v ?>
        <?php if ($k !== 'todos'):
          $cnt = count(array_filter($pedidos, fn($p) => $p['estado'] === $k));
          if ($cnt > 0): ?>
            <span style="background:var(--naranja);color:white;border-radius:50px;
                         padding:0 6px;font-size:0.7rem;margin-left:4px">
              <?= $cnt ?>
            </span>
          <?php endif;
        endif; ?>
      </a>
    <?php endforeach; ?>
  </div>

  <!-- Lista de pedidos -->
  <?php if (empty($pedidos)): ?>
    <div class="hist-empty">
      <span class="hist-empty-ico">📭</span>
      <h2>No hay pedidos <?= $filtro !== 'todos' ? 'con este estado' : 'aún' ?></h2>
      <p style="margin:6px 0 24px">
        <?= $filtro !== 'todos' ? 'Probá con otro filtro.' : 'Explorá el catálogo y hacé tu primer pedido.' ?>
      </p>
      <a href="<?= SITE_URL ?>/catalogo.php" class="btn btn-naranja">Ver catálogo</a>
    </div>

  <?php else: ?>
    <?php foreach ($pedidos as $ped): ?>
      <?php
        $items    = $items_map[$ped['id']] ?? [];
        $cal      = $cals_map[$ped['id']] ?? null;
        $nombre_p = $ped['panaderia'] ?: $ped['nombre_vendedor'];
      ?>
      <div class="hist-card" id="card-<?= $ped['id'] ?>">

        <!-- Cabecera (clickable para expandir) -->
        <div class="hist-card-top" onclick="toggleCard(<?= $ped['id'] ?>)">
          <div>
            <div class="hist-panaderia">
              <?= avatar_html(['nombre' => $nombre_p, 'avatar_url' => $ped['avatar_vendedor'] ?? null,
                               'nombre_panaderia' => $nombre_p], '28px', '0.7rem') ?>
              <span style="margin-left:8px"><?= h($nombre_p) ?></span>
            </div>
            <div class="hist-fecha">
              <?= date('d/m/Y H:i', strtotime($ped['created_at'])) ?>
            </div>
            <div class="hist-ticket">Pedido #<?= (int)$ped['id'] ?></div>
          </div>
          <div class="hist-right">
            <span class="estado-badge estado-<?= $ped['estado'] ?>">
              <?= estado_label($ped['estado']) ?>
            </span>
            <span class="hist-total"><?= precio((float)$ped['total']) ?></span>
            <span class="hist-chevron">▼</span>
          </div>
        </div>

        <!-- Detalle expandible -->
        <div class="hist-detalle">

          <!-- Ítems -->
          <?php foreach ($items as $it): ?>
            <div class="hist-item">
              <span class="hist-item-nombre">
                <?= h($it['nombre_producto']) ?> × <?= $it['cantidad'] ?>
              </span>
              <span class="hist-item-precio">
                <?= precio($it['precio_unitario'] * $it['cantidad']) ?>
              </span>
            </div>
          <?php endforeach; ?>

          <!-- Metadatos del pedido -->
          <div class="hist-meta">
            <span>💳 <?= $medios_label[$ped['metodo_pago']] ?? h($ped['metodo_pago']) ?></span>
            <?php if ($ped['notas']): ?>
              <span>📝 <?= h($ped['notas']) ?></span>
            <?php endif; ?>
            <span>🧾 Total: <strong><?= precio((float)$ped['total']) ?></strong></span>
          </div>

          <!-- Calificación (solo pedidos entregados) -->
          <?php if ($ped['estado'] === 'entregado'): ?>
            <div class="rating-wrap">
              <?php if ($cal): ?>
                <!-- Ya calificó -->
                <div class="rating-enviada">
                  <span class="estrellas-static">
                    <?= str_repeat('★', (int)$cal['puntaje']) . str_repeat('☆', 5 - (int)$cal['puntaje']) ?>
                  </span>
                  <span>Tu calificación</span>
                  <?php if ($cal['comentario']): ?>
                    <span>· "<?= h($cal['comentario']) ?>"</span>
                  <?php endif; ?>
                </div>
              <?php else: ?>
                <!-- Formulario calificar -->
                <p>⭐ ¿Cómo estuvo tu pedido?</p>
                <form method="POST" class="form-cal" data-pid="<?= $ped['id'] ?>">
                  <input type="hidden" name="accion"      value="calificar">
                  <input type="hidden" name="pedido_id"   value="<?= $ped['id'] ?>">
                  <input type="hidden" name="vendedor_id" value="<?= $ped['vid'] ?>">
                  <input type="hidden" name="puntaje"     id="puntaje-<?= $ped['id'] ?>" value="0">

                  <div class="estrellas" id="estrellas-<?= $ped['id'] ?>">
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                      <span class="estrella" data-val="<?= $i ?>"
                            onclick="setEstrella(<?= $ped['id'] ?>, <?= $i ?>)">☆</span>
                    <?php endfor; ?>
                  </div>

                  <div class="field" style="margin-bottom:10px">
                    <textarea name="comentario" rows="2"
                              placeholder="Comentario opcional..."
                              style="font-size:0.85rem;resize:none"></textarea>
                  </div>

                  <button type="submit" class="btn btn-naranja btn-sm">
                    Enviar calificación
                  </button>
                </form>
              <?php endif; ?>
            </div>
          <?php endif; ?>

        </div><!-- /hist-detalle -->
      </div><!-- /hist-card -->
    <?php endforeach; ?>
  <?php endif; ?>

</div><!-- /historial-wrap -->

<?php include __DIR__ . '/includes/footer.php'; ?>

<div id="toast-box"></div>

<script>
/* ── Toggle expandir/colapsar tarjeta ───────────────────────────────────── */
function toggleCard(id) {
  const card = document.getElementById(`card-${id}`);
  card.classList.toggle('open');
}

/* ── Estrellas calificación ─────────────────────────────────────────────── */
function setEstrella(pedidoId, val) {
  document.getElementById(`puntaje-${pedidoId}`).value = val;
  const stars = document.querySelectorAll(`#estrellas-${pedidoId} .estrella`);
  stars.forEach((s, i) => {
    s.textContent = i < val ? '★' : '☆';
    s.classList.toggle('on', i < val);
  });
}

/* Hover efecto estrellas */
document.querySelectorAll('.estrellas').forEach(wrap => {
  const stars = wrap.querySelectorAll('.estrella');
  stars.forEach((s, idx) => {
    s.addEventListener('mouseenter', () => {
      stars.forEach((st, i) => st.textContent = i <= idx ? '★' : '☆');
    });
    s.addEventListener('mouseleave', () => {
      const pid = wrap.id.replace('estrellas-', '');
      const val = parseInt(document.getElementById(`puntaje-${pid}`)?.value || 0);
      stars.forEach((st, i) => st.textContent = i < val ? '★' : '☆');
    });
  });
});

/* ── Abrir automáticamente si hay ?abrir= en la URL ────────────────────── */
const params = new URLSearchParams(location.search);
const abrir  = params.get('abrir');
if (abrir) {
  const card = document.getElementById(`card-${abrir}`);
  if (card) { card.classList.add('open'); card.scrollIntoView({ behavior:'smooth', block:'center' }); }
}

/* ── Toast desde calificación exitosa ───────────────────────────────────── */
<?php if ($msg_ok): ?>
function toast(msg, tipo='ok') {
  const box = document.getElementById('toast-box');
  const t   = document.createElement('div');
  t.className = `toast toast-${tipo}`;
  t.innerHTML = `<div class="toast-icon">✓</div>${msg}`;
  box.appendChild(t);
  setTimeout(() => t.remove(), 3200);
}
toast('<?= addslashes($msg_ok) ?>', 'ok');
<?php endif; ?>
</script>

</body>
</html>