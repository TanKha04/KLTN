<?php
// Script to add fullname column to users table
require_once 'config.php';

try {
    // Check if fullname column exists
    $check = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'fullname'");
    $check->execute();
    
    if ((int)$check->fetchColumn() === 0) {
        echo "Adding fullname column to users table...<br>";
        $pdo->exec("ALTER TABLE users ADD COLUMN fullname VARCHAR(150) DEFAULT NULL AFTER name");
        echo "Column added successfully!<br>";
        
        // Sync existing data
        echo "Syncing existing data...<br>";
        $pdo->exec("UPDATE users SET fullname = name WHERE fullname IS NULL OR fullname = ''");
        echo "Data synced successfully!<br>";
    } else {
        echo "fullname column already exists.<br>";
        
        // Sync data anyway
        echo "Syncing data...<br>";
        $pdo->exec("UPDATE users SET fullname = name WHERE fullname IS NULL OR fullname = ''");
        echo "Data synced!<br>";
    }
    
    echo "<br><strong>Fix completed! Please try accessing edit_post.php again.</strong>";
    echo "<br><a href='edit_post.php?id=10'>Test edit_post.php?id=10</a>";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
