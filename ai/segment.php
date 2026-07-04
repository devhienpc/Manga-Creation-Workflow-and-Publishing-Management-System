<?php
require_once __DIR__ . '/../config/constants.php';
$pageTitle    = 'AI Phân Đoạn Vùng';
$activePage   = 'ai_segment';
$allowedRoles = [ROLES['MANGAKA']];
require_once __DIR__ . '/../includes/layout.php';

$db  = getDB();
$uid = $currentUser['id'];

// Lấy danh sách chapters + pages của mangaka này (cho dropdown)
$stmt = $db->prepare(
    "SELECT c.id AS chapter_id, c.chapter_number, c.title AS chapter_title,
            p.id AS page_id, p.page_number, p.original_file,
            s.title AS series_title
     FROM chapters c
     JOIN series  s ON s.id = c.series_id
     JOIN pages   p ON p.chapter_id = c.id
     WHERE s.mangaka_id = ? AND p.original_file IS NOT NULL
     ORDER BY s.title ASC, c.chapter_number ASC, p.page_number ASC"
);
$stmt->execute([$uid]);
$allPages = $stmt->fetchAll();

// Group by chapter for dropdown — chỉ lấy trang có file thực sự tồn tại
$chapterPages = [];
$projectRoot  = dirname(__DIR__);
foreach ($allPages as $row) {
    // Chuẩn hóa path: thay backslash -> forward slash, xóa leading slash
    $normalizedPath = str_replace('\\', '/', ltrim($row['original_file'], '/\\'));
    $row['original_file'] = $normalizedPath;
    
    // Bỏ qua file không tồn tại trên disk
    if (!file_exists($projectRoot . '/' . $normalizedPath)) {
        continue;
    }
    
    $key = $row['chapter_id'];
    if (!isset($chapterPages[$key])) {
        $chapterPages[$key] = [
            'chapter_title' => "Chương {$row['chapter_number']} — {$row['chapter_title']} ({$row['series_title']})",
            'pages' => [],
        ];
    }
    $chapterPages[$key]['pages'][] = $row;
}
?>

<style>
:root {
    --ai-purple:      #7B2FBE;
    --ai-purple-dark: #5a1f8c;
    --ai-purple-glow: rgba(123, 47, 190, 0.35);
    --ai-purple-dim:  rgba(123, 47, 190, 0.12);
}

.ai-badge {
    display: inline-flex; align-items: center; gap: 5px;
    background: linear-gradient(135deg, #7B2FBE, #a855f7);
    color: #fff; font-size: .65rem; font-weight: 800;
    letter-spacing: .8px; text-transform: uppercase;
    padding: 3px 9px; border-radius: 100px;
    box-shadow: 0 2px 10px rgba(123,47,190,.4);
    vertical-align: middle;
}

.segment-layout {
    display: grid;
    grid-template-columns: 1fr 320px;
    gap: 22px;
    align-items: start;
}
@media (max-width: 1000px) {
    .segment-layout { grid-template-columns: 1fr; }
}

/* Tab switcher */
.tab-switcher {
    display: flex; gap: 0; border: 1px solid var(--border);
    border-radius: 8px; overflow: hidden; margin-bottom: 18px;
}
.tab-btn {
    flex: 1; padding: 10px; font-size: .83rem; font-weight: 700;
    background: var(--bg-input); border: none; color: var(--text-muted);
    cursor: pointer; transition: background .2s, color .2s;
}
.tab-btn.active { background: var(--ai-purple); color: #fff; }
.tab-btn:not(:last-child) { border-right: 1px solid var(--border); }

/* Upload zone */
.upload-zone-sm {
    border: 2px dashed var(--border); border-radius: 10px;
    padding: 22px 16px; text-align: center;
    cursor: pointer; transition: border-color .2s, background .2s;
    background: var(--bg-input); position: relative; overflow: hidden;
    margin-bottom: 14px;
}
.upload-zone-sm:hover, .upload-zone-sm.drag-over {
    border-color: var(--ai-purple); background: var(--ai-purple-dim);
}
.upload-zone-sm input { position: absolute; inset: 0; opacity: 0; cursor: pointer; }
.upload-zone-sm p { font-size: .82rem; color: var(--text-muted); margin: 4px 0 0; }

/* Canvas area */
.seg-canvas-wrap {
    position: relative; border-radius: 10px; overflow: hidden;
    border: 2px solid var(--border); background: #0a0a12;
    min-height: 300px; display: flex; align-items: center; justify-content: center;
}
.seg-canvas-wrap img {
    display: block; width: 100%; height: auto;
    pointer-events: none;
}
.seg-canvas-overlay {
    position: absolute; inset: 0; cursor: crosshair;
    pointer-events: none;
}
#segCanvas {
    position: absolute; inset: 0;
    width: 100%; height: 100%;
    pointer-events: auto;
}
.seg-placeholder {
    display: flex; flex-direction: column; align-items: center;
    justify-content: center; gap: 12px; color: var(--text-muted);
    min-height: 280px; padding: 40px;
}
.seg-placeholder .icon { font-size: 3.5rem; filter: drop-shadow(0 0 16px var(--ai-purple-glow)); }

/* Analyze button */
.btn-ai-analyze {
    width: 100%; padding: 13px; border-radius: 10px;
    background: linear-gradient(135deg, #7B2FBE, #a855f7);
    color: #fff; font-weight: 800; font-size: .95rem;
    border: none; cursor: pointer; margin-top: 14px;
    box-shadow: 0 4px 20px rgba(123,47,190,.4);
    transition: opacity .2s, transform .15s;
    display: flex; align-items: center; justify-content: center; gap: 8px;
}
.btn-ai-analyze:hover:not(:disabled) { opacity: .9; transform: translateY(-1px); }
.btn-ai-analyze:disabled { opacity: .5; cursor: not-allowed; transform: none; }

/* Loading text */
.ai-loading-text {
    display: none; text-align: center; padding: 20px;
    color: var(--ai-purple); font-weight: 700; font-size: .92rem;
}
.dots-anim::after {
    content: '';
    animation: dots3 1.4s infinite;
}
@keyframes dots3 {
    0%  { content: ''; }
    33% { content: '.'; }
    66% { content: '..'; }
    100%{ content: '...'; }
}

/* Complexity badge */
.complexity-badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 10px; border-radius: 100px; font-size: .7rem; font-weight: 700;
}
.complexity-low    { background: rgba(16,185,129,.12); color: #10b981; border: 1px solid rgba(16,185,129,.3); }
.complexity-medium { background: rgba(245,158,11,.12); color: #f59e0b; border: 1px solid rgba(245,158,11,.3); }
.complexity-high   { background: rgba(230,57,70,.12);  color: #E63946; border: 1px solid rgba(230,57,70,.3); }

/* Sidebar regions */
.seg-sidebar { position: sticky; top: 24px; }

.ai-seg-item {
    padding: 12px 14px; border-radius: 8px;
    border: 1px solid var(--border); margin-bottom: 8px;
    background: var(--bg-input); transition: border-color .2s;
}
.ai-seg-item.highlighted { border-color: var(--ai-purple); background: var(--ai-purple-dim); }
.ai-seg-item-header { display: flex; align-items: flex-start; gap: 10px; margin-bottom: 8px; }
.ai-seg-check { display: flex; align-items: center; gap: 6px; cursor: pointer; flex-shrink: 0; margin-top: 2px; }
.ai-seg-check input[type=checkbox] { accent-color: var(--ai-purple); width: 14px; height: 14px; }
.ai-seg-color-dot { width: 10px; height: 10px; border-radius: 50%; display: inline-block; flex-shrink: 0; }
.ai-seg-label { font-size: .85rem; font-weight: 700; margin-bottom: 4px; line-height: 1.3; }
.ai-seg-meta  { display: flex; align-items: center; gap: 8px; }
.ai-badge-type {
    font-size: .65rem; font-weight: 700; padding: 2px 7px; border-radius: 100px;
    border: 1px solid; text-transform: uppercase; letter-spacing: .4px;
}
.ai-conf { font-size: .72rem; color: var(--text-muted); font-weight: 600; }

.btn-use-region {
    width: 100%; padding: 7px 10px; border-radius: 6px;
    background: linear-gradient(135deg, rgba(123,47,190,.2), rgba(168,85,247,.2));
    border: 1px solid rgba(123,47,190,.35);
    color: #a855f7; font-size: .78rem; font-weight: 700;
    cursor: pointer; transition: background .15s, transform .1s;
    display: flex; align-items: center; justify-content: center; gap: 5px;
}
.btn-use-region:hover { background: rgba(123,47,190,.3); transform: translateY(-1px); }

.seg-sidebar-empty {
    padding: 30px 20px; text-align: center; color: var(--text-muted); font-size: .85rem;
}
.seg-sidebar-empty .icon { font-size: 2.5rem; margin-bottom: 10px; }

/* Alert */
.ai-alert {
    padding: 12px 16px; border-radius: 8px; font-size: .83rem; font-weight: 600;
    display: none; margin-top: 12px; align-items: center; gap: 10px;
}
.ai-alert.error   { background: rgba(230,57,70,.12);  border:1px solid rgba(230,57,70,.3);  color: #E63946; }
.ai-alert.success { background: rgba(16,185,129,.12); border:1px solid rgba(16,185,129,.3); color: #10b981; }

/* Stats row */
.seg-stats {
    display: flex; gap: 14px; flex-wrap: wrap;
    margin-bottom: 14px; display: none;
}
.seg-stat-item {
    flex: 1; min-width: 80px; padding: 10px 14px;
    background: var(--bg-input); border: 1px solid var(--border);
    border-radius: 8px; text-align: center;
}
.seg-stat-val { font-size: 1.4rem; font-weight: 800; color: var(--ai-purple); }
.seg-stat-lbl { font-size: .7rem; color: var(--text-muted); margin-top: 2px; }
</style>

<!-- Page header -->
<div class="page-header">
    <div class="breadcrumb">
        <a href="<?= BASE_URL ?>mangaka/dashboard.php">Dashboard</a>
        <span class="sep">›</span>
        <span class="current">AI Phân Đoạn</span>
    </div>
    <h1>Phân Đoạn Vùng Trang <span class="ai-badge">✨ AI</span></h1>
    <p>Gemini AI tự động phát hiện các vùng (nhân vật, phông nền, thoại, hiệu ứng...) trên trang manga</p>
</div>

<div class="segment-layout">

<!-- ═══════════════════ CỘT TRÁI: Canvas ═══════════════════ -->
<div>

    <!-- Tabs -->
    <div class="tab-switcher" id="tabSwitcher">
        <button class="tab-btn active" id="tabUpload" onclick="switchTab('upload')">📤 Upload ảnh</button>
        <button class="tab-btn" id="tabPage" onclick="switchTab('page')">📄 Chọn từ DB</button>
    </div>

    <!-- Tab: Upload -->
    <div id="panelUpload">
        <div class="upload-zone-sm" id="uploadZone"
             ondragover="event.preventDefault();this.classList.add('drag-over')"
             ondragleave="this.classList.remove('drag-over')"
             ondrop="handleDrop(event)">
            <input type="file" id="fileInput" accept="image/jpeg,image/png,image/webp"
                   onchange="handleFileSelect(this)">
            <span style="font-size:2rem;">🖼️</span>
            <p>Kéo ảnh trang manga vào đây hoặc click để chọn</p>
            <p>JPG, PNG, WebP — tối đa 10MB</p>
        </div>
        <p id="uploadFileName" style="font-size:.8rem;color:var(--ai-purple);font-weight:600;margin-bottom:8px;display:none;"></p>
    </div>

    <!-- Tab: Chọn từ DB -->
    <div id="panelPage" style="display:none; margin-bottom: 14px;">
        <div class="form-group">
            <label class="form-label">Chương</label>
            <select id="chapterSelect" class="form-control" onchange="loadPagesForChapter(this.value)">
                <option value="">— Chọn chương —</option>
                <?php foreach ($chapterPages as $chId => $ch): ?>
                <option value="<?= $chId ?>"><?= htmlspecialchars($ch['chapter_title']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" id="pageSelectWrap" style="display:none;">
            <label class="form-label">Trang</label>
            <select id="pageSelect" class="form-control">
                <option value="">— Chọn trang —</option>
            </select>
        </div>
        <!-- Preview ảnh trang đã chọn -->
        <div id="dbPagePreview" style="display:none; margin-top:10px; border-radius:8px; overflow:hidden; border:1px solid var(--border);">
            <img id="dbPagePreviewImg" src="" alt="Preview" style="width:100%;height:auto;display:block;max-height:180px;object-fit:contain;background:#0a0a12;">
        </div>
    </div>

    <!-- Alert -->
    <div class="ai-alert error" id="alertBox"><span id="alertMsg"></span></div>

    <!-- Analyze button -->
    <button class="btn-ai-analyze" id="analyzeBtn" onclick="startAnalyze()" disabled>
        🔍 Phân tích bằng AI
    </button>

    <!-- Loading -->
    <div class="ai-loading-text" id="loadingText">
        🤖 AI đang phân tích<span class="dots-anim"></span>
    </div>

    <!-- Stats row -->
    <div class="seg-stats" id="segStats">
        <div class="seg-stat-item">
            <div class="seg-stat-val" id="statRegions">0</div>
            <div class="seg-stat-lbl">Vùng phát hiện</div>
        </div>
        <div class="seg-stat-item">
            <div class="seg-stat-val" id="statComplexity">—</div>
            <div class="seg-stat-lbl">Độ phức tạp</div>
        </div>
    </div>

    <!-- Canvas wrap -->
    <div class="card" style="padding: 0; overflow: hidden; margin-top: 16px;">
        <div class="seg-canvas-wrap" id="canvasWrap">
            <!-- Placeholder -->
            <div class="seg-placeholder" id="canvasPlaceholder">
                <span class="icon">🔍</span>
                <p style="font-size:.85rem;text-align:center;max-width:200px;">
                    Upload ảnh hoặc chọn trang từ DB, rồi nhấn <strong>Phân tích</strong>
                </p>
            </div>
            <!-- Image -->
            <img id="segImage" src="" alt="Trang manga" style="display:none;">
            <!-- Canvas overlay -->
            <canvas id="segCanvas" style="display:none;"></canvas>
        </div>
    </div>
</div>

<!-- ═══════════════════ CỘT PHẢI: Sidebar vùng ═══════════════════ -->
<div class="seg-sidebar">
    <div class="card">
        <div class="card-header">
            <p class="card-title">Danh Sách Vùng</p>
            <span class="ai-badge" id="regionCountBadge" style="display:none;">0 vùng</span>
        </div>
        <div id="regionSidebar">
            <div class="seg-sidebar-empty">
                <div class="icon">📋</div>
                <p>Các vùng phát hiện sẽ hiển thị ở đây sau khi phân tích.</p>
            </div>
        </div>

        <!-- Action bar: chọn vùng rồi chuyển sang Giao Task -->
        <div id="segActionBar" style="display:none;padding:14px 16px;border-top:1px solid var(--border);background:rgba(0,0,0,.15);">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;">
                <label style="display:flex;align-items:center;gap:7px;cursor:pointer;font-size:.82rem;font-weight:700;user-select:none;">
                    <input type="checkbox" id="selectAllChk" style="accent-color:#7B2FBE;width:15px;height:15px;"
                           onchange="handleSelectAll(this.checked)">
                    Chọn tất cả
                </label>
                <span id="selectedCount" style="margin-left:auto;font-size:.75rem;color:var(--text-muted);font-weight:600;">0/0</span>
            </div>
            <button id="btnSendToTasks" onclick="sendToTasks()"
                    style="width:100%;padding:12px 10px;border-radius:9px;
                           background:linear-gradient(135deg,#10b981,#059669);
                           color:#fff;font-weight:800;font-size:.88rem;letter-spacing:.2px;
                           border:none;cursor:pointer;display:flex;align-items:center;
                           justify-content:center;gap:7px;
                           box-shadow:0 3px 14px rgba(16,185,129,.35);
                           transition:opacity .2s,transform .15s;"
                    onmouseover="this.style.opacity='.88';this.style.transform='translateY(-1px)'"
                    onmouseout="this.style.opacity='1';this.style.transform=''">
                ✅ Dùng vùng đã chọn → Giao Task
            </button>
            <p id="segActionNote" style="font-size:.73rem;color:var(--text-muted);margin-top:8px;text-align:center;display:none;">
                ⚠️ Chỉ hoạt động khi chọn trang từ DB (tab "Chọn từ DB")
            </p>
        </div>
    </div>

    <!-- Legend -->
    <div class="card" style="margin-top: 16px; padding: 16px 18px;">
        <p style="font-weight:700;font-size:.83rem;margin-bottom:12px;">🎨 Chú thích màu</p>
        <?php
        $legend = [
            ['background',  '#87CEEB', 'Phông nền'],
            ['character',   '#FF6347', 'Nhân vật'],
            ['effects',     '#FFD700', 'Hiệu ứng'],
            ['shading',     '#90EE90', 'Đổ bóng'],
            ['text_bubble', '#BA55D3', 'Hộp thoại'],
        ];
        foreach ($legend as [$type, $color, $label]): ?>
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:7px;">
            <span style="width:14px;height:14px;border-radius:3px;background:<?= $color ?>;opacity:.7;flex-shrink:0;border:2px solid <?= $color ?>;"></span>
            <span style="font-size:.8rem;color:var(--text-muted);"><?= $label ?></span>
        </div>
        <?php endforeach; ?>
    </div>
</div>

</div><!-- /.segment-layout -->

<?php
$pagesJson = json_encode($chapterPages, JSON_UNESCAPED_UNICODE);
require_once __DIR__ . '/../includes/footer.php';
?>

<script src="<?= BASE_URL ?>assets/js/ai_segment.js"></script>
<script>
/* ── Data from PHP ── */
const chapterPagesData = <?= $pagesJson ?>;

/* ── State ── */
let currentTab  = 'upload';
let selectedFile = null;
let currentPageId = 0;

/* ── Tab switch ── */
function switchTab(tab) {
    currentTab = tab;
    document.getElementById('tabUpload').classList.toggle('active', tab === 'upload');
    document.getElementById('tabPage').classList.toggle('active',   tab === 'page');
    document.getElementById('panelUpload').style.display = tab === 'upload' ? 'block' : 'none';
    document.getElementById('panelPage').style.display   = tab === 'page'   ? 'block' : 'none';
    updateAnalyzeBtn();
}

/* ── File upload ── */
function handleFileSelect(input) {
    const f = input.files[0]; if (!f) return;
    setFile(f);
}
function handleDrop(e) {
    e.preventDefault();
    document.getElementById('uploadZone').classList.remove('drag-over');
    const f = e.dataTransfer.files[0]; if (f) setFile(f);
}
function setFile(f) {
    const allowed = ['image/jpeg', 'image/png', 'image/webp'];
    if (!allowed.includes(f.type)) { showAlert('Chỉ hỗ trợ JPG, PNG, WebP.'); return; }
    if (f.size > 10 * 1024 * 1024) { showAlert('File quá lớn (tối đa 10MB).'); return; }
    hideAlert();
    selectedFile = f;
    const fn = document.getElementById('uploadFileName');
    fn.textContent = '📎 ' + f.name + ' (' + (f.size / 1024).toFixed(0) + ' KB)';
    fn.style.display = 'block';
    updateAnalyzeBtn();
}

/* ── Chapter/page dropdown ── */
function loadPagesForChapter(chId) {
    currentPageId = 0;
    const pageWrap = document.getElementById('pageSelectWrap');
    const pageSel  = document.getElementById('pageSelect');
    const preview  = document.getElementById('dbPagePreview');
    pageSel.innerHTML = '<option value="">— Chọn trang —</option>';
    preview.style.display = 'none';

    if (!chId || !chapterPagesData[chId]) {
        pageWrap.style.display = 'none';
        updateAnalyzeBtn();
        return;
    }
    
    const pages = chapterPagesData[chId].pages;
    if (pages.length === 0) {
        pageWrap.style.display = 'none';
        showAlert('Chương này chưa có trang ảnh nào. Hãy upload ảnh vào chương trước.');
        updateAnalyzeBtn();
        return;
    }
    
    pageWrap.style.display = 'block';
    pages.forEach(p => {
        const opt = document.createElement('option');
        opt.value = p.page_id;
        opt.textContent = 'Trang ' + p.page_number;
        pageSel.appendChild(opt);
    });
    pageSel.onchange = () => {
        currentPageId = parseInt(pageSel.value) || 0;
        // Preview ảnh ngay khi chọn trang
        const pageRow = findPageById(currentPageId);
        if (pageRow && pageRow.original_file) {
            const baseUrl = '<?= rtrim(BASE_URL, "/") ?>';
            const filePath = pageRow.original_file.replace(/\\/g, '/').replace(/^\//, '');
            document.getElementById('dbPagePreviewImg').src = baseUrl + '/' + filePath;
            preview.style.display = 'block';
        } else {
            preview.style.display = 'none';
        }
        updateAnalyzeBtn();
    };
    updateAnalyzeBtn();
}

function updateAnalyzeBtn() {
    let ok = false;
    if (currentTab === 'upload' && selectedFile) ok = true;
    if (currentTab === 'page'   && currentPageId)  ok = true;
    document.getElementById('analyzeBtn').disabled = !ok;
}

/* ── Analyze ── */
async function startAnalyze() {
    hideAlert();
    setAnalyzing(true);

    let body, method, headers = {};

    if (currentTab === 'upload' && selectedFile) {
        const fd = new FormData();
        fd.append('file', selectedFile);
        body   = fd;
        method = 'POST';
    } else if (currentTab === 'page' && currentPageId) {
        body    = JSON.stringify({ page_id: currentPageId });
        method  = 'POST';
        headers = { 'Content-Type': 'application/json' };
    } else {
        showAlert('Vui lòng chọn ảnh hoặc trang.'); setAnalyzing(false); return;
    }

    try {
        const res  = await fetch('<?= BASE_URL ?>api/ai_segment.php', { method, headers, body });
        const data = await res.json();
        setAnalyzing(false);

        if (data.success) {
            renderSegmentResult(data);
        } else {
            showAlert(data.message || 'AI không phân tích được. Thử lại.');
        }
    } catch (err) {
        setAnalyzing(false);
        showAlert('Lỗi kết nối: ' + err.message);
    }
}

/* ── Render result ── */
function renderSegmentResult(data) {
    const regions    = data.regions     || [];
    const complexity = data.page_complexity || 'medium';

    // Stats
    document.getElementById('statRegions').textContent  = regions.length;
    document.getElementById('statComplexity').textContent =
        complexity === 'high' ? '🔴 Cao' : complexity === 'medium' ? '🟡 Vừa' : '🟢 Thấp';
    document.getElementById('segStats').style.display = 'flex';
    document.getElementById('regionCountBadge').textContent = regions.length + ' vùng';
    document.getElementById('regionCountBadge').style.display = 'inline-flex';

    // Show image on canvas
    const img    = document.getElementById('segImage');
    const canvas = document.getElementById('segCanvas');
    document.getElementById('canvasPlaceholder').style.display = 'none';

    // Helper: init AiSegment và render vùng — dùng chung cho tất cả paths
    function _initAndRender(cvs, imgEl, regs) {
        AiSegment.init(cvs, imgEl, document.getElementById('regionSidebar'), null);
        AiSegment.setOnSelectionChange(function(selected, total) {
            updateSelectionUI(selected, total);
        });
        AiSegment.bindCanvasClick(cvs);
        AiSegment.renderRegions(regs);
        // Hiện action bar
        document.getElementById('segActionBar').style.display = 'block';
        // Upload tab: không có page_id → hiện note
        const note = document.getElementById('segActionNote');
        if (currentTab === 'upload') note.style.display = 'block';
        else note.style.display = 'none';
        updateSelectionUI(regs.length, regs.length);
    }

    // Helper chạy an toàn khi ảnh đã sẵn sàng — guard 'done' tránh chạy đôi
    function _whenReady(imgEl, cvs, regs, alsoOnError) {
        let done = false;
        function doRender() {
            if (done) return;
            done = true;
            cvs.style.display  = 'block';
            imgEl.style.display = 'block';
            _initAndRender(cvs, imgEl, regs);
        }
        // Đặt handler TRƯỚC khi gán src để không bỏ lỡ event
        imgEl.onload  = doRender;
        if (alsoOnError) imgEl.onerror = doRender;
        return doRender;   // caller dùng để gọi fallback complete-check
    }

    if (currentTab === 'upload' && selectedFile) {
        const url    = URL.createObjectURL(selectedFile);
        const doRend = _whenReady(img, canvas, regions, false);
        img.src = url;                              // src gán SAU handler
        if (img.complete && img.naturalWidth) doRend(); // đã cache → fallback
    } else {
        // page from DB
        const pageRow = findPageById(currentPageId);
        if (pageRow && pageRow.original_file) {
            let filePath = pageRow.original_file.replace(/\\/g, '/').replace(/^\//, '');
            const baseUrl = '<?= rtrim(BASE_URL, '/') ?>';
            const doRend  = _whenReady(img, canvas, regions, true);
            img.src = baseUrl + '/' + filePath;     // src gán SAU handler
            if (img.complete && img.naturalWidth) doRend();
        } else {
            // Không có ảnh — render vùng không có ảnh nền
            canvas.style.display = 'block';
            _initAndRender(canvas, null, regions);
        }
    }
}

/* ── Selection UI helpers ── */
function updateSelectionUI(selected, total) {
    const countEl = document.getElementById('selectedCount');
    const chk     = document.getElementById('selectAllChk');
    const btn     = document.getElementById('btnSendToTasks');
    if (countEl) countEl.textContent = selected + '/' + total + ' vùng';
    if (chk) {
        chk.checked       = (total > 0 && selected === total);
        chk.indeterminate = (selected > 0 && selected < total);
    }
    if (btn) {
        btn.disabled = (selected === 0);
        btn.style.opacity = selected > 0 ? '1' : '.5';
        btn.style.cursor  = selected > 0 ? 'pointer' : 'not-allowed';
        if (selected > 0) {
            btn.innerHTML = `✅ Dùng <strong>${selected}</strong> vùng đã chọn → Giao Task`;
        } else {
            btn.innerHTML = '⚠️ Chọn ít nhất 1 vùng';
        }
    }
}

function handleSelectAll(checked) {
    if (checked) AiSegment.selectAll();
    else         AiSegment.deselectAll();
}

/* ── Send to Tasks — lưu sessionStorage rồi redirect ── */
function sendToTasks() {
    const selected = AiSegment.getSelectedRegions();
    if (!selected.length) {
        showAlert('Vui lòng chọn ít nhất 1 vùng.');
        return;
    }

    // Tìm chapter_id từ currentPageId (chỉ có khi tab DB)
    let chapterId = null;
    if (currentTab === 'page' && currentPageId) {
        for (const [chId, ch] of Object.entries(chapterPagesData)) {
            if (ch.pages.find(p => p.page_id === currentPageId)) {
                chapterId = parseInt(chId);
                break;
            }
        }
    }

    if (!chapterId || !currentPageId) {
        showAlert('Tính năng này chỉ hoạt động khi chọn trang từ DB (tab "Chọn từ DB"). Ảnh upload không có page_id để liên kết với Task.');
        return;
    }

    // Lưu vào sessionStorage
    sessionStorage.setItem('ai_segment_regions', JSON.stringify({
        regions:    selected,
        page_id:    currentPageId,
        chapter_id: chapterId,
    }));

    // Redirect sang tasks.php với chapter + page đúng
    window.location.href = '<?= BASE_URL ?>mangaka/tasks.php?chapter_id=' + chapterId + '&page_id=' + currentPageId;
}

function findPageById(id) {
    for (const chId of Object.keys(chapterPagesData)) {
        const found = chapterPagesData[chId].pages.find(p => p.page_id === id);
        if (found) return found;
    }
    return null;
}

/* ── UI helpers ── */
function setAnalyzing(on) {
    document.getElementById('analyzeBtn').disabled    = on;
    document.getElementById('loadingText').style.display = on ? 'block' : 'none';
    if (on) { AiSegment.clear(); document.getElementById('segStats').style.display = 'none'; }
}

function showAlert(msg) {
    document.getElementById('alertMsg').textContent = msg;
    document.getElementById('alertBox').style.display = 'flex';
}
function hideAlert() {
    document.getElementById('alertBox').style.display = 'none';
}
</script>
