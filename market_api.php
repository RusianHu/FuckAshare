<?php
/**
 * 聚合行情服务 API
 * 给前端工作台使用的统一入口
 *
 * action: quote / kline / hot_stock / screener / fundx / stock_flow / sector_flow / hot_stocks / market_breadth
 * source: auto / eastmoney / ashare / xueqiu
 * fallback: 1 / 0
 * raw: 1 / 0
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/SecurityAudit.php';
require_once __DIR__ . '/lib/MarketDataService.php';

SecurityAudit::init(['endpoint' => 'market_api', 'rate_limit' => 40]);

// ── 通用参数 ──

$source   = SecurityAudit::getParam('source', 'auto', ['whitelist' => SecurityAudit::ALLOWED_DATA_SOURCES]);
$fallback = SecurityAudit::getParam('fallback', 1, ['int' => true]) === 1;
$raw      = SecurityAudit::getParam('raw', 0, ['int' => true]) === 1;
$action   = SecurityAudit::getParam('action', '', ['required' => true]);

$service = new MarketDataService();

// ── 路由 ──

switch ($action) {
    case 'quote':
        $codes = SecurityAudit::getParam('codes', '', ['required' => true]);
        $validCodes = SecurityAudit::validateCodeList($codes, SecurityAudit::STOCK_CODE_PATTERN, SecurityAudit::MAX_CODES_COUNT);
        $result = $service->quote(implode(',', $validCodes), $source, $fallback, $raw);
        break;

    case 'kline':
        $code = SecurityAudit::getParam('code', '', [
            'required'  => true,
            'pattern'   => SecurityAudit::STOCK_CODE_PATTERN,
            'maxLength' => SecurityAudit::MAX_CODE_LENGTH,
        ]);
        $frequency = SecurityAudit::getParam('frequency', '1d', [
            'whitelist' => SecurityAudit::ALLOWED_FREQUENCIES,
        ]);
        $count = SecurityAudit::getParam('count', 120, [
            'int' => true,
            'min' => 1,
            'max' => 500,
        ]);
        $end_date = SecurityAudit::getParam('end_date', '', [
            'pattern' => SecurityAudit::DATE_PATTERN,
        ]);
        $result = $service->kline($code, $frequency, $count, $end_date, $source, $fallback, $raw);
        break;

    case 'hot_stock':
        $type = SecurityAudit::getParam('type', '10', [
            'whitelist' => SecurityAudit::ALLOWED_XUEQIU_HOT_TYPES,
        ]);
        $size = SecurityAudit::getParam('size', 20, ['int' => true, 'min' => 1, 'max' => 100]);
        $result = $service->hotStock($type, $size, $raw);
        break;

    case 'screener':
        $page = SecurityAudit::getParam('page', 1, ['int' => true, 'min' => 1, 'max' => 100]);
        $size = SecurityAudit::getParam('size', 20, ['int' => true, 'min' => 1, 'max' => 100]);
        $orderBy = SecurityAudit::getParam('order_by', 'percent', [
            'whitelist' => SecurityAudit::ALLOWED_SCREENER_ORDER_FIELDS,
        ]);
        $order = SecurityAudit::getParam('order', 'desc', ['whitelist' => ['asc', 'desc']]);
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
        $xqSource = SecurityAudit::getParam('fundx_source', '', ['sanitize' => 'alphanum']);
        $lastId = SecurityAudit::getParam('last_id', 0, ['int' => true, 'min' => 0]);
        $result = $service->fundx($page, $xqSource, $lastId, $raw);
        break;

    case 'stock_flow':
        $code = SecurityAudit::getParam('code', '', [
            'required'  => true,
            'pattern'   => SecurityAudit::STOCK_CODE_PATTERN,
            'maxLength' => SecurityAudit::MAX_CODE_LENGTH,
        ]);
        $lmt = SecurityAudit::getParam('lmt', 0, ['int' => true, 'min' => 0, 'max' => 1000]);
        $result = $service->stockFlow($code, $lmt);
        break;

    case 'sector_flow':
        $key = SecurityAudit::getParam('key', 'f62', ['whitelist' => SecurityAudit::ALLOWED_SECTOR_KEYS]);
        $type = SecurityAudit::getParam('type', 'industry', ['whitelist' => SecurityAudit::ALLOWED_SECTOR_TYPES]);
        $result = $service->sectorFlow($key, $type);
        break;

    case 'hot_stocks':
        $page = SecurityAudit::getParam('page', 1, ['int' => true, 'min' => 1, 'max' => 100]);
        $pageSize = SecurityAudit::getParam('pagesize', 50, ['int' => true, 'min' => 1, 'max' => 200]);
        $sort = SecurityAudit::getParam('sort', 'f62', ['whitelist' => SecurityAudit::ALLOWED_SORT_FIELDS]);
        $sortOrder = SecurityAudit::getParam('order', 1, ['int' => true]);
        $result = $service->hotStocks($page, $pageSize, $sort, $sortOrder);
        break;

    case 'market_breadth':
        $scope = SecurityAudit::getParam('scope', 'a_share', ['whitelist' => SecurityAudit::ALLOWED_MARKET_BREADTH_SCOPES]);
        $includeLimitStats = SecurityAudit::getParam('include_limit_stats', 1, ['int' => true]) === 1;
        $includeIndexQuotes = SecurityAudit::getParam('include_index_quotes', 1, ['int' => true]) === 1;
        $result = $service->marketBreadth($scope, $includeLimitStats, $includeIndexQuotes);
        break;

    default:
        echo json_encode([
            'success' => false,
            'message' => '未知 action，支持: quote/kline/hot_stock/screener/fundx/stock_flow/sector_flow/hot_stocks/market_breadth',
        ], JSON_UNESCAPED_UNICODE);
        exit;
}

echo $result->toJson($raw);
