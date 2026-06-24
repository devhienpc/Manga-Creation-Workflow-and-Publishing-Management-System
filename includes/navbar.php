<div class="top-bar">
    <div class="page-title">
        <h2><?php echo $pageTitle ?? 'Dashboard'; ?></h2>
        <p>Hệ thống quản lý quy trình sản xuất truyện tranh Manga</p>
    </div>
    <div style="display: flex; gap: 15px; align-items: center;">
        <span class="badge badge-purple" style="font-size: 0.8rem; padding: 6px 12px;">
            <i class="fa-regular fa-calendar"></i> <?php echo date('d/m/Y'); ?>
        </span>
        <span class="badge badge-cyan" style="font-size: 0.8rem; padding: 6px 12px; text-transform: capitalize;">
            <i class="fa-solid fa-user-tag"></i> Vai trò: <?php echo $currentUser['role']; ?>
        </span>
    </div>
</div>
