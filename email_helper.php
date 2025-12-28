<?php

function generate_email_token(PDO $pdo, int $userId, int $validHours = 24): string {
    try {
        $token = bin2hex(random_bytes(32));
    } catch (Throwable $e) {
        $token = bin2hex(openssl_random_pseudo_bytes(32) ?: uniqid('', true));
    }

    $expires = date('Y-m-d H:i:s', time() + ($validHours * 3600));
    $pdo->prepare('DELETE FROM email_verifications WHERE user_id = ?')->execute([$userId]);
    $stmt = $pdo->prepare('INSERT INTO email_verifications (user_id, token, expires_at) VALUES (?, ?, ?)');
    $stmt->execute([$userId, $token, $expires]);

    return $token;
}

function send_verification_email(string $toEmail, string $toName, string $token): bool {
    $verifyUrl = site_url('verify_email.php?token=' . urlencode($token));
    $subject = 'Xác minh tài khoản ' . APP_NAME;
    $body = build_verification_template($toName, $verifyUrl);

    return send_html_email($toEmail, $toName, $subject, $body);
}

function build_verification_template(string $name, string $verifyUrl): string {
    $safeName = htmlspecialchars($name ?: 'bạn');
    $safeUrl = htmlspecialchars($verifyUrl);
    $appName = htmlspecialchars(APP_NAME);
    return <<<HTML
    <!DOCTYPE html>
    <html lang="vi">
    <head>
        <meta charset="UTF-8">
        <title>Xác minh email</title>
    </head>
    <body style="background:#f5f6fb;padding:24px;font-family:'Segoe UI',Arial,sans-serif;color:#1f2937;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;margin:0 auto;background:#ffffff;border-radius:12px;box-shadow:0 10px 30px rgba(15,23,42,0.1);overflow:hidden;">
            <tr>
                <td style="padding:32px 32px 0;">
                    <h2 style="margin:0 0 12px;font-size:22px;color:#0f172a;">Xác thực Email của bạn</h2>
                    <p style="margin:0;font-size:15px;color:#475467;">Chào $safeName,</p>
                    <p style="margin:16px 0 0;font-size:15px;color:#475467;">Vui lòng click vào nút dưới đây để xác thực email của bạn:</p>
                </td>
            </tr>
            <tr>
                <td style="padding:24px 32px 8px;text-align:center;">
                    <a href="$safeUrl" style="display:inline-block;background:#1d4ed8;color:#ffffff;text-decoration:none;padding:12px 32px;border-radius:8px;font-weight:600;">Xác thực Email</a>
                </td>
            </tr>
            <tr>
                <td style="padding:0 32px 24px;text-align:center;">
                    <p style="margin:8px 0 0;font-size:13px;color:#94a3b8;">Link sẽ hết hạn sau 24 giờ.</p>
                    <p style="margin:8px 0 0;font-size:13px;color:#94a3b8;">Nếu nút không hoạt động, copy liên kết sau vào trình duyệt:</p>
                    <p style="margin:8px 0 0;font-size:13px;color:#2563eb;word-break:break-all;"><a href="$safeUrl" style="color:#2563eb;">$safeUrl</a></p>
                </td>
            </tr>
            <tr>
                <td style="padding:0 32px 32px;">
                    <p style="margin:0;font-size:13px;color:#94a3b8;">Nếu bạn không thực hiện yêu cầu này, hãy bỏ qua email.</p>
                    <p style="margin:8px 0 0;font-size:13px;color:#94a3b8;">Trân trọng,<br><strong>Đội ngũ $appName</strong></p>
                </td>
            </tr>
        </table>
    </body>
    </html>
HTML;
}

function send_html_email(string $toEmail, string $toName, string $subject, string $body): bool {
    $fromFormatted = sprintf('%s <%s>', MAIL_FROM_NAME, MAIL_FROM_ADDRESS);
    $headers = [
        'From: ' . $fromFormatted,
        'Reply-To: ' . $fromFormatted,
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit'
    ];

    if (!empty(SMTP_HOST)) {
        $result = smtp_send_mail($toEmail, $toName, $subject, $body, $headers);
        if (!$result) {
            error_log('SMTP send failed, falling back to mail()');
        } else {
            return true;
        }
    }

    if (!function_exists('mail')) {
        error_log('send_html_email: mail() not available. Subject: ' . $subject);
        return false;
    }

    $encodedSubject = encode_header($subject);
    $headerString = implode("\r\n", $headers);
    return @mail($toEmail, $encodedSubject, $body, $headerString);
}

function smtp_send_mail(string $toEmail, string $toName, string $subject, string $body, array $headers): bool {
    $host = SMTP_HOST;
    if (empty($host)) {
        return false;
    }
    $port = SMTP_PORT ?: 587;
    $encryption = strtolower(SMTP_ENCRYPTION ?: 'tls');
    $username = SMTP_USERNAME;
    $password = SMTP_PASSWORD;
    if (!$username || !$password) {
        error_log('SMTP credentials missing');
        return false;
    }

    $remote = ($encryption === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
    $contextOptions = [];
    if ($encryption === 'ssl') {
        $contextOptions['ssl'] = ['verify_peer' => false, 'verify_peer_name' => false];
    }
    $context = stream_context_create($contextOptions);
    $socket = @stream_socket_client($remote, $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $context);
    if (!$socket) {
        error_log('SMTP connection failed: ' . $errstr);
        return false;
    }
    stream_set_timeout($socket, 30);

    $readResponse = function () use ($socket) {
        $data = '';
        while ($line = fgets($socket, 515)) {
            $data .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        return $data;
    };

    $sendCommand = function (string $cmd, array $expectedCodes) use ($socket, $readResponse) {
        if ($cmd !== '') {
            fwrite($socket, $cmd . "\r\n");
        }
        $response = $readResponse();
        $code = (int)substr($response, 0, 3);
        if (!in_array($code, $expectedCodes, true)) {
            throw new RuntimeException('SMTP unexpected response: ' . trim($response));
        }
        return $response;
    };

    try {
        $sendCommand('', [220]);
        $localhost = $_SERVER['SERVER_NAME'] ?? 'localhost';
        $sendCommand('EHLO ' . $localhost, [250]);

        if ($encryption === 'tls') {
            $sendCommand('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('Unable to establish TLS connection');
            }
            $sendCommand('EHLO ' . $localhost, [250]);
        }

        $sendCommand('AUTH LOGIN', [334]);
        $sendCommand(base64_encode($username), [334]);
        $sendCommand(base64_encode($password), [235]);

        $sendCommand('MAIL FROM: <' . $username . '>', [250]);
        $sendCommand('RCPT TO: <' . $toEmail . '>', [250, 251]);
        $sendCommand('DATA', [354]);

        $encodedSubject = encode_header($subject);
        $toHeader = $toName ? sprintf('%s <%s>', $toName, $toEmail) : $toEmail;
        $messageHeaders = array_merge([
            'To: ' . $toHeader,
            'Subject: ' . $encodedSubject
        ], $headers);
        $message = implode("\r\n", $messageHeaders) . "\r\n\r\n" . $body . "\r\n.";
        fwrite($socket, $message . "\r\n");
        $sendCommand('', [250]);

        $sendCommand('QUIT', [221]);
        fclose($socket);
        return true;
    } catch (Throwable $e) {
        error_log('smtp_send_mail error: ' . $e->getMessage());
        if (is_resource($socket)) {
            fclose($socket);
        }
        return false;
    }
}

function encode_header(string $value): string {
    if (!preg_match('/[\x80-\xFF]/', $value)) {
        return $value;
    }
    return '=?UTF-8?B?' . base64_encode($value) . '?=';
}

function issue_email_verification(PDO $pdo, int $userId, string $email, string $name): bool {
    $token = generate_email_token($pdo, $userId);
    return send_verification_email($email, $name, $token);
}

function generate_password_reset_token(PDO $pdo, int $userId, int $validMinutes = 60): string {
    try {
        $token = bin2hex(random_bytes(32));
    } catch (Throwable $e) {
        $token = bin2hex(openssl_random_pseudo_bytes(32) ?: uniqid('', true));
    }

    $expires = date('Y-m-d H:i:s', time() + ($validMinutes * 60));
    $pdo->prepare('DELETE FROM password_resets WHERE user_id = ?')->execute([$userId]);
    $stmt = $pdo->prepare('INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, ?)');
    $stmt->execute([$userId, $token, $expires]);

    return $token;
}

function build_password_reset_template(string $name, string $resetUrl): string {
    $safeName = htmlspecialchars($name ?: 'bạn');
    $safeUrl = htmlspecialchars($resetUrl);
    $appName = htmlspecialchars(APP_NAME);
    return <<<HTML
    <!DOCTYPE html>
    <html lang="vi">
    <head>
        <meta charset="UTF-8">
        <title>Đặt lại mật khẩu</title>
    </head>
    <body style="background:#f5f6fb;padding:24px;font-family:'Segoe UI',Arial,sans-serif;color:#1f2937;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;margin:0 auto;background:#ffffff;border-radius:12px;box-shadow:0 10px 30px rgba(15,23,42,0.1);overflow:hidden;">
            <tr>
                <td style="padding:32px 32px 0;">
                    <h2 style="margin:0 0 12px;font-size:22px;color:#0f172a;">Cài đặt lại mật khẩu</h2>
                    <p style="margin:0;font-size:15px;color:#475467;">Chào $safeName,</p>
                    <p style="margin:16px 0 0;font-size:15px;color:#475467;">Bạn vừa yêu cầu đặt lại mật khẩu cho tài khoản $appName. Nhấp nút dưới đây để tạo mật khẩu mới:</p>
                </td>
            </tr>
            <tr>
                <td style="padding:24px 32px 8px;text-align:center;">
                    <a href="$safeUrl" style="display:inline-block;background:#1d4ed8;color:#ffffff;text-decoration:none;padding:12px 32px;border-radius:8px;font-weight:600;">Đặt lại mật khẩu</a>
                </td>
            </tr>
            <tr>
                <td style="padding:0 32px 24px;text-align:center;">
                    <p style="margin:8px 0 0;font-size:13px;color:#94a3b8;">Liên kết sẽ hết hạn sau 60 phút.</p>
                    <p style="margin:8px 0 0;font-size:13px;color:#94a3b8;">Nếu nút không hoạt động, sao chép liên kết sau:</p>
                    <p style="margin:8px 0 0;font-size:13px;color:#2563eb;word-break:break-all;"><a href="$safeUrl" style="color:#2563eb;">$safeUrl</a></p>
                </td>
            </tr>
            <tr>
                <td style="padding:0 32px 32px;">
                    <p style="margin:0;font-size:13px;color:#94a3b8;">Nếu bạn không yêu cầu đổi mật khẩu, hãy bỏ qua email này.</p>
                    <p style="margin:8px 0 0;font-size:13px;color:#94a3b8;">Trân trọng,<br><strong>Đội ngũ $appName</strong></p>
                </td>
            </tr>
        </table>
    </body>
    </html>
HTML;
}

function send_password_reset_email(string $toEmail, string $toName, string $token): bool {
    $resetUrl = site_url('reset_password.php?token=' . urlencode($token));
    $subject = 'Đặt lại mật khẩu ' . APP_NAME;
    $body = build_password_reset_template($toName, $resetUrl);
    return send_html_email($toEmail, $toName, $subject, $body);
}

function issue_password_reset(PDO $pdo, int $userId, string $email, string $name): bool {
    $token = generate_password_reset_token($pdo, $userId);
    return send_password_reset_email($email, $name, $token);
}
