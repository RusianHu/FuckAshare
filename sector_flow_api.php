<?php
/**
 * 板块资金流向代理API
 * Phase 1.3: 内部委托 MarketDataService::sectorFlow()，保留旧响应格式兼容前端
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/SecurityAudit.php';
SecurityAudit::init(['endpoint' => 'sector_flow']);

$key  = SecurityAudit::getParam('key', 'f62', ['whitelist' => SecurityAudit::ALLOWED_SECTOR_KEYS]);
$type = SecurityAudit::getParam('type', 'industry', ['whitelist' => SecurityAudit::ALLOWED_SECTOR_TYPES]);

require_once __DIR__ . '/lib/MarketDataService.php';
$service = new MarketDataService();
$result = $service->sectorFlow($key, $type);

if ($result->hasData()) {
    // 保留旧响应格式
    echo json_encode([
        'success' => true,
        'key'     => $key,
        'type'    => $type,
        'total'   => count($result->data),
        'data'    => $result->data,
    ], JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode([
        'success' => false,
        'message' => $result->errorMessage ?? '解析数据失败',
    ], JSON_UNESCAPED_UNICODE);
}
