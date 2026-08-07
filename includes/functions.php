<?php
function precio(float $n): string {
    return '$' . number_format($n, 0, ',', '.');
}

function iniciales(string $nombre): string {
    $palabras = array_filter(explode(' ', trim($nombre)));
    $ini = '';
    foreach (array_slice($palabras, 0, 2) as $p) {
        $ini .= mb_strtoupper(mb_substr($p, 0, 1));
    }
    return $ini ?: '?';
}

function categoria_info(string $slug): ?array {
    static $categorias = null;

    if ($categorias === null) {
        $categorias = [];

        try {
            $filas = db()->query("
                SELECT slug, nombre, emoji, activo
                FROM categorias
            ")->fetchAll();

            foreach ($filas as $fila) {
                $categorias[$fila['slug']] = $fila;
            }
        } catch (Throwable $e) {
            $categorias = [];
        }
    }

    return $categorias[$slug] ?? null;
}

function cat_emoji(string $cat): string {
    $categoria = categoria_info($cat);

    if ($categoria && !empty($categoria['emoji'])) {
        return $categoria['emoji'];
    }

    /*
     * Compatibilidad visual con categorías antiguas.
     */
    return match($cat) {
        'pan'      => '🍞',
        'facturas' => '🥐',
        'galletas' => '🍪',
        'cakes'    => '🎂',
        default    => '🥖',
    };
}

function cat_label(string $cat): string {
    $categoria = categoria_info($cat);

    if ($categoria && !empty($categoria['nombre'])) {
        return $categoria['nombre'];
    }

    /*
     * Compatibilidad con valores antiguos que todavía puedan existir.
     */
    return match($cat) {
        'pan'      => 'Pan',
        'facturas' => 'Facturas',
        'galletas' => 'Galletas',
        'cakes'    => 'Tortas',
        default    => 'Otro',
    };
}

function estado_label(string $e): string {
    return match($e) {
        'pendiente'  => 'Pendiente',
        'confirmado' => 'Confirmado',
        'listo'      => 'Listo para retirar',
        'entregado'  => 'Entregado',
        default      => $e,
    };
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function avatar_html(array $u, string $size = '48px', string $font = '1rem'): string {
    $nombre = $u['nombre_panaderia'] ?: $u['nombre'];
    if (!empty($u['avatar_url'])) {
        return '<div style="width:'.$size.';height:'.$size.';border-radius:50%;overflow:hidden;background:var(--crema-dark)">
                  <img src="'.h($u['avatar_url']).'" style="width:100%;height:100%;object-fit:cover" alt="">
                </div>';
    }
    return '<div style="width:'.$size.';height:'.$size.';border-radius:50%;background:var(--naranja);color:white;
                        display:flex;align-items:center;justify-content:center;
                        font-family:\'Playfair Display\',serif;font-size:'.$font.';font-weight:900">
              '.iniciales($nombre).'
            </div>';
}

function subir_imagen(array $file, string $prefix = 'img'): ?string {
    if ($file['error'] !== UPLOAD_ERR_OK) return null;
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg','jpeg','png','webp','gif'])) return null;
    if ($file['size'] > 5 * 1024 * 1024) return null;

    if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0775, true);

    $nombre = $prefix . '_' . uniqid() . '.' . $ext;
    $destino = UPLOAD_DIR . $nombre;
    if (!move_uploaded_file($file['tmp_name'], $destino)) return null;
    return UPLOAD_URL . $nombre;
}