<?php
// config.simple.php - Simple Docker config
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
session_start();

// Force Docker database configuration
define('DB_HOST', 'db');
define('DB_NAME', 'dacn1_db');
define('DB_USER', 'root');
define('DB_PASS', 'rootpassword');
define('APP_NAME', 'Kết nối Y tế');
define('SITE_URL', 'http://localhost:8080');

try {
    $pdo = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4', DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    echo "<!-- Database connected successfully -->";
} catch (Exception $e) {
    die('Database connection failed: '.$e->getMessage());
}

// Basic functions - chỉ khai báo nếu chưa tồn tại
if (!function_exists('is_logged_in')) {
    function is_logged_in() {
        return !empty($_SESSION['user_id']);
    }
}

if (!function_exists('require_login')) {
    function require_login() {
        if (!is_logged_in()) {
            header('Location: login.php');
            exit;
        }
    }
}

if (!function_exists('is_admin_user')) {
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
}

if (!function_exists('require_admin')) {
    function require_admin() {
        require_login();
        if (!is_admin_user()) {
            header('Location: index.php');
            exit;
        }
    }
}

if (!function_exists('is_user_online')) {
    function is_user_online($lastActivity) {
        if (empty($lastActivity)) return false;
        $lastTime = strtotime($lastActivity);
        return (time() - $lastTime) < 300; // 5 minutes
    }
}

if (!function_exists('update_user_activity')) {
    function update_user_activity() {
        if (!empty($_SESSION['user_id'])) {
            global $pdo;
            $lastUpdate = $_SESSION['last_activity_update'] ?? 0;
            if (time() - $lastUpdate > 60) {
                try {
                    $stmt = $pdo->prepare('UPDATE users SET last_activity = NOW() WHERE id = ?');
                    $stmt->execute([$_SESSION['user_id']]);
                    $_SESSION['last_activity_update'] = time();
                } catch (Exception $e) {
                    // ignore
                }
            }
        }
    }
}

// Auto-update activity
update_user_activity();
?>