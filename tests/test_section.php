<?php
// Test file để kiểm tra section
require_once 'config.php';
require_login();
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test Section</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        body { background: #f1f5f9; padding: 2rem; }
        .section-btn { margin: 0.5rem; padding: 1rem 2rem; }
        .section-content { margin-top: 2rem; background: #fff; border-radius: 12px; min-height: 400px; }
        .dashboard-section { display: none; padding: 1rem; }
        .dashboard-section.active { display: block; }
    </style>
</head>
<body>
    <h2>Test Dashboard Sections</h2>
    
    <div class="btn-group">
        <button class="btn btn-primary section-btn" onclick="showTest('messages')">Tin nhắn</button>
        <button class="btn btn-success section-btn" onclick="showTest('verify')">Xác minh</button>
        <button class="btn btn-info section-btn" onclick="showTest('profile')">Hồ sơ</button>
    </div>
    
    <div class="section-content">
        <div class="dashboard-section" id="section-messages">
            <h4>Section Tin nhắn</h4>
            <iframe src="view_messages.php?embed=1" style="width:100%;height:500px;border:1px solid #ddd;border-radius:8px;"></iframe>
        </div>
        
        <div class="dashboard-section" id="section-verify">
            <h4>Section Xác minh</h4>
            <iframe src="request_verification.php?embed=1" style="width:100%;height:500px;border:1px solid #ddd;border-radius:8px;"></iframe>
        </div>
        
        <div class="dashboard-section" id="section-profile">
            <h4>Section Hồ sơ</h4>
            <iframe src="edit_profile.php?embed=1" style="width:100%;height:500px;border:1px solid #ddd;border-radius:8px;"></iframe>
        </div>
    </div>
    
    <script>
    function showTest(sectionId) {
        // Ẩn tất cả
        document.querySelectorAll('.dashboard-section').forEach(function(el) {
            el.classList.remove('active');
        });
        // Hiện section được chọn
        var section = document.getElementById('section-' + sectionId);
        if (section) {
            section.classList.add('active');
        } else {
            alert('Không tìm thấy: section-' + sectionId);
        }
    }
    </script>
</body>
</html>
