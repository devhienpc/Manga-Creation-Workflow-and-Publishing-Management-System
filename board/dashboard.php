<?php
/**
 * board/dashboard.php
 * Dashboard Ban Biên Tập (Board) - Thiết kế cao cấp, hiệu ứng sống động.
 */

require_once __DIR__ . '/../config/constants.php';
$pageTitle    = 'Dashboard Ban Biên Tập';
$activePage   = 'dashboard';
$allowedRoles = [ROLES['BOARD']];
require_once __DIR__ . '/../includes/layout.php';
?>

<style>
/* Hiệu ứng Stat Cards */
.dashboard-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 20px;
    margin-bottom: 24px;
}
@media (max-width: 768px) {
    .dashboard-grid {
        grid-template-columns: 1fr;
    }
}

.stat-card {
    background: var(--bg-card);
    border: 1px solid var(--border-card);
    border-radius: var(--radius);
    padding: 24px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    position: relative;
    overflow: hidden;
    transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
    box-shadow: 0 4px 20px rgba(0,0,0,0.15);
    z-index: 1;
}

.stat-card::before {
    content: '';
    position: absolute;
    top: 0; left: 0; width: 100%; height: 100%;
    background: linear-gradient(135deg, rgba(230, 57, 70, 0.1) 0%, transparent 60%);
    opacity: 0;
    transition: opacity 0.4s ease;
    z-index: -1;
}

.stat-card:hover {
    transform: translateY(-6px);
    border-color: var(--red) !important;
    box-shadow: 0 12px 30px var(--red-glow);
}

.stat-card:hover::before {
    opacity: 1;
}

.stat-number {
    font-size: 2.2rem;
    font-weight: 800;
    color: var(--text);
    margin-top: 8px;
    letter-spacing: -0.5px;
    animation: countUp 1s ease-out;
}

.stat-icon {
    width: 56px;
    height: 56px;
    border-radius: 12px;
    background: var(--bg-input);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--red);
    transition: all 0.3s ease;
}

.stat-card:hover .stat-icon {
    background: var(--red);
    color: #fff;
    transform: scale(1.1) rotate(5deg);
    box-shadow: 0 4px 15px var(--red-glow);
}

/* Biểu đồ phần trăm lượt xem động */
.progress-container {
    height: 8px;
    border-radius: 4px;
    background: rgba(255,255,255,0.04);
    overflow: hidden;
    position: relative;
}

.progress-bar-animated {
    height: 100%;
    border-radius: 4px;
    background: linear-gradient(90deg, var(--red), #ff758f);
    transform: scaleX(0);
    transform-origin: left;
    animation: animateProgressBar 1.6s cubic-bezier(0.1, 1, 0.1, 1) forwards;
    box-shadow: 0 0 10px var(--red-glow);
}

@keyframes animateProgressBar {
    to { transform: scaleX(1); }
}

/* Badge Pulse cho bảng dữ liệu */
.pulse-badge {
    position: relative;
    display: inline-block;
}

.pulse-badge::after {
    content: '';
    position: absolute;
    top: 0; left: 0; width: 100%; height: 100%;
    border-radius: 100px;
    box-shadow: 0 0 0 0.15rem rgba(59, 130, 246, 0.4);
    animation: badgePulse 2s infinite;
}

@keyframes badgePulse {
    0% { transform: scale(1); opacity: 1; }
    100% { transform: scale(1.2); opacity: 0; }
}

/* Bàn bỏ phiếu / Data Table Premium */
.data-table-container {
    background: var(--bg-card);
    border: 1px solid var(--border-card);
    border-radius: var(--radius);
    padding: 24px;
}

.table tbody tr {
    transition: all 0.25s ease;
    border-bottom: 1px solid var(--border);
}

.table tbody tr:hover {
    background: var(--bg-hover) !important;
    transform: translateX(4px);
}

.link {
    color: var(--red);
    font-weight: 600;
    transition: all 0.2s ease;
}

.link:hover {
    color: var(--red-dark);
    padding-left: 4px;
}
</style>

<div class="dashboard-grid">
    <div class="stat-card">
        <div>
            <p style="color: var(--text-muted); font-size: 0.85rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin: 0;">Dự án xin xuất bản</p>
            <div class="stat-number">4</div>
        </div>
        <div class="stat-icon"><i class="fi fi-rr-document" style="font-size: 1.5rem;"></i></div>
    </div>
    <div class="stat-card">
        <div>
            <p style="color: var(--text-muted); font-size: 0.85rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin: 0;">Truyện đang xuất bản</p>
            <div class="stat-number">38</div>
        </div>
        <div class="stat-icon"><i class="fi fi-rr-book-open-reader" style="font-size: 1.5rem;"></i></div>
    </div>
    <div class="stat-card">
        <div>
            <p style="color: var(--text-muted); font-size: 0.85rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin: 0;">Đánh giá/Bình chọn mở</p>
            <div class="stat-number">2</div>
        </div>
        <div class="stat-icon"><i class="fi fi-rr-box" style="font-size: 1.5rem;"></i></div>
    </div>
</div>

<div class="content-row">
    <div class="data-table-container" style="flex: 2;">
        <div class="table-header d-flex justify-content-between align-items-center mb-3">
            <h3 style="margin: 0; font-size: 1.1rem; font-weight: 700; color: var(--text);"><i class="fi fi-rr-document-signed" style="color: var(--red); margin-right: 8px;"></i> Các bản thảo cần bỏ phiếu thông qua xuất bản</h3>
            <a href="voting.php" class="link">Xem tất cả <i class="fi fi-rr-arrow-small-right" style="font-size: 1.1rem; vertical-align: middle;"></i></a>
        </div>
        <table class="table" style="width: 100%;">
            <thead>
                <tr>
                    <th style="padding: 12px 8px;">Tên bộ truyện</th>
                    <th>Họa sĩ</th>
                    <th>Biên tập viên đề xuất</th>
                    <th>Đánh giá tổng quát</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td style="padding: 14px 8px;"><strong>Hành trình thế giới ảo (Tập 3)</strong></td>
                    <td>Họa sĩ Manga</td>
                    <td>Nguyễn Biên Tập</td>
                    <td><span class="badge badge-green"><i class="fi fi-rr-checkbox"></i> 8.5/10 (Khuyên đọc)</span></td>
                </tr>
                <tr>
                    <td style="padding: 14px 8px;"><strong>Huyền thoại kiếm sĩ (Tập 1)</strong></td>
                    <td>Họa sĩ Manga</td>
                    <td>Nguyễn Biên Tập</td>
                    <td><span class="badge badge-blue pulse-badge"><i class="fi fi-rr-clock"></i> Chưa bỏ phiếu</span></td>
                </tr>
            </tbody>
        </table>
    </div>
    
    <div class="data-table-container" style="flex: 1; padding: 24px;">
        <h3 style="margin: 0 0 20px 0; font-size: 1.1rem; font-weight: 700; color: var(--text);"><i class="fi fi-rr-chart-histogram" style="color: var(--red); margin-right: 8px;"></i> Thống kê lượt xem tháng</h3>
        <div style="display: flex; flex-direction: column; gap: 20px;">
            <div>
                <div style="display: flex; justify-content: space-between; font-size: 0.9rem; margin-bottom: 8px;">
                    <span style="color: var(--text-muted); font-weight: 500;">Hành trình thế giới ảo</span>
                    <strong style="color: var(--text); font-weight: 700;">1.2M lượt</strong>
                </div>
                <div class="progress-container">
                    <div class="progress-bar-animated" style="width: 85%;"></div>
                </div>
            </div>
            <div>
                <div style="display: flex; justify-content: space-between; font-size: 0.9rem; margin-bottom: 8px;">
                    <span style="color: var(--text-muted); font-weight: 500;">Huyền thoại kiếm sĩ</span>
                    <strong style="color: var(--text); font-weight: 700;">640K lượt</strong>
                </div>
                <div class="progress-container">
                    <div class="progress-bar-animated" style="width: 50%; animation-delay: 0.2s;"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
