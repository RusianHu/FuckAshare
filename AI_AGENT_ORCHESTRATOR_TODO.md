# AI 智能体编排器 TODO

## 目标

把当前 AI 工具箱升级成接近 Codex / Claude Code / Kilo Code 形态的智能体运行时：

- 模型始终拥有项目提供的全部只读金融研究工具能力。
- 服务端负责智能体循环、工具执行、状态管理、可观测追踪、终止条件、检查点和安全护栏。
- 前端继续保持 SSE 流式体验，能看到类似“正在调用工具 / 正在分析 / 已完成”的清晰过程。

本规划不采用“按意图隐藏工具”的方案。工具能力不收窄，约束交给运行时、预算、安全策略和输出协议。

## 当前技术验证（多轮、复杂、ai自主决策工具调用）

验证提示词：

```text
查询资金流向前10的股票，充分深入评估它们，给出建议
```

用当前 `AIChatToolAgent` 做模拟调用后，得到两个关键结论：

### 正常工具调用路径

- 模型握手次数：1
- 实际执行工具：
  - `fa_get_hot_stocks`
  - `fa_get_sector_flow`
  - `fa_get_xueqiu_hot_stock`
- 服务端预取：否
- 候选股深挖：否

问题：

当前正常路径能拿到资金流向前 10、板块资金、雪球热度，但由于 `stream_after_tool_round=true`，模型执行第一轮工具后就被要求直接最终回答。它不能先观察前 10 的代码，再继续对每个候选调用行情、K 线指标、资金流等工具。

### 异常 fallback 路径

- 模型握手次数：1
- 先执行：
  - `fa_get_hot_stocks`
  - `fa_get_sector_flow`
  - `fa_get_xueqiu_hot_stock`
- 再额外对前 3 个候选执行：
  - `fa_get_stock_quote`
  - `fa_calculate_kline_indicators`
  - `fa_get_stock_flow`
- 服务端预取：是
- 候选股深挖：是

问题：

fallback 路径反而比正常路径更深入，这说明当前架构把“深挖能力”藏在应急预取逻辑里，而不是放在正常智能体循环里。

### 已施工后的真实链路验证

本地启动 PHP 内置服务器，通过 HTTP POST 调用真实 `ai_api.php`，提交：

```text
查询资金流向前10的股票，充分深入评估它们，给出建议
```

验证结果：

- curl 退出码：0
- SSE 响应：正常结束
- `run_finished`：已出现，且位于 `data: [DONE]` 之前
- `data: [DONE]`：已出现
- 错误事件：无
- `fa_get_stock_quote`：10 次
- `fa_calculate_kline_indicators`：10 次
- `fa_get_stock_flow`：10 次
- 原始 `reasoning_content`：未透传
- 最终回答长度：2547 字
- 最终回答包含前 10 分析、风险/不确定性说明和“不构成投资建议”

真实上游本次返回了畸形模型工具参数 `{"page": `，系统进入服务端 fallback；fallback 已改为按用户请求前 N 深挖，默认最多 10 个候选，因此仍能得到有效的完整研究行为。

### 二次真实链路验证

新增 `AgentProfile`、`TraceRecorder`、`CheckpointManager` 并补齐 `ai_api.php` 配置透传后，再次调用真实 `ai_api.php`：

- curl 退出码：0
- `fa_get_stock_quote`：10 次
- `fa_calculate_kline_indicators`：10 次
- `fa_get_stock_flow`：10 次
- `checkpoint_created`：已出现
- `market_scanner` profile：已出现
- `run_finished`：已出现，且位于 `data: [DONE]` 之前
- `data: [DONE]`：已出现
- 错误事件：无
- 原始 `reasoning_content`：未透传
- “不构成投资建议”：已出现

### 三次真实链路验证

本轮修复后，对真实 `ai_api.php` 做基金与股票两类 SSE 回归：

基金提示词：

```text
查询今天的最新 基金信息 ，今日涨的最多的是哪个，如何评估？
```

验证结果：

- HTTP 状态：200
- `run_finished`：已出现
- `data: [DONE]`：已出现
- 错误事件：无
- `fa_get_fund_rank`：已出现，且 `period=day`
- `fa_get_fund_info`：已出现
- `fa_get_fund_estimate`：已出现
- `fa_get_hot_stocks`：0 次
- `fa_get_stock_quote`：0 次
- 原始 `reasoning_content`：未透传
- “不构成投资建议”：已出现

股票市场扫描提示词：

```text
查询资金流向前10的股票，充分深入评估它们，给出建议
```

验证结果：

- HTTP 状态：200
- `run_finished`：已出现
- `data: [DONE]`：已出现
- 错误事件：无
- `fa_get_hot_stocks`：已出现
- `fa_get_stock_quote`：10 次
- `fa_calculate_kline_indicators`：10 次
- `fa_get_stock_flow`：10 次
- `fa_get_fund_rank`：0 次
- 原始 `reasoning_content`：未透传
- 最终回答分片拼接后包含研究参考 / 不构成投资建议提示

本轮定位的根因：无基金代码的基金排行/今日涨幅请求没有命中基金意图，导致 malformed `fa_get_fund_rank` 后被市场扫描 fallback 接管，错误调用股票热榜。已改为基金意图优先，且基金 fallback 使用 `fa_get_fund_rank` + 候选基金资料/估值补全。

### 自动化检修验证

基于 `http://localhost:8081` 对本地开发服务器进行自动化检修：

- 页面入口：HTTP 200，包含 `AI 顾问`、`main.js`、`ai-advisor-panel`。
- 前端脚本：`node --check main.js` 通过。
- PHP 语法：`AIChatToolAgent`、`AIToolRuntime`、`AIAgentStreamEmitter` 通过。
- 单元回归：`./php/php.exe tests/ai_tool_agent_tests.php` 通过。

真实 SSE 场景结果：

1. 无基金代码的今日基金涨幅问题：
   - `run_finished` / `[DONE]`：正常。
   - `fa_get_fund_rank`：已调用，且 `period=day`。
   - `fa_get_fund_info` / `fa_get_fund_estimate`：已调用。
   - `fa_get_hot_stocks` / `fa_get_stock_quote`：0 次。
   - 原始 `reasoning_content`：未透传。
   - 最终文本包含“不构成投资建议”。
2. 资金流向前 10 股票扫描：
   - `run_finished` / `[DONE]`：正常。
   - `fa_get_hot_stocks`：已调用。
   - `fa_get_stock_quote` / `fa_calculate_kline_indicators` / `fa_get_stock_flow`：各 10 次。
   - `fa_get_fund_rank`：0 次。
   - 原始 `reasoning_content`：未透传。
   - 最终文本包含“不构成投资建议”。
3. 基金代码研究 `161725`：
   - `run_finished` / `[DONE]`：正常。
   - `fa_get_fund_rank` / `fa_get_fund_info` / `fa_get_fund_estimate` / `fa_get_fund_history`：已调用。
   - `fa_get_hot_stocks` / `fa_get_stock_quote` / `fa_calculate_kline_indicators` / `fa_get_stock_flow`：0 次。
   - 已修复基金代码被误判为股票代码的问题。
   - 原始 `reasoning_content`：未透传。
   - 最终文本包含“不构成投资建议”。

### 多轮模型工具循环真实验证

本轮将主路径改为完整 Observe-Then-Act 循环：每轮模型返回 `tool_calls` 后，服务端执行工具并把观察结果回填，再次调用模型让其自行决定继续工具调用或最终回答。

验证项：

- 单元回归新增多轮模型主路径用例：第 1 轮模型调用热榜，第 2 轮模型观察后继续调用行情/资金流，第 3 轮模型输出最终回答；断言没有 `server_planned` 自动深挖。
- 真实 SSE 基金代码研究验证到连续事件：`model_tool_call(fa_get_fund_info)` → 观察回填 → `model_tool_call(fa_get_fund_estimate)` → 观察回填 → `model_tool_call(fa_get_fund_history)`；上游异常只回退普通流式对话，不再触发服务端研究工具预取。
- 真实 SSE 矩阵继续通过：无代码基金排行、资金流向前 10 股票扫描、基金代码研究均有 `run_finished` / `[DONE]`，无原始 `reasoning_content` 泄漏，最终文本包含“不构成投资建议”。

### 禁止服务端自动兜底工具调用

本轮按 Codex / Claude Code / Kilo Code 的模型驱动方式收敛：

- 模型可先分析任务，再通过正式 `tools/tool_calls` 决定是否调用工具。
- 模型无工具调用时，服务端直接输出模型回答；常识性问答不会被服务端自动查行情、基金或热榜。
- 模型调用工具时，服务端只执行模型明确请求的只读工具，并把观察结果回填。
- 模型连续给出畸形工具参数时，服务端要求模型基于已有上下文结束，不再执行 `server_prefetch` / `server_planned`。
- 工具握手失败时只回退普通流式对话，不再兜底调用研究工具。
- `auto_prefetch` 配置默认 `false` 且运行时已移除自动预取调用链；旧 `AIAgentPlanningPolicy` 已删除。

自动化检修与真实链路验证：

- `.\php\php.exe tests\ai_tool_agent_tests.php` 通过。
- `.\php\php.exe tests\phase2_core_tests.php` 通过。
- 全量 PHP 文件 `php -l` 通过，`node --check main.js` 通过。
- 本地 HTTP 调用 `ai_api.php` 常识性问答 `什么是市盈率？`：HTTP 200，`run_finished` / `[DONE]` 正常，工具调用计数 0，无 `server_prefetch` / `server_planned`。
- 本地 HTTP 调用 `ai_api.php` 行情查询 `查询 600519 的最新行情`：HTTP 200，`run_finished` / `[DONE]` 正常，真实工具链路出现 `fa_get_stock_quote` 且 origin 为 `model_tool_call`，无服务端自动预取/规划深挖。

### 畸形工具参数卡顿修复

真实页面复现到基金排行请求中，上游模型明确选择 `fa_get_fund_rank`，但返回畸形 arguments：`{"type":`。旧路径会先回填 `invalid_arguments_json`，再等待模型第二轮修正；第二轮非流式握手可能卡满 `tool_timeout=45s`，随后退到 `fallback_plain_stream`，导致页面长时间停留在“正在调用工具”。

本轮修复：

- 仅当模型已经明确选择同一工具时，服务端才对畸形 JSON 做窄范围参数修复。
- `fa_get_fund_rank` 按用户问题补齐 `type/period/page/page_size`，如“今天/今日/涨幅”推断 `period=day`，默认 `type=all`。
- 修复参数并真实执行工具后，直接进入最终流式回答，不再额外等待第二轮非流式工具握手。
- 无法安全补齐的畸形参数会直接进入最终回答，不再等待 45s 修正。

验证：

- 单元回归覆盖 `fa_get_fund_rank` 截断参数：只执行模型已选择的基金排行工具，`period=day`，无 `server_prefetch/server_planned`，无第二轮修正等待。
- 本地真实 SSE 基金问题：HTTP 200，约 42s 完成，`fa_get_fund_rank` 已执行，`invalid_arguments_json=false`，`fallback_plain_stream=false`，`run_finished` / `[DONE]` 正常。
- 重启 `http://127.0.0.1:8081/` 后首页 HTTP 200；短常识问答 6s 完成，无工具调用、无 fallback。

### 前端工具调用过程渲染修复

真实页面反馈中，工具调用过程被插入最终回答气泡顶部，视觉上和回答内容黏在一起；同时非流式工具决策阶段不会输出正文 token，容易误解为“没有先思考”。

本轮修复：

- 前端不再预先创建空 bot 回答气泡。
- 常规 `agent_status` 不再渲染进聊天正文，避免出现“AI 正在分析任务并决定下一步是否调用工具 / 工具观察已回填”这类内部过程气泡；仅保留参数修复、超时、回退、错误等异常类状态提示。
- 每条 `tool_status` 渲染为独立工具气泡，包含工具标题、调用说明、工具名和参数摘要。
- 工具参数摘要支持对象/数组压缩展示，避免候选列表显示为 `[object Object]`。
- 最终回答内容到达时才创建正式回答气泡，避免工具过程黏在回答顶部。
- 保留头部状态文本更新，但不再用工具状态覆盖“思考中...” loading 气泡。
- 畸形参数修复不隐藏：前端如实显示 `模型工具参数 JSON 不完整，已按同一工具意图补齐必要参数继续执行`。
- `tool_decision_max_tokens` 调整为 4096：非流式工具决策轮保留足够预算，避免多轮工具观察后出现 `finish_reason=length`；最终回答流仍使用 `max_tokens`。
- 真实验证结论：原默认 `openai/mimo-v2.5-free` 渠道在基金深入研究任务中第二轮工具决策持续超时，90s 预算下仍只完成 `fa_get_fund_rank` 后进入 `fallback_plain_stream`，不能稳定独立完成该多轮工具任务。
- 已将本地默认渠道切到 `deepseek/deepseek-chat`。真实 `8081/ai_api.php` 同句基金任务 34.3s 完成，`run_finished/[DONE]` 正常，模型自主调用 `fa_get_fund_rank`、`fa_get_fund_info`、`fa_get_fund_estimate`、`fa_get_fund_history` 等工具，`invalid_arguments_json=false`，`fallback_plain_stream=false`，无 `server_prefetch/server_planned`。
- 真实 `8081/ai_api.php` 复测基金任务：HTTP 200，约 46s，`run_finished/[DONE]` 正常，`fa_get_fund_rank` 真实执行，`tool_status/tool_call_started/tool_call_finished` 各 1 次，`invalid_arguments_json=false`，`fallback_plain_stream=false`，无 `server_prefetch/server_planned`。
- 已修正 profile 工具策略：所有档案都暴露完整只读工具集，基金研究档案也允许在用户问题需要时调用股票、板块、雪球热度和选股工具；工具选择按任务相关性决定，不按“基金版本/股票版本”裁剪。
- 最新真实 `8081/ai_api.php` 复测同句纯基金任务：HTTP 200，31s 完成，`run_finished/[DONE]` 正常，`tool_status/tool_call_started/tool_call_finished` 各 7 次且数量一致；模型按任务相关性只使用 `fa_get_fund_rank`、`fa_get_fund_info`、`fa_get_fund_estimate`、`fa_get_fund_history`；`invalid_arguments_json=false`、`finish_reason=length=false`、`fallback_plain_stream=false`、无 `server_prefetch/server_planned`。
- 最新真实 `8081/ai_api.php` 复测基金档案跨工具任务：用户在基金分析模块要求查询 `600519` 股票行情/资金流并同时查询今日涨幅靠前基金；HTTP 200，37.2s 完成，profile=`fund_researcher`，`tools_fully_available=true`，`run_finished/[DONE]` 正常；实际工具同时包含股票工具 `fa_get_stock_quote`、`fa_get_stock_flow`、`fa_calculate_kline_indicators`、`fa_get_stock_kline` 和基金工具 `fa_get_fund_rank`、`fa_get_fund_info`；`invalid_arguments_json=false`、`finish_reason=length=false`、`fallback_plain_stream=false`、无 `server_prefetch/server_planned`。
- 最新真实 `8081/ai_api.php` 复测行动前思考：同一基金档案跨工具任务 HTTP 200，26.4s 完成，profile=`fund_researcher`，`tools_fully_available=true`；事件中 `assistant_thought` 出现 2 次，且第一次 `assistant_thought` 在第一次 `tool_status` 之前；首条思考为“我先查询贵州茅台（600519）的最新行情和资金流，同时查询今日涨幅靠前的基金数据”；本次上游未返回 `reasoning_content` delta，但后端默认已不再过滤该字段。

## 官方规范校准

已参考官方资料，当前规划按以下原则收敛：

- OpenAI function calling 建议对工具参数启用 `strict: true`，并让 object schema 使用 `additionalProperties=false`、完整 `required` 字段：https://developers.openai.com/api/docs/guides/function-calling
- OpenAI Agents SDK 将 Agent + Runner 视为管理 turns、tools、guardrails、handoffs、sessions 的编排层；如果要自管循环，则应直接拥有模型调用、工具执行、状态和终止逻辑：https://openai.github.io/openai-agents-python/agents/
- OpenAI Agents SDK tracing 会记录一次 agent run 中的 LLM generation、tool calls、handoffs、guardrails 和 custom events；本项目应本地实现可复盘 trace，而不是只看前端文本：https://openai.github.io/openai-agents-python/tracing/

## 用户约束总表

以下约束来自本轮人工要求，作为后续 AI 顾问改造的硬性验收标准：

- 禁止服务端自动兜底工具调用：不允许 `server_prefetch`、`server_planned` 之类由服务端替模型决定的工具调用流。
- 模型驱动工具链：先由模型根据上下文判断是否需要工具；需要工具时只能通过正式 `tools/tool_calls` 请求；模型无工具调用时直接回答。
- 常识性问答不强行调用工具：例如“什么是市盈率”等解释类问题应允许无工具完成。
- 所有可用只读工具都暴露给 AI 顾问：不因 `advisor`、`fund_researcher`、`market_scanner` 等档案裁剪工具。
- 基金档案也能使用股票相关工具：即使当前问题被识别为基金研究，只要用户问题需要，也可以调用股票行情、K线、资金流、板块、雪球热度和选股工具来解决问题。
- 工具选择按任务相关性决定：不能为了展示能力乱调无关工具，也不能因为“基金版本/股票版本”阻止相关工具。
- 异常不隐藏：畸形工具 JSON、超时、回退、上游失败等需要保留可见或可追踪信号；不能只靠前端隐藏问题。
- 推理流默认展示：上游返回的 `reasoning_content` 默认透传到前端；如需隐藏只能显式设置 `suppress_reasoning_content=true`。
- 行动前必须有可见思路：每轮工具调用前先展示 `assistant_thought`，优先使用模型返回的 assistant content；模型未给出时，根据即将调用的具体工具生成简短行动说明。
- 前端不显示内部编排废话：不再把“AI 正在分析任务并决定下一步是否调用工具”“工具观察已回填，继续让 AI 决定下一步”等内部状态当聊天消息展示。
- 工具调用展示要独立、清晰：每次 `tool_status` 作为独立工具气泡展示，参数摘要应可读，不能黏在最终回答顶部。
- 真实链路通过后再交付：除单元测试外，必须用本地 `8081/ai_api.php` 或同等真实 SSE 链路验证 HTTP、`run_finished`、`[DONE]`、工具事件、fallback、参数异常和实际工具列表。
- OpenAI tools 文档把 function calling、内置工具、MCP/connectors、tool search 视为扩展模型能力的工具体系。本项目当前不做“按意图隐藏工具”，改由运行时预算、只读工具、schema、trace 和 guardrail 管控能力边界：https://developers.openai.com/api/docs/guides/tools

## 核心结论

下一步不应该只是加提示词，也不应该依赖模型在正文里输出某个“终止符”。正确方向是定义服务端智能体事件协议和运行时终止条件。

智能体应该像 Codex / Claude Code 那样工作：

1. 接收用户任务。
2. 调用模型生成下一步行动。
3. 如果模型请求工具，服务端执行工具。
4. 把工具观察结果回填给模型。
5. 模型继续决定下一步。
6. 达到完成条件、预算上限、错误终止或用户取消时，由运行时结束任务。
7. 前端收到明确的 `run_finished` / `[DONE]` 事件。

## 是否需要终止标志

需要，但不应该只靠模型文本里的终止符。

### 不推荐

- 不推荐让模型在回答末尾输出类似 `<END>`、`DONE`、`任务完成` 作为唯一终止依据。
- 不推荐让前端通过正文内容判断智能体是否结束。
- 不推荐把“思考过程”直接作为普通回答文本流给用户。

原因：

- 模型可能漏写终止符。
- 用户问题里可能包含类似终止符文本。
- 工具调用和自然语言输出会混在一起。
- 前端难以区分“模型还在想”、“工具正在执行”、“最终回答已完成”。

### 推荐

采用运行时事件协议：

```text
run_started
assistant_delta
tool_call_started
tool_call_finished
checkpoint_created
agent_status
final_answer_started
final_answer_delta
final_answer_finished
run_finished
run_failed
```

其中：

- 文本输出由 `assistant_delta` 或 `final_answer_delta` 承载。
- 工具调用由 `tool_call_started` / `tool_call_finished` 承载。
- 运行状态由 `agent_status` 承载。
- 终止由 `run_finished` 或 `run_failed` 承载。
- SSE 兼容层最后仍发送 `data: [DONE]`，用于兼容当前前端流式解析。

## 文本 / 思考 / 工具调用输出规范

### 用户可见文本

- 只展示最终回答和简短状态。
- 最终回答必须区分：
  - 数据事实
  - 基于数据的推断
  - 不确定性
  - 风险提示
- 金融回答结尾必须包含：内容仅供研究参考，不构成投资建议。

### 思考过程

- 不向用户展示模型原始隐藏推理。
- 后端默认剥离上游 SSE 中的 `reasoning_content` 字段。
- 可以展示简短、可审计的状态摘要，例如：
  - 正在获取资金流向前 10
  - 正在分析候选股 K 线指标
  - 正在汇总风险因素
- 状态摘要由服务端生成或由模型输出到结构化字段，不作为最终回答正文的一部分。

### 工具调用

每次工具调用必须结构化记录：

```json
{
  "type": "tool_call_started",
  "run_id": "...",
  "round": 2,
  "tool_call_id": "...",
  "tool": "fa_get_stock_quote",
  "origin": "model_tool_call",
  "args_summary": {
    "codes": ["600519"]
  }
}
```

工具结束事件：

```json
{
  "type": "tool_call_finished",
  "run_id": "...",
  "round": 2,
  "tool_call_id": "...",
  "tool": "fa_get_stock_quote",
  "success": true,
  "duration_ms": 183,
  "output_summary": {
    "rows": 1,
    "source": "eastmoney"
  }
}
```

### 最终终止

最终结束事件：

```json
{
  "type": "run_finished",
  "run_id": "...",
  "stop_reason": "final_answer",
  "rounds": 4,
  "tool_calls": 23,
  "elapsed_ms": 12840
}
```

失败结束事件：

```json
{
  "type": "run_failed",
  "run_id": "...",
  "stop_reason": "tool_budget_exceeded",
  "message": "工具调用次数达到上限，已基于已有数据给出阶段性结论。"
}
```

## 停止条件设计

智能体运行时应该支持这些停止条件：

- [ ] `final_answer`：模型已给出最终回答。
- [ ] `user_cancelled`：用户取消请求。
- [ ] `max_rounds`：模型-工具循环轮次达到上限。
- [ ] `max_tool_calls`：工具调用次数达到上限。
- [ ] `max_elapsed_time`：总耗时达到上限。
- [ ] `max_context_chars`：工具结果上下文达到上限。
- [ ] `duplicate_loop_detected`：检测到重复工具调用循环。
- [ ] `no_progress`：连续多轮没有新增有效信息。
- [ ] `upstream_error`：上游模型接口失败。
- [ ] `tool_budget_exceeded`：工具预算不足，只能输出阶段性结论。
- [ ] `guardrail_blocked`：触发金融安全护栏。

## 目标架构

```text
ai_api.php
  -> AgentGateway
      -> AgentRuntime
          -> AgentProfile
          -> AgentState
          -> ModelAdapter
              -> ChatCompletionsAdapter
              -> ResponsesAdapter
          -> ToolRuntime
              -> AIToolExecutor
          -> PlanningPolicy
          -> ContextBuilder
          -> TraceRecorder
          -> CheckpointManager
          -> GuardrailPolicy
          -> StreamEmitter
```

## 模块职责

### AgentGateway

- [ ] 接收 `ai_api.php` 请求。
- [ ] 校验 messages、session、profile、配置。
- [ ] 创建 `run_id`。
- [ ] 初始化运行时。
- [ ] 连接 SSE 输出。

### AgentRuntime

- [ ] 负责主循环。
- [ ] 调用模型。
- [ ] 解析工具调用。
- [ ] 调用 `ToolRuntime`。
- [ ] 把工具结果回填给模型。
- [ ] 判断是否继续、结束、降级或失败。

### AgentState

- [ ] 保存 `run_id`。
- [ ] 保存当前轮次。
- [ ] 保存消息历史。
- [ ] 保存工具调用记录。
- [ ] 保存已执行调用签名，防止重复循环。
- [ ] 保存预算使用情况。
- [ ] 保存停止原因。

### ModelAdapter

- [x] 屏蔽 Chat Completions / OpenAI-compatible endpoint 差异。
- [x] 提供非流式工具握手。
- [x] 提供最终回答流式输出。
- [x] 负责适配 Chat Completions tool call / function call。
- [ ] 增加 Responses API adapter。

### ToolRuntime

- [x] 解码工具参数。
- [x] 校验工具名称。
- [x] 执行 `AIToolExecutor`。
- [x] 控制工具调用次数。
- [x] 做重复调用去重。
- [x] 输出结构化工具结果。
- [x] 生成工具事件。

### Model Decision Loop

- [x] 不隐藏工具。
- [x] 观察工具结果后由模型决定是否继续。
- [x] 禁止服务端自动兜底预取和规划深挖工具调用。
- [x] 常识性问答允许模型直接回答并终止。
- [x] 工具参数异常只要求模型修正或基于已有信息回答。

### StreamEmitter

- [x] 统一生成 SSE。
- [x] 兼容当前 `tool_status`。
- [x] 新增更规范的 agent 事件。
- [x] 确保关键路径输出 `[DONE]`。
- [x] 避免关键错误路径只输出 error 而不终止。

### TraceRecorder

- [x] 记录每个 run 的事件。
- [x] 记录工具调用耗时和结果摘要。
- [x] 记录停止原因。
- [x] 支持调试“为什么没有继续调用工具”。

### CheckpointManager

- [x] 每轮模型响应后创建 checkpoint。
- [x] 每批工具调用后创建 checkpoint。
- [ ] 如果后续模型响应无效，可以回退到最后一个有效观察点。

### GuardrailPolicy

- [x] 禁止承诺收益。
- [x] 禁止确定性买卖点。
- [x] 要求区分事实、推断、不确定性。
- [x] 要求最终风险提示。
- [x] 工具保持只读。

## Phase 0 - 基线与验证

- [x] 确认当前 AI 工具测试通过。
- [x] 统计当前工具数量：16 个只读金融工具。
- [x] 模拟市场扫描提示词，确认正常路径深度不足。
- [x] 增加专门回归测试：`查询资金流向前10的股票，充分深入评估它们，给出建议`。
- [x] 测试正常路径能在观察前 10 后继续深挖候选。
- [x] 测试最终回答确实拿到深挖上下文，而不是只有热榜上下文。
- [x] 测试无效上游工具参数不会触发服务端自动兜底工具。
- [x] 测试关键路径输出终止事件和 `[DONE]`。

## Phase 1 - 先拆运行时，不改变外部行为

- [x] 新建 `AgentOptions`，集中管理当前散落在 `AIChatToolAgent` 内的选项。
- [x] 新建 `AgentState`。
- [x] 新建 `ChatCompletionsAdapter`。
- [x] 新建 `StreamEmitter`。
- [x] 新建 `ToolRuntime`。
- [x] 新建 `AgentProfile`。
- [x] 新建 `TraceRecorder`。
- [x] 新建 `CheckpointManager`。
- [x] 保留 `AIChatToolAgent` 作为兼容 facade。
- [x] `ai_api.php` 透传运行时配置和结构化事件选项。
- [x] 保持前端 SSE 行为不变。
- [x] 跑通 `.\php\php.exe tests\ai_tool_agent_tests.php`。

## Phase 2 - 规范 Agent 事件协议

- [x] 定义服务端内部事件类型：
  - [x] `run_started`
  - [x] `agent_status`
  - [x] `assistant_delta`
  - [x] `tool_call_started`
  - [x] `tool_call_finished`
  - [x] `checkpoint_created`
  - [x] `final_answer_started`
  - [ ] `final_answer_delta`
  - [x] `final_answer_finished`
  - [x] `run_finished`
  - [x] `run_failed`
- [x] 继续兼容前端已有 `tool_status`。
- [x] 最终流结束统一输出 `data: [DONE]`。
- [x] 关键错误流输出 `run_failed` 和 `[DONE]`。
- [x] 前端逐步从只识别 `tool_status` 升级为识别 agent events。
- [x] 后端默认抑制原始 `reasoning_content` 透传。

## Phase 3 - Observe-Then-Act 智能体循环

- [x] 将 `stream_after_tool_round` 后的一轮直出改造成模型可观察工具结果后继续决策或最终输出。
- [x] 支持完整多轮模型-工具循环。
- [x] 市场扫描提示与循环：
  - [x] 引导模型先取资金流向前 N。
  - [x] 观察返回代码。
  - [x] 由模型决定是否对候选执行行情、技术指标、资金流。
  - [x] 根据预算由模型控制深挖范围。
  - [ ] 可选调用 `fa_compare_candidates` 做确定性排序。
- [x] 个股研究提示：只读工具全部可用，由模型决定行情、指标、资金流是否仍需查询。
- [x] 基金研究提示：无基金代码的今日基金涨幅/排行请求引导模型优先查 `fa_get_fund_rank`，候选补充仍由模型主动请求。
- [x] 检测重复调用循环。
- [ ] 检测无进展循环。

## Phase 4 - 预算与终止条件

- [ ] 增加 `max_model_rounds`。
- [x] 增加 `max_tool_calls_total`。
- [x] 增加 `max_deep_dive_candidates`。
- [ ] 增加 `max_tool_context_chars`。
- [ ] 增加 `max_elapsed_seconds`。
- [ ] 增加 `max_duplicate_calls`。
- [ ] 增加 `min_progress_required`。
- [x] 每次关键终止都写入 `stop_reason`。
- [x] 前端展示终止原因的用户友好文案。

## Phase 5 - Agent Profile，但不隐藏工具

- [x] 新建 `AgentProfile`。
- [x] 默认 `advisor`：通用金融研究顾问。
- [x] `market_scanner`：市场扫描和候选排序。
- [x] `fund_researcher`：基金研究。
- [x] `risk_reviewer`：风险审查。
- [x] 所有 profile 都可访问全部工具。
- [x] profile 只调整：
  - [x] 系统提示词。
  - [x] 深挖预算提示。
  - [x] 输出格式倾向。
  - [x] 风险提示要求。
  - [x] 继续规划策略。

## Phase 6 - Trace、Checkpoint 与可观测性

- [x] 每个请求生成 `run_id`。
- [x] 每个工具调用生成 `tool_call_id`。
- [x] 记录事件时间线。
- [x] 记录工具输出摘要。
- [x] 记录错误与普通流式回退。
- [x] 记录每次终止原因。
- [x] 配置项控制是否落盘 trace。
- [x] 支持通过日志复盘一次智能体运行。

## Phase 7 - Responses API 适配

- [ ] 新建 `ResponsesAdapter`。
- [ ] 保留 `ChatCompletionsAdapter` 给 DeepSeek / OpenAI-compatible 端点。
- [ ] 配置增加：
  - [ ] `ai.channels.<name>.api_type = chat_completions | responses`
  - [ ] `ai.channels.<name>.supports_tools`
  - [ ] `ai.channels.<name>.supports_parallel_tool_calls`
- [ ] 正确适配 Responses API 的 function call output。
- [ ] 为两个 adapter 都写 fake transport 测试。

## Phase 8 - 评测集

- [ ] 新建金融任务 eval fixture。
- [ ] 覆盖市场扫描：
  - [ ] 资金流向前 10。
  - [ ] 板块轮动。
  - [ ] 雪球热股对比。
  - [ ] 多股比较。
- [ ] 覆盖个股研究：
  - [ ] 行情 + 技术面。
  - [ ] 资金流。
  - [ ] 趋势不确定性。
- [ ] 覆盖基金研究：
  - [ ] 基金资料。
  - [ ] 估值和历史净值。
  - [ ] 同类排名。
- [ ] 评估维度：
  - [ ] 是否调用正确工具。
  - [ ] 是否使用工具观察结果。
  - [ ] 是否编造实时数据。
  - [ ] 是否区分事实、推断、不确定性。
  - [ ] 是否包含风险提示。
  - [ ] 是否避免确定性买卖建议。
  - [ ] 是否正确终止。

## Phase 9 - 可选 Subagents

先不做。主运行时稳定后再考虑。

可选方向：

- [ ] 市场扫描子智能体。
- [ ] 候选股深挖子智能体。
- [ ] 风险审查子智能体。
- [ ] 最终综合子智能体。

限制：

- [ ] 子智能体仍然只能使用只读工具。
- [ ] 子智能体共享同一套 trace 和预算。
- [ ] 只有在延迟和上下文预算允许时启用。

## 实施备注

- `AIChatToolAgent` 应该逐步变成兼容 facade，而不是长期承担所有编排职责。
- `AIToolExecutor` 继续作为底层工具执行层保留。
- `AIFinanceToolCatalog` 继续作为完整能力目录保留。
- `auto_prefetch` 已废弃，服务端不再执行自动预取或规划深挖工具调用。
- `parallel_tool_calls` 当前只是允许模型一次请求多个工具，PHP 服务端仍是顺序执行；命名和文档要避免误导。
- 第一阶段验收目标：正常市场扫描路径必须由模型通过多轮工具循环自主完成。
- 第二阶段验收目标：所有运行路径都有明确 `stop_reason`、`run_finished/run_failed` 和 `[DONE]`。
