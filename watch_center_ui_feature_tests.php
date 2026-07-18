<?php
/**
 * 自选中心 UI 交互契约回归。
 *
 * 运行：.\php\php.exe watch_center_ui_feature_tests.php
 */

$root = __DIR__;
$index = file_get_contents($root . '/index.php');
$script = file_get_contents($root . '/watch_center_ui.js');
$style = file_get_contents($root . '/style.css');

$passed = 0;
$failed = 0;

function checkContract(bool $condition, string $message): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        return;
    }
    $failed++;
    echo "[FAIL] {$message}\n";
}

checkContract(strpos($index, 'id="wc-select-all"') !== false, '存在当前结果全选入口');
checkContract(strpos($index, 'id="wc-feedback"') !== false, '存在 aria-live 操作反馈区');
checkContract(strpos($index, '监控 = 自动刷新') !== false, '页面明确解释监控语义');
checkContract(strpos($style, '.wc-bulkbar[hidden]') !== false, '批量栏 hidden 不会被 flex 覆盖');
checkContract(strpos($script, "showToast('请先勾选要操作的资产'") !== false, '零选择操作提供反馈');
checkContract(strpos($script, "it.type === 'stock' && !it.monitor") !== false, '批量开启仅处理未监控股票');
checkContract(strpos($script, "it.type === 'stock' && it.monitor") !== false, '批量关闭仅处理监控中股票');
checkContract(strpos($script, "setAttribute('aria-pressed'") !== false, '监控开关暴露无障碍状态');
checkContract(strpos($script, "document.addEventListener('visibilitychange'") !== false, '页面恢复可见后执行过期补刷');
checkContract(strpos($script, 'settings.stockRefreshSeconds') !== false, '股票刷新周期读取统一设置');

echo "\n自选中心 UI 契约测试: {$passed} 通过, {$failed} 失败\n";
exit($failed > 0 ? 1 : 0);
