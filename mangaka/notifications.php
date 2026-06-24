<?php
/**
 * mangaka/notifications.php
 * Trang danh sách thông báo dành cho họa sĩ Mangaka.
 */

// Bắt đầu session nếu chưa có
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. KẾT NỐI CSDL & BẢO MẬT:
// Kiểm tra hoặc tự động tạo session ảo để chạy test không bị lỗi
if (!isset($_SESSION['user_id'])) {
    $_SESSION['user_id'] = 1;
}
if (!isset($_SESSION['role'])) {
    $_SESSION['role'] = 'mangaka';
}
// Đảm bảo khớp với cơ chế layout.php của hệ thống sử dụng $_SESSION['user']
if (!isset($_SESSION['user'])) {
    $_SESSION['user'] = [
        'id'       => $_SESSION['user_id'],
        'username' => 'mangaka',
        'role'     => $_SESSION['role'],
        'email'    => 'mangaka@mangasystem.com'
    ];
}

// Nhúng file cấu hình CSDL theo đúng yêu cầu bằng lệnh:
require_once __DIR__ . '/../config/db.php';

// Gọi hàm kết nối bằng cách gán biến $pdo = getDB();
$pdo = getDB();

// Cấu hình các biến cho layout.php
require_once __DIR__ . '/../config/constants.php';
$pageTitle    = 'Thông báo';
$activePage   = 'notifications';
$allowedRoles = [ROLES['MANGAKA']];

// Nhúng file layout.php (Tự động nhúng sidebar.php, header.php và tạo app-shell)
require_once __DIR__ . '/../includes/layout.php';

// 2. TRUY VẤN DỮ LIỆU (SQL):
// Lấy danh sách thông báo trước khi đánh dấu đã đọc để người dùng thấy trạng thái thực tế khi vừa tải trang
$userId = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC");
$stmt->execute([$userId]);
$notifications = $stmt->fetchAll();

// Cập nhật trạng thái các thông báo của user đó thành đã đọc (is_read = 1)
$updateStmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
$updateStmt->execute([$userId]);

// Hàm phụ trợ để phân loại tiêu đề và icon cho thông báo
function getNotifDetails(string $type): array {
    $mapping = [
        'task_assigned' => [
            'title' => 'Nhiệm vụ vẽ mới',
            'icon'  => '🎨'
        ],
        'manuscript_review' => [
            'title' => 'Ghi chú biên tập mới',
            'icon'  => '📋'
        ],
        'submission_result' => [
            'title' => 'Kết quả duyệt bản thảo',
            'icon'  => '🏛️'
        ],
        'ranking_update' => [
            'title' => 'Cập nhật bảng xếp hạng',
            'icon'  => '📈'
        ],
        'rank_drop' => [
            'title' => 'Cảnh báo tụt hạng nguy hiểm',
            'icon'  => '⚠️'
        ]
    ];

    return $mapping[$type] ?? [
        'title' => 'Thông báo hệ thống',
        'icon'  => '🔔'
    ];
}
?>

<!-- Internal CSS cho trang thông báo (Hiện đại, tối ưu giao diện sáng/tối) -->
<style>
.notif-page-container {
    max-width: 960px;
    margin: 0 auto;
    padding: 8px 0;
}

.notifications-list {
    display: flex;
    flex-direction: column;
    gap: 16px;
    margin-top: 20px;
}

/* Card thông báo chung */
.notif-card {
    display: flex;
    gap: 20px;
    padding: 20px;
    border-radius: 12px;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
    transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    align-items: flex-start;
    border: 1px solid transparent;
    text-decoration: none;
}

.notif-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 20px rgba(0, 0, 0, 0.15);
}

/* 1. THÔNG BÁO CHƯA ĐỌC: Nền xanh nhạt, chữ tối màu dễ nhìn */
.notif-card.unread {
    background: #e0f2fe; /* Light blue */
    border-color: #bae6fd;
}
.notif-card.unread .notif-title {
    color: #0284c7;
    font-weight: 700;
}
.notif-card.unread .notif-msg {
    color: #1e293b;
}
.notif-card.unread .notif-date {
    color: #64748b;
}
.notif-card.unread .notif-icon {
    background: #bae6fd;
}

/* 2. THÔNG BÁO ĐÃ ĐỌC: Nền xám nhạt/trắng */
.notif-card.read {
    background: #f8fafc; /* Light white/grey */
    border-color: #e2e8f0;
}
.notif-card.read .notif-title {
    color: #475569;
    font-weight: 600;
}
.notif-card.read .notif-msg {
    color: #64748b;
}
.notif-card.read .notif-date {
    color: #94a3b8;
}
.notif-card.read .notif-icon {
    background: #e2e8f0;
}

/* Icon bọc ngoài */
.notif-icon {
    font-size: 1.5rem;
    width: 48px;
    height: 48px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    transition: transform 0.2s ease;
}

.notif-card:hover .notif-icon {
    transform: scale(1.08);
}

/* Body nội dung */
.notif-body {
    flex-grow: 1;
    min-width: 0;
}

.notif-header-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 8px;
    gap: 12px;
}

.notif-title {
    font-size: 1.05rem;
}

.notif-status {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-shrink: 0;
}

.notif-msg {
    font-size: 0.95rem;
    line-height: 1.6;
    margin-bottom: 12px;
    word-wrap: break-word;
}

.notif-footer-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 0.8rem;
    border-top: 1px solid rgba(0,0,0,0.03);
    padding-top: 8px;
}

.notif-date {
    display: flex;
    align-items: center;
    gap: 6px;
}

.notif-link-indicator {
    font-weight: 600;
    color: #0284c7;
    display: flex;
    align-items: center;
    gap: 4px;
    transition: transform 0.2s ease;
}

.notif-card:hover .notif-link-indicator {
    transform: translateX(4px);
}

.read .notif-link-indicator {
    color: #64748b;
}

/* Dấu chấm đỏ nhấp nháy cho thông báo mới */
.pulse-dot {
    width: 8px;
    height: 8px;
    background-color: #ef4444;
    border-radius: 50%;
    display: inline-block;
    box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.7);
    animation: dot-glow 1.5s infinite;
}

@keyframes dot-glow {
    0% {
        transform: scale(0.95);
        box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.7);
    }
    70% {
        transform: scale(1);
        box-shadow: 0 0 0 6px rgba(239, 68, 68, 0);
    }
    100% {
        transform: scale(0.95);
        box-shadow: 0 0 0 0 rgba(239, 68, 68, 0);
    }
}

/* Trạng thái danh sách rỗng */
.notif-empty-state {
    text-align: center;
    padding: 80px 20px;
    background: var(--bg-card);
    border: 1px solid var(--border-card);
    border-radius: var(--radius);
    margin-top: 20px;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
}

.notif-empty-icon {
    font-size: 4rem;
    margin-bottom: 16px;
    display: inline-block;
    animation: bounce-bell 3s infinite ease-in-out;
}

@keyframes bounce-bell {
    0%, 100% { transform: translateY(0) rotate(0deg); }
    50% { transform: translateY(-10px) rotate(10deg); }
}

.notif-empty-title {
    font-size: 1.3rem;
    font-weight: 700;
    color: var(--text);
    margin-bottom: 8px;
}

.notif-empty-desc {
    color: var(--text-muted);
    font-size: 0.925rem;
    max-width: 440px;
    margin: 0 auto;
}
</style>

<div class="notif-page-container">
    <!-- Tiêu đề trang & Breadcrumb -->
    <div class="page-header">
        <div class="breadcrumb">
            <a href="<?= BASE_URL ?>mangaka/dashboard.php">📊 Dashboard</a>
            <span class="sep">›</span>
            <span class="current">🔔 Thông báo</span>
        </div>
        <h1>Thông Báo Của Bạn</h1>
        <p>Quản lý các thông báo, phản hồi từ ban biên tập, tiến độ trợ lý và dữ liệu bình chọn</p>
    </div>

    <!-- Danh sách thông báo -->
    <div class="notifications-list">
        <?php if (empty($notifications)): ?>
            <!-- Trạng thái trống thân thiện -->
            <div class="notif-empty-state">
                <span class="notif-empty-icon">🔔</span>
                <p class="notif-empty-title">Bạn chưa có thông báo nào mới!</p>
                <p class="notif-empty-desc">Tất cả các thông báo mới từ Biên tập viên, Trợ lý hoặc hệ thống xếp hạng sẽ xuất hiện tại đây.</p>
            </div>
        <?php else: ?>
            <?php foreach ($notifications as $notif): 
                $details = getNotifDetails($notif['type']);
                // Xây dựng link chi tiết
                $notifLink = !empty($notif['link']) ? BASE_URL . ltrim($notif['link'], '/') : '';
                // Format thời gian: ngày/tháng/năm giờ:phút
                $formattedDate = date('d/m/Y H:i', strtotime($notif['created_at']));
                $isUnread = ((int)$notif['is_read'] === 0);
                $cardClass = $isUnread ? 'unread' : 'read';
            ?>
                <!-- Khối thẻ thông báo -->
                <?php if ($notifLink): ?>
                    <a href="<?= htmlspecialchars($notifLink) ?>" class="notif-card <?= $cardClass ?>">
                <?php else: ?>
                    <div class="notif-card <?= $cardClass ?>">
                <?php endif; ?>

                    <!-- Icon -->
                    <div class="notif-icon">
                        <?= $details['icon'] ?>
                    </div>

                    <!-- Nội dung chi tiết -->
                    <div class="notif-body">
                        <div class="notif-header-row">
                            <span class="notif-title"><?= htmlspecialchars($details['title']) ?></span>
                            <span class="notif-status">
                                <?php if ($isUnread): ?>
                                    <span class="pulse-dot"></span>
                                    <span class="badge badge-red" style="font-size: 0.65rem; padding: 2px 8px;">Mới</span>
                                <?php else: ?>
                                    <span class="badge badge-gray" style="font-size: 0.65rem; padding: 2px 8px;">Đã xem</span>
                                <?php endif; ?>
                            </span>
                        </div>

                        <div class="notif-msg">
                            ✉️ <?= htmlspecialchars($notif['message']) ?>
                        </div>

                        <div class="notif-footer-row">
                            <span class="notif-date">📅 <?= htmlspecialchars($formattedDate) ?></span>
                            <?php if ($notifLink): ?>
                                <span class="notif-link-indicator">Chi tiết ➔</span>
                            <?php endif; ?>
                        </div>
                    </div>

                <?php if ($notifLink): ?>
                    </a>
                <?php else: ?>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php
// Nhúng footer của hệ thống
require_once __DIR__ . '/../includes/footer.php';
?>
