<?php
require_once 'config.php';
require_admin();

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $reqId = (int)($_POST['req_id'] ?? 0);
    $table = $_POST['table'] ?? 'verifications'; // 'verifications' or 'posting_requests'
    $note = trim($_POST['admin_note'] ?? '');

    try {
        if ($reqId > 0) {
            if ($table === 'posting_requests') {
                // fetch user_id
                $stmt = $pdo->prepare('SELECT user_id FROM posting_requests WHERE id = ?');
                $stmt->execute([$reqId]);
                $userId = (int)$stmt->fetchColumn();

                if ($action === 'approve') {
                    $pdo->prepare("UPDATE posting_requests SET status='approved', admin_note=?, processed_at=NOW() WHERE id=?")->execute([$note, $reqId]);
                    // allow posting for this user
                    if ($userId) {
                        $pdo->prepare('UPDATE users SET can_post = 1, verified = 1 WHERE id = ?')->execute([$userId]);
                        // notify user
                        $pdo->prepare('INSERT INTO messages (sender_id,receiver_id,message) VALUES (?,?,?)')->execute([$_SESSION['user_id'], $userId, 'Yêu cầu đăng bài đã được phê duyệt. '.$note]);
                    }
                    $success = 'Đã phê duyệt yêu cầu đăng bài.';
                } elseif ($action === 'reject') {
                    $pdo->prepare("UPDATE posting_requests SET status='rejected', admin_note=?, processed_at=NOW() WHERE id=?")->execute([$note, $reqId]);
                    if ($userId) {
                        // Hủy quyền đăng bài của user
                        $pdo->prepare('UPDATE users SET can_post = 0 WHERE id = ?')->execute([$userId]);
                        $pdo->prepare('INSERT INTO messages (sender_id,receiver_id,message) VALUES (?,?,?)')->execute([$_SESSION['user_id'], $userId, 'Yêu cầu đăng bài bị từ chối. '.$note]);
                    }
                    $success = 'Đã từ chối yêu cầu đăng bài.';
                }
            } else {
                // verifications table
                $stmt = $pdo->prepare('SELECT user_id FROM verifications WHERE id = ?');
                $stmt->execute([$reqId]);
                $userId = (int)$stmt->fetchColumn();

                if ($action === 'approve') {
                    $pdo->prepare("UPDATE verifications SET status='approved', admin_note=?, processed_at=NOW() WHERE id=?")->execute([$note, $reqId]);
                    if ($userId) {
                        $pdo->prepare('UPDATE users SET verified = 1, can_post = 1 WHERE id = ?')->execute([$userId]);
                        $pdo->prepare('INSERT INTO messages (sender_id,receiver_id,message) VALUES (?,?,?)')->execute([$_SESSION['user_id'], $userId, 'Xác minh sinh viên đã được chấp nhận. '.$note]);
                    }
                    $success = 'Đã chấp nhận xác minh.';
                } elseif ($action === 'reject') {
                    $pdo->prepare("UPDATE verifications SET status='rejected', admin_note=?, processed_at=NOW() WHERE id=?")->execute([$note, $reqId]);
                    if ($userId) {
                        // Hủy xác minh và quyền đăng bài của user
                        $pdo->prepare('UPDATE users SET verified = 0, can_post = 0 WHERE id = ?')->execute([$userId]);
                        $pdo->prepare('INSERT INTO messages (sender_id,receiver_id,message) VALUES (?,?,?)')->execute([$_SESSION['user_id'], $userId, 'Xác minh sinh viên bị từ chối. '.$note]);
                    }
                    $success = 'Đã từ chối xác minh.';
                }
            }
        }
    } catch (Throwable $e) {
        error_log('admin_verifications action error: ' . $e->getMessage());
        $error = 'Có lỗi khi xử lý yêu cầu.';
    }
}

// Get filter status from URL
$filterStatus = $_GET['status'] ?? 'pending';
$validStatuses = ['pending', 'approved', 'rejected', 'all'];
if (!in_array($filterStatus, $validStatuses)) {
    $filterStatus = 'pending';
}

// Get search keyword
$searchKeyword = trim($_GET['search'] ?? '');
$searchCondition = '';
$searchConditionPR = '';
if ($searchKeyword !== '') {
    $searchEscaped = addslashes($searchKeyword);
    $searchCondition = " AND (u.name LIKE '%{$searchEscaped}%' OR u.email LIKE '%{$searchEscaped}%' OR v.full_name LIKE '%{$searchEscaped}%' OR v.student_code LIKE '%{$searchEscaped}%' OR v.class_name LIKE '%{$searchEscaped}%')";
    $searchConditionPR = " AND (u.name LIKE '%{$searchEscaped}%' OR u.email LIKE '%{$searchEscaped}%' OR pr.full_name LIKE '%{$searchEscaped}%' OR pr.student_code LIKE '%{$searchEscaped}%' OR pr.class_name LIKE '%{$searchEscaped}%')";
}

// Build WHERE clause based on filter
$statusCondition = $filterStatus === 'all' ? "1=1" : "v.status='{$filterStatus}'";
$statusConditionPR = $filterStatus === 'all' ? "1=1" : "pr.status='{$filterStatus}'";

// Load requests from both tables based on filter
try {
    $pendingVerifications = $pdo->query("SELECT v.*, u.email, u.name, u.id AS user_id, 'verifications' AS src_table FROM verifications v JOIN users u ON u.id = v.user_id WHERE {$statusCondition}{$searchCondition} ORDER BY v.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $pendingVerifications = [];
}

try {
    $pendingPosting = $pdo->query("SELECT pr.*, u.email, u.name, u.id AS user_id, 'posting_requests' AS src_table FROM posting_requests pr JOIN users u ON u.id = pr.user_id WHERE {$statusConditionPR}{$searchConditionPR} ORDER BY pr.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $pendingPosting = [];
}

$requests = array_merge($pendingVerifications, $pendingPosting);

// Count for stats
try {
    $countPending = $pdo->query("SELECT COUNT(*) FROM verifications WHERE status='pending'")->fetchColumn() + 
                    $pdo->query("SELECT COUNT(*) FROM posting_requests WHERE status='pending'")->fetchColumn();
    $countApproved = $pdo->query("SELECT COUNT(*) FROM verifications WHERE status='approved'")->fetchColumn() + 
                     $pdo->query("SELECT COUNT(*) FROM posting_requests WHERE status='approved'")->fetchColumn();
    $countRejected = $pdo->query("SELECT COUNT(*) FROM verifications WHERE status='rejected'")->fetchColumn() + 
                     $pdo->query("SELECT COUNT(*) FROM posting_requests WHERE status='rejected'")->fetchColumn();
} catch (Throwable $e) {
    $countPending = 0;
    $countApproved = 0;
    $countRejected = 0;
}

require_once 'header.php';
?>

<style>
    /* Admin pages: hide global site navbar to match dashboard UX */
    .premium-navbar, nav.navbar, .navbar { display: none !important; }
    body { padding-top: 0 !important; background: #f4f6ff; }
</style>

<div class="verification-page-wrapper">
    <!-- Header Section -->
    <div class="verification-header">
        <div class="header-content">
            <div class="header-icon">
                <i class="bi bi-patch-check-fill"></i>
            </div>
            <div class="header-text">
                <h2>Xác minh & Yêu cầu đăng bài</h2>
                <p>Xem xét giấy tờ, phê duyệt hoặc từ chối yêu cầu từ người dùng</p>
            </div>
        </div>
        <div class="header-actions">
            <a href="admin_users.php" class="btn-header-action btn-back-header">
                <i class="bi bi-arrow-left"></i>
                <span>Quay lại</span>
            </a>
        </div>
    </div>

    <!-- Search Box -->
    <div class="verification-search-box mb-4">
        <form method="get" class="search-form">
            <input type="hidden" name="status" value="<?php echo htmlspecialchars($filterStatus); ?>">
            <div class="search-input-wrapper">
                <i class="bi bi-search"></i>
                <input type="text" name="search" class="search-input" 
                       placeholder="Tìm kiếm theo tên, email, MSSV, lớp..." 
                       value="<?php echo htmlspecialchars($searchKeyword); ?>">
                <?php if ($searchKeyword): ?>
                <a href="admin_verifications.php?status=<?php echo htmlspecialchars($filterStatus); ?>" class="search-clear">
                    <i class="bi bi-x-lg"></i>
                </a>
                <?php endif; ?>
            </div>
            <button type="submit" class="search-btn">
                <i class="bi bi-search"></i> Tìm kiếm
            </button>
        </form>
        <?php if ($searchKeyword): ?>
        <div class="search-result-info">
            <i class="bi bi-info-circle"></i>
            Tìm thấy <strong><?php echo count($requests); ?></strong> kết quả cho "<strong><?php echo htmlspecialchars($searchKeyword); ?></strong>"
        </div>
        <?php endif; ?>
    </div>

    <!-- Filter Tabs -->
    <div class="verification-filter-tabs mb-4">
        <a href="admin_verifications.php?status=pending<?php echo $searchKeyword ? '&search='.urlencode($searchKeyword) : ''; ?>" class="filter-tab <?php echo $filterStatus === 'pending' ? 'active' : ''; ?>">
            <i class="bi bi-hourglass-split"></i>
            <span>Đang chờ</span>
            <span class="badge-count"><?php echo $countPending; ?></span>
        </a>
        <a href="admin_verifications.php?status=approved<?php echo $searchKeyword ? '&search='.urlencode($searchKeyword) : ''; ?>" class="filter-tab <?php echo $filterStatus === 'approved' ? 'active' : ''; ?>">
            <i class="bi bi-check-circle"></i>
            <span>Đã duyệt</span>
            <span class="badge-count"><?php echo $countApproved; ?></span>
        </a>
        <a href="admin_verifications.php?status=rejected<?php echo $searchKeyword ? '&search='.urlencode($searchKeyword) : ''; ?>" class="filter-tab <?php echo $filterStatus === 'rejected' ? 'active' : ''; ?>">
            <i class="bi bi-x-circle"></i>
            <span>Đã từ chối</span>
            <span class="badge-count"><?php echo $countRejected; ?></span>
        </a>
        <a href="admin_verifications.php?status=all<?php echo $searchKeyword ? '&search='.urlencode($searchKeyword) : ''; ?>" class="filter-tab <?php echo $filterStatus === 'all' ? 'active' : ''; ?>">
            <i class="bi bi-list-ul"></i>
            <span>Tất cả</span>
        </a>
    </div>

    <!-- Stats -->
    <div class="verification-stats">
        <div class="stat-item pending">
            <div class="stat-icon"><i class="bi bi-hourglass-split"></i></div>
            <div class="stat-info">
                <span class="stat-value"><?php echo $countPending; ?></span>
                <span class="stat-label">Đang chờ xử lý</span>
            </div>
        </div>
        <div class="stat-item student">
            <div class="stat-icon"><i class="bi bi-check-circle"></i></div>
            <div class="stat-info">
                <span class="stat-value"><?php echo $countApproved; ?></span>
                <span class="stat-label">Đã duyệt</span>
            </div>
        </div>
        <div class="stat-item posting">
            <div class="stat-icon"><i class="bi bi-x-circle"></i></div>
            <div class="stat-info">
                <span class="stat-value"><?php echo $countRejected; ?></span>
                <span class="stat-label">Đã từ chối</span>
            </div>
        </div>
    </div>
    
    <style>
    /* Search Box Styles */
    .verification-search-box {
        background: linear-gradient(180deg, #ffffff 0%, #f4f6ff 100%);
        padding: 1.25rem 1.5rem;
        border-radius: 24px;
        box-shadow: 0 18px 45px rgba(15, 23, 42, 0.08);
        border: none;
    }
    .search-form {
        display: flex;
        gap: 0.75rem;
        align-items: center;
        flex-wrap: wrap;
    }
    .search-input-wrapper {
        flex: 1;
        min-width: 250px;
        position: relative;
    }
    .search-input-wrapper > i {
        position: absolute;
        left: 1rem;
        top: 50%;
        transform: translateY(-50%);
        color: #94a3b8;
        font-size: 1rem;
    }
    .search-input {
        width: 100%;
        padding: 0.85rem 2.5rem 0.85rem 2.85rem;
        border: 2px solid #e3e8ff;
        background: #f7f7ff;
        border-radius: 16px;
        font-size: 0.95rem;
        transition: all 0.3s ease;
    }
    .search-input:focus {
        outline: none;
        border-color: #1f7aff;
        box-shadow: 0 0 0 4px rgba(31,122,255,0.12);
        background: #fff;
    }
    .search-input::placeholder {
        color: #94a3b8;
    }
    .search-clear {
        position: absolute;
        right: 0.75rem;
        top: 50%;
        transform: translateY(-50%);
        color: #94a3b8;
        padding: 0.25rem;
        border-radius: 50%;
        transition: all 0.2s ease;
    }
    .search-clear:hover {
        background: #fee2e2;
        color: #dc2626;
    }
    .search-btn {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.9rem 1.75rem;
        background: linear-gradient(125deg, #0b3f91, #1e40af);
        color: #fff;
        border: none;
        border-radius: 18px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
        box-shadow: 0 18px 30px rgba(11, 63, 145, 0.25);
    }
    .search-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 12px 28px rgba(11, 63, 145, 0.35);
    }
    .search-result-info {
        margin-top: 0.75rem;
        padding: 0.5rem 0.75rem;
        background: #eef4ff;
        border-radius: 8px;
        font-size: 0.9rem;
        color: #0b3f91;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    .search-result-info strong {
        color: #1d4ed8;
    }
    @media (max-width: 576px) {
        .search-form {
            flex-direction: column;
        }
        .search-input-wrapper {
            width: 100%;
            min-width: unset;
        }
        .search-btn {
            width: 100%;
            justify-content: center;
        }
    }
    
    .verification-filter-tabs {
        display: flex;
        gap: 0.75rem;
        flex-wrap: wrap;
        background: transparent;
        padding: 0.25rem;
        border-radius: 16px;
    }
    .filter-tab {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.85rem 1.5rem;
        border-radius: 999px;
        text-decoration: none;
        color: #64748b;
        font-weight: 500;
        transition: all 0.3s ease;
        background: #fff;
        border: 1px solid #e4e7f5;
        box-shadow: 0 10px 25px rgba(15,23,42,0.08);
    }
    .filter-tab:hover {
        color: #1f7aff;
        border-color: #c7d5ff;
        background: #f2f6ff;
    }
    .filter-tab.active {
        background: linear-gradient(125deg, #0b3f91 0%, #1e40af 100%);
        color: white;
        border-color: transparent;
        box-shadow: 0 18px 30px rgba(11, 63, 145, 0.25);
    }
    .filter-tab .badge-count {
        background: rgba(255,255,255,0.2);
        padding: 0.15rem 0.5rem;
        border-radius: 20px;
        font-size: 0.75rem;
    }
    .filter-tab.active .badge-count {
        background: rgba(255,255,255,0.3);
    }
    </style>

    <!-- Alert Messages -->
    <?php if ($success): ?>
        <div class="alert-custom alert-success-custom">
            <div class="alert-icon"><i class="bi bi-check-circle-fill"></i></div>
            <div class="alert-content"><?php echo htmlspecialchars($success); ?></div>
        </div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert-custom alert-danger-custom">
            <div class="alert-icon"><i class="bi bi-exclamation-circle-fill"></i></div>
            <div class="alert-content"><?php echo htmlspecialchars($error); ?></div>
        </div>
    <?php endif; ?>

    <!-- Requests List -->
    <?php if (empty($requests)): ?>
        <div class="empty-state">
            <div class="empty-icon">
                <i class="bi bi-inbox"></i>
            </div>
            <h4>Không có yêu cầu nào</h4>
            <p>Tất cả yêu cầu đã được xử lý. Quay lại sau để kiểm tra yêu cầu mới.</p>
        </div>
    <?php else: ?>
        <div class="requests-list">
            <?php foreach ($requests as $index => $r): 
                $isPosting = $r['src_table'] === 'posting_requests';
            ?>
            <div class="request-card" style="animation-delay: <?php echo $index * 0.1; ?>s">
                <div class="request-header">
                    <div class="user-info">
                        <div class="user-avatar <?php echo $isPosting ? 'posting' : 'student'; ?>">
                            <?php echo strtoupper(substr($r['name'] ?? 'U', 0, 1)); ?>
                        </div>
                        <div class="user-details">
                            <span class="user-name"><?php echo htmlspecialchars($r['name'] ?? ''); ?></span>
                            <span class="user-email"><?php echo htmlspecialchars($r['email'] ?? ''); ?></span>
                        </div>
                    </div>
                    <div class="request-type <?php echo $isPosting ? 'posting' : 'student'; ?>">
                        <i class="bi bi-<?php echo $isPosting ? 'file-earmark-text' : 'mortarboard'; ?>"></i>
                        <span><?php echo $isPosting ? 'Yêu cầu đăng bài' : 'Xác minh sinh viên'; ?></span>
                    </div>
                </div>

                <div class="request-body">
                    <div class="info-grid">
                        <div class="info-item">
                            <i class="bi bi-person"></i>
                            <div>
                                <span class="info-label">Họ tên</span>
                                <span class="info-value"><?php echo htmlspecialchars($r['full_name'] ?? 'N/A'); ?></span>
                            </div>
                        </div>
                        <div class="info-item">
                            <i class="bi bi-card-text"></i>
                            <div>
                                <span class="info-label">MSSV</span>
                                <span class="info-value"><?php echo htmlspecialchars($r['student_code'] ?? 'N/A'); ?></span>
                            </div>
                        </div>
                        <div class="info-item">
                            <i class="bi bi-building"></i>
                            <div>
                                <span class="info-label">Lớp</span>
                                <span class="info-value"><?php echo htmlspecialchars($r['class_name'] ?? 'N/A'); ?></span>
                            </div>
                        </div>
                        <div class="info-item">
                            <i class="bi bi-geo-alt"></i>
                            <div>
                                <span class="info-label">Địa chỉ</span>
                                <span class="info-value"><?php echo htmlspecialchars($r['address'] ?? 'N/A'); ?></span>
                            </div>
                        </div>
                        <?php if (!empty($r['phone'])): ?>
                        <div class="info-item">
                            <i class="bi bi-telephone"></i>
                            <div>
                                <span class="info-label">Điện thoại</span>
                                <span class="info-value"><?php echo htmlspecialchars($r['phone']); ?></span>
                            </div>
                        </div>
                        <?php endif; ?>
                        <div class="info-item">
                            <i class="bi bi-calendar-event"></i>
                            <div>
                                <span class="info-label">Ngày gửi</span>
                                <span class="info-value"><?php echo !empty($r['created_at']) ? date('d/m/Y H:i', strtotime($r['created_at'])) : 'N/A'; ?></span>
                            </div>
                        </div>
                        <?php if (!empty($r['processed_at'])): ?>
                        <div class="info-item">
                            <i class="bi bi-calendar-check"></i>
                            <div>
                                <span class="info-label">Ngày xử lý</span>
                                <span class="info-value"><?php echo date('d/m/Y H:i', strtotime($r['processed_at'])); ?></span>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="documents-section">
                        <span class="section-title"><i class="bi bi-folder2-open"></i> Tài liệu đính kèm</span>
                        <div class="documents-list">
                            <?php
                            $cardPath = $r['document_card'] ?? '';
                            if ($cardPath && upload_exists($cardPath)) { ?>
                                <a class="doc-btn" target="_blank" href="<?php echo htmlspecialchars(public_url_for($cardPath)); ?>">
                                    <i class="bi bi-file-earmark-image"></i> Thẻ sinh viên
                                </a>
                            <?php } elseif ($cardPath) { ?>
                                <span class="doc-missing"><i class="bi bi-exclamation-triangle"></i> Thẻ SV không tồn tại</span>
                            <?php }

                            $internPath = $r['document_internship'] ?? '';
                            if ($internPath && upload_exists($internPath)) { ?>
                                <a class="doc-btn" target="_blank" href="<?php echo htmlspecialchars(public_url_for($internPath)); ?>">
                                    <i class="bi bi-file-earmark-pdf"></i> Giấy thực tập
                                </a>
                            <?php } elseif ($internPath) { ?>
                                <span class="doc-missing"><i class="bi bi-exclamation-triangle"></i> Giấy thực tập không tồn tại</span>
                            <?php } ?>
                        </div>
                    </div>
                </div>

                <div class="request-footer">
                    <form method="post" class="action-form">
                        <input type="hidden" name="req_id" value="<?php echo (int)$r['id']; ?>">
                        <input type="hidden" name="table" value="<?php echo htmlspecialchars($r['src_table']); ?>">
                        <div class="note-input">
                            <i class="bi bi-chat-left-text"></i>
                            <input type="text" name="admin_note" placeholder="Ghi chú phản hồi (không bắt buộc)...">
                        </div>
                        <div class="action-buttons">
                            <?php if ($filterStatus !== 'approved'): ?>
                            <button type="submit" name="action" value="approve" class="btn-approve">
                                <i class="bi bi-check-lg"></i> Chấp nhận
                            </button>
                            <?php endif; ?>
                            <?php if ($filterStatus !== 'rejected'): ?>
                            <button type="button" class="btn-reject" onclick="confirmReject(this)">
                                <i class="bi bi-x-lg"></i> Từ chối
                            </button>
                            <button type="submit" name="action" value="reject" class="btn-reject-hidden" style="display:none;"></button>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<style>
/* Verification Page Styles */
.verification-page-wrapper { max-width: 1100px; margin: 0 auto; padding: 2rem 1rem 3rem; }

.verification-header {
    display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;
    padding: 1.75rem 2.25rem; background: linear-gradient(120deg, #0b3f91 0%, #1e40af 50%, #3b82f6 100%);
    border-radius: 32px; box-shadow: 0 25px 60px rgba(11, 63, 145, 0.35);
}
.verification-header .header-content { display: flex; align-items: center; gap: 1.25rem; }
.verification-header .header-icon {
    width: 78px; height: 78px; border-radius: 24px;
    background: linear-gradient(140deg, #ff955b, #ff6275);
    display: flex; align-items: center; justify-content: center; font-size: 2rem; color: #fff;
    box-shadow: 0 12px 30px rgba(255, 116, 91, 0.35);
}
.verification-header .header-text h2 { margin: 0; color: #fff; font-size: 2rem; font-weight: 700; }
.verification-header .header-text p { margin: 0.25rem 0 0; color: rgba(255,255,255,0.8); font-size: 0.95rem; }
.verification-header .btn-back-header {
    display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.75rem 1.25rem;
    background: rgba(255,255,255,0.15); color: #fff !important; border-radius: 12px;
    text-decoration: none; font-weight: 500; transition: all 0.3s ease;
    border: 1px solid rgba(255,255,255,0.22);
}
.verification-header .btn-back-header:hover { background: rgba(255,255,255,0.25); }

/* Stats */
.verification-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1.25rem; margin-bottom: 1.75rem; }
.verification-stats .stat-item {
    display: flex; align-items: center; gap: 1rem; padding: 1.35rem 1.6rem;
    background: #fff; border-radius: 24px; box-shadow: 0 25px 60px rgba(15, 23, 42, 0.08);
    border: none; position: relative; overflow: hidden;
}
.verification-stats .stat-item::before {
    content: ""; position: absolute; inset: 0; border-radius: 24px;
    border: 1px solid rgba(148,163,184,0.2);
}
.verification-stats .stat-item > * { position: relative; z-index: 1; }
.verification-stats .stat-icon {
    width: 60px; height: 60px; border-radius: 18px; display: flex;
    align-items: center; justify-content: center; font-size: 1.6rem;
}
.stat-item.pending .stat-icon { background: linear-gradient(140deg, #ffe0eb, #ffb7c8); color: #e93d7c; }
.stat-item.student .stat-icon { background: linear-gradient(140deg, #e0e7ff, #c4d4ff); color: #3b5bff; }
.stat-item.posting .stat-icon { background: linear-gradient(140deg, #d3f6ec, #afe9d6); color: #0f9d7e; }
.verification-stats .stat-info { display: flex; flex-direction: column; }
.verification-stats .stat-value { font-size: 1.75rem; font-weight: 700; color: #0f172a; }
.verification-stats .stat-label { font-size: 0.9rem; color: #64748b; }

/* Alerts */
.alert-custom { display: flex; align-items: center; gap: 1rem; padding: 1rem 1.5rem; border-radius: 16px; margin-bottom: 1.5rem; }
.alert-success-custom { background: linear-gradient(135deg, #d1fae5, #a7f3d0); border: 1px solid #6ee7b7; color: #065f46; }
.alert-danger-custom { background: linear-gradient(135deg, #fee2e2, #fecaca); border: 1px solid #fca5a5; color: #991b1b; }
.alert-icon { font-size: 1.5rem; }
.alert-success-custom .alert-icon { color: #059669; }
.alert-danger-custom .alert-icon { color: #dc2626; }

/* Empty State */
.empty-state {
    text-align: center; padding: 4rem 2rem; background: #fff; border-radius: 24px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.06);
}
.empty-icon { font-size: 4rem; color: #cbd5e1; margin-bottom: 1rem; }
.empty-state h4 { color: #475569; margin-bottom: 0.5rem; }
.empty-state p { color: #94a3b8; }

/* Request Cards */
.requests-list { display: flex; flex-direction: column; gap: 1.5rem; }
.request-card {
    background: #fff; border-radius: 26px; overflow: hidden;
    box-shadow: 0 25px 60px rgba(15, 23, 42, 0.08); border: none;
    border: 1px solid rgba(226,232,240,0.7);
    animation: slideUp 0.5s ease backwards;
}
@keyframes slideUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }

.request-header {
    display: flex; justify-content: space-between; align-items: center;
    padding: 1.35rem 1.75rem; background: linear-gradient(90deg, #f8f9ff, #fdfdff);
    border-bottom: 1px solid #edf2ff;
}
.request-header .user-info { display: flex; align-items: center; gap: 0.875rem; }
.request-header .user-avatar {
    width: 58px; height: 58px; border-radius: 18px; display: flex;
    align-items: center; justify-content: center; font-weight: 600; font-size: 1.3rem; color: #fff;
    box-shadow: 0 12px 30px rgba(24, 78, 248, 0.25);
}
.user-avatar.student { background: linear-gradient(140deg, #6f5bff, #8a5dff); }
.user-avatar.posting { background: linear-gradient(140deg, #1f9dff, #1f7aff); }
.user-details { display: flex; flex-direction: column; }
.user-name { font-weight: 600; color: #1e293b; }
.user-email { font-size: 0.85rem; color: #64748b; }
.request-type {
    display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.5rem 1rem;
    border-radius: 10px; font-size: 0.85rem; font-weight: 500;
}
.request-type.student { background: rgba(111,91,255,0.12); color: #6f5bff; }
.request-type.posting { background: rgba(31,122,255,0.12); color: #1f7aff; }

.request-body { padding: 1.75rem; }
.info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
.info-item { display: flex; align-items: flex-start; gap: 0.75rem; }
.info-item > i { color: #94a3b8; font-size: 1.1rem; margin-top: 0.15rem; }
.info-item .info-label { display: block; font-size: 0.75rem; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.05em; }
.info-item .info-value { display: block; color: #1e293b; font-weight: 500; }

.documents-section { padding-top: 1rem; border-top: 1px solid #f1f5f9; }
.section-title { display: flex; align-items: center; gap: 0.5rem; font-size: 0.85rem; color: #64748b; margin-bottom: 0.75rem; }
.documents-list { display: flex; flex-wrap: wrap; gap: 0.75rem; }
.doc-btn {
    display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.625rem 1rem;
    background: #f1f5f9; color: #475569; border-radius: 10px; text-decoration: none;
    font-size: 0.9rem; font-weight: 500; transition: all 0.2s ease;
}
.doc-btn:hover { background: #e2e8f0; color: #1e293b; }
.doc-missing { display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.625rem 1rem; background: #fef2f2; color: #dc2626; border-radius: 10px; font-size: 0.85rem; }

.request-footer { padding: 1.25rem 1.5rem; background: #f8fafc; border-top: 1px solid #e2e8f0; }
.action-form { display: flex; gap: 1rem; align-items: center; flex-wrap: wrap; }
.note-input {
    flex: 1; min-width: 250px; position: relative;
}
.note-input i { position: absolute; left: 1rem; top: 50%; transform: translateY(-50%); color: #94a3b8; }
.note-input input {
    width: 100%; padding: 0.85rem 1rem 0.85rem 2.75rem; border: 2px solid #e2e8f0;
    border-radius: 16px; font-size: 0.95rem; transition: all 0.3s ease;
}
.note-input input:focus { outline: none; border-color: #1f7aff; box-shadow: 0 0 0 4px rgba(31,122,255,0.16); }
.action-buttons { display: flex; gap: 0.75rem; }
.btn-approve, .btn-reject {
    display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.75rem 1.25rem;
    border-radius: 12px; font-weight: 600; cursor: pointer; transition: all 0.3s ease; border: none;
}
.btn-approve {
    background: linear-gradient(125deg, #0b3f91, #1e40af); color: #fff; padding: 0.85rem 1.85rem;
    box-shadow: 0 18px 30px rgba(11, 63, 145, 0.25);
}
.btn-approve:hover { transform: translateY(-2px); box-shadow: 0 22px 34px rgba(11, 63, 145, 0.3); }
.btn-reject {
    background: #fff; color: #dc2626; border: 2px solid #ffd0d4; padding: 0.85rem 1.5rem;
    box-shadow: 0 10px 20px rgba(220,38,38,0.12);
}
.btn-reject:hover { background: #fff0f1; }

/* Responsive */
@media (max-width: 768px) {
    .verification-header { flex-direction: column; gap: 1.25rem; text-align: center; padding: 1.5rem; }
    .verification-header .header-content { flex-direction: column; }
    .action-form { flex-direction: column; }
    .note-input { min-width: 100%; }
    .action-buttons { width: 100%; }
    .btn-approve, .btn-reject { flex: 1; justify-content: center; }
}
</style>

<!-- Modal Từ chối -->
<div class="reject-modal-overlay" id="rejectModal">
    <div class="reject-modal">
        <div class="reject-modal-header">
            <div class="reject-modal-icon">
                <i class="bi bi-x-circle"></i>
            </div>
            <h3>Từ chối yêu cầu</h3>
            <p>Bạn đang từ chối yêu cầu của <strong id="rejectUserName"></strong></p>
        </div>
        
        <div class="reject-modal-body">
            <label class="reject-label">
                <i class="bi bi-lightning"></i> Chọn lý do nhanh:
            </label>
            <div class="quick-reasons">
                <button type="button" class="quick-reason-btn" data-reason="Thông tin không chính xác hoặc không khớp với giấy tờ.">
                    <i class="bi bi-info-circle"></i> Thông tin không chính xác
                </button>
                <button type="button" class="quick-reason-btn" data-reason="Hình ảnh thẻ sinh viên không rõ ràng, vui lòng gửi lại.">
                    <i class="bi bi-image"></i> Ảnh không rõ ràng
                </button>
                <button type="button" class="quick-reason-btn" data-reason="Thiếu giấy tờ xác minh cần thiết.">
                    <i class="bi bi-file-earmark-x"></i> Thiếu giấy tờ
                </button>
                <button type="button" class="quick-reason-btn" data-reason="Thẻ sinh viên đã hết hạn, vui lòng cập nhật.">
                    <i class="bi bi-calendar-x"></i> Thẻ hết hạn
                </button>
                <button type="button" class="quick-reason-btn" data-reason="MSSV không tồn tại trong hệ thống trường.">
                    <i class="bi bi-person-x"></i> MSSV không hợp lệ
                </button>
                <button type="button" class="quick-reason-btn" data-reason="Vui lòng liên hệ admin để được hỗ trợ thêm.">
                    <i class="bi bi-headset"></i> Cần hỗ trợ thêm
                </button>
            </div>
            
            <label class="reject-label mt-3">
                <i class="bi bi-pencil"></i> Hoặc nhập lý do tùy chỉnh:
            </label>
            <textarea id="rejectReasonInput" class="reject-textarea" placeholder="Nhập lý do từ chối chi tiết..."></textarea>
        </div>
        
        <div class="reject-modal-footer">
            <button type="button" class="btn-modal-cancel" onclick="closeRejectModal()">
                <i class="bi bi-arrow-left"></i> Hủy bỏ
            </button>
            <button type="button" class="btn-modal-confirm" onclick="submitReject()">
                <i class="bi bi-x-lg"></i> Xác nhận từ chối
            </button>
        </div>
    </div>
</div>

<style>
/* Reject Modal Styles */
.reject-modal-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(15, 23, 42, 0.7);
    backdrop-filter: blur(4px);
    z-index: 9999;
    align-items: center;
    justify-content: center;
    padding: 1rem;
    animation: fadeIn 0.2s ease;
}
.reject-modal-overlay.show {
    display: flex;
}
@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}
.reject-modal {
    background: #fff;
    border-radius: 24px;
    width: 100%;
    max-width: 520px;
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
    animation: slideUp 0.3s ease;
    overflow: hidden;
}
.reject-modal-header {
    background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
    padding: 1.5rem;
    text-align: center;
    border-bottom: 1px solid #fca5a5;
}
.reject-modal-icon {
    width: 64px;
    height: 64px;
    background: linear-gradient(135deg, #ef4444, #dc2626);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 1rem;
    font-size: 1.75rem;
    color: #fff;
    box-shadow: 0 8px 20px rgba(239, 68, 68, 0.3);
}
.reject-modal-header h3 {
    margin: 0 0 0.5rem;
    color: #991b1b;
    font-size: 1.25rem;
    font-weight: 700;
}
.reject-modal-header p {
    margin: 0;
    color: #b91c1c;
    font-size: 0.95rem;
}
.reject-modal-header strong {
    color: #7f1d1d;
}
.reject-modal-body {
    padding: 1.5rem;
}
.reject-label {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.9rem;
    font-weight: 600;
    color: #475569;
    margin-bottom: 0.75rem;
}
.quick-reasons {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
}
.quick-reason-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.5rem 0.875rem;
    background: #f8fafc;
    border: 2px solid #e2e8f0;
    border-radius: 10px;
    font-size: 0.85rem;
    color: #475569;
    cursor: pointer;
    transition: all 0.2s ease;
    font-weight: 500;
}
.quick-reason-btn:hover {
    background: #eff6ff;
    border-color: #3b82f6;
    color: #2563eb;
}
.quick-reason-btn.selected {
    background: linear-gradient(135deg, #3b82f6, #2563eb);
    border-color: transparent;
    color: #fff;
}
.quick-reason-btn i {
    font-size: 0.9rem;
}
.reject-textarea {
    width: 100%;
    min-height: 100px;
    padding: 1rem;
    border: 2px solid #e2e8f0;
    border-radius: 12px;
    font-size: 0.95rem;
    resize: vertical;
    transition: all 0.3s ease;
    font-family: inherit;
}
.reject-textarea:focus {
    outline: none;
    border-color: #ef4444;
    box-shadow: 0 0 0 4px rgba(239, 68, 68, 0.1);
}
.reject-textarea::placeholder {
    color: #94a3b8;
}
.mt-3 {
    margin-top: 1rem;
}
.reject-modal-footer {
    display: flex;
    gap: 0.75rem;
    padding: 1rem 1.5rem 1.5rem;
    background: #f8fafc;
    border-top: 1px solid #e2e8f0;
}
.btn-modal-cancel, .btn-modal-confirm {
    flex: 1;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    padding: 0.875rem 1.25rem;
    border-radius: 12px;
    font-weight: 600;
    font-size: 0.95rem;
    cursor: pointer;
    transition: all 0.3s ease;
    border: none;
}
.btn-modal-cancel {
    background: #fff;
    color: #64748b;
    border: 2px solid #e2e8f0;
}
.btn-modal-cancel:hover {
    background: #f1f5f9;
    border-color: #cbd5e1;
}
.btn-modal-confirm {
    background: linear-gradient(135deg, #ef4444, #dc2626);
    color: #fff;
    box-shadow: 0 4px 15px rgba(239, 68, 68, 0.3);
}
.btn-modal-confirm:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(239, 68, 68, 0.4);
}

@media (max-width: 576px) {
    .reject-modal {
        border-radius: 20px 20px 0 0;
        position: fixed;
        bottom: 0;
        max-height: 90vh;
        overflow-y: auto;
    }
    .quick-reason-btn {
        font-size: 0.8rem;
        padding: 0.4rem 0.7rem;
    }
}
</style>

<script>
let currentRejectCard = null;

function confirmReject(btn) {
    currentRejectCard = btn.closest('.request-card');
    const userName = currentRejectCard.querySelector('.user-name').textContent;
    
    document.getElementById('rejectUserName').textContent = userName;
    document.getElementById('rejectReasonInput').value = '';
    
    // Reset selected quick reasons
    document.querySelectorAll('.quick-reason-btn').forEach(b => b.classList.remove('selected'));
    
    document.getElementById('rejectModal').classList.add('show');
}

function closeRejectModal() {
    document.getElementById('rejectModal').classList.remove('show');
    currentRejectCard = null;
}

function submitReject() {
    if (!currentRejectCard) return;
    
    const reason = document.getElementById('rejectReasonInput').value.trim();
    const noteInput = currentRejectCard.querySelector('input[name="admin_note"]');
    
    noteInput.value = reason;
    currentRejectCard.querySelector('.btn-reject-hidden').click();
    closeRejectModal();
}

// Quick reason buttons
document.querySelectorAll('.quick-reason-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        // Toggle selection
        document.querySelectorAll('.quick-reason-btn').forEach(b => b.classList.remove('selected'));
        this.classList.add('selected');
        
        // Set reason to textarea
        document.getElementById('rejectReasonInput').value = this.dataset.reason;
    });
});

// Close modal on overlay click
document.getElementById('rejectModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeRejectModal();
    }
});

// Close modal on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeRejectModal();
    }
});
</script>

<?php require_once 'footer.php'; ?>
