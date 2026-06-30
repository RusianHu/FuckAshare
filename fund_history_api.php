<?php
/**
 * 基金历史净值代理API
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/SecurityAudit.php';
SecurityAudit::init(['endpoint' => 'fund_history']);

$code = SecurityAudit::getParam('code', '', [
    'required' => true,
    'pattern' => SecurityAudit::FUND_CODE_PATTERN,
    'maxLength' => 6,
    'sanitize' => 'digits',
]);
$page = (int)SecurityAudit::getParam('page', 1, [
    'int' => true,
    'min' => 1,
    'max' => 200,
]);
$pageSize = (int)SecurityAudit::getParam('page_size', 30, [
    'int' => true,
    'min' => 5,
    'max' => 100,
]);

require_once __DIR__ . '/lib/FundService.php';
$service = new FundService();
$result = $service->history($code, $page, $pageSize);

if ($result->hasData()) {
    echo json_encode([
        'success' => true,
        'code' => $code,
        'data' => $result->data,
        'meta' => $result->meta,
    ], JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode([
        'success' => false,
        'message' => $result->errorMessage ?? '获取历史净值失败',
    ], JSON_UNESCAPED_UNICODE);
}
