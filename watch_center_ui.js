/**
 * watch_center_ui.js — 自选中心页面 + 快捷抽屉 UI 控制器
 *
 * 依赖：window.WatchCenter（存储）、window.ApiClient / AppBus / AppUtil（核心层）。
 * 通过 AppBus 事件与 main.js 业务模块解耦：
 *   watch-center:navigate  → main.js 监听并跳转股票工作台 / 基金分析
 *   watch-center:add-request → 请求打开添加对话框（走搜索选择）
 *   data-status:retry → 触发对应 scope 重刷
 *
 * 刷新协调（Phase 6）：
 *   批量报价 ≤20 码/批；同类并发 ≤2；相同参数在途复用（ApiClient dedupe）。
 *   页面/抽屉可见时按周期刷新，隐藏时暂停；恢复超周期立即补刷。
 *   价格快照仅内存，不写入自选存储。
 */
(function () {
  'use strict';

  const WC = window.WatchCenter;
  const Bus = window.AppBus;
  const Util = window.AppUtil;
  const Api = window.ApiClient;

  const STOCK_BATCH = 20;
  const FUND_BATCH = 20;
  const MAX_CONCURRENCY = 2;
  const PAGE_RENDER_LIMIT = 50;
  const DRAWER_RENDER_LIMIT = 30;

  // 内存价格快照：id -> { price, changePct, name, at, status, dataStatus, valueKind }
  const snapshot = new Map();

  // 页面 UI 状态
  const ui = {
    view: 'all',
    groupId: null,     // null=全部分组
    search: '',
    sort: 'manual',
    selection: new Set(),
  };
  let renderedIds = [];
  const drawerUi = { view: 'all', search: '', open: false, lastFocus: null };

  let stockTimer = null;
  let fundTimer = null;
  let lastStockRefreshAt = 0;
  let lastFundRefreshAt = 0;
  let activeTab = 'stock';
  let toastTimer = null;

  // ─────────────────────────────────────────────
  // 工具
  // ─────────────────────────────────────────────
  function $(sel, root) { return (root || document).querySelector(sel); }
  function $all(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }
  function fmtNum(v, digits) {
    if (v === null || v === undefined || v === '' || isNaN(Number(v))) return '—';
    return Number(v).toFixed(digits == null ? 2 : digits);
  }
  function fmtPct(v) {
    if (v === null || v === undefined || v === '' || isNaN(Number(v))) return '—';
    const n = Number(v);
    return (n > 0 ? '+' : '') + n.toFixed(2) + '%';
  }
  function pctClass(v) {
    if (v === null || v === undefined || v === '' || isNaN(Number(v))) return 'flat';
    const n = Number(v);
    return n > 0 ? 'up' : (n < 0 ? 'down' : 'flat');
  }
  function nowTimeStr() {
    const d = new Date();
    const p = (n) => (n < 10 ? '0' + n : '' + n);
    return p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds());
  }

  function showToast(message, kind) {
    const host = $('#wc-feedback');
    if (!host) return;
    if (toastTimer) { clearTimeout(toastTimer); toastTimer = null; }
    host.className = 'wc-feedback show ' + (kind || 'success');
    host.innerHTML = '<span class="wc-feedback-mark" aria-hidden="true">'
      + (kind === 'warning' ? '!' : (kind === 'error' ? '×' : '✓'))
      + '</span><span>' + esc(message) + '</span>';
    toastTimer = setTimeout(() => {
      host.classList.remove('show');
      toastTimer = null;
    }, 2600);
  }

  function clearSelection() {
    ui.selection.clear();
    $all('#wc-list .wc-row').forEach((row) => {
      row.classList.remove('selected');
      const input = $('.wc-row-check input', row);
      if (input) input.checked = false;
    });
    updateBulkBar();
  }

  function setView(view) {
    const allowed = ['all', 'stock', 'fund', 'monitor', 'ungrouped'];
    ui.view = allowed.indexOf(view) !== -1 ? view : 'all';
    if (ui.view !== 'all') ui.groupId = null;
    clearSelection();
    $all('.wc-view').forEach((b) => {
      const active = b.getAttribute('data-view') === ui.view;
      b.classList.toggle('active', active);
      b.setAttribute('aria-selected', active ? 'true' : 'false');
    });
    $all('[data-quick-view]').forEach((b) => {
      const active = b.getAttribute('data-quick-view') === ui.view;
      b.classList.toggle('active', active);
      b.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
    renderGroups();
    renderList();
  }

  // ─────────────────────────────────────────────
  // 过滤 / 排序
  // ─────────────────────────────────────────────
  function applyFilters(items, view, groupId, search) {
    let list = items.slice();
    // 系统视图
    if (view === 'stock') list = list.filter((it) => it.type === 'stock');
    else if (view === 'fund') list = list.filter((it) => it.type === 'fund');
    else if (view === 'monitor') list = list.filter((it) => it.type === 'stock' && it.monitor);
    else if (view === 'ungrouped') list = list.filter((it) => it.groupId === WC.DEFAULT_GROUP_ID);
    // 用户分组（仅在 all 视图下叠加）
    if (groupId) list = list.filter((it) => it.groupId === groupId);
    // 搜索
    const q = (search || '').trim().toLowerCase();
    if (q) {
      list = list.filter((it) => {
        return (it.name || '').toLowerCase().indexOf(q) !== -1
          || (it.code || '').toLowerCase().indexOf(q) !== -1
          || (it.tags || []).some((t) => t.toLowerCase().indexOf(q) !== -1)
          || (it.note || '').toLowerCase().indexOf(q) !== -1;
      });
    }
    return list;
  }

  function sortItems(list, mode) {
    const arr = list.slice();
    if (mode === 'name') {
      arr.sort((a, b) => (a.name || '').localeCompare(b.name || '', 'zh'));
    } else if (mode === 'change') {
      arr.sort((a, b) => (snapChange(b) - snapChange(a)));
    } else if (mode === 'recent') {
      arr.sort((a, b) => (b.createdAt || '').localeCompare(a.createdAt || ''));
    } else {
      // manual：置顶优先 + sortOrder
      arr.sort((a, b) => {
        if (!!b.pinned !== !!a.pinned) return b.pinned ? 1 : -1;
        return (a.sortOrder || 0) - (b.sortOrder || 0);
      });
    }
    return arr;
  }
  function snapChange(it) {
    const s = snapshot.get(it.id);
    return s && s.changePct != null && !isNaN(Number(s.changePct)) ? Number(s.changePct) : -Infinity;
  }

  // ─────────────────────────────────────────────
  // 页面渲染
  // ─────────────────────────────────────────────
  function renderPage(refreshQuotes) {
    const root = $('#watch-center');
    if (!root) return;

    // 统计
    const items = WC.getItems();
    const counts = {
      all: items.length,
      stock: items.filter((it) => it.type === 'stock').length,
      fund: items.filter((it) => it.type === 'fund').length,
      monitor: items.filter((it) => it.type === 'stock' && it.monitor).length,
      ungrouped: items.filter((it) => it.groupId === WC.DEFAULT_GROUP_ID).length,
    };
    $('#wc-stat-total').textContent = counts.all;
    $('#wc-stat-stock').textContent = counts.stock;
    $('#wc-stat-fund').textContent = counts.fund;
    $('#wc-stat-monitor').textContent = counts.monitor;
    Object.keys(counts).forEach((key) => {
      const el = $('[data-view-count="' + key + '"]');
      if (el) el.textContent = counts[key];
    });
    $all('[data-quick-view]').forEach((b) => {
      const active = b.getAttribute('data-quick-view') === ui.view;
      b.classList.toggle('active', active);
      b.setAttribute('aria-pressed', active ? 'true' : 'false');
    });

    renderGroups();
    renderList(refreshQuotes);
    updateWatchCounts();
  }

  function renderGroups() {
    const el = $('#wc-groups');
    if (!el) return;
    const groups = WC.getGroups();
    const items = WC.getItems();
    const countIn = (gid) => items.filter((it) => it.groupId === gid).length;
    const groupFiltering = ui.view === 'all';
    let html = '<div class="wc-groups-title"><span>我的分组</span>'
      + (groupFiltering ? '<small>按分组筛选</small>' : '<small>切回“全部”后可用</small>') + '</div>';
    html += '<button class="wc-group-item' + (ui.groupId === null && groupFiltering ? ' active' : '') + '" data-group=""' + (groupFiltering ? '' : ' disabled') + '>'
      + '全部分组<span class="wc-group-count">' + items.length + '</span></button>';
    groups.forEach((g) => {
      html += '<button class="wc-group-item' + (ui.groupId === g.id && groupFiltering ? ' active' : '') + '" data-group="' + esc(g.id) + '"' + (groupFiltering ? '' : ' disabled') + '>'
        + esc(g.name) + '<span class="wc-group-count">' + countIn(g.id) + '</span></button>';
    });
    html += '<button class="wc-group-add" id="wc-group-add">+ 新建分组</button>';
    el.innerHTML = html;

    $all('.wc-group-item', el).forEach((btn) => {
      btn.addEventListener('click', () => {
        ui.groupId = btn.getAttribute('data-group') || null;
        clearSelection();
        renderGroups(); renderList();
      });
      btn.addEventListener('contextmenu', (e) => {
        const gid = btn.getAttribute('data-group');
        if (!gid || gid === WC.DEFAULT_GROUP_ID) return;
        e.preventDefault();
        groupContextMenu(gid);
      });
    });
    const addBtn = $('#wc-group-add', el);
    if (addBtn) addBtn.addEventListener('click', () => {
      const name = window.prompt('新分组名称（最多20字）');
      if (name && name.trim()) { WC.createGroup(name.trim()); }
    });
  }

  function groupContextMenu(gid) {
    const g = WC.getGroups().find((x) => x.id === gid);
    if (!g) return;
    const action = window.prompt('分组「' + g.name + '」：输入 r 重命名，d 删除', '');
    if (action === 'r') {
      const name = window.prompt('新名称', g.name);
      if (name && name.trim()) WC.renameGroup(gid, name.trim());
    } else if (action === 'd') {
      if (window.confirm('删除分组「' + g.name + '」？其项目将移入默认分组')) {
        WC.deleteGroup(gid);
        if (ui.groupId === gid) ui.groupId = null;
      }
    }
  }

  function renderList(refreshQuotes) {
    const el = $('#wc-list');
    if (!el) return;
    const all = WC.getItems();
    let list = applyFilters(all, ui.view, ui.groupId, ui.search);
    list = sortItems(list, ui.sort);
    const summary = $('#wc-result-summary');
    if (summary) summary.textContent = list.length + ' 项资产' + (ui.search ? ' · 搜索结果' : '');

    if (all.length === 0) {
      renderedIds = [];
      updateBulkBar();
      el.innerHTML = emptyState('empty-all');
      wireEmptyState(el);
      return;
    }
    if (list.length === 0) {
      renderedIds = [];
      updateBulkBar();
      el.innerHTML = emptyState(ui.view === 'monitor' ? 'empty-monitor' : 'empty-filter');
      wireEmptyState(el);
      return;
    }

    const limited = list.slice(0, PAGE_RENDER_LIMIT);
    renderedIds = limited.map((it) => it.id);
    updateBulkBar();
    const groupName = new Map(WC.getGroups().map((g) => [g.id, g.name]));
    el.innerHTML = '<div class="wc-column-head" aria-hidden="true"><span></span><span>资产</span><span>最新数据</span><span>涨跌</span><span>分组与标签</span><span>监控状态</span><span>更新时间</span><span>操作</span></div>'
      + limited.map((it) => rowHtml(it, groupName)).join('')
      + (list.length > PAGE_RENDER_LIMIT
          ? '<div class="wc-truncated">仅显示前 ' + PAGE_RENDER_LIMIT + ' 项（共 ' + list.length + '），请用搜索或分组缩小范围</div>'
          : '');

    wireRows(el);
    // 触发可见项刷新
    if (refreshQuotes !== false) refreshVisible(limited);
  }

  function rowHtml(it, groupName) {
    const s = snapshot.get(it.id) || {};
    const priceVal = it.type === 'fund' ? s.price : s.price;
    const priceLabel = it.type === 'fund' ? (s.valueKind === 'nav' ? '净值' : '估值') : '现价';
    const selected = ui.selection.has(it.id);
    const failed = s.status === 'error';
    const priceStr = failed ? '<span class="wc-cell-fail" title="' + esc(s.message || '获取失败') + '">失败</span>'
      : (priceVal == null ? '—' : fmtNum(priceVal, it.type === 'fund' ? 4 : 2));
    const pctStr = failed ? '—' : fmtPct(s.changePct);
    const pcls = pctClass(s.changePct);
    const tags = (it.tags || []).map((t) => '<span class="wc-tag">' + esc(t) + '</span>').join('');
    return (
      '<div class="wc-row' + (selected ? ' selected' : '') + '" data-id="' + esc(it.id) + '" data-type="' + it.type + '">' +
        '<label class="wc-row-check"><input type="checkbox" ' + (selected ? 'checked' : '') + ' aria-label="选择' + esc(it.name || it.code) + '"></label>' +
        '<button class="wc-pin' + (it.pinned ? ' on' : '') + '" title="置顶" aria-label="置顶">★</button>' +
        '<div class="wc-asset" role="button" tabindex="0">' +
          '<span class="wc-name">' + esc(it.name || it.code) + '</span>' +
          '<span class="wc-code">' + esc(it.type === 'stock' ? it.code : it.code) + '</span>' +
        '</div>' +
        '<div class="wc-price"><span class="wc-price-label">' + priceLabel + '</span>' + priceStr + '</div>' +
        '<div class="wc-change ' + pcls + '">' + pctStr + '</div>' +
        '<div class="wc-group-tag">' + esc(groupName.get(it.groupId) || '默认分组') + tags + '</div>' +
        (it.type === 'stock'
          ? '<button class="wc-monitor' + (it.monitor ? ' on' : '') + '" type="button" aria-pressed="' + (it.monitor ? 'true' : 'false') + '" title="' + (it.monitor ? '关闭自动刷新' : '开启每30秒自动刷新') + '" aria-label="' + (it.monitor ? '关闭' : '开启') + esc(it.name || it.code) + '自动刷新"><span class="wc-monitor-dot" aria-hidden="true"></span>' + (it.monitor ? '自动刷新中' : '开启监控') + '</button>'
          : '<span class="wc-monitor-na" title="基金在页面可见时每60秒刷新">普通刷新</span>') +
        '<div class="wc-updated">' + (s.at ? s.at : '—') + '</div>' +
        '<div class="wc-ops">' +
          '<button class="wc-op wc-op-edit" title="编辑">编辑</button>' +
          '<button class="wc-op wc-op-remove" title="移除">移除</button>' +
        '</div>' +
      '</div>'
    );
  }

  function wireRows(el) {
    $all('.wc-row', el).forEach((row) => {
      const id = row.getAttribute('data-id');
      const it = WC.getItem(id);
      if (!it) return;
      const check = $('.wc-row-check input', row);
      if (check) check.addEventListener('change', () => {
        if (check.checked) ui.selection.add(id); else ui.selection.delete(id);
        row.classList.toggle('selected', check.checked);
        updateBulkBar();
      });
      $('.wc-pin', row).addEventListener('click', (e) => { e.stopPropagation(); WC.togglePin(id); });
      const asset = $('.wc-asset', row);
      const nav = () => Bus.emit('watch-center:navigate', { type: it.type, code: it.code, name: it.name });
      asset.addEventListener('click', nav);
      asset.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); nav(); } });
      const mon = $('.wc-monitor', row);
      if (mon) mon.addEventListener('click', (e) => {
        e.stopPropagation();
        const enabled = WC.toggleMonitor(id);
        showToast('已' + (enabled ? '开启' : '关闭') + '「' + (it.name || it.code) + '」自动刷新', 'success');
      });
      $('.wc-op-edit', row).addEventListener('click', (e) => { e.stopPropagation(); openEditDialog(id); });
      $('.wc-op-remove', row).addEventListener('click', (e) => {
        e.stopPropagation();
        if (window.confirm('移除「' + (it.name || it.code) + '」？')) {
          WC.removeItem(id);
          showToast('已从自选中移除「' + (it.name || it.code) + '」', 'success');
        }
      });
    });
  }

  function emptyState(kind) {
    if (kind === 'empty-all') {
      return '<div class="wc-empty"><p>自选列表为空</p><button class="btn-sm btn-accent" data-empty-add>添加第一个资产</button></div>';
    }
    if (kind === 'empty-filter') {
      return '<div class="wc-empty"><p>没有匹配的自选项</p><button class="btn-sm" data-empty-clear>清除筛选</button></div>';
    }
    if (kind === 'empty-monitor') {
      return '<div class="wc-empty wc-empty-monitor"><span class="wc-empty-icon" aria-hidden="true">◉</span><div><b>还没有自动刷新的股票</b><p>在“全部”或“股票”视图中点击“开启监控”，行情会在页面可见时每 30 秒刷新。</p></div><button class="btn-sm" data-empty-stock>查看股票</button></div>';
    }
    if (kind === 'all-failed') {
      return '<div class="wc-empty wc-empty-error"><p>全部行情请求失败</p><button class="btn-sm" data-empty-retry>重试</button></div>';
    }
    return '<div class="wc-empty"><p>暂无数据</p></div>';
  }
  function wireEmptyState(el) {
    const add = $('[data-empty-add]', el);
    if (add) add.addEventListener('click', () => Bus.emit('watch-center:add-request', {}));
    const clear = $('[data-empty-clear]', el);
    if (clear) clear.addEventListener('click', () => {
      ui.search = ''; ui.view = 'all'; ui.groupId = null;
      const si = $('#wc-search'); if (si) si.value = '';
      $all('.wc-view').forEach((b) => b.classList.toggle('active', b.getAttribute('data-view') === 'all'));
      renderGroups(); renderList();
    });
    const retry = $('[data-empty-retry]', el);
    if (retry) retry.addEventListener('click', () => renderList());
    const stock = $('[data-empty-stock]', el);
    if (stock) stock.addEventListener('click', () => setView('stock'));
  }

  // ── 批量条 ──
  function updateBulkBar() {
    const bar = $('#wc-bulkbar');
    if (!bar) return;
    Array.from(ui.selection).forEach((id) => { if (!WC.getItem(id)) ui.selection.delete(id); });
    const n = ui.selection.size;
    bar.hidden = n === 0;
    const c = $('#wc-bulk-count');
    if (c) c.textContent = '已选 ' + n + ' 项';
    const selectedItems = Array.from(ui.selection).map((id) => WC.getItem(id)).filter(Boolean);
    const stocks = selectedItems.filter((it) => it.type === 'stock');
    const funds = selectedItems.filter((it) => it.type === 'fund');
    const eligible = $('#wc-bulk-eligible');
    if (eligible) eligible.textContent = stocks.length
      ? ('其中 ' + stocks.length + ' 只股票可设置监控' + (funds.length ? '，' + funds.length + ' 只基金将忽略' : ''))
      : (n ? '所选均为基金，不支持股票监控' : '');
    const onBtn = $('[data-bulk="monitor-on"]', bar);
    const offBtn = $('[data-bulk="monitor-off"]', bar);
    if (onBtn) onBtn.disabled = !stocks.some((it) => !it.monitor);
    if (offBtn) offBtn.disabled = !stocks.some((it) => it.monitor);

    const selectAll = $('#wc-select-all');
    if (selectAll) {
      const selectedVisible = renderedIds.filter((id) => ui.selection.has(id)).length;
      selectAll.checked = renderedIds.length > 0 && selectedVisible === renderedIds.length;
      selectAll.indeterminate = selectedVisible > 0 && selectedVisible < renderedIds.length;
      selectAll.disabled = renderedIds.length === 0;
    }
  }

  function wireBulkBar() {
    const bar = $('#wc-bulkbar');
    if (!bar) return;
    bar.addEventListener('click', (e) => {
      const btn = e.target.closest('[data-bulk]');
      if (!btn) return;
      const ids = Array.from(ui.selection);
      const action = btn.getAttribute('data-bulk');
      if (!ids.length && action !== 'clear') {
        showToast('请先勾选要操作的资产', 'warning');
        return;
      }
      if (action === 'group') {
        const groups = WC.getGroups();
        const name = window.prompt('移动到分组（输入名称，留空=默认分组）：\n' + groups.map((g) => g.name).join(' / '), '');
        if (name === null) return;
        const g = groups.find((x) => x.name === name.trim());
        const gid = g ? g.id : (name.trim() ? (WC.createGroup(name.trim()) || {}).id : WC.DEFAULT_GROUP_ID);
        WC.bulkUpdate(ids, { groupId: gid });
        showToast('已移动 ' + ids.length + ' 项资产', 'success');
      } else if (action === 'tag') {
        const tag = window.prompt('添加标签（最多20字）');
        if (tag && tag.trim()) {
          WC.bulkUpdate(ids, { addTag: tag.trim() });
          showToast('已为 ' + ids.length + ' 项资产添加标签', 'success');
        } else {
          return;
        }
      } else if (action === 'monitor-on') {
        const targets = ids.filter((id) => { const it = WC.getItem(id); return it && it.type === 'stock' && !it.monitor; });
        if (!targets.length) { showToast('所选资产中没有可开启监控的股票', 'warning'); return; }
        WC.bulkUpdate(targets, { monitor: true });
        showToast('已为 ' + targets.length + ' 只股票开启自动刷新', 'success');
      } else if (action === 'monitor-off') {
        const targets = ids.filter((id) => { const it = WC.getItem(id); return it && it.type === 'stock' && it.monitor; });
        if (!targets.length) { showToast('所选资产中没有正在监控的股票', 'warning'); return; }
        WC.bulkUpdate(targets, { monitor: false });
        showToast('已关闭 ' + targets.length + ' 只股票的自动刷新', 'success');
      } else if (action === 'remove') {
        if (!window.confirm('删除选中 ' + ids.length + ' 项？')) return;
        const removed = WC.bulkRemove(ids);
        showToast('已移除 ' + removed + ' 项资产', 'success');
      } else if (action === 'clear') {
        clearSelection();
        return;
      }
      clearSelection();
    });
  }

  // ─────────────────────────────────────────────
  // 编辑对话框
  // ─────────────────────────────────────────────
  let dialogEl = null;
  function ensureDialog() {
    if (dialogEl) return dialogEl;
    dialogEl = document.createElement('div');
    dialogEl.className = 'wc-dialog-overlay';
    dialogEl.id = 'wc-dialog-overlay';
    document.body.appendChild(dialogEl);
    dialogEl.addEventListener('click', (e) => { if (e.target === dialogEl) closeDialog(); });
    return dialogEl;
  }
  function closeDialog() { if (dialogEl) dialogEl.classList.remove('open'); }

  function openEditDialog(id) {
    const it = WC.getItem(id);
    if (!it) return;
    const el = ensureDialog();
    const groups = WC.getGroups();
    el.innerHTML =
      '<div class="wc-dialog" role="dialog" aria-label="编辑自选">' +
        '<div class="wc-dialog-head"><span>编辑 ' + esc(it.name || it.code) + '</span><button class="wc-dialog-close" aria-label="关闭">✕</button></div>' +
        '<div class="wc-dialog-body">' +
          '<label>名称<input type="text" id="wcd-name" value="' + esc(it.name || '') + '" maxlength="40"></label>' +
          '<label>分组<select id="wcd-group">' +
            groups.map((g) => '<option value="' + esc(g.id) + '"' + (g.id === it.groupId ? ' selected' : '') + '>' + esc(g.name) + '</option>').join('') +
          '</select></label>' +
          '<label>标签（逗号分隔，最多10个）<input type="text" id="wcd-tags" value="' + esc((it.tags || []).join(',')) + '"></label>' +
          '<label>备注<textarea id="wcd-note" maxlength="500">' + esc(it.note || '') + '</textarea></label>' +
          (it.type === 'stock' ? '<label class="wc-check-label"><input type="checkbox" id="wcd-monitor"' + (it.monitor ? ' checked' : '') + '> 开启每 30 秒自动刷新</label>' : '') +
        '</div>' +
        '<div class="wc-dialog-foot"><button class="btn-sm" id="wcd-cancel">取消</button><button class="btn-sm btn-accent" id="wcd-save">保存</button></div>' +
      '</div>';
    el.classList.add('open');
    $('.wc-dialog-close', el).addEventListener('click', closeDialog);
    $('#wcd-cancel', el).addEventListener('click', closeDialog);
    $('#wcd-save', el).addEventListener('click', () => {
      const patch = {
        name: $('#wcd-name', el).value.trim(),
        groupId: $('#wcd-group', el).value,
        tags: $('#wcd-tags', el).value.split(',').map((s) => s.trim()).filter(Boolean),
        note: $('#wcd-note', el).value,
      };
      const mon = $('#wcd-monitor', el);
      if (mon) patch.monitor = mon.checked;
      WC.updateItem(id, patch);
      closeDialog();
      showToast('已保存「' + (patch.name || it.code) + '」的设置', 'success');
    });
  }

  // ── 添加对话框（走搜索选择） ──
  function openAddDialog() {
    const el = ensureDialog();
    el.innerHTML =
      '<div class="wc-dialog" role="dialog" aria-label="添加资产">' +
        '<div class="wc-dialog-head"><span>添加资产</span><button class="wc-dialog-close" aria-label="关闭">✕</button></div>' +
        '<div class="wc-dialog-body">' +
          '<div class="wc-add-tabs"><button class="wc-add-tab active" data-t="stock">股票</button><button class="wc-add-tab" data-t="fund">基金</button></div>' +
          '<input type="search" id="wca-search" placeholder="输入名称或代码搜索" autocapitalize="off" spellcheck="false">' +
          '<div class="wc-add-results" id="wca-results"><p class="placeholder-text">输入关键词搜索后选择</p></div>' +
        '</div>' +
      '</div>';
    el.classList.add('open');
    let addType = 'stock';
    $('.wc-dialog-close', el).addEventListener('click', closeDialog);
    $all('.wc-add-tab', el).forEach((tab) => tab.addEventListener('click', () => {
      addType = tab.getAttribute('data-t');
      $all('.wc-add-tab', el).forEach((t) => t.classList.toggle('active', t === tab));
      doAddSearch($('#wca-search', el).value);
    }));
    const doAddSearch = Util.debounce((kw) => {
      if (!kw || !kw.trim()) { $('#wca-results', el).innerHTML = '<p class="placeholder-text">输入关键词搜索后选择</p>'; return; }
      const url = addType === 'stock'
        ? 'stock_search_api.php?key=' + encodeURIComponent(kw) + '&limit=10'
        : 'fund_search_api.php?key=' + encodeURIComponent(kw);
      Api.request(url, { scope: 'watch-center:add-search', silent: true, dedupeKey: 'wca:' + addType + ':' + kw })
        .then((r) => {
          if (!r.ok || !Array.isArray(r.data) || !r.data.length) {
            $('#wca-results', el).innerHTML = '<p class="placeholder-text">无结果</p>';
            return;
          }
          $('#wca-results', el).innerHTML = r.data.map((row) => {
            const code = addType === 'stock' ? (row.symbol || row.code) : row.code;
            const name = row.name || code;
            return '<button class="wc-add-result" data-code="' + esc(code) + '" data-name="' + esc(name) + '">'
              + '<span>' + esc(name) + '</span><span class="wc-add-code">' + esc(code) + '</span></button>';
          }).join('');
          $all('.wc-add-result', el).forEach((btn) => btn.addEventListener('click', () => {
            const assetName = btn.getAttribute('data-name');
            const res = WC.addItem(addType, btn.getAttribute('data-code'), assetName);
            if (res.ok) {
              closeDialog();
              showToast(res.existed ? ('「' + assetName + '」已在自选中') : ('已添加「' + assetName + '」'), res.existed ? 'warning' : 'success');
            }
            else if (res.message) window.alert(res.message);
          }));
        });
    }, 250);
    $('#wca-search', el).addEventListener('input', (e) => doAddSearch(e.target.value));
    setTimeout(() => { const s = $('#wca-search', el); if (s) s.focus(); }, 30);
  }

  // ─────────────────────────────────────────────
  // 快捷抽屉
  // ─────────────────────────────────────────────
  function openDrawer() {
    const d = $('#watch-drawer');
    const ov = $('#watch-drawer-overlay');
    if (!d) return;
    drawerUi.lastFocus = document.activeElement;
    d.classList.add('open');
    d.setAttribute('aria-hidden', 'false');
    if (ov) ov.classList.add('open');
    document.body.classList.add('watch-drawer-open');
    drawerUi.open = true;
    renderDrawer();
    Bus.emit('overlay:opened', { id: 'watch-drawer' });
    const s = $('#wd-search'); if (s) setTimeout(() => s.focus(), 30);
  }
  function closeDrawer() {
    const d = $('#watch-drawer');
    const ov = $('#watch-drawer-overlay');
    if (d) { d.classList.remove('open'); d.setAttribute('aria-hidden', 'true'); }
    if (ov) ov.classList.remove('open');
    document.body.classList.remove('watch-drawer-open');
    drawerUi.open = false;
    Bus.emit('overlay:closed', { id: 'watch-drawer' });
    if (drawerUi.lastFocus && drawerUi.lastFocus.focus) drawerUi.lastFocus.focus();
  }
  function toggleDrawer() { drawerUi.open ? closeDrawer() : openDrawer(); }

  function renderDrawer(refreshQuotes) {
    const el = $('#wd-items');
    if (!el) return;
    let list = applyFilters(WC.getItems(), drawerUi.view, null, drawerUi.search);
    // 抽屉优先展示置顶 + 监控
    list.sort((a, b) => {
      const pa = (a.pinned ? 2 : 0) + (a.monitor ? 1 : 0);
      const pb = (b.pinned ? 2 : 0) + (b.monitor ? 1 : 0);
      if (pb !== pa) return pb - pa;
      return (a.sortOrder || 0) - (b.sortOrder || 0);
    });
    if (!list.length) {
      el.innerHTML = '<p class="placeholder-text">暂无自选</p>';
      return;
    }
    const limited = list.slice(0, DRAWER_RENDER_LIMIT);
    el.innerHTML = limited.map((it) => {
      const s = snapshot.get(it.id) || {};
      const failed = s.status === 'error';
      const price = failed ? '失败' : (s.price == null ? '—' : fmtNum(s.price, it.type === 'fund' ? 4 : 2));
      const pct = failed ? '—' : fmtPct(s.changePct);
      return '<div class="wd-item" data-id="' + esc(it.id) + '">' +
        '<div class="wd-item-main" role="button" tabindex="0">' +
          '<span class="wd-item-name">' + esc(it.name || it.code) + (it.monitor ? ' <span class="wd-dot" title="监控中">●</span>' : '') + '</span>' +
          '<span class="wd-item-code">' + esc(it.code) + '</span>' +
        '</div>' +
        '<div class="wd-item-quote"><span class="wd-item-price">' + price + '</span>' +
          '<span class="wd-item-pct ' + pctClass(s.changePct) + '">' + pct + '</span></div>' +
        '<button class="wd-item-remove" aria-label="移除">✕</button>' +
      '</div>';
    }).join('') + (list.length > DRAWER_RENDER_LIMIT ? '<div class="wc-truncated">仅显示前 ' + DRAWER_RENDER_LIMIT + ' 项，完整管理请进入自选中心</div>' : '');

    $all('.wd-item', el).forEach((row) => {
      const id = row.getAttribute('data-id');
      const it = WC.getItem(id);
      if (!it) return;
      const main = $('.wd-item-main', row);
      const nav = () => { Bus.emit('watch-center:navigate', { type: it.type, code: it.code, name: it.name }); closeDrawer(); };
      main.addEventListener('click', nav);
      main.addEventListener('keydown', (e) => { if (e.key === 'Enter') nav(); });
      $('.wd-item-remove', row).addEventListener('click', (e) => { e.stopPropagation(); WC.removeItem(id); });
    });
    if (refreshQuotes !== false) refreshVisible(limited);
  }

  // ─────────────────────────────────────────────
  // 刷新协调
  // ─────────────────────────────────────────────
  function chunk(arr, size) {
    const out = [];
    for (let i = 0; i < arr.length; i += size) out.push(arr.slice(i, i + size));
    return out;
  }

  /** 刷新给定项目的行情/估值（成功即更新，失败保留旧值并标失败） */
  function refreshItems(items) {
    const stocks = items.filter((it) => it.type === 'stock');
    const funds = items.filter((it) => it.type === 'fund');
    if (stocks.length) refreshStockBatches(stocks);
    if (funds.length) refreshFundBatches(funds);
  }

  function refreshVisible(items) {
    // 去重：仅刷新尚无新鲜快照或超过阈值的
    refreshItems(items);
  }

  let stockConcurrency = 0;
  const stockQueue = [];
  function refreshStockBatches(stocks) {
    const batches = chunk(stocks.map((it) => it.code), STOCK_BATCH);
    batches.forEach((codes) => stockQueue.push(codes));
    pumpStockQueue();
  }
  function pumpStockQueue() {
    while (stockConcurrency < MAX_CONCURRENCY && stockQueue.length) {
      const codes = stockQueue.shift();
      stockConcurrency++;
      const url = 'stock_quote_api.php?codes=' + encodeURIComponent(codes.join(','));
      Api.request(url, {
        scope: 'watch:stock-quote',
        label: '自选股票行情',
        page: currentScopePage(),
        dedupeKey: 'watch-quote:' + codes.join(','),
      }).then((r) => {
        applyStockResult(codes, r);
      }).finally(() => {
        stockConcurrency--;
        pumpStockQueue();
      });
    }
    lastStockRefreshAt = Date.now();
  }

  function applyStockResult(codes, r) {
    const returned = new Set();
    const resolvedNames = [];
    if (r.ok && Array.isArray(r.data)) {
      r.data.forEach((q) => {
        const code = Util.normalizeStockCode(q.symbol || q.code || '');
        if (!code) return;
        returned.add(code);
        const id = 'stock:' + code;
        snapshot.set(id, {
          price: q.price, changePct: q.change_pct, name: q.name,
          at: nowTimeStr(), status: 'ok', dataStatus: r.dataStatus,
        });
        resolvedNames.push({ type: 'stock', code, name: q.name });
      });
    }
    // 未返回的标失败（保留旧价，仅置状态）
    codes.forEach((c) => {
      const code = Util.normalizeStockCode(c);
      const id = 'stock:' + code;
      if (!returned.has(code)) {
        const prev = snapshot.get(id) || {};
        snapshot.set(id, Object.assign({}, prev, {
          status: r.ok ? 'error' : 'error',
          message: r.message || '未返回',
          at: prev.at || null,
        }));
      }
    });
    WC.resolveNames(resolvedNames);
    refreshRenderThrottled();
  }

  let fundConcurrency = 0;
  const fundQueue = [];
  function refreshFundBatches(funds) {
    const batches = chunk(funds.map((it) => it.code), FUND_BATCH);
    batches.forEach((codes) => fundQueue.push(codes));
    pumpFundQueue();
  }
  function pumpFundQueue() {
    while (fundConcurrency < MAX_CONCURRENCY && fundQueue.length) {
      const codes = fundQueue.shift();
      fundConcurrency++;
      const url = 'fund_estimate_api.php?codes=' + encodeURIComponent(codes.join(','));
      Api.request(url, {
        scope: 'watch:fund-estimate',
        label: '自选基金估值',
        page: currentScopePage(),
        dedupeKey: 'watch-fund:' + codes.join(','),
      }).then((r) => {
        applyFundResult(codes, r);
      }).finally(() => {
        fundConcurrency--;
        pumpFundQueue();
      });
    }
    lastFundRefreshAt = Date.now();
  }

  function applyFundResult(codes, r) {
    const returned = new Set();
    const resolvedNames = [];
    // envelope 下 data 为 code=>item 映射；旧格式为数组
    const rows = [];
    if (r.ok && r.data) {
      if (Array.isArray(r.data)) rows.push.apply(rows, r.data);
      else Object.keys(r.data).forEach((k) => { if (r.data[k]) rows.push(r.data[k]); });
    }
    rows.forEach((f) => {
      const code = Util.normalizeFundCode(f.fundcode || f.code || '');
      if (!code) return;
      returned.add(code);
      const id = 'fund:' + code;
      snapshot.set(id, {
        price: f.gsz != null ? f.gsz : f.dwjz, changePct: f.gszzl, name: f.name,
        at: nowTimeStr(), status: 'ok', dataStatus: r.dataStatus,
        valueKind: (f.estimate_available === false || f.quote_type === 'latest_nav') ? 'nav' : 'estimate',
      });
      resolvedNames.push({ type: 'fund', code, name: f.name });
    });
    codes.forEach((c) => {
      const code = Util.normalizeFundCode(c);
      const id = 'fund:' + code;
      if (!returned.has(code)) {
        const prev = snapshot.get(id) || {};
        snapshot.set(id, Object.assign({}, prev, { status: 'error', message: r.message || '未返回', at: prev.at || null }));
      }
    });
    WC.resolveNames(resolvedNames);
    refreshRenderThrottled();
  }

  const refreshRenderThrottled = Util.throttle(() => {
    $('#wc-stat-refresh') && ($('#wc-stat-refresh').textContent = nowTimeStr());
    if (isPageActive()) updateRowSnapshots();
    if (drawerUi.open) renderDrawer();
  }, 400);

  /** 只更新价格单元格，避免整表重绘打断交互 */
  function updateRowSnapshots() {
    $all('#wc-list .wc-row').forEach((row) => {
      const id = row.getAttribute('data-id');
      const it = WC.getItem(id);
      const s = snapshot.get(id);
      if (!it || !s) return;
      const failed = s.status === 'error';
      const priceEl = $('.wc-price', row);
      const changeEl = $('.wc-change', row);
      const upd = $('.wc-updated', row);
      if (priceEl) {
        const label = it.type === 'fund' ? (s.valueKind === 'nav' ? '净值' : '估值') : '现价';
        priceEl.innerHTML = '<span class="wc-price-label">' + label + '</span>' +
          (failed ? '<span class="wc-cell-fail">失败</span>' : (s.price == null ? '—' : fmtNum(s.price, it.type === 'fund' ? 4 : 2)));
      }
      if (changeEl) {
        changeEl.className = 'wc-change ' + pctClass(s.changePct);
        changeEl.textContent = failed ? '—' : fmtPct(s.changePct);
      }
      if (upd && s.at) upd.textContent = s.at;
    });
  }

  // ── 定时器与可见性 ──
  function currentScopePage() {
    // 供状态聚合归属：抽屉打开时归 watch-drawer，否则归当前页 tab（自选中心 tab 名为 realtime）
    return drawerUi.open ? 'watch-drawer' : activeTab;
  }
  function isPageActive() { return activeTab === 'realtime'; }

  function startTimers() {
    stopTimers();
    const settings = WC.getSettings();
    const stockMs = Math.max(10, Number(settings.stockRefreshSeconds) || 30) * 1000;
    const fundMs = Math.max(30, Number(settings.fundRefreshSeconds) || 60) * 1000;
    stockTimer = setInterval(() => {
      if (!Util.isPageVisible()) return;
      if (!isPageActive() && !drawerUi.open) {
        // 页面/抽屉都未打开：仅刷新监控项
        const monitors = WC.getItems().filter((it) => it.type === 'stock' && it.monitor);
        if (monitors.length) refreshItems(monitors);
        return;
      }
      refreshCurrentScope();
    }, stockMs);
    fundTimer = setInterval(() => {
      if (!Util.isPageVisible()) return;
      if (!isPageActive() && !drawerUi.open) return;
      const funds = currentScopeItems().filter((it) => it.type === 'fund');
      if (funds.length) refreshFundBatches(funds);
    }, fundMs);
  }
  function stopTimers() {
    if (stockTimer) { clearInterval(stockTimer); stockTimer = null; }
    if (fundTimer) { clearInterval(fundTimer); fundTimer = null; }
  }

  function currentScopeItems() {
    if (drawerUi.open) {
      return applyFilters(WC.getItems(), drawerUi.view, null, drawerUi.search).slice(0, DRAWER_RENDER_LIMIT);
    }
    if (isPageActive()) {
      const list = sortItems(applyFilters(WC.getItems(), ui.view, ui.groupId, ui.search), ui.sort);
      return list.slice(0, PAGE_RENDER_LIMIT);
    }
    return WC.getItems().filter((it) => it.type === 'stock' && it.monitor);
  }

  function refreshCurrentScope() {
    refreshItems(currentScopeItems());
  }

  // ─────────────────────────────────────────────
  // 事件绑定
  // ─────────────────────────────────────────────
  function wirePageControls() {
    const search = $('#wc-search');
    if (search) search.addEventListener('input', Util.debounce((e) => {
      ui.search = e.target.value;
      clearSelection();
      renderList();
    }, 200));
    $all('.wc-view').forEach((btn) => btn.addEventListener('click', () => setView(btn.getAttribute('data-view'))));
    $all('[data-quick-view]').forEach((btn) => btn.addEventListener('click', () => setView(btn.getAttribute('data-quick-view'))));
    const sort = $('#wc-sort');
    if (sort) sort.addEventListener('change', (e) => { ui.sort = e.target.value; clearSelection(); renderList(); });
    const selectAll = $('#wc-select-all');
    if (selectAll) selectAll.addEventListener('change', () => {
      renderedIds.forEach((id) => {
        if (selectAll.checked) ui.selection.add(id); else ui.selection.delete(id);
      });
      renderList(false);
    });
    const addBtn = $('#wc-add-btn');
    if (addBtn) addBtn.addEventListener('click', openAddDialog);
    const refreshBtn = $('#wc-refresh-btn');
    if (refreshBtn) refreshBtn.addEventListener('click', () => {
      const items = currentScopeItems();
      if (!items.length) { showToast('当前结果中没有可刷新的资产', 'warning'); return; }
      refreshItems(items);
      showToast('正在刷新当前 ' + items.length + ' 项资产', 'success');
    });
    const exportBtn = $('#wc-export-btn');
    if (exportBtn) exportBtn.addEventListener('click', openExportMenu);
    const importBtn = $('#wc-import-btn');
    if (importBtn) importBtn.addEventListener('click', () => { const f = $('#wc-import-file'); if (f) f.click(); });
    const importFile = $('#wc-import-file');
    if (importFile) importFile.addEventListener('change', onImportFile);
    wireBulkBar();
    document.addEventListener('visibilitychange', () => {
      if (!Util.isPageVisible()) return;
      const now = Date.now();
      const settings = WC.getSettings();
      const stockMs = Math.max(10, Number(settings.stockRefreshSeconds) || 30) * 1000;
      const fundMs = Math.max(30, Number(settings.fundRefreshSeconds) || 60) * 1000;
      const scope = currentScopeItems();
      const stocks = scope.filter((it) => it.type === 'stock');
      const funds = scope.filter((it) => it.type === 'fund');
      if (stocks.length && now - lastStockRefreshAt >= stockMs) refreshStockBatches(stocks);
      if (funds.length && now - lastFundRefreshAt >= fundMs) refreshFundBatches(funds);
    });
  }

  function openExportMenu() {
    const choice = window.prompt('导出格式：输入 json（备份）或 csv（只读）', 'json');
    if (!choice) return;
    if (choice.toLowerCase() === 'csv') downloadFile('watchlist.csv', WC.exportCsv(), 'text/csv');
    else downloadFile('watch_center_backup.json', WC.exportJson(), 'application/json');
  }
  function downloadFile(name, content, mime) {
    const blob = new Blob([content], { type: mime + ';charset=utf-8' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = name;
    document.body.appendChild(a); a.click();
    setTimeout(() => { URL.revokeObjectURL(a.href); document.body.removeChild(a); }, 100);
  }
  function onImportFile(e) {
    const file = e.target.files && e.target.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = () => {
      const text = String(reader.result || '');
      const mode = window.prompt('导入模式：输入 merge（合并）或 replace（替换全部）', 'merge');
      if (!mode) { e.target.value = ''; return; }
      if (mode.toLowerCase() === 'replace') {
        const v = WC.validateImport(text);
        if (!v.ok) { window.alert('校验失败：\n' + v.errors.join('\n')); e.target.value = ''; return; }
        if (!window.confirm('替换导入将覆盖现有全部自选，确定继续？')) { e.target.value = ''; return; }
        const r = WC.importReplace(text);
        window.alert(r.ok ? ('替换完成，共 ' + r.added + ' 项') : ('失败：' + (r.errors || []).join('；')));
      } else {
        const r = WC.importMerge(text);
        if (r.ok) window.alert('合并完成：新增 ' + r.added + '，更新 ' + r.updated + '，跳过 ' + r.skipped + '，失败 ' + r.failed);
        else window.alert('失败：' + (r.errors || []).join('；'));
      }
      e.target.value = '';
    };
    reader.readAsText(file);
  }

  function wireDrawerControls() {
    const toggle = $('#watchlist-toggle');
    if (toggle) toggle.addEventListener('click', toggleDrawer);
    const close = $('#watch-drawer-close');
    if (close) close.addEventListener('click', closeDrawer);
    const ov = $('#watch-drawer-overlay');
    if (ov) ov.addEventListener('click', closeDrawer);
    const openCenter = $('#wd-open-center');
    if (openCenter) openCenter.addEventListener('click', () => {
      closeDrawer();
      Bus.emit('watch-center:goto-page', {});
    });
    const wdRefresh = $('#wd-refresh-btn');
    if (wdRefresh) wdRefresh.addEventListener('click', () => refreshItems(currentScopeItems()));
    const wdSearch = $('#wd-search');
    if (wdSearch) wdSearch.addEventListener('input', Util.debounce((e) => { drawerUi.search = e.target.value; renderDrawer(); }, 180));
    $all('.wd-view').forEach((btn) => btn.addEventListener('click', () => {
      drawerUi.view = btn.getAttribute('data-view');
      $all('.wd-view').forEach((b) => b.classList.toggle('active', b === btn));
      renderDrawer();
    }));
    // Escape 关闭最上层浮层
    window.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && drawerUi.open) { closeDrawer(); }
    });
  }

  function updateWatchCounts() {
    const c = $('#watchlist-count');
    if (c) c.textContent = WC.count();
  }

  // ─────────────────────────────────────────────
  // 初始化
  // ─────────────────────────────────────────────
  function init() {
    WC.init();
    wirePageControls();
    wireDrawerControls();

    Bus.on('watch-center:changed', (ev) => {
      // 名称回填不需要再次请求同一批行情，避免首轮修复时产生重复请求。
      const refreshQuotes = !ev || ev.reason !== 'resolve-names';
      renderPage(refreshQuotes);
      if (drawerUi.open) renderDrawer(refreshQuotes);
      updateWatchCounts();
    });
    Bus.on('watch-center:ready', () => { renderPage(); updateWatchCounts(); });
    Bus.on('watch-center:add-request', openAddDialog);
    Bus.on('watch-center:storage-unavailable', () => {
      const el = $('#wc-list'); if (el) el.insertAdjacentHTML('afterbegin', '<div class="wc-warn">本地保存不可用，当前为临时内存模式</div>');
    });
    Bus.on('tab:changed', (p) => {
      activeTab = (p && p.tab) ? p.tab : p;
      if (isPageActive()) { renderPage(); refreshCurrentScope(); }
    });
    Bus.on('data-status:retry', (ev) => {
      if (ev.scope === 'watch:stock-quote' || ev.scope === 'watch:fund-estimate') refreshCurrentScope();
    });

    renderPage();
    updateWatchCounts();
    startTimers();
  }

  window.WatchCenterUI = {
    init,
    openDrawer, closeDrawer, toggleDrawer,
    openAddDialog,
    refreshCurrentScope,
    _snapshot: snapshot,
  };
})();
