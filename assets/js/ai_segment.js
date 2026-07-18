/**
 * assets/js/ai_segment.js
 * Canvas overlay + sidebar logic cho tính năng phân đoạn vùng AI
 *
 * Dùng trong: ai/segment.php, mangaka/tasks.php (integration)
 *
 * API Public:
 *   AiSegment.init(canvasEl, imgEl, sidebarEl, onUseRegionCb?)
 *   AiSegment.renderRegions(regions)
 *   AiSegment.clear()
 *   AiSegment.bindCanvasClick(canvasEl)
 *   AiSegment.selectAll()
 *   AiSegment.deselectAll()
 *   AiSegment.getSelectedRegions()     → array vùng đã tick
 *   AiSegment.setOnSelectionChange(cb) → cb(selectedCount, totalCount)
 */

const AiSegment = (() => {
    'use strict';

    // ── Màu theo type ──────────────────────────────────────────────────────
    const TYPE_COLORS = {
        background:  { fill: 'rgba(135, 206, 235, 0.35)', stroke: '#87CEEB' },
        character:   { fill: 'rgba(255, 99,  71,  0.35)', stroke: '#FF6347' },
        effects:     { fill: 'rgba(255, 215,  0,  0.35)', stroke: '#FFD700' },
        shading:     { fill: 'rgba(144, 238, 144, 0.35)', stroke: '#90EE90' },
        text_bubble: { fill: 'rgba(186,  85, 211, 0.35)', stroke: '#BA55D3' },
    };
    const DEFAULT_COLOR = { fill: 'rgba(200,200,200,0.3)', stroke: '#ccc' };

    const TYPE_LABELS_VI = {
        background:  'Phông nền',
        character:   'Nhân vật',
        effects:     'Hiệu ứng',
        shading:     'Đổ bóng',
        text_bubble: 'Thoại',
    };

    // ── State ──────────────────────────────────────────────────────────────
    let _canvas            = null;
    let _img               = null;
    let _sidebar           = null;
    let _regions           = [];
    let _hidden            = new Set();
    let _highlighted       = null;
    let _onUseRegion       = null;
    let _selectedForUse    = new Set();  // vùng được tick để dùng
    let _onSelectionChange = null;       // callback(selectedCount, totalCount)

    // ── Init ──────────────────────────────────────────────────────────────
    function init(canvasEl, imgEl, sidebarEl, onUseRegionCb) {
        _canvas      = canvasEl;
        _img         = imgEl;
        _sidebar     = sidebarEl;
        _onUseRegion = onUseRegionCb || null;

        if (_img) {
            _img.addEventListener('load', _syncCanvasSize);
            if (_img.complete && _img.naturalWidth) _syncCanvasSize();
        }
        window.addEventListener('resize', () => {
            if (_regions.length) _draw();
        });
    }

    function _syncCanvasSize() {
        if (!_canvas || !_img) return;
        // Sử dụng độ phân giải tự nhiên của ảnh làm độ phân giải vẽ trong của canvas
        _canvas.width  = _img.naturalWidth  || 512;
        _canvas.height = _img.naturalHeight || 512;

        // Đồng bộ CSS để canvas nằm đè khít lên vị trí và kích thước thực tế của ảnh
        _canvas.style.position = 'absolute';
        _canvas.style.left     = _img.offsetLeft + 'px';
        _canvas.style.top      = _img.offsetTop + 'px';
        _canvas.style.width    = _img.offsetWidth + 'px';
        _canvas.style.height   = _img.offsetHeight + 'px';

        if (_regions.length) _draw();
    }

    // ── Render ────────────────────────────────────────────────────────────
    function renderRegions(regions) {
        _regions        = regions || [];
        _hidden         = new Set();
        _highlighted    = null;
        // Mặc định: tất cả vùng được chọn để dùng
        _selectedForUse = new Set(_regions.map(r => r.id));
        _draw();
        _buildSidebar();
        if (_onSelectionChange) _onSelectionChange(_selectedForUse.size, _regions.length);
    }

    function clear() {
        _regions        = [];
        _hidden         = new Set();
        _highlighted    = null;
        _selectedForUse = new Set();
        if (_canvas) {
            const ctx = _canvas.getContext('2d');
            ctx.clearRect(0, 0, _canvas.width, _canvas.height);
        }
        if (_sidebar) _sidebar.innerHTML = '';
        if (_onSelectionChange) _onSelectionChange(0, 0);
    }

    // ── Draw canvas ───────────────────────────────────────────────────────
    function _draw() {
        if (!_canvas) return;
        if (_img) {
            // Đồng bộ kích thước vẽ trong bằng kích thước tự nhiên của ảnh gốc
            _canvas.width  = _img.naturalWidth  || 512;
            _canvas.height = _img.naturalHeight || 512;

            // Đồng bộ CSS để canvas đè khít lên ảnh thực tế trên trình duyệt
            _canvas.style.position = 'absolute';
            _canvas.style.left     = _img.offsetLeft + 'px';
            _canvas.style.top      = _img.offsetTop + 'px';
            _canvas.style.width    = _img.offsetWidth + 'px';
            _canvas.style.height   = _img.offsetHeight + 'px';
        }

        const ctx = _canvas.getContext('2d');
        ctx.clearRect(0, 0, _canvas.width, _canvas.height);

        const W = _canvas.width;
        const H = _canvas.height;

        _regions.forEach(r => {
            if (_hidden.has(r.id)) return;

            const c          = TYPE_COLORS[r.type] || DEFAULT_COLOR;
            const isHL       = _highlighted === r.id;
            const isSelected = _selectedForUse.has(r.id);

            const px = (r.x      / 100) * W;
            const py = (r.y      / 100) * H;
            const pw = (r.width  / 100) * W;
            const ph = (r.height / 100) * H;

            // Fill — đậm hơn nếu được chọn
            const fillOpacity = isHL ? '0.60' : (isSelected ? '0.40' : '0.12');
            ctx.fillStyle = c.fill.replace('0.35', fillOpacity);
            ctx.fillRect(px, py, pw, ph);

            // Border — liền nếu chọn, nét đứt nhạt nếu không chọn
            ctx.strokeStyle = isHL ? '#fff' : c.stroke;
            ctx.lineWidth   = isHL ? 3 : (isSelected ? 2 : 1);
            ctx.globalAlpha = isSelected ? 1 : 0.45;
            ctx.setLineDash(isSelected ? (isHL ? [] : [4, 3]) : [2, 5]);
            ctx.strokeRect(px, py, pw, ph);
            ctx.setLineDash([]);
            ctx.globalAlpha = 1;

            // Label badge
            const label    = r.label.length > 18 ? r.label.slice(0, 18) + '…' : r.label;
            const fontSize = Math.max(10, Math.min(13, pw / 8));
            ctx.font       = `bold ${fontSize}px Inter, sans-serif`;
            const textW    = ctx.measureText(label).width;
            const badgeW   = textW + 10;
            const badgeH   = fontSize + 8;

            ctx.globalAlpha = isSelected ? 1 : 0.4;
            ctx.fillStyle   = isHL ? '#fff' : c.stroke;
            ctx.beginPath();
            ctx.roundRect(px + 2, py + 2, badgeW, badgeH, 4);
            ctx.fill();

            ctx.fillStyle   = '#0d0d1a';
            ctx.globalAlpha = 1;
            ctx.fillText(label, px + 7, py + 2 + fontSize);

            // Confidence %
            const conf    = Math.round(r.confidence * 100);
            ctx.font      = `${fontSize - 2}px Inter, sans-serif`;
            ctx.fillStyle = isSelected ? (isHL ? '#000' : c.stroke) : c.stroke + '66';
            ctx.fillText(conf + '%', px + pw - 26, py + ph - 4);
        });
    }

    // ── Sidebar ────────────────────────────────────────────────────────────
    function _buildSidebar() {
        if (!_sidebar) return;
        _sidebar.innerHTML = '';

        if (!_regions.length) {
            _sidebar.innerHTML = '<p style="color:var(--text-muted);font-size:.85rem;padding:16px;text-align:center;">Chưa có vùng nào được phát hiện.</p>';
            return;
        }

        _regions.forEach(r => {
            const c          = TYPE_COLORS[r.type] || DEFAULT_COLOR;
            const typeVI     = TYPE_LABELS_VI[r.type] || r.type;
            const conf       = Math.round(r.confidence * 100);
            const isSelected = _selectedForUse.has(r.id);
            const isHid      = _hidden.has(r.id);

            const item = document.createElement('div');
            item.className = 'ai-seg-item' + (r.id === _highlighted ? ' highlighted' : '');
            item.id        = 'seg-item-' + r.id;
            item.style.opacity = isHid ? '0.4' : '1';
            item.style.transition = 'opacity .2s';

            item.innerHTML = `
                <div class="ai-seg-item-header">
                    <label class="ai-seg-check" title="Chọn vùng này để dùng trong Giao Task">
                        <input type="checkbox" data-id="${r.id}" ${isSelected ? 'checked' : ''}
                               style="accent-color:#7B2FBE;width:15px;height:15px;cursor:pointer;"
                               onchange="AiSegment._toggleSelect(${r.id}, this.checked)">
                        <span class="ai-seg-color-dot" style="background:${c.stroke};"></span>
                    </label>
                    <div class="ai-seg-info" onclick="AiSegment._highlight(${r.id})" style="cursor:pointer;flex:1;">
                        <div class="ai-seg-label">${r.label}</div>
                        <div class="ai-seg-meta">
                            <span class="ai-badge-type" style="border-color:${c.stroke};color:${c.stroke};">${typeVI}</span>
                            <span class="ai-conf">${conf}%</span>
                        </div>
                    </div>
                    <button onclick="AiSegment._toggleHide(${r.id}, !${isHid})"
                            title="${isHid ? 'Hiện trên canvas' : 'Ẩn trên canvas'}"
                            style="background:none;border:none;cursor:pointer;padding:2px 6px;font-size:.78rem;
                                   color:var(--text-muted);flex-shrink:0;border-radius:4px;transition:background .15s;"
                            onmouseover="this.style.background='rgba(255,255,255,.08)'"
                            onmouseout="this.style.background='none'">
                        ${isHid ? '🚫' : '👁'}
                    </button>
                </div>
            `;
            _sidebar.appendChild(item);
        });
    }

    // ── Multi-select (chọn để dùng) ───────────────────────────────────────
    function _toggleSelect(id, selected) {
        if (selected) _selectedForUse.add(id);
        else          _selectedForUse.delete(id);
        _draw();
        if (_onSelectionChange) _onSelectionChange(_selectedForUse.size, _regions.length);
    }

    function selectAll() {
        _selectedForUse = new Set(_regions.map(r => r.id));
        _regions.forEach(r => {
            const cb = document.querySelector(`input[data-id="${r.id}"]`);
            if (cb) cb.checked = true;
        });
        _draw();
        if (_onSelectionChange) _onSelectionChange(_selectedForUse.size, _regions.length);
    }

    function deselectAll() {
        _selectedForUse = new Set();
        _regions.forEach(r => {
            const cb = document.querySelector(`input[data-id="${r.id}"]`);
            if (cb) cb.checked = false;
        });
        _draw();
        if (_onSelectionChange) _onSelectionChange(0, _regions.length);
    }

    function getSelectedRegions() {
        return _regions.filter(r => _selectedForUse.has(r.id));
    }

    function setOnSelectionChange(cb) {
        _onSelectionChange = cb;
    }

    // ── Hide/show & Highlight ─────────────────────────────────────────────
    function _toggleHide(id, hide) {
        if (hide) _hidden.add(id);
        else      _hidden.delete(id);
        _draw();
        _buildSidebar();
    }

    function _highlight(id) {
        _highlighted = (_highlighted === id) ? null : id;
        _draw();
        _buildSidebar();

        if (_canvas && _highlighted) {
            _canvas.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
        const el = document.getElementById('seg-item-' + id);
        if (el) el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function _useRegion(id) {
        const r = _regions.find(x => x.id === id);
        if (!r) return;
        if (typeof _onUseRegion === 'function') {
            _onUseRegion(r);
        }
    }

    // ── Canvas click để highlight vùng ────────────────────────────────────
    function bindCanvasClick(canvasEl) {
        if (!canvasEl) return;
        canvasEl.addEventListener('click', e => {
            if (!_regions.length) return;
            const rect = canvasEl.getBoundingClientRect();
            const mx   = e.clientX - rect.left;
            const my   = e.clientY - rect.top;
            // Tính tỷ lệ phần trăm theo kích thước hiển thị thực tế (rect.width/height)
            const pctX = (mx / rect.width) * 100;
            const pctY = (my / rect.height) * 100;

            // Tìm vùng nhỏ nhất chứa điểm click
            let hit = null;
            let hitArea = Infinity;
            _regions.forEach(r => {
                if (_hidden.has(r.id)) return;
                if (pctX >= r.x && pctX <= r.x + r.width &&
                    pctY >= r.y && pctY <= r.y + r.height) {
                    const area = r.width * r.height;
                    if (area < hitArea) { hit = r.id; hitArea = area; }
                }
            });
            if (hit !== null) _highlight(hit);
        });
    }

    // ── Expose ────────────────────────────────────────────────────────────
    return {
        init,
        renderRegions,
        clear,
        bindCanvasClick,
        selectAll,
        deselectAll,
        getSelectedRegions,
        setOnSelectionChange,
        _toggleHide,
        _toggleSelect,
        _highlight,
        _useRegion,
    };
})();
