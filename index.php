<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>FuckAshare - A股智能分析平台</title>
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
    <!-- 主题切换过渡遮罩 -->
    <div class="theme-transition-overlay" id="theme-transition-overlay"></div>

    <!-- 顶部导航栏 -->
    <nav class="top-nav">
        <div class="nav-brand">
            <span class="brand-icon">📈</span>
            <span class="brand-text">FuckAshare</span>
            <span class="brand-sub">智能分析</span>
        </div>
        <div class="nav-tabs">
            <button class="nav-tab active" data-tab="stock">股票行情</button>
            <button class="nav-tab" data-tab="realtime">实时看板</button>
            <button class="nav-tab" data-tab="sector">板块资金</button>
            <button class="nav-tab" data-tab="fund">基金分析</button>
            <button class="nav-tab" data-tab="ai">AI顾问</button>
        </div>
        <div class="nav-actions">
            <!-- 主题切换 -->
            <div class="theme-switcher" id="theme-switcher" title="切换主题">
                <button class="theme-btn theme-btn-light" data-theme="light" title="浅色护眼">☀️</button>
                <button class="theme-btn theme-btn-dark" data-theme="dark" title="深色">🌙</button>
                <button class="theme-btn theme-btn-system" data-theme="system" title="跟随系统">💻</button>
            </div>
            <button id="watchlist-toggle" class="btn-icon" title="自选股">
                <span>⭐</span>
                <span class="watchlist-count" id="watchlist-count">0</span>
            </button>
        </div>
    </nav>

    <!-- 主内容区域 -->
    <div class="main-wrapper">
        <!-- 股票行情页 -->
        <div class="tab-panel active" id="panel-stock">
            <div class="stock-layout">
                <!-- 左侧：查询表单 + 数据表 -->
                <div class="stock-left">
                    <div class="card query-card">
                        <div class="card-header">
                            <h3>🔍 股票行情查询</h3>
                        </div>
                        <form id="stockForm" class="query-form-inline">
                            <div class="form-row">
                                <div class="form-group form-group-code flex-1">
                                    <input type="text" id="code" name="code" placeholder="股票代码 如: sh000001" required>
                                </div>
                                <div class="form-group form-group-frequency">
                                    <select id="frequency" name="frequency">
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
                                    <input type="number" id="count" name="count" min="1" max="500" value="120" placeholder="条数">
                                </div>
                                <div class="form-group form-group-date">
                                    <input type="date" id="end_date" name="end_date" title="结束日期(可选)">
                                </div>
                                <button type="submit" class="btn-primary form-submit-btn">查询</button>
                                <button type="button" id="ai-analyze-btn" class="btn-primary btn-ai form-ai-btn">🤖 AI分析</button>
                            </div>
                        </form>
                    </div>

                    <!-- K线图区域 -->
                    <div class="card chart-card">
                        <div class="card-header">
                            <h3 id="chart-title">📊 K线图表</h3>
                            <div class="chart-controls">
                                <div class="indicator-toggles">
                                    <label class="toggle-label"><input type="checkbox" id="ind-ma" checked> MA</label>
                                    <label class="toggle-label"><input type="checkbox" id="ind-boll"> BOLL</label>
                                    <label class="toggle-label"><input type="checkbox" id="ind-vol" checked> VOL</label>
                                    <label class="toggle-label"><input type="checkbox" id="ind-macd"> MACD</label>
                                    <label class="toggle-label"><input type="checkbox" id="ind-rsi"> RSI</label>
                                    <label class="toggle-label"><input type="checkbox" id="ind-kdj"> KDJ</label>
                                </div>
                                <button id="add-watchlist-btn" class="btn-sm btn-star" title="加入自选">⭐ 加自选</button>
                            </div>
                        </div>
                        <div id="chart-container" class="chart-wrapper"></div>
                    </div>

                    <!-- 数据表格 -->
                    <div class="card data-card">
                        <div class="card-header">
                            <h3>📋 数据明细</h3>
                            <button id="export-data-btn" class="btn-sm" style="display:none;">📥 导出CSV</button>
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
                        <div class="card-header"><h3>💹 实时行情</h3></div>
                        <div class="quote-content" id="quote-content">
                            <p class="placeholder-text">输入股票代码查询后显示</p>
                        </div>
                    </div>
                    <div class="card flow-card" id="flow-panel">
                        <div class="card-header"><h3>💰 资金流向</h3></div>
                        <div class="flow-content" id="flow-content">
                            <p class="placeholder-text">输入股票代码查询后显示</p>
                        </div>
                    </div>
                </div>
            </div>
            <!-- 牢A净流入排名：全宽展示 -->
            <div class="card hot-card">
                <div class="card-header">
                    <h3>🔥 牢A净流入排名</h3>
                    <div class="hot-actions">
                        <button id="super-query-btn" class="btn-sm btn-accent">⚡超级查询(60d)</button>
                        <button id="ask-ai-btn" class="btn-sm btn-ai" style="display:none;">🤖 AI选股</button>
                        <button id="download-query-btn" class="btn-sm" style="display:none;">📥 下载</button>
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
                        <h3>📊 实时行情看板</h3>
                        <div class="realtime-controls">
                            <input type="text" id="realtime-code-input" placeholder="输入代码添加 如: sh600519">
                            <button id="realtime-add-btn" class="btn-sm btn-accent">添加</button>
                            <button id="realtime-refresh-btn" class="btn-sm">🔄 刷新</button>
                            <span class="auto-refresh-hint" id="auto-refresh-timer">自动刷新: 30s</span>
                        </div>
                    </div>
                    <div class="realtime-grid" id="realtime-grid">
                        <p class="placeholder-text">点击"添加"按钮输入股票代码，或从自选股添加</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- 板块资金页 -->
        <div class="tab-panel" id="panel-sector">
            <div class="sector-layout">
                <div class="card">
                    <div class="card-header">
                        <h3>🏦 板块资金流向</h3>
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

        <!-- 基金分析页 -->
        <div class="tab-panel" id="panel-fund">
            <div class="fund-layout">
                <div class="card">
                    <div class="card-header">
                        <h3>💳 基金分析</h3>
                        <div class="fund-controls">
                            <input type="text" id="fund-search-input" placeholder="输入基金代码或名称搜索">
                            <button id="fund-search-btn" class="btn-sm btn-accent">搜索</button>
                        </div>
                    </div>
                    <div id="fund-loading" style="display:none;" class="loading-spinner"><div class="spinner"></div><span>搜索基金中...</span></div>
                    <div class="fund-content">
                        <div class="fund-search-results" id="fund-search-results"></div>
                    </div>
                </div>
                <!-- 自选基金 -->
                <div class="card">
                    <div class="card-header">
                        <h3>⭐ 自选基金</h3>
                        <button id="fund-refresh-btn" class="btn-sm">🔄 刷新估值</button>
                    </div>
                    <div class="fund-watchlist" id="fund-watchlist">
                        <p class="placeholder-text">搜索基金后可添加到自选</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- AI顾问页 -->
        <div class="tab-panel" id="panel-ai">
            <div class="ai-layout">
                <div class="card ai-card">
                    <div class="card-header">
                        <h3>🤖 AI 智能顾问</h3>
                        <button id="clear-chat-btn" class="btn-sm">🗑️ 清空对话</button>
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
            <h3>⭐ 自选股</h3>
            <button id="watchlist-close" class="btn-icon-sm">✕</button>
        </div>
        <div class="watchlist-add">
            <input type="text" id="watchlist-add-input" placeholder="输入代码添加 如: sh600519">
            <button id="watchlist-add-btn" class="btn-sm btn-accent">添加</button>
        </div>
        <div class="watchlist-items" id="watchlist-items">
            <p class="placeholder-text">暂无自选股</p>
        </div>
    </div>
    <div class="watchlist-overlay" id="watchlist-overlay"></div>

    <!-- AI 顾问 FAB 悬浮按钮 -->
    <button class="ai-advisor-fab" id="ai-advisor-fab" aria-label="打开 AI 顾问" aria-controls="ai-advisor-panel" aria-expanded="false" title="AI 顾问">
        <span class="fab-icon">🤖</span>
        <span class="fab-badge" id="fab-badge" style="display:none;">0</span>
        <span class="fab-glow"></span>
    </button>

    <!-- AI 顾问弹出面板 -->
    <div class="ai-advisor-panel" id="ai-advisor-panel" role="dialog" aria-label="AI 顾问面板" aria-modal="false" aria-hidden="true">
        <!-- 头部栏 -->
        <div class="advisor-header">
            <div class="advisor-header-left">
                <span class="advisor-avatar">🤖</span>
                <div class="advisor-identity">
                    <span class="advisor-name">AI 顾问</span>
                    <span class="advisor-status" id="advisor-status">在线 · Beta</span>
                </div>
            </div>
            <div class="advisor-header-right">
                <button class="advisor-header-btn" id="advisor-expand-btn" title="展开到完整页" aria-label="展开到完整 AI 顾问页">📋</button>
                <button class="advisor-header-btn" id="advisor-close-btn" title="关闭面板" aria-label="关闭 AI 顾问面板">✕</button>
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
                <p class="welcome-title">你好，我可以结合行情、资金流和板块数据帮你快速研判。</p>
                <p class="welcome-sub">我已接入当前页面数据，可以直接问我股票趋势、主力意图或板块机会。</p>
            </div>
            <div class="advisor-quick-actions" id="ai-advisor-quick-actions">
                <button class="quick-action-btn" data-prompt="帮我分析当前股票的趋势与支撑压力位">
                    <span class="qa-icon">📊</span>
                    <span class="qa-text">分析当前股票趋势</span>
                    <span class="qa-arrow">→</span>
                </button>
                <button class="quick-action-btn" data-prompt="帮我结合资金流向判断主力在吸筹还是出货">
                    <span class="qa-icon">💰</span>
                    <span class="qa-text">判断主力资金意图</span>
                    <span class="qa-arrow">→</span>
                </button>
                <button class="quick-action-btn" data-prompt="帮我从净流入热榜里筛选短期值得关注的标的">
                    <span class="qa-icon">🔥</span>
                    <span class="qa-text">从热榜筛选候选标的</span>
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
    <div class="modal-overlay" id="stock-modal-overlay" style="display:none;">
        <div class="modal-dialog" id="stock-modal">
            <div class="modal-header-bar">
                <h3 id="modal-stock-title">股票详情</h3>
                <button class="modal-close-btn" id="modal-close-btn">✕</button>
            </div>
            <div class="modal-body-content" id="modal-body-content"></div>
        </div>
    </div>

    <footer class="site-footer">
        <p>by <a href="https://yanshanlaosiji.top" target="_blank">雁山老司机</a> · ⚠️ 仅供娱乐研究，不构成投资建议</p>
    </footer>

    <script src="main.js"></script>
</body>
</html>
