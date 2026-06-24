<?php
/**
 * editor/defense.php
 * Trang dành cho Editor (Biên tập viên) để quản lý và duyệt các yêu cầu
 * "Bảo vệ tác phẩm / Giải trình bản thảo" từ phía Họa sĩ (Mangaka).
 */

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';

// 1. Kiểm tra quyền truy cập: Chỉ cho phép tài khoản có role là 'editor' truy cập
if (!isLoggedIn() || getCurrentUser()['role'] !== ROLES['EDITOR']) {
    header('Location: ' . BASE_URL . 'auth/login.php');
    exit();
}

$db = getDB();
$currentUser = getCurrentUser();

// 2. Tự động kiểm tra và tạo bảng 'defenses' (Bảo vệ tác phẩm) nếu chưa tồn tại
// Điều này giúp chạy dự án ổn định trên mọi môi trường (MySQL chính hoặc SQLite dự phòng)
try {
    $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'mysql') {
        $db->exec("CREATE TABLE IF NOT EXISTS defenses (
            id INT AUTO_INCREMENT PRIMARY KEY,
            mangaka_id INT NOT NULL,
            chapter_id INT NOT NULL,
            manuscript_id INT DEFAULT NULL,
            reason TEXT NOT NULL,
            status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (mangaka_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (chapter_id) REFERENCES chapters(id) ON DELETE CASCADE,
            FOREIGN KEY (manuscript_id) REFERENCES manuscripts(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    } else {
        $db->exec("CREATE TABLE IF NOT EXISTS defenses (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            mangaka_id INTEGER NOT NULL,
            chapter_id INTEGER NOT NULL,
            manuscript_id INTEGER DEFAULT NULL,
            reason TEXT NOT NULL,
            status TEXT CHECK(status IN ('pending', 'approved', 'rejected')) DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (mangaka_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (chapter_id) REFERENCES chapters(id) ON DELETE CASCADE,
            FOREIGN KEY (manuscript_id) REFERENCES manuscripts(id) ON DELETE SET NULL
        );");
    }

    // Nạp dữ liệu mẫu (Seed Data) nếu bảng rỗng để Biên tập viên có thông tin tương tác ngay
    $count = (int)$db->query("SELECT COUNT(*) FROM defenses")->fetchColumn();
    if ($count === 0) {
        // Kiểm tra xem chapter 3 và 2 có tồn tại không để gán dữ liệu mẫu hợp lệ
        $hasCh3 = $db->query("SELECT id FROM chapters WHERE id = 3")->fetch();
        $hasCh2 = $db->query("SELECT id FROM chapters WHERE id = 2")->fetch();

        if ($hasCh3 && $hasCh2) {
            $db->exec("INSERT INTO defenses (mangaka_id, chapter_id, manuscript_id, reason, status, created_at, updated_at) VALUES
            (1, 3, 2, 'Kính gửi Ban Biên Tập, tôi xin giải trình về việc chỉnh sửa lại toàn bộ các khung hình chiến đấu ở trang 12 và 13 theo đúng góp ý của Biên tập viên ở phiên bản trước. Tôi cũng đã nâng cấp chi tiết background cảnh đổ nát và cải thiện phần đi nét của nhân vật chính để tăng tính kịch tính cho phân cảnh cao trào. Rất mong Biên tập viên xem xét lại và thông qua bản thảo này để kịp tiến độ xuất bản tuần tới. Xin chân thành cảm ơn!', 'pending', '2026-06-23 10:00:00', '2026-06-23 10:00:00'),
            (1, 2, 1, 'Bản thảo chương 42 bị hệ thống đánh dấu từ chối ban đầu là do lỗi trùng lặp tệp tin khi upload hai lần liên tiếp. Tôi xin đính kèm bản giải trình này cùng tệp tin chính xác nhất đã được tinh chỉnh phần hiệu ứng tô bóng. Kính mong Biên tập viên phê duyệt để chúng tôi thực hiện các chương tiếp theo.', 'approved', '2026-06-15 09:00:00', '2026-06-16 14:00:00')");
        }
    }
} catch (\Throwable $e) {
    // Bỏ qua lỗi tạo bảng nếu cấu trúc CSDL đã có sẵn
}

// 3. Xử lý POST phê duyệt (Approve) hoặc Bác bỏ (Reject) giải trình
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $defense_id = intval($_POST['defense_id'] ?? 0);
    $action = $_POST['action'] ?? ''; // 'approved' hoặc 'rejected'

    if ($defense_id > 0 && ($action === 'approved' || $action === 'rejected')) {
        try {
            $db->beginTransaction();

            // Cập nhật trạng thái của đơn giải trình
            $stmt = $db->prepare("UPDATE defenses SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$action, $defense_id]);

            // Lấy thông tin liên quan của đơn để cập nhật bản thảo và gửi thông báo
            $stmtInfo = $db->prepare("
                SELECT d.manuscript_id, d.mangaka_id, d.chapter_id, c.chapter_number, s.title AS series_title 
                FROM defenses d
                JOIN chapters c ON d.chapter_id = c.id
                JOIN series s ON c.series_id = s.id
                WHERE d.id = ?
            ");
            $stmtInfo->execute([$defense_id]);
            $info = $stmtInfo->fetch();

            if ($info) {
                $manuscriptId = $info['manuscript_id'];
                $mangakaId    = $info['mangaka_id'];
                $chapterId    = $info['chapter_id'];
                $chapterNo    = $info['chapter_number'];
                $seriesTitle  = $info['series_title'];

                // Cập nhật trạng thái bản thảo
                if ($manuscriptId) {
                    $stmtManuscript = $db->prepare("UPDATE manuscripts SET status = ? WHERE id = ?");
                    $stmtManuscript->execute([$action, $manuscriptId]);
                }

                // Cập nhật trạng thái chương truyện (Nếu duyệt giải trình thì cho phép duyệt chương)
                if ($action === 'approved') {
                    $stmtChapter = $db->prepare("UPDATE chapters SET status = 'approved' WHERE id = ?");
                    $stmtChapter->execute([$chapterId]);
                }

                // Gửi thông báo hệ thống đến Họa sĩ (Mangaka)
                $notifType = 'defense_' . $action;
                if ($action === 'approved') {
                    $message = "Yêu cầu giải trình của bạn cho tác phẩm \"{$seriesTitle}\" - Chương {$chapterNo} đã được CHẤP NHẬN. Bản thảo đã chuyển sang trạng thái Đã duyệt!";
                } else {
                    $message = "Yêu cầu giải trình của bạn cho tác phẩm \"{$seriesTitle}\" - Chương {$chapterNo} đã bị BÁC BỎ. Quyết định hủy bỏ bản thảo được giữ nguyên.";
                }
                $link = 'mangaka/dashboard.php';

                $stmtNotif = $db->prepare("INSERT INTO notifications (user_id, type, message, link) VALUES (?, ?, ?, ?)");
                $stmtNotif->execute([$mangakaId, $notifType, $message, $link]);
            }

            $db->commit();
            $_SESSION['success'] = "Đã cập nhật trạng thái bản giải trình bảo vệ tác phẩm thành công!";
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $_SESSION['error'] = "Có lỗi xảy ra trong quá trình xử lý: " . $e->getMessage();
        }

        header('Location: defense.php');
        exit();
    }
}

// 4. Nhúng Layout giao diện chính (Sử dụng hệ thống layout thống nhất của MangaFlow)
$pageTitle = 'Bảo vệ tác phẩm';
$activePage = 'defense';
$allowedRoles = [ROLES['EDITOR']];
require_once __DIR__ . '/../includes/layout.php';

// Lấy thông báo lưu trong Session để hiển thị
$success_msg = '';
$error_msg = '';
if (isset($_SESSION['success'])) {
    $success_msg = $_SESSION['success'];
    unset($_SESSION['success']);
}
if (isset($_SESSION['error'])) {
    $error_msg = $_SESSION['error'];
    unset($_SESSION['error']);
}

// 5. Truy vấn danh sách đơn giải trình
// Danh sách chờ xử lý (Pending)
$stmtPending = $db->query("
    SELECT d.*, s.title AS series_title, c.chapter_number, c.title AS chapter_title, u.username AS mangaka_name
    FROM defenses d
    JOIN chapters c ON d.chapter_id = c.id
    JOIN series s ON c.series_id = s.id
    JOIN users u ON d.mangaka_id = u.id
    WHERE d.status = 'pending'
    ORDER BY d.created_at DESC
");
$pendingList = $stmtPending->fetchAll();

// Danh sách đã xử lý (Lịch sử: Approved / Rejected)
$stmtHistory = $db->query("
    SELECT d.*, s.title AS series_title, c.chapter_number, c.title AS chapter_title, u.username AS mangaka_name
    FROM defenses d
    JOIN chapters c ON d.chapter_id = c.id
    JOIN series s ON c.series_id = s.id
    JOIN users u ON d.mangaka_id = u.id
    WHERE d.status IN ('approved', 'rejected')
    ORDER BY d.updated_at DESC
");
$historyList = $stmtHistory->fetchAll();
?>

<!-- Tiêu đề & Điều hướng -->
<div class="page-header">
    <div class="breadcrumb">
        <a href="<?= BASE_URL ?>editor/dashboard.php">Dashboard</a>
        <span class="separator">/</span>
        <span class="current">Bảo vệ tác phẩm</span>
    </div>
    <h1>Bảo Vệ Tác Phẩm / Giải Trình Bản Thảo</h1>
    <p>Nơi Biên tập viên xem xét, đánh giá các đơn giải trình phục hồi bản thảo bị từ chối từ phía Họa sĩ.</p>
</div>

<!-- Alert Thông báo -->
<?php if (!empty($success_msg)): ?>
    <div class="alert alert-success" style="margin-bottom: 24px;">
        <span style="margin-right: 8px;">✓</span> <?php echo htmlspecialchars($success_msg); ?>
    </div>
<?php endif; ?>
<?php if (!empty($error_msg)): ?>
    <div class="alert alert-error" style="margin-bottom: 24px;">
        <span style="margin-right: 8px;">✕</span> <?php echo htmlspecialchars($error_msg); ?>
    </div>
<?php endif; ?>

<!-- Tabs Điều Hướng Chức Năng -->
<div class="tabs-container mb-24">
    <div class="tab-header" style="display: flex; gap: 24px; border-bottom: 1px solid var(--border); margin-bottom: 24px;">
        <button class="tab-btn active" onclick="switchTab('pending')" id="tab-btn-pending" style="background: none; border: none; color: #fff; padding: 12px 4px; font-weight: 600; font-size: 0.95rem; cursor: pointer; border-bottom: 3px solid var(--red); transition: all 0.2s;">
            📥 Đơn chờ xử lý <span class="badge badge-yellow" style="margin-left: 6px; font-size: 0.7rem; padding: 1px 6px;"><?= count($pendingList) ?></span>
        </button>
        <button class="tab-btn" onclick="switchTab('history')" id="tab-btn-history" style="background: none; border: none; color: var(--text-muted); padding: 12px 4px; font-weight: 600; font-size: 0.95rem; cursor: pointer; border-bottom: 3px solid transparent; transition: all 0.2s;">
            📜 Lịch sử đã xử lý <span class="badge badge-gray" style="margin-left: 6px; font-size: 0.7rem; padding: 1px 6px;"><?= count($historyList) ?></span>
        </button>
    </div>

    <!-- TAB 1: DANH SÁCH CHỜ DUYỆT -->
    <div class="tab-content" id="tab-content-pending">
        <div class="card" style="padding: 0; overflow: hidden; border: 1px solid var(--border-card);">
            <div class="card-header" style="padding: 20px 24px; border-bottom: 1px solid var(--border);">
                <h3 class="card-title" style="font-size: 1.05rem; font-weight: 700; color: #fff;">Đơn Giải Trình Chờ Duyệt</h3>
                <p class="card-subtitle" style="font-size: 0.85rem; color: var(--text-muted);">Biên tập viên vui lòng đọc kỹ nội dung giải trình trước khi đưa ra quyết định chấp nhận hoặc bác bỏ.</p>
            </div>
            
            <?php if (empty($pendingList)): ?>
                <div style="text-align: center; padding: 60px 20px; color: var(--text-muted);">
                    <span style="font-size: 3rem; display: block; margin-bottom: 15px;">🎉</span>
                    <p style="font-size: 0.95rem; font-weight: 500;">Hộp thư trống! Không có đơn bảo vệ tác phẩm nào đang chờ xử lý.</p>
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th style="padding: 14px 20px;">STT</th>
                                <th style="padding: 14px 20px;">Bộ Truyện (Series)</th>
                                <th style="padding: 14px 20px;">Chương (Chapter)</th>
                                <th style="padding: 14px 20px;">Họa Sĩ (Mangaka)</th>
                                <th style="padding: 14px 20px;">Nội Dung Giải Trình</th>
                                <th style="padding: 14px 20px;">Ngày Gửi</th>
                                <th style="padding: 14px 20px; text-align: right;">Hành Động</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $stt = 1;
                            foreach ($pendingList as $row): 
                            ?>
                                <tr>
                                    <td style="padding: 14px 20px;" class="td-muted"><?= $stt++ ?></td>
                                    <td style="padding: 14px 20px;">
                                        <strong style="font-size: 0.9rem; color: #fff;"><?= htmlspecialchars($row['series_title']) ?></strong>
                                    </td>
                                    <td style="padding: 14px 20px;">
                                        <span class="badge badge-blue">Chương <?= htmlspecialchars($row['chapter_number']) ?></span>
                                        <div class="text-xs text-muted" style="margin-top: 2px;"><?= htmlspecialchars($row['chapter_title']) ?></div>
                                    </td>
                                    <td style="padding: 14px 20px;">
                                        <div style="display: flex; align-items: center; gap: 8px;">
                                            <div class="user-avatar-sm" style="width: 28px; height: 28px; border-radius: 50%; background: var(--purple); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 0.75rem; font-weight: 700;">
                                                <?= strtoupper(mb_substr($row['mangaka_name'], 0, 1)) ?>
                                            </div>
                                            <span style="font-weight: 600; font-size: 0.85rem; color: #f0f0f8;"><?= htmlspecialchars($row['mangaka_name']) ?></span>
                                        </div>
                                    </td>
                                    <td style="padding: 14px 20px;">
                                        <div style="max-width: 240px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-size: 0.85rem;" class="td-muted">
                                            <?= htmlspecialchars($row['reason']) ?>
                                        </div>
                                    </td>
                                    <td style="padding: 14px 20px; font-size: 0.82rem;" class="td-muted">
                                        <?= date('d/m/Y H:i', strtotime($row['created_at'])) ?>
                                    </td>
                                    <td style="padding: 14px 20px; text-align: right;">
                                        <div style="display: inline-flex; gap: 8px; align-items: center;">
                                            <button class="btn btn-secondary btn-sm" onclick="showReason(<?= htmlspecialchars(json_encode($row['reason'])) ?>, '<?= htmlspecialchars($row['series_title']) ?>', 'Chương <?= htmlspecialchars($row['chapter_number']) ?>')">
                                                👁 Xem chi tiết
                                            </button>
                                            
                                            <form action="" method="POST" style="display: inline;" onsubmit="return confirmApprove(event, '<?= htmlspecialchars($row['series_title']) ?>', <?= $row['chapter_number'] ?>)">
                                                <input type="hidden" name="defense_id" value="<?= $row['id'] ?>">
                                                <input type="hidden" name="action" value="approved">
                                                <button type="submit" class="btn btn-success btn-sm">✓ Duyệt</button>
                                            </form>

                                            <form action="" method="POST" style="display: inline;" onsubmit="return confirmReject(event, '<?= htmlspecialchars($row['series_title']) ?>', <?= $row['chapter_number'] ?>)">
                                                <input type="hidden" name="defense_id" value="<?= $row['id'] ?>">
                                                <input type="hidden" name="action" value="rejected">
                                                <button type="submit" class="btn btn-danger btn-sm">✕ Bác bỏ</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- TAB 2: LỊCH SỬ ĐÃ XỬ LÝ -->
    <div class="tab-content" id="tab-content-history" style="display: none;">
        <div class="card" style="padding: 0; overflow: hidden; border: 1px solid var(--border-card);">
            <div class="card-header" style="padding: 20px 24px; border-bottom: 1px solid var(--border);">
                <h3 class="card-title" style="font-size: 1.05rem; font-weight: 700; color: #fff;">Lịch Sử Đơn Đã Xử Lý</h3>
                <p class="card-subtitle" style="font-size: 0.85rem; color: var(--text-muted);">Danh sách các yêu cầu giải trình bản thảo đã được quyết định phê duyệt hoặc bác bỏ trong quá khứ.</p>
            </div>
            
            <?php if (empty($historyList)): ?>
                <div style="text-align: center; padding: 60px 20px; color: var(--text-muted);">
                    <span style="font-size: 3rem; display: block; margin-bottom: 15px;">📜</span>
                    <p style="font-size: 0.95rem; font-weight: 500;">Chưa có đơn bảo vệ tác phẩm nào được xử lý trước đây.</p>
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th style="padding: 14px 20px;">STT</th>
                                <th style="padding: 14px 20px;">Bộ Truyện (Series)</th>
                                <th style="padding: 14px 20px;">Chương (Chapter)</th>
                                <th style="padding: 14px 20px;">Họa Sĩ (Mangaka)</th>
                                <th style="padding: 14px 20px;">Quyết Định</th>
                                <th style="padding: 14px 20px;">Thời Gian Xử Lý</th>
                                <th style="padding: 14px 20px; text-align: right;">Giải Trình</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $stt = 1;
                            foreach ($historyList as $row): 
                                $isApproved = ($row['status'] === 'approved');
                                $badgeClass = $isApproved ? 'badge-green' : 'badge-red';
                                $statusText = $isApproved ? 'Đã chấp nhận' : 'Đã bác bỏ';
                            ?>
                                <tr>
                                    <td style="padding: 14px 20px;" class="td-muted"><?= $stt++ ?></td>
                                    <td style="padding: 14px 20px;">
                                        <strong style="font-size: 0.9rem; color: #fff;"><?= htmlspecialchars($row['series_title']) ?></strong>
                                    </td>
                                    <td style="padding: 14px 20px;">
                                        <span class="badge badge-blue">Chương <?= htmlspecialchars($row['chapter_number']) ?></span>
                                        <div class="text-xs text-muted" style="margin-top: 2px;"><?= htmlspecialchars($row['chapter_title']) ?></div>
                                    </td>
                                    <td style="padding: 14px 20px;">
                                        <div style="display: flex; align-items: center; gap: 8px;">
                                            <div class="user-avatar-sm" style="width: 28px; height: 28px; border-radius: 50%; background: var(--purple); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 0.75rem; font-weight: 700;">
                                                <?= strtoupper(mb_substr($row['mangaka_name'], 0, 1)) ?>
                                            </div>
                                            <span style="font-weight: 600; font-size: 0.85rem; color: #f0f0f8;"><?= htmlspecialchars($row['mangaka_name']) ?></span>
                                        </div>
                                    </td>
                                    <td style="padding: 14px 20px;">
                                        <span class="badge <?= $badgeClass ?>"><?= $statusText ?></span>
                                    </td>
                                    <td style="padding: 14px 20px; font-size: 0.82rem;" class="td-muted">
                                        <?= date('d/m/Y H:i', strtotime($row['updated_at'])) ?>
                                    </td>
                                    <td style="padding: 14px 20px; text-align: right;">
                                        <button class="btn btn-secondary btn-sm" onclick="showReason(<?= htmlspecialchars(json_encode($row['reason'])) ?>, '<?= htmlspecialchars($row['series_title']) ?>', 'Chương <?= htmlspecialchars($row['chapter_number']) ?>')">
                                            👁 Xem lý do
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Modal xem chi tiết lý do giải trình bảo vệ tác phẩm -->
<div class="modal-backdrop" id="reasonModal" style="display: none;">
    <div class="modal-box" style="max-width: 580px;">
        <div class="modal-header">
            <h3 id="modalTitle" style="display: flex; align-items: center; gap: 8px; font-size: 1.15rem; font-weight: 700; color: #fff;">
                🛡️ Nội dung giải trình chi tiết
            </h3>
            <button class="modal-close" onclick="closeReasonModal()">×</button>
        </div>
        <div class="modal-body" style="padding: 20px 0;">
            <div id="modalMeta" style="margin-bottom: 16px; padding-bottom: 12px; border-bottom: 1px solid var(--border); font-size: 0.85rem; color: var(--text-muted);">
                <strong>Bộ truyện:</strong> <span id="modalSeries" style="color: #fff; margin-right: 18px;"></span>
                <strong>Chương:</strong> <span id="modalChapter" style="color: #fff;"></span>
            </div>
            <div style="background: rgba(255,255,255,0.02); border: 1px solid var(--border); border-radius: 10px; padding: 18px; line-height: 1.7; font-size: 0.92rem; color: #e2e8f0; white-space: pre-wrap; word-break: break-word; max-height: 300px; overflow-y: auto;" id="modalReasonContent"></div>
        </div>
        <div class="modal-footer" style="padding-top: 15px; border-top: 1px solid var(--border);">
            <button class="btn btn-secondary" onclick="closeReasonModal()">Đóng</button>
        </div>
    </div>
</div>

<!-- Tùy biến kiểu CSS để đạt độ thẩm mỹ cao nhất -->
<style>
/* Custom green button style to match premium UI */
.btn-success {
    background: rgba(16,185,129,0.12);
    color: var(--green);
    border: 1px solid rgba(16,185,129,0.2);
}
.btn-success:hover {
    background: var(--green);
    color: #fff;
    box-shadow: 0 4px 12px rgba(16,185,129,0.25);
    transform: translateY(-1px);
}

/* Modal UI adjustments matching global styling standards */
.modal-backdrop {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.75);
    backdrop-filter: blur(6px);
    z-index: 10000;
    align-items: center;
    justify-content: center;
}
.modal-backdrop.open {
    display: flex;
    animation: confirmFadeIn 0.2s ease;
}
.modal-box {
    background: #1e2547;
    border: 1px solid rgba(255,255,255,0.1);
    border-radius: 16px;
    padding: 24px;
    box-shadow: 0 24px 80px rgba(0,0,0,0.7);
    animation: confirmSlideUp 0.25s cubic-bezier(0.34, 1.56, 0.64, 1);
    width: 90%;
}
.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 1px solid var(--border);
    padding-bottom: 12px;
}
.modal-close {
    background: none;
    border: none;
    color: var(--text-muted);
    font-size: 1.5rem;
    cursor: pointer;
    line-height: 1;
    transition: color 0.2s;
}
.modal-close:hover {
    color: #fff;
}
.modal-footer {
    display: flex;
    justify-content: flex-end;
}
</style>

<!-- Xử lý logic Client-Side -->
<script>
/**
 * Chuyển đổi qua lại giữa các tab danh sách
 */
function switchTab(tabId) {
    document.getElementById('tab-content-pending').style.display = 'none';
    document.getElementById('tab-content-history').style.display = 'none';
    
    document.getElementById('tab-btn-pending').classList.remove('active');
    document.getElementById('tab-btn-pending').style.borderBottomColor = 'transparent';
    document.getElementById('tab-btn-pending').style.color = 'var(--text-muted)';
    
    document.getElementById('tab-btn-history').classList.remove('active');
    document.getElementById('tab-btn-history').style.borderBottomColor = 'transparent';
    document.getElementById('tab-btn-history').style.color = 'var(--text-muted)';
    
    document.getElementById('tab-content-' + tabId).style.display = 'block';
    
    const activeBtn = document.getElementById('tab-btn-' + tabId);
    activeBtn.classList.add('active');
    activeBtn.style.borderBottomColor = 'var(--red)';
    activeBtn.style.color = '#fff';
}

/**
 * Hiển thị Modal chi tiết giải trình của họa sĩ
 */
function showReason(reasonText, seriesTitle, chapterNum) {
    document.getElementById('modalReasonContent').textContent = reasonText;
    document.getElementById('modalSeries').textContent = seriesTitle;
    document.getElementById('modalChapter').textContent = chapterNum;
    
    const modal = document.getElementById('reasonModal');
    modal.classList.add('open');
}

/**
 * Đóng Modal giải trình
 */
function closeReasonModal() {
    const modal = document.getElementById('reasonModal');
    modal.classList.remove('open');
}

// Bắt sự kiện click ra ngoài modal để đóng tự động
document.getElementById('reasonModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeReasonModal();
    }
});

// Bắt sự kiện phím ESC để đóng nhanh modal
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeReasonModal();
    }
});

/**
 * Đè sự kiện submit form để sử dụng hộp thoại Confirm Modal xịn sò từ main.js
 */
function confirmApprove(e, seriesTitle, chapterNum) {
    e.preventDefault();
    const form = e.target;
    window.confirmAction(
        `Bạn có chắc chắn muốn PHÊ DUYỆT yêu cầu giải trình của bộ truyện "${seriesTitle}" - Chương ${chapterNum}? Việc này sẽ cập nhật trạng thái bản thảo của họa sĩ thành Đã duyệt (Approved).`, 
        {
            title: 'Chấp nhận đơn giải trình',
            okText: 'Xác nhận duyệt',
            cancelText: 'Hủy bỏ',
            type: 'info'
        }
    ).then(() => {
        form.submit();
    }).catch(() => {});
    return false;
}

function confirmReject(e, seriesTitle, chapterNum) {
    e.preventDefault();
    const form = e.target;
    window.confirmAction(
        `Bạn có chắc chắn muốn BÁC BỎ yêu cầu giải trình của bộ truyện "${seriesTitle}" - Chương ${chapterNum}? Quyết định từ chối bản thảo này sẽ được giữ nguyên (Rejected).`, 
        {
            title: 'Bác bỏ đơn giải trình',
            okText: 'Xác nhận bác bỏ',
            cancelText: 'Hủy bỏ',
            type: 'danger'
        }
    ).then(() => {
        form.submit();
    }).catch(() => {});
    return false;
}
</script>

<?php
// 6. Nhúng Footer đóng các thẻ shell giao diện và import các script cần thiết
require_once __DIR__ . '/../includes/footer.php';
?>
