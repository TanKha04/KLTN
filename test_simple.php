<?php
echo "<h2>Test Docker Connection</h2>";

// Test 1: Basic info
echo "<h3>1. Server Info:</h3>";
echo "PHP Version: " . phpversion() . "<br>";
echo "Server: " . ($_SERVER['SERVER_SOFTWARE'] ?? 'Unknown') . "<br>";
echo "Host: " . ($_SERVER['HTTP_HOST'] ?? 'Unknown') . "<br>";

// Test 2: Environment
echo "<h3>2. Environment:</h3>";
echo "DOCKER_CONTAINER: " . (getenv('DOCKER_CONTAINER') ?: 'Not set') . "<br>";
echo "/.dockerenv exists: " . (file_exists('/.dockerenv') ? 'Yes' : 'No') . "<br>";

// Test 3: Database connection
echo "<h3>3. Database Connection:</h3>";
try {
    $pdo = new PDO('mysql:host=db;dbname=dacn1_db;charset=utf8mb4', 'root', 'rootpassword', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    
    echo "✅ Connected successfully!<br>";
    
    // Test query
    $stmt = $pdo->query("SELECT VERSION() as version");
    $result = $stmt->fetch();
    echo "MySQL Version: " . $result['version'] . "<br>";
    
    // Test database
    $stmt = $pdo->query("SELECT DATABASE() as db_name");
    $result = $stmt->fetch();
    echo "Current Database: " . $result['db_name'] . "<br>";
    
    // Show tables
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll();
    echo "Tables: " . count($tables) . "<br>";
    foreach($tables as $table) {
        echo "- " . array_values($table)[0] . "<br>";
    }
    
} catch (Exception $e) {
    echo "❌ Connection failed: " . $e->getMessage() . "<br>";
    
    // Try to ping host
    echo "<br><strong>Debug info:</strong><br>";
    echo "Trying to resolve 'db' host: " . gethostbyname('db') . "<br>";
}
?>