# FuckAshare - A股智能分析平台

## 项目简介

FuckAshare 是一个基于PHP和Python的全功能股票数据查询与AI分析平台，专为A股投资者设计。集成了K线图表、技术指标分析、实时行情看板、板块资金流向、基金估值追踪、AI智能分析等核心功能，帮助投资者快速获取行情数据并获得专业的投资参考。

![image](https://github.com/user-attachments/assets/4264dc74-e581-46ee-a002-37a6adf563b6)

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
- AI 分析自动融合 K 线数据 + 💰 资金流向数据，综合研判
- 查询股票数据后一键触发 AI 分析，同时关注主力资金动向与量价关系
- 超级查询：批量查询热门股票60天数据后 AI 选股
- 支持推理模型（reasoning_content）的思考过程展示
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
| AI引擎 | DeepSeek / OpenAI兼容协议 (多渠道SSE流式输出) |

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
           ],
           'openai' => [
               'name'    => 'OpenAI兼容',
               'api_url' => 'https://your-openai-compatible-endpoint/v1/chat/completions',
               'api_key' => 'your-api-key',
               'model'   => 'your-model',
           ],
       ],
   ],
   ```

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
├── lib/                   # 服务层、数据源 Client、缓存、熔断、HTTP 工具
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

### 股票代码格式

支持多种格式自动识别：
- 上证：`sh000001` 或 `000001.XSHG`
- 深证：`sz399001` 或 `000001.XSHE`
- 纯数字：`600519`（6开头自动识别为沪市）

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

## 更新日志

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
