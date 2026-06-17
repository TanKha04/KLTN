<?php
/**
 * AI Find Students - Tìm sinh viên y khoa phù hợp theo chuyên khoa/triệu chứng
 * POST body: { "specialty": "Thần kinh", "location": "", "limit": 3 }
 */
require_once dirname(__DIR__) . '/config.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input    = json_decode(file_get_contents('php://input'), true);
$specialty = trim($input['specialty'] ?? '');
$location  = trim($input['location'] ?? '');
$limit     = min(5, max(1, (int)($input['limit'] ?? 3)));

// Mapping chuyên khoa → từ khóa tìm trong bio/school/class_code
$specialtyKeywords = [
    'Thần kinh'       => ['thần kinh', 'neurology', 'não'],
    'Tim mạch'        => ['tim mạch', 'cardiology', 'tim'],
    'Nội khoa'        => ['nội khoa', 'internal medicine', 'nội'],
    'Ngoại khoa'      => ['ngoại khoa', 'surgery', 'ngoại'],
    'Nhi khoa'        => ['nhi khoa', 'pediatrics', 'nhi'],
    'Da liễu'         => ['da liễu', 'dermatology', 'da'],
    'Cơ xương khớp'   => ['cơ xương khớp', 'orthopedics', 'xương khớp'],
    'Nhãn khoa'       => ['nhãn khoa', 'ophthalmology', 'mắt'],
    'Tai mũi họng'    => ['tai mũi họng', 'ent', 'tai', 'mũi'],
    'Phụ sản'         => ['phụ sản', 'obstetrics', 'sản'],
    'Cấp cứu'         => ['cấp cứu', 'emergency', 'cấp cứu'],
    'Tâm thần'        => ['tâm thần', 'psychiatry', 'tâm lý'],
    'Răng hàm mặt'    => ['răng hàm mặt', 'dentistry', 'răng'],
    'Nội tiết'        => ['nội tiết', 'endocrinology', 'tiểu đường', 'tuyến giáp'],
];

/**
 * Map triệu chứng → chuyên khoa
 */
function mapSymptomToSpecialty($text) {
    $text = mb_strtolower($text, 'UTF-8');
    $map = [
        'Thần kinh'     => 'đau đầu|nhức đầu|chóng mặt|thần kinh|run tay|run chân|tê bì|co giật|lú lẫn|mất trí nhớ|não|migraine|tê liệt',
        'Tim mạch'      => 'đau ngực|tim mạch|huyết áp|tim đập|nhịp tim|trống ngực|khó thở',
        'Nội khoa'      => 'sốt|mệt mỏi|suy nhược|mất ngủ|nội khoa',
        'Ngoại khoa'    => 'chấn thương|đau bụng cấp|vết thương|mổ|phẫu thuật',
        'Nhi khoa'      => 'trẻ em|trẻ nhỏ|trẻ sơ sinh|nhi',
        'Da liễu'       => 'da|ngứa|mẩn đỏ|nổi mụn|dị ứng da|vảy nến',
        'Cơ xương khớp' => 'đau lưng|đau khớp|xương|khớp|thoái hóa|viêm khớp',
        'Nhãn khoa'     => 'mắt|nhìn mờ|đau mắt|mắt đỏ',
        'Tai mũi họng'  => 'tai|mũi|họng|ho|viêm họng|sổ mũi|đau tai',
        'Phụ sản'       => 'kinh nguyệt|mang thai|phụ khoa|sản phụ',
        'Nội tiết'      => 'tiểu đường|tuyến giáp|nội tiết|đường huyết|béo phì',
        'Răng hàm mặt'  => 'đau răng|răng|nướu|hàm|miệng',
        'Tâm thần'      => 'lo âu|trầm cảm|tâm lý|tâm thần|căng thẳng|stress',
    ];
    foreach ($map as $spec => $pattern) {
        if (preg_match("/$pattern/ui", $text)) {
            return $spec;
        }
    }
    return 'Nội khoa'; // default
}

// Nếu không truyền specialty, tự detect
if (empty($specialty)) {
    $specialty = mapSymptomToSpecialty($input['symptom_text'] ?? '');
}

// Build SQL query
try {
    $conditions = ["u.role = 'student'", "u.verified = 1"];
    $params = [];

    // Lọc theo chuyên khoa (tìm trong bio, school, class_code)
    $keywords = $specialtyKeywords[$specialty] ?? [mb_strtolower($specialty, 'UTF-8')];
    $keywordClauses = [];
    foreach ($keywords as $kw) {
        $keywordClauses[] = "(LOWER(u.bio) LIKE ? OR LOWER(u.school) LIKE ? OR LOWER(u.class_code) LIKE ?)";
        $like = '%' . $kw . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }
    if (!empty($keywordClauses)) {
        $conditions[] = '(' . implode(' OR ', $keywordClauses) . ')';
    }

    // Lọc theo địa điểm (tuỳ chọn)
    if (!empty($location)) {
        $conditions[] = "LOWER(u.location) LIKE ?";
        $params[] = '%' . mb_strtolower($location, 'UTF-8') . '%';
    }

    $where = implode(' AND ', $conditions);

    // Lấy thêm avg rating nếu có bảng ratings
    $sql = "
        SELECT u.id, u.name, u.avatar, u.school, u.location, u.bio, u.class_code,
               COALESCE(AVG(r.rating), 0) AS avg_rating,
               COUNT(r.id) AS rating_count,
               u.last_activity
        FROM users u
        LEFT JOIN ratings r ON r.rated_user_id = u.id
        WHERE $where
        GROUP BY u.id
        ORDER BY u.verified DESC, avg_rating DESC, u.last_activity DESC
        LIMIT ?
    ";
    $params[] = $limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $students = $stmt->fetchAll();

    // Nếu không đủ kết quả theo chuyên khoa, lấy thêm sinh viên xác minh bất kỳ
    if (count($students) < $limit) {
        $existing_ids = array_column($students, 'id');
        $extra_limit = $limit - count($students);
        $not_in = empty($existing_ids) ? '' : 'AND u.id NOT IN (' . implode(',', array_map('intval', $existing_ids)) . ')';
        $extra_sql = "
            SELECT u.id, u.name, u.avatar, u.school, u.location, u.bio, u.class_code,
                   COALESCE(AVG(r.rating), 0) AS avg_rating,
                   COUNT(r.id) AS rating_count,
                   u.last_activity
            FROM users u
            LEFT JOIN ratings r ON r.rated_user_id = u.id
            WHERE u.role = 'student' AND u.verified = 1 $not_in
            GROUP BY u.id
            ORDER BY avg_rating DESC, u.last_activity DESC
            LIMIT ?
        ";
        $extraStmt = $pdo->prepare($extra_sql);
        $extraStmt->execute([$extra_limit]);
        $extra = $extraStmt->fetchAll();
        $students = array_merge($students, $extra);
    }

    // Format output
    $result = [];
    foreach ($students as $s) {
        $avatar = !empty($s['avatar']) ? public_url_for($s['avatar']) : null;
        $isOnline = is_user_online($s['last_activity'] ?? '');
        $bioShort = !empty($s['bio']) ? mb_substr(strip_tags($s['bio']), 0, 80, 'UTF-8') . '...' : '';
        $result[] = [
            'id'           => (int)$s['id'],
            'name'         => $s['name'] ?? 'Sinh viên Y khoa',
            'avatar'       => $avatar,
            'school'       => $s['school'] ?? '',
            'location'     => $s['location'] ?? '',
            'bio_short'    => $bioShort,
            'avg_rating'   => round((float)$s['avg_rating'], 1),
            'rating_count' => (int)$s['rating_count'],
            'is_online'    => $isOnline,
            'profile_url'  => 'view_profile.php?id=' . $s['id'],
            'message_url'  => 'view_messages.php?user=' . $s['id'],
        ];
    }

    echo json_encode([
        'success'   => true,
        'specialty' => $specialty,
        'students'  => $result,
        'count'     => count($result),
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
