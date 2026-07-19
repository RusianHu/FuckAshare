/**
 * market_overview.js — 大盘指数概览条
 *
 * 数据：market_api.php?action=market_breadth（ApiClient envelope，scope=market:overview，
 *       全局请求域 → data_status.js 状态条在所有页面聚合它）
 * 展示：主要指数行情 chips + 全市场涨跌家数/情绪 + 近似涨跌停统计
 * 交互：点击指数 → AppBus 'market-overview:navigate'（main.js 转发 submitStockQuery 打开行情工作台）；
 *       手动刷新；页面可见时自动刷新，隐藏即暂停、恢复补刷。
 *
 * 性能契约（本地 php -S 单线程，慢请求会阻塞全站，务必遵守）：
 *   首屏只发轻量请求（include_limit_stats=0，仅指数行情，单个上游调用）；
 *   全市场扫描版本延迟到 window load + idle 后异步升级，交易时段按 ≥5min 间隔重扫；
 *   交易时段每 60s 轻量刷新指数行情；休市且已有数据时零网络请求，仅 5min 定时检查等待开盘。
 *
 * 依赖：app_core.js（AppBus/ApiClient/AppUtil）。init() 由 main.js 在 DataStatus.init() 之后调用，
 *       保证首个请求的生命周期事件能被状态条捕获。纯函数经 _pure 导出供回归测试。
 */
(function () {
  'use strict';

  const API_URL = 'market_api.php?action=market_breadth&scope=a_share';
  const API_URL_LIGHT = API_URL + '&include_limit_stats=0';
  const SCOPE = 'market:overview';
  const LABEL = '大盘概览';
  const REFRESH_TRADING_MS = 60000;
  const REFRESH_IDLE_MS = 300000;
  const FULL_SCAN_MIN_GAP_MS = 300000; // 全市场扫描较重（本地单线程 PHP 下 10s+），交易时段最多每 5min 升级一次

  const SENTIMENT_CN = {
    very_positive: '普涨',
    positive: '偏强',
    neutral: '均衡',
    negative: '偏弱',
    very_negative: '普跌',
  };
  const SENTIMENT_TONE = {
    very_positive: 'up',
    positive: 'up',
    neutral: 'flat',
    negative: 'down',
    very_negative: 'down',
  };
  const METHOD_CN = {
    full_a_share_scan: { label: '全市场', title: '全部A股逐股扫描口径' },
    scoped_a_share_scan: { label: '市场扫描', title: '按市场范围逐股扫描口径' },
    index_constituent_counts: { label: '指数口径', title: '来自指数成分股涨跌家数，非全市场逐股精确统计' },
  };

  let el = null;
  let timer = null;
  let loading = false;
  let lastFetchAt = 0;
  let lastFullAt = 0;
  let lastModel = null;
  let inflightFetches = 0; // 全站在飞 fetch 计数（Resource Timing 看不到未完成请求）

  // ─────────────────────────────────────────────
  // 纯函数（导出供 market_overview_feature_tests.php 断言）
  // ─────────────────────────────────────────────

  /** A股连续交易时段（含少量缓冲：09:15-11:35 / 12:55-15:05，北京时间，仅按周一~周五判断，不含节假日历） */
  function isTradingSession(nowMs) {
    const ms = typeof nowMs === 'number' ? nowMs : Date.now();
    const bj = new Date(ms + 480 * 60000); // 显式 UTC+8，避免依赖客户端时区
    const day = bj.getUTCDay();
    if (day === 0 || day === 6) return false;
    const m = bj.getUTCHours() * 60 + bj.getUTCMinutes();
    return (m >= 555 && m <= 695) || (m >= 775 && m <= 905);
  }

  function pickIntervalMs(nowMs) {
    return isTradingSession(nowMs) ? REFRESH_TRADING_MS : REFRESH_IDLE_MS;
  }

  /** 成交额（元）→ 万亿/亿 可读文本 */
  function fmtAmount(v) {
    const n = Number(v);
    if (!isFinite(n) || n <= 0) return '—';
    if (n >= 1e12) return (n / 1e12).toFixed(2) + '万亿';
    if (n >= 1e8) return n >= 1e10 ? Math.round(n / 1e8) + '亿' : (n / 1e8).toFixed(1) + '亿';
    if (n >= 1e4) return Math.round(n / 1e4) + '万';
    return Math.round(n) + '元';
  }

  function fmtPrice(v) {
    const n = Number(v);
    return isFinite(n) ? n.toFixed(2) : '—';
  }

  function fmtPct(v) {
    if (v === null || v === undefined || !isFinite(Number(v))) return '—';
    const n = Number(v);
    return (n > 0 ? '+' : '') + n.toFixed(2) + '%';
  }

  function fmtSigned(v) {
    if (v === null || v === undefined || !isFinite(Number(v))) return '—';
    const n = Number(v);
    return (n > 0 ? '+' : '') + n.toFixed(2);
  }

  function trendClass(v) {
    if (v === null || v === undefined || !isFinite(Number(v))) return 'flat';
    const n = Number(v);
    if (n > 0) return 'up';
    if (n < 0) return 'down';
    return 'flat';
  }

  /** 指数 → 行情工作台可查询代码（sh000001 / sz399006） */
  function indexQueryCode(ix) {
    const code = String((ix && ix.code) || '').replace(/\D/g, '');
    if (!code) return '';
    let mk = String((ix && ix.market) || '').toLowerCase();
    if (mk !== 'sh' && mk !== 'sz') mk = /^39/.test(code) ? 'sz' : 'sh';
    return mk + code;
  }

  /**
   * ApiClient 归一化结果 → 渲染视图模型。
   * 失败时 ok=false + message；缺失聚合/涨停统计时对应段为 null，渲染层按段降级。
   */
  function buildViewModel(res) {
    const model = {
      ok: false, message: '', indices: [], breadth: null, limits: null,
      generatedAt: '', cache: '', partial: false,
    };
    if (!res || !res.ok || !res.data || typeof res.data !== 'object' || Array.isArray(res.data)) {
      model.message = (res && res.message) || '大盘数据暂不可用';
      return model;
    }
    const d = res.data;
    model.ok = true;
    model.cache = String((res.meta && res.meta.cache) || 'miss');
    model.partial = !!(res.meta && res.meta.partial);
    model.generatedAt = typeof d.generated_at === 'string' ? d.generated_at : '';

    const list = Array.isArray(d.indices) ? d.indices : [];
    model.indices = list
      .filter(function (x) { return x && x.name && x.price !== null && x.price !== undefined; })
      .map(function (x) {
        return {
          name: String(x.name),
          code: String(x.code || ''),
          queryCode: indexQueryCode(x),
          price: Number(x.price),
          changePct: (x.change_pct === null || x.change_pct === undefined) ? null : Number(x.change_pct),
          changeAmt: (x.change_amt === null || x.change_amt === undefined) ? null : Number(x.change_amt),
          amount: (x.amount === null || x.amount === undefined) ? null : Number(x.amount),
        };
      });

    const ag = (d.aggregate && typeof d.aggregate === 'object') ? d.aggregate : null;
    if (ag && typeof ag.up_count === 'number' && typeof ag.down_count === 'number') {
      const upR = typeof ag.up_ratio_pct === 'number' ? ag.up_ratio_pct : null;
      const downR = typeof ag.down_ratio_pct === 'number' ? ag.down_ratio_pct : null;
      const method = String(ag.method || '');
      model.breadth = {
        up: ag.up_count,
        down: ag.down_count,
        flat: typeof ag.flat_count === 'number' ? ag.flat_count : 0,
        upRatio: upR,
        downRatio: downR,
        flatRatio: (upR !== null && downR !== null) ? Math.max(0, Math.round((100 - upR - downR) * 100) / 100) : null,
        score: typeof ag.breadth_score === 'number' ? ag.breadth_score : null,
        sentiment: SENTIMENT_CN[ag.sentiment_label] || '',
        sentimentTone: SENTIMENT_TONE[ag.sentiment_label] || 'flat',
        method: method,
        methodLabel: (METHOD_CN[method] || {}).label || '统计',
        methodTitle: (METHOD_CN[method] || {}).title || '',
      };
    }

    const ls = (d.limit_stats && typeof d.limit_stats === 'object') ? d.limit_stats : null;
    if (ls && (typeof ls.limit_up_count === 'number' || typeof ls.limit_down_count === 'number')) {
      model.limits = {
        up: typeof ls.limit_up_count === 'number' ? ls.limit_up_count : null,
        down: typeof ls.limit_down_count === 'number' ? ls.limit_down_count : null,
      };
    }
    return model;
  }

  // ─────────────────────────────────────────────
  // 渲染
  // ─────────────────────────────────────────────

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function fmtClock(iso) {
    if (!iso) return '--:--';
    const d = new Date(iso);
    if (isNaN(d.getTime())) return '--:--';
    const p = function (n) { return n < 10 ? '0' + n : '' + n; };
    return p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds());
  }

  function chipHtml(ix) {
    const cls = trendClass(ix.changePct);
    const arrow = cls === 'up' ? '↑' : (cls === 'down' ? '↓' : '');
    const title = ix.name + ' ' + fmtPrice(ix.price)
      + ' · 涨跌 ' + fmtSigned(ix.changeAmt) + ' (' + fmtPct(ix.changePct) + ')'
      + (ix.amount ? ' · 成交 ' + fmtAmount(ix.amount) : '')
      + ' · 点击在行情工作台查看K线';
    const aria = ix.name + ' ' + fmtPrice(ix.price) + ' 点，'
      + (cls === 'up' ? '涨 ' : (cls === 'down' ? '跌 ' : '持平 ')) + fmtPct(ix.changePct)
      + '，点击查看K线';
    return '<button type="button" class="mo-chip" role="listitem" data-code="' + esc(ix.queryCode) + '"'
      + ' data-name="' + esc(ix.name) + '" title="' + esc(title) + '" aria-label="' + esc(aria) + '">'
      + '<span class="mo-chip-name">' + esc(ix.name) + '</span>'
      + '<span class="mo-chip-price ' + cls + '">' + esc(fmtPrice(ix.price)) + '</span>'
      + '<span class="mo-chip-chg ' + cls + '">' + esc((arrow ? arrow + ' ' : '') + fmtPct(ix.changePct)) + '</span>'
      + '</button>';
  }

  function breadthHtml(model) {
    const b = model.breadth;
    if (!b) return '';
    const titleParts = ['涨跌家数口径：' + (b.methodTitle || b.methodLabel)];
    if (model.partial) titleParts.push('本次为部分覆盖，计数可能偏少');
    if (b.score !== null) titleParts.push('市场宽度情绪分 ' + b.score + '（0-100，50 为均衡）');
    if (model.limits) titleParts.push('涨停/跌停为 ±9.8% 近似判定，非交易所精确口径');

    let meter = '';
    if (b.upRatio !== null && b.downRatio !== null) {
      meter = '<span class="mo-meter" aria-hidden="true">'
        + '<i class="up" style="width:' + b.upRatio + '%"></i>'
        + '<i class="flat" style="width:' + (b.flatRatio || 0) + '%"></i>'
        + '<i class="down" style="width:' + b.downRatio + '%"></i>'
        + '</span>';
    }

    let limits = '';
    if (model.limits) {
      limits = '<span class="mo-limits">'
        + '<b class="up">涨停 ' + (model.limits.up === null ? '—' : model.limits.up) + '</b>'
        + '<b class="down">跌停 ' + (model.limits.down === null ? '—' : model.limits.down) + '</b>'
        + '</span>';
    }

    const badgeText = b.sentiment || b.methodLabel;
    const badge = '<span class="mo-badge" data-tone="' + esc(b.sentiment ? b.sentimentTone : 'flat') + '">'
      + esc(badgeText) + (model.partial ? ' ·部分' : '') + '</span>';

    const aria = '全市场上涨 ' + b.up + ' 家，下跌 ' + b.down + ' 家，平盘 ' + b.flat + ' 家'
      + (model.limits ? '，涨停 ' + (model.limits.up === null ? '未知' : model.limits.up) + ' 家，跌停 ' + (model.limits.down === null ? '未知' : model.limits.down) + ' 家' : '');

    return '<div class="mo-breadth" title="' + esc(titleParts.join('；')) + '" aria-label="' + esc(aria) + '">'
      + '<span class="mo-counts">'
      + '<b class="up">涨 ' + b.up + '</b>'
      + meter
      + '<b class="down">跌 ' + b.down + '</b>'
      + '</span>'
      + limits
      + badge
      + '</div>';
  }

  function sideHtml(model, opts) {
    const stale = opts && opts.staleMessage;
    const clock = model && model.generatedAt ? fmtClock(model.generatedAt) : '--:--';
    const title = stale
      ? ('最近一次更新失败：' + stale + '。当前展示 ' + clock + ' 的快照。')
      : ('数据生成时间 ' + clock + (model && model.cache && model.cache.indexOf('hit') === 0 ? '（命中服务端缓存）' : ''));
    return '<div class="mo-side">'
      + '<span class="mo-updated' + (stale ? ' stale' : '') + '" title="' + esc(title) + '">' + esc(clock) + '</span>'
      + '<button type="button" class="mo-refresh" aria-label="刷新大盘概览" title="立即刷新">'
      + '<span class="ui-icon" aria-hidden="true"><svg><use href="#icon-refresh"></use></svg></span>'
      + '</button>'
      + '</div>';
  }

  function render(model, opts) {
    if (!el) return;
    const prevTrack = el.querySelector('.mo-indices');
    const scrollLeft = prevTrack ? prevTrack.scrollLeft : 0;

    // chips 在 .mo-scroll 内横向滚动；涨跌家数簇与刷新区常驻右侧，不随滚动截断
    el.innerHTML = '<div class="mo-scroll">'
      + '<div class="mo-indices" role="list" aria-label="主要指数行情">' + model.indices.map(chipHtml).join('') + '</div>'
      + '</div>'
      + breadthHtml(model)
      + sideHtml(model, opts);

    const track = el.querySelector('.mo-indices');
    if (track && scrollLeft) track.scrollLeft = scrollLeft;
    syncSpinner();
    syncOverflow();
  }

  function renderSkeleton() {
    if (!el) return;
    let chips = '';
    for (let i = 0; i < 5; i++) chips += '<span class="mo-skel mo-skel-chip" aria-hidden="true"></span>';
    el.innerHTML = '<div class="mo-scroll">'
      + '<div class="mo-indices">' + chips + '</div>'
      + '</div>'
      + '<div class="mo-breadth"><span class="mo-skel mo-skel-breadth" aria-hidden="true"></span></div>'
      + sideHtml(null, null);
    syncSpinner();
    syncOverflow();
  }

  function renderError(message) {
    if (!el) return;
    el.innerHTML = '<div class="mo-scroll"><div class="mo-error">'
      + '<span class="mo-error-text">⚠ 大盘概览加载失败：' + esc(message || '未知错误') + '</span>'
      + '<button type="button" class="btn-sm mo-retry">重试</button>'
      + '</div></div>'
      + sideHtml(null, null);
    syncSpinner();
    syncOverflow();
  }

  /** chips 溢出时给滚动区加右缘渐隐提示 */
  function syncOverflow() {
    if (!el) return;
    const scroll = el.querySelector('.mo-scroll');
    if (scroll) scroll.classList.toggle('has-overflow', scroll.scrollWidth > scroll.clientWidth + 2);
  }

  function syncSpinner() {
    if (!el) return;
    const btn = el.querySelector('.mo-refresh');
    if (btn) {
      btn.classList.toggle('loading', loading);
      btn.disabled = loading;
    }
  }

  // ─────────────────────────────────────────────
  // 请求与调度
  // ─────────────────────────────────────────────

  /**
   * @param {boolean} full true=含全市场扫描（涨跌家数精确口径+涨停统计，上游较重）；
   *                       false=仅指数行情（单个上游调用，首屏/休市轮询用）
   */
  function refresh(full) {
    if (!el || loading || !window.ApiClient) return;
    loading = true;
    syncSpinner();
    window.ApiClient.request(full ? API_URL : API_URL_LIGHT, {
      scope: SCOPE,
      label: LABEL,
      action: 'market_breadth',
      global: true,
    }).then(function (res) {
      loading = false;
      lastFetchAt = Date.now();
      const model = buildViewModel(res);
      if (model.ok) {
        if (full) lastFullAt = Date.now();
        // 轻量结果不覆盖已有的全量涨跌家数/涨停统计（下一次全量升级前保持口径一致）
        if (!full && lastModel && lastModel.breadth && lastModel.breadth.method !== 'index_constituent_counts') {
          model.breadth = lastModel.breadth;
          model.limits = lastModel.limits;
          model.partial = lastModel.partial;
        }
        lastModel = model;
        render(model);
      } else if (lastModel) {
        // 保留最近成功快照，仅在时间戳上标注失败
        render(lastModel, { staleMessage: model.message });
      } else {
        renderError(model.message);
      }
      schedule();
    });
  }

  function schedule() {
    if (timer) { clearTimeout(timer); timer = null; }
    if (document.visibilityState !== 'visible') return; // 隐藏时不排程，恢复可见时补刷
    timer = setTimeout(tick, pickIntervalMs());
  }

  function tick() {
    timer = null;
    if (!lastModel) {
      refresh(true); // 尚无数据：直接全量补齐（settle 后自动续约 schedule()）
      return;
    }
    if (isTradingSession()) {
      // 交易时段：指数行情每轮轻量刷新，全量扫描按最小间隔升级
      refresh(Date.now() - lastFullAt >= FULL_SCAN_MIN_GAP_MS);
      return;
    }
    schedule(); // 休市且已有数据：数据不会变化，跳过网络请求，仅续约检查等待开盘
  }

  /**
   * 全量扫描升级：等静态资源加载完、且页面网络静默后再拉。
   * 本地 php -S 单线程下扫描会阻塞其它请求，过早触发会撞上首屏 K线/行情/舆情链路，
   * 因此以"近 2.5s 无新完成的请求"为静默信号，最长等 20s 兜底。
   */
  function deferFullUpgrade() {
    const start = function () { whenNetworkQuiet(function () { refresh(true); }); };
    if (document.readyState === 'complete') start();
    else window.addEventListener('load', start, { once: true });
  }

  /** 包装 window.fetch 维护在飞计数：静默检测需要感知尚未完成的请求（含 main.js 裸 fetch） */
  function installFetchCounter() {
    if (!window.fetch || window.fetch.__moCounted) return;
    const orig = window.fetch;
    const wrapped = function () {
      inflightFetches++;
      const done = function () { inflightFetches = Math.max(0, inflightFetches - 1); };
      try {
        const p = orig.apply(this, arguments);
        p.then(done, done);
        return p;
      } catch (e) {
        done();
        throw e;
      }
    };
    wrapped.__moCounted = true;
    window.fetch = wrapped;
  }

  function whenNetworkQuiet(cb) {
    const startedAt = Date.now();
    const check = function () {
      if (Date.now() - startedAt > 20000) { cb(); return; }
      let busy = inflightFetches > 0;
      if (!busy) {
        try {
          // 再看同源 fetch/XHR 的近期完成记录：排除 Clarity 心跳、CDN 静态资源等持续性噪声
          const origin = window.location.origin;
          const now = performance.now();
          const entries = performance.getEntriesByType('resource');
          for (let i = entries.length - 1; i >= 0; i--) {
            const r = entries[i];
            if (r.name.indexOf(origin) !== 0) continue;
            if (r.initiatorType !== 'fetch' && r.initiatorType !== 'xmlhttprequest') continue;
            if (now - r.responseEnd < 2500) { busy = true; break; }
          }
        } catch (e) { /* Performance API 不可用时直接放行 */ }
      }
      if (busy) setTimeout(check, 2000);
      else cb();
    };
    setTimeout(check, 2500);
  }

  function onVisibility() {
    if (document.visibilityState !== 'visible') {
      if (timer) { clearTimeout(timer); timer = null; }
      return;
    }
    if (Date.now() - lastFetchAt >= pickIntervalMs()) tick();
    else schedule();
  }

  function onClick(e) {
    const chip = e.target.closest ? e.target.closest('.mo-chip') : null;
    if (chip && chip.dataset.code) {
      window.AppBus.emit('market-overview:navigate', { code: chip.dataset.code, name: chip.dataset.name || '' });
      return;
    }
    if (e.target.closest && (e.target.closest('.mo-refresh') || e.target.closest('.mo-retry'))) {
      refresh(true);
    }
  }

  function init() {
    el = document.getElementById('market-overview');
    if (!el || !window.ApiClient || !window.AppBus) return;
    installFetchCounter(); // 尽早安装：main.js 首屏请求在本 init 之后才发出
    el.addEventListener('click', onClick);
    document.addEventListener('visibilitychange', onVisibility);
    if (window.AppUtil && window.addEventListener) {
      window.addEventListener('resize', window.AppUtil.throttle(syncOverflow, 200));
    }
    window.AppBus.on('data-status:retry', function (ev) {
      if (ev && ev.scope === SCOPE) refresh(true);
    });
    renderSkeleton();
    refresh(false);      // 首屏：轻量指数行情，毫秒级返回
    deferFullUpgrade();  // 全市场涨跌家数/涨停统计延迟异步升级
  }

  window.MarketOverview = {
    init: init,
    refresh: refresh,
    _pure: {
      isTradingSession: isTradingSession,
      pickIntervalMs: pickIntervalMs,
      fmtAmount: fmtAmount,
      fmtPct: fmtPct,
      fmtPrice: fmtPrice,
      trendClass: trendClass,
      indexQueryCode: indexQueryCode,
      buildViewModel: buildViewModel,
    },
  };
})();
