# xueqiu2s 下一轮建议（基于 2026-03-21 现场探测）

## 本轮结论

这一轮最大的价值不是“又跑通一条接口”，而是把接口族差异跑出来了：

- `fundx/public/list`：预热后 unsigned 可直接成功
- `v5/stock/quote` / `kline` / `hot_stock` / `screener`：预热后可直接成功
- `query/v1/search/status`：仍然命中 WAF challenge

也就是说，下一轮最值得做的不是继续重复验证成功接口，而是**针对仍受限的接口族单独破题**。

## 推荐优先级

### P1：搜索类接口攻坚

目标：确认 `query/v1/search/*` 是否必须保留 signer/challenge 处理链路。

建议动作：

1. 把 challenge HTML、外链 JS、cookie 写入单独样本目录
2. 对 `search/status`、`search/stock`、`search/user` 分别做最小样本探测
3. 记录“预热后直接访问 / signer 后访问 / 多轮 challenge”三种结果
4. 产出搜索接口专属说明，不和 `v5/stock` 混写

### P2：整理成机器可读矩阵

目标：让后续脚本不用靠 README 猜接口行为。

建议新增一个矩阵文件，例如：

- `docs/access_matrix.json`

字段至少包含：

- `endpoint_family`
- `sample_url`
- `warmup_required`
- `unsigned_ok`
- `challenge_seen`
- `signer_required`
- `business_ok`
- `sample_file`
- `notes`

### P3：把 PoC 抽成批量验证器

目标：把“给一条 URL 手工试”升级成“喂一组样本自动出结果”。

建议新增：

- `samples/targets.txt` 或 `samples/targets.json`
- `probe_xueqiu_endpoints.py`

输出：

- 每条接口的探测结果
- 成功样本自动落盘
- 失败模式自动归类

## 不建议现在做的事

- 不建议现在就宣称“雪球接口已整体打通”
- 不建议继续围绕旧 `md5__1038` 结论写大段泛化说明
- 不建议在没有样本矩阵前封装统一 SDK
