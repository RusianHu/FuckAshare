<?php
/**
 * EastmoneyDividendClient — 东方财富分红配送数据源。
 *
 * 上游 reportName: RPT_SHAREBONUS_DET
 */

require_once __DIR__ . '/DividendDataProvider.php';
require_once __DIR__ . '/HttpClient.php';
require_once __DIR__ . '/CircuitBreaker.php';

class EastmoneyDividendClient implements DividendDataProvider
{
    const SOURCE_NAME = 'eastmoney_dividend';
    const API_URL = 'https://datacenter-web.eastmoney.com/api/data/v1/get';
    const PAGE_SIZE = 500;
    const MAX_PAGES = 20;

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
                'Accept' => 'application/json,text/plain,*/*',
                'Referer' => 'https://data.eastmoney.com/yjfp/',
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
        $filter = "(EQUITY_RECORD_DATE>='{$startDate}')(EQUITY_RECORD_DATE<='{$endDate}')";
        return $this->fetch('dividend_calendar_raw', $filter, 'EQUITY_RECORD_DATE', '1');
    }

    public function detail(string $code): DataSourceResult
    {
        $filter = '(SECURITY_CODE="' . $code . '")';
        return $this->fetch('dividend_detail_raw', $filter, 'REPORT_DATE', '-1');
    }

    private function fetch(string $action, string $filter, string $sortColumn, string $sortType): DataSourceResult
    {
        if (!$this->breaker->allow()) {
            return DataSourceResult::error(self::SOURCE_NAME, $action, 'circuit_open', '分红数据源熔断中', [
                'circuit_state' => $this->breaker->getState(),
            ]);
        }

        $started = microtime(true);
        $all = [];
        $totalCount = 0;
        $pages = 1;

        for ($page = 1; $page <= min($pages, self::MAX_PAGES); $page++) {
            $params = [
                'sortColumns' => $sortColumn,
                'sortTypes' => $sortType,
                'pageSize' => self::PAGE_SIZE,
                'pageNumber' => $page,
                'reportName' => 'RPT_SHAREBONUS_DET',
                'columns' => 'ALL',
                'source' => 'WEB',
                'client' => 'WEB',
                'filter' => $filter,
            ];
            $resp = $this->http->get(self::API_URL . '?' . http_build_query($params));
            if ($resp['error'] || (int)$resp['http_code'] !== 200) {
                $reason = $resp['error'] ?: 'HTTP ' . (int)$resp['http_code'];
                $this->breaker->failure($reason);
                return DataSourceResult::error(self::SOURCE_NAME, $action, 'network_error', '请求分红数据失败: ' . $reason, [
                    'provider_status' => (int)$resp['http_code'],
                    'duration' => $this->http->lastDuration,
                    'page' => $page,
                ]);
            }

            $parsed = HttpClient::parseJson($resp['body']);
            $json = $parsed['data'] ?? null;
            if (!$parsed['ok'] || !is_array($json) || empty($json['success']) || !isset($json['result'])) {
                $message = is_array($json) ? (string)($json['message'] ?? '上游返回业务错误') : ($parsed['error'] ?? 'JSON解析失败');
                $this->breaker->failure('parse_error: ' . $message);
                return DataSourceResult::error(self::SOURCE_NAME, $action, 'parse_error', '解析分红数据失败: ' . $message, [
                    'provider_status' => (int)$resp['http_code'],
                    'page' => $page,
                ]);
            }

            $result = is_array($json['result']) ? $json['result'] : [];
            $pages = max(1, (int)($result['pages'] ?? 1));
            $totalCount = (int)($result['count'] ?? 0);
            foreach (($result['data'] ?? []) as $row) {
                if (is_array($row)) {
                    $all[] = $this->normalize($row);
                }
            }
        }

        $this->breaker->success();
        return DataSourceResult::success(self::SOURCE_NAME, $action, $all, [
            'provider_status' => 200,
            'provider_count' => $totalCount,
            'fetched_count' => count($all),
            'pages' => min($pages, self::MAX_PAGES),
            'duration_ms' => (int)round((microtime(true) - $started) * 1000),
            'truncated' => $pages > self::MAX_PAGES,
        ]);
    }

    private function normalize(array $row): array
    {
        return [
            'code' => trim((string)($row['SECURITY_CODE'] ?? '')),
            'name' => trim((string)($row['SECURITY_NAME_ABBR'] ?? '')),
            'report_date' => $this->date($row['REPORT_DATE'] ?? null),
            'plan_notice_date' => $this->date($row['PLAN_NOTICE_DATE'] ?? null),
            'notice_date' => $this->date($row['NOTICE_DATE'] ?? null),
            'publish_date' => $this->date($row['PUBLISH_DATE'] ?? null),
            'record_date' => $this->date($row['EQUITY_RECORD_DATE'] ?? null),
            'ex_date' => $this->date($row['EX_DIVIDEND_DATE'] ?? null),
            'pay_date' => null,
            'progress' => trim((string)($row['ASSIGN_PROGRESS'] ?? '')),
            'plan_text' => trim((string)($row['IMPL_PLAN_PROFILE'] ?? '')),
            'cash_per_10' => $this->number($row['PRETAX_BONUS_RMB'] ?? null),
            'bonus_ratio' => $this->number($row['BONUS_RATIO'] ?? null),
            'capitalization_ratio' => $this->number($row['IT_RATIO'] ?? null),
            'total_shares' => $this->number($row['TOTAL_SHARES'] ?? null),
            'source' => self::SOURCE_NAME,
        ];
    }

    private function date($value): ?string
    {
        $value = trim((string)$value);
        if ($value === '') return null;
        $ts = strtotime($value);
        return $ts === false ? null : date('Y-m-d', $ts);
    }

    private function number($value): ?float
    {
        return is_numeric($value) ? (float)$value : null;
    }
}
