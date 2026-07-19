// ============================================================
// FuckAshare - A股智能分析平台 主脚本
// ============================================================

// 统一 SVG 图标辅助（供动态渲染使用）
const Icons = {
    star: '<span class="ui-icon" aria-hidden="true"><svg><use href="#icon-star"></use></svg></span>',
    close: '<span class="ui-icon" aria-hidden="true"><svg><use href="#icon-close"></use></svg></span>',
    search: '<span class="ui-icon" aria-hidden="true"><svg><use href="#icon-search"></use></svg></span>',
    chart: '<span class="ui-icon" aria-hidden="true"><svg><use href="#icon-chart"></use></svg></span>',
    table: '<span class="ui-icon" aria-hidden="true"><svg><use href="#icon-table"></use></svg></span>',
    flow: '<span class="ui-icon" aria-hidden="true"><svg><use href="#icon-flow"></use></svg></span>',
    hot: '<span class="ui-icon" aria-hidden="true"><svg><use href="#icon-hot"></use></svg></span>',
    warning: '<span class="ui-icon" aria-hidden="true"><svg><use href="#icon-warning"></use></svg></span>',
    layers: '<span class="ui-icon" aria-hidden="true"><svg><use href="#icon-layers"></use></svg></span>',
    settings: '<span class="ui-icon" aria-hidden="true"><svg><use href="#icon-settings"></use></svg></span>',
    calendar: '<span class="ui-icon" aria-hidden="true"><svg><use href="#icon-calendar"></use></svg></span>'
};

function escapeHTML(value) {
    return String(value).replace(/[&<>"']/g, ch => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;'
    }[ch]));
}

function escapeAttr(value) {
    return escapeHTML(value).replace(/`/g, '&#96;');
}

function formatCompactSize(value) {
    const n = Number(value) || 0;
    if (n >= 1000000) return (n / 1000000).toFixed(1).replace(/\.0$/, '') + 'M';
    if (n >= 1000) return Math.round(n / 1000) + 'K';
    return String(n);
}

function countTextChars(text) {
    return Array.from(String(text || '')).length;
}

function submitStockQuery(code, options = {}) {
    const codeInput = document.getElementById('code');
    const sourceInput = document.getElementById('data-source');
    const frequencyInput = document.getElementById('frequency');
    const countInput = document.getElementById('count');
    const form = document.getElementById('stockForm');
    if (!codeInput || !form || !code) return;
    codeInput.value = code;
    delete codeInput.dataset.stockCode;
    delete codeInput.dataset.stockName;
    delete codeInput.dataset.selectedLabel;
    if (options.source && sourceInput) sourceInput.value = options.source;
    if (options.frequency && frequencyInput) frequencyInput.value = options.frequency;
    if (options.count && countInput) countInput.value = options.count;
    switchTab('stock');
    form.dispatchEvent(new Event('submit'));
}

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
        if (typeof DividendModule !== 'undefined' && DividendModule.eventChart) {
            DividendModule.updateEventChartTheme();
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
            ? 'radial-gradient(circle at ' + x + 'px ' + y + 'px, rgba(238,241,234,0.6) 0%, transparent 70%)'
            : 'radial-gradient(circle at ' + x + 'px ' + y + 'px, rgba(10,14,22,0.6) 0%, transparent 70%)';

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
        const flashColor = isUp ? 'rgba(246, 70, 93, 0.16)' : isDown ? 'rgba(46, 189, 133, 0.16)' : 'rgba(139, 149, 169, 0.12)';
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
const AI_SYSTEM_PROMPT = '你是一位专业的金融研究助理，擅长解读A股、板块资金、基金净值、估值和排行数据。回答时区分事实、推断和不确定性，避免把基金当作股票分析，结尾提示内容仅供研究参考，不构成投资建议。';
const AI_CONTEXT_LIMIT = 255000;
const AI_CONTEXT_MESSAGE_LIMIT = 100;

const APP = {
    // AI聊天
    chatContainer: null,
    userInput: null,
    messageHistory: [{ role: 'system', content: AI_SYSTEM_PROMPT }],
    chatDisplayHistory: [],
    currentSessionId: null,
    aiContextLimit: AI_CONTEXT_LIMIT,
    // 图表
    chart: null,
    candleSeries: null,
    volumeSeries: null,
    indicatorSeries: {},
    currentStockCode: '',
    currentStockName: '',
    currentStockData: [],
    stockQueryController: null,
    stockQueryRequestId: 0,
    // 自选状态已迁移至 WatchCenter；以下三项保留为兼容读取视图（派生自统一存储）。
    get watchlist() {
        return window.WatchCenter
            ? window.WatchCenter.queryView('stock').map(it => ({ code: it.code, name: it.name }))
            : [];
    },
    get fundWatchlist() {
        return window.WatchCenter
            ? window.WatchCenter.queryView('fund').map(it => ({ code: it.code, name: it.name }))
            : [];
    },
    get realtimeCodes() {
        return window.WatchCenter
            ? window.WatchCenter.queryView('monitor').map(it => it.code)
            : [];
    },
    realtimeTimer: null,
    // 超级查询
    allStocksData: {},
    // 查询时是否触发AI分析
    queryWithAI: false,
    // 配置
    config: {
        maxQueryStocks: 50,
        autoRefreshInterval: 30,
        dividendAutoRefreshSeconds: Math.max(300, Math.min(1800, Number(window.FA_RUNTIME_CONFIG?.dividendAutoRefreshSeconds) || 600)),
        fundDividendAutoRefreshSeconds: Math.max(300, Math.min(1800, Number(window.FA_RUNTIME_CONFIG?.fundDividendAutoRefreshSeconds) || 900)),
        superQueryMaxConcurrent: 3,
        collapseLongUserMessages: true,
        longUserMessageThreshold: 4000
    },
    // AI 顾问面板状态
    advisorOpen: false,
    advisorExpanded: false,
    advisorUnread: 0,
    advisorThinking: false,
    advisorContext: { assetType: '', assetCode: '', assetName: '', assetLabel: '', stock: '', tab: 'stock', source: '', dividendEvent: null },
    advisorChatContainer: null,
    advisorUserInput: null,
    advisorLastFocusedElement: null,
    advisorScrollLocked: false,
    advisorRequestVersion: 0,
    advisorReturnTab: 'stock'
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
    if (code.toLowerCase().startsWith('bj')) return '0.' + code.substring(2);
    if (/^6\d{5}$/.test(code)) return '1.' + code;
    if (/^(0|3|4|8)\d{5}$/.test(code) || /^92\d{4}$/.test(code)) return '0.' + code;
    return '1.' + code;
}

// 标准化股票代码为 sh/sz 格式
function normalizeCode(code) {
    code = code.trim();
    if (code.includes('.XSHG')) return 'sh' + code.replace('.XSHG', '');
    if (code.includes('.XSHE')) return 'sz' + code.replace('.XSHE', '');
    if (/^(?:[48]\d{5}|92\d{4})$/.test(code)) return 'bj' + code;
    if (/^[0-9]{6}$/.test(code)) {
        return (code.startsWith('6') ? 'sh' : 'sz') + code;
    }
    return code.toLowerCase();
}

// ============================================================
// 股票关键词搜索与候选选择
// ============================================================
const StockSearchModule = {
    input: null,
    resultsEl: null,
    items: [],
    activeIndex: -1,
    timer: null,
    controller: null,
    requestVersion: 0,

    init() {
        this.input = document.getElementById('code');
        this.resultsEl = document.getElementById('stock-search-results');
        if (!this.input || !this.resultsEl) return;

        this.input.addEventListener('input', () => {
            this.clearSelection();
            const keyword = this.input.value.trim();
            clearTimeout(this.timer);
            this.controller?.abort();
            if (!keyword) {
                this.hide();
                return;
            }
            this.timer = setTimeout(() => this.search(keyword), 220);
        });
        this.input.addEventListener('keydown', (event) => this.onKeydown(event));
        this.input.addEventListener('focus', () => {
            if (this.items.length && this.input.value.trim()) this.show();
        });
        this.input.addEventListener('blur', () => window.setTimeout(() => this.hide(), 120));
        this.resultsEl.addEventListener('mousedown', event => event.preventDefault());
        this.resultsEl.addEventListener('click', event => {
            const option = event.target.closest('[data-stock-index]');
            if (!option) return;
            this.select(Number(option.dataset.stockIndex));
        });
    },

    async search(keyword) {
        const version = ++this.requestVersion;
        this.controller = new AbortController();
        try {
            const response = await fetch(`stock_search_api.php?key=${encodeURIComponent(keyword)}&limit=10`, {
                signal: this.controller.signal,
                cache: 'no-store'
            });
            const payload = await response.json();
            if (version !== this.requestVersion || keyword !== this.input.value.trim()) return;
            if (!payload.success) {
                this.renderMessage(payload.message || '股票搜索暂时不可用', 'error');
                return;
            }
            this.render(payload.data || []);
        } catch (error) {
            if (error.name !== 'AbortError' && version === this.requestVersion) {
                this.renderMessage('股票搜索暂时不可用，可继续输入准确代码查询', 'error');
            }
        } finally {
            if (version === this.requestVersion) this.controller = null;
        }
    },

    render(items) {
        this.items = Array.isArray(items) ? items : [];
        this.activeIndex = -1;
        if (!this.items.length) {
            this.renderMessage('未找到匹配的沪深北 A 股', 'empty');
            return;
        }
        this.resultsEl.innerHTML = this.items.map((item, index) => `
            <button type="button" class="stock-search-option" role="option" aria-selected="false" data-stock-index="${index}">
                <span class="stock-search-primary">
                    <strong>${escapeHTML(item.name || '-')}</strong>
                    <span>${escapeHTML(item.symbol || item.code || '')}</span>
                </span>
                <span class="stock-search-meta">${escapeHTML(item.security_type || item.market || 'A股')} · ${escapeHTML(item.pinyin || '')}</span>
            </button>
        `).join('');
        this.show();
    },

    renderMessage(message, type = 'empty') {
        this.items = [];
        this.activeIndex = -1;
        this.resultsEl.innerHTML = `<div class="stock-search-message ${type === 'error' ? 'is-error' : ''}">${escapeHTML(message)}</div>`;
        this.show();
    },

    showCandidates(items) {
        if (Array.isArray(items) && items.length) this.render(items);
    },

    select(index) {
        const item = this.items[index];
        if (!item) return;
        this.input.value = item.name || item.symbol;
        this.input.dataset.stockCode = item.symbol;
        this.input.dataset.stockName = item.name || '';
        this.input.dataset.selectedLabel = this.input.value;
        this.hide();
        this.input.focus();
        this.input.form?.dispatchEvent(new Event('submit'));
    },

    syncResolved(stock) {
        if (!stock || !stock.symbol) return;
        const current = this.input.value.trim();
        if (stock.name && (current === stock.name || current === stock.symbol || current === stock.code)) {
            this.input.value = stock.name;
        }
        this.input.dataset.stockCode = stock.symbol;
        this.input.dataset.stockName = stock.name || '';
        this.input.dataset.selectedLabel = this.input.value;
        this.hide();
    },

    resolvedQuery() {
        if (this.input.dataset.stockCode && this.input.dataset.selectedLabel === this.input.value) {
            return this.input.dataset.stockCode;
        }
        return this.input.value.trim();
    },

    resolvedName() {
        if (this.input.dataset.stockCode && this.input.dataset.selectedLabel === this.input.value) {
            return this.input.dataset.stockName || '';
        }
        return '';
    },

    clearSelection() {
        delete this.input.dataset.stockCode;
        delete this.input.dataset.stockName;
        delete this.input.dataset.selectedLabel;
    },

    onKeydown(event) {
        if (this.resultsEl.hidden || !this.items.length) return;
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            const delta = event.key === 'ArrowDown' ? 1 : -1;
            this.activeIndex = (this.activeIndex + delta + this.items.length) % this.items.length;
            this.updateActive();
        } else if (event.key === 'Enter' && this.activeIndex >= 0) {
            event.preventDefault();
            this.select(this.activeIndex);
        } else if (event.key === 'Escape') {
            event.preventDefault();
            this.hide();
        }
    },

    updateActive() {
        this.resultsEl.querySelectorAll('[data-stock-index]').forEach((option, index) => {
            const active = index === this.activeIndex;
            option.classList.toggle('is-active', active);
            option.setAttribute('aria-selected', active ? 'true' : 'false');
            if (active) option.scrollIntoView({ block: 'nearest' });
        });
    },

    show() {
        this.resultsEl.hidden = false;
        this.input.setAttribute('aria-expanded', 'true');
    },

    hide() {
        this.resultsEl.hidden = true;
        this.input?.setAttribute('aria-expanded', 'false');
        this.activeIndex = -1;
    }
};

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

    // 获取当前主题的图表配色：优先读取 style.css 的 --chart-* 设计令牌，
    // 读取失败时回退到内置色板，保证图表始终与页面主题一致
    getChartColors() {
        const style = getComputedStyle(document.documentElement);
        const isLight = ThemeManager.getEffectiveTheme() === 'light';
        const cssVar = (name, fallback) => {
            const v = style.getPropertyValue(name).trim();
            return v || fallback;
        };
        // hex → rgba（MACD 柱需要半透明；非 hex 值原样返回）
        const withAlpha = (color, alpha) => {
            const m = /^#([0-9a-f]{6})$/i.exec(color);
            if (!m) return color;
            const n = parseInt(m[1], 16);
            return `rgba(${(n >> 16) & 255}, ${(n >> 8) & 255}, ${n & 255}, ${alpha})`;
        };

        const fb = isLight ? {
            bg: '#fbfdf8', text: '#5a6b5e', grid: '#e5eadd', border: '#cbd5c2',
            up: '#d43e4d', down: '#159568',
            volUp: 'rgba(212, 62, 77, 0.35)', volDown: 'rgba(21, 149, 104, 0.35)'
        } : {
            bg: '#10151f', text: '#8b98af', grid: '#1a2130', border: '#273043',
            up: '#f6465d', down: '#2ebd85',
            volUp: 'rgba(246, 70, 93, 0.42)', volDown: 'rgba(46, 189, 133, 0.42)'
        };

        const bg = cssVar('--chart-bg', fb.bg);
        const text = cssVar('--chart-text', fb.text);
        const grid = cssVar('--chart-grid', fb.grid);
        const border = cssVar('--chart-border', fb.border);
        const up = cssVar('--chart-up', fb.up);
        const down = cssVar('--chart-down', fb.down);
        const volUp = cssVar('--chart-vol-up', fb.volUp);
        const volDown = cssVar('--chart-vol-down', fb.volDown);

        return {
            layout: {
                background: { type: 'solid', color: bg },
                textColor: text,
                fontSize: 12,
            },
            grid: {
                vertLines: { color: grid },
                horzLines: { color: grid },
            },
            crosshair: {
                mode: 0, // Normal
                vertLine: { color: border, width: 1, style: 2 },
                horzLine: { color: border, width: 1, style: 2 },
            },
            rightPriceScale: { borderColor: border },
            timeScale: { borderColor: border, timeVisible: true },
            candle: {
                upColor: up, downColor: down,
                borderUpColor: up, borderDownColor: down,
                wickUpColor: up, wickDownColor: down,
            },
            volume: {
                up: volUp,
                down: volDown,
            },
            macd: {
                up: withAlpha(up, 0.6),
                down: withAlpha(down, 0.6),
            }
        };
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
        const displayCode = normalizeCode(quote.symbol || quote.code || '');
        el.innerHTML = `
            <div class="quote-stock-name">
                <span>${quote.name || '-'}</span>
                <span style="font-size:0.8rem;color:var(--text-muted);font-family:var(--font-mono)">${escapeHTML(displayCode)}</span>
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
        this.renderLoading();
        const resp = await fetch(`stock_flow_api.php?code=${encodeURIComponent(code)}`);
        let data;
        try {
            data = await resp.json();
        } catch (error) {
            throw new Error(`资金流向接口响应无法解析 (HTTP ${resp.status})`);
        }
        if (!resp.ok || !data.success) {
            const error = new Error(data.message || `资金流向请求失败 (HTTP ${resp.status})`);
            error.code = data.code || 'stock_flow_request_failed';
            error.meta = data.meta || {};
            throw error;
        }
        const rows = Array.isArray(data.data) ? data.data : [];
        Object.defineProperty(rows, 'flowMeta', {
            value: { ...(data.meta || {}), fallback: Boolean(data.fallback) },
            enumerable: false,
        });
        return rows;
    },

    renderLoading() {
        const el = document.getElementById('flow-content');
        if (el) el.innerHTML = '<div class="loading-spinner"><div class="spinner"></div><span>获取资金流向...</span></div>';
    },

    renderError(error) {
        const el = document.getElementById('flow-content');
        if (!el) return;
        const code = error?.code ? ` data-error-code="${escapeHTML(error.code)}"` : '';
        el.innerHTML = `<div class="flow-error"${code}><p class="placeholder-text">资金流向暂不可用</p><small>上游数据链路异常，请稍后重试</small></div>`;
        console.error('获取资金流向失败:', error);
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

        const meta = flowData.flowMeta || {};
        const timeLabel = String(latest.time).includes(' ') ? '时间' : '日期';
        const fallbackBadge = meta.partial
            ? '<span class="flow-fallback-badge" title="历史资金接口暂不可用，当前展示盘中最新累计值">降级 · 盘中</span>'
            : meta.latest_is_intraday
                ? '<span class="flow-fallback-badge" title="历史序列已合并当日盘中最新累计值">盘中</span>'
                : '';
        let html = `<div style="font-size:0.8rem;color:var(--text-muted);margin-bottom:8px">${timeLabel}: ${escapeHTML(latest.time)} ${fallbackBadge}</div><div class="flow-bars">`;
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
// 自选股模块 — 兼容适配层（薄壳）
// 存储与界面已迁移至 WatchCenter / WatchCenterUI；此处仅保留旧调用点所需 API。
// ============================================================
const WatchlistModule = {
    add(code, name) {
        if (window.WatchCenter) window.WatchCenter.addItem('stock', code, name || code);
    },
    remove(code) {
        if (window.WatchCenter) {
            const norm = window.AppUtil.normalizeStockCode(code);
            window.WatchCenter.removeItem('stock:' + norm);
        }
    },
    has(code) {
        return window.WatchCenter ? window.WatchCenter.has('stock', code) : false;
    },
    render() { /* 由自选中心接管 */ },
    refreshPrices() { /* 由自选中心接管 */ },
    query(code) {
        if (typeof submitStockQuery === 'function') submitStockQuery(code, { withAI: false });
    }
};

// ============================================================
// 实时看板模块 — 兼容适配层（薄壳）
// 功能已并入自选中心（monitor 属性 + 统一刷新协调）；保留 addCode 供旧入口调用。
// ============================================================
const RealtimeModule = {
    init() { /* 由 WatchCenterUI 接管 */ },
    addCode(code) {
        if (window.WatchCenter) {
            window.WatchCenter.addItem('stock', code, '', { monitor: true });
        }
    },
    removeCode(code) {
        if (window.WatchCenter) {
            const norm = window.AppUtil.normalizeStockCode(code);
            const it = window.WatchCenter.getItem('stock:' + norm);
            if (it) window.WatchCenter.updateItem(it.id, { monitor: false });
        }
    },
    refresh() { if (window.WatchCenterUI) window.WatchCenterUI.refreshCurrentScope(); },
    render() {},
    renderPlaceholder() {},
    renderCards() {},
    startAutoRefresh() {}
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
// 分红日历模块
// ============================================================
const DividendModule = {
    initialized: false,
    mode: 'stock',
    loaded: false,
    items: [],
    pagination: { page: 1, pages: 1, total: 0 },
    meta: {},
    controller: null,
    loadRequestId: 0,
    savedStates: { stock: null, fund: null },
    marketController: null,
    timer: null,
    lastFocused: null,
    detailData: null,
    detailSelected: null,
    detailMode: 'stock',
    historyPage: 1,
    historyPageSize: 8,
    selectedHistoryIndex: -1,
    eventChart: null,
    eventChartSeries: null,
    eventChartResizeObserver: null,
    eventChartRows: [],
    eventChartSummary: {},

    init() {
        if (this.initialized || !document.getElementById('panel-dividend')) return;
        this.initialized = true;
        this.applyWindow(14);
        document.querySelectorAll('.dividend-window-btn').forEach(btn => btn.addEventListener('click', () => {
            this.applyWindow(parseInt(btn.dataset.days || '14', 10));
        }));
        document.querySelectorAll('.dividend-mode-btn').forEach(btn => btn.addEventListener('click', () => {
            this.switchMode(btn.dataset.dividendMode || 'stock');
        }));
        document.getElementById('dividend-filter-form')?.addEventListener('submit', e => {
            e.preventDefault();
            this.setFilterExpanded(false);
            this.load(1);
        });
        document.getElementById('dividend-filter-toggle')?.addEventListener('click', () => {
            const expanded = document.getElementById('dividend-filter-toggle')?.getAttribute('aria-expanded') === 'true';
            this.setFilterExpanded(!expanded);
        });
        document.getElementById('dividend-refresh-btn')?.addEventListener('click', () => this.load(this.pagination.page || 1, true));
        document.getElementById('dividend-prev-page')?.addEventListener('click', () => this.load(Math.max(1, this.pagination.page - 1)));
        document.getElementById('dividend-next-page')?.addEventListener('click', () => this.load(Math.min(this.pagination.pages, this.pagination.page + 1)));
        document.getElementById('dividend-scan-ai-btn')?.addEventListener('click', () => this.scanWithAI());
        document.getElementById('panel-dividend')?.addEventListener('click', e => {
            const button = e.target.closest('[data-dividend-action]');
            if (!button) return;
            const item = this.items.find(row => row.code === button.dataset.code && (row.record_date || '') === (button.dataset.recordDate || ''));
            const fallback = item || this.items.find(row => row.code === button.dataset.code);
            if (!fallback) return;
            if (button.dataset.dividendAction === 'detail') this.openDetail(fallback, button);
            if (button.dataset.dividendAction === 'ai') this.analyzeWithAI(fallback);
        });
        document.getElementById('dividend-detail-close')?.addEventListener('click', () => this.closeDetail());
        document.getElementById('dividend-detail-overlay')?.addEventListener('click', e => {
            if (e.target.id === 'dividend-detail-overlay') { this.closeDetail(); return; }
            const historyItem = e.target.closest('[data-dividend-history-index]');
            if (historyItem) { this.selectHistoryEvent(Number(historyItem.dataset.dividendHistoryIndex)); return; }
            const pageButton = e.target.closest('[data-dividend-history-page]');
            if (pageButton) { this.setHistoryPage(Number(pageButton.dataset.dividendHistoryPage)); return; }
            const eventNav = e.target.closest('[data-dividend-event-nav]');
            if (eventNav) this.selectHistoryEvent(this.selectedHistoryIndex + Number(eventNav.dataset.dividendEventNav));
        });
        document.addEventListener('keydown', e => {
            const overlay = document.getElementById('dividend-detail-overlay');
            if (e.key === 'Escape' && overlay?.classList.contains('open')) this.closeDetail();
            if (e.key === 'Tab' && overlay?.classList.contains('open')) {
                const focusable = Array.from(overlay.querySelectorAll('button:not([disabled]), a[href], input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])')).filter(el => el.offsetParent !== null);
                if (!focusable.length) return;
                const first = focusable[0];
                const last = focusable[focusable.length - 1];
                if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
                else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
            }
        });
    },

    switchMode(mode) {
        if (mode === this.mode || !['stock', 'fund'].includes(mode)) return;
        // 保存当前模式状态（含共享日期窗口，股票/基金专属筛选由 DOM 显隐自然保留）
        this.savedStates[this.mode] = {
            loaded: this.loaded,
            items: this.items,
            pagination: this.pagination,
            meta: this.meta,
            start_date: document.getElementById('dividend-start-date')?.value || '',
            end_date: document.getElementById('dividend-end-date')?.value || '',
            window_days: parseInt(document.querySelector('.dividend-window-btn.active')?.dataset.days || '14', 10),
        };
        // 中止旧请求，防止旧响应覆盖新模式
        if (this.controller) this.controller.abort();
        this.controller = null;
        this.loadRequestId++;
        this.mode = mode;
        // 恢复目标模式状态
        const saved = this.savedStates[mode];
        if (saved) {
            this.loaded = saved.loaded;
            this.items = saved.items || [];
            this.pagination = saved.pagination || { page: 1, pages: 1, total: 0 };
            this.meta = saved.meta || {};
            // 恢复共享日期窗口
            if (saved.start_date) { const s = document.getElementById('dividend-start-date'); if (s) s.value = saved.start_date; }
            if (saved.end_date) { const e = document.getElementById('dividend-end-date'); if (e) e.value = saved.end_date; }
            document.querySelectorAll('.dividend-window-btn').forEach(btn => btn.classList.toggle('active', parseInt(btn.dataset.days || '0', 10) === (saved.window_days || 14)));
        } else {
            this.loaded = false;
            this.items = [];
            this.pagination = { page: 1, pages: 1, total: 0 };
            this.meta = {};
        }
        this.updateModeDom();
        this.restartAutoRefreshTimer();
        if (this.loaded) {
            this.renderSummary(this.meta.__summary || {});
            this.renderItems();
            this.renderPagination();
            const updated = document.getElementById('dividend-updated-at');
            if (updated) updated.textContent = `数据时间 ${this.formatDateTime(this.meta.as_of || this.meta.updated_at)}`;
            const table = document.getElementById('dividend-table');
            const mobile = document.getElementById('dividend-mobile-list');
            const empty = document.getElementById('dividend-empty');
            if (table) table.style.display = this.items.length ? 'table' : 'none';
            if (mobile) mobile.style.display = this.items.length && window.innerWidth <= 768 ? 'flex' : '';
            if (empty) empty.style.display = this.items.length ? 'none' : 'block';
        } else {
            // 首次切换才加载
            this.applyWindow(14);
            this.load(1);
        }
    },

    updateModeDom() {
        const isFund = this.mode === 'fund';
        document.querySelectorAll('[data-stock-only]').forEach(el => { el.hidden = isFund; });
        document.querySelectorAll('[data-fund-only]').forEach(el => { el.hidden = !isFund; });
        document.querySelectorAll('[data-mode-text]').forEach(el => { el.hidden = (el.dataset.modeText !== this.mode); });
        document.querySelectorAll('.dividend-mode-btn').forEach(btn => {
            const active = btn.dataset.dividendMode === this.mode;
            btn.classList.toggle('active', active);
            btn.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
        const thead = document.getElementById('dividend-table-head');
        if (thead) thead.innerHTML = isFund
            ? '<tr><th>基金</th><th>登记 / 除息 / 发放</th><th>每份分红</th><th>参考净值</th><th>本次分配比例</th><th>事件阶段</th><th>操作</th></tr>'
            : '<tr><th>股票</th><th>登记 / 除息</th><th>每股现金</th><th>参考价</th><th>本次毛率</th><th>税后现金率</th><th>状态</th><th>操作</th></tr>';
        const loading = document.getElementById('dividend-loading');
        const span = loading?.querySelector('span');
        if (span) span.textContent = isFund ? '正在合并基金分红事件与净值...' : '正在合并分红事件与行情...';
    },

    setFilterExpanded(expanded) {
        const form = document.getElementById('dividend-filter-form');
        const toggle = document.getElementById('dividend-filter-toggle');
        form?.classList.toggle('mobile-expanded', Boolean(expanded));
        toggle?.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        if (toggle) toggle.textContent = expanded ? '收起筛选' : '筛选';
    },

    applyWindow(days) {
        const start = new Date();
        const end = new Date(start);
        end.setDate(end.getDate() + Math.max(1, days) - 1);
        const localDate = date => {
            const y = date.getFullYear();
            const m = String(date.getMonth() + 1).padStart(2, '0');
            const d = String(date.getDate()).padStart(2, '0');
            return `${y}-${m}-${d}`;
        };
        const startInput = document.getElementById('dividend-start-date');
        const endInput = document.getElementById('dividend-end-date');
        if (startInput) startInput.value = localDate(start);
        if (endInput) endInput.value = localDate(end);
        document.querySelectorAll('.dividend-window-btn').forEach(btn => btn.classList.toggle('active', parseInt(btn.dataset.days || '0', 10) === days));
    },

    filters() {
        if (this.mode === 'fund') {
            const sortBy = document.getElementById('dividend-fund-sort')?.value || 'record_date';
            return {
                asset_type: 'fund',
                start_date: document.getElementById('dividend-start-date')?.value || '',
                end_date: document.getElementById('dividend-end-date')?.value || '',
                fund_category: document.getElementById('dividend-fund-category')?.value || 'all',
                min_distribution_ratio: document.getElementById('dividend-min-ratio')?.value || '0',
                sort_by: sortBy,
                order: sortBy === 'record_date' ? 'asc' : 'desc',
            };
        }
        return {
            start_date: document.getElementById('dividend-start-date')?.value || '',
            end_date: document.getElementById('dividend-end-date')?.value || '',
            market: document.getElementById('dividend-market')?.value || 'all',
            status: document.getElementById('dividend-status')?.value || 'confirmed',
            holding_period: document.getElementById('dividend-holding')?.value || 'within_1m',
            min_yield: document.getElementById('dividend-min-yield')?.value || '0',
            sort_by: document.getElementById('dividend-sort')?.value || 'gross_yield',
            order: (document.getElementById('dividend-sort')?.value || '') === 'record_date' ? 'asc' : 'desc',
        };
    },

    async load(page = 1, force = false, silent = false) {
        if (this.controller) this.controller.abort();
        this.controller = new AbortController();
        const controller = this.controller;
        const requestId = ++this.loadRequestId;
        const loading = document.getElementById('dividend-loading');
        const table = document.getElementById('dividend-table');
        const mobile = document.getElementById('dividend-mobile-list');
        const empty = document.getElementById('dividend-empty');
        const alert = document.getElementById('dividend-alert');
        if (!silent && loading) loading.style.display = 'flex';
        if (!silent && empty) empty.style.display = 'none';
        if (!silent && alert) { alert.style.display = 'none'; alert.className = 'dividend-alert'; }
        const params = new URLSearchParams({ action: 'dividend_calendar', ...this.filters(), page: String(page), page_size: '50' });
        if (force) params.set('_', String(Date.now()));
        try {
            const response = await fetch(`market_api.php?${params}`, { signal: controller.signal, cache: 'no-store' });
            const payload = await response.json();
            if (!payload.success) throw new Error(payload.message || '获取分红日历失败');
            if (requestId !== this.loadRequestId) return;
            const data = payload.data || {};
            this.items = Array.isArray(data.items) ? data.items : [];
            this.pagination = data.pagination || { page, pages: 1, total: this.items.length };
            this.meta = payload.meta || {};
            this.meta.__summary = data.summary || {};
            this.loaded = true;
            this.renderSummary(data.summary || {});
            this.renderItems();
            this.renderPagination();
            const updated = document.getElementById('dividend-updated-at');
            if (updated) updated.textContent = `数据时间 ${this.formatDateTime(this.meta.as_of || this.meta.updated_at)}`;
            const resultSummary = document.getElementById('dividend-result-summary');
            if (resultSummary) resultSummary.textContent = this.mode === 'fund'
                ? `共 ${this.pagination.total || 0} 项 · 事件源 ${payload.source || '-'} · 净值源 ${this.meta.nav_source || '-'}`
                : `共 ${this.pagination.total || 0} 项 · 事件源 ${payload.source || '-'} · 行情源 ${this.meta.price_source || '-'}`;
            const cacheState = this.meta.upstream_cache || this.meta.cache || 'fresh';
            if (this.meta.partial || cacheState === 'stale' || cacheState === 'stale_fallback') {
                const missing = this.mode === 'fund' ? (this.meta.missing_nav_count || 0) : (this.meta.missing_quote_count || 0);
                this.showAlert(`部分数据可能不完整：缺少 ${missing} 条${this.mode === 'fund' ? '净值' : '行情'}；事件缓存状态 ${cacheState}。`, false);
            }
            if (table) table.style.display = this.items.length ? 'table' : 'none';
            if (mobile) mobile.style.display = this.items.length && window.innerWidth <= 768 ? 'flex' : '';
            if (empty) empty.style.display = this.items.length ? 'none' : 'block';
        } catch (error) {
            if (error.name === 'AbortError') return;
            if (requestId !== this.loadRequestId) return;
            if (silent) {
                console.warn('分红日历自动刷新失败:', error);
                return;
            }
            this.items = [];
            if (table) table.style.display = 'none';
            if (mobile) mobile.innerHTML = '';
            if (empty) empty.style.display = 'block';
            this.showAlert(error.message || '获取分红日历失败', true);
        } finally {
            if (requestId === this.loadRequestId && !silent && loading) loading.style.display = 'none';
        }
    },

    isAShareTradingSession(date = new Date()) {
        const parts = Object.fromEntries(new Intl.DateTimeFormat('en-US', {
            timeZone: 'Asia/Shanghai',
            weekday: 'short',
            hour: '2-digit',
            minute: '2-digit',
            hourCycle: 'h23'
        }).formatToParts(date).filter(part => part.type !== 'literal').map(part => [part.type, part.value]));
        if (parts.weekday === 'Sat' || parts.weekday === 'Sun') return false;
        const minutes = Number(parts.hour) * 60 + Number(parts.minute);
        return (minutes >= 570 && minutes <= 690) || (minutes >= 780 && minutes <= 900);
    },

    renderSummary(summary) {
        if (this.mode === 'fund') {
            const set = (id, value) => { const el = document.getElementById(id); if (el) el.textContent = value; };
            set('dividend-fund-summary-count', String(summary.event_count ?? 0));
            set('dividend-fund-summary-soon', String(summary.within_3_days_count ?? 0));
            set('dividend-fund-summary-max', this.percent(summary.max_distribution_ratio_pct));
            set('dividend-fund-summary-median', this.percent(summary.median_distribution_ratio_pct));
            set('dividend-fund-summary-coverage', `覆盖 ${summary.ratio_coverage_count ?? 0} 条`);
            return;
        }
        const set = (id, value) => { const el = document.getElementById(id); if (el) el.textContent = value; };
        set('dividend-summary-confirmed', String(summary.confirmed_count ?? 0));
        set('dividend-summary-soon', String(summary.within_3_days_count ?? 0));
        set('dividend-summary-max', this.percent(summary.max_gross_yield_pct));
        set('dividend-summary-median', this.percent(summary.median_net_yield_pct));
        const holding = this.filters().holding_period;
        const captions = { within_1m: '个人短持20%税估算', '1m_to_1y': '个人持有10%税估算', over_1y: '个人持有超1年估算' };
        set('dividend-tax-caption', captions[holding] || '个人税后估算');
    },

    fundStageLabel(stage) {
        return { upcoming_record: '待登记', upcoming_ex: '待除息', payment_pending: '待发放', completed: '已完成' }[stage] || stage || '-';
    },

    fundRatioStatusNote(item) {
        if (item.currency_status === 'unknown') return '未知币种，不计算比例';
        if (item.ratio_status === 'missing_nav') return '缺最新净值，比例暂缺';
        if (item.ratio_status === 'nav_not_pre_ex') return '净值日期不早于除息日，比例暂缺';
        if (item.ratio_status === 'currency_unverified') return '币种未确认，比例暂缺';
        return '';
    },

    renderItems() {
        if (this.mode === 'fund') { this.renderFundItems(); return; }
        const tbody = document.getElementById('dividend-table-body');
        const mobile = document.getElementById('dividend-mobile-list');
        if (tbody) tbody.innerHTML = this.items.map(item => `
            <tr>
                <td><div class="dividend-stock-cell"><span class="dividend-stock-avatar">${escapeHTML((item.market || '').toUpperCase())}</span><div><b>${escapeHTML(item.name || '-')}</b><small>${escapeHTML(item.code || '')}</small></div></div></td>
                <td><div class="dividend-date-stack"><span>登记 ${escapeHTML(item.record_date || '-')}</span><small>除息 ${escapeHTML(item.ex_date || '-')}</small></div></td>
                <td><span class="dividend-cash">¥${this.number(item.cash_per_share, 6)}</span></td>
                <td>¥${this.number(item.price, 2)}</td>
                <td><span class="dividend-yield">${this.percent(item.gross_yield_pct)}</span></td>
                <td><span class="dividend-yield dividend-net-yield">${this.percent(item.net_yield_pct)}</span></td>
                <td><span class="dividend-status-badge${item.implementation_confirmed ? '' : ' unconfirmed'}">${item.implementation_confirmed ? '已实施' : escapeHTML(item.plan_status || '未确认')}</span></td>
                <td><div class="dividend-row-actions"><button class="btn-sm" data-dividend-action="detail" data-code="${escapeAttr(item.code)}" data-record-date="${escapeAttr(item.record_date || '')}">详情</button><button class="btn-sm btn-ai" data-dividend-action="ai" data-code="${escapeAttr(item.code)}" data-record-date="${escapeAttr(item.record_date || '')}">AI研判</button></div></td>
            </tr>`).join('');
        if (mobile) mobile.innerHTML = this.items.map(item => `
            <article class="dividend-event-card">
                <div class="dividend-event-card-head"><div class="dividend-event-card-name"><b>${escapeHTML(item.name || '-')}</b><small>${escapeHTML(item.code || '')} · ${(item.market || '').toUpperCase()}</small></div><span class="dividend-status-badge${item.implementation_confirmed ? '' : ' unconfirmed'}">${item.implementation_confirmed ? '已实施' : escapeHTML(item.plan_status || '未确认')}</span></div>
                <div class="dividend-event-card-yields"><div><span>本次毛率</span><b>${this.percent(item.gross_yield_pct)}</b></div><div><span>税后现金率</span><b>${this.percent(item.net_yield_pct)}</b></div></div>
                <div class="dividend-event-card-dates">登记日 ${escapeHTML(item.record_date || '-')} · 除息日 ${escapeHTML(item.ex_date || '-')}<br>每股 ¥${this.number(item.cash_per_share, 6)} · 参考价 ¥${this.number(item.price, 2)}</div>
                <div class="dividend-event-card-foot"><small>距登记日 ${item.days_to_record ?? '-'} 天</small><div class="dividend-row-actions"><button class="btn-sm" data-dividend-action="detail" data-code="${escapeAttr(item.code)}" data-record-date="${escapeAttr(item.record_date || '')}">详情</button><button class="btn-sm btn-ai" data-dividend-action="ai" data-code="${escapeAttr(item.code)}" data-record-date="${escapeAttr(item.record_date || '')}">AI研判</button></div></div>
            </article>`).join('');
        if (typeof AnimationManager !== 'undefined') AnimationManager.animateRows('#dividend-table-body tr');
    },

    renderFundItems() {
        const tbody = document.getElementById('dividend-table-body');
        const mobile = document.getElementById('dividend-mobile-list');
        const cashText = item => item.currency_status === 'unknown' ? `${this.number(item.cash_per_unit, 4)}` : `¥${this.number(item.cash_per_unit, 6)}`;
        const navText = item => item.nav === null || item.nav === undefined ? '-' : `¥${this.number(item.nav, 4)}`;
        if (tbody) tbody.innerHTML = this.items.map(item => {
            const note = this.fundRatioStatusNote(item);
            return `<tr>
                <td><div class="dividend-stock-cell"><span class="dividend-stock-avatar">${escapeHTML((item.fund_category || 'fund').slice(0, 4))}</span><div><b>${escapeHTML(item.name || '-')}</b><small>${escapeHTML(item.code || '')}${item.fund_type ? ' · ' + escapeHTML(item.fund_type) : ''}</small></div></div></td>
                <td><div class="dividend-date-stack"><span>登记 ${escapeHTML(item.record_date || '-')}</span><small>除息 ${escapeHTML(item.ex_date || '-')} · 发放 ${escapeHTML(item.pay_date || '-')}</small></div></td>
                <td><span class="dividend-cash">${cashText(item)}</span></td>
                <td>${navText(item)}${item.nav_date ? `<small class="dividend-nav-date">${escapeHTML(item.nav_date)}</small>` : ''}</td>
                <td><span class="dividend-yield">${this.percent(item.distribution_ratio_pct)}</span>${note ? `<small class="dividend-ratio-note">${escapeHTML(note)}</small>` : ''}</td>
                <td><span class="dividend-status-badge ${item.event_stage === 'completed' ? '' : ' unconfirmed'}">${escapeHTML(this.fundStageLabel(item.event_stage))}</span></td>
                <td><div class="dividend-row-actions"><button class="btn-sm" data-dividend-action="detail" data-code="${escapeAttr(item.code)}" data-record-date="${escapeAttr(item.record_date || '')}">详情</button><button class="btn-sm btn-ai" data-dividend-action="ai" data-code="${escapeAttr(item.code)}" data-record-date="${escapeAttr(item.record_date || '')}">AI研判</button></div></td>
            </tr>`;
        }).join('');
        if (mobile) mobile.innerHTML = this.items.map(item => {
            const note = this.fundRatioStatusNote(item);
            return `<article class="dividend-event-card">
                <div class="dividend-event-card-head"><div class="dividend-event-card-name"><b>${escapeHTML(item.name || '-')}</b><small>${escapeHTML(item.code || '')} · ${escapeHTML(item.fund_type || item.fund_category || '-')}</small></div><span class="dividend-status-badge ${item.event_stage === 'completed' ? '' : ' unconfirmed'}">${escapeHTML(this.fundStageLabel(item.event_stage))}</span></div>
                <div class="dividend-event-card-yields"><div><span>每份分红</span><b>${cashText(item)}</b></div><div><span>分配比例</span><b>${this.percent(item.distribution_ratio_pct)}</b></div></div>
                <div class="dividend-event-card-dates">登记日 ${escapeHTML(item.record_date || '-')} · 除息日 ${escapeHTML(item.ex_date || '-')}<br>发放日 ${escapeHTML(item.pay_date || '-')} · 参考净值 ${navText(item)}${item.nav_date ? `（${escapeHTML(item.nav_date)}）` : ''}</div>
                ${note ? `<small class="dividend-ratio-note">${escapeHTML(note)}</small>` : ''}
                <div class="dividend-event-card-foot"><small>距登记日 ${item.days_to_record ?? '-'} 天</small><div class="dividend-row-actions"><button class="btn-sm" data-dividend-action="detail" data-code="${escapeAttr(item.code)}" data-record-date="${escapeAttr(item.record_date || '')}">详情</button><button class="btn-sm btn-ai" data-dividend-action="ai" data-code="${escapeAttr(item.code)}" data-record-date="${escapeAttr(item.record_date || '')}">AI研判</button></div></div>
            </article>`;
        }).join('');
        if (typeof AnimationManager !== 'undefined') AnimationManager.animateRows('#dividend-table-body tr');
    },

    renderPagination() {
        const box = document.getElementById('dividend-pagination');
        const label = document.getElementById('dividend-page-label');
        const prev = document.getElementById('dividend-prev-page');
        const next = document.getElementById('dividend-next-page');
        const pages = Math.max(1, Number(this.pagination.pages) || 1);
        const page = Math.max(1, Number(this.pagination.page) || 1);
        if (box) box.style.display = (this.pagination.total || 0) > 0 ? 'flex' : 'none';
        if (label) label.textContent = `第 ${page} / ${pages} 页`;
        if (prev) prev.disabled = page <= 1;
        if (next) next.disabled = page >= pages;
    },

    showAlert(message, isError) {
        const alert = document.getElementById('dividend-alert');
        if (!alert) return;
        alert.textContent = message;
        alert.className = `dividend-alert${isError ? ' error' : ''}`;
        alert.style.display = 'block';
    },

    async openDetail(item, trigger) {
        this.lastFocused = trigger || document.activeElement;
        this.detailMode = this.mode;
        const overlay = document.getElementById('dividend-detail-overlay');
        const drawer = overlay?.querySelector('.dividend-detail-drawer');
        const content = document.getElementById('dividend-detail-content');
        const title = document.getElementById('dividend-detail-title');
        if (!overlay || !content) return;
        overlay.classList.add('open');
        overlay.setAttribute('aria-hidden', 'false');
        document.body.classList.add('dividend-drawer-open');
        if (title) title.textContent = `${item.name || item.code} · ${this.mode === 'fund' ? '基金分红详情' : '分红详情'}`;
        content.innerHTML = '<div class="loading-spinner"><div class="spinner"></div><span>加载分红历史...</span></div>';
        setTimeout(() => drawer?.focus(), 20);
        try {
            let response;
            if (this.mode === 'fund') {
                const params = new URLSearchParams({ action: 'dividend_detail', asset_type: 'fund', code: item.code, event_date: item.record_date || '' });
                response = await fetch(`market_api.php?${params}`, { cache: 'no-store' });
            } else {
                const holding = this.filters().holding_period;
                response = await fetch(`market_api.php?action=dividend_detail&code=${encodeURIComponent(item.code)}&history_scope=all&holding_period=${encodeURIComponent(holding)}`, { cache: 'no-store' });
            }
            const payload = await response.json();
            if (!payload.success) throw new Error(payload.message || '加载详情失败');
            if (this.mode === 'fund') this.renderFundDetail(payload.data || {}, item);
            else this.renderDetail(payload.data || {}, item);
        } catch (error) {
            content.innerHTML = `<div class="error-msg">${escapeHTML(error.message || '加载详情失败')}</div>`;
        }
    },

    renderFundDetail(data, selected) {
        const content = document.getElementById('dividend-detail-content');
        if (!content) return;
        this.detailData = data;
        this.detailSelected = selected;
        this.historyPage = 1;
        this.selectedHistoryIndex = -1;
        const fund = data.fund || {};
        const summary = data.summary || {};
        const event = data.selected_event || selected || {};
        const history = Array.isArray(data.history) ? data.history : [];
        const announcements = Array.isArray(data.announcements) ? data.announcements : [];
        const related = Array.isArray(data.related_funds) ? data.related_funds : [];
        const dated = history.map(row => row.record_date || row.ex_date).filter(Boolean);
        const newest = dated[0] || '-';
        const oldest = dated[dated.length - 1] || '-';
        const annStatusMap = { verified: '公告已核验', checked_unmatched: '公告未匹配', check_failed: '公告核验失败', not_checked: '未检查公告' };
        const ratio = (event.cash_per_unit !== null && event.cash_per_unit !== undefined && fund.nav) ? this.percent((Number(event.cash_per_unit) / Number(fund.nav)) * 100) : '-';
        const cashText = fund.currency_status === 'unknown' ? this.number(event.cash_per_unit, 4) : `¥${this.number(event.cash_per_unit, 6)}`;
        content.innerHTML = `
            <section class="dividend-detail-hero">
                <div class="dividend-detail-hero-row"><div><div class="dividend-detail-name">${escapeHTML(fund.name || selected.name || '-')}</div><div class="dividend-detail-code">${escapeHTML(fund.code || selected.code || '')}${fund.fund_type ? ' · ' + escapeHTML(fund.fund_type) : ''}</div></div><div><div class="dividend-detail-price">${fund.nav ? '¥' + this.number(fund.nav, 4) : '-'}</div><small>最新单位净值${fund.nav_date ? ' · ' + escapeHTML(fund.nav_date) : ''}</small></div></div>
                <div class="dividend-detail-metrics"><div class="dividend-detail-metric"><span>现金分红事件</span><b>${summary.cash_dividend_events ?? 0} 次</b></div><div class="dividend-detail-metric"><span>覆盖年份</span><b>${summary.years_with_cash_dividend ?? 0} 年</b></div><div class="dividend-detail-metric"><span>近5年每份合计</span><b>¥${this.number(summary.five_year_total_cash_per_unit, 4)}</b></div></div>
            </section>
            <section class="dividend-detail-section"><h4>当前事件</h4><div class="dividend-current-plan"><b>每份分红 ${cashText}</b><br>登记日 ${escapeHTML(event.record_date || selected.record_date || '-')} · 除息日 ${escapeHTML(event.ex_date || selected.ex_date || '-')} · 发放日 ${escapeHTML(event.pay_date || '-')}</div>
            <div class="dividend-fund-ratio"><span>参考分配比例（按最新净值）</span><b>${ratio}</b><small>状态：${escapeHTML(annStatusMap[data.announcement_match_status] || data.announcement_match_status || '-')}</small></div></section>
            <section class="dividend-detail-section dividend-pulse-section"><div id="dividend-asset-pulse" class="asset-pulse-card asset-pulse-embedded" data-asset-pulse="fund" aria-live="polite"></div></section>
            ${announcements.length ? `<section class="dividend-detail-section"><h4>分红公告证据</h4><div class="dividend-announcement-list">${announcements.map(a => `<a class="dividend-announcement-item" href="${escapeAttr(a.url || a.pdf_url || '#')}" target="_blank" rel="noopener noreferrer"><span>${escapeHTML(a.date || '-')}</span><b>${escapeHTML(a.title || '未命名公告')}</b></a>`).join('')}</div></section>` : ''}
            ${related.length ? `<section class="dividend-detail-section"><h4>联接基金与目标 ETF</h4><div class="dividend-related-list">${related.map(r => `<div class="dividend-related-item"><div><b>${escapeHTML(r.name || r.code || '-')}</b><small>${escapeHTML(r.code || '')} · ${escapeHTML(r.relationship || 'target_etf')}</small></div><small class="dividend-related-note">${escapeHTML(r.interpretation_note || '目标 ETF 分红属于资产层收入，不等同于向联接基金持有人直接派现。')}</small></div>`).join('')}</div><p class="dividend-market-note">${escapeHTML(data.scope_note || '')}</p></section>` : ''}
            <section class="dividend-detail-section dividend-history-section">
                <div class="dividend-section-heading"><div><h4>完整历史分红</h4><small>${history.length} 条记录 · ${escapeHTML(oldest)} 至 ${escapeHTML(newest)}</small></div><span class="dividend-history-hint">点选任一次，查看除息日前后净值</span></div>
                <div id="dividend-history-list" class="dividend-history-list"></div>
                <div id="dividend-history-pagination" class="dividend-history-pagination"></div>
            </section>
            <section class="dividend-detail-section dividend-market-section"><div id="dividend-event-market" class="dividend-event-market"><p class="placeholder-text">选择一条历史分红，查看附近净值</p></div></section>
            <section class="dividend-detail-section"><a class="btn-sm" href="${escapeAttr(fund.source_url || selected.source_url || '#')}" target="_blank" rel="noopener noreferrer">查看数据源详情</a></section>`;
        AssetPulseModule.focus('fund', { code: fund.code || selected.code, name: fund.name || selected.name }, { target: 'dividend-asset-pulse' });
        this.renderHistoryPage();
        if (history.length) {
            const selectedDate = selected.record_date || '';
            let defaultIndex = history.findIndex(row => (row.record_date || '') === selectedDate);
            if (defaultIndex < 0) defaultIndex = 0;
            this.selectHistoryEvent(defaultIndex);
        }
    },

    renderDetail(data, selected) {
        const content = document.getElementById('dividend-detail-content');
        if (!content) return;
        this.detailData = data;
        this.detailSelected = selected;
        this.historyPage = 1;
        this.selectedHistoryIndex = -1;
        const stock = data.stock || {};
        const summary = data.summary || {};
        const event = data.upcoming_event || selected || {};
        const history = Array.isArray(data.history) ? data.history : [];
        const dated = history.map(row => row.record_date || row.ex_date || row.report_date).filter(Boolean);
        const newest = dated[0] || '-';
        const oldest = dated[dated.length - 1] || '-';
        content.innerHTML = `
            <section class="dividend-detail-hero">
                <div class="dividend-detail-hero-row"><div><div class="dividend-detail-name">${escapeHTML(stock.name || selected.name || '-')}</div><div class="dividend-detail-code">${escapeHTML(stock.code || selected.code || '')} · ${(stock.market || selected.market || '').toUpperCase()}</div></div><div><div class="dividend-detail-price">¥${this.number(stock.price, 2)}</div><small>当前价格快照</small></div></div>
                <div class="dividend-detail-metrics"><div class="dividend-detail-metric"><span>现金分红事件</span><b>${summary.cash_dividend_events ?? 0} 次</b></div><div class="dividend-detail-metric"><span>覆盖年份</span><b>${summary.years_with_cash_dividend ?? 0} 年</b></div><div class="dividend-detail-metric"><span>近5年每股合计</span><b>¥${this.number(summary.five_year_total_cash_per_share, 4)}</b></div></div>
            </section>
            <section class="dividend-detail-section"><h4>当前事件</h4><div class="dividend-current-plan"><b>${escapeHTML(event.plan_text || selected.plan_text || '暂无完整方案文本')}</b><br>登记日 ${escapeHTML(event.record_date || selected.record_date || '-')} · 除息日 ${escapeHTML(event.ex_date || selected.ex_date || '-')} · 派息日未由当前数据源提供</div></section>
            <section class="dividend-detail-section dividend-pulse-section"><div id="dividend-asset-pulse" class="asset-pulse-card asset-pulse-embedded" data-asset-pulse="stock" aria-live="polite"></div></section>
            <section class="dividend-detail-section dividend-history-section">
                <div class="dividend-section-heading"><div><h4>完整历史分红</h4><small>${history.length} 条记录 · ${escapeHTML(oldest)} 至 ${escapeHTML(newest)}</small></div><span class="dividend-history-hint">点选任一次，查看除息日前后日 K</span></div>
                <div id="dividend-history-list" class="dividend-history-list"></div>
                <div id="dividend-history-pagination" class="dividend-history-pagination"></div>
            </section>
            <section class="dividend-detail-section dividend-market-section"><div id="dividend-event-market" class="dividend-event-market"><p class="placeholder-text">选择一条历史分红，查看附近行情</p></div></section>
            <section class="dividend-detail-section"><a class="btn-sm" href="${escapeAttr(data.source_url || selected.source_url || '#')}" target="_blank" rel="noopener noreferrer">查看数据源详情</a></section>`;
        AssetPulseModule.focus('stock', { code: stock.code || selected.code, name: stock.name || selected.name }, { target: 'dividend-asset-pulse' });
        this.renderHistoryPage();
        if (history.length) {
            const selectedDate = selected.ex_date || selected.record_date || '';
            let defaultIndex = history.findIndex(row => (row.ex_date || row.record_date || '') === selectedDate);
            if (defaultIndex < 0) defaultIndex = 0;
            this.selectHistoryEvent(defaultIndex);
        }
    },

    setHistoryPage(page) {
        const history = Array.isArray(this.detailData?.history) ? this.detailData.history : [];
        const pages = Math.max(1, Math.ceil(history.length / this.historyPageSize));
        this.historyPage = Math.max(1, Math.min(pages, page));
        this.renderHistoryPage();
    },

    renderHistoryPage() {
        const history = Array.isArray(this.detailData?.history) ? this.detailData.history : [];
        const list = document.getElementById('dividend-history-list');
        const pagination = document.getElementById('dividend-history-pagination');
        if (!list || !pagination) return;
        const pages = Math.max(1, Math.ceil(history.length / this.historyPageSize));
        this.historyPage = Math.max(1, Math.min(pages, this.historyPage));
        const start = (this.historyPage - 1) * this.historyPageSize;
        const rows = history.slice(start, start + this.historyPageSize);
        const isFund = this.detailMode === 'fund';
        list.innerHTML = rows.length ? rows.map((row, offset) => {
            const index = start + offset;
            if (isFund) {
                const date = row.ex_date || row.record_date || '-';
                const cash = Number(row.cash_per_unit);
                return `<button type="button" class="dividend-history-item${index === this.selectedHistoryIndex ? ' active' : ''}" data-dividend-history-index="${index}" aria-pressed="${index === this.selectedHistoryIndex ? 'true' : 'false'}">
                    <time>${escapeHTML(date)}</time>
                    <span class="dividend-history-plan"><b>每份 ${Number.isFinite(cash) ? '¥' + this.number(cash, 6) : '非现金'}</b><small>登记 ${escapeHTML(row.record_date || '-')} · ${escapeHTML(this.fundStageLabel(row.event_stage))}</small></span>
                    <span class="dividend-history-value">${escapeHTML(row.pay_date || '发放日 -')}</span>
                    <span class="dividend-history-open">净值 ›</span>
                </button>`;
            }
            const date = row.ex_date || row.record_date || row.report_date || '-';
            const cash = Number(row.cash_per_share);
            return `<button type="button" class="dividend-history-item${index === this.selectedHistoryIndex ? ' active' : ''}" data-dividend-history-index="${index}" aria-pressed="${index === this.selectedHistoryIndex ? 'true' : 'false'}">
                <time>${escapeHTML(date)}</time>
                <span class="dividend-history-plan"><b>${escapeHTML(row.plan_text || '方案文本暂缺')}</b><small>登记 ${escapeHTML(row.record_date || '-')} · ${escapeHTML(row.plan_status || '状态未知')}</small></span>
                <span class="dividend-history-value">${Number.isFinite(cash) ? `¥${this.number(cash, 6)}/股` : '非现金方案'}</span>
                <span class="dividend-history-open">日 K ›</span>
            </button>`;
        }).join('') : '<p class="placeholder-text">暂无历史分红记录</p>';
        pagination.innerHTML = history.length > this.historyPageSize ? `
            <button class="btn-sm" type="button" data-dividend-history-page="${this.historyPage - 1}" ${this.historyPage <= 1 ? 'disabled' : ''}>上一页</button>
            <span>第 ${this.historyPage} / ${pages} 页</span>
            <button class="btn-sm" type="button" data-dividend-history-page="${this.historyPage + 1}" ${this.historyPage >= pages ? 'disabled' : ''}>下一页</button>` : '';
    },

    async selectHistoryEvent(index) {
        const history = Array.isArray(this.detailData?.history) ? this.detailData.history : [];
        if (!Number.isInteger(index) || index < 0 || index >= history.length) return;
        this.selectedHistoryIndex = index;
        this.historyPage = Math.floor(index / this.historyPageSize) + 1;
        this.renderHistoryPage();
        const panel = document.getElementById('dividend-event-market');
        const row = history[index];
        const eventDate = this.detailMode === 'fund' ? (row.ex_date || row.record_date) : (row.ex_date || row.record_date || row.report_date);
        if (!panel) return;
        this.destroyEventChart();
        panel.innerHTML = `<div class="dividend-market-loading"><div class="spinner"></div><span>正在加载 ${escapeHTML(eventDate || '')} 附近${this.detailMode === 'fund' ? '净值' : '日 K'}...</span></div>`;
        if (!eventDate) {
            panel.innerHTML = '<div class="dividend-market-error">该条记录缺少可定位的事件日期。</div>';
            return;
        }
        if (this.marketController) this.marketController.abort();
        this.marketController = new AbortController();
        const code = this.detailData?.fund?.code || this.detailData?.stock?.code || this.detailSelected?.code || '';
        try {
            const params = this.detailMode === 'fund'
                ? new URLSearchParams({ action: 'dividend_event_market', asset_type: 'fund', code, event_date: eventDate, before: '10', after: '15' })
                : new URLSearchParams({ action: 'dividend_event_market', code, event_date: eventDate, before: '10', after: '15' });
            const response = await fetch(`market_api.php?${params}`, { signal: this.marketController.signal, cache: 'no-store' });
            const payload = await response.json();
            if (!payload.success) throw new Error(payload.message || (this.detailMode === 'fund' ? '附近净值暂不可用' : '附近日 K 暂不可用'));
            if (index !== this.selectedHistoryIndex) return;
            if (this.detailMode === 'fund') this.renderFundEventMarket(payload.data || {}, row, payload.source || '-');
            else this.renderEventMarket(payload.data || {}, row, payload.source || '-');
        } catch (error) {
            if (error.name === 'AbortError' || index !== this.selectedHistoryIndex) return;
            panel.innerHTML = `<div class="dividend-market-error"><b>${this.detailMode === 'fund' ? '附近净值暂时无法加载' : '附近日 K 暂时无法加载'}</b><span>${escapeHTML(error.message || '服务返回异常')}</span><small>分红历史仍可继续翻阅，稍后也可重新点选本条记录。</small></div>`;
        }
    },

    renderFundEventMarket(data, event, source) {
        const panel = document.getElementById('dividend-event-market');
        if (!panel) return;
        const rows = Array.isArray(data.rows) ? data.rows : [];
        const summary = data.summary || {};
        const changeClass = value => Number(value) > 0 ? 'price-up' : Number(value) < 0 ? 'price-down' : '';
        const pending = data.post_event_data_pending || summary.post_event_data_pending;
        panel.innerHTML = `
            <div class="dividend-market-head">
                <button class="btn-sm" type="button" data-dividend-event-nav="-1" ${this.selectedHistoryIndex <= 0 ? 'disabled' : ''}>‹ 较新一次</button>
                <div><span>除息事件净值</span><b>${escapeHTML(data.event_date || event.ex_date || event.record_date || '-')}</b></div>
                <button class="btn-sm" type="button" data-dividend-event-nav="1" ${this.selectedHistoryIndex >= (this.detailData?.history?.length || 1) - 1 ? 'disabled' : ''}>较早一次 ›</button>
            </div>
            <div class="dividend-event-plan"><b>每份分红 ${event.cash_per_unit !== null && event.cash_per_unit !== undefined ? '¥' + this.number(event.cash_per_unit, 6) : '-'}</b><span>登记日 ${escapeHTML(event.record_date || '-')} · 除息日 ${escapeHTML(event.ex_date || '-')}</span></div>
            <div class="dividend-market-summary">
                <div><span>除息前净值</span><b>${summary.pre_event_nav !== null && summary.pre_event_nav !== undefined ? '¥' + this.number(summary.pre_event_nav, 4) : '-'}</b></div>
                <div><span>区间高 / 低</span><b>${this.number(summary.window_high, 4)} / ${this.number(summary.window_low, 4)}</b></div>
                <div><span>后置数据</span><b>${pending ? '待除息后更新' : '已含除息后'}</b></div>
            </div>
            <div class="dividend-kline-card">
                <div class="dividend-kline-caption">
                    <div class="dividend-kline-title"><span class="dividend-kline-chip">净值</span><div><b>除息前后单位净值</b><small>前 10 / 后 15 个日历日</small></div></div>
                    <span class="dividend-kline-source">${escapeHTML(source)}</span>
                </div>
                <div class="dividend-kline-stage"><div id="dividend-event-kline" role="img" aria-label="${escapeAttr(data.event_date || '')} 附近净值走势"></div></div>
            </div>
            <details class="dividend-kline-details"><summary>展开每日净值明细（${rows.length} 条）</summary><div class="dividend-kline-table-wrap"><table class="dividend-kline-table"><thead><tr><th>日期</th><th>单位净值</th><th>累计净值</th><th>日增长率</th></tr></thead><tbody>${rows.map(row => `<tr class="${row.is_event_day ? 'event-day' : ''}"><td>${escapeHTML(row.date || '-')} ${row.is_event_day ? '<em>除息</em>' : ''}</td><td>${this.number(row.nav, 4)}</td><td>${this.number(row.acc_nav, 4)}</td><td class="${changeClass(row.growth_rate)}">${this.signedPercent(row.growth_rate)}</td></tr>`).join('')}</tbody></table></div></details>
            <p class="dividend-market-note">净值窗口用于观察事件前后走势；除息后单位净值会相应下降，不能单独证明变化由分红造成。${pending ? '事件尚未除息，除息后净值将在更新后补齐。' : ''}</p>`;
        requestAnimationFrame(() => this.initFundNavChart(rows));
    },

    initFundNavChart(rows) {
        const container = document.getElementById('dividend-event-kline');
        if (!container || !rows.length) return;
        this.destroyEventChart();
        this.eventChartRows = rows;
        if (typeof LightweightCharts === 'undefined') {
            container.innerHTML = '<div class="dividend-market-error">图表组件未能加载，请展开下方每日净值明细查看。</div>';
            return;
        }
        const colors = ChartModule.getChartColors();
        const css = getComputedStyle(document.documentElement);
        const cssVar = (name, fallback) => css.getPropertyValue(name).trim() || fallback;
        const accent = cssVar('--accent-blue', '#4d9fff');
        const eventColor = cssVar('--accent-purple', '#8b6de9');
        const chart = LightweightCharts.createChart(container, {
            width: Math.max(320, container.clientWidth),
            height: 340,
            layout: { ...colors.layout, fontFamily: cssVar('--font-sans', 'sans-serif'), attributionLogo: false },
            grid: { vertLines: { color: colors.grid.vertLines.color, style: 2 }, horzLines: { color: colors.grid.horzLines.color, style: 2 } },
            rightPriceScale: { ...colors.rightPriceScale, scaleMargins: { top: 0.1, bottom: 0.1 }, minimumWidth: 56 },
            timeScale: { ...colors.timeScale, timeVisible: false, secondsVisible: false, borderVisible: false, rightOffset: 1, barSpacing: 19, minBarSpacing: 8, fixLeftEdge: true, fixRightEdge: true },
            localization: { locale: 'zh-CN', priceFormatter: price => Number(price).toFixed(4) },
            handleScroll: { mouseWheel: false, pressedMouseMove: true, horzTouchDrag: true, vertTouchDrag: false },
            handleScale: { axisPressedMouseMove: true, mouseWheel: true, pinch: true },
        });
        const navSeries = chart.addLineSeries({ color: accent, lineWidth: 2, priceLineVisible: false, lastValueVisible: true, priceFormat: { type: 'price', precision: 4, minMove: 0.0001 } });
        const navData = rows.map(row => ({ time: row.date, value: Number(row.nav) }));
        navSeries.setData(navData);
        if (rows.some(row => row.is_event_day)) {
            const eventRow = rows.find(row => row.is_event_day);
            navSeries.createPriceLine({ price: Number(eventRow.nav), color: eventColor, lineWidth: 1, lineStyle: 2, axisLabelVisible: true, title: '除息' });
        }
        chart.timeScale().fitContent();
        this.eventChart = chart;
        this.eventChartSeries = navSeries;
        const ro = new ResizeObserver(() => { if (this.eventChart) this.eventChart.applyOptions({ width: Math.max(320, container.clientWidth) }); });
        ro.observe(container);
        this.eventChartResizeObserver = ro;
    },

    renderEventMarket(data, event, source) {
        const panel = document.getElementById('dividend-event-market');
        if (!panel) return;
        const rows = Array.isArray(data.rows) ? data.rows : [];
        const summary = data.summary || {};
        const changeClass = value => Number(value) > 0 ? 'price-up' : Number(value) < 0 ? 'price-down' : '';
        const recovery = !data.event_trading_date
            ? '等待事件发生'
            : summary.recovered_in_window
                ? (Number(summary.recovery_trading_days) === 0 ? '除息当日已收复' : `${summary.recovery_trading_days} 个交易日`)
                : '窗口内未收复';
        panel.innerHTML = `
            <div class="dividend-market-head">
                <button class="btn-sm" type="button" data-dividend-event-nav="-1" ${this.selectedHistoryIndex <= 0 ? 'disabled' : ''}>‹ 较新一次</button>
                <div><span>除息事件行情</span><b>${escapeHTML(data.event_date || event.ex_date || event.record_date || '-')}</b></div>
                <button class="btn-sm" type="button" data-dividend-event-nav="1" ${this.selectedHistoryIndex >= (this.detailData?.history?.length || 1) - 1 ? 'disabled' : ''}>较早一次 ›</button>
            </div>
            <div class="dividend-event-plan"><b>${escapeHTML(event.plan_text || '方案文本暂缺')}</b><span>每股现金 ¥${this.number(event.cash_per_share, 6)} · 登记日 ${escapeHTML(event.record_date || '-')} · 除息日 ${escapeHTML(event.ex_date || '-')}</span></div>
            <div class="dividend-market-summary">
                <div><span>除息日涨跌</span><b class="${changeClass(summary.event_change_pct)}">${this.signedPercent(summary.event_change_pct)}</b></div>
                <div><span>窗口涨跌</span><b class="${changeClass(summary.window_change_pct)}">${this.signedPercent(summary.window_change_pct)}</b></div>
                <div><span>区间高 / 低</span><b>${this.number(summary.window_high, 2)} / ${this.number(summary.window_low, 2)}</b></div>
                <div><span>除权缺口恢复</span><b>${escapeHTML(recovery)}</b></div>
                <div><span>除息后量比</span><b>${Number.isFinite(Number(summary.post_pre_volume_ratio)) ? `${this.number(summary.post_pre_volume_ratio, 2)}×` : '—'}</b></div>
            </div>
            <div class="dividend-kline-card">
                <div class="dividend-kline-caption">
                    <div class="dividend-kline-title"><span class="dividend-kline-chip">日 K</span><div><b>除息前后价格走势</b><small>前 10 / 后 15 个交易日</small></div></div>
                    <span class="dividend-kline-source">${escapeHTML(source)}</span>
                </div>
                <div class="dividend-kline-quote" id="dividend-kline-quote" aria-live="polite">
                    <time>—</time><span>开 <b>—</b></span><span>高 <b>—</b></span><span>低 <b>—</b></span><span>收 <b>—</b></span><span>涨跌 <b>—</b></span><span>成交量 <b>—</b></span>
                </div>
                <div class="dividend-kline-legend"><span><i class="legend-candle-up"></i>上涨</span><span><i class="legend-candle-down"></i>下跌</span><span><i class="legend-ma5"></i>MA5 <b id="dividend-kline-ma5">—</b></span><span><i class="legend-ma10"></i>MA10 <b id="dividend-kline-ma10">—</b></span><span class="legend-event"><i></i>除息日</span></div>
                <div class="dividend-kline-stage"><div id="dividend-event-kline" role="img" aria-label="${escapeAttr(data.event_date || '')} 附近日 K 蜡烛图"></div></div>
                <div class="dividend-kline-foot"><span>价格轴</span><i></i><span>成交量</span><small>拖动查看 · 滚轮或双指缩放</small></div>
            </div>
            <details class="dividend-kline-details"><summary>展开每日行情明细（${rows.length} 条）</summary><div class="dividend-kline-table-wrap"><table class="dividend-kline-table"><thead><tr><th>日期</th><th>开</th><th>高</th><th>低</th><th>收</th><th>涨跌</th><th>成交量</th></tr></thead><tbody>${rows.map(row => `<tr class="${row.is_event_day ? 'event-day' : ''}"><td>${escapeHTML(row.date || '-')} ${row.is_event_day ? '<em>除息</em>' : ''}</td><td>${this.number(row.open, 2)}</td><td>${this.number(row.high, 2)}</td><td>${this.number(row.low, 2)}</td><td>${this.number(row.close, 2)}</td><td class="${changeClass(row.change_pct)}">${this.signedPercent(row.change_pct)}</td><td>${this.compactNumber(row.volume)}</td></tr>`).join('')}</tbody></table></div></details>
            <p class="dividend-market-note">行情使用数据源默认价格口径，用于观察事件前后走势；它不能单独证明价格变化由分红造成。</p>`;
        requestAnimationFrame(() => this.initEventKline(rows, summary));
    },

    initEventKline(rows, summary = {}) {
        const container = document.getElementById('dividend-event-kline');
        if (!container || !rows.length) return;
        this.destroyEventChart();
        this.eventChartRows = rows;
        this.eventChartSummary = summary;
        if (typeof LightweightCharts === 'undefined') {
            container.innerHTML = '<div class="dividend-market-error">图表组件未能加载，请展开下方每日行情明细查看。</div>';
            return;
        }

        const colors = ChartModule.getChartColors();
        const css = getComputedStyle(document.documentElement);
        const cssVar = (name, fallback) => css.getPropertyValue(name).trim() || fallback;
        const ma5Color = '#e7a829';
        const ma10Color = cssVar('--accent-blue', '#4d9fff');
        const accent = cssVar('--accent-blue', '#4d9fff');
        const eventColor = cssVar('--accent-purple', '#8b6de9');
        const chart = LightweightCharts.createChart(container, {
            width: Math.max(320, container.clientWidth),
            height: 340,
            layout: { ...colors.layout, fontFamily: cssVar('--font-sans', 'sans-serif'), attributionLogo: false },
            grid: {
                vertLines: { color: colors.grid.vertLines.color, style: 2 },
                horzLines: { color: colors.grid.horzLines.color, style: 2 },
            },
            crosshair: {
                mode: LightweightCharts.CrosshairMode?.Normal ?? 0,
                vertLine: { color: accent, width: 1, style: 2, labelBackgroundColor: accent },
                horzLine: { color: colors.rightPriceScale.borderColor, width: 1, style: 2 },
            },
            rightPriceScale: {
                ...colors.rightPriceScale,
                scaleMargins: { top: 0.1, bottom: 0.28 },
                minimumWidth: 56,
            },
            timeScale: {
                ...colors.timeScale,
                timeVisible: false,
                secondsVisible: false,
                borderVisible: false,
                rightOffset: 1,
                barSpacing: 19,
                minBarSpacing: 8,
                fixLeftEdge: true,
                fixRightEdge: true,
            },
            localization: { locale: 'zh-CN', priceFormatter: price => Number(price).toFixed(2) },
            handleScroll: { mouseWheel: false, pressedMouseMove: true, horzTouchDrag: true, vertTouchDrag: false },
            handleScale: { axisPressedMouseMove: true, mouseWheel: true, pinch: true },
        });
        const candleSeries = chart.addCandlestickSeries({
            ...colors.candle,
            priceLineVisible: false,
            lastValueVisible: true,
            priceFormat: { type: 'price', precision: 2, minMove: 0.01 },
        });
        const volumeSeries = chart.addHistogramSeries({
            priceFormat: { type: 'volume' },
            priceScaleId: 'volume',
            priceLineVisible: false,
            lastValueVisible: false,
        });
        chart.priceScale('volume').applyOptions({ scaleMargins: { top: 0.79, bottom: 0.02 } });
        const ma5Series = chart.addLineSeries({ color: ma5Color, lineWidth: 2, priceLineVisible: false, lastValueVisible: false, crosshairMarkerVisible: false });
        const ma10Series = chart.addLineSeries({ color: ma10Color, lineWidth: 2, priceLineVisible: false, lastValueVisible: false, crosshairMarkerVisible: false });

        const closes = rows.map(row => Number(row.close));
        const ma5 = Indicators.MA(closes, 5);
        const ma10 = Indicators.MA(closes, 10);
        candleSeries.setData(rows.map(row => ({ time: row.date, open: Number(row.open), high: Number(row.high), low: Number(row.low), close: Number(row.close) })));
        volumeSeries.setData(rows.map(row => ({
            time: row.date,
            value: Number(row.volume) || 0,
            color: Number(row.close) >= Number(row.open) ? colors.volume.up : colors.volume.down,
        })));
        ma5Series.setData(rows.map((row, index) => ma5[index] === null ? null : ({ time: row.date, value: ma5[index] })).filter(Boolean));
        ma10Series.setData(rows.map((row, index) => ma10[index] === null ? null : ({ time: row.date, value: ma10[index] })).filter(Boolean));
        const eventRow = rows.find(row => row.is_event_day);
        if (eventRow) {
            candleSeries.setMarkers([{ time: eventRow.date, position: 'aboveBar', color: eventColor, shape: 'arrowDown', text: '除息' }]);
        }
        if (Number.isFinite(Number(summary.pre_close))) {
            candleSeries.createPriceLine({ price: Number(summary.pre_close), color: eventColor, lineWidth: 1, lineStyle: 2, axisLabelVisible: true, title: '除息前收盘' });
        }

        this.eventChart = chart;
        this.eventChartSeries = { candleSeries, volumeSeries, ma5Series, ma10Series, ma5, ma10 };
        const fallbackIndex = Math.max(0, eventRow ? rows.indexOf(eventRow) : rows.length - 1);
        this.updateEventKlineQuote(rows[fallbackIndex], ma5[fallbackIndex], ma10[fallbackIndex]);
        chart.subscribeCrosshairMove(param => {
            if (!param?.time || !param.point || param.point.x < 0 || param.point.y < 0) {
                this.updateEventKlineQuote(rows[fallbackIndex], ma5[fallbackIndex], ma10[fallbackIndex]);
                return;
            }
            const index = rows.findIndex(row => row.date === param.time || this.chartTimeToDate(param.time) === row.date);
            if (index >= 0) this.updateEventKlineQuote(rows[index], ma5[index], ma10[index]);
        });
        chart.timeScale().fitContent();

        this.eventChartResizeObserver = new ResizeObserver(entries => {
            const width = Math.round(entries[0]?.contentRect?.width || container.clientWidth);
            if (width > 0 && this.eventChart) this.eventChart.applyOptions({ width, height: window.innerWidth <= 480 ? 310 : 340 });
        });
        this.eventChartResizeObserver.observe(container);
    },

    updateEventKlineQuote(row, ma5, ma10) {
        const quote = document.getElementById('dividend-kline-quote');
        if (!quote || !row) return;
        const cells = quote.querySelectorAll('span b');
        quote.querySelector('time').textContent = row.date || '—';
        const values = [this.number(row.open, 2), this.number(row.high, 2), this.number(row.low, 2), this.number(row.close, 2), this.signedPercent(row.change_pct), this.compactNumber(row.volume)];
        cells.forEach((cell, index) => { cell.textContent = values[index] ?? '—'; });
        const changeCell = cells[4];
        if (changeCell) changeCell.className = Number(row.change_pct) > 0 ? 'price-up' : Number(row.change_pct) < 0 ? 'price-down' : '';
        const ma5Label = document.getElementById('dividend-kline-ma5');
        const ma10Label = document.getElementById('dividend-kline-ma10');
        if (ma5Label) ma5Label.textContent = ma5 !== null && Number.isFinite(Number(ma5)) ? this.number(ma5, 2) : '—';
        if (ma10Label) ma10Label.textContent = ma10 !== null && Number.isFinite(Number(ma10)) ? this.number(ma10, 2) : '—';
    },

    chartTimeToDate(time) {
        if (typeof time === 'string') return time;
        if (time && typeof time === 'object' && 'year' in time) return `${time.year}-${String(time.month).padStart(2, '0')}-${String(time.day).padStart(2, '0')}`;
        return '';
    },

    updateEventChartTheme() {
        if (!this.eventChart || !this.eventChartRows.length) return;
        const rows = this.eventChartRows;
        const summary = this.eventChartSummary;
        requestAnimationFrame(() => this.initEventKline(rows, summary));
    },

    destroyEventChart() {
        if (this.eventChartResizeObserver) this.eventChartResizeObserver.disconnect();
        this.eventChartResizeObserver = null;
        if (this.eventChart) {
            try { this.eventChart.remove(); } catch (error) { /* 已被 DOM 清理 */ }
        }
        this.eventChart = null;
        this.eventChartSeries = null;
    },

    signedPercent(value) {
        if (value === null || value === undefined || value === '') return '—';
        const number = Number(value);
        if (!Number.isFinite(number)) return '—';
        return `${number > 0 ? '+' : ''}${number.toFixed(2)}%`;
    },

    compactNumber(value) {
        const number = Number(value);
        if (!Number.isFinite(number)) return '—';
        if (Math.abs(number) >= 1e8) return `${this.number(number / 1e8, 2)}亿`;
        if (Math.abs(number) >= 1e4) return `${this.number(number / 1e4, 2)}万`;
        return this.number(number, 0);
    },

    closeDetail() {
        if (this.marketController) this.marketController.abort();
        this.destroyEventChart();
        const overlay = document.getElementById('dividend-detail-overlay');
        overlay?.classList.remove('open');
        overlay?.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('dividend-drawer-open');
        this.lastFocused?.focus?.();
    },

    analyzeWithAI(item) {
        AdvisorModule.setDividendContext(item);
        if (this.mode === 'fund') {
            const prompt = `请研判当前基金分红事件，并由你自主选择必要工具交叉验证。必须同时调用 fa_get_fund_dividend_profile 与 fa_get_fund_dividend_event_market，核查直接分红、公告证据、事件日前后净值、ETF 场内日K、流动性和官方全收益基准；除此之外最多选择 2 个最相关的研究工具，彼此独立的查询尽量放在同一轮并行执行。\n\n基金：${item.name}（${item.code}）\n基金类型：${item.fund_type || item.fund_category || '-'}\n登记日：${item.record_date}\n除息日：${item.ex_date}\n发放日：${item.pay_date || '-'}\n每份分红：${item.cash_per_unit ?? '缺失'}元\n参考净值：${item.nav ?? '缺失'}（${item.nav_date || '日期缺失'}）\n本次分配比例：${item.distribution_ratio_pct ?? '缺失'}%\n比例状态：${item.ratio_status || '-'}\n事件阶段：${this.fundStageLabel(item.event_stage)}\n数据时间：${this.meta.as_of || '未知'}\n\n同日官方净值已有时不要再查盘中估值。只有实际基准序列且样本足够才能评价跟踪误差，只有成交额/换手率证据才能评价流动性；不要把 is_buy 解释为仅限二级市场。理论除息值与实际除息日净值分开表述；登记日收盘后不要提示“收盘前买入”。请区分事实、推断和不确定性；分红来自基金财产、净值会相应下降，不是额外或无风险收益；不要把分配比例称为年化收益，不推测基金红利税，只说明未覆盖的佣金、价差和政策数据缺口；公告未核验时不得声称“没有公告”。`;
            AdvisorModule.autoSend(prompt);
            return;
        }
        const prompt = `请研判当前分红事件，并由你自主选择必要工具交叉验证。必须调用 fa_get_stock_dividend_profile 核查历史分红；除此之外最多选择 3 个最相关的研究工具，档案已有有效价格时不要重复查询行情，未明确需要大盘背景时不必查询市场宽度。请将彼此独立的查询尽量放在同一工具轮并行执行。\n\n股票：${item.name}（${item.code}）\n方案状态：${item.plan_status}\n股权登记日：${item.record_date}\n除权除息日：${item.ex_date}\n每股含税现金：${item.cash_per_share}元\n价格快照：${item.price ?? '缺失'}元\n本次毛现金率：${item.gross_yield_pct ?? '缺失'}%\n当前税档税后现金率：${item.net_yield_pct ?? '缺失'}%\n数据时间：${this.meta.as_of || '未知'}\n\n请区分事实、推断和不确定性，重点分析是否存在除息前抢跑、波动与资金风险；不要把本次现金率当作年化或无风险收益。`;
        AdvisorModule.autoSend(prompt);
    },

    scanWithAI() {
        APP.advisorContext.dividendEvent = null;
        APP.advisorContext.source = '分红日历扫描';
        const f = this.filters();
        if (this.mode === 'fund') {
            const days = Math.max(1, Math.round((new Date(f.end_date) - new Date(f.start_date)) / 86400000) + 1);
            AdvisorModule.autoSend(`请使用 fa_get_upcoming_fund_dividends 扫描从 ${f.start_date} 开始未来 ${days} 日的基金分红事件，基金类型=${f.fund_category}，最低分配比例=${f.min_distribution_ratio}%，排序=${f.sort_by}。本次先完成全市场召回、排序与风险摘要；除非缺少形成扫描结论的关键事实，否则不要在同一请求中展开多只基金深挖，可建议我从事件行继续进入单基金研判。必须说明数据时间、比例口径、事件状态和工具失败项；不要把分配比例视为年化或无风险收益，不要套用股票红利税档。`);
            return;
        }
        const days = Math.max(1, Math.round((new Date(f.end_date) - new Date(f.start_date)) / 86400000) + 1);
        AdvisorModule.autoSend(`请使用 fa_get_upcoming_dividends 扫描从 ${f.start_date} 开始未来 ${days} 日的临近分红事件，市场=${f.market}，方案状态=${f.status}，持有期税档=${f.holding_period}，最低本次毛率=${f.min_yield}%。本次先完成全市场召回、排序与风险摘要；除非缺少形成扫描结论的关键事实，否则不要在同一请求中展开多只股票深挖，可建议我从事件行继续进入单股研判。必须说明数据时间、税务口径、事件状态和工具失败项，不要把本次现金率视为无风险收益。`);
    },

    restartAutoRefreshTimer() {
        if (this.timer) {
            clearInterval(this.timer);
            this.timer = null;
        }
        const tabActive = document.querySelector('.nav-tab.active')?.dataset.tab === 'dividend';
        if (!tabActive) return;
        const seconds = this.mode === 'fund'
            ? APP.config.fundDividendAutoRefreshSeconds
            : APP.config.dividendAutoRefreshSeconds;
        this.timer = setInterval(() => {
                const tabActive = document.querySelector('.nav-tab.active')?.dataset.tab === 'dividend';
                if (document.visibilityState !== 'visible' || !tabActive) return;
                // 基金模式刷新不受 A 股交易时段判断限制；股票模式保持原逻辑
                if (this.mode === 'fund') {
                    this.load(this.pagination.page || 1, false, true);
                } else if (this.isAShareTradingSession()) {
                    this.load(this.pagination.page || 1, false, true);
                }
            }, seconds * 1000);
    },

    onTabChange(tabName) {
        if (tabName === 'dividend') {
            if (!this.loaded) this.load(1);
            this.restartAutoRefreshTimer();
        } else if (this.timer) {
            clearInterval(this.timer);
            this.timer = null;
        }
    },

    number(value, digits = 2) {
        const number = Number(value);
        if (!Number.isFinite(number)) return '—';
        return number.toFixed(digits).replace(/\.0+$/, '').replace(/(\.\d*?)0+$/, '$1');
    },
    percent(value) { return Number.isFinite(Number(value)) ? `${Number(value).toFixed(2)}%` : '—'; },
    formatDateTime(value) {
        if (!value) return '未知';
        const date = new Date(value);
        return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleString('zh-CN', { hour12: false });
    }
};

// ============================================================
// 基金模块
// ============================================================
const FundModule = {
    selectedFund: null,
    detailController: null,
    detailRequestId: 0,
    rankItems: [],
    rankMeta: {},
    currentRankType: 'all',
    currentRankPeriod: 'year',
    aiContextLimit: 255000,
    aiHistoryTargetRows: 240,
    rankPeriodLabels: {
        day: '日涨幅',
        week: '近1周',
        month: '近1月',
        quarter: '近3月',
        half_year: '近6月',
        year: '近1年',
        two_year: '近2年',
        three_year: '近3年',
        this_year: '今年来',
        since: '成立来'
    },

    init() {
        const panel = document.getElementById('panel-fund');
        panel?.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-fund-action]');
            if (!btn) return;
            const action = btn.dataset.fundAction;
            const code = btn.dataset.code || '';
            const name = btn.dataset.name || '';
            if (action === 'watch') this.addToWatchlist(code, name);
            if (action === 'detail') this.openDetail(code, name);
            if (action === 'remove') this.removeFromWatchlist(code);
            if (action === 'ai') this.analyzeSelectedFund(btn);
        });
    },

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
            } else {
                document.getElementById('fund-search-results').innerHTML = `<div class="error-msg">${escapeHTML(data.message || '搜索基金失败')}</div>`;
            }
        } catch(e) {
            loading.style.display = 'none';
            document.getElementById('fund-search-results').innerHTML = '<div class="error-msg">搜索基金失败，请稍后重试</div>';
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
            const code = escapeAttr(f.code || '');
            const name = escapeAttr(f.name || '');
            html += `
                <div class="fund-card">
                    <div class="fc-title-row">
                        <div>
                            <div class="fc-name">${escapeHTML(f.name || '-')}</div>
                            <div class="fc-code">${escapeHTML(f.code || '')}</div>
                        </div>
                        <span class="fc-type">${escapeHTML(f.type || f.category || '-')}</span>
                    </div>
                    <div class="fc-metrics">
                        <span><b>${escapeHTML(f.nav || '-')}</b><small>单位净值</small></span>
                        <span class="${colorClass(f.nav_chg_rate)}"><b>${formatPct(f.nav_chg_rate)}</b><small>${escapeHTML(f.nav_date || '-')}</small></span>
                    </div>
                    <div class="fc-meta">${escapeHTML(f.company || '')}${f.company && f.manager ? ' · ' : ''}${escapeHTML(f.manager || '')}</div>
                    <div class="fc-actions">
                        <button class="btn-sm" data-fund-action="detail" data-code="${code}" data-name="${name}">${Icons.table} 详情</button>
                        <button class="btn-sm ${inWl ? '' : 'btn-star'}" data-fund-action="watch" data-code="${code}" data-name="${name}" ${inWl ? 'disabled' : ''}>
                            ${inWl ? '已添加' : `${Icons.star} 加自选`}
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
        if (!/^\d{6}$/.test(code)) return;
        if (window.WatchCenter) window.WatchCenter.addItem('fund', code, name);
        // 刷新搜索结果按钮
        const keyword = document.getElementById('fund-search-input')?.value.trim();
        if (keyword) this.search();
    },

    removeFromWatchlist(code) {
        if (window.WatchCenter) window.WatchCenter.removeItem('fund:' + code);
    },

    async renderWatchlist() {
        // 自选基金列表已由统一自选中心接管，此处为兼容旧调用点的空实现。
    },

    async loadRank() {
        const type = document.getElementById('fund-rank-type')?.value || 'all';
        const period = document.getElementById('fund-rank-period')?.value || 'year';
        const loading = document.getElementById('fund-rank-loading');
        const table = document.getElementById('fund-rank-table');
        const tbody = document.getElementById('fund-rank-data');
        const summary = document.getElementById('fund-rank-summary');
        if (loading) loading.style.display = 'flex';

        try {
            const resp = await fetch(`fund_rank_api.php?type=${encodeURIComponent(type)}&period=${encodeURIComponent(period)}&page=1&page_size=30`);
            const data = await resp.json();
            if (loading) loading.style.display = 'none';
            if (!data.success) {
                summary.innerHTML = `<div class="error-msg">${escapeHTML(data.message || '获取基金排行失败')}</div>`;
                table.style.display = 'none';
                return;
            }
            this.renderRank(data.data || [], data.meta || {}, period);
        } catch (e) {
            if (loading) loading.style.display = 'none';
            summary.innerHTML = '<div class="error-msg">获取基金排行失败，请稍后重试</div>';
            table.style.display = 'none';
            console.error('获取基金排行失败:', e);
        }
    },

    renderRank(funds, meta, period) {
        const table = document.getElementById('fund-rank-table');
        const tbody = document.getElementById('fund-rank-data');
        const summary = document.getElementById('fund-rank-summary');
        const label = this.rankPeriodLabels[period] || '周期收益';
        this.rankItems = Array.isArray(funds) ? funds : [];
        this.rankMeta = meta || {};
        this.currentRankType = document.getElementById('fund-rank-type')?.value || 'all';
        this.currentRankPeriod = period;
        summary.innerHTML = `
            <span>${label}</span>
            <span>共 ${meta.total || funds.length} 只</span>
            <span>更新时间 ${new Date().toLocaleTimeString()}</span>
        `;
        tbody.innerHTML = '';
        funds.forEach((f, index) => {
            const inWl = APP.fundWatchlist.some(w => w.code === f.code);
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>${index + 1}</td>
                <td>
                    <button class="fund-link-btn" data-fund-action="detail" data-code="${escapeAttr(f.code)}" data-name="${escapeAttr(f.name)}">
                        <span>${escapeHTML(f.name || '-')}</span>
                        <small>${escapeHTML(f.code || '')}</small>
                    </button>
                </td>
                <td>${escapeHTML(f.nav || '-')}<small>${escapeHTML(f.nav_date || '')}</small></td>
                <td class="${colorClass(f.day_growth)}">${formatPct(f.day_growth)}</td>
                <td class="${colorClass(f.selected_growth)}">${formatPct(f.selected_growth)}</td>
                <td class="${colorClass(f.this_year_growth)}">${formatPct(f.this_year_growth)}</td>
                <td class="${colorClass(f.since_growth)}">${formatPct(f.since_growth)}</td>
                <td>
                    <button class="btn-sm ${inWl ? '' : 'btn-star'}" data-fund-action="watch" data-code="${escapeAttr(f.code)}" data-name="${escapeAttr(f.name)}" ${inWl ? 'disabled' : ''}>${inWl ? '已添加' : '加自选'}</button>
                </td>
            `;
            tbody.appendChild(tr);
        });
        table.style.display = 'table';
    },

    async openDetail(code, name = '') {
        if (!/^\d{6}$/.test(code)) return;
        this.detailController?.abort();
        this.detailController = new AbortController();
        const signal = this.detailController.signal;
        const requestId = ++this.detailRequestId;
        const loading = document.getElementById('fund-detail-loading');
        const box = document.getElementById('fund-detail');
        const codeTag = document.getElementById('fund-detail-code');
        codeTag.textContent = code;
        loading.style.display = 'flex';
        box.innerHTML = '';
        if (typeof AssetPulseModule !== 'undefined') {
            AssetPulseModule.focus('fund', { code, name }, { target: 'fund-asset-pulse' });
        }

        try {
            const [infoResp, historyResp, estimateResp] = await Promise.all([
                fetch(`fund_info_api.php?codes=${encodeURIComponent(code)}`, { signal }),
                fetch(`fund_history_api.php?code=${encodeURIComponent(code)}&page=1&page_size=40`, { signal }),
                fetch(`fund_estimate_api.php?code=${encodeURIComponent(code)}`, { signal })
            ]);
            const [infoData, historyData, estimateData] = await Promise.all([
                infoResp.json(),
                historyResp.json(),
                estimateResp.json()
            ]);
            if (requestId !== this.detailRequestId) return;
            loading.style.display = 'none';
            if (!infoData.success && !historyData.success) {
                box.innerHTML = `<div class="error-msg">${escapeHTML(infoData.message || historyData.message || '加载基金详情失败')}</div>`;
                return;
            }
            const fund = (infoData.data && infoData.data[0]) || { code, name };
            this.selectedFund = {
                fund,
                history: historyData.success ? historyData.data : [],
                estimate: estimateData.success ? estimateData.data : null
            };
            if (typeof AdvisorModule !== 'undefined') {
                AdvisorModule.setAssetContext('fund', fund, '基金详情');
            }
            this.renderDetail(this.selectedFund);
        } catch (e) {
            if (e.name === 'AbortError' || requestId !== this.detailRequestId) return;
            loading.style.display = 'none';
            box.innerHTML = '<div class="error-msg">加载基金详情失败，请稍后重试</div>';
            console.error('加载基金详情失败:', e);
        } finally {
            if (requestId === this.detailRequestId) this.detailController = null;
        }
    },

    renderDetail(payload) {
        const { fund, history, estimate } = payload;
        const box = document.getElementById('fund-detail');
        const latest = history?.[0] || {};
        const scale = parseFloat(fund.scale);
        const scaleText = isNaN(scale) ? '-' : (scale / 1e8).toFixed(2) + '亿';
        const spark = this.renderHistorySparkline(history || []);
        const rows = (history || []).slice(0, 12).map(item => `
            <tr>
                <td>${escapeHTML(item.date || '')}</td>
                <td>${escapeHTML(item.nav || '-')}</td>
                <td>${escapeHTML(item.acc_nav || '-')}</td>
                <td class="${colorClass(item.growth_rate)}">${formatPct(item.growth_rate)}</td>
                <td>${escapeHTML(item.purchase_status || '-')}</td>
                <td>${escapeHTML(item.redeem_status || '-')}</td>
            </tr>
        `).join('');

        box.innerHTML = `
            <div class="fund-detail-head">
                <div>
                    <h4>${escapeHTML(fund.name || estimate?.name || '-')}</h4>
                    <p>${escapeHTML(fund.full_name || fund.code || '')}</p>
                </div>
                <div class="fund-detail-actions">
                    <button class="btn-sm btn-star" data-fund-action="watch" data-code="${escapeAttr(fund.code)}" data-name="${escapeAttr(fund.name || estimate?.name || '')}">${Icons.star} 加自选</button>
                    <button class="btn-sm btn-accent" data-fund-action="ai">${Icons.chart} AI分析</button>
                </div>
            </div>
            <div class="fund-metric-grid">
                <div><span>单位净值</span><b>${escapeHTML(latest.nav || fund.nav || estimate?.dwjz || '-')}</b><small>${escapeHTML(latest.date || fund.nav_date || estimate?.jzrq || '')}</small></div>
                <div><span>估算净值</span><b>${escapeHTML(estimate?.gsz || '-')}</b><small>${escapeHTML(estimate?.gztime || '盘中估值')}</small></div>
                <div><span>估算涨幅</span><b class="${colorClass(estimate?.gszzl)}">${formatPct(estimate?.gszzl)}</b><small>实时</small></div>
                <div><span>最新日涨幅</span><b class="${colorClass(latest.growth_rate || fund.nav_chg_rate)}">${formatPct(latest.growth_rate || fund.nav_chg_rate)}</b><small>净值</small></div>
                <div><span>基金类型</span><b>${escapeHTML(fund.type || '-')}</b><small>风险 ${escapeHTML(fund.risk_level || '-')}</small></div>
                <div><span>基金规模</span><b>${scaleText}</b><small>${escapeHTML(fund.scale_date || '')}</small></div>
            </div>
            <div class="fund-profile-grid">
                <div><span>基金公司</span><b>${escapeHTML(fund.fund_company || '-')}</b></div>
                <div><span>基金经理</span><b>${escapeHTML(fund.fund_manager || '-')}</b></div>
                <div><span>托管银行</span><b>${escapeHTML(fund.custodian || '-')}</b></div>
                <div><span>成立日期</span><b>${escapeHTML(fund.establish_date || '-')}</b></div>
                <div><span>管理费</span><b>${escapeHTML(fund.management_fee || '-')}</b></div>
                <div><span>托管费</span><b>${escapeHTML(fund.custody_fee || '-')}</b></div>
            </div>
            ${fund.benchmark ? `<div class="fund-benchmark"><span>业绩比较基准</span><p>${escapeHTML(fund.benchmark)}</p></div>` : ''}
            ${spark}
            <div class="fund-history-table-wrapper">
                <table class="fund-history-table">
                    <thead><tr><th>日期</th><th>单位净值</th><th>累计净值</th><th>日增长率</th><th>申购</th><th>赎回</th></tr></thead>
                    <tbody>${rows || '<tr><td colspan="6">暂无历史净值</td></tr>'}</tbody>
                </table>
            </div>
        `;
    },

    renderHistorySparkline(history) {
        const rows = history.slice(0, 30).reverse();
        const values = rows.map(item => parseFloat(item.nav)).filter(v => !isNaN(v));
        if (values.length < 2) return '';
        const min = Math.min(...values);
        const max = Math.max(...values);
        const range = max - min || 1;
        const bars = rows.map(item => {
            const nav = parseFloat(item.nav);
            const h = isNaN(nav) ? 4 : Math.max(8, Math.round(((nav - min) / range) * 54) + 6);
            return `<span style="height:${h}px" title="${escapeAttr(item.date)} ${escapeAttr(item.nav)}"></span>`;
        }).join('');
        return `<div class="fund-history-spark"><div class="fund-history-spark-head"><span>近30条净值走势</span><small>${min.toFixed(4)} - ${max.toFixed(4)}</small></div><div class="fund-history-bars">${bars}</div></div>`;
    },

    async analyzeSelectedFund(triggerBtn = null) {
        if (!this.selectedFund || typeof AdvisorModule === 'undefined') return;
        const originalText = triggerBtn?.innerHTML || '';
        if (triggerBtn) {
            triggerBtn.disabled = true;
            triggerBtn.innerHTML = `${Icons.chart} 整理资料...`;
        }

        try {
            const payload = await this.collectFundAIContext(this.selectedFund);
            const prompt = this.buildFundAIContextPrompt(payload);
            if (typeof AdvisorModule !== 'undefined') {
                AdvisorModule.setAssetContext('fund', payload.fund, '基金深度分析');
            } else {
                APP.advisorContext.source = '基金深度分析';
                APP.advisorContext.assetType = 'fund';
                APP.advisorContext.assetCode = payload.fund.code || '';
                APP.advisorContext.assetName = payload.fund.name || '';
                APP.advisorContext.assetLabel = `${payload.fund.name || ''} (${payload.fund.code || ''})`;
                APP.advisorContext.stock = '';
            }
            AdvisorModule.autoSend(prompt);
        } catch (e) {
            console.error('构建基金AI上下文失败:', e);
            alert('整理基金分析资料失败，请稍后重试');
        } finally {
            if (triggerBtn) {
                triggerBtn.disabled = false;
                triggerBtn.innerHTML = originalText;
            }
        }
    },

    async collectFundAIContext(basePayload) {
        const fund = basePayload.fund || {};
        const code = fund.code;
        const fundRankType = this.typeToRankType(fund.type);
        const watchCodes = APP.fundWatchlist.map(item => item.code).filter(code => /^\d{6}$/.test(code));

        const tasks = [
            this.fetchFundHistoryForAI(code, this.aiHistoryTargetRows),
            this.fetchJson(`fund_estimate_api.php?code=${encodeURIComponent(code)}`),
            this.fetchJson(`fund_rank_api.php?type=${encodeURIComponent(fundRankType)}&period=year&page=1&page_size=100`),
            this.fetchJson(`fund_rank_api.php?type=${encodeURIComponent(fundRankType)}&period=quarter&page=1&page_size=100`)
        ];

        if (watchCodes.length > 0) {
            tasks.push(this.fetchJson(`fund_estimate_api.php?codes=${encodeURIComponent(watchCodes.join(','))}`));
        }

        const [historyData, estimateData, sameTypeYearRank, sameTypeQuarterRank, watchEstimateData] = await Promise.all(tasks);
        const history = historyData?.success ? historyData.data : (basePayload.history || []);
        const estimate = estimateData?.success ? estimateData.data : basePayload.estimate;
        const stats = this.calculateHistoryStats(history);

        return {
            fund,
            estimate,
            history,
            stats,
            historyMeta: historyData?.meta || {},
            visibleRank: {
                type: this.currentRankType,
                period: this.currentRankPeriod,
                meta: this.rankMeta,
                items: this.rankItems
            },
            sameTypeYearRank: sameTypeYearRank?.success ? sameTypeYearRank : null,
            sameTypeQuarterRank: sameTypeQuarterRank?.success ? sameTypeQuarterRank : null,
            watchlist: APP.fundWatchlist.slice(),
            watchEstimates: watchEstimateData?.success ? (watchEstimateData.data || []) : []
        };
    },

    async fetchJson(url) {
        const resp = await fetch(url);
        return await resp.json();
    },

    async fetchFundHistoryForAI(code, targetRows = 160) {
        const pageSize = 20; // 上游常见实际返回上限约 20 行，分页更稳。
        const maxPages = Math.ceil(targetRows / pageSize);
        const rows = [];
        const seen = new Set();
        const meta = { target_rows: targetRows, page_size: pageSize, fetched_pages: 0, records: 0, pages: 0 };

        for (let page = 1; page <= maxPages; page++) {
            const data = await this.fetchJson(`fund_history_api.php?code=${encodeURIComponent(code)}&page=${page}&page_size=${pageSize}`);
            if (!data?.success || !Array.isArray(data.data) || data.data.length === 0) {
                break;
            }
            meta.fetched_pages = page;
            meta.records = data.meta?.records || meta.records;
            meta.pages = data.meta?.pages || meta.pages;
            for (const item of data.data) {
                const key = item.date || JSON.stringify(item);
                if (!seen.has(key)) {
                    seen.add(key);
                    rows.push(item);
                }
            }
            if (rows.length >= targetRows || data.data.length < pageSize) {
                break;
            }
        }

        return { success: rows.length > 0, data: rows.slice(0, targetRows), meta };
    },

    typeToRankType(type = '') {
        if (type.includes('股票')) return 'stock';
        if (type.includes('混合')) return 'mixed';
        if (type.includes('债')) return 'bond';
        if (type.includes('指数')) return 'index';
        if (/QDII/i.test(type)) return 'qdii';
        if (/FOF/i.test(type)) return 'fof';
        return 'all';
    },

    calculateHistoryStats(history = []) {
        const rows = history
            .map(item => ({
                date: item.date || '',
                nav: parseFloat(item.nav),
                accNav: parseFloat(item.acc_nav),
                growth: parseFloat(item.growth_rate),
                purchase: item.purchase_status || '',
                redeem: item.redeem_status || ''
            }))
            .filter(item => !isNaN(item.nav));

        const chronological = rows.slice().reverse();
        const growthRows = rows.filter(item => !isNaN(item.growth));
        const growths = growthRows.map(item => item.growth);
        const sum = growths.reduce((a, b) => a + b, 0);
        const avg = growths.length ? sum / growths.length : NaN;
        const variance = growths.length ? growths.reduce((acc, v) => acc + Math.pow(v - avg, 2), 0) / growths.length : NaN;
        const dailyVol = isNaN(variance) ? NaN : Math.sqrt(variance);
        const annualizedVol = isNaN(dailyVol) ? NaN : dailyVol * Math.sqrt(252);
        const positives = growths.filter(v => v > 0).length;
        const negatives = growths.filter(v => v < 0).length;
        const flats = growths.filter(v => v === 0).length;
        const latest = rows[0] || null;
        const oldest = rows[rows.length - 1] || null;
        const sampleReturn = latest && oldest && oldest.nav ? (latest.nav / oldest.nav - 1) * 100 : NaN;
        const best = growthRows.reduce((best, item) => (!best || item.growth > best.growth ? item : best), null);
        const worst = growthRows.reduce((worst, item) => (!worst || item.growth < worst.growth ? item : worst), null);

        let peak = -Infinity;
        let peakDate = '';
        let maxDrawdown = 0;
        let troughDate = '';
        chronological.forEach(item => {
            if (item.nav > peak) {
                peak = item.nav;
                peakDate = item.date;
            }
            if (peak > 0) {
                const dd = (item.nav / peak - 1) * 100;
                if (dd < maxDrawdown) {
                    maxDrawdown = dd;
                    troughDate = item.date;
                }
            }
        });

        const purchaseCounts = {};
        const redeemCounts = {};
        rows.forEach(item => {
            if (item.purchase) purchaseCounts[item.purchase] = (purchaseCounts[item.purchase] || 0) + 1;
            if (item.redeem) redeemCounts[item.redeem] = (redeemCounts[item.redeem] || 0) + 1;
        });

        return {
            count: rows.length,
            dateRange: latest && oldest ? `${oldest.date} 至 ${latest.date}` : '',
            latestNav: latest?.nav,
            oldestNav: oldest?.nav,
            sampleReturn,
            avgDailyGrowth: avg,
            dailyVol,
            annualizedVol,
            winRate: growths.length ? positives / growths.length * 100 : NaN,
            positives,
            negatives,
            flats,
            best,
            worst,
            maxDrawdown,
            peakDate,
            troughDate,
            purchaseCounts,
            redeemCounts,
            latestPurchase: latest?.purchase || '',
            latestRedeem: latest?.redeem || ''
        };
    },

    buildFundAIContextPrompt(payload) {
        const cap = Math.min(this.aiContextLimit || 255000, APP.aiContextLimit || AI_CONTEXT_LIMIT);
        const fund = payload.fund || {};
        const estimate = payload.estimate || {};
        const stats = payload.stats || {};
        const scale = parseFloat(fund.scale);
        const scaleText = isNaN(scale) ? (fund.scale || '-') : `${(scale / 1e8).toFixed(2)}亿元`;
        const line = (label, value) => `${label}: ${value === undefined || value === null || value === '' ? '-' : value}`;
        const fmt = (value, digits = 2) => {
            const n = parseFloat(value);
            return isNaN(n) ? '-' : n.toFixed(digits);
        };
        const pct = value => {
            const n = parseFloat(value);
            return isNaN(n) ? '-' : `${n >= 0 ? '+' : ''}${n.toFixed(2)}%`;
        };
        const countsToText = obj => {
            const entries = Object.entries(obj || {});
            return entries.length ? entries.map(([k, v]) => `${k}:${v}`).join('；') : '-';
        };
        const rankPeriodLabel = this.rankPeriodLabels[this.currentRankPeriod] || this.currentRankPeriod;
        const historyRows = payload.history || [];
        const visibleRankItems = payload.visibleRank?.items || [];
        const sameYearItems = payload.sameTypeYearRank?.data || [];
        const sameQuarterItems = payload.sameTypeQuarterRank?.data || [];
        const watchEstimates = payload.watchEstimates || [];

        const sections = [];
        const add = (title, body) => {
            if (!body) return;
            sections.push(`\n## ${title}\n${body.trim()}\n`);
        };

        add('分析任务', [
            '请基于下面尽可能完整的基金资料，做一次偏投研风格的基金分析。',
            '要求输出：1. 结论摘要；2. 收益与波动特征；3. 回撤和极端波动；4. 基金类型/策略/基准适配度；5. 经理、公司、规模、费率与申赎状态影响；6. 同类基金对照；7. 适合/不适合的投资者画像；8. 后续跟踪指标；9. 数据局限。',
            '不要只根据最近几天涨跌下结论；请区分事实、推断和不确定性。本系统仅供研究娱乐，不构成投资建议。'
        ].join('\n'));

        add('基金基础信息', [
            line('基金名称', `${fund.name || '-'} (${fund.code || '-'})`),
            line('基金全称', fund.full_name),
            line('基金类型', fund.type),
            line('风险等级', fund.risk_level),
            line('成立日期', fund.establish_date),
            line('基金公司', fund.fund_company),
            line('基金经理', fund.fund_manager),
            line('托管银行', fund.custodian),
            line('基金规模', `${scaleText}，规模日期 ${fund.scale_date || '-'}`),
            line('管理费', fund.management_fee),
            line('托管费', fund.custody_fee),
            line('最低申购', fund.min_purchase),
            line('是否支持购买', fund.is_buy ? '是' : '否或未知')
        ].join('\n'));

        add('最新净值与估值', [
            line('净值日期', fund.nav_date || estimate.jzrq),
            line('单位净值', fund.nav || estimate.dwjz),
            line('累计净值', fund.acc_nav),
            line('最新日涨幅', pct(fund.nav_chg_rate)),
            line('估算净值', estimate.gsz),
            line('估算涨幅', pct(estimate.gszzl)),
            line('估值时间', estimate.gztime),
            line('估值基金名', estimate.name)
        ].join('\n'));

        add('量化统计摘要', [
            line('样本条数', stats.count),
            line('样本区间', stats.dateRange),
            line('样本期累计收益', pct(stats.sampleReturn)),
            line('样本期平均日增长率', pct(stats.avgDailyGrowth)),
            line('样本期日波动率', pct(stats.dailyVol)),
            line('估算年化波动率', pct(stats.annualizedVol)),
            line('上涨/下跌/持平天数', `${stats.positives || 0}/${stats.negatives || 0}/${stats.flats || 0}`),
            line('胜率', pct(stats.winRate)),
            line('样本最大回撤', `${pct(stats.maxDrawdown)}，峰值日期 ${stats.peakDate || '-'}，低点日期 ${stats.troughDate || '-'}`),
            line('最大单日上涨', stats.best ? `${stats.best.date} ${pct(stats.best.growth)} NAV=${fmt(stats.best.nav, 4)}` : '-'),
            line('最大单日下跌', stats.worst ? `${stats.worst.date} ${pct(stats.worst.growth)} NAV=${fmt(stats.worst.nav, 4)}` : '-'),
            line('申购状态分布', countsToText(stats.purchaseCounts)),
            line('赎回状态分布', countsToText(stats.redeemCounts)),
            line('最新申购/赎回', `${stats.latestPurchase || '-'} / ${stats.latestRedeem || '-'}`)
        ].join('\n'));

        add('业绩比较基准与投资目标', [
            line('业绩比较基准', fund.benchmark),
            line('投资目标', fund.investment_target)
        ].join('\n'));

        add(`历史净值明细 目标${payload.historyMeta?.target_rows || historyRows.length}条`, [
            `目标至少半年数据；本次实际取得 ${historyRows.length} 条，分页 ${payload.historyMeta?.fetched_pages || '-'} 页，接口记录数 ${payload.historyMeta?.records || '-'}`,
            '日期,单位净值,累计净值,日增长率,申购状态,赎回状态,分红送配',
            ...historyRows.map(item => [
                item.date || '',
                item.nav || '',
                item.acc_nav || '',
                pct(item.growth_rate),
                item.purchase_status || '',
                item.redeem_status || '',
                item.dividend || ''
            ].join(','))
        ].join('\n'));

        add(`当前页面基金排行快照 ${rankPeriodLabel}`, [
            `类型=${payload.visibleRank?.type || '-'} 周期=${payload.visibleRank?.period || '-'} 总数=${payload.visibleRank?.meta?.total || '-'}`,
            '名次,代码,名称,净值日期,单位净值,日涨幅,周期收益,今年来,成立来',
            ...visibleRankItems.slice(0, 30).map((item, index) => [
                index + 1,
                item.code || '',
                item.name || '',
                item.nav_date || '',
                item.nav || '',
                pct(item.day_growth),
                pct(item.selected_growth),
                pct(item.this_year_growth),
                pct(item.since_growth)
            ].join(','))
        ].join('\n'));

        add(`同类基金近1年排行样本 ${fund.type || ''}`, [
            `总数=${payload.sameTypeYearRank?.meta?.total || '-'}；当前基金不一定在Top100内，主要用于观察同类强势样本和收益分布。`,
            '名次,代码,名称,净值日期,单位净值,日涨幅,近1年,今年来,成立来,成立日期',
            ...sameYearItems.slice(0, 100).map((item, index) => [
                index + 1,
                item.code || '',
                item.name || '',
                item.nav_date || '',
                item.nav || '',
                pct(item.day_growth),
                pct(item.year_growth),
                pct(item.this_year_growth),
                pct(item.since_growth),
                item.establish_date || ''
            ].join(','))
        ].join('\n'));

        add(`同类基金近3月排行样本 ${fund.type || ''}`, [
            `总数=${payload.sameTypeQuarterRank?.meta?.total || '-'}；用于观察近期风格强弱和短期动量。`,
            '名次,代码,名称,净值日期,单位净值,日涨幅,近1月,近3月,近6月,今年来',
            ...sameQuarterItems.slice(0, 100).map((item, index) => [
                index + 1,
                item.code || '',
                item.name || '',
                item.nav_date || '',
                item.nav || '',
                pct(item.day_growth),
                pct(item.month_growth),
                pct(item.quarter_growth),
                pct(item.half_year_growth),
                pct(item.this_year_growth)
            ].join(','))
        ].join('\n'));

        add('自选基金估值横向对照', [
            '代码,名称,净值日期,单位净值,估算净值,估算涨幅,估值时间',
            ...watchEstimates.filter(Boolean).map(item => [
                item.fundcode || '',
                item.name || '',
                item.jzrq || '',
                item.dwjz || '',
                item.gsz || '',
                pct(item.gszzl),
                item.gztime || ''
            ].join(','))
        ].join('\n'));

        let prompt = '';
        for (const section of sections) {
            if (prompt.length + section.length <= cap) {
                prompt += section;
            } else {
                const remaining = cap - prompt.length - 120;
                if (remaining > 500) {
                    prompt += section.slice(0, remaining) + '\n...[因上下文上限截断，以上为优先保留的主要资料]\n';
                }
                break;
            }
        }

        prompt += `\n## 上下文规模\n本次基金资料包约 ${prompt.length} 字符，上限 ${cap} 字符。请尽量利用所有资料做全面分析。\n`;
        return prompt.slice(0, cap);
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
            collapseBtn: document.getElementById('advisor-collapse-btn'),
            clearBtn: document.getElementById('advisor-clear-btn'),
            welcome: document.getElementById('ai-advisor-welcome'),
            context: document.getElementById('ai-advisor-context'),
            contextStock: document.getElementById('advisor-context-stock'),
            contextTab: document.getElementById('advisor-context-tab'),
            badge: document.getElementById('fab-badge'),
            status: document.getElementById('advisor-status'),
            pageStatus: document.getElementById('ai-page-status'),
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
        el.fab?.addEventListener('click', () => {
            if (APP.advisorExpanded) {
                this.collapseToPanel();
            } else {
                this.toggle();
            }
        });

        // 关闭按钮
        el.closeBtn?.addEventListener('click', () => this.close());

        // 展开到完整页
        el.expandBtn?.addEventListener('click', () => this.expandToPage());

        // 从完整页收回浮窗
        el.collapseBtn?.addEventListener('click', () => this.collapseToPanel());

        // 清理历史对话 / 上下文
        el.clearBtn?.addEventListener('click', () => this.clearConversation());

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
                if (btn.dataset.source === 'xueqiu') {
                    // 雪球数据增强：先获取热度+选股，再拼接 prompt
                    Promise.all([
                        fetch('xueqiu_api.php?action=hot_stock&type=10&size=10').then(r => r.json()).catch(() => ({success: false, data: []})),
                        fetch('xueqiu_api.php?action=screener&order_by=percent&market=CN&size=10&order=desc').then(r => r.json()).catch(() => ({success: false, data: []}))
                    ]).then(([hotData, screenerData]) => {
                        let enhancedPrompt = btn.dataset.prompt + '\n\n';
                        if (hotData.success && hotData.data && hotData.data.length > 0) {
                            enhancedPrompt += '【雪球热度榜 Top10】\n代码,名称,涨跌幅,热度值\n';
                            hotData.data.forEach(item => {
                                enhancedPrompt += `${item.code || item.symbol},${item.name},${item.change_pct}%,${item.hot_value}\n`;
                            });
                            enhancedPrompt += '\n';
                        }
                        if (screenerData.success && screenerData.data) {
                            const items = screenerData.data.data || screenerData.data;
                            if (items.length > 0) {
                                enhancedPrompt += '【雪球条件选股 Top10】\n代码,名称,涨跌幅,换手率,PE_TTM,PB\n';
                                items.forEach(item => {
                                    enhancedPrompt += `${item.code || item.symbol},${item.name},${item.change_pct}%,${item.turnover_rate},${item.pe_ttm},${item.pb}\n`;
                                });
                            }
                        }
                        this.sendQuickAction(enhancedPrompt);
                    });
                } else {
                    this.sendQuickAction(btn.dataset.prompt);
                }
            }
        });
    },

    /** 打开面板 */
    open() {
        if (APP.advisorExpanded) {
            this._els.userInput?.blur();
            APP.userInput?.focus();
            return;
        }
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
    close({ restoreFocus = true } = {}) {
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
        if (restoreFocus) {
            if (APP.advisorLastFocusedElement) {
                APP.advisorLastFocusedElement.focus();
            } else {
                el.fab?.focus();
            }
        }
    },

    /** 浮窗与完整页只是同一任务的两种视图，切换不得中止流式请求。 */
    expandToPage() {
        if (APP.advisorExpanded) return;
        const activeTab = document.querySelector('.nav-tab.active')?.dataset.tab;
        if (activeTab && activeTab !== 'ai') APP.advisorReturnTab = activeTab;

        this._transferDraft(this._els.userInput, APP.userInput);
        APP.advisorExpanded = true;
        document.body.classList.add('advisor-expanded');
        this.close({ restoreFocus: false });
        switchTab('ai');
        AIModule.ensureDisplayView(APP.chatContainer);
        requestAnimationFrame(() => APP.userInput?.focus());
    },

    /** 返回展开前的业务页并打开浮窗，继续显示同一个流式任务。 */
    collapseToPanel() {
        this._transferDraft(APP.userInput, this._els.userInput);
        APP.advisorExpanded = false;
        document.body.classList.remove('advisor-expanded');
        const returnTab = APP.advisorReturnTab && APP.advisorReturnTab !== 'ai'
            ? APP.advisorReturnTab
            : 'stock';
        switchTab(returnTab);
        this.open();
        AIModule.ensureDisplayView(this._els.chatContainer);
    },

    leaveExpandedPage() {
        APP.advisorExpanded = false;
        document.body.classList.remove('advisor-expanded');
    },

    _transferDraft(from, to) {
        if (!from || !to) return;
        if (from.value && !to.value) to.value = from.value;
        from.value = '';
        if (to === this._els.userInput) {
            this._autoResize();
            this._updateSendState();
        } else {
            autoResizeTextarea();
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
        const loadingMsg = AIModule.appendMessage('分析中...', null, 'loading-message', this._els.chatContainer);
        let seconds = 0;
        const timer = setInterval(() => { seconds++; loadingMsg.textContent = `分析中... ${seconds}s`; }, 1000);

        // 设置分析态
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
        const loadingMsg = AIModule.appendMessage('分析中...', null, 'loading-message', this._els.chatContainer);
        let seconds = 0;
        const timer = setInterval(() => { seconds++; loadingMsg.textContent = `分析中... ${seconds}s`; }, 1000);

        this.setThinking(true);
        AIModule.sendToAI(loadingMsg, timer, this._els.chatContainer);

        // 更新上下文来源
        APP.advisorContext.source = '快捷任务';
        this.updateContext();
    },

    /** 兼容旧调用：外部模块可直接发送到顾问面板 */
    send(message) {
        this.autoSend(message);
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
            const loadingMsg = AIModule.appendMessage('分析中...', null, 'loading-message', this._els.chatContainer);
            let seconds = 0;
            const timer = setInterval(() => { seconds++; loadingMsg.textContent = `分析中... ${seconds}s`; }, 1000);

            this.setThinking(true);
            AIModule.sendToAI(loadingMsg, timer, this._els.chatContainer);
        }, 150);
    },

    /** 显式设置顾问当前资产上下文 */
    setAssetContext(assetType, asset = {}, source = '') {
        const type = assetType === 'fund' ? 'fund' : (assetType === 'stock' ? 'stock' : '');
        const code = String(asset.code || asset.fundcode || asset.symbol || asset.dm || asset.stock || '').trim();
        const name = String(asset.name || asset.mc || '').trim();
        const label = type === 'fund'
            ? (name && code ? `${name} (${code})` : (name || code))
            : (name && code && name !== code ? `${name} (${code})` : (code || name));

        APP.advisorContext.assetType = type;
        APP.advisorContext.assetCode = code;
        APP.advisorContext.assetName = name;
        APP.advisorContext.assetLabel = label;
        APP.advisorContext.stock = type === 'stock' ? code : '';
        if (source !== '分红日历') APP.advisorContext.dividendEvent = null;
        if (source) APP.advisorContext.source = source;

        this.updateContext();
    },

    /** 设置当前分红事件，并保留为每次请求的临时系统上下文 */
    setDividendContext(event = {}) {
        const isFund = DividendModule?.mode === 'fund';
        this.setAssetContext(isFund ? 'fund' : 'stock', { code: event.code, name: event.name }, '分红日历');
        if (isFund) {
            APP.advisorContext.dividendEvent = {
                assetType: 'fund',
                code: String(event.code || ''),
                name: String(event.name || ''),
                fund_type: String(event.fund_type || event.fund_category || ''),
                record_date: event.record_date || null,
                ex_date: event.ex_date || null,
                pay_date: event.pay_date || null,
                cash_per_unit: event.cash_per_unit ?? null,
                currency_status: String(event.currency_status || ''),
                nav: event.nav ?? null,
                nav_date: event.nav_date || null,
                distribution_ratio_pct: event.distribution_ratio_pct ?? null,
                ratio_status: String(event.ratio_status || ''),
                event_stage: String(event.event_stage || ''),
                announcement_match_status: String(event.announcement_match_status || 'not_checked'),
                source: event.source || 'eastmoney_fund_dividend',
                as_of: DividendModule?.meta?.as_of || null
            };
        } else {
            APP.advisorContext.dividendEvent = {
                assetType: 'stock',
                code: String(event.code || ''),
                name: String(event.name || ''),
                plan_status: String(event.plan_status || ''),
                implementation_confirmed: Boolean(event.implementation_confirmed),
                record_date: event.record_date || null,
                ex_date: event.ex_date || null,
                cash_per_share: event.cash_per_share ?? null,
                price: event.price ?? null,
                gross_yield_pct: event.gross_yield_pct ?? null,
                net_yield_pct: event.net_yield_pct ?? null,
                holding_period: event.holding_period || 'within_1m',
                source: event.source || 'eastmoney_dividend',
                as_of: DividendModule?.meta?.as_of || null
            };
        }
        this.updateContext();
    },

    getTransientContextMessage() {
        const event = APP.advisorContext?.dividendEvent;
        if (!event || !event.code) return null;
        if (event.assetType === 'fund') {
            return {
                role: 'system',
                content: `当前页面选中的基金分红事件上下文（仅作页面事实锚点，实时事实仍需调用工具刷新）：${JSON.stringify(event)}。分配比例不是年化收益；分红来自基金财产、净值会相应下降；不要套用股票红利税档；公告未核验时不得声称“没有公告”。`
            };
        }
        return {
            role: 'system',
            content: `当前页面选中的分红事件上下文（仅作页面事实锚点，实时事实仍需调用工具刷新）：${JSON.stringify(event)}。单次现金率不是年化收益；回答需说明事件状态、数据时间、税务估算和除息风险。`
        };
    },

    /** 当前导航模块 */
    getActiveTabName() {
        return document.querySelector('.nav-tab.active')?.dataset.tab || APP.advisorContext.tab || 'stock';
    },

    /** 从基金模块读取当前基金详情 */
    getSelectedFundContext() {
        if (typeof FundModule === 'undefined' || !FundModule.selectedFund?.fund) return null;
        const fund = FundModule.selectedFund.fund;
        const code = String(fund.code || '').trim();
        const name = String(fund.name || FundModule.selectedFund.estimate?.name || '').trim();
        if (!code && !name) return null;
        return {
            type: 'fund',
            code,
            name,
            label: name && code ? `${name} (${code})` : (name || code)
        };
    },

    /** 根据当前页面模块推导顾问资产上下文，避免基金页串用股票代码 */
    resolvePageContext() {
        const tab = this.getActiveTabName();
        const tabNames = { stock: '股票行情', realtime: '实时看板', strategy: '策略池', sector: '板块资金', dividend: '分红日历', news: '新闻舆情', xueqiu: '雪球洞察', fund: '基金分析', ai: 'AI顾问' };
        const result = {
            tab,
            tabLabel: tabNames[tab] || tab,
            assetType: '',
            assetCode: '',
            assetName: '',
            assetLabel: ''
        };

        if (tab === 'dividend') {
            const event = APP.advisorContext.dividendEvent;
            const eventType = event?.assetType === 'fund' || (!event && DividendModule?.mode === 'fund') ? 'fund' : 'stock';
            result.assetType = eventType;
            if (event?.code) {
                result.assetCode = event.code;
                result.assetName = event.name || '';
                result.assetLabel = event.name ? `${event.name} (${event.code})` : event.code;
            }
            return result;
        }

        if (tab === 'fund') {
            const fund = this.getSelectedFundContext();
            if (fund) {
                result.assetType = fund.type;
                result.assetCode = fund.code;
                result.assetName = fund.name;
                result.assetLabel = fund.label;
            }
            return result;
        }

        if (tab === 'news') {
            const news = typeof NewsModule !== 'undefined' ? NewsModule.current : null;
            if (news && (news.mode === 'stock' || news.mode === 'fund')) {
                result.assetType = news.mode;
                result.assetCode = news.code || '';
                result.assetName = news.name || '';
                result.assetLabel = news.name && news.code ? `${news.name} (${news.code})` : (news.name || news.code || '');
            }
            return result;
        }

        if (tab === 'stock' || tab === 'realtime' || tab === 'sector') {
            const fallbackStock = APP.advisorContext.assetType === 'stock' ? APP.advisorContext.assetCode : APP.advisorContext.stock;
            const stockCode = String(APP.currentStockCode || fallbackStock || '').trim();
            if (stockCode) {
                result.assetType = 'stock';
                result.assetCode = stockCode;
                result.assetName = APP.advisorContext.assetType === 'stock' ? (APP.advisorContext.assetName || '') : '';
                result.assetLabel = APP.advisorContext.assetType === 'stock' ? (APP.advisorContext.assetLabel || stockCode) : stockCode;
            }
        }

        return result;
    },

    /** 按当前模块更新欢迎区和快捷任务 */
    updateWelcomeState(pageContext) {
        const title = this._els.welcome?.querySelector('.welcome-title');
        const sub = this._els.welcome?.querySelector('.welcome-sub');
        const isFund = pageContext?.tab === 'fund';
        const isDividend = pageContext?.tab === 'dividend';
        const isFundDividend = isDividend && pageContext?.assetType === 'fund';
        const asset = pageContext?.assetLabel || '';

        if (title) {
            title.textContent = isFund
                ? '你好，我可以结合基金净值、估值、排行和产品资料做研究评估。'
                : (isDividend ? '你好，我可以扫描临近分红事件，并交叉验证分红历史、行情与风险。' : '你好，我可以结合行情、资金流和板块数据帮你快速研判。');
        }
        if (sub) {
            sub.textContent = isFund
                ? (asset ? `当前基金：${asset}。可以直接问我收益波动、回撤风险、同类对照或持有适配。` : '从搜索结果、自选或排行中打开基金详情后，我会自动接入当前基金上下文。')
                : (isDividend ? (asset ? `当前${isFundDividend ? '基金' : '股票'}分红事件：${asset}。我会先刷新事件事实，再判断历史连续性和短期风险。` : `可以让我扫描未来7/14/30日${isFundDividend ? '基金' : '股票'}分红事件，或从列表选择一个事件继续研判。`) : '我已接入当前页面数据，可以直接问我股票趋势、主力意图或板块机会。');
        }

        this.renderQuickActions(isFund ? this.getFundQuickActions(asset) : (isDividend ? this.getDividendQuickActions(asset, pageContext?.assetType) : this.getStockQuickActions(asset)));
    },

    getStockQuickActions(assetLabel = '') {
        const actions = [
            { icon: Icons.chart, text: '分析当前股票趋势', prompt: '帮我分析当前股票的趋势与支撑压力位' },
            { icon: Icons.flow, text: '判断主力资金意图', prompt: '帮我结合资金流向判断主力在吸筹还是出货' },
            { icon: Icons.hot, text: '从热榜筛选候选标的', prompt: '帮我从净流入热榜里筛选短期值得关注的标的' },
            { icon: Icons.search, text: '雪球热度+选股共振', prompt: '帮我结合雪球热度榜和条件选股数据，找出当前市场关注度与基本面共振的标的', source: 'xueqiu' }
        ];
        if (assetLabel) {
            actions[2] = {
                icon: Icons.hot,
                text: '研判当前股票舆情',
                prompt: `请调用 fa_get_asset_news 与 fa_get_sentiment_snapshot，研判当前股票 ${assetLabel} 的最新热点与标题情绪；区分新闻事实、标题弱信号和推断，并说明样本量与不确定性。`
            };
        }
        return actions;
    },

    getFundQuickActions(assetLabel = '') {
        const subject = assetLabel ? `当前基金 ${assetLabel}` : '当前基金';
        const actions = [
            { icon: Icons.chart, text: '分析基金收益质量', prompt: `请基于${subject}的净值、估值、历史走势和同类排行，分析收益质量、波动、回撤与结论摘要。` },
            { icon: Icons.flow, text: '评估波动与风险', prompt: `请评估${subject}近期估值涨跌、历史净值波动、最大回撤和申购赎回状态，指出主要风险和跟踪指标。` },
            { icon: Icons.table, text: '对照同类基金排行', prompt: `请结合${subject}与同类基金近1年、近3月排行样本，判断相对强弱、风格适配和替代选择标准。` },
            { icon: Icons.search, text: '检查持有适配度', prompt: `请从基金类型、基金经理、基金公司、规模、费率、业绩基准和投资者画像角度，评估${subject}是否适合继续关注。` }
        ];
        if (assetLabel) {
            actions[3] = {
                icon: Icons.hot,
                text: '研判当前基金舆情',
                prompt: `请调用 fa_get_asset_news 与 fa_get_sentiment_snapshot，研判当前基金 ${assetLabel} 的最新热点与标题情绪；严格过滤泛基金资讯，并说明样本不足、相关性和不确定性。`
            };
        }
        return actions;
    },

    getDividendQuickActions(assetLabel = '', assetType = 'stock') {
        if (assetType === 'fund') {
            if (assetLabel) {
                return [
                    { icon: Icons.calendar, text: '核查当前基金分红', prompt: `请针对当前基金分红事件 ${assetLabel} 同时调用 fa_get_fund_dividend_profile 与 fa_get_fund_dividend_event_market，核查直接分红、公告证据、事件净值、ETF 场内日K、流动性与官方全收益基准。` },
                    { icon: Icons.chart, text: '检查除息净值影响', prompt: `请分析当前基金分红事件 ${assetLabel} 的除息净值影响；调用基金分红档案，并按需结合净值历史交叉验证。` },
                    { icon: Icons.warning, text: '审查短持风险', prompt: `请对当前基金分红事件 ${assetLabel} 做短持风险审查，说明净值除息、波动、费用和数据缺口；不要套用股票红利税档。` },
                    { icon: Icons.table, text: '比较同日基金候选', prompt: '请调用 fa_get_upcoming_fund_dividends，比较与当前事件登记日接近的基金候选，并明确排序及分配比例口径。' }
                ];
            }
            return [
                { icon: Icons.calendar, text: '扫描未来14天', prompt: '请调用 fa_get_upcoming_fund_dividends 扫描未来14天基金分红事件，并说明数据时间与比例口径。' },
                { icon: Icons.table, text: '按分配比例比较', prompt: '请调用 fa_get_upcoming_fund_dividends，按安全可计算的本次分配比例比较临近基金事件；不要将其称为年化收益。' },
                { icon: Icons.warning, text: '说明基金分红风险', prompt: '请解释基金分红的净值除息、费用、波动和数据核验风险，不要套用股票红利税档。' },
                { icon: Icons.chart, text: '筛选代表候选', prompt: '请扫描临近基金分红候选并给出代表事件；本轮先做全市场召回，不要自动深挖多只基金。' }
            ];
        }
        if (assetLabel) {
            return [
                { icon: Icons.calendar, text: '核查当前分红历史', prompt: `请针对当前分红事件 ${assetLabel} 调用 fa_get_stock_dividend_profile，核查历史分红连续性和本次方案状态。` },
                { icon: Icons.chart, text: '检查除息前抢跑', prompt: `请分析当前分红事件 ${assetLabel} 是否存在除息前价格抢跑；调用分红历史、K线指标和资金流工具交叉验证。` },
                { icon: Icons.warning, text: '审查短持风险', prompt: `请对当前分红事件 ${assetLabel} 做短持风险审查，说明除息、税费、波动和数据缺口。` },
                { icon: Icons.table, text: '比较同日候选', prompt: '请调用 fa_get_upcoming_dividends，比较与当前事件登记日接近的候选，并明确排序口径。' }
            ];
        }
        return [
            { icon: Icons.calendar, text: '扫描未来14天', prompt: '请调用 fa_get_upcoming_dividends 扫描未来14天已实施的A股分红事件，按本次毛现金率排序。' },
            { icon: Icons.table, text: '按短持税后率比较', prompt: '请调用 fa_get_upcoming_dividends，按个人持有不超过1个月的税后现金率比较临近事件。' },
            { icon: Icons.warning, text: '说明抢息风险', prompt: '请解释临近登记日抢分红的除息、税费和价格波动风险，并结合实时工具给出研究框架。' },
            { icon: Icons.chart, text: '筛选代表候选', prompt: '请扫描临近分红候选，并对不超过3只代表股票继续调用分红历史、技术指标和资金流工具交叉验证。' }
        ];
    },

    renderQuickActions(actions) {
        const box = this._els.quickActions;
        if (!box || !Array.isArray(actions)) return;
        box.innerHTML = actions.map(action => `
            <button class="quick-action-btn" data-prompt="${escapeAttr(action.prompt || '')}"${action.source ? ` data-source="${escapeAttr(action.source)}"` : ''}>
                <span class="qa-icon">${action.icon || Icons.search}</span>
                <span class="qa-text">${escapeHTML(action.text || '')}</span>
                <span class="qa-arrow">→</span>
            </button>
        `).join('');
    },

    /** 清理历史对话，并保持顾问可立即继续使用 */
    clearConversation() {
        APP.advisorRequestVersion++;

        if (APP._aiAbortController) {
            APP._aiAbortController.abort();
            APP._aiAbortController = null;
        }

        APP.messageHistory = [{ role: 'system', content: AI_SYSTEM_PROMPT }];
        APP.chatDisplayHistory = [];

        if (APP.chatContainer) APP.chatContainer.innerHTML = '';
        if (this._els.chatContainer) this._els.chatContainer.innerHTML = '';
        this._els.panel?.classList.remove('has-messages');

        if (APP.userInput) {
            APP.userInput.value = '';
            APP.userInput.style.height = '44px';
        }
        if (this._els.userInput) {
            this._els.userInput.value = '';
            this._els.userInput.style.height = '24px';
        }

        this.setThinking(false);
        this.clearUnread();
        this.updateContextMeter();
        this._updateSendState();

        if (APP.advisorOpen) {
            this.updateContext();
            setTimeout(() => this._els.userInput?.focus(), 0);
        }
    },

    /** 渲染历史消息到顾问面板 */
    renderHistory() {
        const container = this._els.chatContainer;
        if (!container) return;

        const displayMessages = Array.isArray(APP.chatDisplayHistory) ? APP.chatDisplayHistory : [];
        const hasMessages = displayMessages.length > 0;

        // 切换欢迎区/消息区显示
        this._els.panel?.classList.toggle('has-messages', hasMessages);

        if (!hasMessages) {
            container.innerHTML = '';
            return;
        }

        // 检查是否需要重新渲染（对比已有消息数）
        const existingMsgs = container.querySelectorAll('.message');
        // 简单对比：如果数量一致则跳过（避免重复渲染）
        if (existingMsgs.length === displayMessages.length) return;

        AIModule.renderDisplayHistory(container);
    },

    /** 设置分析态 */
    setThinking(isThinking) {
        APP.advisorThinking = isThinking;
        const fab = this._els.fab;

        if (isThinking) {
            fab?.classList.add('thinking');
            this.setStatus('分析中...');
        } else {
            fab?.classList.remove('thinking');
            this.setStatus('在线 · Beta');
        }
    },

    setStatus(text) {
        const label = String(text || '').trim() || (APP.advisorThinking ? '分析中...' : '在线 · Beta');
        if (this._els.status) this._els.status.textContent = label;
        if (this._els.pageStatus) {
            this._els.pageStatus.textContent = label;
            this._els.pageStatus.classList.toggle('thinking', APP.advisorThinking);
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
        const pageContext = this.resolvePageContext();
        const previousSource = ctx.source || '';
        let show = false;

        ctx.tab = pageContext.tab;
        ctx.assetType = pageContext.assetType;
        ctx.assetCode = pageContext.assetCode;
        ctx.assetName = pageContext.assetName;
        ctx.assetLabel = pageContext.assetLabel;
        ctx.stock = pageContext.assetType === 'stock' ? pageContext.assetCode : '';
        if (pageContext.assetType === 'stock' && ['基金详情', '基金深度分析'].includes(previousSource)) {
            ctx.source = '股票查询';
        } else if (pageContext.assetType === 'fund' && ['股票查询', 'AI分析', 'AI选股'].includes(previousSource)) {
            ctx.source = '基金详情';
        }

        // 当前资产
        if (pageContext.assetLabel) {
            const assetPrefix = pageContext.assetType === 'fund' ? '基金 ' : '股票 ';
            if (el.contextStock) {
                el.contextStock.textContent = assetPrefix + pageContext.assetLabel;
                el.contextStock.style.display = '';
            }
            show = true;
        } else {
            if (el.contextStock) el.contextStock.style.display = 'none';
        }

        // 当前 Tab
        if (el.contextTab) {
            el.contextTab.textContent = '模块 ' + pageContext.tabLabel;
            el.contextTab.style.display = '';
        }
        show = true;

        if (el.context) {
            el.context.style.display = show ? 'flex' : 'none';
        }
        this.updateWelcomeState(pageContext);
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
        const label = `约${AIModule.formatContextSize(stats.sentSize)} / ${AIModule.formatContextSize(stats.limit)}`;
        const charLabel = `${formatCompactSize(stats.sentChars)} 字符`;
        const title = stats.truncated
            ? `估算上下文: ${label}，实际 ${charLabel}，已发送最近 ${stats.sentMessages}/${stats.totalMessages} 条消息`
            : `估算上下文: ${label}，实际 ${charLabel}，共 ${stats.totalMessages} 条消息`;

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
    _displayContainers() {
        return [APP.chatContainer, APP.advisorChatContainer]
            .filter((container, index, items) => container && items.indexOf(container) === index);
    },

    _renderDisplayRecord(record, container, index) {
        if (!record || !record.className || !container) return null;
        let element = null;
        const options = { skipDisplayRecord: true };
        if (record.className === 'process-message') {
            element = this._appendProcessStatus(record.message || '', container, options);
        } else if (record.className === 'thought-message') {
            element = this._appendThoughtBubble(record.message || '', container, options);
        } else if (record.className === 'tool-message') {
            element = this._appendToolStatusBubble(record.status || {}, container, options);
        } else if (record.className === 'reasoning-message') {
            element = this._appendReasoningBubble(container, options);
            const body = element?.querySelector('.reasoning-body');
            if (body) body.innerHTML = DOMPurify.sanitize(marked.parse(record.content || ''));
        } else {
            element = this.appendMessage(record.content || '', record.reasoningContent || null, record.className, container, options);
        }
        if (element) element.dataset.displayIndex = String(index);
        return element;
    },

    _applyDisplayRecord(element, record, options = {}) {
        if (!element || !record) return;
        if (record.className === 'reasoning-message') {
            let body = element.querySelector('.reasoning-body');
            if (!body) {
                body = document.createElement('div');
                body.className = 'reasoning-body';
                element.appendChild(body);
            }
            if (options.reasoningMarkdown) {
                body.innerHTML = DOMPurify.sanitize(marked.parse(record.content || ''));
            } else {
                body.textContent = record.content || '';
            }
        } else if (record.className === 'bot-message') {
            let content = element.querySelector('.content');
            if (!content) {
                content = document.createElement('div');
                content.className = 'content';
                element.appendChild(content);
            }
            content.innerHTML = DOMPurify.sanitize(marked.parse(record.content || ''));
        }
        const container = element.closest('.chat-messages, .advisor-messages');
        if (container) container.scrollTop = container.scrollHeight;
    },

    _syncNewDisplayRecord(index, sourceElement) {
        const record = APP.chatDisplayHistory[index];
        const sourceContainer = sourceElement?.parentElement;
        this._displayContainers().forEach(container => {
            if (container === sourceContainer || container.querySelector(`[data-display-index="${index}"]`)) return;
            const messageCount = container.querySelectorAll('.message[data-display-index]').length;
            if (messageCount !== index) {
                this.renderDisplayHistory(container);
                return;
            }
            this._renderDisplayRecord(record, container, index);
        });
    },

    _recordDisplayMessage(element, record, options = {}) {
        if (!element || options.skipDisplayRecord || record?.className === 'loading-message') return;
        const history = Array.isArray(APP.chatDisplayHistory) ? APP.chatDisplayHistory : (APP.chatDisplayHistory = []);
        const index = history.length;
        element.dataset.displayIndex = String(index);
        history.push({ ...record });
        this._syncNewDisplayRecord(index, element);
    },

    _updateDisplayMessage(element, patch = {}, options = {}) {
        if (!element || !Array.isArray(APP.chatDisplayHistory)) return;
        const index = Number(element.dataset.displayIndex);
        if (!Number.isInteger(index) || index < 0 || index >= APP.chatDisplayHistory.length) return;
        APP.chatDisplayHistory[index] = { ...APP.chatDisplayHistory[index], ...patch };
        const record = APP.chatDisplayHistory[index];
        this._displayContainers().forEach(container => {
            let mirror = container.querySelector(`[data-display-index="${index}"]`);
            if (!mirror) {
                this.ensureDisplayView(container);
                mirror = container.querySelector(`[data-display-index="${index}"]`);
            }
            if (mirror && mirror !== element) this._applyDisplayRecord(mirror, record, options);
        });
    },

    renderDisplayHistory(container) {
        if (!container) return;
        const records = Array.isArray(APP.chatDisplayHistory) ? APP.chatDisplayHistory : [];
        container.innerHTML = '';
        records.forEach((record, index) => this._renderDisplayRecord(record, container, index));
        container.scrollTop = container.scrollHeight;
    },

    ensureDisplayView(container) {
        if (!container) return;
        const records = Array.isArray(APP.chatDisplayHistory) ? APP.chatDisplayHistory : [];
        const elements = container.querySelectorAll('.message[data-display-index]');
        if (elements.length !== records.length) {
            this.renderDisplayHistory(container);
            return;
        }
        records.forEach((record, index) => {
            const element = container.querySelector(`[data-display-index="${index}"]`);
            if (element) this._applyDisplayRecord(element, record, { reasoningMarkdown: true });
        });
        container.scrollTop = container.scrollHeight;
    },

    countMessageChars(message) {
        return countTextChars(this._messageContextText(message));
    },

    estimateContextSize(text) {
        if (!text) return 0;
        const value = String(text);
        const cjkMatches = value.match(/[\u3400-\u9fff\u3040-\u30ff\uac00-\ud7af]/g);
        const cjkCount = cjkMatches ? cjkMatches.length : 0;
        const asciiLike = value.replace(/[\u3400-\u9fff\u3040-\u30ff\uac00-\ud7af]/g, '');
        return Math.ceil(cjkCount + asciiLike.length / 4);
    },

    estimateMessageSize(message) {
        return this.estimateContextSize(this._messageContextText(message)) + 4;
    },

    _messageContextText(message) {
        if (!message || typeof message !== 'object') return '';
        const parts = [typeof message.content === 'string' ? message.content : ''];
        if (typeof message.reasoning_content === 'string') parts.push(message.reasoning_content);
        if (Array.isArray(message.tool_calls)) {
            try { parts.push(JSON.stringify(message.tool_calls)); } catch (e) { /* ignore */ }
        }
        if (typeof message.tool_call_id === 'string') parts.push(message.tool_call_id);
        return parts.join('\n');
    },

    formatContextSize(size) {
        const value = Number(size) || 0;
        if (value >= 1000000) return (value / 1000000).toFixed(1).replace(/\.0$/, '') + 'M';
        if (value >= 1000) return Math.round(value / 1000) + 'K';
        return String(value);
    },

    getContextMessages() {
        const messages = Array.isArray(APP.messageHistory) ? APP.messageHistory.slice() : [];
        const transient = typeof AdvisorModule !== 'undefined' ? AdvisorModule.getTransientContextMessage() : null;
        if (transient) {
            let insertAt = 0;
            while (insertAt < messages.length && messages[insertAt]?.role === 'system') insertAt++;
            messages.splice(insertAt, 0, transient);
        }
        const limit = APP.aiContextLimit || AI_CONTEXT_LIMIT;
        const totalSize = messages.reduce((sum, msg) => sum + this.estimateMessageSize(msg), 0);
        if (totalSize <= limit && messages.length <= AI_CONTEXT_MESSAGE_LIMIT) return messages.slice();

        const systemMessages = messages.filter(msg => msg.role === 'system');
        const dialogueMessages = messages.filter(msg => msg.role !== 'system');
        const groups = [];
        let currentGroup = [];
        dialogueMessages.forEach(msg => {
            if (msg?.role === 'user' && currentGroup.length > 0) {
                groups.push(currentGroup);
                currentGroup = [];
            }
            currentGroup.push(msg);
        });
        if (currentGroup.length > 0) groups.push(currentGroup);

        const selectedGroups = [];
        let used = systemMessages.reduce((sum, msg) => sum + this.estimateMessageSize(msg), 0);
        let usedMessages = systemMessages.length;

        // assistant(tool_calls) 与其后的 tool 结果必须作为一个用户轮次整体保留，不能被截断拆散。
        for (let i = groups.length - 1; i >= 0; i--) {
            const group = groups[i];
            const size = group.reduce((sum, msg) => sum + this.estimateMessageSize(msg), 0);
            const fitsSize = used + size <= limit;
            const fitsCount = usedMessages + group.length <= AI_CONTEXT_MESSAGE_LIMIT;
            if ((fitsSize && fitsCount) || selectedGroups.length === 0) {
                selectedGroups.unshift(group);
                used += size;
                usedMessages += group.length;
            } else {
                break;
            }
        }

        return systemMessages.concat(selectedGroups.flat());
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
            totalChars: messages.reduce((sum, msg) => sum + this.countMessageChars(msg), 0),
            sentChars: contextMessages.reduce((sum, msg) => sum + this.countMessageChars(msg), 0),
            totalMessages,
            sentMessages,
            truncated: sentMessages < totalMessages
        };
    },

    autoSend(message) {
        APP.messageHistory.push({ role: 'user', content: message });
        if (typeof AdvisorModule !== 'undefined') AdvisorModule.updateContextMeter();
        this.appendMessage(message, null, 'user-message');
        const loadingMsg = this.appendMessage('分析中...', null, 'loading-message');
        let seconds = 0;
        const timer = setInterval(() => {
            seconds++;
            loadingMsg.textContent = `分析中... ${seconds}s`;
        }, 1000);
        this.sendToAI(loadingMsg, timer);
    },

    appendMessage(content, reasoningContent, className, targetContainer, options = {}) {
        const container = targetContainer || APP.chatContainer;
        if (!container) return null;
        const div = document.createElement('div');
        div.classList.add('message', className);
        if (className === 'user-message') {
            const contentDiv = document.createElement('div');
            contentDiv.classList.add('content');
            contentDiv.textContent = content;
            div.appendChild(contentDiv);

            const shouldCollapse = APP.config?.collapseLongUserMessages !== false
                && countTextChars(content) > (APP.config?.longUserMessageThreshold || 4000);
            if (shouldCollapse) {
                div.classList.add('long-user-message', 'collapsed');

                const tools = document.createElement('div');
                tools.classList.add('message-collapse-tools');

                const meta = document.createElement('span');
                const charSize = countTextChars(content);
                const contextSize = this.estimateContextSize(content);
                meta.textContent = `已折叠长消息 · ${formatCompactSize(charSize)} 字符 · 约${formatCompactSize(contextSize)}上下文`;
                meta.title = `实际字符 ${charSize}，估算上下文单位 ${contextSize}`;

                const toggle = document.createElement('button');
                toggle.type = 'button';
                toggle.className = 'message-collapse-toggle';
                toggle.textContent = '展开全文';
                toggle.addEventListener('click', () => {
                    const expanded = div.classList.toggle('expanded');
                    div.classList.toggle('collapsed', !expanded);
                    toggle.textContent = expanded ? '收起全文' : '展开全文';
                    const scrollContainer = div.closest('.chat-messages, .advisor-messages');
                    if (expanded && scrollContainer) {
                        div.scrollIntoView({ block: 'nearest' });
                    }
                });

                tools.appendChild(meta);
                tools.appendChild(toggle);
                div.appendChild(tools);
            }
        } else {
            if (reasoningContent) {
                const rd = document.createElement('div');
                rd.classList.add('reasoning-content');
                const title = document.createElement('div');
                title.className = 'reasoning-title';
                title.textContent = '推理流';
                const body = document.createElement('div');
                body.className = 'reasoning-body';
                body.innerHTML = DOMPurify.sanitize(marked.parse(reasoningContent));
                rd.appendChild(title);
                rd.appendChild(body);
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
        this._recordDisplayMessage(div, { className, content: content || '', reasoningContent: reasoningContent || '' }, options);

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

    _appendReasoningBubble(targetContainer, options = {}) {
        const container = targetContainer || APP.chatContainer;
        if (!container) return null;

        const div = document.createElement('div');
        div.className = 'message reasoning-message';

        const title = document.createElement('div');
        title.className = 'reasoning-title';
        title.textContent = '推理流';

        const body = document.createElement('div');
        body.className = 'reasoning-body';

        div.appendChild(title);
        div.appendChild(body);
        container.appendChild(div);
        container.scrollTop = container.scrollHeight;
        this._recordDisplayMessage(div, { className: 'reasoning-message', content: '' }, options);

        if (typeof gsap !== 'undefined') {
            gsap.fromTo(div, { autoAlpha: 0, y: 4 }, { autoAlpha: 1, y: 0, duration: 0.22, ease: 'power2.out', clearProps: 'autoAlpha,transform' });
        }

        return div;
    },

    /**
     * 更新独立推理流消息气泡
     */
    _updateReasoning(reasoningDiv, fullReasoning) {
        if (!reasoningDiv) return;
        let body = reasoningDiv.querySelector('.reasoning-body');
        if (!body) {
            body = document.createElement('div');
            body.className = 'reasoning-body';
            reasoningDiv.appendChild(body);
        }
        // 推理内容用纯文本显示，避免大量 Markdown 解析卡顿
        body.textContent = fullReasoning;
        this._updateDisplayMessage(reasoningDiv, { content: fullReasoning });
        const scrollContainer = reasoningDiv.closest('.chat-messages, .advisor-messages');
        if (scrollContainer) scrollContainer.scrollTop = scrollContainer.scrollHeight;
    },

    _renderReasoningMarkdown(reasoningDiv, fullReasoning) {
        if (!reasoningDiv) return;
        let body = reasoningDiv.querySelector('.reasoning-body');
        if (!body) {
            body = document.createElement('div');
            body.className = 'reasoning-body';
            reasoningDiv.appendChild(body);
        }
        body.innerHTML = DOMPurify.sanitize(marked.parse(fullReasoning));
        this._updateDisplayMessage(reasoningDiv, { content: fullReasoning }, { reasoningMarkdown: true });
    },

    _extractReasoningDelta(delta) {
        if (!delta || typeof delta !== 'object') return '';
        for (const key of ['reasoning_content', 'reasoning', 'thinking']) {
            const value = delta[key];
            if (typeof value === 'string' && value) return value;
        }
        return '';
    },

    _normalizeConversationContextMessage(message) {
        if (!message || typeof message !== 'object') return null;
        const role = String(message.role || '');
        if (!['assistant', 'tool'].includes(role)) return null;

        const normalized = {
            role,
            content: typeof message.content === 'string'
                ? message.content
                : (role === 'assistant' && Array.isArray(message.tool_calls) ? null : '')
        };
        if (role === 'assistant') {
            if (typeof message.reasoning_content === 'string') {
                normalized.reasoning_content = message.reasoning_content;
            }
            if (Array.isArray(message.tool_calls)) {
                normalized.tool_calls = message.tool_calls;
            }
        } else {
            if (typeof message.tool_call_id !== 'string' || !message.tool_call_id) return null;
            normalized.tool_call_id = message.tool_call_id;
            if (typeof message.name === 'string') normalized.name = message.name;
        }
        return normalized;
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
        this._updateDisplayMessage(botDiv, { content: fullResponse });
        // 使用 botDiv 的父级容器来滚动，而非硬编码 APP.chatContainer
        const scrollContainer = botDiv.closest('.chat-messages, .advisor-messages');
        if (scrollContainer) scrollContainer.scrollTop = scrollContainer.scrollHeight;
    },

    _appendProcessStatus(message, targetContainer, options = {}) {
        const text = String(message || '').trim();
        if (!text) return null;
        const container = targetContainer || APP.chatContainer;
        if (!container) return null;

        const div = document.createElement('div');
        div.className = 'message process-message';
        const dot = document.createElement('span');
        dot.className = 'process-dot';
        dot.setAttribute('aria-hidden', 'true');
        const content = document.createElement('span');
        content.className = 'process-text';
        content.textContent = text;
        div.appendChild(dot);
        div.appendChild(content);
        container.appendChild(div);
        container.scrollTop = container.scrollHeight;
        this._recordDisplayMessage(div, { className: 'process-message', message: text }, options);

        if (typeof gsap !== 'undefined') {
            gsap.fromTo(div, { autoAlpha: 0, y: 4 }, { autoAlpha: 1, y: 0, duration: 0.2, ease: 'power2.out', clearProps: 'autoAlpha,transform' });
        }
        return div;
    },

    _appendThoughtBubble(message, targetContainer, options = {}) {
        const text = String(message || '').trim();
        if (!text) return null;
        const container = targetContainer || APP.chatContainer;
        if (!container) return null;

        const div = document.createElement('div');
        div.className = 'message thought-message';
        const title = document.createElement('div');
        title.className = 'thought-message-title';
        title.textContent = '执行计划';
        const body = document.createElement('div');
        body.className = 'thought-message-body';
        body.textContent = text;
        div.appendChild(title);
        div.appendChild(body);
        container.appendChild(div);
        container.scrollTop = container.scrollHeight;
        this._recordDisplayMessage(div, { className: 'thought-message', message: text }, options);

        if (typeof gsap !== 'undefined') {
            gsap.fromTo(div, { autoAlpha: 0, y: 4 }, { autoAlpha: 1, y: 0, duration: 0.22, ease: 'power2.out', clearProps: 'autoAlpha,transform' });
        }
        return div;
    },

    _appendToolStatusBubble(status, targetContainer, options = {}) {
        if (!status) return null;
        const container = targetContainer || APP.chatContainer;
        if (!container) return null;

        const div = document.createElement('div');
        div.className = 'message tool-message';
        div.dataset.origin = status.origin || 'model_tool_call';

        const title = document.createElement('div');
        title.className = 'tool-message-title';
        title.textContent = status.trace_title || 'AI 模型正在调用工具';

        const body = document.createElement('div');
        body.className = 'tool-message-body';
        body.textContent = status.message || '调用研究工具';

        const meta = document.createElement('div');
        meta.className = 'tool-message-meta';
        const metaParts = [];
        if (status.tool) metaParts.push(status.tool);
        if (status.args_summary && Object.keys(status.args_summary).length) {
            metaParts.push(Object.entries(status.args_summary)
                .map(([k, v]) => `${k}: ${this._formatToolArgValue(v)}`)
                .join(' / '));
        }
        meta.textContent = metaParts.join(' · ');

        div.appendChild(title);
        div.appendChild(body);
        if (meta.textContent) div.appendChild(meta);
        container.appendChild(div);
        container.scrollTop = container.scrollHeight;
        this._recordDisplayMessage(div, { className: 'tool-message', status: { ...status } }, options);

        if (typeof gsap !== 'undefined') {
            gsap.fromTo(div, { autoAlpha: 0, y: 5 }, { autoAlpha: 1, y: 0, duration: 0.22, ease: 'power2.out', clearProps: 'autoAlpha,transform' });
        }
        return div;
    },

    _formatToolArgValue(value) {
        if (Array.isArray(value)) {
            return value.map(item => this._formatToolArgValue(item)).join(', ');
        }
        if (value && typeof value === 'object') {
            try {
                const json = JSON.stringify(value);
                return json.length > 120 ? json.slice(0, 117) + '...' : json;
            } catch (e) {
                return String(value);
            }
        }
        return String(value);
    },

    _agentStatusText(event) {
        if (!event || typeof event !== 'object') return '';
        const type = event.type || '';
        const duration = this._formatDuration(event.duration_ms);
        if (type === 'run_started') return 'AI 已开始分析任务';
        if (type === 'agent_status') return event.message || 'AI 正在分析';
        if (type === 'model_request_started') return `模型决策请求开始：第 ${event.round || '-'} 轮`;
        if (type === 'model_request_finished') return `模型决策请求完成：第 ${event.round || '-'} 轮${duration ? ' · ' + duration : ''}`;
        if (type === 'model_request_failed') return `模型决策请求失败：第 ${event.round || '-'} 轮${duration ? ' · ' + duration : ''}${event.message ? ' · ' + event.message : ''}`;
        if (type === 'model_stream_started') return `模型流式生成开始：${event.phase || 'stream'}`;
        if (type === 'model_stream_finished') return `模型流式生成结束${duration ? ' · ' + duration : ''}`;
        if (type === 'tool_call_started') return `AI 模型正在调用工具：${event.tool || '研究工具'}`;
        if (type === 'tool_call_finished') {
            const ok = event.success === false ? '失败' : '完成';
            const rows = event.output_summary?.rows;
            const rowsText = typeof rows === 'number' ? ` · ${rows} 项` : '';
            const codeText = event.output_summary?.code ? ` · ${event.output_summary.code}` : '';
            return `工具调用${ok}：${event.tool || '研究工具'}${duration ? ' · ' + duration : ''}${rowsText}${codeText}`;
        }
        if (type === 'checkpoint_created') return '已创建运行检查点';
        if (type === 'final_answer_started') return '正在生成最终回答';
        if (type === 'final_answer_finished') return '最终回答已完成';
        if (type === 'run_finished') return `AI 任务已完成${duration || event.elapsed_ms ? ' · ' + this._formatDuration(event.elapsed_ms || event.duration_ms) : ''}`;
        if (type === 'run_failed') return `${event.message || 'AI 任务失败'}${event.stop_reason ? ' · ' + event.stop_reason : ''}`;
        return '';
    },

    _shouldDisplayAgentStatus(event, text) {
        if (!event) return false;
        if ([
            'model_request_finished',
            'model_request_failed',
            'tool_call_finished',
            'model_stream_finished',
            'run_finished',
            'run_failed'
        ].includes(event.type)) {
            return true;
        }
        if (event.type !== 'agent_status') return false;
        const statusText = String(text || event.message || '').trim();
        if (!statusText) return false;
        return /JSON|参数|失败|超时|回退|错误|异常|上限|无法|中断|invalid|timeout|fallback/i.test(statusText);
    },

    _formatDuration(ms) {
        const value = Number(ms);
        if (!Number.isFinite(value) || value < 0) return '';
        if (value < 1000) return `${Math.round(value)}ms`;
        return `${(value / 1000).toFixed(value < 10000 ? 2 : 1)}s`;
    },

    _formatAIErrorPayload(error, diagnostics = {}) {
        const parts = [];
        const message = String(error?.message || '未知错误').trim();
        parts.push(`**错误:** ${message}`);

        const extra = [];
        if (error?.type) extra.push(`type=${error.type}`);
        if (error?.code !== undefined && error?.code !== null && error?.code !== '') extra.push(`code=${error.code}`);
        if (diagnostics?.runId) extra.push(`run_id=${diagnostics.runId}`);
        if (diagnostics?.lastRound !== null && diagnostics?.lastRound !== undefined) extra.push(`round=${diagnostics.lastRound}`);
        if (diagnostics?.lastTool) extra.push(`tool=${diagnostics.lastTool}`);
        if (diagnostics?.lastEventType) extra.push(`event=${diagnostics.lastEventType}`);

        const details = error?.details || {};
        if (details.duration_ms !== undefined) extra.push(`duration=${this._formatDuration(details.duration_ms)}`);
        if (details.http_code) extra.push(`http=${details.http_code}`);
        if (details.curl_errno) extra.push(`curl_errno=${details.curl_errno}`);
        if (details.phase) extra.push(`phase=${details.phase}`);

        if (extra.length) {
            parts.push('');
            parts.push('```text');
            parts.push(extra.join(' | '));
            parts.push('```');
        }
        return parts.join('\n');
    },

    _formatStreamDiagnostics(diagnostics = {}, reason = 'stream_interrupted') {
        const items = [`reason=${reason}`];
        if (diagnostics.runId) items.push(`run_id=${diagnostics.runId}`);
        if (diagnostics.lastEventType) items.push(`event=${diagnostics.lastEventType}`);
        if (diagnostics.lastRound !== null && diagnostics.lastRound !== undefined) items.push(`round=${diagnostics.lastRound}`);
        if (diagnostics.lastTool) items.push(`tool=${diagnostics.lastTool}`);
        if (diagnostics.lastAgentMessage) items.push(`status=${diagnostics.lastAgentMessage}`);
        return `**错误:** AI 响应流提前结束\n\n\`\`\`text\n${items.join(' | ')}\n\`\`\``;
    },

    _formatFrontendFetchError(error) {
        const rawMessage = String(error?.message || error || 'unknown error');
        const isNetworkError = /network\s*error|failed to fetch|load failed/i.test(rawMessage);
        const message = isNetworkError
            ? 'AI 长连接被浏览器或中间代理中断'
            : rawMessage;
        const hint = isNetworkError
            ? '通常由 PHP-FPM/Nginx/CDN 的请求时长或空闲超时触发；如果后端心跳仍无法避免，请同步调高 fastcgi_read_timeout / proxy_read_timeout / request_terminate_timeout。'
            : '请查看浏览器 Network 面板中 ai_api.php 的状态码和响应内容。';
        return `**错误:** ${message}\n\n${hint}\n\n\`\`\`text\nstage=frontend_fetch | raw=${rawMessage}\n\`\`\``;
    },

    async sendToAI(loadingMessage, timer, targetContainer) {
        const requestVersion = APP.advisorRequestVersion;
        let requestController = null;
        const finishRequestState = () => {
            if (APP._aiAbortController !== requestController) return;
            APP._aiAbortController = null;
            if (typeof AdvisorModule !== 'undefined') AdvisorModule.setThinking(false);
        };
        try {
            // Phase 1.4: AbortController — 新请求发出时取消旧请求
            if (APP._aiAbortController) {
                APP._aiAbortController.abort();
            }
            requestController = new AbortController();
            APP._aiAbortController = requestController;
            const signal = requestController.signal;
            if (typeof AdvisorModule !== 'undefined') AdvisorModule.setThinking(true);

            const requestMessages = this.getContextMessages();
            // 渠道和模型由后端 ai_api.php 的 $defaultChannel 统一控制
            const response = await fetch('ai_api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    session_id: APP.currentSessionId,
                    messages: requestMessages,
                    stream: true
                }),
                signal
            });

            if (!response.ok) throw new Error(`服务器错误(${response.status})`);
            if (!response.body) throw new Error('无法获取响应流');

            const reader = response.body.getReader();
            const decoder = new TextDecoder('utf-8');
            let botDiv = null;
            let reasoningDiv = null;
            let reasoningRound = null;
            let currentReasoning = '';
            const ensureBotDiv = () => {
                if (!botDiv) botDiv = this.appendMessage('', '', 'bot-message', targetContainer);
                return botDiv;
            };
            const ensureReasoningDiv = () => {
                const roundKey = diagnostics.lastRound ?? 'unknown';
                if (!reasoningDiv || reasoningRound !== roundKey) {
                    reasoningDiv = this._appendReasoningBubble(targetContainer, { round: roundKey });
                    reasoningRound = roundKey;
                    currentReasoning = '';
                }
                return reasoningDiv;
            };
            let fullResponse = '';
            let fullReasoning = '';
            let finalReasoning = '';
            let finalAnswerStarted = false;
            let hasToolContextMessage = false;
            let hasFinalContextMessage = false;
            let finalContextMessageIndex = null;
            let streamDone = false;
            let lastProcessText = '';
            const diagnostics = {
                runId: '',
                lastEventType: '',
                lastRound: null,
                lastTool: '',
                lastAgentMessage: '',
                lastError: null
            };

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
                            if (json && typeof json === 'object') {
                                diagnostics.lastEventType = String(json.type || diagnostics.lastEventType || '');
                                if (json.run_id) diagnostics.runId = json.run_id;
                                if (json.round !== undefined && json.round !== null) diagnostics.lastRound = json.round;
                                if (json.tool) diagnostics.lastTool = json.tool;
                                if (json.message) diagnostics.lastAgentMessage = json.message;
                            }

                            if (json.type === 'final_answer_started') {
                                finalAnswerStarted = true;
                            }

                            if (json.type === 'conversation_context') {
                                if (requestVersion === APP.advisorRequestVersion && Array.isArray(json.messages)) {
                                    json.messages.forEach(message => {
                                        const normalized = this._normalizeConversationContextMessage(message);
                                        if (!normalized) return;
                                        APP.messageHistory.push(normalized);
                                        if (normalized.role === 'assistant' && Array.isArray(normalized.tool_calls) && normalized.tool_calls.length > 0) {
                                            hasToolContextMessage = true;
                                        }
                                        if (normalized.role === 'assistant' && (!Array.isArray(normalized.tool_calls) || normalized.tool_calls.length === 0)) {
                                            hasFinalContextMessage = true;
                                            finalContextMessageIndex = APP.messageHistory.length - 1;
                                        }
                                    });
                                }
                                continue;
                            }

                            if (json.type === 'tool_status') {
                                const label = json.message || '调用研究工具';
                                this._appendToolStatusBubble(json, targetContainer);
                                if (typeof AdvisorModule !== 'undefined') AdvisorModule.setStatus(label + '...');
                                continue;
                            }

                            if (json.type === 'assistant_thought') {
                                this._appendThoughtBubble(json.message || '', targetContainer);
                                if (typeof AdvisorModule !== 'undefined') AdvisorModule.setStatus('规划工具调用...');
                                continue;
                            }

                            const agentStatus = this._agentStatusText(json);
                            if (agentStatus) {
                                if (this._shouldDisplayAgentStatus(json, agentStatus) && agentStatus !== lastProcessText) {
                                    this._appendProcessStatus(agentStatus, targetContainer);
                                    lastProcessText = agentStatus;
                                }
                                if (typeof AdvisorModule !== 'undefined') AdvisorModule.setStatus(agentStatus + '...');
                                continue;
                            }

                            // 错误响应
                            if (json.error) {
                                diagnostics.lastError = json.error;
                                fullResponse = this._formatAIErrorPayload(json.error, diagnostics);
                                this._updateContent(ensureBotDiv(), fullResponse);
                                continue;
                            }

                            // 增量 delta
                            const delta = json.choices?.[0]?.delta;
                            if (delta) {
                                // 推理内容（reasoning 模型特有）
                                const reasoningDelta = this._extractReasoningDelta(delta);
                                if (reasoningDelta) {
                                    fullReasoning += reasoningDelta;
                                    if (finalAnswerStarted) finalReasoning += reasoningDelta;
                                    const targetReasoningDiv = ensureReasoningDiv();
                                    currentReasoning += reasoningDelta;
                                    this._updateReasoning(targetReasoningDiv, currentReasoning);
                                }
                                // 正式回复内容
                                if (delta.content) {
                                    fullResponse += delta.content;
                                    this._updateContent(ensureBotDiv(), fullResponse);
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
                            if (json && typeof json === 'object') {
                                diagnostics.lastEventType = String(json.type || diagnostics.lastEventType || '');
                                if (json.run_id) diagnostics.runId = json.run_id;
                                if (json.round !== undefined && json.round !== null) diagnostics.lastRound = json.round;
                                if (json.tool) diagnostics.lastTool = json.tool;
                                if (json.message) diagnostics.lastAgentMessage = json.message;
                            }
                            if (json.type === 'final_answer_started') {
                                finalAnswerStarted = true;
                            }
                            if (json.type === 'conversation_context') {
                                if (requestVersion === APP.advisorRequestVersion && Array.isArray(json.messages)) {
                                    json.messages.forEach(message => {
                                        const normalized = this._normalizeConversationContextMessage(message);
                                        if (!normalized) return;
                                        APP.messageHistory.push(normalized);
                                        if (normalized.role === 'assistant' && Array.isArray(normalized.tool_calls) && normalized.tool_calls.length > 0) {
                                            hasToolContextMessage = true;
                                        }
                                        if (normalized.role === 'assistant' && (!Array.isArray(normalized.tool_calls) || normalized.tool_calls.length === 0)) {
                                            hasFinalContextMessage = true;
                                            finalContextMessageIndex = APP.messageHistory.length - 1;
                                        }
                                    });
                                }
                            } else if (json.type === 'tool_status') {
                                const label = json.message || '调用研究工具';
                                this._appendToolStatusBubble(json, targetContainer);
                                if (typeof AdvisorModule !== 'undefined') AdvisorModule.setStatus(label + '...');
                            } else if (json.type === 'assistant_thought') {
                                this._appendThoughtBubble(json.message || '', targetContainer);
                                if (typeof AdvisorModule !== 'undefined') AdvisorModule.setStatus('规划工具调用...');
                            } else {
                                const agentStatus = this._agentStatusText(json);
                                if (agentStatus) {
                                    if (this._shouldDisplayAgentStatus(json, agentStatus) && agentStatus !== lastProcessText) {
                                        this._appendProcessStatus(agentStatus, targetContainer);
                                        lastProcessText = agentStatus;
                                    }
                                    if (typeof AdvisorModule !== 'undefined') AdvisorModule.setStatus(agentStatus + '...');
                                } else if (json.error) {
                                    diagnostics.lastError = json.error;
                                    fullResponse = this._formatAIErrorPayload(json.error, diagnostics);
                                    this._updateContent(ensureBotDiv(), fullResponse);
                                } else {
                                    const delta = json.choices?.[0]?.delta;
                                    if (delta) {
                                        const reasoningDelta = this._extractReasoningDelta(delta);
                                        if (reasoningDelta) {
                                            fullReasoning += reasoningDelta;
                                            if (finalAnswerStarted) finalReasoning += reasoningDelta;
                                            const targetReasoningDiv = ensureReasoningDiv();
                                            currentReasoning += reasoningDelta;
                                            this._updateReasoning(targetReasoningDiv, currentReasoning);
                                        }
                                        if (delta.content) {
                                            fullResponse += delta.content;
                                            this._updateContent(ensureBotDiv(), fullResponse);
                                        }
                                    }
                                }
                            }
                        } catch (e) { /* ignore */ }
                    }
                }
            }

            if (!streamDone && requestVersion === APP.advisorRequestVersion) {
                const interruption = this._formatStreamDiagnostics(
                    diagnostics,
                    diagnostics.lastError ? 'upstream_error_before_done' : 'stream_ended_without_done'
                );
                fullResponse = fullResponse ? `${fullResponse}\n\n${interruption}` : interruption;
                this._updateContent(ensureBotDiv(), fullResponse);
            }

            // 流结束：清理 loading 状态
            clearInterval(timer);
            if (loadingMessage.parentNode) loadingMessage.parentNode.removeChild(loadingMessage);
            finishRequestState();

            // 如果有推理内容，渲染为 Markdown
            if (reasoningDiv && currentReasoning) {
                this._renderReasoningMarkdown(reasoningDiv, currentReasoning);
            }

            // conversation_context 已保存非流式最终消息；流式最终消息在此补齐 reasoning_content。
            // 如果用户已清空对话，丢弃旧流式请求的回写，避免清空后历史被旧响应恢复。
            if (requestVersion !== APP.advisorRequestVersion) return;
            const reasoningForHistory = finalReasoning || (!hasToolContextMessage ? fullReasoning : '');
            if (hasFinalContextMessage && finalContextMessageIndex !== null && APP.messageHistory[finalContextMessageIndex]) {
                // syntheticContent 可能补充风险提示，以浏览器最终收到的正式内容为准。
                if (fullResponse) APP.messageHistory[finalContextMessageIndex].content = fullResponse;
            } else if (fullResponse) {
                const finalMessage = { role: 'assistant', content: fullResponse };
                if (reasoningForHistory) finalMessage.reasoning_content = reasoningForHistory;
                APP.messageHistory.push(finalMessage);
            } else if (fullReasoning) {
                // 推理模型可能因超时只输出了推理过程而没有正式回复
                APP.messageHistory.push({ role: 'assistant', content: '', reasoning_content: reasoningForHistory || fullReasoning });
                this._updateContent(ensureBotDiv(), '> 模型思考超时，仅返回了推理过程，请重新发送以获取完整回复。');
            } else {
                this._updateContent(ensureBotDiv(), '**提示:** 服务器返回空响应，请检查网络或稍后重试。');
            }
            if (typeof AdvisorModule !== 'undefined') AdvisorModule.updateContextMeter();

        } catch(error) {
            clearInterval(timer);
            if (loadingMessage.parentNode) loadingMessage.parentNode.removeChild(loadingMessage);
            // Phase 1.4: AbortError 是用户主动取消，静默处理
            if (error.name === 'AbortError') {
                finishRequestState();
                return;
            }
            if (requestVersion === APP.advisorRequestVersion) {
                this.appendMessage(this._formatFrontendFetchError(error), null, 'bot-message', targetContainer);
            }
            finishRequestState();
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
    const loadingMsg = AIModule.appendMessage('分析中...', null, 'loading-message');
    let seconds = 0;
    const timer = setInterval(() => { seconds++; loadingMsg.textContent = `分析中... ${seconds}s`; }, 1000);
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

// ============================================================
// 全站标的舆情脉搏（行情 / 基金 / 详情抽屉复用）
// ============================================================
const AssetPulseModule = {
    cache: new Map(),
    inFlight: new Map(),
    assets: new Map(),
    cacheTtl: 60000,
    initialized: false,

    init() {
        if (this.initialized) return;
        this.initialized = true;
        document.addEventListener('click', event => {
            const button = event.target.closest('[data-pulse-action]');
            if (!button) return;
            const host = button.closest('[data-asset-pulse]');
            if (!host) return;
            const asset = {
                type: host.dataset.assetType || host.dataset.assetPulse || 'stock',
                code: host.dataset.assetCode || '',
                name: host.dataset.assetName || ''
            };
            if (!asset.code && !asset.name) return;
            const action = button.dataset.pulseAction;
            if (action === 'refresh') this.refresh(host, asset);
            if (action === 'full') this.openFullNews(asset);
            if (action === 'ai') this.analyzeWithAI(asset);
            if (action === 'announcement-detail' && button.dataset.announcementId && typeof NewsModule !== 'undefined') {
                NewsModule.openAnnouncementDetail(button.dataset.announcementId, button);
            }
        });
    },

    normalizeAsset(assetType, asset = {}) {
        const type = assetType === 'fund' ? 'fund' : 'stock';
        let code = String(asset.code || asset.fundcode || asset.symbol || asset.dm || '').trim();
        if (type === 'stock') code = code.replace(/[^A-Za-z0-9.]/g, '').toUpperCase().slice(0, 20);
        else code = code.replace(/[^0-9]/g, '').slice(0, 6);
        const name = String(asset.name || asset.mc || '').trim().slice(0, 80);
        return { type, code, name };
    },

    assetKey(asset) {
        return `${asset.type}|${asset.code}|${asset.name}`;
    },

    hostsFor(assetType, target = null) {
        if (target instanceof Element) return [target];
        if (typeof target === 'string' && target) {
            const element = document.getElementById(target);
            return element ? [element] : [];
        }
        return Array.from(document.querySelectorAll(`[data-asset-pulse="${assetType}"]`));
    },

    focus(assetType, asset = {}, options = {}) {
        const normalized = this.normalizeAsset(assetType, asset);
        if (!normalized.code && !normalized.name) return Promise.resolve(null);
        this.assets.set(normalized.type, normalized);
        const hosts = this.hostsFor(normalized.type, options.target || null);
        if (!hosts.length) return Promise.resolve(null);
        const key = this.assetKey(normalized);

        hosts.forEach(host => {
            host.dataset.assetType = normalized.type;
            host.dataset.assetCode = normalized.code;
            host.dataset.assetName = normalized.name;
            host.dataset.pulseRequest = key;
            this.renderLoading(host, normalized);
        });

        return this.load(normalized, Boolean(options.force)).then(payload => {
            hosts.forEach(host => {
                if (host.dataset.pulseRequest === key) this.render(host, normalized, payload);
            });
            return payload;
        }).catch(error => {
            hosts.forEach(host => {
                if (host.dataset.pulseRequest === key) this.renderError(host, normalized, error);
            });
            return null;
        });
    },

    async load(asset, force = false) {
        const key = this.assetKey(asset);
        const cached = this.cache.get(key);
        if (!force && cached && Date.now() - cached.timestamp < this.cacheTtl) return cached.payload;
        if (this.inFlight.has(key)) return this.inFlight.get(key);

        const task = (async () => {
            const base = new URLSearchParams({
                action: 'asset',
                asset_type: asset.type,
                limit: '6'
            });
            if (asset.code) base.set('code', asset.code);
            if (asset.name) base.set('name', asset.name);
            const news = await this.fetchJson(`news_api.php?${base}`);

            const sentimentParams = new URLSearchParams({
                action: 'sentiment',
                scope: 'asset',
                asset_type: asset.type,
                limit: '18'
            });
            if (asset.code) sentimentParams.set('code', asset.code);
            if (asset.name) sentimentParams.set('name', asset.name);
            let sentiment = null;
            let sentimentError = '';
            try {
                sentiment = await this.fetchJson(`news_api.php?${sentimentParams}`);
            } catch (error) {
                sentimentError = error.message || '情绪快照暂不可用';
            }

            let announcements = null;
            let announcementError = '';
            if (asset.type === 'stock') {
                const announcementParams = new URLSearchParams({
                    action: 'list',
                    scope: 'stock',
                    importance: 'important',
                    limit: '2'
                });
                if (asset.code) announcementParams.set('code', asset.code);
                if (asset.name) announcementParams.set('name', asset.name);
                try {
                    announcements = await this.fetchJson(`announcement_api.php?${announcementParams}`);
                } catch (error) {
                    announcementError = error.message || '公告暂不可用';
                }
            }

            const payload = { news, sentiment, sentimentError, announcements, announcementError };
            this.cache.set(key, { timestamp: Date.now(), payload });
            return payload;
        })();

        this.inFlight.set(key, task);
        try {
            return await task;
        } finally {
            this.inFlight.delete(key);
        }
    },

    async fetchJson(url) {
        const response = await fetch(url, { headers: { Accept: 'application/json' } });
        let payload;
        try {
            payload = await response.json();
        } catch (error) {
            throw new Error(`数据服务返回无效响应（HTTP ${response.status}）`);
        }
        if (!response.ok || !payload.success) {
            throw new Error(payload.message || payload.error_message || `数据服务暂不可用（HTTP ${response.status}）`);
        }
        return payload;
    },

    renderLoading(host, asset) {
        const label = asset.name || asset.code || (asset.type === 'fund' ? '当前基金' : '当前股票');
        host.classList.add('is-loading');
        host.innerHTML = `
            <div class="asset-pulse-head">
                <div class="asset-pulse-heading"><span class="asset-pulse-orb"><i></i></span><div><small>标的资讯脉搏</small><b>${escapeHTML(label)}</b></div></div>
                <span class="asset-pulse-status neutral">对齐中</span>
            </div>
            <div class="asset-pulse-loading"><span></span><span></span><span></span><p>正在匹配相关标题与情绪样本…</p></div>`;
    },

    render(host, requestedAsset, payload) {
        host.classList.remove('is-loading', 'has-error');
        const news = payload.news || {};
        const snapshot = payload.sentiment?.data || null;
        const mapped = news.meta?.asset || {};
        const asset = this.normalizeAsset(requestedAsset.type, {
            code: mapped.code || requestedAsset.code,
            name: mapped.name || requestedAsset.name
        });
        host.dataset.assetType = asset.type;
        host.dataset.assetCode = asset.code;
        host.dataset.assetName = asset.name;
        this.assets.set(asset.type, asset);

        const items = Array.isArray(news.data) ? news.data.slice(0, 4) : [];
        const labelMap = { positive: '偏正面', negative: '偏负面', neutral: '中性' };
        const sentimentLabel = snapshot ? (labelMap[snapshot.label] || '中性') : '情绪暂缺';
        const sentimentClass = snapshot?.label || 'neutral';
        const numericScore = Number(snapshot?.score);
        const score = Number.isFinite(numericScore) ? `${numericScore > 0 ? '+' : ''}${numericScore.toFixed(3)}` : '--';
        const title = asset.name || asset.code || '当前标的';
        const codeLabel = asset.name && asset.code ? asset.code : (asset.type === 'fund' ? '基金' : '股票');
        const sampleSize = snapshot?.sample_size ?? items.length;
        const sourceCount = snapshot?.source_count ?? new Set(items.map(item => item.source).filter(Boolean)).size;
        const newestAt = snapshot?.newest_at || items[0]?.published_at || '';
        const announcementItems = requestedAsset.type === 'stock' && Array.isArray(payload.announcements?.data)
            ? payload.announcements.data.slice(0, 2)
            : [];
        const eventLabels = { performance: '业绩', capital_operation: '资本', ownership: '股权', operation: '经营', dividend: '分红', governance: '治理', risk_regulatory: '风险', other: '事项' };
        const announcementBlock = requestedAsset.type === 'stock' ? `
            <div class="asset-pulse-announcements">
                <div class="asset-pulse-announcement-label"><span>公司公告与事件</span><span>${announcementItems.length ? `${announcementItems.length} 条近期重要公告` : (payload.announcementError ? '暂不可用' : '暂无重要公告')}</span></div>
                ${announcementItems.map(item => `<button type="button" class="asset-pulse-announcement" data-pulse-action="announcement-detail" data-announcement-id="${escapeAttr(item.id || '')}"><span>${escapeHTML(eventLabels[item.event_type] || '公告')}</span><b>${escapeHTML(item.title || '未命名公告')}</b><em>›</em></button>`).join('')}
            </div>` : '';
        const headlines = items.length ? items.map(item => {
            const content = `<span class="asset-pulse-dot"></span><span class="asset-pulse-news-main"><b>${escapeHTML(item.title || '未命名新闻')}</b><small>${escapeHTML(item.source || '未知来源')} · ${escapeHTML(this.shortTime(item.published_at))}</small></span><span class="asset-pulse-news-arrow">↗</span>`;
            return item.url
                ? `<a class="asset-pulse-news" href="${escapeAttr(item.url)}" target="_blank" rel="noopener noreferrer">${content}</a>`
                : `<div class="asset-pulse-news">${content}</div>`;
        }).join('') : '<p class="asset-pulse-no-news">暂未找到足够相关的标题，可稍后刷新或进入完整舆情调整关键词。</p>';

        host.innerHTML = `
            <div class="asset-pulse-head">
                <div class="asset-pulse-heading"><span class="asset-pulse-orb"><i></i></span><div><small>${asset.type === 'fund' ? '基金舆情脉搏' : '标的资讯脉搏'}</small><b>${escapeHTML(title)} <em>${escapeHTML(codeLabel)}</em></b></div></div>
                <div class="asset-pulse-head-actions"><span class="asset-pulse-status ${escapeAttr(sentimentClass)}">${escapeHTML(sentimentLabel)}</span><button type="button" data-pulse-action="refresh" title="刷新舆情" aria-label="刷新当前标的舆情">↻</button></div>
            </div>
            <div class="asset-pulse-metrics">
                <div class="asset-pulse-score ${escapeAttr(sentimentClass)}"><span>标题情绪</span><b>${score}</b><small>弱信号</small></div>
                <div><span>相关样本</span><b>${escapeHTML(sampleSize)}</b><small>${escapeHTML(sourceCount)} 个来源</small></div>
                <div><span>最新覆盖</span><b>${escapeHTML(this.shortTime(newestAt, true))}</b><small>${payload.sentimentError ? '情绪降级' : '近实时拉取'}</small></div>
            </div>
            ${announcementBlock}
            <div class="asset-pulse-news-list">${headlines}</div>
            <div class="asset-pulse-foot">
                <p>${asset.type === 'stock' ? '公告用于事实核验；标题情绪仅是弱信号。' : '仅基于标题与时间衰减，不代表事实判断。'}</p>
                <div><button type="button" class="btn-sm" data-pulse-action="full">完整资讯</button><button type="button" class="btn-sm btn-ai" data-pulse-action="ai">${Icons.hot} AI研判</button></div>
            </div>`;
    },

    renderError(host, asset, error) {
        host.classList.remove('is-loading');
        host.classList.add('has-error');
        const label = asset.name || asset.code || '当前标的';
        host.innerHTML = `
            <div class="asset-pulse-head">
                <div class="asset-pulse-heading"><span class="asset-pulse-orb muted"><i></i></span><div><small>标的资讯脉搏</small><b>${escapeHTML(label)}</b></div></div>
                <span class="asset-pulse-status neutral">暂不可用</span>
            </div>
            <div class="asset-pulse-error"><p>${escapeHTML(error.message || '舆情服务暂时不可用')}</p><button type="button" class="btn-sm" data-pulse-action="refresh">重新加载</button></div>`;
    },

    shortTime(value, dateOnly = false) {
        const text = String(value || '').trim();
        if (!text) return '--';
        const match = text.match(/^(\d{4})-(\d{2})-(\d{2})(?:\s+(\d{2}):(\d{2}))?/);
        if (!match) return text.slice(0, dateOnly ? 10 : 16);
        return dateOnly ? `${match[2]}-${match[3]}` : `${match[2]}-${match[3]} ${match[4] || '00'}:${match[5] || '00'}`;
    },

    refresh(host, asset) {
        this.cache.delete(this.assetKey(this.normalizeAsset(asset.type, asset)));
        this.focus(asset.type, asset, { target: host, force: true });
    },

    openFullNews(asset) {
        const normalized = this.normalizeAsset(asset.type, asset);
        const mode = document.getElementById('news-mode');
        const input = document.getElementById('news-query-input');
        if (mode) mode.value = normalized.type;
        if (input) input.value = normalized.code || normalized.name;
        if (typeof NewsModule !== 'undefined') {
            const wasLoaded = NewsModule.loaded;
            NewsModule.updateModeUI();
            switchTab('news');
            if (wasLoaded) NewsModule.search();
        } else {
            switchTab('news');
        }
    },

    analyzeWithAI(asset) {
        const normalized = this.normalizeAsset(asset.type, asset);
        const typeLabel = normalized.type === 'fund' ? '基金' : '股票';
        const label = normalized.name && normalized.code ? `${normalized.name}（${normalized.code}）` : (normalized.name || normalized.code);
        const source = document.querySelector('.nav-tab.active')?.dataset.tab === 'dividend' ? '分红日历' : '标的资讯脉搏';
        AdvisorModule.setAssetContext(normalized.type, normalized, source);
        if (normalized.type === 'stock') {
            AdvisorModule.autoSend(`请研判股票 ${label} 的最新公告、公司事件、媒体热点与舆情。必须调用 fa_get_stock_announcements、fa_get_asset_news 与 fa_get_sentiment_snapshot；如需解释公告中的金额、比例、日期、条件或风险，再调用 fa_get_stock_announcement_detail。请分开陈述公告事实、媒体标题、标题情绪弱信号和推断，并说明来源与不确定性。`);
        } else {
            AdvisorModule.autoSend(`请研判${typeLabel} ${label} 的最新热点与舆情。必须调用 fa_get_asset_news 与 fa_get_sentiment_snapshot；需要判断市场反应时再自主选择行情、K线或资金工具。请区分新闻标题、可核验事实、标题情绪弱信号和推断，并说明数据时间、样本量、相关性与不确定性。`);
        }
    }
};

// ============================================================
// 新闻舆情模块
// ============================================================
const NewsModule = {
    initialized: false,
    loaded: false,
    current: null,
    announcementPage: 1,
    announcementPayload: null,
    announcementLastFocused: null,

    init() {
        if (this.initialized) return;
        this.initialized = true;
        const mode = document.getElementById('news-mode');
        const input = document.getElementById('news-query-input');
        mode?.addEventListener('change', () => this.updateModeUI());
        document.getElementById('news-query-btn')?.addEventListener('click', () => this.search());
        document.getElementById('news-ai-btn')?.addEventListener('click', () => this.analyzeWithAI());
        document.getElementById('announcement-filter-btn')?.addEventListener('click', () => this.loadAnnouncements(1));
        document.getElementById('announcement-ai-btn')?.addEventListener('click', () => this.analyzeAnnouncementsWithAI());
        document.getElementById('announcement-prev-page')?.addEventListener('click', () => this.loadAnnouncements(Math.max(1, this.announcementPage - 1)));
        document.getElementById('announcement-next-page')?.addEventListener('click', () => this.loadAnnouncements(this.announcementPage + 1));
        document.getElementById('announcement-list')?.addEventListener('click', event => {
            const button = event.target.closest('[data-announcement-id]');
            if (button) this.openAnnouncementDetail(button.dataset.announcementId, button);
        });
        document.getElementById('announcement-detail-close')?.addEventListener('click', () => this.closeAnnouncementDetail());
        document.getElementById('announcement-detail-overlay')?.addEventListener('click', event => {
            if (event.target.id === 'announcement-detail-overlay') this.closeAnnouncementDetail();
            const aiButton = event.target.closest('[data-announcement-ai]');
            if (aiButton) this.analyzeAnnouncementDetail(aiButton.dataset.announcementAi);
            const dividendButton = event.target.closest('[data-announcement-dividend]');
            if (dividendButton) this.openDividendFromAnnouncement(dividendButton.dataset.code || '', dividendButton.dataset.name || '', dividendButton);
        });
        document.addEventListener('keydown', event => {
            const overlay = document.getElementById('announcement-detail-overlay');
            if (event.key === 'Escape' && overlay?.classList.contains('open')) this.closeAnnouncementDetail();
        });
        input?.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                this.search();
            }
        });
        document.querySelectorAll('#news-quick-keywords [data-keyword]').forEach(button => {
            button.addEventListener('click', () => {
                if (mode) mode.value = 'market';
                if (input) input.value = button.dataset.keyword || '';
                this.updateModeUI();
                this.search();
            });
        });
        if (input && !input.value) input.value = 'A股,沪指';
        this.updateModeUI();
    },

    onTabChange(tabName) {
        if (tabName !== 'news' || this.loaded) return;
        this.search();
    },

    updateModeUI() {
        const mode = document.getElementById('news-mode')?.value || 'market';
        const input = document.getElementById('news-query-input');
        const quick = document.getElementById('news-quick-keywords');
        const hint = document.getElementById('news-query-hint');
        const announcementSection = document.getElementById('announcement-section');
        const importance = document.getElementById('announcement-importance');
        if (quick) quick.style.display = mode === 'market' ? 'flex' : 'none';
        if (announcementSection) announcementSection.style.display = mode === 'fund' ? 'none' : '';
        if (importance) {
            importance.disabled = mode === 'market';
            if (mode === 'market') importance.value = 'important';
        }
        if (!input || !hint) return;
        if (mode === 'stock') {
            input.placeholder = '输入股票代码或名称，如：600519 / 贵州茅台';
            hint.textContent = '股票代码会自动解析名称，并合并“代码 + 名称”搜索结果。';
        } else if (mode === 'fund') {
            input.placeholder = '输入基金代码或名称，如：005827 / 易方达蓝筹精选混合';
            hint.textContent = '基金代码会自动解析基金名；严格过滤泛基金资讯，结果可能较少。';
        } else {
            input.placeholder = '市场关键词，支持逗号分隔，如：A股,沪指';
            hint.textContent = '市场模式支持 1～4 个关键词；热点按跨关键词去重后的发布时间排序。';
        }
    },

    buildAnnouncementUrl(context, page = 1) {
        if (!context || context.mode === 'fund') return '';
        const params = new URLSearchParams({
            action: 'list',
            scope: context.mode === 'market' ? 'market' : 'stock',
            event_type: document.getElementById('announcement-event-type')?.value || 'all',
            importance: context.mode === 'market' ? 'important' : (document.getElementById('announcement-importance')?.value || 'important'),
            page: String(Math.max(1, page)),
            limit: context.mode === 'market' ? '30' : '20'
        });
        const dateFrom = document.getElementById('announcement-date-from')?.value || '';
        const dateTo = document.getElementById('announcement-date-to')?.value || '';
        if (dateFrom) params.set('date_from', dateFrom);
        if (dateTo) params.set('date_to', dateTo);
        if (context.mode === 'stock') {
            if (context.code) params.set('code', context.code);
            if (context.name) params.set('name', context.name);
        }
        return `announcement_api.php?${params}`;
    },

    async loadAnnouncements(page = 1, contextOverride = null) {
        let context = contextOverride || this.current;
        if (!context) {
            try { context = this.buildRequests().context; } catch (error) { return null; }
        }
        const section = document.getElementById('announcement-section');
        if (context.mode === 'fund') {
            if (section) section.style.display = 'none';
            return null;
        }
        if (section) section.style.display = '';
        const url = this.buildAnnouncementUrl(context, page);
        if (!url) return null;
        const loading = document.getElementById('announcement-loading');
        const errorBox = document.getElementById('announcement-error');
        if (loading) loading.style.display = 'flex';
        if (errorBox) errorBox.style.display = 'none';
        try {
            const payload = await this.fetchJson(url, '公告');
            this.announcementPage = Math.max(1, page);
            this.announcementPayload = payload;
            this.renderAnnouncements(payload, context);
            if (this.current) {
                this.current.announcements = Array.isArray(payload.data) ? payload.data : [];
                this.current.announcementMeta = payload.meta || {};
            }
            return payload;
        } catch (error) {
            if (errorBox) {
                errorBox.textContent = error.message || '公告查询失败，请稍后重试';
                errorBox.style.display = 'block';
            }
            this.renderAnnouncements({ data: [], meta: {} }, context);
            return null;
        } finally {
            if (loading) loading.style.display = 'none';
        }
    },

    renderAnnouncements(payload, context) {
        const list = document.getElementById('announcement-list');
        const title = document.getElementById('announcement-title');
        const meta = document.getElementById('announcement-meta');
        const pagination = document.getElementById('announcement-pagination');
        const previous = document.getElementById('announcement-prev-page');
        const next = document.getElementById('announcement-next-page');
        const pageLabel = document.getElementById('announcement-page-label');
        if (!list) return;
        const items = Array.isArray(payload.data) ? payload.data : [];
        const asset = payload.meta?.asset || {};
        const eventLabels = { performance: '业绩披露', capital_operation: '资本运作', ownership: '股权事项', operation: '经营事项', dividend: '分红事项', governance: '公司治理', risk_regulatory: '监管风险', other: '其他事项' };
        const importanceLabels = { important: '重要', normal: '一般', routine: '程序性' };
        if (title) title.innerHTML = `${Icons.table} ${context.mode === 'market' ? '全市场重要公告' : `${escapeHTML(asset.name || context.name || asset.code || context.code || '指定股票')} · 公司公告`}`;
        if (meta) {
            const boundary = payload.meta?.scan_limited ? ' · 已达扫描上限' : '';
            const partial = payload.meta?.partial ? ' · 部分上游失败' : '';
            meta.textContent = `${items.length} 条 · 第 ${payload.meta?.page || this.announcementPage} 页 · ${payload.source || 'eastmoney_announcements'}${boundary}${partial}`;
        }
        if (!items.length) {
            list.innerHTML = '<p class="placeholder-text">当前筛选条件下没有公告。可切换“全部公告”或扩大日期范围。</p>';
        } else {
            list.innerHTML = items.map(item => `
                <button type="button" class="announcement-item" data-announcement-id="${escapeAttr(item.id || '')}">
                    <span class="announcement-item-badges"><em class="announcement-badge ${escapeAttr(item.importance || 'normal')}">${escapeHTML(importanceLabels[item.importance] || '一般')}</em><em class="announcement-badge event">${escapeHTML(eventLabels[item.event_type] || '其他事项')}</em></span>
                    <span class="announcement-item-main"><b>${escapeHTML(item.title || '未命名公告')}</b><span><em>${escapeHTML(item.name || item.code || '未知公司')}</em><time>${escapeHTML(item.disclosure_date || item.published_at || '日期未知')}</time>${item.category_raw ? `<span>${escapeHTML(item.category_raw)}</span>` : ''}</span></span>
                    <span class="announcement-item-open" aria-hidden="true">›</span>
                </button>`).join('');
        }
        const hasMore = Boolean(payload.meta?.has_more);
        if (pagination) pagination.style.display = items.length || this.announcementPage > 1 ? 'flex' : 'none';
        if (previous) previous.disabled = this.announcementPage <= 1;
        if (next) next.disabled = !hasMore;
        if (pageLabel) pageLabel.textContent = `第 ${this.announcementPage} 页`;
    },

    async openAnnouncementDetail(id, trigger = null) {
        if (!/^AN\d{18}$/i.test(String(id || ''))) return;
        this.announcementLastFocused = trigger || document.activeElement;
        const overlay = document.getElementById('announcement-detail-overlay');
        const drawer = overlay?.querySelector('.announcement-detail-drawer');
        const content = document.getElementById('announcement-detail-content');
        const title = document.getElementById('announcement-detail-title');
        if (!overlay || !content) return;
        overlay.classList.add('open');
        overlay.setAttribute('aria-hidden', 'false');
        document.body.classList.add('announcement-drawer-open');
        if (title) title.textContent = '公告详情';
        content.innerHTML = '<div class="loading-spinner"><div class="spinner"></div><span>加载公告正文...</span></div>';
        setTimeout(() => drawer?.focus(), 20);
        try {
            const payload = await this.fetchJson(`announcement_api.php?action=detail&id=${encodeURIComponent(id)}&content_limit=12000`, '公告正文');
            const item = payload.data || {};
            if (title) title.textContent = item.title || '公告详情';
            const eventLabels = { performance: '业绩披露', capital_operation: '资本运作', ownership: '股权事项', operation: '经营事项', dividend: '分红事项', governance: '公司治理', risk_regulatory: '监管风险', other: '其他事项' };
            const links = [
                item.document_url ? `<a class="btn-sm" href="${escapeAttr(item.document_url)}" target="_blank" rel="noopener noreferrer">查看 PDF 文件</a>` : '',
                item.provider_url ? `<a class="btn-sm" href="${escapeAttr(item.provider_url)}" target="_blank" rel="noopener noreferrer">查看聚合详情</a>` : '',
                `<button type="button" class="btn-sm btn-ai" data-announcement-ai="${escapeAttr(item.id || id)}">${Icons.hot} AI解读</button>`,
                item.event_type === 'dividend' && item.code ? `<button type="button" class="btn-sm" data-announcement-dividend="1" data-code="${escapeAttr(item.code)}" data-name="${escapeAttr(item.name || '')}">查看分红档案</button>` : ''
            ].filter(Boolean).join('');
            const statusText = item.content_status === 'truncated' ? '正文已按长度限制截断' : (item.content_status === 'empty' ? '正文暂不可用，请查看 PDF' : '正文已加载');
            content.innerHTML = `
                <div class="announcement-detail-meta"><span><b>${escapeHTML(item.name || item.code || '-')}</b>${item.code ? ` · ${escapeHTML(item.code)}` : ''}</span><span>${escapeHTML(eventLabels[item.event_type] || '其他事项')}</span><span>${escapeHTML(item.disclosure_date || item.published_at || '日期未知')}</span><span>${escapeHTML(item.provider || payload.source || '公告聚合')}</span></div>
                <div class="announcement-detail-actions">${links}</div>
                <section class="announcement-detail-body"><h4>公告正文</h4>${item.content ? `<pre>${escapeHTML(item.content)}</pre>` : '<p class="placeholder-text">正文暂不可用，请通过 PDF 或聚合详情页核验。</p>'}<p class="announcement-detail-notice">${escapeHTML(statusText)} · 重要性分类只用于降噪，不代表投资方向。</p></section>`;
        } catch (error) {
            content.innerHTML = `<div class="error-msg">${escapeHTML(error.message || '公告正文加载失败')}</div>`;
        }
    },

    closeAnnouncementDetail() {
        const overlay = document.getElementById('announcement-detail-overlay');
        if (!overlay?.classList.contains('open')) return;
        overlay.classList.remove('open');
        overlay.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('announcement-drawer-open');
        this.announcementLastFocused?.focus?.();
        this.announcementLastFocused = null;
    },

    analyzeAnnouncementDetail(id) {
        if (!id) return;
        this.closeAnnouncementDetail();
        AdvisorModule.autoSend(`请调用 fa_get_stock_announcement_detail 读取并解读公告 ${id}。请提取公告主体、核心事项、金额或比例、关键日期、实施条件、主要风险和待核实项；只依据正文陈述事实，不机械标注利好或利空，并附原文链接。`);
    },

    openDividendFromAnnouncement(code, name, trigger) {
        if (!code || typeof DividendModule === 'undefined') return;
        this.closeAnnouncementDetail();
        if (DividendModule.mode !== 'stock') DividendModule.switchMode('stock');
        switchTab('dividend');
        DividendModule.openDetail({ code, name, market: '' }, trigger);
    },

    async analyzeAnnouncementsWithAI() {
        const context = this.current || this.buildRequests().context;
        if (context.mode === 'fund') return;
        if (!this.announcementPayload) await this.loadAnnouncements(1, context);
        const label = context.mode === 'market' ? '近期全市场重要公告' : `${context.name || context.code || '当前股票'}的近期公告`;
        if (context.mode === 'stock') AdvisorModule.setAssetContext('stock', { code: context.code, name: context.name }, '公司公告与事件');
        AdvisorModule.autoSend(`请研究${label}。必须先调用 fa_get_stock_announcements；从中选择最多 3 篇真正影响基本面或风险判断的公告，需要解释具体金额、比例、日期、条件或风险时再调用 fa_get_stock_announcement_detail。请区分公告事实、确定性分类与推断，不输出机械利好/利空标签。`);
    },

    parseAssetQuery(raw) {
        const text = String(raw || '').trim();
        const match = text.match(/(?:SH|SZ|BJ)?\d{6}(?:\.(?:SH|SZ|BJ))?/i);
        return {
            code: match ? match[0] : '',
            name: match ? text.replace(match[0], '').replace(/^[\s,，()（）-]+|[\s,，()（）-]+$/g, '') : text
        };
    },

    buildRequests() {
        const mode = document.getElementById('news-mode')?.value || 'market';
        const raw = document.getElementById('news-query-input')?.value.trim() || '';
        const news = new URLSearchParams({ limit: '30' });
        const sentiment = new URLSearchParams({ action: 'sentiment', limit: '30' });
        let context = { mode, code: '', name: '', keywords: [] };

        if (mode === 'market') {
            const keywords = raw || 'A股,沪指';
            news.set('action', 'market');
            news.set('keywords', keywords);
            sentiment.set('scope', 'market');
            sentiment.set('keywords', keywords);
            context.keywords = keywords.split(/[,，;；]+/).map(item => item.trim()).filter(Boolean).slice(0, 4);
        } else {
            const asset = this.parseAssetQuery(raw);
            if (!asset.code && !asset.name) throw new Error('请输入股票或基金代码/名称');
            news.set('action', 'asset');
            news.set('asset_type', mode);
            sentiment.set('scope', 'asset');
            sentiment.set('asset_type', mode);
            if (asset.code) {
                news.set('code', asset.code);
                sentiment.set('code', asset.code);
            }
            if (asset.name) {
                news.set('name', asset.name);
                sentiment.set('name', asset.name);
            }
            context = { mode, code: asset.code, name: asset.name, keywords: [] };
        }
        return { newsUrl: `news_api.php?${news}`, sentimentUrl: `news_api.php?${sentiment}`, context };
    },

    async fetchJson(url, label = '新闻') {
        const response = await fetch(url);
        let data;
        try {
            data = await response.json();
        } catch (error) {
            throw new Error(`${label}接口返回无效数据（HTTP ${response.status}）`);
        }
        if (!response.ok || !data.success) throw new Error(data.message || `${label}接口请求失败（HTTP ${response.status}）`);
        return data;
    },

    async search() {
        const loading = document.getElementById('news-loading');
        const errorBox = document.getElementById('news-error');
        if (loading) loading.style.display = 'flex';
        if (errorBox) errorBox.style.display = 'none';
        try {
            const request = this.buildRequests();
            const announcementTask = request.context.mode === 'fund' ? null : this.loadAnnouncements(1, request.context);
            // 先完成新闻列表，再请求情绪，确保并行工具场景也能复用同一份新闻缓存。
            const news = await this.fetchJson(request.newsUrl);
            this.renderNews(news, request.context);
            let sentiment = null;
            try {
                sentiment = await this.fetchJson(request.sentimentUrl);
                this.renderSentiment(sentiment.data || {});
            } catch (sentimentError) {
                this.renderSentiment(null);
                if (errorBox) {
                    errorBox.textContent = `新闻已加载；情绪快照暂不可用：${sentimentError.message}`;
                    errorBox.style.display = 'block';
                }
            }
            this.current = {
                ...request.context,
                items: Array.isArray(news.data) ? news.data : [],
                meta: news.meta || {},
                sentiment: sentiment?.data || null,
                announcements: [],
                announcementMeta: {}
            };
            if (announcementTask) {
                const announcementPayload = await announcementTask;
                this.current.announcements = Array.isArray(announcementPayload?.data) ? announcementPayload.data : [];
                this.current.announcementMeta = announcementPayload?.meta || {};
            }
            const mapped = news.meta?.asset || {};
            if (request.context.mode !== 'market') {
                this.current.code = mapped.code || request.context.code;
                this.current.name = mapped.name || request.context.name;
                AdvisorModule.setAssetContext(request.context.mode, mapped, '资讯公告');
            } else {
                APP.advisorContext.source = '市场资讯公告';
                AdvisorModule.updateContext();
            }
            this.loaded = true;
            return this.current;
        } catch (error) {
            if (errorBox) {
                errorBox.textContent = error.message || '新闻查询失败，请稍后重试';
                errorBox.style.display = 'block';
            }
            this.renderNews({ data: [], meta: {} }, { mode: document.getElementById('news-mode')?.value || 'market' });
            this.renderSentiment(null);
            return null;
        } finally {
            if (loading) loading.style.display = 'none';
        }
    },

    renderNews(payload, context) {
        const list = document.getElementById('news-list');
        const title = document.getElementById('news-results-title');
        const meta = document.getElementById('news-results-meta');
        if (!list) return;
        const items = Array.isArray(payload.data) ? payload.data : [];
        const asset = payload.meta?.asset || {};
        const label = context.mode === 'market'
            ? `市场热点 · ${(payload.meta?.keywords || context.keywords || []).join(' / ')}`
            : `${context.mode === 'fund' ? '基金' : '股票'}新闻 · ${asset.name || context.name || asset.code || context.code || ''}`;
        if (title) title.innerHTML = `${Icons.table} ${escapeHTML(label)}`;
        if (meta) meta.textContent = `${items.length} 条 · ${payload.meta?.mapping_status === 'resolved' ? '名称已自动映射 · ' : ''}${payload.source || 'eastmoney_news'}`;
        if (!items.length) {
            list.innerHTML = '<p class="placeholder-text">没有找到足够相关的新闻。基金代码检索结果较少时，可尝试输入基金全名。</p>';
            return;
        }
        list.innerHTML = items.map((item, index) => `
            <a class="news-item" href="${escapeAttr(item.url || '#')}" target="_blank" rel="noopener noreferrer">
                <span class="news-item-rank">${index + 1}</span>
                <span class="news-item-main">
                    <b>${escapeHTML(item.title || '未命名新闻')}</b>
                    <span><em>${escapeHTML(item.source || '未知来源')}</em><time>${escapeHTML(item.published_at || '时间未知')}</time></span>
                </span>
                <span class="news-item-open" aria-hidden="true">↗</span>
            </a>
        `).join('');
    },

    renderSentiment(snapshot) {
        const score = Number(snapshot?.score);
        const label = snapshot?.label || 'neutral';
        const labelMap = { positive: '偏正面', negative: '偏负面', neutral: snapshot ? '中性' : '待查询' };
        const pill = document.getElementById('news-sentiment-label');
        const scoreEl = document.getElementById('news-sentiment-score');
        if (pill) {
            pill.className = `news-sentiment-pill ${label}`;
            pill.textContent = labelMap[label] || '中性';
        }
        if (scoreEl) scoreEl.textContent = Number.isFinite(score) ? `${score > 0 ? '+' : ''}${score.toFixed(3)}` : '--';
        const marker = document.getElementById('news-score-marker');
        if (marker) marker.style.left = `${Number.isFinite(score) ? Math.max(0, Math.min(100, (score + 1) * 50)) : 50}%`;
        const counts = snapshot?.counts || {};
        const set = (id, value) => { const el = document.getElementById(id); if (el) el.textContent = String(value); };
        set('news-positive-count', counts.positive || 0);
        set('news-neutral-count', counts.neutral || 0);
        set('news-negative-count', counts.negative || 0);
        set('news-sample-size', snapshot?.sample_size || 0);
        set('news-source-count', snapshot?.source_count || 0);
        set('news-confidence', snapshot ? `${Math.round((Number(snapshot.confidence) || 0) * 100)}%` : '--');
        set('news-freshness', snapshot?.newest_at ? `最新 ${snapshot.newest_at}` : '尚未加载');
    },

    async analyzeWithAI() {
        let current = this.current;
        if (!current) current = await this.search();
        if (!current) return;
        if (current.mode === 'market') {
            const keywords = current.keywords.join('、') || 'A股、沪指、基金市场';
            AdvisorModule.autoSend(`请研究当前市场重要公告与新闻热点。必须调用 fa_get_stock_announcements（scope=market）、fa_get_market_hot_news 与 fa_get_sentiment_snapshot，新闻关键词为：${keywords}。需要解释公告具体条款时再调用 fa_get_stock_announcement_detail。请把公告事实、媒体新闻、标题情绪弱信号和行情/资金信号分开，不要把重要性或标题情绪当作投资方向；说明数据时间、样本量、来源失败项与不确定性。`);
            return;
        }
        const assetType = current.mode === 'fund' ? '基金' : '股票';
        const label = current.name && current.code ? `${current.name}（${current.code}）` : (current.name || current.code);
        AdvisorModule.setAssetContext(current.mode, { code: current.code, name: current.name }, '资讯公告AI研判');
        if (current.mode === 'stock') {
            AdvisorModule.autoSend(`请研究股票 ${label} 的最新公司公告、媒体新闻与舆情。必须调用 fa_get_stock_announcements、fa_get_asset_news 与 fa_get_sentiment_snapshot；需要解释公告具体条款时再调用 fa_get_stock_announcement_detail，需要判断市场反应时再自主选择行情、K线或资金工具。请分开陈述公告事实、媒体标题、标题情绪弱信号和推断，并说明数据时间、来源失败项与不确定性。`);
        } else {
            AdvisorModule.autoSend(`请研究${assetType} ${label} 的最新新闻与舆情。必须调用 fa_get_asset_news 与 fa_get_sentiment_snapshot；需要判断市场反应时再自主选择行情、K线或资金工具。请区分新闻标题、官方事实、情绪弱信号和推断，说明数据时间、相关性、样本不足与上游失败项。`);
        }
    }
};

// ============================================================
// 雪球洞察模块
// ============================================================
const XueqiuModule = {
    // ── 雪球热度榜 ──
    fetchHotStock(type, size) {
        const loading = document.getElementById('xq-hot-loading');
        const errorDiv = document.getElementById('xq-hot-error');
        const table = document.getElementById('xq-hot-table');
        const tbody = document.getElementById('xq-hot-data');

        if (loading) loading.style.display = 'flex';
        if (errorDiv) errorDiv.style.display = 'none';
        if (table) table.style.display = 'none';

        return fetch(`xueqiu_api.php?action=hot_stock&type=${encodeURIComponent(type)}&size=${encodeURIComponent(size)}`)
            .then(r => r.json())
            .then(data => {
                if (loading) loading.style.display = 'none';
                if (!data.success) {
                    if (errorDiv) {
                        errorDiv.textContent = data.message || '获取雪球热度数据失败';
                        errorDiv.style.display = 'block';
                    }
                    return [];
                }
                const items = data.data || [];
                if (tbody) {
                    tbody.innerHTML = '';
                    items.forEach(item => {
                        const tr = document.createElement('tr');
                        const code = item.code || item.symbol || '';
                        tr.innerHTML = `
                            <td>${escapeHTML(code)}</td>
                            <td>${escapeHTML(item.name || '')}</td>
                            <td style="${colorStyle(item.price)}">${Number(item.price || 0).toFixed(2)}</td>
                            <td class="${colorClass(item.change_pct)}">${formatPct(item.change_pct)}</td>
                            <td>${escapeHTML(item.hot_value || '-')}</td>
                            <td style="${colorStyle(item.hot_increment)}">${Number(item.hot_increment) > 0 ? '+' : ''}${escapeHTML(item.hot_increment || '-')}</td>
                            <td>${escapeHTML(item.rank_change || '-')}</td>
                            <td><button class="btn-sm xq-query-btn" data-code="${escapeHTML(code)}">查询</button></td>
                        `;
                        tbody.appendChild(tr);
                    });
                }
                if (table) table.style.display = 'table';

                // 绑定查询按钮
                tbody?.querySelectorAll('.xq-query-btn').forEach(btn => {
                    btn.addEventListener('click', () => {
                        submitStockQuery(btn.dataset.code, {source: 'xueqiu'});
                    });
                });

                return items;
            })
            .catch(err => {
                if (loading) loading.style.display = 'none';
                if (errorDiv) {
                    errorDiv.textContent = '网络错误: ' + err.message;
                    errorDiv.style.display = 'block';
                }
                return [];
            });
    },

    // ── 条件选股 ──
    fetchScreener(orderBy, market, size) {
        const loading = document.getElementById('xq-screener-loading');
        const errorDiv = document.getElementById('xq-screener-error');
        const table = document.getElementById('xq-screener-table');
        const tbody = document.getElementById('xq-screener-data');

        if (loading) loading.style.display = 'flex';
        if (errorDiv) errorDiv.style.display = 'none';
        if (table) table.style.display = 'none';

        return fetch(`xueqiu_api.php?action=screener&order_by=${encodeURIComponent(orderBy)}&market=${encodeURIComponent(market)}&size=${encodeURIComponent(size)}&order=desc`)
            .then(r => r.json())
            .then(data => {
                if (loading) loading.style.display = 'none';
                if (!data.success) {
                    if (errorDiv) {
                        errorDiv.textContent = data.message || '获取条件选股数据失败';
                        errorDiv.style.display = 'block';
                    }
                    return [];
                }
                const items = (data.data && data.data.data) || data.data || [];
                if (tbody) {
                    tbody.innerHTML = '';
                    items.forEach(item => {
                        const tr = document.createElement('tr');
                        const code = item.code || item.symbol || '';
                        tr.innerHTML = `
                            <td>${escapeHTML(code)}</td>
                            <td>${escapeHTML(item.name || '')}</td>
                            <td style="${colorStyle(item.price)}">${Number(item.price || 0).toFixed(2)}</td>
                            <td class="${colorClass(item.change_pct)}">${formatPct(item.change_pct)}</td>
                            <td>${item.turnover_rate ? Number(item.turnover_rate).toFixed(2) + '%' : '-'}</td>
                            <td>${escapeHTML(item.volume_ratio || '-')}</td>
                            <td>${item.pe_ttm ? Number(item.pe_ttm).toFixed(1) : '-'}</td>
                            <td>${item.pb ? Number(item.pb).toFixed(2) : '-'}</td>
                            <td>${item.roe_ttm ? Number(item.roe_ttm).toFixed(2) + '%' : '-'}</td>
                            <td>${item.dividend_yield ? Number(item.dividend_yield).toFixed(2) + '%' : '-'}</td>
                            <td><button class="btn-sm xq-query-btn" data-code="${escapeHTML(code)}">查询</button></td>
                        `;
                        tbody.appendChild(tr);
                    });
                }
                if (table) table.style.display = 'table';

                tbody?.querySelectorAll('.xq-query-btn').forEach(btn => {
                    btn.addEventListener('click', () => {
                        submitStockQuery(btn.dataset.code, {source: 'xueqiu'});
                    });
                });

                return items;
            })
            .catch(err => {
                if (loading) loading.style.display = 'none';
                if (errorDiv) {
                    errorDiv.textContent = '网络错误: ' + err.message;
                    errorDiv.style.display = 'block';
                }
                return [];
            });
    },

    // ── 雪球动态 ──
    fetchFundx() {
        const loading = document.getElementById('xq-fundx-loading');
        const errorDiv = document.getElementById('xq-fundx-error');
        const list = document.getElementById('xq-fundx-list');

        if (loading) loading.style.display = 'flex';
        if (errorDiv) errorDiv.style.display = 'none';

        return fetch('xueqiu_api.php?action=fundx&page=1')
            .then(r => r.json())
            .then(data => {
                if (loading) loading.style.display = 'none';
                if (!data.success) {
                    if (errorDiv) {
                        errorDiv.textContent = data.message || '获取雪球动态失败';
                        errorDiv.style.display = 'block';
                    }
                    return [];
                }
                const items = (data.data && data.data.data) || data.data || [];
                if (list) {
                    list.innerHTML = '';
                    if (items.length === 0) {
                        list.innerHTML = '<p class="placeholder-text">暂无动态数据</p>';
                    }
                    items.forEach(item => {
                        const displayTitle = item.title || (item.description && item.description.substring(0, 60)) || '';
                        const card = document.createElement('div');
                        card.className = 'xq-fundx-item';
                        card.innerHTML = `
                            <div class="xq-fundx-header">
                                <span class="xq-fundx-author">${escapeHTML(item.author_name || '')}</span>
                                <span class="xq-fundx-time">${escapeHTML(item.created_at || '')}</span>
                            </div>
                            <div class="xq-fundx-title">${escapeHTML(displayTitle)}</div>
                            ${item.description ? '<div class="xq-fundx-desc">' + escapeHTML(item.description).substring(0, 200) + '</div>' : ''}
                            <div class="xq-fundx-stats">
                                <span>点赞 ${Number(item.like_count || 0)}</span>
                                <span>评论 ${Number(item.reply_count || 0)}</span>
                                <span>转发 ${Number(item.retweet_count || 0)}</span>
                                <span>收藏 ${Number(item.fav_count || 0)}</span>
                                <span>浏览 ${Number(item.view_count || 0)}</span>
                            </div>
                        `;
                        list.appendChild(card);
                    });
                }
                return items;
            })
            .catch(err => {
                if (loading) loading.style.display = 'none';
                if (errorDiv) {
                    errorDiv.textContent = '网络错误: ' + err.message;
                    errorDiv.style.display = 'block';
                }
                return [];
            });
    },


    // ── 热度AI分析 ──
    hotSuperQuery() {
        const type = document.getElementById('xq-hot-type')?.value || '10';
        this.fetchHotStock(type, 20).then(items => {
            if (!items || items.length === 0) return;
            let prompt = '请分析以下雪球热度榜数据，从热度趋势、资金面情绪、市场关注度角度给出研判：\n\n';
            prompt += '代码,名称,最新价,涨跌幅,热度值,热度变化\n';
            items.forEach(item => {
                prompt += `${item.code || item.symbol},${item.name},${item.price},${item.change_pct}%,${item.hot_value},${item.hot_increment}\n`;
            });
            prompt += '\n请关注：1) 热度值与涨跌幅的背离 2) 热度增量异常的个股 3) 整体市场情绪判断';
            APP.advisorContext.source = '雪球热度AI分析';
            AdvisorModule.send(prompt);
        });
    },

    // ── 条件选股AI分析 ──
    screenerAIQuery() {
        const orderBy = document.getElementById('xq-screener-order')?.value || 'percent';
        const market = document.getElementById('xq-screener-market')?.value || 'CN';
        this.fetchScreener(orderBy, market, 20).then(items => {
            if (!items || items.length === 0) return;
            let prompt = '请分析以下雪球条件选股数据，从技术面、估值、热度等角度给出筛选建议：\n\n';
            prompt += '代码,名称,最新价,涨跌幅,换手率,量比,PE_TTM,PB,ROE,股息率\n';
            items.forEach(item => {
                prompt += `${item.code || item.symbol},${item.name},${item.price},${item.change_pct}%,${item.turnover_rate},${item.volume_ratio},${item.pe_ttm},${item.pb},${item.roe_ttm},${item.dividend_yield}\n`;
            });
            prompt += '\n请关注：1) 估值与成长性匹配度 2) 技术面强势信号 3) 量价配合情况 4) 风险提示';
            APP.advisorContext.source = '雪球选股AI分析';
            AdvisorModule.send(prompt);
        });
    }
};

// StrategyModule 已迁移到 strategy_pool.js，主脚本只负责初始化。

function switchTab(tabName) {
    let activeTab = null;
    document.querySelectorAll('.nav-tab').forEach(t => {
        const isActive = t.dataset.tab === tabName;
        t.classList.toggle('active', isActive);
        if (isActive) activeTab = t;
    });
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.toggle('active', p.id === 'panel-' + tabName));
    if (typeof DividendModule !== 'undefined') DividendModule.onTabChange(tabName);
    if (typeof NewsModule !== 'undefined') NewsModule.onTabChange(tabName);

    // 切换到 AI Tab 时，同步消息历史到 #chat-container
    if (tabName === 'ai' && APP.chatContainer) {
        AIModule.ensureDisplayView(APP.chatContainer);
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

    if (typeof AdvisorModule !== 'undefined' && AdvisorModule._els?.panel) {
        AdvisorModule.updateContext();
    }

    // 通知核心层：页面切换（数据状态条按页聚合、自选中心感知激活）
    if (window.AppBus) {
        window.AppBus.emit('tab:changed', { tab: tabName });
    }
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
    DividendModule.init();

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
    AssetPulseModule.init();

    // 创建会话
    fetch('create_session.php', { method: 'POST' })
        .then(r => r.json())
        .then(d => { if (d.session) APP.currentSessionId = d.session.id; })
        .catch(() => {});

    // 初始化图表
    ChartModule.init();
    StockSearchModule.init();

    // Tab切换
    document.querySelectorAll('.nav-tab').forEach(tab => {
        tab.addEventListener('click', () => {
            const tabName = tab.dataset.tab;
            // AI顾问 Tab 点击时，改为打开顾问面板
            if (tabName === 'ai') {
                if (!APP.advisorExpanded) AdvisorModule.open();
                return;
            }
            if (APP.advisorExpanded) AdvisorModule.leaveExpandedPage();
            if (APP.advisorOpen) {
                AdvisorModule.close();
            }
            switchTab(tabName);
            // 更新顾问面板上下文
            if (typeof AdvisorModule !== 'undefined') AdvisorModule.updateContext();
        });
    });

    // 自选星标按钮 → 统一快捷抽屉（绑定由 WatchCenterUI.wireDrawerControls 完成）
    // 旧自选股侧栏、旧添加输入、旧计数逻辑已由自选中心接管。

    // 加自选按钮（股票工作台）→ 写入统一自选存储
    document.getElementById('add-watchlist-btn')?.addEventListener('click', () => {
        const code = APP.currentStockCode || StockSearchModule.resolvedQuery();
        if (code && window.WatchCenter) {
            window.WatchCenter.addItem('stock', code, APP.currentStockName || code);
        }
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
        const code = StockSearchModule.resolvedQuery();
        if (!code) { alert('请先输入股票代码、名称或拼音关键词'); return; }
        APP.queryWithAI = true;
        stockForm.dispatchEvent(new Event('submit'));
    });

    stockForm.addEventListener('submit', function(e) {
        e.preventDefault();
        const query = StockSearchModule.resolvedQuery();
        const selectedStockName = StockSearchModule.resolvedName();
        if (!query) return;
        const frequency = document.getElementById('frequency').value;
        const count = document.getElementById('count').value;
        const end_date = document.getElementById('end_date').value;
        APP.stockQueryController?.abort();
        APP.stockQueryController = new AbortController();
        const signal = APP.stockQueryController.signal;
        const requestId = ++APP.stockQueryRequestId;
        const queryWithAI = APP.queryWithAI;
        APP.queryWithAI = false;

        loading.style.display = 'flex';
        errorDiv.style.display = 'none';
        stockTable.style.display = 'none';
        const source = document.getElementById('data-source')?.value || 'auto';
        const url = `api.php?code=${encodeURIComponent(query)}&frequency=${encodeURIComponent(frequency)}&count=${encodeURIComponent(count)}&end_date=${encodeURIComponent(end_date)}&source=${encodeURIComponent(source)}`;

        fetch(url, { signal })
            .then(r => r.json())
            .then(data => {
                if (requestId !== APP.stockQueryRequestId) return;
                loading.style.display = 'none';
                if (!data.success) {
                    StockSearchModule.showCandidates(data.candidates || data.meta?.candidates || []);
                    throw new Error(data.message || '获取数据失败');
                }

                const resolvedStock = data.stock || {};
                const code = resolvedStock.symbol || resolvedStock.code || query;
                const stockName = resolvedStock.name || selectedStockName || '';
                APP.currentStockCode = code;
                APP.currentStockName = stockName;
                StockSearchModule.syncResolved({ ...resolvedStock, symbol: code, name: stockName });
                if (typeof AdvisorModule !== 'undefined') {
                    AdvisorModule.setAssetContext('stock', { code, name: stockName }, '股票查询');
                }
                AssetPulseModule.focus('stock', { code, name: stockName }, { target: 'stock-asset-pulse' });

                stockData.innerHTML = '';
                if (data.data && data.data.length > 0) {
                    // 更新图表
                    ChartModule.updateData(data.data);
                    const stockLabel = stockName ? `${stockName} · ${code}` : code;
                    document.getElementById('chart-title').innerHTML = `${Icons.chart} ${escapeHTML(stockLabel)} K线图表`;

                    // 更新加自选按钮
                    const starBtn = document.getElementById('add-watchlist-btn');
                    if (starBtn) {
                        starBtn.innerHTML = WatchlistModule.has(code) ? `${Icons.star} 已自选` : `${Icons.star} 加自选`;
                    }

                    // 更新表格
                    let aiStr = `${stockName ? `股票名称: ${stockName}\n` : ''}股票代码: ${code}\n频率: ${frequency}\n数据条数: ${count}\n\n`;
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
                    QuoteModule.fetch(code).then(q => {
                        if (requestId === APP.stockQueryRequestId) QuoteModule.render(q);
                    });

                    // 根据标志决定是否触发AI分析
                    if (queryWithAI) {
                        // 先获取资金流向数据，再拼接AI提示词
                        FlowModule.fetch(code).then(flowData => {
                            if (requestId !== APP.stockQueryRequestId) return null;
                            FlowModule.render(flowData);
                            return flowData;
                        }).catch(e => {
                            console.warn('获取资金流向失败，AI分析将不含资金数据:', e);
                            if (requestId === APP.stockQueryRequestId) FlowModule.renderError(e);
                            return null;
                        }).then(flowData => {
                            if (requestId !== APP.stockQueryRequestId) return;
                            // 将资金流向数据拼接到AI分析提示词
                            if (flowData && flowData.length > 0) {
                                const recentFlow = flowData.slice(-10);
                                aiStr += "\n\n资金流向数据（近" + recentFlow.length + "日）：\n";
                                if (flowData.flowMeta?.partial) {
                                    aiStr += "注意：历史资金端点暂不可用，以下仅为当日盘中最新累计快照，不代表完整历史序列。\n";
                                }
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
                        FlowModule.fetch(code).then(f => {
                            if (requestId === APP.stockQueryRequestId) FlowModule.render(f);
                        }).catch(e => {
                            if (requestId === APP.stockQueryRequestId) FlowModule.renderError(e);
                        });
                    }
                } else {
                    errorDiv.textContent = '没有查询到数据';
                    errorDiv.style.display = 'block';
                }
            })
            .catch(error => {
                if (error.name === 'AbortError' || requestId !== APP.stockQueryRequestId) return;
                loading.style.display = 'none';
                errorDiv.textContent = error.message;
                errorDiv.style.display = 'block';
            })
            .finally(() => {
                if (requestId === APP.stockQueryRequestId) APP.stockQueryController = null;
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
                            <td class="hot-action-cell">
                                <div class="table-action-group">
                                    <button class="btn-quick-query" data-code="${stock.dm}">查询</button>
                                    <button class="btn-hot-ai" data-code="${stock.dm}"><span class="ui-icon" aria-hidden="true"><svg><use href="#icon-ai"></use></svg></span> 一键AI分析</button>
                                </div>
                            </td>
                        `;
                        hotStocksData.appendChild(tr);
                    });

                    hotStocksTable.style.display = 'table';

                    // 热门股票行入场动画
                    AnimationManager.animateHotRows();

                    const submitHotStockQuery = (code, withAI = false) => {
                        document.getElementById('code').value = code;
                        document.getElementById('frequency').value = '1d';
                        document.getElementById('count').value = '60';
                        APP.queryWithAI = withAI;
                        stockForm.dispatchEvent(new Event('submit'));
                    };

                    document.querySelectorAll('.btn-quick-query').forEach(btn => {
                        btn.addEventListener('click', function(e) {
                            e.stopPropagation();
                            submitHotStockQuery(this.getAttribute('data-code'), false);
                        });
                    });
                    document.querySelectorAll('.btn-hot-ai').forEach(btn => {
                        btn.addEventListener('click', function(e) {
                            e.stopPropagation();
                            submitHotStockQuery(this.getAttribute('data-code'), true);
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
    // Phase 1.4: 增加最大并发限制与取消机制
    let superQueryAbortController = null;
    document.getElementById('super-query-btn')?.addEventListener('click', async function() {
        const rows = document.querySelectorAll('#hot-stocks-data tr');
        if (!rows.length) { alert('没有可查询的股票数据'); return; }

        // 如果上一次查询还在进行中，先取消
        if (superQueryAbortController) {
            superQueryAbortController.abort();
            superQueryAbortController = null;
        }
        superQueryAbortController = new AbortController();
        const signal = superQueryAbortController.signal;

        const maxStocks = APP.config.maxQueryStocks || rows.length;
        const stockRows = Array.from(rows).slice(0, maxStocks);
        const total = stockRows.length;
        const loadingEl = document.getElementById('super-query-loading');
        loadingEl.style.display = 'block';
        APP.allStocksData = {};

        const maxConcurrent = Math.max(1, Math.min(APP.config.superQueryMaxConcurrent || 3, 3)); // 最大并发请求数，避免服务端请求风暴
        const batchSize = maxConcurrent;
        for (let i = 0; i < total; i += batchSize) {
            if (signal.aborted) break;
            const batch = stockRows.slice(i, Math.min(i + batchSize, total));
            const tasks = batch.map(row => {
                const code = row.cells[0]?.textContent.trim();
                const name = row.cells[1]?.textContent.trim();
                if (!code) return Promise.resolve();
                return fetch(`api.php?code=${encodeURIComponent(code)}&frequency=1d&count=60&end_date=`, { signal })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success && data.data?.length > 0) {
                            APP.allStocksData[code] = { name, data: data.data };
                            row.classList.add('details-trigger');
                            row.setAttribute('data-code', code);
                            row.addEventListener('click', () => showStockModal(code));
                        }
                    })
                    .catch(err => {
                        if (err.name === 'AbortError') return;
                    });
            });
            await Promise.all(tasks);
            if (signal.aborted) break;
            loadingEl.innerHTML = `已加载 ${Object.keys(APP.allStocksData).length}/${total} 只股票`;
            await new Promise(r => setTimeout(r, 100));
        }

        superQueryAbortController = null;
        loadingEl.style.display = 'none';
        if (Object.keys(APP.allStocksData).length === 0) return;
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

    // === 自选中心（替代旧实时看板 + 旧自选侧栏 + 旧基金自选卡） ===
    if (window.WatchCenterUI && typeof window.WatchCenterUI.init === 'function') {
        window.WatchCenterUI.init();
    }
    if (window.DataStatus && typeof window.DataStatus.init === 'function') {
        window.DataStatus.init();
    }
    // 大盘指数概览条（在 DataStatus 之后初始化，保证首个请求被状态条捕获）
    if (window.MarketOverview && typeof window.MarketOverview.init === 'function') {
        window.MarketOverview.init();
    }
    // 自选中心导航：点击项目跳转对应工作台并自动查询
    if (window.AppBus) {
        window.AppBus.on('watch-center:navigate', function (ev) {
            if (!ev || !ev.code) return;
            if (ev.type === 'fund') {
                switchTab('fund');
                if (typeof FundModule !== 'undefined' && FundModule.openDetail) {
                    FundModule.openDetail(ev.code, ev.name || ev.code);
                }
            } else {
                submitStockQuery(ev.code, { withAI: false });
            }
        });
        window.AppBus.on('watch-center:goto-page', function () { switchTab('realtime'); });
        // 大盘概览条：点击指数 → 行情工作台自动查询
        window.AppBus.on('market-overview:navigate', function (ev) {
            if (ev && ev.code) submitStockQuery(ev.code, { withAI: false });
        });
        // 自选变更时同步股票工作台星标状态
        window.AppBus.on('watch-center:changed', function () {
            const starBtn = document.getElementById('add-watchlist-btn');
            if (starBtn && APP.currentStockCode) {
                starBtn.innerHTML = WatchlistModule.has(APP.currentStockCode) ? `${Icons.star} 已自选` : `${Icons.star} 加自选`;
            }
        });
    }

    // === 板块资金 ===
    document.getElementById('sector-query-btn')?.addEventListener('click', () => SectorModule.query());

    // === 策略池 ===
    if (window.StrategyModule && typeof window.StrategyModule.init === 'function') {
        window.StrategyModule.init();
    }

    // === 雪球洞察 ===
    document.getElementById('xq-hot-query-btn')?.addEventListener('click', () => {
        const type = document.getElementById('xq-hot-type')?.value || '10';
        const size = document.getElementById('xq-hot-size')?.value || 20;
        XueqiuModule.fetchHotStock(type, parseInt(size));
    });
    document.getElementById('xq-hot-super-btn')?.addEventListener('click', () => XueqiuModule.hotSuperQuery());
    document.getElementById('xq-screener-btn')?.addEventListener('click', () => {
        const orderBy = document.getElementById('xq-screener-order')?.value || 'percent';
        const market = document.getElementById('xq-screener-market')?.value || 'CN';
        const size = document.getElementById('xq-screener-size')?.value || 20;
        XueqiuModule.fetchScreener(orderBy, market, parseInt(size));
    });
    document.getElementById('xq-screener-ai-btn')?.addEventListener('click', () => XueqiuModule.screenerAIQuery());
    document.getElementById('xq-fundx-btn')?.addEventListener('click', () => XueqiuModule.fetchFundx());

    // === 新闻舆情 ===
    NewsModule.init();

    // === 基金分析 ===
    FundModule.init();
    document.getElementById('fund-search-btn')?.addEventListener('click', () => FundModule.search());
    document.getElementById('fund-search-input')?.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') { e.preventDefault(); FundModule.search(); }
    });
    document.getElementById('fund-goto-center')?.addEventListener('click', () => switchTab('realtime'));
    document.getElementById('fund-rank-btn')?.addEventListener('click', () => FundModule.loadRank());
    document.getElementById('fund-rank-type')?.addEventListener('change', () => FundModule.loadRank());
    document.getElementById('fund-rank-period')?.addEventListener('change', () => FundModule.loadRank());
    FundModule.loadRank();

    // === AI聊天 ===
    document.getElementById('send-button')?.addEventListener('click', sendMessage);
    document.getElementById('clear-chat-btn')?.addEventListener('click', () => {
        if (typeof AdvisorModule !== 'undefined') {
            AdvisorModule.clearConversation();
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

