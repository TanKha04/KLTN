<?php
/**
 * Test AI Chatbot
 * Dùng để kiểm tra xem AI đã hoạt động chưa
 */
require_once 'config.php';

// Kiểm tra API key
$apiKey = getenv('HUGGINGFACE_API_KEY') ?: (defined('HUGGINGFACE_API_KEY') ? HUGGINGFACE_API_KEY : '');
$hasApiKey = !empty($apiKey) && $apiKey !== 'YOUR_API_KEY_HERE';

?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test AI Chatbot - Kết nối Y tế</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 2rem;
        }
        .test-container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            border-radius: 20px;
            padding: 2rem;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            border-radius: 50px;
            font-weight: 600;
            font-size: 1.1rem;
        }
        .status-active {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
        }
        .status-fallback {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: white;
        }
        .test-message {
            background: #f8fafc;
            border-left: 4px solid #3b82f6;
            padding: 1rem;
            margin: 1rem 0;
            border-radius: 8px;
        }
        .response-box {
            background: #f1f5f9;
            padding: 1.5rem;
            border-radius: 12px;
            margin-top: 1rem;
            min-height: 100px;
        }
        .loading {
            display: none;
            text-align: center;
            padding: 2rem;
        }
        .loading.active {
            display: block;
        }
    </style>
</head>
<body>
    <div class="test-container">
        <div class="text-center mb-4">
            <h1>🤖 Test AI Chatbot</h1>
            <p class="text-muted">Kiểm tra trạng thái Trợ lý Y tế AI</p>
        </div>

        <!-- Status -->
        <div class="text-center mb-4">
            <?php if ($hasApiKey): ?>
                <span class="status-badge status-active">
                    <i class="bi bi-check-circle-fill"></i>
                    AI Mode: ACTIVE
                </span>
                <p class="mt-3 text-success">✅ API Key đã được cấu hình</p>
            <?php else: ?>
                <span class="status-badge status-fallback">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    Fallback Mode
                </span>
                <p class="mt-3 text-warning">⚠️ Chưa có API Key - Đang dùng pattern matching</p>
                <div class="alert alert-info mt-3">
                    <strong>Cách kích hoạt AI:</strong><br>
                    1. Lấy API key tại: <a href="https://huggingface.co/settings/tokens" target="_blank">https://huggingface.co/settings/tokens</a><br>
                    2. Thêm vào file <code>config.php</code> dòng 52<br>
                    3. Refresh trang này
                </div>
            <?php endif; ?>
        </div>

        <hr>

        <!-- Test Cases -->
        <h4 class="mb-3">📝 Test Cases</h4>
        
        <div class="test-message">
            <strong>Test 1: Chào hỏi</strong>
            <button class="btn btn-sm btn-primary float-end" onclick="testAI('Xin chào', 1)">
                <i class="bi bi-play-fill"></i> Test
            </button>
            <div class="clearfix"></div>
            <small class="text-muted">Câu hỏi: "Xin chào"</small>
            <div id="response-1" class="response-box" style="display:none;">
                <div class="loading active">
                    <div class="spinner-border text-primary" role="status"></div>
                    <p class="mt-2">Đang chờ AI trả lời...</p>
                </div>
                <div class="result" style="display:none;"></div>
            </div>
        </div>

        <div class="test-message">
            <strong>Test 2: Câu hỏi về hệ thống</strong>
            <button class="btn btn-sm btn-primary float-end" onclick="testAI('Làm sao để đăng tin tuyển dụng sinh viên y khoa?', 2)">
                <i class="bi bi-play-fill"></i> Test
            </button>
            <div class="clearfix"></div>
            <small class="text-muted">Câu hỏi: "Làm sao để đăng tin tuyển dụng sinh viên y khoa?"</small>
            <div id="response-2" class="response-box" style="display:none;">
                <div class="loading active">
                    <div class="spinner-border text-primary" role="status"></div>
                    <p class="mt-2">Đang chờ AI trả lời...</p>
                </div>
                <div class="result" style="display:none;"></div>
            </div>
        </div>

        <div class="test-message">
            <strong>Test 3: Câu hỏi về sức khỏe</strong>
            <button class="btn btn-sm btn-primary float-end" onclick="testAI('Triệu chứng đau đầu và cách xử lý', 3)">
                <i class="bi bi-play-fill"></i> Test
            </button>
            <div class="clearfix"></div>
            <small class="text-muted">Câu hỏi: "Triệu chứng đau đầu và cách xử lý"</small>
            <div id="response-3" class="response-box" style="display:none;">
                <div class="loading active">
                    <div class="spinner-border text-primary" role="status"></div>
                    <p class="mt-2">Đang chờ AI trả lời...</p>
                </div>
                <div class="result" style="display:none;"></div>
            </div>
        </div>

        <div class="test-message">
            <strong>Test 4: Câu hỏi tự do</strong>
            <div class="input-group mt-2">
                <input type="text" id="custom-question" class="form-control" placeholder="Nhập câu hỏi của bạn...">
                <button class="btn btn-primary" onclick="testCustom()">
                    <i class="bi bi-play-fill"></i> Test
                </button>
            </div>
            <div id="response-4" class="response-box" style="display:none;">
                <div class="loading active">
                    <div class="spinner-border text-primary" role="status"></div>
                    <p class="mt-2">Đang chờ AI trả lời...</p>
                </div>
                <div class="result" style="display:none;"></div>
            </div>
        </div>

        <hr>

        <div class="text-center">
            <a href="dashboard_patient.php" class="btn btn-success">
                <i class="bi bi-arrow-left"></i> Quay lại Dashboard
            </a>
            <a href="HUONG_DAN_AI.md" class="btn btn-info" target="_blank">
                <i class="bi bi-book"></i> Xem hướng dẫn
            </a>
        </div>
    </div>

    <script>
        function testAI(question, testId) {
            const responseBox = document.getElementById('response-' + testId);
            const loading = responseBox.querySelector('.loading');
            const result = responseBox.querySelector('.result');
            
            responseBox.style.display = 'block';
            loading.style.display = 'block';
            result.style.display = 'none';
            
            fetch('ai_chat.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ message: question })
            })
            .then(res => res.text())
            .then(aiReply => {
                loading.style.display = 'none';
                result.style.display = 'block';
                result.innerHTML = '<strong>Trả lời:</strong><br>' + aiReply;
            })
            .catch(err => {
                loading.style.display = 'none';
                result.style.display = 'block';
                result.innerHTML = '<span class="text-danger">❌ Lỗi: ' + err.message + '</span>';
            });
        }

        function testCustom() {
            const question = document.getElementById('custom-question').value.trim();
            if (!question) {
                alert('Vui lòng nhập câu hỏi!');
                return;
            }
            testAI(question, 4);
        }
    </script>
</body>
</html>
