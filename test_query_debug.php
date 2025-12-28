<?php
// File debug để tìm lỗi fullname
require_once 'config.php';

echo "<h2>Debug Database Structure</h2>";

// Check users table structure
try {
    $stmt = $pdo->query("DESCRIBE users");
    echo "<h3>Users Table Structure:</h3><pre>";
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        print_r($row);
    }
    echo "</pre>";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}

// Check posts table structure
try {
    $stmt = $pdo->query("DESCRIBE posts");
    echo "<h3>Posts Table Structure:</h3><pre>";
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        print_r($row);
    }
    echo "</pre>";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}

// Try to find any cached queries
echo "<h3>OPcache Status:</h3><pre>";
if (function_exists('opcache_get_status')) {
    print_r(opcache_get_status());
} else {
    echo "OPcache not available";
}
echo "</pre>";
?>
