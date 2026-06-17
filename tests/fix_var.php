<?php
$code = file_get_contents('dashboard_student.php');
$old = '<?php if ($userCanPost): ?>';
$new = '<?php if ($canPost): ?>';
$count = 0;
$code = str_replace($old, $new, $code, $count);
file_put_contents('dashboard_student.php', $code);
echo "Replaced $count occurrences of \$userCanPost with \$canPost\n";
$remaining = substr_count($code, 'userCanPost');
echo "Remaining occurrences: $remaining\n";
?>
