<?php
/**
 * 基金详细信息代理API
 * Phase 1.3: 内部委托 FundService::info()，保留旧响应格式兼容前端
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/SecurityAudit.php';
SecurityAudit::init(['endpoint' => 'fund_info']);

$codes = SecurityAudit::getParam('codes', '', [
    'required' => true,
]);

$validCodes = SecurityAudit::validateCodeList($codes, SecurityAudit::FUND_CODE_PATTERN, SecurityAudit::MAX_CODES_COUNT);

require_once __DIR__ . '/lib/FundService.php';
$service = new FundService();
$result = $service->info($validCodes);

if ($result->hasData()) {
    echo json_encode([
        'success' => true,
        'total'   => count($result->data),
        'data'    => $result->data,
    ], JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode(['success' => false, 'message' => $result->errorMessage ?? '解析基金数据失败'], JSON_UNESCAPED_UNICODE);
}
