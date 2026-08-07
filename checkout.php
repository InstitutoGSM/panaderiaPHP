<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';

// Requiere login
if (!esta_logueado()) {
  $_SESSION['redirect_post_login'] = SITE_URL . '/checkout.php';
  $_SESSION['login_motivo'] = 'Para finalizar tu compra necesitás iniciar sesión o crear una cuenta 🛒';
  header('Location: ' . SITE_URL . '/login.php');
  exit;
}

$u = usuario_actual();
?>
<!DOCTYPE html>
<html lang="es">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Finalizar pedido — <?= SITE_NAME ?></title>
  <link rel="stylesheet" href="<?= SITE_URL ?>/css/global.css">
  <link rel="stylesheet" href="<?= SITE_URL ?>/css/checkout.css">
</head>

<body>

  <?php
  $page_title = 'Checkout';
  include __DIR__ . '/includes/header.php';
  ?>

  <div class="checkout-wrap">
    <h1 class="checkout-titulo">Listo para ordenar? 🥖</h1>
    <p class="checkout-sub">Revisá tu pedido y completá tus datos</p>

    <div class="checkout-grid" id="checkout-grid">

      <!-- ══ DATOS ══ -->
      <div>
        <div class="checkout-form-card">
          <h3>Tus datos</h3>
          <div class="field">
            <label for="co-nombre">Nombre completo</label>
            <input type="text" id="co-nombre"
              value="<?= h($u['nombre'] ?? '') ?>"
              placeholder="Juan Pérez" autocomplete="name">
          </div>
          <div class="field">
            <label for="co-email">Email</label>
            <input type="email" id="co-email"
              value="<?= h($u['email'] ?? '') ?>"
              placeholder="tu@email.com" autocomplete="email">
          </div>

          <div class="checkout-sep"></div>
          <h3>Medio de envío</h3>

          <div class="field">
            <label for="co-cp">Código postal</label>
            <input type="text" id="co-cp" placeholder="Ej: 4700" maxlength="8">
          </div>
          <div class="field">
            <label for="co-dir">Dirección de entrega</label>
            <textarea id="co-dir" rows="2"
              placeholder="Calle, número, piso..."></textarea>
          </div>
          <div class="field">
            <label for="co-notas">Notas para el vendedor (opcional)</label>
            <textarea id="co-notas" rows="2"
              placeholder="Sin sal, extra semillas..."></textarea>
          </div>
        </div>
      </div>

      <!-- ══ COLUMNA DERECHA: RESUMEN ══ -->
      <div>
        <div class="resumen-card">
          <h3>Tu pedido</h3>

          <!-- Items agrupados por vendedor -->
          <div id="resumen-items">
            <p class="resumen-cargando">Cargando carrito...</p>
          </div>

          <!-- Total general -->
          <div class="resumen-total">
            <span>Total</span>
            <strong id="resumen-total">$0</strong>
          </div>

          <!-- Tarjeta visual (se muestra solo si elige debito/credito) -->
          <div class="tarjeta-wrap" id="tarjeta-wrap">
            <div class="tarjeta-visual">
              <div class="tarjeta-chip"></div>
              <div class="tarjeta-numero" id="tv-numero">•••• •••• •••• ••••</div>
              <div class="tarjeta-bottom">
                <div>
                  <div class="tarjeta-label">Titular</div>
                  <div class="tarjeta-val" id="tv-nombre">TU NOMBRE</div>
                </div>
                <div>
                  <div class="tarjeta-label">Vence</div>
                  <div class="tarjeta-val" id="tv-vence">MM/AA</div>
                </div>
                <div class="tarjeta-brand" id="tv-brand">CARD</div>
              </div>
            </div>

            <div class="tarjeta-form">
              <div class="field">
                <label for="t-numero">Número de tarjeta</label>
                <input type="text" id="t-numero"
                  placeholder="1234 5678 9012 3456"
                  maxlength="19" inputmode="numeric" autocomplete="cc-number">
              </div>
              <div class="field">
                <label for="t-nombre">Nombre del titular</label>
                <input type="text" id="t-nombre"
                  placeholder="Como figura en la tarjeta"
                  autocomplete="cc-name">
              </div>
              <div class="tarjeta-form form-row">
                <div class="field">
                  <label for="t-vence">Vencimiento</label>
                  <input type="text" id="t-vence"
                    placeholder="MM/AA" maxlength="5" autocomplete="cc-exp">
                </div>
                <div class="field">
                  <label for="t-cvv">CVV</label>
                  <input type="password" id="t-cvv"
                    placeholder="123" maxlength="4"
                    inputmode="numeric" autocomplete="cc-csc">
                </div>
              </div>
              <div class="tarjeta-aviso">
                🔒 El CVV no se guarda. Solo guardamos los últimos 4 dígitos de tu tarjeta.
              </div>
            </div>
          </div>

          <button class="btn btn-naranja btn-full btn-finalizar-margin"
            id="btn-finalizar">
            Confirmar pedido →
          </button>
          <p class="checkout-aviso-final">
            Al confirmar, el vendedor recibirá tu pedido
          </p>
        </div>
      </div>

    </div>

    <!-- Carrito vacío -->
    <div id="empty-checkout" style="display:none;text-align:center;padding:80px 20px;color:var(--gris)">
      <span style="font-size:4rem;display:block;margin-bottom:16px">🛒</span>
      <h2 style="color:var(--marron);margin-bottom:8px">Tu carrito está vacío</h2>
      <p style="margin-bottom:24px">Agregá productos antes de continuar</p>
      <a href="<?= SITE_URL ?>/catalogo.php" class="btn btn-naranja">Ver productos</a>
    </div>

  </div><!-- /.checkout-wrap -->

  <?php include __DIR__ . '/includes/footer.php'; ?>

  <script>
    const SITE_URL = '<?= SITE_URL ?>';

    /* ══ LABELS DE PAGO ════════════════════════════════════════════════════════ */
    const LABELS_PAGO = {
      efectivo: {
        ico: '💵',
        label: 'Efectivo'
      },
      transferencia: {
        ico: '📲',
        label: 'Transferencia'
      },
      debito: {
        ico: '💳',
        label: 'Débito'
      },
      credito: {
        ico: '💳',
        label: 'Crédito'
      },
    };

    /* ══ ESTADO GLOBAL ══════════════════════════════════════════════════════════ */
    let grupos = {};

    /* ══ CARRITO ══════════════════════ */
    function getCarrito() {
      try {
        return JSON.parse(localStorage.getItem('carrito')) || [];
      } catch {
        return [];
      }
    }

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

    /* ══ INIT ═══════════════════════════════════════════════════════════════════ */
    async function init() {
      const items = getCarrito();

      if (items.length === 0) {
        document.getElementById('checkout-grid').style.display = 'none';
        document.getElementById('empty-checkout').style.display = 'block';
        return;
      }

      // Agrupar por vendedor
      const porGrupo = {};

      items.forEach(item => {
        const vendedorId = Number(item.vendedor_id);
        const sucursalId = Number(item.sucursal_id || 0);
        const clave = `${vendedorId}:${sucursalId}`;

        if (!porGrupo[clave]) {
          porGrupo[clave] = {
            vendedor_id: vendedorId,
            sucursal_id: sucursalId,
            items: []
          };
        }

        porGrupo[clave].items.push(item);
      });

      // Obt info de pago de cada vendedor
      const ids = [
        ...new Set(
          Object.values(porGrupo).map(grupo => grupo.vendedor_id)
        )
      ].join(',');

      const res = await fetch(
        `${SITE_URL}/api/vendedores_pago.php?ids=${encodeURIComponent(ids)}`
      );

      const data = res.ok ? await res.json() : {};

      // Armar grupos
      Object.entries(porGrupo).forEach(([clave, grupo]) => {
        const vid = grupo.vendedor_id;
        const info = data[vid] || {};
        const itms = grupo.items;
        const medios = (info.medios_pago || 'efectivo').split(',').filter(Boolean);
        if (!medios.includes('efectivo')) medios.unshift('efectivo');

        grupos[clave] = {
          vendedor_id: vid,
          sucursal_id: grupo.sucursal_id,
          nombre: info.nombre_panaderia || info.nombre || 'Panadería',
          items: itms,
          medios,
          cbu: info.cbu || null,
          alias: info.alias_cbu || null,
          titular: info.titular_cuenta || null,
          pagoSel: 'efectivo',
        };
      });

      renderResumen();
    }

    /* ══ RENDER RESUMEN ════════════════════════════════════════════════════════ */
    function renderResumen() {
      const el = document.getElementById('resumen-items');
      let totalGeneral = 0;

      el.innerHTML = Object.entries(grupos).map(([clave, g]) => {
        const subtotal = g.items.reduce((acc, i) => acc + i.precio * i.cantidad, 0);
        totalGeneral += subtotal;

        const itemsHtml = g.items.map(i => `
      <div class="resumen-item">
        <span class="resumen-nombre">${h(i.nombre)}</span>
        <span class="resumen-cant">× ${i.cantidad}</span>
        <span class="resumen-precio">${formatPrecio(i.precio * i.cantidad)}</span>
      </div>
    `).join('');

        const pagoHtml = g.medios.map(m => {
          const info = LABELS_PAGO[m] || {
            ico: '💰',
            label: m
          };
          return `
        <div class="pago-opt ${g.pagoSel === m ? 'on' : ''}"
     data-grupo="${clave}" data-pago="${m}"
     tabindex="0" role="radio" aria-checked="${g.pagoSel === m}">
          <span class="pago-ico">${info.ico}</span> ${info.label}
        </div>`;
        }).join('');

        const cbuBox = g.pagoSel === 'transferencia' ? `
      <div class="cbu-box">
        <strong>Datos para transferir a ${h(g.nombre)}:</strong>
        ${g.cbu     ? `<div>CBU: <strong>${h(g.cbu)}</strong></div>` : ''}
        ${g.alias   ? `<div>Alias: <strong>${h(g.alias)}</strong></div>` : ''}
        ${g.titular ? `<div>Titular: ${h(g.titular)}</div>` : ''}
        ${!g.cbu && !g.alias
          ? '<div style="color:var(--gris)">Este vendedor no completó sus datos de transferencia.</div>'
          : ''}
      </div>` : '';

        return `
      <div class="vendor-pago-group" data-grupo="${clave}">
        <h4 class="vendor-pago-titulo">🏪 ${h(g.nombre)}</h4>
        ${itemsHtml}
        <div class="subtotal-row">
          <span>Subtotal ${h(g.nombre)}</span>
          <span>${formatPrecio(subtotal)}</span>
        </div>
        <p style="font-size:0.8rem;color:var(--gris);margin:10px 0 6px">Medio de pago:</p>
        <div class="pago-opts pago-opts-vendor">${pagoHtml}</div>
        ${cbuBox}
      </div>`;
      }).join('');

      document.getElementById('resumen-total').textContent = formatPrecio(totalGeneral);

      // Eventos de seleccion de pago
      el.querySelectorAll('.pago-opt').forEach(btn => {
        btn.addEventListener('click', () => seleccionarPago(btn.dataset.grupo, btn.dataset.pago));
        btn.addEventListener('keydown', e => {
          if (e.key === 'Enter' || e.key === ' ') {
            seleccionarPago(btn.dataset.grupo, btn.dataset.pago);
          }
        });
      });

      actualizarTarjeta();
    }

    function seleccionarPago(clave, pago) {
      if (!grupos[clave]) return;

      grupos[clave].pagoSel = pago;
      renderResumen();
    }

    /* ══ MOSTRAR/OCULTAR TARJETA ════════════════════════════════════════════════ */
    function actualizarTarjeta() {
      const alguno = Object.values(grupos).some(g => g.pagoSel === 'debito' || g.pagoSel === 'credito');
      document.getElementById('tarjeta-wrap').classList.toggle('show', alguno);
    }

    /* ══ TARJETA VISUAL EN TIEMPO REAL ════════════════════════════════════════ */
    document.getElementById('t-numero').addEventListener('input', function() {
      let v = this.value.replace(/\D/g, '').slice(0, 16);
      this.value = v.replace(/(.{4})/g, '$1 ').trim();
      const display = v.padEnd(16, '•');
      document.getElementById('tv-numero').textContent =
        display.replace(/(.{4})/g, '$1 ').trim();

      // Detectar marca
      const brand = v[0] === '4' ? 'VISA' :
        v[0] === '5' ? 'MASTERCARD' :
        v[0] === '3' ? 'AMEX' :
        'CARD';
      document.getElementById('tv-brand').textContent = brand;
    });

    document.getElementById('t-nombre').addEventListener('input', function() {
      document.getElementById('tv-nombre').textContent = this.value.toUpperCase() || 'TU NOMBRE';
    });

    document.getElementById('t-vence').addEventListener('input', function() {
      let v = this.value.replace(/\D/g, '').slice(0, 4);
      if (v.length > 2) v = v.slice(0, 2) + '/' + v.slice(2);
      this.value = v;
      document.getElementById('tv-vence').textContent = v || 'MM/AA';
    });

    document.getElementById('t-numero').addEventListener('keypress', e => {
      if (!/[0-9]/.test(e.key)) e.preventDefault();
    });

    /* ══ CONFIRMAR PEDIDO ════════════════════════════════════════════════════ */
    document.getElementById('btn-finalizar').addEventListener('click', async () => {
      const nombre = document.getElementById('co-nombre').value.trim();
      const email = document.getElementById('co-email').value.trim();

      if (!nombre || !email) {
        toast('Completá tu nombre y email', 'err');
        return;
      }

      // Validar tarjeta si alguno eligio debito/credito
      const usaTarjeta = Object.values(grupos).some(g => g.pagoSel === 'debito' || g.pagoSel === 'credito');
      if (usaTarjeta) {
        const numRaw = document.getElementById('t-numero').value.replace(/\s/g, '');
        const vence = document.getElementById('t-vence').value;
        if (numRaw.length < 13) {
          toast('Número de tarjeta inválido', 'err');
          return;
        }
        if (!/^\d{2}\/\d{2}$/.test(vence)) {
          toast('Fecha de vencimiento inválida', 'err');
          return;
        }
      }

      const btn = document.getElementById('btn-finalizar');
      btn.disabled = true;
      btn.textContent = 'Procesando...';

      // Preparar datos para el servidor
      const numRaw = document.getElementById('t-numero').value.replace(/\s/g, '');
      const ultimos4 = numRaw.slice(-4) || null;
      const tipoTarj = document.getElementById('tv-brand').textContent !== 'CARD' ?
        document.getElementById('tv-brand').textContent : null;

      const payload = {
        nombre,
        email,
        cp: document.getElementById('co-cp').value.trim() || null,
        direccion: document.getElementById('co-dir').value.trim() || null,
        notas: document.getElementById('co-notas').value.trim() || null,
        ultimos4,
        tipo_tarjeta: tipoTarj,
        grupos: Object.entries(grupos).map(([clave, g]) => ({
          vendedor_id: g.vendedor_id,
          sucursal_id: g.sucursal_id,
          medio_pago: g.pagoSel,
          items: g.items.map(i => ({
            producto_id: i.id,
            nombre: i.nombre,
            cantidad: i.cantidad,
            precio: i.precio,
            variante: i.variante || 'unidad',
          })),
        })),
      };

      try {
        const res = await fetch(`${SITE_URL}/api/checkout.php`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json'
          },
          body: JSON.stringify(payload),
        });
        const data = await res.json();

        if (!data.ok) {
          toast(data.msg || 'Error al procesar el pedido', 'err');
          btn.disabled = false;
          btn.textContent = 'Confirmar pedido →';
          return;
        }

        // Vaciar carrito
        localStorage.removeItem('carrito');
        if (typeof actualizarBadge === 'function') actualizarBadge();

        toast('¡Pedido confirmado! 🎉', 'ok');

        // Abrir ticket en ventana nueva
        if (data.ticket_html) {
          const win = window.open('', '_blank');
          if (win) {
            win.document.write(data.ticket_html);
            win.document.close();
          }
        }

        setTimeout(() => {
          location.href = `${SITE_URL}/historial.php`;
        }, 900);

      } catch (err) {
        toast('Error de conexión. Intentá de nuevo.', 'err');
        btn.disabled = false;
        btn.textContent = 'Confirmar pedido →';
      }
    });

    // Iniciar
    init();
  </script>

</body>

</html>