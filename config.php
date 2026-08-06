<?php
define('DB_HOST',    'localhost');
define('DB_PORT',    '1107');
define('DB_NAME',    'panaderia_db');
define('DB_USER',    'root');
define('DB_PASS',    '');
define('DB_CHARSET', 'utf8mb4');

define('SITE_NAME', 'PanaderiaMarket');
define('SITE_URL',  'http://localhost:8012/panaderia');

define('UPLOAD_DIR', __DIR__ . '/uploads/');
define('UPLOAD_URL', SITE_URL . '/uploads/');

function db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
    $opts = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $opts);
    } catch (PDOException $e) {
        // TEMPORAL - para ver el error exacto:
        die('<pre style="color:red;padding:20px">ERROR DE BD: ' . $e->getMessage() . '</pre>');
    }
    return $pdo;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function usuario_actual(): ?array {
    if (!isset($_SESSION['user_id'])) return null;
    $stmt = db()->prepare('SELECT * FROM usuarios WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch() ?: null;
}

function esta_logueado(): bool {
    return isset($_SESSION['user_id']);
}

function es_vendedor(): bool {
    return ($_SESSION['user_tipo'] ?? '') === 'vendedor';
}

function es_admin(): bool {
    return ($_SESSION['user_tipo'] ?? '') === 'admin';
}

function requerir_login(string $redir = 'login.php'): void {
    if (!esta_logueado()) {
        $_SESSION['redirect_post_login'] = $_SERVER['REQUEST_URI'];
        header('Location: ' . SITE_URL . '/' . $redir);
        exit;
    }
}

function requerir_vendedor(): void {
    requerir_login();

    if (!es_vendedor()) {
        header('Location: ' . SITE_URL . '/index.php');
        exit;
    }
}

function requerir_encargado(): void {
    requerir_login();

    $u = usuario_actual();

    // Todo vendedor puede entrar a su panel,
    // aunque todavía no haya sido aprobado por el administrador.
    if (
        !$u ||
        ($u['tipo'] ?? '') !== 'vendedor'
    ) {
        header('Location: ' . SITE_URL . '/index.php');
        exit;
    }
}

function requerir_admin(): void {
    requerir_login('admin-login.php');
    if (!es_admin()) { header('Location: ' . SITE_URL . '/index.php'); exit; }
}