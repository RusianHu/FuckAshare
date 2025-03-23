// 全局变量
let userInput;
let chatContainer;
let messageHistory;
let currentSessionId;

// 全局配置
const CONFIG = {
    // 超级查询时最多查询的股票数量，设为null则不限制
    maxQueryStocks: 50
};

// 格式化成交量
function formatVolume(volume) {
    if (volume >= 100000000) {
        return (volume / 100000000).toFixed(2) + '亿';
    } else if (volume >= 10000) {
        return (volume / 10000).toFixed(2) + '万';
    } else {
        return volume;
    }
}

// 自动向AI发送数据
function autoSendToAI(message) {
    // 确保消息记录到历史
    messageHistory.push({ role: 'user', content: message });
    
    // 向聊天界面添加用户消息
    appendMessage(message, null, 'user-message');
    
    const loadingMessage = appendMessage('思考中...', null, 'loading-message');
    
    let seconds = 0;
    const timer = setInterval(() => {
        seconds++;
        loadingMessage.textContent = `思考中... ${seconds}s`;
    }, 1000);
    
    sendToAI(loadingMessage, timer);
}

// 添加消息到聊天容器
function appendMessage(content, reasoningContent, className) {
    if (!chatContainer) return null;
    const container = document.createElement('div');
    container.classList.add('message', className);
    
    if (className === 'user-message') {
        const contentDiv = document.createElement('div');
        contentDiv.classList.add('content');
        contentDiv.textContent = content;
        container.appendChild(contentDiv);
    } else {
        if (reasoningContent) {
            const reasoningDiv = document.createElement('div');
            reasoningDiv.classList.add('reasoning-content');
            reasoningDiv.innerHTML = DOMPurify.sanitize(marked.parse(reasoningContent));
            container.appendChild(reasoningDiv);
        }
        
        if (content) {
            const contentDiv = document.createElement('div');
            contentDiv.classList.add('content');
            contentDiv.innerHTML = DOMPurify.sanitize(marked.parse(content));
            container.appendChild(contentDiv);
        }
    }
    
    chatContainer.appendChild(container);
    chatContainer.scrollTop = chatContainer.scrollHeight;
    return container;
}

// 发送到AI API
async function sendToAI(loadingMessage, timer) {
    try {
        const response = await fetch('ai_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                session_id: currentSessionId,
                messages: messageHistory,
                stream: true,
                model: 'deepseek-chat'
            })
        });
        
        if (!response.ok) {
            // 处理HTTP错误状态
            const errorText = await response.text();
            throw new Error(`服务器返回错误(${response.status}): ${errorText}`);
        }
        
        if (!response.body) {
            throw new Error('无法获取响应数据流');
        }
        
        const reader = response.body.getReader();
        let botMessageDiv = appendMessage('', '', 'bot-message');
        let decoder = new TextDecoder('utf-8');
        let fullBotResponse = '';
        let fullReasoningContent = '';
        let isError = false;
        
        while (true) {
            const { done, value } = await reader.read();
            if (done) break;
            
            const chunk = decoder.decode(value, { stream: true });
            const lines = chunk.split('\n');
            
            for (const line of lines) {
                if (line.trim() === '') continue;
                
                if (line.trim() === 'data: [DONE]') {
                    messageHistory.push({
                        role: 'assistant',
                        content: fullBotResponse
                    });
                    clearInterval(timer);
                    chatContainer.removeChild(loadingMessage);
                    return;
                }
                
                if (line.startsWith('data:')) {
                    try {
                        const json = JSON.parse(line.slice(5).trim());
                        
                        // 检查是否有错误信息
                        if (json.error) {
                            isError = true;
                            const errorMessage = json.error.message || JSON.stringify(json.error);
                            fullBotResponse = `**错误信息:** ${errorMessage}`;
                            
                            const contentDiv = botMessageDiv.querySelector('.content');
                            if (contentDiv) {
                                contentDiv.innerHTML = DOMPurify.sanitize(marked.parse(fullBotResponse));
                            } else {
                                const newContentDiv = document.createElement('div');
                                newContentDiv.classList.add('content');
                                newContentDiv.innerHTML = DOMPurify.sanitize(marked.parse(fullBotResponse));
                                botMessageDiv.appendChild(newContentDiv);
                            }
                            continue;
                        }
                        
                        if (json.choices && json.choices[0] && json.choices[0].delta) {
                            const delta = json.choices[0].delta;
                            
                            if (delta.content) {
                                fullBotResponse += delta.content;
                                const contentDiv = botMessageDiv.querySelector('.content');
                                if (contentDiv) {
                                    contentDiv.innerHTML = DOMPurify.sanitize(marked.parse(fullBotResponse));
                                } else {
                                    const newContentDiv = document.createElement('div');
                                    newContentDiv.classList.add('content');
                                    newContentDiv.innerHTML = DOMPurify.sanitize(marked.parse(delta.content));
                                    botMessageDiv.appendChild(newContentDiv);
                                }
                            }                          
                            if (delta.reasoning_content) {
                                fullReasoningContent += delta.reasoning_content;
                                const reasoningDiv = botMessageDiv.querySelector('.reasoning-content');
                                if (reasoningDiv) {
                                    reasoningDiv.textContent += delta.reasoning_content;
                                } else {
                                    const newReasoningDiv = document.createElement('div');
                                    newReasoningDiv.classList.add('reasoning-content');
                                    newReasoningDiv.textContent = fullReasoningContent;
                                    botMessageDiv.insertBefore(newReasoningDiv, botMessageDiv.firstChild);
                                }
                            }
                        }
                    } catch (err) {
                        console.error('解析流式数据失败:', err);
                        // 尝试显示原始错误数据
                        try {
                            const rawData = line.slice(5).trim();
                            if (!isError && rawData.includes('error')) {
                                isError = true;
                                fullBotResponse = `**解析错误:** ${rawData}`;
                                
                                const contentDiv = botMessageDiv.querySelector('.content');
                                if (contentDiv) {
                                    contentDiv.innerHTML = DOMPurify.sanitize(marked.parse(fullBotResponse));
                                } else {
                                    const newContentDiv = document.createElement('div');
                                    newContentDiv.classList.add('content');
                                    newContentDiv.innerHTML = DOMPurify.sanitize(marked.parse(fullBotResponse));
                                    botMessageDiv.appendChild(newContentDiv);
                                }
                            }
                        } catch (parseErr) {
                            console.error('无法解析错误数据:', parseErr);
                        }
                    }
                }
            }
        }
        
        clearInterval(timer);
        chatContainer.removeChild(loadingMessage);
        
        // 如果没有收到任何有效响应但也没有明确错误
        if (fullBotResponse === '' && !isError) {
            fullBotResponse = '**提示:** 服务器返回了空响应，可能是因为请求超时或模型上下文长度超限。';
            const contentDiv = botMessageDiv.querySelector('.content');
            if (contentDiv) {
                contentDiv.innerHTML = DOMPurify.sanitize(marked.parse(fullBotResponse));
            } else {
                const newContentDiv = document.createElement('div');
                newContentDiv.classList.add('content');
                newContentDiv.innerHTML = DOMPurify.sanitize(marked.parse(fullBotResponse));
                botMessageDiv.appendChild(newContentDiv);
            }
        }
        
    } catch (error) {
        clearInterval(timer);
        chatContainer.removeChild(loadingMessage);
        appendMessage(`**错误:** ${error.message}`, null, 'bot-message');
    }
}

// 手动发送消息
function sendMessage() {
    if (!userInput) return;
    const message = userInput.value.trim();
    if (!message) return;
    
    userInput.value = '';
    userInput.style.height = '50px';
    
    appendMessage(message, null, 'user-message');
    messageHistory.push({ role: 'user', content: message });
    
    const loadingMessage = appendMessage('思考中...', null, 'loading-message');
    
    let seconds = 0;
    const timer = setInterval(() => {
        seconds++;
        loadingMessage.textContent = `思考中... ${seconds}s`;
    }, 1000);
    
    sendToAI(loadingMessage, timer);
}

// 处理Enter键发送消息
function handleKeyDown(event) {
    if (!userInput) return;
    if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        sendMessage();
    }
}

// 自动调整输入框高度
function autoResizeTextarea() {
    if (!userInput) return;
    const minHeight = 50;
    const maxHeight = 120;
    userInput.style.height = minHeight + 'px';
    const scrollHeight = userInput.scrollHeight;
    if (scrollHeight <= maxHeight) {
        userInput.style.height = scrollHeight + 'px';
    } else {
        userInput.style.height = maxHeight + 'px';
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const stockForm = document.getElementById('stockForm');
    const stockTable = document.getElementById('stock-table');
    const stockData = document.getElementById('stock-data');
    const loading = document.getElementById('loading');
    const errorDiv = document.getElementById('error');
    
    // 配置 marked
    marked.setOptions({
        renderer: new marked.Renderer(),
        highlight: function(code, lang) {
            return code;
        },
        pedantic: false,
        gfm: true,
        breaks: false,
        sanitize: false,
        smartypants: false,
        xhtml: false
    });
    
    // 初始化聊天相关变量
    chatContainer = document.getElementById('chat-container');
    userInput = document.getElementById('user-input');
    messageHistory = [
        {role: 'system', content: '你是一位专业的股票分析师，擅长解读股票数据并给出建议。'}
    ];
    
    // 创建新会话
    createNewSession();
    
    // 创建新会话
    function createNewSession() {
        fetch('create_session.php', {
            method: 'POST'
        })
        .then(response => response.json())
        .then(data => {
            if (data.session) {
                currentSessionId = data.session.id;
                console.log('创建新会话:', currentSessionId);
            }
        })
        .catch(error => {
            console.error('无法创建新会话:', error);
        });
    }
    
    // 股票表单提交事件处理
    stockForm.addEventListener('submit', function(e) {
        e.preventDefault();
        
        const code = document.getElementById('code').value.trim();
        const frequency = document.getElementById('frequency').value;
        const count = document.getElementById('count').value;
        const end_date = document.getElementById('end_date').value;
        
        loading.style.display = 'block';
        errorDiv.style.display = 'none';
        stockTable.style.display = 'none';
        
        const url = `api.php?code=${encodeURIComponent(code)}&frequency=${encodeURIComponent(frequency)}&count=${encodeURIComponent(count)}&end_date=${encodeURIComponent(end_date)}`;
        
        fetch(url)
            .then(response => response.json())
            .then(data => {
                loading.style.display = 'none';
                
                if (!data.success) {
                    throw new Error(data.message || '获取数据失败');
                }
                
                stockData.innerHTML = '';
                
                if (data.data && data.data.length > 0) {
                    let aiDataString = `股票代码: ${code}\n频率: ${frequency}\n数据条数: ${count}\n\n`;
                    aiDataString += "日期/时间,开盘价,收盘价,最高价,最低价,成交量\n";
                    
                    data.data.forEach(row => {
                        const tr = document.createElement('tr');
                        
                        tr.innerHTML = `
                            <td>${row.time}</td>
                            <td>${parseFloat(row.open).toFixed(2)}</td>
                            <td>${parseFloat(row.close).toFixed(2)}</td>
                            <td>${parseFloat(row.high).toFixed(2)}</td>
                            <td>${parseFloat(row.low).toFixed(2)}</td>
                            <td>${formatVolume(row.volume)}</td>
                        `;
                        
                        stockData.appendChild(tr);
                        
                        aiDataString += `${row.time},${parseFloat(row.open).toFixed(2)},${parseFloat(row.close).toFixed(2)},${parseFloat(row.high).toFixed(2)},${parseFloat(row.low).toFixed(2)},${formatVolume(row.volume)}\n`;
                    });
                    
                    stockTable.style.display = 'table';
                    
                    aiDataString += "\n请评估这些数据，考虑成交量、历史趋势、MACD指标、相对强弱指数（RSI）、支撑位和阻力位等常见因素，给出你的投资建议。\n今天是：";
                    //提示今天的日期
                    aiDataString += new Date().toISOString().split('T')[0];
                    autoSendToAI(aiDataString);
                    
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
    
    const hotStocksTable = document.getElementById('hot-stocks-table');
    const hotStocksData = document.getElementById('hot-stocks-data');
    const loadingApi = document.getElementById('loading-api');
    const errorApi = document.getElementById('error-api');
    
    // 页面加载时获取热门股票数据
    fetchHotStocksData();
    
    // 获取热门股票数据函数
    function fetchHotStocksData() {
        loadingApi.style.display = 'block';
        errorApi.style.display = 'none';
        hotStocksTable.style.display = 'none';
        
        fetch('hot_stocks_api.php')
            .then(response => response.json())
            .then(data => {
                loadingApi.style.display = 'none';
                
                if (data.error) {
                    throw new Error(data.error || '获取热门股票数据失败');
                }
                
                hotStocksData.innerHTML = '';
                
                if (data && data.length > 0) {
                    data.forEach(stock => {
                        const tr = document.createElement('tr');
                        
                        const netInflow = parseFloat(stock.jlr);
                        const netInflowRate = parseFloat(stock.jlrl);
                        
                        tr.innerHTML = `
                            <td>${stock.dm}</td>
                            <td>${stock.mc}</td>
                            <td>${parseFloat(stock.zxj).toFixed(2)}</td>
                            <td class="${stock.zdf >= 0 ? 'positive' : 'negative'}">${parseFloat(stock.zdf).toFixed(2)}</td>
                            <td>${parseFloat(stock.hsl).toFixed(2)}</td>
                            <td>${formatAmount(stock.cje)}</td>
                            <td class="${netInflow >= 0 ? 'positive' : 'negative'}">${formatAmount(netInflow)}</td>
                            <td class="${netInflowRate >= 0 ? 'positive' : 'negative'}">${netInflowRate.toFixed(2)}</td>
                            <td>
                                <button class="btn-quick-query" data-code="${stock.dm}">AI快询60d</button>
                            </td>
                        `;
                        
                        hotStocksData.appendChild(tr);
                    });
                    
                    hotStocksTable.style.display = 'table';
                    
                    document.querySelectorAll('.btn-quick-query').forEach(button => {
                        button.addEventListener('click', function() {
                            const stockCode = this.getAttribute('data-code');
                            
            document.getElementById('code').value = stockCode;
            document.getElementById('frequency').value = '1d';
            document.getElementById('count').value = '60';
            
            const today = new Date();
            const yyyy = today.getFullYear();
            let mm = today.getMonth() + 1;
            let dd = today.getDate();
            
            if (dd < 10) dd = '0' + dd;
            if (mm < 10) mm = '0' + mm;
            
            const formattedDate = yyyy + '-' + mm + '-' + dd;
            document.getElementById('end_date').value = formattedDate;
            
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
    
    // 格式化金额
    function formatAmount(amount) {
        const num = parseFloat(amount);
        if (num >= 100000000) {
            return (num / 100000000).toFixed(2) + '亿';
        } else if (num >= 10000) {
            return (num / 10000).toFixed(2) + '万';
        } else {
            return num.toFixed(2);
        }
    }
    
    // 发送按钮点击事件
    document.getElementById('send-button').addEventListener('click', sendMessage);
    
    // 输入框键盘事件
    userInput.addEventListener('keydown', handleKeyDown);
    
    // 输入框内容变化事件
    userInput.addEventListener('input', autoResizeTextarea);
});

// 超级查询功能
document.addEventListener('DOMContentLoaded', function() {
    const superQueryBtn = document.getElementById('super-query-btn');
    if (!superQueryBtn) return;
    
    // 全局变量，存储所有查询到的股票数据
    let allStocksData = {};
    
    superQueryBtn.addEventListener('click', async function() {
        // 获取所有股票代码
        const allStockRows = document.querySelectorAll('#hot-stocks-data tr');
        if (!allStockRows.length) {
            alert('没有可查询的股票数据');
            return;
        }
        
        // 应用最大查询数量限制
        const maxStocks = CONFIG.maxQueryStocks || allStockRows.length;
        const stockRows = Array.from(allStockRows).slice(0, maxStocks);
        const actualCount = stockRows.length;
        
        const superQueryLoading = document.getElementById('super-query-loading');
        superQueryLoading.style.display = 'block';
        superQueryLoading.innerHTML = `超级查询中，请稍候... <span class="query-progress">0/${actualCount}</span>` + 
                                     (actualCount < allStockRows.length ? `<span class="query-limit">(已限制查询前${actualCount}只股票)</span>` : '');
        
        // 清除已有的子表格
        document.querySelectorAll('.stock-details').forEach(el => el.remove());
        
        // 存储所有查询结果的对象
        allStocksData = {};
        
        // 批量处理股票查询，使用限制后的股票数组
        await batchProcessStocks(stockRows);
        
        // 完成所有查询
        superQueryLoading.style.display = 'none';
        
        // 生成并存储用于未来AI询问的文本
        const aiQueryText = generateAIQueryText(allStocksData);
        localStorage.setItem('superQueryData', aiQueryText);
        
        // 显示询问AI按钮和下载查询记录按钮
        const askAiBtn = document.getElementById('ask-ai-btn');
        const downloadQueryBtn = document.getElementById('download-query-btn');
        if (askAiBtn) askAiBtn.style.display = 'inline-block';
        if (downloadQueryBtn) downloadQueryBtn.style.display = 'inline-block';
        
        console.log(`超级查询完成，共查询了${actualCount}只股票的数据`);
    });

    // 批量处理查询以避免浏览器卡顿
    async function batchProcessStocks(stockRows) {
        const batchSize = 5; // 每批处理5只股票
        const totalStocks = stockRows.length;
        const superQueryLoading = document.getElementById('super-query-loading');
        
        for (let i = 0; i < totalStocks; i += batchSize) {
            const end = Math.min(i + batchSize, totalStocks);
            superQueryLoading.innerHTML = `超级查询中，请稍候... <span class="query-progress">${i}/${totalStocks}</span>` + 
                                          (totalStocks < document.querySelectorAll('#hot-stocks-data tr').length ? 
                                           `<span class="query-limit">(已限制查询前${totalStocks}只股票)</span>` : '');
            
            // 创建一批查询任务
            const batchTasks = [];
            for (let j = i; j < end; j++) {
                const row = stockRows[j];
                if (!row) continue; // 防止undefined错误
                
                const stockCode = row.cells[0]?.textContent.trim();
                const stockName = row.cells[1]?.textContent.trim();
                
                if (!stockCode || !stockName) continue;
                
                batchTasks.push(
                    fetchStockData(stockCode, '1d', 60, '')
                        .then(data => {
                            if (data.success && data.data && data.data.length > 0) {
                                allStocksData[stockCode] = {
                                    name: stockName,
                                    data: data.data
                                };
                                
                                // 直接为父行添加点击事件，而不是创建子表格
                                row.classList.add('details-trigger');
                                row.setAttribute('data-code', stockCode);
                                row.setAttribute('data-name', stockName);
                                
                                // 添加点击事件
                                row.addEventListener('click', function() {
                                    showStockDetailsModal(stockCode);
                                });
                            }
                        })
                        .catch(error => console.error(`获取 ${stockCode} 数据失败:`, error))
                );
            }
            
            await Promise.all(batchTasks)
                .then(results => {
                    // 更新界面，显示有多少股票数据已加载成功
                    const loadedCount = Object.keys(allStocksData).length;
                    superQueryLoading.innerHTML = `已成功加载 ${loadedCount}/${totalStocks} 只股票数据`;
                });

            
            // 给浏览器UI线程喘息的机会
            await new Promise(resolve => setTimeout(resolve, 100));
        }
        
        superQueryLoading.innerHTML = `超级查询中，请稍候... <span class="query-progress">${totalStocks}/${totalStocks}</span>` + 
                                     (totalStocks < document.querySelectorAll('#hot-stocks-data tr').length ? 
                                      `<span class="query-limit">(已限制查询前${totalStocks}只股票)</span>` : '');
    }
    
    // 获取股票数据的函数
    function fetchStockData(code, frequency, count, end_date) {
        return new Promise((resolve, reject) => {
            const url = `api.php?code=${encodeURIComponent(code)}&frequency=${encodeURIComponent(frequency)}&count=${encodeURIComponent(count)}&end_date=${encodeURIComponent(end_date)}`;
            
            fetch(url)
                .then(response => response.json())
                .then(data => resolve(data))
                .catch(error => reject(error));
        });
    }
    
    // 创建详情弹窗 (替换原来的createDetailsRow函数)
    function createDetailsRow(parentRow, stockCode, stockName, stockData) {
        // 不再创建内嵌行，而是为父行添加点击事件数据
        parentRow.classList.add('details-trigger');
        parentRow.setAttribute('data-code', stockCode);
        parentRow.setAttribute('data-name', stockName);
        
        // 将数据存储在全局变量中，以便点击时使用
        if (!window.stockDetailsData) window.stockDetailsData = {};
        window.stockDetailsData[stockCode] = {
            name: stockName,
            data: stockData
        };
        
        // 直接在这里添加点击事件
        parentRow.addEventListener('click', function() {
            showStockDetailsModal(stockCode);
        });
    }
    
    // 显示股票详情模态框的函数
    function showStockDetailsModal(stockCode) {
        // 获取存储的数据
        const stockDetails = allStocksData[stockCode];
        if (!stockDetails) return;
        
        // 创建模态框外层
        const modal = document.createElement('div');
        modal.classList.add('stock-details-modal');
        
        // 创建模态框内容区
        const modalContent = document.createElement('div');
        modalContent.classList.add('modal-content');
        
        // 创建模态框头部
        const modalHeader = document.createElement('div');
        modalHeader.classList.add('modal-header');
        
        const modalTitle = document.createElement('h3');
        modalTitle.textContent = `${stockDetails.name} (${stockCode}) 60天数据`;
        
        const closeBtn = document.createElement('span');
        closeBtn.classList.add('close-modal');
        closeBtn.innerHTML = '&times;';
        closeBtn.onclick = function() {
            document.body.removeChild(modal);
        };
        
        modalHeader.appendChild(modalTitle);
        modalHeader.appendChild(closeBtn);
        
        // 创建模态框内容主体
        const modalBody = document.createElement('div');
        modalBody.classList.add('modal-body');
        
        // 创建表格
        const table = document.createElement('table');
        table.classList.add('stock-details-table');
        
        // 创建表头
        const thead = document.createElement('thead');
        const headerRow = document.createElement('tr');
        const headers = ['日期', '开盘价', '收盘价', '最高价', '最低价', '成交量'];
        
        headers.forEach(header => {
            const th = document.createElement('th');
            th.textContent = header;
            headerRow.appendChild(th);
        });
        
        thead.appendChild(headerRow);
        table.appendChild(thead);
        
        // 创建表体
        const tbody = document.createElement('tbody');
        
        // 添加数据行
        stockDetails.data.forEach(item => {
            const dataRow = document.createElement('tr');
            
            // 添加单元格
            dataRow.innerHTML = `
                <td>${item.time}</td>
                <td>${parseFloat(item.open).toFixed(2)}</td>
                <td>${parseFloat(item.close).toFixed(2)}</td>
                <td>${parseFloat(item.high).toFixed(2)}</td>
                <td>${parseFloat(item.low).toFixed(2)}</td>
                <td>${formatVolume(item.volume)}</td>
            `;
            
            tbody.appendChild(dataRow);
        });
        
        table.appendChild(tbody);
        modalBody.appendChild(table);
        
        // 组装模态框
        modalContent.appendChild(modalHeader);
        modalContent.appendChild(modalBody);
        modal.appendChild(modalContent);
        
        // 添加到body
        document.body.appendChild(modal);
        
        // 点击模态框外部关闭
        modal.onclick = function(event) {
            if (event.target === modal) {
                document.body.removeChild(modal);
            }
        };
    }

    
    // 添加点击事件处理程序
    function addClickHandlers() {
        document.querySelectorAll('.details-trigger').forEach(row => {
            row.addEventListener('click', function() {
                const targetId = this.getAttribute('data-target');
                const detailsRow = document.getElementById(targetId);
                
                if (detailsRow.style.display === 'table-row') {
                    detailsRow.style.display = 'none';
                    this.classList.remove('active');
                } else {
                    detailsRow.style.display = 'table-row';
                    this.classList.add('active');
                }
            });
        });
    }
    
    // 生成用于AI查询的文本结构
    function generateAIQueryText(allData) {
        let result = `# 股票超级查询结果\n\n`;
        result += `查询时间: ${new Date().toLocaleString()}\n`;
        result += `共查询 ${Object.keys(allData).length} 只股票的60天数据\n\n`;
        
        // 获取热门股票表格中的数据
        const hotStockRows = document.querySelectorAll('#hot-stocks-data tr');
        let hotStocksInfo = {};
        
        // 将热门股票数据存入对象中，便于查找
        hotStockRows.forEach(row => {
            const cells = row.cells;
            if (cells && cells.length >= 8) {
                const code = cells[0].textContent.trim();
                hotStocksInfo[code] = {
                    name: cells[1].textContent.trim(),
                    price: cells[2].textContent.trim(),
                    change: cells[3].textContent.trim(),
                    turnover: cells[4].textContent.trim(),
                    volume: cells[5].textContent.trim(),
                    netInflow: cells[6].textContent.trim(),
                    netInflowRate: cells[7].textContent.trim()
                };
            }
        });
        
        for (const code in allData) {
            const stock = allData[code];
            result += `## ${stock.name} (${code})\n\n`;
            
            // 添加热门股票排名信息
            if (hotStocksInfo[code]) {
                result += `### 当前市场数据\n`;
                result += `- 最新价: ${hotStocksInfo[code].price}\n`;
                result += `- 涨跌幅: ${hotStocksInfo[code].change}%\n`;
                result += `- 换手率: ${hotStocksInfo[code].turnover}%\n`;
                result += `- 成交额: ${hotStocksInfo[code].volume}\n`;
                result += `- 净流入: ${hotStocksInfo[code].netInflow}\n`;
                result += `- 净流入率: ${hotStocksInfo[code].netInflowRate}%\n\n`;
            }
            
            result += `### 历史60天数据\n`;
            result += `日期,开盘价,收盘价,最高价,最低价,成交量\n`;
            
            stock.data.forEach(item => {
                result += `${item.time},${parseFloat(item.open).toFixed(2)},${parseFloat(item.close).toFixed(2)},`;
                result += `${parseFloat(item.high).toFixed(2)},${parseFloat(item.low).toFixed(2)},${formatVolume(item.volume)}\n`;
            });
            
            result += `\n`;
        }
        
        result += `请根据以上数据分析这些股票的走势，剔除掉今日涨跌幅超过10%的，考虑成交量、历史趋势、MACD指标、相对强弱指数（RSI）、支撑位和阻力位等常见因素，根据证券和经济学规则，帮我选出几只下一个交易日最有可能会涨的股票，并简要分析原因。\n`;
        return result;
    }

    const askAiBtn = document.getElementById('ask-ai-btn');
    if (askAiBtn) {
        askAiBtn.addEventListener('click', function() {
            const aiQueryText = localStorage.getItem('superQueryData');
            if (aiQueryText) {
                // 向AI发送查询
                autoSendToAI(aiQueryText);
            } else {
                alert('请先执行超级查询以获取股票数据');
            }
        });
    }
    
    //超级查询记录下载
    const downloadQueryBtn = document.getElementById('download-query-btn');
    if (downloadQueryBtn) {
        downloadQueryBtn.addEventListener('click', function() {
            const aiQueryText = localStorage.getItem('superQueryData');
            if (aiQueryText) {
                // 创建下载链接
                const blob = new Blob([aiQueryText], { type: 'text/plain' });
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = `股票分析数据_${new Date().toISOString().split('T')[0]}.txt`;
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                URL.revokeObjectURL(url);
            } else {
                alert('请先执行超级查询以获取股票数据');
            }
        });
    }

});