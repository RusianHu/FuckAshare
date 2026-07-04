// ============================================================
// FuckAshare Strategy Pool Module
// 独立策略池前端模块：API、状态、渲染、弹窗管理集中在此文件。
// ============================================================
(function() {
'use strict';

// ============================================================
// 策略池模块
// ============================================================
window.StrategyModule = {
    STORAGE_KEY: 'fa_strategy_pool',
    strategies: [],
    pool: [],
    draftPool: [],
    activeStrategy: null,
    activeSource: 'all',
    results: {},
    meta: null,
    asOf: '',
    showAll: false,
    initialized: false,

    init() {
        if (this.initialized) return;
        this.initialized = true;
        this.bindEvents();
        this.loadStrategies();
    },

    bindEvents() {
        document.getElementById('strategy-pool-btn')?.addEventListener('click', () => this.openPoolModal());
        document.getElementById('strategy-run-all-btn')?.addEventListener('click', () => this.runAll());
        document.getElementById('strategy-show-all-btn')?.addEventListener('click', () => this.renderAllResults());
        document.getElementById('strategy-modal-close')?.addEventListener('click', () => this.closePoolModal());
        document.getElementById('strategy-modal-cancel')?.addEventListener('click', () => this.closePoolModal());
        document.getElementById('strategy-modal-save')?.addEventListener('click', () => this.savePoolDraft());
        document.getElementById('strategy-pool-modal')?.addEventListener('click', e => {
            if (e.target === e.currentTarget) this.closePoolModal();
        });
        document.querySelectorAll('.strategy-source-tab').forEach(btn => {
            btn.addEventListener('click', () => {
                this.activeSource = btn.dataset.source || 'all';
                document.querySelectorAll('.strategy-source-tab').forEach(x => x.classList.toggle('active', x === btn));
                this.renderPoolModal();
            });
        });
        document.getElementById('strategy-table-data')?.addEventListener('click', e => {
            const queryBtn = e.target.closest('[data-strategy-query]');
            if (queryBtn) {
                submitStockQuery(queryBtn.dataset.strategyQuery, { frequency: '1d', count: 90 });
                return;
            }
            const aiBtn = e.target.closest('[data-strategy-row-ai]');
            if (aiBtn) {
                const row = this.findRenderedRow(aiBtn.dataset.strategyRowAi);
                if (row) this.sendRowsToAI([row], `请复盘 ${row.name}(${row.symbol}) 这条策略命中记录。`);
            }
        });
        document.getElementById('strategy-result-ai-btn')?.addEventListener('click', () => {
            const rows = this.currentRenderedRows || [];
            this.sendRowsToAI(rows.slice(0, 30), '请根据策略池命中结果做一次盘后复盘，给出分组、风险、次日观察清单和需要排除的票。');
        });
    },

    async loadStrategies() {
        this.setStatus('加载策略列表中...');
        try {
            const data = await fetch('strategy_api.php?action=list').then(r => r.json());
            if (!data.success) throw new Error(data.message || '策略列表加载失败');
            this.strategies = data.strategies || [];
            this.pool = this.loadPool();
            this.prunePool();
            this.renderCards();
            this.updateCounts();
            this.setStatus(`已加载 ${this.strategies.length} 个内置策略。当前策略池 ${this.pool.length} 个，点击卡片可运行单策略。`);
        } catch (error) {
            this.showError(error.message);
            this.setStatus('策略列表加载失败');
        }
    },

    loadPool() {
        try {
            const saved = JSON.parse(localStorage.getItem(this.STORAGE_KEY) || '[]');
            if (Array.isArray(saved) && saved.length) return saved;
        } catch (e) {}
        return [
            'high_turnover_surge', 'near_limit_up', 'strong_open', 'limit_up_momentum',
            'volume_price_surge', 'macd_golden', 'bullish_alignment', 'trend_breakout',
        ];
    },

    savePool() {
        try { localStorage.setItem(this.STORAGE_KEY, JSON.stringify(this.pool)); } catch (e) {}
    },

    prunePool() {
        const valid = new Set(this.strategies.map(s => s.id));
        this.pool = this.pool.filter(id => valid.has(id));
        if (!this.pool.length) this.pool = this.strategies.slice(0, 8).map(s => s.id);
        this.savePool();
    },

    strategyMap() {
        return new Map(this.strategies.map(s => [s.id, s]));
    },

    renderCards() {
        const wrap = document.getElementById('strategy-cards');
        if (!wrap) return;
        const map = this.strategyMap();
        wrap.innerHTML = '';
        if (!this.pool.length) {
            wrap.innerHTML = '<div class="strategy-empty-inline">策略池为空，点击“管理策略池”添加策略。</div>';
            return;
        }
        this.pool.forEach(id => {
            const s = map.get(id);
            if (!s) return;
            const total = this.results[id]?.total;
            const card = document.createElement('button');
            card.className = `strategy-card ${this.activeStrategy === id && !this.showAll ? 'active' : ''}`;
            card.type = 'button';
            card.dataset.strategyId = id;
            card.innerHTML = `
                <span class="strategy-card-top">
                    <span class="strategy-source-badge">内置</span>
                    <span class="strategy-card-name">${escapeHTML(s.name)}</span>
                    <span class="strategy-hit-count">${total == null ? '-' : total}</span>
                </span>
                <span class="strategy-card-desc">${escapeHTML(s.description || '')}</span>
                <span class="strategy-card-tags">${(s.tags || []).map(t => `<i>${escapeHTML(t)}</i>`).join('')}</span>
            `;
            card.addEventListener('click', () => this.runStrategy(id));
            wrap.appendChild(card);
        });
        this.updateCounts();
    },

    async runAll() {
        if (!this.pool.length) {
            this.setStatus('策略池为空，请先添加策略。');
            this.openPoolModal();
            return;
        }
        this.setLoading(true);
        this.hideError();
        this.setStatus('正在扫描候选池并精算日 K 指标...');
        try {
            const data = await this.postRun('run_all', { strategy_ids: this.pool });
            if (!data.success) throw new Error(data.message || '策略运行失败');
            this.results = data.results || {};
            this.meta = data.meta || null;
            this.asOf = data.as_of || '';
            this.showAll = true;
            this.activeStrategy = null;
            this.renderCards();
            this.renderAllResults(false);
            this.setStatus(this.metaText());
        } catch (error) {
            this.showError(error.message);
            this.setStatus('策略池运行失败');
        } finally {
            this.setLoading(false);
        }
    },

    async runStrategy(id) {
        const strategy = this.strategyMap().get(id);
        if (!strategy) return;
        this.activeStrategy = id;
        this.showAll = false;
        this.renderCards();
        if (this.results[id]) {
            this.renderResult(strategy.name, this.results[id].rows || [], this.results[id].total || 0, [id]);
            return;
        }
        this.setLoading(true);
        this.hideError();
        this.setStatus(`正在运行「${strategy.name}」...`);
        try {
            const data = await this.postRun('run', { strategy_id: id });
            if (!data.success) throw new Error(data.message || '策略运行失败');
            this.results[id] = data.result;
            this.meta = data.meta || null;
            this.asOf = data.as_of || '';
            this.renderCards();
            this.renderResult(strategy.name, data.result.rows || [], data.result.total || 0, [id]);
            this.setStatus(this.metaText());
        } catch (error) {
            this.showError(error.message);
            this.setStatus(`「${strategy.name}」运行失败`);
        } finally {
            this.setLoading(false);
        }
    },

    async postRun(action, body) {
        const limit = parseInt(document.getElementById('strategy-candidate-limit')?.value || '80', 10);
        const payload = Object.assign({ candidate_limit: limit, pages: 2 }, body || {});
        return fetch(`strategy_api.php?action=${encodeURIComponent(action)}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        }).then(r => r.json());
    },

    renderAllResults(toggle = true) {
        if (toggle) {
            this.showAll = true;
            this.activeStrategy = null;
            this.renderCards();
        }
        const map = this.strategyMap();
        const merged = new Map();
        Object.entries(this.results).forEach(([sid, result]) => {
            (result.rows || []).forEach(row => {
                const current = merged.get(row.symbol) || Object.assign({}, row, { strategy_ids: [], strategy_names: [] });
                current.score = Math.max(Number(current.score || 0), Number(row.score || 0));
                current.strategy_ids.push(sid);
                current.strategy_names.push(map.get(sid)?.name || sid);
                merged.set(row.symbol, current);
            });
        });
        const rows = Array.from(merged.values()).sort((a, b) => (b.score || 0) - (a.score || 0));
        this.renderResult('全部策略命中', rows, rows.length, this.pool);
    },

    renderResult(title, rows, total, strategyIds) {
        const empty = document.getElementById('strategy-result-empty');
        const result = document.getElementById('strategy-result');
        const tbody = document.getElementById('strategy-table-data');
        if (!result || !tbody) return;
        empty && (empty.style.display = 'none');
        result.style.display = 'block';
        document.getElementById('strategy-result-title').textContent = `${title} · ${total} 只`;
        document.getElementById('strategy-result-meta').textContent = this.metaText(strategyIds);
        this.currentRenderedRows = rows;
        tbody.innerHTML = rows.length ? rows.map(row => this.rowHtml(row)).join('') : `
            <tr><td colspan="10" class="strategy-no-result">当前候选池内无命中。可提高候选数后重新运行。</td></tr>
        `;
    },

    rowHtml(row) {
        const strategyText = row.strategy_names?.length ? row.strategy_names.join(' / ') : (this.strategyMap().get(this.activeStrategy)?.name || '-');
        const code = row.symbol || row.code || '';
        return `
            <tr>
                <td class="mono">${escapeHTML(code)}</td>
                <td>${escapeHTML(row.name || '-')}</td>
                <td class="mono">${this.fmt(row.close ?? row.price, 2)}</td>
                <td class="${colorClass(row.change_pct_display ?? row.change_pct * 100)}">${formatPct(row.change_pct_display ?? row.change_pct * 100)}</td>
                <td class="mono">${this.fmt(row.turnover_rate_display ?? row.turnover_rate * 100, 2)}%</td>
                <td class="mono">${this.fmt(row.vol_ratio_5d, 2)}</td>
                <td class="mono">${formatAmount(row.amount || 0)}</td>
                <td class="strategy-score">${this.fmt(row.score, 1)}</td>
                <td class="strategy-tags-cell">${escapeHTML(strategyText)}</td>
                <td>
                    <div class="table-action-group">
                        <button class="btn-quick-query" data-strategy-query="${escapeAttr(code)}">查询</button>
                        <button class="btn-hot-ai" data-strategy-row-ai="${escapeAttr(code)}">${Icons.warning} AI</button>
                    </div>
                </td>
            </tr>
        `;
    },

    findRenderedRow(symbol) {
        return (this.currentRenderedRows || []).find(r => r.symbol === symbol);
    },

    sendRowsToAI(rows, instruction) {
        if (!rows.length) return;
        const lines = rows.map((r, i) => {
            const strategies = r.strategy_names?.join('/') || this.strategyMap().get(this.activeStrategy)?.name || '';
            return `${i + 1}. ${r.name}(${r.symbol}) 策略=${strategies} 价格=${this.fmt(r.close ?? r.price, 2)} 涨跌幅=${formatPct(r.change_pct_display ?? r.change_pct * 100)} 换手=${this.fmt(r.turnover_rate_display ?? r.turnover_rate * 100, 2)}% 量比=${this.fmt(r.vol_ratio_5d, 2)} 成交额=${formatAmount(r.amount || 0)} 评分=${this.fmt(r.score, 1)}`;
        }).join('\n');
        const prompt = `# 策略池命中复盘\n日期: ${this.asOf || new Date().toISOString().slice(0, 10)}\n${this.metaText()}\n\n${lines}\n\n${instruction}\n请说明数据局限：候选池来自东方财富排行合并，不等同完整本地全市场历史库。`;
        APP.advisorContext.source = '策略池';
        AdvisorModule.autoSend(prompt);
    },

    openPoolModal() {
        this.draftPool = [...this.pool];
        this.renderPoolModal();
        const modal = document.getElementById('strategy-pool-modal');
        if (modal) modal.style.display = 'flex';
    },

    closePoolModal() {
        const modal = document.getElementById('strategy-pool-modal');
        if (modal) modal.style.display = 'none';
    },

    savePoolDraft() {
        this.pool = [...this.draftPool];
        this.savePool();
        this.closePoolModal();
        this.renderCards();
        this.results = {};
        this.setStatus(`策略池已更新为 ${this.pool.length} 个策略，点击“运行策略池”刷新命中。`);
    },

    renderPoolModal() {
        const map = this.strategyMap();
        const availableEl = document.getElementById('strategy-available-list');
        const selectedEl = document.getElementById('strategy-selected-list');
        if (!availableEl || !selectedEl) return;
        const available = this.strategies.filter(s => !this.draftPool.includes(s.id) && (this.activeSource === 'all' || s.source === this.activeSource));
        document.getElementById('strategy-modal-count').textContent = `${this.draftPool.filter(id => map.has(id)).length} / ${this.strategies.length}`;
        availableEl.innerHTML = available.length ? available.map(s => `
            <button class="strategy-pool-item" data-add="${escapeAttr(s.id)}">
                <span><b>${escapeHTML(s.name)}</b><small>${escapeHTML(s.description || '')}</small></span>
                <i>内置</i>
                <em>+</em>
            </button>
        `).join('') : '<div class="strategy-list-empty">全部已加入策略池</div>';
        selectedEl.innerHTML = this.draftPool.length ? this.draftPool.map((id, index) => {
            const s = map.get(id);
            return `
                <div class="strategy-pool-item selected" draggable="true" data-id="${escapeAttr(id)}">
                    <button class="strategy-drag-handle" title="拖拽排序">⋮⋮</button>
                    <span><b>${escapeHTML(s?.name || id)}</b><small>${escapeHTML(s?.description || '策略已失效')}</small></span>
                    <i>${s ? '内置' : '失效'}</i>
                    <button class="strategy-order-btn" data-move="${escapeAttr(id)}" data-dir="-1" ${index === 0 ? 'disabled' : ''}>↑</button>
                    <button class="strategy-order-btn" data-move="${escapeAttr(id)}" data-dir="1" ${index === this.draftPool.length - 1 ? 'disabled' : ''}>↓</button>
                    <button class="strategy-remove-btn" data-remove="${escapeAttr(id)}" title="移除">${Icons.close}</button>
                </div>
            `;
        }).join('') : '<div class="strategy-list-empty">从左侧点击策略添加</div>';

        availableEl.querySelectorAll('[data-add]').forEach(btn => {
            btn.addEventListener('click', () => {
                const id = btn.dataset.add;
                if (!this.draftPool.includes(id)) this.draftPool.push(id);
                this.renderPoolModal();
            });
        });
        selectedEl.querySelectorAll('[data-remove]').forEach(btn => {
            btn.addEventListener('click', () => {
                this.draftPool = this.draftPool.filter(id => id !== btn.dataset.remove);
                this.renderPoolModal();
            });
        });
        selectedEl.querySelectorAll('[data-move]').forEach(btn => {
            btn.addEventListener('click', () => {
                this.moveDraft(btn.dataset.move, parseInt(btn.dataset.dir || '0', 10));
                this.renderPoolModal();
            });
        });
        this.bindDragSort(selectedEl);
    },

    bindDragSort(container) {
        let dragging = null;
        container.querySelectorAll('.strategy-pool-item.selected').forEach(item => {
            item.addEventListener('dragstart', () => {
                dragging = item.dataset.id;
                item.classList.add('dragging');
            });
            item.addEventListener('dragend', () => {
                item.classList.remove('dragging');
                dragging = null;
            });
            item.addEventListener('dragover', e => e.preventDefault());
            item.addEventListener('drop', e => {
                e.preventDefault();
                const target = item.dataset.id;
                if (!dragging || dragging === target) return;
                const from = this.draftPool.indexOf(dragging);
                const to = this.draftPool.indexOf(target);
                if (from < 0 || to < 0) return;
                this.draftPool.splice(from, 1);
                this.draftPool.splice(to, 0, dragging);
                this.renderPoolModal();
            });
        });
    },

    moveDraft(id, dir) {
        const i = this.draftPool.indexOf(id);
        const j = i + dir;
        if (i < 0 || j < 0 || j >= this.draftPool.length) return;
        const copy = [...this.draftPool];
        [copy[i], copy[j]] = [copy[j], copy[i]];
        this.draftPool = copy;
    },

    metaText(strategyIds) {
        const ids = strategyIds || this.pool;
        const meta = this.meta || {};
        const parts = [];
        if (this.asOf) parts.push(`日期 ${this.asOf}`);
        parts.push(`${ids.length} 个策略`);
        if (meta.candidate_count != null) parts.push(`候选 ${meta.candidate_count}`);
        if (meta.hydrated_count != null) parts.push(`精算 ${meta.hydrated_count}`);
        if (meta.elapsed_ms != null) parts.push(`${meta.elapsed_ms} ms`);
        return parts.join(' · ');
    },

    updateCounts() {
        const count = document.getElementById('strategy-pool-count');
        if (count) count.textContent = `${this.pool.length}/${this.strategies.length}`;
    },

    setLoading(loading) {
        const el = document.getElementById('strategy-loading');
        if (el) el.style.display = loading ? 'flex' : 'none';
        document.getElementById('strategy-run-all-btn')?.toggleAttribute('disabled', loading);
    },

    setStatus(text) {
        const el = document.getElementById('strategy-status');
        if (el) el.innerHTML = `<span>${escapeHTML(text)}</span>`;
    },

    showError(text) {
        const el = document.getElementById('strategy-error');
        if (el) {
            el.textContent = text;
            el.style.display = 'block';
        }
    },

    hideError() {
        const el = document.getElementById('strategy-error');
        if (el) el.style.display = 'none';
    },

    fmt(value, digits = 2) {
        const n = Number(value);
        return Number.isFinite(n) ? n.toFixed(digits) : '-';
    },
};


})();

