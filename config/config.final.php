<?php
// config.final.php - Final minimal config without schema updates
ini_set('display_errors', '0'); // Tắt hiển thị lỗi
ini_set('display_startup_errors', '0');
error_reporting(0); // Tắt error reporting
session_start();

define('DB_HOST', 'db');
define('DB_NAME', 'dacn1_db');
define('DB_USER', 'root');
define('DB_PASS', 'rootpassword');
define('APP_NAME', 'Kết nối Y tế');
define('SITE_URL', 'http://localhost:8080');
define('MAIL_FROM_ADDRESS', 'tramtankhatv@gmail.com');
define('MAIL_FROM_NAME', 'Kết nối Y tế');
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_ENCRYPTION', 'tls');
define('SMTP_USERNAME', 'tramtankhatv@gmail.com');
define('SMTP_PASSWORD', 'bghf tohu ppff vkea');
define('FACEBOOK_APP_ID', '1274834994363695');
define('FACEBOOK_APP_SECRET', 'c8ca96fd22492f2fd6147580f5995568');

try {
    $pdo = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4', DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Exception $e) {
    die('Database connection failed: '.$e->getMessage());
}

function is_logged_in() {
    return !empty($_SESSION['user_id']);
}

function require_login() {
    if (!is_logged_in()) {
        header('Location: login.php');
        exit;
    }
}

function is_admin_user() {
    if (!empty($_SESSION['is_admin'])) {
        return true;
    }
    if (!empty($_SESSION['user_id'])) {
        global $pdo;
        $stmt = $pdo->prepare('SELECT is_admin FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$_SESSION['user_id']]);
        $isAdmin = $stmt->fetchColumn();
        if ($isAdmin) {
            $_SESSION['is_admin'] = 1;
            return true;
        }
    }
    return false;
}

function require_admin() {
    require_login();
    if (!is_admin_user()) {
        header('Location: index.php');
        exit;
    }
}

function refresh_student_verification_flag() {
    if (!empty($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'student') {
        global $pdo;
        $stmt = $pdo->prepare('SELECT verified FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$_SESSION['user_id']]);
        $verified = $stmt->fetchColumn();
        $_SESSION['verified'] = $verified ? 1 : 0;
        return !empty($verified);
    }
    return false;
}

function is_student_verified() {
    if (($_SESSION['role'] ?? '') !== 'student') {
        return false;
    }
    if (isset($_SESSION['verified'])) {
        return (bool)$_SESSION['verified'];
    }
    return refresh_student_verification_flag();
}

// KHÔNG chạy schema updates để tránh lỗi
// ensure_schema_updates();

// Update last_activity cho logged-in users (đơn giản hóa)
function update_user_activity() {
    if (!empty($_SESSION['user_id'])) {
        global $pdo;
        $lastUpdate = $_SESSION['last_activity_update'] ?? 0;
        if (time() - $lastUpdate > 60) {
            try {
                // Kiểm tra xem cột last_activity có tồn tại không
                $stmt = $pdo->prepare("SHOW COLUMNS FROM users LIKE 'last_activity'");
                $stmt->execute();
                if ($stmt->rowCount() > 0) {
                    $stmt = $pdo->prepare('UPDATE users SET last_activity = NOW() WHERE id = ?');
                    $stmt->execute([$_SESSION['user_id']]);
                }
                $_SESSION['last_activity_update'] = time();
            } catch (Exception $e) {
                // Bỏ qua lỗi
            }
        }
    }
}

function is_user_online($lastActivity) {
    if (empty($lastActivity)) return false;
    $lastTime = strtotime($lastActivity);
    return (time() - $lastTime) < 300;
}

// Auto-update activity
update_user_activity();

// Helper functions (đơn giản hóa)
function find_upload(string $path): ?string {
    $path = trim($path);
    if ($path === '') return null;
    if (preg_match('#^https?://#i', $path)) return $path;
    
    $candidates = [
        'uploads/verification_docs/' . $path,
        'uploads/student_cards/' . $path,
        'uploads/' . $path,
        ltrim($path, '/')
    ];
    
    foreach ($candidates as $candidate) {
        if (file_exists(dirname(__DIR__) . '/' . $candidate)) {
            return $candidate;
        }
    }
    return null;
}

function public_url_for(?string $path): string {
    if (empty($path)) return '';
    if (preg_match('#^https?://#i', $path)) return $path;
    
    $resolved = find_upload($path);
    $rel = $resolved ?? ltrim($path, '/');
    
    $scheme = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? '') == '443') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
    $urlPath = $base . '/' . ltrim($rel, '/');
    return $scheme . '://' . $host . $urlPath;
}

function site_url(string $path = ''): string {
    if (defined('SITE_URL') && SITE_URL !== '') {
        $base = rtrim(SITE_URL, '/');
        $trimmedPath = ltrim($path, '/\\');
        return $trimmedPath !== '' ? $base . '/' . $trimmedPath : $base;
    }
    
    $scheme = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') == '443')) ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '/') ?: '/';
    $base = $scriptDir === '/' ? '' : trim($scriptDir, '/');
    $segments = [];
    if ($base !== '') $segments[] = $base;
    $trimmedPath = ltrim($path, '/\\');
    if ($trimmedPath !== '') $segments[] = $trimmedPath;
    $uri = implode('/', $segments);
    $suffix = $uri === '' ? '' : '/' . $uri;
    return $scheme . '://' . $host . $suffix;
}

function upload_exists(?string $path): bool {
    if (empty($path)) return false;
    if (preg_match('#^https?://#i', $path)) return true;
    return find_upload($path) !== null;
}
?>