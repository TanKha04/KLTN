<?php
echo "Server is working!";
echo "<br>PHP Version: " . phpversion();
echo "<br>Current Time: " . date('Y-m-d H:i:s');
echo "<br>Server Info: " . $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown';
?>

<!DOCTYPE html>
<html>
<head>
    <title>Server Test</title>
    <meta charset="UTF-8">
</head>
<body>
    <h1>Server Test Page</h1>
    <p>If you can see this, the server is working correctly.</p>
    
    <h2>Test Links:</h2>
    <ul>
        <li><a href="index.php">Homepage</a></li>
        <li><a href="login.php">Login Page</a></li>
        <li><a href="test_qr.php">QR Test</a></li>
    </ul>
</body>
</html>
