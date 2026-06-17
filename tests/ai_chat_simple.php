<?php
/**
 * Simple AI Chat - Dùng GPT-2 hoặc fallback
 */
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $question = $_POST['message'] ?? '';
    
    // Lấy API key
    $apiKey = getenv('HUGGINGFACE_API_KEY') ?: (defined('HUGGINGFACE_API_KEY') ? HUGGINGFACE_API_KEY : '');
    
    // Nếu có API key, thử gọi AI
    if (!empty($apiKey) && $apiKey !== 'YOUR_API_KEY_HERE') {
        
        // Thử model GPT-2 (nhẹ và nhanh)
        $data = [
            "inputs" => $question,
            "parameters" => [
                "max_new_tokens" => 100,
                "temperature" => 0.8,
                "return_full_text" => false
            ],
            "options" => [
                "wait_for_model" => true
            ]
        ];
        
        $ch = curl_init('https://api-inference.huggingface.co/models/gpt2');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey
        ]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        // Nếu thành công
        if ($httpCode === 200) {
            $response = json_decode($result, true);
            if (isset($response[0]['generated_text'])) {
                $aiText = $response[0]['generated_text'];
                // Kết hợp AI với fallback để có câu trả lời tốt hơn
                $fallback = getFallbackResponse($question);
                echo $fallback . "\n\n💡 AI gợi ý: " . $aiText;
                exit;
            }
        }
    }
    
    // Fallback - luôn hoạt động
    echo getFallbackResponse($question);
}

// Hàm fallback thông minh
function getFallbackResponse($question) {
    $lowerQuestion = mb_strtolower($question, 'UTF-8');
    
    $responses = [
        'xin chào|hello|hi|chào|hey' => '👋 Xin chào! Tôi là trợ lý ảo của Kết nối Y tế. Tôi có thể giúp bạn về:\n• Đăng tin tuyển dụng sinh viên y khoa\n• Tìm và liên hệ sinh viên\n• Các vấn đề sức khỏe cơ bản\n• Cách sử dụng hệ thống\n\nBạn cần hỗ trợ gì?',
        
        'đăng tin|tạo tin|đăng bài|post|tuyển dụng' => '📢 **Cách đăng tin tuyển dụng:**\n\n1. Nhấn nút "Tạo tin tuyển dụng" trên dashboard\n2. Điền thông tin:\n   • Tiêu đề công việc\n   • Mô tả chi tiết\n   • Khu vực làm việc\n   • Mức lương đề xuất\n3. Nhấn "Đăng tin"\n\n✅ Sinh viên sẽ xem và liên hệ với bạn qua tin nhắn!',
        
        'tìm sinh viên|sinh viên y|student|chăm sóc' => '🔍 **Cách tìm sinh viên y khoa:**\n\n1. Xem "Tin ứng tuyển" trên trang chủ\n2. Lọc theo:\n   • Khu vực\n   • Chuyên khoa\n   • Đánh giá\n3. Xem hồ sơ chi tiết\n4. Nhấn "Liên hệ" để nhắn tin\n\n💡 Ưu tiên sinh viên có dấu ✅ (đã xác minh)',
        
        'thanh toán|giá|chi phí|phí|tiền|free|miễn phí' => '💰 **Về chi phí:**\n\n✅ Hệ thống hoàn toàn MIỄN PHÍ:\n• Đăng tin tuyển dụng\n• Tìm kiếm sinh viên\n• Nhắn tin, đánh giá\n\n💵 Chi phí dịch vụ chăm sóc:\n• Do bạn và sinh viên tự thỏa thuận\n• Nên thảo luận rõ trước khi bắt đầu',
        
        'an toàn|xác minh|tin cậy|uy tín|lừa đảo|scam' => '🛡️ **Đảm bảo an toàn:**\n\n✅ Chỉ liên hệ sinh viên có dấu ✅ (đã xác minh)\n✅ Kiểm tra đánh giá từ người dùng khác\n✅ Trao đổi qua tin nhắn hệ thống trước\n✅ Gặp mặt ở nơi công cộng lần đầu\n\n⚠️ Báo cáo ngay nếu phát hiện hành vi đáng ngờ!',
        
        'tin nhắn|nhắn tin|chat|message|liên hệ' => '💬 **Cách nhắn tin:**\n\n1. Vào hồ sơ sinh viên\n2. Nhấn nút "Nhắn tin"\n3. Hoặc vào menu "Tin nhắn" để xem tất cả\n\n📱 Tin nhắn được lưu an toàn trên hệ thống.',
        
        'đánh giá|review|rating|sao|nhận xét' => '⭐ **Hệ thống đánh giá:**\n\nSau khi hoàn thành dịch vụ:\n1. Đánh giá 1-5 sao\n2. Viết nhận xét\n3. Giúp sinh viên xây dựng uy tín\n\n💡 Đánh giá công bằng giúp cộng đồng phát triển!',
        
        'hỗ trợ|support|help|admin|liên hệ admin' => '📞 **Liên hệ hỗ trợ:**\n\n📧 Email: tramtankhatv@gmail.com\n💬 Mục "Thông báo" để xem tin từ Admin\n📝 Trang "Hỗ trợ" để gửi phản hồi\n\n⏰ Phản hồi trong 24h làm việc',
        
        'menu|danh sách|chức năng|tính năng' => '📋 **DANH SÁCH CHỨC NĂNG:**\n\n1️⃣ Đăng tin tuyển dụng\n2️⃣ Tìm sinh viên y khoa\n3️⃣ Tin nhắn\n4️⃣ Đánh giá\n5️⃣ Yêu thích\n6️⃣ Hỗ trợ\n\nGõ từ khóa để biết chi tiết!',
        
        // Câu hỏi về sức khỏe
        'đau đầu|nhức đầu|headache|đầu' => '🏥 **Về đau đầu:**\n\nNguyên nhân thường gặp:\n• Căng thẳng, stress\n• Mất ngủ, thiếu nước\n• Ánh sáng quá mạnh\n\nCách xử lý:\n✅ Nghỉ ngơi, thư giãn\n✅ Uống đủ nước\n✅ Massage nhẹ vùng thái dương\n\n⚠️ Gặp bác sĩ nếu:\n• Đau >3 ngày\n• Đau dữ dội đột ngột\n• Kèm sốt cao, nôn',
        
        'sốt|fever|nóng|nhiệt độ' => '🌡️ **Về sốt:**\n\nSốt là dấu hiệu cơ thể chống nhiễm trùng.\n\nCách xử lý:\n✅ Uống nhiều nước\n✅ Nghỉ ngơi đầy đủ\n✅ Chườm mát\n✅ Mặc quần áo thoáng\n\n⚠️ Gặp bác sĩ ngay nếu:\n• Sốt >39°C\n• Sốt >3 ngày\n• Khó thở, co giật\n• Trẻ em <3 tháng tuổi',
        
        'ho|cough|khó thở|thở|hơi thở' => '😷 **Về ho:**\n\nNguyên nhân:\n• Cảm lạnh, cúm\n• Dị ứng\n• Nhiễm trùng đường hô hấp\n\nCách xử lý:\n✅ Uống nước ấm\n✅ Nghỉ ngơi\n✅ Tránh khói bụi\n\n⚠️ Gặp bác sĩ ngay nếu:\n• Ho ra máu\n• Khó thở\n• Ho >2 tuần\n• Sốt cao kèm theo',
        
        'đau bụng|stomach|bụng|tiêu hóa' => '🤕 **Về đau bụng:**\n\nNguyên nhân thường gặp:\n• Ăn uống không hợp lý\n• Stress\n• Nhiễm trùng tiêu hóa\n\nCách xử lý:\n✅ Ăn nhẹ, dễ tiêu\n✅ Uống nhiều nước\n✅ Nghỉ ngơi\n\n⚠️ Gặp bác sĩ ngay nếu:\n• Đau dữ dội\n• Nôn ra máu\n• Sốt cao\n• Đau kéo dài',
        
        'tiểu đường|đường huyết|diabetes|sugar' => '🩺 **Về tiểu đường:**\n\nQuản lý tiểu đường cần:\n✅ Kiểm soát đường huyết đều đặn\n✅ Ăn uống lành mạnh\n✅ Tập thể dục đều đặn\n✅ Uống thuốc theo chỉ định\n\n💡 Bạn có thể tìm sinh viên y khoa có kinh nghiệm chăm sóc bệnh tiểu đường trên hệ thống!',
        
        'huyết áp|blood pressure|cao huyết áp|huyết' => '💓 **Về huyết áp:**\n\nQuản lý huyết áp:\n✅ Đo huyết áp đều đặn\n✅ Ăn ít muối\n✅ Tập thể dục\n✅ Giảm stress\n✅ Uống thuốc đúng giờ\n\n💡 Sinh viên y khoa có thể hỗ trợ đo và theo dõi huyết áp tại nhà!',
        
        'chăm sóc người già|elderly|người cao tuổi|già' => '👴👵 **Chăm sóc người cao tuổi:**\n\nCần chú ý:\n✅ Kiên nhẫn, tận tâm\n✅ Theo dõi sức khỏe đều đặn\n✅ Hỗ trợ sinh hoạt hàng ngày\n✅ Đảm bảo dinh dưỡng\n✅ Tạo môi trường an toàn\n\n💡 Hệ thống có nhiều sinh viên y khoa có kinh nghiệm chăm sóc người cao tuổi!',
    ];
    
    // Tìm response phù hợp
    foreach ($responses as $pattern => $answer) {
        if (preg_match('/' . $pattern . '/ui', $lowerQuestion)) {
            return $answer;
        }
    }
    
    // Default response
    return '🤖 Xin lỗi, tôi chưa hiểu rõ câu hỏi của bạn.\n\n📚 Bạn có thể hỏi về:\n• Cách đăng tin tuyển dụng\n• Tìm sinh viên y khoa\n• Các vấn đề sức khỏe cơ bản (đau đầu, sốt, ho, đau bụng...)\n• Hệ thống đánh giá\n• Cách nhắn tin\n• Liên hệ hỗ trợ\n\n💡 Hoặc gõ "menu" để xem danh sách đầy đủ!';
}
?>
