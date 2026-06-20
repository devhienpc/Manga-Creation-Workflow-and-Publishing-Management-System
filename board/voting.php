<?php
$pageTitle = 'Bình chọn / Đánh giá';
$activeMenu = 'voting';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>
<main class="main-content">
    <?php require_once __DIR__ . '/../includes/navbar.php'; ?>
    <div class="glass-panel" style="padding: 40px; text-align: center;">
        <i class="fa-solid fa-square-poll-vertical" style="font-size: 3rem; color: var(--accent-primary); margin-bottom: 20px;"></i>
        <h2>Hệ thống Bình chọn & Đánh giá bản thảo</h2>
        <p style="color: var(--text-secondary); margin-top: 10px;">Trang đang trong quá trình phát triển. Ban biên tập có thể bỏ phiếu đánh giá chất lượng tác phẩm trước khi ra quyết định xuất bản.</p>
        <a href="dashboard.php" class="btn btn-primary" style="max-width: 200px; margin: 30px auto 0 auto;">
            <i class="fa-solid fa-house"></i> Quay lại Dashboard
        </a>
    </div>
</main>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
