<?php
// We will temporarily test real Gmail SMTP sending
$host = 'smtp.gmail.com';
$port = 587;
$encryption = 'tls';
$username = 'tramtankhatv@gmail.com';
$password = 'bghf tohu ppff vkea';

$toEmail = 'tramtankhatv@gmail.com'; // Send to self for testing
$toName = 'Test User';
$subject = 'Test Gmail SMTP from Docker';
$body = 'This is a test email sent from the Docker container to verify Gmail SMTP.';

$fromFormatted = sprintf('%s <%s>', 'Kết nối Y tế', $username);
$headers = [
    'From: ' . $fromFormatted,
    'Reply-To: ' . $fromFormatted,
    'MIME-Version: 1.0',
    'Content-Type: text/html; charset=UTF-8',
    'Content-Transfer-Encoding: 8bit'
];

echo "Connecting to $host:$port...\n";
$remote = $host . ':' . $port;
$context = stream_context_create();
$socket = @stream_socket_client($remote, $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $context);
if (!$socket) {
    echo "Connection failed: $errstr ($errno)\n";
    exit;
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
        echo "SENT: $cmd\n";
    }
    $response = $readResponse();
    echo "RCVD: " . trim($response) . "\n";
    $code = (int)substr($response, 0, 3);
    if (!in_array($code, $expectedCodes, true)) {
        throw new RuntimeException('SMTP unexpected response: ' . trim($response));
    }
    return $response;
};

try {
    $sendCommand('', [220]);
    $sendCommand('EHLO localhost', [250]);

    if ($encryption === 'tls') {
        $sendCommand('STARTTLS', [220]);
        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            throw new RuntimeException('Unable to establish TLS connection');
        }
        $sendCommand('EHLO localhost', [250]);
    }

    $sendCommand('AUTH LOGIN', [334]);
    $sendCommand(base64_encode($username), [334]);
    $sendCommand(base64_encode($password), [235]);

    $sendCommand('MAIL FROM: <' . $username . '>', [250]);
    $sendCommand('RCPT TO: <' . $toEmail . '>', [250, 251]);
    $sendCommand('DATA', [354]);

    $toHeader = $toName ? sprintf('%s <%s>', $toName, $toEmail) : $toEmail;
    $messageHeaders = array_merge([
        'To: ' . $toHeader,
        'Subject: =?UTF-8?B?' . base64_encode($subject) . '?='
    ], $headers);
    $message = implode("\r\n", $messageHeaders) . "\r\n\r\n" . $body . "\r\n.";
    fwrite($socket, $message . "\r\n");
    $sendCommand('', [250]);

    $sendCommand('QUIT', [221]);
    fclose($socket);
    echo "SUCCESS: Email sent!\n";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    if (is_resource($socket)) {
        fclose($socket);
    }
}
?>
