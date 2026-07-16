<?php
/**
 * EastmoneyF10NewsClient — 东方财富个股 F10 公司资讯 Provider。
 *
 * 该接口与站内搜索使用独立域名，按股票代码返回公司资讯；只公开四字段，
 * summary 等正文型字段不会离开 Provider。
 */

require_once __DIR__ . '/NewsDataProvider.php';
require_once __DIR__ . '/HttpClient.php';
require_once __DIR__ . '/DataSourceResult.php';
require_once __DIR__ . '/CircuitBreaker.php';

class EastmoneyF10NewsClient implements NewsDataProvider
{
    const SOURCE_NAME = 'eastmoney_f10_news';
    const F10_URL = 'https://emweb.securities.eastmoney.com/PC_HSF10/NewsBulletin/PageAjax';

    /** @var HttpClient */
    private $http;

    /** @var CircuitBreaker */
    private $breaker;

    public function __construct(?HttpClient $http = null, ?CircuitBreaker $breaker = null)
    {
        $this->http = $http ?: new HttpClient([
            'timeout' => 10,
            'connect_timeout' => 5,
            'headers' => [
                'Accept' => 'application/json',
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124.0.0.0 Safari/537.36',
                'Referer' => 'https://emweb.securities.eastmoney.com/',
            ],
        ]);
        $this->breaker = $breaker ?: new CircuitBreaker(self::SOURCE_NAME);
    }

    public function sourceName(): string
    {
        return self::SOURCE_NAME;
    }

    public function search(string $keyword, int $limit = 20): DataSourceResult
    {
        return $this->searchMany([$keyword], $limit);
    }

    public function searchMany(array $keywords, int $limitPerKeyword = 20): DataSourceResult
    {
        $keywords = $this->normalizeKeywords($keywords);
        $limitPerKeyword = max(1, min(20, $limitPerKeyword));
        if (empty($keywords)) {
            return DataSourceResult::error(self::SOURCE_NAME, 'search_news', 'invalid_keyword', 'F10 新闻关键词不能为空');
        }

        // 基金名称 + 六位代码也会进入新闻 Provider；F10 股票接口不得误把基金代码当股票。
        if ($this->looksLikeFundQuery($keywords)) {
            return DataSourceResult::success(self::SOURCE_NAME, 'search_news', [], [
                'keywords' => $keywords,
                'capability' => 'stock_code_required',
                'skipped_reason' => 'fund_query_not_supported',
                'content_exposed' => false,
            ]);
        }

        $stocks = $this->stockQueries($keywords);
        if (empty($stocks)) {
            return DataSourceResult::success(self::SOURCE_NAME, 'search_news', [], [
                'keywords' => $keywords,
                'capability' => 'stock_code_required',
                'skipped_reason' => 'no_stock_code',
                'content_exposed' => false,
            ]);
        }
        if (!$this->breaker->allow()) {
            $state = $this->breaker->getState();
            return DataSourceResult::error(self::SOURCE_NAME, 'search_news', 'circuit_open', '东方财富 F10 资讯接口熔断中', [
                'circuit_state' => $state['state'] ?? 'open',
                'failures' => $state['failures'] ?? 0,
            ]);
        }

        $requests = [];
        foreach ($stocks as $code => $query) {
            $requests[] = [
                'key' => $code,
                'url' => self::F10_URL . '?' . http_build_query(['code' => $query['f10_code']], '', '&', PHP_QUERY_RFC3986),
            ];
        }
        $responses = $this->http->multiGet($requests, min(3, count($requests)));
        $items = [];
        $statuses = [];
        $successCount = 0;

        foreach ($stocks as $code => $query) {
            $response = $responses[$code] ?? ['body' => '', 'http_code' => 0, 'error' => 'missing_response'];
            if (!empty($response['error']) || (int)($response['http_code'] ?? 0) !== 200) {
                $statuses[] = ['code' => $code, 'success' => false, 'http_code' => (int)($response['http_code'] ?? 0), 'error' => (string)($response['error'] ?: 'HTTP ' . ($response['http_code'] ?? 0))];
                continue;
            }
            $parsed = HttpClient::parseJson((string)$response['body']);
            $payload = $parsed['ok'] && is_array($parsed['data'] ?? null) ? $parsed['data'] : [];
            $gszx = is_array($payload['gszx'] ?? null) ? $payload['gszx'] : [];
            $dataNode = is_array($gszx['data'] ?? null) ? $gszx['data'] : [];
            $rows = is_array($dataNode['items'] ?? null) ? $dataNode['items'] : null;
            if ($rows === null) {
                $statuses[] = ['code' => $code, 'success' => false, 'http_code' => 200, 'error' => 'parse_error'];
                continue;
            }

            $successCount++;
            $rowCount = 0;
            foreach (array_slice($rows, 0, $limitPerKeyword) as $row) {
                if (!is_array($row)) continue;
                $normalized = $this->normalizeItem($row, $code);
                if ($normalized !== null) {
                    $items[] = $normalized;
                    $rowCount++;
                }
            }
            $statuses[] = ['code' => $code, 'success' => true, 'http_code' => 200, 'count' => $rowCount];
        }

        if ($successCount === 0) {
            $reason = '全部东方财富 F10 资讯请求失败';
            $this->breaker->failure($reason);
            return DataSourceResult::error(self::SOURCE_NAME, 'search_news', 'upstream_error', $reason, ['query_statuses' => $statuses]);
        }

        $this->breaker->success();
        return DataSourceResult::success(self::SOURCE_NAME, 'search_news', $items, [
            'keywords' => $keywords,
            'query_statuses' => $statuses,
            'partial' => $successCount < count($stocks),
            'capability' => 'eastmoney_stock_f10_company_news',
            'content_exposed' => false,
            'copyright_notice' => '仅返回标题、来源、时间和原文链接；公开商业化使用前需确认数据授权。',
        ]);
    }

    private function normalizeItem(array $row, string $queryCode): ?array
    {
        $title = $this->cleanText((string)($row['title'] ?? ''));
        if ($title === '') return null;
        $url = (string)($row['uniqueUrl'] ?? $row['url'] ?? '');
        if (stripos($url, 'http://') === 0) $url = 'https://' . substr($url, 7);
        if (!preg_match('#^https://(?:finance\.)?eastmoney\.com/#i', $url)) $url = '';
        return [
            'title' => $title,
            'source' => $this->cleanText((string)($row['source'] ?? '')) ?: '东方财富',
            'published_at' => $this->timestampToDate($row['showDateTime'] ?? $row['publishDate'] ?? null),
            'url' => $url,
            '_query' => $queryCode,
        ];
    }

    private function timestampToDate($value): string
    {
        if (!is_numeric($value) || (float)$value <= 0) return '';
        $timestamp = (float)$value > 100000000000 ? (int)floor((float)$value / 1000) : (int)$value;
        try {
            return (new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone('Asia/Shanghai'))->format('Y-m-d H:i:s');
        } catch (Throwable $e) {
            return '';
        }
    }

    /** @return array<string,array{f10_code:string}> */
    private function stockQueries(array $keywords): array
    {
        $stocks = [];
        foreach ($keywords as $keyword) {
            if (!preg_match('/(?:(SH|SZ|BJ))?(\d{6})/i', $keyword, $matches)) continue;
            $code = $matches[2];
            $market = strtoupper((string)($matches[1] ?? ''));
            if ($market === '') {
                if (preg_match('/^[69]/', $code)) $market = 'SH';
                elseif (preg_match('/^[48]/', $code)) $market = 'BJ';
                else $market = 'SZ';
            }
            $stocks[$code] = ['f10_code' => $market . $code];
        }
        return $stocks;
    }

    private function looksLikeFundQuery(array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if (preg_match('/基金|混合|债券|ETF|LOF|QDII|FOF|联接/u', $keyword)) return true;
        }
        return false;
    }

    /** @return string[] */
    private function normalizeKeywords(array $keywords): array
    {
        $result = [];
        foreach (array_slice($keywords, 0, 4) as $keyword) {
            if (!is_scalar($keyword)) continue;
            $keyword = mb_substr(trim((string)preg_replace('/[\x00-\x1F\x7F]/', '', (string)$keyword)), 0, 60);
            if ($keyword !== '') $result[$keyword] = $keyword;
        }
        return array_values($result);
    }

    private function cleanText(string $value): string
    {
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/[\x{00A0}\x{3000}\s]+/u', ' ', $value);
        return trim((string)$value);
    }
}
