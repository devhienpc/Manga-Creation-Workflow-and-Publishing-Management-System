/**
 * assets/js/tasks_mangaka.js
 * Frontend logic for Mangaka task manager and review.
 */

// State variables
let selectedSeriesId = 0;
let selectedChapterId = 0;
let selectedPageId = 0;
let selectedPageOriginalFile = '';
let loadedTasks = [];
let activeFilter = 'all';

// Drawing state
let isDrawing = false;
let startX = 0;
let startY = 0;
let currentDrawW = 0;
let currentDrawH = 0;

// AI Segmentation state
let aiSegRegions = [];
let aiSegHidden = new Set();

// Canvas context
let canvas = null;
let ctx = null;

// Initialize when DOM ready
document.addEventListener('DOMContentLoaded', () => {
    initSelectors();
    initCanvas();
    initFilterTabs();
    initQuickReviewTemplates();
});

// ── Dropdown Selectors ────────────────────────────────
function initSelectors() {
    const seriesSelect = document.getElementById('seriesSelect');
    const chapterSelect = document.getElementById('chapterSelect');
    const pageSelect = document.getElementById('pageSelect');

    seriesSelect.addEventListener('change', async () => {
        selectedSeriesId = parseInt(seriesSelect.value) || 0;
        selectedChapterId = 0;
        selectedPageId = 0;
        
        chapterSelect.innerHTML = '<option value="">-- Chọn Chapter --</option>';
        pageSelect.innerHTML = '<option value="">-- Chọn Trang --</option>';
        chapterSelect.disabled = !selectedSeriesId;
        pageSelect.disabled = true;
        
        hideMainContent();

        if (selectedSeriesId) {
            try {
                const res = await fetch(`tasks.php?ajax=get_chapters&series_id=${selectedSeriesId}`);
                const data = await res.json();
                if (data.success && data.chapters) {
                    data.chapters.forEach(ch => {
                        const opt = document.createElement('option');
                        opt.value = ch.id;
                        opt.textContent = `Chương ${ch.chapter_number} — ${ch.title}`;
                        chapterSelect.appendChild(opt);
                    });
                    chapterSelect.disabled = false;
                }
            } catch (err) {
                showToast('Lỗi tải danh sách chapters: ' + err.message, 'error');
            }
        }
    });

    chapterSelect.addEventListener('change', async () => {
        selectedChapterId = parseInt(chapterSelect.value) || 0;
        selectedPageId = 0;
        
        pageSelect.innerHTML = '<option value="">-- Chọn Trang --</option>';
        pageSelect.disabled = !selectedChapterId;
        
        hideMainContent();

        if (selectedChapterId) {
            try {
                const res = await fetch(`tasks.php?ajax=get_pages&chapter_id=${selectedChapterId}`);
                const data = await res.json();
                if (data.success && data.pages) {
                    data.pages.forEach(p => {
                        const opt = document.createElement('option');
                        opt.value = p.id;
                        let statusIcon = '[Chờ]';
                        if (p.status === 'approved') statusIcon = '[Đã duyệt]';
                        else if (p.status === 'revision') statusIcon = '[Cần sửa]';
                        else if (p.status === 'in_progress') statusIcon = '[Đang làm]';
                        
                        opt.textContent = `${statusIcon} Trang ${p.page_number}`;
                        pageSelect.appendChild(opt);
                    });
                    pageSelect.disabled = false;
                }
            } catch (err) {
                showToast('Lỗi tải danh sách trang: ' + err.message, 'error');
            }
        }
    });

    pageSelect.addEventListener('change', async () => {
        selectedPageId = parseInt(pageSelect.value) || 0;
        if (selectedPageId) {
            await loadPageDetail();
        } else {
            hideMainContent();
        }
    });
}

function hideMainContent() {
    document.getElementById('mainContentArea').style.display = 'none';
    document.getElementById('summaryStickyBar').style.display = 'none';
    clearAiSegment();
}

async function loadPageDetail() {
    try {
        const res = await fetch(`tasks.php?ajax=get_page_detail&page_id=${selectedPageId}`);
        const data = await res.json();
        if (data.success && data.page) {
            selectedPageOriginalFile = data.page.original_file;
            
            // Set image source
            const pageImage = document.getElementById('pageImage');
            pageImage.src = data.page.original_url;
            pageImage.onload = () => {
                resizeCanvas();
            };
            
            document.getElementById('mainContentArea').style.display = 'grid';
            
            // Show canvas and drawing area only if status != 'approved'
            const canvasWrapper = document.getElementById('canvasWrapper');
            const formCard = document.getElementById('taskFormCard');
            
            if (data.page.status === 'approved') {
                canvasWrapper.style.pointerEvents = 'none';
                formCard.style.display = 'none';
                document.getElementById('approvedPageMsg').style.display = 'block';
                document.getElementById('aiSegFloatingBtn').style.display = 'none';
            } else {
                canvasWrapper.style.pointerEvents = 'auto';
                formCard.style.display = 'none';
                document.getElementById('approvedPageMsg').style.display = 'none';
                document.getElementById('aiSegFloatingBtn').style.display = 'flex';
            }

            // Load tasks for page
            await refreshTaskList();
            
            // Show summary bar
            document.getElementById('summaryStickyBar').style.display = 'flex';
        } else {
            showToast(data.message || 'Lỗi tải chi tiết trang.', 'error');
        }
    } catch (err) {
        showToast('Lỗi kết nối tải trang: ' + err.message, 'error');
    }
}

// ── HTML5 Canvas Drawing ──────────────────────────────
function initCanvas() {
    canvas = document.getElementById('taskCanvas');
    ctx = canvas.getContext('2d');
    
    // Mouse drawing events
    canvas.addEventListener('mousedown', (e) => {
        // Only allow drawing if drawing interactive is enabled
        const formCard = document.getElementById('taskFormCard');
        if (document.getElementById('approvedPageMsg').style.display === 'block') return;
        
        isDrawing = true;
        const rect = canvas.getBoundingClientRect();
        
        // Calculate coords as %
        startX = ((e.clientX - rect.left) / rect.width) * 100;
        startY = ((e.clientY - rect.top) / rect.height) * 100;
        currentDrawW = 0;
        currentDrawH = 0;
        
        formCard.style.display = 'none';
    });

    canvas.addEventListener('mousemove', (e) => {
        if (!isDrawing) return;
        
        const rect = canvas.getBoundingClientRect();
        const curX = ((e.clientX - rect.left) / rect.width) * 100;
        const curY = ((e.clientY - rect.top) / rect.height) * 100;
        
        currentDrawW = curX - startX;
        currentDrawH = curY - startY;
        
        redrawCanvas();
        drawRegionPreview(startX, startY, currentDrawW, currentDrawH, '#E63946');
    });

    canvas.addEventListener('mouseup', (e) => {
        if (!isDrawing) return;
        isDrawing = false;
        
        const w = currentDrawW;
        const h = currentDrawH;
        
        // Form is displayed only if region is large enough (> 2%)
        if (Math.abs(w) > 2 && Math.abs(h) > 2) {
            const rx = Math.min(startX, startX + w);
            const ry = Math.min(startY, startY + h);
            const rw = Math.abs(w);
            const rh = Math.abs(h);
            
            showTaskForm(rx, ry, rw, rh);
        } else {
            redrawCanvas();
        }
    });

    // Tap to highlight matching tasks
    canvas.addEventListener('click', (e) => {
        if (Math.abs(currentDrawW) > 2 || Math.abs(currentDrawH) > 2) return;
        
        const rect = canvas.getBoundingClientRect();
        const mx = ((e.clientX - rect.left) / rect.width) * 100;
        const my = ((e.clientY - rect.top) / rect.height) * 100;
        
        let selectedTask = null;
        let minArea = Infinity;
        
        loadedTasks.forEach(t => {
            if (t.region_data) {
                const r = t.region_data;
                if (mx >= r.x && mx <= r.x + r.w && my >= r.y && my <= r.y + r.h) {
                    const area = r.w * r.h;
                    if (area < minArea) {
                        selectedTask = t;
                        minArea = area;
                    }
                }
            }
        });
        
        if (selectedTask) {
            highlightTaskCard(selectedTask.id);
        }
    });

    window.addEventListener('resize', () => {
        resizeCanvas();
    });
}

function resizeCanvas() {
    const img = document.getElementById('pageImage');
    if (!img || !canvas || img.style.display === 'none') return;
    
    // Sync size and position of canvas overlays
    canvas.style.left = img.offsetLeft + 'px';
    canvas.style.top = img.offsetTop + 'px';
    canvas.style.width = img.clientWidth + 'px';
    canvas.style.height = img.clientHeight + 'px';
    
    canvas.width = img.clientWidth;
    canvas.height = img.clientHeight;
    
    // Sync AI Canvas
    const aiCanvas = document.getElementById('aiSegCanvas');
    if (aiCanvas) {
        aiCanvas.style.left = img.offsetLeft + 'px';
        aiCanvas.style.top = img.offsetTop + 'px';
        aiCanvas.style.width = img.clientWidth + 'px';
        aiCanvas.style.height = img.clientHeight + 'px';
        aiCanvas.width = img.clientWidth;
        aiCanvas.height = img.clientHeight;
        if (aiSegRegions.length > 0) {
            drawAiCanvas();
        }
    }
    
    redrawCanvas();
}

function redrawCanvas(highlightTaskId = null) {
    if (!ctx || !canvas) return;
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    
    // Draw all regions
    loadedTasks.forEach(t => {
        if (!t.region_data) return;
        
        const r = t.region_data;
        const isHighlighted = (t.id === highlightTaskId);
        
        const px = (r.x / 100) * canvas.width;
        const py = (r.y / 100) * canvas.height;
        const pw = (r.w / 100) * canvas.width;
        const ph = (r.h / 100) * canvas.height;
        
        // Define color based on task type
        let color = '#95A5A6';
        if (t.task_type === 'background') color = '#3498DB';
        else if (t.task_type === 'shading') color = '#F39C12';
        else if (t.task_type === 'effects') color = '#9B59B6';
        else if (t.task_type === 'lettering') color = '#2ECC71';
        else if (t.task_type === 'cleanup') color = '#7f8c8d';
        
        ctx.strokeStyle = color;
        ctx.lineWidth = isHighlighted ? 4 : 2;
        ctx.setLineDash(isHighlighted ? [] : [6, 4]);
        
        // Stroke rectangle
        ctx.strokeRect(px, py, pw, ph);
        
        // Fill area
        ctx.fillStyle = color + (isHighlighted ? '25' : '08');
        ctx.fillRect(px, py, pw, ph);
        
        // Label position (make sure it stays inside bounds)
        const labelY = py - 18 < 0 ? py + 18 : py - 18;
        const textY = py - 18 < 0 ? py + 13 : py - 5;
        
        // Label header background
        ctx.fillStyle = color;
        ctx.font = 'bold 11px Inter, sans-serif';
        const labelText = `${t.task_type.toUpperCase()} - ${t.assistant_name}`;
        const textWidth = ctx.measureText(labelText).width;
        ctx.fillRect(px, labelY, textWidth + 12, 18);
        
        // Label text
        ctx.fillStyle = '#FFFFFF';
        ctx.fillText(labelText, px + 6, textY);
        
        // Draw status symbol on bottom right
        let statusSym = '⚪';
        if (t.status === 'in_progress') statusSym = '🔵';
        else if (t.status === 'submitted') statusSym = '🟠';
        else if (t.status === 'approved') statusSym = '✅';
        else if (t.status === 'revision') statusSym = '🔄';
        
        ctx.font = '12px Arial';
        ctx.fillText(statusSym, px + pw - 20, py + ph - 6);
    });
}

function drawRegionPreview(x, y, w, h, color) {
    if (!ctx || !canvas) return;
    const px = (x / 100) * canvas.width;
    const py = (y / 100) * canvas.height;
    const pw = (w / 100) * canvas.width;
    const ph = (h / 100) * canvas.height;
    
    ctx.strokeStyle = color;
    ctx.lineWidth = 2;
    ctx.setLineDash([5, 3]);
    ctx.strokeRect(px, py, pw, ph);
    ctx.fillStyle = color + '15';
    ctx.fillRect(px, py, pw, ph);
}

// ── Task Form Handler ──────────────────────────────────
function showTaskForm(x, y, w, h) {
    const formCard = document.getElementById('taskFormCard');
    formCard.style.display = 'block';
    
    // Fill coordinates
    document.getElementById('coordX').value = x.toFixed(2);
    document.getElementById('coordY').value = y.toFixed(2);
    document.getElementById('coordW').value = w.toFixed(2);
    document.getElementById('coordH').value = h.toFixed(2);
    
    document.getElementById('regionCoords').textContent = `Vùng: x:${x.toFixed(1)}% y:${y.toFixed(1)}% w:${w.toFixed(1)}% h:${h.toFixed(1)}%`;
    
    // Clear inputs
    document.getElementById('taskDescription').value = '';
    document.getElementById('taskDueDate').value = '';
    document.getElementById('taskType').value = 'background';
    
    // Scroll form into view
    formCard.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function cancelTaskCreate() {
    document.getElementById('taskFormCard').style.display = 'none';
    currentDrawW = 0;
    currentDrawH = 0;
    redrawCanvas();
}

async function submitCreateTask(event) {
    event.preventDefault();
    
    const page_id = selectedPageId;
    const assigned_to = parseInt(document.getElementById('taskAssistant').value) || 0;
    const task_type = document.getElementById('taskType').value;
    const description = document.getElementById('taskDescription').value.trim();
    const due_date = document.getElementById('taskDueDate').value;
    
    const x = parseFloat(document.getElementById('coordX').value);
    const y = parseFloat(document.getElementById('coordY').value);
    const w = parseFloat(document.getElementById('coordW').value);
    const h = parseFloat(document.getElementById('coordH').value);
    
    if (assigned_to <= 0) {
        showToast('Vui lòng chọn trợ lý vẽ.', 'error');
        return;
    }
    if (description.length < 20) {
        showToast('Mô tả công việc quá ngắn (tối thiểu 20 ký tự).', 'error');
        return;
    }
    
    const region_data = JSON.stringify({ x, y, w, h });
    const payload = {
        action: 'create_task',
        page_id,
        assigned_to,
        task_type,
        description,
        due_date,
        region_data
    };
    
    try {
        const res = await fetch(BASE_URL + 'api/tasks.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const data = await res.json();
        
        if (data.success) {
            showToast('✅ Đã giao nhiệm vụ thành công!', 'success');
            cancelTaskCreate();
            await loadPageDetail(); // reload page & tasks
        } else {
            showToast(data.message || 'Lỗi giao việc.', 'error');
        }
    } catch (err) {
        showToast('Lỗi kết nối API: ' + err.message, 'error');
    }
}

// ── Tasks List and Filtering ──────────────────────────
async function refreshTaskList() {
    try {
        const res = await fetch(`${BASE_URL}api/tasks.php?action=get_page_tasks&page_id=${selectedPageId}`);
        const data = await res.json();
        
        if (data.success && data.tasks) {
            loadedTasks = data.tasks;
            renderTasksList();
            redrawCanvas();
            updateStickySummary(data.page_summary);
        }
    } catch (err) {
        showToast('Lỗi tải danh sách task: ' + err.message, 'error');
    }
}

function initFilterTabs() {
    const tabs = document.querySelectorAll('.filter-tab');
    tabs.forEach(tab => {
        tab.addEventListener('click', () => {
            tabs.forEach(t => t.classList.remove('active'));
            tab.classList.add('active');
            activeFilter = tab.getAttribute('data-filter');
            renderTasksList();
        });
    });
}

function renderTasksList() {
    const listContainer = document.getElementById('tasksListContainer');
    listContainer.innerHTML = '';
    
    const filtered = loadedTasks.filter(t => {
        if (activeFilter === 'all') return true;
        if (activeFilter === 'pending') return t.status === 'pending';
        if (activeFilter === 'in_progress') return t.status === 'in_progress';
        if (activeFilter === 'submitted') return t.status === 'submitted';
        if (activeFilter === 'approved') return t.status === 'approved';
        if (activeFilter === 'revision') return t.status === 'revision';
        return true;
    });

    document.getElementById('taskCountHeader').innerHTML = `Tasks trang này (${loadedTasks.length} tasks | <span class="text-orange">${loadedTasks.filter(t=>t.status==='submitted').length} cần review</span>)`;

    if (filtered.length === 0) {
        listContainer.innerHTML = '<div class="empty-state">Không tìm thấy nhiệm vụ nào.</div>';
        return;
    }

    filtered.forEach(t => {
        const card = document.createElement('div');
        card.className = `task-card border-${t.task_type} ${t.status}`;
        card.id = `task-card-${t.id}`;
        card.addEventListener('mouseenter', () => {
            redrawCanvas(t.id);
        });
        card.addEventListener('mouseleave', () => {
            redrawCanvas();
        });

        let badgeClass = 'badge-gray';
        let badgeLabel = 'Làm sạch';
        if (t.task_type === 'background') { badgeClass = 'badge-blue'; badgeLabel = 'Tô nền'; }
        else if (t.task_type === 'shading') { badgeClass = 'badge-orange'; badgeLabel = 'Tô bóng'; }
        else if (t.task_type === 'effects') { badgeClass = 'badge-purple'; badgeLabel = 'Hiệu ứng'; }
        else if (t.task_type === 'lettering') { badgeClass = 'badge-green'; badgeLabel = 'Hội thoại'; }
        
        let statusDot = '<i class="fi fi-rr-clock text-muted"></i>';
        if (t.status === 'in_progress') statusDot = '<i class="fi fi-rr-play text-blue"></i>';
        else if (t.status === 'submitted') statusDot = '<i class="fi fi-rr-document-signed text-warning"></i>';
        else if (t.status === 'approved') statusDot = '<i class="fi fi-rr-checkbox text-success"></i>';
        else if (t.status === 'revision') statusDot = '<i class="fi fi-rr-refresh text-danger"></i>';

        let innerContent = `
            <div class="task-card-header">
                <span class="badge ${badgeClass}">${badgeLabel}</span>
                <span class="status-indicator" title="Trạng thái: ${t.status}">${statusDot}</span>
            </div>
            <div class="task-card-meta">
                <span><i class="fi fi-rr-user"></i> <strong>${t.assistant_name}</strong></span>
                <span><i class="fi fi-rr-calendar"></i> Hạn: ${t.due_date ? t.due_date : 'Không giới hạn'}</span>
            </div>
            <p class="task-card-desc"><i class="fi fi-rr-document" style="opacity: 0.7;"></i> ${t.description}</p>
        `;

        if (t.status === 'submitted') {
            innerContent += `
                <div class="review-panel">
                    <div class="review-panel-meta">
                        <span><i class="fi fi-rr-upload text-warning"></i> Nộp lúc: ${t.submitted_at ? t.submitted_at : 'Vừa xong'} | v${t.current_version}</span>
                        ${t.submission_note ? `<p class="submission-note"><i class="fi fi-rr-comment"></i> "${t.submission_note}"</p>` : ''}
                    </div>
                    <div class="review-panel-actions">
                        <button class="btn btn-ghost btn-sm" onclick="openReviewModal(${t.id})"><i class="fi fi-rr-eye"></i> Xem file & Review</button>
                    </div>
                </div>
            `;
        } else if (t.status === 'approved') {
            innerContent += `
                <div class="approved-info">
                    <span><i class="fi fi-rr-checkbox text-success"></i> Đã duyệt lúc: ${t.approved_at ? t.approved_at : ''}</span>
                    <button class="btn-link" onclick="openReviewModal(${t.id})"><i class="fi fi-rr-time-past"></i> Lịch sử versions</button>
                </div>
            `;
        } else if (t.status === 'revision') {
            innerContent += `
                <div class="revision-info">
                    <span><i class="fi fi-rr-refresh text-danger"></i> Cần sửa đổi (v${t.version} đang làm)</span>
                    <p class="revision-comment">Nhận xét: "${t.last_review_comment || ''}"</p>
                </div>
            `;
        }

        // Add Delete Button if task is pending
        if (t.status === 'pending') {
            innerContent += `
                <div class="task-card-actions">
                    <button class="btn btn-ghost btn-xs text-red" onclick="deleteTask(${t.id})"><i class="fi fi-rr-trash"></i> Xóa task</button>
                </div>
            `;
        }

        card.innerHTML = innerContent;
        listContainer.appendChild(card);
    });
}

function highlightTaskCard(taskId) {
    const card = document.getElementById(`task-card-${taskId}`);
    if (card) {
        card.scrollIntoView({ behavior: 'smooth', block: 'center' });
        card.classList.add('highlight-glow');
        setTimeout(() => {
            card.classList.remove('highlight-glow');
        }, 1500);
        redrawCanvas(taskId);
    }
}

async function deleteTask(taskId) {
    if (!confirm('Bạn có chắc muốn xóa nhiệm vụ chưa thực hiện này?')) return;
    
    try {
        const res = await fetch(BASE_URL + 'api/tasks.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'delete_task', task_id: taskId })
        });
        const data = await res.json();
        
        if (data.success) {
            showToast('✅ Đã xóa task!', 'success');
            await loadPageDetail();
        } else {
            showToast(data.message || 'Không thể xóa task.', 'error');
        }
    } catch (err) {
        showToast('Lỗi kết nối: ' + err.message, 'error');
    }
}

// ── Modal Detail Review ────────────────────────────────
let activeReviewTaskId = null;
let activeReviewSubmissionId = null;

async function openReviewModal(taskId) {
    activeReviewTaskId = taskId;
    
    // Fetch task history
    try {
        const res = await fetch(`${BASE_URL}api/tasks.php?action=get_task_history&task_id=${taskId}`);
        const data = await res.json();
        
        if (data.success && data.history) {
            const task = loadedTasks.find(t => t.id === taskId);
            if (!task) return;
            
            // Set header
            document.getElementById('modalTaskHeader').textContent = `Review Task: [${task.task_type.toUpperCase()}] - ${task.assistant_name} - v${task.version}`;
            
            // Render Column 1 Canvas Highlight
            renderHighlightedRegionOnModalCanvas(document.getElementById('pageImage').src, task.region_data);
            
            // Render Column 2 File result
            const latestSub = data.history[data.history.length - 1];
            activeReviewSubmissionId = latestSub ? latestSub.submission_id : null;
            
            const filePreviewContainer = document.getElementById('modalFilePreview');
            const fileInfoContainer = document.getElementById('modalFileInfo');
            filePreviewContainer.innerHTML = '';
            fileInfoContainer.innerHTML = '';
            
            if (latestSub && latestSub.file_result) {
                const fileUrl = BASE_URL + latestSub.file_result;
                const ext = latestSub.file_result.split('.').pop().toLowerCase();
                const isImage = ['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(ext);
                
                if (isImage) {
                    filePreviewContainer.innerHTML = `<img src="${fileUrl}" class="img-fluid rounded shadow" style="max-height: 450px; width: auto;" alt="Kết quả" />`;
                } else {
                    let iconClass = 'fi-rr-file-zipper text-warning';
                    if (ext === 'pdf') iconClass = 'fi-rr-document text-red';
                    else if (ext === 'psd') iconClass = 'fi-rr-picture text-blue';
                    
                    filePreviewContainer.innerHTML = `
                        <div class="non-image-file-icon">
                            <i class="fi ${iconClass}" style="font-size: 5rem;"></i>
                            <p class="mt-2 text-muted">File: .${ext.toUpperCase()}</p>
                        </div>
                    `;
                }
                
                fileInfoContainer.innerHTML = `
                    <a href="${fileUrl}" download class="btn btn-primary mt-3 w-100"><i class="fi fi-rr-download"></i> Tải về kết quả</a>
                    <div class="mt-3 p-3 bg-dark rounded border border-light-subtle">
                        <p class="mb-1 text-muted"><strong>Lời nhắn từ trợ lý:</strong></p>
                        <p class="mb-0 text-white italic">"${latestSub.note || 'Không có lời nhắn.'}"</p>
                        <hr class="my-2 border-secondary">
                        <small class="text-muted d-block">Nộp lúc: ${latestSub.submitted_at}</small>
                    </div>
                `;
            } else {
                filePreviewContainer.innerHTML = '<div class="empty-state">Chưa có tệp nộp bài nào.</div>';
            }
            
            // Render Column 3: History Timeline + Action Buttons
            renderHistoryTimeline(data.history);
            
            // Actions
            const actionsContainer = document.getElementById('modalActionsContainer');
            actionsContainer.innerHTML = '';
            
            if (task.status === 'submitted' && latestSub) {
                actionsContainer.innerHTML = `
                    <button class="btn btn-success btn-lg w-100 mb-3" onclick="approveTask(${taskId}, ${latestSub.submission_id})">
                        <i class="fi fi-rr-checkbox"></i> DUYỆT TASK NÀY
                    </button>
                    <div class="revision-request-box">
                        <h5 class="text-orange mb-2"><i class="fi fi-rr-undo"></i> YÊU CẦU SỬA LẠI</h5>
                        <textarea id="revisionComment" class="form-control mb-2" rows="3" placeholder="Nhập lý do cụ thể (tối thiểu 10 ký tự)..."></textarea>
                        <div class="quick-comment-templates mb-3">
                            <button class="btn btn-xs btn-outline-secondary" onclick="appendQuickComment('[Màu chưa đúng]')">Màu chưa đúng</button>
                            <button class="btn btn-xs btn-outline-secondary" onclick="appendQuickComment('[Thiếu chi tiết]')">Thiếu chi tiết</button>
                            <button class="btn btn-xs btn-outline-secondary" onclick="appendQuickComment('[Sai nét vẽ]')">Sai nét vẽ</button>
                            <button class="btn btn-xs btn-outline-secondary" onclick="appendQuickComment('[Sai vùng yêu cầu]')">Sai vùng yêu cầu</button>
                        </div>
                        <button class="btn btn-warning w-100" onclick="requestRevision(${taskId}, ${latestSub.submission_id})">
                            Gửi yêu cầu sửa
                        </button>
                    </div>
                `;
            } else {
                actionsContainer.innerHTML = `<div class="p-3 text-center text-muted border border-secondary rounded bg-dark">Nhiệm vụ ở trạng thái <strong>${task.status.toUpperCase()}</strong>. Không cần duyệt/sửa.</div>`;
            }
            
            // Show modal
            document.getElementById('reviewModal').classList.add('visible');
        } else {
            showToast(data.message || 'Lỗi tải lịch sử task.', 'error');
        }
    } catch (err) {
        showToast('Lỗi kết nối lịch sử: ' + err.message, 'error');
    }
}

function closeModal() {
    document.getElementById('reviewModal').classList.remove('visible');
    activeReviewTaskId = null;
    activeReviewSubmissionId = null;
}

function renderHighlightedRegionOnModalCanvas(imgSrc, region) {
    const canvas = document.getElementById('modalRegionCanvas');
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    const img = new Image();
    img.src = imgSrc;
    img.onload = () => {
        // Limit display size
        const maxDisplayW = 350;
        const scale = maxDisplayW / img.naturalWidth;
        
        canvas.width = maxDisplayW;
        canvas.height = img.naturalHeight * scale;
        
        // Draw base image scaled
        ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
        
        // Draw 50% dark overlay
        ctx.fillStyle = 'rgba(0, 0, 0, 0.6)';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        
        // Convert region % to pixels
        const rx = (region.x / 100) * canvas.width;
        const ry = (region.y / 100) * canvas.height;
        const rw = (region.w / 100) * canvas.width;
        const rh = (region.h / 100) * canvas.height;
        
        // Clear rect for task region
        ctx.clearRect(rx, ry, rw, rh);
        
        // Draw image section cropped back
        ctx.drawImage(
            img,
            (region.x / 100) * img.naturalWidth,
            (region.y / 100) * img.naturalHeight,
            (region.w / 100) * img.naturalWidth,
            (region.h / 100) * img.naturalHeight,
            rx, ry, rw, rh
        );
        
        // Stroke region border
        ctx.strokeStyle = '#3498DB';
        ctx.lineWidth = 3;
        ctx.strokeRect(rx, ry, rw, rh);
    };
}

function renderHistoryTimeline(history) {
    const timeline = document.getElementById('modalTimeline');
    timeline.innerHTML = '';
    
    if (history.length === 0) {
        timeline.innerHTML = '<p class="text-muted">Chưa có lịch sử phiên bản.</p>';
        return;
    }
    
    history.forEach(h => {
        const item = document.createElement('div');
        item.className = 'timeline-item';
        
        let statusBadge = '';
        if (h.review_action === 'approved') {
            statusBadge = '<span class="badge bg-success">Approved</span>';
        } else if (h.review_action === 'revision') {
            statusBadge = '<span class="badge bg-warning text-dark">Revision</span>';
        } else {
            statusBadge = '<span class="badge bg-info">Chờ review</span>';
        }
        
        item.innerHTML = `
            <div class="timeline-header">
                <strong>v${h.version}</strong> - nộp bởi <strong>${h.submitted_by_name}</strong>
                ${statusBadge}
            </div>
            <div class="timeline-body text-white">
                <p class="mb-1">${h.note ? `💬 "${h.note}"` : 'Không kèm ghi chú.'}</p>
                ${h.review_comment ? `<p class="mb-1 text-orange pl-3 border-left border-warning">🔄 Nhận xét: "${h.review_comment}"</p>` : ''}
                <small class="text-muted">${h.submitted_at}</small>
            </div>
        `;
        timeline.appendChild(item);
    });
}

function initQuickReviewTemplates() {
    window.appendQuickComment = (text) => {
        const tx = document.getElementById('revisionComment');
        if (tx) {
            tx.value = (tx.value ? tx.value + ' ' : '') + text;
        }
    };
}

// ── Action Handlers ────────────────────────────────────
async function approveTask(taskId, submissionId) {
    if (!confirm('Phê duyệt task này? Hành động này không thể hoàn tác.')) return;
    
    try {
        const res = await fetch(BASE_URL + 'api/tasks.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'approve_task',
                task_id: taskId,
                submission_id: submissionId
            })
        });
        const data = await res.json();
        
        if (data.success) {
            showToast('✅ Đã duyệt task thành công!', 'success');
            if (data.page_completed) {
                showToast('🎉 Trang đã hoàn thành toàn bộ tasks!', 'success');
            }
            closeModal();
            await loadPageDetail(); // reload
        } else {
            showToast(data.message || 'Lỗi khi duyệt task.', 'error');
        }
    } catch (err) {
        showToast('Lỗi kết nối: ' + err.message, 'error');
    }
}

async function requestRevision(taskId, submissionId) {
    const comment = document.getElementById('revisionComment').value.trim();
    if (comment.length < 10) {
        showToast('Vui lòng cung cấp lý do sửa cụ thể (tối thiểu 10 ký tự).', 'error');
        return;
    }
    
    try {
        const res = await fetch(BASE_URL + 'api/tasks.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'request_revision',
                task_id: taskId,
                submission_id: submissionId,
                comment: comment
            })
        });
        const data = await res.json();
        
        if (data.success) {
            showToast(`🔄 Đã gửi yêu cầu sửa lại (Phiên bản tiếp theo: v${data.next_version})`, 'warning');
            closeModal();
            await loadPageDetail(); // reload
        } else {
            showToast(data.message || 'Lỗi khi gửi yêu cầu sửa.', 'error');
        }
    } catch (err) {
        showToast('Lỗi kết nối: ' + err.message, 'error');
    }
}

// ── Summary Sticky Bar ────────────────────────────────
function updateStickySummary(summary) {
    if (!summary) return;
    
    const summaryText = document.getElementById('summaryText');
    const summaryProgressBar = document.getElementById('summaryProgressBar');
    
    const pageNumSelect = document.getElementById('pageSelect');
    const selectedText = pageNumSelect.options[pageNumSelect.selectedIndex].text;
    
    summaryText.innerHTML = `
        <strong>${selectedText}</strong>: 
        <span class="badge bg-success"><i class="fi fi-rr-checkbox"></i> ${summary.approved}</span>
        <span class="badge bg-warning text-dark"><i class="fi fi-rr-document-signed"></i> ${summary.submitted}</span>
        <span class="badge bg-danger"><i class="fi fi-rr-refresh"></i> ${summary.revision}</span>
        <span class="badge bg-secondary"><i class="fi fi-rr-clock"></i> ${summary.pending + summary.in_progress}</span>
        / ${summary.total} tasks
    `;
    
    const progress = summary.total > 0 ? (summary.approved / summary.total) * 100 : 0;
    summaryProgressBar.style.width = `${progress}%`;
}

// ── AI Segment Implementation ──────────────────────────
async function runAiSegment() {
    if (!selectedPageId) {
        showToast('Vui lòng chọn trang trước.', 'error');
        return;
    }
    const img = document.getElementById('pageImage');
    if (!img || img.style.display === 'none') {
        showToast('Trang này chưa có ảnh để phân tích.', 'error');
        return;
    }

    const btnAi = document.getElementById('aiSegFloatingBtn');
    btnAi.disabled = true;
    const oldText = btnAi.innerHTML;
    btnAi.innerHTML = '<i class="fi fi-rr-spinner spin"></i> Đang phân tích...';
    clearAiSegment();

    try {
        const res = await fetch(BASE_URL + 'api/ai_segment.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ page_id: selectedPageId })
        });
        const data = await res.json();

        btnAi.disabled = false;
        btnAi.innerHTML = oldText;

        if (data.success && data.regions && data.regions.length) {
            renderAiSegment(data.regions);
            showToast(`🤖 AI phát hiện ${data.regions.length} vùng! Click lên vùng để giao việc.`, 'success');
        } else {
            showToast(data.message || 'AI không phân tích được vùng nào. Hãy vẽ tay vùng cần giao.', 'warning');
        }
    } catch (err) {
        btnAi.disabled = false;
        btnAi.innerHTML = oldText;
        showToast('Lỗi kết nối: ' + err.message, 'error');
    }
}

function renderAiSegment(regions) {
    aiSegRegions = regions;
    aiSegHidden = new Set();

    const img = document.getElementById('pageImage');
    const canvas = document.getElementById('aiSegCanvas');
    if (!img || !canvas) return;
    
    canvas.style.display = 'block';
    canvas.style.pointerEvents = 'auto';
    
    canvas.style.position = 'absolute';
    canvas.style.left = img.offsetLeft + 'px';
    canvas.style.top = img.offsetTop + 'px';
    canvas.style.width = img.clientWidth + 'px';
    canvas.style.height = img.clientHeight + 'px';

    canvas.width = img.clientWidth;
    canvas.height = img.clientHeight;

    drawAiCanvas();

    // Canvas click
    canvas.onclick = (e) => {
        const rect = canvas.getBoundingClientRect();
        const px = ((e.clientX - rect.left) / rect.width) * 100;
        const py = ((e.clientY - rect.top) / rect.height) * 100;
        
        let hit = null;
        let hitArea = Infinity;
        
        aiSegRegions.forEach(r => {
            if (aiSegHidden.has(r.id)) return;
            if (px >= r.x && px <= r.x + r.width && py >= r.y && py <= r.y + r.height) {
                const area = r.width * r.height;
                if (area < hitArea) {
                    hit = r;
                    hitArea = area;
                }
            }
        });
        
        if (hit !== null) {
            // Apply coordinates to Form
            showTaskForm(hit.x, hit.y, hit.width, hit.height);
            drawRegionPreview(hit.x, hit.y, hit.width, hit.height, '#E63946');
            
            // Mark region as used / hidden visually
            aiSegHidden.add(hit.id);
            drawAiCanvas();
            showToast('🤖 Đã nạp vùng phân đoạn của AI vào form giao việc!', 'info');
        }
    };
}

function drawAiCanvas() {
    const aiCanvas = document.getElementById('aiSegCanvas');
    if (!aiCanvas) return;
    const actx = aiCanvas.getContext('2d');
    actx.clearRect(0, 0, aiCanvas.width, aiCanvas.height);

    aiSegRegions.forEach(r => {
        if (aiSegHidden.has(r.id)) return;
        
        const px = (r.x / 100) * aiCanvas.width;
        const py = (r.y / 100) * aiCanvas.height;
        const pw = (r.width / 100) * aiCanvas.width;
        const ph = (r.height / 100) * aiCanvas.height;

        actx.strokeStyle = '#a862ea';
        actx.lineWidth = 1.5;
        actx.setLineDash([4, 4]);
        actx.strokeRect(px, py, pw, ph);

        actx.fillStyle = 'rgba(168, 98, 234, 0.12)';
        actx.fillRect(px, py, pw, ph);
    });
}

function clearAiSegment() {
    aiSegRegions = [];
    aiSegHidden = new Set();
    const aiCanvas = document.getElementById('aiSegCanvas');
    if (aiCanvas) {
        aiCanvas.style.display = 'none';
        aiCanvas.style.pointerEvents = 'none';
        const actx = aiCanvas.getContext('2d');
        actx.clearRect(0, 0, aiCanvas.width, aiCanvas.height);
    }
}
