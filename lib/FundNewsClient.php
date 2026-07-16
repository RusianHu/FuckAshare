<?php
/**
 * FundNewsClient — 海外可用的基金专用新闻回退。
 *
 * Google News RSS 补充媒体报道，东方财富基金公告补充产品级重要事件。
 * 仅由 NewsService 的 fund 路径调用，避免六位基金代码与股票代码串线。
 */

require_once __DIR__ . '/NewsDataProvider.php';
require_once __DIR__ . '/HttpClient.php';
require_once __DIR__ . '/DataSourceResult.php';
require_once __DIR__ . '/CircuitBreaker.php';
require_once __DIR__ . '/AppConfig.php';

class FundNewsClient implements NewsDataProvider
{
    const SOURCE_NAME = 'fund_news_fallback';
    const GOOGLE_RSS_URL = 'https://news.google.com/rss/search';
    const EASTMONEY_ANNOUNCEMENT_URL = 'https://api.fund.eastmoney.com/f10/JJGG';

    /** @var HttpClient */
    private $http;

    /** @var CircuitBreaker */
    private $rssBreaker;

    /** @var CircuitBreaker */
    private $announcementBreaker;

    /** @var bool */
    private $rssEnabled;

    /** @var bool */
    private $announcementsEnabled;

    /** @var int */
    private $rssMaxQueries;

    public function __construct(
        ?HttpClient $http = null,
        ?CircuitBreaker $rssBreaker = null,
        ?CircuitBreaker $announcementBreaker = null
    ) {
        $this->http = $http ?: new HttpClient([
            'timeout' => 12,
            'connect_timeout' => 5,
            'headers' => [
                'Accept-Language' => 'zh-CN,zh;q=0.9',
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124.0.0.0 Safari/537.36',
            ],
        ]);
        $this->rssBreaker = $rssBreaker ?: new CircuitBreaker('google_news_rss');
        $this->announcementBreaker = $announcementBreaker ?: new CircuitBreaker('eastmoney_fund_announcements');
        $this->rssEnabled = (bool)AppConfig::get('news.fund_google_news_rss', true);
        $this->announcementsEnabled = (bool)AppConfig::get('news.fund_announcements', true);
        $this->rssMaxQueries = max(1, min(2, (int)AppConfig::get('news.fund_rss_max_queries', 2)));
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
            return DataSourceResult::error(self::SOURCE_NAME, 'search_news', 'invalid_keyword', '基金新闻关键词不能为空');
        }

        $codes = $this->fundCodes($keywords);
        $rssKeywords = $this->rssKeywords($keywords);
        $requests = [];
        $statuses = [];
        $rssAllowed = $this->rssEnabled && $this->rssBreaker->allow();
        $announcementAllowed = $this->announcementsEnabled && $this->announcementBreaker->allow();

        if ($this->rssEnabled && !$rssAllowed) {
            $statuses[] = ['provider' => 'google_news_rss', 'success' => false, 'error' => 'circuit_open'];
        }
        if ($rssAllowed) {
            foreach ($rssKeywords as $keyword) {
                $requests[] = [
                    'key' => 'rss:' . hash('sha256', $keyword),
                    'url' => $this->buildGoogleRssUrl($keyword),
                    'headers' => ['Accept' => 'application/rss+xml, application/xml, text/xml'],
                ];
            }
        }

        if ($this->announcementsEnabled && !$announcementAllowed) {
            $statuses[] = ['provider' => 'eastmoney_fund_announcements', 'success' => false, 'error' => 'circuit_open'];
        }
        if ($announcementAllowed) {
            foreach ($codes as $code) {
                $requests[] = [
                    'key' => 'announcement:' . $code,
                    'url' => $this->buildAnnouncementUrl($code, $limitPerKeyword),
                    'headers' => [
                        'Accept' => 'application/json',
                        'Referer' => "https://fundf10.eastmoney.com/jjgg_{$code}.html",
                    ],
                ];
            }
        }

        if (empty($requests)) {
            return DataSourceResult::error(self::SOURCE_NAME, 'search_news', 'all_sources_unavailable', '基金新闻回退源均未启用或正在熔断', [
                'query_statuses' => $statuses,
                'content_exposed' => false,
            ]);
        }

        $responses = $this->http->multiGet($requests, min(4, count($requests)));
        $items = [];
        $rssSuccess = 0;
        $rssAttempted = 0;
        $announcementSuccess = 0;
        $announcementAttempted = 0;

        foreach ($rssKeywords as $keyword) {
            if (!$rssAllowed) break;
            $rssAttempted++;
            $key = 'rss:' . hash('sha256', $keyword);
            $response = $responses[$key] ?? ['body' => '', 'http_code' => 0, 'error' => 'missing_response'];
            if (!empty($response['error']) || (int)($response['http_code'] ?? 0) !== 200) {
                $statuses[] = $this->failureStatus('google_news_rss', $keyword, $response);
                continue;
            }
            $parsed = $this->parseGoogleRss((string)$response['body'], $keyword, $limitPerKeyword);
            if ($parsed === null) {
                $statuses[] = ['provider' => 'google_news_rss', 'keyword' => $keyword, 'success' => false, 'http_code' => 200, 'error' => 'parse_error'];
                continue;
            }
            $rssSuccess++;
            foreach ($parsed as $item) $items[] = $item;
            $statuses[] = ['provider' => 'google_news_rss', 'keyword' => $keyword, 'success' => true, 'http_code' => 200, 'count' => count($parsed)];
        }

        foreach ($codes as $code) {
            if (!$announcementAllowed) break;
            $announcementAttempted++;
            $key = 'announcement:' . $code;
            $response = $responses[$key] ?? ['body' => '', 'http_code' => 0, 'error' => 'missing_response'];
            if (!empty($response['error']) || (int)($response['http_code'] ?? 0) !== 200) {
                $statuses[] = $this->failureStatus('eastmoney_fund_announcements', $code, $response);
                continue;
            }
            $parsed = $this->parseAnnouncements((string)$response['body'], $code, $limitPerKeyword);
            if ($parsed === null) {
                $statuses[] = ['provider' => 'eastmoney_fund_announcements', 'keyword' => $code, 'success' => false, 'http_code' => 200, 'error' => 'parse_error'];
                continue;
            }
            $announcementSuccess++;
            foreach ($parsed as $item) $items[] = $item;
            $statuses[] = ['provider' => 'eastmoney_fund_announcements', 'keyword' => $code, 'success' => true, 'http_code' => 200, 'count' => count($parsed)];
        }

        $this->recordBreakerResult($this->rssBreaker, $rssAttempted, $rssSuccess, 'Google News RSS 请求失败');
        $this->recordBreakerResult($this->announcementBreaker, $announcementAttempted, $announcementSuccess, '东方财富基金公告请求失败');

        $successCount = $rssSuccess + $announcementSuccess;
        if ($successCount === 0) {
            return DataSourceResult::error(self::SOURCE_NAME, 'search_news', 'all_sources_failed', '基金新闻回退源请求全部失败', [
                'query_statuses' => $statuses,
                'content_exposed' => false,
            ]);
        }

        return DataSourceResult::success(self::SOURCE_NAME, 'search_news', $items, [
            'keywords' => $keywords,
            'query_statuses' => $statuses,
            'partial' => $successCount < ($rssAttempted + $announcementAttempted),
            'capability' => 'fund_media_news_and_product_announcements',
            'rss_count' => count(array_filter($items, function (array $item): bool { return ($item['_provider'] ?? '') === 'google_news_rss'; })),
            'announcement_count' => count(array_filter($items, function (array $item): bool { return ($item['_provider'] ?? '') === 'eastmoney_fund_announcements'; })),
            'content_exposed' => false,
            'copyright_notice' => '媒体报道通过 Google News RSS 索引，基金公告来自东方财富基金公开接口；仅返回标题、来源、时间和链接。',
        ]);
    }

    private function buildGoogleRssUrl(string $keyword): string
    {
        return self::GOOGLE_RSS_URL . '?' . http_build_query([
            'q' => '"' . $keyword . '"',
            'hl' => 'zh-CN',
            'gl' => 'CN',
            'ceid' => 'CN:zh-Hans',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    private function buildAnnouncementUrl(string $code, int $limit): string
    {
        return self::EASTMONEY_ANNOUNCEMENT_URL . '?' . http_build_query([
            'fundcode' => $code,
            'pageIndex' => 1,
            'pageSize' => min(30, $limit),
            'type' => 0,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** @return array<int,array>|null */
    private function parseGoogleRss(string $body, string $keyword, int $limit): ?array
    {
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ($xml === false || !isset($xml->channel)) return null;

        $items = [];
        foreach ($xml->channel->item as $row) {
            $source = $this->cleanText((string)($row->source ?? ''));
            $title = $this->cleanText((string)($row->title ?? ''));
            if ($title === '') continue;
            $suffix = $source !== '' ? ' - ' . $source : '';
            if ($suffix !== '' && mb_substr($title, -mb_strlen($suffix)) === $suffix) {
                $title = trim(mb_substr($title, 0, mb_strlen($title) - mb_strlen($suffix)));
            }
            $url = trim((string)($row->link ?? ''));
            if (!preg_match('#^https://news\.google\.com/rss/articles/#i', $url)) $url = '';
            $items[] = [
                'title' => $title,
                'source' => $source !== '' ? $source : 'Google News',
                'published_at' => $this->normalizeDate((string)($row->pubDate ?? '')),
                'url' => $url,
                '_query' => $keyword,
                '_provider' => 'google_news_rss',
            ];
            if (count($items) >= $limit) break;
        }
        return $items;
    }

    /** @return array<int,array>|null */
    private function parseAnnouncements(string $body, string $code, int $limit): ?array
    {
        $parsed = HttpClient::parseJson($body);
        if (!$parsed['ok'] || !is_array($parsed['data'] ?? null)) return null;
        $rows = $parsed['data']['Data'] ?? null;
        if (!is_array($rows)) return null;

        $items = [];
        foreach (array_slice($rows, 0, $limit) as $row) {
            if (!is_array($row)) continue;
            $title = $this->cleanText((string)($row['TITLE'] ?? ''));
            $id = trim((string)($row['ID'] ?? ''));
            if ($title === '') continue;
            $items[] = [
                'title' => $title,
                'source' => '东方财富基金公告',
                'published_at' => $this->normalizeDate((string)($row['PUBLISHDATE'] ?? $row['PUBLISHDATEDesc'] ?? '')),
                'url' => $id !== '' ? "https://fund.eastmoney.com/gonggao/{$code}," . rawurlencode($id) . '.html' : '',
                '_query' => $code,
                '_provider' => 'eastmoney_fund_announcements',
            ];
        }
        return $items;
    }

    private function normalizeDate(string $value): string
    {
        $value = trim($value);
        if ($value === '') return '';
        try {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('Asia/Shanghai'))->format('Y-m-d H:i:s');
        } catch (Throwable $e) {
            return '';
        }
    }

    private function cleanText(string $value): string
    {
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/[\x{00A0}\x{3000}\s]+/u', ' ', $value);
        return trim((string)$value);
    }

    /** @return string[] */
    private function normalizeKeywords(array $keywords): array
    {
        $result = [];
        foreach (array_slice($keywords, 0, 4) as $keyword) {
            if (!is_scalar($keyword)) continue;
            $keyword = mb_substr(trim((string)preg_replace('/[\x00-\x1F\x7F]/', '', (string)$keyword)), 0, 80);
            if ($keyword !== '') $result[$keyword] = $keyword;
        }
        return array_values($result);
    }

    /** @return string[] */
    private function fundCodes(array $keywords): array
    {
        $codes = [];
        foreach ($keywords as $keyword) {
            if (preg_match('/^\d{6}$/', $keyword)) $codes[$keyword] = $keyword;
        }
        return array_values($codes);
    }

    /** @return string[] */
    private function rssKeywords(array $keywords): array
    {
        usort($keywords, function (string $a, string $b): int {
            return (preg_match('/^\d{6}$/', $b) <=> preg_match('/^\d{6}$/', $a));
        });
        return array_slice($keywords, 0, $this->rssMaxQueries);
    }

    private function failureStatus(string $provider, string $keyword, array $response): array
    {
        return [
            'provider' => $provider,
            'keyword' => $keyword,
            'success' => false,
            'http_code' => (int)($response['http_code'] ?? 0),
            'error' => (string)($response['error'] ?: 'HTTP ' . ($response['http_code'] ?? 0)),
        ];
    }

    private function recordBreakerResult(CircuitBreaker $breaker, int $attempted, int $successes, string $reason): void
    {
        if ($attempted === 0) return;
        if ($successes > 0) $breaker->success(); else $breaker->failure($reason);
    }
}
