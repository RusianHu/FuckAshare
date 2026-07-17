<?php
/**
 * EastmoneyAnnouncementClient — 东方财富公开股票公告列表与正文适配器。
 *
 * 这是公开网页接口的技术适配，不代表接口 SLA 或再分发授权。
 */

require_once __DIR__ . '/AnnouncementDataProvider.php';
require_once __DIR__ . '/HttpClient.php';
require_once __DIR__ . '/DataSourceResult.php';
require_once __DIR__ . '/CircuitBreaker.php';

class EastmoneyAnnouncementClient implements AnnouncementDataProvider
{
    const SOURCE_NAME = 'eastmoney_announcements';
    const LIST_URL = 'https://np-anotice-stock.eastmoney.com/api/security/ann';
    const DETAIL_URL = 'https://np-cnotice-stock.eastmoney.com/api/content/ann';

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
                'Accept' => 'application/json, text/plain, */*',
                'Accept-Language' => 'zh-CN,zh;q=0.9',
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124 Safari/537.36',
                'Referer' => 'https://data.eastmoney.com/notices/',
            ],
        ]);
        $this->breaker = $breaker ?: new CircuitBreaker(self::SOURCE_NAME);
    }

    public function sourceName(): string
    {
        return self::SOURCE_NAME;
    }

    public function listAnnouncements(array $query): DataSourceResult
    {
        if (!$this->breaker->allow()) {
            return DataSourceResult::error(self::SOURCE_NAME, 'announcement_list', 'circuit_open', '公告列表接口熔断中', [
                'circuit_state' => $this->breaker->getState(),
            ]);
        }

        $marketMap = ['all' => 'SHA,SZA,BJA', 'sh' => 'SHA', 'sz' => 'SZA', 'bj' => 'BJA'];
        $market = strtolower(trim((string)($query['market'] ?? 'all')));
        if (!isset($marketMap[$market])) $market = 'all';
        $page = max(1, (int)($query['page'] ?? 1));
        $pageSize = max(1, min(100, (int)($query['page_size'] ?? 100)));
        $params = [
            'page_size' => $pageSize,
            'page_index' => $page,
            'ann_type' => $marketMap[$market],
            'client_source' => 'web',
            'f_node' => '0',
            's_node' => '0',
        ];
        $code = preg_replace('/\D/', '', (string)($query['code'] ?? ''));
        if (preg_match('/^\d{6}$/', $code)) $params['stock_list'] = $code;
        $dateFrom = trim((string)($query['date_from'] ?? ''));
        $dateTo = trim((string)($query['date_to'] ?? ''));
        if ($this->validDate($dateFrom)) $params['begin_time'] = $dateFrom;
        if ($this->validDate($dateTo)) $params['end_time'] = $dateTo;

        $url = self::LIST_URL . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $resp = $this->http->get($url);
        if (($resp['error'] ?? null) || (int)($resp['http_code'] ?? 0) !== 200) {
            $message = ($resp['error'] ?? '') ?: 'HTTP ' . (int)($resp['http_code'] ?? 0);
            $this->breaker->failure($message);
            return DataSourceResult::error(self::SOURCE_NAME, 'announcement_list', 'network_error', '公告列表请求失败: ' . $message, [
                'http_code' => (int)($resp['http_code'] ?? 0),
                'duration_ms' => (int)round($this->http->lastDuration * 1000),
            ]);
        }
        $parsed = HttpClient::parseJson((string)($resp['body'] ?? ''));
        $payload = $parsed['data'] ?? null;
        if (!$parsed['ok'] || !is_array($payload) || !is_array($payload['data'] ?? null)) {
            $this->breaker->failure('parse_error');
            return DataSourceResult::error(self::SOURCE_NAME, 'announcement_list', 'parse_error', '公告列表响应解析失败', [
                'http_code' => 200,
            ]);
        }

        $items = [];
        foreach ((array)($payload['data']['list'] ?? []) as $row) {
            if (!is_array($row)) continue;
            $item = $this->normalizeListItem($row);
            if ($item !== null) $items[] = $item;
        }
        $this->breaker->success();
        $totalHits = max(0, (int)($payload['data']['total_hits'] ?? count($items)));
        return DataSourceResult::success(self::SOURCE_NAME, 'announcement_list', $items, [
            'page' => $page,
            'page_size' => $pageSize,
            'raw_count' => count($items),
            'total_hits' => $totalHits,
            'has_more' => $page * $pageSize < $totalHits,
            'market' => $market,
            'code' => $code,
            'http_code' => 200,
            'duration_ms' => (int)round($this->http->lastDuration * 1000),
            'provider_notice' => '公开网页接口技术适配；不承诺 SLA，公开使用前需确认授权。',
        ]);
    }

    public function announcementDetail(string $announcementId): DataSourceResult
    {
        $announcementId = strtoupper(trim($announcementId));
        if (!preg_match('/^AN\d{18}$/', $announcementId)) {
            return DataSourceResult::error(self::SOURCE_NAME, 'announcement_detail', 'invalid_announcement_id', '公告 ID 格式不正确');
        }
        if (!$this->breaker->allow()) {
            return DataSourceResult::error(self::SOURCE_NAME, 'announcement_detail', 'circuit_open', '公告正文接口熔断中', [
                'circuit_state' => $this->breaker->getState(),
            ]);
        }

        $url = self::DETAIL_URL . '?' . http_build_query([
            'client_source' => 'web',
            'show_all' => '1',
            'art_code' => $announcementId,
        ], '', '&', PHP_QUERY_RFC3986);
        $resp = $this->http->get($url);
        if (($resp['error'] ?? null) || (int)($resp['http_code'] ?? 0) !== 200) {
            $message = ($resp['error'] ?? '') ?: 'HTTP ' . (int)($resp['http_code'] ?? 0);
            $this->breaker->failure($message);
            return DataSourceResult::error(self::SOURCE_NAME, 'announcement_detail', 'network_error', '公告正文请求失败: ' . $message, [
                'http_code' => (int)($resp['http_code'] ?? 0),
                'duration_ms' => (int)round($this->http->lastDuration * 1000),
            ]);
        }
        $parsed = HttpClient::parseJson((string)($resp['body'] ?? ''));
        $payload = $parsed['data'] ?? null;
        $data = is_array($payload) && is_array($payload['data'] ?? null) ? $payload['data'] : null;
        if (!$parsed['ok'] || $data === null) {
            $this->breaker->failure('parse_error');
            return DataSourceResult::error(self::SOURCE_NAME, 'announcement_detail', 'parse_error', '公告正文响应解析失败', [
                'http_code' => 200,
            ]);
        }

        $this->breaker->success();
        $security = is_array($data['security'] ?? null) ? $data['security'] : [];
        $code = preg_replace('/\D/', '', (string)($security['stock'] ?? ''));
        $content = $this->cleanContent((string)($data['notice_content'] ?? ''));
        $documentUrl = $this->safeDocumentUrl((string)($data['attach_url_web'] ?? $data['attach_url'] ?? ''));
        $publishedAt = $this->normalizeDateTime((string)($data['eitime'] ?? ''));
        $disclosureDate = $this->normalizeDate((string)($data['notice_date'] ?? ''));
        $detail = [
            'id' => $announcementId,
            'code' => $code,
            'name' => $this->cleanInline((string)($security['short_name'] ?? $data['short_name'] ?? '')),
            'market' => $this->marketFromCode($code),
            'title' => $this->cleanInline((string)($data['notice_title'] ?? '')),
            'disclosure_date' => $disclosureDate,
            'published_at' => $publishedAt !== '' ? $publishedAt : null,
            'provider' => self::SOURCE_NAME,
            'provider_url' => $code !== '' ? 'https://data.eastmoney.com/notices/detail/' . rawurlencode($code) . '/' . rawurlencode($announcementId) . '.html' : '',
            'document_url' => $documentUrl,
            'content' => $content,
            'content_status' => $content !== '' ? 'available' : 'empty',
            'content_chars' => mb_strlen($content, 'UTF-8'),
        ];
        return DataSourceResult::success(self::SOURCE_NAME, 'announcement_detail', $detail, [
            'http_code' => 200,
            'duration_ms' => (int)round($this->http->lastDuration * 1000),
            'content_exposed' => true,
        ]);
    }

    private function normalizeListItem(array $row): ?array
    {
        $id = strtoupper(trim((string)($row['art_code'] ?? '')));
        $title = $this->cleanInline((string)($row['title'] ?? $row['title_ch'] ?? ''));
        if (!preg_match('/^AN\d{18}$/', $id) || $title === '') return null;

        $securities = [];
        foreach ((array)($row['codes'] ?? []) as $security) {
            if (!is_array($security)) continue;
            $code = preg_replace('/\D/', '', (string)($security['stock_code'] ?? ''));
            if (!preg_match('/^\d{6}$/', $code)) continue;
            $securities[] = [
                'code' => $code,
                'name' => $this->cleanInline((string)($security['short_name'] ?? '')),
                'market' => $this->marketFromCode($code),
            ];
        }
        if (empty($securities)) return null;
        $primary = $securities[0];
        $category = is_array($row['columns'][0] ?? null) ? $row['columns'][0] : [];
        $publishedAt = $this->normalizeDateTime((string)($row['display_time'] ?? ''));
        return [
            'id' => $id,
            'code' => $primary['code'],
            'name' => $primary['name'],
            'market' => $primary['market'],
            'securities' => $securities,
            'title' => $title,
            'disclosure_date' => $this->normalizeDate((string)($row['notice_date'] ?? '')),
            'published_at' => $publishedAt !== '' ? $publishedAt : null,
            'category_raw' => $this->cleanInline((string)($category['column_name'] ?? '')),
            'category_code' => preg_replace('/[^A-Za-z0-9]/', '', (string)($category['column_code'] ?? '')),
            'provider' => self::SOURCE_NAME,
            'provider_url' => 'https://data.eastmoney.com/notices/detail/' . rawurlencode($primary['code']) . '/' . rawurlencode($id) . '.html',
            'document_url' => null,
            'detail_available' => true,
        ];
    }

    private function safeDocumentUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') return null;
        $parts = parse_url($url);
        $host = strtolower((string)($parts['host'] ?? ''));
        if (($parts['scheme'] ?? '') !== 'https' || !in_array($host, ['pdf.dfcfw.com'], true)) return null;
        return $url;
    }

    private function cleanInline(string $value): string
    {
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim((string)preg_replace('/[\x{00A0}\x{3000}\s]+/u', ' ', $value));
    }

    private function cleanContent(string $value): string
    {
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = preg_replace('/[\x{00A0}\x{3000}\t]+/u', ' ', $value);
        $value = preg_replace('/ *\n */u', "\n", (string)$value);
        $value = preg_replace('/\n{3,}/u', "\n\n", (string)$value);
        return trim((string)$value);
    }

    private function normalizeDate(string $value): string
    {
        $value = trim($value);
        if ($value === '') return '';
        try {
            return (new DateTimeImmutable($value, new DateTimeZone('Asia/Shanghai')))->format('Y-m-d');
        } catch (Throwable $e) {
            return '';
        }
    }

    private function normalizeDateTime(string $value): string
    {
        $value = trim($value);
        if ($value === '') return '';
        try {
            return (new DateTimeImmutable($value, new DateTimeZone('Asia/Shanghai')))->format('Y-m-d H:i:s');
        } catch (Throwable $e) {
            return '';
        }
    }

    private function marketFromCode(string $code): string
    {
        if (preg_match('/^(?:4|8|92)/', $code)) return 'bj';
        if (preg_match('/^(?:5|6|9)/', $code)) return 'sh';
        return 'sz';
    }

    private function validDate(string $value): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return false;
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('Asia/Shanghai'));
        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
