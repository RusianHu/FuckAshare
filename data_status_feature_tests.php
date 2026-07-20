<?php
/**
 * 数据状态功能测试 — 统一 envelope 契约 + data_status 归一化
 *
 * 运行：.\php\php.exe data_status_feature_tests.php
 * 覆盖：DataSourceResult::toEnvelope / computeDataStatus / setBatchCounts；
 *       primary / cache hit / fallback / stale / partial / empty / error 的状态映射；
 *       旧 toResponse 格式保持不变。
 */

require_once __DIR__ . '/lib/DataSourceResult.php';

$passed = 0;
$failed = 0;
function check($cond, string $msg): void
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
    } else {
        $failed++;
        echo "[FAIL] {$msg}\n";
    }
}

// ── 1. 旧 toResponse 格式保持不变（不含 status / data_status） ──
$ok = DataSourceResult::success('eastmoney', 'quote', [['symbol' => 'sh600519', 'price' => 1]]);
$legacy = $ok->toResponse();
check($legacy['success'] === true, 'legacy toResponse success 保持');
check(!array_key_exists('status', $legacy), 'legacy toResponse 不应新增 status 字段');
check(!isset($legacy['meta']['data_status']), 'legacy toResponse 不应包含 data_status');

// ── 2. envelope 主路径 primary / ok / fresh / complete ──
$env = $ok->toEnvelope();
check($env['status'] === 'success', 'envelope 输出 status=success');
$ds = $env['meta']['data_status'];
check($ds['severity'] === 'ok', 'primary severity=ok');
check($ds['route'] === 'primary', 'primary route=primary');
check($ds['freshness'] === 'fresh', 'miss 缓存 freshness=fresh');
check($ds['completeness'] === 'complete', 'complete completeness');
check(isset($env['meta']['request_id']) && strlen($env['meta']['request_id']) > 0, 'envelope 含 request_id');
check(array_key_exists('observed_at', $env['meta']), 'envelope 含 observed_at');
check(array_key_exists('data_at', $env['meta']) && $env['meta']['data_at'] === null, 'data_at 默认 null 不冒充行情时间');

// ── 3. cache hit → info / cached ──
$hit = DataSourceResult::success('eastmoney', 'quote', [['symbol' => 'sh600519']]);
$hit->meta['cache'] = 'hit';
$dsHit = $hit->toEnvelope()['meta']['data_status'];
check($dsHit['freshness'] === 'cached', 'cache hit freshness=cached');
check($dsHit['severity'] === 'info', 'cache hit severity=info');

// ── 4. fallback → info / fallback route ──
$fb = DataSourceResult::fallback('xueqiu', 'quote', [['symbol' => 'sh600519']], 'eastmoney', '东财失败');
$dsFb = $fb->toEnvelope()['meta']['data_status'];
check($dsFb['route'] === 'fallback', 'fallback route=fallback');
check($dsFb['severity'] === 'info', 'fallback severity=info');
check(count($dsFb['warnings']) >= 1, 'fallback 产生 warning');

// ── 5. stale 缓存 → warning / stale ──
$stale = DataSourceResult::success('eastmoney', 'quote', [['symbol' => 'x']]);
$stale->meta['cache'] = 'stale_fallback';
$dsStale = $stale->toEnvelope()['meta']['data_status'];
check($dsStale['freshness'] === 'stale', 'stale freshness=stale');
check($dsStale['severity'] === 'warning', 'stale severity=warning');

// ── 6. partial（批量缺失一项） → warning / partial + missing 列表 ──
$batch = DataSourceResult::success('eastmoney', 'quote', [
    ['symbol' => 'sh600519'],
    ['symbol' => 'sz000001'],
]);
$batch->setBatchCounts(['sh600519', 'sz000001', 'sh600036'], ['sh600519', 'sz000001']);
$dsBatch = $batch->toEnvelope()['meta']['data_status'];
check($dsBatch['completeness'] === 'partial', '缺失一项 completeness=partial');
check($dsBatch['severity'] === 'warning', 'partial severity=warning');
check($dsBatch['counts']['expected'] === 3, 'counts.expected=3');
check($dsBatch['counts']['returned'] === 2, 'counts.returned=2');
check(in_array('sh600036', $dsBatch['counts']['missing'], true), 'missing 列出 sh600036');

// setBatchCounts 归一化：sh 前缀与纯数字应视为同一代码
$batch2 = DataSourceResult::success('em', 'quote', [['symbol' => 'sh600519']]);
$batch2->setBatchCounts(['600519'], ['sh600519']);
$dsB2 = $batch2->toEnvelope()['meta']['data_status'];
check($dsB2['completeness'] === 'complete', '前缀归一后视为完整（无缺失）');

// ── 7. empty → completeness=empty ──
$empty = DataSourceResult::success('eastmoney', 'quote', []);
$dsEmpty = $empty->toEnvelope()['meta']['data_status'];
check($dsEmpty['completeness'] === 'empty', '空数组 completeness=empty');

// ── 8. error → failed / error ──
$err = DataSourceResult::error('eastmoney', 'quote', 'network_error', '请求失败');
$envErr = $err->toEnvelope();
check($envErr['success'] === false, 'error envelope success=false');
check($envErr['status'] === 'network_error', 'error status=错误码');
check($envErr['code'] === 'network_error', 'error 保留 code');
check($envErr['message'] === '请求失败', 'error 保留 message');
$dsErr = $envErr['meta']['data_status'];
check($dsErr['route'] === 'failed', 'error route=failed');
check($dsErr['severity'] === 'error', 'error severity=error');

// ── 9. cache_age_seconds：miss=0，其它默认 null ──
$missAge = DataSourceResult::success('em', 'quote', [['x' => 1]]);
$missAge->meta['cache'] = 'miss';
check($missAge->toEnvelope()['meta']['cache_age_seconds'] === 0, 'miss 缓存 age=0');
$hitAge = DataSourceResult::success('em', 'quote', [['x' => 1]]);
$hitAge->meta['cache'] = 'hit';
$hitAge->meta['cache_age_seconds'] = 42;
check($hitAge->toEnvelope()['meta']['cache_age_seconds'] === 42, '已有 cache_age_seconds 被保留');

// ── 10. 新请求拿到旧日期内容时，缓存可为 fresh，但内容时效必须单独告警 ──
$dated = DataSourceResult::fallback(
    'eastmoney_fund',
    'estimate',
    ['fundcode' => '008163', 'quote_type' => 'latest_nav'],
    'eastmoney_fund_realtime_estimate',
    '盘中估值不可用',
    [
        'cache' => 'miss',
        'data_at' => '2026-07-17',
        'data_recency' => 'dated',
        'non_realtime_count' => 1,
        'non_realtime_label' => '官方净值',
    ]
);
$dsDated = $dated->toEnvelope()['meta']['data_status'];
check($dsDated['freshness'] === 'fresh', '新请求与缓存时效仍可标 fresh');
check($dsDated['data_recency'] === 'dated', '内容时效必须标 dated');
check($dsDated['non_realtime_count'] === 1, '必须暴露非实时项数量');
check($dsDated['severity'] === 'warning', '非实时净值必须提升为 warning');
check(count(array_filter($dsDated['warnings'], function ($w) { return ($w['code'] ?? '') === 'non_realtime_data'; })) === 1, '必须生成非实时数据警告');

echo "\n数据状态功能测试: {$passed} 通过, {$failed} 失败\n";
exit($failed > 0 ? 1 : 0);
