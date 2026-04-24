// ============================================================
// FuckAshare - A股智能分析平台 主脚本
// ============================================================

// 安全降级
if (typeof window !== 'undefined') {
    if (typeof window.marked === 'undefined') {
        window.marked = { parse: t => t, setOptions: () => {}, Renderer: function(){} };
    }
    if (typeof window.DOMPurify === 'undefined') {
        window.DOMPurify = { sanitize: h => h };
    }
}

// ============================================================
// 主题管理模块
// ============================================================
const ThemeManager = {
    STORAGE_KEY: 'fa_theme',
    currentTheme: 'system',
    systemDarkQuery: null,

    init() {
        // 从 localStorage 读取保存的主题（try/catch 防止隐私模式/存储禁用抛异常）
        try {
            const saved = localStorage.getItem(this.STORAGE_KEY);
            if (saved && ['light', 'dark', 'system'].includes(saved)) {
                this.currentTheme = saved;
            }
        } catch (e) { /* 存储不可用时保持默认 system */ }

        // 监听系统主题变化（兼容旧版 Safari 的 addListener 降级）
        this.systemDarkQuery = window.matchMedia('(prefers-color-scheme: dark)');
        const handler = () => {
            if (this.currentTheme === 'system') {
                this.applyTheme('system');
            }
        };
        if (this.systemDarkQuery.addEventListener) {
            this.systemDarkQuery.addEventListener('change', handler);
        } else if (this.systemDarkQuery.addListener) {
            this.systemDarkQuery.addListener(handler);
        }

        // 应用主题
        this.applyTheme(this.currentTheme, false);

        // 绑定按钮
        document.querySelectorAll('.theme-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const theme = btn.dataset.theme;
                if (theme && theme !== this.currentTheme) {
                    this.switchWithAnimation(theme, e);
                }
            });
        });
    },

    applyTheme(theme, animate = true) {
        this.currentTheme = theme;
        try { localStorage.setItem(this.STORAGE_KEY, theme); } catch (e) { /* 存储不可用时静默忽略 */ }
        document.documentElement.setAttribute('data-theme', theme);

        // 更新按钮激活状态
        document.querySelectorAll('.theme-btn').forEach(btn => {
            btn.classList.toggle('active', btn.dataset.theme === theme);
        });

        // 更新图表主题
        if (typeof ChartModule !== 'undefined' && ChartModule.chart) {
            ChartModule.updateChartTheme();
        }

        // GSAP 入场动画
        if (animate && typeof gsap !== 'undefined') {
            gsap.fromTo('body', 
                { opacity: 0.92 },
                { opacity: 1, duration: 0.4, ease: 'power2.out' }
            );
        }
    },

    switchWithAnimation(theme, event) {
        const overlay = document.getElementById('theme-transition-overlay');
        if (!overlay || typeof gsap === 'undefined') {
            this.applyTheme(theme);
            return;
        }

        // 从点击位置扩散的圆形遮罩
        const x = event?.clientX || window.innerWidth / 2;
        const y = event?.clientY || window.innerHeight / 2;
        overlay.style.setProperty('--transition-x', x + 'px');
        overlay.style.setProperty('--transition-y', y + 'px');

        // 判断目标主题是否为浅色
        const isLight = theme === 'light' || 
            (theme === 'system' && window.matchMedia('(prefers-color-scheme: light)').matches);

        const color = isLight 
            ? 'radial-gradient(circle at ' + x + 'px ' + y + 'px, rgba(232,240,228,0.6) 0%, transparent 70%)'
            : 'radial-gradient(circle at ' + x + 'px ' + y + 'px, rgba(13,17,23,0.6) 0%, transparent 70%)';

        gsap.set(overlay, { background: color });
        
        const tl = gsap.timeline();
        tl.to(overlay, {
            opacity: 1,
            duration: 0.3,
            ease: 'power2.inOut'
        });
        tl.call(() => {
            this.applyTheme(theme);
        });
        tl.to(overlay, {
            opacity: 0,
            duration: 0.4,
            ease: 'power2.out'
        });
    },

    // 获取当前实际生效的主题（解析 system）
    getEffectiveTheme() {
        if (this.currentTheme !== 'system') return this.currentTheme;
        return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    },

    // 获取当前主题下的 CSS 变量值
    getCSSVar(name) {
        return getComputedStyle(document.documentElement).getPropertyValue(name).trim();
    }
};

// ============================================================
// GSAP 动画模块 — 深度集成 Timeline / ScrollTrigger / matchMedia / quickTo
// ============================================================
const AnimationManager = {
    // gsap.matchMedia() 实例——响应式 + prefers-reduced-motion
    mm: null,
    // gsap.quickTo() 缓存——高频更新复用同一 tween
    _quickTos: {},

    init() {
        if (typeof gsap === 'undefined') return;

        // 注册 ScrollTrigger 插件
        if (typeof ScrollTrigger !== 'undefined') {
            gsap.registerPlugin(ScrollTrigger);
        }

        // 全局默认值
        gsap.defaults({ ease: 'power2.out', duration: 0.4 });

        // 创建 matchMedia 实例
        this.mm = gsap.matchMedia();

        // 1) Timeline 编排入场
        this.entranceAnimations();

        // 2) ScrollTrigger 滚动触发
        this.scrollAnimations();

        // 3) 按钮点击波纹
        this.bindRippleEffect();
    },

    // ════════════════════════════════════════════
    //  1. 页面入场 — Timeline 编排 + matchMedia 无障碍
    // ════════════════════════════════════════════
    entranceAnimations() {
        this.mm.add({
            reduceMotion: '(prefers-reduced-motion: reduce)'
        }, (context) => {
            const { reduceMotion } = context.conditions;
            // 用户偏好减少动效时，duration 全部归零
            const d = reduceMotion ? 0 : 0.35;

            // 用 Timeline + labels 编排入场序列，避免散落 delay
            const tl = gsap.timeline({
                defaults: { autoAlpha: 0, duration: d, ease: 'power2.out' }
            });

            tl.from('.top-nav', { clearProps: 'autoAlpha' })
              .addLabel('brand')
              .from('.nav-brand', { clearProps: 'autoAlpha' }, 'brand')
              .addLabel('tabs', '-=0.15')
              .from('.nav-tab', { stagger: 0.04, clearProps: 'autoAlpha' }, 'tabs')
              .addLabel('actions', 'tabs+=0.08')
              .from('.theme-switcher', { clearProps: 'autoAlpha' }, 'actions')
              .from('#watchlist-toggle', { clearProps: 'autoAlpha' }, 'actions')
              .addLabel('footer', '-=0.1')
              .from('.site-footer', { clearProps: 'autoAlpha' }, 'footer');

            // 兜底恢复——极端情况下也不会停留不可见
            window.setTimeout(() => {
                document.querySelectorAll(
                    '.nav-tab, .theme-switcher, #watchlist-toggle, .site-footer, .top-nav, .nav-brand'
                ).forEach(el => { el.style.opacity = '1'; el.style.visibility = 'visible'; });
            }, 1500);
        });
    },

    // ════════════════════════════════════════════
    //  2. ScrollTrigger — 滚动触发卡片/页脚淡入
    // ════════════════════════════════════════════
    scrollAnimations() {
        if (typeof ScrollTrigger === 'undefined') return;

        this.mm.add({
            reduceMotion: '(prefers-reduced-motion: reduce)',
            isDesktop: '(min-width: 800px)'
        }, (context) => {
            const { reduceMotion } = context.conditions;

            // 当前可见 Tab 面板中的卡片——批量淡入
            ScrollTrigger.batch('.tab-panel.active .card', {
                interval: 0.1,
                batchMax: 6,
                onEnter: (batch) => {
                    gsap.to(batch, {
                        autoAlpha: 1, duration: reduceMotion ? 0 : 0.35,
                        stagger: 0.05, ease: 'power2.out', overwrite: true
                    });
                },
                start: 'top 92%',
                once: true
            });

            // 页脚滚动触发
            gsap.from('.site-footer', {
                autoAlpha: 0,
                scrollTrigger: {
                    trigger: '.site-footer',
                    start: 'top 95%',
                    once: true
                },
                duration: reduceMotion ? 0 : 0.4,
                clearProps: 'autoAlpha'
            });
        });
    },

    // Tab 切换后刷新 ScrollTrigger 位置计算
    refreshAfterTabSwitch() {
        if (typeof ScrollTrigger !== 'undefined') {
            ScrollTrigger.refresh();
        }
    },

    // ════════════════════════════════════════════
    //  3. 按钮点击波纹
    // ════════════════════════════════════════════
    bindRippleEffect() {
        document.addEventListener('click', (e) => {
            const btn = e.target.closest('.btn-primary, .btn-sm, .btn-accent, .btn-ai, .nav-tab, .theme-btn');
            if (!btn) return;

            const ripple = document.createElement('span');
            ripple.classList.add('ripple');
            const rect = btn.getBoundingClientRect();
            const size = Math.max(rect.width, rect.height);
            ripple.style.width = ripple.style.height = size + 'px';
            ripple.style.left = (e.clientX - rect.left - size / 2) + 'px';
            ripple.style.top = (e.clientY - rect.top - size / 2) + 'px';

            btn.style.position = btn.style.position || 'relative';
            btn.style.overflow = 'hidden';
            btn.appendChild(ripple);

            if (typeof gsap !== 'undefined') {
                gsap.to(ripple, {
                    scale: 4, autoAlpha: 0, duration: 0.5, ease: 'power2.out',
                    onComplete: () => ripple.remove()
                });
            } else {
                setTimeout(() => ripple.remove(), 500);
            }
        });
    },

    // ════════════════════════════════════════════
    //  quickTo —— 高频价格追踪，复用同一 tween
    // ════════════════════════════════════════════
    getQuickTo(el, prop, vars = {}) {
        const key = el.dataset.qtId || (el.dataset.qtId = 'qt_' + Math.random().toString(36).slice(2, 8));
        if (!this._quickTos[key]) {
            this._quickTos[key] = gsap.quickTo(el, prop, { duration: 0.35, ease: 'power2.out', ...vars });
        }
        return this._quickTos[key];
    },

    // ════════════════════════════════════════════
    //  实用型动效 API —— 供业务模块按需调用
    // ════════════════════════════════════════════

    /**
     * 价格闪烁 —— 股价/涨跌更新时短暂高亮背景
     * @param {HTMLElement} el    目标元素
     * @param {'up'|'down'|'flat'} direction  涨跌方向
     */
    flashPrice(el, direction) {
        if (!el || typeof gsap === 'undefined') return;
        const isUp = direction === 'up';
        const isDown = direction === 'down';
        const flashColor = isUp ? 'rgba(199, 58, 24, 0.18)' : isDown ? 'rgba(46, 139, 87, 0.18)' : 'rgba(95, 117, 95, 0.1)';
        gsap.fromTo(el,
            { backgroundColor: flashColor },
            { backgroundColor: 'transparent', duration: 0.8, ease: 'power2.out' }
        );
    },

    /**
     * 条形图增长 —— 用 scaleX（GPU 合成层）替代 width（触发 layout）
     * @param {string} selector 条形 fill 元素选择器
     */
    animateBars(selector) {
        if (typeof gsap === 'undefined') return;
        document.querySelectorAll(selector).forEach(bar => {
            // 如果已经可见则跳过
            const currentWidth = bar.style.width;
            if (!currentWidth || currentWidth === '0px' || currentWidth === '0%') return;
            gsap.fromTo(bar,
                { scaleX: 0, transformOrigin: 'left center' },
                { scaleX: 1, transformOrigin: 'left center', duration: 0.6, ease: 'power2.out', clearProps: 'scaleX' }
            );
        });
    },

    /**
     * 数字递增动画 —— 从 0 递增到目标值
     * @param {HTMLElement} el   目标元素
     * @param {number} end       目标数值
     * @param {number} duration  动画时长（秒）
     * @param {number} decimals  小数位数
     */
    countUp(el, end, duration = 0.5, decimals = 2) {
        if (!el || typeof gsap === 'undefined') { if (el) el.textContent = end.toFixed(decimals); return; }
        const obj = { val: 0 };
        gsap.to(obj, {
            val: end, duration, ease: 'power2.out',
            onUpdate: () => { el.textContent = obj.val.toFixed(decimals); }
        });
    },

    /**
     * 卡片数据刷新高亮 —— 边框短暂亮绿
     * @param {HTMLElement} card  目标 .card 元素
     */
    pulseCard(card) {
        if (!card || typeof gsap === 'undefined') return;
        const orig = getComputedStyle(card).borderColor;
        gsap.fromTo(card,
            { borderColor: 'var(--accent-green)' },
            { borderColor: orig, duration: 0.8, ease: 'power2.out' }
        );
    },

    /**
     * 行交错淡入——使用 autoAlpha（不可见时自动 visibility:hidden）
     * @param {string} selector 目标行选择器
     */
    animateRows(selector) {
        if (typeof gsap === 'undefined') return;
        gsap.from(selector, {
            autoAlpha: 0, duration: 0.2, stagger: 0.015, ease: 'power2.out', clearProps: 'autoAlpha'
        });
    },

    // ── 实时看板卡片交错淡入 ──
    animateRealtimeCards() {
        if (typeof gsap === 'undefined') return;
        gsap.from('.realtime-card', {
            autoAlpha: 0, duration: 0.3, stagger: 0.04, ease: 'power2.out', clearProps: 'autoAlpha'
        });
    },

    // ── 基金卡片交错淡入 ──
    animateFundCards() {
        if (typeof gsap === 'undefined') return;
        gsap.from('.fund-card', {
            autoAlpha: 0, duration: 0.3, stagger: 0.03, ease: 'power2.out', clearProps: 'autoAlpha'
        });
    },

    // ── 热门股票行交错淡入 ──
    animateHotRows() {
        this.animateRows('#hot-stocks-data tr');
    },

    // ── 侧边栏自选股条目交错淡入 ──
    animateSidebarOpen() {
        if (typeof gsap === 'undefined') return;
        gsap.from('.wl-item', {
            autoAlpha: 0, duration: 0.25, stagger: 0.04, ease: 'power2.out', delay: 0.1, clearProps: 'autoAlpha'
        });
    },

    // ── 弹窗淡入 ──
    animateModalIn() {
        if (typeof gsap === 'undefined') return;
        gsap.from('.modal-dialog', {
            autoAlpha: 0, duration: 0.3, ease: 'power2.out', clearProps: 'autoAlpha'
        });
    }
};

// ============================================================
// 全局状态
// ============================================================
const AI_SYSTEM_PROMPT = '你是一位专业的股票分析师，擅长解读股票数据并给出建议。';
const AI_CONTEXT_LIMIT = 255000;

const APP = {
    // AI聊天
    chatContainer: null,
    userInput: null,
    messageHistory: [{ role: 'system', content: AI_SYSTEM_PROMPT }],
    currentSessionId: null,
    aiContextLimit: AI_CONTEXT_LIMIT,
    // 图表
    chart: null,
    candleSeries: null,
    volumeSeries: null,
    indicatorSeries: {},
    currentStockCode: '',
    currentStockData: [],
    // 自选股
    watchlist: JSON.parse(localStorage.getItem('fa_watchlist') || '[]'),
    // 自选基金
    fundWatchlist: JSON.parse(localStorage.getItem('fa_fund_watchlist') || '[]'),
    // 实时看板
    realtimeCodes: JSON.parse(localStorage.getItem('fa_realtime_codes') || '[]'),
    realtimeTimer: null,
    // 超级查询
    allStocksData: {},
    // 查询时是否触发AI分析
    queryWithAI: false,
    // 配置
    config: { maxQueryStocks: 50, autoRefreshInterval: 30 },
    // AI 顾问面板状态
    advisorOpen: false,
    advisorExpanded: false,
    advisorUnread: 0,
    advisorThinking: false,
    advisorContext: { stock: '', tab: 'stock', source: '' },
    advisorChatContainer: null,
    advisorUserInput: null,
    advisorLastFocusedElement: null,
    advisorScrollLocked: false,
    advisorRequestVersion: 0
};

// ============================================================
// 工具函数
// ============================================================
function formatVolume(v) {
    v = parseFloat(v);
    if (isNaN(v)) return '-';
    if (v >= 1e8) return (v / 1e8).toFixed(2) + '亿';
    if (v >= 1e4) return (v / 1e4).toFixed(2) + '万';
    return v.toFixed(0);
}

function formatAmount(n) {
    n = parseFloat(n);
    if (isNaN(n)) return '-';
    if (Math.abs(n) >= 1e8) return (n / 1e8).toFixed(2) + '亿';
    if (Math.abs(n) >= 1e4) return (n / 1e4).toFixed(2) + '万';
    return n.toFixed(2);
}

function formatPct(v) {
    v = parseFloat(v);
    if (isNaN(v)) return '-';
    return (v >= 0 ? '+' : '') + v.toFixed(2) + '%';
}

function colorClass(v) {
    v = parseFloat(v);
    if (v > 0) return 'up';
    if (v < 0) return 'down';
    return 'flat';
}

function colorStyle(v) {
    v = parseFloat(v);
    if (v > 0) return 'color:var(--price-up)';
    if (v < 0) return 'color:var(--price-down)';
    return 'color:var(--price-flat)';
}

// 股票代码转东方财富secid格式
function codeToSecid(code) {
    code = code.trim();
    if (code.includes('.XSHG')) return '1.' + code.replace('.XSHG', '');
    if (code.includes('.XSHE')) return '0.' + code.replace('.XSHE', '');
    if (code.toLowerCase().startsWith('sh')) return '1.' + code.substring(2);
    if (code.toLowerCase().startsWith('sz')) return '0.' + code.substring(2);
    if (/^6\d{5}$/.test(code)) return '1.' + code;
    if (/^(0|3)\d{5}$/.test(code)) return '0.' + code;
    return '1.' + code;
}

// 标准化股票代码为 sh/sz 格式
function normalizeCode(code) {
    code = code.trim();
    if (code.includes('.XSHG')) return 'sh' + code.replace('.XSHG', '');
    if (code.includes('.XSHE')) return 'sz' + code.replace('.XSHE', '');
    if (/^[0-9]{6}$/.test(code)) {
        return (code.startsWith('6') ? 'sh' : 'sz') + code;
    }
    return code.toLowerCase();
}

// ============================================================
// 技术指标计算
// ============================================================
const Indicators = {
    // 移动平均线
    MA(data, period) {
        const result = [];
        for (let i = 0; i < data.length; i++) {
            if (i < period - 1) { result.push(null); continue; }
            let sum = 0;
            for (let j = i - period + 1; j <= i; j++) sum += data[j];
            result.push(sum / period);
        }
        return result;
    },

    // EMA指数移动平均
    EMA(data, period) {
        const result = [];
        const k = 2 / (period + 1);
        result[0] = data[0];
        for (let i = 1; i < data.length; i++) {
            result[i] = data[i] * k + result[i - 1] * (1 - k);
        }
        return result;
    },

    // MACD
    MACD(closes, fast = 12, slow = 26, signal = 9) {
        const emaFast = this.EMA(closes, fast);
        const emaSlow = this.EMA(closes, slow);
        const dif = emaFast.map((v, i) => v - emaSlow[i]);
        const dea = this.EMA(dif, signal);
        const macd = dif.map((v, i) => (v - dea[i]) * 2);
        return { dif, dea, macd };
    },

    // RSI
    RSI(closes, period = 14) {
        const result = [];
        let gains = 0, losses = 0;
        for (let i = 0; i < closes.length; i++) {
            if (i === 0) { result.push(null); continue; }
            const change = closes[i] - closes[i - 1];
            if (i <= period) {
                if (change > 0) gains += change; else losses -= change;
                if (i < period) { result.push(null); continue; }
                const avgGain = gains / period;
                const avgLoss = losses / period;
                result.push(avgLoss === 0 ? 100 : 100 - 100 / (1 + avgGain / avgLoss));
            } else {
                const prevGain = result[i - 1] !== null ? (100 / (100 - result[i - 1]) - 1) : 0;
                const avgGain = (prevGain * (period - 1) + (change > 0 ? change : 0)) / period;
                const avgLoss = (prevGain * (period - 1) + (change < 0 ? -change : 0)) / period;
                result.push(avgLoss === 0 ? 100 : 100 - 100 / (1 + avgGain / avgLoss));
            }
        }
        return result;
    },

    // KDJ
    KDJ(highs, lows, closes, n = 9, m1 = 3, m2 = 3) {
        const K = [], D = [], J = [];
        let prevK = 50, prevD = 50;
        for (let i = 0; i < closes.length; i++) {
            if (i < n - 1) { K.push(null); D.push(null); J.push(null); continue; }
            let highN = -Infinity, lowN = Infinity;
            for (let j = i - n + 1; j <= i; j++) {
                highN = Math.max(highN, highs[j]);
                lowN = Math.min(lowN, lows[j]);
            }
            const rsv = highN === lowN ? 50 : (closes[i] - lowN) / (highN - lowN) * 100;
            const k = (2 / m1) * prevK + (1 / m1) * rsv;
            const d = (2 / m2) * prevD + (1 / m2) * k;
            const j = 3 * k - 2 * d;
            K.push(k); D.push(d); J.push(j);
            prevK = k; prevD = d;
        }
        return { k: K, d: D, j: J };
    },

    // BOLL布林带
    BOLL(closes, period = 20, mult = 2) {
        const mid = this.MA(closes, period);
        const upper = [], lower = [];
        for (let i = 0; i < closes.length; i++) {
            if (mid[i] === null) { upper.push(null); lower.push(null); continue; }
            let sum = 0;
            for (let j = i - period + 1; j <= i; j++) sum += (closes[j] - mid[i]) ** 2;
            const std = Math.sqrt(sum / period);
            upper.push(mid[i] + mult * std);
            lower.push(mid[i] - mult * std);
        }
        return { mid, upper, lower };
    }
};

// ============================================================
// 图表模块
// ============================================================
const ChartModule = {
    indicatorSeries: {},

    // 获取当前主题的图表配色
    getChartColors() {
        const style = getComputedStyle(document.documentElement);
        const isLight = ThemeManager.getEffectiveTheme() === 'light';
        
        if (isLight) {
            return {
                layout: {
                    background: { type: 'solid', color: '#f2f7ef' },
                    textColor: '#4a6348',
                    fontSize: 12,
                },
                grid: {
                    vertLines: { color: '#dce8d6' },
                    horzLines: { color: '#dce8d6' },
                },
                crosshair: {
                    mode: 0, // Normal
                    vertLine: { color: '#b8ccb0', width: 1, style: 2 },
                    horzLine: { color: '#b8ccb0', width: 1, style: 2 },
                },
                rightPriceScale: { borderColor: '#b8ccb0' },
                timeScale: { borderColor: '#b8ccb0', timeVisible: true },
                candle: {
                    upColor: '#d4380d', downColor: '#389e0f',
                    borderUpColor: '#d4380d', borderDownColor: '#389e0f',
                    wickUpColor: '#d4380d', wickDownColor: '#389e0f',
                },
                volume: {
                    up: 'rgba(212, 56, 13, 0.35)',
                    down: 'rgba(56, 158, 15, 0.35)',
                },
                macd: {
                    up: 'rgba(212, 56, 13, 0.5)',
                    down: 'rgba(56, 158, 15, 0.5)',
                }
            };
        } else {
            return {
                layout: {
                    background: { type: 'solid', color: '#0d1117' },
                    textColor: '#8b949e',
                    fontSize: 12,
                },
                grid: {
                    vertLines: { color: '#1c2128' },
                    horzLines: { color: '#1c2128' },
                },
                crosshair: {
                    mode: 0,
                    vertLine: { color: '#30363d', width: 1, style: 2 },
                    horzLine: { color: '#30363d', width: 1, style: 2 },
                },
                rightPriceScale: { borderColor: '#30363d' },
                timeScale: { borderColor: '#30363d', timeVisible: true },
                candle: {
                    upColor: '#f85149', downColor: '#3fb950',
                    borderUpColor: '#f85149', borderDownColor: '#3fb950',
                    wickUpColor: '#f85149', wickDownColor: '#3fb950',
                },
                volume: {
                    up: 'rgba(248, 81, 73, 0.4)',
                    down: 'rgba(63, 185, 80, 0.4)',
                },
                macd: {
                    up: 'rgba(248, 81, 73, 0.6)',
                    down: 'rgba(63, 185, 80, 0.6)',
                }
            };
        }
    },

    normalizeChartTime(rawTime) {
        if (rawTime === null || rawTime === undefined) return rawTime;
        if (typeof rawTime !== 'string') return rawTime;

        const value = rawTime.trim();
        if (!value) return value;

        const dateTimeMatch = value.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?$/);
        if (dateTimeMatch) {
            const [, year, month, day, hour, minute, second = '00'] = dateTimeMatch;
            return Math.floor(Date.UTC(
                Number(year),
                Number(month) - 1,
                Number(day),
                Number(hour),
                Number(minute),
                Number(second)
            ) / 1000);
        }

        return value;
    },
 
    init() {
        const container = document.getElementById('chart-container');
        if (!container || typeof LightweightCharts === 'undefined') return;

        const colors = this.getChartColors();

        this.chart = LightweightCharts.createChart(container, {
            width: container.clientWidth,
            height: container.clientHeight,
            layout: colors.layout,
            grid: colors.grid,
            crosshair: colors.crosshair,
            rightPriceScale: colors.rightPriceScale,
            timeScale: colors.timeScale,
        });

        this.candleSeries = this.chart.addCandlestickSeries(colors.candle);

        // 成交量
        this.volumeSeries = this.chart.addHistogramSeries({
            priceFormat: { type: 'volume' },
            priceScaleId: 'vol',
        });
        this.chart.priceScale('vol').applyOptions({
            scaleMargins: { top: 0.8, bottom: 0 },
        });

        // 响应式
        const ro = new ResizeObserver(() => {
            this.resize();
        });
        ro.observe(container);

        window.addEventListener('orientationchange', () => {
            window.setTimeout(() => this.resize(), 120);
        });
    },

    resize() {
        if (!this.chart) return;
        const container = document.getElementById('chart-container');
        if (!container) return;

        const width = Math.max(0, container.clientWidth);
        const height = Math.max(280, container.clientHeight || 0);
        if (!width) return;

        this.chart.applyOptions({ width, height });
        this.chart.timeScale().fitContent();
    },

    // 主题切换时更新图表配色
    updateChartTheme() {
        if (!this.chart) return;
        const colors = this.getChartColors();

        this.chart.applyOptions({
            layout: colors.layout,
            grid: colors.grid,
            crosshair: colors.crosshair,
            rightPriceScale: colors.rightPriceScale,
            timeScale: colors.timeScale,
        });

        // 更新K线配色
        if (this.candleSeries) {
            this.candleSeries.applyOptions(colors.candle);
        }

        // 如果有数据，重新渲染以更新颜色
        if (APP.currentStockData.length > 0) {
            this.updateData(APP.currentStockData);
        }
    },

    // 更新K线数据
    updateData(data) {
        if (!this.candleSeries) return;
        APP.currentStockData = data;
 
        const colors = this.getChartColors();
 
        const normalizedData = data.map(row => ({
            ...row,
            chartTime: this.normalizeChartTime(row.time),
        }));
 
        const candleData = normalizedData.map(row => ({
            time: row.chartTime,
            open: parseFloat(row.open),
            high: parseFloat(row.high),
            low: parseFloat(row.low),
            close: parseFloat(row.close),
        }));
 
        const volumeData = normalizedData.map(row => ({
            time: row.chartTime,
            value: parseFloat(row.volume),
            color: parseFloat(row.close) >= parseFloat(row.open) ? colors.volume.up : colors.volume.down,
        }));
 
        this.candleSeries.setData(candleData);
        this.volumeSeries.setData(volumeData);
 
        // 计算并显示技术指标
        this.updateIndicators(normalizedData);
        this.chart.timeScale().fitContent();
    },

    updateIndicators(data) {
        // 清除旧的指标线
        Object.values(this.indicatorSeries).forEach(s => { try { this.chart.removeSeries(s); } catch(e){} });
        this.indicatorSeries = {};
 
        const closes = data.map(r => parseFloat(r.close));
        const highs = data.map(r => parseFloat(r.high));
        const lows = data.map(r => parseFloat(r.low));
        const times = data.map(r => r.chartTime ?? this.normalizeChartTime(r.time));
        const colors = this.getChartColors();

        // MA均线
        if (document.getElementById('ind-ma')?.checked) {
            [5, 10, 20, 60].forEach((period, idx) => {
                const ma = Indicators.MA(closes, period);
                const lineColors = ['#f0883e', '#58a6ff', '#bc8cff', '#3fb950'];
                const series = this.chart.addLineSeries({
                    priceLineVisible: false, lastValueVisible: false,
                    color: lineColors[idx], lineWidth: 1,
                });
                series.setData(ma.map((v, i) => v !== null ? { time: times[i], value: v } : null).filter(Boolean));
                this.indicatorSeries['ma' + period] = series;
            });
        }

        // BOLL布林带
        if (document.getElementById('ind-boll')?.checked) {
            const boll = Indicators.BOLL(closes);
            ['upper', 'mid', 'lower'].forEach((key, idx) => {
                const lineColors = ['rgba(248,81,73,0.5)', 'rgba(210,153,34,0.8)', 'rgba(63,185,80,0.5)'];
                const series = this.chart.addLineSeries({
                    priceLineVisible: false, lastValueVisible: false,
                    color: lineColors[idx], lineWidth: 1, lineStyle: idx === 1 ? 0 : 2,
                });
                series.setData(boll[key].map((v, i) => v !== null ? { time: times[i], value: v } : null).filter(Boolean));
                this.indicatorSeries['boll_' + key] = series;
            });
        }

        // MACD
        if (document.getElementById('ind-macd')?.checked) {
            const macd = Indicators.MACD(closes);
            const macdSeries = this.chart.addHistogramSeries({
                priceFormat: { type: 'price', precision: 3 },
                priceScaleId: 'macd',
            });
            this.chart.priceScale('macd').applyOptions({
                scaleMargins: { top: 0.85, bottom: 0 },
            });
            macdSeries.setData(macd.macd.map((v, i) => ({
                time: times[i],
                value: v,
                color: v >= 0 ? colors.macd.up : colors.macd.down,
            })));
            this.indicatorSeries['macd_hist'] = macdSeries;

            const difSeries = this.chart.addLineSeries({
                priceLineVisible: false, lastValueVisible: false,
                color: '#f0883e', lineWidth: 1, priceScaleId: 'macd',
            });
            difSeries.setData(macd.dif.map((v, i) => ({ time: times[i], value: v })));
            this.indicatorSeries['macd_dif'] = difSeries;

            const deaSeries = this.chart.addLineSeries({
                priceLineVisible: false, lastValueVisible: false,
                color: '#58a6ff', lineWidth: 1, priceScaleId: 'macd',
            });
            deaSeries.setData(macd.dea.map((v, i) => ({ time: times[i], value: v })));
            this.indicatorSeries['macd_dea'] = deaSeries;
        }

        // RSI
        if (document.getElementById('ind-rsi')?.checked) {
            const rsi = Indicators.RSI(closes);
            const rsiSeries = this.chart.addLineSeries({
                priceLineVisible: false, lastValueVisible: false,
                color: '#bc8cff', lineWidth: 1, priceScaleId: 'rsi',
            });
            this.chart.priceScale('rsi').applyOptions({
                scaleMargins: { top: 0.85, bottom: 0 },
            });
            rsiSeries.setData(rsi.map((v, i) => v !== null ? { time: times[i], value: v } : null).filter(Boolean));
            this.indicatorSeries['rsi'] = rsiSeries;
        }

        // KDJ
        if (document.getElementById('ind-kdj')?.checked) {
            const kdj = Indicators.KDJ(highs, lows, closes);
            const kdjScale = 'kdj';
            ['k', 'd', 'j'].forEach((key, idx) => {
                const lineColors = ['#f0883e', '#58a6ff', '#bc8cff'];
                const series = this.chart.addLineSeries({
                    priceLineVisible: false, lastValueVisible: false,
                    color: lineColors[idx], lineWidth: 1, priceScaleId: kdjScale,
                });
                series.setData(kdj[key].map((v, i) => v !== null ? { time: times[i], value: v } : null).filter(Boolean));
                this.indicatorSeries['kdj_' + key] = series;
            });
            this.chart.priceScale(kdjScale).applyOptions({
                scaleMargins: { top: 0.85, bottom: 0 },
            });
        }
    },

    // 重新绘制指标
    refreshIndicators() {
        if (APP.currentStockData.length > 0) {
            this.updateIndicators(APP.currentStockData);
        }
    }
};

// ============================================================
// 实时行情面板
// ============================================================
const QuoteModule = {
    async fetch(code) {
        try {
            const secid = codeToSecid(code);
            const resp = await fetch(`stock_quote_api.php?codes=${encodeURIComponent(code)}`);
            const data = await resp.json();
            if (data.success && data.data.length > 0) return data.data[0];
            return null;
        } catch (e) { console.error('获取实时行情失败:', e); return null; }
    },

    render(quote) {
        const el = document.getElementById('quote-content');
        if (!quote) { el.innerHTML = '<p class="placeholder-text">暂无行情数据</p>'; return; }

        const pctClass = colorClass(quote.change_pct);
        el.innerHTML = `
            <div class="quote-stock-name">
                <span>${quote.name || '-'}</span>
                <span style="font-size:0.8rem;color:var(--text-muted);font-family:var(--font-mono)">${quote.code}</span>
            </div>
            <div class="quote-price-big ${pctClass}">${quote.price > 0 ? quote.price.toFixed(2) : '-'}</div>
            <div class="quote-change ${pctClass}">
                ${formatPct(quote.change_pct)} ${quote.change_amt > 0 ? '+' : ''}${quote.change_amt.toFixed(2)}
            </div>
            <div class="quote-grid">
                <span class="q-label">开盘</span><span class="q-value">${quote.open > 0 ? quote.open.toFixed(2) : '-'}</span>
                <span class="q-label">最高</span><span class="q-value up">${quote.high > 0 ? quote.high.toFixed(2) : '-'}</span>
                <span class="q-label">收盘</span><span class="q-value">${quote.close > 0 ? quote.close.toFixed(2) : '-'}</span>
                <span class="q-label">最低</span><span class="q-value down">${quote.low > 0 ? quote.low.toFixed(2) : '-'}</span>
                <span class="q-label">昨收</span><span class="q-value">${quote.prev_close > 0 ? quote.prev_close.toFixed(2) : '-'}</span>
                <span class="q-label">振幅</span><span class="q-value">${quote.amplitude > 0 ? quote.amplitude.toFixed(2) + '%' : '-'}</span>
                <span class="q-label">换手率</span><span class="q-value">${quote.turnover_rate > 0 ? quote.turnover_rate.toFixed(2) + '%' : '-'}</span>
                <span class="q-label">成交额</span><span class="q-value">${formatAmount(quote.amount)}</span>
                <span class="q-label">PE(TTM)</span><span class="q-value">${quote.pe_ttm > 0 ? quote.pe_ttm.toFixed(2) : '-'}</span>
                <span class="q-label">PB</span><span class="q-value">${quote.pb > 0 ? quote.pb.toFixed(2) : '-'}</span>
                <span class="q-label">总市值</span><span class="q-value">${formatAmount(quote.total_mv)}</span>
            </div>
        `;

        // 价格更新闪烁 + 卡片边框脉冲
        const priceEl = el.querySelector('.quote-price-big');
        const pct = quote.change_pct || 0;
        AnimationManager.flashPrice(priceEl, pct > 0 ? 'up' : pct < 0 ? 'down' : 'flat');
        const card = el.closest('.card');
        if (card) AnimationManager.pulseCard(card);
    }
};

// ============================================================
// 资金流向面板
// ============================================================
const FlowModule = {
    async fetch(code) {
        try {
            const resp = await fetch(`stock_flow_api.php?code=${encodeURIComponent(code)}`);
            const data = await resp.json();
            if (data.success) return data.data;
            return [];
        } catch (e) { console.error('获取资金流向失败:', e); return []; }
    },

    render(flowData) {
        const el = document.getElementById('flow-content');
        if (!flowData || flowData.length === 0) {
            el.innerHTML = '<p class="placeholder-text">暂无资金流向数据</p>';
            return;
        }

        // 取最近一天的数据
        const latest = flowData[flowData.length - 1];
        const items = [
            { label: '主力', value: latest.main_net_inflow },
            { label: '超大单', value: latest.super_net_inflow },
            { label: '大单', value: latest.big_net_inflow },
            { label: '中单', value: latest.mid_net_inflow },
            { label: '小单', value: latest.small_net_inflow },
        ];

        const maxVal = Math.max(...items.map(i => Math.abs(i.value)), 1);

        let html = `<div style="font-size:0.8rem;color:var(--text-muted);margin-bottom:8px">日期: ${latest.time}</div><div class="flow-bars">`;
        items.forEach(item => {
            const isInflow = item.value >= 0;
            const pct = Math.abs(item.value) / maxVal * 100;
            html += `
                <div class="flow-bar-item">
                    <span class="flow-bar-label">${item.label}</span>
                    <div class="flow-bar-track">
                        <div class="flow-bar-fill ${isInflow ? 'inflow' : 'outflow'}" style="width:${pct}%"></div>
                    </div>
                    <span class="flow-bar-value ${colorClass(item.value)}">${formatAmount(item.value)}</span>
                </div>
            `;
        });
        html += '</div>';
        el.innerHTML = html;

        // 资金流向柱增长动画
        AnimationManager.animateBars('.flow-content .flow-bar-fill');
    }
};

// ============================================================
// 自选股模块
// ============================================================
const WatchlistModule = {
    save() {
        localStorage.setItem('fa_watchlist', JSON.stringify(APP.watchlist));
        document.getElementById('watchlist-count').textContent = APP.watchlist.length;
    },

    add(code, name) {
        code = normalizeCode(code);
        if (APP.watchlist.find(w => w.code === code)) return;
        APP.watchlist.push({ code, name: name || code });
        this.save();
        this.render();
    },

    remove(code) {
        APP.watchlist = APP.watchlist.filter(w => w.code !== code);
        this.save();
        this.render();
    },

    has(code) {
        return APP.watchlist.some(w => w.code === normalizeCode(code));
    },

    render() {
        const container = document.getElementById('watchlist-items');
        if (APP.watchlist.length === 0) {
            container.innerHTML = '<p class="placeholder-text">暂无自选股</p>';
            return;
        }
        let html = '';
        APP.watchlist.forEach(w => {
            html += `
                <div class="wl-item" data-code="${w.code}">
                    <div class="wl-item-info" onclick="WatchlistModule.query('${w.code}')">
                        <div class="wl-item-name">${w.name}</div>
                        <div class="wl-item-code">${w.code}</div>
                    </div>
                    <div class="wl-item-price" id="wl-price-${w.code.replace('.', '')}">-</div>
                    <button class="wl-item-remove" onclick="WatchlistModule.remove('${w.code}')">✕</button>
                </div>
            `;
        });
        container.innerHTML = html;

        // 异步刷新价格
        this.refreshPrices();
    },

    async refreshPrices() {
        for (const w of APP.watchlist) {
            try {
                const resp = await fetch(`stock_quote_api.php?codes=${encodeURIComponent(w.code)}`);
                const data = await resp.json();
                if (data.success && data.data.length > 0) {
                    const q = data.data[0];
                    const el = document.getElementById('wl-price-' + w.code.replace('.', ''));
                    if (el) {
                        el.innerHTML = `
                            <div class="wl-item-pct" style="${colorStyle(q.change_pct)}">${formatPct(q.change_pct)}</div>
                            <div class="wl-item-val">${q.price > 0 ? q.price.toFixed(2) : '-'}</div>
                        `;
                    }
                }
            } catch(e) {}
        }
    },

    query(code) {
        document.getElementById('code').value = code;
        document.getElementById('frequency').value = '1d';
        document.getElementById('count').value = '120';
        // 切换到股票tab
        switchTab('stock');
        document.getElementById('stockForm').dispatchEvent(new Event('submit'));
        // 关闭侧边栏并同步清理顾问面板避让状态
        document.getElementById('watchlist-sidebar').classList.remove('open');
        document.getElementById('watchlist-overlay').classList.remove('open');
        if (typeof AdvisorModule !== 'undefined') AdvisorModule.notifyWatchlistOpen(false);
    }
};

// ============================================================
// 实时看板模块
// ============================================================
const RealtimeModule = {
    init() {
        this.render();
        this.startAutoRefresh();
    },

    addCode(code) {
        code = normalizeCode(code);
        if (!APP.realtimeCodes.includes(code)) {
            APP.realtimeCodes.push(code);
            localStorage.setItem('fa_realtime_codes', JSON.stringify(APP.realtimeCodes));
        }
        this.refresh();
    },

    removeCode(code) {
        APP.realtimeCodes = APP.realtimeCodes.filter(c => c !== code);
        localStorage.setItem('fa_realtime_codes', JSON.stringify(APP.realtimeCodes));
        this.render();
    },

    async refresh() {
        if (APP.realtimeCodes.length === 0) { this.render(); return; }
        const codesStr = APP.realtimeCodes.join(',');
        try {
            const resp = await fetch(`stock_quote_api.php?codes=${encodeURIComponent(codesStr)}`);
            const data = await resp.json();
            if (data.success) {
                this.renderCards(data.data);
            }
        } catch(e) { console.error('刷新实时行情失败:', e); }
    },

    render() {
        const grid = document.getElementById('realtime-grid');
        if (APP.realtimeCodes.length === 0) {
            grid.innerHTML = '<p class="placeholder-text">点击"添加"按钮输入股票代码，或从自选股添加</p>';
            return;
        }
        this.refresh();
    },

    renderCards(stocks) {
        const grid = document.getElementById('realtime-grid');
        let html = '';
        stocks.forEach(s => {
            const cls = colorClass(s.change_pct);
            html += `
                <div class="realtime-card" onclick="document.getElementById('code').value='${s.code}';switchTab('stock');document.getElementById('stockForm').dispatchEvent(new Event('submit'));">
                    <button class="rc-remove" onclick="event.stopPropagation();RealtimeModule.removeCode('${s.code}')">✕</button>
                    <div class="rc-name">${s.name}</div>
                    <div class="rc-code">${s.code}</div>
                    <div class="rc-price ${cls}">${s.price > 0 ? s.price.toFixed(2) : '-'}</div>
                    <div class="rc-change ${cls}">${formatPct(s.change_pct)}</div>
                    <div class="rc-details">
                        <span>开盘</span><span>${s.open > 0 ? s.open.toFixed(2) : '-'}</span>
                        <span>最高</span><span style="${colorStyle(s.high - s.prev_close)}">${s.high > 0 ? s.high.toFixed(2) : '-'}</span>
                        <span>最低</span><span style="${colorStyle(s.low - s.prev_close)}">${s.low > 0 ? s.low.toFixed(2) : '-'}</span>
                        <span>成交额</span><span>${formatAmount(s.amount)}</span>
                        <span>换手率</span><span>${s.turnover_rate > 0 ? s.turnover_rate.toFixed(2) + '%' : '-'}</span>
                        <span>PE</span><span>${s.pe_ttm > 0 ? s.pe_ttm.toFixed(1) : '-'}</span>
                    </div>
                </div>
            `;
        });
        grid.innerHTML = html || '<p class="placeholder-text">暂无数据</p>';

        // 入场动画
        AnimationManager.animateRealtimeCards();
    },

    startAutoRefresh() {
        let countdown = APP.config.autoRefreshInterval;
        const timerEl = document.getElementById('auto-refresh-timer');
        if (APP.realtimeTimer) clearInterval(APP.realtimeTimer);
        APP.realtimeTimer = setInterval(() => {
            countdown--;
            if (timerEl) timerEl.textContent = `自动刷新: ${countdown}s`;
            if (countdown <= 0) {
                countdown = APP.config.autoRefreshInterval;
                this.refresh();
            }
        }, 1000);
    }
};

// ============================================================
// 板块资金模块
// ============================================================
const SectorModule = {
    async query() {
        const type = document.getElementById('sector-type').value;
        const period = document.getElementById('sector-period').value;
        const loading = document.getElementById('sector-loading');
        loading.style.display = 'flex';

        try {
            const resp = await fetch(`sector_flow_api.php?key=${period}&type=${type}`);
            const data = await resp.json();
            loading.style.display = 'none';
            if (data.success) {
                this.renderBar(data.data);
                this.renderTable(data.data);
            } else {
                document.getElementById('sector-bar-chart').innerHTML = `<p class="placeholder-text">${data.message}</p>`;
            }
        } catch(e) {
            loading.style.display = 'none';
            console.error('获取板块数据失败:', e);
        }
    },

    renderBar(sectors) {
        const container = document.getElementById('sector-bar-chart');
        // 取前20
        const top = sectors.slice(0, 20);
        const maxVal = Math.max(...top.map(s => Math.abs(s.net_inflow_today)), 1);

        let html = '';
        top.forEach((s, i) => {
            const isInflow = s.net_inflow_today >= 0;
            const pct = Math.abs(s.net_inflow_today) / maxVal * 100;
            html += `
                <div class="sector-bar-item">
                    <span class="sector-bar-rank">${i + 1}</span>
                    <span class="sector-bar-name">${s.name}</span>
                    <div class="sector-bar-track">
                        <div class="sector-bar-fill ${isInflow ? 'inflow' : 'outflow'}" style="width:${pct}%"></div>
                    </div>
                    <span class="sector-bar-value ${colorClass(s.net_inflow_today)}">${formatAmount(s.net_inflow_today)}</span>
                </div>
            `;
        });
        container.innerHTML = html;

        // 板块柱增长动画
        AnimationManager.animateBars('#sector-bar-chart .sector-bar-fill');
    },

    renderTable(sectors) {
        const table = document.getElementById('sector-table');
        const tbody = document.getElementById('sector-data');
        tbody.innerHTML = '';
        sectors.forEach((s, i) => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>${i + 1}</td>
                <td>${s.name}</td>
                <td class="${colorClass(s.change_pct)}">${formatPct(s.change_pct)}</td>
                <td class="${colorClass(s.net_inflow_today)}">${formatAmount(s.net_inflow_today)}</td>
                <td class="${colorClass(s.main_net_inflow)}">${formatAmount(s.main_net_inflow)}</td>
                <td class="${colorClass(s.super_net_inflow)}">${formatAmount(s.super_net_inflow)}</td>
                <td class="${colorClass(s.big_net_inflow)}">${formatAmount(s.big_net_inflow)}</td>
                <td>${s.turnover_rate > 0 ? s.turnover_rate.toFixed(2) + '%' : '-'}</td>
            `;
            tbody.appendChild(tr);
        });
        table.style.display = 'table';
    }
};

// ============================================================
// 基金模块
// ============================================================
const FundModule = {
    async search() {
        const keyword = document.getElementById('fund-search-input').value.trim();
        if (!keyword) return;
        const loading = document.getElementById('fund-loading');
        loading.style.display = 'flex';

        try {
            const resp = await fetch(`fund_search_api.php?key=${encodeURIComponent(keyword)}`);
            const data = await resp.json();
            loading.style.display = 'none';
            if (data.success) {
                this.renderSearchResults(data.data);
            }
        } catch(e) {
            loading.style.display = 'none';
            console.error('搜索基金失败:', e);
        }
    },

    renderSearchResults(funds) {
        const container = document.getElementById('fund-search-results');
        if (funds.length === 0) {
            container.innerHTML = '<p class="placeholder-text">未找到相关基金</p>';
            return;
        }
        let html = '';
        funds.forEach(f => {
            const inWl = APP.fundWatchlist.some(w => w.code === f.code);
            html += `
                <div class="fund-card">
                    <div class="fc-name">${f.name}</div>
                    <div class="fc-code">${f.code}</div>
                    <span class="fc-type">${f.type || '-'}</span>
                    <div class="fc-nav">${f.nav || '-'}</div>
                    <div class="fc-meta">${f.company || ''} · ${f.manager || ''}</div>
                    <div class="fc-actions">
                        <button class="btn-sm ${inWl ? '' : 'btn-star'}" onclick="FundModule.addToWatchlist('${f.code}','${f.name}')">
                            ${inWl ? '已添加' : '⭐ 加自选'}
                        </button>
                    </div>
                </div>
            `;
        });
        container.innerHTML = html;

        // 基金卡片入场动画
        AnimationManager.animateFundCards();
    },

    addToWatchlist(code, name) {
        if (APP.fundWatchlist.some(w => w.code === code)) return;
        APP.fundWatchlist.push({ code, name });
        localStorage.setItem('fa_fund_watchlist', JSON.stringify(APP.fundWatchlist));
        this.renderWatchlist();
        // 刷新搜索结果按钮
        const keyword = document.getElementById('fund-search-input')?.value.trim();
        if (keyword) this.search();
    },

    removeFromWatchlist(code) {
        APP.fundWatchlist = APP.fundWatchlist.filter(w => w.code !== code);
        localStorage.setItem('fa_fund_watchlist', JSON.stringify(APP.fundWatchlist));
        this.renderWatchlist();
    },

    async renderWatchlist() {
        const container = document.getElementById('fund-watchlist');
        if (APP.fundWatchlist.length === 0) {
            container.innerHTML = '<p class="placeholder-text">搜索基金后可添加到自选</p>';
            return;
        }

        let html = '';
        // 批量获取估值
        for (const f of APP.fundWatchlist) {
            let estimate = null;
            try {
                const resp = await fetch(`fund_estimate_api.php?code=${f.code}`);
                const data = await resp.json();
                if (data.success) estimate = data.data;
            } catch(e) {}

            const pctClass = estimate ? colorClass(parseFloat(estimate.gszzl)) : '';
            html += `
                <div class="fund-wl-item">
                    <div class="fund-wl-info">
                        <div class="fund-wl-name">${f.name}</div>
                        <div class="fund-wl-code">${f.code}</div>
                    </div>
                    <div class="fund-wl-estimate">
                        ${estimate ? `
                            <div class="fund-wl-gsz">${estimate.gsz}</div>
                            <div class="fund-wl-gszzl ${pctClass}">${formatPct(parseFloat(estimate.gszzl))}</div>
                            <div class="fund-wl-time">${estimate.gztime}</div>
                        ` : '<span style="color:var(--text-muted)">暂无估值</span>'}
                    </div>
                    <button class="fund-wl-remove" onclick="FundModule.removeFromWatchlist('${f.code}')">✕</button>
                </div>
            `;
        }
        container.innerHTML = html;
    }
};

// ============================================================
// AI 顾问面板模块（AdvisorModule）
// ============================================================
const AdvisorModule = {
    _els: {},  // 缓存 DOM 引用

    /** 初始化：缓存 DOM + 绑定事件 */
    init() {
        this._els = {
            fab: document.getElementById('ai-advisor-fab'),
            panel: document.getElementById('ai-advisor-panel'),
            backdrop: document.getElementById('ai-advisor-backdrop'),
            chatContainer: document.getElementById('advisor-chat-container'),
            userInput: document.getElementById('advisor-user-input'),
            sendBtn: document.getElementById('advisor-send-btn'),
            closeBtn: document.getElementById('advisor-close-btn'),
            expandBtn: document.getElementById('advisor-expand-btn'),
            welcome: document.getElementById('ai-advisor-welcome'),
            context: document.getElementById('ai-advisor-context'),
            contextStock: document.getElementById('advisor-context-stock'),
            contextTab: document.getElementById('advisor-context-tab'),
            badge: document.getElementById('fab-badge'),
            status: document.getElementById('advisor-status'),
            quickActions: document.getElementById('ai-advisor-quick-actions'),
            contextMeter: document.getElementById('advisor-context-meter'),
            contextRing: document.getElementById('advisor-context-ring'),
            contextSize: document.getElementById('advisor-context-size')
        };

        // 缓存到 APP
        APP.advisorChatContainer = this._els.chatContainer;
        APP.advisorUserInput = this._els.userInput;

        this._bindEvents();
        this.updateContextMeter();
    },

    /** 绑定所有事件 */
    _bindEvents() {
        const el = this._els;

        // FAB 点击
        el.fab?.addEventListener('click', () => this.toggle());

        // 关闭按钮
        el.closeBtn?.addEventListener('click', () => this.close());

        // 展开到完整页
        el.expandBtn?.addEventListener('click', () => {
            this.close();
            switchTab('ai');
        });

        // 遮罩关闭（移动端）
        el.backdrop?.addEventListener('click', () => this.close());

        // 发送按钮
        el.sendBtn?.addEventListener('click', () => this.sendMessage());

        // 输入框键盘
        el.userInput?.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                this.sendMessage();
            }
        });

        // 输入框自适应高度 + 发送按钮状态
        el.userInput?.addEventListener('input', () => {
            this._autoResize();
            this._updateSendState();
        });
        this._updateSendState();

        // Esc 关闭 + Tab 焦点闭环
        document.addEventListener('keydown', (e) => {
            if (!APP.advisorOpen) return;
            if (e.key === 'Escape') {
                e.preventDefault();
                this.close();
            } else if (e.key === 'Tab') {
                this._trapFocus(e);
            }
        });

        // 窗口尺寸变化时同步移动端遮罩和滚动锁
        window.addEventListener('resize', () => this._syncModalState());

        // 快捷任务胶囊
        el.quickActions?.addEventListener('click', (e) => {
            const btn = e.target.closest('.quick-action-btn');
            if (btn && btn.dataset.prompt) {
                this.sendQuickAction(btn.dataset.prompt);
            }
        });
    },

    /** 打开面板 */
    open() {
        if (APP.advisorOpen) return;
        APP.advisorOpen = true;
        APP.advisorLastFocusedElement = document.activeElement;

        const el = this._els;
        el.panel?.classList.add('open');
        el.panel?.setAttribute('aria-hidden', 'false');
        el.fab?.setAttribute('aria-expanded', 'true');

        this._syncModalState();

        // 清除 GSAP 可能残留的内联样式，确保 CSS 过渡能正常工作
        if (el.panel) {
            el.panel.style.removeProperty('opacity');
            el.panel.style.removeProperty('visibility');
            el.panel.style.removeProperty('transform');
        }

        // 焦点进入输入框
        setTimeout(() => {
            el.userInput?.focus();
        }, 100);

        // 同步消息历史
        this.renderHistory();

        // 更新上下文
        this.updateContext();
        this.updateContextMeter();

        // 清除未读
        this.clearUnread();
    },

    /** 关闭面板 */
    close() {
        if (!APP.advisorOpen) return;
        APP.advisorOpen = false;

        const el = this._els;
        // 终止 GSAP 可能正在运行的动画，清除残留内联样式
        if (typeof gsap !== 'undefined' && el.panel) {
            gsap.killTweensOf(el.panel);
        }
        el.panel?.classList.remove('open');
        el.panel?.setAttribute('aria-hidden', 'true');
        // 清除 GSAP 残留的内联样式，让 CSS 过渡正常工作
        if (el.panel) {
            el.panel.style.removeProperty('opacity');
            el.panel.style.removeProperty('visibility');
            el.panel.style.removeProperty('transform');
        }
        el.fab?.setAttribute('aria-expanded', 'false');
        el.backdrop?.classList.remove('open');
        this._unlockScroll();

        // 焦点回退到 FAB
        if (APP.advisorLastFocusedElement) {
            APP.advisorLastFocusedElement.focus();
        } else {
            el.fab?.focus();
        }
    },

    /** 切换面板 */
    toggle() {
        if (APP.advisorOpen) {
            this.close();
        } else {
            this.open();
        }
    },

    /** 从顾问面板发送消息 */
    sendMessage() {
        const input = this._els.userInput;
        if (!input) return;
        const msg = input.value.trim();
        if (!msg) return;

        input.value = '';
        input.style.height = '24px';
        this._updateSendState();

        // 标记面板有消息
        this._els.panel?.classList.add('has-messages');

        // 通过 AIModule 发送，指定顾问面板容器
        APP.messageHistory.push({ role: 'user', content: msg });
        this.updateContextMeter();
        AIModule.appendMessage(msg, null, 'user-message', this._els.chatContainer);
        const loadingMsg = AIModule.appendMessage('思考中...', null, 'loading-message', this._els.chatContainer);
        let seconds = 0;
        const timer = setInterval(() => { seconds++; loadingMsg.textContent = `思考中... ${seconds}s`; }, 1000);

        // 设置思考态
        this.setThinking(true);

        // 发送到 AI，使用顾问面板容器
        AIModule.sendToAI(loadingMsg, timer, this._els.chatContainer);
    },

    /** 快捷任务发送 */
    sendQuickAction(prompt) {
        if (!prompt) return;

        // 标记面板有消息
        this._els.panel?.classList.add('has-messages');

        // 通过 AIModule 发送
        APP.messageHistory.push({ role: 'user', content: prompt });
        this.updateContextMeter();
        AIModule.appendMessage(prompt, null, 'user-message', this._els.chatContainer);
        const loadingMsg = AIModule.appendMessage('思考中...', null, 'loading-message', this._els.chatContainer);
        let seconds = 0;
        const timer = setInterval(() => { seconds++; loadingMsg.textContent = `思考中... ${seconds}s`; }, 1000);

        this.setThinking(true);
        AIModule.sendToAI(loadingMsg, timer, this._els.chatContainer);

        // 更新上下文来源
        APP.advisorContext.source = '快捷任务';
        this.updateContext();
    },

    /** 从外部自动触发（替代原来的 switchTab('ai') + AIModule.autoSend） */
    autoSend(message) {
        // 打开面板
        this.open();

        // 等面板展开后发送
        setTimeout(() => {
            this._els.panel?.classList.add('has-messages');
            APP.messageHistory.push({ role: 'user', content: message });
            this.updateContextMeter();
            AIModule.appendMessage(message, null, 'user-message', this._els.chatContainer);
            const loadingMsg = AIModule.appendMessage('思考中...', null, 'loading-message', this._els.chatContainer);
            let seconds = 0;
            const timer = setInterval(() => { seconds++; loadingMsg.textContent = `思考中... ${seconds}s`; }, 1000);

            this.setThinking(true);
            AIModule.sendToAI(loadingMsg, timer, this._els.chatContainer);
        }, 150);
    },

    /** 渲染历史消息到顾问面板 */
    renderHistory() {
        const container = this._els.chatContainer;
        if (!container) return;

        // 跳过 system 消息
        const messages = APP.messageHistory.filter(m => m.role !== 'system');
        const hasMessages = messages.length > 0;

        // 切换欢迎区/消息区显示
        this._els.panel?.classList.toggle('has-messages', hasMessages);

        if (!hasMessages) {
            container.innerHTML = '';
            return;
        }

        // 检查是否需要重新渲染（对比已有消息数）
        const existingMsgs = container.querySelectorAll('.message');
        // 简单对比：如果数量一致则跳过（避免重复渲染）
        if (existingMsgs.length === messages.length) return;

        // 重新渲染全部
        container.innerHTML = '';
        messages.forEach(m => {
            if (m.role === 'user') {
                AIModule.appendMessage(m.content, null, 'user-message', container);
            } else if (m.role === 'assistant') {
                AIModule.appendMessage(m.content, null, 'bot-message', container);
            }
        });
        container.scrollTop = container.scrollHeight;
    },

    /** 设置思考态 */
    setThinking(isThinking) {
        APP.advisorThinking = isThinking;
        const fab = this._els.fab;
        const status = this._els.status;

        if (isThinking) {
            fab?.classList.add('thinking');
            if (status) status.textContent = '思考中...';
        } else {
            fab?.classList.remove('thinking');
            if (status) status.textContent = '在线 · Beta';
        }
    },

    /** 设置未读角标 */
    setUnread(count) {
        APP.advisorUnread = count;
        const badge = this._els.badge;
        if (!badge) return;

        if (count > 0 && !APP.advisorOpen) {
            badge.style.display = 'flex';
            badge.textContent = count > 99 ? '99+' : count;
        } else {
            badge.style.display = 'none';
        }
    },

    /** 清除未读 */
    clearUnread() {
        this.setUnread(0);
    },

    /** 更新上下文提示 */
    updateContext() {
        const ctx = APP.advisorContext;
        const el = this._els;
        let show = false;

        // 当前股票
        if (APP.currentStockCode) {
            ctx.stock = APP.currentStockCode;
            if (el.contextStock) {
                el.contextStock.textContent = '📈 ' + ctx.stock;
                el.contextStock.style.display = '';
            }
            show = true;
        } else {
            if (el.contextStock) el.contextStock.style.display = 'none';
        }

        // 当前 Tab
        const activeTab = document.querySelector('.nav-tab.active');
        if (activeTab) {
            const tabNames = { stock: '股票行情', realtime: '实时看板', sector: '板块资金', fund: '基金分析', ai: 'AI顾问' };
            ctx.tab = activeTab.dataset.tab || 'stock';
            if (el.contextTab) {
                el.contextTab.textContent = '📋 ' + (tabNames[ctx.tab] || ctx.tab);
                el.contextTab.style.display = '';
            }
            show = true;
        } else {
            if (el.contextTab) el.contextTab.style.display = 'none';
        }

        if (el.context) {
            el.context.style.display = show ? 'flex' : 'none';
        }
    },

    /** 更新上下文用量环 */
    updateContextMeter() {
        const meter = this._els.contextMeter;
        const ring = this._els.contextRing;
        const size = this._els.contextSize;
        if (!meter || !ring || typeof AIModule === 'undefined') return;

        const stats = AIModule.getContextStats();
        const percent = Math.min(Math.round(stats.sentSize / stats.limit * 1000) / 10, 100);
        const dash = stats.sentSize > 0 ? Math.max(1, Math.min(percent, 100)) : 0;
        const label = `${AIModule.formatContextSize(stats.sentSize)} / ${AIModule.formatContextSize(stats.limit)}`;
        const title = stats.truncated
            ? `上下文: ${label}，已发送最近 ${stats.sentMessages}/${stats.totalMessages} 条消息`
            : `上下文: ${label}，共 ${stats.totalMessages} 条消息`;

        ring.style.strokeDasharray = `${dash} 100`;
        if (size) size.textContent = label;
        meter.title = title;
        meter.setAttribute('aria-valuemax', String(stats.limit));
        meter.setAttribute('aria-valuenow', String(stats.sentSize));
        meter.setAttribute('aria-valuetext', title);
        meter.classList.toggle('warning', percent >= 80 && percent < 95);
        meter.classList.toggle('danger', percent >= 95);
    },

    /** 输入框自适应高度 */
    _autoResize() {
        const ta = this._els.userInput;
        if (!ta) return;
        ta.style.height = '24px';
        const sh = ta.scrollHeight;
        ta.style.height = (sh <= 80 ? sh : 80) + 'px';
    },

    /** 根据输入内容切换发送按钮状态 */
    _updateSendState() {
        const input = this._els.userInput;
        const btn = this._els.sendBtn;
        if (!btn) return;
        btn.disabled = !input || !input.value.trim();
    },

    /** 打开态下保持键盘焦点在顾问面板内 */
    _trapFocus(e) {
        const panel = this._els.panel;
        if (!panel) return;
        const focusable = Array.from(panel.querySelectorAll('button:not([disabled]), textarea:not([disabled]), input:not([disabled]), select:not([disabled]), a[href], [tabindex]:not([tabindex="-1"])'))
            .filter(el => el.offsetParent !== null || el === document.activeElement);
        if (!focusable.length) return;

        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        const active = document.activeElement;

        if (!panel.contains(active)) {
            e.preventDefault();
            first.focus();
        } else if (e.shiftKey && active === first) {
            e.preventDefault();
            last.focus();
        } else if (!e.shiftKey && active === last) {
            e.preventDefault();
            first.focus();
        }
    },

    /** 移动端滚动锁定 */
    _lockScroll() {
        if (APP.advisorScrollLocked) return;
        document.body.dataset.advisorPreviousOverflow = document.body.style.overflow || '';
        document.body.style.overflow = 'hidden';
        APP.advisorScrollLocked = true;
    },

    /** 恢复移动端滚动 */
    _unlockScroll() {
        if (!APP.advisorScrollLocked) return;
        document.body.style.overflow = document.body.dataset.advisorPreviousOverflow || '';
        delete document.body.dataset.advisorPreviousOverflow;
        APP.advisorScrollLocked = false;
    },

    /** 根据当前视口同步遮罩和滚动锁 */
    _syncModalState() {
        const isMobile = window.innerWidth <= 768;
        if (APP.advisorOpen && isMobile) {
            this._els.backdrop?.classList.add('open');
            this._lockScroll();
        } else {
            this._els.backdrop?.classList.remove('open');
            this._unlockScroll();
        }
    },

    /** 自选股侧边栏避让：打开时标记 body */
    notifyWatchlistOpen(isOpen) {
        document.body.classList.toggle('watchlist-open', isOpen);
    }
};

// ============================================================
// AI聊天模块
// ============================================================
const AIModule = {
    estimateContextSize(text) {
        if (!text) return 0;
        const value = String(text);
        const cjkMatches = value.match(/[\u3400-\u9fff\u3040-\u30ff\uac00-\ud7af]/g);
        const cjkCount = cjkMatches ? cjkMatches.length : 0;
        const asciiLike = value.replace(/[\u3400-\u9fff\u3040-\u30ff\uac00-\ud7af]/g, '');
        return Math.ceil(cjkCount + asciiLike.length / 4);
    },

    estimateMessageSize(message) {
        return this.estimateContextSize(message?.content || '') + 4;
    },

    formatContextSize(size) {
        const value = Number(size) || 0;
        if (value >= 1000000) return (value / 1000000).toFixed(1).replace(/\.0$/, '') + 'M';
        if (value >= 1000) return Math.round(value / 1000) + 'K';
        return String(value);
    },

    getContextMessages() {
        const messages = Array.isArray(APP.messageHistory) ? APP.messageHistory : [];
        const limit = APP.aiContextLimit || AI_CONTEXT_LIMIT;
        const totalSize = messages.reduce((sum, msg) => sum + this.estimateMessageSize(msg), 0);
        if (totalSize <= limit) return messages.slice();

        const systemMessages = messages.filter(msg => msg.role === 'system');
        const dialogueMessages = messages.filter(msg => msg.role !== 'system');
        const selected = [];
        let used = systemMessages.reduce((sum, msg) => sum + this.estimateMessageSize(msg), 0);

        for (let i = dialogueMessages.length - 1; i >= 0; i--) {
            const msg = dialogueMessages[i];
            const size = this.estimateMessageSize(msg);
            if (used + size <= limit || selected.length === 0) {
                selected.unshift(msg);
                used += size;
            } else {
                break;
            }
        }

        return systemMessages.concat(selected);
    },

    getContextStats() {
        const messages = Array.isArray(APP.messageHistory) ? APP.messageHistory : [];
        const contextMessages = this.getContextMessages();
        const totalMessages = messages.filter(msg => msg.role !== 'system').length;
        const sentMessages = contextMessages.filter(msg => msg.role !== 'system').length;
        return {
            limit: APP.aiContextLimit || AI_CONTEXT_LIMIT,
            totalSize: messages.reduce((sum, msg) => sum + this.estimateMessageSize(msg), 0),
            sentSize: contextMessages.reduce((sum, msg) => sum + this.estimateMessageSize(msg), 0),
            totalMessages,
            sentMessages,
            truncated: sentMessages < totalMessages
        };
    },

    autoSend(message) {
        APP.messageHistory.push({ role: 'user', content: message });
        if (typeof AdvisorModule !== 'undefined') AdvisorModule.updateContextMeter();
        this.appendMessage(message, null, 'user-message');
        const loadingMsg = this.appendMessage('思考中...', null, 'loading-message');
        let seconds = 0;
        const timer = setInterval(() => {
            seconds++;
            loadingMsg.textContent = `思考中... ${seconds}s`;
        }, 1000);
        this.sendToAI(loadingMsg, timer);
    },

    appendMessage(content, reasoningContent, className, targetContainer) {
        const container = targetContainer || APP.chatContainer;
        if (!container) return null;
        const div = document.createElement('div');
        div.classList.add('message', className);
        if (className === 'user-message') {
            const contentDiv = document.createElement('div');
            contentDiv.classList.add('content');
            contentDiv.textContent = content;
            div.appendChild(contentDiv);
        } else {
            if (reasoningContent) {
                const rd = document.createElement('div');
                rd.classList.add('reasoning-content');
                rd.innerHTML = DOMPurify.sanitize(marked.parse(reasoningContent));
                div.appendChild(rd);
            }
            if (content) {
                const cd = document.createElement('div');
                cd.classList.add('content');
                cd.innerHTML = DOMPurify.sanitize(marked.parse(content));
                div.appendChild(cd);
            }
        }
        container.appendChild(div);
        container.scrollTop = container.scrollHeight;

        // 消息气泡 GSAP 入场——autoAlpha 淡入
        // 使用 fromTo 显式指定目标状态，避免父元素动画干扰计算
        if (typeof gsap !== 'undefined') {
            gsap.fromTo(div,
                { autoAlpha: 0 },
                { autoAlpha: 1, duration: 0.3, ease: 'power2.out', clearProps: 'autoAlpha' }
            );
        }

        return div;
    },

    /**
     * 更新 bot 消息气泡中的 reasoning 区域
     */
    _updateReasoning(botDiv, fullReasoning) {
        let rd = botDiv.querySelector('.reasoning-content');
        if (!rd) {
            rd = document.createElement('div');
            rd.classList.add('reasoning-content');
            botDiv.insertBefore(rd, botDiv.firstChild);
        }
        // 推理内容用纯文本显示，避免大量 Markdown 解析卡顿
        rd.textContent = fullReasoning;
    },

    /**
     * 更新 bot 消息气泡中的 content 区域
     */
    _updateContent(botDiv, fullResponse) {
        let cd = botDiv.querySelector('.content');
        if (!cd) {
            cd = document.createElement('div');
            cd.classList.add('content');
            botDiv.appendChild(cd);
        }
        cd.innerHTML = DOMPurify.sanitize(marked.parse(fullResponse));
        // 使用 botDiv 的父级容器来滚动，而非硬编码 APP.chatContainer
        const scrollContainer = botDiv.closest('.chat-messages, .advisor-messages');
        if (scrollContainer) scrollContainer.scrollTop = scrollContainer.scrollHeight;
    },

    async sendToAI(loadingMessage, timer, targetContainer) {
        const requestVersion = APP.advisorRequestVersion;
        try {
            const requestMessages = this.getContextMessages();
            // 渠道和模型由后端 ai_api.php 的 $defaultChannel 统一控制
            const response = await fetch('ai_api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    session_id: APP.currentSessionId,
                    messages: requestMessages,
                    stream: true
                })
            });

            if (!response.ok) throw new Error(`服务器错误(${response.status})`);
            if (!response.body) throw new Error('无法获取响应流');

            const reader = response.body.getReader();
            const decoder = new TextDecoder('utf-8');
            let botDiv = this.appendMessage('', '', 'bot-message', targetContainer);
            let fullResponse = '';
            let fullReasoning = '';
            let streamDone = false;

            // SSE 行缓冲区：处理跨 chunk 的行分割
            let lineBuffer = '';

            while (true) {
                const { done, value } = await reader.read();
                if (done) break;

                // 将新数据追加到行缓冲区，按换行拆分
                lineBuffer += decoder.decode(value, { stream: true });
                const lines = lineBuffer.split('\n');

                // 最后一个元素可能是不完整的行，保留到下次处理
                lineBuffer = lines.pop() || '';

                for (const line of lines) {
                    const trimmed = line.trim();
                    if (!trimmed) continue;

                    // 流结束标记
                    if (trimmed === 'data: [DONE]') {
                        streamDone = true;
                        break;
                    }

                    // 解析 SSE data 行
                    if (trimmed.startsWith('data:')) {
                        const jsonStr = trimmed.slice(5).trim();
                        if (!jsonStr) continue;
                        try {
                            const json = JSON.parse(jsonStr);

                            // 错误响应
                            if (json.error) {
                                fullResponse = `**错误:** ${json.error.message || JSON.stringify(json.error)}`;
                                this._updateContent(botDiv, fullResponse);
                                continue;
                            }

                            // 增量 delta
                            const delta = json.choices?.[0]?.delta;
                            if (delta) {
                                // 推理内容（reasoning 模型特有）
                                if (delta.reasoning_content) {
                                    fullReasoning += delta.reasoning_content;
                                    this._updateReasoning(botDiv, fullReasoning);
                                }
                                // 正式回复内容
                                if (delta.content) {
                                    fullResponse += delta.content;
                                    this._updateContent(botDiv, fullResponse);
                                }
                            }
                        } catch (e) {
                            // JSON 解析失败，忽略（可能是不完整数据）
                        }
                    }
                }

                if (streamDone) break;
            }

            // 处理行缓冲区中可能残留的最后一行
            if (!streamDone && lineBuffer.trim()) {
                const trimmed = lineBuffer.trim();
                if (trimmed === 'data: [DONE]') {
                    streamDone = true;
                } else if (trimmed.startsWith('data:')) {
                    const jsonStr = trimmed.slice(5).trim();
                    if (jsonStr) {
                        try {
                            const json = JSON.parse(jsonStr);
                            if (json.error) {
                                fullResponse = `**错误:** ${json.error.message || JSON.stringify(json.error)}`;
                                this._updateContent(botDiv, fullResponse);
                            }
                            const delta = json.choices?.[0]?.delta;
                            if (delta) {
                                if (delta.reasoning_content) {
                                    fullReasoning += delta.reasoning_content;
                                    this._updateReasoning(botDiv, fullReasoning);
                                }
                                if (delta.content) {
                                    fullResponse += delta.content;
                                    this._updateContent(botDiv, fullResponse);
                                }
                            }
                        } catch (e) { /* ignore */ }
                    }
                }
            }

            // 流结束：清理 loading 状态
            clearInterval(timer);
            if (loadingMessage.parentNode) loadingMessage.parentNode.removeChild(loadingMessage);
            // 通知顾问面板思考结束
            if (typeof AdvisorModule !== 'undefined') AdvisorModule.setThinking(false);

            // 如果有推理内容，渲染为 Markdown
            if (fullReasoning) {
                const rd = botDiv.querySelector('.reasoning-content');
                if (rd) rd.innerHTML = DOMPurify.sanitize(marked.parse(fullReasoning));
            }

            // 将最终回复记入消息历史（仅记录正式内容，不含推理过程）
            // 如果用户已清空对话，丢弃旧流式请求的回写，避免清空后历史被旧响应恢复。
            if (requestVersion !== APP.advisorRequestVersion) return;
            if (fullResponse) {
                APP.messageHistory.push({ role: 'assistant', content: fullResponse });
            } else if (fullReasoning) {
                // 推理模型可能因超时只输出了推理过程而没有正式回复
                // 将推理内容也记入历史，避免下次重发时丢失上下文
                APP.messageHistory.push({ role: 'assistant', content: fullReasoning });
                this._updateContent(botDiv, '> ⚠️ 模型思考超时，仅返回了推理过程，请重新发送以获取完整回复。');
            } else {
                this._updateContent(botDiv, '**提示:** 服务器返回空响应，请检查网络或稍后重试。');
            }
            if (typeof AdvisorModule !== 'undefined') AdvisorModule.updateContextMeter();

        } catch(error) {
            clearInterval(timer);
            if (loadingMessage.parentNode) loadingMessage.parentNode.removeChild(loadingMessage);
            if (requestVersion === APP.advisorRequestVersion) {
                this.appendMessage(`**错误:** ${error.message}`, null, 'bot-message', targetContainer);
            }
            if (typeof AdvisorModule !== 'undefined') AdvisorModule.setThinking(false);
            if (typeof AdvisorModule !== 'undefined') AdvisorModule.updateContextMeter();
        }
    }
};

// 全局暴露给HTML的函数
function sendMessage() {
    if (!APP.userInput) return;
    const msg = APP.userInput.value.trim();
    if (!msg) return;
    APP.userInput.value = '';
    APP.userInput.style.height = '44px';
    APP.messageHistory.push({ role: 'user', content: msg });
    if (typeof AdvisorModule !== 'undefined') AdvisorModule.updateContextMeter();
    AIModule.appendMessage(msg, null, 'user-message');
    const loadingMsg = AIModule.appendMessage('思考中...', null, 'loading-message');
    let seconds = 0;
    const timer = setInterval(() => { seconds++; loadingMsg.textContent = `思考中... ${seconds}s`; }, 1000);
    AIModule.sendToAI(loadingMsg, timer);
}

function handleKeyDown(event) {
    if (!APP.userInput) return;
    if (event.key === 'Enter' && !event.shiftKey) { event.preventDefault(); sendMessage(); }
}

function autoResizeTextarea() {
    if (!APP.userInput) return;
    const min = 44, max = 100;
    APP.userInput.style.height = min + 'px';
    const sh = APP.userInput.scrollHeight;
    APP.userInput.style.height = (sh <= max ? sh : max) + 'px';
}

function switchTab(tabName) {
    let activeTab = null;
    document.querySelectorAll('.nav-tab').forEach(t => {
        const isActive = t.dataset.tab === tabName;
        t.classList.toggle('active', isActive);
        if (isActive) activeTab = t;
    });
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.toggle('active', p.id === 'panel-' + tabName));

    // 切换到 AI Tab 时，同步消息历史到 #chat-container
    if (tabName === 'ai' && APP.chatContainer) {
        const messages = APP.messageHistory.filter(m => m.role !== 'system');
        const existingMsgs = APP.chatContainer.querySelectorAll('.message');
        if (existingMsgs.length !== messages.length) {
            APP.chatContainer.innerHTML = '';
            messages.forEach(m => {
                if (m.role === 'user') {
                    AIModule.appendMessage(m.content, null, 'user-message');
                } else if (m.role === 'assistant') {
                    AIModule.appendMessage(m.content, null, 'bot-message');
                }
            });
            APP.chatContainer.scrollTop = APP.chatContainer.scrollHeight;
        }
    }

    if (activeTab && typeof activeTab.scrollIntoView === 'function') {
        activeTab.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
    }

    // Tab 切换面板入场——autoAlpha 淡入，零位移
    const activePanel = document.getElementById('panel-' + tabName);
    if (activePanel && typeof gsap !== 'undefined') {
        const cards = activePanel.querySelectorAll('.card');
        gsap.fromTo(cards,
            { autoAlpha: 0 },
            { autoAlpha: 1, duration: 0.35, stagger: 0.05, ease: 'power2.out', clearProps: 'autoAlpha' }
        );
    }

    window.requestAnimationFrame(() => {
        if (typeof ChartModule !== 'undefined' && typeof ChartModule.resize === 'function') {
            ChartModule.resize();
        }
        window.setTimeout(() => {
            if (typeof ChartModule !== 'undefined' && typeof ChartModule.resize === 'function') {
                ChartModule.resize();
            }
        }, 180);
    });

    // 刷新 ScrollTrigger 位置计算
    AnimationManager.refreshAfterTabSwitch();
}

// ============================================================
// 初始化
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
    // 确保弹窗遮罩初始隐藏
    const modalOverlay = document.getElementById('stock-modal-overlay');
    if (modalOverlay) modalOverlay.style.display = 'none';

    // 配置marked
    marked.setOptions({ gfm: true, breaks: false });

    // 初始化主题（在 GSAP 之前，以尽早应用主题）
    ThemeManager.init();

    // 移除主题加载锁，恢复过渡动画（延迟一帧确保首次渲染无闪烁）
    requestAnimationFrame(() => {
        requestAnimationFrame(() => {
            document.documentElement.classList.remove('theme-loading');
        });
    });

    // 初始化动画
    AnimationManager.init();

    // 初始化聊天
    APP.chatContainer = document.getElementById('chat-container');
    APP.userInput = document.getElementById('user-input');

    // 初始化 AI 顾问面板
    AdvisorModule.init();

    // 创建会话
    fetch('create_session.php', { method: 'POST' })
        .then(r => r.json())
        .then(d => { if (d.session) APP.currentSessionId = d.session.id; })
        .catch(() => {});

    // 初始化图表
    ChartModule.init();

    // Tab切换
    document.querySelectorAll('.nav-tab').forEach(tab => {
        tab.addEventListener('click', () => {
            const tabName = tab.dataset.tab;
            // AI顾问 Tab 点击时，改为打开顾问面板
            if (tabName === 'ai') {
                AdvisorModule.toggle();
                return;
            }
            switchTab(tabName);
            // 更新顾问面板上下文
            if (typeof AdvisorModule !== 'undefined') AdvisorModule.updateContext();
        });
    });

    // 自选股侧边栏
    document.getElementById('watchlist-toggle')?.addEventListener('click', () => {
        const sidebar = document.getElementById('watchlist-sidebar');
        const isOpen = !sidebar.classList.contains('open');
        sidebar.classList.toggle('open');
        document.getElementById('watchlist-overlay').classList.toggle('open');
        WatchlistModule.render();
        AnimationManager.animateSidebarOpen();
        // 通知顾问面板避让
        AdvisorModule.notifyWatchlistOpen(isOpen);
    });
    document.getElementById('watchlist-close')?.addEventListener('click', () => {
        document.getElementById('watchlist-sidebar').classList.remove('open');
        document.getElementById('watchlist-overlay').classList.remove('open');
        AdvisorModule.notifyWatchlistOpen(false);
    });
    document.getElementById('watchlist-overlay')?.addEventListener('click', () => {
        document.getElementById('watchlist-sidebar').classList.remove('open');
        document.getElementById('watchlist-overlay').classList.remove('open');
        AdvisorModule.notifyWatchlistOpen(false);
    });

    // 自选股添加
    document.getElementById('watchlist-add-btn')?.addEventListener('click', () => {
        const input = document.getElementById('watchlist-add-input');
        const code = input.value.trim();
        if (code) { WatchlistModule.add(code); input.value = ''; }
    });

    // 更新自选股计数
    document.getElementById('watchlist-count').textContent = APP.watchlist.length;

    // 加自选按钮
    document.getElementById('add-watchlist-btn')?.addEventListener('click', () => {
        const code = document.getElementById('code').value.trim();
        if (code) WatchlistModule.add(code, code);
    });

    // 指标切换
    ['ind-ma', 'ind-boll', 'ind-vol', 'ind-macd', 'ind-rsi', 'ind-kdj'].forEach(id => {
        document.getElementById(id)?.addEventListener('change', () => ChartModule.refreshIndicators());
    });

    // === 股票查询表单 ===
    const stockForm = document.getElementById('stockForm');
    const stockTable = document.getElementById('stock-table');
    const stockData = document.getElementById('stock-data');
    const loading = document.getElementById('loading');
    const errorDiv = document.getElementById('error');

    // AI分析按钮：设置标志后触发表单查询，查询完成后自动跳转AI标签卡
    document.getElementById('ai-analyze-btn')?.addEventListener('click', () => {
        const code = document.getElementById('code').value.trim();
        if (!code) { alert('请先输入股票代码'); return; }
        APP.queryWithAI = true;
        stockForm.dispatchEvent(new Event('submit'));
    });

    stockForm.addEventListener('submit', function(e) {
        e.preventDefault();
        const code = document.getElementById('code').value.trim();
        const frequency = document.getElementById('frequency').value;
        const count = document.getElementById('count').value;
        const end_date = document.getElementById('end_date').value;

        loading.style.display = 'flex';
        errorDiv.style.display = 'none';
        stockTable.style.display = 'none';
        APP.currentStockCode = code;

        const url = `api.php?code=${encodeURIComponent(code)}&frequency=${encodeURIComponent(frequency)}&count=${encodeURIComponent(count)}&end_date=${encodeURIComponent(end_date)}`;

        fetch(url)
            .then(r => r.json())
            .then(data => {
                loading.style.display = 'none';
                if (!data.success) throw new Error(data.message || '获取数据失败');

                stockData.innerHTML = '';
                if (data.data && data.data.length > 0) {
                    // 更新图表
                    ChartModule.updateData(data.data);
                    document.getElementById('chart-title').textContent = `📊 ${code} K线图表`;

                    // 更新加自选按钮
                    const starBtn = document.getElementById('add-watchlist-btn');
                    if (starBtn) {
                        starBtn.textContent = WatchlistModule.has(code) ? '⭐ 已自选' : '⭐ 加自选';
                    }

                    // 更新表格
                    let aiStr = `股票代码: ${code}\n频率: ${frequency}\n数据条数: ${count}\n\n`;
                    aiStr += "日期/时间,开盘价,收盘价,最高价,最低价,成交量\n";

                    data.data.forEach(row => {
                        const close = parseFloat(row.close);
                        const open = parseFloat(row.open);
                        const prevClose = stockData.rows.length > 0
                            ? parseFloat(stockData.rows[stockData.rows.length - 1]?.cells[2]?.textContent || open)
                            : open;
                        const pct = prevClose !== 0 ? ((close - prevClose) / prevClose * 100) : 0;

                        const tr = document.createElement('tr');
                        tr.innerHTML = `
                            <td>${row.time}</td>
                            <td>${parseFloat(row.open).toFixed(2)}</td>
                            <td>${close.toFixed(2)}</td>
                            <td class="up">${parseFloat(row.high).toFixed(2)}</td>
                            <td class="down">${parseFloat(row.low).toFixed(2)}</td>
                            <td>${formatVolume(row.volume)}</td>
                            <td class="${colorClass(pct)}">${formatPct(pct)}</td>
                        `;
                        stockData.appendChild(tr);
                        aiStr += `${row.time},${parseFloat(row.open).toFixed(2)},${close.toFixed(2)},${parseFloat(row.high).toFixed(2)},${parseFloat(row.low).toFixed(2)},${formatVolume(row.volume)}\n`;
                    });

                    stockTable.style.display = 'table';
                    document.getElementById('export-data-btn').style.display = 'inline-block';

                    // 获取实时行情
                    QuoteModule.fetch(code).then(q => QuoteModule.render(q));

                    // 根据标志决定是否触发AI分析
                    if (APP.queryWithAI) {
                        APP.queryWithAI = false;
                        // 先获取资金流向数据，再拼接AI提示词
                        FlowModule.fetch(code).then(flowData => {
                            FlowModule.render(flowData);
                            return flowData;
                        }).catch(e => {
                            console.warn('获取资金流向失败，AI分析将不含资金数据:', e);
                            return null;
                        }).then(flowData => {
                            // 将资金流向数据拼接到AI分析提示词
                            if (flowData && flowData.length > 0) {
                                const recentFlow = flowData.slice(-10);
                                aiStr += "\n\n💰 资金流向数据（近" + recentFlow.length + "日）：\n";
                                aiStr += "日期,主力净流入,超大单净流入,大单净流入,中单净流入,小单净流入\n";
                                recentFlow.forEach(f => {
                                    aiStr += `${f.time},${formatAmount(f.main_net_inflow)},${formatAmount(f.super_net_inflow)},${formatAmount(f.big_net_inflow)},${formatAmount(f.mid_net_inflow)},${formatAmount(f.small_net_inflow)}\n`;
                                });
                                aiStr += "请同时分析资金流向数据，关注主力资金动向、大单与散户资金的博弈关系，判断资金面是否支撑股价走势。\n";
                            }

                            aiStr += "\n请评估这些数据，考虑成交量、历史趋势、MACD指标、RSI、支撑位和阻力位等，给出投资建议。\n今天是：" + new Date().toISOString().split('T')[0];
                            // 优先打开顾问面板，而非切换到 AI Tab
                            APP.advisorContext.source = 'AI分析';
                            AdvisorModule.autoSend(aiStr);
                        });
                    } else {
                        // 非AI分析模式，直接获取资金流向渲染面板
                        FlowModule.fetch(code).then(f => FlowModule.render(f)).catch(e => console.warn('获取资金流向失败:', e));
                    }
                } else {
                    errorDiv.textContent = '没有查询到数据';
                    errorDiv.style.display = 'block';
                }
            })
            .catch(error => {
                loading.style.display = 'none';
                errorDiv.textContent = error.message;
                errorDiv.style.display = 'block';
            });
    });

    // 导出CSV
    document.getElementById('export-data-btn')?.addEventListener('click', () => {
        const rows = document.querySelectorAll('#stock-data tr');
        if (!rows.length) return;
        let csv = '日期/时间,开盘价,收盘价,最高价,最低价,成交量,涨跌幅\n';
        rows.forEach(tr => {
            const cells = tr.querySelectorAll('td');
            csv += Array.from(cells).map(c => c.textContent.trim()).join(',') + '\n';
        });
        const blob = new Blob(['\ufeff' + csv], { type: 'text/csv;charset=utf-8' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url; a.download = `stock_${APP.currentStockCode}_${new Date().toISOString().split('T')[0]}.csv`;
        document.body.appendChild(a); a.click(); document.body.removeChild(a);
        URL.revokeObjectURL(url);
    });

    // === 热门股票 ===
    const hotStocksTable = document.getElementById('hot-stocks-table');
    const hotStocksData = document.getElementById('hot-stocks-data');
    const loadingApi = document.getElementById('loading-api');
    const errorApi = document.getElementById('error-api');

    function fetchHotStocksData() {
        loadingApi.style.display = 'flex';
        errorApi.style.display = 'none';
        hotStocksTable.style.display = 'none';

        fetch('hot_stocks_api.php')
            .then(r => r.json())
            .then(data => {
                loadingApi.style.display = 'none';
                if (data.error) throw new Error(data.error);

                hotStocksData.innerHTML = '';
                if (data && data.length > 0) {
                    data.forEach(stock => {
                        const tr = document.createElement('tr');
                        const netInflow = parseFloat(stock.jlr);
                        const superInflow = parseFloat(stock.cjlr_super || 0);
                        const bigInflow = parseFloat(stock.cjlr_big || 0);
                        tr.innerHTML = `
                            <td>${stock.dm}</td>
                            <td>${stock.mc}</td>
                            <td>${parseFloat(stock.zxj).toFixed(2)}</td>
                            <td class="${colorClass(stock.zdf)}">${formatPct(stock.zdf)}</td>
                            <td>${parseFloat(stock.hsl).toFixed(2)}</td>
                            <td class="${colorClass(netInflow)}">${formatAmount(netInflow)}</td>
                            <td class="${colorClass(superInflow)}">${formatAmount(superInflow)}</td>
                            <td class="${colorClass(bigInflow)}">${formatAmount(bigInflow)}</td>
                            <td><button class="btn-quick-query" data-code="${stock.dm}">查询</button></td>
                        `;
                        hotStocksData.appendChild(tr);
                    });

                    hotStocksTable.style.display = 'table';

                    // 热门股票行入场动画
                    AnimationManager.animateHotRows();

                    document.querySelectorAll('.btn-quick-query').forEach(btn => {
                        btn.addEventListener('click', function() {
                            const code = this.getAttribute('data-code');
                            document.getElementById('code').value = code;
                            document.getElementById('frequency').value = '1d';
                            document.getElementById('count').value = '60';
                            stockForm.dispatchEvent(new Event('submit'));
                        });
                    });
                } else {
                    errorApi.textContent = '没有热门股票数据';
                    errorApi.style.display = 'block';
                }
            })
            .catch(error => {
                loadingApi.style.display = 'none';
                errorApi.textContent = error.message;
                errorApi.style.display = 'block';
            });
    }
    fetchHotStocksData();

    // === 超级查询 ===
    document.getElementById('super-query-btn')?.addEventListener('click', async function() {
        const rows = document.querySelectorAll('#hot-stocks-data tr');
        if (!rows.length) { alert('没有可查询的股票数据'); return; }

        const maxStocks = APP.config.maxQueryStocks || rows.length;
        const stockRows = Array.from(rows).slice(0, maxStocks);
        const total = stockRows.length;
        const loadingEl = document.getElementById('super-query-loading');
        loadingEl.style.display = 'block';
        APP.allStocksData = {};

        const batchSize = 5;
        for (let i = 0; i < total; i += batchSize) {
            const batch = stockRows.slice(i, Math.min(i + batchSize, total));
            const tasks = batch.map(row => {
                const code = row.cells[0]?.textContent.trim();
                const name = row.cells[1]?.textContent.trim();
                if (!code) return Promise.resolve();
                return fetch(`api.php?code=${encodeURIComponent(code)}&frequency=1d&count=60&end_date=`)
                    .then(r => r.json())
                    .then(data => {
                        if (data.success && data.data?.length > 0) {
                            APP.allStocksData[code] = { name, data: data.data };
                            row.classList.add('details-trigger');
                            row.setAttribute('data-code', code);
                            row.addEventListener('click', () => showStockModal(code));
                        }
                    })
                    .catch(() => {});
            });
            await Promise.all(tasks);
            loadingEl.innerHTML = `已加载 ${Object.keys(APP.allStocksData).length}/${total} 只股票`;
            await new Promise(r => setTimeout(r, 100));
        }

        loadingEl.style.display = 'none';
        const aiText = generateAIQueryText(APP.allStocksData);
        localStorage.setItem('superQueryData', aiText);
        document.getElementById('ask-ai-btn').style.display = 'inline-block';
        document.getElementById('download-query-btn').style.display = 'inline-block';
    });

    // AI选股
    document.getElementById('ask-ai-btn')?.addEventListener('click', () => {
        const text = localStorage.getItem('superQueryData');
        if (text) { APP.advisorContext.source = 'AI选股'; AdvisorModule.autoSend(text); }
        else alert('请先执行超级查询');
    });

    // 下载查询记录
    document.getElementById('download-query-btn')?.addEventListener('click', () => {
        const text = localStorage.getItem('superQueryData');
        if (text) {
            const blob = new Blob([text], { type: 'text/plain' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url; a.download = `股票分析数据_${new Date().toISOString().split('T')[0]}.txt`;
            document.body.appendChild(a); a.click(); document.body.removeChild(a);
            URL.revokeObjectURL(url);
        }
    });

    function showStockModal(code) {
        const stock = APP.allStocksData[code];
        if (!stock) return;
        const overlay = document.getElementById('stock-modal-overlay');
        document.getElementById('modal-stock-title').textContent = `${stock.name} (${code}) 60天数据`;
        const body = document.getElementById('modal-body-content');
        let html = '<table class="stock-details-table"><thead><tr><th>日期</th><th>开盘</th><th>收盘</th><th>最高</th><th>最低</th><th>成交量</th></tr></thead><tbody>';
        stock.data.forEach(item => {
            html += `<tr>
                <td>${item.time}</td>
                <td>${parseFloat(item.open).toFixed(2)}</td>
                <td>${parseFloat(item.close).toFixed(2)}</td>
                <td>${parseFloat(item.high).toFixed(2)}</td>
                <td>${parseFloat(item.low).toFixed(2)}</td>
                <td>${formatVolume(item.volume)}</td>
            </tr>`;
        });
        html += '</tbody></table>';
        body.innerHTML = html;
        overlay.style.display = 'flex';

        // 弹窗入场动画
        AnimationManager.animateModalIn();
    }

    document.getElementById('modal-close-btn')?.addEventListener('click', () => {
        const overlay = document.getElementById('stock-modal-overlay');
        if (overlay) overlay.style.display = 'none';
    });
    document.getElementById('stock-modal-overlay')?.addEventListener('click', (e) => {
        if (e.target === e.currentTarget) e.currentTarget.style.display = 'none';
    });

    function generateAIQueryText(allData) {
        let result = `# 股票超级查询结果\n\n查询时间: ${new Date().toLocaleString()}\n共查询 ${Object.keys(allData).length} 只股票的60天数据\n\n`;
        const hotRows = document.querySelectorAll('#hot-stocks-data tr');
        let hotInfo = {};
        hotRows.forEach(row => {
            const cells = row.cells;
            if (cells?.length >= 6) {
                hotInfo[cells[0].textContent.trim()] = {
                    name: cells[1].textContent.trim(),
                    price: cells[2].textContent.trim(),
                    change: cells[3].textContent.trim(),
                    turnover: cells[4].textContent.trim(),
                    netInflow: cells[5].textContent.trim(),
                    superInflow: cells[6]?.textContent?.trim() || '-',
                    bigInflow: cells[7]?.textContent?.trim() || '-',
                };
            }
        });
        for (const code in allData) {
            const stock = allData[code];
            result += `## ${stock.name} (${code})\n\n`;
            if (hotInfo[code]) {
                result += `当前价: ${hotInfo[code].price} | 涨跌: ${hotInfo[code].change}% | 主力净流入: ${hotInfo[code].netInflow} | 超大单: ${hotInfo[code].superInflow} | 大单: ${hotInfo[code].bigInflow}\n\n`;
            }
            result += `日期,开盘,收盘,最高,最低,成交量\n`;
            stock.data.forEach(item => {
                result += `${item.time},${parseFloat(item.open).toFixed(2)},${parseFloat(item.close).toFixed(2)},${parseFloat(item.high).toFixed(2)},${parseFloat(item.low).toFixed(2)},${formatVolume(item.volume)}\n`;
            });
            result += '\n';
        }
        result += `请根据以上数据分析，剔除今日涨跌幅超过10%的，考虑成交量、MACD、RSI、支撑阻力位等，选出几只下一个交易日最有可能涨的股票并分析原因。\n`;
        return result;
    }

    // === 实时看板 ===
    RealtimeModule.init();
    document.getElementById('realtime-add-btn')?.addEventListener('click', () => {
        const input = document.getElementById('realtime-code-input');
        const code = input.value.trim();
        if (code) { RealtimeModule.addCode(code); input.value = ''; }
    });
    document.getElementById('realtime-refresh-btn')?.addEventListener('click', () => RealtimeModule.refresh());

    // === 板块资金 ===
    document.getElementById('sector-query-btn')?.addEventListener('click', () => SectorModule.query());

    // === 基金分析 ===
    document.getElementById('fund-search-btn')?.addEventListener('click', () => FundModule.search());
    document.getElementById('fund-search-input')?.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') { e.preventDefault(); FundModule.search(); }
    });
    document.getElementById('fund-refresh-btn')?.addEventListener('click', () => FundModule.renderWatchlist());
    FundModule.renderWatchlist();

    // === AI聊天 ===
    document.getElementById('send-button')?.addEventListener('click', sendMessage);
    document.getElementById('clear-chat-btn')?.addEventListener('click', () => {
        APP.advisorRequestVersion++;
        APP.chatContainer.innerHTML = '';
        APP.messageHistory = [{ role: 'system', content: AI_SYSTEM_PROMPT }];
        // 同步清空顾问面板消息
        if (APP.advisorChatContainer) APP.advisorChatContainer.innerHTML = '';
        const advisorPanel = document.getElementById('ai-advisor-panel');
        if (advisorPanel) advisorPanel.classList.remove('has-messages');
        if (typeof AdvisorModule !== 'undefined') {
            AdvisorModule.setThinking(false);
            AdvisorModule.clearUnread();
            AdvisorModule.updateContextMeter();
        }
    });

    // === 入场数据：自动查询上证指数（仅查询，不触发AI） ===
    const codeInput = document.getElementById('code');
    if (codeInput && !codeInput.value.trim()) {
        codeInput.value = 'sh000001';
        APP.queryWithAI = false;
        stockForm.dispatchEvent(new Event('submit'));
    }
});
