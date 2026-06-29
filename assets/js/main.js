/**
 * MangaFlow — main.js  (v2)
 * Global JS for the dashboard shell.
 *
 * Exported to window.MangaFlow:
 *   .toast(message, type, duration)   — toast notification
 *   .confirm(message, opts)           — promise-based confirm modal
 *   .api(endpoint, body)              — POST helper → JSON
 *   .upload(input, opts)              — file upload with preview
 *   .validate(form, rules)            — client-side validation
 *   .showLoader() / .hideLoader()     — full-page loader
 */

(function () {
    'use strict';

    /* ─────────────────────────────────────────
       DOM-ready helper
    ───────────────────────────────────────── */
    function ready(fn) {
        if (document.readyState !== 'loading') fn();
        else document.addEventListener('DOMContentLoaded', fn);
    }

    /* ─────────────────────────────────────────
       Escape HTML (XSS-safe)
    ───────────────────────────────────────── */
    function esc(str) {
        return String(str).replace(/[&<>"']/g, t => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        }[t]));
    }

    /* ═══════════════════════════════════════════════════════
       1. TOAST NOTIFICATION SYSTEM
    ═══════════════════════════════════════════════════════ */
    const TOAST_ICONS = {
        success: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                    <polyline points="20 6 9 17 4 12"/>
                  </svg>`,
        error:   `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                    <circle cx="12" cy="12" r="10"/>
                    <line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/>
                  </svg>`,
        warning: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                    <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                    <line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
                  </svg>`,
        info:    `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                    <circle cx="12" cy="12" r="10"/>
                    <line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
                  </svg>`,
    };

    const TOAST_TITLES = { success: 'Thành công', error: 'Lỗi', warning: 'Cảnh báo', info: 'Thông tin' };

    function getToastContainer() {
        let el = document.getElementById('toastContainer');
        if (!el) {
            el = document.createElement('div');
            el.id = 'toastContainer';
            document.body.appendChild(el);
        }
        return el;
    }

    /**
     * showToast — global alias + MangaFlow.toast
     * @param {string} message
     * @param {'success'|'error'|'warning'|'info'} type
     * @param {number} duration  ms (default 4000)
     */
    function showToast(message, type = 'info', duration = 4000) {
        const validTypes = ['success', 'error', 'warning', 'info'];
        if (!validTypes.includes(type)) type = 'info';

        const container = getToastContainer();
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.setAttribute('role', 'alert');
        toast.setAttribute('aria-live', 'polite');

        toast.innerHTML = `
            <span class="toast-icon">${TOAST_ICONS[type]}</span>
            <div class="toast-body">
                <div class="toast-title">${TOAST_TITLES[type]}</div>
                <div class="toast-message">${esc(message)}</div>
            </div>
            <button class="toast-close" aria-label="Đóng">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
            <div class="toast-progress" style="width:100%"></div>
        `;

        container.appendChild(toast);

        // Progress bar drain
        const progressBar = toast.querySelector('.toast-progress');
        progressBar.style.transition = `width ${duration}ms linear`;
        requestAnimationFrame(() => {
            requestAnimationFrame(() => { progressBar.style.width = '0%'; });
        });

        // Close button
        toast.querySelector('.toast-close').addEventListener('click', () => dismissToast(toast));

        // Auto dismiss
        const timer = setTimeout(() => dismissToast(toast), duration);
        toast._timer = timer;

        // Pause on hover
        toast.addEventListener('mouseenter', () => {
            clearTimeout(toast._timer);
            progressBar.style.transitionDuration = '0ms';
        });
        toast.addEventListener('mouseleave', () => {
            const remaining = parseFloat(progressBar.style.width) / 100 * duration;
            progressBar.style.transitionDuration = `${remaining}ms`;
            progressBar.style.width = '0%';
            toast._timer = setTimeout(() => dismissToast(toast), remaining);
        });

        return toast;
    }

    function dismissToast(toast) {
        if (!toast || !toast.parentNode) return;
        clearTimeout(toast._timer);
        toast.classList.add('toast-hiding');
        toast.addEventListener('animationend', () => toast.remove(), { once: true });
        setTimeout(() => toast.remove(), 400); // fallback
    }

    // Global shortcut
    window.showToast = showToast;


    /* ═══════════════════════════════════════════════════════
       2. CONFIRM MODAL (promise-based)
    ═══════════════════════════════════════════════════════ */

    function ensureConfirmModal() {
        let modal = document.getElementById('confirmModal');
        if (!modal) {
            modal = document.createElement('div');
            modal.id = 'confirmModal';
            modal.innerHTML = `
                <div class="confirm-box" role="dialog" aria-modal="true" aria-labelledby="confirmTitle">
                    <div class="confirm-icon" id="confirmIconWrap">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                            <circle cx="12" cy="12" r="10"/>
                            <line x1="12" y1="8" x2="12" y2="12"/>
                            <line x1="12" y1="16" x2="12.01" y2="16"/>
                        </svg>
                    </div>
                    <div class="confirm-title" id="confirmTitle">Xác nhận thao tác</div>
                    <div class="confirm-message" id="confirmMessage">Bạn có chắc chắn muốn thực hiện?</div>
                    <div class="confirm-actions">
                        <button class="btn btn-secondary" id="confirmCancel">Hủy bỏ</button>
                        <button class="btn btn-primary"   id="confirmOk">Xác nhận</button>
                    </div>
                </div>`;
            document.body.appendChild(modal);

            modal.addEventListener('click', (e) => {
                if (e.target === modal) modal._reject && modal._reject();
            });
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape' && modal.classList.contains('open')) {
                    modal._reject && modal._reject();
                }
            });
        }
        return modal;
    }

    /**
     * confirmAction — global alias + MangaFlow.confirm
     * Returns a Promise that resolves on OK, rejects on Cancel.
     *
     * @param {string} message
     * @param {object} opts — { title, okText, cancelText, type:'danger'|'warning'|'info' }
     */
    function confirmAction(message, opts = {}) {
        const {
            title      = 'Xác nhận thao tác',
            okText     = 'Xác nhận',
            cancelText = 'Hủy bỏ',
            type       = 'danger',
        } = opts;

        return new Promise((resolve, reject) => {
            const modal   = ensureConfirmModal();
            const titleEl = document.getElementById('confirmTitle');
            const msgEl   = document.getElementById('confirmMessage');
            const okBtn   = document.getElementById('confirmOk');
            const cancelBtn = document.getElementById('confirmCancel');
            const iconWrap  = document.getElementById('confirmIconWrap');

            if (titleEl)  titleEl.textContent  = title;
            if (msgEl)    msgEl.textContent     = message;
            if (okBtn)    okBtn.textContent     = okText;
            if (cancelBtn) cancelBtn.textContent = cancelText;

            // Style confirm button by type
            if (okBtn) {
                okBtn.className = 'btn ' + (type === 'danger' ? 'btn-primary' : type === 'warning' ? 'btn-secondary' : 'btn-primary');
                if (type === 'danger') okBtn.style.cssText = 'background:#E63946; border-color:#E63946;';
                else okBtn.style.cssText = '';
            }

            // Icon color
            if (iconWrap) {
                const colors = { danger: 'rgba(230,57,70,0.12)', warning: 'rgba(245,158,11,0.12)', info: 'rgba(59,130,246,0.12)' };
                const borderColors = { danger: 'rgba(230,57,70,0.25)', warning: 'rgba(245,158,11,0.25)', info: 'rgba(59,130,246,0.25)' };
                const iconColors   = { danger: '#f87171', warning: '#fbbf24', info: '#60a5fa' };
                iconWrap.style.background   = colors[type]       || colors.danger;
                iconWrap.style.borderColor  = borderColors[type] || borderColors.danger;
                iconWrap.style.color        = iconColors[type]   || iconColors.danger;
            }

            modal.classList.add('open');
            setTimeout(() => okBtn && okBtn.focus(), 50);

            function cleanup() {
                modal.classList.remove('open');
                okBtn    && okBtn.removeEventListener('click', onOk);
                cancelBtn && cancelBtn.removeEventListener('click', onCancel);
                modal._reject = null;
            }

            function onOk()     { cleanup(); resolve(true); }
            function onCancel() { cleanup(); reject(false); }

            okBtn     && okBtn.addEventListener('click', onOk, { once: true });
            cancelBtn && cancelBtn.addEventListener('click', onCancel, { once: true });
            modal._reject = onCancel;
        });
    }

    // Global alias (for pages that call it directly)
    window.confirmAction = confirmAction;


    /* ═══════════════════════════════════════════════════════
       3. NOTIFICATION POLLING (every 30s)
    ═══════════════════════════════════════════════════════ */
    function initNotifications() {
        const bell     = document.getElementById('notifBell');
        const dropdown = document.getElementById('notifDropdown');
        const markAll  = document.getElementById('markAllRead');
        const notifList = dropdown ? dropdown.querySelector('.notif-list') : null;

        if (!bell || !dropdown) return;

        // Toggle dropdown
        bell.addEventListener('click', (e) => {
            e.stopPropagation();
            dropdown.classList.toggle('open');
        });

        document.addEventListener('click', (e) => {
            if (!dropdown.contains(e.target) && e.target !== bell) {
                dropdown.classList.remove('open');
            }
        });

        // Mark all read
        markAll && markAll.addEventListener('click', () => {
            fetch(BASE_URL + 'api/notifications.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=mark_all_read'
            })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    dropdown.querySelectorAll('.notif-item.unread').forEach(el => {
                        el.classList.remove('unread');
                        const dot = el.querySelector('.notif-dot');
                        if (dot) dot.outerHTML = '<div style="width:8px;flex-shrink:0"></div>';
                    });
                    updateBadge(0);
                }
            })
            .catch(() => {});
        });

        // Click on individual notification
        notifList && notifList.addEventListener('click', (e) => {
            const item = e.target.closest('.notif-item');
            if (!item) return;

            const id   = item.dataset.id;
            const link = item.dataset.link;

            if (item.classList.contains('unread') && id) {
                fetch(BASE_URL + 'api/notifications.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `action=mark_read&id=${id}`
                }).catch(() => {});

                item.classList.remove('unread');
                const dot = item.querySelector('.notif-dot');
                if (dot) dot.outerHTML = '<div style="width:8px;flex-shrink:0"></div>';

                const countEl = document.getElementById('notifCount');
                if (countEl) {
                    const n = Math.max(0, parseInt(countEl.textContent) - 1);
                    updateBadge(n);
                }
            }

            if (link && link !== BASE_URL && link !== (BASE_URL + '/')) {
                window.location.href = link;
            }
        });

        // Track highest seen notification ID
        let maxNotifId = 0;
        document.querySelectorAll('.notif-item[data-id]').forEach(el => {
            const id = parseInt(el.dataset.id);
            if (id > maxNotifId) maxNotifId = id;
        });

        function updateBadge(count) {
            const el = document.getElementById('notifCount');
            if (!el) return;
            el.textContent = count;
            el.style.display = count > 0 ? 'flex' : 'none';
        }

        function renderNotifList(notifications) {
            if (!notifList) return;

            if (!notifications || notifications.length === 0) {
                notifList.innerHTML = `
                    <div class="notif-empty">
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin:0 auto 8px;opacity:0.3">
                            <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
                            <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
                        </svg>
                        <p>Không có thông báo mới</p>
                    </div>`;
                return;
            }

            let html = '';
            notifications.forEach(n => {
                const isUnread = !n.is_read || parseInt(n.is_read) === 0;
                const link = n.link ? BASE_URL + n.link.replace(/^\//, '') : '';
                let time = '';
                try {
                    const d = new Date(n.created_at.replace(/-/g, '/'));
                    time = `${String(d.getDate()).padStart(2,'0')}/${String(d.getMonth()+1).padStart(2,'0')} ${String(d.getHours()).padStart(2,'0')}:${String(d.getMinutes()).padStart(2,'0')}`;
                } catch(e) { time = n.created_at || ''; }

                html += `
                    <div class="notif-item ${isUnread ? 'unread' : ''}" data-id="${n.id}" data-link="${esc(link)}">
                        ${isUnread ? '<div class="notif-dot"></div>' : '<div style="width:8px;flex-shrink:0"></div>'}
                        <div>
                            <div class="notif-text">${esc(n.message)}</div>
                            <div class="notif-time">${time}</div>
                        </div>
                    </div>`;
            });
            notifList.innerHTML = html;
        }

        // Polling
        function poll() {
            fetch(BASE_URL + 'api/notifications.php?limit=20')
            .then(r => r.json())
            .then(res => {
                if (!res.success) return;

                const data  = res.data || res; // handle both response shapes
                const list  = data.notifications || res.notifications || [];
                const count = data.unread_count  ?? res.unread_count ?? 0;

                updateBadge(count);

                // Detect new notifications and toast them
                const toastMap = {
                    rank_drop:            'error',
                    task_revision:        'warning',
                    task_approved:        'success',
                    task_assigned:        'info',
                    task_submitted:       'info',
                    manuscript_decision:  'success',
                    manuscript_review:    'warning',
                    submission_approved:  'success',
                    submission_rejected:  'error',
                    series_cancelled:     'error',
                    schedule_changed:     'info',
                };

                // Show toasts for new items (highest id first)
                [...list]
                    .filter(n => parseInt(n.id) > maxNotifId)
                    .sort((a, b) => parseInt(a.id) - parseInt(b.id))
                    .forEach(n => {
                        maxNotifId = Math.max(maxNotifId, parseInt(n.id));
                        if (toastMap[n.type]) {
                            showToast(n.message, toastMap[n.type]);
                        }
                    });

                renderNotifList(list);
            })
            .catch(() => {}); // silently ignore network errors
        }

        setInterval(poll, 30_000);
    }


    /* ═══════════════════════════════════════════════════════
       4. FILE UPLOAD PREVIEW
    ═══════════════════════════════════════════════════════ */

    /**
     * initFileUploadPreviews — auto-attach to [data-preview-target] inputs
     * Or call MangaFlow.upload(inputEl, { previewEl, maxMB, accept }) manually.
     */
    function initFileUploadPreviews() {
        document.querySelectorAll('input[type="file"][data-preview]').forEach(input => {
            const previewContainer = document.getElementById(input.dataset.preview);
            if (previewContainer) {
                attachUploadPreview(input, previewContainer);
            }
        });

        // Drag-and-drop for .upload-zone elements
        document.querySelectorAll('.upload-zone').forEach(zone => {
            const input = zone.querySelector('input[type="file"]');
            if (!input) return;

            zone.addEventListener('dragover', (e) => { e.preventDefault(); zone.classList.add('dragover'); });
            zone.addEventListener('dragleave', ()  => zone.classList.remove('dragover'));
            zone.addEventListener('drop', (e) => {
                e.preventDefault();
                zone.classList.remove('dragover');
                if (e.dataTransfer.files.length) {
                    // Transfer files to input
                    const dt = new DataTransfer();
                    [...e.dataTransfer.files].forEach(f => dt.items.add(f));
                    input.files = dt.files;
                    input.dispatchEvent(new Event('change', { bubbles: true }));
                }
            });
        });
    }

    function attachUploadPreview(input, previewContainer, opts = {}) {
        const maxMB  = opts.maxMB || 20;
        const maxBytes = maxMB * 1024 * 1024;

        input.addEventListener('change', () => {
            previewContainer.innerHTML = '';
            const files = [...(input.files || [])];
            if (!files.length) return;

            files.forEach(file => {
                if (file.size > maxBytes) {
                    showToast(`File "${file.name}" vượt quá ${maxMB}MB.`, 'error');
                    return;
                }

                const item = document.createElement('div');
                item.className = 'upload-preview-item';

                if (file.type.startsWith('image/')) {
                    const reader = new FileReader();
                    reader.onload = (e) => {
                        item.innerHTML = `
                            <img src="${e.target.result}" alt="${esc(file.name)}" loading="lazy">
                            <div class="upload-preview-name">${esc(file.name)}</div>
                            <button type="button" class="remove-preview" title="Xóa">✕</button>`;
                        item.querySelector('.remove-preview').addEventListener('click', () => {
                            item.remove();
                            clearFileInput(input);
                        });
                    };
                    reader.readAsDataURL(file);
                } else {
                    // Non-image (PDF, ZIP…)
                    const ext = file.name.split('.').pop().toUpperCase();
                    item.innerHTML = `
                        <div style="display:flex;flex-direction:column;align-items:center;justify-content:center;height:100%;gap:6px;">
                            <span style="font-size:1.4rem;">📄</span>
                            <span style="font-size:0.7rem;font-weight:700;color:#a5b4fc;">${ext}</span>
                        </div>
                        <div class="upload-preview-name">${esc(file.name)}</div>
                        <button type="button" class="remove-preview" title="Xóa">✕</button>`;
                    item.querySelector('.remove-preview').addEventListener('click', () => {
                        item.remove();
                        clearFileInput(input);
                    });
                }

                // File size indicator
                const sizeMB = (file.size / 1048576).toFixed(1);
                item.setAttribute('title', `${file.name} (${sizeMB} MB)`);
                previewContainer.appendChild(item);
            });
        });
    }

    function clearFileInput(input) {
        try {
            input.value = '';
            if (input.value) { // IE fallback
                const form = document.createElement('form');
                const parent = input.parentNode;
                const next   = input.nextSibling;
                form.appendChild(input);
                form.reset();
                parent.insertBefore(input, next);
            }
        } catch (e) {}
    }


    /* ═══════════════════════════════════════════════════════
       5. FORM VALIDATION (client-side)
    ═══════════════════════════════════════════════════════ */

    /**
     * validateForm — validate form fields based on HTML5 attributes + data-* hints
     *
     * Supported rules (via element attributes):
     *   required
     *   minlength / maxlength
     *   min / max  (for number inputs)
     *   pattern
     *   data-match="#otherId"  (equality check)
     *   data-msg-required / data-msg-minlength / data-msg-pattern
     *
     * @param {HTMLFormElement|string} formOrSelector
     * @returns {{ valid: boolean, errors: {[name]: string} }}
     */
    function validateForm(formOrSelector) {
        const form = typeof formOrSelector === 'string'
            ? document.querySelector(formOrSelector)
            : formOrSelector;

        if (!form) return { valid: false, errors: { _form: 'Form not found' } };

        const errors = {};
        let firstError = null;

        // Clear previous errors
        form.querySelectorAll('.form-control.is-invalid').forEach(el => {
            el.classList.remove('is-invalid');
            el.classList.remove('is-valid');
        });
        form.querySelectorAll('.form-error').forEach(el => el.remove());

        const inputs = [...form.querySelectorAll('input, textarea, select')];

        inputs.forEach(input => {
            if (input.disabled || input.type === 'submit' || input.type === 'button') return;

            const name  = input.name || input.id || 'field';
            const val   = input.value.trim();
            const label = form.querySelector(`label[for="${input.id}"]`)?.textContent?.trim()
                       || input.placeholder
                       || name;
            let errorMsg = '';

            // required
            if (input.required && val === '') {
                errorMsg = input.dataset.msgRequired || `${label} là bắt buộc.`;
            }
            // minlength
            else if (input.minLength > 0 && val.length < input.minLength) {
                errorMsg = input.dataset.msgMinlength || `${label} cần ít nhất ${input.minLength} ký tự.`;
            }
            // maxlength
            else if (input.maxLength > 0 && val.length > input.maxLength) {
                errorMsg = `${label} không được vượt quá ${input.maxLength} ký tự.`;
            }
            // min/max for numbers
            else if (input.type === 'number') {
                const num = parseFloat(val);
                if (input.min !== '' && !isNaN(parseFloat(input.min)) && num < parseFloat(input.min)) {
                    errorMsg = `${label} phải ≥ ${input.min}.`;
                } else if (input.max !== '' && !isNaN(parseFloat(input.max)) && num > parseFloat(input.max)) {
                    errorMsg = `${label} phải ≤ ${input.max}.`;
                }
            }
            // pattern
            else if (input.pattern && val !== '') {
                const regex = new RegExp('^(?:' + input.pattern + ')$');
                if (!regex.test(val)) {
                    errorMsg = input.dataset.msgPattern || `${label} không đúng định dạng.`;
                }
            }
            // data-match (password confirm)
            else if (input.dataset.match) {
                const matchEl = document.getElementById(input.dataset.match.replace('#', ''));
                if (matchEl && val !== matchEl.value.trim()) {
                    errorMsg = `${label} không khớp.`;
                }
            }
            // email
            else if (input.type === 'email' && val !== '') {
                if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(val)) {
                    errorMsg = `${label} không phải địa chỉ email hợp lệ.`;
                }
            }

            if (errorMsg) {
                errors[name] = errorMsg;
                input.classList.add('is-invalid');
                if (!firstError) firstError = input;

                // Inject error message
                const errEl = document.createElement('div');
                errEl.className = 'form-error';
                errEl.innerHTML = `<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg> ${errorMsg}`;
                input.parentNode.appendChild(errEl);
            } else if (val !== '') {
                input.classList.add('is-valid');
            }
        });

        if (firstError) {
            firstError.focus();
            firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        return { valid: Object.keys(errors).length === 0, errors };
    }

    // Live validation: remove error on re-focus/input
    function initLiveValidation() {
        document.addEventListener('input', (e) => {
            const input = e.target;
            if (!input.matches('input, textarea, select')) return;
            if (input.classList.contains('is-invalid') && input.value.trim() !== '') {
                input.classList.remove('is-invalid');
                const errEl = input.parentNode.querySelector('.form-error');
                if (errEl) errEl.remove();
            }
        });

        document.addEventListener('submit', (e) => {
            const form = e.target;
            if (!form.matches('form[data-validate]')) return;
            const { valid } = validateForm(form);
            if (!valid) {
                e.preventDefault();
                showToast('Vui lòng kiểm tra lại các trường bắt buộc.', 'warning');
            }
        });
    }


    /* ═══════════════════════════════════════════════════════
       6. AUTO-RESIZE TEXTAREA
    ═══════════════════════════════════════════════════════ */
    function initAutoResizeTextareas() {
        function resize(el) {
            el.style.height = 'auto';
            el.style.height = (el.scrollHeight) + 'px';
        }

        document.querySelectorAll('textarea.auto-resize').forEach(ta => {
            ta.addEventListener('input', () => resize(ta));
            resize(ta); // initial
        });

        // MutationObserver to catch dynamically added textareas
        const observer = new MutationObserver((mutations) => {
            mutations.forEach(m => m.addedNodes.forEach(node => {
                if (node.nodeType !== 1) return;
                const tas = node.matches('textarea.auto-resize')
                    ? [node]
                    : [...node.querySelectorAll('textarea.auto-resize')];
                tas.forEach(ta => {
                    ta.addEventListener('input', () => resize(ta));
                    resize(ta);
                });
            }));
        });
        observer.observe(document.body, { childList: true, subtree: true });
    }


    /* ═══════════════════════════════════════════════════════
       7. CHARACTER COUNTER for textareas / inputs with maxlength
    ═══════════════════════════════════════════════════════ */
    function initCharCounters() {
        document.querySelectorAll('[data-char-counter]').forEach(el => {
            const max = parseInt(el.maxLength || el.dataset.maxLength || 0);
            if (!max) return;

            const counter = document.createElement('div');
            counter.className = 'char-counter';
            counter.textContent = `0 / ${max}`;
            el.parentNode.appendChild(counter);

            el.addEventListener('input', () => {
                const len = el.value.length;
                counter.textContent = `${len} / ${max}`;
                counter.classList.toggle('warn',  len >= max * 0.8 && len < max);
                counter.classList.toggle('limit', len >= max);
            });
        });
    }


    /* ═══════════════════════════════════════════════════════
       8. MOBILE SIDEBAR
    ═══════════════════════════════════════════════════════ */
    function initSidebar() {
        const hamburger = document.getElementById('hamburgerBtn');
        const sidebar   = document.getElementById('appSidebar');
        const overlay   = document.getElementById('sidebarOverlay');

        if (!hamburger || !sidebar) return;

        function open() {
            sidebar.classList.add('open');
            overlay && overlay.classList.add('active');
            document.body.style.overflow = 'hidden';
            hamburger.setAttribute('aria-expanded', 'true');
        }

        function close() {
            sidebar.classList.remove('open');
            overlay && overlay.classList.remove('active');
            document.body.style.overflow = '';
            hamburger.setAttribute('aria-expanded', 'false');
        }

        hamburger.addEventListener('click', () =>
            sidebar.classList.contains('open') ? close() : open()
        );

        overlay   && overlay.addEventListener('click', close);
        document.addEventListener('keydown', (e) => { if (e.key === 'Escape') close(); });
        window.addEventListener('resize', () => { if (window.innerWidth > 900) close(); });
    }


    /* ═══════════════════════════════════════════════════════
       9. PAGE LOADER
    ═══════════════════════════════════════════════════════ */
    function ensureLoader() {
        let loader = document.getElementById('pageLoader');
        if (!loader) {
            loader = document.createElement('div');
            loader.id = 'pageLoader';
            loader.className = 'page-loader hidden';
            loader.innerHTML = `
                <div class="loading-spinner lg"></div>
                <span>Đang xử lý…</span>`;
            document.body.appendChild(loader);
        }
        return loader;
    }

    function showLoader(msg = 'Đang xử lý…') {
        const loader = ensureLoader();
        loader.querySelector('span').textContent = msg;
        loader.classList.remove('hidden');
    }

    function hideLoader() {
        const loader = document.getElementById('pageLoader');
        loader && loader.classList.add('hidden');
    }


    /* ═══════════════════════════════════════════════════════
       10. ACTIVE NAV + CONFIRM DATA-ATTR
    ═══════════════════════════════════════════════════════ */
    function initActiveNav() {
        const current = window.location.pathname.replace(/\\/g, '/');
        document.querySelectorAll('.nav-link[data-path]').forEach(link => {
            if (current.endsWith(link.dataset.path.replace(/\\/g, '/'))) {
                link.classList.add('active');
            }
        });
    }

    function initDataConfirm() {
        document.querySelectorAll('[data-confirm]').forEach(el => {
            el.addEventListener('click', async function (e) {
                e.preventDefault();
                e.stopImmediatePropagation();
                const msg = this.dataset.confirm || 'Bạn có chắc chắn?';
                try {
                    await confirmAction(msg, { type: 'danger' });
                    // Proceed: follow href or submit form
                    if (this.tagName === 'A') {
                        window.location.href = this.href;
                    } else if (this.form) {
                        this.form.submit();
                    } else {
                        this.click(); // fire original click (won't re-trigger due to guard)
                    }
                } catch { /* cancelled */ }
            });
        });
    }

    function initAlerts() {
        document.querySelectorAll('.alert[data-auto-dismiss]').forEach(el => {
            const ms = parseInt(el.dataset.autoDismiss) || 4000;
            setTimeout(() => {
                el.style.transition = 'opacity 0.4s';
                el.style.opacity    = '0';
                setTimeout(() => el.remove(), 420);
            }, ms);
        });
        document.querySelectorAll('.alert-close').forEach(btn => {
            btn.addEventListener('click', function () {
                this.closest('.alert')?.remove();
            });
        });
    }


    /* ═══════════════════════════════════════════════════════
       11. PUBLIC API  (window.MangaFlow)
    ═══════════════════════════════════════════════════════ */
    window.MangaFlow = {
        /**
         * POST to api/<endpoint> and parse JSON.
         * @param {string} endpoint — filename inside api/, e.g. 'notifications.php'
         * @param {object|FormData} body
         * @param {'POST'|'PUT'|'DELETE'} method
         */
        api(endpoint, body = {}, method = 'POST') {
            let headers = {};
            let bodyData;

            if (body instanceof FormData) {
                bodyData = body;
            } else if (typeof body === 'object') {
                headers['Content-Type'] = 'application/x-www-form-urlencoded';
                bodyData = new URLSearchParams(body).toString();
            } else {
                bodyData = body;
            }

            return fetch(BASE_URL + 'api/' + endpoint, {
                method,
                headers,
                body: method !== 'GET' ? bodyData : undefined,
            }).then(r => {
                if (!r.ok) throw new Error(`HTTP ${r.status}: ${r.statusText}`);
                return r.json();
            });
        },

        /** POST JSON body to endpoint */
        apiJson(endpoint, body = {}, method = 'POST') {
            return fetch(BASE_URL + 'api/' + endpoint, {
                method,
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body),
            }).then(r => r.json());
        },

        toast:   showToast,
        confirm: confirmAction,

        /** Validate a form. Returns { valid, errors }. */
        validate: validateForm,

        /**
         * Attach upload preview to an input element.
         * @param {HTMLInputElement} input
         * @param {HTMLElement|string} container — element or selector
         * @param {{ maxMB?: number }} opts
         */
        upload(input, container, opts = {}) {
            const el = typeof container === 'string'
                ? document.querySelector(container)
                : container;
            if (input && el) attachUploadPreview(input, el, opts);
        },

        showLoader,
        hideLoader,
        esc,
        downloadZip: (imgs, name) => downloadZip(imgs, name),
        openWebtoonReader: (imgs, title, name) => openWebtoonReader(imgs, title, name),
    };

    /* ═══════════════════════════════════════════════════════
       BULK DOWNLOAD (JSZIP AT FRONTEND) & WEBTOON READER
       ═══════════════════════════════════════════════════════ */
    function showZipProgressModal(total) {
        let el = document.getElementById('zipProgressOverlay');
        if (!el) {
            el = document.createElement('div');
            el.id = 'zipProgressOverlay';
            el.className = 'zip-progress-overlay';
            el.innerHTML = `
                <div class="zip-progress-card">
                    <div style="font-size:2.2rem; margin-bottom:10px;">📦</div>
                    <h4 id="zipProgressTitle" style="margin:0; font-size:1.1rem; color:#fff; font-weight:700;">Đang chuẩn bị nén ZIP...</h4>
                    <div class="zip-progress-bar-wrap">
                        <div id="zipProgressBarFill" class="zip-progress-bar-fill"></div>
                    </div>
                    <div id="zipProgressSub" style="font-size:0.85rem; color:var(--text-muted, #94a3b8);">Vui lòng chờ trong giây lát...</div>
                </div>
            `;
            document.body.appendChild(el);
        }
        document.getElementById('zipProgressBarFill').style.width = '0%';
        document.getElementById('zipProgressTitle').innerText = 'Đang chuẩn bị nén ZIP...';
        document.getElementById('zipProgressSub').innerText = `0 / ${total} trang (0%)`;
        el.classList.add('open');
    }

    function updateZipProgressModal(current, total, msg) {
        const fill = document.getElementById('zipProgressBarFill');
        const sub = document.getElementById('zipProgressSub');
        if (fill && sub) {
            const percent = Math.round((current / total) * 100);
            fill.style.width = percent + '%';
            sub.innerText = msg || `${current} / ${total} trang (${percent}%)`;
        }
    }

    function hideZipProgressModal() {
        const el = document.getElementById('zipProgressOverlay');
        if (el) el.classList.remove('open');
    }

    function parseImagesInput(images) {
        if (!images) return [];
        let items = [];
        if (typeof images === 'string') {
            let str = images.replace(/&quot;/g, '"').replace(/&#039;/g, "'").trim();
            if (str.startsWith('[') && str.endsWith(']')) {
                try {
                    let parsed = JSON.parse(str);
                    if (Array.isArray(parsed)) items = parsed;
                    else items = [str];
                } catch (e) {
                    items = [str];
                }
            } else {
                items = [str];
            }
        } else if (Array.isArray(images)) {
            items = images;
        } else {
            items = [String(images)];
        }

        let result = [];
        for (let item of items) {
            if (!item) continue;
            if (typeof item === 'string') {
                let str = item.replace(/&quot;/g, '"').replace(/&#039;/g, "'").trim();
                if (str.startsWith('[') && str.endsWith(']')) {
                    try {
                        let parsed = JSON.parse(str);
                        if (Array.isArray(parsed)) {
                            result.push(...parsed);
                            continue;
                        }
                    } catch (e) {}
                }
                result.push(str);
            } else {
                result.push(String(item));
            }
        }
        return result;
    }

    function resolveAssetUrl(imgPath) {
        if (!imgPath || typeof imgPath !== 'string') return '';
        // Normalize: decode HTML entities, trim whitespace, convert Windows backslashes
        imgPath = imgPath.replace(/&quot;/g, '').replace(/"/g, '').trim();
        imgPath = imgPath.replace(/\\/g, '/');   // convert Windows \ to /

        if (imgPath.startsWith('http://') || imgPath.startsWith('https://')) {
            return imgPath;
        }
        const base = (typeof BASE_URL !== 'undefined') ? BASE_URL : '/';
        const cleanBase = base.replace(/\/+$/, '');
        let cleanPath = imgPath.replace(/^\/+/, '');

        if (cleanPath.startsWith('assets/')) {
            return cleanBase + '/' + cleanPath;
        } else if (cleanPath.startsWith('uploads/')) {
            return cleanBase + '/assets/' + cleanPath;
        } else {
            return cleanBase + '/assets/uploads/' + cleanPath;
        }
    }

    async function downloadZip(imagesInput, zipName) {
        const images = parseImagesInput(imagesInput);
        if (!Array.isArray(images) || images.length === 0) {
            showToast('Không tìm thấy trang ảnh bản thảo nào để tải về.', 'warning');
            return;
        }
        if (typeof JSZip === 'undefined') {
            showToast('Thư viện JSZip chưa sẵn sàng. Vui lòng kiểm tra lại.', 'error');
            return;
        }

        const folderName = (zipName || 'BanThao_Chuong').replace(/[^a-zA-Z0-9_\-]/g, '_');
        showZipProgressModal(images.length);

        const zip = new JSZip();
        const folder = zip.folder(folderName) || zip;
        let completed = 0;
        let lastErr = '';

        for (let i = 0; i < images.length; i++) {
            let imgPath = resolveAssetUrl(images[i]);
            updateZipProgressModal(i, images.length, `Đang tải ảnh trang ${i + 1}/${images.length}...`);
            try {
                const response = await fetch(imgPath);
                if (!response.ok) throw new Error('HTTP ' + response.status + ' (' + response.statusText + ')');
                const blob = await response.blob();
                let ext = imgPath.split('.').pop().split('?')[0].toLowerCase();
                const allowedExts = ['jpg','jpeg','png','webp','gif','pdf','zip','rar'];
                if (ext.length > 4 || ext.includes('/') || !allowedExts.includes(ext)) ext = 'jpg';
                const pageNum = String(i + 1).padStart(2, '0');
                const fileName = (images.length === 1 && (ext === 'pdf' || ext === 'zip')) ? `${folderName}.${ext}` : `Trang_${pageNum}.${ext}`;
                folder.file(fileName, blob);
                completed++;
            } catch (err) {
                console.error(`Lỗi khi nén trang ${i + 1} [${imgPath}]:`, err);
                lastErr = err.message;
            }
        }

        if (completed === 0) {
            hideZipProgressModal();
            showToast(`Không thể tải ảnh về máy. (${lastErr || 'Lỗi kết nối / file không tồn tại'})`, 'error');
            return;
        }

        updateZipProgressModal(images.length, images.length, 'Đang tiến hành đóng gói ZIP...');

        zip.generateAsync({ type: 'blob' }, (metadata) => {
            const pct = Math.round(metadata.percent);
            updateZipProgressModal(images.length, images.length, `Đang nén ZIP: ${pct}%`);
        }).then((content) => {
            hideZipProgressModal();
            const a = document.createElement('a');
            a.href = URL.createObjectURL(content);
            a.download = `${folderName}.zip`;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            setTimeout(() => URL.revokeObjectURL(a.href), 10000);
            showToast(`Đã tải về thành công ${folderName}.zip!`, 'success');
        }).catch((err) => {
            hideZipProgressModal();
            console.error(err);
            showToast('Lỗi khi tạo file ZIP: ' + err.message, 'error');
        });
    }

    let currentReaderImages = [];
    let currentReaderIndex = 0;
    let currentReaderMode = 'webtoon';
    let currentReaderTitle = '';
    let currentReaderZipName = '';

    function openWebtoonReader(imagesInput, title, zipName) {
        const images = parseImagesInput(imagesInput);
        if (!Array.isArray(images) || images.length === 0) {
            showToast('Bản thảo chưa có trang ảnh nào để xem.', 'warning');
            return;
        }
        
        currentReaderImages = images.map(imgPath => resolveAssetUrl(imgPath));
        currentReaderIndex = 0;
        currentReaderTitle = title || 'Bản Thảo Truyện';
        currentReaderZipName = zipName || 'BanThao_Chuong';

        let backdrop = document.getElementById('webtoonReaderModal');
        if (!backdrop) {
            backdrop = document.createElement('div');
            backdrop.id = 'webtoonReaderModal';
            backdrop.className = 'webtoon-modal-backdrop';
            document.body.appendChild(backdrop);
        }

        renderReaderHTML();
        backdrop.classList.add('open');
        document.body.style.overflow = 'hidden';

        window.removeEventListener('keydown', handleReaderKeyDown);
        window.addEventListener('keydown', handleReaderKeyDown);
    }

    function closeWebtoonReader() {
        const backdrop = document.getElementById('webtoonReaderModal');
        if (backdrop) backdrop.classList.remove('open');
        document.body.style.overflow = '';
        window.removeEventListener('keydown', handleReaderKeyDown);
    }

    function handleReaderKeyDown(e) {
        const backdrop = document.getElementById('webtoonReaderModal');
        if (!backdrop || !backdrop.classList.contains('open')) return;
        if (e.key === 'Escape') {
            closeWebtoonReader();
        } else if (currentReaderMode === 'gallery') {
            if (e.key === 'ArrowLeft') switchGalleryPage(currentReaderIndex - 1);
            if (e.key === 'ArrowRight') switchGalleryPage(currentReaderIndex + 1);
        }
    }

    function setReaderMode(mode) {
        currentReaderMode = mode;
        renderReaderHTML();
    }

    function switchGalleryPage(index) {
        if (index < 0 || index >= currentReaderImages.length) return;
        currentReaderIndex = index;
        const imgEl = document.getElementById('galleryMainImg');
        const numEl = document.getElementById('galleryPageNum');
        if (imgEl) imgEl.src = currentReaderImages[index];
        if (numEl) numEl.innerText = `Trang ${index + 1} / ${currentReaderImages.length}`;

        // Update nav buttons disabled state
        const prevBtn = document.querySelector('.gallery-nav-btn.prev');
        const nextBtn = document.querySelector('.gallery-nav-btn.next');
        if (prevBtn) {
            prevBtn.disabled = index <= 0;
            prevBtn.setAttribute('onclick', `switchGalleryPage(${index - 1})`);
        }
        if (nextBtn) {
            nextBtn.disabled = index >= currentReaderImages.length - 1;
            nextBtn.setAttribute('onclick', `switchGalleryPage(${index + 1})`);
        }
        
        const thumbs = document.querySelectorAll('.gallery-thumb-item');
        thumbs.forEach((t, i) => {
            if (i === index) {
                t.classList.add('active');
                t.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
            } else {
                t.classList.remove('active');
            }
        });
    }

    function renderReaderHTML() {
        const backdrop = document.getElementById('webtoonReaderModal');
        if (!backdrop) return;

        const encodedImgs = JSON.stringify(currentReaderImages).replace(/"/g, '&quot;');

        let contentHTML = '';
        if (currentReaderMode === 'webtoon') {
            contentHTML = `
                <div class="webtoon-reader-body">
                    <div class="webtoon-container">
                        ${currentReaderImages.map((src, i) => `
                            <div class="webtoon-page-item">
                                <img src="${src}" class="webtoon-page-img" alt="Trang ${i+1}" loading="lazy">
                                <div class="webtoon-page-num-tag">Trang ${i+1} / ${currentReaderImages.length}</div>
                            </div>
                        `).join('')}
                    </div>
                </div>
            `;
        } else {
            contentHTML = `
                <div class="gallery-container">
                    <div class="gallery-stage">
                        <button class="gallery-nav-btn prev" onclick="switchGalleryPage(${currentReaderIndex - 1})" title="Trang trước (←)" ${currentReaderIndex <= 0 ? 'disabled' : ''}>‹</button>
                        <img id="galleryMainImg" src="${currentReaderImages[currentReaderIndex]}" class="gallery-img" alt="Trang ${currentReaderIndex + 1}">
                        <button class="gallery-nav-btn next" onclick="switchGalleryPage(${currentReaderIndex + 1})" title="Trang sau (→)" ${currentReaderIndex >= currentReaderImages.length - 1 ? 'disabled' : ''}>›</button>
                    </div>
                    <div class="gallery-thumbs-bar">
                        ${currentReaderImages.map((src, i) => `
                            <div class="gallery-thumb-item ${i === currentReaderIndex ? 'active' : ''}" onclick="switchGalleryPage(${i})" title="Trang ${i+1}">
                                <img src="${src}" alt="Thumb ${i+1}">
                            </div>
                        `).join('')}
                    </div>
                </div>
            `;
        }

        backdrop.innerHTML = `
            <div class="webtoon-reader-header">
                <div class="webtoon-reader-title">
                    <span style="font-size:1.2rem">📖</span>
                    <span>${esc(currentReaderTitle)}</span>
                    <span id="galleryPageNum" style="font-size:0.8rem; font-weight:500; color:var(--text-muted, #94a3b8); margin-left:8px;">
                        ${currentReaderMode === 'gallery' ? `Trang ${currentReaderIndex + 1} / ${currentReaderImages.length}` : `(${currentReaderImages.length} trang)`}
                    </span>
                </div>
                <div class="webtoon-reader-controls">
                    <button class="reader-mode-btn ${currentReaderMode === 'webtoon' ? 'active' : ''}" onclick="setReaderMode('webtoon')" title="Cuộn đọc dạng Webtoon dọc">
                        📜 Dọc (Webtoon)
                    </button>
                    <button class="reader-mode-btn ${currentReaderMode === 'gallery' ? 'active' : ''}" onclick="setReaderMode('gallery')" title="Xem dạng lướt hình Gallery">
                        🖼️ Slide (Gallery)
                    </button>
                    <button class="btn btn-secondary btn-sm" onclick="downloadZip('${encodedImgs}', '${esc(currentReaderZipName)}')" style="background:rgba(99,102,241,0.2); color:#a5b4fc; border-color:rgba(99,102,241,0.4);">
                        📥 Tải ZIP
                    </button>
                    <button class="btn btn-secondary btn-sm" onclick="closeWebtoonReader()" style="padding:4px 10px; font-size:1.1rem; border-radius:50%;">
                        ✕
                    </button>
                </div>
            </div>
            ${contentHTML}
        `;
    }

    window.downloadZip = downloadZip;
    window.openWebtoonReader = openWebtoonReader;
    window.closeWebtoonReader = closeWebtoonReader;
    window.setReaderMode = setReaderMode;
    window.switchGalleryPage = switchGalleryPage;
    window.resolveAssetUrl = resolveAssetUrl;



    /* ═══════════════════════════════════════════════════════
       INIT on DOM ready
    ═══════════════════════════════════════════════════════ */
    ready(() => {
        initSidebar();
        initNotifications();
        initActiveNav();
        initAlerts();
        initDataConfirm();
        initFileUploadPreviews();
        initLiveValidation();
        initAutoResizeTextareas();
        initCharCounters();
    });

})();
