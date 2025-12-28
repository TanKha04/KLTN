<?php
// Script to add missing columns to posts table
require_once 'config.php';

$columnsToAdd = [
    'contact_info' => "ALTER TABLE posts ADD COLUMN contact_info VARCHAR(255) DEFAULT NULL AFTER area",
    'student_fullname' => "ALTER TABLE posts ADD COLUMN student_fullname VARCHAR(150) DEFAULT NULL AFTER contact_info",
    'student_code' => "ALTER TABLE posts ADD COLUMN student_code VARCHAR(100) DEFAULT NULL AFTER student_fullname",
    'student_class' => "ALTER TABLE posts ADD COLUMN student_class VARCHAR(100) DEFAULT NULL AFTER student_code",
    'recruiter_fullname' => "ALTER TABLE posts ADD COLUMN recruiter_fullname VARCHAR(150) DEFAULT NULL AFTER student_class",
    'suggested_price' => "ALTER TABLE posts ADD COLUMN suggested_price INT DEFAULT NULL AFTER recruiter_fullname",
    'video_path' => "ALTER TABLE posts ADD COLUMN video_path VARCHAR(255) DEFAULT NULL AFTER suggested_price",
    'card_image' => "ALTER TABLE posts ADD COLUMN card_image VARCHAR(255) DEFAULT NULL AFTER video_path"
];

echo "<h2>Adding Missing Columns to Posts Table</h2>";

foreach ($columnsToAdd as $columnName => $alterStatement) {
    try {
        // Check if column exists
        $check = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'posts' AND COLUMN_NAME = ?");
        $check->execute([$columnName]);
        
        if ((int)$check->fetchColumn() === 0) {
            echo "Adding column: <strong>$columnName</strong>...<br>";
            $pdo->exec($alterStatement);
            echo "✓ Column <strong>$columnName</strong> added successfully!<br>";
        } else {
            echo "✓ Column <strong>$columnName</strong> already exists.<br>";
        }
    } catch (Exception $e) {
        echo "✗ Error adding column <strong>$columnName</strong>: " . $e->getMessage() . "<br>";
    }
}

echo "<br><h3>Fix completed!</h3>";
echo "<p><a href='edit_post.php?id=10'>Test edit_post.php?id=10</a></p>";
echo "<p><a href='dashboard_student.php'>Go to Dashboard</a></p>";
?>
