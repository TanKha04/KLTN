<?php
/**
 * RESTful API for Students
 * Supports: GET, POST, PUT, DELETE
 * 
 * Endpoints:
 * GET    /api/students.php          - Lấy danh sách sinh viên
 * GET    /api/students.php?id=1     - Lấy thông tin 1 sinh viên
 * POST   /api/students.php          - Tạo sinh viên mới
 * PUT    /api/students.php?id=1     - Cập nhật sinh viên
 * DELETE /api/students.php?id=1     - Xóa sinh viên
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config.php';

$method = $_SERVER['REQUEST_METHOD'];
$id = isset($_GET['id']) ? (int)$_GET['id'] : null;

// Response helper
function jsonResponse($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// Error helper
function jsonError($message, $code = 400) {
    jsonResponse(['success' => false, 'error' => $message], $code);
}

try {
    switch ($method) {
        case 'GET':
            handleGet($pdo, $id);
            break;
        case 'POST':
            handlePost($pdo);
            break;
        case 'PUT':
            handlePut($pdo, $id);
            break;
        case 'DELETE':
            handleDelete($pdo, $id);
            break;
        default:
            jsonError('Method not allowed', 405);
    }
} catch (Exception $e) {
    jsonError('Server error: ' . $e->getMessage(), 500);
}

// GET - Lấy danh sách hoặc 1 sinh viên
function handleGet($pdo, $id) {
    if ($id) {
        // Lấy 1 user theo ID
        $stmt = $pdo->prepare('SELECT id, username, name, email, phone, role, created_at FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $student = $stmt->fetch();
        
        if (!$student) {
            jsonError('User not found', 404);
        }
        
        jsonResponse([
            'success' => true,
            'data' => $student
        ]);
    } else {
        // Lấy danh sách sinh viên
        $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
        $limit = isset($_GET['limit']) ? min(100, max(1, (int)$_GET['limit'])) : 10;
        $offset = ($page - 1) * $limit;
        
        // Đếm tổng
        $countStmt = $pdo->query('SELECT COUNT(*) FROM users WHERE role = "student"');
        $total = (int)$countStmt->fetchColumn();
        
        // Lấy danh sách
        $stmt = $pdo->prepare("SELECT id, name, email, phone, role, created_at FROM users WHERE role = 'student' ORDER BY id DESC LIMIT $limit OFFSET $offset");
        $stmt->execute();
        $students = $stmt->fetchAll();
        
        jsonResponse([
            'success' => true,
            'data' => $students,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'total_pages' => ceil($total / $limit)
            ]
        ]);
    }
}

// POST - Tạo sinh viên mới
function handlePost($pdo) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        jsonError('Invalid JSON data');
    }
    
    // Validate required fields
    $required = ['name', 'email', 'password'];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            jsonError("Field '$field' is required");
        }
    }
    
    // Check email exists
    $checkStmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $checkStmt->execute([$input['email']]);
    if ($checkStmt->fetch()) {
        jsonError('Email already exists', 409);
    }
    
    // Generate username from email
    $username = $input['username'] ?? explode('@', $input['email'])[0];
    
    // Check username exists
    $checkUsername = $pdo->prepare('SELECT id FROM users WHERE username = ?');
    $checkUsername->execute([$username]);
    if ($checkUsername->fetch()) {
        $username = $username . '_' . time();
    }
    
    // Insert new student
    $stmt = $pdo->prepare('INSERT INTO users (username, name, email, password, phone, role, created_at) VALUES (?, ?, ?, ?, ?, "student", NOW())');
    $stmt->execute([
        $username,
        $input['name'],
        $input['email'],
        password_hash($input['password'], PASSWORD_DEFAULT),
        $input['phone'] ?? null
    ]);
    
    $newId = $pdo->lastInsertId();
    
    jsonResponse([
        'success' => true,
        'message' => 'Student created successfully',
        'data' => [
            'id' => (int)$newId,
            'name' => $input['name'],
            'email' => $input['email'],
            'phone' => $input['phone'] ?? null
        ]
    ], 201);
}

// PUT - Cập nhật sinh viên
function handlePut($pdo, $id) {
    if (!$id) {
        jsonError('Student ID is required');
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        jsonError('Invalid JSON data');
    }
    
    // Check student exists
    $checkStmt = $pdo->prepare('SELECT id FROM users WHERE id = ? AND role = "student"');
    $checkStmt->execute([$id]);
    if (!$checkStmt->fetch()) {
        jsonError('Student not found', 404);
    }
    
    // Build update query
    $updates = [];
    $params = [];
    
    if (isset($input['name'])) {
        $updates[] = 'name = ?';
        $params[] = $input['name'];
    }
    if (isset($input['email'])) {
        $updates[] = 'email = ?';
        $params[] = $input['email'];
    }
    if (isset($input['phone'])) {
        $updates[] = 'phone = ?';
        $params[] = $input['phone'];
    }
    if (isset($input['password'])) {
        $updates[] = 'password = ?';
        $params[] = password_hash($input['password'], PASSWORD_DEFAULT);
    }
    
    if (empty($updates)) {
        jsonError('No fields to update');
    }
    
    $params[] = $id;
    $sql = 'UPDATE users SET ' . implode(', ', $updates) . ' WHERE id = ?';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    jsonResponse([
        'success' => true,
        'message' => 'Student updated successfully'
    ]);
}

// DELETE - Xóa sinh viên
function handleDelete($pdo, $id) {
    if (!$id) {
        jsonError('Student ID is required');
    }
    
    // Check student exists
    $checkStmt = $pdo->prepare('SELECT id, name FROM users WHERE id = ? AND role = "student"');
    $checkStmt->execute([$id]);
    $student = $checkStmt->fetch();
    
    if (!$student) {
        jsonError('Student not found', 404);
    }
    
    // Delete student
    $stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
    $stmt->execute([$id]);
    
    jsonResponse([
        'success' => true,
        'message' => "Student '{$student['name']}' deleted successfully"
    ]);
}
