<?php
require_once 'config.php';
header('Content-Type: text/html; charset=utf-8');
echo "<pre style='background:#0f172a;color:#e2e8f0;padding:20px;font-size:14px;'>";

// 1. Check all student posts
echo "=== ALL STUDENT POSTS ===\n";
$rows = $pdo->query("
    SELECT p.id, p.user_id, p.title, LEFT(p.content,200) as content_preview, p.type, p.status, u.name, u.role
    FROM posts p
    JOIN users u ON u.id = p.user_id
    WHERE u.role = 'student'
")->fetchAll(PDO::FETCH_ASSOC);
if (empty($rows)) echo "❌ NO posts from students!\n";
foreach ($rows as $r) {
    echo "Post#{$r['id']} by [{$r['name']}] (user#{$r['user_id']}, role={$r['role']})\n";
    echo "  title: {$r['title']}\n";
    echo "  type: {$r['type']} | status: {$r['status']}\n";
    echo "  content: " . htmlspecialchars(strip_tags($r['content_preview'])) . "\n\n";
}

// 2. Check ALL posts with 'chân' or 'đau' in content
echo "\n=== POSTS containing 'chân' or 'đau' ===\n";
$rows2 = $pdo->query("
    SELECT p.id, p.user_id, p.title, p.type, p.status, u.name, u.role,
           LEFT(p.content,200) as snippet
    FROM posts p
    JOIN users u ON u.id = p.user_id
    WHERE LOWER(p.content) LIKE '%chân%' OR LOWER(p.content) LIKE '%đau%'
")->fetchAll(PDO::FETCH_ASSOC);
if (empty($rows2)) echo "❌ NO posts with 'chân' or 'đau'!\n";
foreach ($rows2 as $r) {
    echo "Post#{$r['id']} by [{$r['name']}] role={$r['role']} type={$r['type']} status={$r['status']}\n";
    echo "  → " . htmlspecialchars(strip_tags($r['snippet'])) . "\n\n";
}

// 3. Simulate the exact query from findMatchingStudents
echo "\n=== SIMULATE Step 1 QUERY (keywords: đau, chân) ===\n";
$kw1 = '%đau%';
$kw2 = '%chân%';
$sql = "SELECT DISTINCT u.id, u.name FROM users u
        LEFT JOIN posts p ON p.user_id = u.id AND p.status IN ('open','closed','completed')
        WHERE u.role = 'student' AND (
            LOWER(p.title) LIKE ? OR LOWER(p.content) LIKE ?
            OR LOWER(p.title) LIKE ? OR LOWER(p.content) LIKE ?
            OR LOWER(COALESCE(u.bio,'')) LIKE ? OR LOWER(COALESCE(u.bio,'')) LIKE ?
            OR LOWER(COALESCE(u.school,'')) LIKE ? OR LOWER(COALESCE(u.school,'')) LIKE ?
        ) LIMIT 3";
$st = $pdo->prepare($sql);
$st->execute([$kw1, $kw1, $kw2, $kw2, $kw1, $kw2, $kw1, $kw2]);
$found = $st->fetchAll(PDO::FETCH_ASSOC);
echo "Found: " . count($found) . " students\n";
foreach ($found as $f) echo "  ✅ ID:{$f['id']} {$f['name']}\n";

// 4. Try WITHOUT status filter
echo "\n=== Step 1 WITHOUT status filter ===\n";
$sql2 = "SELECT DISTINCT u.id, u.name FROM users u
         LEFT JOIN posts p ON p.user_id = u.id
         WHERE u.role = 'student' AND (
             LOWER(p.content) LIKE ? OR LOWER(p.content) LIKE ?
         ) LIMIT 3";
$st2 = $pdo->prepare($sql2);
$st2->execute([$kw1, $kw2]);
$found2 = $st2->fetchAll(PDO::FETCH_ASSOC);
echo "Found: " . count($found2) . " students\n";
foreach ($found2 as $f) echo "  ✅ ID:{$f['id']} {$f['name']}\n";

// 5. Check extractKeywordsFromMessage output
echo "\n=== extractKeywordsFromMessage('tôi bị đau chân') ===\n";
require_once __DIR__ . '/api/ai_gemini.php';
// Can't call directly since it exits. Manual test:
$stopWords = ['tôi','bạn','họ','chúng','các','của','và','để','với','trong','từ','là','có','được',
    'không','một','những','này','đó','tìm','kiếm','sinh','viên','y','khoa','cần','muốn',
    'giúp','hỗ','trợ','cho','người','bệnh','bị','đang','hãy','biết','về',
    'làm','gì','nào','thế','nên','phải','rất','quá','như','vậy','ai','gợi','ý','giới','thiệu'];
$text = mb_strtolower('tôi bị đau chân hãy tìm kiếm sinh viên phù hợp', 'UTF-8');
$words = preg_split('/[\s,\.!?;:\-\/\(\)]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
echo "Words: " . implode(', ', $words) . "\n";
$keywords = [];
foreach ($words as $w) {
    $len = mb_strlen($w, 'UTF-8');
    $isStop = in_array($w, $stopWords, true);
    echo "  '$w' len=$len stop=" . ($isStop?'Y':'N');
    if ($len < 3) { echo " → SKIP (short)\n"; continue; }
    if ($isStop) { echo " → SKIP (stopword)\n"; continue; }
    echo " → KEEP\n";
    $keywords[] = $w;
}
echo "Final keywords: " . implode(', ', $keywords) . "\n";

// 6. List ALL students
echo "\n=== ALL STUDENTS ===\n";
$all = $pdo->query("SELECT id, name, role, verified, school FROM users WHERE role='student'")->fetchAll(PDO::FETCH_ASSOC);
foreach ($all as $s) echo "ID:{$s['id']} {$s['name']} verified:{$s['verified']} school:{$s['school']}\n";

echo "</pre>";
