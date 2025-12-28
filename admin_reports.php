<?php
require_once 'config.php';
require_admin();

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type = $_POST['type'] ?? '';
    try {
        if ($type === 'update_feedback') {
            $id = (int)($_POST['id'] ?? 0);
            $status = $_POST['status'] ?? 'pending';
            $response = trim($_POST['admin_response'] ?? '');
            if ($id > 0) {
                $stmt = $pdo->prepare('UPDATE user_feedback SET status = ?, admin_response = ?, updated_at = NOW() WHERE id = ?');
                $stmt->execute([$status, $response, $id]);
                $success = 'Đã cập nhật trạng thái phản hồi.';
            }
        } elseif ($type === 'delete_feedback') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $stmt = $pdo->prepare('DELETE FROM user_feedback WHERE id = ?');
                $stmt->execute([$id]);
                $success = 'Đã xóa phản hồi.';
            }
        } elseif ($type === 'update_report') {
            $id = (int)($_POST['id'] ?? 0);
            $status = $_POST['status'] ?? 'pending';
            $note = trim($_POST['admin_note'] ?? '');
            if ($id > 0) {
                $stmt = $pdo->prepare('UPDATE reports SET status = ?, admin_note = ?, processed_at = NOW() WHERE id = ?');
                $stmt->execute([$status, $note, $id]);
                $success = 'Đã cập nhật báo cáo.';
            }
        } elseif ($type === 'delete_report') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $stmt = $pdo->prepare('DELETE FROM reports WHERE id = ?');
                $stmt->execute([$id]);
                $success = 'Đã xóa báo cáo.';
            }
        } elseif ($type === 'update_comment_report') {
            $id = (int)($_POST['id'] ?? 0);
            $status = $_POST['status'] ?? 'pending';
            $note = trim($_POST['admin_note'] ?? '');
            if ($id > 0) {
                $stmt = $pdo->prepare('UPDATE comment_reports SET status = ?, admin_note = ?, resolved_at = NOW() WHERE id = ?');
                $stmt->execute([$status, $note, $id]);
                $success = 'Đã cập nhật báo cáo bình luận.';
            }
        } elseif ($type === 'delete_comment') {
            $commentId = (int)($_POST['comment_id'] ?? 0);
            $reportId = (int)($_POST['report_id'] ?? 0);
            if ($commentId > 0) {
                $stmt = $pdo->prepare('DELETE FROM comments WHERE id = ?');
                $stmt->execute([$commentId]);
                if ($reportId > 0) {
                    $stmt = $pdo->prepare('UPDATE comment_reports SET status = "resolved", admin_note = "Bình luận đã bị xóa", resolved_at = NOW() WHERE id = ?');
                    $stmt->execute([$reportId]);
                }
                $success = 'Đã xóa bình luận vi phạm.';
            }
        }
    } catch (Throwable $e) {
        error_log('Admin reports error: ' . $e->getMessage());
        $error = 'Có lỗi khi cập nhật: ' . $e->getMessage();
    }
}

// Lấy phản hồi người dùng
$feedbacks = [];
try {
    $feedbacks = $pdo->query('
        SELECT uf.*, u.name AS user_name, u.email AS user_email, u.avatar AS user_avatar 
        FROM user_feedback uf 
        JOIN users u ON u.id = uf.user_id 
        ORDER BY uf.created_at DESC
    ')->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('Fetch feedbacks error: ' . $e->getMessage());
}

// Lấy báo cáo vi phạm
$reports = [];
try {
    $reports = $pdo->query('
        SELECT r.*, 
               u.name AS reporter_name, u.email AS reporter_email, u.avatar AS reporter_avatar,
               ru.name AS reported_name, ru.email AS reported_email
        FROM reports r 
        JOIN users u ON u.id = r.reporter_id 
        LEFT JOIN users ru ON ru.id = r.reported_user_id
        ORDER BY r.created_at DESC
    ')->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('Fetch reports error: ' . $e->getMessage());
}

// Lấy báo cáo bình luận
$commentReports = [];
try {
    $commentReports = $pdo->query('
        SELECT cr.*, 
               c.content AS comment_content, 
               c.post_id,
               u.name AS reporter_name, 
               u.email AS reporter_email,
               u.avatar AS reporter_avatar,
               cu.name AS commenter_name,
               cu.email AS commenter_email,
               p.title AS post_title
        FROM comment_reports cr 
        JOIN comments c ON c.id = cr.comment_id 
        JOIN users u ON u.id = cr.reporter_id 
        JOIN users cu ON cu.id = c.user_id
        LEFT JOIN posts p ON p.id = c.post_id
        ORDER BY cr.created_at DESC
    ')->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('Fetch comment reports error: ' . $e->getMessage());
}

$feedbackCount = count($feedbacks);
$reportCount = count($reports);
$commentReportCount = count($commentReports);
$pendingFeedback = count(array_filter($feedbacks, fn($f) => ($f['status'] ?? 'pending') === 'pending'));
$pendingReport = count(array_filter($reports, fn($r) => ($r['status'] ?? 'pending') === 'pending'));
$pendingCommentReport = count(array_filter($commentReports, fn($r) => ($r['status'] ?? 'pending') === 'pending'));

$isEmbed = isset($_GET['embed']) && $_GET['embed'] == '1';

require_once 'header.php';
?>

<style>
.rpt-page { background: linear-gradient(135deg, #0b3f91 0%, #1e40af 50%, #3b82f6 100%); min-height: 100vh; margin: -1.5rem -0.75rem; padding: 2rem; }
.rpt-container { max-width: 1400px; margin: 0 auto; }
.rpt-header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1.5rem; margin-bottom: 2rem; }
.rpt-header-left { display: flex; align-items: center; gap: 1rem; }
.rpt-icon { width: 70px; height: 70px; background: linear-gradient(135deg, #e0ecff 0%, #c7d2fe 100%); border-radius: 20px; display: flex; align-items: center; justify-content: center; font-size: 2rem; color: #0b3f91; box-shadow: 0 10px 30px rgba(11, 63, 145, 0.25); }
.rpt-title { color: #fff; }
.rpt-title h1 { font-size: 2rem; font-weight: 700; margin: 0 0 0.25rem 0; text-shadow: 0 2px 10px rgba(0,0,0,0.2); }
.rpt-title p { margin: 0; opacity: 0.9; font-size: 1rem; }
.rpt-back { display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.75rem 1.5rem; background: rgba(255,255,255,0.18); backdrop-filter: blur(10px); border: 1px solid rgba(255,255,255,0.35); border-radius: 14px; color: #fff; text-decoration: none; font-weight: 600; transition: all 0.3s ease; }
.rpt-back:hover { background: #fff; color: #0b3f91; transform: translateX(-5px); }
.rpt-alert { display: flex; align-items: center; gap: 0.75rem; padding: 1rem 1.5rem; border-radius: 14px; margin-bottom: 1.5rem; font-weight: 500; }
.rpt-alert.success { background: linear-gradient(135deg, #d1fae5, #a7f3d0); color: #065f46; border: 1px solid #6ee7b7; }
.rpt-alert.error { background: linear-gradient(135deg, #fee2e2, #fecaca); color: #991b1b; border: 1px solid #fca5a5; }
.rpt-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.5rem; margin-bottom: 2rem; }
.rpt-stat { background: #fff; border-radius: 20px; padding: 1.5rem; display: flex; align-items: center; gap: 1rem; box-shadow: 0 10px 40px rgba(0,0,0,0.1); transition: all 0.3s ease; position: relative; overflow: hidden; }
.rpt-stat::before { content: ''; position: absolute; top: 0; left: 0; width: 5px; height: 100%; }
.rpt-stat:hover { transform: translateY(-5px); box-shadow: 0 20px 50px rgba(0,0,0,0.15); }
.rpt-stat.purple::before { background: linear-gradient(180deg, #93c5fd, #3b82f6); }
.rpt-stat.orange::before { background: linear-gradient(180deg, #c7d2fe, #6366f1); }
.rpt-stat.green::before { background: linear-gradient(180deg, #86efac, #22c55e); }
.rpt-stat.yellow::before { background: linear-gradient(180deg, #bae6fd, #0ea5e9); }
.rpt-stat.red::before { background: linear-gradient(180deg, #fca5a5, #ef4444); }
.rpt-stat-icon { width: 55px; height: 55px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 1.4rem; }
.rpt-stat.purple .rpt-stat-icon { background: linear-gradient(135deg, #e0f2ff, #c7d2fe); color: #0b3f91; }
.rpt-stat.orange .rpt-stat-icon { background: linear-gradient(135deg, #e0e7ff, #c7d2fe); color: #0b3f91; }
.rpt-stat.green .rpt-stat-icon { background: linear-gradient(135deg, #dcfce7, #bbf7d0); color: #166534; }
.rpt-stat.yellow .rpt-stat-icon { background: linear-gradient(135deg, #e0f2fe, #bae6fd); color: #0369a1; }
.rpt-stat.red .rpt-stat-icon { background: linear-gradient(135deg, #fee2e2, #fecaca); color: #dc2626; }
.rpt-stat-info h3 { font-size: 1.75rem; font-weight: 800; margin: 0; color: #1e293b; }
.rpt-stat-info p { margin: 0; color: #64748b; font-weight: 500; font-size: 0.9rem; }
.rpt-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(500px, 1fr)); gap: 1.5rem; }
.rpt-card { background: #fff; border-radius: 24px; box-shadow: 0 20px 60px rgba(0,0,0,0.1); overflow: hidden; }
.rpt-card-header { padding: 1.5rem 2rem; background: linear-gradient(135deg, #f2f6ff, #e0ecff); border-bottom: 1px solid rgba(148,163,184,0.25); display: flex; align-items: center; gap: 0.75rem; }
.rpt-card-header i { font-size: 1.5rem; }
.rpt-card-header.feedback i { color: #2563eb; }
.rpt-card-header.report i { color: #0ea5e9; }
.rpt-card-header h3 { margin: 0; font-size: 1.25rem; font-weight: 700; color: #1e293b; }
.rpt-card-header .badge { margin-left: auto; padding: 0.5rem 1rem; border-radius: 20px; font-size: 0.85rem; font-weight: 600; }
.rpt-card-header.feedback .badge { background: linear-gradient(135deg, #e0ecff, #c7d2fe); color: #1d4ed8; }
.rpt-card-header.report .badge { background: linear-gradient(135deg, #e0f7ff, #b3ecff); color: #0369a1; }
.rpt-card-body { padding: 1.5rem 2rem; max-height: 600px; overflow-y: auto; }
.rpt-empty { text-align: center; padding: 3rem 2rem; }
.rpt-empty-icon { width: 80px; height: 80px; border-radius: 50%; background: linear-gradient(135deg, #f1f5f9, #e2e8f0); display: flex; align-items: center; justify-content: center; margin: 0 auto 1rem; font-size: 2rem; color: #94a3b8; }
.rpt-empty h4 { font-size: 1.1rem; font-weight: 600; color: #64748b; margin: 0; }
.rpt-item { background: linear-gradient(135deg, #f5f8ff, #eef2ff); border-radius: 16px; padding: 1.25rem; margin-bottom: 1rem; border: 1px solid rgba(148,163,184,0.25); transition: all 0.3s ease; }
.rpt-item:hover { transform: translateX(5px); box-shadow: 0 5px 20px rgba(0,0,0,0.08); }
.rpt-item:last-child { margin-bottom: 0; }
.rpt-item-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 0.75rem; }
.rpt-item-title { font-weight: 700; color: #1e293b; font-size: 1rem; margin-bottom: 0.25rem; }
.rpt-item-user { font-size: 0.85rem; color: #64748b; display: flex; align-items: center; gap: 0.5rem; }
.rpt-item-user i { color: #94a3b8; }
.rpt-item-date { font-size: 0.8rem; color: #94a3b8; display: flex; align-items: center; gap: 0.35rem; }
.rpt-item-content { background: #fff; border-radius: 10px; padding: 1rem; margin-bottom: 1rem; font-size: 0.95rem; color: #475569; line-height: 1.6; white-space: pre-wrap; border: 1px solid #e2e8f0; }
.rpt-item-form { display: flex; gap: 0.75rem; align-items: center; }
.rpt-item-form select { flex: 1; padding: 0.6rem 1rem; border: 2px solid #e2e8f0; border-radius: 12px; font-size: 0.9rem; transition: all 0.3s ease; background: #fff; }
.rpt-item-form select:focus { outline: none; border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12); }
.rpt-item-form button { padding: 0.65rem 1.4rem; background: linear-gradient(135deg, #2563eb, #4f46e5); color: #fff; border: none; border-radius: 12px; font-weight: 600; cursor: pointer; transition: all 0.3s ease; box-shadow: 0 10px 25px rgba(37, 99, 235, 0.3); }
.rpt-item-form button:hover { transform: translateY(-2px); box-shadow: 0 14px 30px rgba(37, 99, 235, 0.35); }
.rpt-status { display: inline-flex; align-items: center; gap: 0.35rem; padding: 0.35rem 0.75rem; border-radius: 20px; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; }
.rpt-status.pending { background: #dbeafe; color: #1d4ed8; }
.rpt-status.in_progress, .rpt-status.reviewing { background: #e0f2ff; color: #0369a1; }
.rpt-status.resolved { background: #dcfce7; color: #047857; }
.rpt-status.dismissed { background: #e2e8f0; color: #475569; }
.rpt-btn-secondary { padding: 0.5rem 1rem; background: #f1f5f9; color: #64748b; border: none; border-radius: 8px; font-size: 0.85rem; font-weight: 600; cursor: pointer; transition: all 0.3s; display: inline-flex; align-items: center; gap: 0.35rem; }
.rpt-btn-secondary:hover { background: #e2e8f0; color: #475569; }
.rpt-btn-danger { padding: 0.5rem 1rem; background: #fee2e2; color: #dc2626; border: none; border-radius: 8px; font-size: 0.85rem; font-weight: 600; cursor: pointer; transition: all 0.3s; display: inline-flex; align-items: center; gap: 0.35rem; }
.rpt-btn-danger:hover { background: #fecaca; }
@media (max-width: 991px) { .rpt-grid { grid-template-columns: 1fr; } }
@media (max-width: 767px) { .rpt-page { padding: 1rem; } .rpt-header { flex-direction: column; align-items: flex-start; } .rpt-stats { grid-template-columns: 1fr 1fr; } .rpt-item-form { flex-direction: column; } .rpt-item-form select, .rpt-item-form button { width: 100%; } }
</style>

<div class="rpt-page">
    <div class="rpt-container">
        <div class="rpt-header">
            <div class="rpt-header-left">
                <div class="rpt-icon"><i class="bi bi-flag-fill"></i></div>
                <div class="rpt-title">
                    <h1>Quản lý báo cáo & phản hồi</h1>
                    <p>Xử lý các phản hồi và báo cáo từ người dùng</p>
                </div>
            </div>
            <?php if ($isEmbed): ?>
            <a href="#" class="rpt-back" onclick="window.parent.showSection('welcome', 'Bảng điều khiển'); return false;"><i class="bi bi-arrow-left"></i> Quay lại</a>
            <?php else: ?>
            <a href="admin.php" class="rpt-back"><i class="bi bi-arrow-left"></i> Quay lại</a>
            <?php endif; ?>
        </div>

        <?php if ($success): ?>
            <div class="rpt-alert success"><i class="bi bi-check-circle-fill"></i> <?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="rpt-alert error"><i class="bi bi-exclamation-circle-fill"></i> <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="rpt-stats">
            <div class="rpt-stat purple">
                <div class="rpt-stat-icon"><i class="bi bi-chat-dots-fill"></i></div>
                <div class="rpt-stat-info"><h3><?php echo $feedbackCount; ?></h3><p>Tổng phản hồi</p></div>
            </div>
            <div class="rpt-stat orange">
                <div class="rpt-stat-icon"><i class="bi bi-flag-fill"></i></div>
                <div class="rpt-stat-info"><h3><?php echo $reportCount; ?></h3><p>Báo cáo vi phạm</p></div>
            </div>
            <div class="rpt-stat green">
                <div class="rpt-stat-icon"><i class="bi bi-chat-left-text-fill"></i></div>
                <div class="rpt-stat-info"><h3><?php echo $commentReportCount; ?></h3><p>Báo cáo bình luận</p></div>
            </div>
            <div class="rpt-stat yellow">
                <div class="rpt-stat-icon"><i class="bi bi-hourglass-split"></i></div>
                <div class="rpt-stat-info"><h3><?php echo $pendingFeedback; ?></h3><p>Phản hồi chờ xử lý</p></div>
            </div>
            <div class="rpt-stat red">
                <div class="rpt-stat-icon"><i class="bi bi-exclamation-triangle-fill"></i></div>
                <div class="rpt-stat-info"><h3><?php echo $pendingReport + $pendingCommentReport; ?></h3><p>Báo cáo chờ xử lý</p></div>
            </div>
        </div>

        <div class="rpt-grid">
            <!-- Feedback Column -->
            <div class="rpt-card">
                <div class="rpt-card-header feedback">
                    <i class="bi bi-chat-dots-fill"></i>
                    <h3>Phản hồi người dùng</h3>
                    <span class="badge"><?php echo $feedbackCount; ?> phản hồi</span>
                </div>
                <div class="rpt-card-body">
                    <?php if (empty($feedbacks)): ?>
                        <div class="rpt-empty">
                            <div class="rpt-empty-icon"><i class="bi bi-chat-dots"></i></div>
                            <h4>Chưa có phản hồi nào</h4>
                            <p style="color: #94a3b8; font-size: 0.9rem; margin-top: 0.5rem;">Phản hồi từ người dùng sẽ xuất hiện tại đây</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($feedbacks as $f): ?>
                            <div class="rpt-item" id="feedback-<?php echo $f['id']; ?>">
                                <div class="rpt-item-header">
                                    <div>
                                        <div class="rpt-item-title">
                                            <i class="bi bi-envelope-fill me-1" style="color: #3b82f6;"></i>
                                            <?php echo htmlspecialchars($f['subject'] ?? 'Không có tiêu đề'); ?>
                                        </div>
                                        <div class="rpt-item-user">
                                            <i class="bi bi-person-fill"></i>
                                            <?php echo htmlspecialchars($f['user_name'] ?? 'Ẩn danh'); ?>
                                            <span style="color: #94a3b8;">(<?php echo htmlspecialchars($f['user_email'] ?? ''); ?>)</span>
                                        </div>
                                    </div>
                                    <div class="text-end">
                                        <span class="rpt-status <?php echo $f['status'] ?? 'pending'; ?>">
                                            <?php 
                                            $statusText = ['pending' => '⏳ Chờ xử lý', 'in_progress' => '🔄 Đang xử lý', 'resolved' => '✅ Đã giải quyết'];
                                            echo $statusText[$f['status'] ?? 'pending'] ?? $f['status'];
                                            ?>
                                        </span>
                                        <div class="rpt-item-date mt-1">
                                            <i class="bi bi-clock"></i>
                                            <?php echo date('d/m/Y H:i', strtotime($f['created_at'] ?? 'now')); ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="rpt-item-content"><?php echo nl2br(htmlspecialchars($f['message'] ?? '')); ?></div>
                                
                                <?php if (!empty($f['admin_response'])): ?>
                                <div style="background: #dcfce7; border: 1px solid #86efac; border-radius: 10px; padding: 0.75rem; margin-bottom: 1rem;">
                                    <div style="font-size: 0.8rem; color: #166534; font-weight: 600; margin-bottom: 0.25rem;">
                                        <i class="bi bi-reply-fill"></i> Phản hồi của Admin:
                                    </div>
                                    <div style="color: #15803d;"><?php echo nl2br(htmlspecialchars($f['admin_response'])); ?></div>
                                </div>
                                <?php endif; ?>
                                
                                <form method="post" class="rpt-item-form">
                                    <input type="hidden" name="type" value="update_feedback">
                                    <input type="hidden" name="id" value="<?php echo (int)$f['id']; ?>">
                                    <select name="status">
                                        <option value="pending" <?php echo ($f['status'] ?? '') === 'pending' ? 'selected' : ''; ?>>⏳ Chờ xử lý</option>
                                        <option value="in_progress" <?php echo ($f['status'] ?? '') === 'in_progress' ? 'selected' : ''; ?>>🔄 Đang xử lý</option>
                                        <option value="resolved" <?php echo ($f['status'] ?? '') === 'resolved' ? 'selected' : ''; ?>>✅ Đã giải quyết</option>
                                    </select>
                                    <button type="submit"><i class="bi bi-check2"></i> Cập nhật</button>
                                </form>
                                
                                <div style="display: flex; gap: 0.5rem; margin-top: 0.75rem;">
                                    <button onclick="toggleReplyForm(<?php echo $f['id']; ?>)" class="rpt-btn-secondary">
                                        <i class="bi bi-reply"></i> Trả lời
                                    </button>
                                    <form method="post" style="margin: 0;" onsubmit="return confirm('Xóa phản hồi này?');">
                                        <input type="hidden" name="type" value="delete_feedback">
                                        <input type="hidden" name="id" value="<?php echo (int)$f['id']; ?>">
                                        <button type="submit" class="rpt-btn-danger">
                                            <i class="bi bi-trash"></i> Xóa
                                        </button>
                                    </form>
                                </div>
                                
                                <div id="reply-form-<?php echo $f['id']; ?>" style="display: none; margin-top: 1rem;">
                                    <form method="post">
                                        <input type="hidden" name="type" value="update_feedback">
                                        <input type="hidden" name="id" value="<?php echo (int)$f['id']; ?>">
                                        <input type="hidden" name="status" value="resolved">
                                        <textarea name="admin_response" placeholder="Nhập phản hồi cho người dùng..." style="width: 100%; padding: 0.75rem; border: 2px solid #e2e8f0; border-radius: 10px; min-height: 80px; margin-bottom: 0.5rem;"><?php echo htmlspecialchars($f['admin_response'] ?? ''); ?></textarea>
                                        <button type="submit" style="background: linear-gradient(135deg, #10b981, #059669); color: #fff; border: none; padding: 0.5rem 1rem; border-radius: 8px; cursor: pointer;">
                                            <i class="bi bi-send"></i> Gửi phản hồi
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Reports Column -->
            <div class="rpt-card">
                <div class="rpt-card-header report">
                    <i class="bi bi-flag-fill"></i>
                    <h3>Báo cáo vi phạm</h3>
                    <span class="badge"><?php echo $reportCount; ?> báo cáo</span>
                </div>
                <div class="rpt-card-body">
                    <?php if (empty($reports)): ?>
                        <div class="rpt-empty">
                            <div class="rpt-empty-icon"><i class="bi bi-flag"></i></div>
                            <h4>Chưa có báo cáo nào</h4>
                            <p style="color: #94a3b8; font-size: 0.9rem; margin-top: 0.5rem;">Báo cáo vi phạm sẽ xuất hiện tại đây</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($reports as $r): ?>
                            <div class="rpt-item" id="report-<?php echo $r['id']; ?>">
                                <div class="rpt-item-header">
                                    <div>
                                        <div class="rpt-item-title">
                                            <i class="bi bi-exclamation-triangle-fill me-1" style="color: #f59e0b;"></i>
                                            <?php echo htmlspecialchars($r['reason_code'] ?? 'Không rõ lý do'); ?>
                                        </div>
                                        <div class="rpt-item-user">
                                            <i class="bi bi-person-fill"></i>
                                            Người báo cáo: <?php echo htmlspecialchars($r['reporter_name'] ?? 'Ẩn danh'); ?>
                                        </div>
                                        <?php if (!empty($r['reported_name'])): ?>
                                        <div class="rpt-item-user" style="color: #dc2626;">
                                            <i class="bi bi-person-x-fill"></i>
                                            Bị báo cáo: <?php echo htmlspecialchars($r['reported_name']); ?>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-end">
                                        <span class="rpt-status <?php echo $r['status'] ?? 'pending'; ?>">
                                            <?php 
                                            $statusText = ['pending' => '⏳ Chờ', 'reviewing' => '🔍 Đang xem', 'resolved' => '✅ Đã xử lý', 'dismissed' => '❌ Bác bỏ'];
                                            echo $statusText[$r['status'] ?? 'pending'] ?? $r['status'];
                                            ?>
                                        </span>
                                        <div class="rpt-item-date mt-1">
                                            <i class="bi bi-clock"></i>
                                            <?php echo date('d/m/Y H:i', strtotime($r['created_at'] ?? 'now')); ?>
                                        </div>
                                    </div>
                                </div>
                                <?php if (!empty($r['message'])): ?>
                                    <div class="rpt-item-content"><?php echo nl2br(htmlspecialchars($r['message'])); ?></div>
                                <?php endif; ?>
                                
                                <?php if (!empty($r['admin_note'])): ?>
                                <div style="background: #fef3c7; border: 1px solid #fcd34d; border-radius: 10px; padding: 0.75rem; margin-bottom: 1rem;">
                                    <div style="font-size: 0.8rem; color: #92400e; font-weight: 600; margin-bottom: 0.25rem;">
                                        <i class="bi bi-sticky-fill"></i> Ghi chú Admin:
                                    </div>
                                    <div style="color: #78350f;"><?php echo nl2br(htmlspecialchars($r['admin_note'])); ?></div>
                                </div>
                                <?php endif; ?>
                                
                                <form method="post" class="rpt-item-form">
                                    <input type="hidden" name="type" value="update_report">
                                    <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                                    <select name="status">
                                        <option value="pending" <?php echo ($r['status'] ?? '') === 'pending' ? 'selected' : ''; ?>>⏳ Chờ xử lý</option>
                                        <option value="reviewing" <?php echo ($r['status'] ?? '') === 'reviewing' ? 'selected' : ''; ?>>🔍 Đang xem xét</option>
                                        <option value="resolved" <?php echo ($r['status'] ?? '') === 'resolved' ? 'selected' : ''; ?>>✅ Đã xử lý</option>
                                        <option value="dismissed" <?php echo ($r['status'] ?? '') === 'dismissed' ? 'selected' : ''; ?>>❌ Bác bỏ</option>
                                    </select>
                                    <button type="submit"><i class="bi bi-check2"></i> Cập nhật</button>
                                </form>
                                
                                <div style="display: flex; gap: 0.5rem; margin-top: 0.75rem;">
                                    <button onclick="toggleNoteForm('report', <?php echo $r['id']; ?>)" class="rpt-btn-secondary">
                                        <i class="bi bi-sticky"></i> Ghi chú
                                    </button>
                                    <form method="post" style="margin: 0;" onsubmit="return confirm('Xóa báo cáo này?');">
                                        <input type="hidden" name="type" value="delete_report">
                                        <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                                        <button type="submit" class="rpt-btn-danger">
                                            <i class="bi bi-trash"></i> Xóa
                                        </button>
                                    </form>
                                </div>
                                
                                <div id="note-form-report-<?php echo $r['id']; ?>" style="display: none; margin-top: 1rem;">
                                    <form method="post">
                                        <input type="hidden" name="type" value="update_report">
                                        <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                                        <input type="hidden" name="status" value="<?php echo $r['status'] ?? 'pending'; ?>">
                                        <textarea name="admin_note" placeholder="Nhập ghi chú..." style="width: 100%; padding: 0.75rem; border: 2px solid #e2e8f0; border-radius: 10px; min-height: 80px; margin-bottom: 0.5rem;"><?php echo htmlspecialchars($r['admin_note'] ?? ''); ?></textarea>
                                        <button type="submit" style="background: linear-gradient(135deg, #f59e0b, #d97706); color: #fff; border: none; padding: 0.5rem 1rem; border-radius: 8px; cursor: pointer;">
                                            <i class="bi bi-save"></i> Lưu ghi chú
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Comment Reports Section -->
        <div class="rpt-card mt-4" style="margin-top: 1.5rem;">
            <div class="rpt-card-header comment-report" style="background: linear-gradient(135deg, #fef2f2, #fee2e2);">
                <i class="bi bi-chat-left-text-fill" style="color: #dc2626;"></i>
                <h3>Báo cáo bình luận</h3>
                <span class="badge" style="background: linear-gradient(135deg, #fee2e2, #fecaca); color: #dc2626;"><?php echo $commentReportCount; ?> báo cáo</span>
            </div>
            <div class="rpt-card-body">
                <?php if (empty($commentReports)): ?>
                    <div class="rpt-empty">
                        <div class="rpt-empty-icon"><i class="bi bi-chat-left-text"></i></div>
                        <h4>Chưa có báo cáo bình luận nào</h4>
                    </div>
                <?php else: ?>
                    <?php foreach ($commentReports as $cr): ?>
                        <div class="rpt-item" style="border-left: 4px solid #ef4444;">
                            <div class="rpt-item-header">
                                <div>
                                    <div class="rpt-item-title">
                                        <i class="bi bi-exclamation-circle text-danger me-1"></i>
                                        <?php echo htmlspecialchars($cr['reason']); ?>
                                    </div>
                                    <div class="rpt-item-user">
                                        <i class="bi bi-person-fill"></i>
                                        Người báo cáo: <?php echo htmlspecialchars($cr['reporter_name']); ?>
                                    </div>
                                    <div class="rpt-item-user mt-1">
                                        <i class="bi bi-person-x-fill text-danger"></i>
                                        Người bình luận: <?php echo htmlspecialchars($cr['commenter_name']); ?>
                                    </div>
                                </div>
                                <div class="text-end">
                                    <span class="rpt-status <?php echo $cr['status']; ?>">
                                        <?php 
                                        $statusText = ['pending' => 'Chờ', 'reviewed' => 'Đã xem', 'resolved' => 'Đã xử lý', 'dismissed' => 'Bác bỏ'];
                                        echo $statusText[$cr['status']] ?? $cr['status'];
                                        ?>
                                    </span>
                                    <div class="rpt-item-date mt-1">
                                        <i class="bi bi-clock"></i>
                                        <?php echo date('d/m/Y H:i', strtotime($cr['created_at'])); ?>
                                    </div>
                                </div>
                            </div>
                            
                            <div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 10px; padding: 1rem; margin-bottom: 1rem;">
                                <div style="font-size: 0.8rem; color: #991b1b; margin-bottom: 0.5rem; font-weight: 600;">
                                    <i class="bi bi-chat-quote-fill me-1"></i> Nội dung bình luận:
                                </div>
                                <div style="color: #7f1d1d; font-style: italic;">
                                    "<?php echo htmlspecialchars($cr['comment_content']); ?>"
                                </div>
                                <?php if (!empty($cr['post_title'])): ?>
                                <div style="font-size: 0.8rem; color: #64748b; margin-top: 0.5rem;">
                                    <i class="bi bi-file-earmark-text me-1"></i> 
                                    Bài viết: <a href="view_post.php?id=<?php echo $cr['post_id']; ?>" target="_blank"><?php echo htmlspecialchars($cr['post_title']); ?></a>
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <?php if (!empty($cr['description'])): ?>
                                <div class="rpt-item-content"><?php echo htmlspecialchars($cr['description']); ?></div>
                            <?php endif; ?>
                            
                            <div style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
                                <form method="post" class="rpt-item-form" style="flex: 1;">
                                    <input type="hidden" name="type" value="update_comment_report">
                                    <input type="hidden" name="id" value="<?php echo (int)$cr['id']; ?>">
                                    <select name="status">
                                        <option value="pending" <?php echo $cr['status'] === 'pending' ? 'selected' : ''; ?>>Chờ xử lý</option>
                                        <option value="reviewed" <?php echo $cr['status'] === 'reviewed' ? 'selected' : ''; ?>>Đã xem xét</option>
                                        <option value="resolved" <?php echo $cr['status'] === 'resolved' ? 'selected' : ''; ?>>Đã xử lý</option>
                                        <option value="dismissed" <?php echo $cr['status'] === 'dismissed' ? 'selected' : ''; ?>>Bác bỏ</option>
                                    </select>
                                    <button type="submit"><i class="bi bi-check2"></i> Cập nhật</button>
                                </form>
                                <?php if ($cr['status'] !== 'resolved'): ?>
                                <form method="post" onsubmit="return confirm('Bạn có chắc muốn xóa bình luận này?');">
                                    <input type="hidden" name="type" value="delete_comment">
                                    <input type="hidden" name="comment_id" value="<?php echo (int)$cr['comment_id']; ?>">
                                    <input type="hidden" name="report_id" value="<?php echo (int)$cr['id']; ?>">
                                    <button type="submit" style="background: linear-gradient(135deg, #ef4444, #dc2626); color: #fff; border: none; padding: 0.65rem 1.4rem; border-radius: 12px; font-weight: 600; cursor: pointer; box-shadow: 0 10px 25px rgba(239, 68, 68, 0.3);">
                                        <i class="bi bi-trash"></i> Xóa bình luận
                                    </button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
function toggleReplyForm(id) {
    var form = document.getElementById('reply-form-' + id);
    form.style.display = form.style.display === 'none' ? 'block' : 'none';
}

function toggleNoteForm(type, id) {
    var form = document.getElementById('note-form-' + type + '-' + id);
    form.style.display = form.style.display === 'none' ? 'block' : 'none';
}
</script>

<?php require_once 'footer.php'; ?>
