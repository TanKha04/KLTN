<?php
/**
 * ChatBot Widget for Patient Dashboard
 * Provides automated responses for common healthcare questions
 */

// Predefined responses for the chatbot
$chatbot_responses = [
    'greeting' => [
        'keywords' => ['xin chào', 'hello', 'hi', 'chào', 'hey'],
        'response' => 'Xin chào! Tôi là trợ lý ảo của hệ thống Kết nối Y tế. Tôi có thể giúp bạn với các câu hỏi về dịch vụ chăm sóc sức khỏe, cách đăng tin tuyển dụng, hoặc tìm sinh viên y khoa phù hợp. Bạn cần hỗ trợ gì?'
    ],
    'post_help' => [
        'keywords' => ['đăng tin', 'tạo tin', 'đăng bài', 'tuyển dụng', 'tìm người'],
        'response' => 'Để đăng tin tuyển dụng sinh viên y khoa:\n1. Nhấn nút "Tạo tin tuyển dụng" trên dashboard\n2. Điền đầy đủ thông tin: tiêu đề, mô tả công việc, khu vực, mức lương đề xuất\n3. Nhấn "Đăng tin"\n\nSau khi đăng, sinh viên sẽ có thể xem và liên hệ với bạn.'
    ],
    'find_student' => [
        'keywords' => ['tìm sinh viên', 'sinh viên y', 'chăm sóc', 'hỗ trợ y tế'],
        'response' => 'Để tìm sinh viên y khoa phù hợp:\n1. Xem danh sách "Tin ứng tuyển" trên trang chủ\n2. Lọc theo khu vực và chuyên khoa\n3. Xem hồ sơ và đánh giá của sinh viên\n4. Nhấn "Liên hệ" để nhắn tin trực tiếp\n\nBạn cũng có thể đăng tin tuyển dụng để sinh viên chủ động liên hệ.'
    ],
    'payment' => [
        'keywords' => ['thanh toán', 'giá', 'chi phí', 'phí', 'tiền', 'trả'],
        'response' => 'Hệ thống Kết nối Y tế hoàn toàn MIỄN PHÍ cho việc đăng tin và kết nối. Về chi phí dịch vụ chăm sóc, bạn và sinh viên sẽ tự thỏa thuận trực tiếp. Chúng tôi khuyến nghị thảo luận rõ ràng về mức thù lao trước khi bắt đầu hợp tác.'
    ],
    'safety' => [
        'keywords' => ['an toàn', 'xác minh', 'tin cậy', 'uy tín', 'lừa đảo'],
        'response' => 'Để đảm bảo an toàn:\n✅ Chỉ liên hệ với sinh viên đã được XÁC MINH (có dấu tick xanh)\n✅ Kiểm tra đánh giá và nhận xét từ người dùng khác\n✅ Trao đổi qua hệ thống tin nhắn trước khi gặp mặt\n✅ Báo cáo ngay nếu phát hiện hành vi đáng ngờ\n\nNếu gặp vấn đề, hãy liên hệ bộ phận hỗ trợ.'
    ],
    'contact' => [
        'keywords' => ['liên hệ', 'hỗ trợ', 'support', 'giúp đỡ', 'admin'],
        'response' => 'Bạn có thể liên hệ hỗ trợ qua:\n📧 Email: tramtankhatv@gmail.com\n💬 Tin nhắn hệ thống: Vào mục "Thông báo" để xem tin từ Admin\n📝 Gửi yêu cầu: Vào trang "Hỗ trợ" để gửi phản hồi\n\nChúng tôi sẽ phản hồi trong vòng 24 giờ làm việc.'
    ],
    'rating' => [
        'keywords' => ['đánh giá', 'review', 'nhận xét', 'sao', 'rating'],
        'response' => 'Hệ thống đánh giá giúp xây dựng uy tín:\n⭐ Sau khi hoàn thành dịch vụ, bạn có thể đánh giá sinh viên\n⭐ Đánh giá từ 1-5 sao kèm nhận xét\n⭐ Đánh giá tốt giúp sinh viên được nhiều người tin tưởng\n\nHãy đánh giá công bằng để giúp cộng đồng!'
    ],
    'message' => [
        'keywords' => ['tin nhắn', 'nhắn tin', 'chat', 'trò chuyện', 'message'],
        'response' => 'Để nhắn tin với sinh viên:\n1. Vào trang hồ sơ của sinh viên\n2. Nhấn nút "Nhắn tin"\n3. Hoặc vào mục "Tin nhắn" trên menu để xem tất cả cuộc trò chuyện\n\nTin nhắn được lưu trữ an toàn trên hệ thống.'
    ],
    'default' => [
        'response' => 'Xin lỗi, tôi chưa hiểu câu hỏi của bạn. Bạn có thể hỏi về:\n• Cách đăng tin tuyển dụng\n• Tìm sinh viên y khoa\n• Hệ thống đánh giá\n• Cách nhắn tin\n• Vấn đề an toàn\n• Liên hệ hỗ trợ\n\nHoặc gõ "menu" để xem danh sách chức năng.'
    ],
    'menu' => [
        'keywords' => ['menu', 'danh sách', 'chức năng', 'help', 'trợ giúp'],
        'response' => '📋 DANH SÁCH CHỨC NĂNG:\n\n1️⃣ Đăng tin tuyển dụng - Tìm sinh viên chăm sóc\n2️⃣ Tìm sinh viên - Xem danh sách ứng viên\n3️⃣ Tin nhắn - Liên hệ trực tiếp\n4️⃣ Đánh giá - Nhận xét sau dịch vụ\n5️⃣ Yêu thích - Lưu bài đăng quan tâm\n6️⃣ Hỗ trợ - Liên hệ admin\n\nGõ từ khóa để biết thêm chi tiết!'
    ]
];
?>

<!-- ChatBot Widget HTML -->
<div id="chatbot-widget" class="chatbot-widget">
    <!-- Toggle Button -->
    <button id="chatbot-toggle" class="chatbot-toggle" title="Trợ lý Y tế">
        <span class="chatbot-toggle-icon">👨‍⚕️</span>
        <span class="chatbot-toggle-close">✕</span>
        <span class="chatbot-pulse"></span>
    </button>
    
    <!-- Chat Window -->
    <div id="chatbot-window" class="chatbot-window">
        <div class="chatbot-header">
            <div class="chatbot-header-info">
                <div class="chatbot-avatar">👨‍⚕️</div>
                <div>
                    <h6 class="chatbot-name">Trợ lý Y tế</h6>
                    <span class="chatbot-status"><i class="bi bi-circle-fill"></i> Đang hoạt động</span>
                </div>
            </div>
            <button id="chatbot-minimize" class="chatbot-minimize" title="Thu nhỏ">
                <i class="bi bi-dash-lg"></i>
            </button>
        </div>
        
        <div id="chatbot-messages" class="chatbot-messages">
            <!-- Welcome message -->
            <div class="chatbot-message bot">
                <div class="message-avatar">👨‍⚕️</div>
                <div class="message-content">
                    <p>Xin chào! 👋 Tôi là trợ lý ảo của hệ thống Kết nối Y tế.</p>
                    <p>Tôi có thể giúp bạn với các câu hỏi về:</p>
                    <ul>
                        <li>Đăng tin tuyển dụng</li>
                        <li>Tìm sinh viên y khoa</li>
                        <li>Hệ thống đánh giá</li>
                        <li>Cách sử dụng tin nhắn</li>
                    </ul>
                    <p>Hãy hỏi tôi bất cứ điều gì! 😊</p>
                </div>
            </div>
        </div>
        
        <div class="chatbot-quick-replies">
            <button class="quick-reply" data-message="Cách đăng tin tuyển dụng">📢 Đăng tin</button>
            <button class="quick-reply" data-message="Tìm sinh viên y khoa">🔍 Tìm SV</button>
            <button class="quick-reply" data-message="Hệ thống đánh giá">⭐ Đánh giá</button>
            <button class="quick-reply" data-message="Liên hệ hỗ trợ">📞 Hỗ trợ</button>
        </div>
        
        <div class="chatbot-input-area">
            <input type="text" id="chatbot-input" class="chatbot-input" placeholder="Nhập câu hỏi của bạn..." autocomplete="off">
            <button id="chatbot-send" class="chatbot-send" title="Gửi">
                <i class="bi bi-send-fill"></i>
            </button>
        </div>
    </div>
</div>

<style>
/* ChatBot Widget Styles */
.chatbot-widget {
    position: fixed;
    bottom: 24px;
    right: 24px;
    z-index: 9999;
    font-family: 'Inter', 'Segoe UI', system-ui, sans-serif;
}

/* Toggle Button */
.chatbot-toggle {
    width: 64px;
    height: 64px;
    border-radius: 50%;
    background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
    border: none;
    cursor: pointer;
    box-shadow: 0 8px 32px rgba(59, 130, 246, 0.4);
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

.chatbot-toggle:hover {
    transform: scale(1.1);
    box-shadow: 0 12px 40px rgba(59, 130, 246, 0.5);
}

.chatbot-toggle-icon,
.chatbot-toggle-close {
    font-size: 1.75rem;
    transition: all 0.3s ease;
    position: absolute;
}

.chatbot-toggle-close {
    opacity: 0;
    transform: rotate(-90deg) scale(0.5);
    color: #fff;
    font-size: 1.5rem;
}

.chatbot-widget.active .chatbot-toggle-icon {
    opacity: 0;
    transform: rotate(90deg) scale(0.5);
}

.chatbot-widget.active .chatbot-toggle-close {
    opacity: 1;
    transform: rotate(0) scale(1);
}

.chatbot-pulse {
    position: absolute;
    width: 100%;
    height: 100%;
    border-radius: 50%;
    background: rgba(59, 130, 246, 0.4);
    animation: chatbot-pulse 2s infinite;
}

.chatbot-widget.active .chatbot-pulse {
    display: none;
}

@keyframes chatbot-pulse {
    0% { transform: scale(1); opacity: 0.8; }
    100% { transform: scale(1.5); opacity: 0; }
}
</style>

<style>
/* Chat Window */
.chatbot-window {
    position: absolute;
    bottom: 80px;
    right: 0;
    width: 380px;
    max-width: calc(100vw - 48px);
    height: 520px;
    max-height: calc(100vh - 150px);
    background: #fff;
    border-radius: 24px;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15), 0 0 0 1px rgba(0, 0, 0, 0.05);
    display: flex;
    flex-direction: column;
    overflow: hidden;
    opacity: 0;
    visibility: hidden;
    transform: translateY(20px) scale(0.95);
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

.chatbot-widget.active .chatbot-window {
    opacity: 1;
    visibility: visible;
    transform: translateY(0) scale(1);
}

/* Header */
.chatbot-header {
    background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
    padding: 1rem 1.25rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-shrink: 0;
}

.chatbot-header-info {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.chatbot-avatar {
    width: 44px;
    height: 44px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
}

.chatbot-name {
    color: #fff;
    font-size: 1rem;
    font-weight: 700;
    margin: 0;
}

.chatbot-status {
    color: rgba(255, 255, 255, 0.85);
    font-size: 0.8rem;
    display: flex;
    align-items: center;
    gap: 0.35rem;
}

.chatbot-status i {
    color: #4ade80;
    font-size: 0.5rem;
    animation: status-pulse 2s infinite;
}

@keyframes status-pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
}

.chatbot-minimize {
    width: 36px;
    height: 36px;
    background: rgba(255, 255, 255, 0.15);
    border: none;
    border-radius: 10px;
    color: #fff;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.3s ease;
}

.chatbot-minimize:hover {
    background: rgba(255, 255, 255, 0.25);
}
</style>

<style>
/* Messages Area */
.chatbot-messages {
    flex: 1;
    overflow-y: auto;
    padding: 1rem;
    display: flex;
    flex-direction: column;
    gap: 1rem;
    background: linear-gradient(180deg, #f8fafc 0%, #fff 100%);
}

.chatbot-messages::-webkit-scrollbar {
    width: 5px;
}

.chatbot-messages::-webkit-scrollbar-track {
    background: #f1f5f9;
}

.chatbot-messages::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 3px;
}

.chatbot-message {
    display: flex;
    gap: 0.75rem;
    max-width: 90%;
    animation: message-in 0.3s ease-out;
}

@keyframes message-in {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

.chatbot-message.bot {
    align-self: flex-start;
}

.chatbot-message.user {
    align-self: flex-end;
    flex-direction: row-reverse;
}

.message-avatar {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.1rem;
    flex-shrink: 0;
}

.chatbot-message.bot .message-avatar {
    background: linear-gradient(135deg, #dbeafe, #bfdbfe);
}

.chatbot-message.user .message-avatar {
    background: linear-gradient(135deg, #3b82f6, #1d4ed8);
    color: #fff;
}

.message-content {
    padding: 0.85rem 1rem;
    border-radius: 16px;
    font-size: 0.9rem;
    line-height: 1.6;
}

.chatbot-message.bot .message-content {
    background: #fff;
    color: #1e293b;
    border: 1px solid #e2e8f0;
    border-bottom-left-radius: 4px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
}

.chatbot-message.user .message-content {
    background: linear-gradient(135deg, #3b82f6, #1d4ed8);
    color: #fff;
    border-bottom-right-radius: 4px;
}

.message-content p {
    margin: 0 0 0.5rem;
}

.message-content p:last-child {
    margin-bottom: 0;
}

.message-content ul {
    margin: 0.5rem 0;
    padding-left: 1.25rem;
}

.message-content li {
    margin-bottom: 0.25rem;
}
</style>

<style>
/* Quick Replies */
.chatbot-quick-replies {
    padding: 0.75rem 1rem;
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    background: #f8fafc;
    border-top: 1px solid #e2e8f0;
}

.quick-reply {
    padding: 0.5rem 0.85rem;
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 500;
    color: #3b82f6;
    cursor: pointer;
    transition: all 0.3s ease;
    white-space: nowrap;
}

.quick-reply:hover {
    background: #3b82f6;
    color: #fff;
    border-color: #3b82f6;
    transform: translateY(-2px);
}

/* Input Area */
.chatbot-input-area {
    padding: 1rem;
    background: #fff;
    border-top: 1px solid #e2e8f0;
    display: flex;
    gap: 0.75rem;
    flex-shrink: 0;
}

.chatbot-input {
    flex: 1;
    padding: 0.75rem 1rem;
    border: 2px solid #e2e8f0;
    border-radius: 12px;
    font-size: 0.9rem;
    transition: all 0.3s ease;
    outline: none;
}

.chatbot-input:focus {
    border-color: #3b82f6;
    box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1);
}

.chatbot-input::placeholder {
    color: #94a3b8;
}

.chatbot-send {
    width: 44px;
    height: 44px;
    background: linear-gradient(135deg, #3b82f6, #1d4ed8);
    border: none;
    border-radius: 12px;
    color: #fff;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.3s ease;
    flex-shrink: 0;
}

.chatbot-send:hover {
    transform: scale(1.05);
    box-shadow: 0 4px 15px rgba(59, 130, 246, 0.4);
}

.chatbot-send:active {
    transform: scale(0.95);
}

/* Typing Indicator */
.typing-indicator {
    display: flex;
    gap: 4px;
    padding: 0.75rem 1rem;
}

.typing-indicator span {
    width: 8px;
    height: 8px;
    background: #94a3b8;
    border-radius: 50%;
    animation: typing 1.4s infinite ease-in-out;
}

.typing-indicator span:nth-child(1) { animation-delay: 0s; }
.typing-indicator span:nth-child(2) { animation-delay: 0.2s; }
.typing-indicator span:nth-child(3) { animation-delay: 0.4s; }

@keyframes typing {
    0%, 60%, 100% { transform: translateY(0); }
    30% { transform: translateY(-8px); }
}

/* Mobile Responsive */
@media (max-width: 480px) {
    .chatbot-widget {
        bottom: 16px;
        right: 16px;
    }
    
    .chatbot-window {
        width: calc(100vw - 32px);
        height: calc(100vh - 120px);
        bottom: 72px;
        right: -8px;
    }
    
    .chatbot-toggle {
        width: 56px;
        height: 56px;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const widget = document.getElementById('chatbot-widget');
    const toggle = document.getElementById('chatbot-toggle');
    const minimize = document.getElementById('chatbot-minimize');
    const messagesContainer = document.getElementById('chatbot-messages');
    const input = document.getElementById('chatbot-input');
    const sendBtn = document.getElementById('chatbot-send');
    const quickReplies = document.querySelectorAll('.quick-reply');
    
    // Chatbot responses database
    const responses = {
        greeting: {
            keywords: ['xin chào', 'hello', 'hi', 'chào', 'hey', 'alo'],
            response: 'Xin chào! 👋 Tôi là trợ lý ảo của hệ thống Kết nối Y tế. Tôi có thể giúp bạn với các câu hỏi về dịch vụ chăm sóc sức khỏe, cách đăng tin tuyển dụng, hoặc tìm sinh viên y khoa phù hợp. Bạn cần hỗ trợ gì?'
        },
        post_help: {
            keywords: ['đăng tin', 'tạo tin', 'đăng bài', 'tuyển dụng', 'tìm người', 'đăng'],
            response: 'Để đăng tin tuyển dụng sinh viên y khoa:<br><br>1️⃣ Nhấn nút <b>"Tạo tin tuyển dụng"</b> trên dashboard<br>2️⃣ Điền đầy đủ thông tin: tiêu đề, mô tả công việc, khu vực, mức lương đề xuất<br>3️⃣ Nhấn <b>"Đăng tin"</b><br><br>Sau khi đăng, sinh viên sẽ có thể xem và liên hệ với bạn. 📢'
        },
        find_student: {
            keywords: ['tìm sinh viên', 'sinh viên y', 'chăm sóc', 'hỗ trợ y tế', 'tìm sv', 'sv'],
            response: 'Để tìm sinh viên y khoa phù hợp:<br><br>1️⃣ Xem danh sách <b>"Tin ứng tuyển"</b> trên trang chủ<br>2️⃣ Lọc theo khu vực và chuyên khoa<br>3️⃣ Xem hồ sơ và đánh giá của sinh viên<br>4️⃣ Nhấn <b>"Liên hệ"</b> để nhắn tin trực tiếp<br><br>Bạn cũng có thể đăng tin tuyển dụng để sinh viên chủ động liên hệ. 🔍'
        },
        payment: {
            keywords: ['thanh toán', 'giá', 'chi phí', 'phí', 'tiền', 'trả', 'miễn phí'],
            response: 'Hệ thống Kết nối Y tế hoàn toàn <b>MIỄN PHÍ</b> cho việc đăng tin và kết nối! 🎉<br><br>Về chi phí dịch vụ chăm sóc, bạn và sinh viên sẽ tự thỏa thuận trực tiếp. Chúng tôi khuyến nghị thảo luận rõ ràng về mức thù lao trước khi bắt đầu hợp tác.'
        },
        safety: {
            keywords: ['an toàn', 'xác minh', 'tin cậy', 'uy tín', 'lừa đảo', 'bảo mật'],
            response: 'Để đảm bảo an toàn:<br><br>✅ Chỉ liên hệ với sinh viên đã được <b>XÁC MINH</b> (có dấu tick xanh)<br>✅ Kiểm tra đánh giá và nhận xét từ người dùng khác<br>✅ Trao đổi qua hệ thống tin nhắn trước khi gặp mặt<br>✅ Báo cáo ngay nếu phát hiện hành vi đáng ngờ<br><br>Nếu gặp vấn đề, hãy liên hệ bộ phận hỗ trợ. 🛡️'
        },
        contact: {
            keywords: ['liên hệ', 'hỗ trợ', 'support', 'giúp đỡ', 'admin', 'hotline'],
            response: 'Bạn có thể liên hệ hỗ trợ qua:<br><br>📧 <b>Email:</b> tramtankhatv@gmail.com<br>💬 <b>Tin nhắn hệ thống:</b> Vào mục "Thông báo" để xem tin từ Admin<br>📝 <b>Gửi yêu cầu:</b> Vào trang "Hỗ trợ" để gửi phản hồi<br><br>Chúng tôi sẽ phản hồi trong vòng 24 giờ làm việc. 📞'
        },
        rating: {
            keywords: ['đánh giá', 'review', 'nhận xét', 'sao', 'rating', 'vote'],
            response: 'Hệ thống đánh giá giúp xây dựng uy tín:<br><br>⭐ Sau khi hoàn thành dịch vụ, bạn có thể đánh giá sinh viên<br>⭐ Đánh giá từ 1-5 sao kèm nhận xét<br>⭐ Đánh giá tốt giúp sinh viên được nhiều người tin tưởng<br><br>Hãy đánh giá công bằng để giúp cộng đồng! ⭐'
        },
        message: {
            keywords: ['tin nhắn', 'nhắn tin', 'chat', 'trò chuyện', 'message', 'inbox'],
            response: 'Để nhắn tin với sinh viên:<br><br>1️⃣ Vào trang hồ sơ của sinh viên<br>2️⃣ Nhấn nút <b>"Nhắn tin"</b><br>3️⃣ Hoặc vào mục <b>"Tin nhắn"</b> trên menu để xem tất cả cuộc trò chuyện<br><br>Tin nhắn được lưu trữ an toàn trên hệ thống. 💬'
        },
        menu: {
            keywords: ['menu', 'danh sách', 'chức năng', 'help', 'trợ giúp', 'hướng dẫn'],
            response: '📋 <b>DANH SÁCH CHỨC NĂNG:</b><br><br>1️⃣ <b>Đăng tin tuyển dụng</b> - Tìm sinh viên chăm sóc<br>2️⃣ <b>Tìm sinh viên</b> - Xem danh sách ứng viên<br>3️⃣ <b>Tin nhắn</b> - Liên hệ trực tiếp<br>4️⃣ <b>Đánh giá</b> - Nhận xét sau dịch vụ<br>5️⃣ <b>Yêu thích</b> - Lưu bài đăng quan tâm<br>6️⃣ <b>Hỗ trợ</b> - Liên hệ admin<br><br>Gõ từ khóa để biết thêm chi tiết!'
        },
        thanks: {
            keywords: ['cảm ơn', 'thank', 'thanks', 'tks', 'cam on'],
            response: 'Không có gì! 😊 Rất vui được hỗ trợ bạn. Nếu có thêm câu hỏi nào, đừng ngại hỏi tôi nhé! 💙'
        },
        bye: {
            keywords: ['tạm biệt', 'bye', 'goodbye', 'chào tạm biệt'],
            response: 'Tạm biệt! 👋 Chúc bạn có trải nghiệm tốt với hệ thống Kết nối Y tế. Hẹn gặp lại! 💙'
        }
    };
    
    const defaultResponse = 'Xin lỗi, tôi chưa hiểu câu hỏi của bạn. 🤔<br><br>Bạn có thể hỏi về:<br>• Cách đăng tin tuyển dụng<br>• Tìm sinh viên y khoa<br>• Hệ thống đánh giá<br>• Cách nhắn tin<br>• Vấn đề an toàn<br>• Liên hệ hỗ trợ<br><br>Hoặc gõ <b>"menu"</b> để xem danh sách chức năng.';

    // Toggle chat window
    toggle.addEventListener('click', function() {
        widget.classList.toggle('active');
        if (widget.classList.contains('active')) {
            input.focus();
        }
    });
    
    minimize.addEventListener('click', function() {
        widget.classList.remove('active');
    });
    
    // Find response based on keywords
    function findResponse(message) {
        const lowerMessage = message.toLowerCase().trim();
        
        for (const key in responses) {
            const item = responses[key];
            if (item.keywords) {
                for (const keyword of item.keywords) {
                    if (lowerMessage.includes(keyword)) {
                        return item.response;
                    }
                }
            }
        }
        
        return defaultResponse;
    }
    
    // Add message to chat
    function addMessage(content, isUser = false) {
        const messageDiv = document.createElement('div');
        messageDiv.className = 'chatbot-message ' + (isUser ? 'user' : 'bot');
        
        const avatar = document.createElement('div');
        avatar.className = 'message-avatar';
        avatar.textContent = isUser ? '👤' : '👨‍⚕️';
        
        const contentDiv = document.createElement('div');
        contentDiv.className = 'message-content';
        contentDiv.innerHTML = '<p>' + content + '</p>';
        
        messageDiv.appendChild(avatar);
        messageDiv.appendChild(contentDiv);
        messagesContainer.appendChild(messageDiv);
        
        // Scroll to bottom
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
    }
    
    // Show typing indicator
    function showTyping() {
        const typingDiv = document.createElement('div');
        typingDiv.className = 'chatbot-message bot';
        typingDiv.id = 'typing-indicator';
        
        const avatar = document.createElement('div');
        avatar.className = 'message-avatar';
        avatar.textContent = '👨‍⚕️';
        
        const contentDiv = document.createElement('div');
        contentDiv.className = 'message-content';
        contentDiv.innerHTML = '<div class="typing-indicator"><span></span><span></span><span></span></div>';
        
        typingDiv.appendChild(avatar);
        typingDiv.appendChild(contentDiv);
        messagesContainer.appendChild(typingDiv);
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
    }
    
    // Remove typing indicator
    function hideTyping() {
        const typing = document.getElementById('typing-indicator');
        if (typing) typing.remove();
    }
    
    // Send message
    function sendMessage() {
        const message = input.value.trim();
        if (!message) return;
        
        // Add user message
        addMessage(message, true);
        input.value = '';
        
        // Show typing indicator
        showTyping();
        
        // Simulate response delay
        setTimeout(function() {
            hideTyping();
            const response = findResponse(message);
            addMessage(response, false);
        }, 800 + Math.random() * 700);
    }
    
    // Event listeners
    sendBtn.addEventListener('click', sendMessage);
    
    input.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            sendMessage();
        }
    });
    
    // Quick replies
    quickReplies.forEach(function(btn) {
        btn.addEventListener('click', function() {
            input.value = this.getAttribute('data-message');
            sendMessage();
        });
    });
});
</script>
