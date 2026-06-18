<?php
// config.php - Auto-detect Docker or local environment
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Load .env file if it exists
if (file_exists(dirname(__DIR__) . '/.env')) {
    $lines = file(dirname(__DIR__) . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0) continue;
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            $name = trim($parts[0]);
            $value = trim($parts[1]);
            $value = trim($value, '"\'');
            if (getenv($name) === false) {
                putenv("$name=$value");
            }
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}

// Define API constants from environment
if (!defined('GEMINI_API_KEY')) {
    define('GEMINI_API_KEY', getenv('GEMINI_API_KEY') ?: 'AIzaSyBxF_20QmJsyBRr-65K1wgQ21l6L8tLodA');
}
if (!defined('GEMINI_MODEL')) {
    define('GEMINI_MODEL', getenv('GEMINI_MODEL') ?: 'gemini-flash-latest');
}
if (!defined('HUGGINGFACE_API_KEY')) {
    define('HUGGINGFACE_API_KEY', getenv('HUGGINGFACE_API_KEY') ?: '');
}

// Detect Docker environment - kiểm tra chính xác hơn
$isDocker = false;

// Cách 1: Kiểm tra biến môi trường DOCKER_CONTAINER (set trong docker-compose)
if (getenv('DOCKER_CONTAINER') === 'true') {
    $isDocker = true;
}
// Cách 2: Kiểm tra file /.dockerenv (chỉ tồn tại trong Linux container)
elseif (PHP_OS_FAMILY === 'Linux' && file_exists('/.dockerenv')) {
    $isDocker = true;
}
// Cách 3: Kiểm tra hostname có phải container ID không
elseif (PHP_OS_FAMILY === 'Linux' && preg_match('/^[a-f0-9]{12}$/', gethostname())) {
    $isDocker = true;
}

if ($isDocker) {
    // Docker configuration
    define('DB_HOST', 'db');
    define('DB_NAME', 'dacn1_db');
    define('DB_USER', 'root');
    define('DB_PASS', 'rootpassword');
    // Sử dụng ngrok URL khi test từ thiết bị khác, đổi lại localhost:8080 khi không dùng ngrok
    define('SITE_URL', 'https://speakable-rosamaria-foxily.ngrok-free.dev');
} else {
    // Local XAMPP configuration
    define('DB_HOST', 'localhost');
    define('DB_NAME', 'dacn1_db');
    define('DB_USER', 'root');
    define('DB_PASS', '');
    define('SITE_URL', 'http://localhost/DATN');
}

define('APP_NAME', 'Kết nối Y tế');
define('MAIL_FROM_ADDRESS', 'tramtankhatv@gmail.com');
define('MAIL_FROM_NAME', 'Kết nối Y tế');
if ($isDocker) {
    define('SMTP_HOST', 'smtp.gmail.com');
    define('SMTP_PORT', 587);
    define('SMTP_ENCRYPTION', 'tls');
    define('SMTP_USERNAME', 'tramtankhatv@gmail.com');
    define('SMTP_PASSWORD', 'bghf tohu ppff vkea');
} else {
    define('SMTP_HOST', 'smtp.gmail.com');
    define('SMTP_PORT', 587);
    define('SMTP_ENCRYPTION', 'tls');
    define('SMTP_USERNAME', 'tramtankhatv@gmail.com');
    define('SMTP_PASSWORD', 'bghf tohu ppff vkea');
}
define('FACEBOOK_APP_ID', getenv('FACEBOOK_APP_ID') ?: '1274834994363695');
define('FACEBOOK_APP_SECRET', getenv('FACEBOOK_APP_SECRET') ?: 'c8ca96fd22492f2fd6147580f5995568');

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

function is_user_online($lastActivity) {
    if (empty($lastActivity)) return false;
    $lastTime = strtotime($lastActivity);
    return (time() - $lastTime) < 300;
}

// Auto-update activity
update_user_activity();

function find_upload(string $path): ?string {
    $path = trim($path);
    if ($path === '') return null;
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }
    $candidates = [];
    if (preg_match('#^(?:/)?uploads/#i', $path)) {
        $candidates[] = ltrim($path, '/');
    } else {
        $candidates[] = 'uploads/verification_docs/' . $path;
        $candidates[] = 'uploads/student_cards/' . $path;
        $candidates[] = 'uploads/' . $path;
        $candidates[] = ltrim($path, '/');
    }
    foreach ($candidates as $candidate) {
        $fs = dirname(__DIR__) . '/' . $candidate;
        if (file_exists($fs)) {
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

function format_chat_message($msgText) {
    // 1. Escape HTML safely
    $escaped = htmlspecialchars($msgText, ENT_QUOTES, 'UTF-8');
    
    // 2. Parse Markdown Bold: **text** -> <strong>text</strong>
    $escaped = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $escaped);
    
    // 3. Parse Markdown Links: [Text](URL) -> <a href="URL" ...>Text</a>
    $escaped = preg_replace_callback('/\[(.*?)\]\((.*?)\)/', function($matches) {
        $text = $matches[1];
        $url = $matches[2];
        
        $escUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        
        if (strpos($url, 'assignment_history.php') !== false) {
            return '<a href="' . $escUrl . '" onclick="if(window.parent && window.parent !== window && typeof window.parent.showSection === \'function\') { window.parent.showSection(\'history\', \'Lịch sử nhận việc\'); return false; } else { window.location.href=\'' . $escUrl . '\'; return false; }" class="chat-message-link">' . $text . '</a>';
        }
        
        return '<a href="' . $escUrl . '" target="_top" class="chat-message-link">' . $text . '</a>';
    }, $escaped);
    
    return nl2br($escaped);
}
