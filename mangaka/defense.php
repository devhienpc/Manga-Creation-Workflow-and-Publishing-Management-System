<?php
/**
 * mangaka/defense.php
 * Trang dành cho Họa sĩ (Mangaka) để xem và tạo đơn Giải trình bảo vệ tác phẩm.
 */

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';

if (!isLoggedIn() || getCurrentUser()['role'] !== ROLES['MANGAKA']) {
    header('Location: ' . BASE_URL . 'auth/login.php');
    exit();
}

$db = getDB();
$currentUser = getCurrentUser();
$mangakaId = $currentUser['id'];

// Xử lý tạo đơn giải trình mới
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_defense') {
    $chapterId = intval($_POST['chapter_id'] ?? 0);
    $manuscriptId = intval($_POST['manuscript_id'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');

    if ($chapterId > 0 && $manuscriptId > 0 && !empty($reason)) {
        try {
            $db->beginTransaction();
            
            // Kiểm tra xem đã có đơn pending nào cho chapter này chưa
            $checkStmt = $db->prepare("SELECT id FROM defenses WHERE chapter_id = ? AND status = 'pending'");
            $checkStmt->execute([$chapterId]);
            if ($checkStmt->fetch()) {
                throw new Exception("Chương này đang có đơn giải trình chờ xử lý.");
            }

            // Thêm đơn
            $stmt = $db->prepare("INSERT INTO defenses (mangaka_id, chapter_id, manuscript_id, reason, status, created_at, updated_at) VALUES (?, ?, ?, ?, 'pending', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
            $stmt->execute([$mangakaId, $chapterId, $manuscriptId, $reason]);

            $db->commit();
            $_SESSION['success'] = "Đã gửi đơn giải trình thành công!";
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $_SESSION['error'] = "Lỗi: " . $e->getMessage();
        }
        header('Location: defense.php');
        exit();
    }
}

// Layout
$pageTitle = 'Bảo vệ tác phẩm';
$activePage = 'defense';
$allowedRoles = [ROLES['MANGAKA']];
require_once __DIR__ . '/../includes/layout.php';

$success_msg = $_SESSION['success'] ?? '';
$error_msg = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

// Query 1: Các bản thảo bị từ chối CHƯA có đơn giải trình pending
$stmtRejected = $db->prepare("
    SELECT m.id AS manuscript_id, m.chapter_id, c.chapter_number, s.title AS series_title
    FROM manuscripts m
    JOIN chapters c ON m.chapter_id = c.id
    JOIN series s ON m.series_id = s.id
    WHERE m.status = 'rejected' 
      AND s.mangaka_id = ?
      AND NOT EXISTS (
          SELECT 1 FROM defenses d 
          WHERE d.chapter_id = m.chapter_id 
          AND d.status = 'pending'
      )
");
$stmtRejected->execute([$mangakaId]);
$rejectedList = $stmtRejected->fetchAll();

// Query 2: Các đơn giải trình đã gửi (Pending / Approved / Rejected)
$stmtDefenses = $db->prepare("
    SELECT d.*, s.title AS series_title, c.chapter_number
    FROM defenses d
    JOIN chapters c ON d.chapter_id = c.id
    JOIN series s ON c.series_id = s.id
    WHERE d.mangaka_id = ?
    ORDER BY d.created_at DESC
");
$stmtDefenses->execute([$mangakaId]);
$defenses = $stmtDefenses->fetchAll();
?>

<div class="page-header">
    <div class="breadcrumb">
        <a href="<?= BASE_URL ?>mangaka/dashboard.php">Dashboard</a>
        <span class="separator">/</span>
        <span class="current">Bảo vệ tác phẩm</span>
    </div>
    <h1>Bảo Vệ Tác Phẩm / Giải Trình Bản Thảo</h1>
    <p>Gửi đơn giải trình cho các bản thảo bị Biên tập viên từ chối để phục hồi trạng thái kiểm duyệt.</p>
</div>

<?php if (!empty($success_msg)): ?>
    <div class="alert alert-success" style="margin-bottom: 24px;">✓ <?php echo htmlspecialchars($success_msg); ?></div>
<?php endif; ?>
<?php if (!empty($error_msg)): ?>
    <div class="alert alert-error" style="margin-bottom: 24px;">✕ <?php echo htmlspecialchars($error_msg); ?></div>
<?php endif; ?>

<div class="card mb-24">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
        <h3 class="card-title">Tạo đơn giải trình mới</h3>
    </div>
    <div class="card-body">
        <?php if (empty($rejectedList)): ?>
            <p class="text-muted">Không có bản thảo nào bị từ chối (hoặc tất cả các bản thảo bị từ chối đều đã có đơn giải trình).</p>
        <?php else: ?>
            <form method="POST">
                <input type="hidden" name="action" value="create_defense">
                <div class="form-group">
                    <label class="form-label">Chọn bản thảo bị từ chối *</label>
                    <select name="chapter_id" id="chapterSelect" class="form-control" required onchange="updateManuscriptId()">
                        <option value="">-- Chọn bản thảo --</option>
                        <?php foreach ($rejectedList as $r): ?>
                            <option value="<?= $r['chapter_id'] ?>" data-mid="<?= $r['manuscript_id'] ?>">
                                <?= htmlspecialchars($r['series_title']) ?> - Chương <?= htmlspecialchars($r['chapter_number']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <input type="hidden" name="manuscript_id" id="manuscriptIdInput">
                </div>
                <div class="form-group">
                    <label class="form-label">Lý do giải trình *</label>
                    <textarea name="reason" class="form-control" rows="4" required placeholder="Nhập lý do tại sao bạn cho rằng bản thảo này không nên bị từ chối, hoặc những sửa đổi bạn đã thực hiện..."></textarea>
                </div>
                <button type="submit" class="btn btn-primary">📤 Gửi đơn giải trình</button>
            </form>
            <script>
            function updateManuscriptId() {
                const sel = document.getElementById('chapterSelect');
                const opt = sel.options[sel.selectedIndex];
                if(opt && opt.dataset.mid) {
                    document.getElementById('manuscriptIdInput').value = opt.dataset.mid;
                } else {
                    document.getElementById('manuscriptIdInput').value = '';
                }
            }
            </script>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">Danh sách đơn giải trình của bạn</h3>
    </div>
    <div class="table-wrap">
        <?php if(empty($defenses)): ?>
            <p class="text-muted" style="padding: 20px;">Bạn chưa gửi đơn giải trình nào.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>STT</th>
                        <th>Bộ Truyện</th>
                        <th>Chương</th>
                        <th>Ngày Gửi</th>
                        <th>Trạng Thái</th>
                        <th>Lý Do</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $stt = 1;
                    foreach ($defenses as $d): 
                        $statusClass = 'badge-gray';
                        $statusLabel = 'Chờ xử lý';
                        if ($d['status'] === 'approved') {
                            $statusClass = 'badge-green';
                            $statusLabel = 'Đã duyệt';
                        } elseif ($d['status'] === 'rejected') {
                            $statusClass = 'badge-red';
                            $statusLabel = 'Từ chối';
                        }
                    ?>
                    <tr>
                        <td class="td-muted"><?= $stt++ ?></td>
                        <td><strong><?= htmlspecialchars($d['series_title']) ?></strong></td>
                        <td>Chương <?= htmlspecialchars($d['chapter_number']) ?></td>
                        <td class="td-muted"><?= date('d/m/Y H:i', strtotime($d['created_at'])) ?></td>
                        <td><span class="badge <?= $statusClass ?>"><?= $statusLabel ?></span></td>
                        <td>
                            <div style="max-width: 200px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-size: 0.85rem;" class="td-muted" title="<?= htmlspecialchars($d['reason']) ?>">
                                <?= htmlspecialchars($d['reason']) ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
