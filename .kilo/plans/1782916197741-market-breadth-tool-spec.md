# fa_get_market_breadth 市场宽度工具 Spec / Todo

## 背景

当前 AI 顾问已暴露 16 个只读金融研究工具，工具目录在 `lib/AIFinanceToolCatalog.php`，执行映射在 `lib/AIToolExecutor.php`，服务层主要复用 `MarketDataService`、`EastmoneyClient`、`XueqiuClient`、`FundService`。

本计划针对新增高优先级工具：`fa_get_market_breadth`，用于给 AI 顾问提供大盘环境、市场宽度、涨跌家数、涨停/跌停统计与指数概览。

## 目标

- 新增只读 AI 工具 `fa_get_market_breadth`。
- 优先复用东方财富公开行情接口与现有缓存/熔断/安全白名单体系。
- 让 AI 在个股研究、市场扫描、基金研究中可先判断市场环境。
- 输出结构化、可复盘、带统计口径说明的数据，不夸大为交易信号。

## 非目标

- 不接入交易、账户、自选股写入或任意 URL 访问。
- 首期不做前端 UI，除非后续明确要求。
- 首期不保证“精确涨停/跌停家数”覆盖所有特殊涨跌幅规则，需在响应中标注统计口径。

## 核心设计决策

1. **工具命名**：`fa_get_market_breadth`。
2. **数据源优先级**：东方财富为主；雪球仅作为未来可选补充，不作为首期依赖。
3. **统计分层**：
   - Phase 1：指数概览 + 指数返回的上涨/下跌/平盘家数字段。
   - Phase 2：分页扫描 A 股列表，计算全市场上涨/下跌/平盘与近似涨停/跌停统计。
4. **安全边界**：仅接受枚举参数；不允许传入 URL、Shell、文件路径或自由过滤表达式。
5. **输出原则**：返回 `success/source/action/data/meta`，失败走结构化错误，不中断 AI 研究链。

## 进一步评估结论

该工具建议按“先可用、再完整”的方式推进：Phase 1 先交付指数概览和指数口径涨跌家数，Phase 2 再打开全市场分页扫描与近似涨停/跌停统计。这样能把新增能力控制在现有 `EastmoneyClient -> MarketDataService -> AIToolExecutor -> AIChatToolAgent` 链路内，不引入新依赖、不扩展写权限，也不影响现有前端。

当前代码基础适配度较高：

- `EastmoneyClient` 已有 `ulist.np/get`、`clist/get` 调用模式、熔断器和字段归一化风格。
- `MarketDataService` 已有统一 `useCache()`、negative cache、stale fallback、防击穿锁，适合新增 `market_breadth` 缓存桶。
- AI 工具注册、strict schema、执行器参数清洗、工具状态文案和系统提示均已有固定位置。
- 测试入口已覆盖工具注册、strict schema、执行器、Agent 工具循环，新增用例成本可控。

当前上游快速探测记录（2026-07-01，本地网络请求）：

- `ulist.np/get` 对 `1.000001,0.399001,0.399006,1.000688` 返回了 `f104/f105/f106`，可作为指数内部上涨/下跌/平盘家数口径。
- `clist/get` 使用现有全 A `fs=m:0+t:6,m:0+t:80,m:1+t:2,m:1+t:23` 返回了 `total=5534` 及 `f2,f3,f12,f13,f14`，按 `pz=200` 约 28 页，分页扫描请求量在可控范围内。
- 以上只证明当前接口可用，不保证长期稳定；实现时仍必须保留解析失败、字段缺失、空列表和 partial fallback。

## 能力边界与可控范围

- **Phase 1 可发布 MVP**：只依赖指数 `ulist.np/get`。当 `include_limit_stats=true` 但 Phase 2 尚未实现时，返回 `limit_stats=null` 或 `limit_stats.method=not_calculated`，并在 `meta.capability_level=indices_only` 说明未做全市场扫描。
- **Phase 2 完整版**：开启全市场分页扫描。默认最多 40 页、每页 200 条；若上游 `total` 异常、页数超限或中途失败，返回已完成的指数概览，并设置 `meta.partial=true`、`meta.failures[]`。
- **涨停/跌停口径**：首期只做涨跌幅阈值近似，不识别 ST、北交所、科创/创业板、新股等精确规则；不得在提示词或文档中称为精确涨停统计。
- **AI 使用边界**：市场宽度只作为市场环境事实，不作为确定性交易信号；最终回答仍需提示研究参考、不构成投资建议。

## 阶段门禁

- **Phase 0 出口**：确认接口字段、分页规模、缓存 TTL、统计口径说明，形成可执行方案；不改业务代码也可完成。
- **Phase 1 出口**：`MarketDataService::marketBreadth()` 能返回指数概览；上游失败走结构化错误；缓存命中、负缓存、stale fallback 行为与现有服务一致。
- **Phase 2 出口**：全市场聚合与近似涨跌停统计可选启用；分页失败不拖垮整个工具；响应中明确 `method`、`sample_scope`、`partial`。
- **Phase 3 出口**：AI 工具可被注册、执行、识别为有效研究结果；系统提示能引导“大盘环境/市场宽度/涨跌家数”问题优先调用该工具。
- **Phase 4 出口**：所有新增入口仍只接受枚举/布尔参数；普通 HTTP API 如未明确需要，不新增。
- **Phase 5 出口**：本地测试脚本通过；至少有一次真实上游手工验证；失败路径可复现。
- **Phase 6 出口**：README 和工具说明同步，且所有口径限制写清楚。

## 参考工具 Schema 

```php
'fa_get_market_breadth' => AIToolSchema::tool(
    'fa_get_market_breadth',
    'Get A-share market breadth, major index overview, advance/decline counts, and optional limit-up/limit-down breadth statistics.',
    [
        'scope' => AIToolSchema::nullableEnum(['a_share', 'sh', 'sz', 'core_indices'], 'Market scope. Default a_share.'),
        'include_limit_stats' => ['type' => ['boolean', 'null'], 'description' => 'Whether to include approximate limit-up/limit-down statistics. Default true.'],
        'include_index_quotes' => ['type' => ['boolean', 'null'], 'description' => 'Whether to include major index quotes. Default true.'],
    ]
)
```

## 建议响应结构

```json
{
  "success": true,
  "source": "eastmoney",
  "action": "market_breadth",
  "data": {
    "scope": "a_share",
    "generated_at": "2026-07-01T00:00:00+08:00",
    "indices": [
      {
        "code": "000001",
        "market": "SH",
        "name": "上证指数",
        "price": 0,
        "change_pct": 0,
        "up_count": 0,
        "down_count": 0,
        "flat_count": 0,
        "total_count": 0,
        "advance_decline_ratio": null
      }
    ],
    "aggregate": {
      "method": "full_a_share_scan",
      "up_count": 0,
      "down_count": 0,
      "flat_count": 0,
      "total_count": 0,
      "up_ratio_pct": 0,
      "down_ratio_pct": 0,
      "advance_decline_ratio": null,
      "breadth_score": 0,
      "sentiment_label": "neutral",
      "sample_scope": "a_share"
    },
    "limit_stats": {
      "method": "approx_by_pct_threshold",
      "limit_up_count": 0,
      "limit_down_count": 0,
      "near_limit_up_count": 0,
      "near_limit_down_count": 0,
      "note": "涨停/跌停统计为公开行情涨跌幅阈值近似口径，可能不完全覆盖 ST、北交所、上市新股等特殊规则。"
    }
  },
  "meta": {
    "cache": "miss",
    "duration_ms": 0,
    "updated_at": "2026-07-01T00:00:00+08:00",
    "capability_level": "full_scan",
    "partial": false,
    "failures": []
  }
}
```

## Phase 0：口径确认与基线检查

- [x] 快速确认东方财富 `ulist.np/get` 对主要指数返回 `f104/f105/f106`：上涨、下跌、平盘家数。（2026-07-01 本地探测通过）
- [x] 快速确认 `clist/get` 分页全 A 查询字段至少包括 `f2,f3,f12,f13,f14`，且返回 `total`。（2026-07-01 本地探测通过）
- [x] 确认缓存 TTL：建议 `market_breadth` 15-30 秒。
- [x] 明确 Phase 1 聚合口径写入 `aggregate.method`，避免把指数样本口径误称为全市场精确统计。
- [x] 决定发布默认能力：若 Phase 2 未合并，工具默认 `capability_level=indices_only`；若 Phase 2 已合并，`include_limit_stats=true` 才执行全市场扫描。
- [x] 固化字段缺失策略：任一指数缺失 `f104/f105/f106` 时该指数保留报价，涨跌家数字段置 `null`，并记录到 `meta.failures[]`。

## Phase 1：后端数据源与服务层

### EastmoneyClient

- [x] 在 `lib/EastmoneyClient.php` 新增 `marketBreadth(string $scope, bool $includeLimitStats, bool $includeIndexQuotes): DataSourceResult`.
- [x] 新增主要指数白名单，不接受外部任意 secid:
  - `1.000001` 上证指数
  - `0.399001` 深证成指
  - `0.399006` 创业板指
  - `1.000688` 科创50
  - 可选：`1.000300` 沪深300、`1.000016` 上证50
- [x] 通过 `ulist.np/get` 拉取字段：`f2,f3,f4,f6,f12,f13,f14,f104,f105,f106`。
- [x] 归一化指数项：价格、涨跌幅、成交额、上涨家数、下跌家数、平盘家数、涨跌比。
- [x] 对网络失败、解析失败、空数据返回结构化错误。
- [x] Phase 1 不做 `clist/get` 分页扫描；`aggregate.method=index_constituent_counts`，`meta.capability_level=indices_only`。
- [x] `scope=core_indices` 仅返回指数概览；`scope=sh/sz/a_share` 在 Phase 1 仍基于对应指数或核心指数集合说明样本口径。

### MarketDataService

- [x] 在 `lib/MarketDataService.php` 新增 `marketBreadth()`。
- [x] 新增缓存桶 `market_breadth`。
- [x] 复用 `useCache()`、negative cache、stale fallback、防击穿锁。
- [x] 缓存 key 包含 `scope/includeLimitStats/includeIndexQuotes`。
- [x] 在 `MarketDataService::CACHE_TTL` 与 `config.example.php` 增加 `market_breadth`，默认建议 20 秒。

## Phase 2：全市场分页统计与涨停/跌停近似值

- [x] 基于 `clist/get` 新增内部方法分页扫描 A 股列表。
- [x] 使用现有 A 股 `fs`：`m:0+t:6,m:0+t:80,m:1+t:2,m:1+t:23`。
- [x] 每页 `pz=200`，从返回 `total` 计算页数，设置最大页数保护，建议 `max_pages=40`。
- [x] 对 `scope=sh/sz/a_share` 保持明确映射；无法确认交易所过滤时，只允许 `a_share` 执行全市场扫描，`sh/sz` 降级为指数口径并说明。
- [x] 统计：
  - `f3 > 0` 上涨
  - `f3 < 0` 下跌
  - `f3 == 0` 平盘
  - `f3 >= 9.8` 近似涨停
  - `f3 <= -9.8` 近似跌停
  - `f3 >= 7` 接近涨停
  - `f3 <= -7` 接近跌停
- [x] 响应中必须写明 `limit_stats.method=approx_by_pct_threshold`。
- [x] 若分页扫描失败，允许返回指数概览并在 `meta.partial=true`、`meta.failures[]` 说明失败项。
- [x] 若个别股票 `f3` 不是数字、停牌或返回 `-`，计入 `unknown_count`，不纳入上涨/下跌/平盘分母，响应写明 `tradable_count`。
- [x] `breadth_score` 使用可复盘公式，例如 `round((up_ratio_pct - down_ratio_pct) / 2 + 50, 2)`，并在 `aggregate.method` 或文档中说明。

## Phase 3：AI 工具注册、执行与提示词联动

### 工具目录

- [x] 在 `lib/AIFinanceToolCatalog.php` 的 `stockMarketTools()` 中加入 `fa_get_market_breadth`。
- [x] 保持 strict schema：所有 object `additionalProperties=false`，全部字段进入 `required`，可选值使用 nullable。

### 执行器

- [x] 在 `lib/AIToolExecutor.php` `$handlers` 中加入：
  - `'fa_get_market_breadth' => 'executeMarketBreadth'`
- [x] 新增 `executeMarketBreadth()`:
  - `scope` 白名单：`a_share/sh/sz/core_indices`
  - `include_limit_stats` 默认 `true`
  - `include_index_quotes` 默认 `true`
  - 调用 `$this->market->marketBreadth(...)`
- [x] 复用 `fromResult()` 和输出截断。
- [x] 非法 `scope`、非布尔参数必须走结构化错误，不允许透传到数据源层。
- [x] 若 Phase 2 未实现，`include_limit_stats=true` 不应抛异常；应返回指数数据和能力说明。

### Agent 提示与状态

- [x] 在 `AIChatToolAgent::hasUsefulResearchToolResult()` 加入 `fa_get_market_breadth`。
- [x] 在 `AIChatToolAgent::shortToolPurpose()` 加入“市场宽度”。
- [x] 在系统提示中加入：涉及“大盘环境、市场情绪、涨跌家数、涨停跌停、市场宽度”时优先调用 `fa_get_market_breadth`。
- [x] 在 `AIAgentStreamEmitter::toolStatusText()` 加入“获取市场宽度”。
- [x] 在 `AIChatToolAgent::looksLikeMarketScanRequest()` 或新增识别逻辑中覆盖“大盘、市场宽度、涨跌家数、涨停、跌停、情绪、普涨、普跌”等关键词。
- [x] 市场扫描问题可先调用 `fa_get_market_breadth`，再调用 `fa_get_hot_stocks`；但个股代码明确且未询问大盘背景时不强制调用。

## Phase 4：安全、配置与接口可选暴露

- [x] 在 `SecurityAudit.php` 增加白名单：
  - `ALLOWED_MARKET_BREADTH_SCOPES = ['a_share', 'sh', 'sz', 'core_indices']`
- [x] 保持参数面只有 `scope/include_limit_stats/include_index_quotes`，不增加自由文本过滤、URL、字段名或排序表达式。
- [x] 如需要普通 HTTP API，在 `market_api.php` 新增 action：`market_breadth`。
- [x] 普通 API 参数同工具 schema，仍只允许枚举/布尔，不允许自由 URL 或表达式。
- [x] 默认不新增独立 `market_breadth_api.php`，除非前端 UI 后续需要。

## Phase 5：测试与验证

### 单元/脚本测试

- [x] 更新 `tests/ai_tool_agent_tests.php`:
  - 工具注册表包含 `fa_get_market_breadth`。
  - strict schema 校验仍通过。
  - FakeMarketDataService 增加 `marketBreadth()`。
  - `AIToolExecutor` 调用该工具成功。
  - 非法 `scope` 返回结构化错误。
- [x] 增加 Agent 行为测试：用户询问“市场宽度/涨跌家数/大盘情绪”时，工具集和提示词包含 `fa_get_market_breadth`，且该工具结果被视为 useful research result。
- [x] 更新 `tests/phase2_core_tests.php` 或新增测试覆盖缓存桶行为。
- [x] 确保已有测试仍通过：
  - `./php/php.exe tests/phase2_core_tests.php`
  - `./php/php.exe tests/ai_tool_agent_tests.php`
- [x] 可选新增 `tests/market_breadth_normalizer_tests.php`，用固定假数据覆盖指数归一化、分页聚合、unknown_count、partial fallback、breadth_score。

### 手工验证场景

- [x] “今天大盘环境怎么样，市场宽度如何？”应优先调用 `fa_get_market_breadth`。
- [x] “筛选资金流入前 10 的股票并结合市场情绪判断”应先/并行获取市场宽度，再调用热股或资金工具。
- [x] “分析 600519”可不强制调用市场宽度，但若用户问大盘背景应调用。
- [x] 上游东方财富失败时，AI 最终回答应说明工具失败项，不编造涨跌家数。
- [x] `./php/php.exe -r` 或临时脚本直接调用 `MarketDataService::marketBreadth('a_share', true, true)`，确认真实上游成功、缓存二次命中、返回字段完整。
- [x] 如暴露 HTTP API，访问 `market_api.php?action=market_breadth&scope=a_share` 返回 JSON；非法 `scope=foo` 返回安全错误。

## Phase 6：文档更新

- [x] 更新 `README.md` 的 AI 工具清单，新增 `fa_get_market_breadth`。
- [x] 更新 AI 顾问说明：支持市场宽度、涨跌家数、指数概览、近似涨跌停统计。
- [x] 若普通 API 暴露 `market_breadth`，更新 API 表格。
- [x] 文档中明确涨停/跌停为近似口径，特殊规则可能偏差。

## 风险与缓解

- **统计口径风险**：指数 `f104/f105/f106` 与全 A 分页统计口径不同。缓解：响应字段明确 `method` 与 `note`。
- **请求量风险**：全市场分页扫描可能触发上游限流。缓解：缓存 15-30 秒、最大页数保护、失败时 partial 返回。
- **特殊涨跌幅规则风险**：ST、北交所、科创/创业板、新股涨跌停规则不同。缓解：首期只做近似阈值，后续再引入板块/证券类型细分。
- **AI 误用风险**：市场宽度可能被模型解读为确定性买卖信号。缓解：系统提示与护栏继续要求区分事实、推断、不确定性。
- **默认参数风险**：`include_limit_stats=true` 会触发多页扫描。缓解：Phase 1 未完成全扫描时只返回能力说明；Phase 2 完成后依靠短 TTL、防击穿锁和页数上限控制请求量。
- **交易时段风险**：非交易时段、午间休市或上游延迟时数据可能停留在最近更新时间。缓解：返回 `generated_at/updated_at`，最终回答说明数据时间。

## 后续可扩展方向

- 引入更精确的涨停/跌停原因、连板、炸板率。
- 加入成交额分布、上涨中位数、涨跌幅分位数。
- 增加“市场风格宽度”：大盘/小盘、行业、概念维度。
- 前端增加 AI 顾问旁的市场环境卡片。
- 可补充 `market_heatmap_summary`：不返回明细，只返回行业/概念维度上涨占比与资金方向，作为后续独立工具或 `fa_get_market_breadth` 的扩展字段。
