<?php
/**
 * A股净流入排名代理API
 * Phase 1.3: 内部委托 MarketDataService::hotStocks()，保留旧响应格式兼容前端
 * 返回字段：dm, mc, zxj, zdf, hsl, jlr, jlrl
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/SecurityAudit.php';
SecurityAudit::init(['endpoint' => 'hot_stocks']);

$page      = SecurityAudit::getParam('page', 1, ['int' => true, 'min' => 1, 'max' => 100]);
$pageSize  = SecurityAudit::getParam('pagesize', 50, ['int' => true, 'min' => 1, 'max' => 200]);
$sortField = SecurityAudit::getParam('sort', 'f62', ['whitelist' => SecurityAudit::ALLOWED_SORT_FIELDS]);
$sortOrder = SecurityAudit::getParam('order', 1, ['int' => true]);

$sortOrder = ($sortOrder === 0) ? 0 : 1;
$format    = SecurityAudit::getParam('format', '', ['maxLength' => 16]);

require_once __DIR__ . '/lib/MarketDataService.php';
$service = new MarketDataService();
$result = $service->hotStocks($page, $pageSize, $sortField, $sortOrder);

if ($format === 'envelope') {
    echo json_encode($result->toEnvelope(), JSON_UNESCAPED_UNICODE);
} elseif ($result->hasData()) {
    // 保留旧响应格式：纯数组
    echo json_encode($result->data, JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode(['error' => $result->errorMessage ?? '请求东方财富数据失败'], JSON_UNESCAPED_UNICODE);
}
