<?php
// test_docker.php - Test Docker database connection

try {
    $pdo = new PDO('mysql:host=db;dbname=student_platform;charset=utf8mb4', 'dbuser', 'dbpassword', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    
    echo "<h2>✅ Kết nối database thành công!</h2>";
    echo "<p>Host: db</p>";
    echo "<p>Database: student_platform</p>";
    echo "<p>User: dbuser</p>";
    
    // Test query
    $stmt = $pdo->query("SELECT VERSION() as version");
    $result = $stmt->fetch();
    echo "<p>MySQL Version: " . $result['version'] . "</p>";
    
} catch (Exception $e) {
    echo "<h2>❌ Lỗi kết nối database:</h2>";
    echo "<p>" . $e->getMessage() . "</p>";
}

echo "<hr>";
echo "<h3>Thông tin môi trường:</h3>";
echo "<p>PHP Version: " . phpversion() . "</p>";
echo "<p>Server: " . $_SERVER['SERVER_SOFTWARE'] . "</p>";
echo "<p>Document Root: " . $_SERVER['DOCUMENT_ROOT'] . "</p>";
?>