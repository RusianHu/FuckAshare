# FuckAshare - A股智能分析平台

## 项目简介

FuckAshare 是一个基于PHP和Python的全功能股票数据查询与AI分析平台，专为A股投资者设计。集成了K线图表、技术指标分析、实时行情看板、板块资金流向、基金估值追踪、AI智能分析等核心功能，帮助投资者快速获取行情数据并获得专业的投资参考。

<img width="1219" height="1083" alt="image" src="https://github.com/user-attachments/assets/fc5edccb-750b-4122-bcdc-19ee3c9cd403" />

⚠️ **免责声明**：本系统仅供娱乐和研究，不构成任何投资建议。股市有风险，投资需谨慎。

## ✨ 核心功能

### 📊 K线图表 & 技术指标
- **专业K线图**：基于 TradingView Lightweight Charts 的交互式K线图
- **移动平均线 MA**：支持 MA5/MA10/MA20/MA60 多周期均线
- **布林带 BOLL**：20日中轨 + 2倍标准差上下轨
- **MACD 指标**：DIF/DEA/MACD 柱状图，经典参数 12/26/9
- **RSI 相对强弱指数**：14日RSI超买超卖判断
- **KDJ 随机指标**：K/D/J 三线分析
- **成交量柱状图**：红涨绿跌配色，直观展示量能

### 💹 实时行情看板
- 多股票同时监控，实时刷新（30秒自动刷新）
- 展示最新价、涨跌幅、开盘/最高/最低、成交额、换手率、PE等
- 一键添加/移除监控股票
- 数据来源于东方财富实时行情API

### 🏦 板块资金流向
- **行业板块 / 概念板块 / 主题板块 / 地域板块** 四大分类
- 今日/近5日/近10日 多时间维度资金流向
- 可视化柱状图 + 详细数据表格
- 主力/超大单/大单/中单/小单 分层资金数据

### 💳 基金分析
- 基金搜索：按代码或名称搜索基金产品
- 基金排行：按类型和日/周/月/季度/年度等周期筛选收益榜
- 实时估值：盘中实时估算基金净值和涨跌幅
- 历史净值：展示近期单位净值、累计净值、申赎状态和净值走势
- 基金自选：添加关注基金，一键刷新估值并汇总涨跌概况
- 详情分析：展示基金类型、风险等级、规模、经理、基金公司、费用和业绩基准

### 🤖 AI 智能分析
- 多渠道 AI 引擎支持（DeepSeek / OpenAI 兼容协议），SSE 流式输出
- 服务端工具调用智能体：按 OpenAI-compatible `tools` / `tool_calls` 协议多轮编排
- AI 可主动调用行情、K线、资金流、板块、市场宽度、基金、雪球热度/选股、技术指标等只读研究工具
- AI 分析自动融合 K 线数据 + 💰 资金流向数据 + 基金净值/排行数据，综合研判
- 查询股票数据后一键触发 AI 分析，同时关注主力资金动向与量价关系
- 支持大盘环境、市场宽度、涨跌家数、指数概览与近似涨跌停统计查询
- 超级查询：批量查询热门股票60天数据后 AI 选股
- 支持工具调用进度提示（查询行情、获取资金流、计算指标等）
- 默认抑制推理模型 `reasoning_content` 原始思考流，改用结构化 agent events 展示运行状态
- 支持多轮对话深入咨询

### ⭐ 自选股管理
- 添加/移除自选股，数据本地持久化
- 侧边栏展示自选股实时涨跌
- 一键跳转查询详情

### 📥 数据导出
- 股票数据CSV导出（支持中文）
- 超级查询结果文本下载

## 🎨 界面特色

- **深色交易主题**：专业金融级深色UI，长时间盯盘不疲劳
- **A股配色习惯**：红涨绿跌，符合A股投资者直觉
- **响应式布局**：完美适配桌面端和移动端
- **模块化Tab页**：股票行情、实时看板、板块资金、基金分析、AI顾问五大模块

## 技术栈

| 层级 | 技术 |
|------|------|
| 前端 | HTML5, CSS3, JavaScript (ES6+) |
| K线图 | [Lightweight Charts](https://github.com/tradingview/lightweight-charts) v4.1 |
| Markdown | marked.js + DOMPurify |
| 后端 | PHP 7.4+ (cURL) |
| 数据处理 | Python 3.x + pandas |
| 股票数据源 | Ashare库 (腾讯/新浪) + 东方财富API |
| 基金数据源 | 东方财富基金API |
| AI引擎 | DeepSeek / OpenAI兼容协议 (多渠道SSE流式输出 + tools/tool_calls 工具编排) |

## 安装步骤

### 环境要求

- PHP 7.4 或更高版本（需 cURL 扩展）
- Python 3.6 或更高版本
- Web 服务器（Apache / Nginx / 宝塔等）

### 安装流程

1. **克隆仓库**
   ```bash
   git clone https://github.com/RusianHu/FuckAshare.git
   cd FuckAshare
   ```

2. **安装 Python 依赖**
   ```bash
   pip install pandas requests
   ```

3. **配置 Web 服务器**

   将项目文件放置于 Web 服务器根目录，确保 PHP 有执行权限。

4. **配置项目与 AI API 密钥**
   ```bash
   cp config.example.php config.php
   ```
   然后修改 `config.php` 中的 `ai` 配置。`config.php` 是本地统一配置入口，包含密钥，不能提交到版本库：
   ```php
   'ai' => [
       'default_channel' => 'deepseek',
       'channels' => [
           'deepseek' => [
               'name'    => 'DeepSeek',
               'api_url' => 'https://api.deepseek.com/chat/completions',
               'api_key' => 'your-deepseek-api-key',
               'model'   => 'deepseek-chat',
               'supports_tools' => true,
           ],
           'openai' => [
               'name'    => 'OpenAI兼容',
               'api_url' => 'https://your-openai-compatible-endpoint/v1/chat/completions',
               'api_key' => 'your-api-key',
               'model'   => 'your-model',
               'supports_tools' => true,
           ],
       ],
       'tool_agent' => [
           'enabled' => true,
           'max_tool_rounds' => 10,
           'max_tool_calls_per_round' => 8,
           'max_tool_calls_total' => 64,
           'max_deep_dive_candidates' => 10,
           'tool_timeout' => 45,
           'tool_output_char_limit' => 60000,
           'parallel_tool_calls' => true,
           'expose_tool_trace' => true,
           'emit_agent_events' => true,
           'suppress_reasoning_content' => true,
           'auto_prefetch' => false,
           'stream_after_tool_round' => true,
           'agent_profile' => '',
           'trace_enabled' => false,
           'trace_log_path' => '',
       ],
   ],
   ```

   如果上游模型或兼容端点不支持 `tools` / `tool_calls`，将对应渠道的 `supports_tools` 设为 `false`，系统会回退为普通 SSE 对话。

5. **设置文件权限**（Linux）
   ```bash
   chmod +x get_stock_data.py
   chmod 755 -R ./*
   ```

## 使用方法

### 股票行情查询
1. 输入股票代码（如：sh000001、600519、000001.XSHG）
2. 选择K线周期（1分钟~月线）
3. 设置数据条数（建议120+以获得完整指标）
4. 点击"查询"，自动显示K线图 + 技术指标 + AI分析

### 实时看板
- 点击"添加"输入股票代码，自动监控实时行情
- 支持30秒自动刷新

### 板块资金
- 选择板块类型（行业/概念/主题/地域）和时间维度
- 查看资金流入流出排名

### 基金分析
- 搜索基金代码或名称
- 添加到自选基金列表，实时查看估值

### AI 顾问
- 支持自然语言连续追问，服务端会按需调用只读研究工具获取事实数据
- 股票研究可调用实时行情、K线、资金流、板块流向、市场宽度、热股榜、雪球热度和条件选股
- 市场环境研究可调用市场宽度工具，返回主要指数概览、涨跌家数、全市场宽度聚合和近似涨停/跌停统计
- 基金研究可调用基金搜索、估值、资料、历史净值和同类排行
- 技术研究可计算 MA、BOLL、MACD、RSI、KDJ、阶段涨跌幅、波动率和区间高低点
- 工具调用结果只用于研究分析，不会执行交易、不会修改自选股/自选基金

### 自选股
- 点击右上角⭐图标打开自选股侧边栏
- 添加代码后可查看实时涨跌，点击跳转查询

## 文件结构

```
FuckAshare/
├── index.php              # 主页面（五大模块Tab页）
├── api.php                # 兼容旧 K 线 API
├── market_api.php         # 统一行情 API 入口
├── ai_api.php             # AI SSE 代理接口（读取 config.php）
├── config.example.php     # 统一配置模板
├── config.php             # 本地配置文件（含密钥，不提交）
├── stock_quote_api.php    # 股票实时行情兼容 API
├── stock_flow_api.php     # 股票资金流向兼容 API
├── sector_flow_api.php    # 板块资金流向兼容 API
├── fund_estimate_api.php  # 基金实时估值 API
├── fund_info_api.php      # 基金详细信息 API
├── fund_history_api.php   # 基金历史净值 API
├── fund_rank_api.php      # 基金收益排行 API
├── fund_search_api.php    # 基金搜索 API
├── hot_stocks_api.php     # 热门股票资金流向 API
├── xueqiu_api.php         # 雪球数据兼容 API
├── create_session.php     # AI聊天会话创建
├── get_stock_data.py      # Python股票数据获取脚本
├── Ashare.py              # 股票数据核心库（腾讯/新浪双核心）
├── lib/                   # 服务层、数据源 Client、缓存、熔断、HTTP 工具、AI 工具编排
│   ├── AIChatToolAgent.php # AI 顾问兼容 facade / 主编排入口
│   ├── AIChatCompletionsAdapter.php # Chat Completions / OpenAI-compatible 传输适配器
│   ├── AIAgentOptions.php # 智能体运行时配置归一化
│   ├── AIAgentState.php   # 单次请求运行状态、run_id、预算与去重状态
│   ├── AIAgentStreamEmitter.php # SSE、agent event、tool_status 与终止事件输出
│   ├── AIAgentProfile.php # advisor/market_scanner/fund_researcher/risk_reviewer 档案
│   ├── AIAgentTraceRecorder.php # 单次 run 的事件轨迹、停止原因与可选 JSONL 落盘
│   ├── AIAgentCheckpointManager.php # 模型响应和工具批次后的 checkpoint 事件
│   ├── AIAgentGuardrailPolicy.php # 金融回答护栏、风险提示和最终输出修正
│   ├── AIToolRuntime.php  # 工具调用解码、执行、去重、预算与事件封装
│   ├── AIFinanceToolCatalog.php # 股票/基金/研究辅助工具目录
│   ├── AIToolSchema.php   # OpenAI strict function schema 构造器
│   ├── AIToolExecutor.php  # AI 只读研究工具执行器
│   └── AIToolRegistry.php  # OpenAI-compatible tools 适配层
├── tests/                 # 本地测试脚本
├── main.js                # 主JavaScript（图表/指标/模块逻辑）
├── style.css              # 前端样式
├── doc/                   # API研究、架构规划与阶段任务文档
└── README.md              # 项目说明文档
```

## API接口说明

### 后端代理API

所有东方财富接口均通过PHP后端代理访问，解决跨域问题：

| 接口文件 | 说明 | 参数 |
|----------|------|------|
| `stock_quote_api.php` | 股票实时行情 | `codes`=股票代码(逗号分隔) |
| `stock_flow_api.php` | 个股资金流向 | `code`=代码, `market`=市场(可选), `lmt`=条数 |
| `sector_flow_api.php` | 板块资金流向 | `type`=industry/concept/theme/region, `key`=f62/f164/f174 |
| `fund_estimate_api.php` | 基金实时估值 | `code`=6位基金代码 |
| `fund_info_api.php` | 基金详细信息 | `codes`=基金代码(逗号分隔) |
| `fund_history_api.php` | 基金历史净值 | `code`=6位基金代码, `page_size`=条数 |
| `fund_rank_api.php` | 基金收益排行 | `type`=all/stock/mixed/bond/index/qdii/fof, `period`=day/week/month/quarter/half_year/year/two_year/three_year/this_year/since |
| `fund_search_api.php` | 基金搜索 | `key`=搜索关键词 |
| `market_api.php` | 统一行情 API 入口 | `action`=quote/kline/hot_stock/screener/fundx/stock_flow/sector_flow/hot_stocks/market_breadth |
| `ai_api.php` | AI 顾问 SSE + 工具调用编排 | `POST JSON: messages, session_id, stream` |

### AI 工具调用工具包

`ai_api.php` 会在服务端向支持工具调用的上游模型提供以下只读工具。模型只能通过这些封装调用本项目已有服务层，不能访问任意 URL、文件路径、Shell 命令或交易接口。

工具包按 OpenAI function/tool calling 规范拆分为四层：
- `AIToolSchema.php` 负责生成 strict JSON Schema：每个 object 都设置 `additionalProperties=false`，并要求列出全部 `required` 字段；可选参数使用 `["类型","null"]`。
- `AIFinanceToolCatalog.php` 只声明金融工具目录，按股票市场、基金、研究辅助分组。
- `AIToolRegistry.php` 将内部目录转换为 Chat Completions 兼容格式：`{ type: "function", function: { name, description, parameters, strict: true } }`。
- `AIToolExecutor.php` 通过工具名到 handler 的映射执行本地只读服务，统一返回 `success/source/action/data/meta` 或结构化错误。

| 工具 | 功能 |
|------|------|
| `fa_normalize_stock_code` | 股票代码归一化，输出东方财富/Ashare/雪球格式 |
| `fa_get_stock_quote` | 查询实时行情 |
| `fa_get_stock_kline` | 查询 K 线数据 |
| `fa_get_stock_flow` | 查询个股资金流向 |
| `fa_get_sector_flow` | 查询行业/概念/主题/地域板块资金流 |
| `fa_get_hot_stocks` | 查询东方财富资金热股榜 |
| `fa_get_market_breadth` | 查询市场宽度、主要指数、涨跌家数和近似涨跌停统计 |
| `fa_get_xueqiu_hot_stock` | 查询雪球热度榜 |
| `fa_run_xueqiu_screener` | 运行雪球条件选股 |
| `fa_get_xueqiu_feed` | 获取雪球公开动态/资讯流 |
| `fa_search_funds` | 基金搜索 |
| `fa_get_fund_info` | 基金资料 |
| `fa_get_fund_estimate` | 基金实时估值 |
| `fa_get_fund_history` | 基金历史净值 |
| `fa_get_fund_rank` | 基金同类排行 |
| `fa_get_index_profile` | 基金跟踪指数画像、业绩基准、投资策略依据 |
| `fa_get_fund_dividend_history` | 基金历史分红/派息记录 |
| `fa_get_fund_documents` | 基金公告/报告/合同/招募说明书及可选正文 |
| `fa_screen_funds` | 多关键词+多排行召回主题基金候选池（红利等主题） |
| `fa_get_fund_performance_stats` | 分页拉取长历史净值并计算收益/回撤/波动/胜率 |
| `fa_score_funds` | 对候选基金做确定性多维评分排序（可复现） |
| `fa_get_fund_trade_rules` | 申购/赎回/限购/费率/购买状态 |
| `fa_get_fund_holdings_or_index_exposure` | 持仓/行业/指数暴露与风格因子标签 |
| `fa_research_state_summary` | 汇总本轮研究状态、已查字段、失败项与下一步建议 |
| `fa_calculate_kline_indicators` | 计算 MA/BOLL/MACD/RSI/KDJ 等指标 |
| `fa_compare_candidates` | 对候选股票/基金按数值指标做确定性排序 |

基金研究聚合工具说明：
- 6 个聚合工具（`fa_screen_funds` / `fa_get_fund_performance_stats` / `fa_score_funds` / `fa_get_fund_trade_rules` / `fa_get_fund_holdings_or_index_exposure` / `fa_research_state_summary`）优先于模型多次裸调单点工具，把“模型自由调用”升级为“服务端可复现研究流程 + 模型解释”。
- `fa_get_fund_performance_stats` 内部用 `curl_multi` 并发分页拉取多基金历史净值，单基金失败不影响其他基金，样本不足时标记 `coverage_level=insufficient_history` 与 `partial=true`。
- `fa_score_funds` 用默认权重表（按 objective/horizon/risk 调整）做 0-100 多维评分，返回 `score_breakdown`、`reasons`、`penalties`、`score_confidence`，同一输入多次调用排名一致。
- 推荐类基金问题必须调用 `fa_score_funds` 后才收敛最终回答；最终回答需说明候选池召回来源、评分依据与数据缺口。
- 聚合工具统一返回 `coverage`/`failures`/`partial`，便于模型说明数据缺口；`fa_research_state_summary` 由 `AIToolRuntime` 维护的结构化 `researchState` 驱动，记录候选池、工具成功/失败与下一步建议。
- 相关配置见 `config.php` 的 `fund_research` 段（`target_history_days` / `max_screen_candidates` / `max_score_candidates` / `max_parallel_workers` / `retry_network_errors`）。

工具执行策略：
- 单次请求最多 `max_tool_rounds` 轮工具调用
- 每轮最多 `max_tool_calls_per_round` 个工具调用
- 单次请求最多 `max_tool_calls_total` 个真实工具执行
- 市场环境、市场宽度、涨跌家数、涨停跌停、普涨普跌问题会优先调用 `fa_get_market_breadth`
- 市场宽度的涨停/跌停数据为公开行情涨跌幅阈值近似统计，可能不完全覆盖 ST、北交所、上市新股等特殊规则
- 市场扫描、个股研究和基金研究都由模型基于工具观察结果决定是否继续调用工具
- 常识性问答可由模型直接回答，不会触发服务端自动行情/基金/热榜查询
- 相同工具和相同参数会去重
- 模型请求工具后，服务端执行本地工具，再把工具结果回填给模型生成最终研究结论
- 单个工具失败会以结构化错误返回给模型，不中断整个研究流程
- 工具输出会按 `tool_output_char_limit` 截断，避免上下文膨胀
- 最终回答会经过金融护栏：禁止承诺收益、确定性买卖点，并要求风险提示；上游未发送 `[DONE]` 时服务端也会补齐护栏、`run_finished` 和 `[DONE]`
- `emit_agent_events=true` 时会额外输出 `run_started`、`checkpoint_created`、`tool_call_finished`、`run_finished` 等结构化 SSE 事件
- `suppress_reasoning_content=true` 时不向前端透传上游 `reasoning_content` 原始思考流，只保留正式回答和结构化状态
- `agent_profile` 留空时自动识别 `advisor` / `market_scanner` / `fund_researcher` / `risk_reviewer`，但所有 profile 都保留完整工具能力
- `trace_enabled=true` 时会把每次 run 的事件时间线、工具摘要、checkpoint 和 `stop_reason` 以 JSONL 写入 `trace_log_path`
- `stream_after_tool_round=true` 时，模型主动选择工具后，服务端把观察结果回填给模型继续自行决策或最终回答
- `auto_prefetch` 已废弃并默认关闭；服务端不再在模型无工具调用、工具参数异常或握手失败时自动调用研究工具

### 股票代码格式

支持多种格式自动识别：
- 上证：`sh000001` 或 `000001.XSHG`
- 深证：`sz399001` 或 `000001.XSHE`
- 纯数字：`600519`（6开头自动识别为沪市）

## 本地测试

项目提供无框架 PHP CLI 测试脚本。Windows 本地可直接使用仓库内便携 PHP：

```powershell
.\php\php.exe tests\phase2_core_tests.php
.\php\php.exe tests\ai_tool_agent_tests.php
.\php\php.exe tests\market_breadth_normalizer_tests.php
```

`phase2_core_tests.php` 覆盖缓存、熔断和核心服务层行为；`ai_tool_agent_tests.php` 覆盖工具 schema、参数拒绝、工具输出截断、指标计算、工具编排和普通 SSE 回退；`market_breadth_normalizer_tests.php` 覆盖市场宽度指数归一化、聚合、unknown_count、近似涨跌停和 breadth_score 口径。

## 常见问题

1. **K线图不显示**
   - 确认网络可访问 CDN（lightweight-charts 库）
   - 检查数据条数是否足够（建议 ≥ 20）

2. **技术指标显示异常**
   - MACD/RSI/KDJ 需要足够的数据量（建议 ≥ 30条）
   - BOLL 需要至少 20 条数据

3. **实时行情无数据**
   - 非交易时间可能无实时数据
   - 检查东方财富API是否可访问

4. **基金估值不更新**
   - 仅在交易时间（9:30-15:00）提供实时估值
   - 基金代码需为6位数字

5. **AI 顾问没有调用工具**
   - 确认 `config.php` 中 `ai.tool_agent.enabled=true`
   - 确认当前渠道 `supports_tools=true`
   - 确认上游模型支持 OpenAI-compatible `tools` / `tool_calls`
   - 不支持工具调用的渠道可设为 `supports_tools=false`，系统会回退普通流式对话

6. **AI 工具调用返回数据过少**
   - 检查 `tool_output_char_limit` 是否过小
   - 检查上游数据源是否命中缓存降级、熔断或接口失败
   - 对需要更长历史样本的问题，可要求 AI 查询更长 K 线或基金历史数据

## 更新日志

### v2.1 (2026-07) - AI 工具调用智能体
- 🤖 新增 OpenAI-compatible `tools` / `tool_calls` 服务端编排
- 🧰 新增股票、基金、雪球、资金流、技术指标等只读研究工具包
- 🧩 工具层拆分为 schema 构造、金融工具目录、OpenAI 适配、执行器 handler 分发
- 🔁 支持多轮复杂工具调用、工具结果回填和最终 SSE 流式回答
- 🧭 新增 AgentProfile、TraceRecorder、CheckpointManager，支持 profile、checkpoint、stop_reason 和可复盘 trace
- 🧱 新增 GuardrailPolicy，并将市场扫描/个股/基金研究收敛为模型驱动的工具循环
- 🛡️ 增加工具参数白名单、输出截断、重复调用去重和结构化错误返回
- 🧪 新增 AI 工具调用编排测试脚本

### v2.0 (2026-04) - 大规模重构
- 🎨 全新深色交易主题UI
- 📊 集成 Lightweight Charts 专业K线图
- 📈 新增 MA/BOLL/MACD/RSI/KDJ 技术指标
- 💹 新增实时行情看板（多股票监控 + 自动刷新）
- 🏦 新增板块资金流向（行业/概念/主题/地域）
- 💳 新增基金分析模块（搜索/估值/自选）
- ⭐ 新增自选股管理（本地持久化）
- 📥 新增CSV数据导出
- 🔌 新增6个东方财富数据代理API
- 📚 新增API接口文档

### v1.0 - 初始版本
- 股票数据查询
- AI智能分析
- 热门股票排行
- 超级查询功能

## 贡献指南

欢迎提交 Issues 和 Pull Requests！

1. Fork 本仓库
2. 创建新分支 (`git checkout -b feature/amazing-feature`)
3. 提交更改 (`git commit -m 'Add some amazing feature'`)
4. 推送到分支 (`git push origin feature/amazing-feature`)
5. 创建 Pull Request

## 许可证

本项目采用 MIT 许可证 - 详情见 [LICENSE](LICENSE) 文件

## 致谢

- [Ashare](https://github.com/mpquant/Ashare) - 股票数据获取核心库
- [Lightweight Charts](https://github.com/tradingview/lightweight-charts) - 专业K线图表库
- [DeepSeek](https://www.deepseek.com/) - AI分析引擎（DeepSeek渠道）
- [东方财富](https://www.eastmoney.com/) - 实时行情/资金流向/基金数据
- 所有开源贡献者和使用者

---

**免责声明**：本项目仅供学习和研究使用，不构成任何投资建议。投资有风险，入市需谨慎。
