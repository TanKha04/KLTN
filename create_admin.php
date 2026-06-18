<?php
require_once 'config.php';

// Simple admin creation script for local/dev use.
// Usage (browser): http://localhost/DATN/create_admin.php
// Optional query params: ?email=112222039&password=123456

if (php_sapi_name() === 'cli') {
    parse_str(implode('&', array_slice($argv,1)), $_GET);
}

$email = trim($_GET['email'] ?? '112222039@st.tvu.edu.vn');
$password = trim($_GET['password'] ?? '123456');
$name = trim($_GET['name'] ?? 'Admin');

if ($email === '' || $password === '') {
    echo "Missing email or password. Provide via ?email=...&password=...\n";
    exit;
}

try {
    // check if user exists
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $existing = $stmt->fetch(PDO::FETCH_COLUMN);

    $hash = password_hash($password, PASSWORD_BCRYPT);

    if ($existing) {
        $upd = $pdo->prepare('UPDATE users SET name = ?, password = ?, is_admin = 1, verified = 1, email_verified = 1 WHERE id = ?');
        $upd->execute([$name, $hash, $existing]);
        $msg = "Updated existing user (id={$existing}) to admin.\n";
    } else {
        $ins = $pdo->prepare('INSERT INTO users (name, email, password, role, verified, is_admin, email_verified, created_at) VALUES (?, ?, ?, ?, 1, 1, 1, NOW())');
        // default role set to 'patient' — change ? to 'student' if desired
        $ins->execute([$name, $email, $hash, 'patient']);
        $id = $pdo->lastInsertId();
        $msg = "Created admin user (id={$id}).\n";
    }
    $msg .= "Email: {$email}\n";
    $msg .= "Password: (the value you provided)\n";
    $msg .= "You can now login at login.php and access admin.php.\n";
    echo nl2br(htmlspecialchars($msg));
    echo '<p><a href="admin.php">Go to Admin Dashboard</a></p>';
} catch (Throwable $e) {
    error_log('create_admin error: ' . $e->getMessage());
    echo 'Error: ' . htmlspecialchars($e->getMessage());
}
