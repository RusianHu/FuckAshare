/**
 * watch_center.js — 统一自选中心存储 + 迁移 + 查询 + 管理
 *
 * 存储键 fa_watch_center_v2：schemaVersion / revision / updatedAt / settings / groups / items。
 * 主键 stock:<规范代码> / fund:<6位代码>。
 *
 * 无损迁移旧三套：
 *   fa_watchlist        -> 股票自选
 *   fa_realtime_codes   -> 股票自选且 monitor=true
 *   fa_fund_watchlist   -> 基金自选
 *
 * 迁移期旧键单向镜像（新→旧），保证旧版本可回滚读取；迁移成功前不删除旧键。
 * 依赖：window.AppBus、window.AppUtil（app_core.js 先加载）。
 */
(function () {
  'use strict';

  const STORE_KEY = 'fa_watch_center_v2';
  const BACKUP_KEY = 'fa_watch_center_v2_backup';
  const LEGACY = {
    stock: 'fa_watchlist',
    realtime: 'fa_realtime_codes',
    fund: 'fa_fund_watchlist',
  };
  const MIGRATED_FLAG = 'fa_watch_center_migrated';

  const LIMITS = {
    maxItems: 500,
    maxGroups: 50,
    maxTagsPerItem: 10,
    maxTagLen: 20,
    maxGroupNameLen: 20,
    maxNoteLen: 500,
  };

  const DEFAULT_GROUP_ID = 'default';

  let state = null;      // 内存态
  let memoryOnly = false; // localStorage 不可用时的降级标志
  const Bus = window.AppBus;
  const Util = window.AppUtil;

  // ─────────────────────────────────────────────
  // 底层存储
  // ─────────────────────────────────────────────
  function lsAvailable() {
    try {
      const k = '__wc_probe__';
      window.localStorage.setItem(k, '1');
      window.localStorage.removeItem(k);
      return true;
    } catch (e) {
      return false;
    }
  }

  function readRaw(key) {
    try { return window.localStorage.getItem(key); } catch (e) { return null; }
  }
  function writeRaw(key, val) {
    try { window.localStorage.setItem(key, val); return true; } catch (e) { return false; }
  }
  function removeRaw(key) {
    try { window.localStorage.removeItem(key); } catch (e) {}
  }

  function nowIso() { return new Date().toISOString(); }

  function emptyState() {
    return {
      schemaVersion: 2,
      revision: 1,
      updatedAt: nowIso(),
      settings: {
        defaultGroupId: DEFAULT_GROUP_ID,
        stockRefreshSeconds: 30,
        fundRefreshSeconds: 60,
      },
      groups: [
        { id: DEFAULT_GROUP_ID, name: '默认分组', sortOrder: 0, createdAt: nowIso() },
      ],
      items: [],
    };
  }

  function validState(s) {
    return s && typeof s === 'object'
      && s.schemaVersion === 2
      && Array.isArray(s.groups)
      && Array.isArray(s.items)
      && s.settings && typeof s.settings === 'object';
  }

  // ─────────────────────────────────────────────
  // 迁移
  // ─────────────────────────────────────────────
  function parseLegacyArray(key) {
    const raw = readRaw(key);
    if (!raw) return null;
    try {
      const parsed = JSON.parse(raw);
      return Array.isArray(parsed) ? parsed : null;
    } catch (e) {
      return null;
    }
  }

  /** 从旧三套构建统一 items（幂等：可对已存在 state 合并补充） */
  function migrateFromLegacy(target) {
    const s = target || emptyState();
    const byId = new Map(s.items.map((it) => [it.id, it]));
    let changed = false;

    function upsertStock(code, name, monitor) {
      const norm = Util.normalizeStockCode(code);
      if (!norm) return;
      const id = 'stock:' + norm;
      if (byId.has(id)) {
        const it = byId.get(id);
        if (monitor) it.monitor = true; // 监控属性取逻辑或
        if (!it.name && name) it.name = name;
        return;
      }
      const it = makeItem('stock', norm, name || norm, { monitor: !!monitor });
      s.items.push(it);
      byId.set(id, it);
      changed = true;
    }

    function upsertFund(code, name) {
      const norm = Util.normalizeFundCode(code);
      if (!norm) return;
      const id = 'fund:' + norm;
      if (byId.has(id)) {
        const it = byId.get(id);
        if (!it.name && name) it.name = name;
        return;
      }
      const it = makeItem('fund', norm, name || norm, { monitor: false });
      s.items.push(it);
      byId.set(id, it);
      changed = true;
    }

    // fa_watchlist: [{code,name}]
    const stocks = parseLegacyArray(LEGACY.stock);
    if (stocks) {
      stocks.forEach((w) => {
        if (w && typeof w === 'object') upsertStock(w.code, w.name, false);
        else if (typeof w === 'string') upsertStock(w, '', false);
      });
    }
    // fa_realtime_codes: ["sh600519", ...] -> monitor=true
    const realtime = parseLegacyArray(LEGACY.realtime);
    if (realtime) {
      realtime.forEach((c) => {
        if (typeof c === 'string') upsertStock(c, '', true);
        else if (c && typeof c === 'object' && c.code) upsertStock(c.code, c.name, true);
      });
    }
    // fa_fund_watchlist: [{code,name}]
    const funds = parseLegacyArray(LEGACY.fund);
    if (funds) {
      funds.forEach((w) => {
        if (w && typeof w === 'object') upsertFund(w.code, w.name);
        else if (typeof w === 'string') upsertFund(w, '');
      });
    }

    // 重排 sortOrder
    s.items.forEach((it, i) => { if (typeof it.sortOrder !== 'number') it.sortOrder = i; });
    return { state: s, changed };
  }

  function makeItem(type, code, name, extra) {
    extra = extra || {};
    return {
      id: type + ':' + code,
      type: type,
      code: code,
      name: name || code,
      groupId: DEFAULT_GROUP_ID,
      tags: [],
      note: '',
      pinned: false,
      monitor: type === 'stock' ? !!extra.monitor : false,
      sortOrder: typeof extra.sortOrder === 'number' ? extra.sortOrder : 0,
      createdAt: nowIso(),
      updatedAt: nowIso(),
    };
  }

  // ─────────────────────────────────────────────
  // 加载 / 持久化 / 镜像
  // ─────────────────────────────────────────────
  function load() {
    if (!lsAvailable()) {
      memoryOnly = true;
      const seed = migrateFromLegacy(emptyState()).state; // 内存态仍尝试读旧键（只读）
      state = seed;
      Bus.emit('watch-center:storage-unavailable', {});
      return state;
    }

    const raw = readRaw(STORE_KEY);
    if (!raw) {
      // 首次：迁移旧数据
      const migrated = migrateFromLegacy(emptyState());
      state = migrated.state;
      persist('migrate');
      writeRaw(MIGRATED_FLAG, '1');
      return state;
    }

    let parsed = null;
    try { parsed = JSON.parse(raw); } catch (e) { parsed = null; }

    if (validState(parsed)) {
      state = parsed;
      // 二次补迁移：若旧键存在新项（用户在旧版本又添加过），幂等合并
      if (readRaw(MIGRATED_FLAG) !== '1') {
        const merged = migrateFromLegacy(state);
        if (merged.changed) persist('migrate-merge');
        writeRaw(MIGRATED_FLAG, '1');
      }
      return state;
    }

    // 数据损坏：备份原字符串 → 尝试旧键恢复
    writeRaw(BACKUP_KEY, raw);
    const recovered = migrateFromLegacy(emptyState());
    state = recovered.state;
    persist('recover');
    Bus.emit('watch-center:recovered', { backupKey: BACKUP_KEY });
    return state;
  }

  function persist(reason, changedIds) {
    if (!state) return;
    state.revision = (state.revision || 1) + 1;
    state.updatedAt = nowIso();
    if (!memoryOnly) {
      const ok = writeRaw(STORE_KEY, JSON.stringify(state));
      if (!ok) {
        memoryOnly = true;
        Bus.emit('watch-center:storage-unavailable', {});
      } else {
        mirrorToLegacy();
      }
    }
    Bus.emit('watch-center:changed', {
      revision: state.revision,
      reason: reason || 'update',
      changedIds: changedIds || [],
    });
  }

  /** 单向镜像回旧键，保证旧版本可回滚读取 */
  function mirrorToLegacy() {
    const stocks = [];
    const realtime = [];
    const funds = [];
    state.items.forEach((it) => {
      if (it.type === 'stock') {
        stocks.push({ code: it.code, name: it.name });
        if (it.monitor) realtime.push(it.code);
      } else if (it.type === 'fund') {
        funds.push({ code: it.code, name: it.name });
      }
    });
    writeRaw(LEGACY.stock, JSON.stringify(stocks));
    writeRaw(LEGACY.realtime, JSON.stringify(realtime));
    writeRaw(LEGACY.fund, JSON.stringify(funds));
  }

  // ─────────────────────────────────────────────
  // 跨标签页同步
  // ─────────────────────────────────────────────
  function onStorageEvent(e) {
    if (e.key !== STORE_KEY || !e.newValue) return;
    let incoming = null;
    try { incoming = JSON.parse(e.newValue); } catch (err) { return; }
    if (!validState(incoming)) return;
    // 冲突：revision 高者胜；相同则以较新 updatedAt 为准
    const localRev = state ? state.revision : 0;
    if (incoming.revision > localRev ||
        (incoming.revision === localRev && incoming.updatedAt > (state ? state.updatedAt : ''))) {
      state = incoming;
      Bus.emit('watch-center:changed', { revision: state.revision, reason: 'cross-tab', changedIds: [] });
    }
  }

  // ─────────────────────────────────────────────
  // 查询 API
  // ─────────────────────────────────────────────
  function getState() { return state; }
  function getItems() { return state ? state.items.slice() : []; }
  function getGroups() { return state ? state.groups.slice().sort((a, b) => a.sortOrder - b.sortOrder) : []; }
  function getSettings() { return state ? Object.assign({}, state.settings) : {}; }
  function getItem(id) { return state ? state.items.find((it) => it.id === id) || null : null; }
  function count() { return state ? state.items.length : 0; }
  function monitorCount() { return state ? state.items.filter((it) => it.type === 'stock' && it.monitor).length : 0; }

  function has(type, code) {
    const norm = type === 'stock' ? Util.normalizeStockCode(code) : Util.normalizeFundCode(code);
    return !!getItem(type + ':' + norm);
  }

  /** 系统视图：all / stock / fund / monitor / ungrouped */
  function queryView(view) {
    const items = getItems();
    switch (view) {
      case 'stock': return items.filter((it) => it.type === 'stock');
      case 'fund': return items.filter((it) => it.type === 'fund');
      case 'monitor': return items.filter((it) => it.type === 'stock' && it.monitor);
      case 'ungrouped': return items.filter((it) => it.groupId === DEFAULT_GROUP_ID);
      case 'all':
      default: return items;
    }
  }

  // ─────────────────────────────────────────────
  // 变更 API
  // ─────────────────────────────────────────────
  function addItem(type, code, name, extra) {
    if (!state) return { ok: false, reason: 'no-state' };
    if (state.items.length >= LIMITS.maxItems) {
      return { ok: false, reason: 'limit', message: '自选项已达上限 ' + LIMITS.maxItems };
    }
    const norm = type === 'stock' ? Util.normalizeStockCode(code) : Util.normalizeFundCode(code);
    if (!norm) return { ok: false, reason: 'invalid-code', message: '代码格式不正确' };
    const id = type + ':' + norm;
    if (getItem(id)) {
      // 已存在：可选更新 monitor / name
      const it = getItem(id);
      let touched = false;
      if (extra && extra.monitor && type === 'stock' && !it.monitor) { it.monitor = true; touched = true; }
      if (name && !it.name) { it.name = name; touched = true; }
      if (touched) { it.updatedAt = nowIso(); persist('update', [id]); }
      return { ok: true, existed: true, id };
    }
    const it = makeItem(type, norm, name, extra);
    it.sortOrder = state.items.length;
    if (extra && extra.groupId && state.groups.some((g) => g.id === extra.groupId)) {
      it.groupId = extra.groupId;
    }
    state.items.push(it);
    persist('add', [id]);
    return { ok: true, existed: false, id };
  }

  function removeItem(id) {
    if (!state) return false;
    const before = state.items.length;
    state.items = state.items.filter((it) => it.id !== id);
    if (state.items.length !== before) { persist('remove', [id]); return true; }
    return false;
  }

  function updateItem(id, patch) {
    const it = getItem(id);
    if (!it) return false;
    const allowed = ['name', 'note', 'pinned', 'monitor', 'groupId', 'tags', 'sortOrder'];
    allowed.forEach((k) => {
      if (patch[k] === undefined) return;
      if (k === 'note') { it.note = String(patch.note).slice(0, LIMITS.maxNoteLen); }
      else if (k === 'tags') { it.tags = sanitizeTags(patch.tags); }
      else if (k === 'monitor') { it.monitor = it.type === 'stock' ? !!patch.monitor : false; }
      else if (k === 'groupId') { if (state.groups.some((g) => g.id === patch.groupId)) it.groupId = patch.groupId; }
      else { it[k] = patch[k]; }
    });
    it.updatedAt = nowIso();
    persist('update', [id]);
    return true;
  }

  function sanitizeTags(tags) {
    if (!Array.isArray(tags)) return [];
    const out = [];
    tags.forEach((t) => {
      const s = String(t).trim().slice(0, LIMITS.maxTagLen);
      if (s && out.indexOf(s) === -1 && out.length < LIMITS.maxTagsPerItem) out.push(s);
    });
    return out;
  }

  function togglePin(id) {
    const it = getItem(id);
    if (!it) return false;
    it.pinned = !it.pinned;
    it.updatedAt = nowIso();
    persist('pin', [id]);
    return it.pinned;
  }

  function toggleMonitor(id) {
    const it = getItem(id);
    if (!it || it.type !== 'stock') return false;
    it.monitor = !it.monitor;
    it.updatedAt = nowIso();
    persist('monitor', [id]);
    return it.monitor;
  }

  /** 批量操作 */
  function bulkUpdate(ids, patch) {
    if (!state) return 0;
    let n = 0;
    ids.forEach((id) => {
      const it = getItem(id);
      if (!it) return;
      if (patch.groupId !== undefined && state.groups.some((g) => g.id === patch.groupId)) { it.groupId = patch.groupId; n++; }
      if (patch.monitor !== undefined && it.type === 'stock') { it.monitor = !!patch.monitor; n++; }
      if (patch.addTag) { it.tags = sanitizeTags((it.tags || []).concat([patch.addTag])); n++; }
      it.updatedAt = nowIso();
    });
    if (n > 0) persist('bulk', ids);
    return n;
  }

  function bulkRemove(ids) {
    if (!state) return 0;
    const set = new Set(ids);
    const before = state.items.length;
    state.items = state.items.filter((it) => !set.has(it.id));
    const removed = before - state.items.length;
    if (removed > 0) persist('bulk-remove', ids);
    return removed;
  }

  /** 拖动排序：按给定 id 顺序重排 sortOrder */
  function reorder(orderedIds) {
    if (!state) return;
    const rank = new Map(orderedIds.map((id, i) => [id, i]));
    state.items.forEach((it) => {
      if (rank.has(it.id)) it.sortOrder = rank.get(it.id);
    });
    persist('reorder', orderedIds);
  }

  // ─────────────────────────────────────────────
  // 分组 API
  // ─────────────────────────────────────────────
  function createGroup(name) {
    if (!state) return null;
    if (state.groups.length >= LIMITS.maxGroups) return null;
    const clean = String(name).trim().slice(0, LIMITS.maxGroupNameLen);
    if (!clean) return null;
    const id = 'g_' + Math.random().toString(36).slice(2, 9);
    const g = { id, name: clean, sortOrder: state.groups.length, createdAt: nowIso() };
    state.groups.push(g);
    persist('group-create', []);
    return g;
  }

  function renameGroup(id, name) {
    const g = state.groups.find((x) => x.id === id);
    if (!g) return false;
    g.name = String(name).trim().slice(0, LIMITS.maxGroupNameLen) || g.name;
    persist('group-rename', []);
    return true;
  }

  function deleteGroup(id) {
    if (id === DEFAULT_GROUP_ID) return false; // 默认分组不可删除
    const before = state.groups.length;
    state.groups = state.groups.filter((g) => g.id !== id);
    if (state.groups.length === before) return false;
    // 该组项目移入默认分组
    state.items.forEach((it) => { if (it.groupId === id) it.groupId = DEFAULT_GROUP_ID; });
    persist('group-delete', []);
    return true;
  }

  function reorderGroups(orderedIds) {
    const rank = new Map(orderedIds.map((id, i) => [id, i]));
    state.groups.forEach((g) => { if (rank.has(g.id)) g.sortOrder = rank.get(g.id); });
    persist('group-reorder', []);
  }

  function updateSettings(patch) {
    if (!state) return;
    Object.assign(state.settings, patch || {});
    persist('settings', []);
  }

  // ─────────────────────────────────────────────
  // 导入 / 导出
  // ─────────────────────────────────────────────
  function exportJson() {
    return JSON.stringify(state, null, 2);
  }

  function exportCsv() {
    const rows = [['type', 'code', 'name', 'group', 'tags', 'note', 'pinned', 'monitor']];
    const groupName = new Map(state.groups.map((g) => [g.id, g.name]));
    state.items.forEach((it) => {
      rows.push([
        it.type, it.code, it.name, groupName.get(it.groupId) || '',
        (it.tags || []).join('|'), (it.note || '').replace(/[\r\n]+/g, ' '),
        it.pinned ? '1' : '0', it.monitor ? '1' : '0',
      ]);
    });
    return rows.map((r) => r.map(csvCell).join(',')).join('\r\n');
  }
  function csvCell(v) {
    const s = String(v == null ? '' : v);
    return /[",\r\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s;
  }

  /** 校验导入负载（不落地），返回 { ok, errors, state } */
  function validateImport(jsonStr) {
    let parsed = null;
    try { parsed = JSON.parse(jsonStr); } catch (e) {
      return { ok: false, errors: ['JSON 解析失败: ' + e.message] };
    }
    const errors = [];
    if (!validState(parsed)) {
      // 允许仅含 items 的简化负载
      if (!parsed || !Array.isArray(parsed.items)) {
        errors.push('缺少有效的 items 数组');
        return { ok: false, errors };
      }
    }
    (parsed.items || []).forEach((it, i) => {
      if (!it || (it.type !== 'stock' && it.type !== 'fund')) errors.push('第' + (i + 1) + '项 type 非法');
      if (!it || !it.code) errors.push('第' + (i + 1) + '项缺少 code');
    });
    return { ok: errors.length === 0, errors, state: parsed };
  }

  /** 合并导入：新增/更新，返回统计 */
  function importMerge(jsonStr) {
    const v = validateImport(jsonStr);
    if (!v.ok) return { ok: false, errors: v.errors };
    const stats = { added: 0, updated: 0, skipped: 0, failed: 0, reasons: [] };
    // 合并分组
    (v.state.groups || []).forEach((g) => {
      if (g && g.id && g.id !== DEFAULT_GROUP_ID && !state.groups.some((x) => x.id === g.id)) {
        if (state.groups.length < LIMITS.maxGroups) {
          state.groups.push({ id: g.id, name: String(g.name || '分组').slice(0, LIMITS.maxGroupNameLen), sortOrder: state.groups.length, createdAt: g.createdAt || nowIso() });
        }
      }
    });
    (v.state.items || []).forEach((raw) => {
      const type = raw.type;
      const code = type === 'stock' ? Util.normalizeStockCode(raw.code) : Util.normalizeFundCode(raw.code);
      if (!code) { stats.failed++; stats.reasons.push('代码非法: ' + raw.code); return; }
      const id = type + ':' + code;
      const existing = getItem(id);
      if (existing) {
        existing.name = raw.name || existing.name;
        existing.note = String(raw.note || existing.note || '').slice(0, LIMITS.maxNoteLen);
        existing.tags = sanitizeTags((existing.tags || []).concat(raw.tags || []));
        if (raw.monitor && type === 'stock') existing.monitor = true;
        if (raw.pinned) existing.pinned = true;
        existing.updatedAt = nowIso();
        stats.updated++;
      } else {
        if (state.items.length >= LIMITS.maxItems) { stats.skipped++; stats.reasons.push('超出上限: ' + id); return; }
        const it = makeItem(type, code, raw.name, { monitor: raw.monitor });
        it.note = String(raw.note || '').slice(0, LIMITS.maxNoteLen);
        it.tags = sanitizeTags(raw.tags || []);
        it.pinned = !!raw.pinned;
        if (raw.groupId && state.groups.some((g) => g.id === raw.groupId)) it.groupId = raw.groupId;
        it.sortOrder = state.items.length;
        state.items.push(it);
        stats.added++;
      }
    });
    persist('import-merge', []);
    stats.ok = true;
    return stats;
  }

  /** 替换导入：全量替换（调用前必须已二次确认）；先备份现有数据 */
  function importReplace(jsonStr) {
    const v = validateImport(jsonStr);
    if (!v.ok) return { ok: false, errors: v.errors };
    // 备份当前，防止替换失败破坏数据
    writeRaw(BACKUP_KEY, JSON.stringify(state));

    const src = v.state || {};
    const next = emptyState();
    next.revision = (state.revision || 1) + 1;
    // 保留导入的设置与分组（默认分组稍后强制存在）
    if (src.settings && typeof src.settings === 'object') {
      next.settings = Object.assign(next.settings, src.settings);
    }
    if (Array.isArray(src.groups)) {
      next.groups = src.groups
        .filter((g) => g && g.id)
        .map((g, i) => ({
          id: g.id,
          name: String(g.name || '分组').slice(0, LIMITS.maxGroupNameLen),
          sortOrder: typeof g.sortOrder === 'number' ? g.sortOrder : i,
          createdAt: g.createdAt || nowIso(),
        }));
    }
    // 逐项归一，确保 id / 字段完整
    const seen = new Set();
    (src.items || []).forEach((raw, i) => {
      if (!raw) return;
      const type = raw.type;
      if (type !== 'stock' && type !== 'fund') return;
      const code = type === 'stock' ? Util.normalizeStockCode(raw.code) : Util.normalizeFundCode(raw.code);
      if (!code) return;
      const id = type + ':' + code;
      if (seen.has(id)) return;
      seen.add(id);
      const it = makeItem(type, code, raw.name, { monitor: raw.monitor });
      it.note = String(raw.note || '').slice(0, LIMITS.maxNoteLen);
      it.tags = sanitizeTags(raw.tags || []);
      it.pinned = !!raw.pinned;
      it.sortOrder = typeof raw.sortOrder === 'number' ? raw.sortOrder : i;
      if (raw.groupId) it.groupId = raw.groupId; // 分组合法性下方统一收敛
      next.items.push(it);
    });

    // 确保默认分组存在
    if (!next.groups.some((g) => g.id === DEFAULT_GROUP_ID)) {
      next.groups.unshift({ id: DEFAULT_GROUP_ID, name: '默认分组', sortOrder: -1, createdAt: nowIso() });
    }
    next.settings.defaultGroupId = DEFAULT_GROUP_ID;
    // 收敛非法 groupId
    const groupIds = new Set(next.groups.map((g) => g.id));
    next.items.forEach((it) => { if (!groupIds.has(it.groupId)) it.groupId = DEFAULT_GROUP_ID; });

    state = next;
    persist('import-replace', []);
    return { ok: true, added: next.items.length };
  }

  // ─────────────────────────────────────────────
  // 初始化
  // ─────────────────────────────────────────────
  function init() {
    load();
    if (!memoryOnly && window.addEventListener) {
      window.addEventListener('storage', onStorageEvent);
    }
    Bus.emit('watch-center:ready', { revision: state ? state.revision : 0, count: count() });
    return state;
  }

  window.WatchCenter = {
    LIMITS,
    DEFAULT_GROUP_ID,
    init,
    getState, getItems, getGroups, getSettings, getItem, count, monitorCount, has, queryView,
    addItem, removeItem, updateItem, togglePin, toggleMonitor,
    bulkUpdate, bulkRemove, reorder,
    createGroup, renameGroup, deleteGroup, reorderGroups, updateSettings,
    exportJson, exportCsv, validateImport, importMerge, importReplace,
    isMemoryOnly: function () { return memoryOnly; },
    // 测试与调试用
    _migrateFromLegacy: migrateFromLegacy,
    _emptyState: emptyState,
  };
})();
