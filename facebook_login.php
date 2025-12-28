<?php
require_once 'config.php';

if (!FACEBOOK_APP_ID || !FACEBOOK_APP_SECRET) {
    require_once 'header.php';
    echo '<div class="container py-5"><div class="alert alert-warning">Facebook Login chưa được cấu hình. Vui lòng thêm FACEBOOK_APP_ID và FACEBOOK_APP_SECRET vào config.</div></div>';
    require_once 'footer.php';
    exit;
}

try {
    $state = bin2hex(random_bytes(24));
} catch (Throwable $e) {
    $state = bin2hex(uniqid('fb', true));
}
$_SESSION['fb_oauth_state'] = $state;

$redirectUri = site_url('facebook_callback.php');
$params = [
    'client_id' => FACEBOOK_APP_ID,
    'redirect_uri' => $redirectUri,
    'state' => $state,
    'scope' => 'email',
    'response_type' => 'code',
    'auth_type' => 'rerequest'
];

header('Location: https://www.facebook.com/v18.0/dialog/oauth?' . http_build_query($params));
exit;
