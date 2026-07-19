<?php
/**
 * 聚合行情服务 API
 * 给前端工作台使用的统一入口
 *
 * action: quote / kline / hot_stock / screener / fundx / stock_flow / sector_flow / hot_stocks / market_breadth / dividend_calendar / dividend_detail / dividend_event_market
 *   dividend_* 支持 asset_type=stock|fund（默认 stock，股票参数和响应不变；fund 走 FundDividendService）
 * source: auto / eastmoney / ashare / xueqiu
 * fallback: 1 / 0
 * raw: 1 / 0
 * format: envelope（可选）输出统一 status + meta.data_status，默认保持旧格式
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/SecurityAudit.php';
require_once __DIR__ . '/lib/MarketDataService.php';
require_once __DIR__ . '/lib/DividendService.php';
require_once __DIR__ . '/lib/FundDividendService.php';

SecurityAudit::init(['endpoint' => 'market_api', 'rate_limit' => 40]);

// ── 通用参数 ──

$source   = SecurityAudit::getParam('source', 'auto', ['whitelist' => SecurityAudit::ALLOWED_DATA_SOURCES]);
$fallback = SecurityAudit::getParam('fallback', 1, ['int' => true]) === 1;
$raw      = SecurityAudit::getParam('raw', 0, ['int' => true]) === 1;
$format   = SecurityAudit::getParam('format', '', ['maxLength' => 16]);
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

    case 'dividend_calendar':
        $assetType = SecurityAudit::getParam('asset_type', 'stock', ['whitelist' => SecurityAudit::ALLOWED_DIVIDEND_ASSET_TYPES]);
        if ($assetType === 'fund') {
            $fundDividend = new FundDividendService();
            $fundDefaults = $fundDividend->defaults();
            $fundStart = SecurityAudit::getParam('start_date', $fundDefaults['start_date'], ['pattern' => SecurityAudit::DATE_PATTERN]);
            $fundEnd = SecurityAudit::getParam('end_date', $fundDefaults['end_date'], ['pattern' => SecurityAudit::DATE_PATTERN]);
            $fundCategory = SecurityAudit::getParam('fund_category', 'all', ['whitelist' => SecurityAudit::ALLOWED_FUND_DIVIDEND_CATEGORIES]);
            $minRatio = SecurityAudit::getParam('min_distribution_ratio', '0', ['pattern' => SecurityAudit::PERCENT_PATTERN, 'maxLength' => 8]);
            $fundSortBy = SecurityAudit::getParam('sort_by', 'record_date', ['whitelist' => SecurityAudit::ALLOWED_FUND_DIVIDEND_SORT_FIELDS]);
            $fundOrder = SecurityAudit::getParam('order', 'asc', ['whitelist' => ['asc', 'desc']]);
            $fundPage = SecurityAudit::getParam('page', 1, ['int' => true, 'min' => 1, 'max' => 1000]);
            $fundPageSize = SecurityAudit::getParam('page_size', 50, ['int' => true, 'min' => 1, 'max' => 100]);
            $result = $fundDividend->calendar([
                'start_date' => $fundStart,
                'end_date' => $fundEnd,
                'fund_category' => $fundCategory,
                'min_distribution_ratio' => (float)$minRatio,
                'sort_by' => $fundSortBy,
                'order' => $fundOrder,
                'page' => $fundPage,
                'page_size' => $fundPageSize,
            ]);
            break;
        }
        $dividend = new DividendService();
        $defaults = $dividend->defaults();
        $startDate = SecurityAudit::getParam('start_date', $defaults['start_date'], ['pattern' => SecurityAudit::DATE_PATTERN]);
        $endDate = SecurityAudit::getParam('end_date', $defaults['end_date'], ['pattern' => SecurityAudit::DATE_PATTERN]);
        $dividendMarket = SecurityAudit::getParam('market', 'all', ['whitelist' => SecurityAudit::ALLOWED_DIVIDEND_MARKETS]);
        $status = SecurityAudit::getParam('status', 'confirmed', ['whitelist' => SecurityAudit::ALLOWED_DIVIDEND_STATUSES]);
        $holdingPeriod = SecurityAudit::getParam('holding_period', 'within_1m', ['whitelist' => SecurityAudit::ALLOWED_DIVIDEND_HOLDING_PERIODS]);
        $minYield = SecurityAudit::getParam('min_yield', '0', ['pattern' => SecurityAudit::PERCENT_PATTERN, 'maxLength' => 8]);
        $sortBy = SecurityAudit::getParam('sort_by', 'gross_yield', ['whitelist' => SecurityAudit::ALLOWED_DIVIDEND_SORT_FIELDS]);
        $dividendOrder = SecurityAudit::getParam('order', 'desc', ['whitelist' => ['asc', 'desc']]);
        $page = SecurityAudit::getParam('page', 1, ['int' => true, 'min' => 1, 'max' => 1000]);
        $pageSize = SecurityAudit::getParam('page_size', 50, ['int' => true, 'min' => 1, 'max' => 100]);
        $result = $dividend->calendar([
            'start_date' => $startDate,
            'end_date' => $endDate,
            'market' => $dividendMarket,
            'status' => $status,
            'holding_period' => $holdingPeriod,
            'min_yield' => (float)$minYield,
            'sort_by' => $sortBy,
            'order' => $dividendOrder,
            'page' => $page,
            'page_size' => $pageSize,
        ]);
        break;

    case 'dividend_detail':
        $assetType = SecurityAudit::getParam('asset_type', 'stock', ['whitelist' => SecurityAudit::ALLOWED_DIVIDEND_ASSET_TYPES]);
        if ($assetType === 'fund') {
            $fundCode = SecurityAudit::getParam('code', '', [
                'required' => true,
                'pattern' => SecurityAudit::FUND_CODE_PATTERN,
            ]);
            $fundEventDate = SecurityAudit::getParam('event_date', '', ['pattern' => SecurityAudit::DATE_PATTERN]);
            $result = (new FundDividendService())->detail($fundCode, $fundEventDate !== '' ? $fundEventDate : null);
            break;
        }
        $code = SecurityAudit::getParam('code', '', [
            'required' => true,
            'pattern' => SecurityAudit::STOCK_CODE_PATTERN,
            'maxLength' => SecurityAudit::MAX_CODE_LENGTH,
        ]);
        $years = SecurityAudit::getParam('years', 10, ['int' => true, 'min' => 1, 'max' => 20]);
        $historyScope = SecurityAudit::getParam('history_scope', 'years', ['whitelist' => ['years', 'all']]);
        $holdingPeriod = SecurityAudit::getParam('holding_period', 'within_1m', ['whitelist' => SecurityAudit::ALLOWED_DIVIDEND_HOLDING_PERIODS]);
        $result = (new DividendService())->detail($code, $historyScope === 'all' ? null : $years, $holdingPeriod);
        break;

    case 'dividend_event_market':
        $assetType = SecurityAudit::getParam('asset_type', 'stock', ['whitelist' => SecurityAudit::ALLOWED_DIVIDEND_ASSET_TYPES]);
        if ($assetType === 'fund') {
            $fundCode = SecurityAudit::getParam('code', '', [
                'required' => true,
                'pattern' => SecurityAudit::FUND_CODE_PATTERN,
            ]);
            $fundEventDate = SecurityAudit::getParam('event_date', '', ['required' => true, 'pattern' => SecurityAudit::DATE_PATTERN]);
            $fundBefore = SecurityAudit::getParam('before', 10, ['int' => true, 'min' => 5, 'max' => 30]);
            $fundAfter = SecurityAudit::getParam('after', 15, ['int' => true, 'min' => 5, 'max' => 30]);
            $result = (new FundDividendService())->eventMarketWindow($fundCode, $fundEventDate, $fundBefore, $fundAfter);
            break;
        }
        $code = SecurityAudit::getParam('code', '', [
            'required' => true,
            'pattern' => SecurityAudit::STOCK_CODE_PATTERN,
            'maxLength' => SecurityAudit::MAX_CODE_LENGTH,
        ]);
        $eventDate = SecurityAudit::getParam('event_date', '', ['required' => true, 'pattern' => SecurityAudit::DATE_PATTERN]);
        $before = SecurityAudit::getParam('before', 10, ['int' => true, 'min' => 5, 'max' => 30]);
        $after = SecurityAudit::getParam('after', 15, ['int' => true, 'min' => 5, 'max' => 30]);
        $result = (new DividendService())->eventMarketWindow($code, $eventDate, $before, $after);
        break;

    default:
        echo json_encode([
            'success' => false,
            'message' => '未知 action，支持: quote/kline/hot_stock/screener/fundx/stock_flow/sector_flow/hot_stocks/market_breadth/dividend_calendar/dividend_detail/dividend_event_market',
        ], JSON_UNESCAPED_UNICODE);
        exit;
}

if ($format === 'envelope') {
    // 统一 envelope：status + meta.data_status（默认旧格式不变，与 stock_quote_api 一致）
    echo json_encode($result->toEnvelope(), JSON_UNESCAPED_UNICODE);
} else {
    echo $result->toJson($raw);
}
