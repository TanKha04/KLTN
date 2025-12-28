<?php
// config.docker.php - Docker DB connection
// Enable errors for development (remove or disable on production)
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Docker database configuration
define('DB_HOST', 'db');  // Docker service name
define('DB_NAME', 'dacn1_db');
define('DB_USER', 'dbuser');
define('DB_PASS', 'dbpassword');
define('APP_NAME', 'Kết nối Y tế');

// URL công khai của website (dùng cho link trong email). Để trống nếu muốn dùng tự động.
define('SITE_URL', 'http://192.168.1.204:8080');
define('MAIL_FROM_ADDRESS', 'tramtankhatv@gmail.com');
define('MAIL_FROM_NAME', 'Kết nối Y tế');
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_ENCRYPTION', 'tls');
define('SMTP_USERNAME', 'tramtankhatv@gmail.com');
define('SMTP_PASSWORD', 'bghf tohu ppff vkea');
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

// Include all the functions from original config.php
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

// Ensure schema updates for newer features (safe to call on each request).
function ensure_schema_updates() {
    global $pdo;
    try {
        // helper to check column existence
        $colStmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");

        // ensure users.can_post
        $colStmt->execute(['users','can_post']);
        if ((int)$colStmt->fetchColumn() === 0) {
            try {
                $pdo->exec("ALTER TABLE users ADD COLUMN can_post TINYINT(1) DEFAULT 0 AFTER is_admin");
            } catch (Exception $e) {
                // ignore - maybe insufficient privileges
            }
        }

        // ensure users.last_login
        $colStmt->execute(['users','last_login']);
        if ((int)$colStmt->fetchColumn() === 0) {
            try {
                $pdo->exec("ALTER TABLE users ADD COLUMN last_login DATETIME DEFAULT NULL AFTER can_post");
            } catch (Exception $e) {
                // ignore
            }
        }

        // ensure users.last_activity for online status tracking
        $colStmt->execute(['users','last_activity']);
        if ((int)$colStmt->fetchColumn() === 0) {
            try {
                $pdo->exec("ALTER TABLE users ADD COLUMN last_activity DATETIME DEFAULT NULL AFTER last_login");
            } catch (Exception $e) {
                // ignore
            }
        }

        // ensure users.email_verified
        $colStmt->execute(['users','email_verified']);
        if ((int)$colStmt->fetchColumn() === 0) {
            try {
                $pdo->exec("ALTER TABLE users ADD COLUMN email_verified TINYINT(1) DEFAULT 0 AFTER email");
                $pdo->exec("UPDATE users SET email_verified = 1");
            } catch (Exception $e) {
                // ignore
            }
        }

        // ensure posting_requests table exists
        $tblStmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        $tblStmt->execute(['posting_requests']);
        if ((int)$tblStmt->fetchColumn() === 0) {
            try {
                $pdo->exec(
                    "CREATE TABLE IF NOT EXISTS `posting_requests` (
                      `id` INT AUTO_INCREMENT PRIMARY KEY,
                      `user_id` INT NOT NULL,
                      `full_name` VARCHAR(150) NOT NULL,
                      `student_code` VARCHAR(100) NOT NULL,
                      `class_name` VARCHAR(100) DEFAULT NULL,
                      `address` VARCHAR(255) DEFAULT NULL,
                      `document_card` VARCHAR(255) DEFAULT NULL,
                      `document_internship` VARCHAR(255) DEFAULT NULL,
                      `status` ENUM('pending','approved','rejected') DEFAULT 'pending',
                      `admin_note` TEXT DEFAULT NULL,
                      `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                      `processed_at` TIMESTAMP NULL DEFAULT NULL,
                      FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
                );
            } catch (Exception $e) {
                // ignore
            }
        }

        // ensure users.avatar (profile image)
        $colStmt->execute(['users','avatar']);
        if ((int)$colStmt->fetchColumn() === 0) {
            try {
                $pdo->exec("ALTER TABLE users ADD COLUMN avatar VARCHAR(255) DEFAULT NULL AFTER phone");
            } catch (Exception $e) {
                // ignore
            }
        }

        // ensure users.facebook_id
        $colStmt->execute(['users','facebook_id']);
        if ((int)$colStmt->fetchColumn() === 0) {
            try {
                $pdo->exec("ALTER TABLE users ADD COLUMN facebook_id VARCHAR(64) DEFAULT NULL AFTER email_verified, ADD UNIQUE KEY idx_users_facebook_id (facebook_id)");
            } catch (Exception $e) {
                // ignore
            }
        }

        // ensure email_verifications table exists
        $tblStmt->execute(['email_verifications']);
        if ((int)$tblStmt->fetchColumn() === 0) {
            try {
                $pdo->exec(
                    "CREATE TABLE IF NOT EXISTS `email_verifications` (
                        `id` INT AUTO_INCREMENT PRIMARY KEY,
                        `user_id` INT NOT NULL,
                        `token` VARCHAR(128) NOT NULL UNIQUE,
                        `expires_at` DATETIME NOT NULL,
                        `used_at` DATETIME DEFAULT NULL,
                        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
                );
            } catch (Exception $e) {
                // ignore
            }
        }

        // ensure password_resets table exists
        $tblStmt->execute(['password_resets']);
        if ((int)$tblStmt->fetchColumn() === 0) {
            try {
                $pdo->exec(
                    "CREATE TABLE IF NOT EXISTS `password_resets` (
                        `id` INT AUTO_INCREMENT PRIMARY KEY,
                        `user_id` INT NOT NULL,
                        `token` VARCHAR(128) NOT NULL UNIQUE,
                        `expires_at` DATETIME NOT NULL,
                        `used_at` DATETIME DEFAULT NULL,
                        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
                );
            } catch (Exception $e) {
                // ignore
            }
        }
    } catch (Exception $e) {
        // nothing to do
    }
}

// Run schema updates (best-effort)
ensure_schema_updates();

// Update last_activity for logged-in users (track online status)
function update_user_activity() {
    if (!empty($_SESSION['user_id'])) {
        global $pdo;
        // Only update every 60 seconds to reduce DB writes
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

// Check if user is online (active within last 5 minutes)
function is_user_online($lastActivity) {
    if (empty($lastActivity)) return false;
    $lastTime = strtotime($lastActivity);
    return (time() - $lastTime) < 300; // 5 minutes = 300 seconds
}

// Auto-update activity on each page load
update_user_activity();

// Helper: produce a public URL for a stored upload path and check existence
function find_upload(string $path): ?string {
    $path = trim($path);
    if ($path === '') return null;

    // If it's a full URL, we can't resolve to local FS here
    if (preg_match('#^https?://#i', $path)) {
        return $path; // caller should handle remote URLs separately
    }

    $candidates = [];

    // If path already contains uploads/ assume it's relative to project
    if (preg_match('#^(?:/)?uploads/#i', $path)) {
        $candidates[] = ltrim($path, '/');
    } else {
        // try common upload folders and the raw filename
        $candidates[] = 'uploads/verification_docs/' . $path;
        $candidates[] = 'uploads/student_cards/' . $path;
        $candidates[] = 'uploads/' . $path;
        $candidates[] = ltrim($path, '/');
    }

    foreach ($candidates as $candidate) {
        $fs = __DIR__ . '/' . $candidate;
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
    // If we couldn't resolve locally, fallback to using the provided path as relative
    $rel = $resolved ?? ltrim($path, '/');

    $scheme = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? '') == '443') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
    $urlPath = $base . '/' . ltrim($rel, '/');
    return $scheme . '://' . $host . $urlPath;
}

function site_url(string $path = ''): string {
    // Nếu đã cấu hình SITE_URL, sử dụng nó
    if (defined('SITE_URL') && SITE_URL !== '') {
        $base = rtrim(SITE_URL, '/');
        $trimmedPath = ltrim($path, '/\\');
        return $trimmedPath !== '' ? $base . '/' . $trimmedPath : $base;
    }
    
    // Tự động detect từ request
    $scheme = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') == '443')) ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '/') ?: '/';
    $base = $scriptDir === '/' ? '' : trim($scriptDir, '/');
    $segments = [];
    if ($base !== '') {
        $segments[] = $base;
    }
    $trimmedPath = ltrim($path, '/\\');
    if ($trimmedPath !== '') {
        $segments[] = $trimmedPath;
    }
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