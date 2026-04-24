<?php
/**
 * 雪球专用代理 API
 * 便于调试和新 UI 使用
 *
 * action: quote / kline / hot_stock / screener / fundx
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/SecurityAudit.php';
require_once __DIR__ . '/lib/MarketDataService.php';

SecurityAudit::init(['endpoint' => 'xueqiu_api']);

// ── 参数验证 ──

$action = SecurityAudit::getParam('action', '', [
    'required' => true,
    'whitelist' => SecurityAudit::ALLOWED_XUEQIU_ACTIONS,
]);

$raw = SecurityAudit::getParam('raw', 0, ['int' => true]) === 1;

$service = new MarketDataService();

// ── 路由 ──

switch ($action) {
    case 'quote':
        $inputCode = getStockCodeParam();
        $result = $service->quote($inputCode, 'xueqiu', false, $raw);
        break;

    case 'kline':
        $inputCode = getStockCodeParam();
        $period = SecurityAudit::getParam('period', 'day', [
            'whitelist' => SecurityAudit::ALLOWED_XUEQIU_PERIODS,
        ]);
        $count = SecurityAudit::getParam('count', 120, [
            'int' => true,
            'min' => 1,
            'max' => 500,
        ]);
        $result = $service->kline($inputCode, $period === 'day' ? '1d' : $period, $count, '', 'xueqiu', false, $raw);
        break;

    case 'hot_stock':
        $type = SecurityAudit::getParam('type', '10', [
            'whitelist' => SecurityAudit::ALLOWED_XUEQIU_HOT_TYPES,
        ]);
        $size = SecurityAudit::getParam('size', 20, [
            'int' => true,
            'min' => 1,
            'max' => 100,
        ]);
        $result = $service->hotStock($type, $size, $raw);
        break;

    case 'screener':
        $page = SecurityAudit::getParam('page', 1, ['int' => true, 'min' => 1, 'max' => 100]);
        $size = SecurityAudit::getParam('size', 20, ['int' => true, 'min' => 1, 'max' => 100]);
        $orderBy = SecurityAudit::getParam('order_by', 'percent', [
            'whitelist' => SecurityAudit::ALLOWED_SCREENER_ORDER_FIELDS,
        ]);
        $order = SecurityAudit::getParam('order', 'desc', [
            'whitelist' => ['asc', 'desc'],
        ]);
        $market = SecurityAudit::getParam('market', 'CN', [
            'whitelist' => SecurityAudit::ALLOWED_SCREENER_MARKETS,
        ]);
        $type = SecurityAudit::getParam('type', 'sh_sz', [
            'whitelist' => SecurityAudit::ALLOWED_SCREENER_TYPES,
        ]);
        $result = $service->screener($page, $size, $orderBy, $order, $market, $type, $raw);
        break;

    case 'fundx':
        $page = SecurityAudit::getParam('page', 1, ['int' => true, 'min' => 1]);
        $source = SecurityAudit::getParam('source', '', ['sanitize' => 'alphanum']);
        $lastId = SecurityAudit::getParam('last_id', 0, ['int' => true, 'min' => 0]);
        $result = $service->fundx($page, $source, $lastId, $raw);
        break;

    default:
        echo json_encode(['success' => false, 'message' => '未知 action'], JSON_UNESCAPED_UNICODE);
        exit;
}

echo $result->toJson($raw);

function getStockCodeParam(): string
{
    $code = SecurityAudit::getParam('code', '', [
        'pattern'   => SecurityAudit::STOCK_CODE_PATTERN,
        'maxLength' => SecurityAudit::MAX_CODE_LENGTH,
    ]);
    $symbol = SecurityAudit::getParam('symbol', '', [
        'pattern'   => SecurityAudit::STOCK_CODE_PATTERN,
        'maxLength' => SecurityAudit::MAX_CODE_LENGTH,
    ]);
    $inputCode = $code ?: $symbol;
    if ($inputCode === '') {
        echo json_encode(['success' => false, 'message' => '参数 code 或 symbol 不能为空'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    return $inputCode;
}
