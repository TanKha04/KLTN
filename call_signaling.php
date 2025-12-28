<?php
require_once 'config.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$currentUserId = $_SESSION['user_id'];
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// Đảm bảo bảng call_signals tồn tại
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `call_signals` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `caller_id` INT NOT NULL,
        `callee_id` INT NOT NULL,
        `signal_type` VARCHAR(50) NOT NULL,
        `signal_data` TEXT,
        `status` ENUM('pending','answered','rejected','ended') DEFAULT 'pending',
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_caller (caller_id),
        INDEX idx_callee (callee_id),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {
    // Table might already exist
}

switch ($action) {
    case 'initiate_call':
        // Bắt đầu cuộc gọi
        $calleeId = (int)($_POST['callee_id'] ?? 0);
        if ($calleeId <= 0 || $calleeId == $currentUserId) {
            echo json_encode(['error' => 'Invalid callee']);
            exit;
        }
        
        // Xóa các cuộc gọi cũ
        $pdo->prepare('DELETE FROM call_signals WHERE (caller_id = ? OR callee_id = ?) AND status IN ("pending", "ended")')->execute([$currentUserId, $currentUserId]);
        
        // Tạo cuộc gọi mới
        $stmt = $pdo->prepare('INSERT INTO call_signals (caller_id, callee_id, signal_type, status) VALUES (?, ?, "call_request", "pending")');
        $stmt->execute([$currentUserId, $calleeId]);
        $callId = $pdo->lastInsertId();
        
        echo json_encode(['success' => true, 'call_id' => $callId]);
        break;
        
    case 'check_incoming':
        // Kiểm tra có cuộc gọi đến không
        $stmt = $pdo->prepare('SELECT cs.*, u.name AS caller_name, u.avatar AS caller_avatar 
            FROM call_signals cs 
            JOIN users u ON u.id = cs.caller_id 
            WHERE cs.callee_id = ? AND cs.status = "pending" AND cs.signal_type = "call_request"
            ORDER BY cs.created_at DESC LIMIT 1');
        $stmt->execute([$currentUserId]);
        $call = $stmt->fetch();
        
        if ($call) {
            echo json_encode([
                'incoming' => true,
                'call_id' => $call['id'],
                'caller_id' => $call['caller_id'],
                'caller_name' => $call['caller_name'],
                'caller_avatar' => $call['caller_avatar']
            ]);
        } else {
            echo json_encode(['incoming' => false]);
        }
        break;
        
    case 'answer_call':
        // Trả lời cuộc gọi
        $callId = (int)($_POST['call_id'] ?? 0);
        $stmt = $pdo->prepare('UPDATE call_signals SET status = "answered" WHERE id = ? AND callee_id = ?');
        $stmt->execute([$callId, $currentUserId]);
        echo json_encode(['success' => true]);
        break;
        
    case 'reject_call':
        // Từ chối cuộc gọi
        $callId = (int)($_POST['call_id'] ?? 0);
        $stmt = $pdo->prepare('UPDATE call_signals SET status = "rejected" WHERE id = ? AND callee_id = ?');
        $stmt->execute([$callId, $currentUserId]);
        echo json_encode(['success' => true]);
        break;
        
    case 'end_call':
        // Kết thúc cuộc gọi
        $callId = (int)($_POST['call_id'] ?? 0);
        $stmt = $pdo->prepare('UPDATE call_signals SET status = "ended" WHERE id = ? AND (caller_id = ? OR callee_id = ?)');
        $stmt->execute([$callId, $currentUserId, $currentUserId]);
        echo json_encode(['success' => true]);
        break;
        
    case 'check_status':
        // Kiểm tra trạng thái cuộc gọi
        $callId = (int)($_GET['call_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT status FROM call_signals WHERE id = ? AND (caller_id = ? OR callee_id = ?)');
        $stmt->execute([$callId, $currentUserId, $currentUserId]);
        $call = $stmt->fetch();
        
        if ($call) {
            echo json_encode(['status' => $call['status']]);
        } else {
            echo json_encode(['status' => 'not_found']);
        }
        break;
        
    case 'send_signal':
        // Gửi WebRTC signal (offer, answer, ice candidate)
        $callId = (int)($_POST['call_id'] ?? 0);
        $signalType = $_POST['signal_type'] ?? '';
        $signalData = $_POST['signal_data'] ?? '';
        
        // Lấy thông tin cuộc gọi gốc để xác định người nhận
        $stmt = $pdo->prepare('SELECT caller_id, callee_id FROM call_signals WHERE id = ?');
        $stmt->execute([$callId]);
        $originalCall = $stmt->fetch();
        
        if ($originalCall) {
            // Xác định người nhận signal (người còn lại trong cuộc gọi)
            $receiverId = ($originalCall['caller_id'] == $currentUserId) ? $originalCall['callee_id'] : $originalCall['caller_id'];
            
            $stmt = $pdo->prepare('INSERT INTO call_signals (caller_id, callee_id, signal_type, signal_data, status) VALUES (?, ?, ?, ?, "pending")');
            $stmt->execute([$currentUserId, $receiverId, $signalType, $signalData]);
        }
        
        echo json_encode(['success' => true]);
        break;
        
    case 'get_signals':
        // Lấy các signals mới
        $callId = (int)($_GET['call_id'] ?? 0);
        $lastId = (int)($_GET['last_id'] ?? 0);
        
        // Lấy signals gửi đến user hiện tại (callee_id = currentUserId)
        $stmt = $pdo->prepare('SELECT id, signal_type, signal_data FROM call_signals 
            WHERE callee_id = ? AND id > ? AND signal_type IN ("offer", "answer", "ice_candidate")
            ORDER BY id ASC');
        $stmt->execute([$currentUserId, $lastId]);
        $signals = $stmt->fetchAll();
        
        echo json_encode(['signals' => $signals]);
        break;
        
    default:
        echo json_encode(['error' => 'Invalid action']);
}
