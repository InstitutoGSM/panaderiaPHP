<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';

// Si ya esta logueado, lo mandamos a donde corresponde
if (esta_logueado()) {
    $tipo = $_SESSION['user_tipo'] ?? 'comprador';
    if ($tipo === 'vendedor')      header('Location: ' . SITE_URL . '/vendedor.php');
    elseif ($tipo === 'admin')     header('Location: ' . SITE_URL . '/admin.php');
    elseif ($tipo === 'trabajador') header('Location: ' . SITE_URL . '/trabajador.php');
    else                           header('Location: ' . SITE_URL . '/index.php');
    exit;
}

$error   = '';
$success = '';
$tab     = $_GET['tab'] ?? 'login'; // login | registro

// ── POST: INICIAR SESIÓN ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'login') {
    $email = trim($_POST['email'] ?? '');
    $pass  = $_POST['password'] ?? '';

    if (!$email || !$pass) {
        $error = 'Completá todos los campos.';
    } else {
        $stmt = db()->prepare('SELECT * FROM usuarios WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $u = $stmt->fetch();

        if ($u && password_verify($pass, $u['password_hash'])) {
            session_regenerate_id(true); // previene session fixation
            $_SESSION['user_id']     = $u['id'];
            $_SESSION['user_tipo']   = $u['tipo'];
            $_SESSION['user_nombre'] = $u['nombre'];

            $redir = $_SESSION['redirect_post_login'] ?? null;
            unset($_SESSION['redirect_post_login']);

            if ($redir) {
                header('Location: ' . $redir);
                exit;
            }

            if ($u['tipo'] === 'vendedor')       header('Location: ' . SITE_URL . '/vendedor.php');
            elseif ($u['tipo'] === 'admin')      header('Location: ' . SITE_URL . '/admin.php');
            elseif ($u['tipo'] === 'trabajador') header('Location: ' . SITE_URL . '/trabajador.php');
            else                                 header('Location: ' . SITE_URL . '/index.php');
            exit;
        } else {
            $error = 'Email o contraseña incorrectos.';
            $tab   = 'login';
        }
    }
}

// ── POST: REGISTRARSE ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'registro') {
    $nombre    = trim($_POST['nombre']    ?? '');
    $email     = trim($_POST['email']     ?? '');
    $pass      = $_POST['password']       ?? '';
    $tipo      = $_POST['tipo']           ?? 'comprador';
    $panaderia = trim($_POST['panaderia'] ?? '');
    $tab       = 'registro';

    if (!$nombre || !$email || !$pass) {
        $error = 'Completá todos los campos obligatorios.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'El email no es válido.';
    } elseif (strlen($pass) < 8) {
        $error = 'La contraseña debe tener al menos 8 caracteres.';
    } elseif (!in_array($tipo, ['comprador', 'vendedor'])) {
        $error = 'Tipo de usuario no válido.';
    } else {
        // Verificar email único
        $chk = db()->prepare('SELECT id FROM usuarios WHERE email = ? LIMIT 1');
        $chk->execute([$email]);
        if ($chk->fetch()) {
            $error = 'Ya existe una cuenta con ese email.';
        } else {
            $hash = password_hash($pass, PASSWORD_DEFAULT);
            $stmt = db()->prepare('
                INSERT INTO usuarios (nombre, email, password_hash, tipo, nombre_panaderia, estado_verificacion)
                VALUES (?, ?, ?, ?, ?, ?)
            ');
            $estado = $tipo === 'vendedor' ? 'sin_enviar' : 'aprobado';
            $stmt->execute([$nombre, $email, $hash, $tipo, $panaderia ?: null, $estado]);
            $newId = db()->lastInsertId();

            session_regenerate_id(true);
            $_SESSION['user_id']     = $newId;
            $_SESSION['user_tipo']   = $tipo;
            $_SESSION['user_nombre'] = $nombre;

            if ($tipo === 'vendedor') header('Location: ' . SITE_URL . '/vendedor.php');
            else header('Location: ' . SITE_URL . '/index.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ingresar — <?= SITE_NAME ?></title>
    <link rel="stylesheet" href="<?= SITE_URL ?>/css/global.css">
    <link rel="stylesheet" href="<?= SITE_URL ?>/css/login.css">
</head>

<body>

    <!-- Decoracion de fondo -->
    <div class="bg-deco" aria-hidden="true">
        <svg style="top:-30px;left:-50px;width:400px" viewBox="0 0 300 180">
            <ellipse cx="150" cy="90" rx="135" ry="65" fill="#C8601A" />
            <ellipse cx="150" cy="78" rx="115" ry="50" fill="#C8601A" />
        </svg>
        <svg style="bottom:40px;right:-60px;width:360px;transform:scaleX(-1)" viewBox="0 0 300 180">
            <ellipse cx="150" cy="90" rx="135" ry="65" fill="#C8601A" />
            <ellipse cx="150" cy="78" rx="115" ry="50" fill="#C8601A" />
        </svg>
    </div>

    <a href="<?= SITE_URL ?>/index.php" class="btn-volver">← Volver al inicio</a>

    <div class="login-wrap">

        <!-- SVG decorativo izquierda -->
        <div class="side" aria-hidden="true">
            <svg viewBox="0 0 300 460" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M10 115 L150 75 L290 115 L290 155 L10 155Z" fill="#A0522D" />
                <path d="M10 115 L150 75 L290 115" stroke="#7B3A1E" stroke-width="2.5" />
                <path d="M10 155 Q22 170 34 155 Q46 170 58 155 Q70 170 82 155 Q94 170 106 155 Q118 170 130 155 Q142 170 154 155 Q166 170 178 155 Q190 170 202 155 Q214 170 226 155 Q238 170 250 155 Q262 170 274 155 Q286 170 290 155" stroke="#A0522D" stroke-width="2.5" fill="none" />
                <rect x="20" y="155" width="260" height="210" rx="4" fill="#D2986A" />
                <rect x="20" y="155" width="260" height="18" fill="#C4844A" />
                <ellipse cx="80" cy="188" rx="28" ry="14" fill="#D4B48E" />
                <ellipse cx="145" cy="187" rx="22" ry="12" fill="#C09060" />
                <ellipse cx="205" cy="188" rx="25" ry="13" fill="#B8855A" />
                <ellipse cx="255" cy="189" rx="17" ry="10" fill="#C8A882" />
                <ellipse cx="163" cy="335" rx="32" ry="18" fill="#A07030" />
                <rect x="131" y="318" width="64" height="20" rx="5" fill="#B08040" />
                <ellipse cx="163" cy="318" rx="32" ry="9" fill="#C09050" />
            </svg>
        </div>

        <!-- Formulario central -->
        <div class="login-col">

            <div class="logo-wrap">
                <div class="logo-circle">
                    <img src="<?= SITE_URL ?>/assets/logo.png" alt="Logo"
                        onerror="this.style.display='none'">
                </div>
                <div class="logo-nombre">
                    <span class="top">Panaderia</span>
                    <span class="bot">PUMA</span>
                </div>
                <div class="logo-div">
                    <div class="logo-div-dot"></div>
                </div>
            </div>

            <!-- Mensaje de error/Exito -->
            <?php if ($error): ?>
                <div style="background:#FFEBEE;border-left:4px solid var(--rojo);padding:11px 16px;
                  border-radius:var(--radio);margin-bottom:16px;font-size:0.88rem;
                  color:var(--rojo);width:100%;max-width:420px">
                    ⚠️ <?= h($error) ?>
                </div>
            <?php endif; ?>

            <div class="login-card">

                <!-- Tabs -->
                <div class="tabs" role="tablist">
                    <button class="tab <?= $tab === 'login' ? 'on' : '' ?>"
                        data-tab="login" role="tab">Iniciar Sesión</button>
                    <button class="tab <?= $tab === 'registro' ? 'on' : '' ?>"
                        data-tab="registro" role="tab">Registrarse</button>
                </div>

                <!-- Login -->
                <div class="panel <?= $tab === 'login' ? 'on' : '' ?>" id="panel-login">
                    <p class="panel-title">Iniciar Sesión</p>
                    <form method="POST" action="login.php">
                        <input type="hidden" name="accion" value="login">
                        <div class="field">
                            <label for="l-email">Email</label>
                            <input type="email" id="l-email" name="email"
                                placeholder="tu@email.com" autocomplete="email" required>
                        </div>
                        <div class="field">
                            <label for="l-pass">Contraseña</label>
                            <input type="password" id="l-pass" name="password"
                                placeholder="••••••••" autocomplete="current-password" required>
                        </div>
                        <button type="submit" class="btn-login">Iniciar Sesión</button>
                    </form>
                    <p class="help">
                        ¿No tenés cuenta?
                        <a href="login.php?tab=registro">¡Registrate!</a>
                    </p>
                </div>

                <!-- Panel: Registro -->
                <div class="panel <?= $tab === 'registro' ? 'on' : '' ?>" id="panel-registro">
                    <p class="panel-title">Registrarse</p>
                    <form method="POST" action="login.php?tab=registro">
                        <input type="hidden" name="accion" value="registro">

                        <div class="field">
                            <label>Soy...</label>
                            <div class="tipo-row" id="tipo-row">
                                <div class="tipo-opt on" data-tipo="comprador" tabindex="0">
                                    <span class="tipo-icon">🛒</span>
                                    <div class="tipo-label">Comprador</div>
                                    <div class="tipo-sub">Quiero comprar</div>
                                </div>
                                <div class="tipo-opt" data-tipo="vendedor" tabindex="0">
                                    <span class="tipo-icon">🍞</span>
                                    <div class="tipo-label">Vendedor</div>
                                    <div class="tipo-sub">Tengo panadería</div>
                                </div>
                            </div>
                            <input type="hidden" name="tipo" id="input-tipo" value="comprador">
                        </div>

                        <div class="field">
                            <label for="r-nombre">Nombre completo</label>
                            <input type="text" id="r-nombre" name="nombre"
                                placeholder="Juan Pérez" autocomplete="name" required>
                        </div>

                        <div class="field" id="campo-panaderia" style="display:none">
                            <label for="r-panaderia">Nombre de tu panadería</label>
                            <input type="text" id="r-panaderia" name="panaderia"
                                placeholder="Ej: Panadería El Amasijo">
                            <div class="aviso-vendedor" style="margin-top:8px">
                                📋 <strong>Importante:</strong> una vez registrado, deberás subir tu
                                documentación desde tu panel. Tus productos no serán visibles
                                hasta que el equipo verifique tu documentación.
                            </div>
                        </div>

                        <div class="field">
                            <label for="r-email">Email</label>
                            <input type="email" id="r-email" name="email"
                                placeholder="tu@email.com" autocomplete="email" required>
                        </div>

                        <div class="field">
                            <label for="r-pass">Contraseña</label>
                            <input type="password" id="r-pass" name="password"
                                placeholder="Mínimo 8 caracteres" autocomplete="new-password"
                                minlength="8" required id="r-pass">
                            <div id="pass-bar" class="pass-bar" style="width:0;background:transparent"></div>
                            <div id="pass-label" class="pass-label"></div>
                        </div>

                        <p class="terminos-desc">
                            Al registrarte aceptás nuestros
                            <a href="<?= SITE_URL ?>/terminos.php" target="_blank"
                                style="color:var(--naranja);font-weight:700">Términos y Condiciones</a>
                        </p>
                        <button type="submit" class="btn-login">Registrarse</button>
                    </form>
                    <p class="help">
                        ¿Ya tenés cuenta?
                        <a href="login.php?tab=login">Iniciá sesión</a>
                    </p>
                </div>

            </div>

            <!-- Redes -->
            <div class="social">
                <p>Seguinos</p>
                <div class="social-icons">
                    <a href="tel:+5493834887766" aria-label="Teléfono">
                        <svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24">
                            <path d="M6.6 10.8c1.4 2.8 3.8 5.1 6.6 6.6l2.2-2.2c.27-.27.67-.36 1.02-.24 1.12.37 2.33.57 3.57.57.55 0 1 .45 1 1V20c0 .55-.45 1-1 1-9.39 0-17-7.61-17-17 0-.55.45-1 1-1h3.5c.55 0 1 .45 1 1 0 1.25.2 2.45.57 3.57.11.35.03.74-.25 1.01L6.6 10.8z" />
                        </svg>
                    </a>
                    <a href="https://instagram.com/lospuma.site" target="_blank" rel="noopener" aria-label="Instagram">
                        <svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24">
                            <path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838c-3.403 0-6.162 2.759-6.162 6.162s2.759 6.163 6.162 6.163 6.162-2.759 6.162-6.163c0-3.403-2.759-6.162-6.162-6.162zm0 10.162c-2.209 0-4-1.79-4-4 0-2.209 1.791-4 4-4s4 1.791 4 4c0 2.21-1.791 4-4 4zm6.406-11.845c-.796 0-1.441.645-1.441 1.44s.645 1.44 1.441 1.44c.795 0 1.439-.645 1.439-1.44s-.644-1.44-1.439-1.44z" />
                        </svg>
                    </a>
                    <a href="mailto:soporte-lospuma@gmail.com" aria-label="Email">
                        <svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24">
                            <path d="M20 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z" />
                        </svg>
                    </a>
                </div>
            </div>

        </div>

        <!-- SVG decorativo derecha -->
        <div class="side" aria-hidden="true">
            <svg viewBox="0 0 300 460" fill="none" style="transform:scaleX(-1)">
                <path d="M10 115 L150 75 L290 115 L290 155 L10 155Z" fill="#A0522D" />
                <path d="M10 115 L150 75 L290 115" stroke="#7B3A1E" stroke-width="2.5" />
                <path d="M10 155 Q22 170 34 155 Q46 170 58 155 Q70 170 82 155 Q94 170 106 155 Q118 170 130 155 Q142 170 154 155 Q166 170 178 155 Q190 170 202 155 Q214 170 226 155 Q238 170 250 155 Q262 170 274 155 Q286 170 290 155" stroke="#A0522D" stroke-width="2.5" fill="none" />
                <rect x="20" y="155" width="260" height="210" rx="4" fill="#D2986A" />
                <rect x="20" y="155" width="260" height="18" fill="#C4844A" />
                <ellipse cx="80" cy="188" rx="28" ry="14" fill="#D4B48E" />
                <ellipse cx="145" cy="187" rx="22" ry="12" fill="#C09060" />
                <ellipse cx="205" cy="188" rx="25" ry="13" fill="#B8855A" />
                <ellipse cx="255" cy="189" rx="17" ry="10" fill="#C8A882" />
                <ellipse cx="163" cy="335" rx="32" ry="18" fill="#A07030" />
                <rect x="131" y="318" width="64" height="20" rx="5" fill="#B08040" />
                <ellipse cx="163" cy="318" rx="32" ry="9" fill="#C09050" />
            </svg>
        </div>

    </div>

    <div id="toast-box"></div>
    <script src="<?= SITE_URL ?>/js/global.js"></script>
    <script>
        // Tabs
        document.querySelectorAll('.tab').forEach(btn => {
            btn.addEventListener('click', () => {
                const tab = btn.dataset.tab;
                document.querySelectorAll('.tab').forEach(t => t.classList.toggle('on', t.dataset.tab === tab));
                document.querySelectorAll('.panel').forEach(p => p.classList.toggle('on', p.id === 'panel-' + tab));
            });
        });

        // Selector tipo (comprador / vendedor)
        document.querySelectorAll('.tipo-opt').forEach(opt => {
            opt.addEventListener('click', () => {
                document.querySelectorAll('.tipo-opt').forEach(o => o.classList.remove('on'));
                opt.classList.add('on');
                const tipo = opt.dataset.tipo;
                document.getElementById('input-tipo').value = tipo;
                document.getElementById('campo-panaderia').style.display =
                    tipo === 'vendedor' ? 'block' : 'none';
            });
        });

        // Barra de fuerza de contraseña
        document.getElementById('r-pass')?.addEventListener('input', function() {
            const v = this.value;
            const bar = document.getElementById('pass-bar');
            const label = document.getElementById('pass-label');
            let score = 0;
            if (v.length >= 8) score++;
            if (/[A-Z]/.test(v)) score++;
            if (/[0-9]/.test(v)) score++;
            if (/[^A-Za-z0-9]/.test(v)) score++;

            const colores = ['#C0392B', '#E07830', '#F0A500', '#2D7A4F'];
            const labels = ['Muy débil', 'Débil', 'Buena', 'Fuerte'];
            bar.style.width = (score * 25) + '%';
            bar.style.background = colores[score - 1] || 'transparent';
            label.textContent = v.length ? labels[score - 1] || '' : '';
            label.style.color = colores[score - 1] || '';
        });
    </script>
</body>

</html>