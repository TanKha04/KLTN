<?php
// Temporary debug script - DELETE AFTER USE
require_once 'config.php';

header('Content-Type: text/plain; charset=utf-8');

// 1. Count students
$stmt = $pdo->query("SELECT COUNT(*) as total, SUM(verified) as verified_count FROM users WHERE role='student'");
$counts = $stmt->fetch();
echo "=== STUDENTS IN DB ===\n";
echo "Total students: " . $counts['total'] . "\n";
echo "Verified students: " . $counts['verified_count'] . "\n\n";

// 2. Show all students
$stmt = $pdo->query("SELECT id, name, school, class_code, LEFT(COALESCE(bio,''), 80) as bio, verified, last_activity FROM users WHERE role='student' LIMIT 10");
$students = $stmt->fetchAll();
echo "=== STUDENT LIST ===\n";
foreach ($students as $s) {
    echo "ID:{$s['id']} | Name:{$s['name']} | Verified:{$s['verified']} | School:{$s['school']} | Class:{$s['class_code']}\n";
    echo "  Bio: {$s['bio']}\n";
}

echo "\n=== TEST INTENT DETECTION ===\n";
$testMessages = [
    "tôi bị nhức đầu tôi muốn tìm kiếm sinh viên phù hợp",
    "tìm sinh viên y khoa",
    "hãy kiếm sinh viên phù hợp với tôi",
    "muốn tìm sinh viên",
    "sinh viên phù hợp với tôi",
];

$triggerPatterns = [
    'tìm\s*kiếm?\s*sinh\s*viên',
    'kiếm\s*sinh\s*viên',
    'sinh\s*viên.*phù\s*hợp',
    'sinh\s*viên\s*y\s*khoa',
    'giới\s*thiệu.*sinh\s*viên',
    'gợi\s*ý.*sinh\s*viên',
    'cần.*sinh\s*viên',
    'muốn.*tìm.*sinh\s*viên',
    'tìm.*người.*hỗ\s*trợ',
    'tìm.*người.*chăm\s*sóc',
    'ai.*phù\s*hợp.*với\s*tôi',
];

foreach ($testMessages as $msg) {
    $q = mb_strtolower($msg, 'UTF-8');
    $matched = false;
    $matchedPat = '';
    foreach ($triggerPatterns as $pat) {
        if (preg_match('/' . $pat . '/ui', $q)) {
            $matched = true;
            $matchedPat = $pat;
            break;
        }
    }
    echo ($matched ? "✓ MATCH" : "✗ NO MATCH") . " | \"$msg\"\n";
    if ($matched) echo "   → Pattern: $matchedPat\n";
}
