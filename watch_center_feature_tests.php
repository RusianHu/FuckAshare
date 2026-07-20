<?php
/**
 * 统一自选功能测试 — watch_center.js 存储与无损迁移
 *
 * 运行：.\php\php.exe watch_center_feature_tests.php
 *
 * 迁移/存储逻辑为纯 JS，本测试生成 Node harness 注入 window/localStorage 垫片，
 * 加载真实 app_core.js + watch_center.js 执行断言，回收退出码。
 */

$root = __DIR__;
$nodeBin = 'node';

// 定位 node（本地环境已在 PATH）
exec($nodeBin . ' -v 2>&1', $verOut, $verCode);
if ($verCode !== 0) {
    fwrite(STDERR, "[SKIP] 未找到 node，无法执行 watch_center JS 测试\n");
    // 资源契约检查兜底：确认文件存在并已在 index.php 挂载
    $ok = file_exists("$root/app_core.js") && file_exists("$root/watch_center.js");
    $index = file_get_contents("$root/index.php");
    $wired = strpos($index, 'app_core.js') !== false && strpos($index, 'watch_center.js') !== false;
    echo $ok && $wired ? "资源契约检查通过（node 缺失，跳过逻辑测试）\n" : "资源契约检查失败\n";
    exit($ok && $wired ? 0 : 1);
}

$harness = <<<'JS'
// ── 浏览器垫片 ──
const store = {};
global.window = global;
global.document = { visibilityState: 'visible', addEventListener() {} };
global.localStorage = {
  _s: store,
  getItem(k) { return Object.prototype.hasOwnProperty.call(store, k) ? store[k] : null; },
  setItem(k, v) { store[k] = String(v); },
  removeItem(k) { delete store[k]; },
  clear() { for (const k in store) delete store[k]; },
};
global.addEventListener = function () {};

const fs = require('fs');
const path = require('path');
const root = process.argv[2];
function loadModule(file) {
  const code = fs.readFileSync(path.join(root, file), 'utf8');
  // IIFE 直接执行，挂载到 global(window)
  (0, eval)(code);
}
loadModule('app_core.js');
loadModule('watch_center.js');

let passed = 0, failed = 0;
function check(cond, msg) { if (cond) passed++; else { failed++; console.log('[FAIL] ' + msg); } }
function reset() { global.localStorage.clear(); }

const WC = global.WatchCenter;
const Util = global.AppUtil;

// ── 1. 全新用户：三键为空 ──
reset();
WC.init();
check(WC.count() === 0, '空环境 count=0');
check(WC.getGroups().length === 1 && WC.getGroups()[0].id === 'default', '默认分组存在');

// ── 2. 仅 fa_watchlist ──
reset();
localStorage.setItem('fa_watchlist', JSON.stringify([{ code: 'sh600519', name: '贵州茅台' }, { code: '000001', name: '平安银行' }]));
WC.init();
check(WC.count() === 2, '仅股票自选迁移 count=2');
check(WC.has('stock', 'sh600519'), '茅台已迁移');
check(WC.has('stock', 'sz000001'), '000001 归一为 sz000001');
check(!WC.getItem('stock:sh600519').monitor, '普通自选 monitor=false');

// ── 3. 仅 fa_realtime_codes -> monitor=true ──
reset();
localStorage.setItem('fa_realtime_codes', JSON.stringify(['sh600519', 'sz000002']));
WC.init();
check(WC.count() === 2, '实时代码迁移 count=2');
check(WC.getItem('stock:sh600519').monitor === true, '实时独有股票 monitor=true');
check(WC.getItem('stock:sh600519').type === 'stock', '实时代码成为股票项');

// ── 4. 仅 fa_fund_watchlist ──
reset();
localStorage.setItem('fa_fund_watchlist', JSON.stringify([{ code: '161725', name: '招商中证白酒' }]));
WC.init();
check(WC.count() === 1 && WC.has('fund', '161725'), '基金自选迁移');
check(WC.getItem('fund:161725').monitor === false, '基金不可 monitor');

// ── 4.1 场内 ETF 与场外联接基金必须使用不同报价路由 ──
reset();
localStorage.setItem('fa_fund_watchlist', JSON.stringify([
  { code: '159819', name: '人工智能ETF易方达' },
  { code: '515450', name: '红利低波50ETF南方' },
  { code: '008163', name: '南方标普红利低波50ETF联接A' },
]));
WC.init();
check(WC.getItem('fund:159819').instrumentType === 'exchange_etf', '深市场内 ETF 识别为 exchange_etf');
check(WC.getItem('fund:515450').instrumentType === 'exchange_etf', '沪市场内 ETF 识别为 exchange_etf');
check(WC.getItem('fund:008163').instrumentType === 'otc_fund', 'ETF 联接基金不得误判为场内 ETF');
check(WC.exchangeQuoteCode('159819') === 'sz159819', '深市 ETF 行情代码正确');
check(WC.exchangeQuoteCode('515450') === 'sh515450', '沪市 ETF 行情代码正确');
check(WC.isMarketQuoted(WC.getItem('fund:515450')) === true, '场内 ETF 必须走交易所行情');
check(WC.isMarketQuoted(WC.getItem('fund:008163')) === false, '场外联接基金必须走估值/净值');

// ── 5. 三键同时存在 + 重复股票合并（监控取逻辑或） ──
reset();
localStorage.setItem('fa_watchlist', JSON.stringify([{ code: 'sh600519', name: '贵州茅台' }]));
localStorage.setItem('fa_realtime_codes', JSON.stringify(['sh600519', 'sz000001']));
localStorage.setItem('fa_fund_watchlist', JSON.stringify([{ code: '161725', name: '白酒' }]));
WC.init();
check(WC.count() === 3, '三键合并去重后 count=3');
check(WC.getItem('stock:sh600519').monitor === true, '重复股票监控属性取逻辑或=true');
check(WC.getItem('stock:sh600519').name === '贵州茅台', '重复股票保留名称');
check(WC.has('stock', 'sz000001') && WC.has('fund', '161725'), '各来源均在');

// ── 6. 迁移后旧键单向镜像 ──
reset();
localStorage.setItem('fa_watchlist', JSON.stringify([{ code: 'sh600519', name: '茅台' }]));
WC.init();
WC.addItem('stock', 'sz000001', '平安银行', { monitor: true });
const mirroredRT = JSON.parse(localStorage.getItem('fa_realtime_codes') || '[]');
check(mirroredRT.indexOf('sz000001') !== -1, '新增监控项镜像回 fa_realtime_codes');
const mirroredWL = JSON.parse(localStorage.getItem('fa_watchlist') || '[]');
check(mirroredWL.some(x => x.code === 'sz000001'), '新增股票镜像回 fa_watchlist');

// ── 7. 损坏存储：备份并从旧键恢复 ──
reset();
localStorage.setItem('fa_watch_center_v2', '{不是合法json');
localStorage.setItem('fa_watchlist', JSON.stringify([{ code: 'sh600519', name: '茅台' }]));
WC.init();
check(localStorage.getItem('fa_watch_center_v2_backup') === '{不是合法json', '损坏数据被备份');
check(WC.has('stock', 'sh600519'), '损坏后从旧键恢复');

// ── 8. 增删改 / 置顶 / 分组 ──
reset();
WC.init();
const add = WC.addItem('stock', '600036', '招商银行');
check(add.ok && add.id === 'stock:sh600036', '添加返回主键');
check(WC.togglePin('stock:sh600036') === true, '置顶切换');
const g = WC.createGroup('核心持仓');
check(g && WC.getGroups().length === 2, '创建分组');
WC.updateItem('stock:sh600036', { groupId: g.id, tags: ['银行', '价值'], note: '长期' });
check(WC.getItem('stock:sh600036').groupId === g.id, '移动分组');
check(WC.getItem('stock:sh600036').tags.length === 2, '标签写入');
check(WC.deleteGroup(g.id) === true, '删除分组');
check(WC.getItem('stock:sh600036').groupId === 'default', '删组后项目回默认组');
check(WC.deleteGroup('default') === false, '默认分组不可删除');

// ── 8.1 代码占位名称可由后续搜索/行情回填，自定义名称不被覆盖 ──
reset();
WC.init();
WC.addItem('stock', '600036', '', { monitor: true });
check(WC.getItem('stock:sh600036').name === 'sh600036', '仅代码添加时先保存规范代码占位');
const upgraded = WC.addItem('stock', '600036', '招商银行');
check(upgraded.existed === true && WC.getItem('stock:sh600036').name === '招商银行', '重复添加可用正式名称替换代码占位');
WC.addItem('stock', '000001', '我的银行');
const resolvedCount = WC.resolveNames([
  { type: 'stock', code: '600036', name: '招商银行' },
  { type: 'stock', code: '000001', name: '平安银行' },
]);
check(resolvedCount === 0 && WC.getItem('stock:sz000001').name === '我的银行', '行情回填保留已有正式或自定义名称');
WC.addItem('fund', '161725', '');
check(WC.resolveNames([{ type: 'fund', code: '161725', name: '招商中证白酒指数A' }]) === 1, '基金代码占位也可由估值名称回填');
check(WC.getItem('fund:161725').name === '招商中证白酒指数A', '基金正式名称已持久化');

// ── 9. 批量操作 ──
reset();
WC.init();
WC.addItem('stock', '600519', '茅台');
WC.addItem('stock', '000001', '平安');
const moved = WC.bulkUpdate(['stock:sh600519', 'stock:sz000001'], { monitor: true });
check(moved === 2, '批量开启监控 2 项');
check(WC.monitorCount() === 2, 'monitorCount=2');
const removed = WC.bulkRemove(['stock:sh600519']);
check(removed === 1 && WC.count() === 1, '批量删除');

// ── 10. 导入导出 ──
reset();
WC.init();
WC.addItem('stock', '600519', '茅台');
WC.addItem('fund', '161725', '白酒');
const json = WC.exportJson();
check(json.indexOf('161725') !== -1, 'JSON 导出含基金');
const csv = WC.exportCsv();
check(csv.split('\r\n').length === 3, 'CSV 导出 2 项+表头');
// 合并导入
reset();
WC.init();
WC.addItem('stock', '600519', '茅台');
const mergeStats = WC.importMerge(JSON.stringify({ items: [
  { type: 'stock', code: '600519', name: '贵州茅台', tags: ['核心'] },
  { type: 'stock', code: '600036', name: '招商银行' },
]}));
check(mergeStats.ok && mergeStats.updated === 1 && mergeStats.added === 1, '合并导入统计正确');
check(WC.getItem('stock:sh600519').tags.indexOf('核心') !== -1, '合并导入补充标签');
// 替换导入
const replaceStats = WC.importReplace(JSON.stringify({ schemaVersion: 2, revision: 1, updatedAt: '', settings: { defaultGroupId: 'default' }, groups: [{ id: 'default', name: '默认分组', sortOrder: 0 }], items: [ { type: 'fund', code: '110022', name: '易方达消费' } ] }));
check(replaceStats.ok && WC.count() === 1 && WC.has('fund', '110022'), '替换导入生效');
check(localStorage.getItem('fa_watch_center_v2_backup') !== null, '替换前有备份');

// ── 11. 校验失败不破坏数据 ──
reset();
WC.init();
WC.addItem('stock', '600519', '茅台');
const badImport = WC.importMerge('{bad json');
check(badImport.ok === false && WC.count() === 1, '非法导入不破坏现有数据');

// ── 12. 上限约束 ──
reset();
WC.init();
check(WC.LIMITS.maxItems === 500, 'maxItems 上限 500');

console.log('\n统一自选功能测试(JS): ' + passed + ' 通过, ' + failed + ' 失败');
process.exit(failed > 0 ? 1 : 0);
JS;

$tmp = tempnam(sys_get_temp_dir(), 'wc_harness_') . '.js';
file_put_contents($tmp, $harness);

$cmd = escapeshellarg($nodeBin) . ' ' . escapeshellarg($tmp) . ' ' . escapeshellarg($root) . ' 2>&1';
exec($cmd, $out, $code);
@unlink($tmp);

echo implode("\n", $out) . "\n";
exit($code);
