<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- 确保移动端视口设置正确 -->
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>FuckAshare</title>
    <link rel="stylesheet" href="style.css">
    <!-- 添加 marked.js 库用于解析Markdown -->
    <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
    <!-- 添加 DOMPurify 用于防止 XSS 攻击 -->
    <script src="https://cdn.jsdelivr.net/npm/dompurify/dist/purify.min.js"></script>

</head>
<body>
    <div class="container">
        <header>
            <h1>FuckAshare</h1>
            <p>幽默牢A行情分析</p>
        </header>
        
        <main>
            <section class="query-form">
                <h2>股票行情查询</h2>
                <form id="stockForm">
                    <div class="form-group">
                        <label for="code">股票代码:</label>
                        <input type="text" id="code" name="code" placeholder="例如: sh000001 或 000001.XSHG" required>
                    </div>
                    <div class="form-group">
                        <label for="frequency">频率:</label>
                        <select id="frequency" name="frequency">
                            <option value="1m">分钟线(1m)</option>
                            <option value="5m">5分钟线(5m)</option>
                            <option value="15m">15分钟线(15m)</option>
                            <option value="30m">30分钟线(30m)</option>
                            <option value="60m">60分钟线(60m)</option>
                            <option value="1d" selected>日线(1d)</option>
                            <option value="1w">周线(1w)</option>
                            <option value="1M">月线(1M)</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="count">数据条数:</label>
                        <input type="number" id="count" name="count" min="1" max="500" value="10">
                    </div>
                    
                    <div class="form-group">
                        <label for="end_date">结束日期 (可选):</label>
                        <input type="date" id="end_date" name="end_date">
                        <small>不填则默认获取最新数据</small>
                    </div>
                    
                    <button type="submit" class="btn-submit">查询数据</button>
                </form>
            </section>
            
            <section class="results">
                <h2>查询结果</h2>
                <div id="loading" style="display: none;">加载中...</div>
                <div id="error" class="error" style="display: none;"></div>
                
                <div id="data-container">
                    <table id="stock-table" style="display: none;">
                        <thead>
                            <tr>
                                <th>日期/时间</th>
                                <th>开盘价</th>
                                <th>收盘价</th>
                                <th>最高价</th>
                                <th>最低价</th>
                                <th>成交量</th>
                            </tr>
                        </thead>
                        <tbody id="stock-data">
                        </tbody>
                    </table>
                </div>
            </section>
            
            <!-- AI咨询区域 -->
            <section class="ai-consultant">
                <h2>D指导帮你看看</h2>
                <div id="chat-container"></div>
                <div id="input-container">
                    <textarea id="user-input" placeholder="输入您的问题..." onkeydown="handleKeyDown(event)" oninput="autoResizeTextarea()"></textarea>
                    <button id="send-button" onclick="sendMessage()">发送</button>
                </div>
            </section>
            
            <section class="hot-stocks">
                <h2>牢A净流入额排名（每日16:00更新）
                    <button id="super-query-btn" class="btn-super-query">超级查询(60d)</button>
                    <button id="ask-ai-btn" class="btn-super-query" style="display: none;">AI选股!!!</button>
                    <button id="download-query-btn" class="btn-super-query" style="display: none;">下载超级查询文本</button>
                </h2>
                <div id="loading-api" style="display: none;">正在获取最新资金流向数据...</div>
                <div id="error-api" class="error" style="display: none;"></div>
                <div id="super-query-loading" style="display: none;">超级查询中，请稍候...</div>
                
                <div id="hot-stocks-container">
                    <table id="hot-stocks-table" style="display: none;">
                        <thead>
                            <tr>
                                <th>代码</th>
                                <th>名称</th>
                                <th>最新价</th>
                                <th>涨跌幅(%)</th>
                                <th>换手率(%)</th>
                                <th>成交额(元)</th>
                                <th>净流入(元)</th>
                                <th>净流入率(%)</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody id="hot-stocks-data">
                        </tbody>
                    </table>
                </div>
            </section>

        </main>
        
        <footer>
            <p>by <a href="https://yanshanlaosiji.top" target="_blank">雁山老司机</a> 助梦每一位空中飞✈人</p>
            <p>&copy; <?php echo date('Y'); ?> FuckAshare</p>
        </footer>
    </div>
    
    <script src="main.js"></script>
</body>
</html>
