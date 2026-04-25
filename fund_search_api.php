<?php
/**
 * 基金搜索代理API
 * Phase 1.3: 内部委托 FundService::search()，保留旧响应格式兼容前端
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/SecurityAudit.php';
SecurityAudit::init(['endpoint' => 'fund_search']);

$keyword = SecurityAudit::getParam('key', '', [
    'required'  => true,
    'maxLength' => SecurityAudit::MAX_KEYWORD_LENGTH,
    'sanitize'  => 'keyword',
]);

require_once __DIR__ . '/lib/FundService.php';
$service = new FundService();
$result = $service->search($keyword);

if ($result->hasData()) {
    echo json_encode([
        'success' => true,
        'keyword' => $keyword,
        'total'   => count($result->data),
        'data'    => $result->data,
    ], JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode(['success' => false, 'message' => $result->errorMessage ?? '解析搜索结果失败'], JSON_UNESCAPED_UNICODE);
}
