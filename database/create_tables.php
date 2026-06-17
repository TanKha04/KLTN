<?php
// Only allow this script to be run from the command line to avoid accidental
// exposure of SQL or diagnostic output via the webserver.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "This script must be run from the command line.\n";
    exit;
}

require_once 'config.php';

try {
    // Read the SQL file
    $sql = file_get_contents('database.sql');
    
    // Split into individual statements
    $statements = explode(';', $sql);
    
    $success = 0;
    $errors = 0;
    
    foreach ($statements as $statement) {
        $statement = trim($statement);
        if (!empty($statement)) {
            try {
                $pdo->exec($statement);
                $success++;
            } catch (PDOException $e) {
                // Skip errors for tables that already exist
                if (strpos($e->getMessage(), 'already exists') === false && 
                    strpos($e->getMessage(), 'Duplicate key') === false) {
                    echo "Error executing: " . substr($statement, 0, 50) . "...\n";
                    echo "Error: " . $e->getMessage() . "\n\n";
                    $errors++;
                }
            }
        }
    }
    
    // Friendly CLI-only output
    fwrite(STDOUT, "Tạo bảng hoàn tất!\n");
    fwrite(STDOUT, "Thành công: $success câu lệnh\n");
    fwrite(STDOUT, "Lỗi: $errors câu lệnh\n");
    
} catch (Exception $e) {
    echo "Lỗi đọc file SQL: " . $e->getMessage();
}
?>