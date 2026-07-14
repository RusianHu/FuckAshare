<?php
/**
 * EastmoneyFundDividendClient - 东方财富全市场基金分红事件源。
 *
 * 事件源：funddataIndex_Interface.aspx?dt=8（按年份、页码、权益登记日倒序）
 *   响应形如：var pageinfo = [total_pages, page_size, current_page]; var jjfh_data=[[code,name,record_date,ex_date,cash,pay_date,flag],...];
 *   严格截取 jjfh_data 后 json_decode，禁止 eval 或执行响应代码。
 *
 * 类型映射：fundcode_search.js  -> var r=[[code,pinyin,name,type_category,pinyin_full],...]
 *   type_category 归一为 stock|index|mixed|bond|money|fof|qdii|reit|other。
 *
 * 使用独立 eastmoney_fund_dividend 熔断器，避免基金事件源故障影响股票分红或其他基金接口。
 */

require_once __DIR__ . '/FundDividendDataProvider.php';
require_once __DIR__ . '/HttpClient.php';
require_once __DIR__ . '/CircuitBreaker.php';

class EastmoneyFundDividendClient implements FundDividendDataProvider
{
    const SOURCE_NAME = 'eastmoney_fund_dividend';
    const DIVIDEND_URL = 'https://fund.eastmoney.com/Data/funddataIndex_Interface.aspx';
    const TYPE_MAP_URL = 'https://fund.eastmoney.com/js/fundcode_search.js';
    const PAGE_SIZE = 100;
    const MAX_PAGES = 100;

    /** @var HttpClient */
    private $http;

    /** @var CircuitBreaker */
    private $breaker;

    public function __construct(?HttpClient $http = null, ?CircuitBreaker $breaker = null)
    {
        $this->http = $http ?: new HttpClient([
            'timeout' => 15,
            'connect_timeout' => 5,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124 Safari/537.36',
                'Accept' => '*/*',
                'Referer' => 'https://fund.eastmoney.com/data/fendongjingzhi.html',
            ],
        ]);
        $this->breaker = $breaker ?: new CircuitBreaker(self::SOURCE_NAME);
    }

    public function sourceName(): string
    {
        return self::SOURCE_NAME;
    }

    public function calendar(string $startDate, string $endDate): DataSourceResult
    {
        if (!$this->breaker->allow()) {
            return DataSourceResult::error(self::SOURCE_NAME, 'fund_dividend_calendar_raw', 'circuit_open', '基金分红数据源熔断中', [
                'circuit_state' => $this->breaker->getState(),
            ]);
        }

        $started = microtime(true);
        $startYear = (int)substr($startDate, 0, 4);
        $endYear = (int)substr($endDate, 0, 4);
        if ($startYear > $endYear) {
            return DataSourceResult::error(self::SOURCE_NAME, 'fund_dividend_calendar_raw', 'invalid_argument', '开始日期不能晚于结束日期');
        }

        $all = [];
        $failures = [];
        $pagesFetched = 0;
        $truncated = false;

        for ($year = $startYear; $year <= $endYear; $year++) {
            $yearResult = $this->fetchYear((string)$year, $startDate, $endDate);
            if ($yearResult->hasData()) {
                foreach ($yearResult->data as $event) {
                    $all[] = $event;
                }
                $pagesFetched += (int)($yearResult->meta['pages'] ?? 0);
                if (!empty($yearResult->meta['truncated'])) {
                    $truncated = true;
                }
            } elseif (!$yearResult->success && $yearResult->errorCode !== 'empty_year') {
                $failures[] = [
                    'year' => (string)$year,
                    'code' => $yearResult->errorCode,
                    'message' => $yearResult->errorMessage,
                ];
            }
        }

        if (empty($all)) {
            if (!empty($failures)) {
                $this->breaker->failure('no_events: ' . $failures[0]['message']);
                return DataSourceResult::error(self::SOURCE_NAME, 'fund_dividend_calendar_raw', $failures[0]['code'], '基金分红数据获取失败: ' . $failures[0]['message'], [
                    'failures' => $failures,
                    'duration_ms' => (int)round((microtime(true) - $started) * 1000),
                ]);
            }
            // 全部年度为空（合法的空结果，不触发熔断）
            $this->breaker->success();
            return DataSourceResult::success(self::SOURCE_NAME, 'fund_dividend_calendar_raw', [], [
                'provider_status' => 200,
                'provider_count' => 0,
                'fetched_count' => 0,
                'pages' => $pagesFetched,
                'years' => range($startYear, $endYear),
                'duration_ms' => (int)round((microtime(true) - $started) * 1000),
                'truncated' => false,
                'failures' => [],
            ]);
        }

        $deduped = $this->dedup($all);
        $this->breaker->success();
        return DataSourceResult::success(self::SOURCE_NAME, 'fund_dividend_calendar_raw', $deduped, [
            'provider_status' => 200,
            'provider_count' => count($deduped),
            'fetched_count' => count($deduped),
            'pages' => $pagesFetched,
            'years' => range($startYear, $endYear),
            'duration_ms' => (int)round((microtime(true) - $started) * 1000),
            'truncated' => $truncated,
            'failures' => $failures,
        ]);
    }

    public function fundTypeMap(): DataSourceResult
    {
        if (!$this->breaker->allow()) {
            return DataSourceResult::error(self::SOURCE_NAME, 'fund_type_map', 'circuit_open', '基金分红数据源熔断中', [
                'circuit_state' => $this->breaker->getState(),
            ]);
        }

        $started = microtime(true);
        $resp = $this->http->get(self::TYPE_MAP_URL, [
            'Referer' => 'https://fund.eastmoney.com/',
            'Accept' => '*/*',
        ]);
        if ($resp['error'] || (int)$resp['http_code'] !== 200) {
            $reason = $resp['error'] ?: 'HTTP ' . (int)$resp['http_code'];
            $this->breaker->failure('type_map_network: ' . $reason);
            return DataSourceResult::error(self::SOURCE_NAME, 'fund_type_map', 'network_error', '请求基金类型映射失败: ' . $reason, [
                'provider_status' => (int)$resp['http_code'],
                'duration_ms' => $this->http->lastDuration,
            ]);
        }

        $map = $this->parseTypeMap($resp['body']);
        if ($map === null) {
            $this->breaker->failure('type_map_parse_error');
            return DataSourceResult::error(self::SOURCE_NAME, 'fund_type_map', 'parse_error', '解析基金类型映射失败', [
                'provider_status' => 200,
                'duration_ms' => $this->http->lastDuration,
            ]);
        }

        $this->breaker->success();
        return DataSourceResult::success(self::SOURCE_NAME, 'fund_type_map', $map, [
            'provider_status' => 200,
            'provider_count' => count($map),
            'duration_ms' => (int)round((microtime(true) - $started) * 1000),
        ]);
    }

    /**
     * 抓取单个年度的分红事件，按权益登记日倒序分页，遇到该页最早登记日早于 startDate 则提前停止。
     */
    private function fetchYear(string $year, string $startDate, string $endDate): DataSourceResult
    {
        $events = [];
        $pages = 0;
        $truncated = false;
        $totalPages = 0;

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $params = http_build_query([
                'dt' => 8,
                'year' => $year,
                'page' => $page,
                'per' => self::PAGE_SIZE,
                // 明确约束登记日倒序，后面的日期提前终止才是安全的。
                'rank' => 'DJR',
                'sort' => 'desc',
                'gs' => '',
                'ftype' => '',
            ]);
            $resp = $this->http->get(self::DIVIDEND_URL . '?' . $params);
            if ($resp['error'] || (int)$resp['http_code'] !== 200) {
                $reason = $resp['error'] ?: 'HTTP ' . (int)$resp['http_code'];
                return DataSourceResult::error(self::SOURCE_NAME, 'fund_dividend_calendar_raw', 'network_error', "请求基金分红失败({$year}年第{$page}页): " . $reason, [
                    'provider_status' => (int)$resp['http_code'],
                    'page' => $page,
                    'year' => $year,
                ]);
            }

            $parsed = $this->parseDividendList($resp['body']);
            if ($parsed === null) {
                return DataSourceResult::error(self::SOURCE_NAME, 'fund_dividend_calendar_raw', 'parse_error', "解析基金分红失败({$year}年第{$page}页)", [
                    'provider_status' => 200,
                    'page' => $page,
                    'year' => $year,
                ]);
            }

            $rows = $parsed['rows'];
            $totalPages = max($totalPages, $parsed['total_pages']);
            if (empty($rows)) {
                break; // 该年度无更多数据
            }
            $pages++;

            $earliestRecord = null;
            foreach ($rows as $row) {
                $event = $this->normalize($row, $year);
                if ($event['record_date'] === '' || $event['code'] === '') continue;
                $rd = $event['record_date'];
                if ($rd >= $startDate && $rd <= $endDate) {
                    $events[] = $event;
                }
                if ($earliestRecord === null || $rd < $earliestRecord) {
                    $earliestRecord = $rd;
                }
            }

            // 提前停止：本页最早登记日已早于查询开始日（数据按登记日倒序，后续页只会更早）
            if ($earliestRecord !== null && $earliestRecord < $startDate) {
                break;
            }
            // 末页：返回行数少于页大小，或已取完该年度总页数。
            if (count($rows) < self::PAGE_SIZE) {
                break;
            }
            if ($totalPages > 0 && $page >= $totalPages) {
                break;
            }
        }

        if ($page > self::MAX_PAGES) {
            $truncated = true;
        }

        if (empty($events) && $pages === 0) {
            // 该年度完全无数据（合法空年度）
            return DataSourceResult::error(self::SOURCE_NAME, 'fund_dividend_calendar_raw', 'empty_year', "{$year}年度无基金分红数据", [
                'year' => $year,
                'pages' => 0,
            ]);
        }

        return DataSourceResult::success(self::SOURCE_NAME, 'fund_dividend_calendar_year', $events, [
            'year' => $year,
            'pages' => $pages,
            'truncated' => $truncated,
            'provider_pages' => $totalPages,
        ]);
    }

    /**
     * 严格截取 jjfh_data=[...] 后 json_decode，禁止 eval。
     */
    private function parseDividendList(string $body): ?array
    {
        if (!preg_match('/pageinfo\s*=\s*\[\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*\]/', $body, $pm)) {
            return null;
        }
        $totalPages = (int)$pm[1];
        $pageSize = (int)$pm[2];
        $currentPage = (int)$pm[3];

        if (!preg_match('/jjfh_data\s*=\s*(\[[\s\S]*?\])\s*;/', $body, $m)) {
            // HTTP 200 的拦截页或上游结构变化不能伪装成合法空年度。
            return null;
        }

        $rows = json_decode($m[1], true);
        if (!is_array($rows)) {
            return null;
        }
        return [
            'rows' => $rows,
            'total_pages' => $totalPages,
            'page_size' => $pageSize,
            'current_page' => $currentPage,
        ];
    }

    private function normalize(array $row, string $year): array
    {
        // [code, name, record_date, ex_date, cash_per_unit, pay_date, flag]
        $code = trim((string)($row[0] ?? ''));
        $name = trim((string)($row[1] ?? ''));
        return [
            'code' => $code,
            'name' => $name,
            'record_date' => $this->date($row[2] ?? null),
            'ex_date' => $this->date($row[3] ?? null),
            'cash_per_unit' => isset($row[4]) && is_numeric($row[4]) ? (float)$row[4] : null,
            'pay_date' => $this->date($row[5] ?? null),
            'source_flag' => trim((string)($row[6] ?? '')),
            'year' => $year,
            'source' => self::SOURCE_NAME,
            'source_url' => $code !== '' ? 'https://fundf10.eastmoney.com/fhsp_' . $code . '.html' : '',
        ];
    }

    private function parseTypeMap(string $body): ?array
    {
        // 去掉 BOM
        $body = preg_replace('/^\x{FEFF}/u', '', $body);
        // 用字符串定位提取外层数组，避免在 3MB 文本上触发 PCRE 回溯上限
        $eqPos = strpos($body, 'var r =');
        if ($eqPos === false) {
            return null;
        }
        $bracketStart = strpos($body, '[', $eqPos);
        if ($bracketStart === false) {
            return null;
        }
        $end = strrpos($body, '];');
        if ($end === false || $end <= $bracketStart) {
            return null;
        }
        $json = substr($body, $bracketStart, $end - $bracketStart + 1);
        $rows = json_decode($json, true);
        if (!is_array($rows)) {
            return null;
        }
        $map = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $code = trim((string)($row[0] ?? ''));
            if (!preg_match('/^\d{6}$/', $code)) continue;
            $typeCategory = (string)($row[3] ?? '');
            $map[$code] = $this->categorize($typeCategory);
        }
        return $map;
    }

    /**
     * 将 fundcode_search.js 的 type_category 归一为：
     * stock|index|mixed|bond|money|fof|qdii|reit|other
     * 结构性类型（reit/qdii/fof）优先于资产类型，便于 fund_category 筛选。
     */
    private function categorize(string $type): string
    {
        if ($type === '') return 'other';
        if (preg_match('/REIT|基础设施/u', $type)) return 'reit';
        if (stripos($type, 'QDII') !== false) return 'qdii';
        if (stripos($type, 'FOF') !== false) return 'fof';
        if (strpos($type, '货币') !== false) return 'money';
        if (strpos($type, '债券') !== false) return 'bond';
        if (strpos($type, '指数') !== false) return 'index';
        if (strpos($type, '混合') !== false) return 'mixed';
        if (strpos($type, '股票') !== false) return 'stock';
        return 'other';
    }

    private function dedup(array $events): array
    {
        $merged = [];
        foreach ($events as $event) {
            $key = implode('|', [
                (string)($event['code'] ?? ''),
                (string)($event['record_date'] ?? ''),
                (string)($event['ex_date'] ?? ''),
                (string)($event['pay_date'] ?? ''),
                number_format((float)($event['cash_per_unit'] ?? 0), 8, '.', ''),
            ]);
            if (!isset($merged[$key])) {
                $merged[$key] = $event;
            }
        }
        return array_values($merged);
    }

    private function date($value): ?string
    {
        $value = trim((string)$value);
        if ($value === '') return null;
        $ts = strtotime($value);
        return $ts === false ? null : date('Y-m-d', $ts);
    }
}
