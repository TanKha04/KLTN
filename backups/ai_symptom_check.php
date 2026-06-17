<?php
/**
 * AI Symptom Checker - Kiểm tra triệu chứng bằng AI
 */
require_once dirname(__DIR__) . '/config.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$symptoms    = $input['symptoms'] ?? [];
$details     = trim($input['details'] ?? '');
$age         = $input['age'] ?? '';
$gender      = $input['gender'] ?? '';
$duration    = $input['duration'] ?? '';

if (empty($symptoms) && empty($details)) {
    echo json_encode(['error' => 'Vui lòng chọn hoặc mô tả triệu chứng']);
    exit;
}

// Xây dựng prompt phân tích triệu chứng
$symptomList = !empty($symptoms) ? implode(', ', $symptoms) : 'Không chỉ định';
$userInfo = "Bệnh nhân";
if ($age) $userInfo .= " $age tuổi";
if ($gender) $userInfo .= ", " . ($gender === 'male' ? 'nam' : 'nữ');

$analysisPrompt = "Bạn là bác sĩ tư vấn AI. Hãy phân tích các triệu chứng sau và đưa ra đánh giá ngắn gọn, KHÔNG chẩn đoán bệnh cụ thể.

THÔNG TIN BỆNH NHÂN:
- Đối tượng: $userInfo
- Triệu chứng chính: $symptomList
- Thời gian: " . ($duration ?: 'Chưa rõ') . "
- Chi tiết thêm: " . ($details ?: 'Không có') . "

YÊU CẦU TRẢ LỜI (bằng JSON):
{
  \"severity\": \"low|medium|high|emergency\",
  \"severity_label\": \"Nhẹ|Trung bình|Nghiêm trọng|Khẩn cấp\",
  \"summary\": \"Tóm tắt tình trạng trong 1-2 câu\",
  \"possible_causes\": [\"nguyên nhân 1\", \"nguyên nhân 2\", \"nguyên nhân 3\"],
  \"immediate_actions\": [\"hành động 1\", \"hành động 2\"],
  \"when_to_see_doctor\": \"Khi nào cần gặp bác sĩ\",
  \"recommended_specialty\": \"Chuyên khoa phù hợp (ví dụ: Nội khoa, Tim mạch, Thần kinh...)\",
  \"home_care\": [\"biện pháp tại nhà 1\", \"biện pháp tại nhà 2\"],
  \"warning_signs\": [\"dấu hiệu nguy hiểm cần nhập viện ngay\"],
  \"disclaimer\": \"Lưu ý: Đây chỉ là tư vấn sơ bộ, không thay thế khám bác sĩ\"
}

Chỉ trả về JSON hợp lệ, không thêm text ngoài.";

function callGeminiForSymptom($prompt) {
    $apiKey = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '';
    
    if (empty($apiKey) || $apiKey === 'AIzaSyDemo_replace_with_real_key') {
        return ['success' => false, 'error' => 'no_key'];
    }

    $payload = [
        'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
        'generationConfig' => [
            'temperature' => 0.3,
            'maxOutputTokens' => 4096,
            'responseMimeType' => 'application/json'
        ]
    ];

    $model = defined('GEMINI_MODEL') && GEMINI_MODEL !== '' ? GEMINI_MODEL : 'gemini-2.5-flash';
    $apiUrl = defined('GEMINI_API_URL') ? GEMINI_API_URL :
              "https://generativelanguage.googleapis.com/v1beta/models/$model:generateContent";

    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-goog-api-key: ' . $apiKey
        ],
        CURLOPT_TIMEOUT => 90,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);

    $result   = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) return ['success' => false, 'error' => 'HTTP ' . $httpCode];

    $response = json_decode($result, true);
    $text = $response['candidates'][0]['content']['parts'][0]['text'] ?? null;
    if (!$text) return ['success' => false, 'error' => 'empty'];

    $parsed = json_decode($text, true);
    if (!$parsed) return ['success' => false, 'error' => 'invalid_json'];

    return ['success' => true, 'data' => $parsed];
}

/**
 * Fallback phân tích triệu chứng offline
 */
function getFallbackSymptomAnalysis($symptoms, $details) {
    $allText = mb_strtolower(implode(' ', $symptoms) . ' ' . $details, 'UTF-8');
    
    // Emergency patterns
    $emergencyPatterns = 'đau ngực|khó thở|tím tái|bất tỉnh|co giật|liệt|nói khó|méo miệng|đau đầu dữ dội|nôn ra máu|chảy máu nhiều|ngất|mất trí nhớ|run tay|run chân|lú lẫn|tê liệt|tê bì|mất ý thức|hoang tưởng|ảo giác|mất thăng bằng|nhìn đôi|tê nửa người|yếu nửa người|đột quỵ|tai biến|co cứng';
    if (preg_match("/$emergencyPatterns/ui", $allText)) {
        return [
            'severity' => 'emergency',
            'severity_label' => 'Khẩn cấp',
            'summary' => '🩺 Tôi rất tiếc khi nghe về triệu chứng của bạn. Tình trạng của bạn có thể phức tạp và không thể đánh giá chính xác qua chatbot này. ⚠️ Các triệu chứng bạn mô tả có thể liên quan đến tình trạng y tế khẩn cấp cần được đánh giá và xử lý ngay lập tức bởi đội ngũ y tế chuyên nghiệp.',
            'possible_causes' => ['Cần bác sĩ đánh giá trực tiếp - không thể xác định qua chatbot'],
            'immediate_actions' => ['Gọi 115 ngay lập tức', 'Đến phòng cấp cứu gần nhất', 'Không tự lái xe'],
            'when_to_see_doctor' => 'NGAY LẬP TỨC - Gọi 115',
            'recommended_specialty' => 'Cấp cứu',
            'home_care' => [],
            'warning_signs' => ['Mất ý thức', 'Ngừng thở', 'Da tím tái'],
            'disclaimer' => '👉 Vì sự an toàn của bạn: Hãy đến bệnh viện ngay. Không nên dựa vào tư vấn trực tuyến cho tình trạng này. Nếu cần, hệ thống có thể giúp bạn tìm hỗ trợ từ sinh viên y khoa.'
        ];
    }
    
    // High severity
    $highPatterns = 'sốt cao|39|40|đau bụng dữ dội|nôn liên tục|tiêu chảy nhiều|mất nước';
    if (preg_match("/$highPatterns/ui", $allText)) {
        return [
            'severity' => 'high',
            'severity_label' => 'Nghiêm trọng',
            'summary' => 'Triệu chứng của bạn ở mức độ đáng lo ngại, cần khám bác sĩ sớm.',
            'possible_causes' => ['Nhiễm trùng', 'Viêm cơ quan nội tạng', 'Rối loạn chuyển hóa'],
            'immediate_actions' => ['Đến phòng khám hoặc bệnh viện trong ngày hôm nay', 'Theo dõi triệu chứng chặt chẽ'],
            'when_to_see_doctor' => 'Trong vòng 24 giờ, hoặc ngay nếu triệu chứng nặng hơn',
            'recommended_specialty' => 'Nội khoa',
            'home_care' => ['Uống nhiều nước', 'Nghỉ ngơi tuyệt đối', 'Không tự ý dùng thuốc'],
            'warning_signs' => ['Triệu chứng nặng hơn', 'Mất ý thức', 'Không uống được nước'],
            'disclaimer' => 'Đây chỉ là tư vấn sơ bộ, không thay thế khám bác sĩ chuyên khoa'
        ];
    }
    
    // Default medium
    $specialty = 'Nội khoa';
    if (preg_match('/tim|huyết áp|ngực/ui', $allText)) $specialty = 'Tim mạch';
    elseif (preg_match('/đầu|thần kinh|chóng mặt/ui', $allText)) $specialty = 'Thần kinh';
    elseif (preg_match('/xương|khớp|lưng/ui', $allText)) $specialty = 'Cơ xương khớp';
    elseif (preg_match('/da|ngứa|mẩn/ui', $allText)) $specialty = 'Da liễu';
    elseif (preg_match('/mắt|nhìn mờ/ui', $allText)) $specialty = 'Nhãn khoa';
    
    return [
        'severity' => 'medium',
        'severity_label' => 'Trung bình',
        'summary' => 'Triệu chứng của bạn cần được theo dõi. Nên đặt lịch khám bác sĩ trong vài ngày tới.',
        'possible_causes' => ['Nhiễm virus thông thường', 'Stress và mệt mỏi', 'Chế độ sinh hoạt chưa hợp lý'],
        'immediate_actions' => ['Nghỉ ngơi đầy đủ', 'Uống nhiều nước', 'Tránh thức khuya'],
        'when_to_see_doctor' => 'Trong vòng 2-3 ngày nếu không cải thiện',
        'recommended_specialty' => $specialty,
        'home_care' => ['Uống nước ấm', 'Ăn nhẹ dễ tiêu', 'Nghỉ ngơi', 'Đo thân nhiệt định kỳ'],
        'warning_signs' => ['Triệu chứng nặng hơn đột ngột', 'Sốt > 39°C', 'Không thể sinh hoạt bình thường'],
        'disclaimer' => 'Đây chỉ là tư vấn sơ bộ, không thay thế khám bác sĩ chuyên khoa'
    ];
}

// Thực hiện phân tích
$geminiResult = callGeminiForSymptom($analysisPrompt);

if ($geminiResult['success']) {
    $analysis = $geminiResult['data'];
    $source = 'gemini';
} else {
    $analysis = getFallbackSymptomAnalysis($symptoms, $details);
    $source = 'fallback';
}

echo json_encode([
    'success'  => true,
    'analysis' => $analysis,
    'source'   => $source,
], JSON_UNESCAPED_UNICODE);
