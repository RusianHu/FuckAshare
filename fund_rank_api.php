<?php
/**
 * 基金收益排行代理API
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/SecurityAudit.php';
SecurityAudit::init(['endpoint' => 'fund_rank']);

$allowedTypes = ['all', 'stock', 'mixed', 'bond', 'index', 'qdii', 'fof'];
$allowedPeriods = ['day', 'week', 'month', 'quarter', 'half_year', 'year', 'two_year', 'three_year', 'this_year', 'since'];

$type = SecurityAudit::getParam('type', 'all', [
    'whitelist' => $allowedTypes,
]);
$period = SecurityAudit::getParam('period', 'year', [
    'whitelist' => $allowedPeriods,
]);
$page = (int)SecurityAudit::getParam('page', 1, [
    'int' => true,
    'min' => 1,
    'max' => 1000,
]);
$pageSize = (int)SecurityAudit::getParam('page_size', 30, [
    'int' => true,
    'min' => 5,
    'max' => 100,
]);

require_once __DIR__ . '/lib/FundService.php';
$service = new FundService();
$result = $service->rank($type, $period, $page, $pageSize);

if ($result->hasData()) {
    echo json_encode([
        'success' => true,
        'data' => $result->data,
        'meta' => $result->meta,
    ], JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode([
        'success' => false,
        'message' => $result->errorMessage ?? '获取基金排行失败',
    ], JSON_UNESCAPED_UNICODE);
}
