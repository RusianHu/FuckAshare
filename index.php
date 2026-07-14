<?php
require_once __DIR__ . '/lib/AppConfig.php';
$dividendAutoRefreshSeconds = max(300, min(1800, (int)AppConfig::get('dividend.auto_refresh_seconds', 600)));
$fundDividendAutoRefreshSeconds = max(300, min(1800, (int)AppConfig::get('fund_dividend.auto_refresh_seconds', 900)));
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="description" content="FuckAshare - A股智能分析平台：K线图表、技术指标、实时行情、板块资金流向、基金估值与 AI 智能研判。仅供研究，不构成投资建议。">
    <meta name="theme-color" media="(prefers-color-scheme: dark)" content="#0a0e16">
    <meta name="theme-color" media="(prefers-color-scheme: light)" content="#eef1ea">
    <title>FuckAshare - A股智能分析平台</title>
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <link rel="preconnect" href="https://unpkg.com" crossorigin>
    <!-- Microsoft Clarity 站长统计 -->
    <script type="text/javascript">
        (function(c,l,a,r,i,t,y){
            c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};
            t=l.createElement(r);t.async=1;t.src="https://www.clarity.ms/tag/"+i;
            y=l.getElementsByTagName(r)[0];y.parentNode.insertBefore(t,y);
        })(window, document, "clarity", "script", "wh4jqb6fac");
    </script>
    <!-- 主题防闪烁：在CSS渲染前尽早设置data-theme，避免深色默认→用户主题的闪烁 -->
    <script>
        (function() {
            var theme = 'system';
            try {
                var saved = localStorage.getItem('fa_theme');
                if (saved && ['light', 'dark', 'system'].indexOf(saved) !== -1) {
                    theme = saved;
                }
            } catch (e) { /* 隐私模式/存储禁用时保持默认 system */ }
            document.documentElement.setAttribute('data-theme', theme);
            document.documentElement.classList.add('theme-loading');
        })();
    </script>
    <link rel="stylesheet" href="style.css">
    <!-- marked.js Markdown解析 -->
    <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
    <!-- DOMPurify XSS防护 -->
    <script src="https://cdn.jsdelivr.net/npm/dompurify/dist/purify.min.js"></script>
    <!-- Lightweight Charts K线图 -->
    <script src="https://unpkg.com/lightweight-charts@4.1.3/dist/lightweight-charts.standalone.production.js"></script>
    <!-- GSAP 动画库 + ScrollTrigger -->
    <script src="https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/gsap.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/ScrollTrigger.min.js"></script>
</head>
<body>
    <!-- 键盘用户跳转主内容 -->
    <a class="skip-link" href="#main">跳到主内容</a>

    <!-- SVG Icon Sprite -->
    <svg xmlns="http://www.w3.org/2000/svg" style="display:none">
        <defs>
            <symbol id="icon-brand-chart" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="M7 16l4-6 4 3 5-8"/></symbol>
            <symbol id="icon-search" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></symbol>
            <symbol id="icon-chart" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="M7 16l4-6 4 3 5-8"/></symbol>
            <symbol id="icon-table" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="3" rx="2"/><path d="M3 9h18M9 3v18"/></symbol>
            <symbol id="icon-quote" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 3h3v3h-3zM8 3h3v3H8zM5 8h14v12a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V8zM12 12v4"/></symbol>
            <symbol id="icon-flow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v20M2 12h20"/><path d="m7 16 5-5 3 3 5-5"/></symbol>
            <symbol id="icon-hot" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/></symbol>
            <symbol id="icon-sector" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="14" x="2" y="5" rx="2"/><path d="M2 10h20"/></symbol>
            <symbol id="icon-calendar" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/><path d="M8 14h.01M12 14h.01M16 14h.01M8 18h.01M12 18h.01"/></symbol>
            <symbol id="icon-fund" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="14" x="2" y="5" rx="2"/><circle cx="12" cy="12" r="3"/></symbol>
            <symbol id="icon-ai" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3.2 18.8 7v7.8L12 20.8l-6.8-6V7L12 3.2z"/><path d="M8.4 10.2c1.1-1.6 2.5-2.4 4.2-2.1 1.6.2 2.8 1.3 3.3 2.8"/><path d="M15.6 13.8c-1.1 1.6-2.5 2.4-4.2 2.1-1.6-.2-2.8-1.3-3.3-2.8"/><path d="M8 5.6 5.6 3.8"/><path d="M16 18.4l2.4 1.8"/><circle cx="12" cy="12" r="1.8" fill="currentColor" stroke="none"/><path d="M19.2 4.2v2.2M18.1 5.3h2.2"/><path d="M4.8 17.6v2.2M3.7 18.7h2.2"/></symbol>
            <symbol id="icon-star" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></symbol>
            <symbol id="icon-download" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></symbol>
            <symbol id="icon-refresh" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></symbol>
            <symbol id="icon-bolt" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></symbol>
            <symbol id="icon-trash" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></symbol>
            <symbol id="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"/><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/></symbol>
            <symbol id="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></symbol>
            <symbol id="icon-monitor" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="14" x="2" y="3" rx="2"/><path d="M8 21h8M12 17v4"/></symbol>
            <symbol id="icon-close" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></symbol>
            <symbol id="icon-send" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></symbol>
            <symbol id="icon-warning" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" x2="12" y1="9" y2="13"/><circle cx="12" cy="17" r=".5"/></symbol>
            <symbol id="icon-expand" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 3 6 6M9 21 3 15M21 3l-7 7M3 21l7-7"/></symbol>
            <symbol id="icon-layers" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m12 2 9 5-9 5-9-5 9-5z"/><path d="m3 12 9 5 9-5"/><path d="m3 17 9 5 9-5"/></symbol>
            <symbol id="icon-settings" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.38a2 2 0 0 0-.73-2.73l-.15-.09a2 2 0 0 1-1-1.74v-.51a2 2 0 0 1 1-1.72l.15-.1a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></symbol>
        </defs>
    </svg>

    <!-- 主题切换过渡遮罩 -->
    <div class="theme-transition-overlay" id="theme-transition-overlay"></div>

    <!-- 顶部导航栏 -->
    <nav class="top-nav">
        <div class="nav-brand">
            <span class="ui-icon brand-icon" aria-hidden="true"><svg><use href="#icon-brand-chart"></use></svg></span>
            <span class="brand-text">FuckAshare</span>
            <span class="brand-sub">智能分析</span>
        </div>
        <div class="nav-tabs">
            <button class="nav-tab active" data-tab="stock">行情工作台</button>
            <button class="nav-tab" data-tab="realtime">实时看板</button>
            <button class="nav-tab" data-tab="strategy">策略池</button>
            <button class="nav-tab" data-tab="sector">资金与板块</button>
            <button class="nav-tab" data-tab="dividend">分红日历</button>
            <button class="nav-tab" data-tab="xueqiu">雪球洞察</button>
            <button class="nav-tab" data-tab="fund">基金分析</button>
            <button class="nav-tab" data-tab="ai">AI顾问</button>
        </div>
        <div class="nav-actions">
            <!-- 主题切换 -->
            <div class="theme-switcher" id="theme-switcher" title="切换主题">
                <button class="theme-btn theme-btn-light" data-theme="light" title="浅色护眼" aria-label="浅色护眼"><span class="ui-icon" aria-hidden="true"><svg><use href="#icon-sun"></use></svg></span></button>
                <button class="theme-btn theme-btn-dark" data-theme="dark" title="深色" aria-label="深色"><span class="ui-icon" aria-hidden="true"><svg><use href="#icon-moon"></use></svg></span></button>
                <button class="theme-btn theme-btn-system" data-theme="system" title="跟随系统" aria-label="跟随系统"><span class="ui-icon" aria-hidden="true"><svg><use href="#icon-monitor"></use></svg></span></button>
            </div>
            <button id="watchlist-toggle" class="btn-icon" title="自选股" aria-label="自选股">
                <span class="ui-icon" aria-hidden="true"><svg><use href="#icon-star"></use></svg></span>
                <span class="watchlist-count" id="watchlist-count">0</span>
            </button>
        </div>
    </nav>

    <!-- 主内容区域 -->
    <div class="main-wrapper" id="main">
        <!-- 股票行情页 -->
        <div class="tab-panel active" id="panel-stock">
            <div class="stock-layout">
                <!-- 左侧：查询表单 + 数据表 -->
                <div class="stock-left">
                    <div class="card query-card">
                        <div class="card-header">
                            <h3><span class="ui-icon" aria-hidden="true"><svg><use href="#icon-search"></use></svg></span> 股票行情查询</h3>
                        </div>
                        <form id="stockForm" class="query-form-inline" autocomplete="off">
                            <div class="form-row">
                                <div class="form-group form-group-code flex-1">
                                    <input type="text" id="code" name="code" placeholder="股票代码 如: sh000001" aria-label="股票代码" enterkeyhint="search" autocapitalize="off" spellcheck="false" required>
                                </div>
                                <div class="form-group form-group-frequency">
                                    <select id="frequency" name="frequency" aria-label="K线周期">
                                        <option value="1m">1分钟</option>
                                        <option value="5m">5分钟</option>
                                        <option value="15m">15分钟</option>
                                        <option value="30m">30分钟</option>
                                        <option value="60m">60分钟</option>
                                        <option value="1d" selected>日线</option>
                                        <option value="1w">周线</option>
                                        <option value="1M">月线</option>
                                    </select>
                                </div>
                                <div class="form-group form-group-count">
                                    <input type="number" id="count" name="count" min="1" max="500" value="120" placeholder="条数" aria-label="数据条数" inputmode="numeric">
                                </div>
                                <div class="form-group form-group-date">
                                    <input type="date" id="end_date" name="end_date" title="结束日期(可选)" aria-label="结束日期（可选）">
                                </div>
                                <div class="form-group form-group-source">
                                    <select id="data-source" name="source" title="数据源" aria-label="数据源">
                                        <option value="auto">自动</option>
                                        <option value="ashare">Ashare</option>
                                        <option value="eastmoney">东方财富</option>
                                        <option value="xueqiu">雪球</option>
                                    </select>
                                </div>
                                <button type="submit" class="btn-primary form-submit-btn">查询</button>
                                <button type="button" id="ai-analyze-btn" class="btn-primary btn-ai form-ai-btn"><span class="ui-icon" aria-hidden="true"><svg><use href="#icon-ai"></use></svg></span> AI分析</button>
                            </div>
                        </form>
                    </div>

                    <!-- K线图区域 -->
                    <div class="card chart-card">
                        <div class="card-header">
                            <h3 id="chart-title"><span class="ui-icon" aria-hidden="true"><svg><use href="#icon-chart"></use></svg></span> K线图表</h3>
                            <div class="chart-controls">
                                <div class="indicator-toggles">
                                    <label class="toggle-label"><input type="checkbox" id="ind-ma" checked> MA</label>
                                    <label class="toggle-label"><input type="checkbox" id="ind-boll"> BOLL</label>
                                    <label class="toggle-label"><input type="checkbox" id="ind-vol" checked> VOL</label>
                                    <label class="toggle-label"><input type="checkbox" id="ind-macd"> MACD</label>
                                    <label class="toggle-label"><input type="checkbox" id="ind-rsi"> RSI</label>
                                    <label class="toggle-label"><input type="checkbox" id="ind-kdj"> KDJ</label>
                                </div>
                                <button id="add-watchlist-btn" class="btn-sm btn-star" title="加入自选"><span class="ui-icon" aria-hidden="true"><svg><use href="#icon-star"></use></svg></span> 加自选</button>
                            </div>
                        </div>
                        <div id="chart-container" class="chart-wrapper"></div>
                    </div>

                    <!-- 数据表格 -->
                    <div class="card data-card">
                        <div class="card-header">
                            <h3><span class="ui-icon" aria-hidden="true"><svg><use href="#icon-table"></use></svg></span> 数据明细</h3>
                            <button id="export-data-btn" class="btn-sm" style="display:none;"><span class="ui-icon" aria-hidden="true"><svg><use href="#icon-download"></use></svg></span> 导出CSV</button>
                        </div>
                        <div id="loading" style="display: none;" class="loading-spinner">
                            <div class="spinner"></div><span>加载中...</span>
                        </div>
                        <div id="error" class="error-msg" style="display: none;"></div>
                        <div id="data-container" class="data-table-wrapper">
                            <table id="stock-table" style="display: none;">
                                <thead>
                                    <tr>
                                        <th>日期/时间</th>
                                        <th>开盘价</th>
                                        <th>收盘价</th>
                                        <th>最高价</th>
                                        <th>最低价</th>
                                        <th>成交量</th>
                                        <th>涨跌幅</th>
                                    </tr>
                                </thead>
                                <tbody id="stock-data"></tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- 右侧：实时行情信息 + 资金流向 -->
                <div class="stock-right">
                    <div class="card quote-card" id="quote-panel">
                        <div class="card-header"><h3><span class="ui-icon" aria-hidden="true"><svg><use href="#icon-quote"></use></svg></span> 实时行情</h3></div>
                        <div class="quote-content" id="quote-content">
                            <p class="placeholder-text">输入股票代码查询后显示</p>
                        </div>
                    </div>
                    <div class="card flow-card" id="flow-panel">
                        <div class="card-header"><h3><span class="ui-icon" aria-hidden="true"><svg><use href="#icon-flow"></use></svg></span> 资金流向</h3></div>
                        <div class="flow-content" id="flow-content">
                            <p class="placeholder-text">输入股票代码查询后显示</p>
                        </div>
                    </div>
                </div>
            </div>
            <!-- 牢A净流入排名：全宽展示 -->
            <div class="card hot-card">
                <div class="card-header">
                    <h3><span class="ui-icon" aria-hidden="true"><svg><use href="#icon-hot"></use></svg></span> 牢A净流入排名</h3>
                    <div class="hot-actions">
                        <button id="super-query-btn" class="btn-sm btn-accent"><span class="ui-icon" aria-hidden="true"><svg><use href="#icon-bolt"></use></svg></span> 超级查询(60d)</button>
                        <button id="ask-ai-btn" class="btn-sm btn-ai" style="display:none;"><span class="ui-icon" aria-hidden="true"><svg><use href="#icon-ai"></use></svg></span> AI选股</button>
                        <button id="download-query-btn" class="btn-sm" style="display:none;"><span class="ui-icon" aria-hidden="true"><svg><use href="#icon-download"></use></svg></span> 下载</button>
                    </div>
                </div>
                <div id="loading-api" style="display:none;" class="loading-spinner"><div class="spinner"></div><span>获取资金流向数据...</span></div>
                <div id="error-api" class="error-msg" style="display: none;"></div>
                <div id="super-query-loading" style="display: none;" class="query-progress-bar"></div>
                <div id="hot-stocks-container" class="hot-table-wrapper">
                    <table id="hot-stocks-table" style="display: none;">
                        <thead>
                            <tr>
                                <th>代码</th>
                                <th>名称</th>
                                <th>最新价</th>
                                <th>涨跌幅</th>
                                <th>换手率</th>
                                <th>主力净流入</th>
                                <th>超大单</th>
                                <th>大单</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody id="hot-stocks-data"></tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- 实时看板页 -->
        <div class="tab-panel" id="panel-realtime">
            <div class="realtime-layout">
                <div class="card">
                    <div class="card-header">
                        <h3><span class="ui-icon" aria-hidden="true"><svg><use href="#icon-chart"></use></svg></span> 实时行情看板</h3>
                        <div class="realtime-controls">
                            <input type="text" id="realtime-code-input" placeholder="输入代码添加 如: sh600519" aria-label="添加监控股票代码" enterkeyhint="done" autocapitalize="off" spellcheck="false">
                            <button id="realtime-add-btn" class="btn-sm btn-accent">添加</button>
                            <button id="realtime-refresh-btn" class="btn-sm"><span class="ui-icon" aria-hidden="true"><svg><use href="#icon-refresh"></use></svg></span> 刷新</button>
                            <span class="auto-refresh-hint" id="auto-refresh-timer">自动刷新: 30s</span>
                        </div>
                    </div>
                    <div class="realtime-grid" id="realtime-grid">
                        <p class="placeholder-text">点击"添加"按钮输入股票代码，或从自选股添加</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- 策略池页 -->
        <div class="tab-panel" id="panel-strategy">
            <div class="strategy-layout">
                <div class="card strategy-workbench-card">
                    <div class="card-header strategy-header">
                        <div>
                            <h3><span class="ui-icon" aria-hidden="true"><svg><use href="#icon-layers"></use></svg></span> 策略池</h3>
                            <p class="strategy-subtitle">东方财富实时候选池 + 日 K 指标精算</p>
                        </div>
                        <div class="strategy-controls">
                            <button id="strategy-show-all-btn" class="btn-sm" title="显示策略池全部命中"><span class="ui-icon" aria-hidden="true"><svg><use href="#icon-table"></use></svg></span> 全部命中</button>
                            <label class="strategy-control-field">
                                <span>候选数</span>
                                <select id="strategy-candidate-limit">
                                    <option value="50">50</option>
                                    <option value="80" selected>80</option>
                                    <option value="120">120</option>
                                    <option value="160">160</option>
                                </select>
                            </label>
                            <button id="strategy-run-all-btn" class="btn-sm btn-accent"><span class="ui-icon" aria-hidden="true"><svg><use href="#icon-refresh"></use></svg></span> 运行策略池</button>
                            <button id="strategy-pool-btn" class="btn-sm"><span class="ui-icon" aria-hidden="true"><svg><use href="#icon-layers"></use></svg></span> 管理策略池 <span id="strategy-pool-count" class="strategy-count-pill">0/0</span></button>
                        </div>
                    </div>
                    <div id="strategy-status" class="strategy-status">
                        <span>加载策略列表中...</span>
                    </div>
                    <div id="strategy-cards" class="strategy-cards"></div>
                    <div id="strategy-loading" style="display:none;" class="loading-spinner"><div class="spinner"></div><span>正在拉取行情并运行策略...</span></div>
                    <div id="strategy-error" class="error-msg" style="display:none;"></div>
                    <div id="strategy-result-empty" class="strategy-empty">
                        <span class="ui-icon" aria-hidden="true"><svg><use href="#icon-search"></use></svg></span>
                        <p>点击策略卡片查看单策略命中，或运行策略池查看全部命中。</p>
                    </div>
                    <div id="strategy-result" class="strategy-result" style="display:none;">
                        <div class="strategy-result-head">
                            <div>
                                <h3 id="strategy-result-title">策略命中</h3>
                                <p id="strategy-result-meta"></p>
                            </div>
                            <div class="strategy-result-actions">
                                <button id="strategy-result-ai-btn" class="btn-sm btn-ai"><span class="ui-icon" aria-hidden="true"><svg><use href="#icon-ai"></use></svg></span> 交给AI复盘</button>
                            </div>
                        </div>
                        <div class="strategy-table-wrapper">
                            <table id="strategy-table">
                                <thead>
                                    <tr>
                                        <th>代码</th>
                                        <th>名称</th>
                                        <th>价格</th>
                                        <th>涨跌幅</th>
                                        <th>换手率</th>
                                        <th>量比</th>
                                        <th>成交额</th>
                                        <th>评分</th>
                                        <th>所属策略</th>
                                        <th>操作</th>
                                    </tr>
                                </thead>
                                <tbody id="strategy-table-data"></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div id="strategy-pool-modal" class="strategy-modal" style="display:none;">
                <div class="strategy-modal-dialog">
                    <div class="strategy-modal-header">
                        <h3>策略池 <span id="strategy-modal-count">0 / 0</span></h3>
                        <button id="strategy-modal-close" class="modal-close-btn" aria-label="关闭"><span class="ui-icon" aria-hidden="true"><svg><use href="#icon-close"></use></svg></span></button>
                    </div>
                    <div class="strategy-modal-body">
                        <div class="strategy-modal-column">
                            <div class="strategy-modal-tabs">
                                <button class="strategy-source-tab active" data-source="all">全部</button>
                                <button class="strategy-source-tab" data-source="builtin">内置</button>
                            </div>
                            <div id="strategy-available-list" class="strategy-list"></div>
                        </div>
                        <div class="strategy-modal-column">
                            <div class="strategy-selected-title"><span class="ui-icon" aria-hidden="true"><svg><use href="#icon-layers"></use></svg></span> 已选 · 拖动右侧手柄排序</div>
                            <div id="strategy-selected-list" class="strategy-list strategy-selected-list"></div>
                        </div>
                    </div>
                    <div class="strategy-modal-footer">
                        <span>仅策略池中的策略会参与批量运行。</span>
                        <div>
                            <button id="strategy-modal-cancel" class="btn-sm">取消</button>
                            <button id="strategy-modal-save" class="btn-sm btn-accent">确定</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 板块资金页 -->
        <div class="tab-panel" id="panel-sector">
            <div class="sector-layout">
                <div class="card">
                    <div class="card-header">
                        <h3><span class="ui-icon" aria-hidden="true"><svg><use href="#icon-sector"></use></svg></span> 板块资金流向</h3>
                        <div class="sector-controls">
                            <select id="sector-type">
                                <option value="industry">行业板块</option>
                                <option value="concept">概念板块</option>
                                <option value="theme">主题板块</option>
                                <option value="region">地域板块</option>
                            </select>
                            <select id="sector-period">
                                <option value="f62">今日</option>
                                <option value="f164">近5日</option>
                                <option value="f174">近10日</option>
                            </select>
                            <button id="sector-query-btn" class="btn-sm btn-accent">查询</button>
                        </div>
                    </div>
                    <div id="sector-loading" style="display:none;" class="loading-spinner"><div class="spinner"></div><span>加载板块数据...</span></div>
                    <div class="sector-content">
                        <div class="sector-bar-chart" id="sector-bar-chart"></div>
                        <div class="sector-table-wrapper">
                            <table id="sector-table" style="display:none;">
                                <thead>
                                    <tr>
                                        <th>排名</th>
                                        <th>板块名称</th>
                                        <th>涨跌幅</th>
                                        <th>净流入</th>
                                        <th>主力净流入</th>
                                        <th>超大单净流入</th>
                                        <th>大单净流入</th>
                                        <th>换手率</th>
                                    </tr>
                                </thead>
                                <tbody id="sector-data"></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 分红日历页 -->
        <div class="tab-panel" id="panel-dividend">
            <div class="dividend-layout">
                <div class="card dividend-filter-card">
                    <div class="card-header dividend-title-row">
                        <div>
                            <h3><span class="ui-icon" aria-hidden="true"><svg><use href="#icon-calendar"></use></svg></span> 临近分红日历</h3>
                            <p class="dividend-subtitle" data-mode-text="stock">仅展示普通 A 股；本次现金率不是年化收益，除息、税费和价格波动会影响总回报。</p>
                            <p class="dividend-subtitle" data-mode-text="fund" hidden>全市场公募基金分红事件；分红来自基金财产、净值会相应下降，不是额外或无风险收益，税务处理不沿用股票持有期模型。</p>
                        </div>
                        <div class="dividend-title-actions">
                            <div class="dividend-mode-toggle" role="group" aria-label="资产类型">
                                <button type="button" class="dividend-mode-btn active" data-dividend-mode="stock" aria-pressed="true">股票</button>
                                <button type="button" class="dividend-mode-btn" data-dividend-mode="fund" aria-pressed="false">基金</button>
                            </div>
                            <span id="dividend-updated-at" class="data-timestamp">尚未更新</span>
                            <button id="dividend-filter-toggle" class="btn-sm dividend-filter-toggle" type="button" aria-controls="dividend-filter-form" aria-expanded="false">筛选</button>
                            <button id="dividend-refresh-btn" class="btn-sm" type="button"><span class="ui-icon" aria-hidden="true"><svg><use href="#icon-refresh"></use></svg></span> 刷新</button>
                        </div>
                    </div>
                    <form id="dividend-filter-form" class="dividend-filters" autocomplete="off">
                        <div class="dividend-window-tabs" role="group" aria-label="日期窗口">
                            <button type="button" class="dividend-window-btn" data-days="7">7日</button>
                            <button type="button" class="dividend-window-btn active" data-days="14">14日</button>
                            <button type="button" class="dividend-window-btn" data-days="30">30日</button>
                        </div>
                        <label><span>开始日期</span><input id="dividend-start-date" type="date" required></label>
                        <label><span>结束日期</span><input id="dividend-end-date" type="date" required></label>
                        <label data-stock-only><span>市场</span><select id="dividend-market"><option value="all">全部A股</option><option value="sh">沪市</option><option value="sz">深市</option><option value="bj">北交所</option></select></label>
                        <label data-stock-only><span>方案状态</span><select id="dividend-status"><option value="confirmed">仅实施确认</option><option value="all">含未确认方案</option></select></label>
                        <label data-stock-only><span>持有期税档</span><select id="dividend-holding"><option value="within_1m">≤1个月 · 20%</option><option value="1m_to_1y">1个月–1年 · 10%</option><option value="over_1y">超过1年 · 0%</option></select></label>
                        <label data-stock-only><span>最低毛率</span><input id="dividend-min-yield" type="number" min="0" max="100" step="0.1" value="0" inputmode="decimal"></label>
                        <label data-fund-only hidden><span>基金类型</span><select id="dividend-fund-category"><option value="all">全部基金</option><option value="stock">股票型</option><option value="index">指数型</option><option value="mixed">混合型</option><option value="bond">债券型</option><option value="money">货币型</option><option value="fof">FOF</option><option value="qdii">QDII</option><option value="reit">REITs</option><option value="other">其他</option></select></label>
                        <label data-fund-only hidden><span>最低分配比例</span><input id="dividend-min-ratio" type="number" min="0" max="100" step="0.001" value="0" inputmode="decimal"></label>
                        <label data-stock-only><span>排序</span><select id="dividend-sort"><option value="gross_yield">本次毛率</option><option value="net_yield">税后现金率</option><option value="record_date">登记日</option><option value="cash_per_share">每股现金</option></select></label>
                        <label data-fund-only hidden><span>排序</span><select id="dividend-fund-sort"><option value="record_date">登记日</option><option value="distribution_ratio">分配比例</option><option value="cash_per_unit">每份分红</option><option value="pay_date">发放日</option></select></label>
                        <button class="btn-sm btn-accent dividend-query-btn" type="submit">查询</button>
                    </form>
                </div>

                <div id="dividend-summary" class="dividend-summary-grid" aria-live="polite" data-stock-only>
                    <div class="dividend-summary-card"><span>实施事件</span><b id="dividend-summary-confirmed">—</b><small>当前筛选范围</small></div>
                    <div class="dividend-summary-card"><span>3日内登记</span><b id="dividend-summary-soon">—</b><small>含登记日当天</small></div>
                    <div class="dividend-summary-card"><span>最高本次毛率</span><b id="dividend-summary-max">—</b><small>按当前价格快照</small></div>
                    <div class="dividend-summary-card"><span>税后率中位数</span><b id="dividend-summary-median">—</b><small id="dividend-tax-caption">个人短持估算</small></div>
                </div>

                <div id="dividend-fund-summary" class="dividend-summary-grid" aria-live="polite" data-fund-only hidden>
                    <div class="dividend-summary-card"><span>分红事件</span><b id="dividend-fund-summary-count">-</b><small>当前筛选范围</small></div>
                    <div class="dividend-summary-card"><span>3日内登记</span><b id="dividend-fund-summary-soon">-</b><small>含登记日当天</small></div>
                    <div class="dividend-summary-card"><span>最高分配比例</span><b id="dividend-fund-summary-max">-</b><small>安全比例口径</small></div>
                    <div class="dividend-summary-card"><span>比例中位数</span><b id="dividend-fund-summary-median">-</b><small id="dividend-fund-summary-coverage">覆盖 - 条</small></div>
                </div>

                <div class="card dividend-results-card">
                    <div class="card-header dividend-results-head">
                        <div>
                            <h3><span class="ui-icon" aria-hidden="true"><svg><use href="#icon-table"></use></svg></span> 事件清单</h3>
                            <span id="dividend-result-summary" class="dividend-result-summary"></span>
                        </div>
                        <button id="dividend-scan-ai-btn" class="btn-sm btn-ai" type="button"><span class="ui-icon" aria-hidden="true"><svg><use href="#icon-ai"></use></svg></span> AI扫描研判</button>
                    </div>
                    <div id="dividend-alert" class="dividend-alert" role="status" style="display:none;"></div>
                    <div id="dividend-loading" class="dividend-loading" style="display:none;" aria-live="polite">
                        <div class="spinner"></div><span>正在合并分红事件与行情...</span>
                    </div>
                    <div class="dividend-table-wrapper">
                        <table id="dividend-table" style="display:none;">
                            <thead id="dividend-table-head"><tr><th>股票</th><th>登记 / 除息</th><th>每股现金</th><th>参考价</th><th>本次毛率</th><th>税后现金率</th><th>状态</th><th>操作</th></tr></thead>
                            <tbody id="dividend-table-body"></tbody>
                        </table>
                    </div>
                    <div id="dividend-mobile-list" class="dividend-mobile-list"></div>
                    <div id="dividend-empty" class="placeholder-text dividend-empty" style="display:none;">当前条件下没有可展示的分红事件</div>
                    <div id="dividend-pagination" class="dividend-pagination" style="display:none;">
                        <button id="dividend-prev-page" class="btn-sm" type="button">上一页</button>
                        <span id="dividend-page-label">第 1 页</span>
                        <button id="dividend-next-page" class="btn-sm" type="button">下一页</button>
                    </div>
                </div>

                <div class="dividend-note-grid" data-stock-only>
                    <div class="card dividend-note-card"><h4>税务口径</h4><p>个人持有不超过1个月、1个月至1年、超过1年的税率估算分别为20%、10%、0%。税款通常在卖出时按实际持有批次补扣，本页不读取账户持仓。</p></div>
                    <div class="card dividend-note-card"><h4>风险口径</h4><p>除息参考价通常会相应调整。现金分红不是额外的无风险收益；页面不推测缺失的派息日，也不把单次现金率年化。</p></div>
                    <div class="card dividend-note-card"><h4>数据与授权</h4><p>首版事件与行情来自东方财富公开页面接口，并保留可替换 Provider。公开商业化使用前需确认数据展示授权。</p></div>
                </div>
                <div class="dividend-note-grid" data-fund-only hidden>
                    <div class="card dividend-note-card"><h4>分红口径</h4><p>基金分红来自基金财产，除息后单位净值会相应下降，不是额外的或无风险收益。本页统一使用“每份分红”，金额口径为元/份。</p></div>
                    <div class="card dividend-note-card"><h4>分配比例</h4><p>“本次分配比例”= 每份分红 ÷ 除息前单位净值，仅在每份分红与净值均为正、净值日期早于除息日且币种确认时计算；未知币种、缺失净值或历史事件不计算，不补零、不年化。</p></div>
                    <div class="card dividend-note-card"><h4>税务口径</h4><p>基金分红税务处理不沿用股票 20%/10%/0% 持有期模型；具体税负请以最新公告与税务规定为准，本页不做税后估算。</p></div>
                    <div class="card dividend-note-card"><h4>数据与授权</h4><p>基金分红事件来自东方财富公开页面接口（funddataIndex dt=8），类型映射来自 fundcode_search.js，净值为 FundMNFInfo 批量接口；公开商业化使用前需确认数据展示与再分发授权。</p></div>
                </div>
            </div>
        </div>

        <!-- 雪球洞察页 -->
        <div class="tab-panel" id="panel-xueqiu">
            <div class="xueqiu-layout">
                <!-- 雪球热度榜 -->
                <div class="card">
                    <div class="card-header">
                        <h3><span class="ui-icon" aria-hidden="true"><svg><use href="#icon-hot"></use></svg></span> 雪球热度榜</h3>
                        <div class="xueqiu-controls">
                            <select id="xq-hot-type">
                                <option value="12">A股热度</option>
                                <option value="13">港股热度</option>
                                <option value="11">美股热度</option>
                            </select>
                            <select id="xq-hot-size">
                                <option value="10">10条</option>
                                <option value="20" selected>20条</option>
                                <option value="50">50条</option>
                            </select>
                            <button id="xq-hot-query-btn" class="btn-sm btn-accent">查询</button>
                            <button id="xq-hot-super-btn" class="btn-sm btn-ai"><span class="ui-icon" aria-hidden="true"><svg><use href="#icon-ai"></use></svg></span> 热度AI分析</button>
                        </div>
                    </div>
                    <div id="xq-hot-loading" style="display:none;" class="loading-spinner"><div class="spinner"></div><span>获取雪球热度数据...</span></div>
                    <div id="xq-hot-error" class="error-msg" style="display:none;"></div>
                    <div class="xueqiu-table-wrapper">
                        <table id="xq-hot-table" style="display:none;">
                            <thead>
                                <tr>
                                    <th>代码</th>
                                    <th>名称</th>
                                    <th>最新价</th>
                                    <th>涨跌幅</th>
                                    <th>热度值</th>
                                    <th>热度变化</th>
                                    <th>排名变化</th>
                                    <th>操作</th>
                                </tr>
                            </thead>
                            <tbody id="xq-hot-data"></tbody>
                        </table>
                    </div>
                </div>

                <!-- 条件选股 -->
                <div class="card">
                    <div class="card-header">
                        <h3><span class="ui-icon" aria-hidden="true"><svg><use href="#icon-search"></use></svg></span> 雪球条件选股</h3>
                        <div class="xueqiu-controls">
                            <select id="xq-screener-order">
                                <option value="percent">涨跌幅</option>
                                <option value="amount">成交额</option>
                                <option value="turnover_rate">换手率</option>
                                <option value="volume_ratio">量比</option>
                                <option value="pe_ttm">市盈率TTM</option>
                                <option value="pb">市净率</option>
                                <option value="roe_ttm">ROE</option>
                                <option value="dividend_yield">股息率</option>
                                <option value="followers">关注人数</option>
                            </select>
                            <select id="xq-screener-market">
                                <option value="CN">A股</option>
                                <option value="HK">港股</option>
                                <option value="US">美股</option>
                            </select>
                            <select id="xq-screener-size">
                                <option value="10">10条</option>
                                <option value="20" selected>20条</option>
                                <option value="50">50条</option>
                            </select>
                            <button id="xq-screener-btn" class="btn-sm btn-accent">查询</button>
                            <button id="xq-screener-ai-btn" class="btn-sm btn-ai"><span class="ui-icon" aria-hidden="true"><svg><use href="#icon-ai"></use></svg></span> AI选股分析</button>
                        </div>
                    </div>
                    <div id="xq-screener-loading" style="display:none;" class="loading-spinner"><div class="spinner"></div><span>获取条件选股数据...</span></div>
                    <div id="xq-screener-error" class="error-msg" style="display:none;"></div>
                    <div class="xueqiu-table-wrapper">
                        <table id="xq-screener-table" style="display:none;">
                            <thead>
                                <tr>
                                    <th>代码</th>
                                    <th>名称</th>
                                    <th>最新价</th>
                                    <th>涨跌幅</th>
                                    <th>换手率</th>
                                    <th>量比</th>
                                    <th>市盈率</th>
                                    <th>市净率</th>
                                    <th>ROE</th>
                                    <th>股息率</th>
                                    <th>操作</th>
                                </tr>
                            </thead>
                            <tbody id="xq-screener-data"></tbody>
                        </table>
                    </div>
                </div>

                <!-- 雪球动态 -->
                <div class="card">
                    <div class="card-header">
                        <h3><span class="ui-icon" aria-hidden="true"><svg><use href="#icon-quote"></use></svg></span> 雪球动态</h3>
                        <div class="xueqiu-controls">
                            <button id="xq-fundx-btn" class="btn-sm btn-accent">加载动态</button>
                        </div>
                    </div>
                    <div id="xq-fundx-loading" style="display:none;" class="loading-spinner"><div class="spinner"></div><span>获取雪球动态...</span></div>
                    <div id="xq-fundx-error" class="error-msg" style="display:none;"></div>
                    <div class="xueqiu-fundx-list" id="xq-fundx-list">
                        <p class="placeholder-text">点击"加载动态"获取雪球市场资讯</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- 基金分析页 -->
        <div class="tab-panel" id="panel-fund">
            <div class="fund-layout">
                <div class="fund-top-grid">
                    <div class="fund-left-stack">
                        <div class="card fund-search-card">
                            <div class="card-header">
                                <h3><span class="ui-icon" aria-hidden="true"><svg><use href="#icon-search"></use></svg></span> 基金检索</h3>
                                <div class="fund-controls">
                                    <input type="text" id="fund-search-input" placeholder="输入基金代码或名称搜索" aria-label="基金代码或名称" enterkeyhint="search" autocapitalize="off" spellcheck="false">
                                    <button id="fund-search-btn" class="btn-sm btn-accent">搜索</button>
                                </div>
                            </div>
                            <div id="fund-loading" style="display:none;" class="loading-spinner"><div class="spinner"></div><span>搜索基金中...</span></div>
                            <div class="fund-content">
                                <div class="fund-search-results" id="fund-search-results"></div>
                            </div>
                        </div>

                        <!-- 自选基金 -->
                        <div class="card fund-watch-card">
                            <div class="card-header">
                                <h3><span class="ui-icon" aria-hidden="true"><svg><use href="#icon-star"></use></svg></span> 自选基金</h3>
                                <button id="fund-refresh-btn" class="btn-sm"><span class="ui-icon" aria-hidden="true"><svg><use href="#icon-refresh"></use></svg></span> 刷新估值</button>
                            </div>
                            <div class="fund-watchlist" id="fund-watchlist">
                                <p class="placeholder-text">搜索基金后可添加到自选</p>
                            </div>
                        </div>
                    </div>

                    <div class="fund-right-stack">
                        <div class="card fund-detail-card">
                            <div class="card-header">
                                <h3><span class="ui-icon" aria-hidden="true"><svg><use href="#icon-fund"></use></svg></span> 基金详情</h3>
                                <span class="fund-detail-code" id="fund-detail-code">未选择</span>
                            </div>
                            <div id="fund-detail-loading" style="display:none;" class="loading-spinner"><div class="spinner"></div><span>加载基金详情...</span></div>
                            <div class="fund-detail" id="fund-detail">
                                <p class="placeholder-text">从搜索结果、自选或排行中打开基金详情</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card fund-rank-card">
                    <div class="card-header">
                        <h3><span class="ui-icon" aria-hidden="true"><svg><use href="#icon-table"></use></svg></span> 基金排行</h3>
                        <div class="fund-rank-controls">
                            <select id="fund-rank-type" aria-label="基金类型">
                                <option value="all">全部</option>
                                <option value="stock">股票型</option>
                                <option value="mixed">混合型</option>
                                <option value="bond">债券型</option>
                                <option value="index">指数型</option>
                                <option value="qdii">QDII</option>
                                <option value="fof">FOF</option>
                            </select>
                            <select id="fund-rank-period" aria-label="排行周期">
                                <option value="day">日涨幅</option>
                                <option value="week">近1周</option>
                                <option value="month">近1月</option>
                                <option value="quarter">近3月</option>
                                <option value="half_year">近6月</option>
                                <option value="year" selected>近1年</option>
                                <option value="two_year">近2年</option>
                                <option value="three_year">近3年</option>
                                <option value="this_year">今年来</option>
                                <option value="since">成立来</option>
                            </select>
                            <button id="fund-rank-btn" class="btn-sm btn-accent">刷新</button>
                        </div>
                    </div>
                    <div id="fund-rank-loading" style="display:none;" class="loading-spinner"><div class="spinner"></div><span>加载基金排行...</span></div>
                    <div class="fund-rank-summary" id="fund-rank-summary"></div>
                    <div class="fund-rank-table-wrapper">
                        <table id="fund-rank-table" style="display:none;">
                            <thead>
                                <tr>
                                    <th>排名</th>
                                    <th>基金</th>
                                    <th>净值</th>
                                    <th>日涨幅</th>
                                    <th>周期收益</th>
                                    <th>今年来</th>
                                    <th>成立来</th>
                                    <th>操作</th>
                                </tr>
                            </thead>
                            <tbody id="fund-rank-data"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- AI顾问页 -->
        <div class="tab-panel" id="panel-ai">
            <div class="ai-layout">
                <div class="card ai-card">
                    <div class="card-header">
                        <h3><span class="ui-icon" aria-hidden="true"><svg><use href="#icon-ai"></use></svg></span> AI 智能顾问</h3>
                        <button id="clear-chat-btn" class="btn-sm"><span class="ui-icon" aria-hidden="true"><svg><use href="#icon-trash"></use></svg></span> 清空对话</button>
                    </div>
                    <div id="chat-container" class="chat-messages"></div>
                    <div class="chat-input-area">
                        <textarea id="user-input" placeholder="输入您的问题... (Enter发送, Shift+Enter换行)" onkeydown="handleKeyDown(event)" oninput="autoResizeTextarea()"></textarea>
                        <button id="send-button">发送</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 自选股侧边栏 -->
    <div class="watchlist-sidebar" id="watchlist-sidebar">
        <div class="watchlist-header">
            <h3><span class="ui-icon" aria-hidden="true"><svg><use href="#icon-star"></use></svg></span> 自选股</h3>
            <button id="watchlist-close" class="btn-icon-sm" aria-label="关闭自选股"><span class="ui-icon" aria-hidden="true"><svg><use href="#icon-close"></use></svg></span></button>
        </div>
        <div class="watchlist-add">
            <input type="text" id="watchlist-add-input" placeholder="输入代码添加 如: sh600519" aria-label="添加自选股代码" enterkeyhint="done" autocapitalize="off" spellcheck="false">
            <button id="watchlist-add-btn" class="btn-sm btn-accent">添加</button>
        </div>
        <div class="watchlist-items" id="watchlist-items">
            <p class="placeholder-text">暂无自选股</p>
        </div>
    </div>
    <div class="watchlist-overlay" id="watchlist-overlay"></div>

    <!-- AI 顾问 FAB 悬浮按钮 -->
    <button class="ai-advisor-fab" id="ai-advisor-fab" aria-label="打开 AI 顾问" aria-controls="ai-advisor-panel" aria-expanded="false" title="AI 顾问">
        <span class="fab-icon"><span class="ui-icon" aria-hidden="true"><svg><use href="#icon-ai"></use></svg></span></span>
        <span class="fab-badge" id="fab-badge" style="display:none;">0</span>
        <span class="fab-glow"></span>
    </button>

    <!-- AI 顾问弹出面板 -->
    <div class="ai-advisor-panel" id="ai-advisor-panel" role="dialog" aria-label="AI 顾问面板" aria-modal="false" aria-hidden="true">
        <!-- 头部栏 -->
        <div class="advisor-header">
            <div class="advisor-header-left">
                <span class="advisor-avatar"><span class="ui-icon" aria-hidden="true"><svg><use href="#icon-ai"></use></svg></span></span>
                <div class="advisor-identity">
                    <span class="advisor-name">AI 顾问</span>
                    <span class="advisor-status" id="advisor-status">在线 · Beta</span>
                </div>
            </div>
            <div class="advisor-header-right">
                <button class="advisor-header-btn advisor-clear-btn" id="advisor-clear-btn" title="清理历史对话" aria-label="清理 AI 顾问历史对话"><span class="ui-icon" aria-hidden="true"><svg><use href="#icon-trash"></use></svg></span></button>
                <button class="advisor-header-btn" id="advisor-expand-btn" title="展开到完整页" aria-label="展开到完整 AI 顾问页"><span class="ui-icon" aria-hidden="true"><svg><use href="#icon-expand"></use></svg></span></button>
                <button class="advisor-header-btn" id="advisor-close-btn" title="关闭面板" aria-label="关闭 AI 顾问面板"><span class="ui-icon" aria-hidden="true"><svg><use href="#icon-close"></use></svg></span></button>
            </div>
        </div>

        <!-- 上下文提示区 -->
        <div class="advisor-context" id="ai-advisor-context" style="display:none;">
            <span class="context-tag" id="advisor-context-stock"></span>
            <span class="context-tag" id="advisor-context-tab"></span>
        </div>

        <!-- 欢迎区 / 空状态区 -->
        <div class="advisor-welcome" id="ai-advisor-welcome">
            <div class="welcome-text">
                <p class="welcome-title">你好，我可以结合行情、资金流、板块和基金数据帮你快速研判。</p>
                <p class="welcome-sub">我会根据当前模块接入对应上下文，可以直接问我股票、板块或基金的问题。</p>
            </div>
            <div class="advisor-quick-actions" id="ai-advisor-quick-actions">
                <button class="quick-action-btn" data-prompt="帮我分析当前股票的趋势与支撑压力位">
                    <span class="qa-icon"><span class="ui-icon" aria-hidden="true"><svg><use href="#icon-chart"></use></svg></span></span>
                    <span class="qa-text">分析当前股票趋势</span>
                    <span class="qa-arrow">→</span>
                </button>
                <button class="quick-action-btn" data-prompt="帮我结合资金流向判断主力在吸筹还是出货">
                    <span class="qa-icon"><span class="ui-icon" aria-hidden="true"><svg><use href="#icon-flow"></use></svg></span></span>
                    <span class="qa-text">判断主力资金意图</span>
                    <span class="qa-arrow">→</span>
                </button>
                <button class="quick-action-btn" data-prompt="帮我从净流入热榜里筛选短期值得关注的标的">
                    <span class="qa-icon"><span class="ui-icon" aria-hidden="true"><svg><use href="#icon-hot"></use></svg></span></span>
                    <span class="qa-text">从热榜筛选候选标的</span>
                    <span class="qa-arrow">→</span>
                </button>
                <button class="quick-action-btn" data-prompt="帮我结合雪球热度榜和条件选股数据，找出当前市场关注度与基本面共振的标的" data-source="xueqiu">
                    <span class="qa-icon"><span class="ui-icon" aria-hidden="true"><svg><use href="#icon-search"></use></svg></span></span>
                    <span class="qa-text">雪球热度+选股共振</span>
                    <span class="qa-arrow">→</span>
                </button>
            </div>
        </div>

        <!-- 消息滚动区 -->
        <div class="advisor-messages" id="advisor-chat-container"></div>

        <!-- 底部输入区 -->
        <div class="advisor-input-area">
            <div class="advisor-input-box">
                <textarea id="advisor-user-input" placeholder="问我任何股票、板块、基金的问题…" rows="1" aria-label="AI 顾问输入框"></textarea>
                <div class="advisor-context-meter" id="advisor-context-meter" role="meter" aria-label="估算上下文用量" aria-valuemin="0" aria-valuemax="255000" aria-valuenow="0" title="估算上下文: 约0 / 255K">
                    <svg class="advisor-context-ring" viewBox="0 0 20 20" aria-hidden="true">
                        <circle class="context-ring-track" cx="10" cy="10" r="8" pathLength="100"></circle>
                        <circle class="context-ring-value" id="advisor-context-ring" cx="10" cy="10" r="8" pathLength="100"></circle>
                    </svg>
                    <span class="advisor-context-size" id="advisor-context-size">约0 / 255K</span>
                </div>
                <button class="advisor-send-btn" id="advisor-send-btn" title="发送" aria-label="发送消息" disabled>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"></line><polygon points="22 2 15 22 11 13 2 9 22 2"></polygon></svg>
                </button>
            </div>
            <div class="advisor-disclaimer">AI 生成内容仅供参考，不构成投资建议</div>
        </div>
    </div>

    <!-- AI 顾问移动端遮罩 -->
    <div class="ai-advisor-backdrop" id="ai-advisor-backdrop" aria-hidden="true"></div>

    <!-- 股票详情弹窗 -->
    <div class="dividend-detail-overlay" id="dividend-detail-overlay" aria-hidden="true">
        <aside class="dividend-detail-drawer" role="dialog" aria-modal="true" aria-labelledby="dividend-detail-title" tabindex="-1">
            <div class="dividend-detail-header">
                <div><span class="dividend-detail-eyebrow">分红档案</span><h3 id="dividend-detail-title">股票分红详情</h3></div>
                <button id="dividend-detail-close" class="modal-close-btn" type="button" aria-label="关闭分红详情">×</button>
            </div>
            <div id="dividend-detail-content" class="dividend-detail-content"><div class="loading-spinner"><div class="spinner"></div><span>加载分红历史...</span></div></div>
        </aside>
    </div>

    <div class="modal-overlay" id="stock-modal-overlay" style="display:none;">
        <div class="modal-dialog" id="stock-modal">
            <div class="modal-header-bar">
                <h3 id="modal-stock-title">股票详情</h3>
                <button class="modal-close-btn" id="modal-close-btn" aria-label="关闭"><span class="ui-icon" aria-hidden="true"><svg><use href="#icon-close"></use></svg></span></button>
            </div>
            <div class="modal-body-content" id="modal-body-content"></div>
        </div>
    </div>

    <footer class="site-footer">
        <p>by <a href="https://yanshanlaosiji.top" target="_blank">雁山老司机</a> · <span class="ui-icon" aria-hidden="true"><svg><use href="#icon-warning"></use></svg></span> 仅供娱乐研究，不构成投资建议</p>
    </footer>

    <script>
        window.FA_RUNTIME_CONFIG = Object.freeze({
            dividendAutoRefreshSeconds: <?= json_encode($dividendAutoRefreshSeconds) ?>,
            fundDividendAutoRefreshSeconds: <?= json_encode($fundDividendAutoRefreshSeconds) ?>
        });
    </script>
    <script src="strategy_pool.js"></script>
    <script src="main.js"></script>
</body>
</html>
