<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';

$token = trim($_GET['token'] ?? $_POST['token'] ?? '');

$error = '';
$ok = '';
$invitacion = null;
$usuario_existente = null;
$finalizada = false;

function marcar_invitacion_vencida(PDO $pdo, int $invitacion_id, int $sucursal_id): void
{
    $pdo->prepare("
        UPDATE invitaciones_sucursal
        SET estado = 'vencida'
        WHERE id = ?
          AND estado = 'pendiente'
    ")->execute([$invitacion_id]);

    $pdo->prepare("
        UPDATE sucursales
        SET activo = 0,
            estado = 'inactiva'
        WHERE id = ?
          AND vendedor_id IS NULL
          AND estado = 'pendiente'
    ")->execute([$sucursal_id]);
}

if (
    $token === '' ||
    !preg_match('/^[a-f0-9]{64}$/i', $token)
) {
    $error = 'El enlace de invitación no es válido.';
} else {
    $token_hash = hash('sha256', $token);

    /*
     * Buscar la invitación.
     */
    $consulta = db()->prepare("
        SELECT
            i.id,
            i.padre_id,
            i.sucursal_id,
            i.usuario_invitado_id,
            i.email_invitado,
            i.nombre_invitado,
            i.estado,
            i.expires_at,

            s.nombre AS sucursal_nombre,
            s.direccion AS sucursal_direccion,
            s.telefono AS sucursal_telefono,

            p.nombre AS padre_nombre,
            p.nombre_panaderia AS padre_panaderia

        FROM invitaciones_sucursal i
        INNER JOIN sucursales s
            ON s.id = i.sucursal_id
        INNER JOIN usuarios p
            ON p.id = i.padre_id
        WHERE i.token_hash = ?
        LIMIT 1
    ");

    $consulta->execute([$token_hash]);
    $invitacion = $consulta->fetch() ?: null;

    if (!$invitacion) {
        $error = 'La invitación no existe o el enlace es incorrecto.';
    } elseif ($invitacion['estado'] !== 'pendiente') {
        $error = match ($invitacion['estado']) {
            'aceptada'  => 'Esta invitación ya fue aceptada.',
            'rechazada' => 'Esta invitación fue rechazada.',
            'revocada'  => 'Esta invitación fue revocada por el Encargado Padre.',
            'vencida'   => 'Esta invitación ya venció.',
            default     => 'Esta invitación ya no está disponible.',
        };

        $finalizada = true;
    } elseif (strtotime($invitacion['expires_at']) <= time()) {
        $pdo = db();

        marcar_invitacion_vencida(
            $pdo,
            (int)$invitacion['id'],
            (int)$invitacion['sucursal_id']
        );

        $error = 'Esta invitación ya venció.';
        $finalizada = true;
    } else {
        /*
         * Verificar si el email ya tiene una cuenta.
         */
        $usuario_q = db()->prepare("
            SELECT
                id,
                nombre,
                email,
                password_hash,
                tipo,
                estado_verificacion,
                is_admin_pan,
                tipo_sucursal,
                sucursal_padre_id
            FROM usuarios
            WHERE email = ?
            LIMIT 1
        ");

        $usuario_q->execute([
            strtolower(trim($invitacion['email_invitado']))
        ]);

        $usuario_existente = $usuario_q->fetch() ?: null;

        /*
         * Procesar acciones.
         */
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $accion = $_POST['accion'] ?? '';

            if (!in_array($accion, ['aceptar', 'rechazar'], true)) {
                $error = 'Acción no válida.';
            }

            /*
             * RECHAZAR INVITACIÓN
             */
            elseif ($accion === 'rechazar') {
                $pdo = db();

                try {
                    $pdo->beginTransaction();

                    $bloqueo = $pdo->prepare("
                        SELECT
                            id,
                            sucursal_id,
                            estado,
                            expires_at
                        FROM invitaciones_sucursal
                        WHERE id = ?
                          AND token_hash = ?
                        LIMIT 1
                        FOR UPDATE
                    ");

                    $bloqueo->execute([
                        (int)$invitacion['id'],
                        $token_hash
                    ]);

                    $inv = $bloqueo->fetch();

                    if (!$inv || $inv['estado'] !== 'pendiente') {
                        throw new RuntimeException(
                            'La invitación ya no está disponible.'
                        );
                    }

                    if (strtotime($inv['expires_at']) <= time()) {
                        marcar_invitacion_vencida(
                            $pdo,
                            (int)$inv['id'],
                            (int)$inv['sucursal_id']
                        );

                        $pdo->commit();

                        $error = 'La invitación venció antes de ser rechazada.';
                        $finalizada = true;
                    } else {
                        $rechazar = $pdo->prepare("
                            UPDATE invitaciones_sucursal
                            SET estado = 'rechazada',
                                rejected_at = NOW()
                            WHERE id = ?
                              AND estado = 'pendiente'
                        ");

                        $rechazar->execute([
                            (int)$inv['id']
                        ]);

                        $pdo->prepare("
                            UPDATE sucursales
                            SET activo = 0,
                                estado = 'inactiva'
                            WHERE id = ?
                              AND vendedor_id IS NULL
                              AND estado = 'pendiente'
                        ")->execute([
                            (int)$inv['sucursal_id']
                        ]);

                        $pdo->commit();

                        $ok = 'La invitación fue rechazada correctamente.';
                        $finalizada = true;
                    }
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    $error = $e instanceof RuntimeException
                        ? $e->getMessage()
                        : 'No se pudo rechazar la invitación.';
                }
            }

            /*
             * ACEPTAR INVITACIÓN
             */
            elseif ($accion === 'aceptar') {
                $nombre = trim($_POST['nombre'] ?? '');
                $password = $_POST['password'] ?? '';
                $password_confirmacion = $_POST['password_confirmacion'] ?? '';

                /*
                 * Validaciones de usuario nuevo.
                 */
                if (!$usuario_existente && $nombre === '') {
                    $error = 'Ingresá tu nombre completo.';
                } elseif (strlen($password) < 8) {
                    $error = 'La contraseña debe tener al menos 8 caracteres.';
                } elseif (
                    !$usuario_existente &&
                    $password !== $password_confirmacion
                ) {
                    $error = 'Las contraseñas no coinciden.';
                }

                /*
                 * Validaciones de usuario existente.
                 */
                elseif (
                    $usuario_existente &&
                    !password_verify(
                        $password,
                        $usuario_existente['password_hash']
                    )
                ) {
                    $error = 'La contraseña no coincide con la cuenta invitada.';
                } elseif (
                    $usuario_existente &&
                    (
                        $usuario_existente['tipo'] !== 'vendedor' ||
                        $usuario_existente['estado_verificacion'] !== 'aprobado'
                    )
                ) {
                    $error = 'La cuenta existente no está habilitada para recibir esta invitación.';
                } elseif (
                    $usuario_existente &&
                    (
                        in_array(
                            $usuario_existente['tipo_sucursal'],
                            ['padre', 'hija'],
                            true
                        ) ||
                        !empty($usuario_existente['sucursal_padre_id'])
                    )
                ) {
                    $error = 'La cuenta existente ya pertenece a otra estructura de sucursales.';
                } else {
                    $pdo = db();

                    try {
                        $pdo->beginTransaction();

                        /*
                         * Bloquear la invitación para evitar doble uso.
                         */
                        $bloqueo = $pdo->prepare("
                            SELECT
                                i.id,
                                i.padre_id,
                                i.sucursal_id,
                                i.estado,
                                i.expires_at,

                                s.vendedor_id,
                                s.estado AS sucursal_estado,
                                s.activo AS sucursal_activa

                            FROM invitaciones_sucursal i
                            INNER JOIN sucursales s
                                ON s.id = i.sucursal_id

                            WHERE i.id = ?
                              AND i.token_hash = ?
                            LIMIT 1
                            FOR UPDATE
                        ");

                        $bloqueo->execute([
                            (int)$invitacion['id'],
                            $token_hash
                        ]);

                        $inv = $bloqueo->fetch();

                        if (!$inv || $inv['estado'] !== 'pendiente') {
                            throw new RuntimeException(
                                'La invitación ya no está disponible.'
                            );
                        }

                        /*
                         * Verificar vencimiento dentro de la transacción.
                         */
                        if (strtotime($inv['expires_at']) <= time()) {
                            marcar_invitacion_vencida(
                                $pdo,
                                (int)$inv['id'],
                                (int)$inv['sucursal_id']
                            );

                            $pdo->commit();

                            $error = 'La invitación venció antes de ser aceptada.';
                            $finalizada = true;
                        } else {
                            /*
                             * Si el usuario ya existe, bloquearlo nuevamente.
                             */
                            $usuario_id = 0;
                            $nombre_final = '';

                            if ($usuario_existente) {
                                $usuario_bloqueado_q = $pdo->prepare("
                                    SELECT
                                        id,
                                        nombre,
                                        email,
                                        password_hash,
                                        tipo,
                                        estado_verificacion,
                                        tipo_sucursal,
                                        sucursal_padre_id
                                    FROM usuarios
                                    WHERE id = ?
                                    LIMIT 1
                                    FOR UPDATE
                                ");

                                $usuario_bloqueado_q->execute([
                                    (int)$usuario_existente['id']
                                ]);

                                $usuario_bloqueado = $usuario_bloqueado_q->fetch();

                                if (
                                    !$usuario_bloqueado ||
                                    $usuario_bloqueado['tipo'] !== 'vendedor' ||
                                    $usuario_bloqueado['estado_verificacion'] !== 'aprobado' ||
                                    in_array(
                                        $usuario_bloqueado['tipo_sucursal'],
                                        ['padre', 'hija'],
                                        true
                                    ) ||
                                    !empty($usuario_bloqueado['sucursal_padre_id'])
                                ) {
                                    throw new RuntimeException(
                                        'La cuenta existente ya no puede recibir esta invitación.'
                                    );
                                }

                                $usuario_id = (int)$usuario_bloqueado['id'];
                                $nombre_final = $usuario_bloqueado['nombre'];
                            }

                            /*
                             * Si no existe, crear el nuevo vendedor Hijo.
                             */
                            if ($usuario_id === 0) {
                                $crear_usuario = $pdo->prepare("
                                    INSERT INTO usuarios (
                                        nombre,
                                        email,
                                        password_hash,
                                        tipo,
                                        estado_verificacion,
                                        is_admin_pan,
                                        tipo_sucursal,
                                        sucursal_padre_id,
                                        es_sucursal
                                    ) VALUES (
                                        ?,
                                        ?,
                                        ?,
                                        'vendedor',
                                        'aprobado',
                                        1,
                                        'hija',
                                        ?,
                                        1
                                    )
                                ");

                                $crear_usuario->execute([
                                    $nombre,
                                    strtolower($invitacion['email_invitado']),
                                    password_hash($password, PASSWORD_DEFAULT),
                                    (int)$inv['padre_id']
                                ]);

                                $usuario_id = (int)$pdo->lastInsertId();
                                $nombre_final = $nombre;
                            }

                            /*
                             * Bloquear y verificar la sucursal pendiente.
                             */
                            $sucursal_q = $pdo->prepare("
                                SELECT
                                    id,
                                    vendedor_id,
                                    estado,
                                    activo
                                FROM sucursales
                                WHERE id = ?
                                LIMIT 1
                                FOR UPDATE
                            ");

                            $sucursal_q->execute([
                                (int)$inv['sucursal_id']
                            ]);

                            $sucursal = $sucursal_q->fetch();

                            if (
                                !$sucursal ||
                                $sucursal['vendedor_id'] !== null ||
                                $sucursal['estado'] !== 'pendiente' ||
                                (int)$sucursal['activo'] !== 0
                            ) {
                                throw new RuntimeException(
                                    'La sucursal de esta invitación ya no está disponible.'
                                );
                            }

                            /*
                             * Vincular usuario como Encargado Hijo.
                             */
                            $actualizar_usuario = $pdo->prepare("
                                UPDATE usuarios
                                SET is_admin_pan = 1,
                                    tipo_sucursal = 'hija',
                                    sucursal_padre_id = ?,
                                    es_sucursal = 1
                                WHERE id = ?
                            ");

                            $actualizar_usuario->execute([
                                (int)$inv['padre_id'],
                                $usuario_id
                            ]);

                            /*
                             * Activar la sucursal y asociarla al usuario.
                             */
                            $activar_sucursal = $pdo->prepare("
                                UPDATE sucursales
                                SET vendedor_id = ?,
                                    activo = 1,
                                    estado = 'activa'
                                WHERE id = ?
                                  AND vendedor_id IS NULL
                                  AND estado = 'pendiente'
                            ");

                            $activar_sucursal->execute([
                                $usuario_id,
                                (int)$inv['sucursal_id']
                            ]);

                            if ($activar_sucursal->rowCount() !== 1) {
                                throw new RuntimeException(
                                    'No se pudo activar la sucursal Hija.'
                                );
                            }

                            /*
                             * Marcar invitación como aceptada.
                             */
                            $aceptar = $pdo->prepare("
                                UPDATE invitaciones_sucursal
                                SET estado = 'aceptada',
                                    usuario_invitado_id = ?,
                                    accepted_at = NOW()
                                WHERE id = ?
                                  AND estado = 'pendiente'
                            ");

                            $aceptar->execute([
                                $usuario_id,
                                (int)$inv['id']
                            ]);

                            if ($aceptar->rowCount() !== 1) {
                                throw new RuntimeException(
                                    'La invitación ya fue utilizada.'
                                );
                            }

                            $pdo->commit();

                            /*
                             * Iniciar sesión automáticamente como Hija.
                             */
                            session_regenerate_id(true);

                            $_SESSION['user_id'] = $usuario_id;
                            $_SESSION['user_tipo'] = 'vendedor';
                            $_SESSION['user_nombre'] = $nombre_final;

                            header(
                                'Location: ' .
                                SITE_URL .
                                '/vendedor.php?sec=inicio'
                            );
                            exit;
                        }
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }

                        $error = $e instanceof RuntimeException
                            ? $e->getMessage()
                            : 'No se pudo aceptar la invitación.';
                    }
                }
            }
        }
    }
}

$nombre_padre = $invitacion
    ? (
        $invitacion['padre_panaderia'] ?:
        $invitacion['padre_nombre']
    )
    : '';

$es_nueva_cuenta = $invitacion && !$usuario_existente;
$mostrar_formulario = (
    $invitacion &&
    $invitacion['estado'] === 'pendiente' &&
    !$finalizada
);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0">

    <title>
        Invitación de sucursal — <?= h(SITE_NAME) ?>
    </title>

    <link
        rel="stylesheet"
        href="<?= SITE_URL ?>/css/global.css">

    <link
        rel="stylesheet"
        href="<?= SITE_URL ?>/css/login.css">

    <style>
        .invitacion-wrap {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 28px 16px;
            box-sizing: border-box;
            background: var(--crema, #fff8ee);
        }

        .invitacion-card {
            width: 100%;
            max-width: 540px;
            background: #fff;
            border-radius: 18px;
            padding: 30px;
            box-sizing: border-box;
            box-shadow: 0 12px 42px rgba(59, 26, 10, .12);
        }

        .invitacion-card h1 {
            margin: 0 0 8px;
            color: var(--marron, #3b1a0a);
        }

        .invitacion-datos {
            background: var(--crema, #fff8ee);
            border-radius: 12px;
            padding: 16px;
            margin: 20px 0;
        }

        .invitacion-datos p {
            margin: 5px 0;
            color: var(--gris, #756e68);
        }

        .invitacion-datos strong {
            color: var(--marron, #3b1a0a);
        }

        .invitacion-alerta {
            padding: 13px 16px;
            border-radius: 10px;
            margin-bottom: 18px;
            font-size: .9rem;
            font-weight: 600;
        }

        .invitacion-error {
            color: #a32121;
            background: #ffebee;
            border-left: 4px solid #d32f2f;
        }

        .invitacion-ok {
            color: #246b35;
            background: #e8f5e9;
            border-left: 4px solid #43a047;
        }

        .invitacion-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 20px;
        }

        .invitacion-actions .btn {
            flex: 1;
            min-width: 150px;
        }

        .invitacion-ayuda {
            margin-top: 18px;
            color: var(--gris, #756e68);
            font-size: .8rem;
            line-height: 1.5;
        }
    </style>
</head>

<body>
    <main class="invitacion-wrap">
        <section class="invitacion-card">

            <div style="text-align:center;margin-bottom:20px">
                <div style="font-size:2.7rem">🥖</div>

                <h1>Invitación de sucursal</h1>

                <p style="margin:0;color:var(--gris,#756e68)">
                    PanaderiaMarket
                </p>
            </div>

            <?php if ($error): ?>
                <div class="invitacion-alerta invitacion-error">
                    <?= h($error) ?>
                </div>
            <?php endif; ?>

            <?php if ($ok): ?>
                <div class="invitacion-alerta invitacion-ok">
                    <?= h($ok) ?>
                </div>
            <?php endif; ?>

            <?php if ($invitacion): ?>
                <div class="invitacion-datos">
                    <p>
                        <strong>Sucursal:</strong>
                        <?= h($invitacion['sucursal_nombre']) ?>
                    </p>

                    <?php if (!empty($invitacion['sucursal_direccion'])): ?>
                        <p>
                            <strong>Dirección:</strong>
                            <?= h($invitacion['sucursal_direccion']) ?>
                        </p>
                    <?php endif; ?>

                    <?php if (!empty($invitacion['sucursal_telefono'])): ?>
                        <p>
                            <strong>Teléfono:</strong>
                            <?= h($invitacion['sucursal_telefono']) ?>
                        </p>
                    <?php endif; ?>

                    <p>
                        <strong>Encargado Padre:</strong>
                        <?= h($nombre_padre) ?>
                    </p>

                    <p>
                        <strong>Email invitado:</strong>
                        <?= h($invitacion['email_invitado']) ?>
                    </p>
                </div>
            <?php endif; ?>

            <?php if ($mostrar_formulario): ?>
                <form method="POST">
                    <input
                        type="hidden"
                        name="token"
                        value="<?= h($token) ?>">

                    <input
                        type="hidden"
                        name="accion"
                        value="aceptar">

                    <?php if ($es_nueva_cuenta): ?>
                        <p style="margin:0 0 16px;color:var(--gris,#756e68)">
                            Creá tu acceso para administrar esta sucursal Hija.
                        </p>

                        <div
                            class="field"
                            style="margin-bottom:14px">

                            <label for="nombre">
                                Nombre completo
                            </label>

                            <input
                                id="nombre"
                                name="nombre"
                                type="text"
                                maxlength="120"
                                value="<?= h($invitacion['nombre_invitado']) ?>"
                                required>
                        </div>
                    <?php else: ?>
                        <p style="margin:0 0 16px;color:var(--gris,#756e68)">
                            Esta invitación corresponde a una cuenta existente.
                            Confirmá tu contraseña para vincularla con la sucursal.
                        </p>
                    <?php endif; ?>

                    <div
                        class="field"
                        style="margin-bottom:14px">

                        <label for="password">
                            <?= $es_nueva_cuenta
                                ? 'Nueva contraseña'
                                : 'Contraseña actual' ?>
                        </label>

                        <input
                            id="password"
                            name="password"
                            type="password"
                            minlength="8"
                            autocomplete="<?= $es_nueva_cuenta
                                ? 'new-password'
                                : 'current-password' ?>"
                            required>
                    </div>

                    <?php if ($es_nueva_cuenta): ?>
                        <div class="field">
                            <label for="password_confirmacion">
                                Repetir contraseña
                            </label>

                            <input
                                id="password_confirmacion"
                                name="password_confirmacion"
                                type="password"
                                minlength="8"
                                autocomplete="new-password"
                                required>
                        </div>
                    <?php endif; ?>

                    <div class="invitacion-actions">
                        <button
                            type="submit"
                            class="btn btn-naranja">

                            Aceptar invitación
                        </button>
                    </div>
                </form>

                <form
                    method="POST"
                    onsubmit="return confirm('¿Rechazar esta invitación?')">

                    <input
                        type="hidden"
                        name="token"
                        value="<?= h($token) ?>">

                    <input
                        type="hidden"
                        name="accion"
                        value="rechazar">

                    <div
                        class="invitacion-actions"
                        style="margin-top:10px">

                        <button
                            type="submit"
                            class="btn btn-ghost"
                            style="color:#b3261e">

                            Rechazar invitación
                        </button>
                    </div>
                </form>
            <?php endif; ?>

            <?php if (!$invitacion || $finalizada): ?>
                <div style="text-align:center;margin-top:20px">
                    <a
                        href="<?= SITE_URL ?>/index.php"
                        class="btn btn-ghost">

                        Volver al inicio
                    </a>
                </div>
            <?php endif; ?>

            <p class="invitacion-ayuda">
                El enlace es de un solo uso. Si no reconocés esta invitación,
                simplemente rechazala o cerrá esta página.
            </p>
        </section>
    </main>
</body>
</html>