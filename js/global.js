// ══ CARRITO (localStorage) ═══════════════════════════════════════════════
function getCarrito() {
  try { return JSON.parse(localStorage.getItem('carrito')) || []; }
  catch { return []; }
}
function setCarrito(c) {
  localStorage.setItem('carrito', JSON.stringify(c));
  actualizarBadge();
}
function actualizarBadge() {
  const n = getCarrito().reduce((s, i) => s + i.cantidad, 0);
  const b = document.getElementById('cart-badge');
  if (!b) return;
  b.textContent = n;
  b.style.display = n > 0 ? 'flex' : 'none';
}
function agregarItem(prod) {
  const c = getCarrito();

  const productoId = Number(prod.id);
  const sucursalId = Number(prod.sucursal_id || 0);

  const idx = c.findIndex(item =>
    Number(item.id) === productoId &&
    Number(item.sucursal_id || 0) === sucursalId
  );

  if (idx >= 0) {
    c[idx].cantidad += 1;
  } else {
    c.push({
      ...prod,
      id: productoId,
      sucursal_id: sucursalId,
      cantidad: 1
    });
  }

  setCarrito(c);
  toast('Agregado al carrito 🛒', 'ok');
}
function quitarItem(id) {
  setCarrito(getCarrito().filter(i => i.id !== id));
}
function cambiarCantidad(id, delta) {
  const c = getCarrito().map(i => i.id === id ? { ...i, cantidad: i.cantidad + delta } : i)
                        .filter(i => i.cantidad > 0);
  setCarrito(c);
  renderCarrito();
}
function vaciarCarrito() { setCarrito([]); }

function formatPrecio(n) {
  return '$' + Number(n).toLocaleString('es-AR', { minimumFractionDigits: 0 });
}

function renderCarrito() {
  const c = getCarrito();
  const body   = document.getElementById('cart-body');
  const totEl  = document.getElementById('cart-total-precio');
  const btnCh  = document.getElementById('btn-ir-checkout');
  if (!body) return;

  if (c.length === 0) {
    body.innerHTML = `<div class="cart-empty"><span>🛒</span>Tu carrito está vacío</div>`;
    if (totEl) totEl.textContent = '$0';
    if (btnCh) btnCh.style.opacity = '0.5';
    return;
  }
  if (btnCh) btnCh.style.opacity = '1';

  const total = c.reduce((s, i) => s + i.precio * i.cantidad, 0);
  if (totEl) totEl.textContent = formatPrecio(total);

  body.innerHTML = c.map(i => `
    <div class="cart-item">
      <div class="cart-item-img">
        ${i.imagen_url
          ? `<img src="${i.imagen_url}" alt="${i.nombre}">`
          : i.nombre.charAt(0)}
      </div>
      <div class="cart-item-info">
        <div class="cart-item-nombre">${i.nombre}</div>
        <div class="cart-item-sub">${i.panaderia || ''}</div>
        <div class="cart-item-precio">${formatPrecio(i.precio * i.cantidad)}</div>
        <div class="cart-qty">
          <button onclick="cambiarCantidad(${i.id}, -1)" aria-label="Restar">−</button>
          <span>${i.cantidad}</span>
          <button onclick="cambiarCantidad(${i.id}, +1)" aria-label="Sumar">+</button>
          <button class="cart-del" onclick="quitarItem(${i.id});renderCarrito()" aria-label="Quitar">🗑</button>
        </div>
      </div>
    </div>
  `).join('');
}

// ── Abrir / cerrar drawer ──────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  actualizarBadge();
  renderCarrito();

  const btn     = document.getElementById('cart-btn');
  const drawer  = document.getElementById('cart-drawer');
  const overlay = document.getElementById('cart-overlay');
  const close   = document.getElementById('cart-close');

  function abrirCarrito() {
    renderCarrito();
    drawer?.classList.add('open');
    overlay?.classList.add('open');
  }
  function cerrarCarrito() {
    drawer?.classList.remove('open');
    overlay?.classList.remove('open');
  }

  btn?.addEventListener('click', abrirCarrito);
  close?.addEventListener('click', cerrarCarrito);
  overlay?.addEventListener('click', cerrarCarrito);
});

// ══ TOAST ════════════════════════════════════════════════════════════════
function toast(msg, tipo = 'ok') {
  const box = document.getElementById('toast-box');
  if (!box) return;
  const cls = tipo === 'ok' ? 'toast-ok' : tipo === 'err' ? 'toast-err' : 'toast-inf';
  const ico = tipo === 'ok' ? '✓' : tipo === 'err' ? '✕' : 'ℹ';
  const el = document.createElement('div');
  el.className = `toast ${cls}`;
  el.innerHTML = `<div class="toast-icon">${ico}</div>${msg}`;
  box.appendChild(el);
  setTimeout(() => el.remove(), 3200);
}

// ══ BUSQUEDA CON SUGERENCIAS ══════════════════════════════════════════════
const inputBuscar = document.getElementById('nav-buscar');
const dropSug     = document.getElementById('sugerencias-drop');
let timerBusq;

inputBuscar?.addEventListener('input', () => {
  clearTimeout(timerBusq);
  const q = inputBuscar.value.trim();
  if (q.length < 2) { dropSug.style.display = 'none'; return; }
  timerBusq = setTimeout(() => buscarSugerencias(q), 300);
});

inputBuscar?.addEventListener('keydown', e => {
  if (e.key === 'Enter') {
    dropSug.style.display = 'none';
    window.location.href = SITE_URL + '/catalogo.php?q=' + encodeURIComponent(inputBuscar.value.trim());
  }
});

document.addEventListener('click', e => {
  if (!dropSug?.contains(e.target) && e.target !== inputBuscar) {
    if (dropSug) dropSug.style.display = 'none';
  }
});

async function buscarSugerencias(q) {
  try {
    const res  = await fetch(SITE_URL + '/api/buscar.php?q=' + encodeURIComponent(q));
    const data = await res.json();
    if (!data.length) { dropSug.style.display = 'none'; return; }
    dropSug.innerHTML = data.map(p => `
      <a href="${SITE_URL}/producto.php?id=${p.id}" class="sug-item">
        <span class="sug-ico">${p.emoji}</span>
        <div>
          <div class="sug-label">${p.nombre}</div>
          <div class="sug-sub">${p.panaderia}</div>
        </div>
        <span class="sug-precio">${p.precio}</span>
      </a>
    `).join('');
    dropSug.style.display = 'block';
  } catch { dropSug.style.display = 'none'; }
}