<?php
$pageTitle = 'Dashboard Ban Biên Tập';
$activeMenu = 'dashboard';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

// Bảo vệ trang
if ($currentUser['role'] !== ROLE_BOARD) {
    redirectDashboard($currentUser['role']);
}
?>
<main class="main-content">
    <?php require_once __DIR__ . '/../includes/navbar.php'; ?>
    
    <div class="dashboard-grid">
        <div class="glass-panel stat-card">
            <div>
                <p style="color: var(--text-secondary); font-size: 0.9rem; font-weight: 500;">Dự án xin xuất bản</p>
                <div class="stat-number">4</div>
            </div>
            <div class="stat-icon"><i class="fa-solid fa-scroll"></i></div>
        </div>
        <div class="glass-panel stat-card">
            <div>
                <p style="color: var(--text-secondary); font-size: 0.9rem; font-weight: 500;">Truyện đang xuất bản</p>
                <div class="stat-number">38</div>
            </div>
            <div class="stat-icon"><i class="fa-solid fa-passport"></i></div>
        </div>
        <div class="glass-panel stat-card">
            <div>
                <p style="color: var(--text-secondary); font-size: 0.9rem; font-weight: 500;">Đánh giá/Bình chọn mở</p>
                <div class="stat-number">2</div>
            </div>
            <div class="stat-icon"><i class="fa-solid fa-vote-yea"></i></div>
        </div>
    </div>
    
    <div class="content-row">
        <div class="glass-panel data-table-container">
            <div class="table-header">
                <h3>Các bản thảo cần bỏ phiếu thông qua xuất bản</h3>
                <a href="voting.php" class="link">Chi tiết các lượt bầu chọn <i class="fa-solid fa-arrow-right"></i></a>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Tên bộ truyện</th>
                        <th>Họa sĩ</th>
                        <th>Biên tập viên đề xuất</th>
                        <th>Đánh giá tổng quát</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><strong>Hành trình thế giới ảo (Tập 3)</strong></td>
                        <td>Họa sĩ Manga</td>
                        <td>Nguyễn Biên Tập</td>
                        <td><span class="badge badge-green">8.5/10 (Khuyên đọc)</span></td>
                    </tr>
                    <tr>
                        <td><strong>Huyền thoại kiếm sĩ (Tập 1)</strong></td>
                        <td>Họa sĩ Manga</td>
                        <td>Nguyễn Biên Tập</td>
                        <td><span class="badge badge-cyan">Chưa bỏ phiếu</span></td>
                    </tr>
                </tbody>
            </table>
        </div>
        
        <div class="glass-panel" style="padding: 24px;">
            <h3>Thống kê lượt xem tháng</h3>
            <div style="margin-top: 20px; display: flex; flex-direction: column; gap: 15px;">
                <div>
                    <div style="display: flex; justify-content: space-between; font-size: 0.9rem; margin-bottom: 5px;">
                        <span>Hành trình thế giới ảo</span>
                        <strong>1.2M lượt</strong>
                    </div>
                    <div style="height: 6px; border-radius: 3px; background: rgba(255,255,255,0.05);">
                        <div style="height: 100%; border-radius: 3px; background: var(--accent-primary); width: 85%;"></div>
                    </div>
                </div>
                <div>
                    <div style="display: flex; justify-content: space-between; font-size: 0.9rem; margin-bottom: 5px;">
                        <span>Huyền thoại kiếm sĩ</span>
                        <strong>640K lượt</strong>
                    </div>
                    <div style="height: 6px; border-radius: 3px; background: rgba(255,255,255,0.05);">
                        <div style="height: 100%; border-radius: 3px; background: var(--accent-secondary); width: 50%;"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
