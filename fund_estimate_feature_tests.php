<?php
/**
 * 基金估值降级回归测试。
 *
 * 运行：.\php\php.exe fund_estimate_feature_tests.php
 * 真实数据：.\php\php.exe fund_estimate_feature_tests.php --live
 */

require_once __DIR__ . '/lib/FundService.php';

$passed = 0;
$failed = 0;
function checkFundEstimate($condition, string $message): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
    } else {
        $failed++;
        echo "[FAIL] {$message}\n";
    }
}

// fallback 结果写入缓存后必须保留 fallback_used，避免命中缓存后状态误报 primary。
$cacheDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fuckashare_fund_estimate_test_' . bin2hex(random_bytes(4));
CacheStoreFactory::useFileStore($cacheDir);
$service = new FundService();
$setToCache = new ReflectionMethod(FundService::class, 'setToCache');
$setToCache->setAccessible(true);
$getFromCache = new ReflectionMethod(FundService::class, 'getFromCache');
$getFromCache->setAccessible(true);

$fallback = DataSourceResult::fallback(
    FundService::SOURCE_NAME,
    'estimate',
    ['fundcode' => '008163', 'dwjz' => '1.0352', 'estimate_available' => false],
    FundService::SOURCE_NAME . '_realtime_estimate',
    '上游未提供该基金的盘中估值'
);
$setToCache->invoke($service, 'test:estimate:fallback', $fallback, 60);
$cached = $getFromCache->invoke($service, 'test:estimate:fallback');
checkFundEstimate($cached instanceof DataSourceResult, '应能读回基金估值缓存');
checkFundEstimate($cached !== null && $cached->isFallback(), '缓存命中后必须保留 fallback_used');
if ($cached !== null) {
    $status = $cached->toEnvelope()['meta']['data_status'];
    checkFundEstimate(($status['route'] ?? '') === 'fallback', '缓存降级数据 route 必须为 fallback');
    checkFundEstimate(($status['completeness'] ?? '') === 'complete', '可用净值降级不应误报 partial');
}

$js = file_get_contents(__DIR__ . '/watch_center_ui.js');
checkFundEstimate(strpos($js, "f.quote_type === 'latest_nav'") !== false, '前端必须识别 latest_nav 降级');
checkFundEstimate(strpos($js, "s.valueKind === 'nav' ? '净值' : '估值'") !== false, '前端必须把降级值标记为净值');

if (in_array('--live', $argv, true)) {
    CacheStoreFactory::reset();
    $live = (new FundService())->batchEstimate(['008163']);
    $item = is_array($live->data ?? null) ? ($live->data['008163'] ?? null) : null;
    checkFundEstimate($live->hasData(), 'live: 008163 应返回可用数据');
    checkFundEstimate(is_array($item) && ($item['estimate_available'] ?? null) === false, 'live: 008163 应降级到最新净值');
    checkFundEstimate(is_array($item) && is_numeric($item['dwjz'] ?? null), 'live: 最新净值必须为数值');
    checkFundEstimate($live->isFallback(), 'live: 批量结果必须明确标记 fallback');
    $liveStatus = $live->toEnvelope()['meta']['data_status'];
    checkFundEstimate(($liveStatus['completeness'] ?? '') === 'complete', 'live: 降级成功后完整性应为 complete');
    checkFundEstimate(($liveStatus['route'] ?? '') === 'fallback', 'live: 降级成功后 route 应为 fallback');
    checkFundEstimate(!empty($live->meta['data_at']), 'live: 单基金批次必须透传真实净值日期');
}

// 清理仅由本测试创建的临时缓存目录。
foreach (glob($cacheDir . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
    if (is_file($path)) @unlink($path);
}
@rmdir($cacheDir . DIRECTORY_SEPARATOR . 'locks');
@rmdir($cacheDir);

echo "\n基金估值功能测试: {$passed} 通过, {$failed} 失败\n";
exit($failed > 0 ? 1 : 0);
