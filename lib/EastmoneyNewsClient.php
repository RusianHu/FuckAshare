<?php
/**
 * EastmoneyNewsClient — 东方财富公开新闻搜索适配器（PoC Provider）。
 *
 * 注意：这是公开网页搜索接口的技术适配，不代表获得内容再分发授权。
 * 对外仅保留 title/source/published_at/url，绝不返回正文或摘要。
 */

require_once __DIR__ . '/NewsDataProvider.php';
require_once __DIR__ . '/HttpClient.php';
require_once __DIR__ . '/DataSourceResult.php';
require_once __DIR__ . '/CircuitBreaker.php';

class EastmoneyNewsClient implements NewsDataProvider
{
    const SOURCE_NAME = 'eastmoney_news';
    const SEARCH_URL = 'https://search-api-web.eastmoney.com/search/jsonp';

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
                'Accept' => '*/*',
                'Accept-Language' => 'zh-CN,zh;q=0.9',
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
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
        $limitPerKeyword = max(1, min(30, $limitPerKeyword));

        if (empty($keywords)) {
            return DataSourceResult::error(self::SOURCE_NAME, 'search_news', 'invalid_keyword', '新闻关键词不能为空');
        }

        if (!$this->breaker->allow()) {
            $state = $this->breaker->getState();
            return DataSourceResult::error(self::SOURCE_NAME, 'search_news', 'circuit_open', '东方财富新闻接口熔断中', [
                'circuit_state' => $state['state'] ?? 'open',
                'failures' => $state['failures'] ?? 0,
                'last_reason' => $state['last_reason'] ?? '',
            ]);
        }

        $requests = [];
        $callbacks = [];
        foreach ($keywords as $index => $keyword) {
            $callback = 'faNews' . substr(hash('sha256', $keyword . '|' . microtime(true) . '|' . $index), 0, 12);
            $callbacks[$keyword] = $callback;
            $requests[] = [
                'key' => $keyword,
                'url' => $this->buildUrl($keyword, $limitPerKeyword, $callback),
                'headers' => [
                    'Referer' => 'https://so.eastmoney.com/news/s?keyword=' . rawurlencode($keyword),
                ],
            ];
        }

        $responses = $this->http->multiGet($requests, min(4, count($requests)));
        $items = [];
        $statuses = [];
        $successCount = 0;

        foreach ($keywords as $keyword) {
            $response = $responses[$keyword] ?? ['body' => '', 'http_code' => 0, 'error' => 'missing_response', 'content_type' => ''];
            if (!empty($response['error']) || (int)$response['http_code'] !== 200) {
                $statuses[] = [
                    'keyword' => $keyword,
                    'success' => false,
                    'http_code' => (int)($response['http_code'] ?? 0),
                    'error' => (string)($response['error'] ?: 'HTTP ' . ($response['http_code'] ?? 0)),
                ];
                continue;
            }

            $parsed = $this->parseJsonp((string)$response['body'], $callbacks[$keyword]);
            if ($parsed === null) {
                $statuses[] = [
                    'keyword' => $keyword,
                    'success' => false,
                    'http_code' => 200,
                    'error' => 'parse_error',
                ];
                continue;
            }

            $successCount++;
            $rows = $parsed['result']['cmsArticleWebOld'] ?? [];
            $rowCount = 0;
            if (is_array($rows)) {
                foreach ($rows as $row) {
                    if (!is_array($row)) continue;
                    $normalized = $this->normalizeItem($row, $keyword);
                    if ($normalized !== null) {
                        $items[] = $normalized;
                        $rowCount++;
                    }
                }
            }
            $statuses[] = [
                'keyword' => $keyword,
                'success' => true,
                'http_code' => 200,
                'count' => $rowCount,
            ];
        }

        if ($successCount === 0) {
            $reason = '全部新闻搜索请求失败';
            $this->breaker->failure($reason);
            return DataSourceResult::error(self::SOURCE_NAME, 'search_news', 'upstream_error', $reason, [
                'query_statuses' => $statuses,
            ]);
        }

        $this->breaker->success();
        return DataSourceResult::success(self::SOURCE_NAME, 'search_news', $items, [
            'keywords' => $keywords,
            'query_statuses' => $statuses,
            'partial' => $successCount < count($keywords),
            'copyright_notice' => '仅返回标题、来源、时间和原文链接；公开商业化使用前需确认数据授权。',
        ]);
    }

    private function buildUrl(string $keyword, int $limit, string $callback): string
    {
        $inner = [
            'uid' => '',
            'keyword' => $keyword,
            'type' => ['cmsArticleWebOld'],
            'client' => 'web',
            'clientType' => 'web',
            'clientVersion' => 'curr',
            'param' => [
                'cmsArticleWebOld' => [
                    'searchScope' => 'default',
                    'sort' => 'default',
                    'pageIndex' => 1,
                    'pageSize' => $limit,
                    'preTag' => '<em>',
                    'postTag' => '</em>',
                ],
            ],
        ];

        return self::SEARCH_URL . '?' . http_build_query([
            'cb' => $callback,
            'param' => json_encode($inner, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            '_' => (string)round(microtime(true) * 1000),
        ], '', '&', PHP_QUERY_RFC3986);
    }

    private function parseJsonp(string $body, string $callback): ?array
    {
        $body = trim($body);
        if (!preg_match('/^' . preg_quote($callback, '/') . '\((.*)\)\s*;?$/s', $body, $matches)) {
            return null;
        }
        $parsed = HttpClient::parseJson($matches[1]);
        return $parsed['ok'] && is_array($parsed['data']) ? $parsed['data'] : null;
    }

    private function normalizeItem(array $row, string $keyword): ?array
    {
        $title = $this->cleanText((string)($row['title'] ?? ''));
        if ($title === '') return null;

        $source = $this->cleanText((string)($row['mediaName'] ?? '东方财富'));
        $publishedAt = $this->normalizeDate((string)($row['date'] ?? ''));
        $url = (string)($row['url'] ?? '');
        if ($url === '' && !empty($row['code'])) {
            $url = 'https://finance.eastmoney.com/a/' . rawurlencode((string)$row['code']) . '.html';
        }
        if (stripos($url, 'http://') === 0) {
            $url = 'https://' . substr($url, 7);
        }
        if (!preg_match('#^https?://#i', $url)) {
            $url = '';
        }

        return [
            'title' => $title,
            'source' => $source !== '' ? $source : '东方财富',
            'published_at' => $publishedAt,
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
            $date = new DateTimeImmutable($value, new DateTimeZone('Asia/Shanghai'));
            return $date->format('Y-m-d H:i:s');
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
}
