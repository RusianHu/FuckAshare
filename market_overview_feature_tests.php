<?php
/**
 * 大盘指数概览条测试 — market_overview.js 纯逻辑 + 挂载契约
 *
 * 运行：.\php\php.exe market_overview_feature_tests.php
 *
 * 视图模型/时段/格式化为纯 JS，本测试生成 Node harness 注入 window/document 垫片，
 * 加载真实 market_overview.js 执行断言；另做 index.php/main.js/market_api.php 接线契约检查。
 */

$root = __DIR__;

// ── 1. 挂载契约检查（无需 node） ──
$contractFailures = [];
$indexHtml = @file_get_contents("$root/index.php") ?: '';
$mainJs = @file_get_contents("$root/main.js") ?: '';
$marketApi = @file_get_contents("$root/market_api.php") ?: '';

if (strpos($indexHtml, 'market_overview.js') === false) $contractFailures[] = 'index.php 未挂载 market_overview.js';
if (strpos($indexHtml, 'id="market-overview"') === false) $contractFailures[] = 'index.php 缺少 #market-overview 容器';
if (strpos($indexHtml, "assetVersions['market_overview']") === false) $contractFailures[] = 'index.php 未走 filemtime 版本号';
if (strpos($mainJs, 'MarketOverview.init') === false) $contractFailures[] = 'main.js 未初始化 MarketOverview';
if (strpos($mainJs, 'market-overview:navigate') === false) $contractFailures[] = 'main.js 未监听 market-overview:navigate';
if (strpos($marketApi, "'envelope'") === false) $contractFailures[] = 'market_api.php 缺少 format=envelope 分支';

foreach ($contractFailures as $f) {
    echo "[FAIL] 契约: {$f}\n";
}
echo '挂载契约检查: ' . (empty($contractFailures) ? '通过' : count($contractFailures) . ' 项失败') . "\n";

// ── 2. JS 逻辑测试（需 node） ──
exec('node -v 2>&1', $verOut, $verCode);
if ($verCode !== 0) {
    fwrite(STDERR, "[SKIP] 未找到 node，跳过 market_overview JS 逻辑测试\n");
    exit(empty($contractFailures) ? 0 : 1);
}

$harness = <<<'JS'
// ── 浏览器垫片 ──
global.window = global;
global.document = {
  visibilityState: 'visible',
  addEventListener() {},
  getElementById() { return null; },
};

const fs = require('fs');
const path = require('path');
const root = process.argv[2];
(0, eval)(fs.readFileSync(path.join(root, 'market_overview.js'), 'utf8'));

let passed = 0, failed = 0;
function check(cond, msg) { if (cond) passed++; else { failed++; console.log('[FAIL] ' + msg); } }

const P = global.MarketOverview._pure;

// ── 交易时段判断（显式 UTC+8，不依赖本机时区） ──
// 2026-07-15 为周三；2026-07-18 为周六
check(P.isTradingSession(Date.UTC(2026, 6, 15, 2, 0)) === true, '周三 10:00 为交易时段');
check(P.isTradingSession(Date.UTC(2026, 6, 15, 1, 15)) === true, '周三 09:15 边界为交易时段');
check(P.isTradingSession(Date.UTC(2026, 6, 15, 3, 50)) === false, '周三 11:50 午休不属于交易时段');
check(P.isTradingSession(Date.UTC(2026, 6, 15, 6, 0)) === true, '周三 14:00 为交易时段');
check(P.isTradingSession(Date.UTC(2026, 6, 15, 7, 10)) === false, '周三 15:10 收盘后不属于交易时段');
check(P.isTradingSession(Date.UTC(2026, 6, 18, 2, 0)) === false, '周六不属于交易时段');
check(P.pickIntervalMs(Date.UTC(2026, 6, 15, 2, 0)) === 60000, '交易时段刷新间隔 60s');
check(P.pickIntervalMs(Date.UTC(2026, 6, 18, 2, 0)) === 300000, '非交易时段检查间隔 5min');

// ── 格式化 ──
check(P.fmtAmount(4.3e11) === '4300亿', '4300亿 成交额格式');
check(P.fmtAmount(1.25e12) === '1.25万亿', '万亿档格式');
check(P.fmtAmount(5.2e8) === '5.2亿', '小额亿档保留 1 位小数');
check(P.fmtAmount(0) === '—' && P.fmtAmount(null) === '—', '空成交额显示 —');
check(P.fmtPct(1.234) === '+1.23%', '正涨幅带 + 号');
check(P.fmtPct(-0.5) === '-0.50%', '负涨幅两位小数');
check(P.fmtPct(null) === '—', '空涨幅显示 —');
check(P.trendClass(1) === 'up' && P.trendClass(-1) === 'down' && P.trendClass(0) === 'flat' && P.trendClass(null) === 'flat', '涨跌样式类');

// ── 指数 → 可查询代码 ──
check(P.indexQueryCode({ market: 'SH', code: '000001' }) === 'sh000001', '上证指数代码映射');
check(P.indexQueryCode({ market: 'SZ', code: '399006' }) === 'sz399006', '创业板指代码映射');
check(P.indexQueryCode({ code: '399001' }) === 'sz399001', '缺市场时 39 开头推断为深市');
check(P.indexQueryCode({ code: '000300', market: '' }) === 'sh000300', '缺市场时默认沪市');
check(P.indexQueryCode({}) === '', '无代码返回空串');

// ── 视图模型：完整成功（全市场扫描） ──
const fullRes = {
  ok: true, message: '', meta: { cache: 'miss', partial: false },
  data: {
    scope: 'a_share', generated_at: '2026-07-17T14:30:05+08:00',
    indices: [
      { code: '000001', market: 'SH', name: '上证指数', price: 3975.1, change_pct: -0.28, change_amt: -11.12, amount: 4.3e11 },
      { code: '399006', market: 'SZ', name: '创业板指', price: 2200.5, change_pct: 1.25, change_amt: 27.2, amount: 2.1e11 },
      { code: 'bad', market: 'SH', name: '', price: null },
    ],
    aggregate: {
      method: 'full_a_share_scan', up_count: 2168, down_count: 3021, flat_count: 130,
      unknown_count: 0, tradable_count: 5319, up_ratio_pct: 40.76, down_ratio_pct: 56.8,
      breadth_score: 41.98, sentiment_label: 'negative', sample_scope: 'a_share',
    },
    limit_stats: { method: 'approx_by_pct_threshold', limit_up_count: 32, limit_down_count: 5 },
  },
};
const m1 = P.buildViewModel(fullRes);
check(m1.ok === true, '完整数据 ok=true');
check(m1.indices.length === 2, '无名/无价指数被过滤');
check(m1.indices[0].queryCode === 'sh000001', '视图模型含可查询代码');
check(m1.breadth && m1.breadth.up === 2168 && m1.breadth.down === 3021, '涨跌家数进入视图模型');
check(m1.breadth.methodLabel === '全市场', '全市场扫描口径标签');
check(m1.breadth.sentiment === '偏弱' && m1.breadth.sentimentTone === 'down', '情绪标签中文映射');
check(m1.breadth.flatRatio === 2.44, '平盘占比 = 100 - 涨占比 - 跌占比');
check(m1.limits && m1.limits.up === 32 && m1.limits.down === 5, '涨跌停计数进入视图模型');
check(m1.generatedAt === '2026-07-17T14:30:05+08:00', '透传数据生成时间');

// ── 视图模型：指数口径降级（无扫描、无涨停统计） ──
const degradedRes = {
  ok: true, message: '', meta: { cache: 'hit', partial: true },
  data: {
    generated_at: '2026-07-17T09:40:00+08:00',
    indices: [{ code: '000001', market: 'SH', name: '上证指数', price: 3970, change_pct: 0.1, change_amt: 3.9, amount: 1e11 }],
    aggregate: { method: 'index_constituent_counts', up_count: 900, down_count: 1100, flat_count: 80, up_ratio_pct: null, down_ratio_pct: null, breadth_score: null, sentiment_label: 'unknown' },
    limit_stats: { method: 'scan_failed', limit_up_count: null, limit_down_count: null },
  },
};
const m2 = P.buildViewModel(degradedRes);
check(m2.ok === true && m2.partial === true, '部分覆盖标记透传');
check(m2.breadth.methodLabel === '指数口径', '指数口径降级标签');
check(m2.breadth.flatRatio === null, '缺占比时不合成比例');
check(m2.limits === null, '涨停统计缺失时整段隐藏');
check(m2.cache === 'hit', '缓存命中状态透传');

// ── 视图模型：失败与异常形态 ──
const m3 = P.buildViewModel({ ok: false, message: '上游熔断', data: null });
check(m3.ok === false && m3.message === '上游熔断', '失败结果保留错误消息');
const m4 = P.buildViewModel({ ok: true, data: [1, 2, 3] });
check(m4.ok === false, '数组形态数据判为不可用');
const m5 = P.buildViewModel(null);
check(m5.ok === false && m5.message.length > 0, '空响应有兜底消息');

console.log('market_overview JS 逻辑: ' + passed + ' 通过, ' + failed + ' 失败');
process.exit(failed === 0 ? 0 : 1);
JS;

$tmpFile = tempnam(sys_get_temp_dir(), 'mo_test_') . '.js';
file_put_contents($tmpFile, $harness);
passthru('node ' . escapeshellarg($tmpFile) . ' ' . escapeshellarg($root), $jsCode);
@unlink($tmpFile);

exit((empty($contractFailures) && $jsCode === 0) ? 0 : 1);
