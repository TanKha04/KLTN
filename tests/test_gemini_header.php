<?php
require_once 'config.php';

echo "GEMINI_API_KEY: " . GEMINI_API_KEY . "\n\n";

$model = 'gemini-1.5-flash';
$apiUrl = "https://generativelanguage.googleapis.com/v1beta/models/$model:generateContent";

$payload = [
    'contents' => [
        ['role' => 'user', 'parts' => [['text' => 'Hello, respond with 1 word.']]]
    ]
];

// Test 1: Passing key via header
echo "Test 1: Passing key via 'x-goog-api-key' header...\n";
$ch = curl_init($apiUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'x-goog-api-key: ' . GEMINI_API_KEY
    ],
    CURLOPT_TIMEOUT => 10,
    CURLOPT_SSL_VERIFYPEER => false,
]);

$res = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    echo "  cURL Error: $curlError\n";
} else {
    echo "  HTTP Code: $httpCode\n";
    $data = json_decode($res, true);
    if ($httpCode === 200) {
        echo "  Success! Response: " . ($data['candidates'][0]['content']['parts'][0]['text'] ?? 'empty') . "\n";
    } else {
        echo "  Error: " . ($data['error']['message'] ?? $res) . "\n";
    }
}
echo "--------------------------------------------------\n";

// Test 2: Passing key via Authorization Bearer header
echo "Test 2: Passing key via 'Authorization: Bearer' header...\n";
$ch = curl_init($apiUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . GEMINI_API_KEY
    ],
    CURLOPT_TIMEOUT => 10,
    CURLOPT_SSL_VERIFYPEER => false,
]);

$res = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    echo "  cURL Error: $curlError\n";
} else {
    echo "  HTTP Code: $httpCode\n";
    $data = json_decode($res, true);
    if ($httpCode === 200) {
        echo "  Success! Response: " . ($data['candidates'][0]['content']['parts'][0]['text'] ?? 'empty') . "\n";
    } else {
        echo "  Error: " . ($data['error']['message'] ?? $res) . "\n";
    }
}
echo "--------------------------------------------------\n";
