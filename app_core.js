/**
 * app_core.js — 统一核心层
 *
 * 提供：
 *   AppBus     —— 轻量事件总线（emit/on/off/once），跨模块解耦通信
 *   ApiClient  —— 统一投资数据请求客户端：envelope 解析、请求去重、AbortController、
 *                 生命周期回调（供 data_status.js 订阅）
 *   AppUtil    —— 通用工具（代码归一、节流防抖、可见性）
 *
 * 加载顺序：核心层(本文件) → 自选/状态层 → 策略 → main.js
 * 不依赖任何其它本地脚本；main.js 及后续模块可安全引用 window.AppBus / window.ApiClient。
 */
(function () {
  'use strict';

  // ─────────────────────────────────────────────
  // AppBus — 事件总线
  // ─────────────────────────────────────────────
  const AppBus = (function () {
    const listeners = new Map(); // event -> Set<fn>

    function on(event, fn) {
      if (!listeners.has(event)) listeners.set(event, new Set());
      listeners.get(event).add(fn);
      return () => off(event, fn);
    }

    function once(event, fn) {
      const wrap = (payload) => {
        off(event, wrap);
        fn(payload);
      };
      return on(event, wrap);
    }

    function off(event, fn) {
      const set = listeners.get(event);
      if (set) set.delete(fn);
    }

    function emit(event, payload) {
      const set = listeners.get(event);
      if (!set) return;
      // 复制一份，避免回调内增删监听导致遍历错乱
      Array.from(set).forEach((fn) => {
        try {
          fn(payload);
        } catch (e) {
          if (window.console) console.error('[AppBus] listener error for ' + event, e);
        }
      });
    }

    return { on, once, off, emit };
  })();

  // ─────────────────────────────────────────────
  // AppUtil — 通用工具
  // ─────────────────────────────────────────────
  const AppUtil = {
    /** 归一化股票代码为 sh600519 / sz000001 / bj430047 小写前缀格式 */
    normalizeStockCode(input) {
      if (!input) return '';
      let c = String(input).trim().toLowerCase();
      const m = c.match(/^(sh|sz|bj)?\s*(\d{6})$/);
      if (m) {
        let prefix = m[1];
        const digits = m[2];
        if (!prefix) {
          // 依首位数字推断市场
          if (/^(6|9)/.test(digits)) prefix = 'sh';
          else if (/^(0|2|3)/.test(digits)) prefix = 'sz';
          else if (/^(4|8)/.test(digits)) prefix = 'bj';
          else prefix = 'sh';
        }
        return prefix + digits;
      }
      return c;
    },

    /** 归一化基金代码为 6 位数字 */
    normalizeFundCode(input) {
      if (!input) return '';
      const m = String(input).trim().match(/\d{6}/);
      return m ? m[0] : '';
    },

    debounce(fn, wait) {
      let t = null;
      return function (...args) {
        if (t) clearTimeout(t);
        t = setTimeout(() => fn.apply(this, args), wait);
      };
    },

    throttle(fn, wait) {
      let last = 0;
      let timer = null;
      return function (...args) {
        const now = Date.now();
        const remaining = wait - (now - last);
        if (remaining <= 0) {
          if (timer) { clearTimeout(timer); timer = null; }
          last = now;
          fn.apply(this, args);
        } else if (!timer) {
          timer = setTimeout(() => {
            last = Date.now();
            timer = null;
            fn.apply(this, args);
          }, remaining);
        }
      };
    },

    isPageVisible() {
      return document.visibilityState === 'visible';
    },

    nowIso() {
      return new Date().toISOString();
    },
  };

  // ─────────────────────────────────────────────
  // ApiClient — 统一投资数据请求客户端
  // ─────────────────────────────────────────────
  const ApiClient = (function () {
    // 在途请求：key -> { promise, controller }
    const inflight = new Map();

    /**
     * 归一化后端响应为统一内部形态：
     *   { ok, status, data, meta, dataStatus, code, message, source, raw }
     * 兼容三类后端：
     *   1. envelope（含 meta.data_status）
     *   2. 半保留（含 meta 但无 data_status）
     *   3. 裸格式（纯数组 / {success,data} / {error}）
     */
    function normalizeResponse(json, fallbackAction) {
      const out = {
        ok: false,
        status: 'unknown',
        data: null,
        meta: {},
        dataStatus: null,
        code: null,
        message: '',
        source: '',
        action: fallbackAction || '',
        raw: json,
      };

      // 裸数组（hot_stocks_api 旧格式）
      if (Array.isArray(json)) {
        out.ok = true;
        out.status = 'success';
        out.data = json;
        out.dataStatus = synthDataStatus(true, json);
        return out;
      }

      if (json && typeof json === 'object') {
        out.source = json.source || '';
        out.action = json.action || out.action;
        out.meta = json.meta || {};
        out.message = json.message || json.error_message || json.error || '';
        out.code = json.code || null;

        if (json.success === true) {
          out.ok = true;
          out.status = json.status || 'success';
          out.data = json.data !== undefined ? json.data : json;
        } else if (json.success === false) {
          out.ok = false;
          out.status = json.status || json.code || 'error';
          out.data = json.data !== undefined ? json.data : null;
        } else if (json.error) {
          out.ok = false;
          out.status = 'error';
          out.message = json.error;
        } else {
          // 无 success 字段但有内容，视为成功裸对象
          out.ok = true;
          out.status = 'success';
          out.data = json.data !== undefined ? json.data : json;
        }

        // 优先使用后端归一化 data_status；否则本地合成
        if (out.meta && out.meta.data_status) {
          out.dataStatus = out.meta.data_status;
        } else {
          out.dataStatus = synthDataStatus(out.ok, out.data, out.meta, out.status);
        }
        return out;
      }

      // 其它（字符串/空）
      out.ok = false;
      out.status = 'parse_error';
      out.message = '响应解析失败';
      out.dataStatus = synthDataStatus(false, null);
      return out;
    }

    /** 后端未提供 data_status 时，前端合成一份等价结构 */
    function synthDataStatus(ok, data, meta, status) {
      meta = meta || {};
      const cache = String(meta.cache || 'miss');
      let freshness = 'unknown';
      if (cache === 'stale' || cache === 'stale_fallback') freshness = 'stale';
      else if (cache === 'hit' || cache === 'hit_after_wait') freshness = 'cached';
      else if (cache === 'miss' || cache === 'miss_after_wait') freshness = ok ? 'fresh' : 'unknown';

      let route = 'primary';
      const hasData = data !== null && (!Array.isArray(data) || data.length > 0);
      if (!ok || !hasData) route = 'failed';
      else if (status === 'fallback_used' || meta.fallback) route = 'fallback';

      let completeness = 'complete';
      if (!ok) completeness = 'unknown';
      else if (Array.isArray(data) && data.length === 0) completeness = 'empty';
      else if (meta.partial) completeness = 'partial';

      let severity = 'ok';
      const dataRecency = String(meta.data_recency || 'unknown');
      const nonRealtimeCount = Math.max(0, Number(meta.non_realtime_count) || 0);
      if (route === 'failed') severity = 'error';
      else if (freshness === 'stale' || completeness === 'partial' || nonRealtimeCount > 0 || dataRecency === 'dated' || dataRecency === 'mixed') severity = 'warning';
      else if (route === 'fallback' || freshness === 'cached') severity = 'info';

      return { severity, freshness, completeness, route, data_recency: dataRecency, non_realtime_count: nonRealtimeCount, warnings: [] };
    }

    /**
     * 统一请求。
     *
     * @param {string} url    完整 URL（相对站点根）
     * @param {object} opts   {
     *   scope        —— 唯一请求域标识（用于状态聚合与去重），如 'stock:quote'
     *   label        —— 中文名称，用于状态详情展示
     *   page         —— 所属页面 tab（stock/fund/watch-center...），用于按页聚合
     *   global       —— 是否影响全局状态条（默认 true）
     *   dedupeKey    —— 去重键（默认 url）；相同 key 的在途请求复用同一 Promise
     *   envelope     —— 是否自动追加 format=envelope（默认 true）
     *   signal       —— 外部 AbortSignal（可选）
     *   silent       —— true 则不广播生命周期事件（瞬时请求如搜索联想）
     *   fetchOpts    —— 透传给 fetch 的额外项（method/headers/body）
     * }
     * @returns {Promise<{ok,status,data,meta,dataStatus,code,message,source,action,raw,aborted}>}
     */
    function request(url, opts) {
      opts = opts || {};
      const scope = opts.scope || url;
      const envelope = opts.envelope !== false;
      const silent = !!opts.silent;

      let finalUrl = url;
      if (envelope && finalUrl.indexOf('format=') === -1) {
        finalUrl += (finalUrl.indexOf('?') === -1 ? '?' : '&') + 'format=envelope';
      }

      const dedupeKey = opts.dedupeKey || finalUrl;

      // 去重：相同在途请求复用
      if (inflight.has(dedupeKey)) {
        return inflight.get(dedupeKey).promise;
      }

      const controller = new AbortController();
      const signal = opts.signal
        ? anySignal([opts.signal, controller.signal])
        : controller.signal;

      if (!silent) {
        AppBus.emit('api:lifecycle', {
          phase: 'loading',
          scope,
          label: opts.label || scope,
          page: opts.page || null,
          global: opts.global !== false,
        });
      }

      const fetchOpts = Object.assign({ signal, cache: 'no-store' }, opts.fetchOpts || {});

      const promise = fetch(finalUrl, fetchOpts)
        .then((resp) => resp.json().catch(() => ({ success: false, message: 'JSON 解析失败', status: 'parse_error' })))
        .then((json) => {
          const norm = normalizeResponse(json, opts.action);
          norm.aborted = false;
          if (!silent) {
            AppBus.emit('api:lifecycle', {
              phase: norm.ok ? 'settled' : 'error',
              scope,
              label: opts.label || scope,
              page: opts.page || null,
              global: opts.global !== false,
              result: norm,
            });
          }
          return norm;
        })
        .catch((err) => {
          const aborted = err && (err.name === 'AbortError');
          const norm = {
            ok: false,
            aborted,
            status: aborted ? 'aborted' : 'network_error',
            data: null,
            meta: {},
            dataStatus: synthDataStatus(false, null),
            code: aborted ? 'aborted' : 'network_error',
            message: aborted ? '请求已取消' : (err && err.message ? err.message : '网络请求失败'),
            source: '',
            action: opts.action || '',
            raw: null,
          };
          if (!silent && !aborted) {
            AppBus.emit('api:lifecycle', {
              phase: 'error',
              scope,
              label: opts.label || scope,
              page: opts.page || null,
              global: opts.global !== false,
              result: norm,
            });
          } else if (!silent && aborted) {
            AppBus.emit('api:lifecycle', {
              phase: 'aborted',
              scope,
              label: opts.label || scope,
              page: opts.page || null,
              global: opts.global !== false,
            });
          }
          return norm;
        })
        .finally(() => {
          inflight.delete(dedupeKey);
        });

      inflight.set(dedupeKey, { promise, controller });
      return promise;
    }

    /** 主动取消某个去重键的在途请求 */
    function abort(dedupeKey) {
      const entry = inflight.get(dedupeKey);
      if (entry) {
        try { entry.controller.abort(); } catch (e) {}
        inflight.delete(dedupeKey);
      }
    }

    function inflightCount() {
      return inflight.size;
    }

    /** 合并多个 AbortSignal（任一触发即 abort） */
    function anySignal(signals) {
      const controller = new AbortController();
      const onAbort = () => {
        controller.abort();
        signals.forEach((s) => s.removeEventListener && s.removeEventListener('abort', onAbort));
      };
      signals.forEach((s) => {
        if (s.aborted) { controller.abort(); return; }
        s.addEventListener && s.addEventListener('abort', onAbort);
      });
      return controller.signal;
    }

    return { request, abort, inflightCount, normalizeResponse, synthDataStatus };
  })();

  // 导出到全局
  window.AppBus = AppBus;
  window.ApiClient = ApiClient;
  window.AppUtil = AppUtil;
})();
