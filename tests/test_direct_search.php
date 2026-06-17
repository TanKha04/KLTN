<?php
/**
 * Direct test of findMatchingStudents - bypasses ai_gemini.php entry point
 */
require_once 'config.php';
header('Content-Type: text/html; charset=utf-8');
echo "<pre style='background:#0f172a;color:#e2e8f0;padding:20px;font-size:14px;'>";

// Manually define the functions we need (copy from ai_gemini.php)
function extractKeywordsFromMessage($text) {
    $stopWords = [
        'tôi','bạn','họ','chúng','các','của','và','để','với','trong','từ','là','có','được',
        'không','một','những','này','đó','tìm','kiếm','sinh','viên','y','khoa','cần','muốn',
        'giúp','hỗ','trợ','cho','người','bệnh','bị','đang','tôi','hãy','cho','biết','về',
        'làm','gì','nào','thế','nên','phải','rất','quá','như','vậy','ai','gợi','ý','giới','thiệu',
        'find','student','medical','help','me','i','need','want','care','support','please',
    ];
    $text = mb_strtolower(strip_tags($text), 'UTF-8');
    $words = preg_split('/[\s,\.!?;:\-\/\(\)]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
    $keywords = [];
    foreach ($words as $w) {
        if (mb_strlen($w, 'UTF-8') < 3) continue;
        if (in_array($w, $stopWords, true)) continue;
        $keywords[] = $w;
    }
    return array_unique($keywords);
}

function findBestMatchingPost($studentId, $keywords) {
    global $pdo;
    if (empty($keywords)) return null;
    try {
        $clauses = [];
        $params  = [];
        foreach ($keywords as $kw) {
            $like = '%' . $kw . '%';
            $clauses[] = "(LOWER(p.title) LIKE ? OR LOWER(p.content) LIKE ?)";
            $params[] = $like;
            $params[] = $like;
        }
        $params[] = (int)$studentId;
        $sql = "SELECT p.id, p.title, p.content FROM posts p
                WHERE p.status = 'open' AND (" . implode(' OR ', $clauses) . ") AND p.user_id = ?
                ORDER BY p.created_at DESC LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $post = $stmt->fetch();
        if (!$post) return null;
        $snippet = mb_substr(strip_tags($post['content']), 0, 120, 'UTF-8');
        return [
            'post_id'    => (int)$post['id'],
            'post_title' => $post['title'],
            'post_snippet' => $snippet,
            'post_url'   => 'view_post.php?id=' . $post['id'],
        ];
    } catch (Exception $e) {
        echo "findBestMatchingPost ERROR: " . $e->getMessage() . "\n";
        return null;
    }
}

function findMatchingStudents($specialty, $symptomText, $limit = 3) {
    global $pdo;
    $freeKeywords = extractKeywordsFromMessage($symptomText);
    echo "Keywords: " . json_encode($freeKeywords, JSON_UNESCAPED_UNICODE) . "\n";

    try {
        $foundIds = [];
        $rows     = [];

        $fetchStudents = function(array $ids) use ($pdo) {
            if (empty($ids)) return [];
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $sql = "SELECT u.id, u.name, u.avatar, u.school, u.location, u.verified, u.last_activity,
                           COALESCE(AVG(r.rating), 0) AS avg_rating,
                           COUNT(r.id) AS rating_count
                    FROM users u
                    LEFT JOIN ratings r ON r.rated_user_id = u.id
                    WHERE u.id IN ($ph)
                    GROUP BY u.id, u.name, u.avatar, u.school, u.location, u.verified, u.last_activity";
            $st = $pdo->prepare($sql);
            $st->execute(array_values($ids));
            return $st->fetchAll(PDO::FETCH_ASSOC);
        };

        // Step 1
        if (!empty($freeKeywords)) {
            $clauses = [];
            $params  = [];
            foreach ($freeKeywords as $kw) {
                $like = '%' . $kw . '%';
                $clauses[] = "LOWER(p.title) LIKE ?";             $params[] = $like;
                $clauses[] = "LOWER(p.content) LIKE ?";           $params[] = $like;
                $clauses[] = "LOWER(COALESCE(u.bio,'')) LIKE ?";   $params[] = $like;
                $clauses[] = "LOWER(COALESCE(u.school,'')) LIKE ?"; $params[] = $like;
            }
            $where = implode(' OR ', $clauses);
            $sql1 = "SELECT DISTINCT u.id FROM users u
                     LEFT JOIN posts p ON p.user_id = u.id AND p.status IN ('open','closed','completed')
                     WHERE u.role = 'student' AND ($where)
                     LIMIT " . (int)$limit;
            echo "SQL1: " . preg_replace('/\s+/', ' ', $sql1) . "\n";
            echo "Params: " . json_encode($params, JSON_UNESCAPED_UNICODE) . "\n";
            $st1 = $pdo->prepare($sql1);
            $st1->execute($params);
            $ids1 = $st1->fetchAll(PDO::FETCH_COLUMN);
            echo "Step1 IDs: " . json_encode($ids1) . "\n";
            if (!empty($ids1)) {
                $foundIds = array_merge($foundIds, $ids1);
                $rows = array_merge($rows, $fetchStudents($ids1));
                echo "Step1 rows: " . count($rows) . "\n";
            }
        }

        echo "Total rows before build: " . count($rows) . "\n";

        // Build result
        $result = [];
        foreach ($rows as $s) {
            $avatar = null;
            if (!empty($s['avatar'])) {
                $av = trim($s['avatar']);
                $avatar = (strpos($av, 'http') === 0 || strpos($av, '/') === 0) ? $av : '/' . ltrim($av, '/');
            }
            $isOnline = false;
            if (!empty($s['last_activity'])) {
                $ts = strtotime($s['last_activity']);
                $isOnline = ($ts && (time() - $ts) < 600);
            }
            $matchedPost = findBestMatchingPost((int)$s['id'], $freeKeywords);
            echo "MatchedPost for ID:{$s['id']}: " . json_encode($matchedPost, JSON_UNESCAPED_UNICODE) . "\n";
            $entry = [
                'id' => (int)$s['id'],
                'name' => $s['name'] ?? 'Sinh viên Y khoa',
                'avatar' => $avatar,
                'school' => $s['school'] ?? '',
                'location' => $s['location'] ?? '',
                'avg_rating' => round((float)($s['avg_rating'] ?? 0), 1),
                'rating_count' => (int)($s['rating_count'] ?? 0),
                'is_online' => $isOnline,
                'verified' => !empty($s['verified']),
                'profile_url' => 'view_profile.php?id=' . $s['id'],
                'message_url' => 'view_messages.php?user=' . $s['id'],
            ];
            if ($matchedPost) $entry['matched_post'] = $matchedPost;
            $result[] = $entry;
        }

        $out = ['success' => true, 'specialty' => $specialty, 'students' => $result, 'keywords' => $freeKeywords];
        return $out;
    } catch (Exception $e) {
        echo "❌ EXCEPTION: " . $e->getMessage() . "\n";
        return ['success' => true, 'specialty' => $specialty, 'students' => [], 'keywords' => []];
    }
}

// === RUN TEST ===
echo "==============================\n";
echo "TEST: findMatchingStudents('Cơ xương khớp', 'tôi bị đau chân hãy tìm kiếm sinh viên phù hợp')\n";
echo "==============================\n\n";

$result = findMatchingStudents('Cơ xương khớp', 'tôi bị đau chân hãy tìm kiếm sinh viên phù hợp');

echo "\n=== FINAL RESULT ===\n";
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

echo "\n✅ Students found: " . count($result['students']) . "\n";
foreach ($result['students'] as $s) {
    echo "  → ID:{$s['id']} {$s['name']} (verified:" . ($s['verified']?'Y':'N') . ")\n";
    if (!empty($s['matched_post'])) {
        echo "    Post: {$s['matched_post']['post_title']} → {$s['matched_post']['post_url']}\n";
    }
}

echo "</pre>";
