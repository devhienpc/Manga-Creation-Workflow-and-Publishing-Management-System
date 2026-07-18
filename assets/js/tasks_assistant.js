/**
 * assets/js/tasks_assistant.js
 * Frontend logic for Assistant task dashboard.
 * Tasks grouped by Series → Page. One file submission per page.
 */

let activeFilter = 'revision';
let searchQuery = '';
let currentPage = 1;
let loadedTasks = [];

document.addEventListener('DOMContentLoaded', () => {
    initFilters();
    initSearch();
    refreshTasks();
    setInterval(updateDeadlineCountdowns, 60000);
});

// ── Fetch stats and tasks ────────────────────────────
async function refreshTasks() {
    await fetchStats();
    await fetchTasks();
}

async function fetchStats() {
    try {
        const res = await fetch('tasks.php?ajax=get_stats');
        const data = await res.json();
        if (data.success) {
            document.getElementById('statTodo').textContent = data.todo;
            document.getElementById('statRevision').textContent = data.revision;
            document.getElementById('statSubmitted').textContent = data.submitted;
            document.getElementById('statApproved').textContent = data.approved;
            const revBadge = document.getElementById('filterBadgeRevision');
            if (revBadge) {
                revBadge.textContent = data.revision;
                revBadge.style.display = data.revision > 0 ? 'inline-block' : 'none';
            }
        }
    } catch (err) {
        console.error('Lỗi tải thống kê: ', err);
    }
}

async function fetchTasks() {
    const statusParam = activeFilter === 'all' ? '' : activeFilter;
    const url = `${BASE_URL}api/tasks.php?action=get_my_tasks&status=${statusParam}&page=${currentPage}`;
    try {
        const res = await fetch(url);
        const data = await res.json();
        if (data.success && data.tasks) {
            loadedTasks = data.tasks;
            renderTasksList();
            updateDeadlineCountdowns();
        }
    } catch (err) {
        showToast('Lỗi kết nối: ' + err.message, 'error');
    }
}

// ── Helpers ───────────────────────────────────────────
function getTypeLabel(type) {
    const map = {
        background: '<i class="fi fi-rr-paint-brush"></i> Tô nền',
        shading:    '<i class="fi fi-rr-brightness"></i> Tô bóng',
        effects:    '<i class="fi fi-rr-sparkles"></i> Hiệu ứng',
        lettering:  '<i class="fi fi-rr-comment"></i> Thoại',
        cleanup:    '<i class="fi fi-rr-eraser"></i> Làm sạch'
    };
    return map[type] || type;
}

function getStatusBadge(status) {
    const map = {
        revision:    '<span class="badge badge-yellow"><i class="fi fi-rr-refresh"></i> Cần sửa</span>',
        pending:     '<span class="badge badge-gray"><i class="fi fi-rr-clock"></i> Chờ nhận</span>',
        in_progress: '<span class="badge badge-blue"><i class="fi fi-rr-play"></i> Đang làm</span>',
        submitted:   '<span class="badge badge-yellow"><i class="fi fi-rr-document-signed"></i> Chờ duyệt</span>',
        approved:    '<span class="badge badge-green"><i class="fi fi-rr-badge-check"></i> Đã duyệt</span>'
    };
    return map[status] || status;
}

function esc(str) {
    if (!str) return '';
    const d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
}

// ── Rendering Tasks List (grouped by Series → Page) ───
function renderTasksList() {
    const container = document.getElementById('tasksListContainer');
    container.innerHTML = '';

    const filtered = loadedTasks.filter(t => {
        if (!searchQuery) return true;
        const q = searchQuery.toLowerCase();
        return (t.series_title || '').toLowerCase().includes(q) ||
               (t.chapter_title || '').toLowerCase().includes(q) ||
               (t.task_type || '').toLowerCase().includes(q) ||
               (t.description || '').toLowerCase().includes(q);
    });

    if (filtered.length === 0) {
        container.innerHTML = `<div class="empty-state"><p>Không có nhiệm vụ nào thuộc bộ lọc hiện tại.</p></div>`;
        return;
    }

    // Group: series_id → page_id → tasks[]
    const grouped = {};
    filtered.forEach(t => {
        const sKey = t.series_id || 0;
        if (!grouped[sKey]) {
            grouped[sKey] = { title: t.series_title, chapters: {} };
        }
        const pKey = `${t.chapter_number}_${t.page_id}`;
        if (!grouped[sKey].chapters[pKey]) {
            grouped[sKey].chapters[pKey] = {
                chapter_number: t.chapter_number,
                chapter_title: t.chapter_title,
                page_id: t.page_id,
                page_number: t.page_number,
                page_image: t.page_image,
                tasks: []
            };
        }
        grouped[sKey].chapters[pKey].tasks.push(t);
    });

    // Render grouped cards
    Object.values(grouped).forEach(series => {
        const seriesCard = document.createElement('div');
        seriesCard.className = 'series-group';

        let seriesHTML = `<div class="series-header"><h4>${esc(series.title)}</h4></div>`;
        
        const sortedPages = Object.values(series.chapters).sort((a, b) => {
            return a.chapter_number - b.chapter_number || a.page_number - b.page_number;
        });

        sortedPages.forEach(pg => {
            const hasRevision = pg.tasks.some(t => t.status === 'revision');
            const hasSubmittable = pg.tasks.some(t => ['in_progress', 'revision'].includes(t.status));
            const allApproved = pg.tasks.every(t => t.status === 'approved');
            const allSubmitted = pg.tasks.every(t => t.status === 'submitted');

            let pageClass = '';
            if (hasRevision) pageClass = 'page-revision';
            else if (allApproved) pageClass = 'page-approved';
            else if (allSubmitted) pageClass = 'page-submitted';

            seriesHTML += `<div class="page-group ${pageClass}">`;
            seriesHTML += `
                <div class="page-header">
                    <div class="page-header-left">
                        <span class="page-label">Chương ${pg.chapter_number} — ${esc(pg.chapter_title)}</span>
                        <span class="page-number">Trang ${pg.page_number}</span>
                    </div>
                    <div class="page-header-right">
                        <button class="btn btn-ghost btn-sm" onclick="viewPageHighlight(${pg.tasks[0].id})">
                            <i class="fi fi-rr-eye"></i> Xem trang gốc
                        </button>
                    </div>
                </div>`;

            // Render each task as a compact row
            seriesHTML += '<div class="task-rows">';
            pg.tasks.forEach(t => {
                seriesHTML += `<div class="task-row status-${t.status}" data-task-id="${t.id}">`;
                seriesHTML += `
                    <div class="task-row-info">
                        <span class="task-type-label">${getTypeLabel(t.task_type)}</span>
                        <span class="task-price-badge" style="font-weight: 700; color: #fbbf24; margin-left: 8px; font-size: 0.8rem;">${new Intl.NumberFormat('vi-VN').format(t.price)} ₫</span>
                        ${getStatusBadge(t.status)}
                        ${t.due_date ? `<span class="deadline-tag" data-deadline="${t.due_date}"></span>` : ''}
                    </div>
                    <div class="task-row-desc">${esc(t.description || '')}</div>`;

                // Feedback box for revision
                if (t.status === 'revision' && t.revision_comment) {
                    seriesHTML += `
                        <div class="revision-feedback">
                            <strong><i class="fi fi-rr-comment"></i> Phản hồi:</strong> "${esc(t.revision_comment)}"
                            <span class="feedback-meta">— Mangaka ${esc(t.mangaka_name)}${t.revision_at ? ', ' + t.revision_at : ''}</span>
                        </div>`;
                }

                // Actions per status
                if (t.status === 'pending') {
                    seriesHTML += `
                        <div class="task-row-actions">
                            <button class="btn btn-primary btn-sm" onclick="startTask(${t.id})">
                                <i class="fi fi-rr-play"></i> Bắt đầu làm
                            </button>
                        </div>`;
                } else if (t.status === 'submitted') {
                    seriesHTML += `
                        <div class="task-row-meta">
                            ${t.my_latest_file ? `<a href="${BASE_URL + t.my_latest_file}" target="_blank" class="btn btn-ghost btn-sm"><i class="fi fi-rr-eye"></i> Xem file đã nộp</a>` : ''}
                            <span class="submitted-label"><i class="fi fi-rr-clock"></i> Đang chờ mangaka duyệt...</span>
                        </div>`;
                } else if (t.status === 'approved') {
                    seriesHTML += `
                        <div class="task-row-meta">
                            <span class="approved-label"><i class="fi fi-rr-usd-circle"></i> Đã tính lương tháng này</span>
                            <button class="btn btn-ghost btn-sm" onclick="showHistoryTimeline(${t.id})"><i class="fi fi-rr-time-past"></i> Lịch sử</button>
                            <div id="history-box-${t.id}" class="history-box" style="display:none;"></div>
                        </div>`;
                }

                seriesHTML += '</div>'; // .task-row
            });
            seriesHTML += '</div>'; // .task-rows

            // Shared file upload area per PAGE (only if there are submittable tasks)
            if (hasSubmittable) {
                seriesHTML += `
                    <div class="page-submit-form" id="page-submit-${pg.page_id}">
                        <div class="submit-form-header"><i class="fi fi-rr-paper-plane"></i> Nộp kết quả cho trang này</div>
                        <form onsubmit="event.preventDefault(); submitPageTasks(${pg.page_id});">
                            <div class="upload-zone" onclick="this.querySelector('input').click()">
                                <i class="fi fi-rr-cloud-upload-alt upload-icon"></i>
                                <p class="upload-text">Click chọn file hoặc kéo thả</p>
                                <small>JPG, PNG, PSD, ZIP, PDF — tối đa 50MB</small>
                                <input type="file" class="file-input-hidden" onchange="this.closest('.upload-zone').querySelector('.upload-text').textContent = this.files[0]?.name || 'Click chọn file'" required />
                            </div>
                            <textarea class="form-control mt-2" rows="2" placeholder="Ghi chú cho mangaka (tùy chọn)..."></textarea>
                            <button type="submit" class="btn btn-primary btn-submit mt-2" style="width:100%;">
                                <i class="fi fi-rr-paper-plane"></i> Nộp kết quả
                            </button>
                        </form>
                    </div>`;
            }

            // Revision file upload for pages that have ALL revision tasks
            if (hasRevision && pg.tasks.some(t => t.my_latest_file)) {
                const latestFile = pg.tasks.find(t => t.my_latest_file)?.my_latest_file;
                if (latestFile) {
                    seriesHTML += `
                        <div class="page-old-file">
                            <a href="${BASE_URL + latestFile}" target="_blank" class="btn btn-ghost btn-sm"><i class="fi fi-rr-download"></i> Xem/tải file nộp cũ</a>
                        </div>`;
                }
            }

            seriesHTML += '</div>'; // .page-group
        });

        seriesCard.innerHTML = seriesHTML;
        container.appendChild(seriesCard);
    });
}

// ── Filter & Search ───────────────────────────────────
function initFilters() {
    document.querySelectorAll('.filter-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            activeFilter = btn.getAttribute('data-filter');
            currentPage = 1;
            fetchTasks();
        });
    });
}

function initSearch() {
    const el = document.getElementById('taskSearch');
    if (el) el.addEventListener('input', () => { searchQuery = el.value.trim(); renderTasksList(); });
}

// ── Actions ───────────────────────────────────────────
async function startTask(taskId) {
    try {
        const res = await fetch(BASE_URL + 'api/tasks.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'start_task', task_id: taskId })
        });
        const data = await res.json();
        if (data.success) {
            showToast('▶️ Đã bắt đầu làm nhiệm vụ!', 'success');
            await refreshTasks();
        } else {
            showToast(data.message || 'Lỗi.', 'error');
        }
    } catch (err) {
        showToast('Lỗi kết nối: ' + err.message, 'error');
    }
}

async function submitPageTasks(pageId) {
    const wrapper = document.getElementById(`page-submit-${pageId}`);
    if (!wrapper) return;
    const form = wrapper.querySelector('form');
    const fileInput = form.querySelector('input[type=file]');
    const file = fileInput?.files[0];
    const note = form.querySelector('textarea')?.value.trim() || '';

    if (!file) { showToast('Vui lòng chọn file kết quả.', 'error'); return; }
    if (file.size > 50 * 1024 * 1024) { showToast('File quá lớn, tối đa 50MB.', 'error'); return; }

    const formData = new FormData();
    formData.append('action', 'submit_page_tasks');
    formData.append('page_id', pageId);
    formData.append('file', file);
    formData.append('note', note);

    const btn = form.querySelector('.btn-submit');
    const oldText = btn.innerHTML;
    btn.innerHTML = '<i class="fi fi-rr-spinner spin"></i> Đang tải lên...';
    btn.disabled = true;

    try {
        const res = await fetch(BASE_URL + 'api/tasks.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
            showToast(`✅ ${data.message}`, 'success');
            await refreshTasks();
        } else {
            showToast(data.message || 'Lỗi nộp bài.', 'error');
            btn.innerHTML = oldText;
            btn.disabled = false;
        }
    } catch (err) {
        showToast('Lỗi kết nối: ' + err.message, 'error');
        btn.innerHTML = oldText;
        btn.disabled = false;
    }
}

// ── History Timeline ──────────────────────────────────
async function showHistoryTimeline(taskId) {
    const box = document.getElementById(`history-box-${taskId}`);
    if (!box) return;
    if (box.style.display === 'block') { box.style.display = 'none'; return; }

    try {
        const res = await fetch(`${BASE_URL}api/tasks.php?action=get_task_history&task_id=${taskId}`);
        const data = await res.json();
        if (data.success && data.history) {
            box.innerHTML = data.history.map(h => {
                let action = '';
                if (h.review_action === 'approved') action = '<span class="badge badge-green">✅ Duyệt</span>';
                else if (h.review_action === 'revision') action = '<span class="badge badge-yellow">🔄 Sửa</span>';
                return `
                    <div class="history-item">
                        <div class="history-header"><strong>v${h.version}</strong> ${action}</div>
                        ${h.note ? `<div class="history-note">"${esc(h.note)}"</div>` : ''}
                        ${h.review_comment ? `<div class="history-review">${esc(h.review_comment)}</div>` : ''}
                        <div class="history-time">${h.submitted_at}</div>
                    </div>`;
            }).join('');
            box.style.display = 'block';
        }
    } catch (err) {
        showToast('Lỗi: ' + err.message, 'error');
    }
}

// ── Page Preview Modal (fixed — uses taskId lookup) ───
function viewPageHighlight(taskId) {
    const modal = document.getElementById('pagePreviewModal');
    if (!modal) return;

    const t = loadedTasks.find(x => x.id === taskId);
    if (!t) { showToast('Không tìm thấy task.', 'error'); return; }

    // Find all tasks on the same page
    const pageTasks = loadedTasks.filter(x => x.page_id === t.page_id);

    // Build sidebar info dynamically
    const infoList = document.getElementById('modalTasksInfoList');
    if (infoList) {
        let infoHTML = `<h5 class="text-white mb-3" style="font-weight:700;">Nhiệm vụ trên trang (${pageTasks.length})</h5>`;
        pageTasks.forEach((pt, index) => {
            infoHTML += `
                <div class="mb-3 p-3 rounded" style="background: var(--bg-input); border: 1px solid var(--border);">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <strong style="color: var(--red); font-size: 0.85rem;">#${index + 1} - ${getTypeLabel(pt.task_type).toUpperCase()}</strong>
                        ${getStatusBadge(pt.status)}
                    </div>
                    <div class="info-row mb-1">
                        <span class="info-label">MÔ TẢ YÊU CẦU:</span>
                        <p class="info-value mb-0" style="font-size:0.8rem; line-height:1.4;">${esc(pt.description || 'Không có mô tả')}</p>
                    </div>
                    <div class="info-row mb-0">
                        <span class="info-label">HẠN CHÓT:</span>
                        <span class="info-value" style="font-size:0.8rem;">${pt.due_date || 'Không giới hạn'}</span>
                    </div>
                </div>
            `;
        });
        infoList.innerHTML = infoHTML;
    }

    document.getElementById('modalDownloadBtn').href = BASE_URL + (t.page_image || '');

    const canvas = document.getElementById('modalPreviewCanvas');
    const ctx = canvas.getContext('2d');
    const img = new Image();
    img.crossOrigin = 'anonymous';
    img.src = BASE_URL + (t.page_image || '');
    img.onload = () => {
        const maxW = Math.min(550, window.innerWidth - 400);
        const scale = maxW / img.naturalWidth;
        canvas.width = maxW;
        canvas.height = img.naturalHeight * scale;
        ctx.drawImage(img, 0, 0, canvas.width, canvas.height);

        // Dark overlay
        ctx.fillStyle = 'rgba(0, 0, 0, 0.6)';
        ctx.fillRect(0, 0, canvas.width, canvas.height);

        // Highlight regions for all tasks on this page
        pageTasks.forEach((pt, index) => {
            const region = pt.region_data;
            if (region && region.x !== undefined) {
                const rx = (region.x / 100) * canvas.width;
                const ry = (region.y / 100) * canvas.height;
                const rw = (region.w / 100) * canvas.width;
                const rh = (region.h / 100) * canvas.height;

                // Clear overlay for this region
                ctx.clearRect(rx, ry, rw, rh);
                
                // Redraw original image slice in the cleared region
                ctx.drawImage(
                    img,
                    (region.x / 100) * img.naturalWidth,
                    (region.y / 100) * img.naturalHeight,
                    (region.w / 100) * img.naturalWidth,
                    (region.h / 100) * img.naturalHeight,
                    rx, ry, rw, rh
                );
                
                // Stroke border around the region
                ctx.strokeStyle = 'var(--red, #E63946)';
                ctx.lineWidth = 2;
                ctx.strokeRect(rx, ry, rw, rh);

                // Draw badge index number matching the sidebar list
                ctx.fillStyle = 'var(--red, #E63946)';
                ctx.fillRect(rx, ry, 22, 22);
                ctx.fillStyle = '#ffffff';
                ctx.font = 'bold 12px sans-serif';
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                ctx.fillText(index + 1, rx + 11, ry + 11);
            }
        });
    };
    modal.classList.add('visible');
}

function closePagePreviewModal() {
    document.getElementById('pagePreviewModal')?.classList.remove('visible');
}

// ── Deadline countdown ────────────────────────────────
function updateDeadlineCountdowns() {
    document.querySelectorAll('[data-deadline]').forEach(el => {
        const val = el.getAttribute('data-deadline');
        if (!val || val === 'null') { el.textContent = ''; return; }
        const deadline = new Date(val + 'T23:59:59');
        const now = new Date();
        const diffMs = deadline - now;
        const diffDays = Math.floor(diffMs / 86400000);
        const diffHours = Math.floor((diffMs % 86400000) / 3600000);
        if (diffMs < 0) { el.innerHTML = '<i class="fi fi-rr-cross-circle"></i> Quá hạn'; el.className = 'deadline-tag overdue'; }
        else if (diffDays === 0) { el.innerHTML = `<i class="fi fi-rr-clock"></i> Còn ${diffHours}h`; el.className = 'deadline-tag urgent'; }
        else if (diffDays <= 2) { el.innerHTML = `<i class="fi fi-rr-time-fast"></i> Còn ${diffDays} ngày`; el.className = 'deadline-tag warning'; }
        else { el.innerHTML = `<i class="fi fi-rr-calendar"></i> Còn ${diffDays} ngày`; el.className = 'deadline-tag'; }
    });
}
