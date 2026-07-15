<?php
/**
 * CsindexClient — 中证指数官网历史表现只读客户端。
 */

require_once __DIR__ . '/HttpClient.php';
require_once __DIR__ . '/DataSourceResult.php';
require_once __DIR__ . '/CacheStoreFactory.php';
require_once __DIR__ . '/CircuitBreaker.php';
require_once __DIR__ . '/AppConfig.php';

class CsindexClient
{
    const SOURCE_NAME = 'csindex';
    const ACTION = 'index_history';

    /** @var HttpClient */
    private $http;
    /** @var CacheStore */
    private $cache;
    /** @var CircuitBreaker */
    private $breaker;
    /** @var int */
    private $ttl;
    /** @var int */
    private $negativeTtl;

    public function __construct(?HttpClient $http = null, ?CacheStore $cache = null, ?CircuitBreaker $breaker = null)
    {
        $this->http = $http ?: new HttpClient([
            'timeout' => 12,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0 Safari/537.36',
                'Referer' => 'https://www.csindex.com.cn/',
                'Accept' => 'application/json, text/javascript, */*; q=0.01',
                'X-Requested-With' => 'XMLHttpRequest',
            ],
        ]);
        $this->cache = $cache ?: CacheStoreFactory::getInstance();
        $this->breaker = $breaker ?: new CircuitBreaker(self::SOURCE_NAME);
        $this->ttl = max(30, (int)AppConfig::get('cache_ttl.index_kline', 300));
        $this->negativeTtl = max(1, (int)AppConfig::get('cache_degradation.negative_cache_ttl', 10));
    }

    public function history(string $indexCode, string $startDate, string $endDate): DataSourceResult
    {
        $indexCode = strtoupper(trim($indexCode));
        if (!preg_match('/^\d{6}(?:CNY0[12]0)?$/', $indexCode)) {
            return DataSourceResult::error(self::SOURCE_NAME, self::ACTION, 'invalid_index_code', '中证指数代码格式不正确');
        }
        if (!$this->validDate($startDate) || !$this->validDate($endDate) || $startDate > $endDate) {
            return DataSourceResult::error(self::SOURCE_NAME, self::ACTION, 'invalid_date', '指数历史日期范围无效');
        }

        $key = 'csindex:history:' . $indexCode . ':' . $startDate . ':' . $endDate;
        $cached = $this->cache->get($key);
        if (is_array($cached) && !empty($cached['success'])) {
            return DataSourceResult::success(self::SOURCE_NAME, self::ACTION, $cached['data'] ?? [], array_merge($cached['meta'] ?? [], [
                'cache' => 'hit',
                'cache_backend' => $this->cache->backendName(),
            ]));
        }
        $negative = $this->cache->get($key . ':neg');
        if (is_array($negative)) {
            return DataSourceResult::error(self::SOURCE_NAME, self::ACTION, $negative['code'] ?? 'negative_cache', $negative['message'] ?? '中证指数接口近期失败');
        }
        if (!$this->breaker->allow()) {
            return $this->staleOrError($key, 'circuit_open', '中证指数接口熔断中');
        }

        $url = 'https://www.csindex.com.cn/csindex-home/perf/index-perf?' . http_build_query([
            'indexCode' => $indexCode,
            'startDate' => str_replace('-', '', $startDate),
            'endDate' => str_replace('-', '', $endDate),
        ]);
        $response = $this->http->get($url);
        if ($response['error'] || (int)$response['http_code'] !== 200) {
            $this->breaker->failure('network_error');
            return $this->rememberFailure($key, 'network_error', '请求中证指数历史失败: ' . ($response['error'] ?: 'HTTP ' . $response['http_code']));
        }
        $parsed = HttpClient::parseJson($response['body']);
        if (!$parsed['ok'] || (string)($parsed['data']['code'] ?? '') !== '200' || !is_array($parsed['data']['data'] ?? null)) {
            $this->breaker->failure('parse_error');
            return $this->rememberFailure($key, 'parse_error', '解析中证指数历史失败');
        }

        $rows = [];
        foreach ($parsed['data']['data'] as $item) {
            if (!is_array($item)) continue;
            $date = (string)($item['tradeDate'] ?? '');
            if (preg_match('/^\d{8}$/', $date)) {
                $date = substr($date, 0, 4) . '-' . substr($date, 4, 2) . '-' . substr($date, 6, 2);
            }
            $close = $this->number($item['close'] ?? null);
            if (!$this->validDate($date) || $close === null || $close <= 0) continue;
            $rows[] = [
                'date' => $date,
                'open' => $this->number($item['open'] ?? null),
                'high' => $this->number($item['high'] ?? null),
                'low' => $this->number($item['low'] ?? null),
                'close' => $close,
                'change_pct' => $this->number($item['changePct'] ?? null),
                'volume' => $this->number($item['tradingVol'] ?? null),
                'amount' => $this->number($item['tradingValue'] ?? null),
            ];
        }
        usort($rows, function ($a, $b) { return strcmp($a['date'], $b['date']); });
        if (empty($rows)) {
            $this->breaker->failure('empty_data');
            return $this->rememberFailure($key, 'empty_data', '中证指数历史未返回有效数据');
        }

        $this->breaker->success();
        $meta = [
            'index_code' => $indexCode,
            'series_kind' => substr($indexCode, -6) === 'CNY010' ? 'total_return' : (substr($indexCode, -6) === 'CNY020' ? 'net_total_return' : 'price'),
            'start_date' => $startDate,
            'end_date' => $endDate,
            'records' => count($rows),
            'source_url' => $url,
            'source_note' => '指数历史来自中证指数有限公司官网 index-perf 接口。',
        ];
        if (substr($indexCode, 0, 6) === '932365') {
            $meta['factsheet_url'] = 'https://oss-ch.csindex.com.cn/static/html/csindex/public/uploads/indices/detail/files/zh_CN/932365factsheet.pdf';
        }
        $this->cache->set($key, ['success' => true, 'data' => $rows, 'meta' => $meta], $this->ttl);
        $meta['cache_backend'] = $this->cache->backendName();
        return DataSourceResult::success(self::SOURCE_NAME, self::ACTION, $rows, $meta);
    }

    private function rememberFailure(string $key, string $code, string $message): DataSourceResult
    {
        $this->cache->set($key . ':neg', ['code' => $code, 'message' => $message], $this->negativeTtl);
        return $this->staleOrError($key, $code, $message);
    }

    private function staleOrError(string $key, string $code, string $message): DataSourceResult
    {
        $stale = $this->cache->getStale($key);
        if (is_array($stale) && !empty($stale['success']) && !empty($stale['data'])) {
            return DataSourceResult::fallback(self::SOURCE_NAME, self::ACTION, $stale['data'], self::SOURCE_NAME, $message, array_merge($stale['meta'] ?? [], [
                'cache' => 'stale_fallback',
                'stale_fallback_reason' => $message,
            ]));
        }
        return DataSourceResult::error(self::SOURCE_NAME, self::ACTION, $code, $message);
    }

    private function validDate(string $date): bool
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('Asia/Shanghai'));
        return $parsed && $parsed->format('Y-m-d') === $date;
    }

    private function number($value): ?float
    {
        return is_numeric($value) ? (float)$value : null;
    }
}
