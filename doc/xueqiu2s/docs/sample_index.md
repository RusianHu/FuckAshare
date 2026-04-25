# xueqiu2s 样本索引（2026-03-21 更新）

## 现有成功样本

| 文件 | 接口 | 说明 |
| --- | --- | --- |
| `xueqiu_response.json` | `statuses/fundx/public/list.json` | 现有 PoC 默认输出样本 |
| `samples/fundx_public_list.json` | `statuses/fundx/public/list.json` | 本轮重新用 unsigned + `/hq` 预热验证的成功样本 |
| `samples/quote_detail_sh600519.json` | `v5/stock/quote.json` | 茅台行情详情样本 |
| `samples/kline_sh600519_day.json` | `v5/stock/chart/kline.json` | 茅台日 K 线样本 |
| `samples/hot_stock_cn.json` | `v5/stock/hot_stock/list.json` | 热门股票列表样本 |
| `samples/screener_quote_list.json` | `v5/stock/screener/quote/list.json` | 条件选股列表样本 |

## 探测结果汇总

| 文件 | 用途 |
| --- | --- |
| `docs/probe_results.json` | 本轮批量探测结果，含成功 / challenge / 业务错误判定 |

## 本轮失败/受限样本说明

当前没有单独保存 challenge HTML 文件到长期目录；受限接口已在 `docs/probe_results.json` 中留有首屏前缀，可确认：

- `query/v1/search/status.json`：本轮命中 WAF challenge，未返回业务 JSON

如果下一轮要专门攻搜索接口，建议把 challenge HTML 与外链脚本一起按样本落盘。\n