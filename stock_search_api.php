<?php
/**
 * 股票关键词搜索 API：仅返回沪深北 A 股候选。
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/SecurityAudit.php';
SecurityAudit::init(['endpoint' => 'stock_search', 'rate_limit' => 90]);

$keyword = SecurityAudit::getParam('key', '', [
    'required' => true,
    'maxLength' => SecurityAudit::MAX_KEYWORD_LENGTH,
    'sanitize' => 'keyword',
]);
$limit = SecurityAudit::getParam('limit', 10, ['int' => true, 'min' => 1, 'max' => 20]);

require_once __DIR__ . '/lib/StockSearchService.php';
$service = new StockSearchService();
$result = $service->search($keyword, $limit);

if ($result->success) {
    echo json_encode([
        'success' => true,
        'keyword' => $keyword,
        'total' => count((array)$result->data),
        'data' => $result->data,
        'meta' => $result->meta,
    ], JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode([
        'success' => false,
        'code' => $result->errorCode,
        'message' => $result->errorMessage ?: '股票搜索失败',
        'data' => [],
    ], JSON_UNESCAPED_UNICODE);
}

