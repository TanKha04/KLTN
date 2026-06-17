<?php
/**
 * Test trực tiếp API Hugging Face
 */

$apiKey = getenv('HUGGINGFACE_API_KEY') ?: '';

echo "<h2>Test Hugging Face API</h2>";
echo "<p>API Key: " . substr($apiKey, 0, 10) . "...</p>";

// Test 1: GPT-2 (model nhẹ nhất)
echo "<h3>Test 1: GPT-2</h3>";
$data = [
    "inputs" => "Hello, how are you?",
    "options" => ["wait_for_model" => true]
];

$ch = curl_init('https://api-inference.huggingface.co/models/gpt2');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $apiKey
]);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_TIMEOUT, 30);

$result = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

echo "<p><strong>HTTP Code:</strong> $httpCode</p>";
if ($error) {
    echo "<p><strong>cURL Error:</strong> $error</p>";
}
echo "<p><strong>Response:</strong></p>";
echo "<pre>" . htmlspecialchars($result) . "</pre>";

// Test 2: BlenderBot
echo "<hr><h3>Test 2: BlenderBot</h3>";
$ch = curl_init('https://api-inference.huggingface.co/models/facebook/blenderbot-400M-distill');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $apiKey
]);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_TIMEOUT, 30);

$result2 = curl_exec($ch);
$httpCode2 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "<p><strong>HTTP Code:</strong> $httpCode2</p>";
echo "<p><strong>Response:</strong></p>";
echo "<pre>" . htmlspecialchars($result2) . "</pre>";

// Kiểm tra API key validity
echo "<hr><h3>Test 3: Kiểm tra API Key</h3>";
$ch = curl_init('https://huggingface.co/api/whoami-v2');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $apiKey
]);

$whoami = curl_exec($ch);
$httpCode3 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "<p><strong>HTTP Code:</strong> $httpCode3</p>";
echo "<p><strong>User Info:</strong></p>";
echo "<pre>" . htmlspecialchars($whoami) . "</pre>";

if ($httpCode3 === 200) {
    echo "<p style='color: green;'><strong>✅ API Key hợp lệ!</strong></p>";
} else {
    echo "<p style='color: red;'><strong>❌ API Key không hợp lệ hoặc đã hết hạn!</strong></p>";
}
?>
