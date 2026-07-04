<?php
require_once __DIR__ . '/../config/constants.php';
$pageTitle    = 'AI Tô Màu';
$activePage   = 'ai_colorize';
$allowedRoles = [ROLES['MANGAKA'], ROLES['ASSISTANT']];
require_once __DIR__ . '/../includes/layout.php';
?>

<style>
/* ── AI Colorize Page Styles ── */
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

.colorize-layout {
    display: grid;
    grid-template-columns: 420px 1fr;
    gap: 22px;
    align-items: start;
}
@media (max-width: 960px) {
    .colorize-layout { grid-template-columns: 1fr; }
}

/* Upload zone */
.upload-zone {
    border: 2px dashed var(--border);
    border-radius: 12px;
    padding: 32px 20px;
    text-align: center;
    cursor: pointer;
    transition: border-color .25s, background .25s;
    background: var(--bg-input);
    position: relative;
    overflow: hidden;
}
.upload-zone:hover,
.upload-zone.drag-over {
    border-color: var(--ai-purple);
    background: var(--ai-purple-dim);
}
.upload-zone input[type=file] {
    position: absolute; inset: 0; opacity: 0; cursor: pointer;
}
.upload-icon {
    font-size: 2.8rem; margin-bottom: 10px; display: block;
    filter: drop-shadow(0 0 12px var(--ai-purple-glow));
}
.upload-zone p { font-size: .85rem; color: var(--text-muted); margin: 0; }
.upload-zone strong { color: var(--text); font-size: .9rem; }

/* Preview image */
.preview-wrap {
    position: relative; border-radius: 10px; overflow: hidden;
    border: 2px solid var(--border); background: #0a0a12;
    margin-top: 14px; display: none;
}
.preview-wrap img { display: block; width: 100%; height: auto; max-height: 320px; object-fit: contain; }
.preview-remove {
    position: absolute; top: 8px; right: 8px;
    background: rgba(0,0,0,.7); border: 1px solid rgba(255,255,255,.2);
    color: #fff; border-radius: 50%; width: 28px; height: 28px;
    cursor: pointer; display: flex; align-items: center; justify-content: center;
    font-size: .9rem; transition: background .15s;
}
.preview-remove:hover { background: rgba(230,57,70,.8); }

/* Model selector */
.model-card {
    display: flex; align-items: flex-start; gap: 12px;
    padding: 12px 14px; border-radius: 10px;
    border: 2px solid var(--border); cursor: pointer;
    transition: border-color .2s, background .2s;
    margin-bottom: 10px; background: var(--bg-input);
}
.model-card:hover { border-color: rgba(123,47,190,.4); background: var(--ai-purple-dim); }
.model-card input[type=radio] { margin-top: 3px; accent-color: var(--ai-purple); flex-shrink: 0; }
.model-card.selected { border-color: var(--ai-purple); background: var(--ai-purple-dim); }
.model-card-title { font-weight: 700; font-size: .88rem; margin-bottom: 2px; }
.model-card-desc  { font-size: .76rem; color: var(--text-muted); line-height: 1.4; }

/* Colorize button */
.btn-ai-colorize {
    width: 100%; padding: 14px; border-radius: 10px;
    background: linear-gradient(135deg, #7B2FBE, #a855f7);
    color: #fff; font-weight: 800; font-size: 1rem; letter-spacing: .3px;
    border: none; cursor: pointer; margin-top: 16px;
    box-shadow: 0 4px 20px rgba(123,47,190,.45);
    transition: opacity .2s, transform .15s, box-shadow .2s;
    display: flex; align-items: center; justify-content: center; gap: 8px;
}
.btn-ai-colorize:hover:not(:disabled) {
    opacity: .92; transform: translateY(-1px);
    box-shadow: 0 6px 28px rgba(123,47,190,.6);
}
.btn-ai-colorize:disabled { opacity: .55; cursor: not-allowed; transform: none; }

/* Result panel */
.result-panel { position: sticky; top: 24px; }

/* Shimmer */
.shimmer-wrap { display: none; }
.shimmer-box {
    border-radius: 10px; background: linear-gradient(90deg, var(--bg-card) 25%, var(--bg-input) 50%, var(--bg-card) 75%);
    background-size: 200% 100%;
    animation: shimmer 1.4s infinite;
    height: 340px;
}
@keyframes shimmer { 0%{background-position:200% 0} 100%{background-position:-200% 0} }
.shimmer-label {
    text-align: center; padding: 16px 0;
    color: var(--ai-purple); font-weight: 700; font-size: .9rem;
}
.dots-anim::after {
    content: '';
    animation: dots 1.4s infinite;
}
@keyframes dots {
    0%   { content: ''; }
    33%  { content: '.'; }
    66%  { content: '..'; }
    100% { content: '...'; }
}

/* Progress bar (countdown khi model loading) */
.model-loading-bar-wrap {
    display: none; padding: 12px 16px;
    background: var(--ai-purple-dim); border-radius: 8px;
    border: 1px solid rgba(123,47,190,.3); margin-top: 12px;
}
.model-loading-msg { font-size: .82rem; font-weight: 600; color: var(--ai-purple); margin-bottom: 8px; }
.model-loading-bar-bg {
    height: 6px; border-radius: 100px;
    background: rgba(123,47,190,.2); overflow: hidden;
}
.model-loading-bar-fill {
    height: 100%; border-radius: 100px;
    background: linear-gradient(90deg, #7B2FBE, #a855f7);
    transition: width .5s linear;
    width: 100%;
}

/* Result image */
.result-img-wrap { display: none; }
.result-img-wrap img {
    width: 100%; border-radius: 10px;
    border: 2px solid rgba(123,47,190,.4);
    box-shadow: 0 8px 32px rgba(123,47,190,.25);
}
.result-actions {
    display: flex; gap: 10px; flex-wrap: wrap; margin-top: 14px;
}
.btn-result {
    flex: 1; padding: 10px 14px; border-radius: 8px; font-weight: 700;
    font-size: .82rem; cursor: pointer; border: none;
    display: flex; align-items: center; justify-content: center; gap: 6px;
    transition: opacity .15s, transform .15s;
}
.btn-result:hover { opacity: .85; transform: translateY(-1px); }
.btn-download { background: var(--ai-purple); color: #fff; }
.btn-save     { background: rgba(16,185,129,.15); color: #10b981; border: 1px solid rgba(16,185,129,.3); }
.btn-retry    { background: var(--bg-input); color: var(--text-muted); border: 1px solid var(--border); }

/* Empty result state */
.result-empty {
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    min-height: 320px; color: var(--text-muted); text-align: center; gap: 12px;
}
.result-empty .big-icon { font-size: 4rem; filter: drop-shadow(0 0 20px var(--ai-purple-glow)); }
.result-empty p { font-size: .88rem; max-width: 240px; line-height: 1.6; }

/* Alert */
.ai-alert {
    padding: 12px 16px; border-radius: 8px; font-size: .83rem; font-weight: 600;
    display: none; margin-top: 12px; align-items: center; gap: 10px;
}
.ai-alert.error   { background: rgba(230,57,70,.12);  border: 1px solid rgba(230,57,70,.3); color: #E63946; }
.ai-alert.success { background: rgba(16,185,129,.12); border: 1px solid rgba(16,185,129,.3); color: #10b981; }
</style>

<!-- Page header -->
<div class="page-header">
    <div class="breadcrumb">
        <a href="<?= BASE_URL ?>mangaka/dashboard.php">Dashboard</a>
        <span class="sep">›</span>
        <span class="current">AI Tô Màu</span>
    </div>
    <h1>Tô Màu Tự Động <span class="ai-badge">✨ AI</span></h1>
    <p>Upload trang manga trắng đen, AI sẽ tô màu tự động bằng Hugging Face</p>
</div>

<div class="colorize-layout">

<!-- ═══════════════════ CỘT TRÁI: Upload + Settings ═══════════════════ -->
<div>
    <div class="card">
        <div class="card-header">
            <div>
                <p class="card-title">Upload Ảnh Manga</p>
                <p class="card-subtitle">JPG, PNG — tối đa 5MB</p>
            </div>
            <span class="ai-badge">HuggingFace</span>
        </div>

        <!-- Upload zone -->
        <div class="upload-zone" id="uploadZone"
             ondragover="event.preventDefault();this.classList.add('drag-over')"
             ondragleave="this.classList.remove('drag-over')"
             ondrop="handleDrop(event)">
            <input type="file" id="fileInput" accept="image/jpeg,image/png,image/webp"
                   onchange="handleFileSelect(this)">
            <span class="upload-icon">🎨</span>
            <strong>Kéo ảnh vào đây hoặc click để chọn</strong>
            <p>Hỗ trợ JPG, PNG, WebP — Tối đa 5MB</p>
        </div>

        <!-- Preview -->
        <div class="preview-wrap" id="previewWrap">
            <img id="previewImg" src="" alt="Preview">
            <button class="preview-remove" onclick="clearFile()" title="Xóa ảnh">✕</button>
        </div>

        <!-- Alert -->
        <div class="ai-alert error" id="alertBox">
            <span id="alertMsg"></span>
        </div>

        <!-- Model selector -->
        <div style="margin-top: 20px;">
            <p class="form-label" style="margin-bottom: 12px;">Chọn Model Tô Màu</p>

            <label class="model-card selected" id="modelCard-manga">
                <input type="radio" name="model" value="manga" checked
                       onchange="updateModelCard()">
                <div>
                    <div class="model-card-title">🖌️ Manga Colorization</div>
                    <div class="model-card-desc">Phong cách manga truyền thống, tối ưu cho truyện tranh đen trắng</div>
                </div>
            </label>

            <label class="model-card" id="modelCard-anime">
                <input type="radio" name="model" value="anime"
                       onchange="updateModelCard()">
                <div>
                    <div class="model-card-title">🌸 Anime Style</div>
                    <div class="model-card-desc">Phong cách anime Nhật Bản, màu sắc tươi sáng và sinh động</div>
                </div>
            </label>

            <label class="model-card" id="modelCard-auto">
                <input type="radio" name="model" value="auto"
                       onchange="updateModelCard()">
                <div>
                    <div class="model-card-title">⚡ Auto Color</div>
                    <div class="model-card-desc">Tô màu tự động thông minh, phù hợp với nhiều phong cách</div>
                </div>
            </label>
        </div>

        <!-- Loading bar khi model warm-up -->
        <div class="model-loading-bar-wrap" id="modelLoadingBar">
            <div class="model-loading-msg" id="modelLoadingMsg">🔄 Model đang khởi động...</div>
            <div class="model-loading-bar-bg">
                <div class="model-loading-bar-fill" id="modelLoadingFill"></div>
            </div>
        </div>

        <!-- Submit button -->
        <button class="btn-ai-colorize" id="colorizeBtn" onclick="startColorize()" disabled>
            🎨 Tô màu bằng AI
        </button>

        <!-- Page ID hidden (nếu đến từ tasks) -->
        <input type="hidden" id="sourcePageId" value="<?= (int)($_GET['page_id'] ?? 0) ?>">
    </div>
</div>

<!-- ═══════════════════ CỘT PHẢI: Kết quả ═══════════════════ -->
<div class="result-panel">
    <div class="card">
        <div class="card-header">
            <div>
                <p class="card-title">Kết Quả Tô Màu</p>
                <p class="card-subtitle" id="resultSubtitle">Chưa có kết quả</p>
            </div>
            <span class="ai-badge" id="resultBadge" style="display:none;">✅ Xong</span>
        </div>

        <!-- Empty state -->
        <div class="result-empty" id="resultEmpty">
            <span class="big-icon">🖼️</span>
            <p>Upload ảnh và nhấn <strong>Tô màu</strong> để bắt đầu.<br>AI sẽ tự động xử lý và trả về ảnh màu.</p>
        </div>

        <!-- Shimmer loading -->
        <div class="shimmer-wrap" id="shimmerWrap">
            <div class="shimmer-box"></div>
            <div class="shimmer-label">
                🤖 AI đang tô màu<span class="dots-anim"></span>
            </div>
        </div>

        <!-- Result -->
        <div class="result-img-wrap" id="resultImgWrap">
            <img id="resultImg" src="" alt="Ảnh đã tô màu">
            <div class="result-actions">
                <button class="btn-result btn-download" onclick="downloadResult()" id="btnDownload">
                    ⬇️ Tải xuống
                </button>
                <?php if ((int)($_GET['page_id'] ?? 0) > 0): ?>
                <button class="btn-result btn-save" onclick="saveToPage()">
                    💾 Lưu vào trang
                </button>
                <?php endif; ?>
                <button class="btn-result btn-retry" onclick="retryColorize()">
                    🔄 Thử lại
                </button>
            </div>
        </div>
    </div>

    <!-- Tips card -->
    <div class="card" style="margin-top: 18px; padding: 16px 20px;">
        <p style="font-weight: 700; font-size: .85rem; margin-bottom: 10px;">💡 Mẹo sử dụng</p>
        <ul style="font-size: .8rem; color: var(--text-muted); line-height: 1.8; padding-left: 18px; margin: 0;">
            <li>Ảnh rõ nét, nét vẽ đậm cho kết quả tốt nhất</li>
            <li>Model lần đầu chạy cần 20–60 giây để khởi động</li>
            <li>Ảnh sẽ được resize về 512×512 để tối ưu tốc độ</li>
            <li>Kết quả phụ thuộc vào model — thử nhiều model để so sánh</li>
        </ul>
    </div>
</div>

</div><!-- /.colorize-layout -->

<?php
require_once __DIR__ . '/../includes/footer.php';
?>

<script>
/* ── State ── */
let selectedFile   = null;
let currentResultUrl = null;
let retryTimer     = null;
let countdownTimer = null;

/* ── File select ── */
function handleFileSelect(input) {
    const file = input.files[0];
    if (!file) return;
    validateAndSetFile(file);
}

function handleDrop(e) {
    e.preventDefault();
    document.getElementById('uploadZone').classList.remove('drag-over');
    const file = e.dataTransfer.files[0];
    if (file) validateAndSetFile(file);
}

function validateAndSetFile(file) {
    const maxSize = 5 * 1024 * 1024;
    const allowed = ['image/jpeg', 'image/png', 'image/webp'];
    if (!allowed.includes(file.type)) {
        showAlert('Chỉ hỗ trợ JPG, PNG, WebP.'); return;
    }
    if (file.size > maxSize) {
        showAlert('File quá lớn. Tối đa 5MB.'); return;
    }
    hideAlert();
    selectedFile = file;

    // Preview
    const reader = new FileReader();
    reader.onload = e => {
        document.getElementById('previewImg').src = e.target.result;
        document.getElementById('previewWrap').style.display = 'block';
    };
    reader.readAsDataURL(file);

    document.getElementById('colorizeBtn').disabled = false;
}

function clearFile() {
    selectedFile = null;
    document.getElementById('fileInput').value = '';
    document.getElementById('previewWrap').style.display = 'none';
    document.getElementById('previewImg').src = '';
    document.getElementById('colorizeBtn').disabled = true;
    hideAlert();
}

/* ── Model cards ── */
function updateModelCard() {
    const selected = document.querySelector('input[name=model]:checked')?.value;
    document.querySelectorAll('.model-card').forEach(c => c.classList.remove('selected'));
    if (selected) {
        document.getElementById('modelCard-' + selected)?.classList.add('selected');
    }
}

/* ── Colorize ── */
async function startColorize() {
    if (!selectedFile) { showAlert('Vui lòng chọn ảnh trước.'); return; }
    const model = document.querySelector('input[name=model]:checked')?.value || 'auto';

    setLoading(true);
    hideAlert();

    const fd = new FormData();
    fd.append('file', selectedFile);
    fd.append('model', model);
    fd.append('page_id', document.getElementById('sourcePageId').value);

    try {
        const res  = await fetch('<?= BASE_URL ?>api/ai_colorize.php', { method: 'POST', body: fd });
        const data = await res.json();

        if (data.loading === true) {
            // Model đang warm-up — hiển thị countdown rồi retry
            const est = data.estimated_time || 30;
            showModelLoading(est, () => startColorize());
            return;
        }

        setLoading(false);

        if (data.success) {
            showResult(data.result_url, data.fallback === true, data.message || '');
        } else {
            showAlert(data.message || 'Lỗi không xác định.');
        }
    } catch (err) {
        setLoading(false);
        showAlert('Lỗi kết nối. Vui lòng thử lại: ' + err.message);
    }
}

function retryColorize() {
    clearTimeout(retryTimer);
    clearInterval(countdownTimer);
    document.getElementById('modelLoadingBar').style.display = 'none';
    hideResult();
    startColorize();
}

/* ── Model warm-up countdown ── */
function showModelLoading(seconds, callback) {
    setLoading(false);
    const wrap = document.getElementById('modelLoadingBar');
    const msg  = document.getElementById('modelLoadingMsg');
    const fill = document.getElementById('modelLoadingFill');

    wrap.style.display = 'block';
    fill.style.width   = '100%';

    let remaining = seconds;
    msg.textContent = `⏳ Model đang khởi động, vui lòng chờ ${remaining}s...`;

    countdownTimer = setInterval(() => {
        remaining--;
        fill.style.width = ((remaining / seconds) * 100) + '%';
        msg.textContent = `⏳ Model đang khởi động, vui lòng chờ ${remaining}s...`;
        if (remaining <= 0) {
            clearInterval(countdownTimer);
            wrap.style.display = 'none';
            msg.textContent = '🔄 Model đang khởi động...';
        }
    }, 1000);

    retryTimer = setTimeout(() => {
        wrap.style.display = 'none';
        if (typeof callback === 'function') callback();
    }, seconds * 1000 + 1500);
}

/* ── UI states ── */
function setLoading(on) {
    document.getElementById('shimmerWrap').style.display  = on ? 'block' : 'none';
    document.getElementById('resultEmpty').style.display  = on ? 'none'  : (!currentResultUrl ? 'flex' : 'none');
    document.getElementById('colorizeBtn').disabled       = on;
    if (on) hideResult();
}

function showResult(url, isFallback = false, fallbackMsg = '') {
    currentResultUrl = url;
    document.getElementById('resultImg').src = url;
    document.getElementById('resultImgWrap').style.display = 'block';
    document.getElementById('resultEmpty').style.display   = 'none';
    document.getElementById('shimmerWrap').style.display   = 'none';
    
    const subtitleEl = document.getElementById('resultSubtitle');
    const badgeEl = document.getElementById('resultBadge');
    
    if (isFallback) {
        subtitleEl.innerHTML = `<span style="color:#f59e0b;font-weight:700;">⚠️ ${fallbackMsg}</span>`;
        badgeEl.textContent = '⚠️ Dự phòng';
        badgeEl.style.background = 'linear-gradient(135deg, #f59e0b, #d97706)';
        badgeEl.style.boxShadow = '0 2px 10px rgba(245,158,11,.4)';
    } else {
        subtitleEl.textContent = 'Đã tô màu thành công bằng AI!';
        badgeEl.textContent = '✅ Xong';
        badgeEl.style.background = 'linear-gradient(135deg, #10b981, #059669)';
        badgeEl.style.boxShadow = '0 2px 10px rgba(16,185,129,.4)';
    }
    badgeEl.style.display = 'inline-flex';
}

function hideResult() {
    document.getElementById('resultImgWrap').style.display = 'none';
    document.getElementById('resultBadge').style.display   = 'none';
    document.getElementById('resultSubtitle').textContent  = 'Đang xử lý...';
}

function showAlert(msg) {
    const box = document.getElementById('alertBox');
    document.getElementById('alertMsg').textContent = msg;
    box.classList.add('error');
    box.style.display = 'flex';
}
function hideAlert() {
    document.getElementById('alertBox').style.display = 'none';
}

/* ── Download ── */
function downloadResult() {
    if (!currentResultUrl) return;
    const a = document.createElement('a');
    a.href     = currentResultUrl;
    a.download = 'manga_colorized_' + Date.now() + '.jpg';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
}

/* ── Save to page (composite_file) ── */
async function saveToPage() {
    const pageId = parseInt(document.getElementById('sourcePageId').value);
    if (!pageId || !currentResultUrl) return;

    try {
        const res  = await fetch('<?= BASE_URL ?>api/upload.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action:        'save_composite',
                page_id:       pageId,
                composite_url: currentResultUrl,
            }),
        });
        const data = await res.json();
        if (data.success) {
            alert('✅ Đã lưu vào trang thành công!');
        } else {
            alert('❌ Lỗi: ' + (data.message || 'Không thể lưu.'));
        }
    } catch (e) {
        alert('Lỗi kết nối: ' + e.message);
    }
}
</script>
