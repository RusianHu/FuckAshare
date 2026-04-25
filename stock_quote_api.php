<?php
/**
 * 股票实时行情代理API
 * Phase 1.3: 统一委托 MarketDataService::quote()，保留旧响应格式兼容前端
 *
 * 参数：codes (逗号分隔), source, fallback, raw
 *   source=auto       默认，东方财富主，失败时尝试雪球兜底
 *   source=eastmoney  强制东方财富
 *   source=xueqiu     强制雪球
 *   fallback=1        允许兜底（默认）
 *   fallback=0        禁用兜底
 *   raw=1             返回上游原始结构
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/SecurityAudit.php';
SecurityAudit::init(['endpoint' => 'stock_quote']);

$codes = SecurityAudit::getParam('codes', '', ['required' => true]);
$validCodes = SecurityAudit::validateCodeList($codes, SecurityAudit::STOCK_CODE_PATTERN, SecurityAudit::MAX_CODES_COUNT);
$codes = implode(',', $validCodes);

$source   = SecurityAudit::getParam('source', 'auto', ['whitelist' => SecurityAudit::ALLOWED_DATA_SOURCES]);
$fallback = SecurityAudit::getParam('fallback', 1, ['int' => true]) === 1;
$raw      = SecurityAudit::getParam('raw', 0, ['int' => true]) === 1;

require_once __DIR__ . '/lib/MarketDataService.php';
$service = new MarketDataService();
$result = $service->quote($codes, $source, $fallback, $raw);

if ($result->hasData()) {
    // 保留旧响应格式：success + data 数组
    $stocks = $result->data;
    echo json_encode([
        'success' => true,
        'total'   => count($stocks),
        'data'    => $stocks,
    ], JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode([
        'success' => false,
        'message' => $result->errorMessage ?? '请求行情数据失败',
    ], JSON_UNESCAPED_UNICODE);
}
