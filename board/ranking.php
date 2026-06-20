<?php
$pageTitle = 'Xếp hạng tổng quát';
$activeMenu = 'ranking';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>
<main class="main-content">
    <?php require_once __DIR__ . '/../includes/navbar.php'; ?>
    <div class="glass-panel" style="padding: 40px; text-align: center;">
        <i class="fa-solid fa-trophy" style="font-size: 3rem; color: var(--accent-primary); margin-bottom: 20px;"></i>
        <h2>Xếp hạng tổng quát hệ thống</h2>
        <p style="color: var(--text-secondary); margin-top: 10px;">Trang đang trong quá trình phát triển. Báo cáo thống kê chi tiết lượt đọc, lượt bình luận và đánh giá của độc giả thực tế sẽ xuất hiện ở đây.</p>
        <a href="dashboard.php" class="btn btn-primary" style="max-width: 200px; margin: 30px auto 0 auto;">
            <i class="fa-solid fa-house"></i> Quay lại Dashboard
        </a>
    </div>
</main>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
