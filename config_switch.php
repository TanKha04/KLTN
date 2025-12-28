<?php
// config_switch.php - Tự động chọn config phù hợp

// Kiểm tra xem có đang chạy trong Docker không
function isRunningInDocker() {
    // Kiểm tra biến môi trường Docker
    if (getenv('DOCKER_CONTAINER') === 'true') {
        return true;
    }
    
    // Kiểm tra file /.dockerenv
    if (file_exists('/.dockerenv')) {
        return true;
    }
    
    // Kiểm tra hostname Docker (container ID pattern)
    $hostname = gethostname();
    if ($hostname !== false && preg_match('/^[a-f0-9]{12}$/', $hostname)) {
        return true;
    }
    
    // Kiểm tra xem có thể kết nối với Docker service 'db' không
    $dbHost = gethostbyname('db');
    if ($dbHost !== 'db' && filter_var($dbHost, FILTER_VALIDATE_IP)) {
        return true;
    }
    
    // Kiểm tra port 80 (Apache trong container)
    if (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == '80' && 
        isset($_SERVER['HTTP_HOST']) && strpos($_SERVER['HTTP_HOST'], 'localhost:8080') !== false) {
        return true;
    }
    
    return false;
}

// Include config phù hợp
if (isRunningInDocker()) {
    require_once 'config.docker.php';
} else {
    require_once 'config.php';
}
?>