/**
 * MangaFlow — main.js
 * Global JS for the dashboard shell
 */

(function () {
    'use strict';

    /* ── DOM ready helper ── */
    function ready(fn) {
        if (document.readyState !== 'loading') fn();
        else document.addEventListener('DOMContentLoaded', fn);
    }

    /* ════════════════════════════════
       Sidebar toggle (mobile hamburger)
       ════════════════════════════════ */
    function initSidebar() {
        const hamburger = document.getElementById('hamburgerBtn');
        const sidebar   = document.getElementById('appSidebar');
        const overlay   = document.getElementById('sidebarOverlay');

        if (!hamburger || !sidebar) return;

        function openSidebar() {
            sidebar.classList.add('open');
            overlay && overlay.classList.add('active');
            document.body.style.overflow = 'hidden';
            hamburger.setAttribute('aria-expanded', 'true');
        }

        function closeSidebar() {
            sidebar.classList.remove('open');
            overlay && overlay.classList.remove('active');
            document.body.style.overflow = '';
            hamburger.setAttribute('aria-expanded', 'false');
        }

        hamburger.addEventListener('click', () => {
            sidebar.classList.contains('open') ? closeSidebar() : openSidebar();
        });

        overlay && overlay.addEventListener('click', closeSidebar);

        // Close on ESC
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') closeSidebar();
        });

        // Close when resized back to desktop
        window.addEventListener('resize', () => {
            if (window.innerWidth > 900) closeSidebar();
        });
    }

    /* ════════════════════════════════
       Notification dropdown
       ════════════════════════════════ */
    function initNotifications() {
        const bell     = document.getElementById('notifBell');
        const dropdown = document.getElementById('notifDropdown');
        const markAll  = document.getElementById('markAllRead');

        if (!bell || !dropdown) return;

        bell.addEventListener('click', (e) => {
            e.stopPropagation();
            dropdown.classList.toggle('open');
        });

        // Close on outside click
        document.addEventListener('click', (e) => {
            if (!dropdown.contains(e.target) && e.target !== bell) {
                dropdown.classList.remove('open');
            }
        });

        // Mark all as read
        markAll && markAll.addEventListener('click', () => {
            fetch(BASE_URL + 'api/notifications.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=mark_all_read'
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    document.querySelectorAll('.notif-item.unread').forEach(el => {
                        el.classList.remove('unread');
                        el.querySelector('.notif-dot') && el.querySelector('.notif-dot').remove();
                        if (!el.querySelector('.notif-offset')) {
                            const offset = document.createElement('div');
                            offset.className = 'notif-offset';
                            offset.style.cssText = 'width:8px;flex-shrink:0;';
                            el.insertBefore(offset, el.firstChild);
                        }
                    });
                    const countEl = document.getElementById('notifCount');
                    if (countEl) countEl.style.display = 'none';
                }
            })
            .catch(() => {}); // Fail silently
        });

        // Event delegation for individual notification click
        const notifList = dropdown.querySelector('.notif-list');
        if (notifList) {
            notifList.addEventListener('click', function(e) {
                const item = e.target.closest('.notif-item');
                if (!item) return;

                const id   = item.dataset.id;
                const link = item.dataset.link;

                if (item.classList.contains('unread')) {
                    fetch(BASE_URL + 'api/notifications.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: `action=mark_read&id=${id}`
                    }).catch(() => {});

                    item.classList.remove('unread');
                    const dot = item.querySelector('.notif-dot');
                    if (dot) dot.remove();

                    if (!item.querySelector('.notif-offset')) {
                        const offset = document.createElement('div');
                        offset.className = 'notif-offset';
                        offset.style.cssText = 'width:8px;flex-shrink:0;';
                        item.insertBefore(offset, item.firstChild);
                    }

                    // Decrement count badge
                    const countEl = document.getElementById('notifCount');
                    if (countEl) {
                        const n = parseInt(countEl.textContent) - 1;
                        countEl.textContent = n;
                        if (n <= 0) countEl.style.display = 'none';
                    }
                }

                if (link && link !== BASE_URL) {
                    window.location.href = link;
                }
            });
        }

        // Initialize maxNotifId from server-rendered notifications
        let maxNotifId = 0;
        document.querySelectorAll('.notif-item[data-id]').forEach(item => {
            const id = parseInt(item.dataset.id);
            if (id > maxNotifId) maxNotifId = id;
        });

        // Helper: escape HTML
        function escapeHTML(str) {
            return str.replace(/[&<>'"]/g, 
                tag => ({
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    "'": '&#39;',
                    '"': '&quot;'
                }[tag] || tag)
            );
        }

        // UI Updater
        function updateNotificationsUI(notifications, unreadCount) {
            const countEl = document.getElementById('notifCount');
            if (countEl) {
                countEl.textContent = unreadCount;
                countEl.style.display = unreadCount > 0 ? 'flex' : 'none';
            }

            if (!notifList) return;

            if (notifications.length === 0) {
                notifList.innerHTML = `
                    <div class="notif-empty">
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin:0 auto 8px;opacity:0.3">
                            <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>
                        </svg>
                        <p>Không có thông báo mới</p>
                    </div>`;
                return;
            }

            let hasNew = false;
            const toastTypes = ['rank_drop', 'task_approved', 'manuscript_decision', 'task_assigned', 'task_revision', 'manuscript_review'];

            // Loop in reverse order to show toast chronologically
            for (let i = notifications.length - 1; i >= 0; i--) {
                const notif = notifications[i];
                const notifId = parseInt(notif.id);
                if (notifId > maxNotifId) {
                    maxNotifId = notifId;
                    hasNew = true;

                    if (toastTypes.includes(notif.type)) {
                        let toastType = 'info';
                        if (notif.type === 'rank_drop' || notif.type === 'task_revision') toastType = 'error';
                        else if (notif.type === 'task_approved') toastType = 'success';
                        else if (notif.type === 'manuscript_decision' || notif.type === 'manuscript_review') toastType = 'warning';

                        window.MangaFlow.toast(notif.message, toastType);
                    }
                }
            }

            let html = '';
            notifications.forEach(notif => {
                const isUnread = parseInt(notif.is_read) === 0;
                const link = notif.link ? BASE_URL + notif.link.replace(/^\//, '') : '';
                let timeStr = '';
                try {
                    const dt = new Date(notif.created_at.replace(/-/g, '/'));
                    const day = String(dt.getDate()).padStart(2, '0');
                    const month = String(dt.getMonth() + 1).padStart(2, '0');
                    const hours = String(dt.getHours()).padStart(2, '0');
                    const minutes = String(dt.getMinutes()).padStart(2, '0');
                    timeStr = `${day}/${month} ${hours}:${minutes}`;
                } catch(e) {
                    timeStr = notif.created_at;
                }

                html += `
                    <div class="notif-item ${isUnread ? 'unread' : ''}"
                         data-id="${notif.id}"
                         data-link="${link}">
                        ${isUnread ? '<div class="notif-dot"></div>' : '<div class="notif-offset" style="width:8px;flex-shrink:0;"></div>'}
                        <div>
                            <div class="notif-text">${escapeHTML(notif.message)}</div>
                            <div class="notif-time">${timeStr}</div>
                        </div>
                    </div>`;
            });

            notifList.innerHTML = html;

            if (markAll) {
                markAll.style.display = notifications.length > 0 ? 'inline-block' : 'none';
            }
        }

        // Polling function
        function pollNotifications() {
            fetch(BASE_URL + 'api/notifications.php')
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    updateNotificationsUI(data.notifications, data.unread_count);
                }
            })
            .catch(() => {});
        }

        // Start polling every 30 seconds
        setInterval(pollNotifications, 30000);
    }

    /* ════════════════════════════════
       Highlight active nav link
       ════════════════════════════════ */
    function initActiveNav() {
        const current = window.location.pathname.replace(/\\/g, '/');
        document.querySelectorAll('.nav-link[data-path]').forEach(link => {
            const path = link.dataset.path.replace(/\\/g, '/');
            if (current.endsWith(path)) {
                link.classList.add('active');
            }
        });
    }

    /* ════════════════════════════════
       Auto-dismiss flash alerts
       ════════════════════════════════ */
    function initAlerts() {
        document.querySelectorAll('.alert[data-auto-dismiss]').forEach(el => {
            const ms = parseInt(el.dataset.autoDismiss) || 4000;
            setTimeout(() => {
                el.style.transition = 'opacity 0.4s, margin-top 0.4s, padding 0.4s, height 0.4s';
                el.style.opacity = '0';
                setTimeout(() => el.remove(), 420);
            }, ms);
        });

        // Manual close button
        document.querySelectorAll('.alert-close').forEach(btn => {
            btn.addEventListener('click', function () {
                this.closest('.alert').remove();
            });
        });
    }

    /* ════════════════════════════════
       Confirm dialogs on delete/danger
       ════════════════════════════════ */
    function initConfirm() {
        document.querySelectorAll('[data-confirm]').forEach(el => {
            el.addEventListener('click', function (e) {
                const msg = this.dataset.confirm || 'Bạn có chắc chắn muốn thực hiện hành động này?';
                if (!window.confirm(msg)) e.preventDefault();
            });
        });
    }

    /* ════════════════════════════════
       AJAX utility (used by sub-pages)
       ════════════════════════════════ */
    window.MangaFlow = {
        /**
         * Perform a JSON POST to the API
         * @param {string} endpoint  — relative to BASE_URL + 'api/'
         * @param {object} body      — key/value pairs
         * @returns {Promise}
         */
        api(endpoint, body = {}) {
            const params = new URLSearchParams(body);
            return fetch(BASE_URL + 'api/' + endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: params.toString()
            }).then(r => {
                if (!r.ok) throw new Error(`HTTP ${r.status}`);
                return r.json();
            });
        },

        /** Show a toast notification */
        toast(message, type = 'info', duration = 3500) {
            const container = document.getElementById('toastContainer') || (() => {
                const el = document.createElement('div');
                el.id = 'toastContainer';
                el.style.cssText = 'position:fixed;bottom:24px;right:24px;z-index:9999;display:flex;flex-direction:column;gap:8px;';
                document.body.appendChild(el);
                return el;
            })();

            const colors = {
                success: '#10b981', error: '#E63946',
                warning: '#f59e0b', info: '#3b82f6'
            };

            const toast = document.createElement('div');
            toast.style.cssText = `
                background:#1e2547;border:1px solid rgba(255,255,255,0.08);
                color:#f0f0f8;padding:12px 18px;border-radius:10px;
                font-size:0.875rem;font-family:Inter,sans-serif;
                box-shadow:0 8px 30px rgba(0,0,0,0.4);
                display:flex;align-items:center;gap:10px;max-width:320px;
                border-left:3px solid ${colors[type] || colors.info};
                animation:slideInRight 0.2s ease;
            `;
            toast.innerHTML = `<span>${message}</span>`;

            // inject animation if not already present
            if (!document.getElementById('toastKeyframes')) {
                const s = document.createElement('style');
                s.id = 'toastKeyframes';
                s.textContent = '@keyframes slideInRight{from{opacity:0;transform:translateX(20px)}to{opacity:1;transform:translateX(0)}}';
                document.head.appendChild(s);
            }

            container.appendChild(toast);
            setTimeout(() => {
                toast.style.opacity = '0';
                toast.style.transition = 'opacity 0.3s';
                setTimeout(() => toast.remove(), 320);
            }, duration);
        }
    };

    /* ════════════════════════════════
       Init all on DOM ready
       ════════════════════════════════ */
    ready(() => {
        initSidebar();
        initNotifications();
        initActiveNav();
        initAlerts();
        initConfirm();
    });
})();
