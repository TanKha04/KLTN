<?php
$lines = file('dashboard_patient.php');
$css = implode('', array_slice($lines, 4086, 4800 - 4086));
$html = implode('', array_slice($lines, 722, 898 - 722));

$html = str_replace('Bệnh nhân', 'Sinh viên Y khoa', $html);
$html = str_replace('patient', 'student', $html);
$html = str_replace('bi-heart-pulse-fill', 'bi-mortarboard-fill', $html);
$html = str_replace('Đăng tin tuyển dụng', 'Đăng tin ứng tuyển', $html);
$html = str_replace('Tìm kiếm sinh viên y khoa để chăm sóc sức khỏe tại nhà', 'Tìm kiếm bệnh nhân cần chăm sóc sức khỏe tại nhà', $html);
$html = str_replace('showSection(\'create-post\', \'Tạo tin tuyển dụng\')', 'showSection(\'create-post\', \'Tạo tin ứng tuyển\')', $html);
$html = str_replace('Tìm sinh viên Y', 'Tìm bệnh nhân', $html);
$html = str_replace('Duyệt danh sách sinh viên y khoa đang tìm việc', 'Duyệt danh sách bệnh nhân đang tìm người chăm sóc', $html);
$html = str_replace('Lịch sử giao việc', 'Lịch sử nhận việc', $html);
$html = str_replace('Theo dõi các sinh viên đã được bạn chọn', 'Theo dõi các công việc bạn đã nhận', $html);
$html = str_replace('recentAssignCount', 'recentAcceptCount', $html);
$html = str_replace('và sinh viên bạn quan tâm', 'và tin tuyển dụng bạn quan tâm', $html);

file_put_contents('scratch_student_hero.html', $css . "\n" . $html);
echo 'Done';
?>
