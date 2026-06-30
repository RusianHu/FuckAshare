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
