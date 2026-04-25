# xueqiu2s 接口清单（2026-03-21 现场探测版）

> 说明：本清单只写 **2026-03-21 在项目目录里现场跑出来的真实结果**，不猜测未验证接口。

## 1. 本轮已验证成功接口

这些接口都在同一轮会话预热后直接返回了业务 JSON，并已把样本落到 `samples/` 目录。

| 接口 | 分类 | 当前状态 | 访问条件 | 样本 |
| --- | --- | --- | --- | --- |
| `https://xueqiu.com/statuses/fundx/public/list.json` | fundx/public feed | 成功 | 先预热 `/hq`；当前样本下 **unsigned 也能直接返回 JSON** | `samples/fundx_public_list.json` |
| `https://stock.xueqiu.com/v5/stock/quote.json?symbol=SH600519&extend=detail` | quote 详情 | 成功 | 先预热 `/hq` 后可直接访问 | `samples/quote_detail_sh600519.json` |
| `https://stock.xueqiu.com/v5/stock/chart/kline.json?...` | K 线 | 成功 | 先预热 `/hq` 后可直接访问 | `samples/kline_sh600519_day.json` |
| `https://stock.xueqiu.com/v5/stock/hot_stock/list.json?...` | 热门股票列表 | 成功 | 先预热 `/hq` 后可直接访问 | `samples/hot_stock_cn.json` |
| `https://stock.xueqiu.com/v5/stock/screener/quote/list.json?...` | 条件选股列表 | 成功 | 参数正确时，预热后可直接访问 | `samples/screener_quote_list.json` |

## 2. 本轮明确受限接口

| 接口 | 当前状态 | 失败模式 | 备注 |
| --- | --- | --- | --- |
| `https://xueqiu.com/query/v1/search/status.json?...` | 受限 | 返回 `text/html` challenge 页，页面含 `aliyunwaf_` / `renderData` | 说明搜索类接口至少当前这轮仍受 WAF 挑战保护 |

## 3. 本轮新增/修正结论

### 3.1 比旧结论更重要的新发现

1. **不是所有可用接口都还依赖 `md5__1038`。**  
   本轮 `fundx/public/list` 在去掉旧 `md5__1038` 后仍可直接返回 JSON，说明至少该接口当前更像是“会话预热优先”，而不是“签名硬依赖”。

2. **`stock.xueqiu.com/v5/...` 这批行情类接口明显更容易直接成功。**  
   本轮 `quote`、`kline`、`hot_stock`、`screener` 都在 `/hq` 预热后直接返回业务 JSON，且 `error_code = 0`。

3. **搜索类接口和行情类接口的防护强度不一样。**  
   同样是预热后访问，`search/status` 仍然命中 WAF challenge，而多条 `v5/stock` 接口直接成功，说明后续要按接口族拆策略，不能拿一个 PoC 套全站。

### 3.2 仍然成立的旧结论

| 限制项 | 当前结论 |
| --- | --- |
| 匿名会话 cookie | 仍然关键；本轮所有成功接口都先做了 `/hq` 预热 |
| 挑战页识别 | 返回 `text/html` 且含 `aliyunwaf_` / `renderData` 时，应按 challenge 处理 |
| JSON 成功判定 | 不能只看 `application/json`，还要检查业务 `error_code` 是否为 0 或不存在 |

## 4. 当前最靠谱的使用边界

现在已经不只是单接口 PoC 了，而是一个 **“已验证 5 条成功样本 + 1 条受限样本” 的现场样本集**。但它仍然不是完整 SDK，边界应该这样理解：

- 可以把 `fundx`、`quote`、`kline`、`hot_stock`、`screener` 当成已验证可访问样本
- 不要把 `search` 也默认算成同策略可访问
- 下一步应继续按接口族扩大样本，而不是继续围绕单个 `md5__1038` 打转
