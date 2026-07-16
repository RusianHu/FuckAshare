<?php
/**
 * EastmoneyFastNewsClient — 东方财富 7×24 快讯 Provider。
 *
 * 与 search-api-web 使用独立域名/接口；只输出标题、来源、时间、链接，
 * summary 仅在服务端用于关键词相关性判断，不向 API 或 AI 工具透传。
 */

require_once __DIR__ . '/NewsDataProvider.php';
require_once __DIR__ . '/HttpClient.php';
require_once __DIR__ . '/DataSourceResult.php';
require_once __DIR__ . '/CircuitBreaker.php';
require_once __DIR__ . '/AppConfig.php';

class EastmoneyFastNewsClient implements NewsDataProvider
{
    const SOURCE_NAME = 'eastmoney_fast_news';
    const FAST_NEWS_URL = 'https://np-listapi.eastmoney.com/comm/web/getFastNewsList';

    /** @var HttpClient */
    private $http;

    /** @var CircuitBreaker */
    private $breaker;

    /** @var int */
    private $pageSize;

    public function __construct(?HttpClient $http = null, ?CircuitBreaker $breaker = null)
    {
        $this->http = $http ?: new HttpClient([
            'timeout' => 10,
            'connect_timeout' => 5,
            'headers' => [
                'Accept' => 'application/json',
                'Accept-Language' => 'zh-CN,zh;q=0.9',
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124.0.0.0 Safari/537.36',
                'Referer' => 'https://kuaixun.eastmoney.com/',
            ],
        ]);
        $this->breaker = $breaker ?: new CircuitBreaker(self::SOURCE_NAME);
        $this->pageSize = max(20, min(100, (int)AppConfig::get('news.fast_news_page_size', 100)));
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
        $limitPerKeyword = max(1, min(30, $limitPerKeyword));
        if (empty($keywords)) {
            return DataSourceResult::error(self::SOURCE_NAME, 'search_news', 'invalid_keyword', '快讯关键词不能为空');
        }
        if (!$this->breaker->allow()) {
            $state = $this->breaker->getState();
            return DataSourceResult::error(self::SOURCE_NAME, 'search_news', 'circuit_open', '东方财富快讯接口熔断中', [
                'circuit_state' => $state['state'] ?? 'open',
                'failures' => $state['failures'] ?? 0,
            ]);
        }

        $columnKeywords = [];
        foreach ($keywords as $keyword) {
            $column = $this->columnForKeyword($keyword);
            $columnKeywords[$column][] = $keyword;
        }

        $pageSize = min($this->pageSize, max(20, $limitPerKeyword * 4));
        $requests = [];
        foreach ($columnKeywords as $column => $groupKeywords) {
            $requests[] = [
                'key' => (string)$column,
                'url' => $this->buildUrl((string)$column, $pageSize),
            ];
        }

        $responses = $this->http->multiGet($requests, min(3, count($requests)));
        $items = [];
        $statuses = [];
        $successCount = 0;
        foreach ($columnKeywords as $column => $groupKeywords) {
            $response = $responses[(string)$column] ?? ['body' => '', 'http_code' => 0, 'error' => 'missing_response'];
            if (!empty($response['error']) || (int)($response['http_code'] ?? 0) !== 200) {
                $statuses[] = [
                    'column' => (string)$column,
                    'keywords' => $groupKeywords,
                    'success' => false,
                    'http_code' => (int)($response['http_code'] ?? 0),
                    'error' => (string)($response['error'] ?: 'HTTP ' . ($response['http_code'] ?? 0)),
                ];
                continue;
            }

            $parsed = HttpClient::parseJson((string)$response['body']);
            $payload = $parsed['ok'] && is_array($parsed['data'] ?? null) ? $parsed['data'] : [];
            $dataNode = is_array($payload['data'] ?? null) ? $payload['data'] : [];
            $rows = is_array($dataNode['fastNewsList'] ?? null) ? $dataNode['fastNewsList'] : null;
            if ($rows === null) {
                $statuses[] = [
                    'column' => (string)$column,
                    'keywords' => $groupKeywords,
                    'success' => false,
                    'http_code' => 200,
                    'error' => 'parse_error',
                ];
                continue;
            }

            $successCount++;
            $matchedCount = 0;
            foreach ($rows as $row) {
                if (!is_array($row)) continue;
                $matchedKeyword = $this->matchedKeyword($row, $groupKeywords);
                if ($matchedKeyword === '') continue;
                $normalized = $this->normalizeItem($row, $matchedKeyword);
                if ($normalized !== null) {
                    $items[] = $normalized;
                    $matchedCount++;
                }
            }
            $statuses[] = [
                'column' => (string)$column,
                'keywords' => $groupKeywords,
                'success' => true,
                'http_code' => 200,
                'received' => count($rows),
                'matched' => $matchedCount,
            ];
        }

        if ($successCount === 0) {
            $reason = '全部东方财富快讯请求失败';
            $this->breaker->failure($reason);
            return DataSourceResult::error(self::SOURCE_NAME, 'search_news', 'upstream_error', $reason, [
                'query_statuses' => $statuses,
            ]);
        }

        $this->breaker->success();
        return DataSourceResult::success(self::SOURCE_NAME, 'search_news', $items, [
            'keywords' => $keywords,
            'query_statuses' => $statuses,
            'partial' => $successCount < count($columnKeywords),
            'capability' => 'eastmoney_7x24_title_and_security_association',
            'content_exposed' => false,
            'copyright_notice' => '仅返回标题、来源、时间和原文链接；公开商业化使用前需确认数据授权。',
        ]);
    }

    private function buildUrl(string $column, int $pageSize): string
    {
        return self::FAST_NEWS_URL . '?' . http_build_query([
            'client' => 'web',
            'biz' => 'web_724',
            'fastColumn' => $column,
            'sortEnd' => '',
            'pageSize' => $pageSize,
            'req_trace' => '1',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    private function columnForKeyword(string $keyword): string
    {
        if ($this->isFundMarketKeyword($keyword)) return '106'; // 机构/基金
        if ($this->isBroadMarketKeyword($keyword)) return '105'; // 市场
        return '101'; // 全部；用于代码、名称与具体主题过滤
    }

    private function matchedKeyword(array $row, array $keywords): string
    {
        $title = $this->cleanText((string)($row['title'] ?? ''));
        $summary = $this->cleanText((string)($row['summary'] ?? ''));
        $haystack = $title . ' ' . $summary;
        $stockCodes = $this->stockCodes($row['stockList'] ?? []);

        foreach ($keywords as $keyword) {
            if ($this->isBroadMarketKeyword($keyword) || $this->isFundMarketKeyword($keyword)) return $keyword;
            if (preg_match('/(\d{6})/', $keyword, $matches) && in_array($matches[1], $stockCodes, true)) return $keyword;
            if (mb_strlen($keyword) >= 2 && mb_stripos($haystack, $keyword) !== false) return $keyword;
        }
        return '';
    }

    /** @return string[] */
    private function stockCodes($stockList): array
    {
        if (!is_array($stockList)) return [];
        $codes = [];
        foreach ($stockList as $entry) {
            $candidates = [];
            if (is_scalar($entry)) {
                $candidates[] = (string)$entry;
            } elseif (is_array($entry)) {
                foreach (['code', 'securityCode', 'symbol', 'secid'] as $key) {
                    if (isset($entry[$key]) && is_scalar($entry[$key])) $candidates[] = (string)$entry[$key];
                }
            }
            foreach ($candidates as $candidate) {
                if (preg_match('/(?:^|\.)(\d{6})(?:$|\D)/', $candidate, $matches) || preg_match('/(\d{6})/', $candidate, $matches)) {
                    $codes[$matches[1]] = $matches[1];
                }
            }
        }
        return array_values($codes);
    }

    private function normalizeItem(array $row, string $keyword): ?array
    {
        $title = $this->cleanText((string)($row['title'] ?? ''));
        if ($title === '') return null;
        $articleCode = preg_replace('/[^A-Za-z0-9]/', '', (string)($row['code'] ?? ''));
        $url = preg_match('/^\d{12,24}$/', (string)$articleCode)
            ? 'https://finance.eastmoney.com/a/' . $articleCode . '.html'
            : '';
        return [
            'title' => $title,
            'source' => '东方财富快讯',
            'published_at' => $this->normalizeDate((string)($row['showTime'] ?? '')),
            'url' => $url,
            '_query' => $keyword,
        ];
    }

    private function cleanText(string $value): string
    {
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/[\x{00A0}\x{3000}\s]+/u', ' ', $value);
        return trim((string)$value);
    }

    private function normalizeDate(string $value): string
    {
        $value = trim($value);
        if ($value === '') return '';
        try {
            return (new DateTimeImmutable($value, new DateTimeZone('Asia/Shanghai')))->format('Y-m-d H:i:s');
        } catch (Throwable $e) {
            return '';
        }
    }

    /** @return string[] */
    private function normalizeKeywords(array $keywords): array
    {
        $result = [];
        foreach (array_slice($keywords, 0, 4) as $keyword) {
            if (!is_scalar($keyword)) continue;
            $keyword = trim((string)preg_replace('/[\x00-\x1F\x7F]/', '', (string)$keyword));
            $keyword = mb_substr($keyword, 0, 60);
            if ($keyword !== '') $result[$keyword] = $keyword;
        }
        return array_values($result);
    }

    private function isBroadMarketKeyword(string $keyword): bool
    {
        return in_array($keyword, ['A股', '沪指', '上证指数', '深证成指', '创业板', '科创板', '大盘', '市场热点'], true);
    }

    private function isFundMarketKeyword(string $keyword): bool
    {
        return in_array($keyword, ['基金', '基金市场', '公募基金', 'ETF市场'], true);
    }
}
