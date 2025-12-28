<?php
// config.minimal.php - Minimal config for Docker
ini_set('display_errors', '1');
error_reporting(E_ALL);
session_start();

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
    return !empty($_SESSION['is_admin']);
}

function require_admin() {
    require_login();
    if (!is_admin_user()) {
        header('Location: index.php');
        exit;
    }
}
?>