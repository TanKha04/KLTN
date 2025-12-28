<?php
require_once 'config.php';

if (!FACEBOOK_APP_ID || !FACEBOOK_APP_SECRET) {
    require_once 'header.php';
    echo '<div class="container py-5"><div class="alert alert-danger">Facebook Login chưa được cấu hình.</div></div>';
    require_once 'footer.php';
    exit;
}

function render_facebook_error($message) {
    require_once 'header.php';
    echo '<div class="container py-5"><div class="alert alert-danger">' . htmlspecialchars($message) . '</div><a class="btn btn-primary mt-3" href="login.php">Quay lại đăng nhập</a></div>';
    require_once 'footer.php';
    exit;
}

function facebook_http_get($baseUrl, array $params) {
    $url = $baseUrl . '?' . http_build_query($params);
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $response = curl_exec($ch);
        if ($response === false) {
            error_log('facebook_http_get curl error: ' . curl_error($ch));
        }
        curl_close($ch);
        return $response ?: '';
    }
    return @file_get_contents($url)
        ?: '';
}

$state = $_GET['state'] ?? '';
if (empty($state) || empty($_SESSION['fb_oauth_state']) || !hash_equals($_SESSION['fb_oauth_state'], $state)) {
    render_facebook_error('Phiên đăng nhập Facebook không hợp lệ. Vui lòng thử lại.');
}
unset($_SESSION['fb_oauth_state']);

$code = $_GET['code'] ?? '';
if ($code === '') {
    render_facebook_error('Không nhận được mã xác thực từ Facebook.');
}

$redirectUri = site_url('facebook_callback.php');
$tokenResponse = facebook_http_get('https://graph.facebook.com/v18.0/oauth/access_token', [
    'client_id' => FACEBOOK_APP_ID,
    'redirect_uri' => $redirectUri,
    'client_secret' => FACEBOOK_APP_SECRET,
    'code' => $code,
]);
if (!$tokenResponse) {
    render_facebook_error('Không thể kết nối tới Facebook.');
}
$tokenData = json_decode($tokenResponse, true);
if (!is_array($tokenData) || empty($tokenData['access_token'])) {
    render_facebook_error('Lấy access token từ Facebook thất bại.');
}

$userResponse = facebook_http_get('https://graph.facebook.com/me', [
    'fields' => 'id,name,email',
    'access_token' => $tokenData['access_token'],
]);
if (!$userResponse) {
    render_facebook_error('Không thể lấy thông tin người dùng Facebook.');
}
$userData = json_decode($userResponse, true);
if (!is_array($userData) || empty($userData['id'])) {
    render_facebook_error('Thông tin người dùng Facebook không hợp lệ.');
}

$facebookId = $userData['id'];
$name = $userData['name'] ?? 'Facebook User';
$email = $userData['email'] ?? ($facebookId . '@facebook.local');

try {
    $pdo->beginTransaction();

    // 1. Tìm theo facebook_id trước
    $stmt = $pdo->prepare('SELECT * FROM users WHERE facebook_id = ? LIMIT 1');
    $stmt->execute([$facebookId]);
    $user = $stmt->fetch();

    // 2. Nếu không tìm thấy, tìm theo email
    if (!$user && !empty($email) && strpos($email, '@facebook.local') === false) {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        if ($user) {
            // Liên kết tài khoản cũ với Facebook
            $upd = $pdo->prepare('UPDATE users SET facebook_id = ?, email_verified = 1 WHERE id = ?');
            $upd->execute([$facebookId, $user['id']]);
            $user['facebook_id'] = $facebookId;
            $user['email_verified'] = 1;
        }
    }
    
    // 3. Nếu không tìm thấy, tìm theo tên (trường hợp email FB khác email đăng ký)
    if (!$user) {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE name = ? AND facebook_id IS NULL LIMIT 1');
        $stmt->execute([$name]);
        $user = $stmt->fetch();
        if ($user) {
            // Liên kết tài khoản cũ với Facebook
            $upd = $pdo->prepare('UPDATE users SET facebook_id = ?, email_verified = 1 WHERE id = ?');
            $upd->execute([$facebookId, $user['id']]);
            $user['facebook_id'] = $facebookId;
            $user['email_verified'] = 1;
        }
    }

    // 4. Nếu vẫn không có, tạo tài khoản mới
    if (!$user) {
        $password = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
        $role = 'patient';
        $canPost = 1;
        $stmt = $pdo->prepare('INSERT INTO users (name,email,password,role,can_post,email_verified,facebook_id) VALUES (?,?,?,?,?,?,?)');
        $stmt->execute([$name, $email, $password, $role, $canPost, 1, $facebookId]);
        $userId = (int)$pdo->lastInsertId();
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('facebook_callback error: ' . $e->getMessage());
    render_facebook_error('Có lỗi xảy ra khi xử lý đăng nhập Facebook.');
}

if (empty($user)) {
    render_facebook_error('Không thể đăng nhập bằng Facebook.');
}

$_SESSION['user_id'] = $user['id'];
$_SESSION['role'] = $user['role'];
$_SESSION['name'] = $user['name'];
$_SESSION['email'] = $user['email'];
$_SESSION['is_admin'] = !empty($user['is_admin']) ? 1 : 0;
$_SESSION['verified'] = !empty($user['verified']) ? 1 : 0;

$pdo->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')->execute([$user['id']]);

if (!empty($_SESSION['is_admin'])) {
    header('Location: admin.php');
    exit;
}

if ($user['role'] === 'patient') {
    header('Location: dashboard_patient.php');
} else {
    header('Location: dashboard_student.php');
}
exit;
