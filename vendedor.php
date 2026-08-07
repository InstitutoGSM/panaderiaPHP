<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';
requerir_encargado();

$u = usuario_actual();

if (!$u) {
  header('Location: ' . SITE_URL . '/index.php');
  exit;
}

$uid      = (int)$u['id'];
$tipo_suc = $u['tipo_sucursal'] ?? null; // 'padre', 'hija', o null

// Sucursal activa perteneciente al Encargado actual.
// Este ID se usa para herencias y movimientos.
$sucursal_q = db()->prepare("
  SELECT id
  FROM sucursales
  WHERE vendedor_id = ? AND activo = 1
  ORDER BY id
  LIMIT 1
");
$sucursal_q->execute([$uid]);

$mi_sucursal_id = $sucursal_q->fetchColumn();
$mi_sucursal_id = $mi_sucursal_id === false
  ? null
  : (int)$mi_sucursal_id;

$seccion = $_GET['sec'] ?? 'inicio';
$msg_ok  = '';
$msg_err = '';

// Solicitud vigente de este vendedor para ser Padre
$sol_padre = null;
try {
  $sp = db()->prepare("SELECT * FROM solicitudes_padre WHERE vendedor_id = ? ORDER BY id DESC LIMIT 1");
  $sp->execute([$uid]);
  $sol_padre = $sp->fetch() ?: null;
} catch (Exception $e) {}

/* ══════════════════════════════════════════════════════════════════════════
   POST HANDLERS
══════════════════════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verificar();
  $accion = $_POST['accion'] ?? '';

  /* ── Solicitar ser Encargado Padre ─────────────────────────────── */
  if ($accion === 'solicitar_ser_padre') {
    if ($u['estado_verificacion'] !== 'aprobado') {
      $msg_err = 'Tu cuenta debe estar aprobada para hacer esta solicitud.';
    } elseif ($tipo_suc) {
      $msg_err = 'Ya tenés un rol asignado.';
    } elseif ($sol_padre && $sol_padre['estado'] === 'pendiente') {
      $msg_err = 'Ya tenés una solicitud pendiente.';
    } else {
      db()->prepare("
        INSERT INTO solicitudes_padre (vendedor_id, estado)
        VALUES (?, 'pendiente')
        ON DUPLICATE KEY UPDATE estado='pendiente', motivo_rechazo=NULL, updated_at=NOW()
      ")->execute([$uid]);
      // Recargar solicitud
      $sp2 = db()->prepare("SELECT * FROM solicitudes_padre WHERE vendedor_id = ? ORDER BY id DESC LIMIT 1");
      $sp2->execute([$uid]);
      $sol_padre = $sp2->fetch() ?: null;
      $msg_ok = 'Solicitud enviada. El administrador la revisará pronto.';
    }
  }

  /* ── Crear invitación para sucursal Hija ───────────────────────────── */
  if ($accion === 'crear_invitacion_sucursal') {
    $seccion = 'hijas';

    if ($tipo_suc !== 'padre') {
      $msg_err = 'Solo el Encargado Padre puede crear sucursales Hija.';
    } else {
      $nombre_sucursal = trim($_POST['nombre_sucursal'] ?? '');
      $direccion        = trim($_POST['direccion'] ?? '');
      $telefono         = trim($_POST['telefono'] ?? '');
      $nombre_invitado  = trim($_POST['nombre_invitado'] ?? '');
      $email_invitado   = strtolower(trim($_POST['email_invitado'] ?? ''));

      if (!$nombre_sucursal || !$nombre_invitado || !$email_invitado) {
        $msg_err = 'Nombre de sucursal, nombre del invitado y email son obligatorios.';
      } elseif (mb_strlen($nombre_sucursal) > 255) {
        $msg_err = 'El nombre de la sucursal no puede superar los 255 caracteres.';
      } elseif (mb_strlen($nombre_invitado) > 120) {
        $msg_err = 'El nombre del invitado no puede superar los 120 caracteres.';
      } elseif (mb_strlen($direccion) > 500) {
        $msg_err = 'La dirección no puede superar los 500 caracteres.';
      } elseif (mb_strlen($telefono) > 50) {
        $msg_err = 'El teléfono no puede superar los 50 caracteres.';
      } elseif (!filter_var($email_invitado, FILTER_VALIDATE_EMAIL)) {
        $msg_err = 'El email del invitado no es válido.';
      } else {
        $pdo = db();

        try {
          $pdo->beginTransaction();

          /*
           * Confirmar nuevamente el rol del Padre desde la base de datos.
           * No confiamos únicamente en el valor cargado al inicio de la página.
           */
          $padre_q = $pdo->prepare("
            SELECT id
            FROM usuarios
            WHERE id = ?
              AND tipo = 'vendedor'
              AND is_admin_pan = 1
              AND tipo_sucursal = 'padre'
            LIMIT 1
            FOR UPDATE
          ");
          $padre_q->execute([$uid]);

          if (!$padre_q->fetchColumn()) {
            throw new RuntimeException(
              'La cuenta actual no está habilitada como Encargado Padre.'
            );
          }

          /*
           * Confirmar que el Padre tenga una sucursal activa propia.
           */
          $sucursal_padre_q = $pdo->prepare("
            SELECT id
            FROM sucursales
            WHERE vendedor_id = ?
              AND padre_id IS NULL
              AND activo = 1
              AND estado = 'activa'
            ORDER BY id
            LIMIT 1
            FOR UPDATE
          ");
          $sucursal_padre_q->execute([$uid]);
          $sucursal_padre_id = $sucursal_padre_q->fetchColumn();

          if (!$sucursal_padre_id) {
            throw new RuntimeException(
              'El Encargado Padre no tiene una sucursal activa propia.'
            );
          }

          /*
           * No permitir dos invitaciones pendientes para el mismo email.
           */
          $invitacion_existente_q = $pdo->prepare("
            SELECT id
            FROM invitaciones_sucursal
            WHERE email_invitado = ?
              AND estado = 'pendiente'
              AND expires_at > NOW()
            LIMIT 1
            FOR UPDATE
          ");
          $invitacion_existente_q->execute([$email_invitado]);

          if ($invitacion_existente_q->fetchColumn()) {
            throw new RuntimeException(
              'Ya existe una invitación pendiente para ese email.'
            );
          }

          /*
           * Si el email ya existe, el usuario debe ser un vendedor aprobado
           * y no debe pertenecer ya a otra estructura de sucursales.
           */
          $usuario_invitado_id = null;

          $usuario_q = $pdo->prepare("
            SELECT
              id,
              nombre,
              tipo,
              estado_verificacion,
              tipo_sucursal,
              sucursal_padre_id
            FROM usuarios
            WHERE email = ?
            LIMIT 1
            FOR UPDATE
          ");
          $usuario_q->execute([$email_invitado]);
          $usuario_existente = $usuario_q->fetch();

          if ($usuario_existente) {
            $usuario_invitado_id = (int)$usuario_existente['id'];

            if ($usuario_invitado_id === $uid) {
              throw new RuntimeException(
                'No podés invitarte a vos mismo como Encargado Hijo.'
              );
            }

            if ($usuario_existente['tipo'] !== 'vendedor') {
              throw new RuntimeException(
                'El email ya pertenece a una cuenta que no es vendedor.'
              );
            }

            if ($usuario_existente['estado_verificacion'] !== 'aprobado') {
              throw new RuntimeException(
                'El vendedor existente debe estar aprobado antes de recibir una invitación.'
              );
            }

            if (
              in_array(
                $usuario_existente['tipo_sucursal'],
                ['padre', 'hija'],
                true
              ) ||
              !empty($usuario_existente['sucursal_padre_id'])
            ) {
              throw new RuntimeException(
                'El vendedor ya pertenece a otra estructura de sucursales.'
              );
            }

            /*
             * Evitar que un vendedor con otra sucursal activa o pendiente
             * reciba una relación incompatible.
             */
            $otra_sucursal_q = $pdo->prepare("
              SELECT id
              FROM sucursales
              WHERE vendedor_id = ?
                AND estado IN ('pendiente', 'activa')
              LIMIT 1
              FOR UPDATE
            ");
            $otra_sucursal_q->execute([$usuario_invitado_id]);

            if ($otra_sucursal_q->fetchColumn()) {
              throw new RuntimeException(
                'El vendedor ya tiene una sucursal activa o pendiente.'
              );
            }
          }

          /*
           * Generar el token únicamente en memoria.
           * En la base de datos se almacenará solo su hash.
           */
          $token      = bin2hex(random_bytes(32));
          $token_hash = hash('sha256', $token);

          /*
           * Crear la sucursal pendiente sin vendedor asignado.
           * La vinculación definitiva se hará al aceptar la invitación.
           */
          $crear_sucursal = $pdo->prepare("
            INSERT INTO sucursales (
              vendedor_id,
              padre_id,
              nombre,
              direccion,
              telefono,
              activo,
              estado
            ) VALUES (
              NULL,
              ?,
              ?,
              ?,
              ?,
              0,
              'pendiente'
            )
          ");

          $crear_sucursal->execute([
            $uid,
            $nombre_sucursal,
            $direccion ?: null,
            $telefono ?: null
          ]);

          $nueva_sucursal_id = (int)$pdo->lastInsertId();

          /*
           * Crear la invitación pendiente con una vigencia de 7 días.
           */
          $crear_invitacion = $pdo->prepare("
            INSERT INTO invitaciones_sucursal (
              padre_id,
              sucursal_id,
              usuario_invitado_id,
              email_invitado,
              nombre_invitado,
              token_hash,
              estado,
              expires_at
            ) VALUES (
              ?,
              ?,
              ?,
              ?,
              ?,
              ?,
              'pendiente',
              DATE_ADD(NOW(), INTERVAL 7 DAY)
            )
          ");

          $crear_invitacion->execute([
            $uid,
            $nueva_sucursal_id,
            $usuario_invitado_id,
            $email_invitado,
            $nombre_invitado,
            $token_hash
          ]);

          $pdo->commit();

          /*
           * El token real solo se conserva temporalmente en la sesión
           * para mostrar el enlace en la interfaz del siguiente subgrupo.
           */
          $_SESSION['ultima_invitacion_sucursal'] = [
            'sucursal_id' => $nueva_sucursal_id,
            'email'       => $email_invitado,
            'link'        => SITE_URL . '/aceptar-invitacion.php?token=' . urlencode($token)
          ];

          $msg_ok = 'Sucursal Hija creada como pendiente. La invitación está lista para enviar.';
        } catch (Throwable $e) {
          if ($pdo->inTransaction()) {
            $pdo->rollBack();
          }

          $msg_err = $e instanceof RuntimeException
            ? $e->getMessage()
            : 'No se pudo crear la sucursal ni la invitación.';
        }
      }
    }

    $accion = '';
  }

  // Las Hijas no pueden crear, editar, activar, desactivar ni eliminar productos.
  $acciones_solo_padre = [
    'add_producto',
    'edit_producto',
    'toggle',
    'delete'
  ];

  if (
    in_array($accion, $acciones_solo_padre, true) &&
    $tipo_suc === 'hija'
  ) {
    $msg_err = 'Las Sucursales Hija no pueden gestionar productos propios.';
    $seccion = 'productos';
    $accion = '';
  }

  /* ── Agregar producto ─────────────────────────────────────────────── */
  if ($accion === 'add_producto') {
    $nombre  = trim($_POST['nombre']   ?? '');
    $desc    = trim($_POST['desc']     ?? '');
    $precio  = (float)($_POST['precio'] ?? 0);
    $cat     = $_POST['cat']           ?? 'pan';
    $unidad  = $_POST['unidad']        ?? 'unidad';
    $med_doc = $unidad === 'kilo' ? null : (($_POST['media_doc'] ?? '') !== '' ? (float)$_POST['media_doc'] : null);
    $docena  = $unidad === 'kilo' ? null : (($_POST['docena']    ?? '') !== '' ? (float)$_POST['docena']    : null);
    $stock   = (int)($_POST['stock']   ?? 0);
    $extra   = trim($_POST['extra']    ?? '') ?: null;

    if (!$nombre || $precio <= 0) {
      $msg_err = 'Completá nombre y precio.';
      $seccion = 'add';
    } else {
      $img_url = null;
      if (!empty($_FILES['imagen']['name'])) {
        $img_url = subir_imagen($_FILES['imagen'], 'prod');
        if (!$img_url) {
          $msg_err = 'Error al subir imagen (máx 5MB, jpg/png/webp).';
          $seccion = 'add';
        }
      }
      if (!$msg_err) {
        db()->prepare("
                    INSERT INTO productos
                      (vendedor_id, nombre, descripcion, precio, categoria,
                       unidad_venta, precio_media_docena, precio_docena,
                       cantidad_disponible, dato_extra, imagen_url)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?)
                ")->execute([$uid, $nombre, $desc, $precio, $cat, $unidad, $med_doc, $docena, $stock, $extra, $img_url]);
        $msg_ok  = '¡Producto publicado! 🎉';
        $seccion = 'productos';
      }
    }
  }

  /* ── Editar producto ──────────────────────────────────────────────── */
  if ($accion === 'edit_producto') {
    $pid     = (int)($_POST['pid']     ?? 0);
    $nombre  = trim($_POST['nombre']   ?? '');
    $desc    = trim($_POST['desc']     ?? '');
    $precio  = (float)($_POST['precio'] ?? 0);
    $cat     = $_POST['cat']           ?? 'pan';
    $unidad  = $_POST['unidad']        ?? 'unidad';
    $med_doc = $unidad === 'kilo' ? null : (($_POST['media_doc'] ?? '') !== '' ? (float)$_POST['media_doc'] : null);
    $docena  = $unidad === 'kilo' ? null : (($_POST['docena']    ?? '') !== '' ? (float)$_POST['docena']    : null);
    $stock   = (int)($_POST['stock']   ?? 0);
    $extra   = trim($_POST['extra']    ?? '') ?: null;

    $chk = db()->prepare("SELECT id FROM productos WHERE id=? AND vendedor_id=?");
    $chk->execute([$pid, $uid]);
    if (!$chk->fetch()) {
      $msg_err = 'Producto no encontrado.';
      $seccion = 'productos';
    } elseif (!$nombre || $precio <= 0) {
      $msg_err = 'Completá nombre y precio.';
      $seccion = 'add';
    } else {
      $img_url = null;
      if (!empty($_FILES['imagen']['name'])) $img_url = subir_imagen($_FILES['imagen'], 'prod');
      if ($img_url) {
        db()->prepare("UPDATE productos SET nombre=?,descripcion=?,precio=?,categoria=?,
                    unidad_venta=?,precio_media_docena=?,precio_docena=?,
                    cantidad_disponible=?,dato_extra=?,imagen_url=? WHERE id=? AND vendedor_id=?")
          ->execute([$nombre, $desc, $precio, $cat, $unidad, $med_doc, $docena, $stock, $extra, $img_url, $pid, $uid]);
      } else {
        db()->prepare("UPDATE productos SET nombre=?,descripcion=?,precio=?,categoria=?,
                    unidad_venta=?,precio_media_docena=?,precio_docena=?,
                    cantidad_disponible=?,dato_extra=? WHERE id=? AND vendedor_id=?")
          ->execute([$nombre, $desc, $precio, $cat, $unidad, $med_doc, $docena, $stock, $extra, $pid, $uid]);
      }
      $msg_ok  = 'Producto actualizado ✅';
      $seccion = 'productos';
    }
  }

  /* ── Toggle activo ────────────────────────────────────────────────── */
  if ($accion === 'toggle') {
    $pid = (int)($_POST['pid'] ?? 0);
    db()->prepare("UPDATE productos SET activo = NOT activo WHERE id=? AND vendedor_id=?")->execute([$pid, $uid]);
    header('Location: vendedor.php?sec=productos');
    exit;
  }

  /* ── Eliminar producto ────────────────────────────────────────────── */
  if ($accion === 'delete') {
    $pid = (int)($_POST['pid'] ?? 0);
    db()->prepare("DELETE FROM productos WHERE id=? AND vendedor_id=?")->execute([$pid, $uid]);
    header('Location: vendedor.php?sec=productos&ok=eliminado');
    exit;
  }

  /* ── Estado pedido ────────────────────────────────────────────────── */
  if ($accion === 'estado_pedido') {
    $pid    = (int)($_POST['pedido_id'] ?? 0);
    $estado = $_POST['estado'] ?? '';
    if (in_array($estado, ['pendiente', 'confirmado', 'listo', 'entregado'])) {
      db()->prepare("UPDATE pedidos SET estado=? WHERE id=? AND vendedor_id=?")->execute([$estado, $pid, $uid]);
    }
    header('Location: vendedor.php?sec=pedidos');
    exit;
  }

  /* ── Solo Encargados con rol Padre o Hija pueden gestionar trabajadores */
  $acciones_trabajadores = [
    'set_identificador',
    'crear_trabajador',
    'eliminar_trabajador'
  ];

  if (
    in_array($accion, $acciones_trabajadores, true) &&
    !in_array($tipo_suc, ['padre', 'hija'], true)
  ) {
    $msg_err = 'Tu cuenta todavía no tiene asignado el rol Padre o Hija.';
    $seccion = 'inicio';
    $accion = '';
  }

  /* ── Set identificador ────────────────────────────────────────────── */
  if ($accion === 'set_identificador' && $u['is_admin_pan']) {
    $ident = preg_replace('/[^a-zA-Z0-9_]/', '', trim($_POST['identificador'] ?? ''));
    if (!$ident) {
      $msg_err = 'Identificador inválido.';
      $seccion = 'trabajadores';
    } else {
      try {
        db()->prepare("UPDATE usuarios SET identificador=? WHERE id=?")->execute([$ident, $uid]);
        $u = usuario_actual();
        $msg_ok = '¡Identificador @' . $ident . ' configurado!';
      } catch (Exception $e) {
        $msg_err = 'Ese identificador ya está en uso, elegí otro.';
      }
      $seccion = 'trabajadores';
    }
  }

  /* ── Crear trabajador ─────────────────────────────────────────────── */
  if ($accion === 'crear_trabajador') {
    $nombre    = trim($_POST['nombre']       ?? '');
    $ident_t   = preg_replace('/[^a-zA-Z0-9_]/', '', trim($_POST['identificador'] ?? ''));
    $documento = trim($_POST['documento_id'] ?? '');
    $email_t   = trim($_POST['email']        ?? '');
    $pass_t    = trim($_POST['password']     ?? '');
    if (!$nombre || !$ident_t || !$documento || !$email_t || strlen($pass_t) < 6) {
      $msg_err = 'Todos los campos son obligatorios (contraseña mínimo 6 caracteres).';
      $seccion = 'trabajadores';
    } else {
      $avatar_t = null;
      if (!empty($_FILES['avatar']['name'])) $avatar_t = subir_imagen($_FILES['avatar'], 'avatar');
      try {
        db()->prepare("
                    INSERT INTO usuarios (nombre, identificador, documento_id, email, password_hash, tipo, panaderia_id, avatar_url, estado_verificacion)
                    VALUES (?,?,?,?,?, 'trabajador', ?,?, 'aprobado')
                ")->execute([$nombre, $ident_t, $documento, $email_t, password_hash($pass_t, PASSWORD_DEFAULT), $uid, $avatar_t]);
        $msg_ok = '¡Trabajador ' . $nombre . ' creado! ✅';
      } catch (Exception $e) {
        $msg = $e->getMessage();
        if (str_contains($msg, 'email') || str_contains($msg, 'Duplicate') && str_contains($msg, 'email')) {
          $msg_err = 'Ese email ya está registrado en el sistema (puede ser una cuenta compradora). Usá otro.';
        } elseif (str_contains($msg, 'identificador')) {
          $msg_err = 'Ese @identificador ya lo usa otra cuenta. Elegí uno diferente.';
        } else {
          $msg_err = 'Error al crear trabajador. Detalle: ' . $e->getMessage();
        }
      }
      $seccion = 'trabajadores';
    }
  }

  /* ── Eliminar trabajador ──────────────────────────────────────────── */
  if ($accion === 'eliminar_trabajador') {
    $trab_id = (int)($_POST['trab_id'] ?? 0);
    db()->prepare("DELETE FROM usuarios WHERE id=? AND tipo='trabajador' AND panaderia_id=?")->execute([$trab_id, $uid]);
    $msg_ok  = 'Trabajador eliminado.';
    $seccion = 'trabajadores';
  }

  /* ── Asignar producto a Hija (solo Padre) ────────────────────────────── */
  if ($accion === 'asignar_herencia' && $tipo_suc === 'padre') {
    $prod_id     = (int)($_POST['producto_id'] ?? 0);
    $sucursal_id = (int)($_POST['sucursal_id'] ?? 0);
    $precio_min  = (float)($_POST['precio_minimo'] ?? 0);

    // El producto debe pertenecer al Padre y estar activo.
    $chk = db()->prepare("
    SELECT id, precio
    FROM productos
    WHERE id = ?
      AND vendedor_id = ?
      AND activo = 1
    LIMIT 1
  ");
    $chk->execute([$prod_id, $uid]);
    $prod = $chk->fetch();

    if (!$prod || !$sucursal_id || $precio_min <= 0) {
      $msg_err = 'Completá todos los campos correctamente.';
    } elseif ($precio_min > (float)$prod['precio']) {
      $msg_err = 'El precio mínimo no puede superar el precio del producto (' .
        precio((float)$prod['precio']) . ').';
    } else {
      /*
     * sucursal_id debe ser realmente una sucursal activa,
     * cuyo vendedor sea una Hija Encargada vinculada a este Padre.
     */
      $chk2 = db()->prepare("
      SELECT s.id
      FROM sucursales s
      INNER JOIN usuarios hija ON hija.id = s.vendedor_id
      WHERE s.id = ?
        AND s.activo = 1
        AND hija.tipo = 'vendedor'
        AND hija.is_admin_pan = 1
        AND hija.tipo_sucursal = 'hija'
        AND hija.sucursal_padre_id = ?
      LIMIT 1
    ");
      $chk2->execute([$sucursal_id, $uid]);

      if (!$chk2->fetch()) {
        $msg_err = 'La sucursal Hija seleccionada no es válida o no pertenece a este Padre.';
      } else {
        try {
          db()->prepare("
          INSERT INTO herencia_productos
            (producto_id, padre_id, sucursal_id, precio_minimo)
          VALUES (?, ?, ?, ?)
          ON DUPLICATE KEY UPDATE
            precio_minimo = VALUES(precio_minimo),
            aceptado = 0,
            precio_sucursal = NULL
        ")->execute([
            $prod_id,
            $uid,
            $sucursal_id,
            $precio_min
          ]);

          $msg_ok = '¡Producto asignado a la sucursal Hija! ✅';
        } catch (Exception $e) {
          $msg_err = 'No se pudo asignar el producto.';
        }
      }
    }

    $seccion = 'hijas';
  }

  /* ── Asignar todos los productos activos a una Hija ─────────────────── */
if ($accion === 'asignar_todos_herencia' && $tipo_suc === 'padre') {
  $sucursal_id = (int)($_POST['sucursal_id'] ?? 0);

  if (!$sucursal_id) {
    $msg_err = 'Seleccioná una sucursal Hija.';
  } else {
    $pdo = db();

    try {
      $pdo->beginTransaction();

      /*
       * Confirmar nuevamente que el usuario actual es Padre.
       */
      $padre_q = $pdo->prepare("
        SELECT id
        FROM usuarios
        WHERE id = ?
          AND tipo = 'vendedor'
          AND is_admin_pan = 1
          AND tipo_sucursal = 'padre'
        LIMIT 1
        FOR UPDATE
      ");

      $padre_q->execute([$uid]);

      if (!$padre_q->fetchColumn()) {
        throw new RuntimeException(
          'La cuenta actual no está habilitada como Encargado Padre.'
        );
      }

      /*
       * Confirmar que la sucursal Hija pertenece a este Padre.
       */
      $hija_q = $pdo->prepare("
        SELECT s.id
        FROM sucursales s
        INNER JOIN usuarios hija
          ON hija.id = s.vendedor_id
        WHERE s.id = ?
          AND s.activo = 1
          AND s.estado = 'activa'
          AND hija.tipo = 'vendedor'
          AND hija.is_admin_pan = 1
          AND hija.tipo_sucursal = 'hija'
          AND hija.sucursal_padre_id = ?
        LIMIT 1
        FOR UPDATE
      ");

      $hija_q->execute([
        $sucursal_id,
        $uid
      ]);

      if (!$hija_q->fetchColumn()) {
        throw new RuntimeException(
          'La sucursal Hija seleccionada no pertenece a este Padre.'
        );
      }

      /*
       * Obtener sólo los productos activos del Padre.
       */
      $productos_q = $pdo->prepare("
        SELECT
          id,
          precio
        FROM productos
        WHERE vendedor_id = ?
          AND activo = 1
        ORDER BY id
      ");

      $productos_q->execute([$uid]);
      $productos_activos = $productos_q->fetchAll();

      if (!$productos_activos) {
        throw new RuntimeException(
          'El Padre todavía no tiene productos activos para asignar.'
        );
      }

      /*
       * Asignar cada producto a la Hija.
       *
       * El precio mínimo queda igual al precio del Padre.
       *
       * Si ya estaba aceptado y el precio de la Hija sigue cumpliendo
       * el nuevo mínimo, conserva su aceptación.
       *
       * Si ya no cumple, vuelve a estado pendiente.
       */
      $asignar = $pdo->prepare("
        INSERT INTO herencia_productos (
          producto_id,
          padre_id,
          sucursal_id,
          precio_minimo,
          aceptado,
          precio_sucursal
        ) VALUES (?, ?, ?, ?, 0, NULL)

        ON DUPLICATE KEY UPDATE
          padre_id = VALUES(padre_id),
          precio_minimo = VALUES(precio_minimo),

          aceptado = CASE
            WHEN aceptado = 1
              AND precio_sucursal >= VALUES(precio_minimo)
            THEN 1
            ELSE 0
          END,

          precio_sucursal = CASE
            WHEN aceptado = 1
              AND precio_sucursal >= VALUES(precio_minimo)
            THEN precio_sucursal
            ELSE NULL
          END
      ");

      foreach ($productos_activos as $producto) {
        $asignar->execute([
          (int)$producto['id'],
          $uid,
          $sucursal_id,
          (float)$producto['precio']
        ]);
      }

      $cantidad_asignada = count($productos_activos);

      $pdo->commit();

      $msg_ok =
        'Se asignaron ' .
        $cantidad_asignada .
        ' productos activos a la sucursal Hija. ' .
        'La Hija deberá aceptarlos antes de venderlos.';
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }

      $msg_err = $e instanceof RuntimeException
        ? $e->getMessage()
        : 'No se pudieron asignar los productos a la sucursal Hija.';
    }
  }

  $seccion = 'hijas';
}

  /* ── Aceptar herencia con precio propio (solo Hija) ──────────────────── */
  if ($accion === 'aceptar_herencia' && $tipo_suc === 'hija') {
    $her_id          = (int)($_POST['herencia_id'] ?? 0);
    $precio_sucursal = (float)($_POST['precio_sucursal'] ?? 0);

    if (!$mi_sucursal_id) {
      $msg_err = 'Tu usuario no tiene una sucursal activa asignada.';
    } else {
      $chk = db()->prepare("
      SELECT id, precio_minimo
      FROM herencia_productos
      WHERE id = ?
        AND sucursal_id = ?
      LIMIT 1
    ");
      $chk->execute([$her_id, $mi_sucursal_id]);
      $her = $chk->fetch();

      if (!$her) {
        $msg_err = 'Herencia no encontrada para tu sucursal.';
      } elseif ($precio_sucursal < (float)$her['precio_minimo']) {
        $msg_err = 'El precio debe ser igual o mayor al precio mínimo (' .
          precio((float)$her['precio_minimo']) . ').';
      } else {
        db()->prepare("
        UPDATE herencia_productos
        SET aceptado = 1,
            precio_sucursal = ?
        WHERE id = ?
          AND sucursal_id = ?
      ")->execute([
          $precio_sucursal,
          $her_id,
          $mi_sucursal_id
        ]);

        $msg_ok = '¡Producto aceptado y activo en tu tienda! ✅';
      }
    }

    $seccion = 'productos';
  }

  /* ── Revocar herencia (Hija deja de venderlo) ────────────────────────── */
  if ($accion === 'revocar_herencia' && $tipo_suc === 'hija') {
    $her_id = (int)($_POST['herencia_id'] ?? 0);

    if (!$mi_sucursal_id) {
      $msg_err = 'Tu usuario no tiene una sucursal activa asignada.';
    } else {
      $revocar = db()->prepare("
      UPDATE herencia_productos
      SET aceptado = 0,
          precio_sucursal = NULL
      WHERE id = ?
        AND sucursal_id = ?
    ");
      $revocar->execute([$her_id, $mi_sucursal_id]);

      if ($revocar->rowCount() > 0) {
        $msg_ok = 'Producto removido de tu tienda.';
      } else {
        $msg_err = 'La herencia no pertenece a tu sucursal.';
      }
    }

    $seccion = 'productos';
  }

  /* ── Guardar perfil ───────────────────────────────────────────────── */
  if ($accion === 'perfil') {
    $nombre_pan = trim($_POST['nombre_panaderia'] ?? '');
    $nombre     = trim($_POST['nombre']           ?? '');
    $desc       = trim($_POST['descripcion']      ?? '');
    $instagram  = trim($_POST['instagram']        ?? '');
    $telefono   = trim($_POST['telefono']         ?? '');
    $email_c    = trim($_POST['email_contacto']   ?? '');
    $banner     = trim($_POST['banner']           ?? '') ?: null;
    $cbu        = trim($_POST['cbu']              ?? '');
    $alias      = trim($_POST['alias_cbu']        ?? '');
    $titular    = trim($_POST['titular_cuenta']   ?? '');

    $medios = ['efectivo'];
    if (!empty($_POST['medio_transferencia'])) $medios[] = 'transferencia';
    if (!empty($_POST['medio_debito']))         $medios[] = 'debito';
    if (!empty($_POST['medio_credito']))        $medios[] = 'credito';

    if (in_array('transferencia', $medios) && !$cbu && !$alias) {
      $msg_err = 'Para aceptar transferencias ingresá al menos el CBU o el alias.';
      $seccion = 'perfil';
    } else {
      $medios_str = implode(',', $medios);
      $avatar_url = $u['avatar_url'] ?? null;
      if (!empty($_FILES['avatar']['name'])) {
        $nueva = subir_imagen($_FILES['avatar'], 'avatar');
        if ($nueva) $avatar_url = $nueva;
      }
      db()->prepare("UPDATE usuarios SET
                nombre=?, nombre_panaderia=?, descripcion=?, banner_anuncio=?,
                instagram=?, telefono=?, email_contacto=?,
                cbu=?, alias_cbu=?, titular_cuenta=?,
                medios_pago=?, avatar_url=?
              WHERE id=?")
        ->execute([
          $nombre,
          $nombre_pan,
          $desc,
          $banner,
          $instagram,
          $telefono,
          $email_c,
          $cbu,
          $alias,
          $titular,
          $medios_str,
          $avatar_url,
          $uid
        ]);
      $u       = usuario_actual();
      $msg_ok  = 'Perfil actualizado ✅';
      $seccion = 'perfil';
    }
  }
}

/* ══════════════════════════════════════════════════════════════════════════
   DATOS
══════════════════════════════════════════════════════════════════════════ */
$st_q = db()->prepare("SELECT
  (SELECT COUNT(*) FROM productos WHERE vendedor_id=? AND activo=1)                    AS activos,
  (SELECT COUNT(*) FROM productos WHERE vendedor_id=?)                                  AS total_prods,
  (SELECT COUNT(*) FROM pedidos   WHERE vendedor_id=?)                                  AS total_pedidos,
  (SELECT COUNT(*) FROM pedidos   WHERE vendedor_id=? AND estado='pendiente')           AS pend,
  (SELECT COALESCE(SUM(total),0)  FROM pedidos WHERE vendedor_id=? AND estado='entregado') AS ingresos
");
$st_q->execute([$uid, $uid, $uid, $uid, $uid]);
$st = $st_q->fetch();

$prods_q = db()->prepare("SELECT * FROM productos WHERE vendedor_id=? ORDER BY created_at DESC");
$prods_q->execute([$uid]);
$productos = $prods_q->fetchAll();

$peds_q = db()->prepare("
    SELECT p.*, u.nombre AS nombre_comprador
    FROM pedidos p JOIN usuarios u ON u.id = p.comprador_id
    WHERE p.vendedor_id=? ORDER BY p.created_at DESC LIMIT 60
");
$peds_q->execute([$uid]);
$pedidos = $peds_q->fetchAll();

$pedidos_items = [];
if (!empty($pedidos)) {
  $pids    = implode(',', array_column($pedidos, 'id'));
  $items_q = db()->query("SELECT * FROM pedido_items WHERE pedido_id IN ($pids)");
  foreach ($items_q->fetchAll() as $it) $pedidos_items[$it['pedido_id']][] = $it;
}

$edit_prod = null;
if (!empty($_GET['edit'])) {
  $ep = db()->prepare("SELECT * FROM productos WHERE id=? AND vendedor_id=?");
  $ep->execute([(int)$_GET['edit'], $uid]);
  $edit_prod = $ep->fetch() ?: null;
  if ($edit_prod) $seccion = 'add';
}

$medios_actuales = array_filter(explode(',', $u['medios_pago'] ?? 'efectivo'));
$tiene_transf    = in_array('transferencia', $medios_actuales);

// ── Trabajadores de esta panadería ────────────────────────────────────────
$trabajadores = db()->query("
    SELECT id, nombre, email, identificador, documento_id, avatar_url, created_at
    FROM usuarios
    WHERE tipo = 'trabajador' AND panaderia_id = $uid
    ORDER BY nombre
")->fetchAll();

// ── Sucursales Hija de este Padre ─────────────────────────────────────────
$mis_hijas = [];

if ($tipo_suc === 'padre') {
  $hijas_stmt = db()->prepare("
    SELECT
      u.id,
      u.nombre,
      u.nombre_panaderia,
      u.email,
      u.avatar_url,
      u.estado_verificacion,
      s.id AS sucursal_id,
      s.nombre AS sucursal_nombre
    FROM usuarios u
    INNER JOIN sucursales s
      ON s.vendedor_id = u.id
     AND s.activo = 1
    WHERE u.tipo = 'vendedor'
      AND u.is_admin_pan = 1
      AND u.tipo_sucursal = 'hija'
      AND u.sucursal_padre_id = ?
    ORDER BY u.nombre_panaderia, s.nombre
  ");

  $hijas_stmt->execute([$uid]);
  $mis_hijas = $hijas_stmt->fetchAll();
}

// ── Invitaciones pendientes creadas por este Padre ─────────────────────────
$invitaciones_pendientes = [];
$ultima_invitacion = $_SESSION['ultima_invitacion_sucursal'] ?? null;
unset($_SESSION['ultima_invitacion_sucursal']);

if ($tipo_suc === 'padre') {
  $invitaciones_q = db()->prepare("
    SELECT
      i.id,
      i.sucursal_id,
      i.email_invitado,
      i.nombre_invitado,
      i.expires_at,
      i.created_at,
      s.nombre AS sucursal_nombre,
      s.direccion,
      s.telefono
    FROM invitaciones_sucursal i
    INNER JOIN sucursales s
      ON s.id = i.sucursal_id
    WHERE i.padre_id = ?
      AND i.estado = 'pendiente'
      AND i.expires_at > NOW()
    ORDER BY i.created_at DESC
  ");

  $invitaciones_q->execute([$uid]);
  $invitaciones_pendientes = $invitaciones_q->fetchAll();
}

// ── Padre de esta Hija ────────────────────────────────────────────────────
$mi_padre = null;
if ($tipo_suc === 'hija' && !empty($u['sucursal_padre_id'])) {
  $padre_stmt = db()->prepare("SELECT id, nombre, nombre_panaderia, email FROM usuarios WHERE id=?");
  $padre_stmt->execute([$u['sucursal_padre_id']]);
  $mi_padre = $padre_stmt->fetch() ?: null;
}

// ── Productos heredados (para Hija) ──────────────────────────────────────
$herencias_hija = [];

if ($tipo_suc === 'hija' && $mi_sucursal_id) {
  $her_stmt = db()->prepare("
    SELECT
      h.id,
      h.precio_minimo,
      h.precio_sucursal,
      h.aceptado,
      p.nombre,
      p.descripcion,
      p.categoria,
      p.imagen_url,
      p.unidad_venta,
      u.nombre_panaderia AS padre_nombre
    FROM herencia_productos h
    INNER JOIN productos p
      ON p.id = h.producto_id
     AND p.activo = 1
    INNER JOIN usuarios u
      ON u.id = h.padre_id
    WHERE h.sucursal_id = ?
    ORDER BY h.aceptado ASC, p.nombre ASC
  ");

  $her_stmt->execute([$mi_sucursal_id]);
  $herencias_hija = $her_stmt->fetchAll();
}

// ── Métricas por Hija (para Padre) ───────────────────────────────────────
$metricas_hijas = [];
if ($tipo_suc === 'padre' && !empty($mis_hijas)) {
  foreach ($mis_hijas as $h) {
    // Los pedidos usan usuarios.id.
    $hija_usuario_id = (int)$h['id'];

    // Las herencias usan sucursales.id.
    $hija_sucursal_id = (int)$h['sucursal_id'];

    $her_q = db()->prepare("
      SELECT
        COUNT(*) AS total_asignados,
        COALESCE(SUM(aceptado), 0) AS total_aceptados
      FROM herencia_productos
      WHERE padre_id = ?
        AND sucursal_id = ?
    ");
    $her_q->execute([$uid, $hija_sucursal_id]);
    $her_s = $her_q->fetch();

    $ped_q = db()->prepare("
      SELECT
        COUNT(*) AS total_pedidos,
        COALESCE(SUM(CASE WHEN estado = 'pendiente' THEN 1 ELSE 0 END), 0) AS pendientes,
        COALESCE(SUM(CASE WHEN estado = 'entregado' THEN total ELSE 0 END), 0) AS ingresos
      FROM pedidos
      WHERE vendedor_id = ?
    ");
    $ped_q->execute([$hija_usuario_id]);
    $ped_s = $ped_q->fetch();

    $metricas_hijas[] = array_merge($h, $her_s, $ped_s);
  }
}
if ($tipo_suc === 'padre') {
  $herp_stmt = db()->prepare("
    SELECT
      h.id,
      h.precio_minimo,
      h.precio_sucursal,
      h.aceptado,
      p.nombre AS prod_nombre,
      p.precio AS precio_original,
      p.imagen_url,
      p.categoria,
      COALESCE(s.nombre, u.nombre_panaderia, u.nombre) AS hija_nombre,
      u.id AS hija_uid,
      s.id AS hija_sucursal_id
    FROM herencia_productos h
    INNER JOIN productos p
      ON p.id = h.producto_id
    INNER JOIN sucursales s
      ON s.id = h.sucursal_id
     AND s.activo = 1
    INNER JOIN usuarios u
      ON u.id = s.vendedor_id
    WHERE h.padre_id = ?
    ORDER BY hija_nombre, p.nombre
  ");

  $herp_stmt->execute([$uid]);
  $herencias_padre = $herp_stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Mi Panel — <?= SITE_NAME ?></title>
  <link rel="stylesheet" href="<?= SITE_URL ?>/css/global.css">
  <link rel="stylesheet" href="<?= SITE_URL ?>/css/vendedor.css">
</head>

<body>

  <div class="sidebar-overlay" id="sidebar-overlay"></div>

  <div class="dash-layout">

    <!-- ══ SIDEBAR ══════════════════════════════════════════════════════════════ -->
    <nav class="sidebar" id="sidebar">
      <div class="sidebar-logo">
        🥖 Panaderia<span>PUMA</span>
        <small>Panel del vendedor</small>
      </div>
      <ul class="sidebar-nav">
        <li><a href="vendedor.php?sec=inicio" class="<?= $seccion === 'inicio'    ? 'on' : '' ?>"><span class="nav-ico">📊</span> Inicio</a></li>
        <li><a href="vendedor.php?sec=productos" class="<?= $seccion === 'productos' ? 'on' : '' ?>">
            <span class="nav-ico">🍞</span>
            <?= $tipo_suc === 'hija' ? 'Productos Heredados' : 'Mis Productos' ?>
          </a></li>
        <?php if ($tipo_suc !== 'hija'): ?>
          <li><a href="vendedor.php?sec=add" class="<?= $seccion === 'add' ? 'on' : '' ?>"><span class="nav-ico">➕</span> Agregar Producto</a></li>
        <?php endif; ?>
        <li>
          <a href="vendedor.php?sec=pedidos" class="<?= $seccion === 'pedidos' ? 'on' : '' ?>">
            <span class="nav-ico">📦</span> Pedidos
            <?php if ($st['pend'] > 0): ?>
              <span style="background:var(--rojo);color:white;border-radius:50%;
                       width:18px;height:18px;font-size:0.7rem;font-weight:700;
                       display:inline-flex;align-items:center;justify-content:center;
                       margin-left:auto"><?= $st['pend'] ?></span>
            <?php endif; ?>
          </a>
        </li>
        <?php if ($tipo_suc === 'padre'): ?>
          <li><a href="vendedor.php?sec=hijas" class="<?= $seccion === 'hijas' ? 'on' : '' ?>"><span class="nav-ico">🏬</span> Mis Sucursales Hija</a></li>
          <li><a href="vendedor.php?sec=metricas" class="<?= $seccion === 'metricas' ? 'on' : '' ?>"><span class="nav-ico">�</span> Métricas</a></li>
        <?php endif; ?>
        <?php if ($tipo_suc): ?>
          <li><a href="vendedor.php?sec=trabajadores" class="<?= $seccion === 'trabajadores' ? 'on' : '' ?>"><span class="nav-ico">👥</span> Trabajadores</a></li>
        <?php endif; ?>
        <li><a href="vendedor.php?sec=perfil" class="<?= $seccion === 'perfil' ? 'on' : '' ?>"><span class="nav-ico">⚙️</span> Mi Perfil</a></li>
        <li>
          <a href="vendedor.php?sec=documentos" class="<?= $seccion === 'documentos' ? 'on' : '' ?>">
            <span class="nav-ico">📂</span> Mis Documentos
            <?php if (in_array($u['estado_verificacion'] ?? '', ['sin_enviar', 'rechazado'])): ?>
              <span style="background:var(--naranja);color:white;border-radius:50%;
                       width:8px;height:8px;margin-left:auto;display:inline-block"></span>
            <?php endif; ?>
          </a>
        </li>
      </ul>
      <div class="sidebar-bottom">
        <ul class="sidebar-nav">
          <li><a href="<?= SITE_URL ?>/catalogo.php" target="_blank"><span class="nav-ico">🏪</span> Ver catálogo</a></li>
          <li><a href="<?= SITE_URL ?>/logout.php"><span class="nav-ico">🚪</span> Salir</a></li>
        </ul>
      </div>
    </nav>

    <!-- ══ MAIN ══════════════════════════════════════════════════════════════════ -->
    <main class="dash-main">

      <div class="dash-topbar" style="margin-bottom:20px">
        <div style="display:flex;align-items:center;gap:10px">
          <button class="btn btn-ghost btn-sm mob-menu-btn" id="mob-menu-btn">☰</button>
          <div>
            <h1>
              <?php
              $titulos = [
                'inicio'       => 'Mi Panel',
                'productos'    => $tipo_suc === 'hija' ? 'Productos Heredados' : 'Mis Productos',
                'add'          => ($edit_prod ? 'Editar Producto' : 'Agregar Producto'),
                'pedidos'      => 'Pedidos recibidos',
                'perfil'       => 'Mi Perfil',
                'documentos'   => '📂 Mis Documentos',
                'trabajadores' => '👥 Mis Trabajadores',
                'hijas'        => '🏬 Mis Sucursales Hija',
                'metricas'     => '📈 Métricas de Red',
              ];
              echo $titulos[$seccion] ?? 'Mi Panel';
              ?>
            </h1>
            <p style="color:var(--gris);font-size:0.9rem;margin-top:2px;display:flex;align-items:center;gap:8px">
              <?= h($u['nombre_panaderia'] ?: $u['nombre']) ?> — <?= date('d/m/Y') ?>
              <?php if ($tipo_suc === 'padre'): ?>
                <span style="background:#E3F2FD;color:#1565C0;font-size:0.72rem;font-weight:700;
                             padding:2px 9px;border-radius:50px">🔵 Sucursal Padre</span>
              <?php elseif ($tipo_suc === 'hija'): ?>
                <span style="background:#F3E5F5;color:#6A1B9A;font-size:0.72rem;font-weight:700;
                             padding:2px 9px;border-radius:50px">🟣 Sucursal Hija
                  <?= $mi_padre ? '· ' . h($mi_padre['nombre_panaderia'] ?: $mi_padre['nombre']) : '' ?>
                </span>
              <?php else: ?>
                <span style="background:#FFF8E1;color:#E65100;font-size:0.72rem;font-weight:700;
                             padding:2px 9px;border-radius:50px">⏳ Sin clasificar</span>
              <?php endif; ?>
            </p>
          </div>
        </div>
        <?php if ($tipo_suc !== 'hija' && in_array($seccion, ['inicio', 'productos'])): ?>
          <a href="vendedor.php?sec=add" class="btn btn-naranja btn-sm">+ Nuevo producto</a>
        <?php endif; ?>
      </div>

      <!-- ── Alertas ── -->
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
      <?php if (($_GET['ok'] ?? '') === 'eliminado'): ?>
        <div style="background:#E8F5E9;border-left:4px solid var(--verde);padding:12px 16px;
                border-radius:var(--radio);margin-bottom:20px;color:#2E7D32;font-weight:600">
          Producto eliminado ✅
        </div>
      <?php endif; ?>

      <?php /* ══════════════════ INICIO ══════════════════ */ if ($seccion === 'inicio'): ?>

        <?php if (!$tipo_suc): ?>
          <div style="background:#FFF8E1;border-left:4px solid var(--naranja);padding:20px 24px;
                      border-radius:var(--radio);margin-bottom:24px">
            <h3 style="margin:0 0 6px;color:#E65100">⏳ Rol pendiente de asignación</h3>
            <p style="margin:0;color:#BF360C;font-size:0.92rem">
              Tu cuenta puede usar el panel aunque todavía esté pendiente de verificación.
              Podés completar tu perfil, subir tus documentos y cargar productos.
              Los productos permanecerán ocultos para el público hasta que tu cuenta sea aprobada.
              Las funciones de sucursales y trabajadores estarán disponibles cuando se asigne el rol correspondiente.
            </p>
            <div style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap">
              <a href="vendedor.php?sec=perfil" class="btn btn-naranja btn-sm">Completar perfil →</a>
              <a href="vendedor.php?sec=documentos" class="btn btn-ghost btn-sm">Mis documentos →</a>
              <?php if ($u['estado_verificacion'] === 'aprobado'): ?>
                <?php if (!$sol_padre || $sol_padre['estado'] === 'rechazada'): ?>
                  <form method="POST" style="margin:0">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <input type="hidden" name="accion" value="solicitar_ser_padre">
                    <button type="submit" class="btn btn-ghost btn-sm" style="border-color:#4CAF50;color:#2E7D32">
                      👑 Solicitar ser Encargado Padre
                    </button>
                  </form>
                  <?php if ($sol_padre && $sol_padre['estado'] === 'rechazada'): ?>
                    <p style="margin:6px 0 0;font-size:0.82rem;color:#C62828">
                      ❌ Solicitud rechazada<?= $sol_padre['motivo_rechazo'] ? ': ' . h($sol_padre['motivo_rechazo']) : '.' ?>
                    </p>
                  <?php endif; ?>
                <?php elseif ($sol_padre['estado'] === 'pendiente'): ?>
                  <span style="padding:6px 14px;border-radius:50px;background:#FFF8E1;color:#E65100;font-size:0.82rem;font-weight:700">
                    ⏳ Solicitud de Padre pendiente
                  </span>
                <?php endif; ?>
              <?php endif; ?>
            </div>
          </div>
        <?php endif; ?>

        <?php if ($u['estado_verificacion'] !== 'aprobado'): ?>
          <div class="onboarding">
            <h2>¡Bienvenido/a a PanaderiaMarket! 🥖</h2>
            <p>Seguí estos pasos para empezar a vender hoy mismo</p>
            <div class="ob-steps">
              <div class="ob-step">
                <div class="ob-step-ico">⚙️</div>
                <div class="ob-step-txt"><strong>1. Completá tu perfil</strong><span>Agregá foto, descripción y contacto</span></div>
              </div>
              <div class="ob-step">
                <div class="ob-step-ico">📸</div>
                <div class="ob-step-txt"><strong>2. Publicá tu primer producto</strong><span>Con foto, precio y descripción</span></div>
              </div>
              <div class="ob-step">
                <div class="ob-step-ico">📲</div>
                <div class="ob-step-txt"><strong>3. Compartí tu tienda</strong><span>Mandá el link por WhatsApp o Instagram</span></div>
              </div>
            </div>
            <div class="ob-actions">
              <a href="vendedor.php?sec=perfil" class="btn btn-naranja btn-sm">Completar perfil →</a>
              <a href="vendedor.php?sec=add" class="btn btn-ghost btn-sm"
                style="border-color:rgba(255,255,255,0.4);color:white">
                Agregar producto →
              </a>
            </div>
          </div>
        <?php endif; ?>

        <div class="stats-grid">
          <div class="stat-card">
            <div class="stat-label">Productos activos</div>
            <div class="stat-value"><?= $st['activos'] ?></div>
            <div class="stat-sub"><?= $st['total_prods'] ?> en total</div>
          </div>
          <div class="stat-card">
            <div class="stat-label">Pedidos pendientes</div>
            <div class="stat-value"><?= $st['pend'] ?></div>
            <div class="stat-sub"><?= $st['total_pedidos'] ?> en total</div>
          </div>
          <div class="stat-card">
            <div class="stat-label">Ingresos entregados</div>
            <div class="stat-value" style="font-size:1.4rem"><?= precio((float)$st['ingresos']) ?></div>
          </div>
          <div class="stat-card">
            <div class="stat-label">Estado de cuenta</div>
            <div style="margin-top:8px">
              <span class="estado-badge estado-<?= h($u['estado_verificacion']) ?>">
                <?= h($u['estado_verificacion']) ?>
              </span>
            </div>
          </div>
        </div>

        <div class="sec-card">
          <div class="sec-card-top">
            <h2>Últimos pedidos</h2>
            <a href="vendedor.php?sec=pedidos" class="btn btn-ghost btn-sm">Ver todos</a>
          </div>
          <?php $ults = array_slice($pedidos, 0, 4); ?>
          <?php if (empty($ults)): ?>
            <p style="color:var(--gris);text-align:center;padding:24px 0">Aún no recibiste pedidos.</p>
          <?php else: ?>
            <?php foreach ($ults as $p): ?>
              <div class="pedido-card">
                <div class="pedido-top">
                  <div>
                    <span class="pedido-id">Pedido #<?= $p['id'] ?></span>
                    <span class="pedido-fecha" style="margin-left:10px">
                      <?= date('d/m/Y H:i', strtotime($p['created_at'])) ?>
                    </span>
                  </div>
                  <span class="estado-badge estado-<?= $p['estado'] ?>">
                    <?= estado_label($p['estado']) ?>
                  </span>
                </div>
                <div class="pedido-total">
                  <span style="color:var(--gris);font-size:0.88rem"><?= h($p['nombre_comprador']) ?></span>
                  <span><?= precio((float)$p['total']) ?></span>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>

      <?php /* ══════════════════ MIS PRODUCTOS ══════════════════ */ elseif ($seccion === 'productos'): ?>

        <?php if ($tipo_suc === 'hija'): ?>
          <!-- VISTA HIJA: productos heredados -->
          <div class="sec-card">
            <div class="sec-card-top">
              <h2>🍞 Productos Heredados</h2>
              <?php if ($mi_padre): ?>
                <span style="font-size:0.82rem;color:var(--gris)">
                  De: <strong><?= h($mi_padre['nombre_panaderia'] ?: $mi_padre['nombre']) ?></strong>
                </span>
              <?php endif; ?>
            </div>

            <?php if (empty($herencias_hija)): ?>
              <div style="text-align:center;padding:48px 0;color:var(--gris)">
                <span style="font-size:3rem;display:block;margin-bottom:12px">⏳</span>
                <p>Tu sucursal Padre todavía no te asignó productos.</p>
              </div>
            <?php else: ?>
              <div style="display:grid;gap:14px">
                <?php foreach ($herencias_hija as $h): ?>
                  <div style="background:var(--crema);border-radius:var(--radio);padding:16px 20px;
                            display:flex;gap:14px;align-items:flex-start;flex-wrap:wrap">
                    <!-- Imagen/emoji -->
                    <div style="width:52px;height:52px;border-radius:var(--radio);
                              background:var(--crema-dark);display:flex;align-items:center;
                              justify-content:center;font-size:1.6rem;flex-shrink:0">
                      <?php if ($h['imagen_url']): ?>
                        <img src="<?= h($h['imagen_url']) ?>"
                          style="width:100%;height:100%;object-fit:cover;border-radius:var(--radio)" alt="">
                      <?php else: ?>
                        <?= cat_emoji($h['categoria']) ?>
                      <?php endif; ?>
                    </div>

                    <!-- Info -->
                    <div style="flex:1;min-width:160px">
                      <p style="font-weight:700;font-size:1rem;margin:0 0 4px"><?= h($h['nombre']) ?></p>
                      <p style="color:var(--gris);font-size:0.82rem;margin:0 0 6px">
                        Precio mínimo: <strong><?= precio((float)$h['precio_minimo']) ?></strong>
                        <?= $h['aceptado'] ? ' · Tu precio: <strong>' . precio((float)$h['precio_sucursal']) . '</strong>' : '' ?>
                      </p>

                      <?php if (!$h['aceptado']): ?>
                        <!-- Formulario para aceptar -->
                        <form method="POST" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:8px">
                          <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>"><input type="hidden" name="accion" value="aceptar_herencia">
                          <input type="hidden" name="herencia_id" value="<?= $h['id'] ?>">
                          <input type="number" name="precio_sucursal"
                            min="<?= $h['precio_minimo'] ?>" step="0.01" required
                            placeholder="Tu precio (mín. <?= precio((float)$h['precio_minimo']) ?>)"
                            style="width:200px;padding:6px 10px;border-radius:var(--radio);
                                    border:1px solid #ccc;font-size:0.88rem">
                          <button type="submit" class="btn btn-naranja btn-sm">✅ Aceptar y activar</button>
                        </form>
                      <?php else: ?>
                        <!-- Ya aceptado: opción de revocar -->
                        <form method="POST" onsubmit="return confirm('¿Dejar de vender este producto?')"
                          style="margin-top:8px">
                          <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>"><input type="hidden" name="accion" value="revocar_herencia">
                          <input type="hidden" name="herencia_id" value="<?= $h['id'] ?>">
                          <button type="submit" class="btn btn-ghost btn-sm"
                            style="color:var(--rojo)">✗ Dejar de vender</button>
                        </form>
                      <?php endif; ?>
                    </div>

                    <!-- Badge estado -->
                    <span style="padding:4px 12px;border-radius:50px;font-size:0.78rem;font-weight:700;align-self:flex-start;
                    background:<?= $h['aceptado'] ? '#E8F5E9' : '#FFF8E1' ?>;
                    color:<?= $h['aceptado'] ? '#2E7D32' : '#E65100' ?>">
                      <?= $h['aceptado'] ? '✅ Activo' : '⏳ Pendiente' ?>
                    </span>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>

        <?php else: ?>
          <!-- VISTA PADRE/sin clasificar: productos propios -->
          <div class="sec-card">
            <div class="sec-card-top">
              <h2>Mis Productos</h2>
              <a href="vendedor.php?sec=add" class="btn btn-naranja btn-sm">+ Nuevo</a>
            </div>
            <?php if (empty($productos)): ?>
              <div style="text-align:center;padding:48px 0;color:var(--gris)">
                <span style="font-size:3rem;display:block;margin-bottom:12px">🍞</span>
                <p>Todavía no cargaste ningún producto.</p>
                <a href="vendedor.php?sec=add" class="btn btn-naranja" style="margin-top:14px">
                  Agregar mi primer producto
                </a>
              </div>
            <?php else: ?>
              <div class="tabla-wrap">
                <table class="tabla">
                  <thead>
                    <tr>
                      <th>Producto</th>
                      <th>Categoría</th>
                      <th>Precio</th>
                      <th>Stock</th>
                      <th>Estado</th>
                      <th>Acciones</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($productos as $p): ?>
                      <tr>
                        <td>
                          <div style="display:flex;align-items:center;gap:10px">
                            <?php if ($p['imagen_url']): ?>
                              <img src="<?= h($p['imagen_url']) ?>"
                                style="width:40px;height:40px;border-radius:var(--radio);
                                  object-fit:cover;border:2px solid var(--crema-dark)" alt="">
                            <?php else: ?>
                              <div style="width:40px;height:40px;background:var(--crema-dark);
                                  border-radius:var(--radio);display:flex;align-items:center;
                                  justify-content:center;font-size:1.3rem">
                                <?= cat_emoji($p['categoria']) ?>
                              </div>
                            <?php endif; ?>
                            <span class="td-nombre"><?= h($p['nombre']) ?></span>
                          </div>
                        </td>
                        <td><?= cat_label($p['categoria']) ?></td>
                        <td class="td-precio">
                          <?= precio((float)$p['precio']) ?>
                          <span style="font-size:0.72rem;color:var(--gris)">
                            <?= ($p['unidad_venta'] ?? 'unidad') === 'kilo' ? '/kg' : '/u' ?>
                          </span>
                        </td>
                        <td><?= $p['cantidad_disponible'] ?? '—' ?></td>
                        <td>
                          <form method="POST" style="display:inline">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>"><input type="hidden" name="accion" value="toggle">
                            <input type="hidden" name="pid" value="<?= $p['id'] ?>">
                            <button type="submit" class="toggle-estado <?= $p['activo'] ? 'activo' : 'inactivo' ?>">
                              <?= $p['activo'] ? '✓ Activo' : '✗ Inactivo' ?>
                            </button>
                          </form>
                        </td>
                        <td>
                          <div style="display:flex;gap:6px">
                            <a href="vendedor.php?edit=<?= $p['id'] ?>" class="btn btn-ghost btn-sm">✏️</a>
                            <form method="POST" onsubmit="return confirm('¿Eliminar este producto?')">
                              <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>"><input type="hidden" name="accion" value="delete">
                              <input type="hidden" name="pid" value="<?= $p['id'] ?>">
                              <button type="submit" class="btn btn-danger btn-sm">🗑</button>
                            </form>
                          </div>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </div>

  </div>
<?php endif; // fin if hija / else padre 
?>

<?php /* ══════════════════ AGREGAR / EDITAR ══════════════════ */ elseif ($seccion === 'add'): ?>

  <?php if ($tipo_suc === 'hija'): ?>
    <div style="background:#FFEBEE;border-left:4px solid var(--rojo);padding:20px 24px;border-radius:var(--radio)">
      <h3 style="margin:0 0 6px;color:#C62828">🚫 Acción no permitida</h3>
      <p style="margin:0;color:#B71C1C;font-size:0.92rem">
        Las Sucursales Hija no pueden crear productos propios. Los productos son asignados por la Sucursal Padre.
      </p>
      <a href="vendedor.php?sec=productos" class="btn btn-ghost btn-sm" style="margin-top:14px;display:inline-block">← Volver</a>
    </div>
  <?php else: ?>

    <div class="sec-card" style="max-width:680px">
      <div class="sec-card-top">
        <h2><?= $edit_prod ? '✏️ Editar Producto' : '➕ Agregar Producto' ?></h2>
        <?php if ($edit_prod): ?>
          <a href="vendedor.php?sec=productos" class="btn btn-ghost btn-sm">Cancelar</a>
        <?php endif; ?>
      </div>

      <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="accion" value="<?= $edit_prod ? 'edit_producto' : 'add_producto' ?>">
        <?php if ($edit_prod): ?>
          <input type="hidden" name="pid" value="<?= $edit_prod['id'] ?>">
        <?php endif; ?>

        <div class="form-row">
          <div class="field">
            <label>Nombre *</label>
            <input type="text" name="nombre"
              value="<?= h($edit_prod['nombre'] ?? '') ?>"
              placeholder="Ej: Pan Francés" required>
          </div>
          <div class="field">
            <label>Categoría *</label>
            <select name="cat">
              <?php foreach (['pan' => '🍞 Pan', 'facturas' => '🥐 Facturas', 'galletas' => '🍪 Galletas', 'cakes' => '🎂 Cakes', 'otro' => '✨ Otro'] as $k => $v): ?>
                <option value="<?= $k ?>"
                  <?= ($edit_prod['categoria'] ?? 'pan') === $k ? 'selected' : '' ?>>
                  <?= $v ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="form-row">
          <div class="field">
            <label>Se vende por *</label>
            <select name="unidad" id="sel-unidad">
              <option value="unidad" <?= ($edit_prod['unidad_venta'] ?? 'unidad') === 'unidad' ? 'selected' : '' ?>>
                Unidad / Media doc. / Docena
              </option>
              <option value="kilo" <?= ($edit_prod['unidad_venta'] ?? '') === 'kilo' ? 'selected' : '' ?>>
                Kilo (precio por kg)
              </option>
            </select>
          </div>
          <div class="field">
            <label>Cantidad disponible</label>
            <input type="number" name="stock" min="0"
              value="<?= $edit_prod['cantidad_disponible'] ?? 0 ?>"
              placeholder="0">
          </div>
        </div>

        <div class="field">
          <label>Descripción</label>
          <textarea name="desc" rows="2"
            placeholder="Contale al cliente qué hace especial este producto..."><?= h($edit_prod['descripcion'] ?? '') ?></textarea>
        </div>

        <div class="form-row">
          <div class="field">
            <label>
              Precio *
              <span id="lbl-precio-hint" style="font-weight:400;color:var(--gris)">(por unidad)</span>
            </label>
            <input type="number" name="precio" min="0" step="50"
              value="<?= $edit_prod['precio'] ?? '' ?>"
              placeholder="0" required>
            <div id="hint-kilo" class="hint-campo" style="display:none">
              💡 Ej: ponés <strong>$2.500</strong> → 1kg = $2.500
            </div>
          </div>
        </div>

        <div class="form-row" id="campos-docena">
          <div class="field">
            <label>Precio media docena</label>
            <input type="number" name="media_doc" min="0" step="50"
              value="<?= $edit_prod['precio_media_docena'] ?? '' ?>"
              placeholder="Opcional">
          </div>
          <div class="field">
            <label>Precio por docena</label>
            <input type="number" name="docena" min="0" step="50"
              value="<?= $edit_prod['precio_docena'] ?? '' ?>"
              placeholder="Opcional">
          </div>
        </div>

        <div class="field">
          <label>Dato extra 💡</label>
          <input type="text" name="extra"
            value="<?= h($edit_prod['dato_extra'] ?? '') ?>"
            placeholder="Sin TACC · Vegano · Horneado a leña · Por encargo...">
        </div>

        <!-- Imagen principal -->
        <div class="field">
          <label>Imagen principal</label>
          <div style="margin-bottom:10px">
            <label for="p-img-file" class="btn btn-ghost btn-sm"
              style="cursor:pointer;display:inline-flex">
              📁 Subir desde galería
            </label>
            <input type="file" id="p-img-file" name="imagen"
              accept="image/*" style="display:none">
            <span style="font-size:0.78rem;color:var(--gris);margin-left:10px">
              JPG, PNG — máx 5MB
            </span>
          </div>
          <?php if (!empty($edit_prod['imagen_url'])): ?>
            <img id="img-preview" class="img-preview"
              src="<?= h($edit_prod['imagen_url']) ?>"
              style="display:block" alt="Preview">
            <div style="font-size:0.75rem;color:var(--gris);margin-top:4px">
              Subí una nueva para reemplazarla
            </div>
          <?php else: ?>
            <img id="img-preview" class="img-preview" alt="Preview">
          <?php endif; ?>
        </div>

        <!-- Fotos extra -->
        <div class="field">
          <label>Fotos adicionales</label>
          <label for="p-fotos-extra" class="btn btn-ghost btn-sm"
            style="cursor:pointer;display:inline-flex">
            📁 Agregar más fotos
          </label>
          <input type="file" id="p-fotos-extra" accept="image/*"
            multiple style="display:none">
          <span style="font-size:0.78rem;color:var(--gris);margin-left:10px">
            Hasta 4 fotos
          </span>
          <div id="fotos-extra-preview"
            style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px"></div>
        </div>

        <button type="submit" class="btn btn-naranja">
          <?= $edit_prod ? '💾 Guardar cambios' : '💾 Guardar producto' ?>
        </button>
      </form>
    </div>

  <?php endif; // cierra else de sec=add (bloqueo Hija) 
  ?>

<?php /* ══════════════════ PEDIDOS ══════════════════ */ elseif ($seccion === 'pedidos'): ?>

  <div class="sec-card">
    <div class="sec-card-top">
      <h2>📦 Pedidos recibidos</h2>
    </div>

    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px" id="filtros-estado">
      <?php foreach (['todos' => 'Todos', 'pendiente' => 'Pendiente', 'confirmado' => 'Confirmado', 'listo' => 'Listo', 'entregado' => 'Entregado'] as $k => $v): ?>
        <button class="filtro <?= $k === 'todos' ? 'on' : '' ?>" data-estado="<?= $k ?>">
          <?= $v ?>
        </button>
      <?php endforeach; ?>
    </div>

    <div style="max-width:320px;margin-bottom:16px">
      <input type="search" id="buscar-pedidos"
        placeholder="Buscar por nombre del comprador...">
    </div>

    <div id="lista-pedidos">
      <?php if (empty($pedidos)): ?>
        <div style="text-align:center;padding:40px 0;color:var(--gris)">
          <span style="font-size:3rem;display:block;margin-bottom:12px">📦</span>
          <p>Aún no recibiste pedidos.</p>
        </div>
      <?php else: ?>
        <?php foreach ($pedidos as $p): ?>
          <div class="pedido-card"
            data-estado="<?= $p['estado'] ?>"
            data-nombre="<?= h(strtolower($p['nombre_comprador'])) ?>">
            <div class="pedido-top">
              <div>
                <span class="pedido-id">Pedido #<?= $p['id'] ?></span>
                <span class="pedido-fecha" style="margin-left:8px">
                  <?= date('d/m/Y H:i', strtotime($p['created_at'])) ?>
                </span>
                <div style="font-size:0.83rem;color:var(--gris);margin-top:3px">
                  👤 <?= h($p['nombre_comprador']) ?>
                </div>
              </div>
              <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                <span class="estado-badge estado-<?= $p['estado'] ?>">
                  <?= estado_label($p['estado']) ?>
                </span>
                <form method="POST">
                  <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>"><input type="hidden" name="accion" value="estado_pedido">
                  <input type="hidden" name="pedido_id" value="<?= $p['id'] ?>">
                  <select name="estado" onchange="this.form.submit()"
                    style="width:auto;margin:0;font-size:0.82rem;padding:5px 10px">
                    <?php foreach (['pendiente' => 'Pendiente', 'confirmado' => 'Confirmado', 'listo' => 'Listo', 'entregado' => 'Entregado'] as $k => $v): ?>
                      <option value="<?= $k ?>" <?= $p['estado'] === $k ? 'selected' : '' ?>><?= $v ?></option>
                    <?php endforeach; ?>
                  </select>
                </form>
              </div>
            </div>

            <?php foreach ($pedidos_items[$p['id']] ?? [] as $it): ?>
              <div class="pedido-item">
                <span><?= h($it['nombre_producto']) ?> × <?= $it['cantidad'] ?></span>
                <span style="font-weight:700">
                  <?= precio($it['precio_unitario'] * $it['cantidad']) ?>
                </span>
              </div>
            <?php endforeach; ?>

            <div class="pedido-total">
              <span>Total</span>
              <span><?= precio((float)$p['total']) ?></span>
            </div>

            <?php if ($p['notas']): ?>
              <div style="margin-top:10px;font-size:0.82rem;background:white;
                          padding:8px 12px;border-radius:6px">
                📝 <?= h($p['notas']) ?>
              </div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <div id="empty-pedidos"
      style="display:none;text-align:center;padding:28px 0;color:var(--gris)">
      No hay pedidos que coincidan.
    </div>
  </div>

<?php /* ══════════════════ TRABAJADORES ══════════════════ */ elseif ($seccion === 'trabajadores'): ?>

  <div class="sec-card">
    <div class="sec-card-top">
      <h2>👥 Mis Trabajadores</h2>
      <button class="btn btn-naranja btn-sm" onclick="document.getElementById('modal-crear-trab').style.display='flex'">
        + Agregar trabajador
      </button>
    </div>

    <!-- Mi identificador único -->
    <div style="background:var(--crema);border-radius:var(--radio);padding:14px 18px;margin-bottom:20px;display:flex;align-items:center;gap:14px">
      <span style="font-size:1.6rem">🪪</span>
      <div style="flex:1">
        <p style="font-weight:700;margin:0 0 2px">Tu identificador único como Admin</p>
        <p style="color:var(--gris);font-size:0.83rem;margin:0">
          <?php if (!empty($u['identificador'])): ?>
            <strong style="color:var(--marron);font-size:1rem">@<?= h($u['identificador']) ?></strong>
          <?php else: ?>
            <em>Aún no configurado</em>
          <?php endif; ?>
        </p>
      </div>
      <button class="btn btn-ghost btn-sm" onclick="document.getElementById('modal-identificador').style.display='flex'">
        <?= !empty($u['identificador']) ? '✏️ Cambiar' : '+ Configurar' ?>
      </button>
    </div>

    <?php if (empty($trabajadores)): ?>
      <p style="color:var(--gris);text-align:center;padding:32px 0">No hay trabajadores aún. Agregá el primero.</p>
    <?php else: ?>
      <div style="display:grid;gap:12px">
        <?php foreach ($trabajadores as $t): ?>
          <?php $sol = $solicitudes_map[$t['id']] ?? null; ?>
          <div style="display:flex;align-items:center;gap:14px;padding:14px;background:var(--crema);border-radius:var(--radio)">
            <?php if (!empty($t['avatar_url'])): ?>
              <img src="<?= h($t['avatar_url']) ?>" style="width:44px;height:44px;border-radius:50%;object-fit:cover" alt="">
            <?php else: ?>
              <div style="width:44px;height:44px;border-radius:50%;background:var(--naranja);color:white;
                    display:flex;align-items:center;justify-content:center;font-weight:900;font-size:1rem">
                <?= strtoupper(mb_substr($t['nombre'], 0, 1)) ?>
              </div>
            <?php endif; ?>

            <div style="flex:1">
              <p style="font-weight:700;margin:0 0 2px"><?= h($t['nombre']) ?></p>
              <p style="color:var(--gris);font-size:0.82rem;margin:0">
                <?= !empty($t['identificador']) ? '@' . h($t['identificador']) . ' · ' : '' ?>
                <?= h($t['email']) ?>
                <?= !empty($t['documento_id']) ? ' · DNI: ' . h($t['documento_id']) : '' ?>
              </p>
              <?php if ($sol): ?>
                <?php
                $badge_color = match ($sol['estado']) {
                  'pendiente'  => '#FFF8E1; color:#F57F17',
                  'aprobado'   => '#E8F5E9; color:#2E7D32',
                  'rechazado'  => '#FFEBEE; color:#C62828',
                };
                $badge_txt = match ($sol['estado']) {
                  'pendiente'  => '⏳ Solicitud pendiente',
                  'aprobado'   => '✅ Admin aprobado',
                  'rechazado'  => '❌ Solicitud rechazada',
                };
                ?>
                <span style="display:inline-block;margin-top:4px;padding:2px 10px;border-radius:50px;
                             font-size:0.75rem;font-weight:700;background:<?= $badge_color ?>">
                  <?= $badge_txt ?>
                </span>
              <?php endif; ?>
            </div>

            <div style="display:flex;gap:8px;align-items:center">
              <?php if (!$sol || $sol['estado'] === 'rechazado'): ?>
                <form method="POST">
                  <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>"><input type="hidden" name="accion" value="solicitar_admin">
                  <input type="hidden" name="trab_id" value="<?= $t['id'] ?>">
                  <button class="btn btn-sm" style="background:#E8F5E9;color:#2E7D32;border:none;font-weight:700"
                    title="Solicitar que sea admin de la panadería">
                    👑 Pedir admin
                  </button>
                </form>
              <?php endif; ?>

              <form method="POST" onsubmit="return confirm('¿Eliminar este trabajador?')">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>"><input type="hidden" name="accion" value="eliminar_trabajador">
                <input type="hidden" name="trab_id" value="<?= $t['id'] ?>">
                <button class="btn btn-sm" style="background:#FFEBEE;color:#C62828;border:none;font-weight:700">🗑️</button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- Modal: Configurar identificador -->
  <div id="modal-identificador" class="modal-overlay" style="display:none" onclick="if(event.target===this)this.style.display='none'">
    <div class="modal-box" onclick="event.stopPropagation()">
      <h3>🪪 Tu Identificador Único</h3>
      <p style="color:var(--gris);font-size:0.85rem;margin-bottom:16px">Solo letras, números y guiones bajos.</p>
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>"><input type="hidden" name="accion" value="set_identificador">
        <div style="margin-bottom:14px">
          <label style="display:block;font-weight:700;margin-bottom:4px">Identificador</label>
          <div style="display:flex;align-items:center;gap:6px">
            <span style="font-size:1.1rem;color:var(--gris)">@</span>
            <input type="text" name="identificador" pattern="[a-zA-Z0-9_]+" required
              value="<?= h($u['identificador'] ?? '') ?>"
              placeholder="mi_panaderia_2024" style="flex:1">
          </div>
        </div>
        <div style="display:flex;gap:10px">
          <button class="btn" type="submit">💾 Guardar</button>
          <button class="btn btn-ghost" type="button" onclick="document.getElementById('modal-identificador').style.display='none'">Cancelar</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Modal: Crear trabajador -->
  <div id="modal-crear-trab" class="modal-overlay" style="display:none" onclick="if(event.target===this)this.style.display='none'">
    <div class="modal-box" onclick="event.stopPropagation()" style="max-width:520px">
      <h3>👤 Agregar Trabajador</h3>
      <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>"><input type="hidden" name="accion" value="crear_trabajador">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px">
          <div>
            <label style="display:block;font-weight:700;font-size:0.85rem;margin-bottom:4px">Nombre completo *</label>
            <input type="text" name="nombre" required placeholder="Juan Pérez">
          </div>
          <div>
            <label style="display:block;font-weight:700;font-size:0.85rem;margin-bottom:4px">Identificador *</label>
            <div style="display:flex;align-items:center;gap:4px">
              <span style="color:var(--gris)">@</span>
              <input type="text" name="identificador" pattern="[a-zA-Z0-9_]+" required placeholder="usuario123" style="flex:1">
            </div>
          </div>
          <div>
            <label style="display:block;font-weight:700;font-size:0.85rem;margin-bottom:4px">DNI / ID *</label>
            <input type="text" name="documento_id" required placeholder="12345678">
          </div>
          <div>
            <label style="display:block;font-weight:700;font-size:0.85rem;margin-bottom:4px">Gmail *</label>
            <input type="email" name="email" required placeholder="trabajador@gmail.com">
          </div>
        </div>
        <div style="margin-bottom:12px">
          <label style="display:block;font-weight:700;font-size:0.85rem;margin-bottom:4px">Contraseña *</label>
          <input type="password" name="password" required minlength="6" placeholder="Mínimo 6 caracteres" style="width:100%;box-sizing:border-box">
        </div>
        <div style="margin-bottom:16px">
          <label style="display:block;font-weight:700;font-size:0.85rem;margin-bottom:4px">Foto de perfil (opcional)</label>
          <input type="file" name="avatar" accept="image/*">
        </div>
        <div style="display:flex;gap:10px">
          <button class="btn btn-naranja" type="submit">✅ Crear trabajador</button>
          <button class="btn btn-ghost" type="button" onclick="document.getElementById('modal-crear-trab').style.display='none'">Cancelar</button>
        </div>
        <?php if ($msg_err && isset($_POST['accion']) && $_POST['accion'] === 'crear_trabajador'): ?>
          <div style="background:#FFEBEE;border-left:4px solid #e53935;padding:10px 14px;
              border-radius:8px;margin-bottom:12px;color:#c62828;font-size:0.85rem;font-weight:600">
            ⚠️ <?= h($msg_err) ?>
          </div>
        <?php endif; ?>
      </form>
    </div>
  </div>

<?php /* ══════════════════ MIS HIJAS ══════════════════ */ elseif ($seccion === 'hijas'): ?>

  <?php if ($tipo_suc !== 'padre'): ?>

    <div style="background:#FFEBEE;border-left:4px solid var(--rojo);
                padding:20px 24px;border-radius:var(--radio)">
      <p style="margin:0;color:#C62828;font-weight:700">
        Solo el Encargado Padre puede administrar sucursales Hija.
      </p>
    </div>

  <?php else: ?>

    <?php if ($msg_ok): ?>
      <div style="background:#E8F5E9;border-left:4px solid var(--verde);
                  padding:12px 16px;border-radius:var(--radio);
                  margin-bottom:20px;color:#2E7D32;font-weight:600">
        <?= h($msg_ok) ?>
      </div>
    <?php endif; ?>

    <?php if ($msg_err): ?>
      <div style="background:#FFEBEE;border-left:4px solid var(--rojo);
                  padding:12px 16px;border-radius:var(--radio);
                  margin-bottom:20px;color:#C62828;font-weight:600">
        <?= h($msg_err) ?>
      </div>
    <?php endif; ?>

    <?php if ($ultima_invitacion): ?>
      <div style="background:#FFF8E1;border-left:4px solid #F9A825;
                  padding:16px 18px;border-radius:var(--radio);
                  margin-bottom:20px">

        <p style="margin:0 0 8px;color:#7A4F00;font-weight:700">
          Invitación creada correctamente
        </p>

        <p style="margin:0 0 10px;color:#6D4C41;font-size:0.88rem">
          Copiá este enlace y enviáselo al futuro Encargado Hijo.
          El enlace será válido durante 7 días.
        </p>

        <input
          type="text"
          value="<?= h($ultima_invitacion['link']) ?>"
          readonly
          onclick="this.select()"
          style="width:100%;box-sizing:border-box;padding:10px;
                 border:1px solid #D6B656;border-radius:8px;
                 background:#FFFDF2;font-size:0.82rem">

        <p style="margin:8px 0 0;color:#8D6E63;font-size:0.76rem">
          Seleccioná el enlace, copialo y guardalo. Por seguridad, el token
          real no se almacena visible en la base de datos.
        </p>
      </div>
    <?php endif; ?>

    <!-- Crear nueva sucursal Hija -->
    <div class="sec-card">
      <div class="sec-card-top">
        <h2>Crear sucursal Hija</h2>
      </div>

      <p style="color:var(--gris);font-size:0.88rem;margin:0 0 18px">
        La sucursal quedará pendiente hasta que el futuro Encargado Hijo
        acepte la invitación.
      </p>

      <form method="POST" style="display:grid;gap:14px;max-width:620px">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>"><input type="hidden" name="accion" value="crear_invitacion_sucursal">

        <div class="field">
          <label for="nombre_sucursal">Nombre de la sucursal</label>
          <input
            type="text"
            id="nombre_sucursal"
            name="nombre_sucursal"
            maxlength="255"
            placeholder="Ej: Panadería Puma Centro"
            required>
        </div>

        <div class="field">
          <label for="direccion">Dirección</label>
          <input
            type="text"
            id="direccion"
            name="direccion"
            maxlength="500"
            placeholder="Ej: Av. Principal 123">
        </div>

        <div class="field">
          <label for="telefono">Teléfono</label>
          <input
            type="text"
            id="telefono"
            name="telefono"
            maxlength="50"
            placeholder="Ej: 383 400-0000">
        </div>

        <div class="field">
          <label for="nombre_invitado">Nombre del futuro Encargado Hijo</label>
          <input
            type="text"
            id="nombre_invitado"
            name="nombre_invitado"
            maxlength="120"
            placeholder="Nombre completo"
            required>
        </div>

        <div class="field">
          <label for="email_invitado">Email del futuro Encargado Hijo</label>
          <input
            type="email"
            id="email_invitado"
            name="email_invitado"
            maxlength="180"
            placeholder="encargado@ejemplo.com"
            required>
        </div>

        <button type="submit" class="btn btn-naranja">
          Crear sucursal e invitación
        </button>
      </form>
    </div>

    <!-- Invitaciones pendientes -->
    <div class="sec-card" style="margin-top:20px">
      <div class="sec-card-top">
        <h2>Invitaciones pendientes</h2>
      </div>

      <?php if (empty($invitaciones_pendientes)): ?>

        <p style="color:var(--gris);text-align:center;padding:24px 0;margin:0">
          No hay invitaciones pendientes.
        </p>

      <?php else: ?>

        <div style="display:grid;gap:12px">
          <?php foreach ($invitaciones_pendientes as $invitacion): ?>
            <div style="padding:16px 20px;background:var(--crema);
                        border-radius:var(--radio);
                        display:flex;align-items:center;
                        gap:14px;flex-wrap:wrap">

              <div style="flex:1;min-width:220px">
                <p style="font-weight:700;margin:0 0 4px">
                  <?= h($invitacion['sucursal_nombre']) ?>
                </p>

                <p style="color:var(--gris);font-size:0.82rem;margin:0">
                  <?= h($invitacion['nombre_invitado'] ?: 'Sin nombre') ?>
                  · <?= h($invitacion['email_invitado']) ?>
                </p>

                <p style="color:var(--gris);font-size:0.76rem;margin:5px 0 0">
                  Vence:
                  <?= date('d/m/Y H:i', strtotime($invitacion['expires_at'])) ?>
                </p>
              </div>

              <span style="background:#FFF8E1;color:#E65100;
                           padding:4px 12px;border-radius:50px;
                           font-size:0.78rem;font-weight:700">
                Pendiente
              </span>
            </div>
          <?php endforeach; ?>
        </div>

      <?php endif; ?>
    </div>

    <!-- Sucursales Hija activas -->
    <div class="sec-card" style="margin-top:20px">
      <div class="sec-card-top">
        <h2>Sucursales Hija activas (<?= count($mis_hijas) ?>)</h2>
      </div>

      <?php if (empty($mis_hijas)): ?>

        <p style="color:var(--gris);text-align:center;padding:24px 0;margin:0">
          Todavía no tenés sucursales Hija activas.
        </p>

      <?php else: ?>

        <div style="display:grid;gap:12px">
          <?php foreach ($mis_hijas as $h): ?>
            <div style="padding:16px 20px;background:var(--crema);
                        border-radius:var(--radio);
                        display:flex;align-items:center;
                        gap:14px;flex-wrap:wrap">

              <?= avatar_html($h, '42px', '0.9rem') ?>

              <div style="flex:1;min-width:160px">
                <p style="font-weight:700;margin:0 0 2px">
                  <?= h($h['nombre_panaderia'] ?: $h['nombre']) ?>
                </p>

                <p style="color:var(--gris);font-size:0.82rem;margin:0">
                  <?= h($h['email']) ?>
                </p>

                <p style="color:var(--gris);font-size:0.78rem;margin:5px 0 0">
                  Sucursal: <?= h($h['sucursal_nombre']) ?>
                </p>
              </div>

              <span style="padding:4px 12px;border-radius:50px;
                           font-size:0.78rem;font-weight:700;
                           background:#E8F5E9;color:#2E7D32">
                Activa
              </span>
            </div>
          <?php endforeach; ?>
        </div>

      <?php endif; ?>
    </div>

    <?php if (!empty($mis_hijas)): ?>

      <!-- Asignar productos a Hija -->
      <div class="sec-card" style="margin-top:20px">
        <div class="sec-card-top">
          <h2>Asignar producto a una Hija</h2>
        </div>

        <form method="POST" style="display:grid;gap:14px;max-width:500px">
          <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>"><input type="hidden" name="accion" value="asignar_herencia">

          <div class="field">
            <label>Sucursal Hija</label>
            <select name="sucursal_id" required>
              <option value="">— Seleccioná una hija —</option>

              <?php foreach ($mis_hijas as $h): ?>
                <option value="<?= $h['sucursal_id'] ?>">
                  <?= h($h['sucursal_nombre'] ?: ($h['nombre_panaderia'] ?: $h['nombre'])) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="field">
            <label>Producto</label>
            <select name="producto_id" required>
              <option value="">— Seleccioná un producto —</option>

              <?php foreach ($productos as $p): ?>
                <?php if (!$p['activo']) continue; ?>

                <option value="<?= $p['id'] ?>" data-precio="<?= $p['precio'] ?>">
                  <?= h($p['nombre']) ?> — <?= precio((float)$p['precio']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="field">
            <label>Precio mínimo que puede cobrar la Hija</label>
            <input
              type="number"
              name="precio_minimo"
              min="1"
              step="0.01"
              required
              placeholder="Ej: 500">

            <small style="color:var(--gris);font-size:0.78rem">
              Debe ser menor o igual al precio del producto.
            </small>
          </div>

          <button type="submit" class="btn btn-naranja">
            Asignar producto
          </button>
        </form>
      </div>

      <!-- Asignar todos los productos activos -->
<div class="sec-card" style="margin-top:20px">
  <div class="sec-card-top">
    <h2>Asignar todos los productos activos</h2>
  </div>

  <p style="color:var(--gris);font-size:0.88rem;margin:0 0 18px">
    Envía todos tus productos activos a una sucursal Hija.
    Cada producto quedará pendiente de aceptación y su precio mínimo
    será igual al precio actual del Padre.
  </p>

  <p style="color:var(--gris);font-size:0.82rem;margin:0 0 14px">
    Productos activos disponibles:
    <strong><?= (int)$st['activos'] ?></strong>
  </p>

  <form
    method="POST"
    style="display:grid;gap:14px;max-width:500px"
    onsubmit="return confirm(
      '¿Asignar todos los productos activos a esta sucursal Hija?'
    )">

    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
    <input
      type="hidden"
      name="accion"
      value="asignar_todos_herencia">

    <div class="field">
      <label>Sucursal Hija</label>

      <select name="sucursal_id" required>
        <option value="">
          — Seleccioná una hija —
        </option>

        <?php foreach ($mis_hijas as $h): ?>
          <option value="<?= $h['sucursal_id'] ?>">
            <?= h(
              $h['sucursal_nombre'] ?:
              ($h['nombre_panaderia'] ?: $h['nombre'])
            ) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <button
      type="submit"
      class="btn btn-naranja">

      Asignar todos los productos
    </button>
  </form>
</div>

      <!-- Asignaciones realizadas -->
      <?php if (!empty($herencias_padre)): ?>
        <div class="sec-card" style="margin-top:20px">
          <div class="sec-card-top">
            <h2>Asignaciones realizadas</h2>
          </div>

          <div style="display:grid;gap:10px">
            <?php foreach ($herencias_padre as $hp): ?>
              <div style="padding:14px 18px;background:var(--crema);
                          border-radius:var(--radio);
                          display:flex;align-items:center;
                          gap:14px;flex-wrap:wrap">

                <div style="flex:1;min-width:180px">
                  <p style="font-weight:700;margin:0 0 2px">
                    <?= h($hp['prod_nombre']) ?>
                  </p>

                  <p style="color:var(--gris);font-size:0.82rem;margin:0">
                    → <?= h($hp['hija_nombre']) ?>
                    · Mín: <?= precio((float)$hp['precio_minimo']) ?>
                  </p>
                </div>

                <?php if ($hp['aceptado']): ?>
                  <span style="background:#E8F5E9;color:#2E7D32;
                               padding:4px 12px;border-radius:50px;
                               font-size:0.78rem;font-weight:700">
                    Aceptado — <?= precio((float)$hp['precio_sucursal']) ?>
                  </span>
                <?php else: ?>
                  <span style="background:#FFF8E1;color:#E65100;
                               padding:4px 12px;border-radius:50px;
                               font-size:0.78rem;font-weight:700">
                    Pendiente
                  </span>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>

    <?php endif; ?>

  <?php endif; ?>

<?php /* ══════════════════ MÉTRICAS ══════════════════ */ elseif ($seccion === 'metricas'): ?>

  <?php if ($tipo_suc !== 'padre'): ?>
    <div style="background:#FFEBEE;border-left:4px solid var(--rojo);padding:20px 24px;border-radius:var(--radio)">
      <p style="margin:0;color:#C62828;font-weight:700">🚫 Solo el Encargado Padre puede ver esta sección.</p>
    </div>

  <?php elseif (empty($mis_hijas)): ?>
    <div style="text-align:center;padding:56px 0;color:var(--gris)">
      <span style="font-size:3rem;display:block;margin-bottom:12px">📊</span>
      <p>Todavía no tenés sucursales Hija vinculadas.</p>
    </div>

  <?php else: ?>

    <?php
          $tot_asignados = array_sum(array_column($metricas_hijas, 'total_asignados'));
          $tot_aceptados = array_sum(array_column($metricas_hijas, 'total_aceptados'));
          $tot_pedidos   = array_sum(array_column($metricas_hijas, 'total_pedidos'));
          $tot_ingresos  = array_sum(array_column($metricas_hijas, 'ingresos'));
    ?>

    <!-- Tarjetas resumen -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;margin-bottom:24px">
      <div style="background:var(--crema);border-radius:var(--radio);padding:18px 20px;text-align:center">
        <p style="font-size:2rem;font-weight:800;margin:0;color:var(--naranja)"><?= count($mis_hijas) ?></p>
        <p style="margin:4px 0 0;font-size:0.82rem;color:var(--gris)">Sucursales Hija</p>
      </div>
      <div style="background:var(--crema);border-radius:var(--radio);padding:18px 20px;text-align:center">
        <p style="font-size:2rem;font-weight:800;margin:0;color:var(--naranja)"><?= $tot_asignados ?></p>
        <p style="margin:4px 0 0;font-size:0.82rem;color:var(--gris)">Productos asignados</p>
      </div>
      <div style="background:var(--crema);border-radius:var(--radio);padding:18px 20px;text-align:center">
        <p style="font-size:2rem;font-weight:800;margin:0;color:#2E7D32"><?= $tot_aceptados ?></p>
        <p style="margin:4px 0 0;font-size:0.82rem;color:var(--gris)">Aceptados por Hijas</p>
      </div>
      <div style="background:var(--crema);border-radius:var(--radio);padding:18px 20px;text-align:center">
        <p style="font-size:2rem;font-weight:800;margin:0;color:var(--marron)"><?= $tot_pedidos ?></p>
        <p style="margin:4px 0 0;font-size:0.82rem;color:var(--gris)">Pedidos en red</p>
      </div>
      <div style="background:var(--crema);border-radius:var(--radio);padding:18px 20px;text-align:center">
        <p style="font-size:2rem;font-weight:800;margin:0;color:var(--marron)"><?= precio($tot_ingresos) ?></p>
        <p style="margin:4px 0 0;font-size:0.82rem;color:var(--gris)">Ingresos en red</p>
      </div>
    </div>

    <!-- Detalle por Hija -->
    <div class="sec-card">
      <div class="sec-card-top">
        <h2>📋 Detalle por sucursal</h2>
      </div>
      <div style="display:grid;gap:14px">
        <?php foreach ($metricas_hijas as $m): ?>
          <div style="background:var(--crema);border-radius:var(--radio);padding:18px 20px">
            <!-- Header Hija -->
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px;flex-wrap:wrap">
              <?= avatar_html($m, '40px', '0.85rem') ?>
              <div style="flex:1">
                <p style="font-weight:700;margin:0 0 2px"><?= h($m['nombre_panaderia'] ?: $m['nombre']) ?></p>
                <p style="color:var(--gris);font-size:0.8rem;margin:0"><?= h($m['email']) ?></p>
              </div>
              <span style="padding:4px 12px;border-radius:50px;font-size:0.78rem;font-weight:700;
                      background:<?= $m['estado_verificacion'] === 'aprobado' ? '#E8F5E9' : '#FFF8E1' ?>;
                      color:<?= $m['estado_verificacion'] === 'aprobado' ? '#2E7D32' : '#E65100' ?>">
                <?= $m['estado_verificacion'] === 'aprobado' ? '✅ Activa' : '⏳ ' . h($m['estado_verificacion']) ?>
              </span>
            </div>

            <!-- Stats de la Hija -->
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:10px">
              <div style="background:#fff;border-radius:calc(var(--radio)/2);padding:12px 14px;text-align:center">
                <p style="font-size:1.4rem;font-weight:800;margin:0;color:var(--naranja)"><?= $m['total_asignados'] ?></p>
                <p style="font-size:0.76rem;color:var(--gris);margin:2px 0 0">Asignados</p>
              </div>
              <div style="background:#fff;border-radius:calc(var(--radio)/2);padding:12px 14px;text-align:center">
                <p style="font-size:1.4rem;font-weight:800;margin:0;color:#2E7D32"><?= $m['total_aceptados'] ?></p>
                <p style="font-size:0.76rem;color:var(--gris);margin:2px 0 0">Aceptados</p>
              </div>
              <div style="background:#fff;border-radius:calc(var(--radio)/2);padding:12px 14px;text-align:center">
                <p style="font-size:1.4rem;font-weight:800;margin:0;color:var(--marron)"><?= $m['pendientes'] ?></p>
                <p style="font-size:0.76rem;color:var(--gris);margin:2px 0 0">Pedidos pend.</p>
              </div>
              <div style="background:#fff;border-radius:calc(var(--radio)/2);padding:12px 14px;text-align:center">
                <p style="font-size:1.4rem;font-weight:800;margin:0;color:var(--marron)"><?= $m['total_pedidos'] ?></p>
                <p style="font-size:0.76rem;color:var(--gris);margin:2px 0 0">Total pedidos</p>
              </div>
              <div style="background:#fff;border-radius:calc(var(--radio)/2);padding:12px 14px;text-align:center">
                <p style="font-size:1.1rem;font-weight:800;margin:0;color:var(--marron)"><?= precio((float)$m['ingresos']) ?></p>
                <p style="font-size:0.76rem;color:var(--gris);margin:2px 0 0">Ingresos</p>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

  <?php endif; ?>

<?php /* ══════════════════ PERFIL ══════════════════ */ elseif ($seccion === 'perfil'): ?>

  <div class="sec-card perfil-wrap">
    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>"><input type="hidden" name="accion" value="perfil">

      <!-- Avatar -->
      <div style="display:flex;align-items:center;gap:20px;margin-bottom:24px">
        <div class="avatar-circle" id="avatar-preview">
          <?php if (!empty($u['avatar_url'])): ?>
            <img src="<?= h($u['avatar_url']) ?>" alt="">
          <?php else: ?>
            <?= mb_strtoupper(mb_substr($u['nombre_panaderia'] ?: $u['nombre'], 0, 1)) ?>
          <?php endif; ?>
        </div>
        <div>
          <label for="pf-avatar" class="btn btn-ghost btn-sm"
            style="cursor:pointer;display:inline-flex">
            📷 Cambiar foto de perfil
          </label>
          <input type="file" id="pf-avatar" name="avatar"
            accept="image/*" style="display:none">
          <p style="font-size:0.78rem;color:var(--gris);margin-top:6px">
            JPG o PNG — máx 2MB
          </p>
        </div>
      </div>

      <div class="form-row">
        <div class="field">
          <label>Nombre completo</label>
          <input type="text" name="nombre" value="<?= h($u['nombre']) ?>">
        </div>
        <div class="field">
          <label>Nombre de la panadería</label>
          <input type="text" name="nombre_panaderia"
            value="<?= h($u['nombre_panaderia'] ?? '') ?>"
            placeholder="Ej: Panadería Los Pumas">
        </div>
      </div>

      <div class="field">
        <label>Descripción</label>
        <textarea name="descripcion" rows="3"
          placeholder="Contales quiénes son, qué los hace únicos..."><?= h($u['descripcion'] ?? '') ?></textarea>
      </div>

      <div class="field">
        <label>
          📢 Banner de anuncio
          <span style="font-weight:400;color:var(--gris)">(aparece en tu tienda)</span>
        </label>
        <input type="text" name="banner"
          value="<?= h($u['banner_anuncio'] ?? '') ?>"
          placeholder="Ej: ¡Esta semana 10% off en medialunas! 🥐"
          maxlength="120">
        <div style="font-size:0.75rem;color:var(--gris);margin-top:4px">
          Máx 120 caracteres. Dejalo vacío para no mostrar.
        </div>
      </div>

      <div class="form-row">
        <div class="field">
          <label>Instagram (sin @)</label>
          <input type="text" name="instagram"
            value="<?= h($u['instagram'] ?? '') ?>"
            placeholder="mibakery">
        </div>
        <div class="field">
          <label>Teléfono / WhatsApp</label>
          <input type="tel" name="telefono"
            value="<?= h($u['telefono'] ?? '') ?>"
            placeholder="+54 9 383 000-0000">
        </div>
      </div>

      <div class="field">
        <label>Email de contacto</label>
        <input type="email" name="email_contacto"
          value="<?= h($u['email_contacto'] ?? '') ?>">
      </div>

      <hr style="border:none;border-top:1px solid var(--crema-dark);margin:24px 0">
      <h3 style="margin-bottom:6px">💳 Medios de pago que aceptás</h3>
      <p class="medios-pago-hint">
        El efectivo siempre está disponible. Activá los demás que uses.
      </p>

      <div class="medios-pago-grid">
        <!-- Efectivo: siempre activo, no editable -->
        <label class="medio-check on disabled">
          <input type="checkbox" checked disabled>
          <span class="medio-ico">💵</span> Efectivo
        </label>

        <!-- Transferencia: muestra panel CBU al activar -->
        <label class="medio-check <?= $tiene_transf ? 'on' : '' ?>" id="lbl-transf">
          <input type="checkbox" name="medio_transferencia" id="chk-transf"
            <?= $tiene_transf ? 'checked' : '' ?>>
          <span class="medio-ico">📲</span> Transferencia
        </label>

        <label class="medio-check <?= in_array('debito', $medios_actuales) ? 'on' : '' ?>">
          <input type="checkbox" name="medio_debito" id="chk-debito"
            <?= in_array('debito', $medios_actuales) ? 'checked' : '' ?>>
          <span class="medio-ico">💳</span> Débito
        </label>

        <label class="medio-check <?= in_array('credito', $medios_actuales) ? 'on' : '' ?>">
          <input type="checkbox" name="medio_credito" id="chk-credito"
            <?= in_array('credito', $medios_actuales) ? 'checked' : '' ?>>
          <span class="medio-ico">💳</span> Crédito
        </label>
      </div>

      <!-- Panel datos de transferencia -->
      <div class="transferencia-panel" id="panel-transf"
        style="display:<?= $tiene_transf ? 'block' : 'none' ?>">
        <div style="font-weight:700;margin-bottom:12px;color:var(--marron)">
          📲 Datos para transferencia
        </div>
        <div class="field">
          <label>CBU / CVU</label>
          <input type="text" name="cbu"
            value="<?= h($u['cbu'] ?? '') ?>"
            placeholder="0000003100000000000000"
            maxlength="22" inputmode="numeric">
        </div>
        <div class="form-row">
          <div class="field">
            <label>Alias</label>
            <input type="text" name="alias_cbu"
              value="<?= h($u['alias_cbu'] ?? '') ?>"
              placeholder="mi.alias.mp">
          </div>
          <div class="field">
            <label>Titular de la cuenta</label>
            <input type="text" name="titular_cuenta"
              value="<?= h($u['titular_cuenta'] ?? '') ?>"
              placeholder="Nombre Apellido">
          </div>
        </div>
        <div style="font-size:0.78rem;color:var(--gris);margin-top:-4px">
          Ingresá al menos el CBU o el alias para que los compradores puedan transferirte.
        </div>
      </div>

      <hr style="border:none;border-top:1px solid var(--crema-dark);margin:24px 0">
      <button type="submit" class="btn btn-marron">💾 Guardar cambios</button>
    </form>
  </div>

<?php /* ══════════════════ DOCUMENTOS ══════════════════ */ elseif ($seccion === 'documentos'): ?>

  <div class="sec-card">
    <div class="sec-card-top" style="margin-bottom:20px">
      <h2>📂 Mis Documentos</h2>
    </div>

    <?php
        $ev   = $u['estado_verificacion'] ?? 'sin_enviar';
        $nota = $u['doc_notas_rechazo']   ?? '';
        $cfgs = [
          'sin_enviar' => [
            'ico' => '📂',
            'color' => '#757575',
            'bg' => '#F5F5F5',
            'titulo' => 'Documentos no enviados',
            'msg' => 'Subí tus 3 documentos y envialos para que podamos verificar tu panadería.'
          ],
          'pendiente'  => [
            'ico' => '🕐',
            'color' => '#F57F17',
            'bg' => '#FFF8E1',
            'titulo' => 'Documentos en revisión',
            'msg' => 'Recibimos tu documentación. Te notificaremos por email cuando esté lista la revisión.'
          ],
          'aprobado'   => [
            'ico' => '✅',
            'color' => '#2E7D32',
            'bg' => '#E8F5E9',
            'titulo' => '¡Panadería verificada!',
            'msg' => 'Tu documentación fue aprobada. Tus productos ya son visibles en el catálogo.'
          ],
          'rechazado'  => [
            'ico' => '❌',
            'color' => '#C62828',
            'bg' => '#FFEBEE',
            'titulo' => 'Documentación rechazada',
            'msg' => 'Tu documentación fue rechazada. Revisá el mensaje del administrador y volvé a subir los documentos corregidos.'
          ],
        ];
        $cfg = $cfgs[$ev] ?? $cfgs['sin_enviar'];
    ?>

    <!-- Banner estado -->
    <div style="background:<?= $cfg['bg'] ?>;border-radius:var(--radio);
                  padding:14px 18px;display:flex;gap:12px;align-items:flex-start;
                  margin-bottom:24px">
      <span style="font-size:1.5rem;flex-shrink:0"><?= $cfg['ico'] ?></span>
      <div>
        <div style="font-weight:700;color:<?= $cfg['color'] ?>;margin-bottom:3px">
          <?= $cfg['titulo'] ?>
        </div>
        <div style="font-size:0.85rem;color:var(--gris)"><?= h($cfg['msg']) ?></div>
        <?php if ($ev === 'rechazado' && $nota): ?>
          <div style="margin-top:8px;padding:10px;background:rgba(198,40,40,.08);
                        border-radius:8px;font-size:0.83rem;color:#C62828">
            <strong>Mensaje del administrador:</strong> <?= h($nota) ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <p style="font-size:0.88rem;color:var(--gris);margin-bottom:20px;line-height:1.7">
      Para poder vender en PanaderiaMarket necesitás subir los siguientes documentos obligatorios.
      Una vez enviados, el equipo los revisará y te notificará por email en 24–48hs.
    </p>

    <?php
        $doc_lista = [
          [
            'col' => 'doc_bromatologia',
            'n' => 1,
            'ico' => '📋',
            'titulo' => 'Habilitación Bromatológica Municipal',
            'sub'  => 'Emitida por la Dirección de Calidad Alimentaria del Municipio de Catamarca'
          ],
          [
            'col' => 'doc_carnet_manipulador',
            'n' => 2,
            'ico' => '🪪',
            'titulo' => 'Carnet de Manipulador de Alimentos',
            'sub'  => 'Emitido por la autoridad sanitaria municipal o provincial. Al menos 1 por establecimiento.'
          ],
          [
            'col' => 'doc_habilitacion_comercial',
            'n' => 3,
            'ico' => '🏪',
            'titulo' => 'Habilitación Comercial Municipal',
            'sub'  => 'Formulario Único de Habilitación Comercial del Municipio de Catamarca'
          ],
        ];
        foreach ($doc_lista as $d):
          $url = $u[$d['col']] ?? '';
    ?>
      <div class="sec-card"
        style="box-shadow:none;border:2px solid var(--crema-dark);
                  margin-bottom:14px;padding:18px">
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px">
          <span style="font-size:1.6rem"><?= $d['ico'] ?></span>
          <div>
            <div style="font-weight:700"><?= h($d['titulo']) ?></div>
            <div style="font-size:0.78rem;color:var(--gris)"><?= h($d['sub']) ?></div>
          </div>
          <span id="ico-doc-<?= $d['n'] ?>" style="margin-left:auto;font-size:1.2rem">
            <?= $url ? '✅' : '' ?>
          </span>
        </div>

        <div id="preview-doc-<?= $d['n'] ?>" style="margin-bottom:10px">
          <?php if ($url): ?>
            <?php if (str_ends_with(strtolower($url), '.pdf')): ?>
              <div style="padding:10px;background:var(--crema);border-radius:8px;
                          font-size:0.82rem;color:var(--marron)">
                📄 Archivo PDF subido —
                <a href="<?= SITE_URL ?>/<?= h($url) ?>" target="_blank"
                  style="color:var(--naranja)">Ver</a>
              </div>
            <?php else: ?>
              <img src="<?= SITE_URL ?>/<?= h($url) ?>"
                style="width:100%;max-height:140px;object-fit:cover;
                          border-radius:8px;border:2px solid var(--crema-dark)">
            <?php endif; ?>
          <?php endif; ?>
        </div>

        <label for="file-doc-<?= $d['n'] ?>" class="btn btn-ghost btn-sm"
          style="cursor:pointer;display:inline-flex">
          📁 <?= $url ? 'Reemplazar archivo' : 'Subir archivo' ?>
        </label>
        <input type="file" id="file-doc-<?= $d['n'] ?>"
          accept="image/*,.pdf" style="display:none">
        <span style="font-size:0.75rem;color:var(--gris);margin-left:10px">
          JPG, PNG o PDF — máx 5MB
        </span>
      </div>
    <?php endforeach; ?>

    <button class="btn btn-naranja" id="btn-enviar-docs"
      <?= $ev === 'pendiente' ? 'disabled title="Ya enviados, aguardá la revisión"' : '' ?>>
      📤 Enviar documentos para revisión
    </button>
    <p style="font-size:0.75rem;color:var(--gris);margin-top:8px">
      Una vez enviados, el equipo revisará tu documentación en un plazo de 24–48hs.
    </p>
  </div>

<?php endif; ?>

</main>
</div><!-- /dash-layout -->

<div id="toast-box"></div>

<script>
  /* ── Sidebar mobile ─────────────────────────────────────────────────────── */
  const sidebar = document.getElementById('sidebar');
  const overlay = document.getElementById('sidebar-overlay');
  document.getElementById('mob-menu-btn')?.addEventListener('click', () => {
    sidebar.classList.toggle('open');
    overlay.classList.toggle('open');
  });
  overlay?.addEventListener('click', () => {
    sidebar.classList.remove('open');
    overlay.classList.remove('open');
  });

  /* ── Preview imagen principal ───────────────────────────────────────────── */
  document.getElementById('p-img-file')?.addEventListener('change', function() {
    const file = this.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = e => {
      const prev = document.getElementById('img-preview');
      prev.src = e.target.result;
      prev.style.display = 'block';
    };
    reader.readAsDataURL(file);
  });

  /* ── Preview fotos adicionales ──────────────────────────────────────────── */
  document.getElementById('p-fotos-extra')?.addEventListener('change', function() {
    const wrap = document.getElementById('fotos-extra-preview');
    wrap.innerHTML = '';
    Array.from(this.files).slice(0, 4).forEach(f => {
      const reader = new FileReader();
      reader.onload = e => {
        const img = document.createElement('img');
        img.src = e.target.result;
        img.className = 'fotos-extra-thumb';
        wrap.appendChild(img);
      };
      reader.readAsDataURL(f);
    });
  });

  /* ── Preview avatar ─────────────────────────────────────────────────────── */
  document.getElementById('pf-avatar')?.addEventListener('change', function() {
    const file = this.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = e => {
      const av = document.getElementById('avatar-preview');
      av.innerHTML = `<img src="${e.target.result}"
                         style="width:100%;height:100%;object-fit:cover">`;
    };
    reader.readAsDataURL(file);
  });

  /* ── Se vende por: toggle campos docena ────────────────────────────────── */
  const selUnidad = document.getElementById('sel-unidad');
  const camposDoc = document.getElementById('campos-docena');
  const hintKilo = document.getElementById('hint-kilo');
  const lblHint = document.getElementById('lbl-precio-hint');

  function actualizarUnidad() {
    if (!selUnidad) return;
    const esKilo = selUnidad.value === 'kilo';
    if (camposDoc) camposDoc.style.display = esKilo ? 'none' : 'grid';
    if (hintKilo) hintKilo.style.display = esKilo ? 'block' : 'none';
    if (lblHint) lblHint.textContent = esKilo ? '(por kg)' : '(por unidad)';
  }
  selUnidad?.addEventListener('change', actualizarUnidad);
  actualizarUnidad();

  /* ── Medios de pago: toggle estilo ─────────────────────────────────────── */
  document.querySelectorAll('.medios-pago-grid .medio-check:not(.disabled)').forEach(lbl => {
    const chk = lbl.querySelector('input[type=checkbox]');
    chk?.addEventListener('change', () => {
      lbl.classList.toggle('on', chk.checked);
    });
  });

  /* ── Panel transferencia ────────────────────────────────────────────────── */
  const chkTransf = document.getElementById('chk-transf');
  const panelTransf = document.getElementById('panel-transf');
  chkTransf?.addEventListener('change', function() {
    if (panelTransf) panelTransf.style.display = this.checked ? 'block' : 'none';
  });

  /* ── Filtro + búsqueda de pedidos ───────────────────────────────────────── */
  let filtroEstado = 'todos';
  let busqNombre = '';

  function filtrarPedidos() {
    const cards = document.querySelectorAll('#lista-pedidos .pedido-card');
    let visible = 0;
    cards.forEach(c => {
      const okEstado = filtroEstado === 'todos' || c.dataset.estado === filtroEstado;
      const okNombre = !busqNombre || (c.dataset.nombre || '').includes(busqNombre);
      const show = okEstado && okNombre;
      c.style.display = show ? '' : 'none';
      if (show) visible++;
    });
    const empty = document.getElementById('empty-pedidos');
    if (empty) empty.style.display = (visible === 0 && cards.length > 0) ? 'block' : 'none';
  }

  document.querySelectorAll('#filtros-estado .filtro').forEach(btn => {
    btn.addEventListener('click', () => {
      document.querySelectorAll('#filtros-estado .filtro').forEach(b => b.classList.remove('on'));
      btn.classList.add('on');
      filtroEstado = btn.dataset.estado;
      filtrarPedidos();
    });
  });

  document.getElementById('buscar-pedidos')?.addEventListener('input', function() {
    busqNombre = this.value.toLowerCase().trim();
    filtrarPedidos();
  });

  /* ── Toast ──────────────────────────────────────────────────────────────── */
  function toast(msg, tipo = 'ok') {
    const box = document.getElementById('toast-box');
    if (!box) return;
    const t = document.createElement('div');
    t.className = `toast toast-${tipo === 'ok' ? 'ok' : tipo === 'err' ? 'err' : 'inf'}`;
    t.innerHTML = `<div class="toast-icon">${tipo === 'ok' ? '✓' : '!'}</div>${msg}`;
    box.appendChild(t);
    setTimeout(() => t.remove(), 3200);
  }

  <?php if ($msg_ok): ?>toast('<?= addslashes($msg_ok) ?>', 'ok');
  <?php endif; ?>
</script>

</body>

</html>