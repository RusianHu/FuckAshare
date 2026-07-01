<?php
/**
 * EastmoneyClient — 东方财富数据源封装
 *
 * Phase 2: 增加独立熔断器，每次调用经过熔断检查
 */

require_once __DIR__ . '/HttpClient.php';
require_once __DIR__ . '/StockCode.php';
require_once __DIR__ . '/DataSourceResult.php';
require_once __DIR__ . '/CircuitBreaker.php';

class EastmoneyClient
{
    const SOURCE_NAME = 'eastmoney';
    const PUSH2_URL = 'https://push2.eastmoney.com';
    const PUSH2_DELAY_URL = 'https://push2delay.eastmoney.com';
    const PUSH2HIS_URL = 'https://push2his.eastmoney.com';

    /** @var HttpClient */
    private $http;

    /** @var CircuitBreaker 熔断器 */
    private $breaker;

    public function __construct()
    {
        $this->breaker = new CircuitBreaker('eastmoney');
        $this->http = new HttpClient([
            'timeout' => 10,
            'headers' => [
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Referer: https://quote.eastmoney.com/',
            ],
        ]);
    }

    /**
     * 批量实时行情
     *
     * @param string[] $codes 股票代码列表 (支持 sh600519 / 600519.XSHG / 600519)
     * @return DataSourceResult
     */
    public function quote(array $codes): DataSourceResult
    {
        if (!$this->breaker->allow()) {
            $state = $this->breaker->getState();
            return DataSourceResult::error(self::SOURCE_NAME, 'quote', 'circuit_open', '东方财富接口熔断中，暂停请求', [
                'circuit_state' => $state['state'],
                'failures'      => $state['failures'],
                'last_reason'   => $state['last_reason'] ?? '',
            ]);
        }

        $secids = [];
        foreach ($codes as $code) {
            $sc = StockCode::parse($code);
            if ($sc->isValid()) {
                $secids[] = $sc->toEastmoneySecid();
            }
        }

        if (empty($secids)) {
            return DataSourceResult::error(self::SOURCE_NAME, 'quote', 'invalid_code', '无法解析股票代码');
        }

        $secidStr = implode(',', $secids);
        $fields = 'f2,f3,f4,f5,f6,f7,f8,f9,f10,f12,f13,f14,f15,f16,f17,f18,f20,f21,f23,f24,f25,f26,f115';
        $path = "/api/qt/ulist.np/get?fltt=2&fields={$fields}&secids={$secidStr}&_=" . (time() * 1000);

        $resp = $this->getPush2($path);

        if ($resp['error'] || $resp['http_code'] !== 200) {
            $this->breaker->failure('network_error: ' . ($resp['error'] ?: "HTTP {$resp['http_code']}"));
            return DataSourceResult::error(self::SOURCE_NAME, 'quote', 'network_error', '请求东方财富API失败: ' . ($resp['error'] ?: "HTTP {$resp['http_code']}"));
        }

        $parsed = HttpClient::parseJson($resp['body']);
        if (!$parsed['ok'] || !isset($parsed['data']['data'])) {
            $this->breaker->failure('parse_error');
            return DataSourceResult::error(self::SOURCE_NAME, 'quote', 'parse_error', '解析东方财富数据失败');
        }

        $this->breaker->success();

        $stocks = [];
        if (isset($parsed['data']['data']['diff']) && is_array($parsed['data']['data']['diff'])) {
            foreach ($parsed['data']['data']['diff'] as $item) {
                $market = $item['f13'] ?? 1;
                $prefix = ($market === 0) ? 'sz' : 'sh';
                $stocks[] = [
                    'code'          => $item['f12'] ?? '',
                    'market'        => $market,
                    'name'          => $item['f14'] ?? '',
                    'price'         => $item['f2'] ?? 0,
                    'change_pct'    => $item['f3'] ?? 0,
                    'change_amt'    => $item['f4'] ?? 0,
                    'volume'        => $item['f5'] ?? 0,
                    'amount'        => $item['f6'] ?? 0,
                    'amplitude'     => $item['f7'] ?? 0,
                    'turnover_rate' => $item['f8'] ?? 0,
                    'pe'            => $item['f9'] ?? 0,
                    'high'          => $item['f15'] ?? 0,
                    'low'           => $item['f16'] ?? 0,
                    'open'          => $item['f17'] ?? 0,
                    'prev_close'    => $item['f18'] ?? 0,
                    'total_mv'      => $item['f20'] ?? 0,
                    'circ_mv'       => $item['f21'] ?? 0,
                    'pb'            => $item['f23'] ?? 0,
                    'roe'           => $item['f24'] ?? 0,
                    'total_shares'  => $item['f25'] ?? 0,
                    'circ_shares'   => $item['f26'] ?? 0,
                    'pe_ttm'        => $item['f115'] ?? 0,
                    'source'        => self::SOURCE_NAME,
                ];
            }
        }

        return DataSourceResult::success(self::SOURCE_NAME, 'quote', $stocks, [
            'provider_status' => $resp['http_code'],
            'duration' => $this->http->lastDuration,
        ]);
    }

    /**
     * 个股资金流向
     */
    public function stockFlow(string $code, int $lmt = 0): DataSourceResult
    {
        if (!$this->breaker->allow()) {
            return DataSourceResult::error(self::SOURCE_NAME, 'stock_flow', 'circuit_open', '东方财富接口熔断中');
        }

        $sc = StockCode::parse($code);
        if (!$sc->isValid()) {
            return DataSourceResult::error(self::SOURCE_NAME, 'stock_flow', 'invalid_code', "无效股票代码: {$code}");
        }

        $secid = $sc->toEastmoneySecid();
        $path = "/api/qt/stock/fflow/daykline/get?secid={$secid}&fields1=f1,f2,f3,f7&fields2=f51,f52,f53,f54,f55,f56,f57,f58,f59,f60,f61,f62,f63,f64,f65";
        if ($lmt > 0) {
            $path .= "&lmt={$lmt}";
        }

        $resp = $this->getPush2($path, [self::PUSH2HIS_URL, self::PUSH2_DELAY_URL]);

        if ($resp['error'] || $resp['http_code'] !== 200) {
            $this->breaker->failure('network_error');
            return DataSourceResult::error(self::SOURCE_NAME, 'stock_flow', 'network_error', '请求失败: ' . ($resp['error'] ?: "HTTP {$resp['http_code']}"));
        }

        $parsed = HttpClient::parseJson($resp['body']);
        if (!$parsed['ok']) {
            $this->breaker->failure('parse_error');
            return DataSourceResult::error(self::SOURCE_NAME, 'stock_flow', 'parse_error', '解析数据失败');
        }

        $this->breaker->success();

        $flowData = [];
        if (isset($parsed['data']['data']['klines']) && is_array($parsed['data']['data']['klines'])) {
            foreach ($parsed['data']['data']['klines'] as $line) {
                $parts = explode(',', $line);
                if (count($parts) >= 6) {
                    $flowData[] = [
                        'time'              => $parts[0],
                        'main_net_inflow'   => floatval($parts[1]),
                        'small_net_inflow'  => floatval($parts[2]),
                        'mid_net_inflow'    => floatval($parts[3]),
                        'big_net_inflow'    => floatval($parts[4]),
                        'super_net_inflow'  => floatval($parts[5]),
                    ];
                }
            }
        }

        return DataSourceResult::success(self::SOURCE_NAME, 'stock_flow', $flowData, [
            'provider_status' => $resp['http_code'],
        ]);
    }

    /**
     * 板块资金流向
     */
    public function sectorFlow(string $key = 'f62', string $type = 'industry'): DataSourceResult
    {
        if (!$this->breaker->allow()) {
            return DataSourceResult::error(self::SOURCE_NAME, 'sector_flow', 'circuit_open', '东方财富接口熔断中');
        }

        $typeMap = [
            'industry' => 'm:90+s:4',
            'concept'  => 'm:90+e:4',
            'theme'    => 'm:90+t:3',
            'region'   => 'm:90+t:1',
        ];

        $codeParam = $typeMap[$type] ?? $typeMap['industry'];
        $url = "https://data.eastmoney.com/dataapi/bkzj/getbkzj?key={$key}&code={$codeParam}";

        $resp = $this->http->get($url, [
            'Referer: https://data.eastmoney.com/bkzj.html',
        ]);

        if ($resp['error'] || $resp['http_code'] !== 200) {
            $this->breaker->failure('network_error');
            return DataSourceResult::error(self::SOURCE_NAME, 'sector_flow', 'network_error', '请求失败: ' . ($resp['error'] ?: "HTTP {$resp['http_code']}"));
        }

        $parsed = HttpClient::parseJson($resp['body']);
        if (!$parsed['ok'] || !isset($parsed['data']['data']['diff'])) {
            $this->breaker->failure('parse_error');
            return DataSourceResult::error(self::SOURCE_NAME, 'sector_flow', 'parse_error', '解析数据失败');
        }

        $this->breaker->success();

        $sectors = [];
        foreach ($parsed['data']['data']['diff'] as $item) {
            $sectors[] = [
                'code'             => $item['f12'] ?? '',
                'name'             => $item['f14'] ?? '',
                'net_inflow_today' => $item['f62'] ?? 0,
                'net_inflow_5d'    => $item['f164'] ?? 0,
                'net_inflow_10d'   => $item['f174'] ?? 0,
                'change_pct'       => $item['f3'] ?? 0,
                'main_net_inflow'  => $item['f66'] ?? 0,
                'super_net_inflow' => $item['f70'] ?? 0,
                'big_net_inflow'   => $item['f74'] ?? 0,
                'mid_net_inflow'   => $item['f78'] ?? 0,
                'small_net_inflow' => $item['f82'] ?? 0,
                'turnover_rate'    => $item['f8'] ?? 0,
                'amount'           => $item['f6'] ?? 0,
            ];
        }

        return DataSourceResult::success(self::SOURCE_NAME, 'sector_flow', $sectors, [
            'provider_status' => $resp['http_code'],
        ]);
    }

    /**
     * 热门股票（资金流入榜）
     */
    public function hotStocks(int $page = 1, int $pageSize = 50, string $sortField = 'f62', int $sortOrder = 1): DataSourceResult
    {
        if (!$this->breaker->allow()) {
            return DataSourceResult::error(self::SOURCE_NAME, 'hot_stocks', 'circuit_open', '东方财富接口熔断中');
        }

        $fs = 'm:0+t:6,m:0+t:80,m:1+t:2,m:1+t:23';
        $fields = 'f2,f3,f8,f12,f13,f14,f62,f184,f66,f69,f72,f75,f78,f81,f84,f87';
        $path = "/api/qt/clist/get?"
             . "pn={$page}&pz={$pageSize}&po={$sortOrder}&np=1&fltt=2&invt=2"
             . "&fid={$sortField}&fs=" . urlencode($fs) . "&fields={$fields}";

        $resp = $this->getPush2($path, [self::PUSH2_URL, self::PUSH2_DELAY_URL], [
            'Referer: https://data.eastmoney.com/',
        ]);

        if ($resp['error'] || $resp['http_code'] !== 200) {
            $this->breaker->failure('network_error');
            return DataSourceResult::error(self::SOURCE_NAME, 'hot_stocks', 'network_error', '请求东方财富数据失败');
        }

        $parsed = HttpClient::parseJson($resp['body']);
        if (!$parsed['ok'] || !isset($parsed['data']['data']['diff'])) {
            $this->breaker->failure('parse_error');
            return DataSourceResult::error(self::SOURCE_NAME, 'hot_stocks', 'parse_error', '解析东方财富数据失败');
        }

        $this->breaker->success();

        $result = [];
        foreach ($parsed['data']['data']['diff'] as $item) {
            $market = isset($item['f13']) ? intval($item['f13']) : 1;
            $code   = $item['f12'] ?? '';
            $prefix = ($market === 0) ? 'sz' : 'sh';
            $price  = $item['f2'] ?? 0;
            if (!is_numeric($price)) continue;

            $result[] = [
                'dm'    => $prefix . $code,
                'mc'    => $item['f14'] ?? '',
                'zxj'   => floatval($price),
                'zdf'   => floatval($item['f3'] ?? 0),
                'hsl'   => floatval($item['f8'] ?? 0),
                'jlr'   => floatval($item['f62'] ?? 0),
                'jlrl'  => floatval($item['f184'] ?? 0),
                'cjlr_super'       => floatval($item['f66'] ?? 0),
                'cjlr_super_rate'  => floatval($item['f69'] ?? 0),
                'cjlr_big'         => floatval($item['f72'] ?? 0),
                'cjlr_big_rate'    => floatval($item['f75'] ?? 0),
                'cjlr_mid'         => floatval($item['f78'] ?? 0),
                'cjlr_mid_rate'    => floatval($item['f81'] ?? 0),
                'cjlr_small'       => floatval($item['f84'] ?? 0),
                'cjlr_small_rate'  => floatval($item['f87'] ?? 0),
            ];
        }

        return DataSourceResult::success(self::SOURCE_NAME, 'hot_stocks', $result, [
            'provider_status' => $resp['http_code'],
        ]);
    }

    public function marketBreadth(string $scope, bool $includeLimitStats, bool $includeIndexQuotes): DataSourceResult
    {
        $started = microtime(true);
        $allowedScopes = ['a_share', 'sh', 'sz', 'core_indices'];
        if (!in_array($scope, $allowedScopes, true)) {
            $scope = 'a_share';
        }

        if (!$this->breaker->allow()) {
            $state = $this->breaker->getState();
            return DataSourceResult::error(self::SOURCE_NAME, 'market_breadth', 'circuit_open', '东方财富接口熔断中，暂停请求', [
                'circuit_state' => $state['state'],
                'failures' => $state['failures'],
                'last_reason' => $state['last_reason'] ?? '',
            ]);
        }

        $failures = [];
        $indices = $this->fetchMarketBreadthIndices($scope, $failures);
        $aggregate = $this->aggregateFromIndexCounts($indices, $scope);
        $limitStats = $this->emptyLimitStats($includeLimitStats ? 'not_calculated' : 'not_requested');
        $capabilityLevel = 'indices_only';
        $partial = false;
        $scanMeta = [];

        if ($includeLimitStats && $scope !== 'core_indices') {
            $scan = $this->scanMarketBreadth($scope, $failures);
            if ($scan !== null) {
                $aggregate = $scan['aggregate'];
                $limitStats = $scan['limit_stats'];
                $partial = (bool)$scan['partial'];
                $capabilityLevel = $partial ? 'partial_scan' : 'full_scan';
                $scanMeta = $scan['meta'];
            } elseif (!empty($indices)) {
                $partial = true;
                $limitStats = $this->emptyLimitStats('scan_failed');
            }
        }

        if (empty($indices) && $aggregate === null) {
            $this->breaker->failure('market_breadth_no_data');
            return DataSourceResult::error(self::SOURCE_NAME, 'market_breadth', 'empty_data', '东方财富市场宽度数据为空或解析失败', [
                'duration' => microtime(true) - $started,
                'partial' => false,
                'failures' => $failures,
                'capability_level' => 'unavailable',
            ]);
        }

        if ($aggregate === null) {
            $aggregate = $this->buildAggregate('index_constituent_counts', 0, 0, 0, 0, $scope, [
                'note' => '指数涨跌家数字段缺失，无法生成指数口径聚合。',
            ]);
        }

        $this->breaker->success();

        $data = [
            'scope' => $scope,
            'generated_at' => date('c'),
            'indices' => $includeIndexQuotes ? $indices : [],
            'aggregate' => $aggregate,
            'limit_stats' => $limitStats,
        ];

        return DataSourceResult::success(self::SOURCE_NAME, 'market_breadth', $data, array_merge([
            'provider_status' => 200,
            'duration' => microtime(true) - $started,
            'capability_level' => $capabilityLevel,
            'partial' => $partial,
            'failures' => $failures,
            'index_count' => count($indices),
        ], $scanMeta));
    }

    private function fetchMarketBreadthIndices(string $scope, array &$failures): array
    {
        $secids = $this->marketBreadthIndexSecids($scope);
        if (empty($secids)) {
            return [];
        }

        $fields = 'f2,f3,f4,f6,f12,f13,f14,f104,f105,f106';
        $path = "/api/qt/ulist.np/get?fltt=2&fields={$fields}&secids=" . implode(',', $secids) . '&_=' . (time() * 1000);
        $resp = $this->getPush2($path, [self::PUSH2_URL, self::PUSH2_DELAY_URL], [
            'Referer: https://quote.eastmoney.com/',
        ]);

        if ($resp['error'] || $resp['http_code'] !== 200) {
            $failures[] = ['stage' => 'index_quotes', 'code' => 'network_error', 'message' => $resp['error'] ?: "HTTP {$resp['http_code']}"];
            return [];
        }

        $parsed = HttpClient::parseJson($resp['body']);
        if (!$parsed['ok'] || !isset($parsed['data']['data']['diff']) || !is_array($parsed['data']['data']['diff'])) {
            $failures[] = ['stage' => 'index_quotes', 'code' => 'parse_error', 'message' => '指数行情 JSON 解析失败或缺少 diff 字段'];
            return [];
        }

        $map = $this->marketBreadthIndexMap();
        $indices = [];
        foreach ($parsed['data']['data']['diff'] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $secid = (string)($item['f13'] ?? '') . '.' . (string)($item['f12'] ?? '');
            if (!isset($map[$secid])) {
                foreach ($map as $candidateSecid => $meta) {
                    if (($meta['code'] ?? '') === (string)($item['f12'] ?? '')) {
                        $secid = $candidateSecid;
                        break;
                    }
                }
            }
            $indices[] = $this->normalizeMarketBreadthIndex($item, $map[$secid] ?? [], $failures);
        }

        if (empty($indices)) {
            $failures[] = ['stage' => 'index_quotes', 'code' => 'empty_data', 'message' => '指数行情列表为空'];
        }

        return $indices;
    }

    private function marketBreadthIndexMap(): array
    {
        return [
            '1.000001' => ['code' => '000001', 'market' => 'SH', 'name' => '上证指数'],
            '0.399001' => ['code' => '399001', 'market' => 'SZ', 'name' => '深证成指'],
            '0.399006' => ['code' => '399006', 'market' => 'SZ', 'name' => '创业板指'],
            '1.000688' => ['code' => '000688', 'market' => 'SH', 'name' => '科创50'],
            '1.000300' => ['code' => '000300', 'market' => 'SH', 'name' => '沪深300'],
            '1.000016' => ['code' => '000016', 'market' => 'SH', 'name' => '上证50'],
        ];
    }

    private function marketBreadthIndexSecids(string $scope): array
    {
        $map = $this->marketBreadthIndexMap();
        if ($scope === 'sh') {
            return ['1.000001', '1.000688', '1.000016'];
        }
        if ($scope === 'sz') {
            return ['0.399001', '0.399006'];
        }
        return array_keys($map);
    }

    private function normalizeMarketBreadthIndex(array $item, array $meta, array &$failures): array
    {
        $code = (string)($item['f12'] ?? ($meta['code'] ?? ''));
        $marketCode = isset($item['f13']) && is_numeric($item['f13']) ? (int)$item['f13'] : null;
        $market = $meta['market'] ?? $this->marketLabel($marketCode);
        $name = (string)($item['f14'] ?? ($meta['name'] ?? ''));
        $up = $this->nullableInt($item['f104'] ?? null);
        $down = $this->nullableInt($item['f105'] ?? null);
        $flat = $this->nullableInt($item['f106'] ?? null);

        if ($up === null || $down === null || $flat === null) {
            $failures[] = ['stage' => 'index_quotes', 'code' => 'missing_breadth_fields', 'message' => "指数 {$code} 缺少 f104/f105/f106 涨跌家数字段"];
        }

        $total = ($up !== null && $down !== null && $flat !== null) ? $up + $down + $flat : null;

        return [
            'code' => $code,
            'market' => $market,
            'name' => $name,
            'price' => $this->nullableNumber($item['f2'] ?? null),
            'change_pct' => $this->nullableNumber($item['f3'] ?? null),
            'change_amt' => $this->nullableNumber($item['f4'] ?? null),
            'amount' => $this->nullableNumber($item['f6'] ?? null),
            'up_count' => $up,
            'down_count' => $down,
            'flat_count' => $flat,
            'total_count' => $total,
            'advance_decline_ratio' => $this->advanceDeclineRatio($up, $down),
        ];
    }

    private function scanMarketBreadth(string $scope, array &$failures): ?array
    {
        $fs = $this->marketBreadthFs($scope);
        if ($fs === null) {
            $failures[] = ['stage' => 'market_scan', 'code' => 'unsupported_scope', 'message' => "scope={$scope} 不执行全市场扫描"];
            return null;
        }

        $pageSize = 200;
        $maxPages = 80;
        $first = $this->fetchMarketBreadthPage($fs, 1, $pageSize, $failures);
        if ($first === null) {
            return null;
        }

        $total = $first['total'];
        if (!is_numeric($total) || (int)$total <= 0) {
            $failures[] = ['stage' => 'market_scan', 'code' => 'invalid_total', 'message' => '全市场扫描 total 字段异常'];
            return null;
        }

        $total = (int)$total;
        $effectivePageSize = count($first['diff']);
        if ($effectivePageSize <= 0) {
            $failures[] = ['stage' => 'market_scan', 'code' => 'empty_first_page', 'message' => '全市场扫描首页为空'];
            return null;
        }
        $pages = (int)ceil($total / $effectivePageSize);
        $partial = false;
        if ($pages > $maxPages) {
            $failures[] = ['stage' => 'market_scan', 'code' => 'max_pages_exceeded', 'message' => "全市场扫描页数 {$pages} 超过上限 {$maxPages}，仅统计前 {$maxPages} 页"];
            $pages = $maxPages;
            $partial = true;
        }

        $stats = $this->emptyScanStats();
        $pagesScanned = 0;
        $this->accumulateMarketBreadthRows($first['diff'], $stats);
        $pagesScanned++;

        for ($page = 2; $page <= $pages; $page++) {
            $pageData = $this->fetchMarketBreadthPage($fs, $page, $pageSize, $failures);
            if ($pageData === null) {
                $partial = true;
                break;
            }
            $this->accumulateMarketBreadthRows($pageData['diff'], $stats);
            $pagesScanned++;
        }

        $method = $scope === 'a_share' ? 'full_a_share_scan' : 'scoped_a_share_scan';
        $aggregate = $this->buildAggregate($method, $stats['up_count'], $stats['down_count'], $stats['flat_count'], $stats['unknown_count'], $scope, [
            'sample_scope' => $scope,
            'tradable_count' => $stats['tradable_count'],
            'formula' => 'breadth_score = round((up_ratio_pct - down_ratio_pct) / 2 + 50, 2)',
        ]);

        $limitStats = [
            'method' => 'approx_by_pct_threshold',
            'limit_up_count' => $stats['limit_up_count'],
            'limit_down_count' => $stats['limit_down_count'],
            'near_limit_up_count' => $stats['near_limit_up_count'],
            'near_limit_down_count' => $stats['near_limit_down_count'],
            'limit_up_threshold_pct' => 9.8,
            'limit_down_threshold_pct' => -9.8,
            'near_limit_up_threshold_pct' => 7,
            'near_limit_down_threshold_pct' => -7,
            'note' => $this->limitStatsNote(),
        ];

        return [
            'aggregate' => $aggregate,
            'limit_stats' => $limitStats,
            'partial' => $partial,
            'meta' => [
                'scan_scope' => $scope,
                'page_size' => $pageSize,
                'effective_page_size' => $effectivePageSize,
                'pages_scanned' => $pagesScanned,
                'max_pages' => $maxPages,
                'upstream_total' => $total,
            ],
        ];
    }

    private function fetchMarketBreadthPage(string $fs, int $page, int $pageSize, array &$failures): ?array
    {
        $fields = 'f2,f3,f12,f13,f14';
        $path = "/api/qt/clist/get?pn={$page}&pz={$pageSize}&po=1&np=1&fltt=2&invt=2&fid=f3&fs=" . urlencode($fs) . "&fields={$fields}&_=" . (time() * 1000);
        $resp = $this->getPush2($path, [self::PUSH2_URL, self::PUSH2_DELAY_URL], [
            'Referer: https://data.eastmoney.com/',
        ]);

        if ($resp['error'] || $resp['http_code'] !== 200) {
            $failures[] = ['stage' => 'market_scan', 'page' => $page, 'code' => 'network_error', 'message' => $resp['error'] ?: "HTTP {$resp['http_code']}"];
            return null;
        }

        $parsed = HttpClient::parseJson($resp['body']);
        if (!$parsed['ok'] || !isset($parsed['data']['data']['diff']) || !is_array($parsed['data']['data']['diff'])) {
            $failures[] = ['stage' => 'market_scan', 'page' => $page, 'code' => 'parse_error', 'message' => '全市场扫描 JSON 解析失败或缺少 diff 字段'];
            return null;
        }

        return [
            'total' => $parsed['data']['data']['total'] ?? null,
            'diff' => $parsed['data']['data']['diff'],
        ];
    }

    private function marketBreadthFs(string $scope): ?string
    {
        if ($scope === 'a_share') {
            return 'm:0+t:6,m:0+t:80,m:1+t:2,m:1+t:23';
        }
        if ($scope === 'sh') {
            return 'm:1+t:2,m:1+t:23';
        }
        if ($scope === 'sz') {
            return 'm:0+t:6,m:0+t:80';
        }
        return null;
    }

    private function emptyScanStats(): array
    {
        return [
            'up_count' => 0,
            'down_count' => 0,
            'flat_count' => 0,
            'unknown_count' => 0,
            'tradable_count' => 0,
            'limit_up_count' => 0,
            'limit_down_count' => 0,
            'near_limit_up_count' => 0,
            'near_limit_down_count' => 0,
        ];
    }

    private function accumulateMarketBreadthRows(array $rows, array &$stats): void
    {
        foreach ($rows as $row) {
            if (!is_array($row)) {
                $stats['unknown_count']++;
                continue;
            }
            $pct = $this->nullableNumber($row['f3'] ?? null);
            if ($pct === null) {
                $stats['unknown_count']++;
                continue;
            }

            $stats['tradable_count']++;
            if ($pct > 0) {
                $stats['up_count']++;
            } elseif ($pct < 0) {
                $stats['down_count']++;
            } else {
                $stats['flat_count']++;
            }

            if ($pct >= 9.8) {
                $stats['limit_up_count']++;
            }
            if ($pct <= -9.8) {
                $stats['limit_down_count']++;
            }
            if ($pct >= 7) {
                $stats['near_limit_up_count']++;
            }
            if ($pct <= -7) {
                $stats['near_limit_down_count']++;
            }
        }
    }

    private function aggregateFromIndexCounts(array $indices, string $scope): ?array
    {
        $up = 0;
        $down = 0;
        $flat = 0;
        $usable = 0;
        foreach ($indices as $index) {
            if (!is_array($index) || $index['up_count'] === null || $index['down_count'] === null || $index['flat_count'] === null) {
                continue;
            }
            $up += (int)$index['up_count'];
            $down += (int)$index['down_count'];
            $flat += (int)$index['flat_count'];
            $usable++;
        }
        if ($usable === 0) {
            return null;
        }
        return $this->buildAggregate('index_constituent_counts', $up, $down, $flat, 0, $scope, [
            'index_sample_count' => $usable,
            'note' => '指数返回的上涨/下跌/平盘家数字段口径，非全市场逐股精确统计。',
        ]);
    }

    private function buildAggregate(string $method, int $up, int $down, int $flat, int $unknown, string $scope, array $extra = []): array
    {
        $tradable = $up + $down + $flat;
        $total = $tradable + $unknown;
        $upRatio = $tradable > 0 ? round($up * 100 / $tradable, 2) : null;
        $downRatio = $tradable > 0 ? round($down * 100 / $tradable, 2) : null;
        $breadthScore = ($upRatio !== null && $downRatio !== null) ? round(($upRatio - $downRatio) / 2 + 50, 2) : null;

        return array_merge([
            'method' => $method,
            'up_count' => $up,
            'down_count' => $down,
            'flat_count' => $flat,
            'unknown_count' => $unknown,
            'tradable_count' => $tradable,
            'total_count' => $total,
            'coverage_ratio_pct' => $total > 0 ? round($tradable * 100 / $total, 2) : null,
            'up_ratio_pct' => $upRatio,
            'down_ratio_pct' => $downRatio,
            'advance_decline_ratio' => $this->advanceDeclineRatio($up, $down),
            'breadth_score' => $breadthScore,
            'sentiment_label' => $this->sentimentLabel($breadthScore),
            'sample_scope' => $scope,
        ], $extra);
    }

    private function emptyLimitStats(string $method): array
    {
        return [
            'method' => $method,
            'limit_up_count' => null,
            'limit_down_count' => null,
            'near_limit_up_count' => null,
            'near_limit_down_count' => null,
            'note' => $method === 'not_requested' ? '调用参数未要求计算涨停/跌停近似统计。' : '未执行全市场分页扫描，涨停/跌停近似统计不可用。',
        ];
    }

    private function limitStatsNote(): string
    {
        return '涨停/跌停统计为公开行情涨跌幅阈值近似口径，可能不完全覆盖 ST、北交所、上市新股等特殊规则。';
    }

    private function advanceDeclineRatio($up, $down): ?float
    {
        if ($up === null || $down === null || (int)$down <= 0) {
            return null;
        }
        return round((int)$up / max((int)$down, 1), 4);
    }

    private function sentimentLabel(?float $score): string
    {
        if ($score === null) {
            return 'unknown';
        }
        if ($score >= 70) {
            return 'very_positive';
        }
        if ($score >= 58) {
            return 'positive';
        }
        if ($score <= 30) {
            return 'very_negative';
        }
        if ($score <= 42) {
            return 'negative';
        }
        return 'neutral';
    }

    private function nullableNumber($value): ?float
    {
        if ($value === null) {
            return null;
        }
        $text = str_replace(',', '', trim((string)$value));
        if ($text === '' || $text === '-') {
            return null;
        }
        return is_numeric($text) ? (float)$text : null;
    }

    private function nullableInt($value): ?int
    {
        $number = $this->nullableNumber($value);
        return $number === null ? null : (int)$number;
    }

    private function marketLabel(?int $market): string
    {
        if ($market === 0) {
            return 'SZ';
        }
        if ($market === 1) {
            return 'SH';
        }
        return 'UNKNOWN';
    }

    private function getPush2(string $path, array $bases = [self::PUSH2_URL, self::PUSH2_DELAY_URL], array $headers = []): array
    {
        $lastResp = null;
        foreach ($bases as $base) {
            $resp = $this->http->get($base . $path, $headers);
            if (!$resp['error'] && $resp['http_code'] === 200) {
                return $resp;
            }
            $lastResp = $resp;
        }
        return $lastResp ?: [
            'body' => '',
            'http_code' => 0,
            'error' => 'no eastmoney endpoint available',
            'content_type' => '',
        ];
    }
}
