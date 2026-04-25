# xueqiu2s

## 这是什么

这个目录当前保存的是 **雪球多接口访问条件验证与字段提取样本集**，不是完整爬虫，也不是批量接口 SDK。

现阶段已经有一组可复用结论：

- 已现场验证成功的接口包括：`statuses/fundx/public/list.json`、`quote`、`kline`、`hot_stock`、`screener`
- 这些接口当前都依赖 **先预热 `/hq` 页面获取匿名会话 cookie**
- `md5__1038` 仍然是需要理解的 WAF 挑战参数，但 **不是所有当前可用接口都硬依赖它**
- 对当前样例接口，脚本会先尝试 unsigned 请求；若 unsigned 已能返回 JSON，则直接跳过 signer
- 已新增 Python 提取脚本与中文字段映射输出，覆盖本轮已验证可用 API

项目里的文档和脚本已经不再只围绕单接口 PoC，而是围绕“多接口可用性 + 访问条件 + 中文字段映射”展开，目标是继续沉淀成更稳定的接口条件矩阵。

---

## 当前目录内容

| 文件 | 用途 |
| --- | --- |
| `xueqiu_md5_flow.py` | 当前主验证脚本：预热 `/hq`、探测 unsigned、必要时运行 signer、最终校验并落盘 JSON |
| `xueqiup.txt` | 当前待验证接口 URL 样例（首行生效） |
| `xueqiu_response.json` | 最近一次成功抓到的接口返回样例 |
| `md5__1038_原理与会话预热说明.md` | 对 `md5__1038`、WAF 挑战、`/hq` 预热原因的说明 |
| `tmp_run_waf3.js` | 本地运行挑战脚本、抓取带 `md5__1038` URL 的 Node 脚本 |
| `tmp_waf_inline.js` | WAF 相关辅助脚本 |
| `docs/next_step_plan.md` | 下一阶段建议：验证器抽象、样本集、访问条件矩阵 |
| `docs/api_inventory.md` | 当前接口清单与状态说明 |
| `docs/sample_index.md` | 当前项目样例索引 |

---

## 当前已确认结论

### 可用结论（已被现场产物支撑）

1. **`/hq` 预热比 `/` 预热更关键**  
   当前说明文档明确指出，仅访问首页通常只拿到部分 cookie；访问 `/hq` 更容易补齐匿名会话所需 cookie。

2. **`md5__1038` 需要按挑战上下文动态生成，但不是所有接口都硬依赖它**  
   不是简单复现一个静态 MD5 公式，而是通过执行挑战脚本，从脚本改写后的 URL 中提取参数；同时，本轮已验证的多条可用接口在预热后可直接返回 JSON，不必统一走 signer。

3. **当前已经有多接口真实 JSON 样本**  
   `samples/` 目录已落盘 `fundx`、`quote`、`kline`、`hot_stock`、`screener` 的实际返回数据，不再只是单个 `fundx` 样例跑通。

4. **脚本已经加入 unsigned 优先策略**  
   `xueqiu_md5_flow.py` 会先探测去掉旧 `md5__1038` 的 URL 是否已可直接返回 JSON；只有遇到 challenge HTML 才继续 signer 路径。

5. **已补齐中文字段映射产物**  
   `extract_chinese_fields.py` 与 `outputs/xueqiu_chinese_fields.json` 已基于本轮已验证可用 API 提取有用字段，并转换为接近“中文名: 数据”的结构化结果。

### 仍未完成的部分

- 还没有完整的批量接口验证器
- 还没有稳定的 access matrix（访问条件矩阵）成品
- 搜索类接口仍不能按行情类接口的策略默认视为可用
- `search/status` 等搜索类接口已明确排除，不纳入本轮主线

所以现在更准确的说法是：**已完成多接口访问样本与中文字段映射的首轮沉淀，但尚未完成全站接口族能力整理。**

---

## 最小使用说明

### 1. 准备环境

需要本机可用：

- Python 3
- Node.js
- Python 依赖：`requests`

### 2. 配置目标 URL

把待验证的接口 URL 写到 `xueqiup.txt` 第一行。当前样例是：

- `https://xueqiu.com/statuses/fundx/public/list.json?...`

脚本会自动：

- 读取第一条非空 URL
- 去掉旧 `md5__1038`
- 先访问 `https://xueqiu.com/hq`
- 探测 unsigned 是否已返回 JSON
- 若仍是 challenge HTML，再调用 Node signer 动态生成新 `md5__1038`

### 3. 运行

```bash
python3 xueqiu_md5_flow.py
```

### 4. 中文字段结果生成

运行：

```bash
python3 extract_chinese_fields.py
```

会生成：

- `outputs/xueqiu_chinese_fields.json`

该文件现在按接口给出：

- `字段映射`：英文原字段 → 中文字段名
- `示例数据`：真正接近“中文名: 数据”的中文结构化结果

### 5. 成功判定

至少满足两点：

- 终端输出显示最终校验为 JSON 成功
- 本地生成或更新 `xueqiu_response.json` / `outputs/xueqiu_chinese_fields.json`

---

## 当前接口状态总览

详见：`docs/api_inventory.md`

简版结论：

- **可用（已现场验证）**：`statuses/fundx/public/list.json`、`quote`、`kline`、`hot_stock`、`screener`
- **受限（已确认存在访问条件）**：任何直接命中 challenge 的接口都需要会话预热；搜索类接口当前仍可能继续依赖更强的挑战处理
- **明确排除本轮主线**：`search/status` 及其他搜索类接口
- **当前中文字段主成果**：`outputs/xueqiu_chinese_fields.json`，已按更直接的“中文名: 数据”风格整理
- **待继续扩样**：`user related` 等尚未形成真实样本与矩阵结论的接口

---

## 建议下一步

优先级已经很明确：

1. 把现有 PoC 抽成“接口访问条件验证器”
2. 建一个 6~12 条的首批接口样本集
3. 输出机器可读结果 + 人类可读矩阵
4. 再决定哪些接口要继续保留 signer 路线

也就是说，下一步不该继续死磕单个 `md5__1038`，而该把结论产品化。
品化。
