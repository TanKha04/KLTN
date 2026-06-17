<?php
/**
 * RESTful API for Posts (Bài đăng)
 * Supports: GET, POST, PUT, DELETE
 * 
 * Endpoints:
 * GET    /api/posts.php              - Lấy danh sách bài đăng
 * GET    /api/posts.php?id=1         - Lấy thông tin 1 bài đăng
 * POST   /api/posts.php              - Tạo bài đăng mới
 * PUT    /api/posts.php?id=1         - Cập nhật bài đăng
 * DELETE /api/posts.php?id=1         - Xóa bài đăng
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config.php';

$method = $_SERVER['REQUEST_METHOD'];
$id = isset($_GET['id']) ? (int)$_GET['id'] : null;

function jsonResponse($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

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

// GET - Lấy danh sách hoặc 1 bài đăng
function handleGet($pdo, $id) {
    if ($id) {
        $stmt = $pdo->prepare('
            SELECT p.*, u.name as author_name, u.username as author_username
            FROM posts p 
            JOIN users u ON p.user_id = u.id 
            WHERE p.id = ?
        ');
        $stmt->execute([$id]);
        $post = $stmt->fetch();
        
        if (!$post) {
            jsonError('Post not found', 404);
        }
        
        jsonResponse(['success' => true, 'data' => $post]);
    } else {
        $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
        $limit = isset($_GET['limit']) ? min(100, max(1, (int)$_GET['limit'])) : 10;
        $offset = ($page - 1) * $limit;
        
        // Filter by status, type, category
        $where = '1=1';
        $params = [];
        
        if (!empty($_GET['status'])) {
            $where .= ' AND p.status = ?';
            $params[] = $_GET['status'];
        }
        if (!empty($_GET['type'])) {
            $where .= ' AND p.type = ?';
            $params[] = $_GET['type'];
        }
        if (!empty($_GET['category'])) {
            $where .= ' AND p.category = ?';
            $params[] = $_GET['category'];
        }
        
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM posts p WHERE $where");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();
        
        $stmt = $pdo->prepare("
            SELECT p.*, u.name as author_name, u.username as author_username
            FROM posts p 
            JOIN users u ON p.user_id = u.id 
            WHERE $where 
            ORDER BY p.created_at DESC 
            LIMIT $limit OFFSET $offset
        ");
        $stmt->execute($params);
        $posts = $stmt->fetchAll();
        
        jsonResponse([
            'success' => true,
            'data' => $posts,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'total_pages' => ceil($total / $limit)
            ]
        ]);
    }
}

// POST - Tạo bài đăng mới
function handlePost($pdo) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        jsonError('Invalid JSON data');
    }
    
    $required = ['user_id', 'title', 'content'];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            jsonError("Field '$field' is required");
        }
    }
    
    // Check user exists
    $checkUser = $pdo->prepare('SELECT id FROM users WHERE id = ?');
    $checkUser->execute([$input['user_id']]);
    if (!$checkUser->fetch()) {
        jsonError('User not found', 404);
    }
    
    $stmt = $pdo->prepare('
        INSERT INTO posts (user_id, title, content, type, category, area, status, created_at) 
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
    ');
    $stmt->execute([
        $input['user_id'],
        $input['title'],
        $input['content'],
        $input['type'] ?? 'recruitment',
        $input['category'] ?? null,
        $input['area'] ?? null,
        $input['status'] ?? 'open'
    ]);
    
    $newId = $pdo->lastInsertId();
    
    jsonResponse([
        'success' => true,
        'message' => 'Post created successfully',
        'data' => ['id' => (int)$newId]
    ], 201);
}

// PUT - Cập nhật bài đăng
function handlePut($pdo, $id) {
    if (!$id) {
        jsonError('Post ID is required');
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        jsonError('Invalid JSON data');
    }
    
    $checkStmt = $pdo->prepare('SELECT id FROM posts WHERE id = ?');
    $checkStmt->execute([$id]);
    if (!$checkStmt->fetch()) {
        jsonError('Post not found', 404);
    }
    
    $updates = [];
    $params = [];
    
    $fields = ['title', 'content', 'type', 'category', 'area', 'status'];
    foreach ($fields as $field) {
        if (isset($input[$field])) {
            $updates[] = "$field = ?";
            $params[] = $input[$field];
        }
    }
    
    if (empty($updates)) {
        jsonError('No fields to update');
    }
    
    $params[] = $id;
    $sql = 'UPDATE posts SET ' . implode(', ', $updates) . ' WHERE id = ?';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    jsonResponse(['success' => true, 'message' => 'Post updated successfully']);
}

// DELETE - Xóa bài đăng
function handleDelete($pdo, $id) {
    if (!$id) {
        jsonError('Post ID is required');
    }
    
    $checkStmt = $pdo->prepare('SELECT id, title FROM posts WHERE id = ?');
    $checkStmt->execute([$id]);
    $post = $checkStmt->fetch();
    
    if (!$post) {
        jsonError('Post not found', 404);
    }
    
    $stmt = $pdo->prepare('DELETE FROM posts WHERE id = ?');
    $stmt->execute([$id]);
    
    jsonResponse(['success' => true, 'message' => "Post '{$post['title']}' deleted successfully"]);
}
