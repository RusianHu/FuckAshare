/**
 * data_status.js — 数据状态中心 + 导航下状态细条 + 详情层
 *
 * 订阅 ApiClient 的 api:lifecycle 事件，维护每个 scope 的生命周期状态，
 * 按当前页面聚合，渲染导航下状态细条与详情层。
 *
 * 生命周期：idle / loading / ok / notice / warning / error
 * 聚合优先级：离线 → 无可用数据错误 → 陈旧 → 部分/备用源 → 加载 → 缓存 → 正常
 *
 * 依赖：window.AppBus、window.ApiClient（app_core.js 先加载）。
 * DOM 由 init() 惰性注入，不要求 index.php 预置节点。
 */
(function () {
  'use strict';

  const Bus = window.AppBus;

  // scope -> record
  // record: { scope, label, page, global, phase, dataStatus, updatedAt, message, source, meta, retry }
  const registry = new Map();
  let currentPage = 'stock';
  let barEl = null;
  let detailEl = null;
  let detailOpen = false;

  const SEVERITY_RANK = { ok: 0, info: 1, notice: 1, warning: 2, error: 3 };

  function isOffline() {
    return typeof navigator !== 'undefined' && navigator.onLine === false;
  }

  // ─────────────────────────────────────────────
  // 事件接入
  // ─────────────────────────────────────────────
  function onLifecycle(ev) {
    if (!ev || !ev.scope) return;
    let rec = registry.get(ev.scope);
    if (!rec) {
      rec = { scope: ev.scope, label: ev.label || ev.scope, page: ev.page, global: ev.global !== false };
      registry.set(ev.scope, rec);
    }
    rec.label = ev.label || rec.label;
    if (ev.page) rec.page = ev.page;
    if (ev.global !== undefined) rec.global = ev.global !== false;

    if (ev.phase === 'loading') {
      rec.phase = 'loading';
    } else if (ev.phase === 'aborted') {
      // Abort 不计失败：回退到上一个稳定态
      rec.phase = rec.lastStable || 'idle';
    } else if (ev.phase === 'settled' || ev.phase === 'error') {
      const r = ev.result || {};
      rec.dataStatus = r.dataStatus || null;
      rec.message = r.message || '';
      rec.source = r.source || '';
      rec.meta = r.meta || {};
      rec.updatedAt = Date.now();
      rec.phase = mapPhase(r);
      rec.lastStable = rec.phase;
    }
    scheduleRender();
    Bus.emit('data-status:changed', { scope: ev.scope, aggregate: aggregate(currentPage) });
  }

  function mapPhase(result) {
    if (!result.ok) {
      if (result.status === 'aborted') return 'idle';
      return 'error';
    }
    const ds = result.dataStatus || {};
    if (ds.severity === 'warning') return 'warning';
    if (ds.severity === 'info') return 'notice';
    return 'ok';
  }

  // ─────────────────────────────────────────────
  // 聚合
  // ─────────────────────────────────────────────
  function relevant(page, includeDrawer) {
    const out = [];
    registry.forEach((rec) => {
      if (!rec.global) return;
      if (rec.page && rec.page !== page && !(includeDrawer && rec.page === 'watch-drawer')) return;
      out.push(rec);
    });
    return out;
  }

  function aggregate(page, includeDrawer) {
    const recs = relevant(page, includeDrawer);
    const agg = {
      severity: 'ok',
      loading: 0,
      total: recs.length,
      okCount: 0,
      cachedCount: 0,
      staleCount: 0,
      partialCount: 0,
      fallbackCount: 0,
      nonRealtimeCount: 0,
      errorCount: 0,
      lastUpdatedAt: 0,
      itemCount: 0,
      offline: isOffline(),
    };
    recs.forEach((rec) => {
      if (rec.phase === 'loading') agg.loading++;
      const ds = rec.dataStatus || {};
      if (rec.phase === 'error') agg.errorCount++;
      else if (rec.phase === 'ok') agg.okCount++;
      if (ds.freshness === 'stale') agg.staleCount++;
      else if (ds.freshness === 'cached') agg.cachedCount++;
      if (ds.completeness === 'partial') agg.partialCount++;
      if (ds.route === 'fallback') agg.fallbackCount++;
      if (typeof ds.non_realtime_count === 'number') agg.nonRealtimeCount += ds.non_realtime_count;
      if (ds.counts && typeof ds.counts.returned === 'number') agg.itemCount += ds.counts.returned;
      if (rec.updatedAt && rec.updatedAt > agg.lastUpdatedAt) agg.lastUpdatedAt = rec.updatedAt;
    });

    // 聚合优先级
    if (agg.offline) agg.severity = 'offline';
    else if (agg.errorCount > 0 && agg.okCount === 0 && agg.cachedCount === 0) agg.severity = 'error';
    else if (agg.errorCount > 0) agg.severity = 'error';
    else if (agg.staleCount > 0) agg.severity = 'warning';
    else if (agg.partialCount > 0 || agg.fallbackCount > 0 || agg.nonRealtimeCount > 0) agg.severity = 'warning';
    else if (agg.loading > 0) agg.severity = 'loading';
    else if (agg.cachedCount > 0) agg.severity = 'info';
    else agg.severity = 'ok';

    return agg;
  }

  // ─────────────────────────────────────────────
  // 渲染
  // ─────────────────────────────────────────────
  let renderScheduled = false;
  function scheduleRender() {
    if (renderScheduled) return;
    renderScheduled = true;
    (window.requestAnimationFrame || window.setTimeout)(function () {
      renderScheduled = false;
      renderBar();
      if (detailOpen) renderDetail();
    }, 0);
  }

  function ensureBar() {
    if (barEl) return barEl;
    const nav = document.querySelector('.top-nav');
    barEl = document.createElement('div');
    barEl.className = 'data-status-bar';
    barEl.id = 'data-status-bar';
    barEl.setAttribute('role', 'status');
    barEl.setAttribute('aria-live', 'polite');
    barEl.addEventListener('click', function () { toggleDetail(); });
    if (nav && nav.parentNode) {
      nav.parentNode.insertBefore(barEl, nav.nextSibling);
    } else {
      document.body.insertBefore(barEl, document.body.firstChild);
    }
    return barEl;
  }

  function fmtTime(ts) {
    if (!ts) return '—';
    const d = new Date(ts);
    const p = (n) => (n < 10 ? '0' + n : '' + n);
    return p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds());
  }

  function renderBar() {
    const el = ensureBar();
    const agg = aggregate(currentPage, isDrawerOpen());
    el.setAttribute('data-severity', agg.severity);

    let icon = '●';
    let text = '';
    switch (agg.severity) {
      case 'offline':
        icon = '⚠'; text = '浏览器离线 · 保留最近成功数据 ' + fmtTime(agg.lastUpdatedAt); break;
      case 'error':
        icon = '✕'; text = agg.errorCount + ' 项请求失败，其余可用数据仍保留'; break;
      case 'warning':
        icon = '▲';
        text = describeWarning(agg); break;
      case 'loading':
        icon = '↻'; text = '正在更新 · ' + agg.loading + ' 个请求'; break;
      case 'info':
        icon = '◐'; text = '含缓存数据 · ' + agg.cachedCount + ' 项 · ' + fmtTime(agg.lastUpdatedAt); break;
      case 'ok':
      default:
        icon = '●';
        text = agg.total === 0 ? '暂无数据请求' : ('数据正常 · ' + agg.itemCount + ' 项 · ' + fmtTime(agg.lastUpdatedAt));
    }

    el.innerHTML =
      '<span class="dsb-icon" aria-hidden="true">' + icon + '</span>' +
      '<span class="dsb-text">' + escapeHtml(text) + '</span>' +
      '<span class="dsb-more">详情</span>';
  }

  function describeWarning(agg) {
    const parts = [];
    if (agg.staleCount) parts.push('陈旧 ' + agg.staleCount);
    if (agg.partialCount) parts.push('部分缺失 ' + agg.partialCount);
    if (agg.nonRealtimeCount) parts.push('非实时数据 ' + agg.nonRealtimeCount + ' 项');
    if (agg.fallbackCount && !agg.nonRealtimeCount) parts.push('备用源 ' + agg.fallbackCount);
    return parts.length ? parts.join(' · ') : '数据需要注意';
  }

  function dataAtSummary(meta) {
    if (meta && meta.data_at) return String(meta.data_at);
    const byCode = meta && meta.data_at_by_code;
    if (!byCode || typeof byCode !== 'object') return '—';
    const rows = Object.keys(byCode).slice(0, 6).map(function (code) { return code + ': ' + byCode[code]; });
    if (Object.keys(byCode).length > 6) rows.push('…');
    return rows.length ? rows.join('；') : '—';
  }

  function isDrawerOpen() {
    const d = document.getElementById('watch-drawer');
    return d ? d.classList.contains('open') : false;
  }

  // ── 详情层 ──
  function ensureDetail() {
    if (detailEl) return detailEl;
    detailEl = document.createElement('div');
    detailEl.className = 'data-status-detail';
    detailEl.id = 'data-status-detail';
    detailEl.setAttribute('role', 'dialog');
    detailEl.setAttribute('aria-label', '数据状态详情');
    document.body.appendChild(detailEl);
    return detailEl;
  }

  function toggleDetail() {
    detailOpen ? closeDetail() : openDetail();
  }
  function openDetail() {
    ensureDetail();
    detailOpen = true;
    renderDetail();
    detailEl.classList.add('open');
  }
  function closeDetail() {
    detailOpen = false;
    if (detailEl) detailEl.classList.remove('open');
  }

  function renderDetail() {
    const el = ensureDetail();
    const recs = relevant(currentPage, isDrawerOpen());
    let rows = recs.map(function (rec) {
      const ds = rec.dataStatus || {};
      const counts = ds.counts || {};
      const missing = (counts.missing || []).join('、');
      const cacheAge = rec.meta && rec.meta.cache_age_seconds != null ? (rec.meta.cache_age_seconds + 's') : '—';
      const dataAt = dataAtSummary(rec.meta);
      const nonRealtimeCodes = rec.meta && Array.isArray(rec.meta.non_realtime_codes)
        ? rec.meta.non_realtime_codes.join('、') : '';
      return (
        '<div class="dsd-row" data-severity="' + (ds.severity || (rec.phase === 'error' ? 'error' : 'ok')) + '">' +
          '<div class="dsd-row-head">' +
            '<span class="dsd-name">' + escapeHtml(rec.label || rec.scope) + '</span>' +
            '<button class="dsd-retry" data-scope="' + escapeHtml(rec.scope) + '">重试</button>' +
          '</div>' +
          '<div class="dsd-meta">' +
            '<span>来源: ' + escapeHtml(rec.source || '—') + '</span>' +
            '<span>完成: ' + fmtTime(rec.updatedAt) + '</span>' +
            '<span>数据时间: ' + escapeHtml(dataAt) + '</span>' +
            '<span>缓存: ' + escapeHtml(ds.freshness || '—') + ' (' + cacheAge + ')</span>' +
            '<span>内容时效: ' + escapeHtml(ds.data_recency || 'unknown') + '</span>' +
            '<span>完整度: ' + escapeHtml(ds.completeness || '—') + '</span>' +
            (nonRealtimeCodes ? '<span>非实时代码: ' + escapeHtml(nonRealtimeCodes) + '</span>' : '') +
            (missing ? '<span>缺失: ' + escapeHtml(missing) + '</span>' : '') +
          '</div>' +
          (rec.message ? '<div class="dsd-msg">' + escapeHtml(rec.message) + '</div>' : '') +
        '</div>'
      );
    }).join('');
    if (!rows) rows = '<div class="dsd-empty">当前页面暂无数据请求</div>';

    el.innerHTML =
      '<div class="dsd-header">' +
        '<span>数据状态</span>' +
        '<div class="dsd-actions">' +
          '<button class="dsd-copy">复制诊断</button>' +
          '<button class="dsd-close" aria-label="关闭">✕</button>' +
        '</div>' +
      '</div>' +
      '<div class="dsd-body">' + rows + '</div>';

    el.querySelector('.dsd-close').addEventListener('click', closeDetail);
    el.querySelector('.dsd-copy').addEventListener('click', copyDiagnostics);
    Array.prototype.forEach.call(el.querySelectorAll('.dsd-retry'), function (btn) {
      btn.addEventListener('click', function () {
        const scope = btn.getAttribute('data-scope');
        Bus.emit('data-status:retry', { scope: scope });
      });
    });
  }

  /** 复制脱敏诊断：仅 request_id / 模块 / 状态 / 时间 / 来源 / 错误码，不含 URL/认证/堆栈 */
  function copyDiagnostics() {
    const recs = relevant(currentPage, isDrawerOpen());
    const lines = recs.map(function (rec) {
      const ds = rec.dataStatus || {};
      return [
        'module=' + (rec.label || rec.scope),
        'request_id=' + (rec.meta && rec.meta.request_id ? rec.meta.request_id : '-'),
        'severity=' + (ds.severity || '-'),
        'route=' + (ds.route || '-'),
        'freshness=' + (ds.freshness || '-'),
        'completeness=' + (ds.completeness || '-'),
        'source=' + (rec.source || '-'),
        'at=' + fmtTime(rec.updatedAt),
      ].join(' ');
    });
    const text = lines.join('\n');
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).catch(function () {});
    }
    Bus.emit('data-status:diagnostics-copied', { text: text });
  }

  // ─────────────────────────────────────────────
  // 页面切换 / 离线
  // ─────────────────────────────────────────────
  function setPage(page) {
    currentPage = page || 'stock';
    scheduleRender();
  }

  function escapeHtml(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function init() {
    Bus.on('api:lifecycle', onLifecycle);
    Bus.on('tab:changed', function (p) { setPage(p && p.tab ? p.tab : p); });
    Bus.on('watch-center:changed', scheduleRender);
    if (window.addEventListener) {
      window.addEventListener('online', scheduleRender);
      window.addEventListener('offline', scheduleRender);
      window.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && detailOpen) { closeDetail(); }
      });
    }
    ensureBar();
    renderBar();
  }

  window.DataStatus = {
    init,
    setPage,
    aggregate,
    openDetail,
    closeDetail,
    _registry: registry,
  };
})();
