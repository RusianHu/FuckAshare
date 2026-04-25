# 雪球搜索接口研究笔记

> 状态：受限，当前阶段不纳入产品 UI

## 已知情况

- 搜索类接口 (`/query/v1/search/status.json`, `/query/v1/search/stock.json`, `/query/v1/search/user.json`) 均返回 WAF challenge HTML
- 即使预热 `/hq` 后，搜索接口仍触发 `md5__1038` challenge
- challenge 需要执行 JS 计算签名，无法在 PHP 同步请求中完成

## 技术障碍

1. **WAF Challenge 机制**：搜索接口被阿里云 WAF 拦截，返回包含 `aliyunwaf_` 前缀参数的 HTML
2. **JS 签名计算**：challenge 要求在浏览器中执行 JS 计算签名值，再携带签名重新请求
3. **Node Signer**：理论上可以用 Node.js 执行 challenge JS，但：
   - 增加 PHP→Node 的进程间调用复杂度
   - challenge JS 可能包含反调试检测
   - 不适合同步 web 请求场景

## 可能的突破方向（待研究）

1. **Cookie 复用**：如果浏览器端已通过 challenge，能否将浏览器 cookie 传给后端？
   - 问题：涉及用户隐私，cookie 可能过期
2. **Puppeteer/Playwright**：用无头浏览器自动通过 challenge
   - 问题：资源消耗大，不适合轻量 PHP 架构
3. **逆向 WAF 算法**：分析 challenge JS 逻辑，在 PHP 中复现
   - 问题：维护成本高，WAF 更新后失效
4. **替代搜索源**：使用东方财富或其他数据源的搜索接口
   - 东方财富已有部分搜索能力，可作为替代

## 决策

- **当前阶段**：搜索接口不纳入产品 UI
- **前端搜索**：继续使用东方财富搜索或本地自选股列表
- **后续阶段**：如需雪球搜索，考虑 Playwright 方案或浏览器端代理

## 相关文件

- `doc/xueqiu2s/README.md` — 原始研究记录
- `doc/xueqiu-access-matrix.json` — 接口访问条件矩阵
