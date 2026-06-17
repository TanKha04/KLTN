<?php
/**
 * TEST: AI Student Search - run this to verify the API works
 * Access: http://localhost:8080/DACN2/test_ai_students.php
 */
require_once 'config.php';

// Simulate session
if (!isset($_SESSION['user_id'])) {
    // Try to get any user for testing
    $stmt = $pdo->query("SELECT id, name, role FROM users LIMIT 1");
    $u = $stmt->fetch();
    if ($u) {
        $_SESSION['user_id'] = $u['id'];
        $_SESSION['name']    = $u['name'];
        $_SESSION['role']    = $u['role'];
    }
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html><head><meta charset="UTF-8">
<title>Test AI Student Search</title>
<style>
body{font-family:monospace;padding:20px;background:#0f172a;color:#e2e8f0;}
.box{background:#1e293b;border-radius:8px;padding:16px;margin:12px 0;}
.ok{color:#4ade80;} .err{color:#f87171;} .info{color:#60a5fa;}
pre{margin:0;white-space:pre-wrap;word-break:break-all;}
h2{color:#818cf8;}
</style>
</head><body>
<h2>🧪 Test: AI Student Search API</h2>

<?php
// ---- Test 1: Detect symptom ----
echo '<div class="box">';
echo '<h3>Test 1: DB connection & students table</h3>';
try {
    $cnt = $pdo->query("SELECT COUNT(*) FROM users WHERE role='student'")->fetchColumn();
    echo "<p class='ok'>✅ Found <strong>$cnt</strong> students in DB</p>";
    $verified = $pdo->query("SELECT COUNT(*) FROM users WHERE role='student' AND verified=1")->fetchColumn();
    echo "<p class='ok'>✅ Verified students: <strong>$verified</strong></p>";

    // Show a few students
    $samples = $pdo->query("SELECT id, name, school, verified, last_activity FROM users WHERE role='student' LIMIT 5")->fetchAll();
    echo '<pre>';
    foreach ($samples as $s) {
        echo "ID:{$s['id']} | {$s['name']} | {$s['school']} | verified:{$s['verified']}\n";
    }
    echo '</pre>';
} catch (Exception $e) {
    echo "<p class='err'>❌ DB Error: " . $e->getMessage() . "</p>";
}
echo '</div>';

// ---- Test 2: Posts table ----
echo '<div class="box">';
echo '<h3>Test 2: Posts from students</h3>';
try {
    $postCnt = $pdo->query("SELECT COUNT(*) FROM posts p JOIN users u ON u.id=p.user_id WHERE u.role='student' AND p.status='open'")->fetchColumn();
    echo "<p class='ok'>✅ Open posts by students: <strong>$postCnt</strong></p>";

    $posts = $pdo->query("SELECT p.id, p.title, LEFT(p.content,100) as snippet, u.name FROM posts p JOIN users u ON u.id=p.user_id WHERE u.role='student' AND p.status='open' LIMIT 5")->fetchAll();
    echo '<pre>';
    foreach ($posts as $p) {
        echo "Post#{$p['id']} by {$p['name']}: {$p['title']}\n  → " . htmlspecialchars($p['snippet']) . "\n\n";
    }
    echo '</pre>';
} catch (Exception $e) {
    echo "<p class='err'>❌ Error: " . $e->getMessage() . "</p>";
}
echo '</div>';

// ---- Test 3: Simulate API call ----
echo '<div class="box">';
echo '<h3>Test 3: Simulate API call for "tôi bị ho"</h3>';

$ch = curl_init('http://localhost:8080/DACN2/api/ai_gemini.php');
// try different port
if (!$ch) $ch = curl_init('http://localhost/DACN2/api/ai_gemini.php');

$cookies = session_name() . '=' . session_id();
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode(['message' => 'tôi bị ho', 'action' => 'chat']),
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Cookie: ' . $cookies],
    CURLOPT_TIMEOUT => 15,
    CURLOPT_SSL_VERIFYPEER => false,
]);
$res = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);

if ($err) {
    echo "<p class='err'>❌ cURL error: $err</p>";
} else {
    echo "<p class='info'>HTTP $code</p>";
    $data = json_decode($res, true);
    if ($data) {
        echo "<p class='ok'>✅ success: " . ($data['success'] ? 'true' : 'false') . "</p>";
        echo "<p class='info'>source: " . ($data['source'] ?? 'n/a') . "</p>";
        if (isset($data['students'])) {
            $sc = count($data['students']['students'] ?? []);
            echo "<p class='ok'>✅ students returned: <strong>$sc</strong></p>";
            echo '<pre>' . htmlspecialchars(json_encode($data['students'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre>';
        } else {
            echo "<p class='err'>❌ No 'students' key in response</p>";
            echo '<pre>' . htmlspecialchars(json_encode(array_diff_key($data, ['reply'=>1]), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre>';
        }
    } else {
        echo "<p class='err'>❌ Invalid JSON: " . htmlspecialchars(substr($res,0,500)) . "</p>";
    }
}
echo '</div>';

// ---- Test 4: Direct findMatchingStudents ----
echo '<div class="box">';
echo '<h3>Test 4: Direct findMatchingStudents() call</h3>';
try {
    // Inline the logic here to test
    $specialty = 'Tai mũi họng';
    $freeKeywords = ['ho'];
    $limit = 3;

    $postClauses = [];
    $postParams  = [];
    foreach ($freeKeywords as $kw) {
        $like = '%' . $kw . '%';
        $postClauses[] = "(LOWER(p.title) LIKE ? OR LOWER(p.content) LIKE ?)";
        $postParams[] = $like; $postParams[] = $like;
    }
    $bioClauses = [];
    foreach ($freeKeywords as $kw) {
        $like = '%' . $kw . '%';
        $bioClauses[] = "(LOWER(COALESCE(u.bio,'')) LIKE ? OR LOWER(COALESCE(u.school,'')) LIKE ? OR LOWER(COALESCE(u.class_code,'')) LIKE ?)";
        $postParams[] = $like; $postParams[] = $like; $postParams[] = $like;
    }
    $postParams[] = $limit;
    $allClauses = array_merge($postClauses, $bioClauses);
    $sql = "SELECT DISTINCT u.id, u.name, u.school FROM users u
            LEFT JOIN posts p ON p.user_id = u.id AND p.status = 'open'
            WHERE u.role = 'student'
              AND (" . implode(' OR ', $allClauses) . ")
            GROUP BY u.id LIMIT ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($postParams);
    $rows = $stmt->fetchAll();
    if ($rows) {
        echo "<p class='ok'>✅ Step1 found: " . count($rows) . " students</p>";
        echo '<pre>' . htmlspecialchars(json_encode($rows, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)) . '</pre>';
    } else {
        echo "<p class='err'>Step1: 0 students matched keyword 'ho' in posts/bio</p>";
        // Fallback: any student
        $any = $pdo->query("SELECT id, name, school FROM users WHERE role='student' LIMIT 3")->fetchAll();
        echo "<p class='info'>Any students available: " . count($any) . "</p>";
        echo '<pre>' . htmlspecialchars(json_encode($any, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)) . '</pre>';
    }
} catch (Exception $e) {
    echo "<p class='err'>❌ Exception: " . $e->getMessage() . "</p>";
}
echo '</div>';
?>

<div class="box">
<h3>✅ Quick fix check</h3>
<p>If Test 3 shows <span class="err">No 'students' key</span>, the API is not detecting intent.</p>
<p>If Test 4 shows <span class="err">0 students</span>, the DB has no students with matching posts — but any-student fallback should still work.</p>
<p><a href="ai_assistant.php" style="color:#818cf8">→ Go to AI Assistant</a></p>
</div>
</body></html>
