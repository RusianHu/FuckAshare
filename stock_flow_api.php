<?php
/**
 * 股票资金流向代理API
 * Phase 1.3: 内部委托 MarketDataService::stockFlow()，保留旧响应格式兼容前端
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/SecurityAudit.php';
SecurityAudit::init(['endpoint' => 'stock_flow']);

$code   = SecurityAudit::getParam('code', '', [
    'required'  => true,
    'pattern'   => SecurityAudit::STOCK_CODE_PATTERN,
    'maxLength' => SecurityAudit::MAX_CODE_LENGTH,
]);
$market = SecurityAudit::getParam('market', '', ['sanitize' => 'digits']);
$lmt    = SecurityAudit::getParam('lmt', 0, ['int' => true, 'min' => 0, 'max' => 1000]);

// 构造兼容的 code（如果传入了 market 参数，拼回 sh/sz 前缀让 StockCode 解析）
if (!empty($market) && is_numeric($market)) {
    $fullCode = ($market == 1 ? 'sh' : 'sz') . $code;
} else {
    $fullCode = $code;
}

require_once __DIR__ . '/lib/MarketDataService.php';
$service = new MarketDataService();
$result = $service->stockFlow($fullCode, $lmt);

if ($result->hasData()) {
    // 保留旧响应格式
    $sc = StockCode::parse($fullCode);
    echo json_encode([
        'success' => true,
        'code'    => $code,
        'secid'   => $sc->isValid() ? $sc->toEastmoneySecid() : '',
        'data'    => $result->data,
    ], JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode([
        'success' => false,
        'message' => $result->errorMessage ?? '请求失败',
    ], JSON_UNESCAPED_UNICODE);
}
